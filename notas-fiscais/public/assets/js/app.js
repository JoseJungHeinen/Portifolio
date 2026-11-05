// @versao Sys_24 V_01_16/07/2026
/**
 * app.js — Workflow NF — Exemplo
 * JavaScript base: modais, alertas, sidebar, dropdowns, filtros de período
 *
 * JS vanilla puro — sem dependências externas.
 */

document.addEventListener('DOMContentLoaded', function () {

  // Inicializa Lucide Icons (se disponível)
  if (typeof lucide !== 'undefined') {
    lucide.createIcons();
  }

  // ─── MODAIS ────────────────────────────────────────────────────
  // Abre modal via data-modal-open="modalId"
  document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-modal-open');
      openModal(id);
    });
  });

  // Fecha modal via data-modal-close ou clique no overlay
  document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var overlay = btn.closest('.modal-overlay');
      if (overlay) closeModal(overlay.id);
    });
  });

  // Modais fecham apenas pelos botões (sem clique-fora / ESC) —
  // evita perder dados digitados por clique acidental.

  function openModal(id) {
    var overlay = document.getElementById(id);
    if (overlay) {
      overlay.classList.add('is-open');
      document.body.style.overflow = 'hidden';
    }
  }

  function closeModal(id) {
    var overlay = document.getElementById(id);
    if (overlay) {
      overlay.classList.remove('is-open');
      // Só restaura scroll se não há outro modal aberto
      if (!document.querySelector('.modal-overlay.is-open')) {
        document.body.style.overflow = '';
      }
    }
  }

  // Expõe globalmente para uso em PHP/HTML inline
  window.openModal  = openModal;
  window.closeModal = closeModal;

  // ─── ALERTAS DISMISSÍVEIS ─────────────────────────────────────
  document.querySelectorAll('.alert-dismiss').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var alert = btn.closest('.alert');
      if (alert) {
        alert.style.transition = 'opacity 0.2s ease';
        alert.style.opacity = '0';
        setTimeout(function () {
          alert.remove();
        }, 200);
      }
    });
  });

  // ─── FLASH MESSAGES (auto-dismiss após 5s) ───────────────────
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(function (alert) {
    var delay = parseInt(alert.getAttribute('data-auto-dismiss')) || 5000;
    setTimeout(function () {
      alert.style.transition = 'opacity 0.5s ease';
      alert.style.opacity = '0';
      setTimeout(function () { alert.remove(); }, 500);
    }, delay);
  });

  // ─── SIDEBAR TOGGLE ──────────────────────────────────────────
  var sidebarToggle = document.getElementById('sidebar-toggle');
  var sidebar       = document.querySelector('.sidebar');
  var body          = document.body;

  if (sidebarToggle && sidebar) {
    sidebarToggle.addEventListener('click', function () {
      var isCollapsed = body.classList.toggle('sidebar-collapsed');
      sidebar.classList.toggle('sidebar--collapsed', isCollapsed);
      // Persiste estado no localStorage
      localStorage.setItem('sidebar_collapsed', isCollapsed ? '1' : '0');
    });
  }

  // Restaura estado da sidebar do localStorage
  if (sidebar) {
    var savedState = localStorage.getItem('sidebar_collapsed');
    if (savedState === '1') {
      body.classList.add('sidebar-collapsed');
      sidebar.classList.add('sidebar--collapsed');
    }
  }

  // ─── FILTRO DE PERÍODO ────────────────────────────────────────
  document.querySelectorAll('.filter-period').forEach(function (filterEl) {
    var buttons   = filterEl.querySelectorAll('.filter-period-btn');
    var customBox = filterEl.querySelector('.filter-period-custom');

    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        buttons.forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');

        var period = btn.getAttribute('data-period');
        if (period === 'custom') {
          if (customBox) customBox.classList.add('is-visible');
        } else {
          if (customBox) customBox.classList.remove('is-visible');
          // Dispara evento customizado com o período selecionado
          filterEl.dispatchEvent(new CustomEvent('period-change', {
            bubbles: true,
            detail: { period: period }
          }));
        }
      });
    });
  });

  // ─── CONFIRMAR AÇÕES DESTRUTIVAS (via modal CSS) ───────────────
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      // Segundo clique (após confirmar): deixa a ação nativa seguir
      // (navegação do link / submit do form).
      if (el.getAttribute('data-confirmed') === '1') {
        el.removeAttribute('data-confirmed');
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      var msg = el.getAttribute('data-confirm') || 'Tem certeza? Esta ação não pode ser desfeita.';
      window.btModalConfirm('Confirmar ação', msg, function () {
        el.setAttribute('data-confirmed', '1');
        el.click();
      });
    });
  });

  // ─── TABELAS — SELEÇÃO DE LINHAS ─────────────────────────────
  var selectAll = document.querySelector('[data-select-all]');
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      var table     = selectAll.closest('table') || document;
      var checkboxes = table.querySelectorAll('[data-row-checkbox]');
      checkboxes.forEach(function (cb) {
        cb.checked = selectAll.checked;
      });
    });
  }

  // ─── TOOLTIPS (fallback via title) ───────────────────────────
  // Nativo do browser via title — nada extra necessário
  // [data-tooltip] usa CSS puro (definido no design-system.css)

});

// ═══════════════════════════════════════════════════════════════
// MODAIS GLOBAIS CSS — substituem alert() e confirm() nativos
// ═══════════════════════════════════════════════════════════════

(function () {
  var MODAL_Z = 10000;
  var overlayCSS = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:' + MODAL_Z + ';display:flex;align-items:center;justify-content:center;padding:20px;animation:btModalIn 0.2s ease';
  var cardCSS = 'background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,0.2);width:100%;max-width:480px;overflow:hidden';
  var headerCSS = 'display:flex;align-items:center;gap:12px;padding:20px 24px 0';
  var bodyCSS = 'padding:14px 24px 20px;font-size:15px;color:#606060;line-height:1.6';
  var footerCSS = 'padding:0 24px 20px;display:flex;justify-content:flex-end;gap:8px';
  var btnBaseCSS = 'padding:10px 24px;border:none;border-radius:6px;font-size:14px;font-weight:700;cursor:pointer;transition:background 0.15s';

  function injectAnim() {
    if (document.getElementById('bt-modal-anim')) return;
    var s = document.createElement('style');
    s.id = 'bt-modal-anim';
    s.textContent = '@keyframes btModalIn{from{opacity:0;transform:scale(0.95)}to{opacity:1;transform:scale(1)}}';
    document.head.appendChild(s);
  }

  function svgIcon(type) {
    if (type === 'error') return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#B3261E" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
    if (type === 'warning') return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#FF8A2D" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
    return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#2347C8" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
  }

  function removeModal(el) {
    if (el && el.parentNode) el.parentNode.removeChild(el);
  }

  function buildOverlay() {
    injectAnim();
    var ov = document.createElement('div');
    ov.style.cssText = overlayCSS;
    return ov;
  }

  /**
   * Modal de alerta (substitui alert())
   * @param {string} titulo
   * @param {string} mensagem — aceita HTML
   * @param {string} [tipo] — 'error'|'warning'|'info' (default 'error')
   */
  window.btModalErro = function (titulo, mensagem, tipo) {
    tipo = tipo || 'error';
    var iconBg = tipo === 'error' ? '#FFE8EE' : (tipo === 'warning' ? '#FFF3E0' : '#EEF2FD');

    var ov = buildOverlay();
    ov.innerHTML =
      '<div style="' + cardCSS + '">' +
        '<div style="' + headerCSS + '">' +
          '<div style="width:40px;height:40px;border-radius:10px;background:' + iconBg + ';display:flex;align-items:center;justify-content:center;flex-shrink:0">' + svgIcon(tipo) + '</div>' +
          '<div style="font-size:18px;font-weight:700;color:#141922">' + titulo + '</div>' +
        '</div>' +
        '<div style="' + bodyCSS + '">' + mensagem + '</div>' +
        '<div style="' + footerCSS + '">' +
          '<button type="button" class="bt-modal-ok" style="' + btnBaseCSS + ';background:#2347C8;color:#fff">Entendi</button>' +
        '</div>' +
      '</div>';

    ov.querySelector('.bt-modal-ok').onclick = function () { removeModal(ov); };
    ov.addEventListener('click', function (e) { if (e.target === ov) removeModal(ov); });
    document.body.appendChild(ov);
    ov.querySelector('.bt-modal-ok').focus();
  };

  /**
   * Modal de confirmação (substitui confirm())
   * @param {string} titulo
   * @param {string} mensagem — aceita HTML
   * @param {function} onConfirm — chamada se usuário confirmar
   * @param {object} [opts] — { confirmLabel, cancelLabel, tipo, confirmBg }
   */
  window.btModalConfirm = function (titulo, mensagem, onConfirm, opts) {
    opts = opts || {};
    var tipo = opts.tipo || 'warning';
    var confirmLabel = opts.confirmLabel || 'Confirmar';
    var cancelLabel = opts.cancelLabel || 'Cancelar';
    var confirmBg = opts.confirmBg || (tipo === 'error' ? '#B3261E' : '#2347C8');
    var iconBg = tipo === 'error' ? '#FFE8EE' : '#FFF3E0';

    var ov = buildOverlay();
    ov.innerHTML =
      '<div style="' + cardCSS + '">' +
        '<div style="' + headerCSS + '">' +
          '<div style="width:40px;height:40px;border-radius:10px;background:' + iconBg + ';display:flex;align-items:center;justify-content:center;flex-shrink:0">' + svgIcon(tipo) + '</div>' +
          '<div style="font-size:18px;font-weight:700;color:#141922">' + titulo + '</div>' +
        '</div>' +
        '<div style="' + bodyCSS + '">' + mensagem + '</div>' +
        '<div style="' + footerCSS + '">' +
          '<button type="button" class="bt-modal-cancel" style="' + btnBaseCSS + ';background:#EAEAEA;color:#606060">' + cancelLabel + '</button>' +
          '<button type="button" class="bt-modal-confirm" style="' + btnBaseCSS + ';background:' + confirmBg + ';color:#fff">' + confirmLabel + '</button>' +
        '</div>' +
      '</div>';

    ov.querySelector('.bt-modal-cancel').onclick = function () { removeModal(ov); };
    ov.querySelector('.bt-modal-confirm').onclick = function () { removeModal(ov); onConfirm(); };
    ov.addEventListener('click', function (e) { if (e.target === ov) removeModal(ov); });
    document.body.appendChild(ov);
    ov.querySelector('.bt-modal-confirm').focus();
  };
}());
