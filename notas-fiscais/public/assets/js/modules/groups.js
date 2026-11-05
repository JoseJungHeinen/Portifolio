// @versao Grp_05 V_02_12/08/2026
'use strict';

/**
 * public/assets/js/modules/groups.js
 * Lógica JS do módulo Grupos de Aprovação (página de detalhe)
 *
 * Segurança: dados vindos do servidor são sempre inseridos via
 * textContent ou setAttribute — nunca via innerHTML — para prevenir XSS.
 */

// ── Referências DOM ──────────────────────────────────────────────
var searchInput   = document.getElementById('member-search-input');
var searchResults = document.getElementById('member-search-results');
var grupoId       = document.getElementById('grupo-id-hidden') ? document.getElementById('grupo-id-hidden').value : '';
var csrfToken     = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').content : '';

// ── Cores de avatar (rotação por índice) ─────────────────────────
var AVATAR_CORES = ['#606060', '#2347C8', '#00A960', '#FF8A2D'];

// ── Utilitários ──────────────────────────────────────────────────
function iniciais(nome) {
    var parts = (nome || '').trim().split(/\s+/);
    var a = parts[0] ? parts[0][0] : '';
    var b = parts[1] ? parts[1][0] : '';
    return (a + b).toUpperCase();
}

function ucfirst(s) {
    if (!s) return '';
    return s.charAt(0).toUpperCase() + s.slice(1);
}

/** Cria um elemento com atributos e estilo de forma segura (sem innerHTML). */
function el(tag, props) {
    var node = document.createElement(tag);
    if (!props) return node;
    Object.keys(props).forEach(function (k) {
        if (k === 'textContent') {
            node.textContent = props[k];
        } else if (k === 'style') {
            node.setAttribute('style', props[k]);
        } else if (k === 'className') {
            node.className = props[k];
        } else {
            node.setAttribute(k, props[k]);
        }
    });
    return node;
}

// ── Busca de usuários ────────────────────────────────────────────
var searchTimeout = null;

if (searchInput) {
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimeout);
        var q = searchInput.value.trim();
        if (q.length < 2) {
            searchResults.style.display = 'none';
            return;
        }
        searchTimeout = setTimeout(function () { buscarUsuarios(q); }, 300);
    });

    // Fechar dropdown ao clicar fora
    document.addEventListener('click', function (e) {
        var container = searchInput.closest('.member-search-container');
        if (container && !container.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });
}

async function buscarUsuarios(q) {
    try {
        var url = '?module=grupos&action=buscar_usuarios'
                + '&q=' + encodeURIComponent(q)
                + '&grupo_id=' + encodeURIComponent(grupoId);

        setDropdownMessage('Buscando...', '#888');
        var res = await fetch(url);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        var usuarios = await res.json();
        renderDropdown(usuarios);
    } catch (e) {
        setDropdownMessage('Erro ao buscar. Tente novamente.', '#B3261E');
    }
}

/** Exibe uma mensagem simples no dropdown (sem dados do usuário — seguro como innerHTML estático). */
function setDropdownMessage(msg, color) {
    // msg é sempre uma string literal do próprio código, não do servidor
    var wrapper = el('div', {
        style: 'padding:12px;text-align:center;font-size:13px',
        textContent: msg
    });
    wrapper.style.color = color;
    searchResults.textContent = '';
    searchResults.appendChild(wrapper);
    searchResults.style.display = 'block';
}

function renderDropdown(usuarios) {
    // Limpa o dropdown com segurança
    searchResults.textContent = '';

    if (!Array.isArray(usuarios) || !usuarios.length) {
        setDropdownMessage('Nenhum usuário encontrado', '#888');
        return;
    }

    usuarios.forEach(function (u) {
        // Container do item
        var item = el('div', { className: 'member-dropdown-item' });
        item.dataset.id     = u.id     || '';
        item.dataset.nome   = u.nome   || '';
        item.dataset.perfil = u.perfil || '';
        item.dataset.filial = u.filial || '';

        // Avatar
        var avatar = el('div', {
            className: 'member-avatar',
            style: 'background:#606060;width:30px;height:30px;font-size:11px',
            textContent: iniciais(u.nome || '')
        });

        // Info do usuário
        var info = el('div', { style: 'flex:1;min-width:0' });

        var nomeEl = el('div', {
            style: 'font-size:13px;font-weight:600;color:#141922;white-space:nowrap;overflow:hidden;text-overflow:ellipsis',
            textContent: u.nome || ''
        });

        var perfilEl = el('div', { style: 'font-size:11px;color:#888' });
        perfilEl.textContent = ucfirst(u.perfil || '') + (u.filial ? ' · ' + u.filial : '');

        info.appendChild(nomeEl);
        info.appendChild(perfilEl);

        // Botão adicionar
        var btnAdd = el('button', {
            type: 'button',
            style: 'margin-left:auto;background:#2347C8;color:#fff;border:none;border-radius:4px;padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer;flex-shrink:0',
            textContent: '+ Adicionar'
        });

        item.appendChild(avatar);
        item.appendChild(info);
        item.appendChild(btnAdd);

        item.addEventListener('click', function () {
            adicionarMembro(item.dataset.id, item.dataset.nome, item.dataset.perfil, item.dataset.filial);
        });

        searchResults.appendChild(item);
    });

    searchResults.style.display = 'block';
}

// ── Adicionar membro ─────────────────────────────────────────────
async function adicionarMembro(usuarioId, nome, perfil, filial) {
    try {
        var res = await fetch('?module=grupos&action=add_membro', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'grupo_id=' + encodeURIComponent(grupoId)
                + '&usuario_id=' + encodeURIComponent(usuarioId)
                + '&csrf_token=' + encodeURIComponent(csrfToken)
        });
        var data = await res.json();
        if (data.success) {
            searchInput.value = '';
            searchResults.style.display = 'none';
            adicionarMembroNaLista(usuarioId, nome, perfil, filial || '');
            atualizarContadorMembros(1);
        } else {
            btModalErro('Erro ao adicionar', data.message || 'Não foi possível adicionar o membro ao grupo.');
        }
    } catch (e) {
        btModalErro('Erro de comunicação', 'Não foi possível conectar ao servidor. Tente novamente.');
    }
}

function adicionarMembroNaLista(usuarioId, nome, perfil, filial) {
    var list = document.getElementById('members-list');
    if (!list) return;

    // Remove estado vazio se presente
    var emptyState = list.querySelector('.empty-state');
    if (emptyState) emptyState.remove();

    // Cor baseada no número de itens atuais
    var items = list.querySelectorAll('.member-item');
    var cor = AVATAR_CORES[items.length % AVATAR_CORES.length];

    // ── Constrói o item via DOM (sem innerHTML com dados externos) ──
    var div = el('div', { className: 'member-item' });
    div.dataset.usuarioId = usuarioId;

    // Avatar
    var avatar = el('div', {
        className: 'member-avatar',
        style: 'background:' + cor,
        textContent: iniciais(nome)
    });

    // Info
    var info = el('div', { style: 'flex:1;min-width:0' });

    var nomeEl = el('div', {
        style: 'font-weight:600;font-size:14px;color:#141922',
        textContent: nome
    });

    var subEl = el('div', { style: 'font-size:12px;color:#888' });
    subEl.textContent = ucfirst(perfil) + (filial ? ' · ' + filial : '');

    info.appendChild(nomeEl);
    info.appendChild(subEl);

    // Botão remover
    var btnRemove = el('button', {
        type: 'button',
        className: 'member-remove-btn',
        title: 'Remover membro do grupo',
        style: 'margin-left:auto;background:#fff;border:1px solid #EAEAEA;cursor:pointer;color:#999;width:30px;height:30px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;transition:all 0.15s;flex-shrink:0'
    });
    btnRemove.dataset.usuarioId = usuarioId;
    btnRemove.addEventListener('mouseover', function () { btnRemove.style.background = '#FFE8EE'; btnRemove.style.borderColor = '#B3261E'; btnRemove.style.color = '#B3261E'; });
    btnRemove.addEventListener('mouseout',  function () { btnRemove.style.background = '#fff'; btnRemove.style.borderColor = '#EAEAEA'; btnRemove.style.color = '#999'; });

    // Ícone Lucide via atributo (seguro — não é dado do servidor)
    var icon = document.createElement('i');
    icon.setAttribute('data-lucide', 'trash-2');
    icon.setAttribute('width', '15');
    icon.setAttribute('height', '15');
    btnRemove.appendChild(icon);

    div.appendChild(avatar);
    div.appendChild(info);
    div.appendChild(btnRemove);

    // Sem listener de clique aqui de propósito: o #members-list tem delegação
    // (no fim deste arquivo) que já cobre os membros adicionados agora. Ter os
    // dois abria dois modais de confirmação no mesmo clique.

    list.appendChild(div);

    // Re-renderiza ícones Lucide
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

// ── Remover membro ───────────────────────────────────────────────
async function removerMembro(usuarioId, elItem) {
    btModalConfirm('Remover membro', 'Deseja remover este membro do grupo?', async function () { await _doRemoverMembro(usuarioId, elItem); }, {tipo: 'error', confirmLabel: 'Remover', confirmBg: '#B3261E'});
}
async function _doRemoverMembro(usuarioId, elItem) {
    try {
        var res = await fetch('?module=grupos&action=remove_membro', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'grupo_id=' + encodeURIComponent(grupoId)
                + '&usuario_id=' + encodeURIComponent(usuarioId)
                + '&csrf_token=' + encodeURIComponent(csrfToken)
        });
        var data = await res.json();
        if (data.success) {
            elItem.remove();
            atualizarContadorMembros(-1);
            var list = document.getElementById('members-list');
            if (list && !list.querySelector('.member-item')) {
                // Estado vazio — strings literais, sem dados do servidor
                var empty = el('div', {
                    className: 'empty-state',
                    style: 'padding:32px 16px;text-align:center;color:#888'
                });
                var p = el('p', {
                    style: 'font-size:14px;font-weight:600;margin:0 0 4px',
                    textContent: 'Nenhum membro ainda'
                });
                var small = el('small', {
                    style: 'font-size:13px',
                    textContent: 'Busque e adicione membros acima'
                });
                empty.appendChild(p);
                empty.appendChild(small);
                list.textContent = '';
                list.appendChild(empty);
            }
        } else {
            btModalErro('Erro ao remover', data.message || 'Não foi possível remover o membro do grupo.');
        }
    } catch (e) {
        btModalErro('Erro de comunicação', 'Não foi possível conectar ao servidor. Tente novamente.');
    }
}

// ── Atualizar contador de membros ────────────────────────────────
function atualizarContadorMembros(delta) {
    document.querySelectorAll('#members-count').forEach(function (elCount) {
        var n = (parseInt(elCount.textContent) || 0) + delta;
        if (n < 0) n = 0;
        elCount.textContent = n + (n === 1 ? ' membro' : ' membros');
    });
}

// ── Delegação de eventos para remoção de membros já na lista ─────
var membersList = document.getElementById('members-list');
if (membersList) {
    membersList.addEventListener('click', function (e) {
        var btn = e.target.closest('.member-remove-btn');
        if (!btn) return;
        var item = btn.closest('.member-item');
        if (item) removerMembro(btn.dataset.usuarioId, item);
    });
}
