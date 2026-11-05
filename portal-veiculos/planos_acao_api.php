<?php
/**
 * planos_acao_api.php — API JSON do módulo Planos de Ação.
 * GET  ?a=meta|listar|plano|minhas_acoes|dashboard|avisos
 * POST ?a=salvar_plano|status_plano|excluir_plano|salvar_acao|status_acao|excluir_acao|acompanhar|excluir_acomp
 */
require_once __DIR__ . '/planos_acao_comum.php';
pa_guard(true);

$a = $_GET['a'] ?? '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !hash_equals($_SESSION['pa_csrf'] ?? '', $_SERVER['HTTP_X_CSRF'] ?? '')) {
    pa_json(['ok' => false, 'msg' => 'Sessão inválida. Recarregue a página.'], 403);
}

function q(string $sql, string $types = '', array $params = []): array
{
    global $mysqli;
    $st = $mysqli->prepare($sql);
    if (!$st) pa_json(['ok' => false, 'msg' => 'Erro de consulta.'], 500);
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $r = $st->get_result();
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}
function x(string $sql, string $types = '', array $params = []): int
{
    global $mysqli;
    $st = $mysqli->prepare($sql);
    if (!$st) pa_json(['ok' => false, 'msg' => 'Erro de gravação.'], 500);
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    return $st->insert_id ?: $st->affected_rows;
}
function num($v): ?float
{
    $v = trim((string)$v);
    if ($v === '') return null;
    if (str_contains($v, ',')) $v = str_replace(',', '.', str_replace('.', '', $v));
    return is_numeric($v) ? (float)$v : null;
}
function erro(string $m, int $c = 422): void { pa_json(['ok' => false, 'msg' => $m], $c); }
function data_ok(string $d): bool { $t = DateTime::createFromFormat('Y-m-d', $d); return $t && $t->format('Y-m-d') === $d; }

function plano_visivel(int $id): array
{
    $r = q(pa_sql_base() . ' WHERE p.id = ? AND ' . pa_sql_visibilidade('p'), 'i', [$id]);
    if (!$r) erro('Plano não encontrado ou sem acesso.', 404);
    return $r[0];
}
function usuario_ativo(int $id): bool { return (bool)q('SELECT id FROM usuarios WHERE id = ? AND is_ativo = 1', 'i', [$id]); }

switch ($a) {

case 'meta':
    $setores = (pa_eh_admin() || pa_eh_diretoria())
        ? q('SELECT id, nome FROM pa_setores WHERE ativo = 1 ORDER BY ordem, nome')
        : q('SELECT s.id, s.nome FROM pa_setores s WHERE s.ativo = 1 AND (s.id IN (SELECT setor_id FROM pa_usuario_setor WHERE usuario_id = ?)
             OR s.id IN (SELECT p.setor_id FROM pa_planos p WHERE ' . pa_sql_visibilidade('p') . ')) ORDER BY s.ordem, s.nome', 'i', [pa_uid()]);
    $usuarios = q('SELECT u.id, u.nome, GROUP_CONCAT(us.setor_id) AS setores FROM usuarios u
                   LEFT JOIN pa_usuario_setor us ON us.usuario_id = u.id WHERE u.is_ativo = 1 GROUP BY u.id ORDER BY u.nome');
    foreach ($usuarios as &$u) $u['setores'] = $u['setores'] ? array_map('intval', explode(',', $u['setores'])) : [];
    $gestorEm = array_keys(array_filter(pa_setores_usuario(), fn($p) => $p === 'gestor'));
    pa_json(['ok' => true, 'me' => ['id' => pa_uid(), 'nome' => $_SESSION['nome'] ?? '', 'admin' => pa_eh_admin(), 'diretoria' => pa_eh_diretoria(), 'gestor_em' => $gestorEm],
             'setores' => $setores, 'usuarios' => $usuarios, 'csrf' => $_SESSION['pa_csrf'],
             'lojas' => q('SELECT id, nome FROM lojas ORDER BY nome')]);

case 'listar':
    $w = [pa_sql_visibilidade('p')]; $t = ''; $pr = [];
    $setIds = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['setor_id'] ?? '')))));   // aceita vários: "9,13"
    if ($setIds) $w[] = 'p.setor_id IN (' . implode(',', $setIds) . ')';
    if (!empty($_GET['responsavel_id'])) { $w[] = 'p.responsavel_id = ?'; $t .= 'i'; $pr[] = (int)$_GET['responsavel_id']; }
    if (!empty($_GET['loja_id']))        { $w[] = 'p.loja_id = ?';        $t .= 'i'; $pr[] = (int)$_GET['loja_id']; }
    $stF = $_GET['status'] ?? 'abertos';
    if ($stF === 'abertos' || $stF === 'atrasado') $w[] = "p.status IN ('nao_iniciado','em_andamento')";
    elseif ($stF !== 'todos') { $w[] = 'p.status = ?'; $t .= 's'; $pr[] = $stF; }
    if (!empty($_GET['q'])) { $w[] = '(p.titulo LIKE ? OR p.indicador LIKE ? OR p.categoria LIKE ?)'; $t .= 'sss'; $l = '%' . $_GET['q'] . '%'; array_push($pr, $l, $l, $l); }
    $rows = q(pa_sql_base() . ' WHERE ' . implode(' AND ', $w) . " ORDER BY FIELD(p.status,'aguardando_validacao','em_andamento','nao_iniciado','pausado','concluido','encerrado','cancelado'), p.prazo IS NULL, p.prazo, p.titulo", $t, $pr);
    $rows = array_map('pa_enriquecer', $rows);
    if ($stF === 'atrasado') $rows = array_values(array_filter($rows, fn($r) => $r['atrasado']));
    foreach ($rows as &$r) $r['pode_editar'] = pa_pode_editar($r);
    if (($_GET['pendentes'] ?? '') === '1') $rows = array_values(array_filter($rows, fn($r) => !empty($r['avisos'])));
    pa_json(['ok' => true, 'planos' => $rows]);

case 'plano':
    $p = pa_enriquecer(plano_visivel((int)($_GET['id'] ?? 0)));
    $id = (int)$p['id'];
    $p['pode_editar'] = pa_pode_editar($p);
    $acoes = q('SELECT a.*, u.nome AS responsavel_nome FROM pa_acoes a JOIN usuarios u ON u.id = a.responsavel_id WHERE a.plano_id = ?
                ORDER BY FIELD(a.status,\'em_andamento\',\'pendente\',\'concluida\',\'cancelada\'), a.prazo IS NULL, a.prazo, a.id', 'i', [$id]);
    foreach ($acoes as &$ac) $ac['pode_alterar'] = $p['pode_editar'] || (int)$ac['responsavel_id'] === pa_uid();
    pa_json(['ok' => true, 'plano' => $p, 'acoes' => $acoes,
        'acompanhamentos' => q('SELECT c.id, c.data_ref, c.valor, c.observacao, u.nome AS usuario FROM pa_acompanhamentos c LEFT JOIN usuarios u ON u.id = c.usuario_id WHERE c.plano_id = ? ORDER BY c.data_ref', 'i', [$id]),
        'historico' => q('SELECT h.evento, h.detalhe, h.criado_em, u.nome AS usuario FROM pa_historico h LEFT JOIN usuarios u ON u.id = h.usuario_id WHERE h.plano_id = ? ORDER BY h.id DESC LIMIT 50', 'i', [$id])]);

case 'minhas_acoes':
    $uid = pa_uid();
    $rows = q("SELECT a.id, a.plano_id, a.titulo, a.prazo, a.status, p.titulo AS plano_titulo, s.nome AS setor_nome,
                      (a.status IN ('pendente','em_andamento') AND a.prazo < CURDATE()) AS atrasada
               FROM pa_acoes a JOIN pa_planos p ON p.id = a.plano_id JOIN pa_setores s ON s.id = p.setor_id
               WHERE a.responsavel_id = ? AND a.status IN ('pendente','em_andamento') AND p.status IN ('nao_iniciado','em_andamento')
               ORDER BY a.prazo IS NULL, a.prazo", 'i', [$uid]);
    $planos = array_map('pa_enriquecer', q(pa_sql_base() . " WHERE (p.responsavel_id = ? OR p.atribuido_id = ?) AND p.status IN ('nao_iniciado','em_andamento') ORDER BY p.prazo IS NULL, p.prazo", 'ii', [$uid, $uid]));
    $validar = pa_eh_diretoria() ? array_map('pa_enriquecer', q(pa_sql_base() . " WHERE p.status = 'aguardando_validacao' ORDER BY p.atualizado_em")) : [];
    pa_json(['ok' => true, 'acoes' => $rows, 'planos' => $planos, 'validar' => $validar]);

case 'avisos':
    $rows = array_map('pa_enriquecer', q(pa_sql_base() . " WHERE p.status IN ('nao_iniciado','em_andamento','aguardando_validacao') AND " . pa_sql_visibilidade('p')));
    $n = 0; foreach ($rows as $r) if ($r['avisos']) $n++;
    pa_json(['ok' => true, 'pendencias' => $n]);

case 'salvar_plano':
    if (!$post) erro('Método inválido.', 405);
    $id = (int)($_POST['id'] ?? 0);
    $setor = (int)($_POST['setor_id'] ?? 0);
    $titulo = trim($_POST['titulo'] ?? ''); $resp = (int)($_POST['responsavel_id'] ?? 0);
    $ini = trim($_POST['inicio'] ?? '') ?: null; $prazo = trim($_POST['prazo'] ?? '') ?: null;   // opcionais
    $prio = in_array($_POST['prioridade'] ?? '', ['baixa', 'media', 'alta'], true) ? $_POST['prioridade'] : 'media';
    if (!q('SELECT id FROM pa_setores WHERE id = ? AND ativo = 1', 'i', [$setor])) erro('Setor inválido.');
    // Plano importado sem usuário responsável (só o nome da planilha) pode ser salvo sem escolher um usuário
    $semUsuarioOk = $id && ($atualPre = plano_visivel($id)) && $atualPre['responsavel_id'] === null;
    if ($titulo === '' || (!$resp && !$semUsuarioOk)) erro('Preencha: O que será feito e Por quem.');
    if (($ini !== null && !data_ok($ini)) || ($prazo !== null && !data_ok($prazo))) erro('Data inválida.');
    if ($ini !== null && $prazo !== null && $prazo < $ini) erro('O prazo não pode ser anterior ao início.');
    if ($resp && !usuario_ativo($resp)) erro('Responsável inválido.');
    $resp = $resp ?: null;
    $atrib = (int)($_POST['atribuido_id'] ?? 0) ?: null;
    if ($atrib !== null && !usuario_ativo($atrib)) erro('"Atribuído a" inválido.');
    $loja = (int)($_POST['loja_id'] ?? 0) ?: null;
    if ($loja !== null && !q('SELECT id FROM lojas WHERE id = ?', 'i', [$loja])) erro('Loja inválida.');
    $custo = num($_POST['custo_estimado'] ?? '');

    $tem = !empty($_POST['tem_meta']) ? 1 : 0;
    $ind = $tipo = $sent = null; $vm = $vb = $va = null;
    if ($tem) {
        $ind = trim($_POST['indicador'] ?? '');
        $tipo = in_array($_POST['tipo_meta'] ?? '', ['percentual', 'valor'], true) ? $_POST['tipo_meta'] : 'percentual';
        $sent = in_array($_POST['sentido'] ?? '', ['aumento', 'reducao'], true) ? $_POST['sentido'] : 'aumento';
        $vm = num($_POST['valor_meta'] ?? ''); $vb = num($_POST['valor_base'] ?? '');
        if ($ind === '' || $vm === null || $vm <= 0 || $vb === null) erro('Meta: informe Indicador, Meta (> 0) e Saindo de.');
        $va = pa_calcular_alvo($tipo, $sent, $vb, $vm);
        if ($va === null) erro('Meta percentual precisa de um valor base diferente de zero. Use meta em valor.');
    }
    $f = [$setor, $loja, trim($_POST['categoria'] ?? ''), $titulo, trim($_POST['justificativa'] ?? ''), $resp, $atrib,
          trim($_POST['onde'] ?? ''), trim($_POST['como'] ?? ''), trim($_POST['observacoes'] ?? ''), trim($_POST['quanto'] ?? ''), $custo,
          $prio, $ini, $prazo, $tem, $ind, $tipo, $sent, $vm, $vb, $va];
    $ty = 'iisssiissssdsssisssddd';
    if ($id) {
        $atual = plano_visivel($id);
        if (!pa_pode_editar($atual)) erro('Sem permissão para editar este plano.', 403);
        if ((int)$atual['setor_id'] !== $setor && !pa_eh_gestor($setor)) erro('Sem permissão no setor de destino.', 403);
        x('UPDATE pa_planos SET setor_id=?,loja_id=?,categoria=?,titulo=?,justificativa=?,responsavel_id=?,atribuido_id=?,onde=?,como=?,observacoes=?,quanto=?,custo_estimado=?,
           prioridade=?,inicio=?,prazo=?,tem_meta=?,indicador=?,tipo_meta=?,sentido=?,valor_meta=?,valor_base=?,valor_alvo=? WHERE id=?', $ty . 'i', [...$f, $id]);
        // Escolheu usuário: o texto da planilha deixa de valer
        x('UPDATE pa_planos SET responsavel_texto = NULL WHERE id = ? AND responsavel_id IS NOT NULL', 'i', [$id]);
        x('UPDATE pa_planos SET atribuido_texto = NULL WHERE id = ? AND atribuido_id IS NOT NULL', 'i', [$id]);
        pa_log($id, 'editou', $titulo);
    } else {
        if (!pa_eh_gestor($setor)) erro('Só admin ou gestor do setor cria planos.', 403);
        $stNovo = in_array($_POST['status'] ?? '', ['nao_iniciado', 'em_andamento'], true) ? $_POST['status'] : 'nao_iniciado';
        $id = x('INSERT INTO pa_planos (setor_id,loja_id,categoria,titulo,justificativa,responsavel_id,atribuido_id,onde,como,observacoes,quanto,custo_estimado,
           prioridade,inicio,prazo,tem_meta,indicador,tipo_meta,sentido,valor_meta,valor_base,valor_alvo,status,criado_por)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $ty . 'si', [...$f, $stNovo, pa_uid()]);
        pa_log($id, 'criou', $titulo);
    }
    pa_json(['ok' => true, 'id' => $id]);

case 'status_plano':
    if (!$post) erro('Método inválido.', 405);
    $p = plano_visivel((int)($_POST['id'] ?? 0));
    if (!pa_pode_editar($p)) erro('Sem permissão.', 403);
    $st = $_POST['status'] ?? '';
    // 'aguardando_validacao' nunca é escolhido à mão: é o destino de "concluir" para quem não é Diretoria
    if (!in_array($st, ['nao_iniciado', 'em_andamento', 'pausado', 'concluido', 'cancelado', 'encerrado'], true)) erro('Status inválido.');
    if ($p['status'] === 'aguardando_validacao' && !pa_eh_diretoria()) erro('Plano aguardando validação da Diretoria.', 403);
    $id = (int)$p['id']; $motivo = null;
    if ($st === 'encerrado' && !pa_eh_gestor((int)$p['setor_id'])) erro('Só admin ou gestor do setor encerra planos.', 403);
    if ($st === 'encerrado' || $st === 'cancelado') {
        $motivo = trim($_POST['motivo'] ?? '');
        if ($motivo === '') erro('Informe o motivo ' . ($st === 'cancelado' ? 'do cancelamento.' : 'do encerramento.'));
    }
    if ($st === 'concluido' && empty($_POST['forcar']) && (int)$p['qtd_acoes'] > (int)$p['acoes_ok']) {
        pa_json(['ok' => false, 'confirmar' => true, 'msg' => ((int)$p['qtd_acoes'] - (int)$p['acoes_ok']) . ' ação(ões) ainda em aberto. Concluir mesmo assim?']);
    }
    // Concluir e Encerrar precisam da validação de quem tem a permissão de validador
    if (in_array($st, ['concluido', 'encerrado', 'cancelado'], true) && !pa_eh_diretoria()) {
        x("UPDATE pa_planos SET status = 'aguardando_validacao', validacao = 'pendente', validacao_destino = ?, encerrado_motivo = ?, parecer = NULL WHERE id = ?", 'ssi', [$st, $motivo, $id]);
        pa_log($id, 'enviou_validacao', ['concluido' => 'Conclusão', 'encerrado' => 'Encerramento', 'cancelado' => 'Cancelamento'][$st] . ' enviado para validação' . ($motivo ? " ($motivo)" : ''));
        pa_json(['ok' => true, 'msg' => 'Enviado para validação.']);
    }
    if (in_array($st, ['concluido', 'encerrado', 'cancelado'], true)) {   // validador executando direto = já validado
        x("UPDATE pa_planos SET status = ?, fim_real = IF(? = 'cancelado', NULL, CURDATE()), encerrado_motivo = ?, validacao = 'aprovada', validacao_destino = NULL, validado_por = ?, validado_em = NOW() WHERE id = ?", 'sssii', [$st, $st, $motivo, pa_uid(), $id]);
    } else {
        x('UPDATE pa_planos SET status = ?, encerrado_motivo = ?, fim_real = IF(? = \'encerrado\', CURDATE(), NULL),
           validacao = IF(?, \'nao_exigida\', validacao) WHERE id = ?', 'sssii', [$st, $motivo, $st, (int)in_array($p['status'], ['concluido', 'encerrado', 'cancelado'], true), $id]);
    }
    pa_log($id, 'status', $p['status'] . ' → ' . $st . ($motivo ? " ($motivo)" : ''));
    pa_json(['ok' => true]);

case 'validar_plano':
    if (!$post) erro('Método inválido.', 405);
    if (!pa_eh_diretoria()) erro('Só a Diretoria valida planos.', 403);
    $p = plano_visivel((int)($_POST['id'] ?? 0));
    if ($p['status'] !== 'aguardando_validacao') erro('Este plano não está aguardando validação.');
    $dec = $_POST['decisao'] ?? ''; $parecer = mb_substr(trim($_POST['parecer'] ?? ''), 0, 500); $id = (int)$p['id'];
    if ($dec === 'aprovar') {
        $dest = in_array($p['validacao_destino'], ['encerrado', 'cancelado'], true) ? $p['validacao_destino'] : 'concluido';
        x("UPDATE pa_planos SET status = ?, fim_real = IF(? = 'cancelado', NULL, CURDATE()), validacao = 'aprovada', validacao_destino = NULL, validado_por = ?, validado_em = NOW(), parecer = ? WHERE id = ?", 'ssisi', [$dest, $dest, pa_uid(), $parecer ?: null, $id]);
        pa_log($id, 'validou', ['concluido' => 'Conclusão aprovada', 'encerrado' => 'Encerramento aprovado', 'cancelado' => 'Cancelamento aprovado'][$dest] . ($parecer ? ": $parecer" : ''));
    } elseif ($dec === 'devolver') {
        if ($parecer === '') erro('Informe o parecer ao devolver o plano.');
        x("UPDATE pa_planos SET status = 'em_andamento', fim_real = NULL, encerrado_motivo = NULL, validacao_destino = NULL, validacao = 'devolvida', validado_por = ?, validado_em = NOW(), parecer = ? WHERE id = ?", 'isi', [pa_uid(), $parecer, $id]);
        pa_log($id, 'devolveu', $parecer);
    } else erro('Decisão inválida.');
    pa_json(['ok' => true]);

case 'excluir_plano':
    if (!$post) erro('Método inválido.', 405);
    $p = plano_visivel((int)($_POST['id'] ?? 0));
    if (!pa_eh_admin()) erro('Só o administrador dos Planos de Ação pode excluir planos.', 403);
    pa_log((int)$p['id'], 'excluiu', $p['titulo']);
    x('DELETE FROM pa_planos WHERE id = ?', 'i', [(int)$p['id']]);
    pa_json(['ok' => true]);

case 'salvar_acao':
    if (!$post) erro('Método inválido.', 405);
    $p = plano_visivel((int)($_POST['plano_id'] ?? 0));
    if (!pa_pode_editar($p)) erro('Sem permissão para criar/editar ações neste plano.', 403);
    $id = (int)($_POST['id'] ?? 0);
    $titulo = trim($_POST['titulo'] ?? ''); $resp = (int)($_POST['responsavel_id'] ?? 0);
    $prazo = trim($_POST['prazo'] ?? '') ?: null;
    if ($titulo === '' || !$resp) erro('Informe a ação e o responsável.');
    if ($prazo !== null && !data_ok($prazo)) erro('Prazo inválido.');
    if (!usuario_ativo($resp)) erro('Responsável inválido.');
    $desc = trim($_POST['descricao'] ?? '');
    if ($id) {
        if (!q('SELECT id FROM pa_acoes WHERE id = ? AND plano_id = ?', 'ii', [$id, (int)$p['id']])) erro('Ação não encontrada.', 404);
        x('UPDATE pa_acoes SET titulo=?, descricao=?, responsavel_id=?, prazo=? WHERE id=?', 'ssisi', [$titulo, $desc, $resp, $prazo, $id]);
        pa_log((int)$p['id'], 'acao_editada', $titulo, $id);
    } else {
        $id = x('INSERT INTO pa_acoes (plano_id,titulo,descricao,responsavel_id,prazo,criado_por) VALUES (?,?,?,?,?,?)', 'issisi', [(int)$p['id'], $titulo, $desc, $resp, $prazo, pa_uid()]);
        pa_log((int)$p['id'], 'acao_criada', $titulo, $id);
    }
    pa_json(['ok' => true, 'id' => $id]);

case 'status_acao':
    if (!$post) erro('Método inválido.', 405);
    $r = q('SELECT * FROM pa_acoes WHERE id = ?', 'i', [(int)($_POST['id'] ?? 0)]);
    if (!$r) erro('Ação não encontrada.', 404);
    $ac = $r[0]; $p = plano_visivel((int)$ac['plano_id']);
    if (!pa_pode_editar($p) && (int)$ac['responsavel_id'] !== pa_uid()) erro('Sem permissão.', 403);
    $st = $_POST['status'] ?? '';
    if (!in_array($st, ['pendente', 'em_andamento', 'concluida', 'cancelada'], true)) erro('Status inválido.');
    x('UPDATE pa_acoes SET status = ?, concluida_em = ? WHERE id = ?', 'ssi', [$st, $st === 'concluida' ? date('Y-m-d H:i:s') : null, (int)$ac['id']]);
    pa_log((int)$p['id'], 'acao_status', $ac['titulo'] . ': ' . $ac['status'] . ' → ' . $st, (int)$ac['id']);
    pa_json(['ok' => true]);

case 'excluir_acao':
    if (!$post) erro('Método inválido.', 405);
    $r = q('SELECT * FROM pa_acoes WHERE id = ?', 'i', [(int)($_POST['id'] ?? 0)]);
    if (!$r) erro('Ação não encontrada.', 404);
    $p = plano_visivel((int)$r[0]['plano_id']);
    if (!pa_pode_editar($p)) erro('Sem permissão.', 403);
    pa_log((int)$p['id'], 'acao_excluida', $r[0]['titulo'], (int)$r[0]['id']);
    x('DELETE FROM pa_acoes WHERE id = ?', 'i', [(int)$r[0]['id']]);
    pa_json(['ok' => true]);

case 'acompanhar':
    if (!$post) erro('Método inválido.', 405);
    $p = plano_visivel((int)($_POST['plano_id'] ?? 0));
    if (!pa_pode_editar($p)) erro('Sem permissão.', 403);
    if (!(int)$p['tem_meta']) erro('Este plano não tem meta/indicador.');
    $d = $_POST['data_ref'] ?? ''; $v = num($_POST['valor'] ?? '');
    if (!data_ok($d) || $d > date('Y-m-d') || $v === null) erro('Informe uma data (não futura) e o valor realizado.');
    x('INSERT INTO pa_acompanhamentos (plano_id,data_ref,valor,observacao,usuario_id) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE valor = VALUES(valor), observacao = VALUES(observacao), usuario_id = VALUES(usuario_id)',
      'isdsi', [(int)$p['id'], $d, $v, mb_substr(trim($_POST['observacao'] ?? ''), 0, 255), pa_uid()]);
    pa_log((int)$p['id'], 'acompanhou', "$d = $v");
    pa_json(['ok' => true]);

case 'excluir_acomp':
    if (!$post) erro('Método inválido.', 405);
    $r = q('SELECT * FROM pa_acompanhamentos WHERE id = ?', 'i', [(int)($_POST['id'] ?? 0)]);
    if (!$r) erro('Lançamento não encontrado.', 404);
    $p = plano_visivel((int)$r[0]['plano_id']);
    if (!pa_pode_editar($p)) erro('Sem permissão.', 403);
    x('DELETE FROM pa_acompanhamentos WHERE id = ?', 'i', [(int)$r[0]['id']]);
    pa_log((int)$p['id'], 'acomp_excluido', $r[0]['data_ref']);
    pa_json(['ok' => true]);

case 'dashboard':
    $w = [pa_sql_visibilidade('p')]; $t = ''; $pr = [];
    $setorF = (int)($_GET['setor_id'] ?? 0); $pessoaF = (int)($_GET['usuario_id'] ?? 0);
    if ($setorF)  { $w[] = 'p.setor_id = ?'; $t .= 'i'; $pr[] = $setorF; }
    if ($pessoaF) { $w[] = '(p.responsavel_id = ? OR EXISTS (SELECT 1 FROM pa_acoes z WHERE z.plano_id = p.id AND z.responsavel_id = ?))'; $t .= 'ii'; $pr[] = $pessoaF; $pr[] = $pessoaF; }
    $planos = array_map('pa_enriquecer', q(pa_sql_base() . ' WHERE ' . implode(' AND ', $w), $t, $pr));

    $k = ['total' => count($planos), 'nao_iniciado' => 0, 'em_andamento' => 0, 'pausado' => 0, 'aguardando_validacao' => 0, 'concluido' => 0, 'cancelado' => 0, 'encerrado' => 0,
          'vencidos' => 0, 'sem_atualizacao' => 0, 'metas_atingidas' => 0];
    $porSetor = []; $prog = []; $prox = [];
    foreach ($planos as $p) {
        $k[$p['status']]++;
        $s = &$porSetor[$p['setor_nome']];
        $s ??= ['setor' => $p['setor_nome'], 'total' => 0, 'em_andamento' => 0, 'concluido' => 0, 'vencidos' => 0, 'soma' => 0, 'n' => 0];
        $s['total']++;
        if (in_array($p['status'], PA_ABERTOS, true)) $s['em_andamento']++;
        if ($p['status'] === 'concluido') $s['concluido']++;
        foreach ($p['avisos'] as $av) {
            if ($av['tipo'] === 'vencido') { $k['vencidos']++; $s['vencidos']++; }
            if ($av['tipo'] === 'atualizar') $k['sem_atualizacao']++;
            if ($av['tipo'] === 'atingida' && $p['meta_atingida']) $k['metas_atingidas']++;
        }
        if ($p['progresso'] !== null && !in_array($p['status'], ['cancelado', 'encerrado'], true)) { $s['soma'] += min(100, max(0, $p['progresso'])); $s['n']++; }
        if (in_array($p['status'], PA_ABERTOS, true) && $p['prazo']) $prox[] =['id' => (int)$p['id'], 'titulo' => $p['titulo'], 'setor' => $p['setor_nome'], 'prazo' => $p['prazo'], 'responsavel' => $p['responsavel_nome']];
        unset($s);
    }
    usort($prox, fn($x, $y) => strcmp($x['prazo'], $y['prazo']));
    $porSetor = array_values(array_map(function ($s) { $s['progresso_medio'] = $s['n'] ? round($s['soma'] / $s['n'], 1) : null; unset($s['soma'], $s['n']); return $s; }, $porSetor));

    // Ações por pessoa (dentro dos planos visíveis/filtrados)
    $ids = array_map(fn($p) => (int)$p['id'], $planos);
    $pessoas = [];
    if ($ids) {
        $in = implode(',', $ids);
        $pessoas = q("SELECT u.id, u.nome, COUNT(*) AS acoes,
                        SUM(a.status = 'concluida') AS concluidas,
                        SUM(a.status IN ('pendente','em_andamento')) AS abertas,
                        SUM(a.status IN ('pendente','em_andamento') AND a.prazo < CURDATE()) AS atrasadas
                      FROM pa_acoes a JOIN usuarios u ON u.id = a.responsavel_id
                      WHERE a.plano_id IN ($in) AND a.status <> 'cancelada'" . ($pessoaF ? " AND a.responsavel_id = $pessoaF" : '') . "
                      GROUP BY u.id ORDER BY atrasadas DESC, abertas DESC, u.nome");
    }
    pa_json(['ok' => true, 'kpis' => $k, 'por_setor' => $porSetor, 'por_pessoa' => $pessoas, 'proximos' => array_slice($prox, 0, 8)]);

default:
    erro('Ação desconhecida.', 404);
}
