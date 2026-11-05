<?php

namespace App\Middleware;

class AuthMiddleware
{
    public function handle(array $params = []): void
    {
        if (!auth()) {
            session_flash('error', 'Faça login para continuar.');
            redirect('/login');
        }

        // Verifica se está bloqueado
        $user = auth();
        if (isset($user['bloqueado_ate']) && $user['bloqueado_ate']) {
            $bloqueado = new \DateTime($user['bloqueado_ate']);
            if (new \DateTime() < $bloqueado) {
                session_destroy();
                session_flash('error', 'Conta bloqueada temporariamente. Tente mais tarde.');
                redirect('/login');
            }
        }
    }
}
