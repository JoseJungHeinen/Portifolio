<?php $title = 'Parâmetros do Sistema'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<?php
$paramMap = [];
foreach ($params as $p) { $paramMap[$p['chave']] = $p; }

$grupos = [
    [
        'titulo' => 'Cotação',
        'icon'   => 'bi-clipboard2-data',
        'cor'    => 'primary',
        'desc'   => 'Regras para abertura e processamento de cotações.',
        'itens'  => [
            ['chave' => 'cotacao.minimo_fornecedores', 'label' => 'Mínimo de fornecedores por cotação',
             'tipo' => 'number', 'min' => 1, 'max' => 10, 'sufixo' => 'fornecedores'],
            ['chave' => 'cotacao.prazo_resposta_horas', 'label' => 'Prazo padrão para resposta',
             'tipo' => 'number', 'min' => 1, 'max' => 720, 'sufixo' => 'horas'],
            ['chave' => 'cotacao.valor_minimo_oc', 'label' => 'Valor mínimo para exigir cotação',
             'tipo' => 'currency', 'min' => 0, 'sufixo' => 'R$'],
        ],
    ],
    [
        'titulo' => 'Login e Segurança',
        'icon'   => 'bi-shield-lock',
        'cor'    => 'danger',
        'desc'   => 'Proteção contra acesso indevido e bloqueio automático.',
        'itens'  => [
            ['chave' => 'login.max_tentativas', 'label' => 'Tentativas antes de bloquear a conta',
             'tipo' => 'number', 'min' => 1, 'max' => 20, 'sufixo' => 'tentativas'],
            ['chave' => 'login.bloqueio_minutos', 'label' => 'Tempo de bloqueio após falhas consecutivas',
             'tipo' => 'number', 'min' => 1, 'max' => 1440, 'sufixo' => 'minutos'],
        ],
    ],
    [
        'titulo' => 'Estoque',
        'icon'   => 'bi-boxes',
        'cor'    => 'warning',
        'desc'   => 'Alertas de nível crítico de estoque.',
        'itens'  => [
            ['chave' => 'estoque.alerta_minimo_pct', 'label' => 'Alertar quando estoque abaixo de X% do mínimo',
             'tipo' => 'percent', 'min' => 0, 'max' => 100, 'sufixo' => '%'],
        ],
    ],
    [
        'titulo' => 'Curva ABC',
        'icon'   => 'bi-bar-chart-steps',
        'cor'    => 'info',
        'desc'   => 'Percentuais acumulados de valor para classificação ABC.',
        'itens'  => [
            ['chave' => 'curva_abc.percentual_a', 'label' => 'Limite acumulado para curva A',
             'tipo' => 'percent', 'min' => 1, 'max' => 99, 'sufixo' => '%'],
            ['chave' => 'curva_abc.percentual_b', 'label' => 'Limite acumulado para curva B',
             'tipo' => 'percent', 'min' => 1, 'max' => 99, 'sufixo' => '%'],
        ],
    ],
    [
        'titulo' => 'Orçamento',
        'icon'   => 'bi-calculator',
        'cor'    => 'success',
        'desc'   => 'Controle de budget e limites de despesas.',
        'itens'  => [
            ['chave' => 'orcamento.acumula_saldo', 'label' => 'Saldo não utilizado acumula para o mês seguinte',
             'tipo' => 'toggle'],
            ['chave' => 'orcamento.eventual_limite_pct', 'label' => 'Limite máximo para despesas eventuais',
             'tipo' => 'percent', 'min' => 0, 'max' => 100, 'sufixo' => '%'],
            ['chave' => 'budget.alerta_consumo_pct', 'label' => 'Alertar quando budget consumido acima de X%',
             'tipo' => 'percent', 'min' => 0, 'max' => 100, 'sufixo' => '%'],
        ],
    ],
    [
        'titulo' => 'Contratos',
        'icon'   => 'bi-file-text',
        'cor'    => 'secondary',
        'desc'   => 'Avisos de vencimento de contratos com fornecedores.',
        'itens'  => [
            ['chave' => 'contrato.alerta_vencimento_dias', 'label' => 'Alertar X dias antes do vencimento',
             'tipo' => 'number', 'min' => 1, 'max' => 180, 'sufixo' => 'dias'],
        ],
    ],
    [
        'titulo' => 'Nota Fiscal',
        'icon'   => 'bi-file-earmark-zip',
        'cor'    => 'dark',
        'desc'   => 'Tolerâncias na conferência de notas fiscais.',
        'itens'  => [
            ['chave' => 'nf.tolerancia_divergencia_pct', 'label' => 'Tolerância de divergência NF vs OC',
             'tipo' => 'decimal', 'min' => 0, 'max' => 10, 'step' => '0.01', 'sufixo' => '%'],
        ],
    ],
];
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-sliders me-2 text-primary"></i>Parâmetros do Sistema</h4>
</div>

<form method="POST" action="<?= url('configuracoes/parametros') ?>" id="formParams">
    <?= csrf_field() ?>
<div class="row g-4">
    <?php foreach ($grupos as $grupo): ?>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom d-flex align-items-center gap-2">
                <span class="badge bg-<?= $grupo['cor'] ?> p-2">
                    <i class="bi <?= $grupo['icon'] ?>"></i>
                </span>
                <div>
                    <div class="fw-semibold"><?= $grupo['titulo'] ?></div>
                    <div class="text-muted" style="font-size:.78rem"><?= $grupo['desc'] ?></div>
                </div>
            </div>
            <div class="card-body">
                <?php foreach ($grupo['itens'] as $item):
                    $p = $paramMap[$item['chave']] ?? null;
                    if (!$p) continue;
                    $val = $p['valor'];
                ?>
                <input type="hidden" name="chave[]" value="<?= e($item['chave']) ?>">
                <div class="mb-3">
                    <label class="form-label fw-semibold small"><?= $item['label'] ?></label>

                    <?php if ($item['tipo'] === 'toggle'): ?>
                        <div class="d-flex align-items-center gap-3">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input toggle-param" type="checkbox"
                                       id="toggle_<?= e($item['chave']) ?>"
                                       data-target="hidden_<?= e($item['chave']) ?>"
                                       <?= $val ? 'checked' : '' ?>>
                                <label class="form-check-label" for="toggle_<?= e($item['chave']) ?>">
                                    <?= $val ? 'Sim' : 'Não' ?>
                                </label>
                            </div>
                            <input type="hidden" name="valor[]"
                                   id="hidden_<?= e($item['chave']) ?>"
                                   value="<?= $val ?>">
                        </div>

                    <?php elseif ($item['tipo'] === 'currency'): ?>
                        <div class="input-group">
                            <span class="input-group-text">R$</span>
                            <input type="number" name="valor[]" class="form-control"
                                   value="<?= e($val) ?>"
                                   min="<?= $item['min'] ?? 0 ?>" step="0.01">
                        </div>

                    <?php else: ?>
                        <div class="input-group">
                            <input type="number" name="valor[]" class="form-control"
                                   value="<?= e($val) ?>"
                                   min="<?= $item['min'] ?? 0 ?>"
                                   max="<?= $item['max'] ?? '' ?>"
                                   step="<?= $item['step'] ?? '1' ?>">
                            <span class="input-group-text text-muted"><?= $item['sufixo'] ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($p['descricao'])): ?>
                    <div class="form-text"><?= e($p['descricao']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="mt-4 d-flex gap-2 align-items-center">
    <button type="submit" class="btn btn-primary px-4">
        <i class="bi bi-floppy me-1"></i>Salvar Parâmetros
    </button>
</div>
</form>

<script>
document.querySelectorAll('.toggle-param').forEach(toggle => {
    toggle.addEventListener('change', function () {
        const hidden = document.getElementById(this.dataset.target);
        hidden.value = this.checked ? 1 : 0;
        this.nextElementSibling.textContent = this.checked ? 'Sim' : 'Não';
    });
});
</script>