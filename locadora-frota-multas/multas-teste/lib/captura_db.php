<?php
/**
 * Aplica uma captura do DETRAN no banco (o "colar em DETRAN → filtrar novas → colar no Controle Geral").
 * Aceita duas fontes:
 *   - .xlsx do robô RPA (aba "Captura", colunas A:H)
 *   - .json do coletor do portal (botão de favorito, API do PCSDetran)
 */

/** CNPJs configurados para o coletor: [['cnpj' => ..., 'loja' => ...], ...] */
function cnpjs_coletor(): array
{
    $v = db()->query("SELECT valor FROM configuracoes WHERE chave = 'coletor_cnpjs'")->fetchColumn();
    return $v ? (json_decode($v, true) ?: []) : [];
}

/**
 * Loja de cada multa: 1º o cadastro do veículo (bate em 100% dos casos conhecidos),
 * 2º a loja de multas anteriores da mesma placa, 3º o CNPJ em que apareceu, 4º o que veio no arquivo (robô).
 */
function atribuir_lojas(array $cards): array
{
    // Placa fora do cadastro da frota: usa a loja da multa mais recente da mesma placa.
    $porPlaca = db()->query('SELECT m.placa, l.nome FROM multas m JOIN lojas l ON l.id = m.loja_id
                             ORDER BY m.data_infracao IS NULL DESC, m.data_infracao')->fetchAll(PDO::FETCH_KEY_PAIR);
    $porPlaca = db()->query('SELECT v.placa, l.nome FROM veiculos_frota v JOIN lojas l ON l.id = v.loja_id')->fetchAll(PDO::FETCH_KEY_PAIR) + $porPlaca;
    $porCnpj = [];
    foreach (cnpjs_coletor() as $c) if (!empty($c['loja'])) $porCnpj[$c['cnpj']] = $c['loja'];
    foreach ($cards as &$c) {
        $c['loja'] = $porPlaca[$c['placa']] ?? ($porCnpj[$c['cnpj_detran'] ?? ''] ?? null) ?? loja_canonica($c['loja'] ?? '') ?? '';
    }
    return $cards;
}

/** @return array{fonte:string, cards:array, empresas:?array} */
function ler_captura_arquivo(string $arquivo, string $nome): array
{
    if (strtolower(pathinfo($nome, PATHINFO_EXTENSION)) === 'json') return ler_captura_json($arquivo);
    return ['fonte' => 'robo_excel', 'cards' => ler_captura_xlsx($arquivo), 'empresas' => null];
}

function ler_captura_json(string $arquivo): array
{
    $j = json_decode(file_get_contents($arquivo), true);
    if (!is_array($j) || ($j['formato'] ?? '') !== 'pcsdetran-api' || !isset($j['empresas'])) {
        throw new RuntimeException('Arquivo .json não é do coletor de multas do PCSDetran.');
    }
    $cards = [];
    $empresas = [];
    foreach ($j['empresas'] as $e) {
        $cnpj = preg_replace('~\D~', '', (string)($e['cnpj'] ?? ''));
        $n = 0;
        foreach ((array)($e['infracoes'] ?? []) as $i) {
            if (is_array($i) && ($c = interpretar_item_api($i, $cnpj))) { $cards[] = $c; $n++; }
        }
        $empresas[] = ['cnpj' => $cnpj, 'ok' => !empty($e['ok']), 'total' => $n, 'erro' => $e['erro'] ?? null];
    }
    return ['fonte' => 'api', 'cards' => atribuir_lojas($cards), 'empresas' => $empresas, 'geradoEm' => $j['geradoEm'] ?? null];
}

/** Lê a planilha gerada pelo robô (aba "Captura", colunas A:H) e devolve os cards de multa. */
function ler_captura_xlsx(string $arquivo): array
{
    $abas = xlsx_abas($arquivo);
    $aba = isset($abas['Captura']) ? 'Captura' : array_key_first($abas);
    $cards = [];
    foreach (xlsx_ler_aba($arquivo, $aba, 8) as $r) {
        $c = interpretar_cartao(array_map(fn($k) => $r[$k] ?? '', range(0, 7)));
        if ($c) {
            $c['bruto'] = array_values(array_map(fn($k) => $r[$k] ?? null, range(0, 7)));
            $cards[] = $c;
        }
    }
    return atribuir_lojas($cards);
}

/**
 * @return array resultado de aplicar_captura() + 'mudancasEtapa' e 'capturaId' (só quando gravar=true)
 */
function processar_captura(array $cards, array $lojasCobertas, string $nomeArquivo, bool $gravar,
                           string $fonte = 'robo_excel', ?array $empresas = null): array
{
    // Travas de segurança (valem para a tela e para a linha de comando):
    //  - coletor da API com algum CNPJ com falha → não finaliza nada, independente do que veio marcado;
    //  - captura sem nenhuma multa → não finaliza nada (e não pode ser aplicada).
    if ($empresas !== null && array_filter($empresas, fn($e) => empty($e['ok']))) $lojasCobertas = [];
    if (!$cards) {
        $lojasCobertas = [];
        if ($gravar) throw new RuntimeException('Captura sem nenhuma multa — não foi aplicada.');
    }

    $pdo = db();
    $existentes = [];
    foreach ($pdo->query('SELECT m.serie, m.presente_no_detran, l.nome loja FROM multas m LEFT JOIN lojas l ON l.id = m.loja_id') as $r) {
        $existentes[$r['serie']] = $r;
    }
    $res = aplicar_captura($existentes, $cards, $lojasCobertas);
    if (!$gravar) return $res;

    $hoje = date('Y-m-d');
    $orgaos = $pdo->query('SELECT codigo, nome FROM orgaos_autuadores')->fetchAll(PDO::FETCH_KEY_PAIR);
    $infracoes = [];
    foreach ($pdo->query('SELECT codigo, descricao FROM infracoes_ctb') as $i) {
        $infracoes[mb_strtolower(trim($i['descricao']))] ??= $i['codigo'];
    }
    $veiculos = $pdo->query('SELECT placa, id FROM veiculos_frota')->fetchAll(PDO::FETCH_KEY_PAIR);

    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO capturas_detran (origem, arquivo, lojas_cobertas, empresas, total_lidas, novas, atualizadas, sairam_do_detran)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$fonte, $nomeArquivo, json_encode(array_values($lojasCobertas), JSON_UNESCAPED_UNICODE),
            $empresas === null ? null : json_encode($empresas), count($cards),
            count($res['novas']), count($res['atualizadas']), count($res['sairam'])]);
    $capturaId = (int)$pdo->lastInsertId();

    $item = $pdo->prepare('INSERT INTO captura_itens (captura_id, serie, loja, situacao_detran, vencimento_detran, valor, bruto) VALUES (?,?,?,?,?,?,?)');
    foreach ($cards as $c) {
        $item->execute([$capturaId, $c['serie'], $c['loja'], $c['situacao_detran'], $c['vencimento_detran'], $c['valor'],
            json_encode($c['bruto'] ?? $c, JSON_UNESCAPED_UNICODE)]);
    }
    $hist = $pdo->prepare('INSERT INTO multas_historico (multa_id, campo, de, para, origem) VALUES (?, ?, ?, ?, "captura")');
    $mudancas = [];

    // ---- Novas
    $cols = ['serie', 'placa', 'veiculo_id', 'loja_id', 'cnpj_detran', 'orgao_codigo', 'autuador', 'tipo', 'codigo_infracao',
        'descricao', 'local_infracao', 'valor', 'pontuacao', 'data_infracao', 'data_infracao_texto', 'situacao_detran',
        'vencimento_detran', 'vencimento_pendente', 'presente_no_detran', 'primeira_captura_em', 'ultima_captura_id',
        'prazo_identificacao', 'email_enviado', 'identificacao_condutor', 'etapa', 'status', 'origem'];
    $ins = $pdo->prepare('INSERT INTO multas (' . implode(',', $cols) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')');
    foreach ($res['novas'] as $c) {
        $m = com_status([
            'serie' => $c['serie'], 'placa' => $c['placa'], 'veiculo_id' => $veiculos[$c['placa']] ?? null,
            'loja_id' => loja_id($c['loja'] ?: null), 'cnpj_detran' => $c['cnpj_detran'] ?? null,
            'orgao_codigo' => $c['orgao_codigo'], 'autuador' => ($c['autuador'] ?? null) ?: ($orgaos[$c['orgao_codigo']] ?? null),
            'tipo' => $c['tipo'],
            'codigo_infracao' => ($c['codigo_infracao'] ?? null) ?: ($infracoes[mb_strtolower($c['descricao'])] ?? null),
            'descricao' => $c['descricao'], 'local_infracao' => $c['local_infracao'], 'valor' => $c['valor'],
            'pontuacao' => $c['pontuacao'] ?? null, 'data_infracao' => $c['data_infracao'],
            'data_infracao_texto' => $c['data_infracao_texto'], 'situacao_detran' => $c['situacao_detran'],
            'vencimento_detran' => $c['vencimento_detran'], 'vencimento_pendente' => (int)($c['vencimento_detran'] === null),
            'presente_no_detran' => 1, 'primeira_captura_em' => $hoje, 'ultima_captura_id' => $capturaId,
            'prazo_identificacao' => $c['prazo_identificacao'] ?? null, 'origem' => 'captura',
        ] + campos_iniciais_da_nova($c));
        $ins->execute(array_map(fn($k) => $m[$k], $cols));
        $hist->execute([(int)$pdo->lastInsertId(), 'etapa', null, $m['etapa']]);
    }

    // ---- Já existentes: atualiza dados do DETRAN e recalcula
    $busca = $pdo->prepare('SELECT * FROM multas WHERE serie = ?');
    $upd = $pdo->prepare('UPDATE multas SET situacao_detran = ?, vencimento_detran = ?, vencimento_pendente = ?, valor = COALESCE(?, valor),
        cnpj_detran = COALESCE(?, cnpj_detran), pontuacao = COALESCE(?, pontuacao), codigo_infracao = COALESCE(codigo_infracao, ?),
        prazo_identificacao = ?, presente_no_detran = 1, saiu_do_detran_em = NULL, ultima_captura_id = ?, etapa = ?, status = ?
        WHERE id = ?');
    foreach ($res['atualizadas'] as $c) {
        $busca->execute([$c['serie']]);
        $m = $busca->fetch();
        // Prazo de identificação: o DETRAN informa (dataLimApresCond) → preenche se ainda estiver vazio.
        $prazo = $m['prazo_identificacao'];
        if ($prazo === null && $m['prazo_identificacao_nota'] === null && !empty($c['prazo_identificacao'])) {
            $prazo = $c['prazo_identificacao'];
            $hist->execute([$m['id'], 'prazo_identificacao', null, $prazo]);
        }
        $novo = com_status(array_merge($m, [
            'situacao_detran' => $c['situacao_detran'], 'vencimento_detran' => $c['vencimento_detran'],
            'vencimento_pendente' => (int)($c['vencimento_detran'] === null), 'presente_no_detran' => 1,
            'prazo_identificacao' => $prazo,
        ]));
        $upd->execute([$novo['situacao_detran'], $novo['vencimento_detran'], $novo['vencimento_pendente'], $c['valor'],
            $c['cnpj_detran'] ?? null, $c['pontuacao'] ?? null, $c['codigo_infracao'] ?? null, $prazo,
            $capturaId, $novo['etapa'], $novo['status'], $m['id']]);
        if ($novo['etapa'] !== $m['etapa']) {
            $hist->execute([$m['id'], 'etapa', $m['etapa'], $novo['etapa']]);
            $mudancas[] = [$m['serie'], $m['etapa'], $novo['etapa']];
        }
    }

    // ---- Saíram do DETRAN
    $sai = $pdo->prepare('UPDATE multas SET presente_no_detran = 0, saiu_do_detran_em = ?, etapa = ?, status = ? WHERE id = ?');
    foreach ($res['sairam'] as $serie) {
        $busca->execute([$serie]);
        $m = $busca->fetch();
        $novo = com_status(array_merge($m, ['presente_no_detran' => 0]));
        $sai->execute([$hoje, $novo['etapa'], $novo['status'], $m['id']]);
        if ($novo['etapa'] !== $m['etapa']) {
            $hist->execute([$m['id'], 'etapa', $m['etapa'], $novo['etapa']]);
            $mudancas[] = [$m['serie'], $m['etapa'], $novo['etapa']];
        }
    }
    $pdo->commit();
    $res['mudancasEtapa'] = $mudancas;
    $res['capturaId'] = $capturaId;
    return $res;
}
