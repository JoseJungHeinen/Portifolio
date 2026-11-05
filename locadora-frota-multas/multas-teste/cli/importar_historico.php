<?php
/**
 * Importa o histórico da planilha CONTROLE DE MULTAS para o banco.
 *
 *   python -I scripts/exportar_planilhas.py --controle "...CONTROLE DE MULTAS - Nova.xlsx" --captura "C:/RPA/Multas_Geral.xlsx"
 *   php cli/importar_historico.php [data/import]
 *
 * Pode rodar de novo: multas existentes (mesma série) são substituídas pelos dados da planilha.
 */
require __DIR__ . '/../config.php';

$pasta = $argv[1] ?? __DIR__ . '/../data/import';
$ler = fn($n) => json_decode(file_get_contents("$pasta/$n"), true, 512, JSON_THROW_ON_ERROR);

$controle = $ler('controle_geral.json');
$detran = $ler('detran.json');
$veiculos = $ler('veiculos.json');
$orgaos = $ler('orgaos.json');
$infracoes = $ler('infracoes.json');

$pdo = db();
$pdo->beginTransaction();

foreach (LOJAS as $l) loja_id($l);

$st = $pdo->prepare('INSERT INTO orgaos_autuadores (codigo, nome) VALUES (?, ?) ON DUPLICATE KEY UPDATE nome = VALUES(nome)');
foreach ($orgaos as $o) $st->execute([$o['codigo'], $o['nome']]);

$st = $pdo->prepare('INSERT INTO infracoes_ctb (codigo, descricao, amparo_legal, infrator, gravidade, orgao_competente)
                     VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE descricao = VALUES(descricao)');
foreach ($infracoes as $i) $st->execute([$i['codigo'], $i['descricao'], $i['amparoLegal'], $i['infrator'], $i['gravidade'], $i['orgaoCompetente']]);

$st = $pdo->prepare('INSERT INTO veiculos_frota (placa, modelo, renavam, loja_id, empresa) VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE modelo = VALUES(modelo), renavam = VALUES(renavam), loja_id = VALUES(loja_id), empresa = VALUES(empresa)');
foreach ($veiculos as $v) {
    $st->execute([$v['placa'], $v['modelo'], $v['renavam'], loja_id(loja_canonica($v['loja'])), $v['empresa']]);
}
$veiculoId = $pdo->query('SELECT placa, id FROM veiculos_frota')->fetchAll(PDO::FETCH_KEY_PAIR);

// Coluna T da planilha: XLOOKUP(série, Detran!E, Detran!J) → primeira ocorrência.
$noDetran = [];
foreach ($detran as $d) {
    $k = strtoupper($d['serie']);
    if (!isset($noDetran[$k])) $noDetran[$k] = $d;
}

$simNao = function (?string $s): ?string {
    if ($s === null) return null;
    $k = mb_strtolower(trim($s));
    if ($k === 'sim') return 'Sim';
    if ($k === 'não' || $k === 'nao') return 'Não';
    return trim($s);
};

$colunas = ['serie', 'placa', 'veiculo_id', 'loja_id', 'orgao_codigo', 'autuador', 'tipo', 'codigo_infracao', 'valor',
    'data_infracao', 'data_infracao_texto', 'vencimento_detran', 'vencimento_pendente', 'presente_no_detran',
    'primeira_captura_em', 'prazo_identificacao', 'prazo_identificacao_nota', 'email_enviado', 'identificacao_condutor',
    'fatura_numero', 'pagamento_situacao', 'ocorrencia_especial', 'observacoes', 'etapa', 'status', 'origem', 'import_raw'];
$st = $pdo->prepare('INSERT INTO multas (' . implode(',', $colunas) . ') VALUES (' . rtrim(str_repeat('?,', count($colunas)), ',') . ')
    ON DUPLICATE KEY UPDATE ' . implode(',', array_map(fn($c) => "$c = VALUES($c)", array_diff($colunas, ['serie']))));

$vistas = [];
$duplicadas = 0;
foreach ($controle as $l) {
    $k = strtoupper($l['serie']);
    if (isset($vistas[$k])) { $duplicadas++; continue; }
    $vistas[$k] = true;

    $d = $noDetran[$k] ?? null;
    $presente = $d !== null && ($d['vencimentoTexto'] ?? '') !== '';

    // Prazo: data ISO, data dd/mm/aaaa em texto, ou uma nota ("Não se aplica", "recurso"...)
    $prazo = $l['prazoIdentificacao'];
    $prazoData = ($prazo && preg_match('~^\d{4}-\d{2}-\d{2}~', $prazo)) ? substr($prazo, 0, 10) : data_br_para_iso($prazo);
    $prazoNota = ($prazo && !$prazoData) ? mb_substr($prazo, 0, 60) : null;

    $placa = strtoupper((string)$l['placa']);
    $m = com_status([
        'serie' => $l['serie'],
        'placa' => $placa,
        'veiculo_id' => $veiculoId[$placa] ?? null,
        'loja_id' => loja_id(loja_canonica($l['loja'])),
        'orgao_codigo' => $l['orgaoCodigo'],
        'autuador' => $l['autuador'],
        'tipo' => $l['tipo'] ? (mb_strtolower($l['tipo']) === 'remulta' ? 'remulta' : 'multa') : null,
        'codigo_infracao' => ($l['codigoInfracao'] && $l['codigoInfracao'] !== 'erro') ? $l['codigoInfracao'] : null,
        'valor' => $l['valor'],
        'data_infracao' => $l['dataInfracao'] ? str_replace('T', ' ', $l['dataInfracao']) : null,
        'data_infracao_texto' => $l['dataInfracaoTexto'],
        'vencimento_detran' => $presente ? $d['vencimento'] : null,
        'vencimento_pendente' => (int)($presente && $d['vencimentoTexto'] === '--'),
        'presente_no_detran' => (int)$presente,
        'primeira_captura_em' => $l['dataCaptura'] ? substr($l['dataCaptura'], 0, 10) : null,
        'prazo_identificacao' => $prazoData,
        'prazo_identificacao_nota' => $prazoNota,
        'email_enviado' => $simNao($l['email']),
        'identificacao_condutor' => $simNao($l['identificado']),
        'fatura_numero' => $l['faturado'],
        'pagamento_situacao' => $l['pagamento'] ? mb_substr($l['pagamento'], 0, 60) : null,
        'ocorrencia_especial' => strtoupper(trim((string)$l['planilhaEtapa'])) === 'FRAUDE' ? 'fraude' : null,
        'observacoes' => $l['obs'],
        'origem' => 'planilha',
        'import_raw' => json_encode($l, JSON_UNESCAPED_UNICODE),
    ]);
    $st->execute(array_map(fn($c) => $m[$c], $colunas));
}

// Registra a aba Detran como a captura de referência do histórico.
$lojasDetran = array_values(array_unique(array_filter(array_map(fn($d) => loja_canonica($d['loja']), $detran))));
$pdo->prepare('INSERT INTO capturas_detran (origem, arquivo, lojas_cobertas, total_lidas, novas, atualizadas, sairam_do_detran)
               VALUES ("planilha", "CONTROLE DE MULTAS - aba Detran", ?, ?, 0, ?, 0)')
    ->execute([json_encode($lojasDetran, JSON_UNESCAPED_UNICODE), count($detran), count($detran)]);

$pdo->commit();

$tot = $pdo->query('SELECT COUNT(*) FROM multas')->fetchColumn();
echo "Importado: " . count($orgaos) . " órgãos, " . count($infracoes) . " infrações, " . count($veiculos) . " veículos, $tot multas"
    . ($duplicadas ? " ($duplicadas séries duplicadas ignoradas)" : '') . "\n";
