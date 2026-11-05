<?php
// @versao Proc_04 V_23_05/10/2026
/**
 * views/processos/novo/step2.php
 * Wizard — Etapa 2: Dados da Nota Fiscal
 *
 * Variáveis esperadas (via $wizard de $_SESSION['wizard_processos']):
 *   $wizard           array  dados da sessão do wizard
 *   $formasPagamento  array  formas de pagamento (se tem_financeiro)
 *   $flash            array|null
 */

$vendasFuturas  = $vendasFuturas  ?? [];
$efVendaAtual   = $efVendaAtual   ?? null;
$efRemessaAtual = $efRemessaAtual ?? null;
$wizard          = $wizard          ?? $_SESSION['wizard_processos'] ?? [];
$formasPagamento = $formasPagamento ?? [];
$filiais         = $filiais         ?? [];
$filialNome      = $filialNome      ?? '';
$setorNome       = $setorNome       ?? '';
$tipoNotaNome    = $tipoNotaNome    ?? '';
$camposJson      = $wizard['campos_json']  ?? [];
$tiposAnexo      = $wizard['tipos_anexo']  ?? [];
$temFinanceiro   = (bool) ($wizard['tem_financeiro'] ?? false);
$temRateio       = (bool) ($wizard['tem_rateio'] ?? false);
$centrosCusto    = $centrosCusto    ?? [];
$temTipoDespesa  = (bool) ($wizard['tem_tipo_despesa'] ?? false);
// Fluxo de solicitação de acesso fora do horário (migration 043): não tem nota
// nem fornecedor. Esconde os dois blocos — o servidor preenche sozinho, com o
// fornecedor interno e sem_nota = 1. Sem isso a pessoa era obrigada a escolher
// um fornecedor e inventar um número de NF para pedir para trabalhar à noite.
$ehSolicitacaoAcesso = (bool) ($wizard['libera_excecao_horario'] ?? false);
$tipoDespesaObrig = (bool) ($wizard['tipo_despesa_obrigatorio'] ?? false);
$tiposDespesa    = $tiposDespesa    ?? [];
$dadosPrev       = $wizard['dados_step2']  ?? [];
$rateiosPrev     = $dadosPrev['rateios'] ?? [];
$tiposDespesaPrev = $dadosPrev['tipos_despesa'] ?? [];

// Modo edição: mesma view reaproveitada para "Completar dados" de um processo
// já criado (via captura/pré-fluxo). Detectado pela sessão do wizard. O fluxo é
// idêntico ao da criação: step2 → step3 (conferência) → grava. Default = criação.
$editMode       = !empty($wizard['edit_processo_id']);
$editProcessoId = $wizard['edit_processo_id'] ?? '';
$editBackQS     = $wizard['edit_back_qs'] ?? '';
$formAction     = '?module=processos&action=step2'; // ambos os modos seguem p/ o step3
$voltarUrl      = $editMode
    ? '?module=processos&action=detalhe&id=' . rawurlencode((string)$editProcessoId) . $editBackQS
    : '?module=processos&action=novo';

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>

<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">

<?php if (!empty($flash)): ?>
<?php
$alertType    = $flash['type']    ?? 'info';
$alertMessage = $flash['message'] ?? '';
include VIEWS . '/components/alert.php';
?>
<?php endif; ?>

<!-- Cabeçalho + indicador de progresso (mesma linha) -->
<div style="margin-bottom:28px; display:flex; align-items:flex-end; justify-content:space-between; gap:24px; flex-wrap:wrap">
  <div>
    <div style="display:flex; align-items:center; gap:12px; margin-bottom:8px">
      <a href="<?= h($voltarUrl) ?>"
         style="display:inline-flex; align-items:center; gap:6px; color:#606060; text-decoration:none; font-size:14px">
        <i data-lucide="arrow-left" width="16" height="16"></i>
        <?= $editMode ? 'Voltar ao processo' : 'Voltar ao Step 1' ?>
      </a>
    </div>
    <?php if ($editMode): ?>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
      Completar <span style="color:#2347C8">dados do processo</span>
    </h1>
    <p style="font-size:14px; color:#888; margin:0">Processo: <strong><?= h($wizard['template_nome'] ?? '') ?></strong> — preencha parcelas, tipo de despesa e centro de custo para o processo seguir no fluxo.</p>
    <?php else: ?>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
      Dados da <span style="color:#2347C8">Nota Fiscal</span>
    </h1>
    <p style="font-size:14px; color:#888; margin:0">Template: <strong><?= h($wizard['template_nome'] ?? '') ?></strong></p>
    <?php endif; ?>
  </div>

  <div class="wizard-steps" style="padding-bottom:4px">
    <div class="wizard-step is-completed">
      <div class="wizard-step-number"><i data-lucide="check" width="14" height="14"></i></div>
      <div class="wizard-step-label">Selecionar Template</div>
    </div>
    <div class="wizard-step-connector is-completed"></div>
    <div class="wizard-step is-active">
      <div class="wizard-step-number">2</div>
      <div class="wizard-step-label">Dados da NF</div>
    </div>
    <div class="wizard-step-connector"></div>
    <div class="wizard-step">
      <div class="wizard-step-number">3</div>
      <div class="wizard-step-label">Revisão</div>
    </div>
  </div>
</div>

<form method="post" action="<?= h($formAction) ?>" enctype="multipart/form-data" id="form-step2" novalidate>
  <?= Csrf::field() ?>
  <?php if ($editMode): ?><script>window.__step2EditMode = true;</script><?php endif; ?>
  <?php if ($efRemessaAtual): ?><script>window.__efJaRemessa = true;</script><?php endif; ?>
  <?php if ($ehSolicitacaoAcesso): ?><script>window.__step2SolicitacaoAcesso = true;</script><?php endif; ?>

  <!-- Identificação do processo (readonly — dados do step1) -->
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:28px; margin-bottom:20px">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px">
      <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0; display:flex; align-items:center; gap:8px">
        <i data-lucide="clipboard-check" width="20" height="20" style="color:#2347C8"></i>
        Identificação do processo
      </h2>
      <span style="display:flex; align-items:center; gap:5px; font-size:12px; font-weight:600; color:#00A960">
        <i data-lucide="check" width="13" height="13"></i>
        Preenchido
      </span>
    </div>
    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px">
      <div>
        <div style="font-size:12px; font-weight:700; color:#888; margin-bottom:4px">Filial</div>
        <div style="height:40px; background:#F8F8F8; border:1px solid #EAEAEA; border-radius:4px; padding:0 12px; display:flex; align-items:center; font-size:14px; color:#606060"><?= h($filialNome) ?></div>
      </div>
      <div>
        <div style="font-size:12px; font-weight:700; color:#888; margin-bottom:4px">Departamento</div>
        <div style="height:40px; background:#F8F8F8; border:1px solid #EAEAEA; border-radius:4px; padding:0 12px; display:flex; align-items:center; font-size:14px; color:#606060"><?= h($setorNome) ?></div>
      </div>
      <div>
        <div style="font-size:12px; font-weight:700; color:#888; margin-bottom:4px">Tipo de nota</div>
        <div style="height:40px; background:#F8F8F8; border:1px solid #EAEAEA; border-radius:4px; padding:0 12px; display:flex; align-items:center; font-size:14px; color:#606060"><?= h($tipoNotaNome) ?></div>
      </div>
    </div>
  </div>

  <!-- Dados fixos da NF -->
  <div data-bloco-nf style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:28px; margin-bottom:20px">
    <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 20px; display:flex; align-items:center; gap:8px">
      <i data-lucide="file-text" width="20" height="20" style="color:#2347C8"></i>
      <span id="titulo-card-nf">Dados da nota fiscal</span>
    </h2>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px">

      <!-- Fornecedor — some só na solicitação de acesso: não há contraparte
           nenhuma em "pedir para trabalhar à noite", e o servidor preenche com
           o fornecedor interno. Num processo sem nota comum (adiantamento,
           reembolso) o fornecedor CONTINUA, porque ali existe de verdade. -->
      <?php if (!$ehSolicitacaoAcesso): ?>
      <div class="campo-dinamico" style="grid-column:1/-1">
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
          Fornecedor <span style="color:#B3261E">*</span>
        </label>
        <div style="position:relative">
          <input type="text" id="fornecedor_search" placeholder="Digite o CNPJ do fornecedor..."
                 autocomplete="off" <?= $editMode ? 'readonly' : '' ?>
                 value="<?= h($dadosPrev['fornecedor_nome'] ?? '') ?>"
                 style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none<?= $editMode ? '; background:#F6F6F6' : '' ?>"
                 <?php if (!$editMode): ?>onfocus="this.style.borderColor='#2347C8'" onblur="setTimeout(function(){document.getElementById('fornecedor_results').style.display='none'},200)"<?php endif; ?>>
          <div id="fornecedor_results"
               style="display:none; position:absolute; top:42px; left:0; right:0; background:#fff; border:1px solid #DFDFDF; border-radius:4px; box-shadow:0 4px 12px rgba(0,0,0,0.15); z-index:100; max-height:200px; overflow-y:auto">
          </div>
        </div>
        <input type="hidden" id="fornecedor_id"   name="fornecedor_id"   value="<?= h($dadosPrev['fornecedor_id']  ?? '') ?>">
        <input type="hidden" id="fornecedor_nome"  name="fornecedor_nome"  value="<?= h($dadosPrev['fornecedor_nome'] ?? '') ?>">
        <input type="hidden" id="fornecedor_doc"   name="fornecedor_doc"   value="<?= h($dadosPrev['fornecedor_doc']  ?? '') ?>">
        <div style="font-size:11px; color:#888; margin-top:4px">Digite o CNPJ (com ou sem formatação). Se não estiver cadastrado, clique em "Cadastrar novo fornecedor".</div>
      </div>
      <?php endif; ?>

      <?php
      // Fluxo habilitado na aba Configuração do template. Marcado pelo
      // solicitante, solta Número da NF, Valor Total e Data da NF — "Emitido
      // para" continua obrigatório (escopo por filial e relatórios por loja).
      $permiteSemNota = !empty($wizard['permite_sem_nota']);
      // No fluxo de solicitação de acesso a caixa já nasce marcada: o card
      // continua aparecendo e o esconder vem da MARCAÇÃO, não de uma regra
      // escondida no template. A pessoa vê o que está acontecendo e pode
      // desmarcar. Um mecanismo só para os dois casos.
      $semNotaMarcado = !empty($dadosPrev['sem_nota']) || !empty($wizard['sem_nota'])
                     || ($ehSolicitacaoAcesso && empty($dadosPrev));
      ?>
      <?php /* O bloco também aparece quando o processo JÁ é sem nota, mesmo que
               o template tenha desligado a opção depois: sem ele o campo oculto
               não volta no POST e a edição travaria pedindo uma NF que não existe. */ ?>
      <?php /* Uma linha só: Sem nota · Venda com entrega futura · Remessa de
               entrega futura. A explicação de cada uma abre ao passar o mouse.
               Entrega futura (migration 071): venda = processo normal marcado
               para acompanhamento; remessa = não abre processo, lança a entrega
               na venda escolhida (quem paga é a nota de venda). */ ?>
      <?php if (true): ?>
      <style>
        .opf { position:relative; flex:1 1 0; min-width:220px; display:flex; align-items:center; gap:10px; background:#F8F8F8; border-left:4px solid #FF8A2D; border-radius:4px; padding:12px 16px; cursor:pointer }
        .opf input { width:16px; height:16px; accent-color:#2347C8; flex-shrink:0; margin:0 }
        .opf .opf-nome { font-size:13px; font-weight:700; color:#141922 }
        .opf .opf-tip { display:none; position:absolute; left:0; top:calc(100% + 6px); z-index:50; width:300px; background:#141922; color:#fff; font-size:12px; line-height:1.4; border-radius:4px; padding:8px 12px; box-shadow:rgba(0,0,0,0.25) 0 4px 12px }
        .opf:hover .opf-tip { display:block }
      </style>
      <div class="campo-dinamico" style="grid-column:1 / -1">
        <div style="display:flex; flex-wrap:wrap; gap:12px">
          <?php if ($permiteSemNota || $semNotaMarcado): ?>
          <?php /* O bloco também aparece quando o processo JÁ é sem nota, mesmo que
                   o template tenha desligado a opção depois: sem ele o campo oculto
                   não volta no POST e a edição travaria pedindo uma NF que não existe. */ ?>
          <label class="opf">
            <input type="checkbox" name="sem_nota" id="sem_nota" value="1"
                   <?= $semNotaMarcado ? 'checked' : '' ?>
                   <?= $editMode ? 'disabled' : '' ?>
                   onchange="semNotaMudou()">
            <span class="opf-nome">Processo sem nota fiscal</span>
            <span class="opf-tip">Para adiantamento, reembolso, coleta ou nota que ainda não chegou. Número, valor e data da NF deixam de ser exigidos.</span>
          </label>
          <?php if ($editMode && $semNotaMarcado): ?>
          <input type="hidden" name="sem_nota" value="1">
          <?php endif; ?>
          <?php endif; ?>
          <?php $efBloqVenda = (bool) $efRemessaAtual; $efBloqRem = (bool) $efVendaAtual || (bool) $efRemessaAtual; ?>
          <label class="opf" data-campo-nota style="border-left-color:#2347C8">
            <input type="checkbox" name="ef_venda" id="ef_venda" value="1" onchange="entregaFuturaMudou(this)"
                   <?= $efVendaAtual ? 'checked' : '' ?> <?= $efBloqVenda ? 'disabled' : '' ?>>
            <span class="opf-nome">Venda com entrega futura</span>
            <span class="opf-tip">Esta é a nota de venda. Fica em acompanhamento em Entrega Futura até todas as remessas serem registradas.</span>
          </label>
          <label class="opf" data-campo-nota>
            <input type="checkbox" name="ef_remessa" id="ef_remessa" value="1" onchange="entregaFuturaMudou(this)"
                   <?= $efRemessaAtual ? 'checked' : '' ?> <?= $efBloqRem ? 'disabled' : '' ?>>
            <span class="opf-nome">Remessa de entrega futura</span>
            <span class="opf-tip">Entrega de uma venda já cadastrada. Segue o fluxo normal (Fiscal), mas sem a obrigação do Financeiro.</span>
          </label>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!$efRemessaAtual): ?>
      <div class="campo-dinamico" style="grid-column:1 / -1" data-campo-nota>
        <div id="ef-remessa-campo" style="display:none; margin-top:12px">
          <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
            Nota de venda <span style="color:#B3261E">*</span>
          </label>
          <select name="ef_venda_id" id="ef_venda_id"
                  style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; background:#fff; box-sizing:border-box">
            <option value="">— Selecione a venda pendente —</option>
            <?php foreach ($vendasFuturas as $vf): ?>
            <option value="<?= h($vf['id']) ?>">
              NF <?= h($vf['numero_nf']) ?> · <?= h($vf['fornecedor_nome']) ?> · <?= h(date('d/m/Y', strtotime((string) $vf['data_nf']))) ?>
              · saldo R$ <?= h(number_format($vf['saldo'], 2, ',', '.')) ?> de R$ <?= h(number_format($vf['valor_nf'], 2, ',', '.')) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$vendasFuturas): ?>
          <div style="font-size:11px; color:#B3261E; margin-top:4px">Nenhuma venda com entrega futura pendente. Cadastre antes a nota de venda marcando "Venda com entrega futura".</div>
          <?php else: ?>
          <div style="font-size:11px; color:#888; margin-top:4px">Informe abaixo fornecedor, número, data, valor e chave da NF de remessa. A venda é concluída quando a soma das remessas atingir o valor dela.</div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Número da NF -->
      <div class="campo-dinamico" data-campo-nota>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
          Número da NF <span class="marca-obrigatorio-nf" style="color:#B3261E">*</span>
        </label>
        <input type="text" name="numero_nf" id="numero_nf" required maxlength="255"
               value="<?= h($dadosPrev['numero_nf'] ?? '') ?>"
               placeholder="Ex.: 000123456 (várias notas: 187 / 188 / 189)"
               style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
      </div>

      <!-- Valor Total (travado quando informado na etapa 1) -->
      <?php
      $valorEtapa1  = (float) ($wizard['valor_aprox'] ?? 0);
      $valorTravado = $valorEtapa1 > 0;
      // Tipo de nota marcado como "não exige valor" (Cancelamento, Devolução,
      // Remessa): o campo continua na tela, editável, mas deixa de ser exigido.
      $exigeValor   = $wizard['tipo_nota_exige_valor'] ?? true;
      $valorExibir  = $valorTravado
          ? number_format($valorEtapa1, 2, ',', '.')
          : (isset($dadosPrev['valor_nf']) && $dadosPrev['valor_nf'] !== '' ? number_format((float)$dadosPrev['valor_nf'], 2, ',', '.') : '');
      ?>
      <div class="campo-dinamico" data-campo-nota>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
          Valor Total <?php if ($exigeValor): ?><span class="marca-obrigatorio-nf" style="color:#B3261E">*</span><?php endif; ?>
        </label>
        <input type="text" name="valor_nf" id="valor_nf" <?= $exigeValor ? 'required' : '' ?>
               data-exige-valor="<?= $exigeValor ? '1' : '0' ?>"
               value="<?= h($valorExibir) ?>"
               placeholder="0,00"
               <?= $valorTravado ? 'readonly' : '' ?>
               style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none<?= $valorTravado ? '; background:#F6F6F6; cursor:not-allowed' : '' ?>"
               <?php if (!$valorTravado): ?>
               onfocus="this.style.borderColor='#2347C8'"
               onblur="this.style.borderColor='#DFDFDF'"
               oninput="formatarValorInput(this)"
               <?php endif; ?>>
        <?php if ($valorTravado): ?>
        <div style="font-size:11px; color:#888; margin-top:4px">
          Definido na etapa 1 — o template foi selecionado com base neste valor.
        </div>
        <?php elseif (!$exigeValor): ?>
        <div style="font-size:11px; color:#888; margin-top:4px">
          Este tipo de nota não exige valor — pode deixar em branco.
        </div>
        <?php endif; ?>
      </div>

      <!-- Data da NF -->
      <div class="campo-dinamico" data-campo-nota>
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
          Data da NF <span class="marca-obrigatorio-nf" style="color:#B3261E">*</span>
        </label>
        <input type="date" name="data_nf" id="data_nf" required <?= $editMode ? 'readonly' : '' ?>
               value="<?= h($dadosPrev['data_nf'] ?? '') ?>"
               style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none<?= $editMode ? '; background:#F6F6F6' : '' ?>"
               <?php if (!$editMode): ?>onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'"<?php endif; ?>>
      </div>

      <!-- Emitido para (loja destinatária) -->
      <div class="campo-dinamico">
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
          Emitido para <span style="color:#B3261E">*</span>
        </label>
        <select name="filial_destino_id" id="filial_destino_id" required
                style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none; appearance:auto"
                onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'"
                onchange="autoFillCnpjDest()">
          <option value="">Selecionar loja...</option>
          <?php foreach ($filiais as $f): ?>
          <option value="<?= h($f['id']) ?>"
                  data-cnpj="<?= h($f['cnpj'] ?? '') ?>"
                  <?= (($dadosPrev['filial_destino_id'] ?? $wizard['filial_id'] ?? '') === $f['id']) ? 'selected' : '' ?>>
            <?= h($f['nome']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px; color:#888; margin-top:4px">Qual empresa do grupo está recebendo a nota</div>
      </div>

      <!-- CNPJ destinatário (auto-preenchido) -->
      <div class="campo-dinamico">
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
          CNPJ destinatário
        </label>
        <input type="text" name="filial_doc" id="filial_doc" readonly
               value="<?= h($dadosPrev['filial_doc'] ?? '') ?>"
               placeholder="Preenchido automaticamente"
               style="width:100%; height:40px; border:1px solid #EAEAEA; border-radius:4px; padding:0 12px; font-size:14px; color:#606060; background:#F8F8F8; box-sizing:border-box; outline:none">
        <div style="font-size:11px; color:#888; margin-top:4px">Preenchido automaticamente pela loja selecionada</div>
      </div>

      <!-- Chave NF -->
      <div class="campo-dinamico" data-campo-nota style="grid-column:1/-1">
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
          Chave NF-e <span style="font-size:11px; color:#888; font-weight:400">(opcional)</span>
        </label>
        <!-- 50 = NFS-e padrão nacional; NF-e/CT-e continuam com 44. -->
        <input type="text" name="chave_nf" id="chave_nf" maxlength="50" <?= $editMode ? 'readonly' : '' ?>
               value="<?= h($dadosPrev['chave_nf'] ?? '') ?>"
               placeholder="44 dígitos (NF-e/CT-e) ou 50 (NFS-e)"
               style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none<?= $editMode ? '; background:#F6F6F6' : '' ?>"
               <?php if (!$editMode): ?>onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'"<?php endif; ?>>
      </div>

    </div>
  </div>

  <!-- Campos dinâmicos do template -->
  <?php if (!empty($camposJson)): ?>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:28px; margin-bottom:20px">
    <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 20px; display:flex; align-items:center; gap:8px">
      <i data-lucide="<?= $ehSolicitacaoAcesso ? 'clock' : 'list' ?>" width="20" height="20" style="color:#2347C8"></i>
      <?= $ehSolicitacaoAcesso ? 'Acesso solicitado' : 'Campos Adicionais' ?>
    </h2>
    <?php
    // Grade de 6 colunas para o campo poder ocupar 1/2 (padrão), 1/3 ou a linha
    // inteira. `largura` é opcional no JSON do template — sem ela, o
    // comportamento é o de antes (metade da linha, texto longo ocupa tudo).
    $spanCampo = [
        'inteira' => 6,   // linha toda
        'metade'  => 3,   // padrão
        'terco'   => 2,   // três lado a lado (ex.: data + hora início + hora fim)
    ];
    ?>
    <div style="display:grid; grid-template-columns:repeat(6, 1fr); gap:16px">
      <?php foreach ($camposJson as $campo): ?>
      <?php
      $nomeCampo  = $campo['nome']      ?? 'campo';
      $tipoCampo  = $campo['tipo']      ?? 'texto';
      $labelCampo = $campo['label']     ?? $nomeCampo;

      // Campo exigido numa etapa do fluxo (etapa_ordem > 1) NÃO é obrigatório
      // na abertura: o dado ainda não existe, quem o preenche é o responsável
      // pela etapa. Cobrá-lo aqui impediria o solicitante de abrir o processo
      // por causa de uma informação que não é dele.
      $etapaDoCampo = (int) ($campo['etapa_ordem'] ?? 0);
      $obrig        = !empty($campo['obrigatorio']) && $etapaDoCampo <= 1;
      if ($etapaDoCampo > 1) {
          $labelCampo .= ' (preenchido na etapa ' . $etapaDoCampo . ')';
      }
      $valAnterior= $dadosPrev['dados_dinamicos'][$nomeCampo] ?? '';
      $inputStyle = 'width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none';

      $largura = $campo['largura'] ?? null;
      if ($largura === null) {
          $largura = in_array($tipoCampo, ['texto_longo', 'textarea'], true) ? 'inteira' : 'metade';
      }
      $span = $spanCampo[$largura] ?? 3;
      ?>
      <div class="campo-dinamico" style="grid-column:span <?= (int) $span ?>">
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
          <?= h($labelCampo) ?>
          <?php if ($obrig): ?><span style="color:#B3261E">*</span><?php endif; ?>
        </label>

        <?php if ($tipoCampo === 'texto' || $tipoCampo === 'texto_curto'): ?>
          <input type="text" name="campo[<?= h($nomeCampo) ?>]"
                 value="<?= h($valAnterior) ?>"
                 <?= $obrig ? 'required' : '' ?>
                 style="<?= $inputStyle ?>"
                 onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">

        <?php elseif ($tipoCampo === 'texto_longo' || $tipoCampo === 'textarea'): ?>
          <textarea name="campo[<?= h($nomeCampo) ?>]" rows="3"
                    <?= $obrig ? 'required' : '' ?>
                    style="width:100%; border:1px solid #DFDFDF; border-radius:4px; padding:8px 12px; font-size:14px; color:#141922; box-sizing:border-box; outline:none; resize:vertical"
                    onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'"><?= h($valAnterior) ?></textarea>

        <?php elseif ($tipoCampo === 'data'): ?>
          <input type="date" name="campo[<?= h($nomeCampo) ?>]"
                 value="<?= h($valAnterior) ?>"
                 <?= $obrig ? 'required' : '' ?>
                 style="<?= $inputStyle ?>"
                 onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">

        <?php elseif ($tipoCampo === 'valor'): ?>
          <input type="text" name="campo[<?= h($nomeCampo) ?>]"
                 value="<?= h($valAnterior) ?>"
                 placeholder="0,00"
                 <?= $obrig ? 'required' : '' ?>
                 style="<?= $inputStyle ?>"
                 onfocus="this.style.borderColor='#2347C8'"
                 onblur="this.style.borderColor='#DFDFDF'"
               oninput="formatarValorInput(this)">

        <?php elseif ($tipoCampo === 'inteiro' || $tipoCampo === 'numero'): ?>
          <input type="number" name="campo[<?= h($nomeCampo) ?>]"
                 value="<?= h($valAnterior) ?>"
                 <?= $obrig ? 'required' : '' ?>
                 style="<?= $inputStyle ?>"
                 onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">

        <?php elseif ($tipoCampo === 'cpf_cnpj'): ?>
          <input type="text" name="campo[<?= h($nomeCampo) ?>]"
                 value="<?= h($valAnterior) ?>"
                 placeholder="000.000.000-00 ou 11.222.333/0001-81"
                 maxlength="18"
                 <?= $obrig ? 'required' : '' ?>
                 style="<?= $inputStyle ?>"
                 onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'"
                 oninput="mascaraCpfCnpj(this)">

        <?php elseif ($tipoCampo === 'telefone'): ?>
          <input type="tel" name="campo[<?= h($nomeCampo) ?>]"
                 value="<?= h($valAnterior) ?>"
                 placeholder="(00) 00000-0000"
                 maxlength="15"
                 <?= $obrig ? 'required' : '' ?>
                 style="<?= $inputStyle ?>"
                 onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'"
                 oninput="mascaraTelefone(this)">

        <?php elseif ($tipoCampo === 'boolean' || $tipoCampo === 'checkbox'): ?>
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin-top:8px">
            <input type="checkbox" name="campo[<?= h($nomeCampo) ?>]" value="1"
                   <?= $valAnterior ? 'checked' : '' ?>
                   style="width:18px; height:18px; accent-color:#2347C8">
            <span style="font-size:14px; color:#141922">Sim</span>
          </label>

        <?php elseif ($tipoCampo === 'lista' || $tipoCampo === 'select'): ?>
          <?php
          $opcoesLista = [];
          $listaId = $campo['lista_id'] ?? '';
          if ($listaId === '_filiais') {
              $stmtOpc = Database::getInstance()->query("SELECT id, nome FROM filiais WHERE ativo = 1 ORDER BY nome");
              $opcoesLista = $stmtOpc->fetchAll(PDO::FETCH_ASSOC);
          } elseif ($listaId === '_setores') {
              $stmtOpc = Database::getInstance()->query("SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY nome");
              $opcoesLista = $stmtOpc->fetchAll(PDO::FETCH_ASSOC);
          } elseif ($listaId !== '') {
              $stmtOpc = Database::getInstance()->prepare(
                  "SELECT id, valor AS nome FROM nf_lista_itens WHERE lista_id = ? AND ativo = 1 ORDER BY valor"
              );
              $stmtOpc->execute([$listaId]);
              $opcoesLista = $stmtOpc->fetchAll(PDO::FETCH_ASSOC);
          } else {
              foreach ($campo['opcoes'] ?? [] as $opc) {
                  $opcoesLista[] = ['id' => $opc, 'nome' => $opc];
              }
          }
          ?>
          <select name="campo[<?= h($nomeCampo) ?>]"
                  <?= $obrig ? 'required' : '' ?>
                  style="<?= $inputStyle ?>"
                  onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
            <option value="">Selecione...</option>
            <?php foreach ($opcoesLista as $opc): ?>
            <option value="<?= h($opc['id']) ?>" <?= $valAnterior === $opc['id'] ? 'selected' : '' ?>><?= h($opc['nome']) ?></option>
            <?php endforeach; ?>
          </select>

        <?php else: ?>
          <input type="text" name="campo[<?= h($nomeCampo) ?>]"
                 value="<?= h($valAnterior) ?>"
                 <?= $obrig ? 'required' : '' ?>
                 style="<?= $inputStyle ?>"
                 onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Financeiro: parcelas -->
  <?php if ($temFinanceiro): ?>
  <div id="card-financeiro" style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:28px; margin-bottom:20px<?= $efRemessaAtual ? '; display:none' : '' ?>">
    <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 20px; display:flex; align-items:center; gap:8px">
      <i data-lucide="landmark" width="20" height="20" style="color:#2347C8"></i>
      Financeiro — Parcelas
    </h2>

    <div style="display:flex; align-items:center; gap:12px; margin-bottom:20px">
      <label style="font-size:13px; font-weight:700; color:#606060">Número de parcelas:</label>
      <input type="number" id="num_parcelas" min="1" max="60" value="1"
             style="width:80px; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; text-align:center; outline:none"
             onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'"
             oninput="gerarLinhasParcelas(this.value)">
      <button type="button" onclick="gerarLinhasParcelas(document.getElementById('num_parcelas').value)"
              style="height:40px; padding:0 16px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; font-size:13px; font-weight:700; cursor:pointer">
        Gerar
      </button>
    </div>

    <div id="parcelas-grid" class="parcelas-grid">
      <!-- Gerado via JS -->
    </div>
  </div>
  <?php endif; ?>

  <!-- Rateio entre centros de custo -->
  <?php if ($temRateio): ?>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:28px; margin-bottom:20px">
    <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px; display:flex; align-items:center; gap:8px">
      <i data-lucide="pie-chart" width="20" height="20" style="color:#2347C8"></i>
      Rateio <span style="color:#2347C8">entre centros de custo</span>
    </h2>
    <p style="font-size:13px; color:#888; margin:0 0 16px">
      Adicione os centros de custo — o valor da NF é dividido igualmente a cada seleção. Ajuste por % ou valor se necessário.
    </p>

    <div id="rateio-linhas"></div>

    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:12px; flex-wrap:wrap">
      <button type="button" onclick="ratAdicionar()"
              style="display:inline-flex; align-items:center; gap:6px; background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer">
        <i data-lucide="plus" width="14" height="14"></i>
        Adicionar centro de custo
      </button>
      <div id="rateio-totais" style="font-size:13px; color:#606060"></div>
    </div>
  </div>

  <script>
  // ── Rateio: N centros de custo, divisão automática, ajuste por % ou valor ──
  var RAT_CENTROS = <?= json_encode(array_map(fn($c) => [
      'id'     => $c['id'],
      'nome'   => $c['nome'],
      'codigo' => $c['codigo'],
      'depto'  => $c['departamento_nome'],
  ], $centrosCusto), JSON_UNESCAPED_UNICODE) ?>;
  var RAT_PREV = <?= json_encode(array_values(array_map(fn($r) => [
      'setor_id'   => $r['setor_id'],
      'percentual' => $r['percentual'],
      'valor'      => $r['valor'],
  ], $rateiosPrev)), JSON_UNESCAPED_UNICODE) ?>;

  var ratLinhas = []; // [{setor_id, percentual, valor}]

  function ratValorNf() {
    var v = (document.getElementById('valor_nf') || {}).value || '0';
    return parseFloat(v.replace(/\./g, '').replace(',', '.')) || 0;
  }
  function ratFmt(n) {
    return n.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  /* Divide o valor da NF igualmente; centavos de sobra vão para a primeira linha */
  function ratDividirIgualmente() {
    var total = ratValorNf();
    var n = ratLinhas.length;
    if (n === 0) return;
    var base = Math.floor((total / n) * 100) / 100;
    var sobra = Math.round((total - base * n) * 100) / 100;
    ratLinhas.forEach(function (l, i) {
      l.valor      = Math.round((base + (i === 0 ? sobra : 0)) * 100) / 100;
      l.percentual = total > 0 ? Math.round((l.valor / total) * 100000) / 1000 : 0;
    });
  }

  function ratAdicionar(setorId, percentual, valor) {
    ratLinhas.push({ setor_id: setorId || '', percentual: percentual || 0, valor: valor || 0 });
    if (percentual === undefined) ratDividirIgualmente();
    ratRender();
  }

  function ratRemover(idx) {
    ratLinhas.splice(idx, 1);
    ratDividirIgualmente();
    ratRender();
  }

  /* Editou o % → recalcula o valor da linha */
  function ratEditouPercentual(idx, val) {
    var p = parseFloat(String(val).replace(',', '.')) || 0;
    ratLinhas[idx].percentual = p;
    ratLinhas[idx].valor = Math.round(ratValorNf() * p) / 100;
    ratRender();
  }

  /* Editou o valor → recalcula o % da linha */
  function ratEditouValor(idx, val) {
    var v = parseFloat(String(val).replace(/\./g, '').replace(',', '.')) || 0;
    var total = ratValorNf();
    ratLinhas[idx].valor = v;
    ratLinhas[idx].percentual = total > 0 ? Math.round((v / total) * 100000) / 1000 : 0;
    ratRender();
  }

  function ratRender() {
    var cont = document.getElementById('rateio-linhas');
    cont.innerHTML = '';

    ratLinhas.forEach(function (l, idx) {
      var opts = '<option value="">Selecione o centro de custo...</option>';
      RAT_CENTROS.forEach(function (c) {
        var usado = ratLinhas.some(function (o, j) { return j !== idx && o.setor_id === c.id; });
        if (usado) return; // mesmo centro não repete dentro do rateio
        var label = (c.codigo ? c.codigo + ' — ' : '') + c.nome + (c.depto ? ' (' + c.depto + ')' : '');
        opts += '<option value="' + c.id + '"' + (l.setor_id === c.id ? ' selected' : '') + '>' + label + '</option>';
      });

      var row = document.createElement('div');
      row.style.cssText = 'display:grid; grid-template-columns:1fr 110px 150px 40px; gap:10px; align-items:center; margin-bottom:10px';
      row.innerHTML =
        '<select name="rateio[' + idx + '][setor_id]" required onchange="ratLinhas[' + idx + '].setor_id=this.value"' +
        ' style="height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; background:#fff">' + opts + '</select>' +
        '<div style="position:relative">' +
          '<input type="text" name="rateio[' + idx + '][percentual]" value="' + String(l.percentual).replace('.', ',') + '"' +
          ' onchange="ratEditouPercentual(' + idx + ', this.value)" title="Percentual"' +
          ' style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 26px 0 12px; font-size:14px; box-sizing:border-box; text-align:right">' +
          '<span style="position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#888; font-size:13px">%</span>' +
        '</div>' +
        '<div style="position:relative">' +
          '<span style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#888; font-size:13px">R$</span>' +
          '<input type="text" name="rateio[' + idx + '][valor]" value="' + ratFmt(l.valor) + '"' +
          ' onchange="ratEditouValor(' + idx + ', this.value)" title="Valor"' +
          ' style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px 0 34px; font-size:14px; box-sizing:border-box; text-align:right">' +
        '</div>' +
        '<button type="button" onclick="ratRemover(' + idx + ')" title="Remover"' +
        ' style="width:40px; height:40px; border:1px solid #EAEAEA; border-radius:4px; background:#fff; cursor:pointer; color:#888"' +
        ' onmouseover="this.style.color=\'#B3261E\';this.style.borderColor=\'#B3261E\'" onmouseout="this.style.color=\'#888\';this.style.borderColor=\'#EAEAEA\'">×</button>';
      cont.appendChild(row);
    });

    // Totais + alerta de diferença
    var somaV = ratLinhas.reduce(function (s, l) { return s + (l.valor || 0); }, 0);
    var somaP = ratLinhas.reduce(function (s, l) { return s + (l.percentual || 0); }, 0);
    var total = ratValorNf();
    var difere = Math.abs(somaV - total) > 0.05;
    var tot = document.getElementById('rateio-totais');
    if (ratLinhas.length === 0) {
      tot.innerHTML = '<span style="color:#888">Nenhum centro de custo adicionado.</span>';
    } else {
      tot.innerHTML = 'Soma: <strong style="color:' + (difere ? '#B3261E' : '#00A960') + '">R$ ' + ratFmt(somaV) +
        ' (' + somaP.toLocaleString('pt-BR', {maximumFractionDigits: 2}) + '%)</strong>' +
        (difere ? ' <span style="color:#B3261E; font-weight:700">≠ valor da NF R$ ' + ratFmt(total) + '</span>' : ' ✓');
    }
  }

  /* Valor da NF mudou → redistribui mantendo os percentuais atuais */
  document.addEventListener('DOMContentLoaded', function () {
    var valorInput = document.getElementById('valor_nf');
    if (valorInput) {
      valorInput.addEventListener('change', function () {
        var total = ratValorNf();
        ratLinhas.forEach(function (l) { l.valor = Math.round(total * l.percentual) / 100; });
        ratRender();
      });
    }
    // Restaurar rateio salvo na sessão (voltou do step3) ou iniciar com 1 linha
    if (RAT_PREV.length > 0) {
      RAT_PREV.forEach(function (r) { ratAdicionar(r.setor_id, parseFloat(r.percentual), parseFloat(r.valor)); });
    } else {
      ratAdicionar();
    }
  });
  </script>
  <?php endif; ?>

  <!-- Tipo de Despesa (classificação do gasto) -->
  <?php if ($temTipoDespesa): ?>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:28px; margin-bottom:20px">
    <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px; display:flex; align-items:center; gap:8px">
      <i data-lucide="tag" width="20" height="20" style="color:#2347C8"></i>
      Tipo <span style="color:#2347C8">de despesa</span>
      <?php if ($tipoDespesaObrig): ?><span style="color:#B3261E; font-size:14px">*</span><?php endif; ?>
    </h2>
    <p style="font-size:13px; color:#888; margin:0 0 16px">
      Classifique a natureza do gasto. Um único tipo fica com 100%; se a nota mistura naturezas, divida por % ou valor.
    </p>

    <div id="td-linhas"></div>

    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:12px; flex-wrap:wrap">
      <button type="button" onclick="tdAdicionar()"
              style="display:inline-flex; align-items:center; gap:6px; background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer">
        <i data-lucide="plus" width="14" height="14"></i>
        Adicionar tipo de despesa
      </button>
      <div id="td-totais" style="font-size:13px; color:#606060"></div>
    </div>
  </div>

  <script>
  // ── Tipo de Despesa: espelha o rateio (N tipos, divisão automática, % ou valor) ──
  var TD_TIPOS = <?= json_encode(array_map(fn($t) => [
      'id'    => $t['id'],
      'label' => $t['label'],
  ], $tiposDespesa), JSON_UNESCAPED_UNICODE) ?>;
  var TD_PREV = <?= json_encode(array_values(array_map(fn($t) => [
      'tipo_id'    => $t['tipo_id'],
      'percentual' => $t['percentual'],
      'valor'      => $t['valor'],
  ], $tiposDespesaPrev)), JSON_UNESCAPED_UNICODE) ?>;
  var TD_OBRIGATORIO = <?= $tipoDespesaObrig ? 'true' : 'false' ?>;

  var tdLinhas = [];

  function tdValorNf() {
    var v = (document.getElementById('valor_nf') || {}).value || '0';
    return parseFloat(v.replace(/\./g, '').replace(',', '.')) || 0;
  }
  function tdFmt(n) {
    return n.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function tdDividirIgualmente() {
    var total = tdValorNf();
    var n = tdLinhas.length;
    if (n === 0) return;
    var base = Math.floor((total / n) * 100) / 100;
    var sobra = Math.round((total - base * n) * 100) / 100;
    tdLinhas.forEach(function (l, i) {
      l.valor      = Math.round((base + (i === 0 ? sobra : 0)) * 100) / 100;
      l.percentual = total > 0 ? Math.round((l.valor / total) * 100000) / 1000 : 0;
    });
  }

  function tdAdicionar(tipoId, percentual, valor) {
    tdLinhas.push({ tipo_id: tipoId || '', percentual: percentual || 0, valor: valor || 0 });
    if (percentual === undefined) tdDividirIgualmente();
    tdRender();
  }

  function tdRemover(idx) {
    tdLinhas.splice(idx, 1);
    tdDividirIgualmente();
    tdRender();
  }

  function tdEditouPercentual(idx, val) {
    var p = parseFloat(String(val).replace(',', '.')) || 0;
    tdLinhas[idx].percentual = p;
    tdLinhas[idx].valor = Math.round(tdValorNf() * p) / 100;
    tdRender();
  }

  function tdEditouValor(idx, val) {
    var v = parseFloat(String(val).replace(/\./g, '').replace(',', '.')) || 0;
    var total = tdValorNf();
    tdLinhas[idx].valor = v;
    tdLinhas[idx].percentual = total > 0 ? Math.round((v / total) * 100000) / 1000 : 0;
    tdRender();
  }

  function tdRender() {
    var cont = document.getElementById('td-linhas');
    cont.innerHTML = '';

    tdLinhas.forEach(function (l, idx) {
      var opts = '<option value="">Selecione o tipo de despesa...</option>';
      TD_TIPOS.forEach(function (c) {
        var usado = tdLinhas.some(function (o, j) { return j !== idx && o.tipo_id === c.id; });
        if (usado) return; // mesmo tipo não repete
        opts += '<option value="' + c.id + '"' + (l.tipo_id === c.id ? ' selected' : '') + '>' + c.label + '</option>';
      });

      var row = document.createElement('div');
      row.style.cssText = 'display:grid; grid-template-columns:1fr 110px 150px 40px; gap:10px; align-items:center; margin-bottom:10px';
      row.innerHTML =
        '<select name="tipo_despesa[' + idx + '][tipo_id]" required onchange="tdLinhas[' + idx + '].tipo_id=this.value"' +
        ' style="height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px; font-size:14px; background:#fff">' + opts + '</select>' +
        '<div style="position:relative">' +
          '<input type="text" name="tipo_despesa[' + idx + '][percentual]" value="' + String(l.percentual).replace('.', ',') + '"' +
          ' onchange="tdEditouPercentual(' + idx + ', this.value)" title="Percentual"' +
          ' style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 26px 0 12px; font-size:14px; box-sizing:border-box; text-align:right">' +
          '<span style="position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#888; font-size:13px">%</span>' +
        '</div>' +
        '<div style="position:relative">' +
          '<span style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#888; font-size:13px">R$</span>' +
          '<input type="text" name="tipo_despesa[' + idx + '][valor]" value="' + tdFmt(l.valor) + '"' +
          ' onchange="tdEditouValor(' + idx + ', this.value)" title="Valor"' +
          ' style="width:100%; height:40px; border:1px solid #DFDFDF; border-radius:4px; padding:0 12px 0 34px; font-size:14px; box-sizing:border-box; text-align:right">' +
        '</div>' +
        '<button type="button" onclick="tdRemover(' + idx + ')" title="Remover"' +
        ' style="width:40px; height:40px; border:1px solid #EAEAEA; border-radius:4px; background:#fff; cursor:pointer; color:#888"' +
        ' onmouseover="this.style.color=\'#B3261E\';this.style.borderColor=\'#B3261E\'" onmouseout="this.style.color=\'#888\';this.style.borderColor=\'#EAEAEA\'">×</button>';
      cont.appendChild(row);
    });

    var somaV = tdLinhas.reduce(function (s, l) { return s + (l.valor || 0); }, 0);
    var somaP = tdLinhas.reduce(function (s, l) { return s + (l.percentual || 0); }, 0);
    var total = tdValorNf();
    var difere = tdLinhas.length > 0 && Math.abs(somaV - total) > 0.05;
    var tot = document.getElementById('td-totais');
    if (tdLinhas.length === 0) {
      tot.innerHTML = '<span style="color:#888">' + (TD_OBRIGATORIO ? 'Obrigatório — adicione ao menos um tipo.' : 'Opcional — nenhum tipo adicionado.') + '</span>';
    } else {
      tot.innerHTML = 'Soma: <strong style="color:' + (difere ? '#B3261E' : '#00A960') + '">R$ ' + tdFmt(somaV) +
        ' (' + somaP.toLocaleString('pt-BR', {maximumFractionDigits: 2}) + '%)</strong>' +
        (difere ? ' <span style="color:#B3261E; font-weight:700">≠ valor da NF R$ ' + tdFmt(total) + '</span>' : ' ✓');
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var valorInput = document.getElementById('valor_nf');
    if (valorInput) {
      valorInput.addEventListener('change', function () {
        var total = tdValorNf();
        tdLinhas.forEach(function (l) { l.valor = Math.round(total * l.percentual) / 100; });
        tdRender();
      });
    }
    if (TD_PREV.length > 0) {
      TD_PREV.forEach(function (t) { tdAdicionar(t.tipo_id, parseFloat(t.percentual), parseFloat(t.valor)); });
    } else if (TD_OBRIGATORIO) {
      tdAdicionar(); // obrigatório já começa com 1 linha; opcional começa vazio
    } else {
      tdRender();
    }
  });
  </script>
  <?php endif; ?>

  <!-- Anexos -->
  <?php if (!empty($tiposAnexo)): ?>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:28px; margin-bottom:20px">
    <h2 style="font-size:18px; font-weight:700; color:#141922; margin:0 0 4px; display:flex; align-items:center; gap:8px">
      <i data-lucide="paperclip" width="20" height="20" style="color:#2347C8"></i>
      Anexos
    </h2>
    <p style="font-size:13px; color:#888; margin:0 0 16px">Clique na área para selecionar um ou mais arquivos por tipo (ex.: vários boletos no mesmo tipo).<?php if ($editMode): ?> Os arquivos enviados aqui <strong>somam</strong> aos que o processo já tiver.<?php endif; ?></p>

    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:10px">
      <?php foreach ($tiposAnexo as $tipo): ?>
      <?php
      $nomeField   = 'anexo_' . preg_replace('/[^a-z0-9]/i', '_', $tipo['nome'] ?? 'anexo');
      $etapaExig   = (int) ($tipo['etapa_ordem'] ?? 0);
      // Anexo exigido numa etapa não bloqueia a abertura — o gate é na aprovação da etapa.
      // No modo edição também não é obrigatório aqui: o processo pode já ter o anexo,
      // e a exigência é reforçada no gate de aprovação (dadosAberturaPendentes).
      $obrigAnexo  = !empty($tipo['obrigatorio']) && $etapaExig === 0 && !$editMode;
      $tipoDoc     = $tipo['tipo_doc'] ?? 'qualquer';

      $acceptMap = [
          'qualquer'   => '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.txt,.csv',
          'pdf'        => '.pdf',
          'imagem'     => '.jpg,.jpeg,.png,.webp',
          'pdf_imagem' => '.pdf,.jpg,.jpeg,.png,.webp',
          'excel'      => '.xls,.xlsx',
          'word'       => '.doc,.docx',
          'video'      => '.mp4,.webm,.mov,.ogg,.mp3,.m4a,.wav',
      ];
      $acceptAttr = $acceptMap[$tipoDoc] ?? $acceptMap['qualquer'];

      $tipoDocLabels = [
          'qualquer'   => '',
          'pdf'        => 'PDF',
          'imagem'     => 'Imagem',
          'pdf_imagem' => 'PDF/Img',
          'excel'      => 'Excel',
          'word'       => 'Word',
          'video'      => 'Áudio/Vídeo · máx. 5 MB',
      ];
      $tipoDocHint = $tipoDocLabels[$tipoDoc] ?? '';
      ?>
      <div class="file-upload-area" onclick="this.querySelector('input[type=file]').click()"
           style="padding:10px 14px; border-radius:6px; display:flex; align-items:center; gap:8px; text-align:left; min-height:44px;">
        <input type="file" name="<?= h($nomeField) ?>[]" multiple
               accept="<?= h($acceptAttr) ?>"
               <?= $obrigAnexo ? 'required' : '' ?>
               style="display:none"
               onchange="updateFileLabel(this)">
        <div class="file-upload-content" style="flex-direction:row; gap:6px; flex:1; min-width:0;">
          <i data-lucide="upload" width="14" height="14" style="color:#2347C8; flex-shrink:0"></i>
          <span class="file-upload-label" style="font-size:12px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
            <?php if ($obrigAnexo): ?><span style="color:#B3261E">*</span><?php endif; ?>
            <?= h($tipo['nome'] ?? 'Anexo') ?>
          </span>
          <?php if ($tipoDocHint): ?>
          <span style="font-size:10px; color:#888; background:#F6F6F6; border-radius:3px; padding:1px 5px; flex-shrink:0"><?= h($tipoDocHint) ?></span>
          <?php endif; ?>
          <?php if ($etapaExig > 0 && !empty($tipo['obrigatorio'])): ?>
          <span title="Poderá ser anexado depois — obrigatório para aprovar a etapa <?= $etapaExig ?>"
                style="font-size:10px; color:#FF8A2D; background:#FFF3E0; border-radius:3px; padding:1px 5px; flex-shrink:0; font-weight:700">Etapa <?= $etapaExig ?></span>
          <?php endif; ?>
        </div>
        <span class="file-upload-name" style="display:none;"></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Botões de navegação -->
  <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px">
    <a href="<?= h($voltarUrl) ?>"
       style="display:inline-flex; align-items:center; gap:8px; background:#EAEAEA; color:#606060; border-radius:4px; padding:11px 24px; font-size:14px; font-weight:700; text-decoration:none">
      <i data-lucide="arrow-left" width="16" height="16"></i>
      Voltar
    </a>
    <button type="submit"
            style="display:inline-flex; align-items:center; gap:8px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:11px 24px; font-size:14px; font-weight:700; cursor:pointer; transition:background 0.15s"
            onmouseover="this.style.background='#1B38A0'" onmouseout="this.style.background='#2347C8'">
      Revisar
      <i data-lucide="arrow-right" width="16" height="16"></i>
    </button>
  </div>

</form>

<script>
// ── Atualizar label do upload ao selecionar arquivo ──
function updateFileLabel(input) {
    var area = input.closest('.file-upload-area');
    if (!area) return;
    var content = area.querySelector('.file-upload-content');
    var nameEl  = area.querySelector('.file-upload-name');

    if (input.files && input.files.length > 0) {
        var accept     = (input.getAttribute('accept') || '').toLowerCase();
        var extsPermit = accept ? accept.split(',').map(function(e) { return e.trim().replace('.', ''); }) : null;

        // Validar a extensão de TODOS os arquivos selecionados
        if (extsPermit) {
            for (var i = 0; i < input.files.length; i++) {
                var ext = input.files[i].name.split('.').pop().toLowerCase();
                if (extsPermit.indexOf(ext) === -1) {
                    input.value = '';
                    area.classList.remove('has-file');
                    area.style.borderColor = '#2347C8';
                    area.style.background  = '#EEF2FD';
                    var lbl = area.querySelector('.file-upload-label');
                    if (lbl) { lbl.dataset.original = lbl.dataset.original || lbl.textContent; lbl.textContent = '.' + ext + ' não permitido'; lbl.style.color = '#2347C8'; }
                    if (nameEl) { nameEl.textContent = ''; nameEl.style.display = 'none'; }
                    setTimeout(function() {
                        area.style.borderColor = ''; area.style.background = '';
                        if (lbl && lbl.dataset.original) { lbl.textContent = lbl.dataset.original; lbl.style.color = ''; }
                    }, 4000);
                    return;
                }
            }
        }

        area.classList.add('has-file');
        area.style.borderColor = '';
        area.style.background = '';

        // Trocar ícone para checkmark verde — nome do tipo permanece inalterado
        var lucideIcon = content.querySelector('i[data-lucide]');
        if (lucideIcon) {
            lucideIcon.setAttribute('data-lucide', 'check-circle');
            lucideIcon.style.color = '#00A960';
        }

        // Label fica verde para indicar seleção
        var label = area.querySelector('.file-upload-label');
        if (label) label.style.color = '#00A960';

        // Mostrar contagem (ou nome do único arquivo)
        if (nameEl) {
            nameEl.textContent = input.files.length === 1
                ? input.files[0].name
                : input.files.length + ' arquivos';
            nameEl.title = Array.prototype.map.call(input.files, function (f) { return f.name; }).join(', ');
            nameEl.style.display      = 'inline-block';
            nameEl.style.fontSize     = '11px';
            nameEl.style.color        = '#00A960';
            nameEl.style.fontWeight   = '700';
            nameEl.style.flexShrink   = '0';
            nameEl.style.maxWidth     = '92px';
            nameEl.style.overflow     = 'hidden';
            nameEl.style.textOverflow = 'ellipsis';
            nameEl.style.whiteSpace   = 'nowrap';
        }

        if (window.lucide) window.lucide.createIcons();
    } else {
        area.classList.remove('has-file');
        var lucideIcon = content.querySelector('i[data-lucide]');
        if (lucideIcon) {
            lucideIcon.setAttribute('data-lucide', 'upload');
            lucideIcon.style.color = '#2347C8';
        }
        var label = area.querySelector('.file-upload-label');
        if (label) label.style.color = '';
        if (nameEl) { nameEl.textContent = ''; nameEl.style.display = 'none'; }
        if (window.lucide) window.lucide.createIcons();
    }
}

// ── Auto-preencher CNPJ destinatário ao selecionar filial ──
function autoFillCnpjDest() {
    var sel = document.getElementById('filial_destino_id');
    var cnpjField = document.getElementById('filial_doc');
    if (!sel || !cnpjField) return;
    var opt = sel.options[sel.selectedIndex];
    var raw = opt && opt.value ? (opt.dataset.cnpj || '') : '';
    cnpjField.value = window.formatarCnpjCpf ? formatarCnpjCpf(raw) : raw;
}
document.addEventListener('DOMContentLoaded', autoFillCnpjDest);

// ── Formas de pagamento para gerarLinhasParcelas (dados do servidor via PHP, escapados) ──
window._formasPagamento = <?= json_encode(array_map(function($fp) {
    return ['id' => $fp['id'], 'label' => $fp['label']];
}, $formasPagamento), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

// Dias para a sugestão automática (botão "Gerar") — configurável em Geral.
window._diasVencimentoSugestao = <?= (int) Settings::getInt('geral.dias_vencimento_sugestao', 15) ?>;

// Parcelas reais já conhecidas (edição, ou nota importada com vencimento
// próprio) — nunca recalculadas, só exibidas como vieram.
window._parcelasReais = <?= json_encode(array_map(function ($p) {
    return [
        'vencimento'         => substr((string) ($p['vencimento'] ?? ''), 0, 10),
        'valor'               => (float) ($p['valor'] ?? 0),
        'forma_pagamento_id'  => $p['forma_pagamento_id'] ?? null,
    ];
}, $dadosPrev['parcelas'] ?? []), JSON_UNESCAPED_UNICODE) ?>;

// ── Inicializar parcelas se tem_financeiro (processos.js cuida do restante) ──
<?php if ($temFinanceiro): ?>
document.addEventListener('DOMContentLoaded', function() {
    // Já existe parcela real (edição, ou nota importada/capturada com
    // vencimento próprio)? Mostra como está — nunca troca pela sugestão só
    // por a tela ter carregado. Só sugere quando não há nada ainda.
    var temParcelaReal = typeof window.preencherLinhasParcelas === 'function'
        && window.preencherLinhasParcelas(window._parcelasReais);
    if (!temParcelaReal && typeof window.gerarLinhasParcelas === 'function') {
        window.gerarLinhasParcelas(1);
    }
});
<?php endif; ?>

// ── Validação client-side — Step 2 ───────────────────────────────────────────
(function () {
    'use strict';

    function showFieldError(input, msg) {
        input.style.borderColor = '#B3261E';
        var wrap = input.closest('.campo-dinamico') || input.parentNode;
        var err = wrap.querySelector('.field-error');
        if (!err) {
            err = document.createElement('span');
            err.className = 'field-error';
            err.style.cssText = 'display:block;color:#B3261E;font-size:12px;margin-top:4px;';
            // Insere após o input (ou após o div de resultados do fornecedor)
            var after = input.nextElementSibling || input;
            after.parentNode.insertBefore(err, after.nextSibling);
        }
        err.textContent = msg;
    }

    function clearFieldError(input) {
        input.style.borderColor = '';
        var wrap = input.closest('.campo-dinamico') || input.parentNode;
        var err = wrap.querySelector('.field-error');
        if (err) err.remove();
    }

    var form = document.getElementById('form-step2');
    if (!form) return;

    // Limpar erros ao interagir com qualquer campo
    form.querySelectorAll('input:not([type="hidden"]):not([type="file"]), select, textarea').forEach(function (el) {
        el.addEventListener('input', function () { clearFieldError(this); });
        el.addEventListener('change', function () { clearFieldError(this); });
    });

    form.addEventListener('submit', function (e) {
        var valid = true;
        var primeiroInvalido = null;

        function marcar(input, msg) {
            showFieldError(input, msg);
            if (!primeiroInvalido) primeiroInvalido = input;
            valid = false;
        }

        // Fornecedor: campo visível de busca + hidden com ID
        // (no modo edição o fornecedor é readonly e não muda — pula a validação)
        // Solicitação de acesso: o fornecedor é preenchido pelo servidor com o
        // fornecedor interno — o campo nem aparece, então não se valida.
        if (!window.__step2EditMode && !window.__step2SolicitacaoAcesso) {
            var fornecedorSearch = document.getElementById('fornecedor_search');
            var fornecedorId     = document.getElementById('fornecedor_id');
            if (fornecedorSearch && !fornecedorSearch.value.trim()) {
                marcar(fornecedorSearch, 'Informe o CNPJ do fornecedor.');
            } else if (fornecedorId && !fornecedorId.value.trim()) {
                marcar(fornecedorSearch, 'Selecione um fornecedor da lista. Digite o CNPJ e clique no resultado.');
            }
        }

        // Número da NF
        var numeroNf = form.querySelector('[name="numero_nf"]');
        if (numeroNf && !numeroNf.value.trim()) {
            if (!processoSemNota()) { marcar(numeroNf, 'Informe o número da nota fiscal.'); }
        }

        // Valor total
        var valorNf = document.getElementById('valor_nf');
        if (valorNf) {
            var exigeValor = valorNf.dataset.exigeValor !== '0' && !processoSemNota();
            var vStr = valorNf.value.replace(/\./g, '').replace(',', '.');
            var vNum = parseFloat(vStr);
            if (!valorNf.value.trim()) {
                // Tipo de nota sem valor a informar: campo em branco é válido.
                if (exigeValor) { marcar(valorNf, 'Informe o valor total.'); }
            } else if (isNaN(vNum) || vNum <= 0) {
                marcar(valorNf, 'Informe um valor numérico positivo.');
            }
        }

        // Data da NF
        var dataNf = form.querySelector('[name="data_nf"]');
        if (dataNf && !dataNf.value && !processoSemNota()) {
            marcar(dataNf, 'Informe a data da nota fiscal.');
        }

        // Remessa de entrega futura: segue o fluxo normal (Fiscal), mas sem a
        // obrigação do Financeiro — as parcelas não são exigidas.
        var efRem  = document.getElementById('ef_remessa');
        var semFin = !!(efRem && efRem.checked) || !!window.__efJaRemessa;
        if (efRem && efRem.checked) {
            var efSel = document.getElementById('ef_venda_id');
            if (efSel && !efSel.value) { marcar(efSel, 'Selecione a nota de venda.'); }
        }

        // Campos dinâmicos obrigatórios
        form.querySelectorAll('.campo-dinamico [required]').forEach(function (el) {
            if (el.type === 'file') return;
            var v = el.value ? el.value.trim() : '';
            if (!v) {
                marcar(el, 'Campo obrigatório.');
            }
        });

        // Parcelas: vencimento, valor e forma de pagamento obrigatórios
        var parcelasGrid = document.getElementById('parcelas-grid');
        var parcelasInvalidas = 0;
        if (parcelasGrid && !semFin) {
            parcelasGrid.querySelectorAll('select[name*="forma_pagamento_id"]').forEach(function (sel) {
                if (!sel.value) {
                    sel.style.borderColor = '#B3261E';
                    sel.style.background = '#FFF0F3';
                    parcelasInvalidas++;
                    if (!primeiroInvalido) primeiroInvalido = sel;
                    valid = false;
                } else {
                    sel.style.borderColor = '';
                    sel.style.background = '';
                }
            });
            parcelasGrid.querySelectorAll('input[name*="vencimento"]').forEach(function (inp) {
                if (!inp.value) {
                    inp.style.borderColor = '#B3261E';
                    parcelasInvalidas++;
                    if (!primeiroInvalido) primeiroInvalido = inp;
                    valid = false;
                } else {
                    inp.style.borderColor = '';
                }
            });
            parcelasGrid.querySelectorAll('input[name*="[valor]"]').forEach(function (inp) {
                var v = inp.value.replace(/\./g, '').replace(',', '.');
                if (!inp.value.trim() || isNaN(parseFloat(v)) || parseFloat(v) <= 0) {
                    inp.style.borderColor = '#B3261E';
                    parcelasInvalidas++;
                    if (!primeiroInvalido) primeiroInvalido = inp;
                    valid = false;
                } else {
                    inp.style.borderColor = '';
                }
            });
        }

        // Anexos obrigatórios
        var anexosFaltando = [];
        form.querySelectorAll('input[type="file"][required]').forEach(function (fileInput) {
            if (!fileInput.files || fileInput.files.length === 0) {
                var area = fileInput.closest('.file-upload-area');
                if (area) {
                    var labelEl = area.querySelector('.file-upload-label');
                    var nome = labelEl ? labelEl.textContent.replace(/[*\s]+/g, ' ').trim() : 'Anexo';
                    anexosFaltando.push(nome);
                    area.style.borderColor = '#B3261E';
                    area.style.background = '#FFF0F3';
                }
                valid = false;
            }
        });
        if (!valid) {
            e.preventDefault();

            // Montar mensagem unificada para o modal
            var partes = [];
            var camposErro = form.querySelectorAll('.field-error');
            if (camposErro.length > 0) {
                partes.push('Corrija <b>' + camposErro.length + ' campo(s)</b> destacado(s) em vermelho no formulário.');
            }
            if (parcelasInvalidas > 0) {
                partes.push('Preencha <b>vencimento, valor e forma de pagamento</b> de todas as parcelas.');
            }
            if (anexosFaltando.length > 0) {
                partes.push(anexosFaltando.length === 1
                    ? 'O documento obrigatório <b>' + anexosFaltando[0] + '</b> não foi anexado.'
                    : 'Documentos obrigatórios não anexados: <b>' + anexosFaltando.join('</b>, <b>') + '</b>.');
            }
            if (partes.length > 0) {
                btModalErro('Campos pendentes', partes.join('<br><br>'), 'warning');
            }

            if (primeiroInvalido) primeiroInvalido.focus();
            return;
        }

        // Evitar double-submit
        var btn = form.querySelector('[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.style.opacity = '0.7';
            btn.style.cursor = 'not-allowed';
        }
    });
}());


/* ── Processo sem nota fiscal ────────────────────────────────────────────
   Habilitado na aba Configuração do template. Marcado, os três campos da NF
   ficam desabilitados e vazios: campo disabled não vai no POST, e o servidor
   grava NULL (que é diferente de zero — zero seria uma nota de valor zero).
   "Emitido para" fica de fora de propósito. */
function processoSemNota() {
    var chk = document.getElementById('sem_nota');
    return !!(chk && chk.checked);
}

/* Entrega futura: as duas opções são exclusivas; remessa mostra o seletor da venda. */
function entregaFuturaMudou(origem) {
    var venda = document.getElementById('ef_venda');
    var rem   = document.getElementById('ef_remessa');
    if (origem && origem.checked) {
        if (origem === venda && rem) rem.checked = false;
        if (origem === rem && venda) venda.checked = false;
    }
    var campo = document.getElementById('ef-remessa-campo');
    if (campo) campo.style.display = (rem && rem.checked) ? '' : 'none';
    // Remessa não tem Financeiro (quem paga é a nota de venda)
    var fin = document.getElementById('card-financeiro');
    if (fin) fin.style.display = ((rem && rem.checked) || window.__efJaRemessa) ? 'none' : '';
}

function semNotaMudou() {
    var sem = processoSemNota();

    // A CHAVE fica de fora da obrigatoriedade, sempre. Ela entra na lista só
    // para ser limpa e desabilitada junto com as outras quando o processo é
    // "sem nota" — nem toda nota tem chave (NFS-e antiga, cupom, nota de
    // serviço digitada à mão), e o próprio rótulo do campo diz "(opcional)".
    //
    // O `data-exige-valor` existe só no valor_nf; para os outros ele vem
    // `undefined`, e `undefined !== '0'` é true. Era isso que tornava a chave
    // obrigatória toda vez que esta função rodava — inclusive no carregamento
    // da tela, sem ninguém tocar em nada.
    var OBRIGATORIOS_NF = ['numero_nf', 'valor_nf', 'data_nf'];

    ['numero_nf', 'valor_nf', 'data_nf', 'chave_nf'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        if (sem) { el.value = ''; }
        el.disabled = sem;
        el.required = sem
            ? false
            : (OBRIGATORIOS_NF.indexOf(id) >= 0 && el.dataset.exigeValor !== '0');
        el.style.background = sem ? '#F6F6F6' : '';
        el.style.cursor     = sem ? 'not-allowed' : '';
    });

    // Campo de nota some da tela, não fica cinza ocupando espaço. São só os
    // QUATRO que descrevem a nota — Fornecedor e "Emitido para" continuam:
    // um adiantamento ou reembolso tem contraparte, e o CNPJ de destino é
    // NOT NULL e define a visibilidade por filial (migration 039).
    document.querySelectorAll('[data-campo-nota]').forEach(function (bloco) {
        bloco.style.display = sem ? 'none' : '';
    });

    // Com os campos da nota fora, o título do card fica mentindo.
    var titulo = document.getElementById('titulo-card-nf');
    if (titulo) { titulo.textContent = sem ? 'Dados do processo' : 'Dados da nota fiscal'; }

    document.querySelectorAll('.marca-obrigatorio-nf').forEach(function (m) {
        m.style.display = sem ? 'none' : '';
    });
}
document.addEventListener('DOMContentLoaded', semNotaMudou);
</script>
