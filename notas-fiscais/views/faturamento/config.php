<?php
// @versao Fat_06 V_01_29/09/2026
/**
 * views/faturamento/config.php — tela de controle do Faturamento (super_admin).
 *
 * Variáveis: $fasesCfg, $gruposPapel, $requisitos, $catalogo
 *
 * Fases   = STATUS do pedido (mudam só com OK). Cada fase define quais grupos
 *           editam e quais dão o OK.
 * Exigências = compõem a SITUAÇÃO e travam o OK da fase em que estão ligadas.
 *           Podem ter condição (ex.: Filial só se Tipo de venda = Novos|...).
 */
$inp  = 'height:36px; border:1px solid #DFDFDF; border-radius:4px; padding:0 10px; font-size:13px; background:#fff';
$btnP = 'height:36px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:0 16px; font-size:13px; font-weight:700; cursor:pointer';
$card = 'background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:20px; margin-bottom:16px';
$lbl  = 'display:block; font-size:12px; color:#606060; margin-bottom:4px';

/** Bloco de permissões (editar / OK) por grupo para uma fase. */
$blocoPerms = static function (array $grupos, array $perms) {
    ?>
    <table style="border-collapse:collapse; font-size:13px; margin-top:8px">
      <thead><tr style="background:#606060; color:#fff; text-align:left">
        <th style="padding:6px 12px">Grupo</th><th style="padding:6px 12px">Edita</th><th style="padding:6px 12px">Dá o OK</th>
      </tr></thead>
      <tbody>
      <?php foreach ($grupos as $i => $g): $pp = $perms[$g['id']] ?? null; ?>
        <tr style="background:<?= $i % 2 ? '#F8F8F8' : '#fff' ?>">
          <td style="padding:6px 12px"><?= h($g['nome']) ?></td>
          <td style="padding:6px 12px; text-align:center"><input type="checkbox" name="perm[<?= h($g['id']) ?>][editar]" value="1" <?= !empty($pp['pode_editar']) ? 'checked' : '' ?>></td>
          <td style="padding:6px 12px; text-align:center"><input type="checkbox" name="perm[<?= h($g['id']) ?>][ok]" value="1" <?= !empty($pp['pode_ok']) ? 'checked' : '' ?>></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
};
?>

<div style="margin-bottom:20px">
  <a href="?module=faturamento&action=index" style="font-size:13px; color:#606060; text-decoration:none">← Voltar para a lista</a>
  <h1 style="font-size:32px; font-weight:700; color:#141922; margin:8px 0 4px">Controle do <span style="color:#2347C8">Faturamento</span></h1>
  <p style="font-size:13px; color:#888; margin:0; max-width:760px">
    <b>Status</b> é a fase do pedido e só muda com o OK. <b>Situação</b> é calculada pelas exigências abaixo (dados e anexos).
    Preencher é livre; para avançar, as exigências da fase atual precisam estar cumpridas.
  </p>
</div>

<?php if (!empty($_SESSION['flash'])): $fl = $_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div style="<?= $card ?>; border-left:4px solid <?= $fl['type'] === 'success' ? '#00A960' : '#B3261E' ?>; padding:12px 16px"><?= h($fl['message']) ?></div>
<?php endif; ?>

<h2 style="font-size:24px; margin:24px 0 12px">Fases <span style="color:#2347C8">(status)</span></h2>

<?php foreach ($fasesCfg as $f): ?>
<form method="post" action="?module=faturamento&action=fase_salvar" style="<?= $card ?>; <?= $f['ativo'] ? '' : 'opacity:.6' ?>">
  <?= Csrf::field() ?>
  <input type="hidden" name="id" value="<?= h($f['id']) ?>">
  <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap">
    <div><label style="<?= $lbl ?>">Nome</label><input name="nome" value="<?= h($f['nome']) ?>" style="<?= $inp ?>; width:220px" required></div>
    <div><label style="<?= $lbl ?>">Ordem</label><input name="ordem" type="number" value="<?= (int) $f['ordem'] ?>" style="<?= $inp ?>; width:80px"></div>
    <div><label style="<?= $lbl ?>">Situação da fase</label>
      <select name="ativo" style="<?= $inp ?>"><option value="1" <?= $f['ativo'] ? 'selected' : '' ?>>Ativa</option><option value="0" <?= !$f['ativo'] ? 'selected' : '' ?>>Inativa</option></select></div>
    <div style="font-size:12px; color:#888; padding-bottom:10px"><?= (int) $f['qtd_pedidos'] ?> pedido(s) nesta fase</div>
    <button type="submit" style="<?= $btnP ?>; margin-left:auto">Salvar fase</button>
  </div>
  <?php $blocoPerms($gruposPapel, $f['perms']); ?>
</form>
<?php endforeach; ?>

<details style="<?= $card ?>">
  <summary style="cursor:pointer; font-weight:700; color:#2347C8">+ Nova fase</summary>
  <form method="post" action="?module=faturamento&action=fase_salvar" style="margin-top:12px">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="">
    <input type="hidden" name="ativo" value="1">
    <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap">
      <div><label style="<?= $lbl ?>">Nome</label><input name="nome" style="<?= $inp ?>; width:220px" required></div>
      <div><label style="<?= $lbl ?>">Ordem</label><input name="ordem" type="number" value="<?= (int) (end($fasesCfg)['ordem'] ?? 0) + 10 ?>" style="<?= $inp ?>; width:80px"></div>
      <button type="submit" style="<?= $btnP ?>; margin-left:auto">Criar fase</button>
    </div>
    <?php $blocoPerms($gruposPapel, []); ?>
  </form>
</details>

<h2 id="requisitos" style="font-size:24px; margin:32px 0 12px">Exigências <span style="color:#2347C8">(situação)</span></h2>
<p style="font-size:13px; color:#888; margin:-4px 0 12px">
  A exigência trava o OK da fase escolhida, mas pode ser preenchida antes. Condição opcional: só se aplica quando o campo tiver um dos valores
  (separe por vírgula). Ex.: <i>Empresa / Filial</i> só exigida se <i>Tipo de venda</i> for Novos, Seminovos, Repasse.
</p>

<?php
$opcoesFase = static function (array $fases, string $sel): string {
    $o = '';
    foreach ($fases as $f) {
        $o .= '<option value="' . h($f['id']) . '"' . ($f['id'] === $sel ? ' selected' : '') . '>' . h($f['nome']) . '</option>';
    }
    return $o;
};
$opcoesCampo = static function (array $cat, string $sel, bool $vazio = false): string {
    $o = $vazio ? '<option value="">— sem condição —</option>' : '';
    foreach ($cat as $k => $r) {
        $o .= '<option value="' . h($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . h($r) . '</option>';
    }
    return $o;
};
/** Formulário de uma exigência (linha existente ou nova). */
$formReq = static function (array $r, array $fases, array $cat) use ($inp, $btnP, $lbl, $opcoesFase, $opcoesCampo) {
    $novo = empty($r['id']);
    $tipo = $r['tipo'] ?? 'campo';
    ?>
    <form method="post" action="?module=faturamento&action=req_salvar" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="<?= h($r['id'] ?? '') ?>">
      <div><label style="<?= $lbl ?>">Nome</label><input name="nome" value="<?= h($r['nome'] ?? '') ?>" style="<?= $inp ?>; width:190px" required></div>
      <div><label style="<?= $lbl ?>">Tipo</label>
        <select name="tipo" style="<?= $inp ?>" onchange="this.form.querySelector('.rq-campo').style.display=this.value==='campo'?'':'none';this.form.querySelector('.rq-anexo').style.display=this.value==='anexo'?'':'none'">
          <option value="campo" <?= $tipo === 'campo' ? 'selected' : '' ?>>Campo</option>
          <option value="anexo" <?= $tipo === 'anexo' ? 'selected' : '' ?>>Anexo</option>
        </select></div>
      <div class="rq-campo" style="<?= $tipo === 'campo' ? '' : 'display:none' ?>"><label style="<?= $lbl ?>">Campo</label>
        <select name="chave_campo" style="<?= $inp ?>"><?= $opcoesCampo($cat, $tipo === 'campo' ? ($r['chave'] ?? '') : '') ?></select></div>
      <div class="rq-anexo" style="<?= $tipo === 'anexo' ? '' : 'display:none' ?>"><label style="<?= $lbl ?>">Tipo de anexo</label>
        <input name="chave_anexo" value="<?= h($tipo === 'anexo' ? ($r['chave'] ?? '') : '') ?>" placeholder="ex.: comprovante_pagamento" style="<?= $inp ?>; width:190px"></div>
      <div><label style="<?= $lbl ?>">Trava o OK da fase</label>
        <select name="fase_id" style="<?= $inp ?>"><?= $opcoesFase($fases, $r['fase_id'] ?? '') ?></select></div>
      <div><label style="<?= $lbl ?>">Só se…</label>
        <select name="condicao_campo" style="<?= $inp ?>"><?= $opcoesCampo($cat, $r['condicao_campo'] ?? '', true) ?></select></div>
      <div><label style="<?= $lbl ?>">&nbsp;</label>
        <select name="condicao_op" style="<?= $inp ?>"><option value="em" <?= ($r['condicao_op'] ?? 'em') === 'em' ? 'selected' : '' ?>>for</option><option value="nao_em" <?= ($r['condicao_op'] ?? '') === 'nao_em' ? 'selected' : '' ?>>não for</option></select></div>
      <div><label style="<?= $lbl ?>">Valores</label>
        <input name="condicao_valores" value="<?= h(str_replace('|', ', ', (string) ($r['condicao_valores'] ?? ''))) ?>" placeholder="Novos, Seminovos" style="<?= $inp ?>; width:170px"></div>
      <div><label style="<?= $lbl ?>">Ordem</label><input name="ordem" type="number" value="<?= (int) ($r['ordem'] ?? 0) ?>" style="<?= $inp ?>; width:70px"></div>
      <div><label style="<?= $lbl ?>">Ativa</label>
        <select name="ativo" style="<?= $inp ?>"><option value="1" <?= ($r['ativo'] ?? 1) ? 'selected' : '' ?>>Sim</option><option value="0" <?= !($r['ativo'] ?? 1) ? 'selected' : '' ?>>Não</option></select></div>
      <button type="submit" style="<?= $btnP ?>"><?= $novo ? 'Adicionar' : 'Salvar' ?></button>
    </form>
    <?php
};
?>

<?php foreach ($requisitos as $r): ?>
  <div style="<?= $card ?>; padding:14px 16px; margin-bottom:10px; <?= $r['ativo'] ? '' : 'opacity:.6' ?>"><?php $formReq($r, $fasesCfg, $catalogo); ?></div>
<?php endforeach; ?>

<details style="<?= $card ?>">
  <summary style="cursor:pointer; font-weight:700; color:#2347C8">+ Nova exigência</summary>
  <div style="margin-top:12px"><?php $formReq([], $fasesCfg, $catalogo); ?></div>
</details>
