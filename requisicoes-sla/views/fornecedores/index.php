<?php
// @versao Ver.003.301026
use App\Models\Fornecedor;
use App\Services\FornecedorListaService as FL;

$qs = function (array $mudar = []) use ($filtros, $ordem): string {
    $p = array_filter($filtros + ['ord' => $ordem !== 'nome' ? $ordem : ''], fn($v) => $v !== '' && $v !== null);
    foreach ($mudar as $k => $v) {
        if ($v === null || $v === '') unset($p[$k]); else $p[$k] = $v;
    }
    return $p ? '?' . http_build_query($p) : '';
};
$nAtivos = count(array_filter($filtros, fn($v) => $v !== ''));
$sitPill = ['pendente' => 'bg-warning text-dark', 'homologado' => 'bg-success', 'bloqueado' => 'bg-danger'];
$cndCor  = ['negativa' => 'text-success', 'positiva' => 'text-danger fw-semibold', 'positiva_efeitos_negativa' => 'text-warning'];
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-building me-2 text-primary"></i>Fornecedores
        <span class="text-muted fs-6 fw-normal ms-1"><?= $total ?> cadastrado<?= $total === 1 ? '' : 's' ?></span></h4>
    <div class="d-flex gap-2">
        <a href="<?= url('fornecedores') . $qs(['csv' => '1']) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-filetype-csv me-1"></i>Exportar lista (CSV)
        </a>
        <?php if (can(['comprador','admin'])): ?>
        <a href="<?= url('fornecedores/importar/modelo') ?>" class="btn btn-outline-secondary btn-sm"
           title="Planilha modelo (CSV) com as colunas reconhecidas na importação">
            <i class="bi bi-download me-1"></i>Baixar modelo
        </a>
        <a href="<?= url('fornecedores/importar') ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Importar planilha
        </a>
        <a href="<?= url('fornecedores/novo') ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Novo Fornecedor
        </a>
        <?php endif; ?>
    </div>
</div>

<?php
$chipsTxt = [
    'cnd_vencida'  => [fn($n) => $n . ' CND' . ($n > 1 ? 's' : '') . ' vencida' . ($n > 1 ? 's' : ''), 'text-danger'],
    'cnd_vence'    => [fn($n) => $n . ' CND' . ($n > 1 ? 's' : '') . ' vencendo em até ' . Fornecedor::CND_AVISO_DIAS . ' dias', 'text-warning'],
    'cnd_positiva' => [fn($n) => $n . ' com CND positiva', 'text-danger'],
    'sem_regime'   => [fn($n) => $n . ' sem regime tributário', 'text-warning'],
];
?>
<?php if (array_sum($chips) > 0): ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($chipsTxt as $k => [$txt, $cor]): if (!$chips[$k]) continue; $on = $filtros['pend'] === $k; ?>
    <a href="<?= url('fornecedores') . $qs(['pend' => $on ? null : $k]) ?>" class="btn btn-sm <?= $on ? 'btn-primary' : 'btn-outline-secondary' ?>">
        <i class="bi bi-circle-fill me-1 <?= $on ? '' : $cor ?>" style="font-size:.6rem"></i><?= e($txt($chips[$k])) ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <form method="GET" id="frmFiltroForn">
            <div class="row g-2 align-items-end mb-2">
                <div class="col-md-5">
                    <input type="search" name="q" class="form-control form-control-sm" aria-label="Buscar fornecedor"
                           placeholder="Buscar por nome, CNPJ, cidade, e-mail…" value="<?= e($filtros['q']) ?>">
                </div>
                <div class="col-auto">
                    <div class="input-group input-group-sm">
                        <label class="input-group-text" for="fOrd">Ordenar</label>
                        <select name="ord" id="fOrd" class="form-select form-select-sm js-auto">
                            <?php foreach (FL::ORDENS as $k => $rot): ?>
                            <option value="<?= $k ?>" <?= $ordem === $k ? 'selected' : '' ?>><?= e($rot) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Buscar</button>
                    <?php if ($nAtivos): ?>
                    <a href="<?= url('fornecedores') . ($ordem !== 'nome' ? '?ord=' . e($ordem) : '') ?>" class="btn btn-outline-secondary btn-sm">Limpar filtros (<?= $nAtivos ?>)</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="row g-2">
                <?php foreach (FL::campos() as $k => [$rotulo, , $nomes]): ?>
                <div class="col-6 col-md">
                    <label class="form-label small text-muted mb-0" for="f_<?= $k ?>"><?= e($rotulo) ?></label>
                    <select name="<?= $k ?>" id="f_<?= $k ?>" class="form-select form-select-sm js-auto <?= $filtros[$k] !== '' ? 'border-primary' : '' ?>">
                        <option value="">Todos</option>
                        <?php if ($filtros[$k] !== '' && !isset($opcoes[$k][$filtros[$k]])): ?>
                        <option value="<?= e($filtros[$k]) ?>" selected><?= e($filtros[$k] === FL::VAZIO ? '(não informado)' : ($nomes[$filtros[$k]] ?? $filtros[$k])) ?> (0)</option>
                        <?php endif; ?>
                        <?php foreach ($opcoes[$k] as $v => $n): ?>
                        <option value="<?= e($v) ?>" <?= $filtros[$k] === (string)$v ? 'selected' : '' ?>>
                            <?= e($v === FL::VAZIO ? '(não informado)' : ($nomes[$v] ?? $v)) ?> (<?= $n ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
                <div class="col-12 col-md-3">
                    <label class="form-label small text-muted mb-0" for="f_pend">Pendência / grupo</label>
                    <select name="pend" id="f_pend" class="form-select form-select-sm js-auto <?= $filtros['pend'] !== '' ? 'border-primary' : '' ?>">
                        <option value="">Todos</option>
                        <?php foreach (Fornecedor::PENDENCIAS + FL::GRUPOS as $k => $rot):
                            if (!$contPend[$k] && $filtros['pend'] !== $k) continue; ?>
                        <option value="<?= $k ?>" <?= $filtros['pend'] === $k ? 'selected' : '' ?>><?= e($rot) ?> (<?= $contPend[$k] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Fornecedor</th>
                        <th>Regime tributário</th>
                        <th>CND Federal</th>
                        <th class="text-end">OCs 12 meses</th>
                        <th>Avaliação</th>
                        <th>Situação</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$lista): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">
                        <?= $total ? 'Nenhum fornecedor com esses filtros.' : 'Nenhum fornecedor cadastrado.' ?>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($lista as $i => $f):
                    $raiz   = Fornecedor::cnpjRaiz($f['cnpj']);
                    $matriz = Fornecedor::ehMatriz($f['cnpj']);
                    $nGrupo = $raiz !== '' ? ($porRaiz[$raiz] ?? 1) : 1;
                    $filial = $raiz !== '' && !$matriz;
                    $ant    = $lista[$i - 1] ?? null;
                    $noGrupo = $filial && $ant && Fornecedor::cnpjRaiz($ant['cnpj']) === $raiz;
                    $pend   = Fornecedor::pendencias($f, $hoje);
                    $sit    = $f['situacao'] ?? 'pendente';
                    $nota   = $f['ultima_nota'] !== null ? (float)$f['ultima_nota'] : null;
                    $bloq   = $nota !== null && $f['penultima_nota'] !== null
                              && Fornecedor::sugereBloqueio([
                                  ['periodo' => $f['ultimo_periodo'], 'nota' => $f['ultima_nota']],
                                  ['periodo' => $f['penultimo_periodo'], 'nota' => $f['penultima_nota']],
                              ]);
                    $regConf = $f['regime_confirmado_em'] ?? null;
                ?>
                    <tr>
                        <td class="<?= $noGrupo ? 'ps-4' : '' ?>">
                            <?php if ($noGrupo): ?><i class="bi bi-arrow-return-right text-muted me-1"></i><?php endif; ?>
                            <a href="<?= url('fornecedores/' . $f['id']) ?>" class="fw-semibold text-body text-decoration-none"><?= e(FL::nome($f)) ?></a>
                            <?php if ($matriz && $nGrupo > 1): ?>
                                <span class="badge bg-light text-secondary border ms-1">Matriz · <?= $nGrupo - 1 ?> filia<?= $nGrupo - 1 > 1 ? 'is' : 'l' ?></span>
                            <?php elseif ($filial): ?>
                                <span class="badge bg-light text-secondary border ms-1">Filial</span>
                            <?php endif; ?>
                            <div class="small text-muted">
                                <?= e($f['cnpj'] ? formatCnpj($f['cnpj']) : 'sem CNPJ') ?><?= !empty($f['mei']) || $f['regime_tributario'] === 'mei' ? ' · MEI' : '' ?><?= $f['cidade'] ? ' · ' . e($f['cidade']) . ($f['estado'] ? '/' . e($f['estado']) : '') : '' ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($f['regime_tributario']): ?>
                                <?= e(Fornecedor::REGIMES[$f['regime_tributario']] ?? $f['regime_tributario']) ?>
                            <?php else: ?>
                                <span class="text-warning">Não informado</span>
                            <?php endif; ?>
                            <?php if ($regConf): ?>
                            <div class="small text-muted">confirmado <?= date_br($regConf) ?>
                                <?php if (isset($pend['regime_reconfirmar'])): ?><span class="badge bg-warning text-dark">reconfirmar</span><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $cs = $f['cnd_federal_situacao'] ?? null; $cv = $f['cnd_federal_validade'] ?? null; ?>
                            <?php if ($cs): ?>
                                <span class="<?= $cndCor[$cs] ?? '' ?>"><?= e(Fornecedor::CND_SITUACOES[$cs] ?? $cs) ?></span>
                            <?php elseif (!$cv): ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                            <?php if ($cs || $cv): ?>
                            <div class="small">
                                <?php if (!$cv): ?>
                                    <span class="text-muted">validade não informada</span>
                                <?php elseif (isset($pend['cnd_vencida'])): ?>
                                    <span class="text-danger"><b>vencida</b> <?= date_br($cv) ?></span>
                                <?php elseif ($selo = Fornecedor::seloCnd($cv, $hoje)): ?>
                                    <span class="text-warning"><?= e(str_replace('CND ', '', $selo['texto'])) ?> (<?= date_br($cv) ?>)</span>
                                <?php else: ?>
                                    <span class="text-muted">válida até <?= date_br($cv) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap"><?= money($f['oc_12m']) ?></td>
                        <td>
                            <?php if ($nota === null): ?>
                                <span class="text-muted">—</span>
                            <?php else: ?>
                                <b class="<?= Fornecedor::classeNota($nota) ?>"><?= Fornecedor::formatarNota($nota) ?></b>
                                <span class="small text-muted"><?= e(Fornecedor::periodoRotulo($f['ultimo_periodo'])) ?></span>
                                <?php if ($bloq): ?>
                                    <div><span class="badge bg-danger">a política prevê bloqueio</span></div>
                                <?php elseif (Fornecedor::precisaPlanoAcao($nota)): ?>
                                    <div><span class="badge bg-warning text-dark">Plano de ação</span></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $sitPill[$sit] ?? 'bg-secondary' ?>"><?= e(Fornecedor::SITUACOES[$sit] ?? $sit) ?></span>
                            <?php if (!(int)$f['ativo']): ?><span class="badge bg-secondary">Inativo</span><?php endif; ?>
                            <?php if ($pend && $sit !== 'bloqueado'): ?>
                                <div class="small text-muted" title="<?= e(implode('; ', $pend)) ?>"><?= count($pend) ?> pendência<?= count($pend) > 1 ? 's' : '' ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="<?= url('fornecedores/' . $f['id']) ?>" class="btn btn-sm btn-outline-secondary" title="Detalhes"><i class="bi bi-eye"></i></a>
                            <?php if (can(['comprador','admin'])): ?>
                            <a href="<?= url('fornecedores/' . $f['id'] . '/editar') ?>" class="btn btn-sm btn-outline-primary" title="Editar"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<p class="small text-muted mt-2 mb-1"><?= count($lista) ?> de <?= $total ?> fornecedor<?= $total === 1 ? '' : 'es' ?><?= $nAtivos ? ' (filtrado)' : '' ?></p>
<p class="small text-muted"><?= e(Fornecedor::REGRA_AVALIACAO) ?></p>

<script>
document.querySelectorAll('#frmFiltroForn .js-auto').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
});
</script>
