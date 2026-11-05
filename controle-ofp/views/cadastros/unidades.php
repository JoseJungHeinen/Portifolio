<h1 class="titulo h3 mb-3">Unidades <span>Eurovale</span></h1>
<div class="table-responsive bg-white rounded border" style="max-width: 760px">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Nome</th><th>Código dealer</th><th>UF</th><th>Situação</th></tr></thead>
        <tbody>
        <?php foreach ($unidades as $u): ?>
            <tr><td><a href="<?= url('/cadastros/unidades/' . $u['id']) ?>"><?= e($u['nome']) ?></a></td><td><?= e($u['codigo_dealer'] ?? '—') ?></td><td><?= e($u['uf']) ?></td><td><?= (int)$u['ativo'] ? 'Ativa' : 'Inativa' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
