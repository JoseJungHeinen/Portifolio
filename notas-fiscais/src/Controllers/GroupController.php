<?php
// @versao Grp_03 V_01_16/07/2026
declare(strict_types=1);

/**
 * Controller do módulo Grupos de Aprovação.
 *
 * Roteamento: ?module=grupos&action=X
 * Entry point: public/index.php
 */

// ─── Autenticação obrigatória ────────────────────────────
Auth::check();
Permissions::require('grupos', 'ver');

// ─── Carregar Model ──────────────────────────────────────
require_once SRC . '/Models/Group.php';

// ─── Determinar action ───────────────────────────────────
$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

// Actions que exigem permissão de criação/edição
$actionsEscrita = ['salvar', 'atualizar', 'toggle_status', 'add_membro', 'remove_membro'];
if (in_array($action, $actionsEscrita, true)) {
    Permissions::require('grupos', 'criar');
}

// ─────────────────────────────────────────────────────────
// Roteamento de actions
// ─────────────────────────────────────────────────────────

switch ($action) {

    // ── GET: lista de grupos ──────────────────────────────
    case 'index':
        $get       = Sanitize::fromGet(['status', 'busca', 'pagina', 'page']);
        $status    = $get['status'];
        $busca     = $get['busca'];
        // 'page' é o parâmetro gerado pelo componente de paginação; 'pagina' é legado
        $pagina    = max(1, (int) ($get['page'] ?: $get['pagina'] ?: 1));

        $filters = [
            'status'    => $status,
            'busca'     => $busca,
            'pagina'    => $pagina,
            'por_pagina' => ITEMS_PER_PAGE,
        ];

        $grupos   = Group::list($filters);
        $total    = Group::count($filters);
        $totais   = Group::getTotais();
        $totalPgs = (int) ceil($total / ITEMS_PER_PAGE);

        $pageTitle    = 'Grupos';
        $activeModule = 'grupos';
        $currentUser  = Auth::user();
        $unreadCount  = 0;
        $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/groups.css">'
                      . '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/tables.css">';

        ob_start();
        include VIEWS . '/groups/list.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET: formulário de novo grupo ────────────────────
    case 'novo':
        $setores = Group::getSetores();
        $usuariosAtivos = Database::getInstance()
            ->query("SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome")->fetchAll();

        $pageTitle    = 'Novo Grupo';
        $activeModule = 'grupos';
        $currentUser  = Auth::user();
        $unreadCount  = 0;
        $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/groups.css">';

        ob_start();
        include VIEWS . '/groups/detail.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET: detalhe / edição de grupo ───────────────────
    case 'detalhe':
        $id = Sanitize::fromGet(['id'])['id'];

        try {
            $id = Sanitize::uuid($id);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID de grupo inválido.'];
            header('Location: ?module=grupos&action=index');
            exit;
        }

        $grupo = Group::findById($id);
        if (!$grupo) {
            http_response_code(404);
            include VIEWS . '/errors/404.php';
            exit;
        }

        $setores = Group::getSetores();
        $usuariosAtivos = Database::getInstance()
            ->query("SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome")->fetchAll();

        $pageTitle    = h($grupo['nome']);
        $activeModule = 'grupos';
        $currentUser  = Auth::user();
        $unreadCount  = 0;
        $jsVer     = filemtime(ROOT . '/public/assets/js/modules/groups.js');
        $cssVer    = filemtime(ROOT . '/public/assets/css/modules/groups.css');
        $extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/groups.css?v=' . $cssVer . '">'
                   . '<script src="' . BASE_URL . '/public/assets/js/modules/groups.js?v=' . $jsVer . '" defer></script>';

        ob_start();
        include VIEWS . '/groups/detail.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── POST: criar novo grupo ───────────────────────────
    case 'salvar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=grupos&action=novo');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'nome', 'setor_id', 'descricao', 'gestor_id']);
        Csrf::validate($post['csrf_token']);

        $erros = [];

        try {
            $nome = Sanitize::text($post['nome'], 100);
            if ($nome === '') {
                $erros[] = 'O nome do grupo é obrigatório.';
            }
        } catch (InvalidArgumentException $e) {
            $erros[] = 'Nome inválido: ' . $e->getMessage();
            $nome    = '';
        }

        $setorId  = $post['setor_id'] ?: null;
        if ($setorId !== null) {
            try {
                $setorId = Sanitize::uuid($setorId);
            } catch (InvalidArgumentException $e) {
                $setorId = null;
            }
        }

        $gestorId = $post['gestor_id'] ?: null;
        if ($gestorId !== null) {
            try {
                $gestorId = Sanitize::uuid($gestorId);
            } catch (InvalidArgumentException $e) {
                $gestorId = null;
            }
        }

        try {
            $descricao = $post['descricao'] !== '' ? Sanitize::text($post['descricao'], 1000) : null;
        } catch (InvalidArgumentException $e) {
            $erros[]   = 'Descrição muito longa.';
            $descricao = null;
        }

        if (!empty($erros)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => implode(' ', $erros)];
            header('Location: ?module=grupos&action=novo');
            exit;
        }

        $novoId = Group::create([
            'nome'      => $nome,
            'setor_id'  => $setorId,
            'descricao' => $descricao,
            'gestor_id' => $gestorId,
        ]);

        AuditLog::registrar('nf_grupo', $novoId, 'nf_grupo.criado', null, [
            'nome'     => $nome,
            'setor_id' => $setorId,
        ]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Grupo criado com sucesso! Agora adicione os membros.'];
        header('Location: ?module=grupos&action=detalhe&id=' . $novoId);
        exit;

    // ── POST: atualizar grupo existente ───────────────────
    case 'atualizar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=grupos&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'id', 'nome', 'setor_id', 'descricao', 'gestor_id']);
        Csrf::validate($post['csrf_token']);

        try {
            $id = Sanitize::uuid($post['id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
            header('Location: ?module=grupos&action=index');
            exit;
        }

        $grupo = Group::findById($id);
        if (!$grupo) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Grupo não encontrado.'];
            header('Location: ?module=grupos&action=index');
            exit;
        }

        $erros = [];

        try {
            $nome = Sanitize::text($post['nome'], 100);
            if ($nome === '') {
                $erros[] = 'O nome do grupo é obrigatório.';
            }
        } catch (InvalidArgumentException $e) {
            $erros[] = 'Nome inválido: ' . $e->getMessage();
            $nome    = '';
        }

        $setorId = $post['setor_id'] ?: null;
        if ($setorId !== null) {
            try {
                $setorId = Sanitize::uuid($setorId);
            } catch (InvalidArgumentException $e) {
                $setorId = null;
            }
        }

        $gestorId = $post['gestor_id'] ?: null;
        if ($gestorId !== null) {
            try {
                $gestorId = Sanitize::uuid($gestorId);
            } catch (InvalidArgumentException $e) {
                $gestorId = null;
            }
        }

        try {
            $descricao = $post['descricao'] !== '' ? Sanitize::text($post['descricao'], 1000) : null;
        } catch (InvalidArgumentException $e) {
            $erros[]   = 'Descrição muito longa.';
            $descricao = null;
        }

        if (!empty($erros)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => implode(' ', $erros)];
            header('Location: ?module=grupos&action=detalhe&id=' . $id);
            exit;
        }

        $antes = ['nome' => $grupo['nome'], 'setor_id' => $grupo['setor_id'], 'descricao' => $grupo['descricao']];

        Group::update($id, [
            'nome'      => $nome,
            'setor_id'  => $setorId,
            'descricao' => $descricao,
            'gestor_id' => $gestorId,
        ]);

        AuditLog::registrar('nf_grupo', $id, 'nf_grupo.atualizado', $antes, [
            'nome'      => $nome,
            'setor_id'  => $setorId,
            'descricao' => $descricao,
        ]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Grupo atualizado com sucesso.'];
        header('Location: ?module=grupos&action=detalhe&id=' . $id);
        exit;

    // ── POST AJAX: ativar / desativar grupo ───────────────
    case 'toggle_status':
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Método não permitido.', 'processos_ativos' => 0]);
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'id']);

        // Verificar CSRF
        try {
            Csrf::validate($post['csrf_token']);
        } catch (RuntimeException $e) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token inválido.', 'processos_ativos' => 0]);
            exit;
        }

        try {
            $id = Sanitize::uuid($post['id']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.', 'processos_ativos' => 0]);
            exit;
        }

        $resultado = Group::toggleStatus($id);

        if ($resultado['success']) {
            $acao    = $resultado['ativo'] ? 'ativado' : 'desativado';
            AuditLog::registrar('nf_grupo', $id, "nf_grupo.{$acao}");
        }

        echo json_encode($resultado);
        exit;

    // ── GET AJAX: buscar usuários para autocomplete ───────
    case 'buscar_usuarios':
        header('Content-Type: application/json; charset=utf-8');

        $get      = Sanitize::fromGet(['q', 'grupo_id']);
        $query    = trim($get['q']);
        $grupoId  = $get['grupo_id'];

        if (mb_strlen($query) < 2) {
            echo json_encode([]);
            exit;
        }

        try {
            $grupoId = Sanitize::uuid($grupoId);
        } catch (InvalidArgumentException $e) {
            echo json_encode([]);
            exit;
        }

        $usuarios = Group::searchUsuarios($query, $grupoId);
        echo json_encode($usuarios);
        exit;

    // ── POST AJAX: adicionar membro ao grupo ──────────────
    case 'add_membro':
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'grupo_id', 'usuario_id']);

        try {
            Csrf::validate($post['csrf_token']);
        } catch (RuntimeException $e) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token inválido.']);
            exit;
        }

        try {
            $grupoId   = Sanitize::uuid($post['grupo_id']);
            $usuarioId = Sanitize::uuid($post['usuario_id']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }

        // Verificar que o grupo existe
        $grupo = Group::findById($grupoId);
        if (!$grupo) {
            echo json_encode(['success' => false, 'message' => 'Grupo não encontrado.']);
            exit;
        }

        $adicionado = Group::addMembro($grupoId, $usuarioId);

        if (!$adicionado) {
            echo json_encode(['success' => false, 'message' => 'Usuário já é membro deste grupo.']);
            exit;
        }

        AuditLog::registrar('nf_grupo', $grupoId, 'nf_grupo.membro_adicionado', null, [
            'usuario_id' => $usuarioId,
        ]);

        // Retornar dados atualizados do membro adicionado
        $membros = Group::getMembros($grupoId);
        $membro  = null;
        foreach ($membros as $m) {
            if ($m['usuario_id'] === $usuarioId) {
                $membro = $m;
                break;
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Membro adicionado com sucesso.',
            'membro'  => $membro,
        ]);
        exit;

    // ── POST AJAX: remover membro do grupo ────────────────
    case 'remove_membro':
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'grupo_id', 'usuario_id']);

        try {
            Csrf::validate($post['csrf_token']);
        } catch (RuntimeException $e) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token inválido.']);
            exit;
        }

        try {
            $grupoId   = Sanitize::uuid($post['grupo_id']);
            $usuarioId = Sanitize::uuid($post['usuario_id']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            exit;
        }

        $removido = Group::removeMembro($grupoId, $usuarioId);

        if (!$removido) {
            echo json_encode(['success' => false, 'message' => 'Membro não encontrado.']);
            exit;
        }

        AuditLog::registrar('nf_grupo', $grupoId, 'nf_grupo.membro_removido', [
            'usuario_id' => $usuarioId,
        ]);

        echo json_encode(['success' => true, 'message' => 'Membro removido com sucesso.']);
        exit;

    // ── Fallback 404 ─────────────────────────────────────
    default:
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        exit;
}
