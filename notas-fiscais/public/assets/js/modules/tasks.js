// @versao Tar_04 V_02_06/08/2026
/**
 * tasks.js — Módulo Minhas Tarefas
 * Exemplo financeiro · Workflow NF
 *
 * Responsabilidades:
 *  - Modal de devolução (abrir, fechar, validar, submeter)
 *
 * Filtro rápido, busca textual e contadores são responsabilidade do script
 * inline em views/tasks/index.php (applyAdvancedFilters), que conhece todos
 * os campos de filtro atuais (fila, grupo, tipo de NF, loja, etc.).
 */

'use strict';

const Tasks = (() => {

  // ── Inicialização ──────────────────────────────────────────
  function init() {
    // 1. Botões "Devolver" — delegação no tasks-list
    const tasksList = document.getElementById('tasks-list');
    if (tasksList) {
      tasksList.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-devolver');
        if (!btn) return;
        e.preventDefault();
        abrirModalDevolver(
          btn.getAttribute('data-processo-id') || '',
          btn.getAttribute('data-etapa-id')    || '',
          btn.getAttribute('data-codigo')       || '',
          btn.getAttribute('data-fornecedor')   || '',
          btn.getAttribute('data-etapa-nome')   || ''
        );
      });
    }

    // 2. Fechar modal — botões e overlay
    const overlay    = document.getElementById('modal-devolver');
    const btnFechar  = document.getElementById('btn-modal-fechar');
    const btnCancelar = document.getElementById('btn-modal-cancelar');

    if (btnFechar)  btnFechar.addEventListener('click',  fecharModalDevolver);
    if (btnCancelar) btnCancelar.addEventListener('click', fecharModalDevolver);

    // 4. Validação inline do textarea de motivo
    const motivo = document.getElementById('devolver-motivo');
    if (motivo) {
      motivo.addEventListener('input', () => _validarMotivo(false));
    }

    // 5. Submit do form de devolução
    const formDevolver = document.getElementById('form-devolver');
    if (formDevolver) {
      formDevolver.addEventListener('submit', (e) => {
        if (!_validarMotivo(true)) {
          e.preventDefault();
        }
      });
    }
  }

  // ── Destinos da devolução ─────────────────────────────────
  /**
   * Preenche um <select> com as pessoas que já agiram no fluxo.
   * A opção guarda o ID da pessoa — o servidor é quem traduz para a etapa.
   *
   * @param {string}            processoId  UUID do processo
   * @param {HTMLSelectElement} select      destino das opções
   */
  function carregarPessoasDevolucao(processoId, select) {
    if (!select) return;

    select.textContent = '';
    const carregando = document.createElement('option');
    carregando.value = '';
    carregando.textContent = 'Carregando…';
    select.appendChild(carregando);
    select.disabled = true;

    fetch('?module=tarefas&action=pessoas_devolucao&processo_id=' + encodeURIComponent(processoId), {
      credentials: 'same-origin'
    })
      .then((r) => r.json())
      .then((data) => {
        select.textContent = '';
        const pessoas = (data && data.pessoas) || [];
        if (!pessoas.length) {
          const vazio = document.createElement('option');
          vazio.value = '';
          vazio.textContent = 'Ninguém disponível';
          select.appendChild(vazio);
          return;
        }
        pessoas.forEach((p) => {
          // textContent, nunca innerHTML — o nome vem do banco
          const opt = document.createElement('option');
          opt.value = p.usuario_id;
          opt.textContent = p.nome + (p.origem === 'solicitante' ? ' · solicitante' : '');
          select.appendChild(opt);
        });
        select.disabled = false;
      })
      .catch(() => {
        select.textContent = '';
        const erro = document.createElement('option');
        erro.value = '';
        erro.textContent = 'Falha ao carregar';
        select.appendChild(erro);
      });
  }

  // O modal de revisão rápida vive no inline script da view e reusa daqui.
  window.carregarPessoasDevolucao = carregarPessoasDevolucao;

  // ── Modal de devolução: abrir ─────────────────────────────
  /**
   * @param {string} processoId   UUID do processo
   * @param {string} etapaId      UUID da etapa
   * @param {string} codigo       Código do processo (ex: PROC-2026-0001)
   * @param {string} fornecedor   Nome do fornecedor
   * @param {string} etapaNome    Nome da etapa
   */
  function abrirModalDevolver(processoId, etapaId, codigo, fornecedor, etapaNome) {
    const overlay    = document.getElementById('modal-devolver');
    const inputProc  = document.getElementById('devolver-processo-id');
    const inputEtapa = document.getElementById('devolver-etapa-id');
    const codigoEl   = document.getElementById('modal-devolver-codigo-display');
    const desc       = document.getElementById('modal-devolver-desc');
    const motivo     = document.getElementById('devolver-motivo');
    const erro       = document.getElementById('devolver-motivo-erro');

    if (!overlay || !inputProc || !inputEtapa) return;

    // Usar setAttribute — nunca innerHTML com dados do servidor
    inputProc.setAttribute('value', processoId);
    inputEtapa.setAttribute('value', etapaId);

    if (codigoEl) {
      codigoEl.textContent = codigo;
    }

    if (desc) {
      desc.textContent = 'Informe o motivo da devolução para '
        + fornecedor
        + (etapaNome ? ' — Etapa: ' + etapaNome : '')
        + '. O solicitante será notificado.';
    }

    // Limpar campo e estado de erro
    if (motivo) {
      motivo.value = '';
      motivo.style.borderColor = '#DFDFDF';
    }
    if (erro) erro.style.display = 'none';

    // Destinos = pessoas que já agiram no fluxo. Carregado sob demanda para
    // não custar uma consulta por linha da fila no carregamento da página.
    carregarPessoasDevolucao(processoId, document.getElementById('devolver-destino'));

    // Mostrar modal
    overlay.removeAttribute('hidden');
    overlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';

    // Foco no textarea
    if (motivo) {
      setTimeout(() => motivo.focus(), 50);
    }
  }

  // ── Modal de devolução: fechar ─────────────────────────────
  function fecharModalDevolver() {
    const overlay = document.getElementById('modal-devolver');
    if (overlay) {
      overlay.classList.remove('is-open');
      overlay.setAttribute('hidden', '');
      document.body.style.overflow = '';
    }
  }

  // ── Validação do motivo ────────────────────────────────────
  function _validarMotivo(aoSubmeter) {
    const motivo = document.getElementById('devolver-motivo');
    const erro   = document.getElementById('devolver-motivo-erro');

    if (!motivo) return true;

    const valor  = motivo.value.trim();
    const valido = valor.length >= 10;

    if (!valido && aoSubmeter) {
      motivo.style.borderColor = '#B3261E';
      if (erro) erro.style.display = 'block';
      motivo.focus();
    } else if (valido) {
      motivo.style.borderColor = '#DFDFDF';
      if (erro) erro.style.display = 'none';
    }

    return valido;
  }

  // ── API pública ────────────────────────────────────────────
  return {
    init,
    abrirModalDevolver,
    fecharModalDevolver,
  };

})();

// Inicializar quando o DOM estiver pronto
document.addEventListener('DOMContentLoaded', Tasks.init);
