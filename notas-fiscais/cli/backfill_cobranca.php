<?php
// @versao Cap_14 V_01_05/08/2026
declare(strict_types=1);

/**
 * Backfill da cobrança do XML — CLI (execução pontual, depois da migration 036).
 *
 * Duas passadas:
 *
 *   1. NOTAS  — toda NF-e capturada com `cobranca` NULL tem o XML relido do
 *               storage para gravar fatura + duplicatas + tPag no cache.
 *
 *   2. PROCESSOS — processo aberto a partir de uma dessas notas, com fluxo
 *               financeiro e NENHUMA parcela, recebe as parcelas das duplicatas.
 *               É o caso do PROC-2026-7760: abriu por regra às 03:00 sem
 *               parcela e alguém redigitou às 08:50 o que já estava no XML.
 *
 * O recorte da passada 2 é deliberadamente conservador — só mexe em processo
 * que ninguém tocou no financeiro:
 *   · status 'aberto', não concluído, não deletado;
 *   · template com tem_financeiro = 1;
 *   · ZERO linhas em nf_parcelas (inclusive deletadas — parcela apagada é
 *     decisão humana, não lacuna a preencher).
 *
 * Uso:
 *   php cli/backfill_cobranca.php                    simula e mostra o que faria
 *   php cli/backfill_cobranca.php --aplicar          grava
 *   php cli/backfill_cobranca.php --aplicar --limite=200
 *
 * Sem --aplicar tudo roda dentro de uma transação que termina em ROLLBACK:
 * o relatório é o mesmo, o banco fica intacto.
 */

define('ROOT', dirname(__DIR__));
define('SRC', ROOT . '/src');
define('STORAGE', ROOT . '/storage');
date_default_timezone_set('America/Sao_Paulo');

require ROOT . '/config/database.php';
require ROOT . '/src/Helpers/XmlDfeParser.php';
require ROOT . '/src/Models/CapturedNote.php';
require ROOT . '/src/Models/Process.php';

$args    = array_slice($argv, 1);
$aplicar = in_array('--aplicar', $args, true);
$limite  = 0;
foreach ($args as $a) {
    if (preg_match('/^--limite=(\d+)$/', $a, $m)) $limite = (int) $m[1];
}

$pdo = Database::getInstance();
$pdo->beginTransaction();

$log = static function (string $msg): void {
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
};

$log($aplicar ? 'Modo APLICAR — as alterações serão gravadas.' : 'Modo SIMULAÇÃO — nada será gravado (use --aplicar).');

// ─────────────────────────────────────────────────────────────
// Passada 1 — cache da cobrança nas notas capturadas
// ─────────────────────────────────────────────────────────────

$sql = "SELECT * FROM nf_notas_capturadas
         WHERE tipo_documento = 'NFe' AND cobranca IS NULL
         ORDER BY capturada_em DESC";
if ($limite > 0) $sql .= ' LIMIT ' . $limite;

$notas    = $pdo->query($sql)->fetchAll();
$comFat   = 0;
$semXml   = 0;
$semCobr  = 0;

foreach ($notas as $i => $n) {
    $cobranca = CapturedNote::cobrancaDaNota($notas[$i]);
    if ($cobranca === null)              { $semXml++;  continue; }
    if (empty($cobranca['duplicatas']))  { $semCobr++; continue; }
    $comFat++;
}

$log(sprintf(
    'Notas: %d sem cache · %d com duplicatas · %d sem bloco de cobrança · %d sem XML no storage',
    count($notas), $comFat, $semCobr, $semXml
));

// ─────────────────────────────────────────────────────────────
// Passada 2 — parcelas nos processos abertos sem nenhuma parcela
// ─────────────────────────────────────────────────────────────

$sql = "SELECT c.*, p.id AS proc_id, p.codigo AS proc_codigo, p.criado_por AS proc_criado_por,
               p.etapa_atual AS proc_etapa, p.is_teste AS proc_is_teste, p.valor_nf AS proc_valor
          FROM nf_notas_capturadas c
          JOIN nf_processos p        ON p.id = c.processo_id
          JOIN nf_template_versoes v ON v.id = p.template_versao_id
         WHERE c.tipo_documento = 'NFe'
           AND c.status = 'vinculada'
           AND p.status = 'aberto'
           AND p.concluido_em IS NULL
           AND p.deletado_em  IS NULL
           AND v.tem_financeiro = 1
           AND NOT EXISTS (SELECT 1 FROM nf_parcelas pa WHERE pa.processo_id = p.id)
         ORDER BY p.criado_em DESC";
if ($limite > 0) $sql .= ' LIMIT ' . $limite;

$candidatos = $pdo->query($sql)->fetchAll();

$stmtParcela = $pdo->prepare(
    "INSERT INTO nf_parcelas
        (id, processo_id, numero_parcela, total_parcelas, vencimento, valor,
         forma_pagamento_id, status, is_teste, criado_em)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'previsto', ?, NOW())"
);

$processosOk = 0;
$parcelasOk  = 0;
$semDup      = 0;
$divergentes = [];

foreach ($candidatos as $i => $cand) {
    $parcelas = CapturedNote::parcelasDaNota($candidatos[$i]);
    if ($parcelas === []) { $semDup++; continue; }

    $total = count($parcelas);
    foreach ($parcelas as $n => $p) {
        $stmtParcela->execute([
            Process::uuid_v4(), $cand['proc_id'], $n + 1, $total,
            $p['vencimento'], $p['valor'], $p['forma_pagamento_id'],
            (int) $cand['proc_is_teste'],
        ]);
        $parcelasOk++;
    }

    $soma = array_sum(array_column($parcelas, 'valor'));
    $detalhe = sprintf(
        '%d parcela(s) geradas das duplicatas do XML%s: %s.',
        $total,
        !empty($cand['numero_fatura']) ? ' (fatura ' . $cand['numero_fatura'] . ')' : '',
        implode(' · ', array_map(
            static fn(array $p) => date('d/m/Y', strtotime($p['vencimento'])) . ' R$ ' . number_format($p['valor'], 2, ',', '.'),
            $parcelas
        ))
    );

    Process::registrarHistorico(
        (string) $cand['proc_id'],
        'processo_dados_alterados',
        (string) $cand['proc_criado_por'],
        'Importação XML',
        $detalhe,
        (int) $cand['proc_etapa']
    );

    // Duplicata que não fecha com o valor da nota é normal (entrada, desconto),
    // mas quem confere o financeiro precisa saber quais olhar.
    if (abs($soma - (float) $cand['proc_valor']) >= 0.01) {
        $divergentes[] = sprintf(
            '%s — nota R$ %s vs duplicatas R$ %s',
            $cand['proc_codigo'],
            number_format((float) $cand['proc_valor'], 2, ',', '.'),
            number_format($soma, 2, ',', '.')
        );
    }

    $processosOk++;
    $log(sprintf('  %s → %s', $cand['proc_codigo'], $detalhe));
}

$log(sprintf(
    'Processos: %d candidatos · %d receberam parcela (%d parcelas) · %d sem duplicata no XML',
    count($candidatos), $processosOk, $parcelasOk, $semDup
));

if ($divergentes) {
    $log('Conferir — soma das duplicatas diferente do valor da nota:');
    foreach ($divergentes as $d) $log('  · ' . $d);
}

if ($aplicar) {
    $pdo->commit();
    $log('Alterações gravadas.');
} else {
    $pdo->rollBack();
    $log('Simulação encerrada — nada foi gravado. Rode com --aplicar para valer.');
}
