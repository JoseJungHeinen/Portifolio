<?php
// @versao Ver.005.281026
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-clipboard-check me-2 text-primary"></i>Cotações</h4>
    <div class="d-flex align-items-center gap-2">
        <label class="small text-muted mb-0 text-nowrap">Status</label>
        <select id="filtroStatusCotacao" class="form-select form-select-sm" style="min-width:190px">
            <option value=""                     <?= ($statusFiltro ?? '') === ''                     ? 'selected' : '' ?>>Ativas</option>
            <option value="aberta"               <?= ($statusFiltro ?? '') === 'aberta'               ? 'selected' : '' ?>>Aberta</option>
            <option value="aguardando_respostas" <?= ($statusFiltro ?? '') === 'aguardando_respostas' ? 'selected' : '' ?>>Aguardando respostas</option>
            <option value="encerrada"            <?= ($statusFiltro ?? '') === 'encerrada'            ? 'selected' : '' ?>>Encerrada</option>
            <option value="cancelada"            <?= ($statusFiltro ?? '') === 'cancelada'            ? 'selected' : '' ?>>Cancelada</option>
        </select>
        <button class="btn btn-primary btn-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#modalNovaCotacao">
            <i class="bi bi-plus-lg me-1"></i>Nova Cotação
        </button>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Filial</th>
                        <th>Solicitante</th>
                        <th>Material / Grupo</th>
                        <th>Status</th>
                        <th>Respostas</th>
                        <th>Prazo</th>
                        <th>Criado em</th>
                        <th class="text-end" style="min-width:180px">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cotacoes)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma cotação encontrada.</td></tr>
                    <?php else: ?>
                    <?php foreach ($cotacoes as $c): ?>
                    <tr>
                        <td><code><?= substr($c['id'], 0, 8) ?></code></td>
                        <td><?= e($c['filial_nome']) ?></td>
                        <td><?= e($c['solicitante_nome']) ?></td>
                        <td class="small text-muted"><?= e($c['grupo_nome'] ?? '—') ?></td>
                        <td><?= status_badge($c['status']) ?></td>
                        <td>
                            <div class="fw-bold"><?= (int)($c['total_respondidas'] ?? 0) ?>/<?= (int)($c['total_fornecedores'] ?? 0) ?></div>
                            <?php if ($c['total_enviados'] > 0): ?>
                            <small class="text-muted" style="font-size:10px">
                                <i class="bi bi-send-check text-success"></i> <?= $c['total_enviados'] ?> enviados
                            </small>
                            <?php endif; ?>
                        </td>
                        <td><?= datetime_br($c['prazo_resposta']) ?></td>
                        <td><?= datetime_br($c['criado_em']) ?></td>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end flex-wrap">
                            <?php if (in_array($c['status'], ['aberta','aguardando_respostas']) && !empty($linksPorCotacao[$c['id']])): ?>
                            <button class="btn btn-sm btn-outline-dark"
                                    onclick="abrirLinksEmail(<?= htmlspecialchars(json_encode($linksPorCotacao[$c['id']], JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES) ?>, '<?= datetime_br($c['prazo_resposta']) ?>', '<?= $c['id'] ?>')">
                                <i class="bi bi-envelope me-1"></i>E-mails
                            </button>
                            <?php endif; ?>
                            <?php if (!empty($c['numero_oc'])): ?>
                            <a href="<?= url('compras/ordens/' . $c['oc_id']) ?>" class="btn btn-sm btn-success">
                                <i class="bi bi-file-earmark-check me-1"></i><?= e($c['numero_oc']) ?>
                            </a>
                            <?php elseif ($c['total_respondidas'] > 0): ?>
                            <a href="<?= url('compras/cotacoes/' . $c['id'] . '/comparativo') ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-bar-chart-line me-1"></i>Comparativo
                            </a>
                            <?php endif; ?>
                            <?php
                            $regra    = \App\Services\CotacaoPolicy::avaliar($c);
                            $bloqueio = $regra['permitido'] ? '' : ' disabled';
                            $titulo   = $regra['motivo'] ?? '';
                            ?>
                            <a href="<?= url('compras/cotacoes/' . $c['id'] . '/editar') ?>"
                               class="btn btn-sm btn-outline-secondary<?= $bloqueio ? ' disabled' : '' ?>"
                               title="<?= e($titulo) ?>">
                                <i class="bi bi-pencil me-1"></i>Editar
                            </a>

                            <?php // O title fica no form: um <button disabled> não emite eventos de mouse,
                                  // então o tooltip nunca dispararia no próprio botão. ?>
                            <form method="POST" action="<?= url('compras/cotacoes/' . $c['id'] . '/refazer') ?>" class="d-inline"
                                  title="<?= e($titulo) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-warning"<?= $bloqueio ?>
                                        onclick="return confirm('Refazer apaga os preços já recebidos e gera links novos para todos os fornecedores. Continuar?')">
                                    <i class="bi bi-arrow-repeat me-1"></i>Refazer
                                </button>
                            </form>

                            <form method="POST" action="<?= url('compras/cotacoes/' . $c['id'] . '/cancelar') ?>" class="d-inline"
                                  title="<?= e($titulo) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger"<?= $bloqueio ?>
                                        onclick="return confirm('Cancelar esta cotação? Ela sai da lista, mas o histórico é preservado.')">
                                    <i class="bi bi-trash me-1"></i>Excluir
                                </button>
                            </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Nova Cotação -->
<div class="modal fade" id="modalNovaCotacao" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="<?= url('compras/cotacoes') ?>" class="modal-content border-0 shadow" id="formNovaCotacao">
            <?= csrf_field() ?>
            <div class="modal-header border-0" style="background:#2347C8;border-radius:8px 8px 0 0">
                <h5 class="modal-title text-white fw-bold">📋 Nova Cotação</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">

                <!-- Origem da cotação -->
                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Origem *</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="origem" id="origemReq" value="requisicao" checked>
                            <label class="form-check-label small" for="origemReq">A partir de uma requisição</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="origem" id="origemAvulsa" value="avulsa">
                            <label class="form-check-label small" for="origemAvulsa">Cotação avulsa (sem requisição)</label>
                        </div>
                    </div>
                    <div class="form-text" id="avisoAvulsa" style="display:none">
                        A requisição será exigida na hora de gerar a ordem de compra.
                    </div>
                </div>

                <!-- Requisição -->
                <div class="mb-3" id="blocoRequisicao">
                    <label class="form-label fw-semibold" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Requisição *</label>
                    <select name="requisicao_id" id="selectRequisicao" class="form-select form-select-sm" required>
                        <option value="">Selecione a requisição</option>
                        <?php foreach ($requisicoes as $r): ?>
                        <option value="<?= $r['id'] ?>"
                                data-itens="<?= (int)$r['total_itens'] ?>"
                                data-filial="<?= e($r['filial_nome']) ?>"
                                data-valor="<?= number_format($r['valor_total'], 2, ',', '.') ?>"
                                data-aprovado="<?= date('d/m/Y', strtotime($r['atualizado_em'])) ?>">
                            <?= e($r['grupo_nome']) ?> — <?= e($r['solicitante_nome']) ?> · R$ <?= number_format($r['valor_total'], 2, ',', '.') ?><?php
                                $totalOcs = (int)($r['total_ocs'] ?? 0);
                                if ($totalOcs > 0) {
                                    echo ' · ' . $totalOcs . ' OC' . ($totalOcs > 1 ? 's' : '') . ' emitida' . ($totalOcs > 1 ? 's' : '');
                                }
                            ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="reqInfo" class="d-none mt-2 px-3 py-2 rounded" style="background:#FFF5F5;border:1px solid #FFCCCC;font-size:12px;color:#606060">
                        <span class="me-3">📦 <strong id="reqItens"></strong> itens</span>
                        <span class="me-3">🏢 <strong id="reqFilial"></strong></span>
                        <span class="me-3">💰 <strong>R$ <span id="reqValor"></span></strong></span>
                        <span><span class="fw-semibold px-1 rounded" style="background:#D1FAE5;color:#065F46;font-size:11px">Aprovado</span> <strong id="reqAprovado"></strong></span>
                    </div>
                </div>

                <!-- Itens da cotação avulsa -->
                <div class="mb-3" id="blocoItensAvulsa" style="display:none">
                    <label class="form-label fw-semibold" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Itens a cotar *</label>
                    <div class="d-flex gap-2 mb-2">
                        <select id="itemCatalogo" class="form-select form-select-sm">
                            <option value="">Selecione um item do catálogo</option>
                            <?php foreach ($itensCatalogo as $it): ?>
                            <option value="<?= $it['id'] ?>" data-unidade="<?= e($it['unidade']) ?>"><?= e($it['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" id="itemQtd" class="form-control form-control-sm" style="max-width:110px"
                               min="0.01" step="0.01" placeholder="Qtd">
                        <button type="button" class="btn btn-sm btn-outline-primary text-nowrap" id="btnAddItem">
                            <i class="bi bi-plus-lg"></i> Adicionar
                        </button>
                    </div>
                    <table class="table table-sm mb-0 d-none" id="tabelaItensAvulsa">
                        <thead class="table-light"><tr><th>Item</th><th style="width:110px">Qtd</th><th style="width:40px"></th></tr></thead>
                        <tbody></tbody>
                    </table>
                    <div class="form-text" id="itensVazio">Nenhum item adicionado.</div>
                </div>

                <hr class="my-3">

                <!-- Fornecedores (tag multiselect) -->
                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">
                        Fornecedores *
                        <span id="fornCount" class="ms-1" style="color:#2347C8;font-weight:700;font-size:12px"></span>
                    </label>
                    <div id="tagBox" class="d-flex flex-wrap align-items-center gap-1 p-2 rounded" style="border:1px solid #dee2e6;min-height:42px;cursor:text;transition:border-color .15s,box-shadow .15s">
                        <input type="text" id="fornSearch" autocomplete="off" placeholder="Buscar fornecedor..."
                               class="border-0 flex-grow-1" style="min-width:140px;font-size:13px;outline:none;background:transparent">
                    </div>
                    <div id="fornDropdown" class="d-none rounded shadow-sm mt-1" style="border:1px solid #dee2e6;background:#fff;max-height:220px;overflow-y:auto;font-size:13px;position:relative;z-index:1055"></div>
                    <div id="fornHidden"></div>
                </div>

                <!-- CRÉDITOS DISPONÍVEIS -->
                <div id="creditosBox" class="d-none mt-3">
                    <div class="p-3 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold small text-success">
                                <i class="bi bi-credit-card me-1"></i>Créditos disponíveis com fornecedores selecionados
                            </span>
                            <span class="fw-bold text-success" id="creditoTotalLabel">R$ 0,00</span>
                        </div>
                        <div id="creditoLista" class="small text-muted mb-2"></div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="usar_credito"
                                   id="chkUsarCredito" value="1">
                            <label class="form-check-label fw-semibold small" for="chkUsarCredito">
                                Aplicar créditos ao gerar a OC desta cotação
                            </label>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer" style="border-color:#EAEAEA">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-sm fw-bold text-white px-4" style="background:#2347C8;border:none"
                        onmouseover="this.style.background='#1B38A0'" onmouseout="this.style.background='#2347C8'">
                    Criar Cotação
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // ── Dados dos fornecedores ───────────────────────────────────────
    const FORNECEDORES = <?= json_encode(array_map(fn($f) => [
        'id'   => $f['id'],
        'nome' => $f['nome_fantasia'],
    ], $fornecedores), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    // ── Estado ───────────────────────────────────────────────────────
    const selected = new Map(); // id → nome

    // ── Elementos ────────────────────────────────────────────────────
    const tagBox      = document.getElementById('tagBox');
    const searchInput = document.getElementById('fornSearch');
    const dropdown    = document.getElementById('fornDropdown');
    const hiddenDiv   = document.getElementById('fornHidden');
    const countLabel  = document.getElementById('fornCount');

    // ── Focus ring vermelho ──────────────────────────────────────────
    tagBox.addEventListener('focusin',  () => {
        tagBox.style.borderColor = '#2347C8';
        tagBox.style.boxShadow   = '0 0 0 3px rgba(35,71,200,.12)';
    });
    tagBox.addEventListener('focusout', () => {
        tagBox.style.borderColor = '#dee2e6';
        tagBox.style.boxShadow   = '';
        setTimeout(hideDropdown, 150);
    });
    tagBox.addEventListener('click', () => searchInput.focus());

    // ── Busca em tempo real ──────────────────────────────────────────
    searchInput.addEventListener('input', () => {
        const q = searchInput.value.trim().toLowerCase();
        if (!q) { hideDropdown(); return; }
        const results = FORNECEDORES.filter(f =>
            !selected.has(f.id) && f.nome.toLowerCase().includes(q)
        ).slice(0, 8);
        renderDropdown(results);
    });

    function renderDropdown(items) {
        if (!items.length) { hideDropdown(); return; }
        dropdown.innerHTML = items.map(f =>
            `<div class="px-3 py-2" style="cursor:pointer" data-id="${f.id}" data-nome="${encodeURIComponent(f.nome)}"
                  onmouseover="this.style.background='#FFF5F5'"
                  onmouseout="this.style.background=''">${f.nome}</div>`
        ).join('');
        dropdown.querySelectorAll('[data-id]').forEach(el =>
            el.addEventListener('mousedown', (e) => {
                e.preventDefault();
                addTag(el.dataset.id, decodeURIComponent(el.dataset.nome));
            })
        );
        dropdown.classList.remove('d-none');
    }

    function hideDropdown() {
        dropdown.classList.add('d-none');
        dropdown.innerHTML = '';
    }

    // ── Adicionar tag ────────────────────────────────────────────────
    function addTag(id, nome) {
        if (selected.has(id)) return;
        selected.set(id, nome);
        searchInput.value = '';
        hideDropdown();
        renderTags();
        renderHidden();
        updateCount();
        verificarCreditos();
    }

    function removeTag(id) {
        selected.delete(id);
        renderTags();
        renderHidden();
        updateCount();
        verificarCreditos();
    }

    const APP_BASE = '<?= rtrim($_ENV['APP_URL'] ?? '', '/') ?>';
    let _creditCache = {};

    async function verificarCreditos() {
        const box   = document.getElementById('creditosBox');
        const lista = document.getElementById('creditoLista');
        const total = document.getElementById('creditoTotalLabel');

        if (selected.size === 0) { box.classList.add('d-none'); return; }

        let linhas = [];
        let soma   = 0;

        for (const [id, nome] of selected) {
            if (!_creditCache[id]) {
                try {
                    const r = await fetch(`${APP_BASE}/api/fornecedor/${id}/creditos`);
                    _creditCache[id] = await r.json();
                } catch { _creditCache[id] = { total: 0, creditos: [] }; }
            }
            const val = parseFloat(_creditCache[id].total) || 0;
            if (val > 0) {
                soma += val;
                linhas.push(`<span class="me-3"><strong>${nome}</strong>: R$ ${val.toLocaleString('pt-BR',{minimumFractionDigits:2})}</span>`);
            }
        }

        if (soma > 0) {
            lista.innerHTML = linhas.join('');
            total.textContent = 'R$ ' + soma.toLocaleString('pt-BR', {minimumFractionDigits:2});
            box.classList.remove('d-none');
        } else {
            box.classList.add('d-none');
        }
    }

    function renderTags() {
        tagBox.querySelectorAll('.forn-tag').forEach(t => t.remove());
        selected.forEach((nome, id) => {
            const tag = document.createElement('span');
            tag.className = 'forn-tag d-flex align-items-center gap-1 px-2 rounded-pill text-white';
            tag.style.cssText = 'background:#2347C8;font-size:12px;white-space:nowrap';
            tag.innerHTML = `${nome} <span style="cursor:pointer;opacity:.8;font-size:14px;line-height:1" data-remove="${id}">×</span>`;
            tag.querySelector('[data-remove]').addEventListener('click', () => removeTag(id));
            tagBox.insertBefore(tag, searchInput);
        });
    }

    function renderHidden() {
        hiddenDiv.innerHTML = '';
        selected.forEach((_, id) => {
            const inp = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = 'fornecedor_id[]';
            inp.value = id;
            hiddenDiv.appendChild(inp);
        });
    }

    function updateCount() {
        countLabel.textContent = selected.size
            ? `${selected.size} selecionado${selected.size > 1 ? 's' : ''}`
            : '';
    }

    // ── Painel de resumo da requisição ───────────────────────────────
    document.getElementById('selectRequisicao').addEventListener('change', function () {
        const opt  = this.options[this.selectedIndex];
        const info = document.getElementById('reqInfo');
        if (!opt.value) { info.classList.add('d-none'); return; }
        document.getElementById('reqItens').textContent    = opt.dataset.itens;
        document.getElementById('reqFilial').textContent   = opt.dataset.filial;
        document.getElementById('reqValor').textContent    = opt.dataset.valor;
        document.getElementById('reqAprovado').textContent = opt.dataset.aprovado;
        info.classList.remove('d-none');
    });

    document.getElementById('modalNovaCotacao').addEventListener('show.bs.modal', () => {
        selected.clear();
        renderTags();
        renderHidden();
        updateCount();
        document.getElementById('reqInfo').classList.add('d-none');
        document.getElementById('selectRequisicao').value = '';

        // Volta para a origem padrão e limpa os itens da avulsa anterior: sem
        // isso, abrir o modal de novo depois de criar uma avulsa traz a lista
        // antiga já montada.
        document.getElementById('origemReq').checked = true;
        document.querySelector('#tabelaItensAvulsa tbody').innerHTML = '';
        document.getElementById('tabelaItensAvulsa').classList.add('d-none');
        document.getElementById('itensVazio').style.display = '';
        document.getElementById('itemCatalogo').value = '';
        document.getElementById('itemQtd').value = '';
        document.getElementById('origemReq').dispatchEvent(new Event('change'));
    });

    // ── Validação antes do submit ────────────────────────────────────
    document.getElementById('formNovaCotacao').addEventListener('submit', function (e) {
        if (!selected.size) {
            e.preventDefault();
            searchInput.focus();
            tagBox.style.borderColor = '#2347C8';
            tagBox.style.boxShadow   = '0 0 0 3px rgba(35,71,200,.25)';
            setTimeout(() => { tagBox.style.boxShadow = '0 0 0 3px rgba(35,71,200,.12)'; }, 2000);
        }
    });
}());
</script>

<!-- ── Modal Links de E-mail ──────────────────────────────────────────── -->
<div class="modal fade" id="modalEmailLinks" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0" style="background:#2347C8;border-radius:8px 8px 0 0">
                <h5 class="modal-title text-white fw-bold"><i class="bi bi-envelope me-2"></i>Links de Resposta — Fornecedores</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3" id="emailLinksBody">
            </div>
            <div class="modal-footer" style="border-color:#EAEAEA">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal Registrar Resposta Manual ────────────────────────────────────── -->
<div class="modal fade" id="modalRespostaManual" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0" style="background:#198754;border-radius:8px 8px 0 0">
                <div>
                    <h5 class="modal-title text-white fw-bold mb-0"><i class="bi bi-pencil-square me-2"></i>Registrar Resposta do Fornecedor</h5>
                    <small class="text-white opacity-75" id="respostaFornNome"></small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <input type="hidden" id="respostaToken">
                <div id="respostaItensBody"></div>
                <div class="row mt-3">
                    <div class="col-auto">
                        <label class="form-label fw-semibold" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Prazo de Entrega (dias)</label>
                        <input type="number" id="respostaPrazo" class="form-control form-control-sm" value="7" min="1" max="365" style="max-width:160px">
                    </div>
                    <div class="col-auto">
                        <label class="form-label fw-semibold" style="font-size:12px;text-transform:uppercase;letter-spacing:.5px">Frete (R$)</label>
                        <input type="text" id="respostaFrete" class="form-control form-control-sm" value="0,00" style="max-width:160px"
                               oninput="this.value=this.value.replace(/[^0-9,\.]/g,'')">
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="border-color:#EAEAEA">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnSalvarResposta" class="btn btn-sm btn-success fw-bold px-4" onclick="salvarRespostaManual()">
                    <i class="bi bi-check-lg me-1"></i>Salvar Resposta
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const APP_URL = '<?= rtrim($_ENV['APP_URL'] ?? '', '/') ?>';

// Garante limpeza ao fechar o modal de e-mails
document.getElementById('modalEmailLinks').addEventListener('hidden.bs.modal', function () {
    bootstrap.Modal.getInstance(this)?.dispose();
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
});

async function abrirLinksEmail(fornecedores, prazo, cotacaoId) {
    const body = document.getElementById('emailLinksBody');
    body.innerHTML = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-danger me-2"></div>Carregando itens...</div>';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEmailLinks')).show();

    // Busca os itens da cotação via API
    let itens = [];
    try {
        const res  = await fetch(APP_URL + '/api/cotacoes/' + cotacaoId + '/itens');
        const data = await res.json();
        itens = data.itens || [];
    } catch(e) { /* sem itens */ }

    // Monta bloco de itens para e-mail
    let itensTexto = '';
    if (itens.length > 0) {
        itensTexto = 'ITENS SOLICITADOS:\n';
        itens.forEach((item, idx) => {
            const qtdFmt = parseFloat(item.qtd).toLocaleString('pt-BR', {minimumFractionDigits: 2});
            itensTexto += `  ${idx + 1}. ${item.nome} - ${qtdFmt} ${item.un}\n`;
        });
        itensTexto += '\n';
    }

    // Monta bloco de itens para WhatsApp
    let itensWa = '';
    if (itens.length > 0) {
        itensWa = '\n\nItens:\n';
        itens.forEach((item, idx) => {
            const qtdFmt = parseFloat(item.qtd).toLocaleString('pt-BR', {minimumFractionDigits: 2});
            itensWa += `${idx + 1}. ${item.nome} - ${qtdFmt} ${item.un}\n`;
        });
    }

    body.innerHTML = '';

    fornecedores.forEach(function(f) {
        const link    = APP_URL + '/cotacao/responder/' + f.token_acesso;
        const cardKey = f.token_acesso.substring(0, 8);
        const cardId  = 'card-' + cardKey;
        const textId  = 'email-body-' + cardKey;

        const emailBody =
            'Prezado(a) ' + f.nome_fantasia + ',\n\n' +
            'Gostaríamos de solicitar sua cotação para os itens listados abaixo.\n' +
            'Por favor, responda este e-mail com seus preços e prazo de entrega para cada item.\n\n' +
            '─────────────────────────────────\n' +
            itensTexto +
            'Prazo para resposta: ' + prazo + '\n' +
            '─────────────────────────────────\n\n' +
            'Em caso de dúvidas, entre em contato.\n\n' +
            'Atenciosamente,\n' +
            'Departamento de Compras - Exemplo';

        const assunto = 'Solicitação de Cotação - Exemplo Compras';

        const nItens = itens.length;
        const waMensagem = 'Olá ' + f.nome_fantasia + ', somos do Departamento de Compras da Exemplo. '
            + 'Gostaríamos de solicitar sua cotação para ' + nItens + ' ' + (nItens === 1 ? 'item' : 'itens') + ' (prazo: ' + prazo + ').'
            + itensWa;
        const waUrl = 'https://wa.me/?text=' + encodeURIComponent(waMensagem);
        const semEmail = !f.email;
        const mailto   = 'mailto:' + (f.email || '') +
                         '?subject=' + encodeURIComponent(assunto) +
                         '&body='    + encodeURIComponent(emailBody);

        // Badge de status
        let statusBadge;
        if (f.status === 'respondida') {
            statusBadge = '<span class="badge bg-success ms-2 badge-status">Respondida</span>';
        } else if (f.email_manual_enviado_at) {
            statusBadge = '<span class="badge bg-warning text-dark ms-2 badge-status">Aguardando Resposta</span>';
        } else {
            statusBadge = '<span class="badge bg-secondary ms-2 badge-status">Não enviado</span>';
        }

        // Botão de ação
        let actionBtn = '';
        if (f.status !== 'respondida') {
            if (f.email_manual_enviado_at) {
                const ns = f.nome_fantasia.replace(/'/g, "\\'");
                actionBtn = `<button class="btn btn-sm btn-warning" onclick="abrirRespostaManual('${f.token_acesso}','${ns}')">
                    <i class="bi bi-pencil-square me-1"></i>Registrar Resposta
                </button>`;
            } else {
                actionBtn = `<button class="btn btn-sm btn-outline-success" onclick="marcarEmailEnviado('${f.token_acesso}',this,'${cardId}')">
                    <i class="bi bi-check-circle me-1"></i>E-mail Enviado
                </button>`;
            }
        }

        body.insertAdjacentHTML('beforeend', `
            <div class="border rounded p-3 mb-3" id="${cardId}">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <strong>${f.nome_fantasia}</strong>${statusBadge}<br>
                        <small class="text-muted">${f.email || '<span class="text-danger">Sem e-mail cadastrado</span>'}</small>
                    </div>
                    <div class="d-flex gap-2 flex-wrap justify-content-end">
                        <button class="btn btn-sm btn-outline-secondary" onclick="copiarTexto('${textId}',this)">
                            <i class="bi bi-clipboard me-1"></i>Copiar
                        </button>
                        ${!semEmail
                            ? `<a href="${mailto}" class="btn btn-sm btn-primary"><i class="bi bi-envelope-arrow-up me-1"></i>Abrir no Outlook</a>`
                            : `<button class="btn btn-sm btn-primary" disabled><i class="bi bi-envelope-arrow-up me-1"></i>Abrir no Outlook</button>`}
                        <a href="${waUrl}" class="btn btn-sm btn-success" target="_blank" rel="noopener">
                            <i class="bi bi-whatsapp me-1"></i>WhatsApp
                        </a>
                        ${actionBtn}
                    </div>
                </div>
                <textarea id="${textId}" class="form-control font-monospace" rows="8" style="font-size:12px;resize:none">${emailBody}</textarea>
            </div>
        `);
    });

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEmailLinks')).show();
}

function copiarTexto(id, btn) {
    const el = document.getElementById(id);
    if (!el) return;

    function marcarSucesso() {
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copiado!';
        btn.classList.replace('btn-outline-secondary', 'btn-success');
        setTimeout(function() {
            btn.innerHTML = orig;
            btn.classList.replace('btn-success', 'btn-outline-secondary');
        }, 2000);
    }

    // Seleciona o textarea visivel diretamente (funciona dentro de modal)
    el.focus();
    el.select();
    el.setSelectionRange(0, el.value.length);

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(el.value)
            .then(marcarSucesso)
            .catch(function() {
                const ok = document.execCommand('copy');
                if (ok) marcarSucesso();
                else alert('Não foi possível copiar automaticamente. Use Ctrl+A e Ctrl+C na caixa de texto.');
            });
    } else {
        const ok = document.execCommand('copy');
        if (ok) marcarSucesso();
        else alert('Não foi possível copiar automaticamente. Use Ctrl+A e Ctrl+C na caixa de texto.');
    }
}

async function marcarEmailEnviado(token, btn, cardId) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';
    try {
        const res  = await fetch(APP_URL + '/compras/cotacoes/email-enviado', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''},
            body: JSON.stringify({token})
        });
        const data = await res.json();
        if (data.success) {
            const card  = document.getElementById(cardId);
            card.querySelector('.badge-status').className    = 'badge bg-warning text-dark ms-2 badge-status';
            card.querySelector('.badge-status').textContent  = 'Aguardando Resposta';
            const ns = data.fornecedor_nome.replace(/'/g, "\\'");
            btn.outerHTML = `<button class="btn btn-sm btn-warning" onclick="abrirRespostaManual('${token}','${ns}')">
                <i class="bi bi-pencil-square me-1"></i>Registrar Resposta
            </button>`;
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>E-mail Enviado';
        }
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>E-mail Enviado';
    }
}

async function abrirRespostaManual(token, fornNome) {
    document.getElementById('respostaToken').value      = token;
    document.getElementById('respostaFornNome').textContent = 'Fornecedor: ' + fornNome;
    document.getElementById('respostaPrazo').value      = 7;
    document.getElementById('respostaItensBody').innerHTML =
        '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-success me-2"></div>Carregando itens...</div>';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalRespostaManual')).show();

    try {
        const res  = await fetch(APP_URL + '/compras/cotacoes/itens-para-resposta?token=' + encodeURIComponent(token));
        const data = await res.json();
        if (data.success && data.itens.length) {
            let html = '<div class="table-responsive"><table class="table table-sm align-middle mb-0">'
                + '<thead class="table-light"><tr>'
                + '<th>Item</th><th class="text-end" style="width:130px">Qtd. Req.</th>'
                + '<th style="width:120px">Qtd. Ofertada</th><th style="width:140px">Valor Unit. (R$)</th>'
                + '</tr></thead><tbody>';
            data.itens.forEach(function(item) {
                const qtd = parseFloat(item.quantidade);
                const valorSugerido = parseFloat(item.valor_sugerido) || 0;
                const valorFmt = valorSugerido > 0
                    ? valorSugerido.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2})
                    : '';
                html += `<tr>
                    <td>
                        <span class="fw-semibold">${item.item_nome}</span>
                        <small class="text-muted ms-1">${item.unidade}</small>
                        <input type="hidden" class="item-id-input" value="${item.item_id || ''}">
                    </td>
                    <td class="text-end text-muted">${qtd.toLocaleString('pt-BR',{minimumFractionDigits:2})}</td>
                    <td><input type="number" class="form-control form-control-sm qtd-input" value="${qtd}" step="0.01" min="0"></td>
                    <td><input type="text" class="form-control form-control-sm valor-input" placeholder="0,00" value="${valorFmt}"></td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            document.getElementById('respostaItensBody').innerHTML = html;
        } else {
            document.getElementById('respostaItensBody').innerHTML =
                '<div class="alert alert-warning mb-0">Nenhum item encontrado.</div>';
        }
    } catch(e) {
        document.getElementById('respostaItensBody').innerHTML =
            '<div class="alert alert-danger mb-0">Erro ao carregar itens.</div>';
    }
}

async function salvarRespostaManual() {
    const token    = document.getElementById('respostaToken').value;
    const prazo    = parseInt(document.getElementById('respostaPrazo').value) || 7;
    const freteStr = (document.getElementById('respostaFrete').value || '0').replace(/\./g,'').replace(',','.');
    const frete    = parseFloat(freteStr) || 0;
    const itens = [];

    document.querySelectorAll('#respostaItensBody tbody tr').forEach(function(row) {
        const itemId = row.querySelector('.item-id-input')?.value || '';
        // Item de texto livre (Ordem de Compra) não tem item_id: a descrição é o
        // que identifica a linha para o servidor. Lida do próprio texto da célula
        // para não depender de escapar aspas num atributo.
        const desc   = row.querySelector('.fw-semibold')?.textContent.trim() || '';
        const qtd    = parseFloat(row.querySelector('.qtd-input')?.value)   || 0;
        const valStr = (row.querySelector('.valor-input')?.value || '0').replace(/\./g,'').replace(',','.');
        const val    = parseFloat(valStr) || 0;
        if ((itemId || desc) && val > 0) {
            itens.push({item_id: itemId, descricao: desc, quantidade: qtd, valor_unitario: val});
        }
    });

    if (!itens.length) { alert('Informe ao menos um item com valor maior que zero.'); return; }

    const btn = document.getElementById('btnSalvarResposta');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';

    try {
        const res  = await fetch(APP_URL + '/compras/cotacoes/resposta-manual', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''},
            body: JSON.stringify({token, prazo_entrega_dias: prazo, frete, itens})
        });
        const data = await res.json();
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalRespostaManual')).hide();
            location.reload();
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvar Resposta';
            alert(data.message || 'Erro ao salvar resposta.');
        }
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Salvar Resposta';
    }
}
</script>

<script>
const filtroStatusCotacao = document.getElementById('filtroStatusCotacao');
if (filtroStatusCotacao) {
    filtroStatusCotacao.addEventListener('change', function () {
        const url = new URL(window.location.href);
        if (this.value) {
            url.searchParams.set('status', this.value);
        } else {
            url.searchParams.delete('status');
        }
        window.location.href = url.toString();
    });
}
</script>

<script>
// ── Cotação avulsa ───────────────────────────────────────────────────────────
(function () {
    const radios      = document.querySelectorAll('input[name="origem"]');
    const blocoReq    = document.getElementById('blocoRequisicao');
    const blocoItens  = document.getElementById('blocoItensAvulsa');
    const selectReq   = document.getElementById('selectRequisicao');
    const aviso       = document.getElementById('avisoAvulsa');
    const tabela      = document.getElementById('tabelaItensAvulsa');
    const tbody       = tabela.querySelector('tbody');
    const vazio       = document.getElementById('itensVazio');
    const selItem     = document.getElementById('itemCatalogo');
    const inpQtd      = document.getElementById('itemQtd');

    const ehAvulsa = () => document.getElementById('origemAvulsa').checked;

    function alternar() {
        const avulsa = ehAvulsa();
        blocoReq.style.display   = avulsa ? 'none' : '';
        blocoItens.style.display = avulsa ? '' : 'none';
        aviso.style.display      = avulsa ? '' : 'none';
        // required só vale para o campo que está visível, senão o browser
        // bloqueia o submit de um campo escondido
        selectReq.required = !avulsa;
    }

    radios.forEach(r => r.addEventListener('change', alternar));
    alternar();

    document.getElementById('btnAddItem').addEventListener('click', () => {
        const id   = selItem.value;
        const nome = selItem.options[selItem.selectedIndex]?.text || '';
        const qtd  = parseFloat(inpQtd.value);

        if (!id)              { alert('Selecione um item.'); return; }
        if (!(qtd > 0))       { alert('Informe a quantidade.'); return; }
        if (tbody.querySelector('tr[data-item="' + id + '"]')) {
            alert('Esse item já está na lista.');
            return;
        }

        const tr = document.createElement('tr');
        tr.dataset.item = id;
        tr.innerHTML =
            '<td>' + nome +
            '<input type="hidden" name="item_id[]" value="' + id + '"></td>' +
            '<td>' + qtd.toLocaleString('pt-BR') +
            '<input type="hidden" name="item_quantidade[]" value="' + qtd + '"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger btn-remover">' +
            '<i class="bi bi-x"></i></button></td>';
        tbody.appendChild(tr);

        selItem.value = '';
        inpQtd.value  = '';
        atualizarTabela();
    });

    tbody.addEventListener('click', e => {
        if (e.target.closest('.btn-remover')) {
            e.target.closest('tr').remove();
            atualizarTabela();
        }
    });

    function atualizarTabela() {
        const temItem = tbody.children.length > 0;
        tabela.classList.toggle('d-none', !temItem);
        vazio.style.display = temItem ? 'none' : '';
    }

    document.getElementById('formNovaCotacao').addEventListener('submit', e => {
        if (ehAvulsa() && tbody.children.length === 0) {
            e.preventDefault();
            alert('Adicione ao menos um item para a cotação avulsa.');
        }
    });
})();
</script>
