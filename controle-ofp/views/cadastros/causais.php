<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="titulo h3 mb-0">Causais <span>do manual</span></h1>
    <a class="btn btn-primary" href="<?= url('/cadastros/causais/novo') ?>"><i class="bi bi-plus-lg"></i> Novo causal</a>
</div>
<div class="table-responsive bg-white rounded border">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Código</th><th>Descrição</th><th>Devolução</th><th>Prazo</th><th>Mínimo</th><th>Frete</th><th>TP41</th><th>Transp.</th><th>Situação</th></tr></thead>
        <tbody>
        <?php foreach ($causais as $c): ?>
            <tr class="<?= (int)$c['ativo'] ? '' : 'text-muted' ?>">
                <td><a href="<?= url('/cadastros/causais/' . $c['id']) ?>"><strong><?= e($c['codigo']) ?></strong></a></td>
                <td><?= e($c['descricao']) ?></td>
                <td><?= $c['tipo_devolucao'] === 'fisica' ? 'Física' : 'Simbólica' ?></td>
                <td><?= $c['prazo_abertura_dias'] === null ? 'sem prazo' : 'D+' . (int)$c['prazo_abertura_dias'] ?></td>
                <td><?= $c['valor_minimo'] === null ? '—' : money($c['valor_minimo']) ?></td>
                <td><?= $c['frete_por'] === 'dealer' ? 'Dealer' : 'Fabricante' ?></td>
                <td><?= (int)$c['exige_tp41'] ? 'Sim' : '—' ?></td>
                <td><?= $c['transportadora'] ? e(strtoupper($c['transportadora'])) : '—' ?></td>
                <td><?= (int)$c['ativo'] ? 'Ativo' : ((int)$c['legado'] ? 'Legado' : 'Inativo') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
