<?php
// @versao Ver.002.301026
use App\Models\Fornecedor;
use App\Services\ImportacaoFornecedoresService as Imp;
use App\Services\PlanilhaReader;

$title = 'Importar fornecedores';
$tipoPill = ['novo' => 'bg-success', 'atualizar' => 'bg-primary', 'sem_mudanca' => 'bg-secondary',
             'ignorado' => 'bg-light text-dark border', 'erro' => 'bg-danger'];
$valor = function (string $campo, mixed $v): string {
    if ($v === null || $v === '') return '—';
    return match ($campo) {
        'regime_tributario'    => Fornecedor::REGIMES[$v] ?? (string)$v,
        'cnd_federal_situacao' => Fornecedor::CND_SITUACOES[$v] ?? (string)$v,
        'situacao'             => Fornecedor::SITUACOES[$v] ?? (string)$v,
        'mei'                  => (int)$v ? 'Sim' : 'Não',
        'regime_confirmado_em', 'cnd_federal_validade' => date_br((string)$v),
        default                => mb_strimwidth((string)$v, 0, 120, '…'),
    };
};
?>
<div class="d-flex align-items-center gap-3 mb-3">
    <a href="<?= url('fornecedores') ?>" class="btn btn-outline-secondary btn-sm" aria-label="Voltar"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-spreadsheet me-2 text-primary"></i>Importar fornecedores de planilha</h4>
</div>

<?php if ($erro): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= e($erro) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <p class="text-muted small mb-3">
            Aceita <b>.xlsx</b> ou <b>.csv</b> (até 2 MB) com cabeçalho. Colunas reconhecidas, em qualquer ordem:
            <?= e(implode(', ', PlanilhaReader::cabecalhoModelo())) ?>.
            O CNPJ é a chave: fornecedor já cadastrado não é duplicado. Células vazias não apagam o que já está no cadastro.
            Situação: "Autorizado" vira Homologado; "Não autorizado" ou Status "Inativo" vira Bloqueado; "Ressalva" e vazio, Pendente.
            <a href="<?= url('fornecedores/importar/modelo') ?>"><i class="bi bi-download"></i> Baixar modelo</a>
        </p>
        <form method="POST" action="<?= url('fornecedores/importar') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label fw-semibold" for="impArquivo">Planilha *</label>
                    <input type="file" name="arquivo" id="impArquivo" class="form-control" required
                           accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="impConf">Data de confirmação do regime *</label>
                    <input type="date" name="confirmacao" id="impConf" class="form-control" required
                           value="<?= e($confirmacao) ?>" max="<?= e($hoje) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="impModo">Fornecedores já cadastrados</label>
                    <select name="modo" id="impModo" class="form-select">
                        <option value="atualizar" <?= $modo === 'atualizar' ? 'selected' : '' ?>>Atualizar com os dados da planilha</option>
                        <option value="manter" <?= $modo === 'manter' ? 'selected' : '' ?>>Manter como estão (não alterar)</option>
                    </select>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-eye me-1"></i>Ver prévia</button>
                <span class="text-muted small ms-2">Nada é gravado até você confirmar.</span>
            </div>
        </form>
    </div>
</div>

<?php if ($previa): $t = $previa['totais']; $gravar = $t['novo'] + $t['atualizar']; ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <b>Prévia</b> — <?= e($previa['arquivo']) ?>, aba "<?= e($previa['aba']) ?>":
                <?= (int)$t['linhas'] ?> linha<?= $t['linhas'] === 1 ? '' : 's' ?>
            </div>
            <div class="d-flex flex-wrap gap-2 small">
                <?php foreach (Imp::TIPOS as $k => $rot): ?>
                <span class="badge <?= $tipoPill[$k] ?>"><?= e($rot) ?>: <?= (int)$t[$k] ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="table-responsive" style="max-height:60vh">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light" style="position:sticky;top:0">
                <tr><th>Linha</th><th>Fornecedor</th><th>Resultado</th><th>Regime</th><th>Detalhes</th></tr>
            </thead>
            <tbody>
            <?php foreach ($previa['itens'] as $it): ?>
                <tr class="<?= $it['tipo'] === 'erro' ? 'table-danger' : '' ?>">
                    <td class="text-muted small"><?= (int)$it['linha'] ?></td>
                    <td>
                        <div class="fw-semibold"><?= e($it['fantasia'] ?: ($it['razao'] ?: '—')) ?></div>
                        <div class="small text-muted"><?= e($it['cnpj'] !== '' ? Imp::formatarCnpj($it['cnpj']) : $it['cnpj_bruto']) ?>
                            <?= $it['fantasia'] && $it['razao'] ? ' · ' . e($it['razao']) : '' ?></div>
                    </td>
                    <td><span class="badge <?= $tipoPill[$it['tipo']] ?>"><?= e(Imp::TIPOS[$it['tipo']]) ?></span></td>
                    <td class="small"><?= e($it['regime'] ? (Fornecedor::REGIMES[$it['regime']] ?? $it['regime']) : '—') ?><?= $it['herdou'] ? ' <span class="text-muted">(herdado)</span>' : '' ?></td>
                    <td class="small">
                        <?php foreach ($it['erros'] as $m): ?>
                        <div class="text-danger"><i class="bi bi-x-circle me-1"></i><?= e($m) ?></div>
                        <?php endforeach; ?>
                        <?php foreach ($it['mudancas'] as $campo => $m): ?>
                        <div><b><?= e(Imp::ROTULOS[$campo] ?? $campo) ?>:</b> <?= e($valor($campo, $m['antes'])) ?> → <?= e($valor($campo, $m['depois'])) ?></div>
                        <?php endforeach; ?>
                        <?php if ($it['tipo'] === 'novo' && ($it['dados']['situacao'] ?? 'pendente') !== 'pendente'): ?>
                        <div><b>Situação:</b> <?= e($valor('situacao', $it['dados']['situacao'])) ?></div>
                        <?php endif; ?>
                        <?php if ($it['tipo'] === 'ignorado'): ?>
                        <div class="text-muted">Já cadastrado — mantido como está.</div>
                        <?php endif; ?>
                        <?php foreach ($it['avisos'] as $m): ?>
                        <div class="text-warning-emphasis"><i class="bi bi-exclamation-circle me-1"></i><?= e($m) ?></div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white">
        <?php if ($gravar > 0): ?>
        <form method="POST" action="<?= url('fornecedores/importar/confirmar') ?>" class="d-flex flex-wrap align-items-center gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="hash" value="<?= e($previa['hash']) ?>">
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check2 me-1"></i>Importar: <?= (int)$t['novo'] ?> novo<?= $t['novo'] === 1 ? '' : 's' ?>
                e <?= (int)$t['atualizar'] ?> atualização<?= $t['atualizar'] === 1 ? '' : 'ões' ?>
            </button>
            <?php if ($t['erro']): ?>
            <span class="text-danger small"><?= (int)$t['erro'] ?> linha<?= $t['erro'] === 1 ? '' : 's' ?> com erro fica<?= $t['erro'] === 1 ? '' : 'm' ?> de fora.</span>
            <?php endif; ?>
        </form>
        <?php else: ?>
        <span class="text-muted">Nada a importar com essas opções.</span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
