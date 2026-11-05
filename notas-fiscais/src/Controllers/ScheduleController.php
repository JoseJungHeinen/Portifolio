<?php
// @versao Ace_11 V_01_12/08/2026
declare(strict_types=1);

/**
 * Controller dos Horários de Acesso.
 *
 * Roteamento: ?module=horarios&action=X
 *   index    — lista de horários
 *   editar   — formulário (novo ou existente)
 *   salvar   — POST
 *   excluir  — POST
 *   atribuir — POST: vincula/desvincula usuários de um horário
 *
 * Fica sob a permissão de `acessos` (Controle de Acesso): quem administra
 * cadastro de gente é quem define a jornada dela.
 */

require_once SRC . '/Models/AccessSchedule.php';

// Mesmo gate do AccessController (admin de cadastros + super_admin): quem
// administra o cadastro de gente é quem define a jornada dela. Não passa pelo
// mapa de permissões, igual ao vizinho.
Auth::check();
if (!Permissions::isAdminCadastros()) {
    http_response_code(403);
    include VIEWS . '/errors/403.php';
    exit;
}

$podeEditar = true;

switch ($action) {

    // ── GET: lista ────────────────────────────────────────
    case 'index':
        $horarios = AccessSchedule::listar();

        $pageTitle    = 'Horários de Acesso';
        $activeModule = 'horarios';
        $currentUser  = Auth::user();
        $unreadCount  = 0;

        ob_start();
        include VIEWS . '/horarios/index.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET: exceções concedidas ──────────────────────────
    //    Onde o RH confere o que foi liberado, e por qual processo.
    case 'excecoes':
        $get       = Sanitize::fromGet(['usuario_id', 'quando']);
        $quando    = $get['quando'] === 'todas' ? 'todas' : 'vigentes';
        $usuarioId = '';
        if ($get['usuario_id'] !== '') {
            try { $usuarioId = Sanitize::uuid($get['usuario_id']); } catch (InvalidArgumentException) {}
        }

        $excecoes = AccessSchedule::listarExcecoes($usuarioId, $quando);

        // Só quem já teve alguma exceção entra no filtro — lista de 200 nomes
        // para escolher entre os 3 que aparecem aqui não ajuda ninguém.
        $usuarios = Database::getInstance()->query(
            'SELECT DISTINCT u.id, u.nome FROM nf_horario_excecoes e
               JOIN usuarios u ON u.id = e.usuario_id ORDER BY u.nome'
        )->fetchAll(PDO::FETCH_ASSOC);

        $pageTitle    = 'Exceções de horário';
        $activeModule = 'horarios';
        $currentUser  = Auth::user();
        $unreadCount  = 0;

        ob_start();
        include VIEWS . '/horarios/excecoes.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET: formulário ───────────────────────────────────
    case 'editar':
        $id      = Sanitize::fromGet(['id'])['id'];
        $horario = null;
        $membros = [];

        if ($id !== '') {
            try {
                $horario = AccessSchedule::find(Sanitize::uuid($id));
            } catch (InvalidArgumentException) {
                $horario = null;
            }
            if (!$horario) {
                http_response_code(404);
                include VIEWS . '/errors/404.php';
                exit;
            }
            $membros = AccessSchedule::usuariosDoHorario($horario['id']);
        }

        $usuarios = Database::getInstance()->query(
            "SELECT u.id, u.nome, u.email, u.perfil,
                    (SELECT uh.horario_id FROM nf_usuario_horario uh WHERE uh.usuario_id = u.id) AS horario_id
               FROM usuarios u WHERE u.ativo = 1 ORDER BY u.nome"
        )->fetchAll(PDO::FETCH_ASSOC);

        $pageTitle    = $horario ? $horario['nome'] : 'Novo horário';
        $activeModule = 'horarios';
        $currentUser  = Auth::user();
        $unreadCount  = 0;

        ob_start();
        include VIEWS . '/horarios/detail.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── POST: salvar ──────────────────────────────────────
    case 'salvar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$podeEditar) {
            header('Location: ?module=horarios&action=index');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $post = Sanitize::fromPost(['id', 'nome', 'descricao', 'tolerancia_minutos']);
        $nome = trim($post['nome']);

        if ($nome === '') {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Informe o nome do horário.'];
            header('Location: ?module=horarios&action=editar' . ($post['id'] ? '&id=' . urlencode($post['id']) : ''));
            exit;
        }

        // As 4 horas de cada dia chegam como faixas[dia][t1_inicio] etc.
        $faixas = [];
        foreach (array_keys(AccessSchedule::DIAS) as $dia) {
            foreach (['t1_inicio', 't1_fim', 't2_inicio', 't2_fim'] as $c) {
                $faixas[$dia][$c] = (string) ($_POST['faixas'][$dia][$c] ?? '');
            }
        }

        $dados = [
            'nome'               => $nome,
            'descricao'          => trim($post['descricao']),
            'tolerancia_minutos' => max(0, min(120, (int) $post['tolerancia_minutos'])),
            'ativo'              => isset($_POST['ativo']) ? 1 : 0,
        ];

        try {
            $id  = $post['id'] !== '' ? Sanitize::uuid($post['id']) : null;
            $new = AccessSchedule::salvar($dados, $faixas, $id);

            AuditLog::registrar('nf_horario_acesso', $new,
                $id ? 'nf_horario.atualizado' : 'nf_horario.criado', [], $dados);

            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Horário salvo.'];
            header('Location: ?module=horarios&action=editar&id=' . urlencode($new));
        } catch (Throwable $e) {
            $dup = str_contains($e->getMessage(), 'uq_horario_nome');
            $_SESSION['flash'] = ['type' => 'danger', 'message' => $dup
                ? 'Já existe um horário com esse nome.'
                : 'Não foi possível salvar o horário.'];
            header('Location: ?module=horarios&action=index');
        }
        exit;

    // ── POST: atribuir usuários ───────────────────────────
    case 'atribuir':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$podeEditar) {
            header('Location: ?module=horarios&action=index');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        try {
            $horarioId = Sanitize::uuid((string) ($_POST['horario_id'] ?? ''));
        } catch (InvalidArgumentException) {
            header('Location: ?module=horarios&action=index');
            exit;
        }

        $marcados = [];
        foreach ((array) ($_POST['usuarios'] ?? []) as $u) {
            try { $marcados[] = Sanitize::uuid((string) $u); } catch (InvalidArgumentException) {}
        }

        $porUsuario = Auth::user()['id'];

        // Quem estava e saiu perde o vínculo; quem foi marcado passa a ter este
        // horário. Só mexe em quem já é deste horário ou foi marcado agora —
        // usuário de OUTRO horário não é tocado por engano.
        foreach (AccessSchedule::usuariosDoHorario($horarioId) as $atual) {
            if (!in_array($atual['id'], $marcados, true)) {
                AccessSchedule::atribuir($atual['id'], null, $porUsuario);
            }
        }
        foreach ($marcados as $uid) {
            AccessSchedule::atribuir($uid, $horarioId, $porUsuario);
        }

        AuditLog::registrar('nf_horario_acesso', $horarioId, 'nf_horario.usuarios_atribuidos',
            [], ['total' => count($marcados)]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => count($marcados) . ' usuário(s) neste horário.'];
        header('Location: ?module=horarios&action=editar&id=' . urlencode($horarioId));
        exit;

    // ── POST: excluir ─────────────────────────────────────
    case 'excluir':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$podeEditar) {
            header('Location: ?module=horarios&action=index');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        try {
            $id = Sanitize::uuid((string) ($_POST['id'] ?? ''));
            AccessSchedule::excluir($id);
            AuditLog::registrar('nf_horario_acesso', $id, 'nf_horario.excluido', [], []);
            // Sem horário, os usuários voltam a não ter limite — é o padrão do
            // sistema, e o cascade já removeu os vínculos.
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Horário excluído. Os usuários ficaram sem limite de acesso.'];
        } catch (Throwable) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Não foi possível excluir.'];
        }
        header('Location: ?module=horarios&action=index');
        exit;

    default:
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        exit;
}
