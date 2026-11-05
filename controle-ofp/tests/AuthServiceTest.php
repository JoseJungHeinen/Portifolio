<?php
declare(strict_types=1);

use App\Services\AuthService;
use App\Support\RegraException;

T::teste('escopo de unidades por perfil', function () {
    T::igual(['u1'], AuthService::escopoUnidades('estoque', ['u1']));
    T::igual([], AuthService::escopoUnidades('estoque', []));
    T::igual(null, AuthService::escopoUnidades('gerente', []));
    T::igual(['u2'], AuthService::escopoUnidades('gerente', ['u2']));
    T::igual(null, AuthService::escopoUnidades('diretor', []));
});

fx_limpar();

T::teste('login grava sessão e 5 erros bloqueiam', function () {
    $u = fx_usuario('comprador', [fx_unidade()]);
    $email = fx_db()->query("SELECT email FROM usuarios WHERE id = '{$u['id']}'")->fetchColumn();
    $svc = new AuthService();
    T::igual('comprador', $svc->login($email, 'Teste@123')['perfil']);
    T::igual([fx_unidade()], $_SESSION['unidades']);
    for ($i = 0; $i < 5; $i++) {
        try {
            $svc->login($email, 'senha-errada');
        } catch (RegraException) {
        }
    }
    T::lanca(RegraException::class, fn() => $svc->login($email, 'Teste@123'));
});

T::teste('revalidarSessao aplica mudanças de perfil, unidade e inativação', function () {
    fx_limpar();
    $u1 = fx_unidade();
    $u = fx_usuario('estoque', [$u1]);
    $_SESSION = ['usuario' => $u, 'unidades' => [$u1]];
    $svc = new AuthService();
    T::verdadeiro($svc->revalidarSessao());
    T::igual([$u1], $_SESSION['unidades']);

    fx_db()->exec("UPDATE usuarios SET perfil = 'gerente' WHERE id = '{$u['id']}'");
    fx_db()->exec("DELETE FROM usuario_unidades WHERE usuario_id = '{$u['id']}'");
    T::verdadeiro($svc->revalidarSessao());
    T::igual('gerente', $_SESSION['usuario']['perfil']);
    T::igual(null, $_SESSION['unidades']);

    fx_db()->exec("UPDATE usuarios SET ativo = 0 WHERE id = '{$u['id']}'");
    T::verdadeiro(!$svc->revalidarSessao());
    T::verdadeiro(!isset($_SESSION['usuario']));
    $_SESSION = [];
});
