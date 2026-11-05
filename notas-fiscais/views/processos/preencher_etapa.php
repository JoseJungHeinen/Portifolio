<?php
// @versao Proc_13 V_01_19/08/2026
/**
 * Formulário enxuto para o responsável da etapa preencher os campos que o
 * template exige NELA.
 *
 * Deliberadamente separado de editar_dados: aqui não há nota, fornecedor nem
 * parcela — só os campos marcados com `etapa_ordem` = etapa atual. Quem está na
 * etapa consegue cumprir a exigência sem receber junto a permissão de reescrever
 * o processo inteiro.
 *
 * Recebe do controller: $processoPre, $numEtapaPre, $camposEtapa, $dadosAtuais,
 * $etapaPre, $embedQS, $backQS.
 */
if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
$inputStyle = 'width:100%;height:40px;border:1px solid #DFDFDF;border-radius:4px;padding:0 12px;'
            . 'font-size:14px;color:#141922;box-sizing:border-box;outline:none';
?>
<div style="max-width:760px;margin:0 auto">

  <h1 style="font-family:var(--font-bold);font-size:32px;color:#141922;margin:0 0 4px">
    Preencher <span style="color:#2347C8">dados da etapa</span>
  </h1>
  <p style="font-size:14px;color:#606060;margin:0 0 24px">
    Processo <strong><?= h($processoPre['codigo'] ?? '') ?></strong> ·
    Etapa <?= (int) $numEtapaPre ?> — <?= h($etapaPre['nome_etapa'] ?? '') ?>
  </p>

  <form method="POST"
        action="?module=processos&amp;action=preencher_etapa&amp;id=<?= h($processoPre['id']) ?><?= $embedQS ?? '' ?><?= $backQS ?? '' ?>">
    <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
    <input type="hidden" name="processo_id" value="<?= h($processoPre['id']) ?>">

    <div style="background:#fff;border-radius:12px;box-shadow:rgba(0,0,0,0.10) 0 2px 8px;padding:24px">
      <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px">
        <?php foreach ($camposEtapa as $campo):
            $nomeCampo  = (string) ($campo['nome'] ?? 'campo');
            $tipoCampo  = (string) ($campo['tipo'] ?? 'texto');
            $labelCampo = (string) ($campo['label'] ?? $nomeCampo);
            $obrig      = !empty($campo['obrigatorio']);
            $valAtual   = (string) ($dadosAtuais[$nomeCampo] ?? '');
            $largura    = in_array($tipoCampo, ['texto_longo', 'textarea'], true) ? '1 / -1' : 'auto';
        ?>
        <div style="grid-column:<?= $largura ?>">
          <label style="display:block;font-family:var(--font-bold);font-size:13px;color:#141922;margin-bottom:6px">
            <?= h($labelCampo) ?><?= $obrig ? ' <span style="color:#2347C8">*</span>' : '' ?>
          </label>

          <?php if ($tipoCampo === 'lista' || $tipoCampo === 'select'): ?>
            <select name="campo[<?= h($nomeCampo) ?>]" <?= $obrig ? 'required' : '' ?>
                    style="<?= $inputStyle ?>"
                    onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
              <option value="">Selecione...</option>
              <?php foreach (($campo['opcoes'] ?? []) as $opc): ?>
              <option value="<?= h($opc['id']) ?>" <?= $valAtual === (string) $opc['id'] ? 'selected' : '' ?>>
                <?= h($opc['nome']) ?>
              </option>
              <?php endforeach; ?>
            </select>

          <?php elseif ($tipoCampo === 'texto_longo' || $tipoCampo === 'textarea'): ?>
            <textarea name="campo[<?= h($nomeCampo) ?>]" rows="4" <?= $obrig ? 'required' : '' ?>
                      style="<?= $inputStyle ?>;height:auto;padding:10px 12px"
                      onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'"><?= h($valAtual) ?></textarea>

          <?php elseif ($tipoCampo === 'numero' || $tipoCampo === 'data'): ?>
            <input type="<?= $tipoCampo === 'data' ? 'date' : 'number' ?>"
                   name="campo[<?= h($nomeCampo) ?>]" value="<?= h($valAtual) ?>"
                   <?= $obrig ? 'required' : '' ?> style="<?= $inputStyle ?>"
                   onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">

          <?php else: ?>
            <input type="text" name="campo[<?= h($nomeCampo) ?>]" value="<?= h($valAtual) ?>"
                   <?= $obrig ? 'required' : '' ?> style="<?= $inputStyle ?>"
                   onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="display:flex;gap:12px;margin-top:24px">
      <a href="?module=processos&amp;action=detalhe&amp;id=<?= h($processoPre['id']) ?><?= $embedQS ?? '' ?><?= $backQS ?? '' ?>"
         style="padding:10px 20px;background:#EAEAEA;color:#606060;border-radius:4px;
                font-family:var(--font-bold);font-size:14px;text-decoration:none">Cancelar</a>
      <button type="submit"
              style="padding:10px 24px;background:#2347C8;color:#fff;border:none;border-radius:4px;
                     font-family:var(--font-bold);font-size:14px;cursor:pointer">Salvar dados</button>
    </div>
  </form>
</div>
