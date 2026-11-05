<?php
// @versao Proc_11 V_04_12/08/2026
/**
 * views/processos/editar_dados.php
 * Editar dados do processo em qualquer etapa (permissão processos.editar_dados).
 *
 * Três blocos: dados da nota, campos do fluxo e parcelas. Parcela já paga ou
 * agendada aparece travada — o financeiro já agiu sobre ela, e reescrevê-la
 * apagaria o que foi executado. Toda alteração vai para o histórico do processo.
 */

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$camposTpl  = $camposTpl  ?? [];
$filiaisEd  = $filiaisEd  ?? [];
$formasEd   = $formasEd   ?? [];
$parcelasEd = $processo['parcelas'] ?? [];
$dadosForm  = is_array($processo['dados_formulario'] ?? null) ? $processo['dados_formulario'] : [];
$temFin     = !empty($processo['tem_financeiro']);
$travados   = ['pago', 'agendado', 'cancelado'];

$inp   = 'width:100%;height:40px;padding:0 12px;border:1px solid #DFDFDF;border-radius:4px;font-size:14px;color:#141922;box-sizing:border-box;outline:none';
$lbl   = 'display:block;font-size:13px;font-weight:700;color:#606060;margin-bottom:6px';
$card  = 'background:#fff;border-radius:12px;box-shadow:rgba(0,0,0,0.10) 0 2px 8px;padding:24px 28px;margin-bottom:20px';
$voltarUrl = '?module=processos&amp;action=detalhe&amp;id=' . h($processo['id']);
?>

<?php include VIEWS . '/components/alert.php'; ?>

<div style="margin-bottom:24px">
  <h1 style="font-size:32px;font-weight:700;color:#141922;margin:0 0 4px">
    Editar <span style="color:#2347C8">campos</span>
  </h1>
  <p style="font-size:14px;color:#888;margin:0">
    <?= h($processo['codigo']) ?> · <?= h($processo['template_nome'] ?? '') ?> ·
    etapa <?= (int) $processo['etapa_atual'] ?> · toda alteração fica registrada no histórico.
  </p>
</div>

<form method="POST" action="?module=processos&amp;action=editar_dados&amp;id=<?= h($processo['id']) ?>" style="max-width:960px">
  <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
  <input type="hidden" name="processo_id" value="<?= h($processo['id']) ?>">

  <!-- ── Dados da nota ── -->
  <div style="<?= $card ?>">
    <h2 style="font-size:16px;font-weight:700;color:#141922;margin:0 0 18px">Dados da nota</h2>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
      <div>
        <label style="<?= $lbl ?>">CNPJ / CPF do fornecedor</label>
        <input type="text" name="fornecedor_doc" value="<?= h($processo['fornecedor_doc']) ?>" required
               style="<?= $inp ?>" onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      </div>
      <div>
        <label style="<?= $lbl ?>">Fornecedor</label>
        <input type="text" name="fornecedor_nome" value="<?= h($processo['fornecedor_nome']) ?>" required
               style="<?= $inp ?>" onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px">
      <div>
        <label style="<?= $lbl ?>">Número da NF</label>
        <input type="text" name="numero_nf" value="<?= h($processo['numero_nf']) ?>" required maxlength="255" style="<?= $inp ?>">
      </div>
      <div>
        <label style="<?= $lbl ?>">Data de emissão</label>
        <input type="text" name="data_nf" placeholder="dd/mm/aaaa" required style="<?= $inp ?>"
               value="<?= h($processo['data_nf'] ? date('d/m/Y', strtotime((string) $processo['data_nf'])) : '') ?>">
      </div>
      <div>
        <label style="<?= $lbl ?>">Valor da nota</label>
        <input type="text" name="valor_nf" required style="<?= $inp ?>"
               value="<?= h(number_format((float) $processo['valor_nf'], 2, ',', '.')) ?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 2fr;gap:16px">
      <div>
        <label style="<?= $lbl ?>">Filial</label>
        <select name="filial_id" required style="<?= $inp ?>;background:#fff">
          <?php foreach ($filiaisEd as $f): ?>
          <option value="<?= h($f['id']) ?>" <?= ($processo['filial_id'] ?? '') === $f['id'] ? 'selected' : '' ?>>
            <?= h($f['nome']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="<?= $lbl ?>">Chave de acesso <span style="font-weight:400;color:#888">(44 ou 50 dígitos — opcional)</span></label>
        <input type="text" name="chave_nf" maxlength="50" value="<?= h($processo['chave_nf'] ?? '') ?>" style="<?= $inp ?>">
        <?php
        // Chave herdada fora do padrão não trava a edição (o controller só cobra
        // 44/50 quando o campo muda) — mas o usuário precisa saber que está ali.
        $chaveDig = preg_replace('/\D/', '', (string) ($processo['chave_nf'] ?? ''));
        if ($chaveDig !== '' && !in_array(strlen($chaveDig), [44, 50], true)):
        ?>
        <div style="font-size:12px;color:#FF8A2D;margin-top:6px">
          A chave gravada tem <?= strlen($chaveDig) ?> dígitos — fora do padrão (44 ou 50).
          Fica como está; só é cobrada se você mexer neste campo. Para gravar sem chave, apague o conteúdo.
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Campos do fluxo ── -->
  <?php if ($camposTpl): ?>
  <div style="<?= $card ?>">
    <h2 style="font-size:16px;font-weight:700;color:#141922;margin:0 0 18px">Campos do fluxo</h2>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
      <?php foreach ($camposTpl as $c):
        $nomeCampo = (string) ($c['nome'] ?? '');
        if ($nomeCampo === '') continue;
        $valorAtual = (string) ($dadosForm[$nomeCampo] ?? '');
        $opcoes = $c['opcoes'] ?? [];
      ?>
      <div>
        <label style="<?= $lbl ?>"><?= h($c['label'] ?? $nomeCampo) ?></label>
        <?php if ($opcoes): ?>
        <select name="campo[<?= h($nomeCampo) ?>]" style="<?= $inp ?>;background:#fff">
          <option value="">—</option>
          <?php foreach ($opcoes as $op): ?>
          <option value="<?= h($op['id']) ?>" <?= $valorAtual === (string) $op['id'] ? 'selected' : '' ?>><?= h($op['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="campo[<?= h($nomeCampo) ?>]" value="<?= h($valorAtual) ?>" style="<?= $inp ?>">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Parcelas ── -->
  <?php if ($temFin): ?>
  <div style="<?= $card ?>">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
      <h2 style="font-size:16px;font-weight:700;color:#141922;margin:0">Parcelas</h2>
      <button type="button" onclick="addParcela()"
              style="background:transparent;border:1px solid #2347C8;color:#2347C8;border-radius:4px;padding:7px 14px;font-size:13px;font-weight:700;cursor:pointer">
        + Adicionar parcela
      </button>
    </div>
    <p style="font-size:12px;color:#888;margin:0 0 16px">
      Parcela já paga, agendada ou cancelada não pode ser alterada aqui — o financeiro já agiu sobre ela.
    </p>

    <?php
      $travadasLista = array_values(array_filter($parcelasEd, fn($p) => in_array($p['status'] ?? '', $travados, true)));
      $abertasLista  = array_values(array_filter($parcelasEd, fn($p) => !in_array($p['status'] ?? '', $travados, true)));
    ?>

    <?php if ($travadasLista): ?>
    <div style="background:#F8F8F8;border-radius:8px;padding:12px 14px;margin-bottom:16px">
      <div style="font-size:12px;font-weight:700;color:#606060;margin-bottom:8px">Mantidas (não editáveis)</div>
      <?php foreach ($travadasLista as $p): ?>
      <div style="display:flex;gap:14px;align-items:center;font-size:13px;color:#606060;padding:4px 0">
        <span style="background:#EAEAEA;color:#888;font-size:10px;font-weight:700;padding:2px 8px;border-radius:8px;text-transform:uppercase"><?= h($p['status']) ?></span>
        <span><?= h(date('d/m/Y', strtotime((string) $p['vencimento']))) ?></span>
        <span>R$ <?= h(number_format((float) $p['valor'], 2, ',', '.')) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div id="parcelas-editaveis">
      <?php foreach ($abertasLista as $i => $p): ?>
      <div class="parcela-linha" style="display:grid;grid-template-columns:1fr 1fr 1.4fr 40px;gap:12px;margin-bottom:10px;align-items:end">
        <div>
          <label style="<?= $lbl ?>">Vencimento</label>
          <input type="text" name="parcela[<?= $i ?>][vencimento]" placeholder="dd/mm/aaaa" style="<?= $inp ?>"
                 value="<?= h(date('d/m/Y', strtotime((string) $p['vencimento']))) ?>">
        </div>
        <div>
          <label style="<?= $lbl ?>">Valor</label>
          <input type="text" name="parcela[<?= $i ?>][valor]" style="<?= $inp ?>"
                 value="<?= h(number_format((float) $p['valor'], 2, ',', '.')) ?>">
        </div>
        <div>
          <label style="<?= $lbl ?>">Forma de pagamento</label>
          <select name="parcela[<?= $i ?>][forma_pagamento_id]" style="<?= $inp ?>;background:#fff">
            <option value="">Selecione…</option>
            <?php foreach ($formasEd as $fp): ?>
            <option value="<?= h($fp['id']) ?>" <?= ($p['forma_pagamento_id'] ?? '') === $fp['id'] ? 'selected' : '' ?>>
              <?= h($fp['nome']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="button" onclick="this.closest('.parcela-linha').remove()" title="Remover parcela"
                style="height:40px;background:#EAEAEA;border:none;border-radius:4px;color:#606060;cursor:pointer;font-size:16px">×</button>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div style="display:flex;gap:10px">
    <button type="submit"
            style="background:#2347C8;color:#fff;border:none;border-radius:4px;padding:11px 24px;font-size:14px;font-weight:700;cursor:pointer">
      Gravar alterações
    </button>
    <a href="<?= $voltarUrl ?>"
       style="background:#EAEAEA;color:#606060;border-radius:4px;padding:11px 20px;font-size:14px;font-weight:700;text-decoration:none">
      Cancelar
    </a>
  </div>
</form>

<script>
  // Índice novo continua de onde as linhas existentes pararam — reaproveitar um
  // índice já usado sobrescreveria a parcela anterior no POST.
  var parcelaIdx = <?= count($abertasLista ?? []) ?>;
  function addParcela() {
    var box = document.getElementById('parcelas-editaveis');
    if (!box) return;
    var d = document.createElement('div');
    d.className = 'parcela-linha';
    d.style.cssText = 'display:grid;grid-template-columns:1fr 1fr 1.4fr 40px;gap:12px;margin-bottom:10px;align-items:end';
    d.innerHTML =
      '<div><label style="<?= $lbl ?>">Vencimento</label>'
      + '<input type="text" name="parcela[' + parcelaIdx + '][vencimento]" placeholder="dd/mm/aaaa" style="<?= $inp ?>"></div>'
      + '<div><label style="<?= $lbl ?>">Valor</label>'
      + '<input type="text" name="parcela[' + parcelaIdx + '][valor]" style="<?= $inp ?>"></div>'
      + '<div><label style="<?= $lbl ?>">Forma de pagamento</label>'
      + '<select name="parcela[' + parcelaIdx + '][forma_pagamento_id]" style="<?= $inp ?>;background:#fff">'
      + '<option value="">Selecione…</option>'
      + <?= json_encode(implode('', array_map(
            fn($f) => '<option value="' . h($f['id']) . '">' . h($f['nome']) . '</option>', $formasEd
          )), JSON_UNESCAPED_UNICODE) ?>
      + '</select></div>'
      + '<button type="button" onclick="this.closest(\'.parcela-linha\').remove()" title="Remover parcela" '
      + 'style="height:40px;background:#EAEAEA;border:none;border-radius:4px;color:#606060;cursor:pointer;font-size:16px">×</button>';
    box.appendChild(d);
    parcelaIdx++;
  }
</script>
