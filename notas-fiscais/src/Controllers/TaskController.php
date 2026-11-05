<?php
// @versao Tar_02 V_13_21/10/2026
declare(strict_types=1);

/**
 * Controller do módulo Minhas Tarefas.
 *
 * Roteamento: ?module=tarefas&action=X
 * Entry point: public/index.php
 */

// ─── Autenticação obrigatória ────────────────────────────
Auth::check();
Permissions::require('tarefas', 'ver');

// ─── Carregar Model ──────────────────────────────────────
require_once SRC . '/Models/Task.php';

/**
 * Observações no formato do painel da revisão em lote — usado pelo
 * detalhe_ajax (carga) e pelo observacao_ajax (depois de gravar uma nova).
 */
function tarefasObservacoesJson(string $processoId, string $usuarioId): array
{
    require_once SRC . '/Models/Observation.php';
    return array_map(static function (array $o) use ($usuarioId): array {
        return [
            'autor' => $o['autor_nome'] ?? ($o['autor_nome_real'] ?? '—'),
            'eu'    => ($o['autor_id'] ?? null) === $usuarioId,
            'texto' => (string) ($o['texto'] ?? ''),
            'quando'=> !empty($o['criado_em']) ? date('d/m/Y H:i', strtotime((string) $o['criado_em'])) : '',
        ];
    }, Observation::listar($processoId));
}

// ─── Determinar action ───────────────────────────────────
$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

// ─────────────────────────────────────────────────────────
// Roteamento de actions
// ─────────────────────────────────────────────────────────

switch ($action) {

    // ── GET: lista de tarefas do usuário logado ──────────
    case 'index':
        $get      = Sanitize::fromGet(['filtro', 'busca']);
        $filtro   = $get['filtro'] ?: 'todas'; // todas | pessoais | grupo
        $busca    = $get['busca'];

        $currentUser = Auth::user();
        $usuarioId   = $currentUser['id'];

        $tarefas      = Task::listByUsuario($usuarioId);
        $stats        = Task::getStats($tarefas);

        $pageTitle    = 'Minhas Tarefas';
        $activeModule = 'tarefas';
        $unreadCount  = 0;
        $cssVer       = filemtime(ROOT . '/public/assets/css/modules/tasks.css');
        $jsVer        = filemtime(ROOT . '/public/assets/js/modules/tasks.js');
        $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/tasks.css?v=' . $cssVer . '">'
                      . '<script src="' . BASE_URL . '/public/assets/js/modules/tasks.js?v=' . $jsVer . '" defer></script>';

        ob_start();
        include VIEWS . '/tasks/index.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET: fila por pessoa (visão de gestão) ───────────
    case 'fila':
        // Somente admin ou quem enxerga todos os processos
        if (!Permissions::isAdmin() && !Permissions::can('processos', 'ver_todos')) {
            http_response_code(403);
            include VIEWS . '/errors/403.php';
            exit;
        }

        require_once SRC . '/Helpers/SlaCalculator.php';

        $pdo      = Database::getInstance();
        $usuarios = $pdo->query("SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

        // Usuário escolhido (precisa estar na lista de ativos)
        $get         = Sanitize::fromGet(['usuario_id']);
        $selecionado = null;
        if ($get['usuario_id'] !== '') {
            try {
                $uid = Sanitize::uuid($get['usuario_id']);
                foreach ($usuarios as $u) {
                    if ($u['id'] === $uid) { $selecionado = $u; break; }
                }
            } catch (InvalidArgumentException $e) {
                // id inválido → trata como não selecionado
            }
        }

        $tarefas = $selecionado ? Task::listByUsuario($selecionado['id']) : [];
        $stats   = Task::getStats($tarefas);

        // Resumo por etapa: qtd, valor somado e o mais antigo (dias parado)
        $resumoEtapas = [];
        foreach ($tarefas as $t) {
            $nome = (string) ($t['nome_etapa'] ?? '—');
            if (!isset($resumoEtapas[$nome])) {
                $resumoEtapas[$nome] = ['qtd' => 0, 'valor' => 0.0, 'mais_antigo_dias' => 0];
            }
            $resumoEtapas[$nome]['qtd']++;
            $resumoEtapas[$nome]['valor'] += (float) $t['valor_nf'];
            $resumoEtapas[$nome]['mais_antigo_dias'] = max(
                $resumoEtapas[$nome]['mais_antigo_dias'],
                (int) ($t['sla']['dias_parado'] ?? 0)
            );
        }
        uasort($resumoEtapas, fn($a, $b) => $b['qtd'] <=> $a['qtd']);

        $currentUser  = Auth::user();
        $pageTitle    = 'Fila por pessoa';
        $activeModule = 'tarefas';
        $unreadCount  = 0;
        $cssVer       = filemtime(ROOT . '/public/assets/css/modules/tasks.css');
        $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/tasks.css?v=' . $cssVer . '">';

        ob_start();
        include VIEWS . '/tasks/fila.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── POST: aprovar etapa de um processo ───────────────
    case 'aprovar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=tarefas&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'etapa_id', 'processo_id', 'observacao']);
        Csrf::validate($post['csrf_token'] ?? '');

        $currentUser = Auth::user();
        $usuarioId   = $currentUser['id'];

        // Validar UUIDs
        try {
            $etapaId   = Sanitize::uuid($post['etapa_id']);
            $processoId = Sanitize::uuid($post['processo_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Identificador inválido.'];
            header('Location: ?module=tarefas&action=index');
            exit;
        }

        // Verificar que a etapa pertence ao usuário (segurança)
        $etapa = Task::findEtapaAutorizada($etapaId, $usuarioId);
        if (!$etapa) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Você não tem permissão para aprovar esta etapa.'];
            header('Location: ?module=tarefas&action=index');
            exit;
        }

        $observacao = trim($post['observacao']);

        $ok = Task::aprovarEtapa($etapaId, $processoId, $usuarioId, $observacao ?: null);

        if ($ok) {
            AuditLog::registrar(
                'nf_processo_etapa',
                $etapaId,
                'nf_etapa.aprovada',
                ['status' => 'pendente'],
                ['status' => 'aprovado', 'atuado_por' => $usuarioId]
            );
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Etapa aprovada com sucesso.'];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' =>
                Task::$ultimoErro ?: 'Não foi possível aprovar a etapa. Tente novamente.'];
        }

        header('Location: ?module=processos&action=detalhe&id=' . $processoId);
        exit;

    // ── POST: devolver etapa com motivo ──────────────────
    case 'devolver':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=tarefas&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'etapa_id', 'processo_id', 'motivo']);
        Csrf::validate($post['csrf_token'] ?? '');

        $currentUser = Auth::user();
        $usuarioId   = $currentUser['id'];

        // Validar UUIDs
        try {
            $etapaId    = Sanitize::uuid($post['etapa_id']);
            $processoId = Sanitize::uuid($post['processo_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Identificador inválido.'];
            header('Location: ?module=tarefas&action=index');
            exit;
        }

        // Verificar que a etapa pertence ao usuário (segurança)
        $etapa = Task::findEtapaAutorizada($etapaId, $usuarioId);
        if (!$etapa) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Você não tem permissão para devolver esta etapa.'];
            header('Location: ?module=tarefas&action=index');
            exit;
        }

        // Destino da devolução — uma pessoa; o model traduz para a etapa dela.
        $usuarioDestino = null;
        if (!empty($_POST['usuario_destino'])) {
            try {
                $usuarioDestino = Sanitize::uuid((string) $_POST['usuario_destino']);
            } catch (InvalidArgumentException $e) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Destino da devolução inválido.'];
                header('Location: ?module=processos&action=detalhe&id=' . $processoId);
                exit;
            }
        }

        $motivo = trim($post['motivo']);
        if ($motivo === '') {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'O motivo da devolução é obrigatório.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId);
            exit;
        }

        $ok = Task::devolverEtapa($etapaId, $processoId, $usuarioId, $motivo, $usuarioDestino);

        if ($ok) {
            AuditLog::registrar(
                'nf_processo_etapa',
                $etapaId,
                'nf_etapa.devolvida',
                ['status' => 'pendente'],
                ['status' => 'devolvido', 'atuado_por' => $usuarioId, 'motivo' => $motivo]
            );
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Processo devolvido com sucesso.'];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Não foi possível devolver o processo. Tente novamente.'];
        }

        header('Location: ?module=processos&action=detalhe&id=' . $processoId);
        exit;

    // ── GET AJAX: pessoas que podem receber a devolução ──
    //    Carregado sob demanda ao abrir o modal — embutir a lista em cada
    //    linha da fila custaria uma consulta por tarefa no carregamento.
    case 'pessoas_devolucao':
        header('Content-Type: application/json; charset=utf-8');

        // Cada case resolve o próprio usuário — não há escopo compartilhado
        // entre os ramos do switch.
        $currentUser = Auth::user();
        $usuarioId   = $currentUser['id'];

        $get = Sanitize::fromGet(['processo_id']);
        try {
            $processoId = Sanitize::uuid($get['processo_id']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['success' => false, 'pessoas' => []]);
            exit;
        }

        // Só quem enxerga o processo consulta seus participantes.
        require_once SRC . '/Helpers/ProcessAccess.php';
        if (!ProcessAccess::pode($processoId, $usuarioId)) {
            echo json_encode(['success' => false, 'pessoas' => []]);
            exit;
        }

        require_once SRC . '/Models/Process.php';
        echo json_encode(['success' => true, 'pessoas' => Process::pessoasParaDevolucao($processoId)]);
        exit;

    // ── GET AJAX: detalhes de um processo para revisão ──
    case 'detalhe_ajax':
        header('Content-Type: application/json; charset=utf-8');

        $get = Sanitize::fromGet(['processo_id']);
        try {
            $processoId = Sanitize::uuid($get['processo_id']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['success' => false]);
            exit;
        }

        $currentUser = Auth::user();
        $usuarioId   = $currentUser['id'];

        // Mesma regra do pessoas_devolucao: só quem enxerga o processo lê os
        // detalhes (agora incluindo as observações).
        require_once SRC . '/Helpers/ProcessAccess.php';
        if (!ProcessAccess::pode($processoId, $usuarioId)) {
            echo json_encode(['success' => false]);
            exit;
        }

        require_once SRC . '/Models/Process.php';
        $proc = Process::findById($processoId);
        if (!$proc) {
            echo json_encode(['success' => false]);
            exit;
        }

        echo json_encode([
            // Observações do processo: na revisão em lote elas não apareciam, e
            // quem aprova precisa ler o mesmo que leria na tela do processo.
            'observacoes' => tarefasObservacoesJson($processoId, $usuarioId),
            'success'    => true,
            'codigo'     => $proc['codigo'],
            'fornecedor' => $proc['fornecedor_nome'],
            'fornecedor_doc' => $proc['fornecedor_doc'] ?? '',
            'numero_nf'  => $proc['numero_nf'],
            'valor_nf'   => number_format((float)$proc['valor_nf'], 2, ',', '.'),
            'data_nf'    => $proc['data_nf'] ? date('d/m/Y', strtotime($proc['data_nf'])) : '—',
            'chave_nf'   => $proc['chave_nf'] ?? '',
            'filial'     => $proc['filial_nome'] ?? '—',
            'template'   => $proc['template_nome'] ?? '—',
            'etapa_nome' => $proc['etapas'][($proc['etapa_atual'] ?? 1) - 1]['nome_etapa'] ?? '—',
            'is_teste'   => (bool)$proc['is_teste'],
            'parcelas'   => array_map(function($p) {
                return [
                    'num'   => $p['numero_parcela'] . '/' . $p['total_parcelas'],
                    'venc'  => $p['vencimento'] ? date('d/m/Y', strtotime($p['vencimento'])) : '—',
                    'valor' => 'R$ ' . number_format((float)$p['valor'], 2, ',', '.'),
                    'forma' => $p['forma_nome'] ?? '—',
                ];
            }, $proc['parcelas'] ?? []),
            'anexos' => array_map(function($a) use ($processoId) {
                return [
                    'nome' => $a['nome_original'],
                    'tipo' => $a['tipo_anexo_nome'] ?? '',
                    'mime' => $a['mime_type'] ?? '',
                    'url'  => '?module=processos&action=download_anexo&processo_id=' . $processoId . '&anexo_id=' . $a['id'] . '&inline=1',
                ];
            }, $proc['anexos'] ?? []),
            'etapa_atual'   => (int) ($proc['etapa_atual'] ?? 1),
            'total_etapas'  => count($proc['etapas'] ?? []),
            // Aba "Fluxo": a mesma timeline da tela de aprovação normal, enxuta
            // para caber no painel de 460px do revisor.
            'etapas' => array_map(static function (array $e): array {
                return [
                    'numero'    => (int) $e['numero_etapa'],
                    'nome'      => $e['nome_etapa'] ?? '—',
                    'status'    => $e['status'] ?? 'pendente',
                    'aprovador' => $e['aprovador_nome'] ?? '—',
                    'tipo'      => $e['tipo_aprovador'] ?? '',
                    'atuado_por'=> $e['atuado_por_nome'] ?? '',
                    'atuado_em' => !empty($e['atuado_em']) ? date('d/m/Y H:i', strtotime((string) $e['atuado_em'])) : '',
                    'observacao'=> $e['observacao'] ?? '',
                ];
            }, $proc['etapas'] ?? []),
            // Aba "Histórico": mais recente primeiro (getHistorico devolve ASC).
            // O rótulo é montado aqui, e não no JS, para não existirem duas
            // traduções de `acao` divergindo entre o revisor e a tela normal.
            'historico' => array_map(static function (array $h): array {
                $acao = (string) ($h['acao'] ?? '');
                $label = match ($acao) {
                    'processo_aberto', 'processo_criado' => 'Processo aberto',
                    'etapa_aprovada'          => 'Aprovado',
                    'processo_devolvido'      => 'Devolvido',
                    'processo_aprovado'       => 'Processo concluído',
                    'processo_reenviado'      => 'Processo reenviado',
                    'processo_cancelado'      => 'Processo cancelado',
                    'arquivo_substituido'     => 'Arquivo substituído',
                    'arquivo_enviado'         => 'Arquivo enviado',
                    'etapa_avancada'          => 'Encaminhado',
                    'etapa_reaberta'          => 'Retornou',
                    'cancelamento_desfeito'   => 'Solicitação de cancelamento desfeita',
                    'cancelamento_solicitado' => 'Cancelamento solicitado',
                    'responsavel_reatribuido' => 'Responsável reatribuído',
                    'fluxo_alterado'          => 'Fluxo alterado',
                    'processo_visualizado'    => 'Visualizado',
                    'processo_encerrado_apollo' => 'Processo encerrado',
                    'nota_vinculada'          => 'Nota vinculada',
                    default                   => $acao,
                };
                return [
                    'acao'    => $acao,
                    'label'   => $label,
                    'detalhe' => (string) ($h['detalhe'] ?? ''),
                    'ator'    => $h['ator_nome_real'] ?? ($h['ator_nome'] ?? '—'),
                    'quando'  => !empty($h['criado_em']) ? date('d/m/Y H:i', strtotime((string) $h['criado_em'])) : '',
                ];
            }, array_reverse($proc['historico'] ?? [])),
            // Campos do formulário do fluxo — só os preenchidos, para a aba "Formulário"
            'campos' => (static function ($df) {
                if (!is_array($df)) return [];
                $out = [];
                foreach ($df as $chave => $valor) {
                    if ($valor === '' || $valor === null) continue;
                    $out[] = [
                        'label' => (string) $chave,
                        'valor' => is_array($valor) ? implode(', ', $valor) : (string) $valor,
                    ];
                }
                return $out;
            })($proc['dados_formulario'] ?? []),
        ]);
        exit;

    // ── POST (AJAX): nova observação a partir da revisão em lote ──
    //    Mesma regra do criar_observacao da tela do processo (gate de acesso +
    //    texto obrigatório), mas responde JSON: o revisor não pode recarregar a
    //    página, senão perde a fila em andamento.
    case 'observacao_ajax':
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Método inválido.']);
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $currentUser = Auth::user();
        $usuarioId   = $currentUser['id'];
        $post        = Sanitize::fromPost(['processo_id', 'texto']);

        try {
            $processoId = Sanitize::uuid($post['processo_id']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['success' => false, 'message' => 'Processo inválido.']);
            exit;
        }

        if (!ProcessAccess::pode($processoId, $usuarioId)) {
            echo json_encode(['success' => false, 'message' => 'Você não tem acesso a este processo.']);
            exit;
        }

        $texto = trim((string) $post['texto']);
        if (mb_strlen($texto) < 3) {
            echo json_encode(['success' => false, 'message' => 'Escreva a observação (mínimo 3 caracteres).']);
            exit;
        }

        require_once SRC . '/Models/Observation.php';
        Observation::criar($processoId, $usuarioId, (string) $currentUser['nome'], $texto);

        echo json_encode([
            'success'     => true,
            'observacoes' => tarefasObservacoesJson($processoId, $usuarioId),
        ]);
        exit;

    // ── POST: aprovar etapas em lote ────────────────────
    case 'aprovar_lote':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=tarefas&action=index');
            exit;
        }

        Csrf::validate($_POST['csrf_token'] ?? '');

        $currentUser = Auth::user();
        $usuarioId   = $currentUser['id'];

        $etapas = $_POST['etapas'] ?? [];
        if (!is_array($etapas) || empty($etapas)) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Nenhuma tarefa selecionada.'];
            header('Location: ?module=tarefas&action=index');
            exit;
        }

        $aprovadas = 0;
        $erros     = 0;

        foreach ($etapas as $item) {
            $etapaId    = $item['etapa_id']    ?? '';
            $processoId = $item['processo_id'] ?? '';

            try {
                $etapaId    = Sanitize::uuid($etapaId);
                $processoId = Sanitize::uuid($processoId);
            } catch (InvalidArgumentException $e) {
                $erros++;
                continue;
            }

            $etapa = Task::findEtapaAutorizada($etapaId, $usuarioId);
            if (!$etapa) {
                $erros++;
                continue;
            }

            $ok = Task::aprovarEtapa($etapaId, $processoId, $usuarioId, null);
            if ($ok) {
                AuditLog::registrar('nf_processo_etapa', $etapaId, 'nf_etapa.aprovada_lote',
                    ['status' => 'pendente'], ['status' => 'aprovado', 'atuado_por' => $usuarioId]);
                $aprovadas++;
            } else {
                $erros++;
            }
        }

        if ($aprovadas > 0 && $erros === 0) {
            $_SESSION['flash'] = ['type' => 'success', 'message' => "{$aprovadas} tarefa(s) aprovada(s) com sucesso."];
        } elseif ($aprovadas > 0) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => "{$aprovadas} aprovada(s), {$erros} com erro."];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Nenhuma tarefa pôde ser aprovada.'];
        }

        header('Location: ?module=tarefas&action=index');
        exit;

    // ── POST: devolver etapas em lote ────────────────────
    case 'devolver_lote':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=tarefas&action=index');
            exit;
        }

        Csrf::validate($_POST['csrf_token'] ?? '');

        $currentUser = Auth::user();
        $usuarioId   = $currentUser['id'];

        $etapas = $_POST['etapas'] ?? [];
        if (!is_array($etapas) || empty($etapas)) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Nenhuma tarefa para devolver.'];
            header('Location: ?module=tarefas&action=index');
            exit;
        }

        $devolvidas = 0;
        $erros      = 0;

        foreach ($etapas as $item) {
            $etapaId    = $item['etapa_id']    ?? '';
            $processoId = $item['processo_id'] ?? '';
            $motivo     = trim($item['motivo'] ?? '');

            if ($motivo === '') { $erros++; continue; }

            try {
                $etapaId    = Sanitize::uuid($etapaId);
                $processoId = Sanitize::uuid($processoId);
            } catch (InvalidArgumentException $e) {
                $erros++;
                continue;
            }

            $etapa = Task::findEtapaAutorizada($etapaId, $usuarioId);
            if (!$etapa) { $erros++; continue; }

            $usuarioDestino = null;
            if (!empty($item['usuario_destino'])) {
                try { $usuarioDestino = Sanitize::uuid((string) $item['usuario_destino']); }
                catch (InvalidArgumentException $e) { $erros++; continue; }
            }

            $ok = Task::devolverEtapa($etapaId, $processoId, $usuarioId, $motivo, $usuarioDestino);
            if ($ok) {
                AuditLog::registrar('nf_processo_etapa', $etapaId, 'nf_etapa.devolvida_lote',
                    ['status' => 'pendente'], ['status' => 'devolvido', 'motivo' => $motivo]);
                $devolvidas++;
            } else {
                $erros++;
            }
        }

        if ($devolvidas > 0 && $erros === 0) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => "{$devolvidas} tarefa(s) devolvida(s)."];
        } elseif ($devolvidas > 0) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => "{$devolvidas} devolvida(s), {$erros} com erro."];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Nenhuma tarefa pôde ser devolvida.'];
        }

        header('Location: ?module=tarefas&action=index');
        exit;

    // ── POST (AJAX): aprovar 1 etapa e devolver JSON ─────
    //    Usado pela revisão sequencial (commit imediato por item).
    case 'aprovar_item':
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Método inválido.']);
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $currentUser = Auth::user();
        $usuarioId   = $currentUser['id'];
        $post        = Sanitize::fromPost(['etapa_id', 'processo_id']);

        try {
            $etapaId    = Sanitize::uuid($post['etapa_id']);
            $processoId = Sanitize::uuid($post['processo_id']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }

        $etapa = Task::findEtapaAutorizada($etapaId, $usuarioId);
        if (!$etapa) {
            echo json_encode(['success' => false, 'message' => 'Você não tem permissão para aprovar este processo.']);
            exit;
        }

        $ok = Task::aprovarEtapa($etapaId, $processoId, $usuarioId, null);
        if ($ok) {
            AuditLog::registrar('nf_processo_etapa', $etapaId, 'nf_etapa.aprovada_lote',
                ['status' => 'pendente'], ['status' => 'aprovado', 'atuado_por' => $usuarioId]);
            echo json_encode(['success' => true, 'message' => 'Tarefa aprovada.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Não foi possível aprovar a tarefa.']);
        }
        exit;

    // ── POST (AJAX): devolver 1 etapa e devolver JSON ────
    case 'devolver_item':
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Método inválido.']);
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $currentUser = Auth::user();
        $usuarioId   = $currentUser['id'];
        $post        = Sanitize::fromPost(['etapa_id', 'processo_id', 'motivo']);
        $motivo      = trim($post['motivo'] ?? '');

        if (mb_strlen($motivo) < 5) {
            echo json_encode(['success' => false, 'message' => 'Informe um motivo de devolução (mínimo 5 caracteres).']);
            exit;
        }

        try {
            $etapaId    = Sanitize::uuid($post['etapa_id']);
            $processoId = Sanitize::uuid($post['processo_id']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }

        $etapa = Task::findEtapaAutorizada($etapaId, $usuarioId);
        if (!$etapa) {
            echo json_encode(['success' => false, 'message' => 'Você não tem permissão para devolver este processo.']);
            exit;
        }

        $usuarioDestino = null;
        if (!empty($_POST['usuario_destino'])) {
            try {
                $usuarioDestino = Sanitize::uuid((string) $_POST['usuario_destino']);
            } catch (InvalidArgumentException $e) {
                echo json_encode(['success' => false, 'message' => 'Destino da devolução inválido.']);
                exit;
            }
        }

        $ok = Task::devolverEtapa($etapaId, $processoId, $usuarioId, $motivo, $usuarioDestino);
        if ($ok) {
            AuditLog::registrar('nf_processo_etapa', $etapaId, 'nf_etapa.devolvida_lote',
                ['status' => 'pendente'], ['status' => 'devolvido', 'motivo' => $motivo]);
            echo json_encode(['success' => true, 'message' => 'Tarefa devolvida.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Não foi possível devolver a tarefa.']);
        }
        exit;

    // ── POST (AJAX): assumir a fila inteira ao entrar na revisão ────
    //    Entrar no revisor em lote é assumir o que foi selecionado. Sem isso
    //    uma etapa de GRUPO segue sem dono e o Devolver de dentro do revisor
    //    age sobre tarefa que não é de ninguém. Mesma conversão do botão
    //    "Assumir tarefa" do detalhe, aplicada item a item.
    case 'assumir_lote':
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Método inválido.']);
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $currentUser = Auth::user();
        $usuarioId   = $currentUser['id'];

        $etapaIds    = (array) ($_POST['etapa_ids']    ?? []);
        $processoIds = (array) ($_POST['processo_ids'] ?? []);

        if (count($etapaIds) === 0 || count($etapaIds) !== count($processoIds)) {
            echo json_encode(['success' => false, 'message' => 'Seleção inválida.']);
            exit;
        }

        $liberados     = [];  // o usuário é dono e pode trabalhar no revisor
        $indisponiveis = [];  // saíram da fila (outro assumiu, etapa avançou…)

        foreach ($etapaIds as $i => $rawEtapa) {
            try {
                $etapaId    = Sanitize::uuid((string) $rawEtapa);
                $processoId = Sanitize::uuid((string) $processoIds[$i]);
            } catch (InvalidArgumentException $e) {
                continue;
            }

            $etapa = Task::findEtapaAutorizada($etapaId, $usuarioId);
            if (!$etapa) {
                $indisponiveis[] = ['etapa_id' => $etapaId, 'motivo' => 'não está mais disponível para você'];
                continue;
            }

            // Já é etapa de usuário: é sua, não há o que assumir.
            if (($etapa['tipo_aprovador'] ?? '') !== 'grupo') {
                $liberados[] = ['etapa_id' => $etapaId, 'processo_id' => $processoId];
                continue;
            }

            // Sem gate de `processos.assumir` aqui de propósito. Essa permissão
            // é do botão "Assumir tarefa" do detalhe e vem false para todos os
            // perfis fora o super_admin — exigi-la devolvia a fila inteira como
            // indisponível. Quem chegou nesta lista já é aprovador legítimo da
            // etapa (findEtapaAutorizada acima) e já podia aprovar uma a uma
            // pela própria tela: assumir em lote não dá poder novo, só evita
            // que a etapa siga sem dono dentro do revisor.
            $res = Task::assumir($etapaId, $processoId, $usuarioId);
            if ($res['ok']) {
                AuditLog::registrar('nf_processo_etapa', $etapaId, 'nf_etapa.assumida',
                    ['tipo_aprovador' => 'grupo'],
                    ['tipo_aprovador' => 'usuario', 'aprovador_id' => $usuarioId, 'origem' => 'revisao_lote']);
                $liberados[] = ['etapa_id' => $etapaId, 'processo_id' => $processoId];
            } else {
                $indisponiveis[] = ['etapa_id' => $etapaId, 'motivo' => $res['motivo'] ?? 'não foi possível assumir'];
            }
        }

        echo json_encode([
            'success'       => true,
            'liberados'     => $liberados,
            'indisponiveis' => $indisponiveis,
        ], JSON_UNESCAPED_UNICODE);
        exit;

    // ── POST: assumir tarefa (grupo → usuário logado) ────
    //    A etapa deixa de ser do grupo e passa a ser do usuário; sai da fila
    //    dos demais membros. Acionado pelo botão "Assumir tarefa" no detalhe.
    case 'assumir':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=tarefas&action=index');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        if (!Permissions::can('processos', 'assumir')) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Você não tem permissão para assumir tarefas de grupo.'];
            header('Location: ?module=tarefas&action=index');
            exit;
        }

        $currentUser = Auth::user();
        $usuarioId   = $currentUser['id'];
        $post        = Sanitize::fromPost(['etapa_id', 'processo_id', 'voltar']);

        try {
            $etapaId    = Sanitize::uuid($post['etapa_id']);
            $processoId = Sanitize::uuid($post['processo_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
            header('Location: ?module=tarefas&action=index');
            exit;
        }

        $res = Task::assumir($etapaId, $processoId, $usuarioId);
        if ($res['ok']) {
            AuditLog::registrar('nf_processo_etapa', $etapaId, 'nf_etapa.assumida',
                ['tipo_aprovador' => 'grupo'], ['tipo_aprovador' => 'usuario', 'aprovador_id' => $usuarioId]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Tarefa assumida. O processo agora é seu e saiu da fila do grupo.'];
        } else {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => $res['motivo'] ?? 'Não foi possível assumir a tarefa.'];
        }

        // Volta para o detalhe do processo (segue trabalhando ali), preservando
        // o modo embed (iframe) e a origem/voltar quando vieram na URL do form.
        $qs  = Sanitize::fromGet(['embed', 'origem', 'voltar']);
        $destino = '?module=processos&action=detalhe&id=' . urlencode($processoId);
        if (($qs['embed'] ?? '') !== '')  { $destino .= '&embed=' . urlencode($qs['embed']); }
        if (($qs['origem'] ?? '') !== '') { $destino .= '&origem=' . urlencode($qs['origem']); }
        if (($qs['voltar'] ?? '') !== '') { $destino .= '&voltar=' . urlencode($qs['voltar']); }
        header('Location: ' . $destino);
        exit;

    // ── Fallback 404 ─────────────────────────────────────
    default:
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        exit;
}
