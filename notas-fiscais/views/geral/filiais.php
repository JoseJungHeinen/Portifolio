<?php
// @versao Ger_07 V_01_16/09/2026
/**
 * views/geral/filiais.php — aba "Filiais" do módulo Geral.
 *
 * Cadastro completo das filiais — saiu de Listas em 16/09/2026, onde só dava
 * pra editar nome e CNPJ. Aqui entra tudo: endereço, contato, orçamento e
 * status. As integrações (Brobot, captura direta NF-e/NFS-e) continuam nas
 * abas próprias — aqui é só o cadastro da filial em si.
 *
 * Variáveis: $filiaisCompleto
 */
$filiaisCompleto = $filiaisCompleto ?? [];

if (!function_exists('formatCnpjGeral')) {
    function formatCnpjGeral($cnpj) {
        $d = preg_replace('/\D/', '', (string) $cnpj);
        if (strlen($d) !== 14) return $cnpj ? (string) $cnpj : '—';
        return substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12,2);
    }
}
?>

<div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:12px">
  <div>
    <h2 style="font-size:20px; font-weight:700; color:#141922; margin:0 0 4px">Filiais</h2>
    <p style="font-size:13px; color:#888; margin:0">
      Lojas/matrizes/fornecedores do grupo — usadas em processos, templates, usuários e pré-fluxo.
      Integrações (Brobot, captura direta NF-e/NFS-e) ficam nas abas ao lado.
    </p>
  </div>
  <button type="button" id="btn-nova-filial"
          style="display:inline-flex; align-items:center; gap:6px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:10px 18px; font-size:14px; font-weight:700; cursor:pointer">
    <i data-lucide="plus" width="15" height="15"></i> Nova filial
  </button>
</div>

<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden">
  <?php if (empty($filiaisCompleto)): ?>
  <div style="padding:32px; text-align:center; color:#888; font-size:13px">Nenhuma filial cadastrada.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table style="width:100%; border-collapse:collapse; font-size:13px">
      <thead>
        <tr style="background:#606060; color:#fff; text-align:left">
          <th style="padding:8px 12px; font-weight:700">Filial</th>
          <th style="padding:8px 12px; font-weight:700">CNPJ</th>
          <th style="padding:8px 12px; font-weight:700">Cidade/UF</th>
          <th style="padding:8px 12px; font-weight:700; text-align:right">Budget mensal</th>
          <th style="padding:8px 12px; font-weight:700; width:90px">Status</th>
          <th style="padding:8px 12px; font-weight:700; width:90px; text-align:right">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($filiaisCompleto as $i => $f): ?>
        <tr style="background:<?= $i % 2 ? '#F8F8F8' : '#fff' ?>; border-bottom:1px solid #EAEAEA; <?= $f['ativo'] ? '' : 'opacity:.55' ?>">
          <td style="padding:8px 12px; color:#141922; font-weight:700"><?= h($f['nome']) ?></td>
          <td style="padding:8px 12px; font-family:Consolas,monospace; color:#606060"><?= h(formatCnpjGeral($f['cnpj'])) ?></td>
          <td style="padding:8px 12px; color:#606060"><?= h(trim(($f['cidade'] ?? '') . ($f['estado'] ? '/' . $f['estado'] : '')) ?: '—') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#606060">
            <?= $f['budget_mensal'] > 0 ? 'R$ ' . number_format((float) $f['budget_mensal'], 2, ',', '.') : '—' ?>
          </td>
          <td style="padding:8px 12px">
            <?php if ($f['ativo']): ?>
            <span style="font-size:10px; font-weight:700; background:#D8F5E5; color:#00A960; padding:2px 8px; border-radius:8px">Ativa</span>
            <?php else: ?>
            <span style="font-size:10px; font-weight:700; background:#EAEAEA; color:#888; padding:2px 8px; border-radius:8px">Inativa</span>
            <?php endif; ?>
          </td>
          <td style="padding:8px 12px; text-align:right">
            <div style="display:flex; gap:4px; justify-content:flex-end">
              <button type="button" class="btn-edit-filial"
                      data-id="<?= h($f['id']) ?>" data-nome="<?= h($f['nome']) ?>"
                      data-cnpj="<?= h(formatCnpjGeral($f['cnpj'])) ?>"
                      data-endereco="<?= h($f['endereco'] ?? '') ?>" data-telefone="<?= h($f['telefone'] ?? '') ?>"
                      data-email="<?= h($f['email'] ?? '') ?>" data-cidade="<?= h($f['cidade'] ?? '') ?>"
                      data-estado="<?= h($f['estado'] ?? '') ?>" data-budget="<?= h((string) $f['budget_mensal']) ?>"
                      style="width:30px; height:30px; border-radius:4px; border:1px solid #EAEAEA; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#888"
                      title="Editar" onmouseover="this.style.color='#2347C8';this.style.borderColor='#2347C8'" onmouseout="this.style.color='#888';this.style.borderColor='#EAEAEA'">
                <i data-lucide="pencil" width="14" height="14"></i>
              </button>
              <form method="post" action="?module=geral&amp;action=toggle_filial_ativo" style="display:inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= h($f['id']) ?>">
                <button type="submit" style="width:30px; height:30px; border-radius:4px; border:1px solid #EAEAEA; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; color:<?= $f['ativo'] ? '#00A960' : '#888' ?>" title="<?= $f['ativo'] ? 'Desativar' : 'Ativar' ?>">
                  <i data-lucide="<?= $f['ativo'] ? 'toggle-right' : 'toggle-left' ?>" width="16" height="16"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Modal: Nova/Editar Filial -->
<div id="modal-filial" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center">
  <div style="background:#fff; border-radius:12px; width:100%; max-width:520px; margin:20px; box-shadow:0 8px 32px rgba(0,0,0,0.2); max-height:90vh; overflow-y:auto">
    <div style="padding:20px 24px 0; display:flex; align-items:center; justify-content:space-between">
      <h3 id="modal-filial-titulo" style="font-size:18px; font-weight:700; color:#141922; margin:0">Nova filial</h3>
      <button type="button" onclick="document.getElementById('modal-filial').style.display='none'" style="background:none; border:none; cursor:pointer; color:#888; font-size:20px">×</button>
    </div>
    <form method="post" action="?module=geral&amp;action=salvar_filial">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" id="f-id" value="">
      <div style="padding:20px 24px; display:grid; gap:14px">
        <div>
          <label style="<?= $lblStyle ?>">Nome <span style="color:#2347C8">*</span></label>
          <input type="text" name="nome" id="f-nome" required style="<?= $inpStyle ?>">
        </div>
        <div>
          <label style="<?= $lblStyle ?>">CNPJ <span style="font-weight:400; color:#888">(opcional)</span></label>
          <input type="text" name="cnpj" id="f-cnpj" maxlength="18" placeholder="11.222.333/0001-81" style="<?= $inpStyle ?>">
        </div>
        <div>
          <label style="<?= $lblStyle ?>">Endereço</label>
          <input type="text" name="endereco" id="f-endereco" style="<?= $inpStyle ?>">
        </div>
        <div style="display:grid; grid-template-columns:2fr 1fr; gap:12px">
          <div>
            <label style="<?= $lblStyle ?>">Cidade</label>
            <input type="text" name="cidade" id="f-cidade" style="<?= $inpStyle ?>">
          </div>
          <div>
            <label style="<?= $lblStyle ?>">UF</label>
            <input type="text" name="estado" id="f-estado" maxlength="2" style="<?= $inpStyle ?>; text-transform:uppercase">
          </div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px">
          <div>
            <label style="<?= $lblStyle ?>">Telefone</label>
            <input type="text" name="telefone" id="f-telefone" style="<?= $inpStyle ?>">
          </div>
          <div>
            <label style="<?= $lblStyle ?>">E-mail</label>
            <input type="email" name="email" id="f-email" style="<?= $inpStyle ?>">
          </div>
        </div>
        <div style="max-width:220px">
          <label style="<?= $lblStyle ?>">Budget mensal</label>
          <input type="number" step="0.01" min="0" name="budget_mensal" id="f-budget" style="<?= $inpStyle ?>">
        </div>
      </div>
      <div style="padding:0 24px 20px; display:flex; gap:8px; justify-content:flex-end">
        <button type="button" onclick="document.getElementById('modal-filial').style.display='none'" style="padding:10px 20px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">Cancelar</button>
        <button type="submit" style="<?= $btnSalvar ?>">Salvar</button>
      </div>
    </form>
  </div>
</div>

<script>
function mascararCnpjGeral(el) {
  if (!el) return;
  el.addEventListener('input', function () {
    var d = this.value.replace(/\D/g, '').slice(0, 14);
    var out = d;
    if (d.length > 2)  out = d.slice(0,2) + '.' + d.slice(2);
    if (d.length > 5)  out = d.slice(0,2) + '.' + d.slice(2,5) + '.' + d.slice(5);
    if (d.length > 8)  out = d.slice(0,2) + '.' + d.slice(2,5) + '.' + d.slice(5,8) + '/' + d.slice(8);
    if (d.length > 12) out = d.slice(0,2) + '.' + d.slice(2,5) + '.' + d.slice(5,8) + '/' + d.slice(8,12) + '-' + d.slice(12);
    this.value = out;
  });
}
mascararCnpjGeral(document.getElementById('f-cnpj'));

document.getElementById('btn-nova-filial').addEventListener('click', function () {
  document.getElementById('modal-filial-titulo').textContent = 'Nova filial';
  ['id','nome','cnpj','endereco','cidade','estado','telefone','email','budget'].forEach(function (k) {
    var el = document.getElementById('f-' + k);
    if (el) el.value = '';
  });
  document.getElementById('modal-filial').style.display = 'flex';
  document.getElementById('f-nome').focus();
});

document.querySelectorAll('.btn-edit-filial').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('modal-filial-titulo').textContent = 'Editar filial';
    document.getElementById('f-id').value       = this.dataset.id;
    document.getElementById('f-nome').value     = this.dataset.nome;
    document.getElementById('f-cnpj').value     = this.dataset.cnpj === '—' ? '' : this.dataset.cnpj;
    document.getElementById('f-endereco').value = this.dataset.endereco;
    document.getElementById('f-cidade').value   = this.dataset.cidade;
    document.getElementById('f-estado').value   = this.dataset.estado;
    document.getElementById('f-telefone').value = this.dataset.telefone;
    document.getElementById('f-email').value    = this.dataset.email;
    document.getElementById('f-budget').value   = this.dataset.budget;
    document.getElementById('modal-filial').style.display = 'flex';
  });
});
</script>
