<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'conexao_oracle.php';
require 'conferencia_lib.php';
require __DIR__ . '/assert.php';

echo "test_transferencia\n";

// ── Etiqueta → cinco campos: puro ───────────────────────────────────────────
$c = conferencia_locacao_em_campos('1-22-13-a-1');
t_eq('etiqueta vira os cinco campos', ['zona' => '1', 'rua' => 22, 'estante' => 13, 'prateleira' => 'A', 'numero' => 1], $c['valores']);
t_eq('etiqueta valida sem erro', [], $c['erros']);
t_null('codigo de item nao e etiqueta', conferencia_locacao_em_campos('52091109'));
t_true('rua 100 nao cabe no Apollo', isset(conferencia_locacao_em_campos('1-100-1-A-1')['erros']['rua']));

// ── Registro no MySQL ───────────────────────────────────────────────────────
$base = [
    'id_inventario' => 0, 'empresa' => 1, 'revenda_origem' => 1, 'revenda_destino' => 5,
    'dupla_label' => 'TESTE', 'item_estoque_pub' => 'TESTE-TRANSF', 'cod_item_estoque' => '1',
    'descricao' => 'teste', 'quantidade' => 2, 'locacao_destino' => '1-1-1-A-1', 'entrada_locacao' => 'bipado',
];
$id = conferencia_registrar_transferencia($pdo, $base);
$st = fn() => $pdo->query("SELECT status, saldo_origem_antes, saldo_destino_antes, erro, gravado_em FROM inv_transferencias WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
t_eq('nasce pendente', 'pendente', $st()['status']);
conferencia_concluir_transferencia($pdo, $id, ['origem_antes' => 3, 'destino_antes' => 0]);
$g = $st();
t_eq('gravado com saldos de antes', ['gravado', 3, 0], [$g['status'], (int)$g['saldo_origem_antes'], (int)$g['saldo_destino_antes']]);
t_true('gravado tem data', $g['gravado_em'] !== null);
conferencia_concluir_transferencia($pdo, $id, null, 'motivo');
t_eq('erro guarda o motivo', ['erro', 'motivo'], [$st()['status'], $st()['erro']]);
$pdo->exec("DELETE FROM inv_transferencias WHERE id = $id");

$semEntrada = false;
try { conferencia_registrar_transferencia($pdo, ['entrada_locacao' => ''] + $base); } catch (InvalidArgumentException $e) { $semEntrada = true; }
t_true('sem entrada nao registra', $semEntrada);

// ── Apollo ──────────────────────────────────────────────────────────────────
$ora = oracle_conectar();
if (!$ora) {
    echo "  SKIP (Apollo): indisponivel\n";
    t_fim();
}

// Fixture: 52091109 = item 48714, Cidade A (empresa 1): Fiat (1) com saldo,
// Jeep (5) zerada. Tudo SEM commit e desfeito: este e o Apollo de producao.
$ITEM = 48714;

$ler = function () use ($ora, $ITEM) {
    $out = [];
    foreach (conferencia_ora_consultar($ora, "
        SELECT revenda, qtd_contabil, qtd_alocada, locacao_zona, locacao_rua, locacao_estante,
               locacao_prateleira, locacao_numero
          FROM pec_item_revenda
         WHERE empresa = 1 AND item_estoque = :item AND revenda IN (1, 5)
    ", [':item' => $ITEM]) as $l) {
        $out[(int)$l['REVENDA']] = $l;
    }
    return $out;
};

$antes = $ler();
$saldoFiat = (int)$antes[1]['QTD_CONTABIL'] - (int)$antes[1]['QTD_ALOCADA'];
$saldoJeep = (int)$antes[5]['QTD_CONTABIL'] - (int)$antes[5]['QTD_ALOCADA'];
if ($saldoFiat < 2 || $saldoJeep > 0) {
    echo "  SKIP (Apollo): fixture mudou (Fiat $saldoFiat, Jeep $saldoJeep) — escolher outro item\n";
    t_fim();
}

$loc = ['zona' => 'ZZ', 'rua' => 99, 'estante' => 999, 'prateleira' => 'ZZ', 'numero' => 999];

try {
    $r = conferencia_transferir_saldo($ora, 1, $ITEM, 1, 5, 2, $loc, false);
    t_eq('devolve os saldos de antes', ['origem_antes' => $saldoFiat, 'destino_antes' => $saldoJeep], $r);

    $durante = $ler();
    t_eq('origem perdeu 2', (int)$antes[1]['QTD_CONTABIL'] - 2, (int)$durante[1]['QTD_CONTABIL']);
    t_eq('destino ganhou 2', (int)$antes[5]['QTD_CONTABIL'] + 2, (int)$durante[5]['QTD_CONTABIL']);
    t_eq('destino ficou com a locacao bipada', 'ZZ-99-999-ZZ-999', implode('-', [
        trim((string)$durante[5]['LOCACAO_ZONA']), $durante[5]['LOCACAO_RUA'], $durante[5]['LOCACAO_ESTANTE'],
        trim((string)$durante[5]['LOCACAO_PRATELEIRA']), $durante[5]['LOCACAO_NUMERO'],
    ]));
    t_eq('locacao da origem nao mudou', $antes[1]['LOCACAO_NUMERO'], $durante[1]['LOCACAO_NUMERO']);
} finally {
    oci_rollback($ora);
}
t_eq('depois do rollback, o Apollo ficou como estava', $antes, $ler());

// Recusas: nenhuma pode alterar nada.
$recusa = function (string $nome, callable $f) use ($ora, $ler, $antes) {
    $msg = null;
    try { $f(); } catch (InvalidArgumentException $e) { $msg = $e->getMessage(); } finally { oci_rollback($ora); }
    t_true($nome . ' (' . ($msg ?? 'nao recusou') . ')', $msg !== null);
    t_eq($nome . ': nada mudou', $antes, $ler());
};

$recusa('mais que o saldo da origem', fn() => conferencia_transferir_saldo($ora, 1, $ITEM, 1, 5, $saldoFiat + 1, $loc, false));
$recusa('destino com saldo (Fiat nao esta zerada)', fn() => conferencia_transferir_saldo($ora, 1, $ITEM, 5, 1, 1, $loc, false));
$recusa('quantidade zero', fn() => conferencia_transferir_saldo($ora, 1, $ITEM, 1, 5, 0, $loc, false));
$recusa('origem igual ao destino', fn() => conferencia_transferir_saldo($ora, 1, $ITEM, 5, 5, 1, $loc, false));

// 5945181 = item 54594, sem linha na Jeep (revenda 5): sem INSERT, recusa.
$semLinha = null;
try { conferencia_transferir_saldo($ora, 1, 54594, 1, 5, 1, $loc, false); }
catch (InvalidArgumentException $e) { $semLinha = $e->getMessage(); }
finally { oci_rollback($ora); }
t_true('destino sem linha no Apollo recusa', $semLinha !== null && strpos($semLinha, 'cadastrado nesta filial') !== false);

t_fim();
