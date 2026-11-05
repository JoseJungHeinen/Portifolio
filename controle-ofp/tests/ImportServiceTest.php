<?php
declare(strict_types=1);

use App\Services\ImportService;

fx_limpar();
$_SESSION['unidades'] = null;

$cabecalho = ['UNIDADE', 'DESENHO', 'PEÇA', 'NFE', 'VOLUME', 'COLUNA', 'VLR PEÇA', 'MOTIVO', 'N. BLOQUEIO', 'DT ATENDIMENTO', 'Nº OFP',
    'DT COLETA', 'STATUS', 'CÓD RASTREAMENTO', 'DT INÍCIO PROCESSO', 'NFE DEVOLUÇÃO', 'DT EMISSÃO DEVOL', 'NFE ICMS ST',
    'DT EMISSÃO ICMS ST', 'VLR DEVOL', 'VLR ICMS ST', 'REANÁLISE', 'COLETA', 'CONTATO', 'VIANUVEM', 'DT RCBTO NFE DEVOLUÇÃO', 'DT RCBTO NFE ICMS ST'];
$csvV4 = sys_get_temp_dir() . '/ofp_v4_teste.csv';
$h = fopen($csvV4, 'w');
fputcsv($h, $cabecalho, ',', '"', '');
foreach ([
    ['UNIDADE' => 'Eurovale - Cidade C', 'DESENHO' => '51855519', 'COLUNA' => '1', 'VLR PEÇA' => '222.88', 'MOTIVO' => 'DG - Recebida a menor', 'Nº OFP' => '330594',
     'STATUS' => 'Recebida', 'DT INÍCIO PROCESSO' => '2017-01-26', 'NFE DEVOLUÇÃO' => '27817', 'DT EMISSÃO DEVOL' => '2017-02-01', 'VLR DEVOL' => '222.88', 'DT RCBTO NFE DEVOLUÇÃO' => '2017-03-10'],
    ['UNIDADE' => 'eurovale - matriz', 'DESENHO' => '9558945780', 'PEÇA' => 'Parafuso', 'COLUNA' => '5', 'VLR PEÇA' => '1.38', 'MOTIVO' => 'VF - Volume faltante', 'N. BLOQUEIO' => '568540',
     'Nº OFP' => '568541', 'STATUS' => 'Recebto Pendente - Tributo', 'DT INÍCIO PROCESSO' => '2020-05-20', 'NFE DEVOLUÇÃO' => '29713', 'DT EMISSÃO DEVOL' => '2020-05-28',
     'NFE ICMS ST' => '29728', 'DT EMISSÃO ICMS ST' => '2020-05-28', 'VLR DEVOL' => '1.38', 'VLR ICMS ST' => '1.17', 'CONTATO' => 'Falta receber imposto', 'DT RCBTO NFE DEVOLUÇÃO' => '2020-06-30'],
    ['UNIDADE' => 'Eurovale - Jeep', 'DESENHO' => '100208682', 'PEÇA' => 'FRISO', 'COLUNA' => '1', 'VLR PEÇA' => '20.39', 'MOTIVO' => 'AT - Peça avariada no transporte com retorno',
     'Nº OFP' => '606376', 'STATUS' => 'AGUARDANDO COLETA', 'DT INÍCIO PROCESSO' => '2021-04-23', 'VLR DEVOL' => '20.39'],
    ['UNIDADE' => 'Eurovale - Jeep', 'Nº OFP' => '606376', 'MOTIVO' => 'AT - x', 'STATUS' => 'AGUARDANDO COLETA'],
    ['UNIDADE' => 'Loja X', 'Nº OFP' => '700001', 'MOTIVO' => 'AP - x', 'STATUS' => 'Recebida'],
    [],
] as $valores) {
    fputcsv($h, array_map(fn($c) => $valores[$c] ?? '', $cabecalho), ',', '"', '');
}
fclose($h);

$csvStatus = sys_get_temp_dir() . '/ofp_status_teste.csv';
$h = fopen($csvStatus, 'w');
foreach ([
    ['OFP', 'STATUS', 'reanalise', 'protocolo'],
    ['606376', 'aguardando coleta', 'peça coletada 14/10', ''],
    ['330594', 'recebida', 'aguardando tp41', 'protocolo 17305169'],
    ['568541', 'aguardando coleta', '', ''],
    ['999999', 'em analise', '', ''],
] as $linha) {
    fputcsv($h, $linha, ',', '"', '');
}
fclose($h);

T::teste('importa a planilha v4', function () use ($csvV4) {
    T::igual(['inseridas' => 3, 'atualizadas' => 0, 'ignoradas' => 0, 'rejeitadas' => 2], (new ImportService())->importarV4($csvV4));
    $a = fx_db()->query("SELECT * FROM ofps WHERE numero_ofp = '330594'")->fetch();
    T::igual('creditada', $a['status']);
    T::igual('2017-03-10', $a['data_credito']);
    T::igual('222.88', $a['valor_creditado_peca']);
    $b = fx_db()->query("SELECT * FROM ofps WHERE numero_ofp = '568541'")->fetch();
    T::igual('aguardando_credito', $b['status']);
    T::igual('1.38', $b['valor_creditado_peca']);
    T::igual('1.17', $b['valor_icms_st']);
    T::igual('credito', $b['prazo_tipo']);
    $c = fx_db()->query("SELECT o.status, c.codigo FROM ofps o JOIN causais c ON c.id = o.causal_id WHERE o.numero_ofp = '606376'")->fetch();
    T::igual(['status' => 'aguardando_coleta', 'codigo' => 'AT'], $c);
});

T::teste('reimportar atualiza sem duplicar', fn() => T::igual(['inseridas' => 0, 'atualizadas' => 3, 'ignoradas' => 0, 'rejeitadas' => 2], (new ImportService())->importarV4($csvV4)));

T::teste('OFP criada no sistema não é sobrescrita', function () use ($csvV4) {
    fx_db()->exec("UPDATE ofps SET origem_importacao = NULL WHERE numero_ofp = '330594'");
    T::igual(['inseridas' => 0, 'atualizadas' => 2, 'ignoradas' => 1, 'rejeitadas' => 2], (new ImportService())->importarV4($csvV4));
});

T::teste('importa a planilha OFP-STATUS sem repetir anotações', function () use ($csvStatus) {
    fx_db()->exec("UPDATE ofps SET origem_importacao = 'planilha_v4:linha 2' WHERE numero_ofp = '330594'");
    T::igual(['atualizadas' => 1, 'anotadas' => 2, 'rejeitadas' => 1], (new ImportService())->importarStatus($csvStatus));
    T::igual('17305169', fx_db()->query("SELECT protocolo_grow FROM ofps WHERE numero_ofp = '330594'")->fetchColumn());
    T::igual('aguardando_coleta', fx_db()->query("SELECT status FROM ofps WHERE numero_ofp = '568541'")->fetchColumn());
    T::igual(['atualizadas' => 0, 'anotadas' => 0, 'rejeitadas' => 1], (new ImportService())->importarStatus($csvStatus));
});

// ---- Robustez e proteção do trabalho feito no sistema ----
$linhaV4 = function (array $v) use ($cabecalho): array {
    return array_map(fn($c) => $v[$c] ?? '', $cabecalho);
};
$gravarCsv = function (string $caminho, array $linhas, array $cab) {
    $h = fopen($caminho, 'w');
    fputcsv($h, $cab, ',', '"', '');
    foreach ($linhas as $l) {
        fputcsv($h, $l, ',', '"', '');
    }
    fclose($h);
};
$base = ['UNIDADE' => 'Eurovale - Matriz', 'DESENHO' => 'abc1', 'COLUNA' => '1', 'VLR PEÇA' => '10', 'MOTIVO' => 'AT - x', 'STATUS' => 'AGUARDANDO COLETA'];

T::teste('OFP-STATUS rejeita OFP criada no sistema', function () use ($csvStatus) {
    fx_db()->exec("UPDATE ofps SET origem_importacao = NULL WHERE numero_ofp = '606376'");
    $r = (new ImportService())->importarStatus($csvStatus);
    T::igual(2, $r['rejeitadas']);
    fx_db()->exec("UPDATE ofps SET origem_importacao = 'planilha_v4:linha 4' WHERE numero_ofp = '606376'");
});

T::teste('linha ruim não derruba a importação', function () use ($gravarCsv, $linhaV4, $cabecalho, $base) {
    $f = sys_get_temp_dir() . '/ofp_v4_ruim.csv';
    $gravarCsv($f, [
        $linhaV4(array_merge($base, ['Nº OFP' => '810001', 'NFE' => '123456789012345678901'])),
        $linhaV4(array_merge($base, ['Nº OFP' => '810002', 'VLR PEÇA' => '99999999999.00'])),
        $linhaV4(array_merge($base, ['Nº OFP' => '810003', 'VLR PEÇA' => '-5'])),
        $linhaV4(array_merge($base, ['Nº OFP' => '810004', 'COLUNA' => '100000000'])),
        $linhaV4(array_merge($base, ['Nº OFP' => '810005', 'DESENHO' => str_repeat('x', 80), 'PEÇA' => str_repeat('p', 300), 'CÓD RASTREAMENTO' => str_repeat('r', 90)])),
        $linhaV4(array_merge($base, ['Nº OFP' => '810006'])),
    ], $cabecalho);
    T::igual(['inseridas' => 2, 'atualizadas' => 0, 'ignoradas' => 0, 'rejeitadas' => 4], (new ImportService())->importarV4($f));
    T::igual(0, (int)fx_db()->query("SELECT COUNT(*) FROM ofps WHERE numero_ofp = '810001'")->fetchColumn());
    T::igual(30, (int)fx_db()->query("SELECT CHAR_LENGTH(desenho) FROM ofps WHERE numero_ofp = '810005'")->fetchColumn());
});

T::teste('VLR DEVOL zerado usa VLR PEÇA; peça sem recebimento de tributo não vira crédito parcial', function () use ($gravarCsv, $linhaV4, $cabecalho, $base) {
    $f = sys_get_temp_dir() . '/ofp_v4_valores.csv';
    $gravarCsv($f, [
        $linhaV4(array_merge($base, ['Nº OFP' => '820001', 'VLR DEVOL' => '0', 'NFE DEVOLUÇÃO' => '5555', 'VLR PEÇA' => '12.5'])),
        $linhaV4(array_merge($base, ['Nº OFP' => '820002', 'STATUS' => 'Recebto Pendente - Peças', 'NFE DEVOLUÇÃO' => '5556', 'VLR DEVOL' => '8', 'DT RCBTO NFE DEVOLUÇÃO' => '2021-01-05'])),
    ], $cabecalho);
    (new ImportService())->importarV4($f);
    $a = fx_db()->query("SELECT valor_reclamado, valor_nf_devolucao FROM ofps WHERE numero_ofp = '820001'")->fetch();
    T::igual(['valor_reclamado' => '12.50', 'valor_nf_devolucao' => '12.50'], $a);
    $b = fx_db()->query("SELECT status, valor_creditado_peca, data_credito FROM ofps WHERE numero_ofp = '820002'")->fetch();
    T::igual(['status' => 'aguardando_credito', 'valor_creditado_peca' => null, 'data_credito' => null], $b);
});

T::teste('OFP mexida por usuário não é sobrescrita nem muda de status', function () use ($gravarCsv, $linhaV4, $cabecalho, $base) {
    $f = sys_get_temp_dir() . '/ofp_v4_usuario.csv';
    $gravarCsv($f, [$linhaV4(array_merge($base, ['Nº OFP' => '830001']))], $cabecalho);
    $svc = new ImportService();
    T::igual(1, $svc->importarV4($f)['inseridas']);
    $id = fx_db()->query("SELECT id FROM ofps WHERE numero_ofp = '830001'")->fetchColumn();
    $u = fx_usuario('comprador');
    fx_db()->prepare("INSERT INTO ofp_eventos (id, ofp_id, usuario_id, status_de, status_para, observacao) VALUES (?, ?, ?, 'aguardando_coleta', 'aguardando_coleta', 'mexi')")
        ->execute([uuid(), $id, $u['id']]);
    fx_db()->exec("UPDATE ofps SET desenho = 'EDITADO' WHERE id = '{$id}'");
    T::igual(['inseridas' => 0, 'atualizadas' => 0, 'ignoradas' => 1, 'rejeitadas' => 0], (new ImportService())->importarV4($f));
    T::igual('EDITADO', fx_db()->query("SELECT desenho FROM ofps WHERE id = '{$id}'")->fetchColumn());

    $fs = sys_get_temp_dir() . '/ofp_status_usuario.csv';
    $gravarCsv($fs, [['830001', 'em analise', 'nota', '']], ['OFP', 'STATUS', 'reanalise', 'protocolo']);
    T::igual(['atualizadas' => 0, 'anotadas' => 1, 'rejeitadas' => 0], (new ImportService())->importarStatus($fs));
    T::igual('aguardando_coleta', fx_db()->query("SELECT status FROM ofps WHERE id = '{$id}'")->fetchColumn());
});

T::teste('OFP-STATUS não muda status de OFP encerrada e a v4 reimportada não desfaz o OFP-STATUS', function () use ($gravarCsv, $linhaV4, $cabecalho, $base) {
    $f = sys_get_temp_dir() . '/ofp_v4_encerrada.csv';
    $gravarCsv($f, [
        $linhaV4(array_merge($base, ['Nº OFP' => '840001', 'STATUS' => 'Cancelada'])),
        $linhaV4(array_merge($base, ['Nº OFP' => '840002', 'STATUS' => 'Em analise'])),
    ], $cabecalho);
    (new ImportService())->importarV4($f);
    $fs = sys_get_temp_dir() . '/ofp_status_encerrada.csv';
    $gravarCsv($fs, [['840001', 'aguardando coleta', '', ''], ['840002', 'aguardando coleta', '', '']], ['OFP', 'STATUS', 'reanalise', 'protocolo']);
    T::igual(['atualizadas' => 1, 'anotadas' => 1, 'rejeitadas' => 0], (new ImportService())->importarStatus($fs));
    T::igual('cancelada', fx_db()->query("SELECT status FROM ofps WHERE numero_ofp = '840001'")->fetchColumn());
    T::igual('aguardando_coleta', fx_db()->query("SELECT status FROM ofps WHERE numero_ofp = '840002'")->fetchColumn());
    (new ImportService())->importarV4($f);
    T::igual('aguardando_coleta', fx_db()->query("SELECT status FROM ofps WHERE numero_ofp = '840002'")->fetchColumn());
    T::igual(['atualizadas' => 0, 'anotadas' => 0, 'rejeitadas' => 0], (new ImportService())->importarStatus($fs));
});

// ---- Duplicadas na planilha: vale a linha de status mais avançado ----
$importarDup = function (array $linhas) use ($gravarCsv, $linhaV4, $cabecalho, $base): array {
    $f = sys_get_temp_dir() . '/ofp_v4_dup.csv';
    $gravarCsv($f, array_map(fn($v) => $linhaV4(array_merge($base, $v)), $linhas), $cabecalho);
    $svc = new ImportService();
    $r = $svc->importarV4($f);
    $rej = sys_get_temp_dir() . '/ofp_v4_dup_rej.csv';
    $svc->salvarRejeitados($rej);
    return [$r, (string)file_get_contents($rej)];
};

T::teste('duplicada: Cancelada depois Recebida mantém a Recebida (creditada)', function () use ($importarDup) {
    [$r, $rej] = $importarDup([
        ['Nº OFP' => '850001', 'STATUS' => 'Cancelada', 'DESENHO' => 'primeira'],
        ['Nº OFP' => '850001', 'STATUS' => 'Recebida', 'DESENHO' => 'segunda'],
    ]);
    T::igual(['inseridas' => 1, 'atualizadas' => 0, 'ignoradas' => 0, 'rejeitadas' => 1], $r);
    T::igual(['status' => 'creditada', 'desenho' => 'SEGUNDA'], fx_db()->query("SELECT status, desenho FROM ofps WHERE numero_ofp = '850001'")->fetch());
    T::verdadeiro(str_contains($rej, 'OFP duplicada — mantida a linha 3 (status mais avançado)'));
});

T::teste('duplicada: Recebida depois Cancelada continua creditada', function () use ($importarDup) {
    [$r, $rej] = $importarDup([
        ['Nº OFP' => '850002', 'STATUS' => 'Recebida', 'DESENHO' => 'primeira'],
        ['Nº OFP' => '850002', 'STATUS' => 'Cancelada', 'DESENHO' => 'segunda'],
    ]);
    T::igual(1, $r['inseridas']);
    T::igual(1, $r['rejeitadas']);
    T::igual(['status' => 'creditada', 'desenho' => 'PRIMEIRA'], fx_db()->query("SELECT status, desenho FROM ofps WHERE numero_ofp = '850002'")->fetch());
    T::verdadeiro(str_contains($rej, 'mantida a linha 2 (status mais avançado)'));
});

T::teste('duplicada: linha inválida (unidade desconhecida) não bloqueia a válida seguinte', function () use ($importarDup) {
    [$r, $rej] = $importarDup([
        ['Nº OFP' => '850003', 'UNIDADE' => 'Loja X', 'STATUS' => 'Recebida'],
        ['Nº OFP' => '850003', 'STATUS' => 'Cancelada', 'DESENHO' => 'valida'],
    ]);
    T::igual(['inseridas' => 1, 'atualizadas' => 0, 'ignoradas' => 0, 'rejeitadas' => 1], $r);
    T::igual(['status' => 'cancelada', 'desenho' => 'VALIDA'], fx_db()->query("SELECT status, desenho FROM ofps WHERE numero_ofp = '850003'")->fetch());
    T::verdadeiro(str_contains($rej, 'Unidade não reconhecida'));
});

T::teste('duplicada: empate de status vale a última linha', function () use ($importarDup) {
    [$r, $rej] = $importarDup([
        ['Nº OFP' => '850004', 'DESENHO' => 'primeira'],
        ['Nº OFP' => '850004', 'DESENHO' => 'segunda'],
        ['Nº OFP' => '850004', 'DESENHO' => 'terceira'],
    ]);
    T::igual(['inseridas' => 1, 'atualizadas' => 0, 'ignoradas' => 0, 'rejeitadas' => 2], $r);
    T::igual('TERCEIRA', fx_db()->query("SELECT desenho FROM ofps WHERE numero_ofp = '850004'")->fetchColumn());
    T::igual(2, substr_count($rej, 'mantida a linha 4 (status mais avançado)'));
});

T::teste('duplicada: Recebida depois Estornada importa a Estornada (estorno vem depois do crédito)', function () use ($importarDup) {
    [$r, $rej] = $importarDup([
        ['Nº OFP' => '850005', 'STATUS' => 'Recebida', 'DESENHO' => 'primeira'],
        ['Nº OFP' => '850005', 'STATUS' => 'Estornada', 'DESENHO' => 'segunda'],
    ]);
    T::igual(['inseridas' => 1, 'atualizadas' => 0, 'ignoradas' => 0, 'rejeitadas' => 1], $r);
    T::igual(['status' => 'estornada', 'desenho' => 'SEGUNDA'], fx_db()->query("SELECT status, desenho FROM ofps WHERE numero_ofp = '850005'")->fetch());
    T::verdadeiro(str_contains($rej, 'mantida a linha 3 (status mais avançado)'));
});
