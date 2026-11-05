<?php
// @versao Cta_02 V_02_12/08/2026
declare(strict_types=1);

/**
 * Controller de "Meu Perfil" — dados do próprio usuário logado e
 * troca da própria senha (self-service, com verificação da senha atual).
 *
 * Roteamento: ?module=account&action=X
 * Acesso: qualquer usuário autenticado (sem gate de admin).
 */

Auth::check();

require_once SRC . '/Models/UserPermission.php';

$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'profile';

switch ($action) {

    // ── GET: tela de perfil ───────────────────────────────
    case 'profile':
        $usuario = Auth::user();

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT u.id, u.perfil, u.ultimo_acesso, f.nome AS filial_nome, s.nome AS setor_nome
               FROM usuarios u
               LEFT JOIN filiais f ON f.id = u.filial_id
               LEFT JOIN setores s ON s.id = u.setor_id
              WHERE u.id = ?
              LIMIT 1"
        );
        $stmt->execute([$usuario['id']]);
        $dados = $stmt->fetch() ?: [];

        $pageTitle    = 'Meu Perfil';
        $activeModule = 'account';
        $currentUser  = $usuario;

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        ob_start();
        include VIEWS . '/account/profile.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── POST: trocar a própria senha ──────────────────────
    case 'trocar_senha':
        $post = Sanitize::fromPost(['csrf_token', 'senha_atual', 'nova_senha', 'confirma_senha']);

        try {
            Csrf::validate($post['csrf_token'] ?? '');
        } catch (RuntimeException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
            header('Location: ?module=account&action=profile');
            exit;
        }

        $novaSenha = $post['nova_senha'];

        if (mb_strlen($novaSenha) < 6) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'A nova senha deve ter no mínimo 6 caracteres.'];
        } elseif ($novaSenha !== $post['confirma_senha']) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'As senhas não coincidem.'];
        } else {
            $ok = UserPermission::trocarSenhaPropria(Auth::id(), $post['senha_atual'], $novaSenha);

            if (!$ok) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Senha atual incorreta.'];
            } else {
                AuditLog::registrar('usuario', Auth::id(), 'nf_usuario.senha_alterada_propria');
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Senha alterada com sucesso.'];
            }
        }

        header('Location: ?module=account&action=profile');
        exit;

    // ── Fallback 404 ─────────────────────────────────────
    default:
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        exit;
}
