<?php
// @versao Ver.001.301026
/**
 * Condição de pagamento em parcelas (R005) — lado do navegador. Espelho de
 * App\Services\CondicaoPagamento (parcelas/descricao/limites): manter os dois iguais.
 * Uso: include deste arquivo antes do script da tela; depois
 *   CondicaoPagamento.ligar(input, dica)            — dica ao vivo abaixo do campo
 *   CondicaoPagamento.preencherAuto(input, valor)   — pré-preenche com a vencedora
 */
if (defined('CONDICAO_PAGAMENTO_JS')) return;
define('CONDICAO_PAGAMENTO_JS', true);
?>
<script>
window.CondicaoPagamento = window.CondicaoPagamento || (function () {
    const MAX_PARCELAS = <?= \App\Services\CondicaoPagamento::MAX_PARCELAS ?>;
    const MAX_DIAS     = <?= \App\Services\CondicaoPagamento::MAX_DIAS ?>;

    // Dias de cada parcela ou null (não reconhecido / vazio). Ver CondicaoPagamento::parcelas().
    function parcelas(texto) {
        let t = String(texto == null ? '' : texto).trim().toLowerCase();
        if (t === '') return null;
        t = t.normalize('NFD').replace(/[̀-ͯ]/g, '');
        t = t.replace(/^condicao de pagamento\s*:\s*/, '');
        t = t.replace(/\d+(?:[.,]\d+)?\s*%/g, ' ');

        let vezes = null;
        const mx = t.match(/\d+\s*x\b/g);
        if (mx) {
            if (mx.length > 1) return null;
            vezes = parseInt(mx[0], 10);
            t = t.replace(/\d+\s*x\b/g, ' ');
        }

        const m = t.match(/\d+/g);
        if (m) {
            const dias = m.map(n => parseInt(n, 10));
            if (vezes !== null && vezes !== dias.length) return null;
            return dias;
        }
        if (vezes === null && /\bvista\b|antecipad/.test(t)) return [0];
        return null;
    }

    function prazoMedio(p) { return p.reduce((a, b) => a + b, 0) / p.length; }

    // "3 parcelas: 30, 60 e 90 dias (prazo médio 60)". Ver CondicaoPagamento::descricao().
    function descricao(p) {
        const n = p.length;
        if (n === 1 && p[0] === 0) return 'À vista';
        const lista = n === 1 ? String(p[0]) : p.slice(0, -1).join(', ') + ' e ' + p[n - 1];
        let txt = n + (n === 1 ? ' parcela: ' : ' parcelas: ') + lista + ' dias';
        if (n > 1) txt += ' (prazo médio ' + String(Math.round(prazoMedio(p) * 10) / 10).replace('.', ',') + ')';
        return txt;
    }

    // {ok, texto} da dica abaixo do campo; texto vazio = campo vazio.
    function dica(texto) {
        if (String(texto == null ? '' : texto).trim() === '') return { ok: true, texto: '' };
        const p = parcelas(texto);
        if (p === null) return { ok: false, texto: 'Não reconhecido — use dias separados por /, ex.: 30/60/90' };
        if (p.length > MAX_PARCELAS) return { ok: false, texto: 'No máximo ' + MAX_PARCELAS + ' parcelas.' };
        if (p.some(d => d > MAX_DIAS)) return { ok: false, texto: 'Cada parcela deve vencer em 0 a ' + MAX_DIAS + ' dias.' };
        return { ok: true, texto: descricao(p) };
    }

    function atualizarDica(input, alvo) {
        if (!input || !alvo) return;
        const d = dica(input.value);
        alvo.textContent = d.texto;
        alvo.classList.toggle('text-danger', !d.ok);
        alvo.classList.toggle('text-muted', d.ok);
    }

    function ligar(input, alvo) {
        if (!input) return;
        input.addEventListener('input', () => atualizarDica(input, alvo));
        input._condicaoDica = alvo;
        atualizarDica(input, alvo);
    }

    // Copia a condição da cotação vencedora para o campo — só se ele estiver vazio
    // ou ainda com o último valor preenchido automaticamente (data-auto): o que o
    // usuário digitou nunca é sobrescrito.
    function preencherAuto(input, valor) {
        if (!input || input.disabled) return false;
        valor = String(valor == null ? '' : valor).trim();
        const atual = input.value.trim();
        if (atual !== '' && atual !== (input.dataset.auto || '').trim()) return false;
        input.value = valor;
        input.dataset.auto = valor;
        atualizarDica(input, input._condicaoDica);
        return true;
    }

    return { parcelas, prazoMedio, descricao, dica, ligar, preencherAuto, atualizarDica };
})();
</script>
