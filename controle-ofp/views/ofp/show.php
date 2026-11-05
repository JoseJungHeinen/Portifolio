<?php
use App\Services\AnexoService;
use App\Services\CorrecaoService;
use App\Services\PrazoService;
use App\Services\StatusService;

$podeOperar = can(['estoque', 'comprador', 'gerente', 'admin']);
$ativo = StatusService::ativo($ofp['status']);
$valor = fn(?string $v) => $v === null || $v === '' ? '—' : e($v);
$dinheiro = fn($v) => $v === null ? '—' : money($v);
$linha = fn(string $rotulo, string $html) => '<dt class="col-sm-5 fw-normal text-muted">' . e($rotulo) . '</dt><dd class="col-sm-7">' . $html . '</dd>';
$secundarias = ['cancelada', 'peca_vendida', 'rejeitada', 'rejeitada_final', 'perdida_prazo', 'encerrada', 'estornada'];
$fmt = fn($v) => number_format((float)$v, 2, ',', '.');
$padrao = function (string $campo) use ($ofp, $fmt): string {
    if (in_array($campo, ['observacao', 'data_decisao'], true)) { // sempre nova, sem sugestão (StatusService::FRESCOS)
        return '';
    }
    if ($campo === 'data_reanalise') { // cada reanálise é um caso GROW novo
        return date('Y-m-d');
    }
    if (!empty($ofp[$campo])) {
        return StatusService::CAMPOS[$campo]['tipo'] === 'money' ? $fmt($ofp[$campo]) : (string)$ofp[$campo];
    }
    return match ($campo) {
        'data_abertura', 'data_nf_devolucao', 'data_nf_icms_st', 'data_coleta_solicitada', 'data_coletada', 'data_credito' => date('Y-m-d'),
        'valor_nf_devolucao'   => $fmt($ofp['valor_reclamado']),
        'valor_creditado_peca' => $fmt($ofp['valor_nf_devolucao'] ?? $ofp['valor_reclamado']),
        'valor_creditado_st'   => $fmt($ofp['valor_icms_st'] ?? 0),
        default                => '',
    };
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <a class="small" href="<?= url('/ofps') ?>">&larr; OFPs</a>
        <h1 class="titulo h3 mb-1">OFP <span><?= e($ofp['numero_ofp'] ?? 'sem nº') ?></span></h1>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?= status_badge($ofp['status']) ?> <?= semaforo_badge($ofp['prazo_data']) ?>
            <?php if ($ofp['prazo_tipo']): ?>
                <span class="small text-muted">Próxima ação: <?= e(PrazoService::ROTULOS[$ofp['prazo_tipo']] ?? $ofp['prazo_tipo']) ?> até <?= date_br($ofp['prazo_data']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($acoes as $para): ?>
            <button type="button" class="btn btn-<?= in_array($para, $secundarias, true) ? 'outline-secondary' : 'primary' ?>" data-bs-toggle="modal" data-bs-target="#modal-<?= $para ?>">&rarr; <?= e(status_label($para)) ?></button>
        <?php endforeach; ?>
        <?php if ($podeOperar && $ativo): ?>
            <a class="btn btn-outline-dark" href="<?= url('/ofps/' . $ofp['id'] . '/editar') ?>"><i class="bi bi-pencil"></i> Editar</a>
        <?php endif; ?>
        <?php if ($camposCorrecao): ?>
            <button type="button" class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#modal-correcao"><i class="bi bi-wrench"></i> Corrigir dados</button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card card-body mb-3">
            <h2 class="h6">Peça e ocorrência</h2>
            <dl class="row mb-0 small">
                <?= $linha('Unidade', e($ofp['unidade_nome'])) ?>
                <?= $linha('Causal', e($ofp['causal_codigo'] . ' — ' . $ofp['causal_descricao']) . ' <span class="text-muted">(' . ($ofp['tipo_devolucao'] === 'fisica' ? 'devolução física' : 'devolução simbólica') . ')</span>') ?>
                <?= $linha('Desenho / peça', e($ofp['desenho']) . ($ofp['descricao_peca'] ? ' — ' . e($ofp['descricao_peca']) : '')) ?>
                <?= $linha('Quantidade', e(rtrim(rtrim((string)$ofp['quantidade'], '0'), '.'))) ?>
                <?= $linha('Valor reclamado', money($ofp['valor_reclamado'])) ?>
                <?= $linha('NF de origem / série', $valor($ofp['nf_origem']) . ' / ' . $valor($ofp['serie_origem'])) ?>
                <?= $linha('Nº do volume', $valor($ofp['numero_volume'])) ?>
                <?= $linha('Transportadora', $valor($ofp['transportadora'] ? strtoupper($ofp['transportadora']) : null)) ?>
                <?= $linha('Recebimento do CTe (D)', date_br($ofp['data_recebimento'])) ?>
                <?= $linha('Responsável', $valor($ofp['responsavel_nome'])) ?>
                <?= $linha('Observação', $ofp['observacao'] ? nl2br(e($ofp['observacao'])) : '—') ?>
            </dl>
        </div>
        <div class="card card-body mb-3">
            <h2 class="h6">Andamento na Fabricante</h2>
            <dl class="row mb-0 small">
                <?= $linha('Abertura no CSPS', date_br($ofp['data_abertura'])) ?>
                <?= $linha('Decisão', date_br($ofp['data_decisao'])) ?>
                <?php if (!empty($ofp['data_rejeicao']) || !empty($ofp['data_aprovacao'])): ?><?= $linha('Rejeitada em / Aprovada em', date_br($ofp['data_rejeicao']) . ' / ' . date_br($ofp['data_aprovacao'])) ?><?php endif; ?>
                <?php if (!empty($ofp['data_reanalise'])): ?><?= $linha('Caso GROW aberto em', date_br($ofp['data_reanalise'])) ?><?php endif; ?>
                <?php if (!empty($ofp['motivo_descricao'])): ?><?= $linha('Motivo', e($ofp['motivo_descricao'])) ?><?php endif; ?>
                <?php if (!empty($ofp['perda_sugerida'])): ?><?= $linha('Perda sugerida', '<span class="badge text-bg-warning">' . e(status_label($ofp['perda_sugerida'])) . '</span>') ?><?php endif; ?>
                <?= $linha('Protocolo GROW', $valor($ofp['protocolo_grow'])) ?>
                <?= $linha('NF de devolução', $valor($ofp['nf_devolucao']) . ' · ' . date_br($ofp['data_nf_devolucao']) . ' · ' . $dinheiro($ofp['valor_nf_devolucao'])) ?>
                <?= $linha('NF de ICMS ST', $valor($ofp['nf_icms_st']) . ' · ' . date_br($ofp['data_nf_icms_st']) . ' · ' . $dinheiro($ofp['valor_icms_st'])) ?>
                <?= $linha('Coleta solicitada / realizada', date_br($ofp['data_coleta_solicitada']) . ' / ' . date_br($ofp['data_coletada'])) ?>
                <?= $linha('Rastreio', $valor($ofp['codigo_rastreio'])) ?>
            </dl>
        </div>
        <div class="card card-body">
            <h2 class="h6">Financeiro</h2>
            <dl class="row mb-0 small">
                <?= $linha('Título Apollo', $valor($ofp['titulo_apollo'])) ?>
                <?= $linha('Data do crédito', date_br($ofp['data_credito'])) ?>
                <?= $linha('Creditado (peça / ICMS ST)', $dinheiro($ofp['valor_creditado_peca']) . ' / ' . $dinheiro($ofp['valor_creditado_st'])) ?>
                <?= $linha('Em aberto (peça / ICMS ST)', '<strong>' . money($ofp['aberto_peca']) . '</strong> / <strong>' . money($ofp['aberto_st']) . '</strong>') ?>
            </dl>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card card-body mb-3">
            <h2 class="h6">Fotos obrigatórias do causal</h2>
            <p class="small mb-0"><?= e($ofp['fotos_obrigatorias'] ?: 'Nenhuma.') ?></p>
        </div>
        <div class="card card-body mb-3">
            <h2 class="h6">Anexos</h2>
            <ul class="list-unstyled small mb-2">
                <?php foreach ($anexos as $a): ?>
                    <li class="mb-1"><i class="bi bi-paperclip"></i> <a href="<?= url('/anexos/' . $a['id']) ?>" target="_blank" rel="noopener"><?= e($a['nome_original']) ?></a>
                        <span class="text-muted">· <?= e(AnexoService::TIPOS[$a['tipo']] ?? $a['tipo']) ?> · <?= datetime_br($a['created_at']) ?></span></li>
                <?php endforeach; ?>
                <?php if (!$anexos): ?><li class="text-muted">Nenhum anexo.</li><?php endif; ?>
            </ul>
            <?php if ($podeOperar): ?>
                <form method="post" action="<?= url('/ofps/' . $ofp['id'] . '/anexos') ?>" enctype="multipart/form-data" class="d-grid gap-2">
                    <?= csrf_field() ?>
                    <select class="form-select form-select-sm" name="tipo" aria-label="Tipo do anexo">
                        <?php foreach (AnexoService::TIPOS as $chave => $rotulo): ?><option value="<?= $chave ?>"><?= e($rotulo) ?></option><?php endforeach; ?>
                    </select>
                    <input class="form-control form-control-sm" type="file" aria-label="Arquivos" name="arquivos[]" multiple accept=".jpg,.jpeg,.png,.pdf,.xml" required>
                    <button class="btn btn-sm btn-outline-primary">Anexar</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card card-body">
            <h2 class="h6">Histórico</h2>
            <ul class="timeline small mb-0">
                <?php foreach ($eventos as $ev): ?>
                    <li>
                        <strong><?= datetime_br($ev['created_at']) ?></strong> · <?= e($ev['usuario_nome'] ?? 'Sistema') ?><br>
                        <?php if ($ev['status_para'] && $ev['status_de'] !== $ev['status_para']): ?>
                            <?= $ev['status_de'] ? e(status_label($ev['status_de'])) . ' &rarr; ' : '' ?><?= e(status_label($ev['status_para'])) ?><br>
                        <?php endif; ?>
                        <?php if (!empty($ev['motivo_descricao'])): ?><span class="badge text-bg-light border">Motivo: <?= e($ev['motivo_descricao']) ?></span><br><?php endif; ?>
                        <?php if ($ev['observacao']): ?><span class="text-muted"><?= nl2br(e($ev['observacao'])) ?></span><br><?php endif; ?>
                        <?php foreach (json_decode((string)($ev['alteracoes'] ?? ''), true) ?: [] as $alt): ?>
                            <?php if (!is_array($alt) || !isset($alt['campo'])) { continue; } ?>
                            <span class="d-block text-muted"><?= rotulo_campo($alt['campo']) ?>: <s><?= valor_campo($alt['campo'], $alt['de']) ?></s> &rarr; <?= valor_campo($alt['campo'], $alt['para']) ?></span>
                        <?php endforeach; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<?php foreach ($acoes as $para): ?>
    <?php $obrigatorios = StatusService::camposObrigatorios($para); $campos = array_merge($obrigatorios, StatusService::camposOpcionais($para)); ?>
    <div class="modal fade" id="modal-<?= $para ?>" tabindex="-1" aria-labelledby="titulo-modal-<?= $para ?>" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="post" action="<?= url('/ofps/' . $ofp['id'] . '/transicao') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="para" value="<?= $para ?>">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="titulo-modal-<?= $para ?>"><?= e(status_label($ofp['status'])) ?> &rarr; <?= e(status_label($para)) ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <?php if (StatusService::exigeMotivo($para)): ?>
                        <div class="mb-3">
                            <label class="form-label" for="<?= $para ?>-motivo">Motivo *</label>
                            <select class="form-select" id="<?= $para ?>-motivo" name="motivo_id" required data-obs="#<?= $para ?>-observacao">
                                <option value="">Selecione…</option>
                                <?php foreach ($motivos[$para] ?? [] as $m): ?>
                                    <option value="<?= e($m['id']) ?>" data-exige-obs="<?= (int)$m['exige_observacao'] ?>"><?= e($m['descricao']) ?><?= (int)$m['exige_observacao'] ? ' (exige observação)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($campos as $campo): ?>
                        <?php $meta = StatusService::CAMPOS[$campo]; $req = in_array($campo, $obrigatorios, true); $idCampo = $para . '-' . $campo; ?>
                        <?php if ($meta['tipo'] === 'checkbox'): ?>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="<?= $idCampo ?>" name="<?= $campo ?>" value="1">
                                <label class="form-check-label" for="<?= $idCampo ?>"><?= e($meta['label']) ?></label>
                            </div>
                            <?php continue; ?>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label" for="<?= $idCampo ?>"><?= e($meta['label']) ?><?= $req ? ' *' : '' ?></label>
                            <?php if ($meta['tipo'] === 'textarea'): ?>
                                <textarea class="form-control" id="<?= $idCampo ?>" name="<?= $campo ?>" rows="2" <?= $req ? 'required' : '' ?>></textarea>
                            <?php else: ?>
                                <input class="form-control" id="<?= $idCampo ?>" name="<?= $campo ?>" type="<?= $meta['tipo'] === 'date' ? 'date' : 'text' ?>"
                                       <?= $meta['tipo'] === 'money' ? 'inputmode="decimal"' : '' ?> value="<?= e($padrao($campo)) ?>" <?= $req ? 'required' : '' ?>>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($para === 'creditada'): ?><p class="small text-muted mb-0">Informe o total já creditado. Se faltar valor, a OFP continua aguardando crédito.</p><?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Voltar</button>
                    <button class="btn btn-primary">Confirmar</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<script>
document.querySelectorAll('select[name="motivo_id"][data-obs]').forEach(function (sel) {
    sel.addEventListener('change', function () {
        var obs = document.querySelector(sel.dataset.obs);
        if (!obs) { return; }
        var exige = sel.selectedOptions[0] && sel.selectedOptions[0].dataset.exigeObs === '1';
        obs.required = exige;
        var lab = document.querySelector('label[for="' + obs.id + '"]');
        if (lab) { lab.textContent = lab.textContent.replace(/ \*$/, '') + (exige ? ' *' : ''); }
    });
});
</script>

<?php if ($camposCorrecao): ?>
    <div class="modal fade" id="modal-correcao" tabindex="-1" aria-labelledby="titulo-modal-correcao" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form class="modal-content" method="post" action="<?= url('/ofps/' . $ofp['id'] . '/corrigir') ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="titulo-modal-correcao">Corrigir dados da OFP</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">Altere só o que está errado. Cada mudança vai para o histórico com o valor anterior.</p>
                    <div class="row g-3">
                        <?php foreach ($camposCorrecao as $campo): ?>
                            <?php $meta = StatusService::CAMPOS[$campo]; $atual = $ofp[$campo] ?? null; ?>
                            <?php if ($meta['tipo'] === 'checkbox'): ?>
                                <div class="col-md-6 d-flex align-items-end">
                                    <div class="form-check mb-2">
                                        <input type="hidden" name="<?= $campo ?>" value="0">
                                        <input class="form-check-input" type="checkbox" id="corr-<?= $campo ?>" name="<?= $campo ?>" value="1" <?= (int)$atual ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="corr-<?= $campo ?>"><?= e($meta['label']) ?></label>
                                    </div>
                                </div>
                                <?php continue; ?>
                            <?php endif; ?>
                            <div class="col-md-6">
                                <label class="form-label" for="corr-<?= $campo ?>"><?= e($meta['label']) ?></label>
                                <input class="form-control" id="corr-<?= $campo ?>" name="<?= $campo ?>" type="<?= $meta['tipo'] === 'date' ? 'date' : 'text' ?>"
                                       <?= $meta['tipo'] === 'money' ? 'inputmode="decimal"' : '' ?>
                                       value="<?= e($atual === null ? '' : ($meta['tipo'] === 'money' ? $fmt($atual) : (string)$atual)) ?>">
                            </div>
                        <?php endforeach; ?>
                        <div class="col-md-6">
                            <label class="form-label" for="corr-tipo">Tipo da correção *</label>
                            <select class="form-select" id="corr-tipo" name="tipo_correcao" required>
                                <option value="">Selecione…</option>
                                <?php foreach (CorrecaoService::TIPOS as $chave => $rotulo): ?><option value="<?= $chave ?>"><?= e($rotulo) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="corr-obs">O que foi corrigido e por quê *</label>
                            <textarea class="form-control" id="corr-obs" name="observacao" rows="2" required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Voltar</button>
                    <button class="btn btn-primary">Salvar correção</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
