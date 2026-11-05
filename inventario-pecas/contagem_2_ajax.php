<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require 'conexao_inventario.php';
require 'recontagem_lib.php';

/**
 * Endpoint da recontagem guiada do coletor (2ª a 4ª contagem).
 *
 * Empresa, revenda, inventário, coletor e dupla saem sempre da sessão, nunca do
 * POST. A parada é sempre a que o servidor escolheu; o id que a tela manda só
 * serve para detectar que ela ficou para trás.
 *
 * Às cegas: nenhuma resposta traz o sistema, as contagens anteriores, nem se
 * a contagem bateu.
 */

function contagem2_responder(array $dados): void
{
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['inventario'])) {
    contagem2_responder(['ok' => false, 'mensagem' => 'Sessão expirada']);
}

$idInv      = (int)($_SESSION['inventario']['id']              ?? 0);
$empresa    = (int)($_SESSION['inventario']['empresa']         ?? 0);
$revenda    = (int)($_SESSION['inventario']['revenda']         ?? 0);
$coletor    = (int)($_SESSION['inventario']['coletor']         ?? 0);
$duplaLabel = (string)($_SESSION['inventario']['dupla_label']  ?? '');
$locacaoIni = (string)($_SESSION['inventario']['locacao_inicial'] ?? '');

if ($idInv <= 0 || $coletor <= 0) {
    contagem2_responder(['ok' => false, 'mensagem' => 'Inventário não definido']);
}

$modo = trim((string)($_GET['modo'] ?? ''));

/** Os pulados vivem na sessão, por estoque.id_linha: é uma escolha desta caminhada. */
$pulados = array_map('intval', (array)($_SESSION['recontagem_pulados'] ?? []));

/** O próximo item, com os pulados no fim. Relê a fila a cada chamada. */
function recontagem_proximo(PDO $pdo, int $inv, int $emp, int $rev, int $coletor, string $locacaoIni, array $pulados): ?array
{
    $fila = contagem2_rotacionar(recontagem_fila($pdo, $inv, $emp, $rev, $coletor), $locacaoIni);

    if ($fila === []) {
        return null;
    }

    foreach ($fila as $linha) {
        if (!in_array($linha['id'], $pulados, true)) {
            return $linha;
        }
    }

    // Só restaram pulados: a lista cumpriu o papel de adiar e sai de cena.
    $_SESSION['recontagem_pulados'] = [];

    return $fila[0];
}

/** Só o que a tela precisa mostrar — sem os campos de ordenação. */
function recontagem_para_tela(?array $item): ?array
{
    if ($item === null) {
        return null;
    }

    return [
        'id'                     => $item['id'],
        'item_estoque_pub'       => $item['item_estoque_pub'],
        'descricao_item_estoque' => $item['descricao_item_estoque'],
        'locacao_geral'          => $item['locacao_geral'],
        'rodada'                 => $item['rodada'],
    ];
}

function recontagem_estado(PDO $pdo, int $inv, int $emp, int $rev, int $coletor, string $locacaoIni, array $pulados, array $extra = []): array
{
    return array_merge([
        'ok'     => true,
        'item'   => recontagem_para_tela(recontagem_proximo($pdo, $inv, $emp, $rev, $coletor, $locacaoIni, $pulados)),
        'totais' => recontagem_totais_coletor($pdo, $inv, $emp, $rev, $coletor),
    ], $extra);
}

$estado = fn(array $extra = []) => recontagem_estado($pdo, $idInv, $empresa, $revenda, $coletor, $locacaoIni, $pulados, $extra);

// -------------------------------------------------------
// MODO: proximo
// -------------------------------------------------------
if ($modo === 'proximo') {
    contagem2_responder($estado());
}

// -------------------------------------------------------
// MODO: confirmar_item — o bipe bate com o item da parada
// -------------------------------------------------------
if ($modo === 'confirmar_item') {
    try {
        contagem2_exigir_entrada($_POST['entrada'] ?? null);
    } catch (InvalidArgumentException $e) {
        contagem2_responder(['ok' => false, 'mensagem' => 'Entrada inválida']);
    }

    $esperado = recontagem_proximo($pdo, $idInv, $empresa, $revenda, $coletor, $locacaoIni, $pulados);

    if ($esperado === null) {
        contagem2_responder($estado(['ok' => false, 'parada_mudou' => true]));
    }

    // A tela diz em qual parada ela acha que está. Se o servidor já passou dela
    // (o gestor redistribuiu o item), repinta em vez de recusar a cada rebipe.
    if ((int)($_POST['parada'] ?? 0) !== $esperado['id']) {
        contagem2_responder($estado(['ok' => false, 'parada_mudou' => true]));
    }

    $lido = contagem2_limpar_codigo($_POST['item'] ?? '');

    if (!contagem2_bipe_confere($esperado['item_estoque_pub'], $lido)) {
        contagem2_responder([
            'ok'       => false,
            'mensagem' => 'Esse é o item ' . ($lido !== '' ? $lido : '(vazio)')
                        . '. Esta parada é ' . $esperado['item_estoque_pub'] . '.',
        ]);
    }

    contagem2_responder(['ok' => true, 'id' => $esperado['id']]);
}

// -------------------------------------------------------
// MODO: gravar
// -------------------------------------------------------
if ($modo === 'gravar') {
    if (!contagem2_inventario_aberto($pdo, $idInv, $empresa, $revenda)) {
        contagem2_responder(['ok' => false, 'mensagem' => 'Inventário encerrado']);
    }

    $quantidadeBruta = (string)($_POST['quantidade'] ?? '');

    if (!preg_match('/^\d+$/', $quantidadeBruta)) {
        contagem2_responder(['ok' => false, 'mensagem' => 'Quantidade inválida']);
    }

    // Revalida contra a parada atual: a confirmação da tela é conveniência,
    // não autorização.
    $esperado = recontagem_proximo($pdo, $idInv, $empresa, $revenda, $coletor, $locacaoIni, $pulados);
    $idPost   = (int)($_POST['id'] ?? 0);

    if ($esperado === null || $esperado['id'] !== $idPost) {
        contagem2_responder($estado(['ok' => false, 'parada_mudou' => true]));
    }

    try {
        $r = recontagem_gravar($pdo, [
            'id_inventario' => $idInv,
            'empresa'       => $empresa,
            'revenda'       => $revenda,
            'id'            => $idPost,
            // A contagem que o coletor viu na tela: se o gestor mandou o item
            // para a próxima enquanto ele digitava, não grava na coluna errada.
            'rodada'        => $esperado['rodada'],
            'coletor'       => $coletor,
            'dupla'         => $duplaLabel,
            'quantidade'    => (int)$quantidadeBruta,
            'entrada'       => $_POST['entrada'] ?? null,
        ]);
    } catch (InvalidArgumentException $e) {
        contagem2_responder(['ok' => false, 'mensagem' => 'Dados inválidos']);
    } catch (Throwable $e) {
        // Trava de linha ou deadlock com o gestor mandando itens: a tela do
        // coletor precisa de JSON para não ficar presa.
        contagem2_responder(['ok' => false, 'mensagem' => 'Não foi possível gravar agora. Tente de novo.']);
    }

    if (!$r['ok']) {
        contagem2_responder($estado(['ok' => false, 'parada_mudou' => true]));
    }

    $pulados = array_values(array_diff($pulados, [$idPost]));
    $_SESSION['recontagem_pulados'] = $pulados;

    // Voltou a contar depois de avisar que tinha terminado: o aviso caducou.
    contagem2_desfazer_terminou($pdo, $idInv, $empresa, $revenda, 2, $coletor);

    contagem2_responder(recontagem_estado($pdo, $idInv, $empresa, $revenda, $coletor, $locacaoIni, $pulados, [
        'registrado' => true,
    ]));
}

// -------------------------------------------------------
// MODO: terminei — o coletor avisa o gestor que acabou a parte dele
// -------------------------------------------------------
if ($modo === 'terminei') {
    $t = recontagem_totais_coletor($pdo, $idInv, $empresa, $revenda, $coletor);
    $pendentes = max(0, $t['total'] - $t['feitos']);

    contagem2_registrar_terminou($pdo, $idInv, $empresa, $revenda, 2, $coletor, $duplaLabel, $pendentes);

    contagem2_responder(['ok' => true, 'pendentes' => $pendentes]);
}

// -------------------------------------------------------
// MODO: pular
// -------------------------------------------------------
if ($modo === 'pular') {
    $id = (int)($_POST['id'] ?? 0);

    if ($id > 0 && !in_array($id, $pulados, true)) {
        $pulados[] = $id;
    }

    $_SESSION['recontagem_pulados'] = $pulados;

    contagem2_responder(recontagem_estado($pdo, $idInv, $empresa, $revenda, $coletor, $locacaoIni, $pulados));
}

contagem2_responder(['ok' => false, 'mensagem' => 'Modo inválido']);
