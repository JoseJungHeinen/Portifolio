<?php
/**
 * Captura do DETRAN → multas.
 *  - interpretar_cartao(): um card do portal (as 8 colunas que o robô grava
 *    na aba "Captura") → campos. Mesmo resultado das fórmulas da aba DADOS.
 *  - aplicar_captura(): o "colar em DETRAN, filtrar novas, colar no Controle Geral".
 */

function data_br_para_iso(?string $s): ?string
{
    return ($s && preg_match('~(\d{2})/(\d{2})/(\d{4})~', $s, $m)) ? "$m[3]-$m[2]-$m[1]" : null;
}

/**
 * @param array $c [placaModelo, orgao, serie, descricao, ocorrida, local, situacao, loja]
 * @return array|null null se a linha não é card de multa
 */
function interpretar_cartao(array $c): ?array
{
    $pm = trim((string)($c[0] ?? ''));
    if ($pm === '' || $pm[0] !== 'P') return null;
    if (!preg_match('~^Placa\s+([A-Z0-9]{7})~i', $pm, $mp)) return null;
    $serie = trim(preg_replace('~^\s*S(é|e)rie:\s*~iu', '', (string)($c[2] ?? '')));
    if ($serie === '') return null;

    $partes = explode(' - ', $pm);
    array_shift($partes);
    $modelo = trim(implode(' - ', $partes)) ?: null;

    preg_match('~(\d+)~', (string)($c[1] ?? ''), $mo);
    $dataTxt = trim(preg_replace('~^\s*Ocorrida em:\s*~iu', '', (string)($c[4] ?? ''))) ?: null;
    $dia = data_br_para_iso($dataTxt);
    $dataInfracao = null;
    if ($dia) {
        $dataInfracao = preg_match('~(\d{2}):(\d{2})~', $dataTxt, $mh) ? "$dia $mh[1]:$mh[2]:00" : "$dia 00:00:00";
    }

    $sit = trim((string)($c[6] ?? ''));
    $valor = null;
    if (preg_match('~R\$\s*([\d.]+,\d{2})~u', $sit, $mv)) {
        $valor = (float)str_replace(['.', ','], ['', '.'], $mv[1]);
    }
    $descricao = trim((string)($c[3] ?? ''));

    return [
        'serie'               => $serie,
        'placa'               => strtoupper($mp[1]),
        'modelo'              => $modelo,
        'orgao_codigo'        => $mo[1] ?? null,
        'descricao'           => $descricao,
        'local_infracao'      => trim(preg_replace('~^\s*Local:\s*~iu', '', (string)($c[5] ?? ''))) ?: null,
        'data_infracao'       => $dataInfracao,
        'data_infracao_texto' => $dataTxt,
        'situacao_detran'     => preg_match('~^vencida~i', $sit) ? 'vencida' : 'a_vencer',
        'vencimento_detran'   => data_br_para_iso(explode('R$', $sit)[0]),
        'valor'               => $valor,
        // A multa por não identificar o condutor é a "Remulta" da planilha.
        'tipo'                => preg_match('~^multa~i', $descricao) ? 'remulta' : 'multa',
        'loja'                => trim((string)($c[7] ?? '')),
    ];
}

/**
 * Um item da API do PCSDetran (GET infracoes/requerentes/{cpf}?grupo=5&cnpj=...) → mesmo formato de interpretar_cartao().
 * Campos usados: serieAIT, placa, marcaVeiculo, codOat, orgaoFiscalizador, codInfracao, descrInfracao,
 * local, data, hora, valor, grupo, codGrupo (1 = a vencer, 2 = vencida), dataLimApresCond, pontuacao.
 */
function interpretar_item_api(array $i, string $cnpj): ?array
{
    $serie = strtoupper(trim((string)($i['serieAIT'] ?? '')));
    $placa = strtoupper(preg_replace('~[^A-Z0-9]~i', '', (string)($i['placa'] ?? '')));
    if ($serie === '' || strlen($placa) !== 7) return null;

    $data = trim((string)($i['data'] ?? ''));
    $hora = trim((string)($i['hora'] ?? ''));
    $dia = data_br_para_iso($data);
    $grupo = trim((string)($i['grupo'] ?? ''));
    $valor = null;
    if (preg_match('~([\d.]+,\d{2})~', (string)($i['valor'] ?? ''), $mv)) $valor = (float)str_replace(['.', ','], ['', '.'], $mv[1]);
    $descricao = trim((string)($i['descrInfracao'] ?? ''));
    $codInfracao = preg_replace('~\D~', '', (string)($i['codInfracao'] ?? '')) ?: null;
    $prazo = $i['dataLimApresCond'] ?? null;
    $prazo = $prazo ? (data_br_para_iso((string)$prazo) ?? (preg_match('~^\d{4}-\d{2}-\d{2}~', (string)$prazo) ? substr($prazo, 0, 10) : null)) : null;
    $vencida = (int)($i['codGrupo'] ?? 0) === 2 || preg_match('~^vencida~i', $grupo);

    return [
        'serie'               => $serie,
        'placa'               => $placa,
        'modelo'              => trim((string)($i['marcaVeiculo'] ?? '')) ?: null,
        'orgao_codigo'        => isset($i['codOat']) ? (string)$i['codOat'] : null,
        'autuador'            => trim((string)($i['orgaoFiscalizador'] ?? '')) ?: null,
        'codigo_infracao'     => $codInfracao,
        'descricao'           => $descricao,
        'local_infracao'      => trim((string)($i['local'] ?? '')) ?: null,
        'data_infracao'       => $dia ? $dia . ' ' . (preg_match('~^\d{2}:\d{2}~', $hora) ? substr($hora, 0, 5) : '00:00') . ':00' : null,
        // Mesmo texto que a planilha guarda: "22/10/2026 às 16:43"
        'data_infracao_texto' => $data !== '' ? trim("$data às $hora") : null,
        'situacao_detran'     => $vencida ? 'vencida' : 'a_vencer',
        'vencimento_detran'   => data_br_para_iso($grupo),
        'valor'               => $valor,
        'pontuacao'           => isset($i['pontuacao']) ? (int)$i['pontuacao'] : null,
        'prazo_identificacao' => $prazo,
        'tipo'                => preg_match('~^multa~i', $descricao) ? 'remulta' : 'multa',
        'cnpj_detran'         => preg_replace('~\D~', '', $cnpj),
        'loja'                => '',
    ];
}

/**
 * @param array $existentes [serie => ['presente_no_detran'=>bool, 'loja'=>string]]
 * @param array $capturadas saída de interpretar_cartao()
 * @param string[] $lojasCobertas lojas percorridas por completo nesta captura
 */
function aplicar_captura(array $existentes, array $capturadas, array $lojasCobertas): array
{
    $porSerie = [];
    foreach ($existentes as $serie => $e) {
        $k = strtoupper(trim($serie));
        if (!isset($porSerie[$k])) $porSerie[$k] = $e + ['serie' => $serie];
    }
    $res = ['novas' => [], 'atualizadas' => [], 'sairam' => [], 'repetidas' => []];
    $vistas = [];
    foreach ($capturadas as $c) {
        $k = strtoupper(trim($c['serie']));
        if (isset($vistas[$k])) { $res['repetidas'][] = $c['serie']; continue; }
        $vistas[$k] = true;
        if (isset($porSerie[$k])) $res['atualizadas'][] = $c; else $res['novas'][] = $c;
    }
    $cobertas = array_flip(array_map(fn($l) => mb_strtolower(trim($l)), $lojasCobertas));
    foreach ($porSerie as $k => $e) {
        if (empty($e['presente_no_detran']) || isset($vistas[$k])) continue;
        if (isset($cobertas[mb_strtolower(trim((string)($e['loja'] ?? '')))])) $res['sairam'][] = $e['serie'];
    }
    return $res;
}

/** Remulta já entra com e-mail e identificação "Não" (aba DETRAN: =SE(F2="Remulta";"Não";"")). */
function campos_iniciais_da_nova(array $c): array
{
    return $c['tipo'] === 'remulta'
        ? ['email_enviado' => 'Não', 'identificacao_condutor' => 'Não']
        : ['email_enviado' => null, 'identificacao_condutor' => null];
}
