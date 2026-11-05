<?php
// @versao Ver.001.281026
?>
<?php $title = 'Entrada de Estoque'; ?>
<meta name="filial-id" content="<?= e(auth()['filial_id'] ?? '') ?>">
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('estoque') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi bi-plus-circle me-2 text-success"></i>Entrada de Estoque</h4>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="<?= url('estoque/entrada') ?>" id="formEntrada">
                    <?= csrf_field() ?>

                    <?php if (can(['diretor','admin'])): ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Filial *</label>
                        <select name="filial_id" class="form-select" required>
                            <?php foreach ($filiais as $f): ?>
                                <option value="<?= $f['id'] ?>" <?= $f['id'] === auth()['filial_id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- Itens -->
                    <div class="mb-1">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="form-label fw-semibold mb-0">Itens *</label>
                            <select class="form-select form-select-sm w-auto" id="filtroGrupo" style="min-width:160px">
                                <option value="">Todos os grupos</option>
                                <?php foreach ($grupos as $g): ?>
                                    <option value="<?= e($g['id']) ?>"><?= e($g['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="linhasItens"></div>

                        <button type="button" class="btn btn-outline-success btn-sm mt-2 w-100" id="btnAdicionarItem">
                            <i class="bi bi-plus-lg me-1"></i>Adicionar Item
                        </button>
                    </div>

                    <hr class="my-3">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Motivo *</label>
                        <select name="motivo" class="form-select" id="motivoEntrada" required>
                            <option value="">Selecione o motivo...</option>
                            <option value="compra">Compra / Recebimento de NF</option>
                            <option value="transferencia">Transferência entre filiais</option>
                            <option value="inventario">Inventário</option>
                            <option value="correcao">Correção de saldo</option>
                            <option value="outro">Outro</option>
                        </select>
                    </div>

                    <div class="mb-3" id="justEntradaWrap" style="display:none">
                        <label class="form-label fw-semibold">Justificativa *</label>
                        <textarea name="justificativa" id="justEntrada" class="form-control" rows="2"
                                  placeholder="Descreva o motivo desta movimentação..."></textarea>
                        <div class="form-text">Obrigatória para Inventário, Correção e Outro.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-lg me-1"></i>Confirmar Entrada
                        </button>
                        <a href="<?= url('estoque') ?>" class="btn btn-outline-secondary">Cancelar</a>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>

<style>
.item-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 1050;
    display: none;
    background: #fff;
    border: 1px solid #dee2e6;
    border-top: none;
    border-radius: 0 0 .375rem .375rem;
    max-height: 200px;
    overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,.1);
}
.item-dropdown .item-opt {
    padding: .4rem .75rem;
    cursor: pointer;
    font-size: .875rem;
    border-bottom: 1px solid #f0f0f0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.item-dropdown .item-opt:hover,
.item-dropdown .item-opt.active {
    background: #e8f5e9;
}
.item-dropdown .item-opt.no-result {
    color: #aaa;
    cursor: default;
    font-style: italic;
}
.item-search-wrap {
    position: relative;
    flex: 1 1 auto;
    min-width: 0;
}
.item-search-wrap .form-control {
    border-radius: .375rem 0 0 .375rem;
}
</style>

<script>
const todosItens = <?= json_encode(array_map(fn($i) => [
    'id'       => $i['id'],
    'nome'     => $i['nome'],
    'unidade'  => $i['unidade'],
    'grupo_id' => $i['grupo_produto_id'] ?? '',
], $itens), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

const estoqueMap = <?= json_encode($estoqueMap ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

function getFilialAtual() {
    const sel = document.querySelector('select[name="filial_id"]');
    if (sel) return sel.value;
    // para perfis sem seletor de filial, pega a filial do usuário via meta
    return document.querySelector('meta[name="filial-id"]')?.content ?? '';
}

function qtdAtual(itemId) {
    const filial = getFilialAtual();
    return (estoqueMap[filial] && estoqueMap[filial][itemId] !== undefined)
        ? estoqueMap[filial][itemId]
        : 0;
}

function fmtQtd(n) {
    return Number(n).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

const filtroGrupo  = document.getElementById('filtroGrupo');
const linhasItens  = document.getElementById('linhasItens');
const btnAdicionar = document.getElementById('btnAdicionarItem');

function itensPorFiltro(busca) {
    const grupo = filtroGrupo.value;
    const termo = (busca || '').toLowerCase().trim();
    return todosItens.filter(it => {
        if (grupo && it.grupo_id !== grupo) return false;
        if (termo && !it.nome.toLowerCase().includes(termo)) return false;
        return true;
    });
}

function adicionarLinha() {
    const row = document.createElement('div');
    row.className = 'input-group mb-2 item-row';

    // Wrapper com dropdown
    const wrap = document.createElement('div');
    wrap.className = 'item-search-wrap';

    const searchInp = document.createElement('input');
    searchInp.type        = 'text';
    searchInp.className   = 'form-control';
    searchInp.placeholder = 'Buscar item...';
    searchInp.autocomplete = 'off';

    const hiddenInp = document.createElement('input');
    hiddenInp.type  = 'hidden';
    hiddenInp.name  = 'item_id[]';
    hiddenInp.required = true;

    const dropdown = document.createElement('div');
    dropdown.className = 'item-dropdown';

    wrap.appendChild(searchInp);
    wrap.appendChild(hiddenInp);
    wrap.appendChild(dropdown);

    // Quantidade
    const qtdInp = document.createElement('input');
    qtdInp.type        = 'text';
    qtdInp.name        = 'quantidade[]';
    qtdInp.className   = 'form-control';
    qtdInp.placeholder = 'Qtd';
    qtdInp.style.maxWidth = '90px';
    qtdInp.required    = true;

    // Remover
    const rmBtn = document.createElement('button');
    rmBtn.type      = 'button';
    rmBtn.className = 'btn btn-outline-danger';
    rmBtn.title     = 'Remover';
    rmBtn.innerHTML = '<i class="bi bi-trash3"></i>';
    rmBtn.onclick   = () => row.remove();

    // Badge de estoque atual / final
    const stockInfo = document.createElement('div');
    stockInfo.className = 'stock-info w-100 mt-1 mb-1';
    stockInfo.style.display = 'none';

    row.appendChild(wrap);
    row.appendChild(qtdInp);
    row.appendChild(rmBtn);

    // Wrapper para agrupar linha + info de estoque
    const rowWrap = document.createElement('div');
    rowWrap.appendChild(row);
    rowWrap.appendChild(stockInfo);
    linhasItens.appendChild(rowWrap);

    function atualizarStockInfo() {
        const itemId = hiddenInp.value;
        if (!itemId) { stockInfo.style.display = 'none'; return; }
        const atual   = qtdAtual(itemId);
        const entrada = parseFloat((qtdInp.value || '0').replace(',', '.')) || 0;
        const final   = atual + entrada;
        const item    = todosItens.find(i => i.id === itemId);
        const un      = item ? item.unidade : '';
        stockInfo.innerHTML =
            `<span class="badge bg-secondary me-1">Atual: ${fmtQtd(atual)} ${un}</span>` +
            `<i class="bi bi-plus-lg text-muted" style="font-size:.7rem"></i>` +
            `<span class="badge bg-primary mx-1">Entrada: ${fmtQtd(entrada)} ${un}</span>` +
            `<i class="bi bi-equals text-muted" style="font-size:.7rem"></i>` +
            `<span class="badge bg-success ms-1">Final: ${fmtQtd(final)} ${un}</span>`;
        stockInfo.style.display = '';
    }

    // â”€â”€ Lógica do dropdown â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    function abrirDropdown(busca) {
        const lista = itensPorFiltro(busca);
        dropdown.innerHTML = '';
        if (lista.length === 0) {
            const d = document.createElement('div');
            d.className = 'item-opt no-result';
            d.textContent = 'Nenhum item encontrado';
            dropdown.appendChild(d);
        } else {
            lista.forEach(it => {
                const d = document.createElement('div');
                d.className   = 'item-opt';
                d.textContent = it.nome + ' (' + it.unidade + ')';
                d.dataset.id  = it.id;
                d.dataset.nome = it.nome;
                d.addEventListener('mousedown', e => {
                    e.preventDefault();
                    selecionarItem(it.id, it.nome + ' (' + it.unidade + ')');
                });
                dropdown.appendChild(d);
            });
        }
        dropdown.style.display = 'block';
    }

    function fecharDropdown() {
        dropdown.style.display = 'none';
    }

    function selecionarItem(id, label) {
        hiddenInp.value  = id;
        searchInp.value  = label;
        searchInp.dataset.selectedId = id;
        fecharDropdown();
        atualizarStockInfo();
        qtdInp.focus();
    }

    searchInp.addEventListener('focus', () => abrirDropdown(searchInp.value));
    searchInp.addEventListener('input', () => {
        hiddenInp.value = '';
        delete searchInp.dataset.selectedId;
        stockInfo.style.display = 'none';
        abrirDropdown(searchInp.value);
    });
    searchInp.addEventListener('blur', () => {
        setTimeout(fecharDropdown, 150);
        if (!hiddenInp.value) searchInp.value = '';
    });

    qtdInp.addEventListener('input', atualizarStockInfo);

    searchInp.focus();
}

// Quando o grupo muda, limpa seleções que não pertencem mais ao filtro
filtroGrupo.addEventListener('change', () => {
    const grupoSel = filtroGrupo.value;
    linhasItens.querySelectorAll('.item-row').forEach(row => {
        const hid = row.querySelector('input[type=hidden]');
        const txt = row.querySelector('input[type=text]');
        if (!hid || !hid.value) return;
        const item = todosItens.find(i => i.id === hid.value);
        if (item && grupoSel && item.grupo_id !== grupoSel) {
            hid.value = '';
            txt.value = '';
        }
    });
});


// Quando a filial muda (admin/diretor), recalcula todos os badges de estoque
const filialSel = document.querySelector('select[name="filial_id"]');
if (filialSel) {
    filialSel.addEventListener('change', () => {
        linhasItens.querySelectorAll('.stock-info').forEach(info => {
            // Recupera item_id da linha pai
            const hid = info.previousElementSibling?.querySelector('input[type=hidden]');
            if (!hid || !hid.value) return;
            const qtdEl = info.previousElementSibling?.querySelector('input[name="quantidade[]"]');
            const atual   = (estoqueMap[filialSel.value] ?? {})[hid.value] ?? 0;
            const entrada = parseFloat((qtdEl?.value || '0').replace(',', '.')) || 0;
            const final   = atual + entrada;
            const item    = todosItens.find(i => i.id === hid.value);
            const un      = item ? item.unidade : '';
            info.innerHTML =
                `<span class="badge bg-secondary me-1">Atual: ${fmtQtd(atual)} ${un}</span>` +
                `<i class="bi bi-plus-lg text-muted" style="font-size:.7rem"></i>` +
                `<span class="badge bg-primary mx-1">Entrada: ${fmtQtd(entrada)} ${un}</span>` +
                `<i class="bi bi-equals text-muted" style="font-size:.7rem"></i>` +
                `<span class="badge bg-success ms-1">Final: ${fmtQtd(final)} ${un}</span>`;
        });
    });
}
btnAdicionar.addEventListener('click', adicionarLinha);

// Motivo / Justificativa
const motivoSel    = document.getElementById('motivoEntrada');
const justWrap     = document.getElementById('justEntradaWrap');
const justField    = document.getElementById('justEntrada');
const obrigMotivos = ['inventario', 'correcao', 'outro'];
motivoSel.addEventListener('change', function () {
    const show = obrigMotivos.includes(this.value);
    justWrap.style.display = show ? '' : 'none';
    justField.required     = show;
});

// Validação antes de enviar
document.getElementById('formEntrada').addEventListener('submit', function (e) {
    const hiddens = linhasItens.querySelectorAll('input[name="item_id[]"]');
    if (hiddens.length === 0) {
        e.preventDefault(); alert('Adicione ao menos um item.'); return;
    }
    const vistos = [];
    let dup = false;
    hiddens.forEach(h => {
        if (!h.value) { e.preventDefault(); alert('Selecione um item em todas as linhas.'); dup = true; return; }
        if (vistos.includes(h.value)) dup = true;
        vistos.push(h.value);
    });
    if (dup && !e.defaultPrevented) {
        e.preventDefault(); alert('Existem itens repetidos. Cada item deve aparecer apenas uma vez.');
    }
});

// Inicia com uma linha
adicionarLinha();
</script>


