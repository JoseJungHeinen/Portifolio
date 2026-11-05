<?php
/**
 * Confere o sistema contra a planilha:
 *  1. etapa de cada multa no banco = coluna S do Controle Geral
 *  2. parser da captura = fórmulas da aba DADOS (lendo o .xlsx do robô direto)
 *
 *   php cli/validar.php [C:/RPA/Multas_Geral.xlsx]
 */
require __DIR__ . '/../config.php';
$falhas = 0;

// ---- 1. Etapas
$div = [];
$n = 0;
foreach (db()->query("SELECT serie, etapa, JSON_UNQUOTE(JSON_EXTRACT(import_raw, '$.planilhaEtapa')) AS planilha FROM multas WHERE origem = 'planilha'") as $r) {
    $n++;
    if (ETAPAS[$r['etapa']] !== trim((string)$r['planilha'])) $div[] = "$r[serie]: sistema=" . ETAPAS[$r['etapa']] . " planilha=$r[planilha]";
}
echo "1. Etapas: $n multas, " . count($div) . " divergências\n";
foreach (array_slice($div, 0, 10) as $d) echo "   $d\n";
$falhas += count($div);

// ---- 2. Parser da captura x fórmulas da aba DADOS
$arquivo = $argv[1] ?? 'C:/RPA/Multas_Geral.xlsx';
if (is_file($arquivo)) {
    $linhas = xlsx_ler_aba($arquivo, 'DADOS', 26);
    $erros = [];
    $cards = 0;
    foreach ($linhas as $i => $r) {
        if ($i === 1 || empty($r[0])) continue;
        $c = interpretar_cartao(array_map(fn($k) => $r[$k] ?? '', range(0, 7)));
        $valida = ($r[24] ?? null) === 'P';
        if (!$c || !$valida) {
            if ((bool)$c !== $valida) $erros[] = "linha $i: válida sistema=" . (int)(bool)$c . " planilha=" . (int)$valida;
            continue;
        }
        $cards++;
        $venc = ($r[17] ?? '') === '--' ? null : data_br_para_iso($r[17] ?? '');
        $checar = [
            'placa' => [$c['placa'], $r[8] ?? null],
            'série' => [$c['serie'], $r[12] ?? null],
            'órgão' => [$c['orgao_codigo'], $r[10] ?? null],
            'tipo' => [$c['tipo'], mb_strtolower((string)($r[13] ?? ''))],
            'valor' => [number_format((float)$c['valor'], 2), number_format((float)($r[15] ?? 0), 2)],
            'data' => [$c['data_infracao_texto'], trim((string)($r[16] ?? ''))],
            'vencimento' => [$c['vencimento_detran'], $venc],
        ];
        foreach ($checar as $campo => [$s, $p]) {
            if ((string)$s !== (string)$p) $erros[] = "linha $i $campo: sistema=$s planilha=$p";
        }
    }
    echo "2. Parser da captura: $cards cards, " . count($erros) . " divergências\n";
    foreach (array_slice($erros, 0, 10) as $e) echo "   $e\n";
    $falhas += count($erros);
}

// ---- 3. Painel
echo "\n3. Painel (banco):\n";
$q = db()->query('SELECT etapa, COUNT(*) q, SUM(valor) v FROM multas GROUP BY etapa')->fetchAll(PDO::FETCH_UNIQUE);
foreach (LINHAS_PAINEL as $l) {
    $qt = 0; $vl = 0;
    foreach ($l['etapas'] as $e) { $qt += $q[$e]['q'] ?? 0; $vl += $q[$e]['v'] ?? 0; }
    printf("   %-40s %5d  R$ %12s\n", $l['rotulo'], $qt, number_format($vl, 2, ',', '.'));
}
exit($falhas ? 1 : 0);
