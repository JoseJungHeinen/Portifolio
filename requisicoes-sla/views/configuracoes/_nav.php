<?php
// @versao Ver.003.301026
$navItems = [
    ['url' => 'configuracoes/alcadas',   'icon' => 'bi-shield-lock',     'label' => 'Alçadas'],
    ['url' => 'configuracoes/perfis',    'icon' => 'bi-person-badge',    'label' => 'Perfis'],
    ['url' => 'configuracoes/setores',   'icon' => 'bi-diagram-3',       'label' => 'Setores'],
    ['url' => 'configuracoes/grupos',    'icon' => 'bi-tags',            'label' => 'Grupos de Produto'],
    ['url' => 'configuracoes/parametros','icon' => 'bi-sliders',         'label' => 'Parâmetros'],
    ['url' => 'configuracoes/filiais',   'icon' => 'bi-building',        'label' => 'Unidades'],
    ['url' => 'configuracoes/gestores', 'icon' => 'bi-person-gear',    'label' => 'Gestores'],
    ['url' => 'configuracoes/integracoes', 'icon' => 'bi-plug', 'label' => 'Integrações'],
];
// Requisições: só os cadastros de apoio ao fluxo (o resto é 404 lá).
if (sistema() === 'requisicoes') {
    $navItems = array_values(array_filter($navItems, fn($n) => in_array($n['url'], [
        'configuracoes/filiais', 'configuracoes/setores', 'configuracoes/gestores', 'configuracoes/alcadas',
    ], true)));
}
?>
<ul class="nav nav-tabs mb-4">
    <?php foreach ($navItems as $n): ?>
    <li class="nav-item">
        <a class="nav-link <?= str_ends_with(rtrim($_SERVER['REQUEST_URI'],'/'), '/'.ltrim($n['url'],'/')) ? 'active' : '' ?>"
           href="<?= url($n['url']) ?>">
            <i class="bi <?= $n['icon'] ?> me-1"></i><?= $n['label'] ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>