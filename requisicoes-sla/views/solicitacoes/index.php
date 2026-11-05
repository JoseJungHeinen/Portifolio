<?php
// @versao Ver.003.301026
use App\Services\SolicitacaoCompraService as Sol;

$title = 'Solicitações de Compra';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-chat-square-text me-2"></i>Solicitações de Compra</h4>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovaSolicitacao">
            <i class="bi bi-plus-lg me-1"></i>Nova
        </button>
    </div>
</div>

<?php if (count($abas) > 1): ?>
<ul class="nav nav-tabs mb-3">
    <?php foreach ($abas as $chave => $rotulo): ?>
    <li class="nav-item">
        <a class="nav-link <?= $aba === $chave ? 'active' : '' ?>" href="<?= url('solicitacoes?aba=' . $chave) ?>">
            <?= e($rotulo) ?>
            <?php if ($chave === 'aprovar' && $nAprovar > 0): ?>
                <span class="badge bg-warning ms-1"><?= $nAprovar ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<form method="GET" action="<?= url('solicitacoes') ?>" class="mb-3">
    <input type="hidden" name="aba" value="<?= e($aba) ?>">
    <select name="status" aria-label="Filtrar por status" class="form-select form-select-sm" style="max-width:240px" onchange="this.form.submit()">
        <option value="">Todos os status</option>
        <?php foreach (Sol::STATUS_LABEL as $valor => $texto): ?>
        <option value="<?= e($valor) ?>" <?= $status === $valor ? 'selected' : '' ?>><?= e($texto) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if (empty($solicitacoes)): ?>
<div class="card shadow-sm">
    <div class="card-body text-center text-muted py-5">
        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
        Nenhuma solicitação por aqui.
    </div>
</div>
<?php else: ?>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nº</th>
                    <th>Urgência</th>
                    <th>SLA</th>
                    <th>Título</th>
                    <?php if ($aba !== 'minhas'): ?><th>Solicitante</th><?php endif; ?>
                    <th>Status</th>
                    <th>Atualizada em</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($solicitacoes as $s): ?>
                <tr class="position-relative">
                    <td class="text-nowrap fw-semibold"><?= e(Sol::numeroFormatado($s)) ?></td>
                    <td><?= Sol::seloUrgencia($s['urgencia'] ?? null) ?></td>
                    <td class="small text-nowrap"><?php $sla = Sol::situacaoSla($s); ?><?php if ($sla): ?><span class="<?= $sla['classe'] ?>"><?= e($sla['texto']) ?></span><?php endif; ?></td>
                    <td><a href="<?= e(url('solicitacoes/' . $s['id'])) ?>" class="stretched-link text-reset text-decoration-none"><?= e($s['titulo']) ?></a></td>
                    <?php if ($aba !== 'minhas'): ?>
                    <td>
                        <?= e($s['solicitante_nome']) ?>
                        <div class="small text-muted"><?= e($s['filial_nome'] ?? '') ?></div>
                    </td>
                    <?php endif; ?>
                    <td><span class="badge bg-<?= Sol::STATUS_BADGE[$s['status']] ?? 'secondary' ?>"><?= e(Sol::STATUS_LABEL[$s['status']] ?? $s['status']) ?></span>
                        <?php if (!empty($andamentos[$s['id']])): ?><div class="mt-1"><?php $andamento = $andamentos[$s['id']]; $andamentoCompacto = true; include ROOT . '/views/partials/andamento.php'; ?></div><?php endif; ?>
                    </td>
                    <td class="text-nowrap small"><?= datetime_br($s['ultima_atualizacao'] ?? $s['criado_em']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Nova solicitação -->
<div class="modal fade" id="modalNovaSolicitacao" tabindex="-1" aria-labelledby="modalNovaSolicitacaoLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <form method="POST" id="formNovaSolicitacao" action="<?= url('solicitacoes') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="modalNovaSolicitacaoLabel">Nova solicitação de compra</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($old['_erro'])): ?><div class="alert alert-danger py-2 small"><?= e($old['_erro']) ?> Se tinha anexado fotos, selecione-as novamente.</div><?php endif; ?>
                    <?php
                    $itensOld   = !empty($old['itens']) ? $old['itens'] : [['descricao' => '', 'quantidade' => '', 'unidade' => 'un', 'valor_unitario' => null]];
                    $solOld     = $old['solicitante_id'] ?? auth()['id'];
                    $finOld     = $old['finalidade'] ?? '';
                    $recOld     = $old['recorrencia'] ?? 'unica';
                    $fmtValor   = static fn($v) => $v === null || $v === '' ? '' : number_format((float)$v, 2, ',', '.');
                    $fmtQtd     = static fn($q) => $q === '' || $q === null ? '' : rtrim(rtrim(number_format((float)$q, 2, '.', ''), '0'), '.');
                    ?>
                    <div class="row g-3 mb-2">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="solSolicitante">Solicitante</label>
                            <?php if ($emNomeDeOutro): ?>
                            <select name="solicitante_id" id="solSolicitante" class="form-select">
                                <?php foreach ($usuarios as $u): ?>
                                <option value="<?= e($u['id']) ?>" data-unidade="<?= e(($u['filial_nome'] ?? '—') . ' / ' . ($u['setor_nome'] ?? '—')) ?>"
                                        data-filial="<?= e($u['filial_id'] ?? '') ?>" data-setor="<?= e($u['setor_id'] ?? '') ?>"
                                        <?= $u['id'] === $solOld ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <input type="text" class="form-control" value="<?= e(auth()['nome']) ?>" disabled>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Unidade / setor</label>
                            <?php if ($podeDefinirUnidade): ?>
                            <?php
                            $solRow = null;
                            foreach ($usuarios as $u) { if ($u['id'] === $solOld) { $solRow = $u; break; } }
                            $filialSel = $old['filial_id'] ?? ($solRow['filial_id'] ?? '');
                            $setorSel  = $old['setor_id']  ?? ($solRow['setor_id']  ?? '');
                            ?>
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <select name="filial_id" id="solFilial" class="form-select" aria-label="Unidade" required>
                                        <?php foreach ($filiais as $fil): ?>
                                        <option value="<?= e($fil['id']) ?>" <?= (string)$fil['id'] === (string)$filialSel ? 'selected' : '' ?>><?= e($fil['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-sm-6">
                                    <select name="setor_id" id="solSetor" class="form-select" aria-label="Setor">
                                        <option value="">— sem setor —</option>
                                        <?php foreach ($setores as $set): ?>
                                        <option value="<?= e($set['id']) ?>" <?= (string)$set['id'] === (string)$setorSel ? 'selected' : '' ?>><?= e($set['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-text">Padrão: cadastro do solicitante. A aprovação segue o gestor do setor escolhido.</div>
                            <?php else: ?>
                            <input type="text" id="solUnidade" class="form-control" disabled value="">
                            <div class="form-text">Vêm do cadastro do solicitante.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <label class="form-label fw-semibold mt-2">Urgência</label>
                    <?php $urgOld = $old['urgencia'] ?? Sol::URGENCIA_PADRAO; ?>
                    <div class="d-flex flex-wrap gap-2 mb-1" role="radiogroup">
                        <?php foreach (Sol::URGENCIAS as $chave => $u): ?>
                        <input type="radio" class="btn-check" name="urgencia" id="solUrg<?= e($chave) ?>" value="<?= e($chave) ?>" <?= $urgOld === $chave ? 'checked' : '' ?>>
                        <label class="btn btn-sm rounded-pill sol-urg" for="solUrg<?= e($chave) ?>" style="--urg:<?= $u['hex'] ?>" title="<?= e($u['ajuda'] . ' — ' . (isset(Sol::SLA_DIAS_UTEIS[$chave]) ? 'SLA ' . Sol::SLA_DIAS_UTEIS[$chave] . (Sol::SLA_DIAS_UTEIS[$chave] === 1 ? ' dia útil' : ' dias úteis') : 'sem SLA')) ?>">
                            <span class="d-inline-block rounded-circle me-1" style="width:.6rem;height:.6rem;background:<?= $u['hex'] ?>"></span><?= e($u['rotulo']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text mb-3" id="solUrgAjuda"></div>

                    <label class="form-label fw-semibold mt-2">Finalidade fiscal</label>
                    <div class="d-flex flex-wrap gap-2 mb-3" role="radiogroup">
                        <?php foreach (['' => 'Não sei'] + \App\Models\Requisicao::FINALIDADES as $valor => $rotulo): ?>
                        <input type="radio" class="btn-check" name="finalidade" id="solFin<?= e($valor ?: 'nao') ?>" value="<?= e($valor) ?>" <?= $finOld === (string)$valor ? 'checked' : '' ?>>
                        <label class="btn btn-outline-secondary btn-sm rounded-pill" for="solFin<?= e($valor ?: 'nao') ?>"><?= e($rotulo) ?></label>
                        <?php endforeach; ?>
                    </div>

                    <label class="form-label fw-semibold">Itens * <span class="fw-normal text-muted small">— uma linha para cada produto ou serviço</span></label>
                    <div id="solItens">
                        <?php foreach ($itensOld as $it): ?>
                        <div class="row g-2 mb-2 sol-item">
                            <div class="col-md-6"><input type="text" name="item_descricao[]" class="form-control" maxlength="255" placeholder="Item ou serviço, marca/especificação" value="<?= e($it['descricao']) ?>"></div>
                            <div class="col-4 col-md-1"><input type="text" inputmode="decimal" name="item_quantidade[]" class="form-control sol-qtd" placeholder="Qtd." value="<?= e($fmtQtd($it['quantidade'])) ?>"></div>
                            <div class="col-3 col-md-1"><input type="text" name="item_unidade[]" class="form-control" maxlength="10" value="<?= e($it['unidade'] ?: 'un') ?>"></div>
                            <div class="col-4 col-md-3"><input type="text" inputmode="decimal" name="item_valor[]" class="form-control sol-valor" placeholder="R$ unit. estimado" value="<?= e($fmtValor($it['valor_unitario'])) ?>"></div>
                            <div class="col-1 d-flex align-items-center"><button type="button" class="btn btn-link text-muted p-0 sol-remover" aria-label="Remover item"><i class="bi bi-x-lg"></i></button></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="solAddItem"><i class="bi bi-plus-lg me-1"></i>Item</button>
                        <span class="small text-muted">Total estimado: <strong id="solTotal">R$ 0,00</strong></span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="solMotivo">Motivo da compra *</label>
                        <textarea name="motivo" id="solMotivo" class="form-control" rows="2" required placeholder="Por que a compra é necessária e o impacto se não for feita"><?= e($old['motivo'] ?? '') ?></textarea>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="solNecessarioAte">Necessário até *</label>
                            <input type="date" name="necessario_ate" id="solNecessarioAte" class="form-control" required
                                   min="<?= date('Y-m-d') ?>" value="<?= e($old['necessario_ate'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="solFornecedor">Fornecedor sugerido <span class="fw-normal text-muted">(opcional)</span></label>
                            <input type="text" name="fornecedor_sugerido" id="solFornecedor" class="form-control" maxlength="150" value="<?= e($old['fornecedor_sugerido'] ?? '') ?>">
                        </div>
                    </div>

                    <label class="form-label fw-semibold">Recorrência</label>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php foreach (Sol::RECORRENCIAS as $valor => $rotulo): ?>
                        <input type="radio" class="btn-check" name="recorrencia" id="solRec<?= e($valor) ?>" value="<?= e($valor) ?>" <?= $recOld === $valor ? 'checked' : '' ?>>
                        <label class="btn btn-outline-primary btn-sm rounded-pill" for="solRec<?= e($valor) ?>"><?= e($rotulo) ?></label>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="fora_orcamento" value="1" id="solForaOrc" <?= !empty($old['fora_orcamento']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="solForaOrc">Compra fora do orçamento (aprovação da Diretoria, independentemente do valor)</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="emergencial" value="1" id="solEmergencial" <?= !empty($old['emergencial']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="solEmergencial">Compra emergencial já realizada — regularização (item 13)</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="solObservacoes">Observações</label>
                        <textarea name="observacoes" id="solObservacoes" class="form-control" rows="2"><?= e($old['observacoes'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="form-label fw-semibold" for="solFotos">Fotos <span class="text-muted fw-normal">(opcional)</span></label>
                        <input type="file" name="fotos[]" id="solFotos" class="form-control" multiple accept=".jpg,.jpeg,.png,.webp">
                        <div class="form-text">Até <?= Sol::MAX_FOTOS ?> fotos, JPG, PNG ou WEBP, 5 MB cada.</div>
                        <div id="solFotosPrevia" class="d-flex flex-wrap gap-2 mt-2"></div>
                    </div>
                    <div class="mt-3">
                        <details class="bg-light rounded px-3 py-2 small">
                            <summary class="text-muted">Exceções — não abrir solicitação para estes casos (item 2.1)</summary>
                            <p class="mb-1 mt-2">Seguem fluxo e controles próprios:</p>
                            <ul class="mb-1">
                                <?php foreach (Sol::EXCECOES_POLITICA as $exc): ?><li><?= e($exc) ?></li><?php endforeach; ?>
                            </ul>
                        </details>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Registrar solicitação</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.sol-urg{border:1px solid var(--urg);color:var(--urg)}
.btn-check:checked + .sol-urg{background:var(--urg);color:#fff}
</style>
<script>
// Bootstrap JS é carregado no fim do <body>, depois deste script: tudo que
// usa `bootstrap` espera o DOMContentLoaded.
document.addEventListener('DOMContentLoaded', function () {
    const urgAjuda = document.getElementById('solUrgAjuda');
    function mostrarAjudaUrgencia() {
        const marcado = document.querySelector('input[name=urgencia]:checked');
        const rotulo  = marcado ? document.querySelector('label[for="' + marcado.id + '"]') : null;
        urgAjuda.textContent = rotulo ? rotulo.title : '';
    }
    document.querySelectorAll('input[name=urgencia]').forEach(function (r) { r.addEventListener('change', mostrarAjudaUrgencia); });
    mostrarAjudaUrgencia();

    const input  = document.getElementById('solFotos');
    const previa = document.getElementById('solFotosPrevia');
    const maxFotos = <?= Sol::MAX_FOTOS ?>;
    const maxBytes = <?= Sol::MAX_BYTES ?>;

    input.addEventListener('change', function () {
        previa.querySelectorAll('img').forEach(function (i) { URL.revokeObjectURL(i.src); });
        previa.innerHTML = '';
        const arquivos = Array.from(input.files);
        if (arquivos.length > maxFotos) {
            alert('Selecione no máximo ' + maxFotos + ' fotos.');
            input.value = '';
            return;
        }
        const invalido = arquivos.find(function (a) { return !/^image\/(jpeg|png|webp)$/.test(a.type); });
        if (invalido) {
            alert('Use JPG, PNG ou WEBP.');
            input.value = '';
            return;
        }
        const grande = arquivos.find(function (a) { return a.size > maxBytes; });
        if (grande) {
            alert('A foto "' + grande.name + '" passa de ' + (maxBytes / 1048576) + ' MB.');
            input.value = '';
            return;
        }
        arquivos.forEach(function (arq) {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(arq);
            img.alt = arq.name;
            img.style.cssText = 'width:80px;height:80px;object-fit:cover;border-radius:6px';
            previa.appendChild(img);
        });
    });

    document.getElementById('formNovaSolicitacao').addEventListener('submit', function (ev) {
        const btn = ev.target.querySelector('button[type=submit]');
        btn.disabled = true;
        btn.textContent = 'Enviando…';
    });

    // ── Itens: adicionar/remover linha e total estimado ──
    const lista  = document.getElementById('solItens');
    // Molde da linha capturado no load (limpo), para o "+ Item" nunca depender de uma linha existente.
    const moldeItem = lista.querySelector('.sol-item').cloneNode(true);
    moldeItem.querySelectorAll('input').forEach(function (i) { i.value = i.name === 'item_unidade[]' ? 'un' : ''; });
    const total  = document.getElementById('solTotal');
    const numBR  = function (s) {
        s = String(s || '').replace(/[^\d.,]/g, '');
        if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
        return parseFloat(s) || 0;
    };
    const recalcular = function () {
        let soma = 0;
        lista.querySelectorAll('.sol-item').forEach(function (l) {
            soma += numBR(l.querySelector('.sol-qtd').value) * numBR(l.querySelector('.sol-valor').value);
        });
        total.textContent = soma.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    };
    document.getElementById('solAddItem').addEventListener('click', function () {
        if (lista.querySelectorAll('.sol-item').length >= <?= Sol::MAX_ITENS ?>) return;
        const nova = moldeItem.cloneNode(true);
        lista.appendChild(nova);
        nova.querySelector('input').focus();
    });
    lista.addEventListener('click', function (ev) {
        const btn = ev.target.closest('.sol-remover');
        if (!btn) return;
        const linhas = lista.querySelectorAll('.sol-item');
        if (linhas.length === 1) {
            linhas[0].querySelectorAll('input').forEach(function (i) { i.value = i.name === 'item_unidade[]' ? 'un' : ''; });
        } else {
            btn.closest('.sol-item').remove();
        }
        recalcular();
    });
    lista.addEventListener('input', recalcular);
    recalcular();

    // ── Unidade/setor do solicitante escolhido ──
    const selSol  = document.getElementById('solSolicitante');
    const unidade = document.getElementById('solUnidade');
    const selFilial = document.getElementById('solFilial');
    const selSetor  = document.getElementById('solSetor');
    const mostrarUnidade = function () {
        if (!unidade) return;
        unidade.value = selSol ? (selSol.selectedOptions[0]?.dataset.unidade || '') : <?= json_encode($userUnidade, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    };
    // Comprador/admin: os selects de unidade/setor acompanham o solicitante escolhido.
    const seguirSolicitante = function () {
        const o = selSol && selSol.selectedOptions[0];
        if (!o || !selFilial || !selSetor) return;
        selFilial.value = o.dataset.filial || '';
        selSetor.value  = o.dataset.setor || '';
    };
    if (selSol) selSol.addEventListener('change', function () { mostrarUnidade(); seguirSolicitante(); });
    mostrarUnidade();

    <?php if ($abrirModal): ?>
    new bootstrap.Modal(document.getElementById('modalNovaSolicitacao')).show();
    <?php endif; ?>
});
</script>
