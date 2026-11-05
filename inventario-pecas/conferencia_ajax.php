<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require 'conexao_inventario.php';
require 'conexao_oracle.php';
require 'conferencia_lib.php';

// Só a sessão da Conferência, que passou pelo PIN: a de 1ª contagem ou
// recontagem não grava aqui, nem a de antes do PIN (sem conferente_id).
if (empty($_SESSION['inventario']) || (int)($_SESSION['inventario']['contagem'] ?? 0) !== 3 || (int)($_SESSION['inventario']['conferente_id'] ?? 0) <= 0) {
    echo json_encode(['ok' => false, 'mensagem' => 'Sessão expirada']);
    exit;
}

$idInv      = (int)($_SESSION['inventario']['id']          ?? 0);
$empresa    = (int)($_SESSION['inventario']['empresa']     ?? 0);
$revenda    = (int)($_SESSION['inventario']['revenda']     ?? 0);
$coletor    = (int)($_SESSION['inventario']['coletor']     ?? 0);
$duplaLabel = (string)($_SESSION['inventario']['dupla_label'] ?? '');

// id 0 é a conferência sem inventário (login.php, Contagem = Conferência): só a loja é obrigatória.
if ($idInv < 0 || $empresa <= 0 || $revenda <= 0) {
    echo json_encode(['ok' => false, 'mensagem' => 'Loja não definida']);
    exit;
}

$modo  = trim((string)($_GET['modo'] ?? ''));
$lojas = conferencia_lojas_da_cidade($pdo, $empresa, $revenda);

// -------------------------------------------------------
// MODO: locacao — guarda a locação bipada, onde a dupla
// está. Vale para todos os itens seguintes até ser trocada;
// vazio limpa. Fica na sessão para sobreviver à ida ao
// ajuste e morrer no logout.
// -------------------------------------------------------
if ($modo === 'locacao') {
    $texto = trim((string)($_POST['locacao'] ?? ''));

    if ($texto === '') {
        unset($_SESSION['conferencia_locacao']);
        echo json_encode(['ok' => true, 'locacao' => '']);
        exit;
    }

    $locacao = conferencia_ler_etiqueta_locacao($texto);

    if ($locacao === null) {
        echo json_encode(['ok' => false, 'mensagem' => '"' . $texto . '" não é uma etiqueta de locação. O formato é 1-22-13-A-1.']);
        exit;
    }

    $_SESSION['conferencia_locacao'] = $locacao;
    echo json_encode(['ok' => true, 'locacao' => $locacao]);
    exit;
}

// -------------------------------------------------------
// MODO: buscar — resolve o código bipado e devolve item,
// locação e a quantidade de cada loja da cidade, e se a
// locação do Apollo confere com a que a dupla bipou.
// -------------------------------------------------------
if ($modo === 'buscar') {
    $codigo = trim((string)($_GET['codigo'] ?? ''));

    if ($codigo === '') {
        echo json_encode(['ok' => false, 'mensagem' => 'Código vazio']);
        exit;
    }

    // Da sessão, nunca da URL: é a locação que a tela mostrou à dupla.
    $locacaoBipada = (string)($_SESSION['conferencia_locacao'] ?? '');

    if ($locacaoBipada === '') {
        echo json_encode(['ok' => false, 'sem_locacao' => true, 'mensagem' => 'Bipe primeiro a etiqueta da locação.']);
        exit;
    }

    try {
        $ora = oracle_conectar();

        if ($ora) {
            $resultado = conferencia_buscar_oracle($ora, $empresa, $revenda, $lojas, $codigo);
            oci_close($ora);

            if ($resultado === null) {
                echo json_encode(['ok' => false, 'mensagem' => 'Item não encontrado']);
                exit;
            }
        } elseif ($idInv === 0) {
            // Sem inventário não há snapshot para cair.
            echo json_encode(['ok' => false, 'mensagem' => 'Apollo indisponível. Tente de novo.']);
            exit;
        } else {
            $resultado = conferencia_buscar_snapshot($pdo, $idInv, $empresa, $revenda, $lojas, $codigo);

            if ($resultado === null) {
                echo json_encode(['ok' => false, 'mensagem' => 'Item não encontrado no snapshot do inventário']);
                exit;
            }
        }

        $resultado['ok']           = true;
        $resultado['ja_conferido'] = conferencia_ja_conferido(
            $pdo,
            $idInv,
            $empresa,
            $revenda,
            $resultado['item']['item_estoque_pub']
        );

        // locacao_geral é a da loja da sessão, nas duas origens.
        $resultado['locacao_bipada']   = $locacaoBipada;
        $resultado['locacao_situacao'] = conferencia_situacao_locacao(
            $locacaoBipada,
            (string)$resultado['item']['locacao_geral']
        );

        echo json_encode($resultado);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => 'Erro ao buscar o item']);
    }
    exit;
}

// -------------------------------------------------------
// MODO: registrar — grava um bipe.
// -------------------------------------------------------
if ($modo === 'registrar') {
    $itemPub = trim((string)($_POST['item_estoque_pub'] ?? ''));

    if ($itemPub === '') {
        echo json_encode(['ok' => false, 'mensagem' => 'Item vazio']);
        exit;
    }

    // A decisão é o que dá sentido ao registro, então é validada aqui e não
    // silenciada num padrão: um status inventado tem que voltar como erro para
    // a tela, não virar 'ok' na tabela.
    $status = trim((string)($_POST['status'] ?? ''));

    if (!in_array($status, CONFERENCIA_STATUS, true)) {
        echo json_encode(['ok' => false, 'mensagem' => 'Decisão inválida']);
        exit;
    }

    // Bipado ou digitado pelo botão de código ilegível. Lista fechada, e sem
    // padrão: sumir com a informação seria sumir com o rastro.
    $entrada = trim((string)($_POST['entrada'] ?? ''));

    if (!in_array($entrada, CONFERENCIA_ENTRADAS, true)) {
        echo json_encode(['ok' => false, 'mensagem' => 'Entrada inválida']);
        exit;
    }

    $saldo = (string)($_POST['saldo_oracle'] ?? '');

    try {
        conferencia_registrar($pdo, [
            // Da sessão, nunca do POST.
            'id_inventario'    => $idInv,
            'empresa'          => $empresa,
            'revenda'          => $revenda,
            'coletor'          => $coletor,
            'dupla_label'      => $duplaLabel,
            // Do POST, porque descrevem o que foi mostrado na tela.
            'item_estoque_pub' => $itemPub,
            'cod_item_estoque' => trim((string)($_POST['cod_item_estoque'] ?? '')),
            'descricao'        => trim((string)($_POST['descricao']        ?? '')),
            'locacao_geral'    => trim((string)($_POST['locacao_geral']    ?? '')),
            // Vazio significa desconhecido (fallback), e desconhecido não é zero.
            'saldo_oracle'     => ($saldo === '' || $saldo === 'null') ? null : (int)$saldo,
            // Lista fechada: origem é rótulo nosso, não texto do cliente.
            'origem'           => ($_POST['origem'] ?? '') === 'snapshot' ? 'snapshot' : 'oracle',
            'status'           => $status,
            'entrada'          => $entrada,
        ]);

        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => 'Erro ao registrar a conferência']);
    }
    exit;
}

// -------------------------------------------------------
// MODO: confirmar_manter — "Manter locação Apollo" do aviso
// de locação diferente. A dupla leva a peça para a locação
// do Apollo e bipa a etiqueta de lá; só grava a conferência
// ('ok') se a etiqueta for mesmo a do Apollo. Sem isso, o
// Manter fechava o aviso sem prova de que a peça foi levada.
// -------------------------------------------------------
if ($modo === 'confirmar_manter') {
    $itemPub = trim((string)($_POST['item_estoque_pub'] ?? ''));
    $texto   = trim((string)($_POST['locacao'] ?? ''));
    $entrada = trim((string)($_POST['entrada'] ?? ''));
    $entLoc  = trim((string)($_POST['entrada_locacao'] ?? ''));

    if ($itemPub === '') {
        echo json_encode(['ok' => false, 'mensagem' => 'Item vazio']);
        exit;
    }
    if (!in_array($entrada, CONFERENCIA_ENTRADAS, true) || !in_array($entLoc, CONFERENCIA_ENTRADAS, true)) {
        echo json_encode(['ok' => false, 'mensagem' => 'Entrada inválida']);
        exit;
    }

    $bipada = conferencia_ler_etiqueta_locacao($texto);
    if ($bipada === null) {
        echo json_encode(['ok' => false, 'mensagem' => '"' . $texto . '" não é uma etiqueta de locação. O formato é 1-22-13-A-1.']);
        exit;
    }

    try {
        // A locação do Apollo é lida de novo aqui, e não aceita do POST: é com
        // ela que a etiqueta tem que bater. Sem Apollo (snapshot), vale a que a
        // tela mostrou — a mesma que o registro normal já aceita do POST.
        $ora = oracle_conectar();
        if ($ora) {
            $resultado = conferencia_buscar_oracle($ora, $empresa, $revenda, [], $itemPub);
            oci_close($ora);
            if ($resultado === null) {
                echo json_encode(['ok' => false, 'mensagem' => 'Item não encontrado']);
                exit;
            }
            $apollo = (string)$resultado['item']['locacao_geral'];
            $origem = 'oracle';
        } else {
            $apollo = trim((string)($_POST['locacao_geral'] ?? ''));
            $origem = 'snapshot';
        }

        if (conferencia_situacao_locacao($bipada, $apollo) !== 'confere') {
            echo json_encode([
                'ok'       => false,
                'mensagem' => 'Esta etiqueta é ' . $bipada . '. Leve a peça para ' . ($apollo !== '' ? $apollo : '(sem locação)')
                    . ' e bipe a etiqueta de lá.',
            ]);
            exit;
        }

        $saldo = (string)($_POST['saldo_oracle'] ?? '');
        conferencia_registrar($pdo, [
            'id_inventario'    => $idInv,
            'empresa'          => $empresa,
            'revenda'          => $revenda,
            'coletor'          => $coletor,
            'dupla_label'      => $duplaLabel,
            'item_estoque_pub' => $itemPub,
            'cod_item_estoque' => trim((string)($_POST['cod_item_estoque'] ?? '')),
            'descricao'        => trim((string)($_POST['descricao']        ?? '')),
            'locacao_geral'    => $apollo,
            'saldo_oracle'     => ($saldo === '' || $saldo === 'null') ? null : (int)$saldo,
            'origem'           => $origem,
            'status'           => 'ok',
            // Um registro só: se o código ou a etiqueta foram digitados, fica
            // 'digitado' — o rastro existe para mostrar a exceção.
            'entrada'          => ($entrada === 'digitado' || $entLoc === 'digitado') ? 'digitado' : 'bipado',
        ]);

        echo json_encode(['ok' => true, 'locacao' => $apollo]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'mensagem' => 'Erro ao registrar a conferência']);
    }
    exit;
}

// -------------------------------------------------------
// MODO: transferir — passa saldo de outra filial da cidade
// para esta, que está zerada no Apollo, e grava nesta a
// locação bipada. Altera o Apollo direto (sem nota).
// -------------------------------------------------------
if ($modo === 'transferir') {
    $itemPub   = trim((string)($_POST['item_estoque_pub'] ?? ''));
    $codItem   = trim((string)($_POST['cod_item_estoque'] ?? ''));
    $revOrigem = (int)($_POST['revenda_origem'] ?? 0);
    $qtdTexto  = trim((string)($_POST['quantidade'] ?? ''));
    $entrada   = trim((string)($_POST['entrada_locacao'] ?? ''));

    if ($itemPub === '' || !ctype_digit($codItem)) {
        echo json_encode(['ok' => false, 'mensagem' => 'Item inválido']);
        exit;
    }
    if (!ctype_digit($qtdTexto) || (int)$qtdTexto < 1) {
        echo json_encode(['ok' => false, 'mensagem' => 'Informe uma quantidade maior que zero.']);
        exit;
    }
    if (!in_array($entrada, CONFERENCIA_ENTRADAS, true)) {
        echo json_encode(['ok' => false, 'mensagem' => 'Entrada inválida']);
        exit;
    }

    // A origem tem que ser outra filial desta cidade; o destino é sempre a da
    // sessão, nunca do POST.
    $origemValida = false;
    foreach ($lojas as $l) {
        if ((int)$l['revenda'] === $revOrigem && !$l['atual']) {
            $origemValida = true;
        }
    }
    if (!$origemValida) {
        echo json_encode(['ok' => false, 'mensagem' => 'Filial de origem inválida']);
        exit;
    }

    $campos = conferencia_locacao_em_campos((string)($_POST['locacao'] ?? ''));
    if ($campos === null) {
        echo json_encode(['ok' => false, 'campo' => 'locacao', 'mensagem' => 'Isso não é uma etiqueta de locação. O formato é 1-22-13-A-1.']);
        exit;
    }
    if ($campos['erros'] !== []) {
        echo json_encode(['ok' => false, 'campo' => 'locacao', 'mensagem' => implode(' ', $campos['erros'])]);
        exit;
    }
    $locacao = $campos['valores'];
    $qtd     = (int)$qtdTexto;

    try {
        $ora = oracle_conectar();
    } catch (Throwable $e) {
        $ora = false;
    }
    if (!$ora) {
        echo json_encode(['ok' => false, 'mensagem' => 'Apollo indisponível. Tente de novo.']);
        exit;
    }

    try {
        $idTransf = conferencia_registrar_transferencia($pdo, [
            'id_inventario'    => $idInv,
            'empresa'          => $empresa,
            'revenda_origem'   => $revOrigem,
            'revenda_destino'  => $revenda,
            'coletor'          => $coletor,
            'dupla_label'      => $duplaLabel,
            'item_estoque_pub' => $itemPub,
            'cod_item_estoque' => $codItem,
            'descricao'        => trim((string)($_POST['descricao'] ?? '')),
            'quantidade'       => $qtd,
            'locacao_destino'  => implode('-', $locacao),
            'entrada_locacao'  => $entrada,
        ]);
    } catch (Throwable $e) {
        // Sem o rastro, o Apollo não é tocado.
        oci_close($ora);
        echo json_encode(['ok' => false, 'mensagem' => 'Não foi possível registrar a transferência']);
        exit;
    }

    try {
        $saldos = conferencia_transferir_saldo($ora, $empresa, (int)$codItem, $revOrigem, $revenda, $qtd, $locacao);
    } catch (InvalidArgumentException $e) {
        oci_close($ora);
        conferencia_concluir_transferencia($pdo, $idTransf, null, $e->getMessage());
        echo json_encode(['ok' => false, 'mensagem' => $e->getMessage()]);
        exit;
    } catch (Throwable $e) {
        @oci_rollback($ora);
        oci_close($ora);
        conferencia_concluir_transferencia($pdo, $idTransf, null, $e->getMessage());
        echo json_encode(['ok' => false, 'mensagem' => 'O Apollo recusou a transferência. Nada foi alterado.']);
        exit;
    }
    oci_close($ora);

    try {
        conferencia_concluir_transferencia($pdo, $idTransf, $saldos);
        // A locação nova acompanha o inventário aberto desta filial, como no ajuste.
        conferencia_atualizar_locacao_inventario($pdo, $empresa, $revenda, $codItem, $itemPub, $locacao);
    } catch (Throwable $e) {
        // O Apollo já foi alterado: a resposta tem que dizer que deu certo,
        // senão a dupla transfere de novo. A linha fica 'pendente'.
    }

    echo json_encode([
        'ok'         => true,
        'quantidade' => $qtd,
        'locacao'    => implode('-', $locacao),
    ]);
    exit;
}

echo json_encode(['ok' => false, 'mensagem' => 'Modo inválido']);
