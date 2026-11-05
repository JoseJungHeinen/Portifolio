<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require __DIR__ . '/assert.php';

echo "test_ajax\n";

const SESSAO_TESTE = [
    'id'          => 45,
    'empresa'     => 1,
    'revenda'     => 1,
    'coletor'     => 3,
    'contagem'    => 3,
    'dupla_label' => 'Teste Automatizado',
    // Sessão da Conferência só vale depois do PIN, que grava conferente_id.
    'conferente_id' => 1,
];

/** Roda um caso no endpoint e devolve a saida crua, so JSON. */
function saida_crua(array $session, array $get, array $post = []): string
{
    // O caso vai por arquivo: no Windows, escapeshellarg() destroi as aspas do
    // JSON e o runner receberia lixo.
    $arquivo = tempnam(sys_get_temp_dir(), 'confajax');
    file_put_contents($arquivo, json_encode([
        'session' => $session,
        'get'     => $get,
        'post'    => $post,
    ]));

    $cmd = escapeshellarg(PHP_BINARY)
         . ' -d display_errors=0 '
         . escapeshellarg('tests/conferencia/run_ajax.php') . ' '
         . escapeshellarg($arquivo);

    $saida = (string)(shell_exec($cmd) ?? '');
    @unlink($arquivo);

    return trim($saida);
}

/** Roda um caso no endpoint e devolve o JSON decodificado. */
function chamar(array $session, array $get, array $post = []): array
{
    $saida = saida_crua($session, $get, $post);
    $ini   = strpos($saida, '{');

    return $ini === false ? [] : (json_decode(substr($saida, $ini), true) ?? []);
}

// ── Sem sessão ──────────────────────────────────────────────────────────────
$r = chamar([], ['modo' => 'buscar', 'codigo' => '063806']);
t_eq('sem sessao nao busca', false, $r['ok'] ?? null);
t_true('sem sessao avisa expirada', str_contains(mb_strtolower((string)($r['mensagem'] ?? '')), 'sess'));

// ── Busca com sessão de Cidade A Fiat, inventário 45 ─────────────────────────
$r = chamar(['inventario' => SESSAO_TESTE, 'conferencia_locacao' => '1-1-1-A-1'], ['modo' => 'buscar', 'codigo' => '063806']);
t_true('busca com sessao responde ok', $r['ok'] ?? null);
t_eq('codigo publico na resposta', '063806', $r['item']['item_estoque_pub'] ?? null);
t_eq('duas lojas na resposta', 2, count($r['lojas'] ?? []));
t_true('traz o campo ja_conferido', array_key_exists('ja_conferido', $r));
t_true('traz uma origem valida', in_array($r['origem'] ?? '', ['oracle', 'snapshot'], true));
t_eq('devolve a locacao bipada', '1-1-1-A-1', $r['locacao_bipada'] ?? null);
t_true('compara com a locacao do Apollo', in_array($r['locacao_situacao'] ?? '', ['confere', 'outra', 'sem_locacao'], true));

// A locacao real do item, bipada, confere; e a resposta diz isso.
$real = (string)($r['item']['locacao_geral'] ?? '');
if ($real !== '') {
    $r2 = chamar(['inventario' => SESSAO_TESTE, 'conferencia_locacao' => $real], ['modo' => 'buscar', 'codigo' => '063806']);
    t_eq('locacao certa confere', 'confere', $r2['locacao_situacao'] ?? null);
}

// ── Locação primeiro ────────────────────────────────────────────────────────
$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'buscar', 'codigo' => '063806']);
t_eq('sem locacao bipada nao busca', false, $r['ok'] ?? null);
t_true('sem locacao avisa a tela', $r['sem_locacao'] ?? null);

$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'locacao'], ['locacao' => '1-022-13-a-1']);
t_eq('locacao bipada e aceita', true, $r['ok'] ?? null);
t_eq('locacao volta normalizada', '1-022-13-A-1', $r['locacao'] ?? null);

$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'locacao'], ['locacao' => 'AT812']);
t_eq('codigo de item no campo de locacao e recusado', false, $r['ok'] ?? null);

$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'locacao'], ['locacao' => '']);
t_eq('locacao vazia limpa', '', $r['locacao'] ?? null);

// A resposta nao pode ter nada antes do JSON — warning do OCI8, aviso do PHP,
// BOM, espaco. Qualquer byte extra quebra o JSON.parse do front.
$cru = saida_crua(['inventario' => SESSAO_TESTE, 'conferencia_locacao' => '1-1-1-A-1'], ['modo' => 'buscar', 'codigo' => '063806']);
t_eq('resposta comeca em {', 0, strpos($cru, '{'));
t_true('resposta e json valido puro', json_decode($cru, true) !== null);

// ── Caminhos de erro ────────────────────────────────────────────────────────
$r = chamar(['inventario' => SESSAO_TESTE, 'conferencia_locacao' => '1-1-1-A-1'], ['modo' => 'buscar', 'codigo' => 'ZZZNAOEXISTE']);
t_eq('codigo inexistente responde ok=false', false, $r['ok'] ?? null);

$r = chamar(['inventario' => SESSAO_TESTE, 'conferencia_locacao' => '1-1-1-A-1'], ['modo' => 'buscar', 'codigo' => '']);
t_eq('codigo vazio responde ok=false', false, $r['ok'] ?? null);

$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'inventado']);
t_eq('modo invalido responde ok=false', false, $r['ok'] ?? null);

$r = chamar(['inventario' => ['id' => 0, 'empresa' => 0, 'revenda' => 0]], ['modo' => 'buscar', 'codigo' => '063806']);
t_eq('sessao sem inventario responde ok=false', false, $r['ok'] ?? null);

// ── Registrar grava com os dados da sessão, nunca os do POST ────────────────
$pdo->prepare("DELETE FROM inv_conferencias WHERE dupla_label = 'Teste Automatizado'")->execute();

$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'registrar'], [
    'item_estoque_pub' => '063806',
    'cod_item_estoque' => '322208',
    'descricao'        => 'BATERIA ELETR 72AH TORO 2.4 START STOP',
    'locacao_geral'    => '1-11-3-A-1',
    'saldo_oracle'     => '6',
    'origem'           => 'oracle',
    'status'           => 'ok',
    'entrada'          => 'bipado',
    // Tentativa de injetar outra loja e outro inventario pelo POST:
    // tem que ser ignorada.
    'revenda'          => '5',
    'id_inventario'    => '1',
    'coletor'          => '9',
    'dupla_label'      => 'Invasor',
]);
t_true('registrar responde ok', $r['ok'] ?? null);

$stmt = $pdo->prepare("
    SELECT id_inventario, revenda, coletor, dupla_label, saldo_oracle, origem, status
      FROM inv_conferencias
     WHERE dupla_label = 'Teste Automatizado'
     ORDER BY id DESC
     LIMIT 1
");
$stmt->execute();
$linha = $stmt->fetch(PDO::FETCH_ASSOC);

t_true('gravou a linha', $linha !== false);
t_eq('inventario veio da sessao', 45, (int)$linha['id_inventario']);
t_eq('revenda veio da sessao, nao do POST', 1, (int)$linha['revenda']);
t_eq('coletor veio da sessao, nao do POST', 3, (int)$linha['coletor']);
t_eq('dupla veio da sessao, nao do POST', 'Teste Automatizado', $linha['dupla_label']);
t_eq('saldo do POST foi gravado', 6, (int)$linha['saldo_oracle']);
t_eq('status ok gravado', 'ok', $linha['status']);

// ── Troca ───────────────────────────────────────────────────────────────────
$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'registrar'], [
    'item_estoque_pub' => '063806',
    'saldo_oracle'     => '6',
    'origem'           => 'oracle',
    'status'           => 'troca',
    'entrada'          => 'bipado',
]);
t_true('registrar troca responde ok', $r['ok'] ?? null);

$stmt2 = $pdo->prepare("SELECT status FROM inv_conferencias WHERE dupla_label = 'Teste Automatizado' ORDER BY id DESC LIMIT 1");
$stmt2->execute();
t_eq('status troca gravado', 'troca', $stmt2->fetchColumn());

// ── Decisao invalida nao grava nada ─────────────────────────────────────────
$antes = (int)$pdo->query("SELECT COUNT(*) FROM inv_conferencias WHERE dupla_label = 'Teste Automatizado'")->fetchColumn();

$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'registrar'], [
    'item_estoque_pub' => '063806',
    'saldo_oracle'     => '6',
    'origem'           => 'oracle',
    'status'           => 'invencao',
]);
t_eq('decisao inventada responde ok=false', false, $r['ok'] ?? null);

$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'registrar'], [
    'item_estoque_pub' => '063806',
    'saldo_oracle'     => '6',
    'origem'           => 'oracle',
]);
t_eq('registrar sem decisao responde ok=false', false, $r['ok'] ?? null);

$depois = (int)$pdo->query("SELECT COUNT(*) FROM inv_conferencias WHERE dupla_label = 'Teste Automatizado'")->fetchColumn();
t_eq('nenhuma das duas tentativas gravou', $antes, $depois);

// ── Entrada: bipado ou digitado ─────────────────────────────────────────────
$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'registrar'], [
    'item_estoque_pub' => '063806',
    'saldo_oracle'     => '6',
    'origem'           => 'oracle',
    'status'           => 'ok',
    'entrada'          => 'digitado',
]);
t_true('registrar digitado responde ok', $r['ok'] ?? null);
$stmtE = $pdo->prepare("SELECT entrada FROM inv_conferencias WHERE dupla_label = 'Teste Automatizado' ORDER BY id DESC LIMIT 1");
$stmtE->execute();
t_eq('entrada digitado chega ao banco', 'digitado', $stmtE->fetchColumn());

$antesE = (int)$pdo->query("SELECT COUNT(*) FROM inv_conferencias WHERE dupla_label = 'Teste Automatizado'")->fetchColumn();
$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'registrar'], [
    'item_estoque_pub' => '063806',
    'saldo_oracle'     => '6',
    'origem'           => 'oracle',
    'status'           => 'ok',
    'entrada'          => 'teclado',
]);
t_eq('entrada inventada responde ok=false', false, $r['ok'] ?? null);
$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'registrar'], [
    'item_estoque_pub' => '063806',
    'saldo_oracle'     => '6',
    'origem'           => 'oracle',
    'status'           => 'ok',
]);
t_eq('registrar sem entrada responde ok=false', false, $r['ok'] ?? null);
$depoisE = (int)$pdo->query("SELECT COUNT(*) FROM inv_conferencias WHERE dupla_label = 'Teste Automatizado'")->fetchColumn();
t_eq('nenhuma das duas foi gravada', $antesE, $depoisE);

// Saldo vazio no POST (loja desconhecida no fallback) grava null, nao zero.
$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'registrar'], [
    'item_estoque_pub' => '777777',
    'saldo_oracle'     => '',
    'origem'           => 'snapshot',
    'status'           => 'ok',
    'entrada'          => 'bipado',
]);
t_true('registrar sem saldo responde ok', $r['ok'] ?? null);

$stmt = $pdo->prepare("SELECT saldo_oracle, origem FROM inv_conferencias WHERE item_estoque_pub = '777777' ORDER BY id DESC LIMIT 1");
$stmt->execute();
$linha2 = $stmt->fetch(PDO::FETCH_ASSOC);
t_null('saldo vazio grava null, nao zero', $linha2['saldo_oracle']);
t_eq('origem snapshot respeitada', 'snapshot', $linha2['origem']);

// Origem desconhecida vinda do POST nao entra crua no banco.
$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'registrar'], [
    'item_estoque_pub' => '666666',
    'saldo_oracle'     => '1',
    'origem'           => 'invencao',
    'status'           => 'ok',
    'entrada'          => 'bipado',
]);
$stmt = $pdo->prepare("SELECT origem FROM inv_conferencias WHERE item_estoque_pub = '666666' ORDER BY id DESC LIMIT 1");
$stmt->execute();
t_eq('origem desconhecida vira oracle', 'oracle', $stmt->fetchColumn());

// Registrar sem item nao grava.
$r = chamar(['inventario' => SESSAO_TESTE], ['modo' => 'registrar'], ['item_estoque_pub' => '', 'status' => 'ok']);
t_eq('registrar sem item responde ok=false', false, $r['ok'] ?? null);

// ── Porta lateral: só a sessão da Conferência com PIN grava ─────────────────
$validoPost = [
    'item_estoque_pub' => '063806',
    'saldo_oracle'     => '6',
    'origem'           => 'oracle',
    'status'           => 'ok',
    'entrada'          => 'bipado',
];
$antesP = (int)$pdo->query("SELECT COUNT(*) FROM inv_conferencias WHERE dupla_label = 'Teste Automatizado'")->fetchColumn();

// Coletor da 1ª contagem (sem PIN) chamando o endpoint direto.
$sessaoContagem1 = SESSAO_TESTE;
$sessaoContagem1['contagem'] = 1;
unset($sessaoContagem1['conferente_id']);
$r = chamar(['inventario' => $sessaoContagem1], ['modo' => 'registrar'], $validoPost);
t_eq('contagem 1 nao registra', false, $r['ok'] ?? null);
t_eq('contagem 1 recebe sessao expirada', 'Sessão expirada', $r['mensagem'] ?? null);

// Conferência de antes do PIN: nome livre, sem conferente_id.
$sessaoSemPin = SESSAO_TESTE;
unset($sessaoSemPin['conferente_id']);
$r = chamar(['inventario' => $sessaoSemPin], ['modo' => 'registrar'], $validoPost);
t_eq('conferencia sem conferente_id nao registra', false, $r['ok'] ?? null);
t_eq('sem conferente_id recebe sessao expirada', 'Sessão expirada', $r['mensagem'] ?? null);

$depoisP = (int)$pdo->query("SELECT COUNT(*) FROM inv_conferencias WHERE dupla_label = 'Teste Automatizado'")->fetchColumn();
t_eq('porta lateral nao gravou nada', $antesP, $depoisP);

// Limpeza.
$pdo->prepare("DELETE FROM inv_conferencias WHERE dupla_label = 'Teste Automatizado'")->execute();
$stmt = $pdo->query("SELECT COUNT(*) FROM inv_conferencias WHERE dupla_label = 'Teste Automatizado'");
t_eq('teste limpou o que criou', 0, (int)$stmt->fetchColumn());

t_fim();
