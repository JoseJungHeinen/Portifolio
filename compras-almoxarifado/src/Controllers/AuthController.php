<?php
// @versao Ver.002.281026

namespace App\Controllers;

use App\Core\Controller;
use App\Services\AuthService;

class AuthController extends Controller
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function showLogin(): void
    {
        if (auth()) redirect('/dashboard');
        $this->view('auth/login', ['title' => 'Login'], 'auth');
    }

    public function doLogin(): void
    {
        $email = trim($this->input('email', ''));
        $senha = $this->input('senha', '');

        $limite = new \App\Services\LoginLimiteService();
        if ($limite->bloqueado('login')) {
            session_flash('error', 'Muitas tentativas de login a partir deste computador. Aguarde '
                . \App\Services\LoginLimiteService::minutosJanela() . ' minutos e tente de novo.');
            redirect('/login');
        }

        try {
            $user = $this->auth->login($email, $senha);

            if ((int)($user['force_change_senha'] ?? 0)) {
                // Só um sinal com horário — nunca a senha (antes ia para a sessão em
                // texto puro e voltava no value do campo "senha atual").
                $_SESSION['_troca_obrigatoria'] = time();
                session_flash('warning', 'Por segurança, você precisa alterar sua senha no primeiro acesso.');
                redirect('/senha/alterar');
            }

            // Compras: sempre o Dashboard (ele mesmo desvia o solicitante). Requisições não
            // tem Dashboard: cada perfil entra na sua etapa do fluxo.
            redirect(sistema() === 'requisicoes' ? rota_inicial(auth()['perfil'] ?? '') : '/dashboard');
        } catch (\RuntimeException $e) {
            $limite->registrar('login', $email);
            session_flash('error', $e->getMessage());
            redirect('/login');
        }
    }

    public function logout(): void
    {
        $this->auth->logout();
        redirect('/login');
    }

    public function showReset(): void
    {
        $this->view('auth/reset', ['title' => 'Recuperar Senha'], 'auth');
    }

    public function doReset(): void
    {
        $email = trim($this->input('email', ''));
        // Cada pedido conta: o reset dispara e-mail, então o limite é baixo (5 em 15 min).
        // Bloqueado ou não, a resposta é a mesma — não revela nada a quem testa e-mails.
        $limite = new \App\Services\LoginLimiteService();
        if (!$limite->bloqueado('reset')) {
            $limite->registrar('reset', $email);
            $this->auth->solicitarReset($email);
        }
        session_flash('success', 'Se o e-mail existir, um link de recuperação será enviado em breve.');
        redirect('/login');
    }

    public function showNovaSenha(string $token): void
    {
        $this->view('auth/nova_senha', ['title' => 'Nova Senha', 'token' => $token], 'auth');
    }

    public function doNovaSenha(string $token): void
    {
        $nova    = $this->input('nova_senha', '');
        $confirm = $this->input('confirmar_senha', '');

        if ($nova !== $confirm) {
            session_flash('error', 'As senhas não conferem.');
            redirect('/senha/nova/' . $token);
        }

        try {
            $this->auth->resetarSenha($token, $nova);
            session_flash('success', 'Senha alterada com sucesso. Faça login.');
            redirect('/login');
        } catch (\RuntimeException $e) {
            session_flash('error', $e->getMessage());
            redirect('/senha/nova/' . $token);
        }
    }

    public function showAlterar(): void
    {
        $this->requireAuth();
        $this->view('auth/alterar_senha', [
            'title'            => 'Alterar Senha',
            'trocaObrigatoria' => $this->trocaObrigatoriaValida(),
        ]);
    }

    /**
     * Primeiro acesso: a pessoa acabou de digitar a senha temporária para entrar,
     * então a troca não pede a "senha atual" — desde que seja logo após o login.
     */
    private function trocaObrigatoriaValida(): bool
    {
        $desde = (int)($_SESSION['_troca_obrigatoria'] ?? 0);
        return $desde > 0 && (time() - $desde) < 900 && (int)(auth()['force_change_senha'] ?? 0) === 1;
    }

    public function doAlterar(): void
    {
        $this->requireAuth();
        $user    = auth();
        $atual   = $this->input('senha_atual', '');
        $nova    = $this->input('nova_senha', '');
        $confirm = $this->input('confirmar_senha', '');

        if ($nova !== $confirm) {
            session_flash('error', 'As senhas não conferem.');
            redirect('/senha/alterar');
        }

        try {
            if ($this->trocaObrigatoriaValida()) {
                $this->auth->definirNovaSenha($user['id'], $nova);
                unset($_SESSION['_troca_obrigatoria']);
                $_SESSION['usuario']['force_change_senha'] = 0;
            } else {
                $this->auth->alterarSenha($user['id'], $atual, $nova);
            }
            session_flash('success', 'Senha alterada com sucesso.');
            // Compras: sempre o Dashboard (ele mesmo desvia o solicitante). Requisições não
            // tem Dashboard: cada perfil entra na sua etapa do fluxo.
            redirect(sistema() === 'requisicoes' ? rota_inicial(auth()['perfil'] ?? '') : '/dashboard');
        } catch (\RuntimeException $e) {
            session_flash('error', $e->getMessage());
            redirect('/senha/alterar');
        }
    }
}
