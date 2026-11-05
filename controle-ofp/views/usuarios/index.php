<?php use App\Models\Usuario; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="titulo h3 mb-0">Usuários <span>do sistema</span></h1>
    <a class="btn btn-primary" href="<?= url('/usuarios/novo') ?>"><i class="bi bi-plus-lg"></i> Novo usuário</a>
</div>
<div class="table-responsive bg-white rounded border">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Unidades</th><th>Último acesso</th><th>Situação</th></tr></thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
            <tr class="<?= (int)$u['ativo'] ? '' : 'text-muted' ?>">
                <td><a href="<?= url('/usuarios/' . $u['id'] . '/editar') ?>"><?= e($u['nome']) ?></a></td>
                <td><?= e($u['email']) ?></td>
                <td><?= e(Usuario::PERFIS[$u['perfil']] ?? $u['perfil']) ?></td>
                <td><?= e($u['unidades_nomes'] ?? (in_array($u['perfil'], ['gerente', 'financeiro', 'diretor', 'admin'], true) ? 'Todas' : 'Nenhuma')) ?></td>
                <td><?= datetime_br($u['ultimo_acesso']) ?></td>
                <td><?= (int)$u['ativo'] ? 'Ativo' : 'Inativo' ?><?= $u['bloqueado_ate'] && strtotime($u['bloqueado_ate']) > time() ? ' · <span class="text-danger">bloqueado</span>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
