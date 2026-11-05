<?php
// @versao Ver.004.281026
?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('fiscal') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-text me-2 text-success"></i>NF-e nº <?= e($nf['numero_nf']) ?></h4>
    <?= status_badge($nf['status']) ?>
    <a href="<?= url('fiscal/' . $nf['id'] . '/comparativo-estoque') ?>" class="btn btn-outline-info btn-sm ms-auto">
        <i class="bi bi-boxes me-1"></i>Comparativo de Estoque
    </a>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Dados da Nota</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Fornecedor</dt><dd class="col-sm-7"><?= e($nf['fornecedor_nome'] ?? '—') ?></dd>
                    <dt class="col-sm-5">Filial</dt><dd class="col-sm-7"><?= e($nf['filial_nome'] ?? '—') ?></dd>
                    <dt class="col-sm-5">Chave de Acesso</dt><dd class="col-sm-7 small text-break"><?= e($nf['chave_acesso'] ?? '—') ?></dd>
                    <dt class="col-sm-5">Data Emissão</dt><dd class="col-sm-7"><?= date_br($nf['data_emissao'] ?? null) ?></dd>
                    <dt class="col-sm-5 fw-bold">Valor Total</dt><dd class="col-sm-7 fw-bold text-success"><?= money($nf['valor_total'] ?? 0) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <?php if (!empty($nf['divergencias'])): ?>
    <div class="col-12">
        <div class="card border-danger border-0 shadow-sm">
            <div class="card-header bg-danger text-white fw-semibold">
                <i class="bi bi-exclamation-triangle me-2"></i>Divergências Encontradas
            </div>
            <div class="card-body">
                <?php foreach ($nf['divergencias'] as $div): ?>
                <div class="alert alert-warning mb-2">
                    <?php if (($div['tipo'] ?? '') === 'numero_oc'): ?>
                    <strong>Nº da OC não consta na NF:</strong>
                    esperado <strong><?= e($div['oc'] ?? '') ?></strong> | pedido na NF: <?= e($div['nf'] ?? '') ?>.
                    Pela Política de Compras, confirmar com o fornecedor antes de liberar o pagamento.
                    <?php else: ?>
                    <strong><?= ucfirst(str_replace('_', ' ', $div['tipo'])) ?>:</strong>
                    OC = <?= money($div['oc'] ?? 0) ?> | NF = <?= money($div['nf'] ?? 0) ?>
                    | Diferença: <strong><?= $div['diff_pct'] ?? 0 ?>%</strong>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($nf['status'] === 'pendente' || $nf['status'] === 'divergente'): ?>
    <?php if (can(['comprador','gestor','diretor','admin'])): ?>
    <div class="col-12">
        <div class="d-flex gap-2">
            <form method="POST" action="<?= url('fiscal/' . $nf['id'] . '/aprovar') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-success" onclick="return confirm('Aprovar esta NF-e?')">
                    <i class="bi bi-check-circle me-1"></i>Aprovar NF-e
                </button>
            </form>
            <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalRejeitar">
                <i class="bi bi-x-circle me-1"></i>Rejeitar
            </button>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Modal Rejeitar -->
<div class="modal fade" id="modalRejeitar" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="<?= url('fiscal/' . $nf['id'] . '/rejeitar') ?>" class="modal-content">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title">Rejeitar NF-e</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold">Motivo da Rejeição *</label>
                <textarea name="motivo" class="form-control" rows="3" required placeholder="Descreva o motivo..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger">Confirmar Rejeição</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm mt-3">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <span class="fw-semibold"><i class="bi bi-calculator me-2"></i>Conferência de custo (IPI + frete)</span>
  </div>
  <div class="card-body">
    <div class="d-flex gap-3 align-items-end flex-wrap mb-3">
      <div>
        <label class="form-label small mb-1">Frete total (NF / CTe)</label>
        <input type="text" id="freteTotal" class="form-control form-control-sm text-end" style="width:130px"
               value="<?= number_format((float)$nf['frete_total'], 2, ',', '.') ?>" oninput="recalc()">
      </div>
      <div>
        <label class="form-label small mb-1">Rateio</label>
        <select id="rateio" class="form-select form-select-sm" style="width:180px" onchange="recalc()">
          <option value="valor" <?= ($nf['rateio_metodo'] ?? 'valor')==='valor'?'selected':'' ?>>Por valor (proporcional)</option>
          <option value="quantidade" <?= ($nf['rateio_metodo'] ?? '')==='quantidade'?'selected':'' ?>>Por quantidade</option>
        </select>
      </div>
      <div class="ms-auto text-end">
        <div class="small text-muted">Total da NF</div>
        <div class="fs-5 fw-bold text-success" id="totalNF">R$ 0,00</div>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead class="table-light text-end">
          <tr><th class="text-start">Item</th><th>Fator</th><th>Qtd</th><th class="text-center">Un</th><th>Vlr Unit</th><th>IPI %</th><th>IPI R$</th><th>Frete</th><th class="table-success">Custo final unit.</th></tr>
        </thead>
        <tbody id="linhas">
        <?php foreach (($nfItens ?? []) as $it): ?>
          <?php
            // Valores do documento fiscal: âncora do fator e referência para
            // conferir contra o papel. O `?:` cobre linha importada antes da
            // migration 050, cujo backfill ainda não tenha rodado.
            $qtdNf = (float)(($it['quantidade_nf']     ?? 0) ?: $it['quantidade']);
            $vlrNf = (float)(($it['valor_unitario_nf'] ?? 0) ?: $it['valor_unitario']);
            $unNf  = (string)(($it['unidade_nf']       ?? '') ?: ($it['unidade'] ?? ''));
            $unIt  = (string)($it['item_unidade'] ?? '');
            $fmtNum = fn(float $v, int $dec) => rtrim(rtrim(number_format($v, $dec, ',', '.'), '0'), ',');
          ?>
          <tr class="lin text-end" data-id="<?= e($it['id']) ?>"
              data-qtd-nf="<?= $qtdNf ?>" data-vlr-nf="<?= $vlrNf ?>"
              data-un-nf="<?= e($unNf) ?>" data-un-item="<?= e($unIt) ?>">
            <td class="text-start" style="min-width:220px">
                <div class="small text-muted mb-1">
                    <?= e($it['descricao_nf']) ?>
                    <span class="text-nowrap">— nota: <?= $fmtNum($qtdNf, 2) ?> <?= e($unNf) ?> &times; <?= $fmtNum($vlrNf, 4) ?></span>
                </div>
                <div class="item-combo-fiscal position-relative">
                    <input type="text" class="form-control form-control-sm item-search-fiscal <?= empty($it['item_id']) ? 'border-danger' : '' ?>"
                           placeholder="Buscar item do catálogo..." autocomplete="off"
                           value="<?= e($it['item_nome_catalogo'] ?? '') ?>">
                    <input type="hidden" class="item-id-hidden-fiscal" value="<?= e($it['item_id'] ?? '') ?>">
                    <div class="item-dropdown-list-fiscal"></div>
                </div>
            </td>
            <?php
              $fatorAtual = (float)($it['fator_conversao'] ?? 1);
              // Com fator aplicado a linha está na unidade do item; sem fator,
              // na do fornecedor. Todos os números aqui vão em formato BR —
              // parseBR() trata ponto como separador de milhar, então render
              // com ponto decimal viraria 20.1 lido como 201.
              $unEfetiva = ($fatorAtual != 1.0 && $unIt !== '') ? $unIt : $unNf;
            ?>
            <td><input class="form-control form-control-sm text-end fator" style="width:80px" value="<?= $fmtNum($fatorAtual, 4) ?>" oninput="aplicarFator(this)" title="Quantas unidades de estoque vêm em 1 <?= e($unNf !== '' ? $unNf : 'unidade da nota') ?>"></td>
            <td><input class="form-control form-control-sm text-end qtd" style="width:80px" value="<?= $fmtNum((float)$it['quantidade'], 2) ?>" oninput="recalc()"></td>
            <td class="un text-center text-muted small"><?= e($unEfetiva !== '' ? $unEfetiva : '—') ?></td>
            <td><input class="form-control form-control-sm text-end vlr" style="width:100px" value="<?= $fmtNum((float)$it['valor_unitario'], 4) ?>" oninput="recalc()"></td>
            <td><input class="form-control form-control-sm text-end ipi" style="width:64px" value="<?= number_format((float)$it['ipi_percentual'],2,',','.') ?>" oninput="recalc()"></td>
            <td class="ipirs">—</td>
            <td><input class="form-control form-control-sm text-end frete-input" style="width:80px" value="0,00" oninput="onFreteInput(this)"></td>
            <td class="table-success fw-bold custo">—</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (in_array($nf['status'], ['pendente_conferencia','divergente'], true) || ($nf['status'] === 'aprovada' && empty($nf['conferido_por']))): ?>
    <div class="text-end">
      <button class="btn btn-success btn-sm" onclick="confirmarConferencia('<?= e($nf['id']) ?>')">
        <i class="bi bi-check2-circle me-1"></i>Confirmar conferência
      </button>
    </div>
    <?php endif; ?>
  </div>
</div>

<style>
.item-combo-fiscal { position: relative; }
.item-dropdown-list-fiscal {
    position: absolute; top: 100%; left: 0; right: 0; z-index: 1060;
    display: none; background: #fff;
    border: 1px solid #dee2e6; border-top: none;
    border-radius: 0 0 .375rem .375rem;
    max-height: 220px; overflow-y: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,.12);
}
.item-opt-fiscal {
    padding: .35rem .75rem; cursor: pointer; font-size: .8125rem;
    border-bottom: 1px solid #f0f0f0;
}
.item-opt-fiscal:hover, .item-opt-fiscal:focus { background: #e8f4fd; }
.item-opt-empty-fiscal { padding: .35rem .75rem; color: #aaa; font-size: .8125rem; font-style: italic; }
</style>
<script>
function parseBR(v){ if(v==null) return 0; v=(''+v).replace(/\./g,'').replace(',','.').replace(/[^0-9.\-]/g,''); var n=parseFloat(v); return isNaN(n)?0:n; }
function fmt(n){ return n.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}); }
// Sem zeros à direita: o campo precisa aceitar 20,1 e 42,8571 sem inventar
// precisão nem perder centavo na divisão pelo fator.
function fmtEnx(n, dec){
  return n.toLocaleString('pt-BR',{minimumFractionDigits:dec,maximumFractionDigits:dec}).replace(/,?0+$/,'') || '0';
}

// O fator recalcula quantidade e valor unitário SEMPRE a partir do que veio no
// XML (data-qtd-nf / data-vlr-nf), nunca a partir do valor já convertido na
// tela. É isso que impede aplicação dupla e faz voltar para 1 restaurar a nota.
// Editar a quantidade à mão continua valendo — o fator só reescreve quando
// o próprio fator muda.
function aplicarFator(input){
  var r = input.closest('.lin');
  var f = parseBR(input.value);
  if (!(f > 0)) { f = 1; }
  var qNf = parseFloat(r.dataset.qtdNf) || 0;
  var vNf = parseFloat(r.dataset.vlrNf) || 0;
  r.querySelector('.qtd').value = fmtEnx(qNf * f, 2);
  r.querySelector('.vlr').value = fmtEnx(vNf / f, 4);
  var cel = r.querySelector('.un');
  if (cel) {
    var un = (f !== 1 && r.dataset.unItem) ? r.dataset.unItem : r.dataset.unNf;
    cel.textContent = un || '—';
  }
  recalc();
}
function onFreteInput(input){
  input.dataset.dirty='1';
  recalc();
}
function recalc(){
  var rows=[].slice.call(document.querySelectorAll('#linhas .lin'));
  var frete=parseBR(document.getElementById('freteTotal').value);
  var modo=document.getElementById('rateio').value;
  var base=0, data=rows.map(function(r){
    var q=parseBR(r.querySelector('.qtd').value), v=parseBR(r.querySelector('.vlr').value), ip=parseBR(r.querySelector('.ipi').value);
    var prod=q*v, ipi=prod*ip/100; return {r:r,q:q,prod:prod,ipi:ipi};
  });
  data.forEach(function(d){ base += (modo==='quantidade'?d.q:d.prod); });
  var tProd=0,tIpi=0,tFrete=0;
  data.forEach(function(d){
    var freteInput=d.r.querySelector('.frete-input');
    var fr;
    if (freteInput.dataset.dirty === '1') {
      fr = parseBR(freteInput.value);
    } else {
      var peso=base>0?((modo==='quantidade'?d.q:d.prod)/base):0;
      fr = frete*peso;
      freteInput.value = fmt(fr);
    }
    var cu=d.q>0?((d.prod+d.ipi+fr)/d.q):0;
    d.r.querySelector('.ipirs').textContent=fmt(d.ipi);
    d.r.querySelector('.custo').textContent=fmt(cu);
    tProd+=d.prod; tIpi+=d.ipi; tFrete+=fr;
  });
  document.getElementById('totalNF').textContent='R$ '+fmt(tProd+tIpi+tFrete);
}
const TODOS_ITENS_FISCAL = <?php echo json_encode(array_map(fn($it) => [
    'id'   => $it['id'],
    'nome' => $it['nome'],
], $itens), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;

function escHtmlFiscal(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function populateComboFiscal(searchInput, query) {
    const combo = searchInput.closest('.item-combo-fiscal');
    const dropdown = combo.querySelector('.item-dropdown-list-fiscal');
    const q = query.toLowerCase().trim();
    let lista = q ? TODOS_ITENS_FISCAL.filter(it => it.nome.toLowerCase().includes(q)) : TODOS_ITENS_FISCAL;
    lista = lista.slice(0, 30);
    if (!lista.length) {
        dropdown.innerHTML = '<div class="item-opt-empty-fiscal">Nenhum item encontrado</div>';
    } else {
        dropdown.innerHTML = lista.map(function(it) {
            return '<div class="item-opt-fiscal" data-id="' + it.id + '" data-label="' + escHtmlFiscal(it.nome) + '">' + escHtmlFiscal(it.nome) + '</div>';
        }).join('');
    }
    dropdown.style.display = 'block';
}

document.getElementById('linhas').addEventListener('input', function (e) {
    if (!e.target.classList.contains('item-search-fiscal')) return;
    populateComboFiscal(e.target, e.target.value);
});

document.getElementById('linhas').addEventListener('focusin', function (e) {
    if (!e.target.classList.contains('item-search-fiscal')) return;
    populateComboFiscal(e.target, e.target.value);
});

document.getElementById('linhas').addEventListener('click', function (e) {
    const opt = e.target.closest('.item-opt-fiscal');
    if (!opt) return;
    const combo = opt.closest('.item-combo-fiscal');
    combo.querySelector('.item-search-fiscal').value = opt.dataset.label;
    combo.querySelector('.item-id-hidden-fiscal').value = opt.dataset.id;
    combo.querySelector('.item-search-fiscal').classList.remove('border-danger');
    combo.querySelector('.item-dropdown-list-fiscal').style.display = 'none';
});

document.getElementById('linhas').addEventListener('focusout', function (e) {
    if (!e.target.classList.contains('item-search-fiscal')) return;
    setTimeout(function () {
        const combo = e.target.closest('.item-combo-fiscal');
        if (!combo) return;
        combo.querySelector('.item-dropdown-list-fiscal').style.display = 'none';
    }, 150);
});

function confirmarConferencia(nfId){
  recalc();

  var linhas = [].slice.call(document.querySelectorAll('#linhas .lin'));
  var semVinculo = false;
  linhas.forEach(function(r){
    var hidden = r.querySelector('.item-id-hidden-fiscal');
    var search = r.querySelector('.item-search-fiscal');
    if (!hidden.value) {
      semVinculo = true;
      search.classList.add('border-danger');
    }
  });
  if (semVinculo) {
    alert('Vincule um item do catálogo em todas as linhas antes de confirmar a conferência.');
    return;
  }

  // O campo Qtd é texto livre: parseBR devolve 0 para qualquer coisa que não
  // seja número, e nada impedia digitar 0 ou negativo. O servidor também
  // recusa, mas avisar aqui poupa a ida e volta e mostra qual linha errou.
  var qtdRuim = null;
  linhas.forEach(function(r){
    var campo = r.querySelector('.qtd');
    var q = parseBR(campo.value);
    var ruim = !(q > 0) || q > 99999999.99;
    campo.classList.toggle('border-danger', ruim);
    if (ruim && qtdRuim === null) qtdRuim = campo.value;
  });
  if (qtdRuim !== null) {
    alert('Quantidade inválida ("' + qtdRuim + '"). Informe um número maior que zero em todas as linhas.');
    return;
  }

  var itens=linhas.map(function(r){
    return {
      id: r.dataset.id,
      item_id: r.querySelector('.item-id-hidden-fiscal').value,
      quantidade: parseBR(r.querySelector('.qtd').value),
      fator_conversao: parseBR(r.querySelector('.fator').value) || 1,
      valor_unitario: parseBR(r.querySelector('.vlr').value),
      ipi_percentual: parseBR(r.querySelector('.ipi').value),
      frete_rateado: parseBR(r.querySelector('.frete-input').value),
      ipi_valor: parseBR(r.querySelector('.ipirs').textContent),
      custo_final_unit: parseBR(r.querySelector('.custo').textContent)
    };
  });
  var payload={ frete_total: parseBR(document.getElementById('freteTotal').value),
                rateio_metodo: document.getElementById('rateio').value, itens: itens };
  fetch('<?= rtrim($_ENV['APP_URL'] ?? '', '/') ?>/fiscal/'+nfId+'/conferir', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':(document.querySelector('meta[name="csrf-token"]')?.content)||'<?= csrf_token() ?>'},
    body: JSON.stringify(payload)
  }).then(r=>r.json()).then(d=>{ if(d.success){ location.reload(); } else { alert(d.message||'Erro ao confirmar.'); } })
    .catch(()=>alert('Falha de comunicação.'));
}
document.addEventListener('DOMContentLoaded', recalc);
</script>
