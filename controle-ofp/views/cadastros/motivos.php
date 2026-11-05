<?php use App\Services\StatusService; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="titulo h3 mb-0">Motivos <span>de perda e rejeição</span></h1>
    <a class="btn btn-primary" href="<?= url('/cadastros/motivos/novo') ?>"><i class="bi bi-plus-lg"></i> Novo motivo</a>
</div>
<div class="table-responsive bg-white rounded border">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Código</th><th>Descrição</th><th>Usado em</th><th>Exige observação</th><th>Situação</th></tr></thead>
        <tbody>
        <?php foreach ($motivos as $m): ?>
            <tr class="<?= (int)$m['ativo'] ? '' : 'text-muted' ?>">
                <td><a href="<?= url('/cadastros/motivos/' . $m['id']) ?>"><strong><?= e($m['codigo']) ?></strong></a></td>
                <td><?= e($m['descricao']) ?></td>
                <td class="small"><?= e(implode(', ', array_map(fn($s) => StatusService::LABELS[trim($s)] ?? $s, explode(',', (string)$m['aplica_a'])))) ?></td>
                <td><?= (int)$m['exige_observacao'] ? 'Sim' : '—' ?></td>
                <td><?= (int)$m['ativo'] ? 'Ativo' : 'Inativo' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
