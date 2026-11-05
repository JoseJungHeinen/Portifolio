<?php
// @versao Ver.001.211026
$title = 'Perfis e Permissões';
include __DIR__ . '/_nav.php';

$perfisInfo = [
    'solicitante' => ['cor' => 'secondary', 'icon' => 'bi-person',       'desc' => 'Cria requisições para o próprio setor.'],
    'almoxarife'  => ['cor' => 'info',      'icon' => 'bi-boxes',        'desc' => 'Gerencia estoque e recebe materiais.'],
    'comprador'   => ['cor' => 'primary',   'icon' => 'bi-cart3',        'desc' => 'Conduz cotações e emite ordens de compra.'],
    'gestor'      => ['cor' => 'success',   'icon' => 'bi-person-check', 'desc' => 'Aprova requisições e monitora orçamentos.'],
    'diretor'     => ['cor' => 'warning',   'icon' => 'bi-star',         'desc' => 'Aprova compras de alto valor e contratos.'],
];
?>

<div class="d-flex align-items-center gap-3 mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-person-badge me-2 text-primary"></i>Perfis e Permissões</h4>
</div>
<p class="text-muted small mb-3">
    Padrão de cada cargo. Todo usuário do cargo herda estas permissões; exceções por pessoa ficam em
    <a href="<?= url('usuarios') ?>">Usuários</a>. Admin tem acesso total e não aparece aqui.
</p>

<ul class="nav nav-pills mb-3 gap-1">
    <?php foreach ($perfis as $p): $info = $perfisInfo[$p]; ?>
    <li class="nav-item">
        <a class="nav-link <?= $p === $perfil ? 'active' : 'border' ?>"
           href="<?= url('configuracoes/perfis?perfil=' . $p) ?>" title="<?= e($info['desc']) ?>">
            <i class="bi <?= $info['icon'] ?> me-1"></i><?= ucfirst($p) ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<form method="POST" action="<?= url('configuracoes/perfis') ?>" style="max-width:820px">
    <?= csrf_field() ?>
    <input type="hidden" name="perfil" value="<?= e($perfil) ?>">

    <div class="alert alert-light border small py-2 mb-3">
        <i class="bi bi-info-circle me-1"></i>
        Editando o cargo <strong><?= ucfirst($perfil) ?></strong> — <?= e($perfisInfo[$perfil]['desc']) ?>
    </div>

    <?php
    $marcadas = $permMarcadas;
    $padrao   = null;
    include ROOT . '/views/partials/permissoes_cartoes.php';
    ?>

    <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-check-lg me-1"></i>Salvar cargo <?= ucfirst($perfil) ?>
        </button>
    </div>
</form>
