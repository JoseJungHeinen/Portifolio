<?php
// @versao Log_02 V_04_18/09/2026
declare(strict_types=1);

/**
 * Controller de autenticação nativa (login/logout).
 *
 * Roteamento: ?module=auth&action=X
 * Única rota acessível sem sessão (ver AuthMiddleware::handle()).
 *
 * Bloqueio por tentativas: reutiliza usuarios.tentativas_login e
 * usuarios.bloqueado_ate (sem tabela própria) — ao atingir
 * MAX_LOGIN_ATTEMPTS, a conta fica bloqueada por LOGIN_LOCKOUT_MINUTES
 * e o contador zera para a próxima janela.
 */

$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

/**
 * Aceita apenas destinos internos relativos (ex: "/index.php?module=...")
 * como "next" — nunca uma URL externa ou protocol-relative, para não
 * transformar o login num open redirect.
 */
function authNextSeguro(string $next): string
{
    $seguro = $next !== ''
        && str_starts_with($next, '/')
        && !str_starts_with($next, '//')
        && !str_contains($next, '://')
        && !str_contains($next, 'module=auth');

    return $seguro ? $next : '';
}

// APP_PUBLIC_URL já resolve a diferença entre Server_novo (Alias na raiz do
// app, precisa de "/public") e Server-APP (Alias já mira em .../public) —
// ver config/constants.php.
switch ($action) {

    // ── GET: formulário de login ──────────────────────────────
    case 'index':
        if (!empty($_SESSION['usuario_id'])) {
            header('Location: ' . APP_PUBLIC_URL . '/index.php?module=tarefas&action=index');
            exit;
        }

        $get    = Sanitize::fromGet(['motivo', 'next']);
        $motivo = $get['motivo'];
        $next   = authNextSeguro($get['next']);
        $erro   = null;

        $avisos = [
            'timeout'       => 'Sua sessão expirou por inatividade. Faça login novamente.',
            'acesso_negado' => 'Sua conta não tem mais acesso a este sistema. Contate o administrador.',
            'logout'        => 'Você saiu do sistema.',
        ];
        $aviso = $avisos[$motivo] ?? null;

        include VIEWS . '/auth/login.php';
        exit;

    // ── POST: autenticar ──────────────────────────────────────
    case 'login':
        $post = Sanitize::fromPost(['csrf_token', 'email', 'senha', 'next']);
        $next = authNextSeguro($post['next']);

        try {
            Csrf::validate($post['csrf_token'] ?? '');
        } catch (RuntimeException $e) {
            $erro = 'Sessão expirada. Recarregue a página e tente novamente.';
            $aviso = null;
            include VIEWS . '/auth/login.php';
            exit;
        }

        $erro  = null;
        $aviso = null;

        try {
            $email = Sanitize::email($post['email']);
        } catch (InvalidArgumentException $e) {
            $erro = 'E-mail ou senha inválidos.';
            include VIEWS . '/auth/login.php';
            exit;
        }

        $senha = $post['senha'];

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, nome, email, senha_hash, perfil, ativo, tentativas_login, bloqueado_ate
               FROM usuarios
              WHERE email = ?
              LIMIT 1'
        );
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        $agora = time();

        if ($usuario && $usuario['bloqueado_ate'] && strtotime($usuario['bloqueado_ate']) > $agora) {
            $minutos = (int) ceil((strtotime($usuario['bloqueado_ate']) - $agora) / 60);
            $erro = "Conta bloqueada temporariamente por excesso de tentativas. Tente novamente em {$minutos} min.";
        } elseif (!$usuario || !$usuario['ativo'] || !password_verify($senha, (string) $usuario['senha_hash'])) {
            if ($usuario) {
                $tentativas = (int) $usuario['tentativas_login'] + 1;

                if ($tentativas >= MAX_LOGIN_ATTEMPTS) {
                    // Calculado em PHP (não com NOW() do MySQL) — o relógio do
                    // MySQL pode estar em timezone diferente do PHP neste
                    // ambiente, o que quebraria a comparação com time() abaixo.
                    $bloqueadoAte = date('Y-m-d H:i:s', $agora + LOGIN_LOCKOUT_MINUTES * 60);
                    $upd = $pdo->prepare(
                        'UPDATE usuarios SET tentativas_login = 0, bloqueado_ate = ? WHERE id = ?'
                    );
                    $upd->execute([$bloqueadoAte, $usuario['id']]);
                    $erro = 'Conta bloqueada por excesso de tentativas. Tente novamente em '
                          . LOGIN_LOCKOUT_MINUTES . ' minutos.';
                } else {
                    $upd = $pdo->prepare('UPDATE usuarios SET tentativas_login = ? WHERE id = ?');
                    $upd->execute([$tentativas, $usuario['id']]);
                    $restantes = MAX_LOGIN_ATTEMPTS - $tentativas;
                    $erro = "E-mail ou senha inválidos. Restam {$restantes} tentativa(s) antes do bloqueio temporário.";
                }

                AuditLog::record([
                    'usuario_id'    => $usuario['id'],
                    'entidade_tipo' => 'usuario',
                    'entidade_id'   => $usuario['id'],
                    'acao'          => 'nf_usuario.login_falha',
                ]);
            } else {
                $erro = 'E-mail ou senha inválidos.';
            }
        } else {
            // ── Sucesso ──
            $pdo->prepare(
                'UPDATE usuarios
                    SET tentativas_login = 0, bloqueado_ate = NULL, ultimo_acesso = NOW()
                  WHERE id = ?'
            )->execute([$usuario['id']]);

            session_regenerate_id(true);
            $_SESSION['usuario_id']    = $usuario['id'];
            $_SESSION['ultimo_acesso'] = $agora;
            Auth::limparCache();

            AuditLog::record([
                'usuario_id'    => $usuario['id'],
                'entidade_tipo' => 'usuario',
                'entidade_id'   => $usuario['id'],
                'acao'          => 'nf_usuario.login_sucesso',
            ]);

            // Com deep link (next) vai direto ao destino; senão passa pela
            // tela inicial de boas-vindas (Notas), de onde o usuário segue ao sistema.
            $destino = $next !== '' ? $next : APP_PUBLIC_URL . '/index.php?module=home&action=index';
            header('Location: ' . $destino);
            exit;
        }

        include VIEWS . '/auth/login.php';
        exit;

    // ── Encerrar sessão ────────────────────────────────────────
    case 'logout':
        session_unset();
        session_destroy();
        header('Location: ' . APP_PUBLIC_URL . '/index.php?module=auth&action=index&motivo=logout');
        exit;

    // ── Fallback 404 ─────────────────────────────────────
    default:
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        exit;
}
