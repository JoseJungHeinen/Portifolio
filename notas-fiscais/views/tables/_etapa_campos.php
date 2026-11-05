<?php
// @versao Tab_08 V_04_19/08/2026
/**
 * views/tables/_etapa_campos.php — campos extras dos modais da lista Etapas.
 * Incluído por items.php com $p = prefixo dos ids ('novo' ou 'edit').
 */
$p = $p ?? 'novo';
$listasDecisao = $listasDecisao ?? [];
?>
<label style="display:block; font-size:13px; font-weight:700; color:#606060; margin:14px 0 6px">
  Descrição <span style="font-size:11px; color:#888; font-weight:400">(opcional)</span>
</label>
<input type="text" name="descricao" id="<?= $p ?>-descricao" maxlength="255" placeholder="Para que serve esta etapa"
       style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
       onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">

<label style="display:block; font-size:13px; font-weight:700; color:#606060; margin:14px 0 6px">
  SLA padrão <span style="font-size:11px; color:#888; font-weight:400">(opcional — vale para todos os fluxos que usam esta etapa)</span>
</label>
<div style="display:flex; gap:8px">
  <input type="number" name="sla_valor" id="<?= $p ?>-sla-valor" min="0" step="1" placeholder="Ex.: 48"
         style="flex:1; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
         onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
  <select name="sla_unidade" id="<?= $p ?>-sla-unidade"
          style="width:110px; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 8px; font-size:14px; color:#141922; background:#fff">
    <option value="minutos">Minutos</option>
    <option value="horas" selected>Horas</option>
    <option value="dias">Dias</option>
  </select>
</div>

<label style="display:block; font-size:13px; font-weight:700; color:#606060; margin:14px 0 6px">
  Aceita como aprovador
</label>
<select name="tipo_aprovador" id="<?= $p ?>-tipo-aprovador"
        style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; background:#fff">
  <option value="ambos" selected>Grupo ou usuário</option>
  <option value="grupo">Apenas grupo</option>
  <option value="usuario">Apenas usuário</option>
</select>

<label style="display:flex; align-items:flex-start; gap:10px; margin:16px 0 0; cursor:pointer">
  <input type="checkbox" name="encerra_automaticamente" id="<?= $p ?>-encerra" value="1" style="margin-top:3px">
  <span>
    <span style="display:block; font-size:13px; font-weight:700; color:#141922">Encerra automaticamente (arquivamento)</span>
    <span style="display:block; font-size:12px; color:#888">Ao atingir esta etapa, o processo é concluído e arquivado na hora — sem aprovador. Registra quem executou o vínculo.</span>
  </span>
</label>

<label style="display:flex; align-items:flex-start; gap:10px; margin:12px 0 0; cursor:pointer">
  <input type="checkbox" name="visualizacao_restrita" id="<?= $p ?>-restrita" value="1" style="margin-top:3px">
  <span>
    <span style="display:block; font-size:13px; font-weight:700; color:#141922">Visualização restrita</span>
    <span style="display:block; font-size:12px; color:#888">Enquanto o processo estiver nesta etapa, somente os aprovadores da etapa (e administradores) podem visualizá-lo.</span>
  </span>
</label>

<label style="display:flex; align-items:flex-start; gap:10px; margin:12px 0 0; cursor:pointer">
  <input type="checkbox" name="somente_observacao" id="<?= $p ?>-observacao" value="1" style="margin-top:3px"
         onchange="etapaObservacaoMudou('<?= $p ?>')">
  <span>
    <span style="display:block; font-size:13px; font-weight:700; color:#141922">Somente observação</span>
    <span style="display:block; font-size:12px; color:#888">O responsável pela etapa passa a enxergar o processo desde a abertura, sem nunca precisar agir: aparece em Processos, não aparece em Minhas Tarefas e o fluxo passa direto pela etapa.</span>
  </span>
</label>

<label style="display:flex; align-items:flex-start; gap:10px; margin:12px 0 0; cursor:pointer">
  <input type="checkbox" name="salto_incondicional" id="<?= $p ?>-salto" value="1" style="margin-top:3px"
         onchange="etapaDestinoMudou('<?= $p ?>')">
  <span>
    <span style="display:block; font-size:13px; font-weight:700; color:#141922">Ir para (salto incondicional)</span>
    <span style="display:block; font-size:12px; color:#888">Sem aprovador e sem pergunta: ao ser atingida, manda o processo para a etapa que o template indicar, pulando o que ficou no caminho. Serve para um ramo reencontrar o tronco.</span>
  </span>
</label>

<label style="display:flex; align-items:flex-start; gap:10px; margin:12px 0 0; cursor:pointer">
  <input type="checkbox" name="abre_fluxo" id="<?= $p ?>-abre-fluxo" value="1" style="margin-top:3px"
         onchange="etapaDestinoMudou('<?= $p ?>')">
  <span>
    <span style="display:block; font-size:13px; font-weight:700; color:#141922">Abrir Fluxo (encadeia outro template)</span>
    <span style="display:block; font-size:12px; color:#888">Ao ser atingida, abre um processo NOVO no template que o fluxo indicar — levando nota, campos de mesmo nome e anexos — e encerra o processo atual. O novo processo segue sozinho, com quem aprovou esta etapa como solicitante.</span>
  </span>
</label>

<?php if ($listasDecisao): ?>
<label style="display:block; font-size:13px; font-weight:700; color:#606060; margin:16px 0 6px">
  Etapa de decisão <span style="font-size:11px; color:#888; font-weight:400">(opcional)</span>
</label>
<select name="decisao_lista_id" id="<?= $p ?>-decisao-lista"
        style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; background:#fff">
  <option value="">— Etapa comum (sem decisão) —</option>
  <?php foreach ($listasDecisao as $ld): ?>
  <option value="<?= h($ld['id']) ?>" data-indices="<?= (int) $ld['indices'] ?>" data-sem="<?= (int) $ld['sem_indice'] ?>">
    <?= h($ld['nome']) ?> — <?= (int) $ld['indices'] ?> índice(s)<?= (int) $ld['sem_indice'] > 0 ? ' · ' . (int) $ld['sem_indice'] . ' item(ns) sem índice' : '' ?>
  </option>
  <?php endforeach; ?>
</select>
<span id="<?= $p ?>-decisao-aviso" style="display:block; font-size:12px; color:#888; margin-top:5px">
  Vinculando uma lista, esta etapa passa a dividir o fluxo: o template que a usar
  precisará indicar, para cada índice da lista, para qual etapa o processo segue.
  Sem vínculo, a etapa é comum e nada muda.
</span>
<?php endif; ?>

<script>
/* Observação e arquivamento automático se excluem — um encerra o processo, o
   outro só olha. O backend também desempata, isto aqui é só para a marcação
   não parecer válida na tela.

   Decisão também não convive com observação: o fluxo passa direto pela etapa
   de observação, então ela nunca chegaria a avaliar a lista. */
function etapaObservacaoMudou(p) {
  etapaDestinoMudou(p);
}

/* As quatro naturezas da etapa se excluem: observação (só olha), arquivamento
   (encerra), Ir para (manda para outra etapa) e Abrir Fluxo (abre outro
   processo e encerra). Decisão também não convive com nenhuma delas — o fluxo
   nunca chegaria a avaliar a lista, ou já teria destino fixo.

   O backend desempata de novo em tableExtrairAtributosEtapa(); isto aqui só
   evita a marcação parecer válida na tela. */
function etapaDestinoMudou(p) {
  var obs     = document.getElementById(p + '-observacao');
  var encerra = document.getElementById(p + '-encerra');
  var salto   = document.getElementById(p + '-salto');
  var abre    = document.getElementById(p + '-abre-fluxo');
  if (!obs) return;

  // Observação vence tudo: é a mais restritiva, não encerra nem manda ninguém.
  if (obs.checked) {
    [encerra, salto, abre].forEach(function (el) { if (el) el.checked = false; });
  } else if (salto && salto.checked) {
    [encerra, abre].forEach(function (el) { if (el) el.checked = false; });
  } else if (abre && abre.checked) {
    if (encerra) encerra.checked = false;
  }

  function trava(el, bloqueado) {
    if (!el) return;
    el.disabled = bloqueado;
    var lb = el.closest('label');
    if (lb) lb.style.opacity = bloqueado ? '0.45' : '1';
  }
  trava(encerra, obs.checked || (salto && salto.checked) || (abre && abre.checked));
  trava(salto,   obs.checked || (abre && abre.checked));
  trava(abre,    obs.checked || (salto && salto.checked));

  var dec = document.getElementById(p + '-decisao-lista');
  if (dec) {
    var bloqueiaDec = obs.checked
                   || (encerra && encerra.checked)
                   || (salto && salto.checked)
                   || (abre && abre.checked);
    if (bloqueiaDec) { dec.value = ''; }
    dec.disabled = bloqueiaDec;
    dec.style.background = bloqueiaDec ? '#F6F6F6' : '#fff';
  }
}

/* Item sem índice não tem para onde ir quando a etapa de decisão avaliar a
   lista. Avisa no cadastro, que é onde dá para resolver — não na hora em que
   um processo travar. */
function etapaDecisaoMudou(p) {
  var sel   = document.getElementById(p + '-decisao-lista');
  var aviso = document.getElementById(p + '-decisao-aviso');
  if (!sel || !aviso) return;
  var opt = sel.options[sel.selectedIndex];
  var sem = opt ? parseInt(opt.getAttribute('data-sem') || '0', 10) : 0;
  if (sel.value !== '' && sem > 0) {
    aviso.textContent = 'Atenção: ' + sem + ' item(ns) desta lista está(ão) sem índice. '
      + 'Enquanto isso, um processo que cair num desses itens não terá caminho definido.';
    aviso.style.color = '#B3261E';
  } else {
    aviso.textContent = 'Vinculando uma lista, esta etapa passa a dividir o fluxo: o template '
      + 'que a usar precisará indicar, para cada índice da lista, para qual etapa o processo '
      + 'segue. Sem vínculo, a etapa é comum e nada muda.';
    aviso.style.color = '#888';
  }
}
document.addEventListener('DOMContentLoaded', function () {
  ['novo', 'edit'].forEach(function (p) {
    var sel = document.getElementById(p + '-decisao-lista');
    if (sel) sel.addEventListener('change', function () { etapaDecisaoMudou(p); });
    // Estado inicial: no modal de edição as marcas já vêm preenchidas, então as
    // travas precisam refletir isso antes de qualquer clique.
    if (document.getElementById(p + '-observacao')) etapaDestinoMudou(p);
  });
});
</script>
