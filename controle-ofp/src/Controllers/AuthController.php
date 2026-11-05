<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Services\AuthService;
use App\Support\RegraException;

class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (auth()) {
            redirect(rota_inicial(auth()['perfil']));
        }
        $this->view('auth/login', ['title' => 'Entrar'], 'auth');
    }

    public function doLogin(): void
    {
        try {
            $usuario = (new AuthService())->login((string)$this->input('email', ''), (string)$this->input('senha', ''));
        } catch (RegraException $e) {
            session_flash('error', $e->getMessage());
            redirect('/login');
        }
        redirect(rota_inicial($usuario['perfil']));
    }

    public function logout(): void
    {
        (new AuthService())->logout();
        redirect('/login');
    }
}
