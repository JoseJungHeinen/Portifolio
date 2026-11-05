<?php
// @versao Proc_05 V_07_11/09/2026
/**
 * views/processos/novo/step3.php
 * Wizard — Etapa 3: Revisão e Confirmação
 *
 * Variáveis esperadas:
 *   $wizard   array  dados completos da sessão do wizard
 *   $flash    array|null
 *   $pisCheck array{bloqueio:?string, aviso:?string, servico:bool}  PIS/NIT do prestador
 */

$wizard        = $wizard        ?? $_SESSION['wizard_processos'] ?? [];
$dados         = $wizard['dados_step2'] ?? [];
$etapas        = $wizard['etapas_json'] ?? [];
$parcelas      = $dados['parcelas']     ?? [];
$isTeste       = !empty($wizard['is_teste']);
$emEdicao      = !empty($wizard['edit_processo_id']);
$editVoltarUrl = $emEdicao
    ? '?module=processos&action=detalhe&id=' . rawurlencode((string) $wizard['edit_processo_id']) . ($wizard['edit_back_qs'] ?? '')
    : '?module=processos&action=step2';
$filialNome    = $filialNome    ?? '';
$setorNome     = $setorNome     ?? '';
$tipoNotaNome  = $tipoNotaNome  ?? '';
$pisCheck      = $pisCheck      ?? ['bloqueio' => null, 'aviso' => null, 'servico' => false];

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>

<?php if (!empty($flash)): ?>
<?php
$alertType    = $flash['type']    ?? 'info';
$alertMessage = $flash['message'] ?? '';
include VIEWS . '/components/alert.php';
?>
<?php endif; ?>

<?php if ($pisCheck['bloqueio'] !== null || $pisCheck['aviso'] !== null):
  $pisBloqueia = $pisCheck['bloqueio'] !== null;
?>
<div style="display:flex; gap:12px; align-items:flex-start; background:<?= $pisBloqueia ? '#FFE8EE' : '#FFF3E0' ?>; border-left:4px solid <?= $pisBloqueia ? '#B3261E' : '#FF8A2D' ?>; border-radius:8px; padding:14px 16px; margin-bottom:20px">
  <i data-lucide="<?= $pisBloqueia ? 'shield-alert' : 'alert-triangle' ?>" width="20" height="20" style="color:<?= $pisBloqueia ? '#B3261E' : '#FF8A2D' ?>; flex-shrink:0; margin-top:1px"></i>
  <div>
    <div style="font-size:14px; font-weight:700; color:#141922; margin-bottom:2px">
      <?= $pisBloqueia ? 'PIS/NIT do prestador pendente' : 'Confirmar se o prestador é MEI' ?>
    </div>
    <div style="font-size:13px; color:#606060"><?= h($pisCheck['bloqueio'] ?? $pisCheck['aviso']) ?></div>
    <a href="?module=tabelas&amp;action=fornecedores&amp;pis=pendente" target="_blank"
       style="display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:700; color:#2347C8; text-decoration:none; margin-top:8px">
      <i data-lucide="external-link" width="14" height="14"></i> Abrir cadastro de fornecedores
    </a>
  </div>
</div>
<?php endif; ?>

<!-- Cabeçalho + indicador de progresso (mesma linha) -->
<div style="margin-bottom:28px; display:flex; align-items:flex-end; justify-content:space-between; gap:24px; flex-wrap:wrap">
  <div>
    <div style="display:flex; align-items:center; gap:12px; margin-bottom:8px">
      <a href="?module=processos&amp;action=step2"
         style="display:inline-flex; align-items:center; gap:6px; color:#606060; text-decoration:none; font-size:14px">
        <i data-lucide="arrow-left" width="16" height="16"></i>
        Voltar e editar
      </a>
    </div>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
      Revisão <span style="color:#2347C8">do Processo</span>
    </h1>
    <p style="font-size:14px; color:#888; margin:0"><?= $emEdicao ? 'Confira os dados completados antes de salvar no processo' : 'Confirme os dados antes de enviar para aprovação' ?></p>
  </div>

  <div class="wizard-steps" style="padding-bottom:4px">
    <div class="wizard-step is-completed">
      <div class="wizard-step-number"><i data-lucide="check" width="14" height="14"></i></div>
      <div class="wizard-step-label">Selecionar Template</div>
    </div>
    <div class="wizard-step-connector is-completed"></div>
    <div class="wizard-step is-completed">
      <div class="wizard-step-number"><i data-lucide="check" width="14" height="14"></i></div>
      <div class="wizard-step-label">Dados da NF</div>
    </div>
    <div class="wizard-step-connector is-completed"></div>
    <div class="wizard-step is-active">
      <div class="wizard-step-number">3</div>
      <div class="wizard-step-label">Revisão</div>
    </div>
  </div>
</div>

<!-- Banner de aviso -->
<div style="display:flex; align-items:flex-start; gap:10px; padding:12px 16px; background:#FFF3CD; border:1px solid #FFE69C; border-radius:8px; margin-bottom:20px; font-size:13px; color:#856404; line-height:1.5">
  <i data-lucide="alert-circle" width="16" height="16" style="flex-shrink:0; margin-top:2px"></i>
  <?php if ($emEdicao): ?>
  <span>Confira os dados abaixo. Ao salvar, as parcelas, tipo de despesa, centro de custo e anexos serão gravados no processo, que continua na etapa de abertura.</span>
  <?php else: ?>
  <span>Revise os dados abaixo antes de enviar. Após o envio, o processo será encaminhado ao primeiro aprovador e <strong>não poderá ser editado.</strong></span>
  <?php endif; ?>
</div>

<!-- Resumo dos dados -->
<div style="display:grid; grid-template-columns:2fr 1fr; gap:20px; align-items:start">

  <!-- Coluna principal -->
  <div>

    <!-- Card: Identificação -->
    <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:24px; margin-bottom:20px">
      <h2 style="font-size:16px; font-weight:700; color:#141922; margin:0 0 16px; display:flex; align-items:center; gap:8px">
        <i data-lucide="clipboard-check" width="18" height="18" style="color:#2347C8"></i>
        Identificação
      </h2>
      <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:14px">
        <div>
          <div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:.06em; margin-bottom:3px">Filial</div>
          <div style="font-size:13px; font-weight:500; color:#141922"><?= h($filialNome ?: '—') ?></div>
        </div>
        <div>
          <div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:.06em; margin-bottom:3px">Departamento</div>
          <div style="font-size:13px; font-weight:500; color:#141922"><?= h($setorNome ?: '—') ?></div>
        </div>
        <div>
          <div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:.06em; margin-bottom:3px">Tipo de nota</div>
          <div style="font-size:13px; font-weight:500; color:#141922"><?= h($tipoNotaNome ?: '—') ?></div>
        </div>
      </div>
      <div style="display:flex; align-items:center; gap:8px; padding:8px 12px; background:#D8F5E5; border:1px solid #00A960; border-radius:6px">
        <i data-lucide="check" width="13" height="13" style="color:#00A960"></i>
        <span style="font-size:12px; font-weight:600; color:#00A960"><?= h($wizard['template_nome'] ?? '—') ?></span>
        <span style="font-size:11px; color:#00A960; margin-left:4px"><?= count($etapas) ?> etapa(s)</span>
        <?php if ($wizard['tem_financeiro'] ?? false): ?>
        <span style="font-size:11px; color:#00A960">· Financeiro</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Card: Dados da nota fiscal -->
    <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:24px; margin-bottom:20px">
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px">
        <h2 style="font-size:16px; font-weight:700; color:#141922; margin:0; display:flex; align-items:center; gap:8px">
          <i data-lucide="file-text" width="18" height="18" style="color:#2347C8"></i>
          Dados da nota fiscal
        </h2>
        <a href="?module=processos&action=step2" style="font-size:12px; font-weight:600; color:#2347C8; text-decoration:none; padding:4px 10px; border:1px solid #2347C8; border-radius:4px">Editar</a>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px 24px; margin-bottom:14px">
        <div>
          <div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:.06em; margin-bottom:3px">Fornecedor</div>
          <div style="font-size:13px; font-weight:500; color:#141922"><?= h($dados['fornecedor_nome'] ?? '—') ?></div>
        </div>
        <div>
          <div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:.06em; margin-bottom:3px">CPF / CNPJ</div>
          <div style="font-size:13px; font-weight:500; color:#141922"><?= h(formatarDoc($dados['fornecedor_doc'] ?? '—')) ?></div>
        </div>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px 24px; margin-bottom:14px">
        <div>
          <div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:.06em; margin-bottom:3px">Número NF</div>
          <div style="font-size:13px; font-weight:500; color:#141922"><?= h($dados['numero_nf'] ?? '—') ?></div>
        </div>
        <div>
          <div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:.06em; margin-bottom:3px">Valor</div>
          <div style="font-size:14px; font-weight:700; color:#2347C8">R$ <?= number_format((float)($dados['valor_nf'] ?? 0), 2, ',', '.') ?></div>
        </div>
        <div>
          <div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:.06em; margin-bottom:3px">Data emissão</div>
          <div style="font-size:13px; font-weight:500; color:#141922"><?= !empty($dados['data_nf']) ? date('d/m/Y', strtotime($dados['data_nf'])) : '—' ?></div>
        </div>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px 24px; margin-bottom:14px">
        <div>
          <div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:.06em; margin-bottom:3px">Emitido para</div>
          <div style="font-size:13px; font-weight:500; color:#141922"><?= h($filialNome ?: '—') ?></div>
        </div>
        <div>
          <div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:.06em; margin-bottom:3px">CNPJ destinatário</div>
          <div style="font-size:12px; font-weight:500; color:#606060; font-family:'Consolas','Courier New',monospace; word-break:break-all"><?= h(formatarDoc($dados['filial_doc'] ?? '—')) ?></div>
        </div>
      </div>
      <?php if (!empty($dados['chave_nf'])): ?>
      <div>
        <div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:.06em; margin-bottom:3px">Chave NF-e</div>
        <div style="font-size:11px; font-weight:500; color:#606060; font-family:'Consolas','Courier New',monospace; word-break:break-all"><?= h($dados['chave_nf']) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Card: Campos do processo (se houver) -->
    <?php if (!empty($dados['dados_dinamicos']) && array_filter($dados['dados_dinamicos'], fn($v) => $v !== '' && $v !== null)): ?>
    <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:24px; margin-bottom:20px">
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px">
        <h2 style="font-size:16px; font-weight:700; color:#141922; margin:0; display:flex; align-items:center; gap:8px">
          <i data-lucide="list" width="18" height="18" style="color:#2347C8"></i>
          Campos do processo
        </h2>
        <a href="?module=processos&action=step2" style="font-size:12px; font-weight:600; color:#2347C8; text-decoration:none; padding:4px 10px; border:1px solid #2347C8; border-radius:4px">Editar</a>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px">
        <?php foreach ($dados['dados_dinamicos'] as $chave => $valor): ?>
        <?php if ($valor !== '' && $valor !== null): ?>
        <div>
          <div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; letter-spacing:.06em; margin-bottom:3px"><?= h($chave) ?></div>
          <div style="font-size:13px; font-weight:500; color:#141922"><?= h($valor) ?></div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Parcelas -->
    <?php if (!empty($parcelas)): ?>
    <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:24px; margin-bottom:20px">
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px">
        <h2 style="font-size:16px; font-weight:700; color:#141922; margin:0; display:flex; align-items:center; gap:8px">
          <i data-lucide="landmark" width="18" height="18" style="color:#2347C8"></i>
          Parcelas (<?= count($parcelas) ?>x)
        </h2>
        <a href="?module=processos&action=step2" style="font-size:12px; font-weight:600; color:#2347C8; text-decoration:none; padding:4px 10px; border:1px solid #2347C8; border-radius:4px">Editar</a>
      </div>
      <table style="width:100%; border-collapse:collapse; font-size:13px">
        <thead>
          <tr style="background:#606060; color:#fff">
            <th style="padding:8px 12px; text-align:center; font-weight:700; text-transform:uppercase">#</th>
            <th style="padding:8px 12px; text-align:left; font-weight:700; text-transform:uppercase">Vencimento</th>
            <th style="padding:8px 12px; text-align:right; font-weight:700; text-transform:uppercase">Valor</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($parcelas as $i => $p): ?>
          <tr style="background:<?= $i % 2 === 0 ? '#fff' : '#F8F8F8' ?>">
            <td style="padding:8px 12px; text-align:center; color:#606060"><?= $i + 1 ?></td>
            <td style="padding:8px 12px; color:#141922">
              <?= !empty($p['vencimento']) ? date('d/m/Y', strtotime($p['vencimento'])) : '—' ?>
            </td>
            <td style="padding:8px 12px; text-align:right; font-weight:700; color:#141922">
              R$ <?= number_format((float)($p['valor'] ?? 0), 2, ',', '.') ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <tr style="background:#F6F6F6; font-weight:700">
            <td colspan="2" style="padding:8px 12px; text-align:right; color:#606060">Total:</td>
            <td style="padding:8px 12px; text-align:right; color:#141922">
              R$ <?= number_format(array_sum(array_column($parcelas, 'valor')), 2, ',', '.') ?>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Anexos -->
    <?php if (!empty($wizard['anexos_temp'])): ?>
    <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:24px; margin-bottom:20px">
      <h2 style="font-size:16px; font-weight:700; color:#141922; margin:0 0 16px; display:flex; align-items:center; gap:8px">
        <i data-lucide="paperclip" width="18" height="18" style="color:#2347C8"></i>
        Anexos (<?= count($wizard['anexos_temp']) ?>)
      </h2>
      <?php foreach ($wizard['anexos_temp'] as $idx => $a):
        $mime = $a['mime_type'] ?? '';
        $isPreviewable = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true);
        $previewUrl = '?module=processos&action=preview_temp&idx=' . $idx;
        $sizeFmt = $a['tamanho_bytes'] >= 1048576
            ? number_format($a['tamanho_bytes'] / 1048576, 1) . ' MB'
            : number_format($a['tamanho_bytes'] / 1024, 1) . ' KB';

        $iconName = 'file';
        if (str_starts_with($mime, 'image/'))        $iconName = 'file-image';
        elseif ($mime === 'application/pdf')          $iconName = 'file-text';
        elseif (str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel')) $iconName = 'file-spreadsheet';
        elseif (str_contains($mime, 'word'))          $iconName = 'file-type';
      ?>
      <div style="display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid #F0F0F0">
        <i data-lucide="<?= $iconName ?>" width="18" height="18" style="color:#2347C8; flex-shrink:0"></i>
        <div style="flex:1; min-width:0">
          <div style="font-size:13px; font-weight:700; color:#141922"><?= h($a['tipo_nome']) ?></div>
          <div style="font-size:12px; color:#888; display:flex; align-items:center; gap:6px; flex-wrap:wrap">
            <span><?= h($a['nome_original']) ?></span>
            <span style="color:#DFDFDF">·</span>
            <span><?= $sizeFmt ?></span>
          </div>
        </div>
        <a href="<?= h($previewUrl) ?>" target="_blank"
           style="display:inline-flex; align-items:center; gap:4px; padding:6px 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:12px; font-weight:600; color:#606060; text-decoration:none; white-space:nowrap; background:#fff"
           onmouseover="this.style.borderColor='#2347C8';this.style.color='#2347C8'" onmouseout="this.style.borderColor='#DFDFDF';this.style.color='#606060'">
          <i data-lucide="<?= $isPreviewable ? 'eye' : 'download' ?>" width="13" height="13"></i>
          <?= $isPreviewable ? 'Visualizar' : 'Baixar' ?>
        </a>
        <button type="button" onclick="document.getElementById('replace-file-<?= $idx ?>').click()"
                style="display:inline-flex; align-items:center; gap:4px; padding:6px 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:12px; font-weight:600; color:#606060; text-decoration:none; white-space:nowrap; background:#fff; cursor:pointer"
                onmouseover="this.style.borderColor='#FF8A2D';this.style.color='#FF8A2D'" onmouseout="this.style.borderColor='#DFDFDF';this.style.color='#606060'">
          <i data-lucide="replace" width="13" height="13"></i>
          Substituir
        </button>
        <form method="post" action="?module=processos&action=substituir_temp" enctype="multipart/form-data" style="display:none" id="replace-form-<?= $idx ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="idx" value="<?= $idx ?>">
          <input type="file" id="replace-file-<?= $idx ?>" name="novo_arquivo"
                 onchange="if(this.files.length) this.closest('form').submit()"
                 accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.mp4,.webm,.mov,.ogg,.mp3,.m4a,.wav">
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>

  <!-- Coluna direita: Fluxo de aprovação -->
  <div>
    <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:24px; margin-bottom:20px">
      <h2 style="font-size:16px; font-weight:700; color:#141922; margin:0 0 16px; display:flex; align-items:center; gap:8px">
        <i data-lucide="git-branch" width="18" height="18" style="color:#2347C8"></i>
        Fluxo de Aprovação
      </h2>

      <?php if (empty($etapas)): ?>
      <p style="font-size:14px; color:#888; margin:0">Nenhuma etapa configurada.</p>
      <?php else: ?>
      <?php foreach ($etapas as $i => $etapa): ?>
      <div style="display:flex; gap:12px; margin-bottom:<?= $i < count($etapas) - 1 ? '0' : '0' ?>">
        <div style="display:flex; flex-direction:column; align-items:center">
          <div style="width:28px; height:28px; border-radius:50%; background:#E3F0FF; color:#1565C0; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; flex-shrink:0">
            <?= $i + 1 ?>
          </div>
          <?php if ($i < count($etapas) - 1): ?>
          <div style="width:2px; flex:1; background:#EAEAEA; min-height:20px; margin:4px 0"></div>
          <?php endif; ?>
        </div>
        <div style="padding-bottom:<?= $i < count($etapas) - 1 ? '16px' : '0' ?>">
          <div style="font-size:13px; font-weight:700; color:#141922"><?= h($etapa['nome'] ?? 'Etapa ' . ($i+1)) ?></div>
          <div style="font-size:12px; color:#888">
            <?php if (($etapa['tipo_aprovador'] ?? '') === 'solicitante'): ?>
              Volta para você
            <?php else: ?>
              <?= $etapa['tipo_aprovador'] === 'grupo' ? 'Grupo' : 'Usuário' ?> aprovador
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php if ($isTeste): ?>
    <!-- Opção de teste (só aparece para templates não-publicados) -->
    <div style="background:#FFF3CD; border-radius:12px; padding:16px; margin-bottom:20px">
      <div style="display:flex; gap:10px; align-items:flex-start">
        <i data-lucide="flask-conical" width="20" height="20" style="color:#856404; flex-shrink:0; margin-top:2px"></i>
        <div>
          <div style="font-size:13px; font-weight:700; color:#856404; margin-bottom:8px">Processo de Teste</div>
          <div style="font-size:12px; color:#856404; opacity:0.8">Este processo será marcado como teste pois o template utilizado não está publicado. Processos de teste não geram efeitos reais.</div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Botão de submissão -->
    <form method="post" action="?module=processos&amp;action=submeter" id="form-submeter">
      <?= Csrf::field() ?>
      <input type="hidden" name="is_teste" id="input_is_teste" value="<?= $isTeste ? '1' : '0' ?>">

      <button type="submit" id="btn-submeter"
              style="width:100%; display:flex; align-items:center; justify-content:center; gap:8px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:14px 24px; font-size:16px; font-weight:700; cursor:pointer; transition:background 0.15s"
              onmouseover="this.style.background='#1B38A0'" onmouseout="this.style.background='#2347C8'">
        <?php if ($emEdicao): ?>
        <i data-lucide="check" width="18" height="18"></i>
        Salvar alterações
        <?php else: ?>
        <i data-lucide="send" width="18" height="18"></i>
        Confirmar e Enviar
        <?php endif; ?>
      </button>

      <a href="?module=processos&amp;action=step2"
         style="display:flex; align-items:center; justify-content:center; gap:8px; margin-top:10px; background:#EAEAEA; color:#606060; border-radius:4px; padding:11px 24px; font-size:14px; font-weight:700; text-decoration:none; text-align:center">
        <i data-lucide="arrow-left" width="16" height="16"></i>
        Voltar e editar
      </a>
      <?php if ($emEdicao): ?>
      <a href="<?= h($editVoltarUrl) ?>"
         style="display:flex; align-items:center; justify-content:center; gap:8px; margin-top:8px; color:#888; font-size:13px; font-weight:600; text-decoration:none; text-align:center">
        Cancelar e voltar ao processo
      </a>
      <?php endif; ?>
    </form>

  </div>
</div>

<script>

// ── Validação client-side + anti double-submit — Step 3 ──────────────────────
(function () {
    'use strict';

    var form = document.getElementById('form-submeter');
    if (!form) return;

    var submetido = false;

    form.addEventListener('submit', function (e) {
        if (submetido) {
            e.preventDefault();
            return;
        }
        submetido = true;

        var btn = document.getElementById('btn-submeter');
        if (btn) {
            btn.disabled = true;
            btn.style.opacity = '0.7';
            btn.style.cursor  = 'not-allowed';

            // Limpa os ícones e seta o texto de aguarde
            while (btn.firstChild) { btn.removeChild(btn.firstChild); }
            btn.textContent = 'Enviando...';
        }
    });
}());
</script>
