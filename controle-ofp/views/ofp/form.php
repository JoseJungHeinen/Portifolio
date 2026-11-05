<?php
$editar = $ofp !== null;
$v = fn(string $campo) => e($dados[$campo] ?? '');
$invalido = fn(string $campo) => isset($erros[$campo]) ? ' is-invalid' : '';
$feedback = fn(string $campo) => isset($erros[$campo]) ? '<div class="invalid-feedback">' . e($erros[$campo]) . '</div>' : '';
$selecionado = fn(string $campo, string $valor) => (string)($dados[$campo] ?? '') === $valor ? 'selected' : '';
$travado = $editar && App\Services\OfpService::unidadeCausalTravados($ofp);
$opcoesCausal = $causais;
if ($editar && !in_array($ofp['causal_id'], array_column($causais, 'id'), true)) {
    $opcoesCausal[] = ['id' => $ofp['causal_id'], 'codigo' => $ofp['causal_codigo'], 'descricao' => $ofp['causal_descricao'],
        'fotos_obrigatorias' => $ofp['fotos_obrigatorias'], 'prazo_abertura_dias' => $ofp['prazo_abertura_dias'], 'valor_minimo' => null, 'transportadora' => null];
}
?>
<h1 class="titulo h3 mb-3"><?= $editar ? 'Editar <span>OFP ' . e($ofp['numero_ofp'] ?? '') . '</span>' : 'Nova <span>OFP</span>' ?></h1>

<form method="post" action="<?= $editar ? url('/ofps/' . $ofp['id']) : url('/ofps') ?>" enctype="multipart/form-data" class="row g-3">
    <?= csrf_field() ?>
    <div class="col-lg-8">
        <div class="card card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="unidade">Unidade *</label>
                    <select class="form-select<?= $invalido('unidade_id') ?>" id="unidade" <?= $travado ? 'disabled' : 'name="unidade_id" required' ?>>
                        <option value="">Selecione…</option>
                        <?php
                        if ($editar && !in_array($ofp['unidade_id'], array_column($unidades, 'id'), true)) {
                            $unidades[] = ['id' => $ofp['unidade_id'], 'nome' => $ofp['unidade_nome'] ?? 'Unidade atual'];
                        }
                        ?>
                        <?php foreach ($unidades as $u): ?><option value="<?= e($u['id']) ?>" <?= $selecionado('unidade_id', $u['id']) ?>><?= e($u['nome']) ?></option><?php endforeach; ?>
                    </select><?= $travado ? '<input type="hidden" name="unidade_id" value="' . e($ofp['unidade_id']) . '"><div class="form-text">Travada depois da aprovação.</div>' : '' ?><?= $feedback('unidade_id') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="causal">Causal *</label>
                    <select class="form-select<?= $invalido('causal_id') ?>" id="causal" <?= $travado ? 'disabled' : 'name="causal_id" required' ?>>
                        <option value="">Selecione…</option>
                        <?php foreach ($opcoesCausal as $c): ?>
                            <option value="<?= e($c['id']) ?>" data-codigo="<?= e($c['codigo']) ?>" data-fotos="<?= e($c['fotos_obrigatorias'] ?? '') ?>"
                                    data-prazo="<?= e($c['prazo_abertura_dias'] ?? '') ?>" data-minimo="<?= e($c['valor_minimo'] ?? '') ?>"
                                    data-transportadora="<?= e($c['transportadora'] ?? '') ?>" <?= $selecionado('causal_id', $c['id']) ?>><?= e($c['codigo'] . ' — ' . $c['descricao']) ?></option>
                        <?php endforeach; ?>
                    </select><?= $travado ? '<input type="hidden" name="causal_id" value="' . e($ofp['causal_id']) . '"><div class="form-text">Travado depois da aprovação.</div>' : '' ?><?= $feedback('causal_id') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="recebimento">Recebimento do CTe (D) *</label>
                    <input type="date" class="form-control<?= $invalido('data_recebimento') ?>" id="recebimento" name="data_recebimento" value="<?= $v('data_recebimento') ?>" max="<?= date('Y-m-d') ?>"><?= $feedback('data_recebimento') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="transportadora">Transportadora</label>
                    <select class="form-select" id="transportadora" name="transportadora">
                        <option value="">—</option>
                        <?php foreach (['azul' => 'Azul', 'jat' => 'JAT', 'outra' => 'Outra'] as $chave => $rotulo): ?><option value="<?= $chave ?>" <?= $selecionado('transportadora', $chave) ?>><?= $rotulo ?></option><?php endforeach; ?>
                    </select>
                </div>
                <?php if (!$editar): ?>
                    <div class="col-md-4">
                        <label class="form-label" for="numero">Nº OFP (se já aberta no CSPS)</label>
                        <input class="form-control<?= $invalido('numero_ofp') ?>" id="numero" name="numero_ofp" inputmode="numeric" value="<?= $v('numero_ofp') ?>"><?= $feedback('numero_ofp') ?>
                    </div>
                <?php endif; ?>
                <div class="col-md-4">
                    <label class="form-label" for="desenho">Desenho *</label>
                    <input class="form-control<?= $invalido('desenho') ?>" id="desenho" name="desenho" value="<?= $v('desenho') ?>" required><?= $feedback('desenho') ?>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="descricao">Descrição da peça</label>
                    <input class="form-control" id="descricao" name="descricao_peca" value="<?= $v('descricao_peca') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="quantidade">Quantidade *</label>
                    <input class="form-control<?= $invalido('quantidade') ?>" id="quantidade" name="quantidade" inputmode="decimal" value="<?= $v('quantidade') !== '' ? $v('quantidade') : '1' ?>"><?= $feedback('quantidade') ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="valor">Valor reclamado (R$) *</label>
                    <input class="form-control<?= $invalido('valor_reclamado') ?>" id="valor" name="valor_reclamado" inputmode="decimal" value="<?= $v('valor_reclamado') ?>"><?= $feedback('valor_reclamado') ?>
                </div>
                <div class="col-md-3"><label class="form-label" for="nf">NF de origem</label><input class="form-control" id="nf" name="nf_origem" value="<?= $v('nf_origem') ?>"></div>
                <div class="col-md-1"><label class="form-label" for="serie">Série</label><input class="form-control" id="serie" name="serie_origem" value="<?= $v('serie_origem') ?>"></div>
                <div class="col-md-2"><label class="form-label" for="volume">Nº volume</label><input class="form-control" id="volume" name="numero_volume" value="<?= $v('numero_volume') ?>"></div>
                <div class="col-md-6">
                    <label class="form-label" for="responsavel">Responsável</label>
                    <select class="form-select" id="responsavel" name="responsavel_id">
                        <option value=""><?= $editar ? 'Manter atual' : 'Eu mesmo' ?></option>
                        <?php foreach ($responsaveis as $r): ?><option value="<?= e($r['id']) ?>" <?= $selecionado('responsavel_id', $r['id']) ?>><?= e($r['nome'] . ' (' . $r['perfil'] . ')') ?></option><?php endforeach; ?>
                    </select>
                </div>
                <?php if ($editar && $ofp['status'] === 'aguardando_coleta'): ?>
                    <div class="col-md-6">
                        <label class="form-label" for="coleta-data">Data da solicitação de coleta</label>
                        <input type="date" class="form-control<?= $invalido('data_coleta_solicitada') ?>" id="coleta-data" name="data_coleta_solicitada" value="<?= e(substr((string)($dados['data_coleta_solicitada'] ?? ''), 0, 10)) ?>" max="<?= date('Y-m-d') ?>"><?= $feedback('data_coleta_solicitada') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="coleta-rastreio">Código de rastreio</label>
                        <input class="form-control<?= $invalido('codigo_rastreio') ?>" id="coleta-rastreio" name="codigo_rastreio" maxlength="40" value="<?= $v('codigo_rastreio') ?>"><?= $feedback('codigo_rastreio') ?>
                    </div>
                <?php endif; ?>
                <div class="col-12"><label class="form-label" for="obs">Observação</label><textarea class="form-control" id="obs" name="observacao" rows="2"><?= $v('observacao') ?></textarea></div>
                <?php if (!$editar): ?>
                    <div class="col-12">
                        <label class="form-label" for="fotos">Fotos e documentos (JPG, PNG, PDF — até 10 MB cada)</label>
                        <input class="form-control" type="file" id="fotos" name="fotos[]" multiple accept=".jpg,.jpeg,.png,.pdf">
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card card-body">
            <h2 class="h6">Regras do causal</h2>
            <p class="text-muted small mb-0" id="regras-vazio">Selecione o causal para ver prazo e fotos obrigatórias.</p>
            <div id="regras-conteudo" class="d-none small">
                <p class="mb-1"><strong>Prazo de abertura:</strong> <span id="regra-prazo"></span></p>
                <p class="mb-1" id="regra-minimo-linha"><strong>Valor mínimo:</strong> <span id="regra-minimo"></span></p>
                <p class="mb-1"><strong>Fotos obrigatórias:</strong></p>
                <p class="mb-2" id="regra-fotos"></p>
                <div class="alert alert-warning py-2 mb-0 d-none" id="regra-alerta"></div>
            </div>
        </div>
        <div class="d-grid gap-2 mt-3">
            <button class="btn btn-primary" type="submit"><?= $editar ? 'Salvar alterações' : 'Registrar OFP' ?></button>
            <a class="btn btn-outline-secondary" href="<?= $editar ? url('/ofps/' . $ofp['id']) : url('/ofps') ?>">Cancelar</a>
        </div>
    </div>
</form>

<script>
(() => {
    const causal = document.getElementById('causal');
    const receb = document.getElementById('recebimento');
    const transp = document.getElementById('transportadora');
    const valor = document.getElementById('valor');
    const numero = txt => txt.includes(',') ? Number(txt.replace(/\./g, '').replace(',', '.')) : Number(txt);

    function atualizar() {
        const op = causal.selectedOptions[0];
        const tem = Boolean(op && op.value);
        document.getElementById('regras-vazio').classList.toggle('d-none', tem);
        document.getElementById('regras-conteudo').classList.toggle('d-none', !tem);
        if (!tem) return;

        const { prazo, minimo, transportadora, codigo, fotos } = op.dataset;
        const alertas = [];
        let textoPrazo = prazo === '' ? 'sem prazo (sinistro)' : `D+${prazo} dias corridos`;
        if (prazo !== '' && receb.value) {
            const limite = new Date(receb.value + 'T00:00:00Z');
            limite.setUTCDate(limite.getUTCDate() + Number(prazo));
            textoPrazo += ` — até ${limite.toLocaleDateString('pt-BR', { timeZone: 'UTC' })}`;
            const hoje = new Date();
            if (Date.UTC(hoje.getFullYear(), hoje.getMonth(), hoje.getDate()) > limite.getTime()) {
                alertas.push('Prazo de abertura já venceu. A Fabricante pode rejeitar a OFP.');
            }
        }
        document.getElementById('regra-prazo').textContent = textoPrazo;
        document.getElementById('regra-minimo-linha').classList.toggle('d-none', minimo === '');
        document.getElementById('regra-minimo').textContent = minimo === '' ? '' : Number(minimo).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        document.getElementById('regra-fotos').textContent = fotos || 'Nenhuma foto obrigatória.';
        if (transportadora && transp.value && transp.value !== transportadora) {
            alertas.push(`Causal ${codigo} é da transportadora ${transportadora.toUpperCase()}.`);
        }
        const v = numero(valor.value || '0');
        if (minimo !== '' && v > 0 && v < Number(minimo)) alertas.push('Valor abaixo do mínimo do causal.');

        const caixa = document.getElementById('regra-alerta');
        caixa.classList.toggle('d-none', alertas.length === 0);
        caixa.replaceChildren(...alertas.map(a => Object.assign(document.createElement('div'), { textContent: a })));
    }
    [causal, receb, transp, valor].forEach(el => el.addEventListener('input', atualizar));
    atualizar();
})();
</script>
