<?php
// @versao Ver.004.281026
?>
<?php $title = 'Aprovações Pendentes'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-check2-square me-2 text-success"></i>Aprovações Pendentes</h4>
    <span class="badge bg-warning text-dark fs-6"><?= count($pendentes) ?> pendente(s)</span>
</div>

<?php if (empty($pendentes)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-check-circle-fill text-success fs-1 d-block mb-3"></i>
            <h5 class="text-muted">Tudo em dia!</h5>
            <p class="text-muted mb-0">Não há aprovações pendentes no momento.</p>
        </div>
    </div>
<?php else: ?>

<?php
// Prepara os dados de cada aprovação como JSON seguro (usado pelo JS do modal)
$dadosJson = [];
foreach ($pendentes as $p) {
    $req   = $p['req_detalhes'] ?? [];
    $itens = $p['req_itens']    ?? [];

    $itensArr  = [];
    $totalCalc = 0.0;
    foreach ($itens as $it) {
        $vu = (float)($it['valor_unit_resolvido'] ?? 0);
        $qtd = (float)($it['quantidade'] ?? 0);
        $totalCalc += $vu * $qtd;
        $itensArr[] = [
            'nome'       => $it['item_nome'] ?? ($it['descricao_livre'] ?: '—'),
            'qtd'        => number_format($qtd, 2, ',', '.'),
            'unidade'    => $it['unidade']     ?? '',
            'valor_unit' => money($vu),
            'subtotal'   => money($qtd * $vu),
            'grupo'      => $it['grupo_nome'] ?? '',
            'obs'        => $it['observacao']  ?? '',
        ];
    }
    $gruposItens = array_values(array_unique(array_filter(array_column($itensArr, 'grupo'))));
    $valorExibirJson = (float)($p['valor'] ?? 0) > 0 ? (float)$p['valor'] : $totalCalc;

    $reqNumFormatado = isset($req['criado_em'], $req['numero'])
        ? formatar_numero_requisicao($req['tipo'] ?? 'compra', $req['criado_em'], (int)$req['numero'])
        : '—';

    $dadosJson[$p['id']] = [
        'aprov_id'    => $p['id'],
        'nivel'       => (int)$p['nivel'],
        'numero'      => $reqNumFormatado,
        'solicitante' => $req['solicitante_nome'] ?? '—',
        'email'       => $req['solicitante_email'] ?? '',
        'filial'      => $req['filial_nome']  ?? '—',
        'setor'       => $req['setor_nome']   ?? '—',
        'grupo'       => $gruposItens ? implode(', ', $gruposItens) : ($req['grupo_nome'] ?? '—'),
        'tipo_despesa'=> $req['tipo_despesa'] ?? '—',
        'valor'       => money($valorExibirJson),
        'justificativa'=> $req['justificativa'] ?? '',
        'criado_em'   => isset($req['criado_em']) ? date('d/m/Y H:i', strtotime($req['criado_em'])) : '—',
        'itens'       => $itensArr,
    ];
}
?>

<div class="row g-3">
<?php foreach ($pendentes as $p): ?>
<?php if (($p['entidade_tipo'] ?? '') === 'requisicao_brinde'):
    $bd = $p['brinde_detalhes'] ?? [];
    $bi = $p['brinde_itens']    ?? [];
?>
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #198754 !important;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <span class="badge bg-warning text-dark me-1">Nível <?= (int)$p['nivel'] ?></span>
                        <span class="badge bg-success"><i class="bi bi-gift me-1"></i>Brinde</span>
                    </div>
                    <small class="text-muted"><?= isset($p['criado_em']) ? date('d/m/Y H:i', strtotime($p['criado_em'])) : '—' ?></small>
                </div>

                <div class="mb-2">
                    <div class="fw-semibold lh-1"><?= e($bd['solicitante_nome'] ?? '—') ?></div>
                    <small class="text-muted"><?= e($bd['filial_nome'] ?? '—') ?></small>
                </div>

                <div class="mb-2">
                    <small class="text-muted d-block">Cliente</small>
                    <span class="fw-semibold"><?= e($bd['cliente_nome'] ?? '—') ?></span>
                </div>

                <div class="mb-2">
                    <small class="text-muted d-block">Vendedor</small>
                    <span class="fw-semibold"><?= e($bd['vendedor_nome'] ?? '—') ?></span>
                </div>

                <?php if (!empty($bd['numero_pedido_carro'])): ?>
                <div class="mb-2">
                    <small class="text-muted d-block">Pedido</small>
                    <span class="fw-semibold"><?= e($bd['numero_pedido_carro']) ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($bd['autorizacao_anexo'])): ?>
                <div class="mb-2">
                    <a href="<?= e(\App\Controllers\AnexoController::url($bd['autorizacao_anexo'])) ?>" target="_blank" class="btn btn-outline-primary btn-sm w-100">
                        <i class="bi bi-paperclip me-1"></i>Ver Autorização do Gerente
                    </a>
                </div>
                <?php endif; ?>

                <div class="mb-3">
                    <span class="fs-5 fw-bold text-success"><?= money((float)($bd['valor_total'] ?? 0)) ?></span>
                </div>

                <?php if (!empty($bi)): ?>
                <div class="border-top pt-2">
                    <small class="text-muted fw-semibold"><?= count($bi) ?> ite<?= count($bi) > 1 ? 'ns' : 'm' ?>:</small>
                    <ul class="mb-0 ps-3 mt-1" style="font-size:.8rem">
                        <?php foreach ($bi as $it): ?>
                        <li class="text-truncate" style="max-width:240px">
                            <?= e($it['brinde_nome'] ?? '—') ?>
                            <span class="text-muted">(<?= number_format((float)$it['quantidade'], 0, ',', '.') ?>)</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if (!empty($bd['observacao'])): ?>
                <div class="small text-muted mt-2"><i class="bi bi-chat-left-text me-1"></i><?= e($bd['observacao']) ?></div>
                <?php endif; ?>

                <div class="d-flex gap-2 mt-3 border-top pt-3">
                    <form method="POST" action="<?= url('aprovacoes/' . e($p['id']) . '/aprovar') ?>" class="flex-grow-1">
                        <?= csrf_field() ?>
                        <button class="btn btn-success btn-sm w-100"
                                onclick="return confirm('Aprovar a saída e dar baixa no estoque da filial?')">
                            <i class="bi bi-check-lg me-1"></i>Aprovar
                        </button>
                    </form>
                    <form method="POST" action="<?= url('aprovacoes/' . e($p['id']) . '/rejeitar') ?>" class="flex-grow-1">
                        <?= csrf_field() ?>
                        <button class="btn btn-outline-danger btn-sm w-100"
                                onclick="return confirm('Rejeitar esta requisição de brinde?')">
                            <i class="bi bi-x-lg me-1"></i>Rejeitar
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php continue; endif; ?>
<?php
    $req  = $p['req_detalhes'] ?? [];
    $itens= $p['req_itens']    ?? [];
    $tipo_badge = match($req['tipo_despesa'] ?? '') {
        'recorrente' => 'bg-primary',
        'eventual'   => 'bg-warning text-dark',
        default      => 'bg-secondary',
    };
    $iniciais = strtoupper(mb_substr($req['solicitante_nome'] ?? '?', 0, 2));
    $valorEstimado = array_sum(array_map(function($it) {
        $vu = (float)($it['valor_unit_resolvido'] ?? 0);
        return $vu * (float)($it['quantidade'] ?? 0);
    }, $itens));
    $valorCard = (float)($p['valor'] ?? 0) > 0 ? (float)$p['valor'] : $valorEstimado;
?>
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100 aprovacao-card"
             style="cursor:pointer; border-left:4px solid #ffc107 !important;"
             data-bs-toggle="modal"
             data-bs-target="#modalDetalhe"
             data-aprov-id="<?= e($p['id']) ?>">

            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <span class="badge bg-warning text-dark me-1">Nível <?= (int)$p['nivel'] ?></span>
                        <span class="badge <?= $tipo_badge ?>"><?= ucfirst($req['tipo_despesa'] ?? '—') ?></span>
                    </div>
                    <small class="text-muted"><?= isset($p['criado_em']) ? date('d/m/Y H:i', strtotime($p['criado_em'])) : '—' ?></small>
                </div>

                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:38px;height:38px;font-size:.85rem">
                        <?= e($iniciais) ?>
                    </div>
                    <div>
                        <div class="fw-semibold lh-1"><?= e($req['solicitante_nome'] ?? '—') ?></div>
                        <small class="text-muted"><?= e($req['setor_nome'] ?? '—') ?> · <?= e($req['filial_nome'] ?? '—') ?></small>
                    </div>
                </div>

                <div class="mb-3">
                    <small class="text-muted d-block">
                        <?php
                        $gruposCard = array_values(array_unique(array_filter(array_map(fn($it) => $it['grupo_nome'] ?? '', $itens))));
                        echo e($gruposCard ? implode(', ', $gruposCard) : ($req['grupo_nome'] ?? '—'));
                        ?>
                    </small>
                    <span class="fs-4 fw-bold text-primary"><?= money($valorCard) ?></span>
                </div>

                <?php if (!empty($itens)): ?>
                <div class="border-top pt-2">
                    <small class="text-muted fw-semibold"><?= count($itens) ?> ite<?= count($itens) > 1 ? 'ns' : 'm' ?>:</small>
                    <ul class="mb-0 ps-3 mt-1" style="font-size:.8rem">
                        <?php foreach (array_slice($itens, 0, 3) as $it): ?>
                        <li class="text-truncate" style="max-width:240px">
                            <?= e($it['item_nome'] ?? $it['descricao_livre'] ?? '—') ?>
                            <span class="text-muted">(<?= number_format((float)$it['quantidade'], 0, ',', '.') ?> <?= e($it['unidade']) ?>)</span>
                        </li>
                        <?php endforeach; ?>
                        <?php if (count($itens) > 3): ?>
                        <li class="text-muted">+ <?= count($itens) - 3 ?> mais...</li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php
                $cotacoesAprov = ($req['tipo'] ?? '') === 'ordem_compra'
                    ? (new \App\Models\RequisicaoCotacao())->daRequisicao($req['id'])
                    : [];
                ?>
                <?php if ($cotacoesAprov): ?>
                <div class="mt-2">
                    <div class="small fw-semibold mb-1">Cotações</div>
                    <table class="table table-sm mb-0">
                        <tbody>
                        <?php foreach ($cotacoesAprov as $c): ?>
                            <tr class="<?= (int)$c['vencedora'] === 1 ? 'table-success' : '' ?>">
                                <td class="small"><?= e($c['fornecedor']) ?><?= (int)$c['vencedora'] === 1 ? ' <span class="badge bg-success">escolhida</span>' : '' ?></td>
                                <td class="small text-end"><?= money((float)$c['valor_total'] + (float)$c['frete']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mt-3">
                    <a href="<?= url('requisicoes/' . e($p['entidade_id']) . '/editar') ?>"
                       class="btn btn-outline-secondary btn-sm"
                       onclick="event.stopPropagation()"
                       title="Editar requisição">
                        <i class="bi bi-pencil me-1"></i>Editar
                    </a>
                    <small class="text-muted"><i class="bi bi-eye me-1"></i>Clique para ver e aprovar</small>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- JSON de dados centralizado — gerado com json_encode seguro -->
<script id="dados-aprovacoes" type="application/json">
<?= json_encode($dadosJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>

<!-- ═══════════════════ MODAL DETALHE ═══════════════════ -->
<div class="modal fade" id="modalDetalhe" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <div>
                    <h6 class="modal-title fw-bold mb-0">
                        <i class="bi bi-file-text me-2 text-primary"></i>Detalhes da Requisição
                    </h6>
                    <small class="text-muted" id="dSubtitle"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">

                <div class="p-4 border-bottom">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <small class="text-muted d-block">Solicitante</small>
                            <strong id="dSolicitante"></strong>
                            <div class="text-muted small" id="dEmail"></div>
                        </div>
                        <div class="col-sm-6">
                            <small class="text-muted d-block">Filial / Setor</small>
                            <strong id="dFilial"></strong>
                            <div class="text-muted small" id="dSetor"></div>
                        </div>
                        <div class="col-sm-4">
                            <small class="text-muted d-block">Grupos de Produto</small>
                            <strong id="dGrupo"></strong>
                        </div>
                        <div class="col-sm-4">
                            <small class="text-muted d-block">Tipo de Despesa</small>
                            <span class="badge" id="dTipoBadge"></span>
                        </div>
                        <div class="col-sm-4">
                            <small class="text-muted d-block">Data da Requisição</small>
                            <strong id="dData"></strong>
                        </div>
                        <div class="col-12" id="dJustBox">
                            <small class="text-muted d-block">Justificativa</small>
                            <div class="bg-light rounded p-2 small" id="dJust"></div>
                        </div>
                    </div>
                </div>

                <div class="p-4">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-list-ul me-2"></i>Itens Solicitados</h6>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Item</th>
                                    <th class="text-end">Qtd</th>
                                    <th class="text-end">Valor Unit.</th>
                                    <th class="text-end">Subtotal</th>
                                    <th>Obs.</th>
                                </tr>
                            </thead>
                            <tbody id="dItens"></tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td colspan="3" class="text-end">Total</td>
                                    <td class="text-end text-primary" id="dTotal"></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light justify-content-between">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="imprimirRequisicao()">
                        <i class="bi bi-printer me-1"></i>Imprimir
                    </button>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-danger" id="btnRejeitarModal">
                        <i class="bi bi-x-lg me-1"></i>Rejeitar
                    </button>
                    <button type="button" class="btn btn-success" id="btnAprovarModal">
                        <i class="bi bi-check-lg me-1"></i>Aprovar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Confirmar Aprovação -->
<div class="modal fade" id="modalAprovar" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="formAprovar">
            <?= csrf_field() ?>
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="bi bi-check-circle text-success me-2"></i>Confirmar Aprovação</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Confirma a aprovação desta requisição?</p>
                    <label class="form-label fw-semibold">Observação (opcional)</label>
                    <textarea name="observacao" class="form-control" rows="2" placeholder="Observação..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Aprovar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal Rejeitar -->
<div class="modal fade" id="modalRejeitar" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="formRejeitar">
            <?= csrf_field() ?>
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h6 class="modal-title fw-bold text-danger"><i class="bi bi-x-circle me-2"></i>Rejeitar Requisição</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold">Motivo da rejeição *</label>
                    <textarea name="observacao" class="form-control" rows="3" required placeholder="Informe o motivo..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-x-lg me-1"></i>Rejeitar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const todos = JSON.parse(document.getElementById('dados-aprovacoes').textContent);
    let aprovId = null;

    window.imprimirRequisicao = imprimirRequisicao;
    function imprimirRequisicao() {
        if (!aprovId || !todos[aprovId]) return;
        const d = todos[aprovId];

        let itensHtml = '';
        if (d.itens && d.itens.length > 0) {
            d.itens.forEach(function(it) {
                const obs = it.obs || '—';
                itensHtml += `<tr>
                    <td>${it.nome}</td>
                    <td class="center">${it.qtd} ${it.unidade}</td>
                    <td class="center">${it.valor_unit}</td>
                    <td class="center">${it.subtotal}</td>
                    <td class="center">${obs}</td>
                </tr>`;
            });
        }

        const just = d.justificativa
            ? `<div class="just-box"><strong>Justificativa:</strong> ${d.justificativa}</div>` : '';

        const html = `<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Requisição — ${d.solicitante}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; font-size: 12px; color: #222; padding: 20px 30px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #222; padding-bottom: 10px; margin-bottom: 16px; }
  .header h1 { font-size: 18px; font-weight: bold; }
  .header .sub { font-size: 11px; color: #555; margin-top: 3px; }
  .meta { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 14px; }
  .meta-item { padding: 6px 8px; border: 1px solid #ddd; border-radius: 4px; }
  .meta-item label { display: block; font-size: 10px; color: #777; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 2px; }
  .meta-item strong { font-size: 12px; }
  .just-box { background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; padding: 7px 10px; margin-bottom: 14px; font-size: 11px; }
  h2 { font-size: 13px; font-weight: bold; margin-bottom: 8px; border-bottom: 1px solid #ddd; padding-bottom: 4px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
  thead tr { background: #f0f0f0; }
  th, td { border: 1px solid #ccc; padding: 5px 8px; font-size: 11px; }
  th { font-size: 10px; text-transform: uppercase; letter-spacing: .3px; font-weight: bold; }
  .center { text-align: center; }
  tfoot td { font-weight: bold; background: #f9f9f9; }
  .assinaturas { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 70px; }
  .assinatura-box .fields p { border-top: 1px solid #999; padding-top: 4px; font-size: 10px; text-transform: uppercase; letter-spacing: .4px; color: #555; margin-top: 30px; }
  .rodape { margin-top: 20px; font-size: 10px; color: #aaa; text-align: right; border-top: 1px solid #eee; padding-top: 6px; }
  @media print {
    body { padding: 0; }
    .no-print { display: none; }
  }
</style>
</head>
<body>
<div class="header">
  <div>
    <h1>Requisição Interna — ${d.numero}</h1>
    <div class="sub">Exemplo — Departamento de Compras</div>
  </div>
  <div style="text-align:right; font-size:11px; color:#555">
    <div><strong>Emitido em:</strong> ${new Date().toLocaleString('pt-BR')}</div>
    <div><strong>Nível de aprovação:</strong> ${d.nivel}</div>
  </div>
</div>

<div class="meta">
  <div class="meta-item"><label>N° Requisição</label><strong>${d.numero}</strong></div>
  <div class="meta-item"><label>Solicitante</label><strong>${d.solicitante}</strong><br><span style="font-size:10px;color:#777">${d.email}</span></div>
  <div class="meta-item"><label>Filial / Setor</label><strong>${d.filial}</strong><br><span style="font-size:10px;color:#777">${d.setor}</span></div>
  <div class="meta-item"><label>Data da Requisição</label><strong>${d.criado_em}</strong></div>
  <div class="meta-item"><label>Grupo de Produto</label><strong>${d.grupo}</strong></div>
  <div class="meta-item"><label>Tipo de Despesa</label><strong>${d.tipo_despesa.charAt(0).toUpperCase() + d.tipo_despesa.slice(1)}</strong></div>
  <div class="meta-item"><label>Valor Total</label><strong>${d.valor}</strong></div>
</div>

${just}

<h2><span style="font-size:14px">☰</span> Itens Solicitados</h2>
<table>
  <thead><tr>
    <th>Item</th>
    <th class="center">Qtd</th>
    <th class="center">Valor Unit.</th>
    <th class="center">Subtotal</th>
    <th class="center">Obs.</th>
  </tr></thead>
  <tbody>${itensHtml}</tbody>
  <tfoot><tr>
    <td colspan="3" style="text-align:right">Total</td>
    <td class="center">${d.valor}</td>
    <td></td>
  </tr></tfoot>
</table>

<div class="assinaturas">
  <div class="assinatura-box">
    <div class="fields">
      <p>Responsável pela Entrega</p>
    </div>
  </div>
  <div class="assinatura-box">
    <div class="fields">
      <p>Responsável pela Retirada</p>
      <p>Data / Horário</p>
    </div>
  </div>
</div>

<div class="rodape">Exemplo Compras — documento gerado automaticamente</div>

<script>window.onload = function(){ window.print(); }<\/script>
</body></html>`;

        const win = window.open('', '_blank', 'width=800,height=650');
        win.document.write(html);
        win.document.close();
    }

    document.getElementById('modalDetalhe').addEventListener('show.bs.modal', function (e) {
        aprovId      = e.relatedTarget.dataset.aprovId;
        const d      = todos[aprovId];
        if (!d) return;

        document.getElementById('dSubtitle').textContent    = 'Nível ' + d.nivel + ' · ' + d.valor;
        document.getElementById('dSolicitante').textContent = d.solicitante;
        document.getElementById('dEmail').textContent       = d.email;
        document.getElementById('dFilial').textContent      = d.filial;
        document.getElementById('dSetor').textContent       = d.setor;
        document.getElementById('dGrupo').textContent       = d.grupo;
        document.getElementById('dData').textContent        = d.criado_em;
        document.getElementById('dTotal').textContent       = d.valor;

        // Badge tipo despesa
        const badge = document.getElementById('dTipoBadge');
        badge.textContent = d.tipo_despesa.charAt(0).toUpperCase() + d.tipo_despesa.slice(1);
        const mapaClasse  = { recorrente: 'bg-primary', eventual: 'bg-warning text-dark' };
        badge.className   = 'badge ' + (mapaClasse[d.tipo_despesa] || 'bg-secondary');

        // Justificativa
        const justBox = document.getElementById('dJustBox');
        if (d.justificativa) {
            document.getElementById('dJust').textContent = d.justificativa;
            justBox.style.display = '';
        } else {
            justBox.style.display = 'none';
        }

        // Itens
        const tbody = document.getElementById('dItens');
        tbody.innerHTML = '';
        if (!d.itens || d.itens.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Nenhum item cadastrado</td></tr>';
        } else {
            // Só textContent: nome e observação são digitados por quem cria a
            // requisição — innerHTML aqui executava script na sessão do aprovador.
            const celula = function (tr, texto, classe) {
                const td = document.createElement('td');
                if (classe) td.className = classe;
                td.textContent = texto;
                tr.appendChild(td);
                return td;
            };
            d.itens.forEach(function (it) {
                const tr = document.createElement('tr');
                celula(tr, it.nome, 'fw-semibold');
                celula(tr, it.qtd + ' ' + it.unidade, 'text-end');
                celula(tr, it.valor_unit, 'text-end');
                celula(tr, it.subtotal, 'text-end fw-semibold');
                const obs = celula(tr, '');
                const span = document.createElement('span');
                span.className   = it.obs ? 'text-muted small' : 'text-muted';
                span.textContent = it.obs || '—';
                obs.appendChild(span);
                tbody.appendChild(tr);
            });
        }
    });

    document.getElementById('btnAprovarModal').addEventListener('click', function () {
        bootstrap.Modal.getInstance(document.getElementById('modalDetalhe')).hide();
        document.getElementById('formAprovar').action = '<?= url("aprovacoes") ?>/' + aprovId + '/aprovar';
        new bootstrap.Modal(document.getElementById('modalAprovar')).show();
    });

    document.getElementById('btnRejeitarModal').addEventListener('click', function () {
        bootstrap.Modal.getInstance(document.getElementById('modalDetalhe')).hide();
        document.getElementById('formRejeitar').action = '<?= url("aprovacoes") ?>/' + aprovId + '/rejeitar';
        new bootstrap.Modal(document.getElementById('modalRejeitar')).show();
    });
})();
</script>

<?php endif; ?>
<!-- Histórico de Aprovações -->
<?php if (!empty($historico)): ?>
<?php
$nAprov  = count(array_filter($historico, fn($a) => $a['status'] === 'aprovado'));
$nRejeit = count(array_filter($historico, fn($a) => $a['status'] === 'rejeitado'));
?>
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white border-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h6 class="fw-bold mb-0">
            <i class="bi bi-clock-history me-2 text-secondary"></i>Histórico de Aprovações
        </h6>
        <div class="d-flex align-items-center gap-2">
            <div class="btn-group btn-group-sm" id="filtroHistorico" role="group">
                <button type="button" class="btn btn-outline-secondary active" data-filtro="todos">
                    Todos <span class="badge bg-secondary ms-1"><?= count($historico) ?></span>
                </button>
                <button type="button" class="btn btn-outline-success" data-filtro="aprovado">
                    Aprovados <span class="badge bg-success ms-1"><?= $nAprov ?></span>
                </button>
                <button type="button" class="btn btn-outline-danger" data-filtro="rejeitado">
                    Rejeitados <span class="badge bg-danger ms-1"><?= $nRejeit ?></span>
                </button>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0" id="tabelaHistorico">
                <thead class="table-light">
                    <tr>
                        <th class="px-3">N°</th>
                        <th>Solicitante</th>
                        <th>Tipo</th>
                        <th>Status</th>
                        <th class="text-end">Valor</th>
                        <th>Resolvido por</th>
                        <th>Data</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($historico as $a): ?>
                    <?php
                        if ($a['entidade_tipo'] === 'ordem_compra' && !empty($a['oc_numero'])) {
                            $numHist = $a['oc_numero'];
                        } elseif ($a['entidade_tipo'] === 'requisicao' && !empty($a['req_numero'])) {
                            $numHist = formatar_numero_requisicao($a['req_tipo'] ?? 'compra', $a['req_criado_em'], (int)$a['req_numero']);
                        } elseif ($a['entidade_tipo'] === 'requisicao_brinde' && !empty($a['brinde_numero'])) {
                            $numHist = 'BRN-' . date('Y', strtotime($a['brinde_criado_em'])) . '-'
                                . str_pad((string)$a['brinde_numero'], 5, '0', STR_PAD_LEFT);
                        } else {
                            $numHist = '—';
                        }
                        $tipoLabel = match ($a['entidade_tipo']) {
                            'requisicao'        => 'Requisição',
                            'requisicao_brinde' => 'Brinde',
                            default              => 'OC',
                        };
                    ?>
                    <tr data-status="<?= e($a['status']) ?>">
                        <td class="px-3 small fw-semibold"><?= e($numHist) ?></td>
                        <td class="small fw-semibold"><?= e($a['solicitante_nome'] ?? '—') ?></td>
                        <td><span class="badge bg-secondary"><?= e($tipoLabel) ?></span></td>
                        <td>
                            <?php if ($a['status'] === 'aprovado'): ?>
                                <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Aprovado</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><i class="bi bi-x-lg me-1"></i>Rejeitado</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end small fw-semibold"><?= money($a['valor'] ?? 0) ?></td>
                        <td class="small text-muted"><?= e($a['aprovado_por_nome'] ?? '—') ?></td>
                        <td class="small text-muted"><?= isset($a['resolvido_em']) ? date('d/m/Y H:i', strtotime($a['resolvido_em'])) : '—' ?></td>
                        <td class="pe-3">
                            <?php if ($a['entidade_tipo'] === 'requisicao'): ?>
                                <a href="<?= url('requisicoes/' . $a['entidade_id']) ?>" class="btn btn-outline-secondary btn-sm" title="Ver Requisição"><i class="bi bi-eye"></i></a>
                                <?php if ($a['status'] === 'aprovado'): ?>
                                    <a href="<?= url('requisicoes/' . $a['entidade_id'] . '/imprimir') ?>" target="_blank"
                                       class="btn btn-outline-secondary btn-sm" title="Imprimir Requisição">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                <?php endif; ?>
                            <?php elseif ($a['entidade_tipo'] === 'ordem_compra'): ?>
                                <a href="<?= url('compras/ordens/' . $a['entidade_id']) ?>" class="btn btn-outline-secondary btn-sm" title="Ver OC"><i class="bi bi-eye"></i></a>
                            <?php elseif ($a['entidade_tipo'] === 'requisicao_brinde' && sistema() === 'compras'): /* /brindes não existe no Requisições */ ?>
                                <a href="<?= url('brindes/requisicoes') ?>" class="btn btn-outline-secondary btn-sm" title="Ver Requisições de Brinde"><i class="bi bi-eye"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
document.getElementById('filtroHistorico').addEventListener('click', function(e) {
    const btn = e.target.closest('[data-filtro]');
    if (!btn) return;
    const filtro = btn.dataset.filtro;
    // Atualiza botões ativos
    this.querySelectorAll('.btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    // Filtra linhas
    document.querySelectorAll('#tabelaHistorico tbody tr').forEach(function(tr) {
        tr.style.display = (filtro === 'todos' || tr.dataset.status === filtro) ? '' : 'none';
    });
});
</script>
<?php endif; ?>
