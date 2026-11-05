<?php
// @versao Ver.004.301026
use App\Services\SolicitacaoCompraService as Sol;

$numero        = Sol::numeroFormatado($sol);
$title         = 'Solicitação ' . $numero;
$fotosAbertura = $anexosPorEvento[''] ?? [];
$stBadge        = Sol::STATUS_BADGE[$sol['status']] ?? 'secondary';
$stLabel        = Sol::STATUS_LABEL[$sol['status']] ?? $sol['status'];
$numReq        = $sol['requisicao_id']
    ? formatar_numero_requisicao($sol['requisicao_tipo'], $sol['requisicao_criado_em'], (int)$sol['requisicao_numero'])
    : null;

/** Miniaturas clicáveis das fotos. */
$miniaturas = function (array $fotos): void {
    if (!$fotos) return;
    echo '<div class="d-flex flex-wrap gap-2 mt-2">';
    foreach ($fotos as $f) {
        $src = url('solicitacoes/anexos/' . $f['id']);
        echo '<a href="' . e($src) . '" target="_blank" rel="noopener" title="' . e($f['nome_original']) . '">'
           . '<img src="' . e($src) . '" alt="' . e($f['nome_original']) . '" loading="lazy" '
           . 'style="width:96px;height:96px;object-fit:cover;border-radius:6px;border:1px solid #dee2e6"></a>';
    }
    echo '</div>';
};
?>
<div class="d-flex align-items-center flex-wrap gap-3 mb-4">
    <a href="<?= url('solicitacoes') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><?= e($numero) ?> — <?= e($sol['titulo']) ?></h4>
    <span class="badge bg-<?= $stBadge ?>"><?= e($stLabel) ?></span>
    <?= Sol::seloUrgencia($sol['urgencia'] ?? null) ?>
    <?php $sla = Sol::situacaoSla($sol); ?>
    <?php if ($sla): ?><span class="small ms-2 <?= $sla['classe'] ?>"<?= !empty($sol['sla_vence_em']) ? ' title="Vencimento: ' . e(date('d/m/Y H:i', strtotime($sol['sla_vence_em']))) . '"' : '' ?>><i class="bi bi-stopwatch me-1"></i><?= e($sla['texto']) ?></span><?php endif; ?>
    <?php if (!empty($sol['fora_orcamento'])): ?><span class="badge bg-danger-subtle text-danger-emphasis ms-1">Fora do orçamento</span><?php endif; ?>
    <?php if (!empty($sol['emergencial'])): ?><span class="badge bg-warning-subtle text-warning-emphasis ms-1">Emergencial — regularização</span><?php endif; ?>
    <?php if (($sol['recorrencia'] ?? 'unica') === 'mensal'): ?><span class="badge bg-info-subtle text-info-emphasis ms-1">Contrato mensal</span><?php endif; ?>
</div>

<?php if (!empty($andamento)) { $andamentoCompacto = false; include ROOT . '/views/partials/andamento.php'; } ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <?php if (!empty($itens)): ?>
                <?php $totalEst = 0.0; ?>
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Item</th><th class="text-end">Qtd.</th><th>Un.</th><th class="text-end">Valor unit. est.</th><th class="text-end">Subtotal</th></tr></thead>
                        <tbody>
                        <?php foreach ($itens as $it): ?>
                            <?php $sub = $it['valor_unitario'] !== null ? (float)$it['valor_unitario'] * (float)$it['quantidade'] : null; $totalEst += $sub ?? 0; ?>
                            <tr>
                                <td><?= e($it['descricao']) ?></td>
                                <td class="text-end"><?= e(rtrim(rtrim(number_format((float)$it['quantidade'], 2, ',', '.'), '0'), ',')) ?></td>
                                <td><?= e($it['unidade']) ?></td>
                                <td class="text-end"><?= $it['valor_unitario'] !== null ? money($it['valor_unitario']) : '—' ?></td>
                                <td class="text-end"><?= $sub !== null ? money($sub) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><th colspan="4" class="text-end">Total estimado</th><th class="text-end"><?= money($totalEst) ?></th></tr></tfoot>
                    </table>
                </div>
                <?php if (!empty($sol['descricao'])): ?>
                <h6 class="text-muted text-uppercase small mb-1">Observações</h6>
                <p class="mb-3"><?= nl2br(e($sol['descricao'])) ?></p>
                <?php endif; ?>
                <?php else: ?>
                <p class="mb-3"><?= nl2br(e($sol['descricao'] ?? '')) ?></p>
                <?php endif; ?>
                <h6 class="text-muted text-uppercase small mb-1">Motivo</h6>
                <p class="mb-2"><?= nl2br(e($sol['motivo'])) ?></p>
                <p class="mb-0 small">
                    <?php if (!empty($sol['necessario_ate'])): ?>
                    <span class="me-3"><i class="bi bi-calendar-event me-1"></i>Necessário até <strong><?= e(date('d/m/Y', strtotime($sol['necessario_ate']))) ?></strong></span>
                    <?php endif; ?>
                    <span><i class="bi bi-tag me-1"></i>Finalidade: <strong><?= e(\App\Models\Requisicao::FINALIDADES[$sol['finalidade'] ?? ''] ?? 'a definir pelo comprador') ?></strong></span>
                    <?php if (!empty($sol['fornecedor_sugerido'])): ?>
                    <span class="ms-3"><i class="bi bi-shop me-1"></i>Fornecedor sugerido: <strong><?= e($sol['fornecedor_sugerido']) ?></strong></span>
                    <?php endif; ?>
                </p>
                <?php $miniaturas($fotosAbertura); ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm" id="timeline">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-2"></i>Acompanhamento</div>
            <div class="card-body">
                <?php foreach ($eventos as $ev): ?>
                    <?php
                    $textoStatus = 'mudou para';
                    if (!$ev['status_de']) {
                        $textoStatus = $ev['status_para'] === 'aprovada'
                            ? 'abriu a solicitação (aprovada automaticamente)'
                            : 'abriu a solicitação';
                    }
                    ?>
                    <?php if ($ev['tipo'] === 'status'): ?>
                    <div class="d-flex gap-2 mb-3 small">
                        <i class="bi bi-arrow-right-circle text-muted mt-1"></i>
                        <div>
                            <strong><?= e($ev['usuario_nome']) ?></strong>
                            <span class="text-muted"><?= e($textoStatus) ?></span>
                            <span class="badge bg-<?= Sol::STATUS_BADGE[$ev['status_para']] ?? 'secondary' ?>"><?= e(Sol::STATUS_LABEL[$ev['status_para']] ?? $ev['status_para']) ?></span>
                            <span class="text-muted">· <?= datetime_br($ev['criado_em']) ?></span>
                            <?php if ($ev['mensagem']): ?>
                                <div class="mt-1"><?= nl2br(e($ev['mensagem'])) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php elseif ($ev['tipo'] === 'andamento'): ?>
                    <div class="d-flex gap-2 mb-3 small">
                        <i class="bi bi-truck text-primary mt-1"></i>
                        <div>
                            <span><?= e($ev['mensagem']) ?></span>
                            <span class="text-muted">· <?= e($ev['usuario_nome']) ?> · <?= datetime_br($ev['criado_em']) ?></span>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="border rounded p-3 mb-3 bg-light">
                        <div class="d-flex justify-content-between small mb-1">
                            <strong><?= e($ev['usuario_nome']) ?></strong>
                            <span class="text-muted"><?= datetime_br($ev['criado_em']) ?></span>
                        </div>
                        <?php if ($ev['mensagem']): ?>
                            <div><?= nl2br(e($ev['mensagem'])) ?></div>
                        <?php endif; ?>
                        <?php $miniaturas($anexosPorEvento[$ev['id']] ?? []); ?>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <form method="POST" id="formComentar" action="<?= url('solicitacoes/' . $sol['id'] . '/comentar') ?>" enctype="multipart/form-data" class="border-top pt-3">
                    <?= csrf_field() ?>
                    <textarea name="mensagem" aria-label="Comentário" class="form-control mb-2" rows="3" placeholder="Escreva um comentário..."></textarea>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <input type="file" name="fotos[]" aria-label="Fotos do comentário" class="form-control form-control-sm" style="max-width:320px" multiple accept=".jpg,.jpeg,.png,.webp">
                        <button type="submit" class="btn btn-primary btn-sm ms-auto"><i class="bi bi-chat-left-text me-1"></i>Comentar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4 order-first order-lg-0">
        <?php if ($aguardandoConfirmacao): ?>
        <div class="card border-warning shadow-sm mb-3" id="confirmacao-recebimento">
            <div class="card-header bg-warning-subtle fw-semibold">
                <i class="bi bi-box-seam me-1"></i><?= $modoConfirmacao === 'proprio' ? 'Aguardando sua confirmação de recebimento' : 'Aguardando confirmação de recebimento do solicitante' ?>
            </div>
            <div class="card-body small">
                <p class="mb-2">O produto foi recebido (NF aprovada) em <strong><?= datetime_br($sol['recebido_em']) ?></strong>.
                    <?= $modoConfirmacao === 'proprio' ? 'Confirme quando ele chegar até você — a solicitação é concluída na confirmação.' : 'A solicitação é concluída quando a entrega for confirmada.' ?></p>
                <?php if ($modoConfirmacao === 'proprio'): ?>
                <form method="POST" action="<?= url('solicitacoes/' . $sol['id'] . '/confirmar-recebimento') ?>" class="d-grid"
                      onsubmit="return confirm('Confirmar que você recebeu o produto?')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Recebi</button>
                </form>
                <?php elseif ($modoConfirmacao === 'em_nome'): ?>
                <form method="POST" action="<?= url('solicitacoes/' . $sol['id'] . '/confirmar-recebimento') ?>">
                    <?= csrf_field() ?>
                    <label class="form-label fw-semibold mb-1" for="recebidoPor">Recebido por</label>
                    <input type="text" name="recebido_por" id="recebidoPor" class="form-control form-control-sm mb-2" maxlength="<?= Sol::MAX_RECEBIDO_POR ?>" required
                           placeholder="Nome de quem recebeu no setor">
                    <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-check2-circle me-1"></i>Registrar entrega</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Informações</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted fw-normal">Solicitante</dt><dd class="col-7"><?= e($sol['solicitante_nome']) ?></dd>
                    <?php if (!empty($sol['aberto_por']) && $sol['aberto_por'] !== $sol['solicitante_id']): ?>
                    <dt class="col-5 text-muted fw-normal">Aberta por</dt><dd class="col-7"><?= e($sol['aberto_por_nome'] ?? '—') ?></dd>
                    <?php endif; ?>
                    <dt class="col-5 text-muted fw-normal">Filial</dt><dd class="col-7"><?= e($sol['filial_nome'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted fw-normal">Setor</dt><dd class="col-7"><?= e($sol['setor_nome'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted fw-normal">Aberta em</dt><dd class="col-7"><?= datetime_br($sol['criado_em']) ?></dd>
                    <?php if ($sol['decidido_em']): ?>
                    <dt class="col-5 text-muted fw-normal">Decisão</dt>
                    <dd class="col-7"><?= e($sol['decidido_por_nome'] ?? 'Automática') ?><br><span class="text-muted"><?= datetime_br($sol['decidido_em']) ?></span></dd>
                    <?php endif; ?>
                    <?php if ($numReq): ?>
                    <dt class="col-5 text-muted fw-normal">Requisição</dt>
                    <dd class="col-7">
                        <?php if ($podeVerReq): ?>
                            <a href="<?= url('requisicoes/' . $sol['requisicao_id']) ?>"><?= e($numReq) ?></a>
                        <?php else: ?>
                            <?= e($numReq) ?>
                        <?php endif; ?>
                        <?php if ($sol['comprador_nome']): ?><br><span class="text-muted"><?= e($sol['comprador_nome']) ?></span><?php endif; ?>
                    </dd>
                    <?php endif; ?>
                    <?php if (!empty($sol['entregue_em'])): ?>
                    <dt class="col-5 text-muted fw-normal">Entrega</dt>
                    <dd class="col-7" id="entrega-info">Entregue a <?= e($sol['entregue_a'] ?? '—') ?> em <?= e(date('d/m/Y H:i', strtotime($sol['entregue_em']))) ?>
                        <?php if (!empty($sol['entrega_registrada_por_nome'])): ?><br><span class="text-muted">(registrado por <?= e($sol['entrega_registrada_por_nome']) ?>)</span><?php endif; ?>
                    </dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if ($podeAprovar || $podeGerarReq || $podeConcluir || $podeCancelar || $podeMudarUrgencia): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Ações</div>
            <div class="card-body d-grid gap-2">
                <?php if ($podeAprovar): ?>
                <form method="POST" action="<?= url('solicitacoes/' . $sol['id'] . '/aprovar') ?>" class="d-grid">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Aprovar</button>
                </form>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalRecusar">
                    <i class="bi bi-x-lg me-1"></i>Recusar
                </button>
                <?php endif; ?>

                <?php if ($podeMudarUrgencia): ?>
                <form method="POST" action="<?= url('solicitacoes/' . $sol['id'] . '/urgencia') ?>" class="d-flex gap-2 align-items-center">
                    <?= csrf_field() ?>
                    <select name="urgencia" class="form-select form-select-sm" style="max-width:200px" aria-label="Urgência">
                        <?php foreach (Sol::URGENCIAS as $chave => $u): ?>
                        <option value="<?= e($chave) ?>" <?= ($sol['urgencia'] ?? Sol::URGENCIA_PADRAO) === $chave ? 'selected' : '' ?>><?= e($u['cor'] . ' — ' . $u['rotulo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-secondary btn-sm text-nowrap">Alterar urgência</button>
                </form>
                <?php endif; ?>

                <?php if ($podeGerarReq): ?>
                <a href="<?= url('requisicoes/nova?tipo=ordem_compra&solicitacao=' . $sol['id']) ?>" class="btn btn-primary">
                    <i class="bi bi-file-earmark-text me-1"></i>Gerar ordem de compra
                </a>
                <?php endif; ?>

                <?php if ($podeConcluir): ?>
                <form method="POST" action="<?= url('solicitacoes/' . $sol['id'] . '/concluir') ?>" class="d-grid"
                      onsubmit="return confirm('Marcar a solicitação como concluída?')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-success"><i class="bi bi-flag me-1"></i>Concluir</button>
                </form>
                <?php endif; ?>

                <?php if ($podeCancelar): ?>
                <form method="POST" action="<?= url('solicitacoes/' . $sol['id'] . '/cancelar') ?>" class="d-grid"
                      onsubmit="return confirm('Cancelar esta solicitação?')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-slash-circle me-1"></i>Cancelar solicitação</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($podeAprovar): ?>
<div class="modal fade" id="modalRecusar" tabindex="-1" aria-labelledby="modalRecusarLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= url('solicitacoes/' . $sol['id'] . '/recusar') ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="modalRecusarLabel">Recusar solicitação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold" for="recusaMotivo">Motivo da recusa</label>
                    <textarea name="mensagem" id="recusaMotivo" class="form-control" rows="3" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Voltar</button>
                    <button type="submit" class="btn btn-danger">Recusar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('formComentar').addEventListener('submit', function (ev) {
        const btn = ev.target.querySelector('button[type=submit]');
        btn.disabled = true;
        btn.textContent = 'Enviando…';
    });
});
</script>
