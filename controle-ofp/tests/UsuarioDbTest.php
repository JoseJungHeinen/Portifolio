<?php
declare(strict_types=1);

use App\Models\Usuario;

fx_limpar();
$u = fx_usuario('comprador');

T::teste('5 erros bloqueiam e, expirado o bloqueio, a contagem recomeça', function () use ($u) {
    $m = new Usuario();
    for ($i = 0; $i < 5; $i++) {
        $m->incrementarTentativas($u['id']);
    }
    $r = $m->find($u['id']);
    T::igual(5, (int)$r['tentativas_login']);
    T::igual(true, $r['bloqueado_ate'] !== null);
    fx_db()->exec("UPDATE usuarios SET bloqueado_ate = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = '{$u['id']}'");
    $m->incrementarTentativas($u['id']);
    $r = $m->find($u['id']);
    T::igual(1, (int)$r['tentativas_login']);
    T::igual(null, $r['bloqueado_ate']);
});

T::teste('listar usuários funciona com ONLY_FULL_GROUP_BY e junta as unidades', function () {
    fx_limpar();
    fx_usuario('estoque', [fx_unidade('Eurovale - Matriz'), fx_unidade('Eurovale - Jeep')]);
    fx_usuario('diretor');
    $lista = (new App\Models\Usuario())->listar();
    T::igual(2, count($lista));
    $estoque = array_values(array_filter($lista, fn($u) => $u['perfil'] === 'estoque'))[0];
    T::igual('Eurovale - Jeep, Eurovale - Matriz', $estoque['unidades_nomes']);
});
