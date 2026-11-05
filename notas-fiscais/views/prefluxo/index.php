<?php
// @versao Pre_01 V_06_05/08/2026
/**
 * views/prefluxo/index.php
 * Configurador de vínculos — regras CNPJ × CNPJ → template
 *
 * Variáveis esperadas:
 *   $regras     array  regras de pré-fluxo (já filtradas)
 *   $templates  array  templates publicados
 *   $opcoes     array  opções dos selects de filtro (origens/destinos/templates)
 *   $filtros    array  filtros aplicados (origem/destino/template)
 */

$regras       = $regras       ?? [];
$templates    = $templates    ?? [];
$opcoes       = $opcoes       ?? ['origens' => [], 'destinos' => [], 'templates' => []];
$filtros      = $filtros      ?? ['origem' => '', 'destino' => '', 'template' => '', 'status' => 'ativas'];
$ambiguidades = $ambiguidades ?? [];   // colisões da última aplicação → modal

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function pfFmtCnpj(?string $cnpj): string {
    // NULL/vazio = "qualquer fornecedor" (fornecedor_cnpj é opcional desde a migration 017)
    if ($cnpj === null || $cnpj === '') return 'Qualquer';
    if (strlen($cnpj) !== 14) return h($cnpj);
    return substr($cnpj,0,2).'.'.substr($cnpj,2,3).'.'.substr($cnpj,5,3).'/'.substr($cnpj,8,4).'-'.substr($cnpj,12,2);
}
?>
<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">

<?php include VIEWS . '/components/alert.php'; ?>

<!-- Page Header -->
<div class="page-header" style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:28px; flex-wrap:wrap; gap:12px">
  <div>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
      Pré-fluxo <span style="color:#2347C8">de Vínculos</span>
    </h1>
    <p style="font-size:14px; color:#888; margin:0">Regras que vinculam notas capturadas a fluxos automaticamente, com campos pré-configurados</p>
  </div>
  <a href="?module=prefluxo&amp;action=novo"
     style="display:inline-flex; align-items:center; gap:8px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; text-decoration:none">
    <i data-lucide="plus" width="16" height="16"></i>
    Nova Regra
  </a>
</div>

<!-- Filtros -->
<?php
  $pfFiltroAtivo = ($filtros['origem'] ?? '') !== '' || ($filtros['destino'] ?? '') !== ''
                   || ($filtros['template'] ?? '') !== '' || ($filtros['status'] ?? 'ativas') !== 'ativas';
  $pfSelectStyle = 'height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; color:#141922; background:#fff; min-width:220px; max-width:320px';
?>
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:16px 20px; margin-bottom:20px">
  <form method="GET" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap">
    <input type="hidden" name="module" value="prefluxo">
    <input type="hidden" name="action" value="index">

    <div>
      <label for="pf-f-origem" style="display:block; font-size:12px; font-weight:700; color:#888; text-transform:uppercase; margin-bottom:6px">Origem</label>
      <select id="pf-f-origem" name="origem" style="<?= $pfSelectStyle ?>">
        <option value="">Todas as origens</option>
        <?php foreach ($opcoes['origens'] as $o):
          $cnpj = (string)($o['cnpj'] ?? '');
          $val  = $cnpj === '' ? '__qualquer__' : $cnpj;
          $lbl  = $cnpj === '' ? 'Qualquer fornecedor'
                : pfFmtCnpj($cnpj) . (!empty($o['nome']) ? ' — ' . h($o['nome']) : '');
        ?>
        <option value="<?= h($val) ?>" <?= ($filtros['origem'] ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label for="pf-f-destino" style="display:block; font-size:12px; font-weight:700; color:#888; text-transform:uppercase; margin-bottom:6px">Destino</label>
      <select id="pf-f-destino" name="destino" style="<?= $pfSelectStyle ?>">
        <option value="">Todos os destinos</option>
        <?php foreach ($opcoes['destinos'] as $d):
          $cnpj = (string)($d['cnpj'] ?? '');
          $lbl  = (!empty($d['nome']) ? h($d['nome']) . ' — ' : '') . pfFmtCnpj($cnpj);
        ?>
        <option value="<?= h($cnpj) ?>" <?= ($filtros['destino'] ?? '') === $cnpj ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label for="pf-f-template" style="display:block; font-size:12px; font-weight:700; color:#888; text-transform:uppercase; margin-bottom:6px">Template</label>
      <select id="pf-f-template" name="template" style="<?= $pfSelectStyle ?>">
        <option value="">Todos os templates</option>
        <?php foreach ($opcoes['templates'] as $t):
          $tid = (string)($t['id'] ?? '');
          $val = $tid === '' ? '__sem__' : $tid;
          $lbl = $tid === '' ? 'Sem template (arquivar)' : h($t['nome'] ?? $tid);
        ?>
        <option value="<?= h($val) ?>" <?= ($filtros['template'] ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label for="pf-f-status" style="display:block; font-size:12px; font-weight:700; color:#888; text-transform:uppercase; margin-bottom:6px">Status</label>
      <select id="pf-f-status" name="status" style="<?= $pfSelectStyle ?>; min-width:160px">
        <?php $pfStatus = $filtros['status'] ?? 'ativas'; ?>
        <option value="ativas"   <?= $pfStatus === 'ativas'   ? 'selected' : '' ?>>Somente ativas</option>
        <option value="inativas" <?= $pfStatus === 'inativas' ? 'selected' : '' ?>>Somente inativas</option>
        <option value="todas"    <?= $pfStatus === 'todas'    ? 'selected' : '' ?>>Todas</option>
      </select>
    </div>

    <?php // Botão único: sem filtro aplicado ele filtra; com filtro aplicado ele
          // limpa. Para trocar de recorte: Limpar → escolher → Filtrar. ?>
    <?php if ($pfFiltroAtivo): ?>
    <a href="?module=prefluxo&amp;action=index&amp;limpar=1" title="Remover filtros e mostrar todas as regras"
       style="height:40px; display:inline-flex; align-items:center; gap:6px; background:#EAEAEA; color:#606060; border-radius:4px; padding:0 20px; font-size:14px; font-weight:700; text-decoration:none">
      <i data-lucide="x" width="15" height="15"></i> Limpar
    </a>
    <?php else: ?>
    <button type="submit"
            style="height:40px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:0 20px; font-size:14px; font-weight:700; cursor:pointer">
      Filtrar
    </button>
    <?php endif; ?>

    <div style="margin-left:auto; align-self:center; font-size:13px; color:#888">
      <?= count($regras) ?> regra(s)<?= $pfFiltroAtivo ? ' no filtro' : '' ?>
    </div>
  </form>
</div>

<!-- Tabela de regras -->
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden">
  <table style="width:100%; border-collapse:collapse">
    <thead>
      <tr style="background:#606060">
        <th style="padding:12px 16px; text-align:left; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase">Regra</th>
        <th style="padding:12px 16px; text-align:left; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase">Origem → Destino</th>
        <th style="padding:12px 16px; text-align:left; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase">Template</th>
        <th style="padding:12px 16px; text-align:center; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase">Modo</th>
        <th style="padding:12px 16px; text-align:center; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase">Disparos</th>
        <th style="padding:12px 16px; text-align:center; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase">Status</th>
        <th style="padding:12px 16px; text-align:center; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase">Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($regras)): ?>
      <tr>
        <td colspan="7" style="padding:48px 20px; text-align:center">
          <i data-lucide="git-branch" width="40" height="40" style="color:#EAEAEA"></i>
          <?php if ($pfFiltroAtivo): ?>
          <div style="font-size:16px; font-weight:700; color:#141922; margin-top:10px">Nenhuma regra neste filtro</div>
          <p style="font-size:13px; color:#888; margin:6px 0 0">Ajuste os campos de Origem, Destino ou Template — ou <a href="?module=prefluxo&amp;action=index" style="color:#2347C8; font-weight:700; text-decoration:none">limpe os filtros</a>.</p>
          <?php else: ?>
          <div style="font-size:16px; font-weight:700; color:#141922; margin-top:10px">Nenhuma regra configurada</div>
          <p style="font-size:13px; color:#888; margin:6px 0 0">Crie a primeira regra para automatizar o vínculo de notas recorrentes.</p>
          <?php endif; ?>
        </td>
      </tr>
      <?php else: ?>
      <?php foreach ($regras as $i => $r):
        $campos  = json_decode($r['campos_json'], true) ?: [];
        $bgLinha = $i % 2 === 0 ? '#FFFFFF' : '#F8F8F8';
      ?>
      <tr style="background:<?= $bgLinha ?>"
          onmouseover="this.style.background='#EEF2FD'" onmouseout="this.style.background='<?= $bgLinha ?>'">
        <td style="padding:14px 16px">
          <div style="font-weight:700; font-size:14px; color:#141922"><?= h($r['nome']) ?></div>
          <div style="font-size:12px; color:#888; margin-top:2px">
            <?= count($campos) ?> campo(s) pré-configurado(s)
            <?php if (!empty($r['cfop'])): ?> · CFOP <?= h($r['cfop']) ?><?php endif; ?>
          </div>
          <?php if ((int)$r['precisa_revisao'] === 1): ?>
          <span style="background:#FFE8EE; color:#B3261E; font-size:10px; font-weight:700; text-transform:uppercase; padding:3px 8px; border-radius:8px; display:inline-block; margin-top:4px">Revisar — template mudou</span>
          <?php endif; ?>
        </td>
        <td style="padding:14px 16px; font-size:13px; color:#141922">
          <div><?= pfFmtCnpj($r['fornecedor_cnpj']) ?></div>
          <div style="color:#888; font-size:12px; margin:2px 0">
            <i data-lucide="arrow-down" width="12" height="12" style="vertical-align:-2px"></i>
            <?= h($r['filial_nome'] ?? '') ?>
          </div>
          <div><?= pfFmtCnpj($r['destinatario_cnpj']) ?></div>
        </td>
        <td style="padding:14px 16px; font-size:13px; color:#141922"><?= h($r['template_nome'] ?? '—') ?></td>
        <td style="padding:14px 16px; text-align:center">
          <?php if ($r['modo'] === 'automatico'): ?>
          <span style="background:#D8F5E5; color:#00A960; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px">Automático</span>
          <?php elseif ($r['modo'] === 'arquivar'): ?>
          <span style="background:#EAEAEA; color:#606060; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px">Arquivar</span>
          <?php else: ?>
          <span style="background:#FFF3E0; color:#FF8A2D; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px">Sugerir</span>
          <?php endif; ?>
        </td>
        <td style="padding:14px 16px; text-align:center; font-size:14px; font-weight:700; color:#141922">
          <?= (int)$r['total_disparos'] ?>
          <?php if (!empty($r['ultima_execucao'])): ?>
          <div style="font-size:11px; font-weight:400; color:#888"><?= date('d/m H:i', strtotime($r['ultima_execucao'])) ?></div>
          <?php endif; ?>
        </td>
        <td style="padding:14px 16px; text-align:center">
          <?php if ((int)$r['ativo'] === 1): ?>
          <span style="background:#D8F5E5; color:#00A960; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px">Ativa</span>
          <?php else: ?>
          <span style="background:#EAEAEA; color:#888; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px">Inativa</span>
          <?php endif; ?>
        </td>
        <td style="padding:14px 16px; text-align:center; white-space:nowrap">
          <a href="?module=prefluxo&amp;action=detalhe&amp;id=<?= h($r['id']) ?>"
             style="display:inline-flex; align-items:center; gap:4px; border:1px solid #2347C8; color:#2347C8; border-radius:4px; padding:6px 12px; font-size:12px; font-weight:700; text-decoration:none; margin-right:6px">
            <i data-lucide="pencil" width="13" height="13"></i> Editar
          </a>
          <?php if ((int)$r['ativo'] === 1 && (int)$r['precisa_revisao'] === 0): ?>
          <form method="POST" action="?module=prefluxo&amp;action=aplicar" style="display:inline"
                onsubmit="return confirm('Aplicar a regra \'<?= h(addslashes($r['nome'])) ?>\' às notas pendentes agora?<?= $r['modo'] === 'automatico' ? '\n\nModo Automático: processos serão abertos sem confirmação.' : '' ?>')">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="id" value="<?= h($r['id']) ?>">
            <button type="submit" title="Aplicar somente esta regra às notas pendentes"
                    style="display:inline-flex; align-items:center; gap:4px; background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:6px 12px; font-size:12px; font-weight:700; cursor:pointer; margin-right:6px">
              <i data-lucide="play" width="13" height="13"></i> Aplicar
            </button>
          </form>
          <?php endif; ?>
          <form method="POST" action="?module=prefluxo&amp;action=toggle" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="id" value="<?= h($r['id']) ?>">
            <button type="submit"
                    style="background:#EAEAEA; color:#606060; border:none; border-radius:4px; padding:7px 12px; font-size:12px; font-weight:700; cursor:pointer; margin-right:6px">
              <?= (int)$r['ativo'] === 1 ? 'Desativar' : 'Ativar' ?>
            </button>
          </form>
          <form method="POST" action="?module=prefluxo&amp;action=excluir" style="display:inline"
                onsubmit="return confirm('Excluir a regra \'<?= h(addslashes($r['nome'])) ?>\'?\n\nAção irreversível. Sugestões pendentes desta regra voltam para a fila manual.')">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="id" value="<?= h($r['id']) ?>">
            <button type="submit" title="Excluir esta regra definitivamente"
                    style="display:inline-flex; align-items:center; gap:4px; background:#B3261E; color:#fff; border:none; border-radius:4px; padding:7px 12px; font-size:12px; font-weight:700; cursor:pointer">
              <i data-lucide="trash-2" width="13" height="13"></i> Excluir
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if (!empty($ambiguidades)): ?>
<!-- Modal: notas ambíguas (casaram com mais de uma regra) -->
<div id="modalAmbiguidade"
     style="position:fixed; inset:0; background:rgba(24,19,31,0.55); z-index:1000; display:flex; align-items:center; justify-content:center; padding:20px"
     onclick="if (event.target === this) this.remove()">
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.25) 0 8px 32px; max-width:640px; width:100%; max-height:85vh; overflow-y:auto">
    <div style="display:flex; align-items:flex-start; gap:12px; padding:20px 24px; border-bottom:1px solid #EAEAEA">
      <i data-lucide="alert-triangle" width="24" height="24" style="color:#B3261E; flex-shrink:0; margin-top:2px"></i>
      <div style="flex:1">
        <h3 style="font-size:20px; font-weight:700; color:#141922; margin:0">Conflito <span style="color:#B3261E">de regras</span></h3>
        <p style="font-size:13px; color:#606060; margin:4px 0 0">
          <?= count($ambiguidades) ?> nota(s) casaram com mais de uma regra e ficaram na decisão manual.
          Ajuste os critérios das regras abaixo para desfazer o empate.
        </p>
      </div>
      <button onclick="document.getElementById('modalAmbiguidade').remove()"
              style="background:none; border:none; cursor:pointer; padding:4px; color:#888">
        <i data-lucide="x" width="20" height="20"></i>
      </button>
    </div>

    <div style="padding:16px 24px">
      <?php foreach ($ambiguidades as $amb): ?>
      <div style="border:1px solid #EAEAEA; border-left:4px solid #B3261E; border-radius:8px; padding:14px 16px; margin-bottom:12px">
        <div style="display:flex; justify-content:space-between; align-items:baseline; gap:12px; flex-wrap:wrap">
          <div>
            <span style="font-weight:700; font-size:14px; color:#141922">NF <?= h($amb['numero_nf']) ?></span>
            <span style="background:#141922; color:#fff; font-size:10px; font-weight:700; padding:2px 8px; border-radius:8px; margin-left:6px"><?= h($amb['tipo_documento']) ?></span>
          </div>
          <span style="font-size:14px; font-weight:700; color:#2347C8">R$ <?= number_format((float)$amb['valor'], 2, ',', '.') ?></span>
        </div>
        <div style="font-size:13px; color:#606060; margin-top:2px"><?= h($amb['fornecedor_nome']) ?></div>
        <div style="font-size:12px; font-weight:700; color:#888; text-transform:uppercase; margin:10px 0 6px">Regras em conflito</div>
        <?php foreach ($amb['regras'] as $rg): ?>
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:6px 0; border-top:1px solid #F6F6F6">
          <span style="font-size:13px; color:#141922"><i data-lucide="git-branch" width="13" height="13" style="vertical-align:-2px; color:#888"></i> <?= h($rg['nome']) ?></span>
          <a href="?module=prefluxo&amp;action=detalhe&amp;id=<?= h($rg['id']) ?>"
             style="display:inline-flex; align-items:center; gap:4px; border:1px solid #2347C8; color:#2347C8; border-radius:4px; padding:4px 10px; font-size:12px; font-weight:700; text-decoration:none">
            <i data-lucide="pencil" width="12" height="12"></i> Editar
          </a>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="padding:14px 24px; border-top:1px solid #EAEAEA; text-align:right">
      <button onclick="document.getElementById('modalAmbiguidade').remove()"
              style="background:#EAEAEA; color:#606060; border:none; border-radius:4px; padding:9px 20px; font-size:13px; font-weight:700; cursor:pointer">Fechar</button>
    </div>
  </div>
</div>
<?php endif; ?>
