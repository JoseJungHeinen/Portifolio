/* Exemplo Compras — App JS */

'use strict';

// ── Máscara de moeda ────────────────────────────────────────────────────────
document.querySelectorAll('.money-mask').forEach(el => {
    el.addEventListener('input', function () {
        let v = this.value.replace(/\D/g, '');
        v = (parseInt(v || '0') / 100).toFixed(2);
        this.value = v.replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    });
});

// ── Auto-dismiss alerts ─────────────────────────────────────────────────────
document.querySelectorAll('.alert.alert-success, .alert.alert-info').forEach(el => {
    setTimeout(() => {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(el);
        bsAlert?.close();
    }, 4000);
});

// ── Confirm links ───────────────────────────────────────────────────────────
document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
        if (!confirm(el.dataset.confirm)) e.preventDefault();
    });
});

// ── Sidebar mobile toggle ───────────────────────────────────────────────────
const overlay = document.createElement('div');
overlay.id = 'sidebarOverlay';
overlay.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:999';
document.body.appendChild(overlay);

overlay.addEventListener('click', closeSidebar);

function closeSidebar() {
    document.getElementById('sidebar')?.classList.remove('mobile-open');
    overlay.style.display = 'none';
}

if (window.innerWidth <= 576) {
    document.getElementById('sidebarToggle')?.addEventListener('click', () => {
        const sb = document.getElementById('sidebar');
        const open = sb?.classList.toggle('mobile-open');
        overlay.style.display = open ? 'block' : 'none';
    });
}

// ── Tooltip Bootstrap ───────────────────────────────────────────────────────
document.querySelectorAll('[title]').forEach(el => {
    if (el.dataset.bsToggle !== 'modal') {
        new bootstrap.Tooltip(el, { trigger: 'hover' });
    }
});

// ── Notificações: marcar como lida via AJAX ─────────────────────────────────
document.querySelectorAll('.notif-item').forEach(item => {
    item.addEventListener('click', function () {
        const id = this.dataset.id;
        if (id && !this.dataset.lida) {
            // keepalive: o item pode ser um link, e a navegação não pode cortar a chamada
            const appUrl = document.querySelector('meta[name="app-url"]')?.content || '';
            fetch(`${appUrl}/dashboard/notificacoes/${id}/lida`, {
                method: 'POST',
                keepalive: true,
                headers: {'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''}
            })
                .then(() => { this.classList.add('text-muted'); this.dataset.lida = '1'; });
        }
    });
});

// ── Verificação de saldo ao digitar valor na requisição ────────────────────
const grupoProdutoSel = document.querySelector('[name="grupo_produto_id"]');
if (grupoProdutoSel) {
    const updateSaldoInfo = () => {
        const grupo = grupoProdutoSel.value;
        if (!grupo) return;
        // Futuro: AJAX para verificar saldo em tempo real
    };
    grupoProdutoSel.addEventListener('change', updateSaldoInfo);
}

// ── Totalizador da requisição ───────────────────────────────────────────────
function calcularTotalReq() {
    let total = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const qtd = parseFloat(row.querySelector('[name="quantidade[]"]')?.value || 0);
        const val = parseFloat(row.querySelector('[name="valor_unit[]"]')?.value || 0);
        total += qtd * val;
    });
    const el = document.getElementById('totalReq');
    if (el) el.textContent = 'R$ ' + total.toLocaleString('pt-BR', { minimumFractionDigits: 2 });
}

document.getElementById('itensContainer')?.addEventListener('input', calcularTotalReq);