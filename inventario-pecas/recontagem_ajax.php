<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require 'conexao_inventario.php';
require 'recontagem_lib.php';

/**
 * JSON da grade de recontagem do gestor.
 *
 * Modos: grade, selecionar (GET) e mandar, trocar_coletor (POST). Só admin e
 * gestor. A loja e o inventário vêm como no dashboard (codigo =
 * "empresa.revenda", inv).
 */

function recontagem_responder(array $dados): void
{
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

$perfil = (string)($_SESSION['inventario_login']['perfil'] ?? '');

if (!in_array($perfil, ['admin', 'gestor'], true)) {
    recontagem_responder(['ok' => false, 'mensagem' => 'Sessão expirada ou sem permissão.', 'sessao' => false]);
}

$codigo = is_string($_GET['codigo'] ?? null) ? trim($_GET['codigo']) : '';
$inv    = (int)($_GET['inv'] ?? 0);
$emp    = 0;
$rev    = 0;

if (strpos($codigo, '.') !== false) {
    [$emp, $rev] = array_map('intval', explode('.', $codigo, 2));
}

if ($inv <= 0 || $emp <= 0 || $rev <= 0) {
    recontagem_responder(['ok' => false, 'mensagem' => 'Escolha a loja e o inventário.']);
}

$modo = is_string($_GET['modo'] ?? null) ? $_GET['modo'] : '';

// O mesmo filtro (situação/rodada/busca) da grade e da seleção por faixa — um
// só lugar monta o array do pedido, para as duas rotas nunca lerem parâmetros
// de jeitos diferentes.
$filtroDoPedido = [
    'situacao' => is_string($_GET['situacao'] ?? null) ? $_GET['situacao'] : 'recontar',
    'rodada'   => (int)($_GET['rodada'] ?? 0),
    'busca'    => is_string($_GET['busca'] ?? null) ? $_GET['busca'] : '',
];

// -------------------------------------------------------
// MODO: grade
// -------------------------------------------------------
if ($modo === 'grade') {
    $grade  = recontagem_grade($pdo, $inv, $emp, $rev, $filtroDoPedido);
    $resumo = recontagem_resumo($pdo, $inv, $emp, $rev);

    recontagem_responder(['ok' => true] + $grade + [
        'resumo'     => $resumo,
        'conclusao'  => recontagem_conclusao($resumo),
        'coletores'  => contagem2_coletores($pdo, $inv, $emp, $rev),
        'terminaram' => contagem2_terminaram($pdo, $inv, $emp, $rev, 2),
        'ultima'     => RECONTAGEM_ULTIMA,
    ]);
}

// -------------------------------------------------------
// MODO: selecionar (seleção por faixa de locação)
// -------------------------------------------------------
if ($modo === 'selecionar') {
    $ids = recontagem_selecionar($pdo, $inv, $emp, $rev, $filtroDoPedido,
        is_string($_GET['de'] ?? null) ? $_GET['de'] : '',
        is_string($_GET['ate'] ?? null) ? $_GET['ate'] : '',
        is_string($_GET['sinal'] ?? null) ? $_GET['sinal'] : 'todos'
    );

    recontagem_responder(['ok' => true, 'ids' => $ids, 'total' => count($ids)]);
}

// ids num campo JSON só: um campo por id passaria do max_input_vars (1000)
// numa seleção grande, e o PHP corta o excedente em silêncio. mandar,
// trocar_coletor, enviar e previa leem ids do mesmo jeito.
function recontagem_ids_do_post(): array
{
    $ids = json_decode(is_string($_POST['ids'] ?? null) ? $_POST['ids'] : '[]', true);

    return is_array($ids)
        ? array_values(array_filter($ids, fn($i) => is_int($i) || (is_string($i) && ctype_digit($i))))
        : [];
}

// -------------------------------------------------------
// MODO: previa (prévia do envio, sem gravar e sem travar)
// -------------------------------------------------------
if ($modo === 'previa') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        recontagem_responder(['ok' => false, 'mensagem' => 'Use POST.']);
    }

    $ids = recontagem_ids_do_post();

    if ($ids === []) {
        recontagem_responder(['ok' => false, 'mensagem' => 'Nenhum item marcado.']);
    }

    recontagem_responder(['ok' => true] + recontagem_previa_envio($pdo, $inv, $emp, $rev, $ids));
}

// -------------------------------------------------------
// MODOS que escrevem: mandar, trocar_coletor, enviar, encerrar
// -------------------------------------------------------
if (in_array($modo, ['mandar', 'trocar_coletor', 'enviar', 'encerrar'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        recontagem_responder(['ok' => false, 'mensagem' => 'Use POST.']);
    }

    if (!contagem2_inventario_aberto($pdo, $inv, $emp, $rev)) {
        recontagem_responder(['ok' => false, 'mensagem' => 'Inventário encerrado.']);
    }

    $ids = recontagem_ids_do_post();

    if ($ids === []) {
        recontagem_responder(['ok' => false, 'mensagem' => 'Nenhum item marcado.']);
    }

    $coletor = (int)($_POST['coletor'] ?? 0);

    // 'encerrar' grava quem encerrou: 'nome' do login do gestor, ou 'usuario'
    // quando o nome vier vazio.
    $login   = $_SESSION['inventario_login'] ?? [];
    $nome    = trim((string)($login['nome'] ?? ''));
    $usuario = $nome !== '' ? $nome : (string)($login['usuario'] ?? '');

    try {
        $pdo->beginTransaction();
        // switch, e não match: produção roda PHP 7, onde match não existe e o
        // arquivo inteiro deixaria de carregar.
        switch ($modo) {
            case 'mandar':
                $r = recontagem_mandar($pdo, $inv, $emp, $rev, $ids, $coletor);
                break;
            case 'trocar_coletor':
                $r = recontagem_trocar_coletor($pdo, $inv, $emp, $rev, $ids, $coletor);
                break;
            case 'enviar':
                $r = recontagem_enviar($pdo, $inv, $emp, $rev, $ids, $coletor);
                break;
            default:
                $r = recontagem_encerrar($pdo, $inv, $emp, $rev, $ids, $usuario);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        // Trava ou deadlock com outro gestor/coletor não pode virar erro HTML na
        // tela: captura qualquer coisa, desfaz para soltar as travas do FOR
        // UPDATE, e devolve JSON mesmo assim.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        recontagem_responder(['ok' => false, 'mensagem' => $e instanceof InvalidArgumentException
            ? 'Escolha um coletor.'
            : 'Não foi possível gravar agora. Tente de novo.']);
    }

    recontagem_responder(['ok' => true] + $r);
}

recontagem_responder(['ok' => false, 'mensagem' => 'Modo inválido.']);
