<?php
// @versao Ver.002.281026

namespace App\Services;

use App\Models\Usuario;
use RuntimeException;

class AuthService
{
    private Usuario $model;

    public function __construct()
    {
        $this->model = new Usuario();
    }

    public function login(string $email, string $senha): array
    {
        // Modo de testes: aceita login por e-mail OU nome do usuário.
        $user = $this->model->findByEmailOrNome($email);

        if (!$user) {
            throw new RuntimeException('E-mail ou senha inválidos.');
        }

        // Verifica bloqueio temporário
        if ($user['bloqueado_ate']) {
            $bloqueado = new \DateTime($user['bloqueado_ate']);
            if (new \DateTime() < $bloqueado) {
                $minutos = (int)ceil((new \DateTime())->diff($bloqueado)->i);
                throw new RuntimeException(
                    "Conta bloqueada por tentativas excessivas. Tente novamente em {$minutos} minuto(s)."
                );
            }
        }

        if (!password_verify($senha, $user['senha_hash'])) {
            $this->model->incrementarTentativas($user['id']);
            throw new RuntimeException('E-mail ou senha inválidos.');
        }

        // Só depois da senha (auditoria B3): antes, "conta desativada" confirmava
        // a quem testava e-mails que a conta existe.
        if (!(int)$user['ativo']) {
            throw new RuntimeException('Conta desativada. Entre em contato com o administrador.');
        }

        // Rehash automático se cost mudou
        $cost = (int)($_ENV['BCRYPT_COST'] ?? 12);
        if (password_needs_rehash($user['senha_hash'], PASSWORD_BCRYPT, ['cost' => $cost])) {
            $this->model->alterarSenha($user['id'], $senha);
        }

        $this->model->registrarAcesso($user['id']);

        // Remove dados sensíveis da sessão
        unset($user['senha_hash'], $user['token_reset_senha'], $user['token_reset_expiry']);

        // Previne session fixation: regenera o ID na mudança de privilégio
        // (anônimo -> autenticado). true descarta o arquivo de sessão antigo,
        // invalidando qualquer ID que um atacante tenha fixado antes do login.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['usuario'] = $user;

        (new AuditoriaService())->log(
            $user['id'], 'usuario', $user['id'], 'login', null, null,
            $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        return $user;
    }

    public function logout(): void
    {
        $user = auth();
        if ($user) {
            (new AuditoriaService())->log(
                $user['id'], 'usuario', $user['id'], 'logout'
            );
        }
        $_SESSION = [];
        session_destroy();
    }

    public function criar(array $dados, string $criadoPor): string
    {
        // Verifica e-mail único
        $existe = $this->model->findByEmail($dados['email']);
        if ($existe) {
            throw new RuntimeException('Este e-mail já está cadastrado.');
        }

        $cost = (int)($_ENV['BCRYPT_COST'] ?? 12);
        $id   = $this->model->create([
            'nome'               => $dados['nome'],
            'email'              => $dados['email'],
            'senha_hash'         => password_hash($dados['senha'], PASSWORD_BCRYPT, ['cost' => $cost]),
            'perfil'             => $dados['perfil'],
            'filial_id'          => $dados['filial_id'],
            'setor_id'           => $dados['setor_id'],
            'ativo'              => 1,
            'force_change_senha' => 1,
            'criado_por'         => $criadoPor,
        ]);

        (new AuditoriaService())->log(
            $criadoPor, 'usuario', $id, 'usuario.criado', null, ['nome' => $dados['nome'], 'perfil' => $dados['perfil']]
        );

        return $id;
    }

    public function solicitarReset(string $email): void
    {
        $user = $this->model->findByEmail($email);
        if (!$user || !(int)$user['ativo']) return; // mesma resposta na tela: não revela se o e-mail existe

        $token = $this->model->gerarTokenReset($email);
        if (!$token) return;

        // O token só vai no e-mail — nunca para o log.
        $link = url('senha/nova/' . $token);
        $html = '<p>Olá, ' . e($user['nome']) . '.</p>'
              . '<p>Recebemos um pedido para redefinir sua senha no Exemplo Compras. '
              . 'Para criar uma nova senha, acesse o link abaixo (válido por 1 hora):</p>'
              . '<p><a href="' . e($link) . '">' . e($link) . '</a></p>'
              . '<p>Se você não pediu, ignore este e-mail — sua senha atual continua valendo.</p>';
        try {
            if (!(new MailService())->enviar($user['email'], $user['nome'], 'Redefinição de senha — Exemplo Compras', $html)) {
                error_log("[AuthService] E-mail de redefinição não enviado para {$email}.");
            }
        } catch (\Throwable $e) {
            error_log('[AuthService] Falha ao enviar redefinição de senha: ' . $e->getMessage());
        }
    }

    public function resetarSenha(string $token, string $novaSenha): void
    {
        $user = $this->model->findByToken($token);
        if (!$user) {
            throw new RuntimeException('Token inválido ou expirado.');
        }

        $this->validarForcaSenha($novaSenha);
        $this->model->alterarSenha($user['id'], $novaSenha);

        (new AuditoriaService())->log(
            $user['id'], 'usuario', $user['id'], 'senha.resetada'
        );
    }

    public function alterarSenha(string $userId, string $senhaAtual, string $novaSenha): void
    {
        $user = $this->model->find($userId);
        if (!$user || !password_verify($senhaAtual, $user['senha_hash'])) {
            throw new RuntimeException('Senha atual incorreta.');
        }

        $this->validarForcaSenha($novaSenha);
        $this->model->alterarSenha($userId, $novaSenha);

        (new AuditoriaService())->log(
            $userId, 'usuario', $userId, 'senha.alterada'
        );
    }

    /** Troca obrigatória do primeiro acesso: a senha temporária foi validada no login. */
    public function definirNovaSenha(string $userId, string $novaSenha): void
    {
        $this->validarForcaSenha($novaSenha);
        $this->model->alterarSenha($userId, $novaSenha);

        (new AuditoriaService())->log(
            $userId, 'usuario', $userId, 'senha.definida_primeiro_acesso'
        );
    }

    private function validarForcaSenha(string $senha): void
    {
        if (strlen($senha) < 8) {
            throw new RuntimeException('A senha deve ter no mínimo 8 caracteres.');
        }
        if (!preg_match('/[A-Z]/', $senha)) {
            throw new RuntimeException('A senha deve conter pelo menos uma letra maiúscula.');
        }
        if (!preg_match('/[0-9]/', $senha)) {
            throw new RuntimeException('A senha deve conter pelo menos um número.');
        }
    }
}
