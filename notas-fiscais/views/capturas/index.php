<?php
// @versao Cap_01 V_13_21/10/2026
/**
 * views/capturas/index.php
 * Inbox de notas capturadas via integração (Brobot / SEFAZ)
 *
 * Variáveis esperadas:
 *   $notas      array  notas capturadas (com dados da regra sugerida, se houver)
 *   $totais     array  [pendentes, sugeridas, valor_pendente, vinculadas_hoje]
 *   $templates  array  templates publicados (id, nome, campos JSON)
 *   $filiais    array  filiais ativas
 *   $filters    array  [status, busca]
 */

$notas     = $notas     ?? [];
$totais    = $totais    ?? ['pendentes' => 0, 'sugeridas' => 0, 'valor_pendente' => 0, 'vinculadas_hoje' => 0];
$templates = $templates ?? [];
$filiais   = $filiais   ?? [];
$departamentos = $departamentos ?? [];
$filters   = $filters   ?? ['status' => '', 'busca' => '', 'tipo' => ''];
$brobotStatus = $brobotStatus ?? ['sync' => null, 'classificacao' => null];

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// Checkbox de seleção múltipla ("combinar em um processo") escondido a
// pedido do usuário em 18/09/2026 — por enquanto, não é decisão definitiva.
// O JS de combinação (capToggleSelecao, capAbrirVinculoMultiplo etc.) fica
// intacto: é só reativar a flag pra voltar a mostrar o checkbox.
if (!defined('SELECAO_MULTIPLA_CAPTURAS')) {
    define('SELECAO_MULTIPLA_CAPTURAS', false);
}

function capFmtCnpj(string $cnpj): string {
    if (strlen($cnpj) !== 14) return $cnpj;
    return substr($cnpj,0,2).'.'.substr($cnpj,2,3).'.'.substr($cnpj,5,3).'/'.substr($cnpj,8,4).'-'.substr($cnpj,12,2);
}
function capFmtValor($v): string {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}

/** Rótulo amigável para o código interno de origem da captura (nf_notas_capturadas.origem). */
function capFmtOrigem(string $origem): string {
    $map = ['adn' => 'NFS-e', 'nfe_dist' => 'NF-e', 'brobot' => 'Brobot'];
    return $map[$origem] ?? $origem;
}

$templatesJson = json_encode(array_map(fn($t) => [
    'id'     => $t['id'],
    'nome'   => $t['nome'],
    'campos' => json_decode($t['campos'], true) ?: [],
    'departamento_id' => $t['departamento_id'] ?? null,
], $templates), JSON_UNESCAPED_UNICODE);

/** Formata um carimbo de execução (nf_brobot_log) para a faixa do topo. */
function capCarimbo(?array $log): array {
    if (!$log) {
        return ['quando' => 'nunca executado', 'cor' => '#888', 'detalhe' => '', 'status' => 'vazio'];
    }
    $cores = ['ok' => '#00A960', 'parcial' => '#FF8A2D', 'erro' => '#B3261E'];
    return [
        'quando'  => date('d/m/Y H:i', strtotime($log['iniciado_em'])),
        'cor'     => $cores[$log['status']] ?? '#888',
        'detalhe' => (string) ($log['detalhe'] ?? ''),
        'status'  => (string) $log['status'],
    ];
}
$cSync = capCarimbo($brobotStatus['sync'] ?? null);
$cCls  = capCarimbo($brobotStatus['classificacao'] ?? null);
?>
<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">

<?php include VIEWS . '/components/alert.php'; ?>

<!-- Page Header -->
<div class="page-header" style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:28px; flex-wrap:wrap; gap:12px">
  <div>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
      Notas <span style="color:#2347C8">Capturadas</span>
    </h1>
  </div>
  <div style="display:flex; gap:10px; align-items:flex-start">
    <form method="POST" action="?module=capturas&amp;action=sincronizar" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:10px 18px; font-size:14px; font-weight:700; cursor:pointer">
        <i data-lucide="refresh-cw" width="16" height="16"></i>
        Sincronizar agora
      </button>
    </form>
    <form method="POST" action="?module=capturas&amp;action=aplicar_regras" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
      <button type="submit"
              style="display:inline-flex; align-items:center; gap:8px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; cursor:pointer">
        <i data-lucide="zap" width="16" height="16"></i>
        Aplicar regras agora
      </button>
      <div style="font-size:11px; color:#888; margin-top:4px; text-align:center">Classificação a cada 30 min</div>
    </form>
  </div>
</div>

<!-- Faixa única: carimbos da integração + cards resumo -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:16px; margin-bottom:24px; align-items:stretch">
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:14px 18px; display:flex; align-items:center; gap:14px; border-left:4px solid <?= $cSync['cor'] ?>">
    <i data-lucide="satellite-dish" width="22" height="22" style="color:<?= $cSync['cor'] ?>"></i>
    <div style="min-width:0">
      <div style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase">Última busca</div>
      <div style="font-size:16px; font-weight:700; color:#141922"><?= h($cSync['quando']) ?></div>
      <?php if ($cSync['detalhe'] !== ''): ?>
      <div style="font-size:11px; color:<?= $cSync['status']==='erro' ? '#B3261E' : '#888' ?>; white-space:nowrap; overflow:hidden; text-overflow:ellipsis" title="<?= h($cSync['detalhe']) ?>"><?= h($cSync['detalhe']) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:14px 18px; display:flex; align-items:center; gap:14px; border-left:4px solid <?= $cCls['cor'] ?>">
    <i data-lucide="wand-2" width="22" height="22" style="color:<?= $cCls['cor'] ?>"></i>
    <div style="min-width:0">
      <div style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase">Última classificação</div>
      <div style="font-size:16px; font-weight:700; color:#141922"><?= h($cCls['quando']) ?></div>
      <?php if ($cCls['detalhe'] !== ''): ?>
      <div style="font-size:11px; color:<?= $cCls['status']==='erro' ? '#B3261E' : '#888' ?>; white-space:nowrap; overflow:hidden; text-overflow:ellipsis" title="<?= h($cCls['detalhe']) ?>"><?= h($cCls['detalhe']) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:14px 18px; border-left:4px solid #2347C8">
    <div style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase">Aguardando vínculo</div>
    <div style="font-size:32px; font-weight:700; color:#141922"><?= (int)$totais['pendentes'] ?></div>
  </div>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:14px 18px; border-left:4px solid #FF8A2D">
    <div style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase">Com sugestão de regra</div>
    <div style="font-size:32px; font-weight:700; color:#FF8A2D"><?= (int)$totais['sugeridas'] ?></div>
  </div>
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:14px 18px; border-left:4px solid #00A960">
    <div style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase">Vinculadas hoje</div>
    <div style="font-size:32px; font-weight:700; color:#00A960"><?= (int)$totais['vinculadas_hoje'] ?></div>
  </div>
</div>

<!-- Filtros -->
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:16px 20px; margin-bottom:20px; display:flex; gap:12px; align-items:center; flex-wrap:wrap">
  <form method="GET" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; flex:1">
    <input type="hidden" name="module" value="capturas">
    <input type="hidden" name="action" value="index">
    <input type="hidden" name="f" value="1"><!-- marca submissão do filtro: persiste em sessão -->
    <div style="position:relative; flex:1; min-width:220px; max-width:360px">
      <i data-lucide="search" width="16" height="16" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#888"></i>
      <input type="search" name="busca" value="<?= h($filters['busca']) ?>" placeholder="Buscar por nº da nota, fornecedor, chave..."
             style="width:100%; height:40px; padding:0 12px 0 34px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box; outline:none"
             onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
    </div>
    <?php
    // Lojas — multi-seleção; sem nada marcado vale "todas as que enxergo".
    $flSufixo   = 'cap';
    $flOpcoes   = $filiais;
    $flSel      = (array) ($filters['filial_id'] ?? []);
    $flCampo    = 'filial';
    $flVariante = 'inline';
    include VIEWS . '/components/filtro-lojas.php';
    ?>
    <select name="tipo" style="height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; color:#141922; background:#fff">
      <option value="">Todos os tipos</option>
      <?php foreach (['NFe','CTe','NFSe','CTOs'] as $tp): ?>
      <option value="<?= $tp ?>" <?= ($filters['tipo'] ?? '') === $tp ? 'selected' : '' ?>><?= $tp ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" style="height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; color:#141922; background:#fff">
      <option value="">Pendentes (padrão)</option>
      <option value="sugerida"  <?= $filters['status']==='sugerida'  ? 'selected' : '' ?>>Com sugestão</option>
      <option value="vinculada" <?= $filters['status']==='vinculada' ? 'selected' : '' ?>>Vinculadas</option>
      <option value="ignorada"  <?= $filters['status']==='ignorada'  ? 'selected' : '' ?>>Arquivadas / ignoradas</option>
      <option value="todos"     <?= $filters['status']==='todos'     ? 'selected' : '' ?>>Todos os status</option>
    </select>
    <button type="submit" style="height:40px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:0 20px; font-size:14px; font-weight:700; cursor:pointer">Filtrar</button>
    <?php
      $capFiltroAtivo = ($filters['busca'] ?? '') !== '' || !empty($filters['filial_id'])
                        || ($filters['status'] ?? '') !== '' || ($filters['tipo'] ?? '') !== '';
    ?>
    <?php if ($capFiltroAtivo): ?>
    <a href="?module=capturas&amp;action=index&amp;limpar=1" title="Remover filtros e mostrar tudo"
       style="height:40px; display:inline-flex; align-items:center; gap:6px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; padding:0 16px; font-size:14px; font-weight:700; text-decoration:none">
      <i data-lucide="x" width="15" height="15"></i> Limpar
    </a>
    <?php endif; ?>
  </form>
</div>

<!-- Lista de notas -->
<?php if (empty($notas)): ?>
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:60px 20px; text-align:center">
  <i data-lucide="inbox" width="48" height="48" style="color:#EAEAEA"></i>
  <div style="font-size:20px; font-weight:700; color:#141922; margin-top:12px">Caixa de entrada limpa</div>
  <p style="font-size:14px; color:#888; margin:6px 0 0">Nenhuma nota capturada aguardando vinculação neste filtro.</p>
</div>
<?php endif; ?>

<?php foreach ($notas as $nota):
  $isSugerida  = $nota['status'] === 'sugerida';
  $isResolvida = in_array($nota['status'], ['vinculada', 'ignorada'], true);
  $borda = $isSugerida ? '#FF8A2D' : ($nota['status'] === 'vinculada' ? '#00A960' : ($nota['status'] === 'ignorada' ? '#888888' : '#EAEAEA'));
  // Dados da nota para o modo "processo existente" do modal — resumo e
  // conferência de fornecedor/filial contra o processo digitado.
  $notaInfo = [
      'tipo_documento'    => $nota['tipo_documento'] ?? '',
      'numero_nf'         => $nota['numero_nf'],
      'fornecedor_nome'   => $nota['fornecedor_nome'],
      'fornecedor_cnpj'   => preg_replace('/\D+/', '', (string) $nota['fornecedor_cnpj']),
      'destinatario_cnpj' => preg_replace('/\D+/', '', (string) $nota['destinatario_cnpj']),
      'valor'             => (float) $nota['valor'],
  ];
?>
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; margin-bottom:15px; overflow:hidden; border-left:4px solid <?= $borda ?>">

  <!-- Cabeçalho da linha -->
  <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:11px 20px; border-bottom:1px solid #EAEAEA; flex-wrap:wrap">
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap">
      <?php if (!$isResolvida && SELECAO_MULTIPLA_CAPTURAS): ?>
      <input type="checkbox" class="cap-select" data-id="<?= h($nota['id']) ?>"
             data-nota='<?= h(json_encode([
                 'numero_nf'       => $nota['numero_nf'],
                 'fornecedor_nome' => $nota['fornecedor_nome'],
                 'valor'           => (float) $nota['valor'],
                 'tipo_documento'  => $nota['tipo_documento'] ?? '',
             ])) ?>'
             onchange="capToggleSelecao(this)" title="Selecionar pra combinar com outra nota"
             style="width:18px; height:18px; cursor:pointer; accent-color:#2347C8">
      <?php endif; ?>
      <span style="font-size:16px; font-weight:700; color:#141922">NF <?= h($nota['numero_nf']) ?></span>
      <?php if (!empty($nota['tipo_documento'])): ?>
      <span style="background:#141922; color:#fff; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px"><?= h($nota['tipo_documento']) ?></span>
      <?php endif; ?>
      <?php if (!empty($nota['categoria_origem'])): ?>
      <span style="background:#D8D4E4; color:#606060; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px"><?= h($nota['categoria_origem']) ?></span>
      <?php endif; ?>
      <?php if ($isSugerida): ?>
      <span style="background:#FFF3E0; color:#FF8A2D; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px">
        Sugestão: <?= h($nota['regra_template_nome'] ?? 'template') ?>
      </span>
      <?php elseif ($nota['status'] === 'vinculada'): ?>
      <span style="background:#D8F5E5; color:#00A960; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px">
        Vinculada · <?= h($nota['processo_codigo'] ?? '') ?>
      </span>
      <?php elseif ($nota['status'] === 'ignorada'): ?>
      <span style="background:#EAEAEA; color:#888; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px"
            <?= !empty($nota['motivo_ignorada']) ? 'title="' . h($nota['motivo_ignorada']) . '"' : '' ?>>
        <?= !empty($nota['regra_id']) ? 'Arquivada' : 'Ignorada' ?>
      </span>
      <?php if (!empty($nota['motivo_ignorada'])): ?>
      <span style="font-size:12px; color:#888"><?= h($nota['motivo_ignorada']) ?></span>
      <?php endif; ?>
      <?php else: ?>
      <span style="background:#FFE8EE; color:#B3261E; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px">Aguardando vínculo</span>
      <?php endif; ?>
      <?php
      // Ambígua: casou com mais de uma regra de pré-fluxo e o motor se recusou a
      // escolher. Fica parada até alguém decidir — o selo diz com o quê colidiu.
      $ambRegras = !empty($nota['ambigua_regras'])
          ? (json_decode((string) $nota['ambigua_regras'], true) ?: [])
          : [];
      ?>
      <?php if ($ambRegras && !$isResolvida): ?>
      <span style="background:#7A4FD0; color:#141922; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px; display:inline-flex; align-items:center; gap:5px"
            title="Casou com <?= count($ambRegras) ?> regras de pré-fluxo e nenhuma foi aplicada: <?= h(implode(' · ', array_column($ambRegras, 'nome'))) ?>">
        <i data-lucide="git-fork" width="11" height="11"></i>
        Ambígua · <?= count($ambRegras) ?> regras
      </span>
      <?php endif; ?>
    </div>
    <div style="font-size:12px; color:#888">
      Capturada em <?= date('d/m/Y H:i', strtotime($nota['capturada_em'])) ?> · <?= h(capFmtOrigem((string) $nota['origem'])) ?>
    </div>
  </div>

  <!-- Corpo: documentos + emitente + destinatário + ações -->
  <div style="display:grid; grid-template-columns:150px 1fr 1fr 230px; gap:0; align-items:stretch">

    <!-- Documentos -->
    <div style="padding:15px; border-right:1px solid #EAEAEA; display:flex; flex-direction:column; gap:8px; align-items:center; justify-content:center">
      <button type="button" onclick="capVerXml('<?= h($nota['id']) ?>', '<?= h($nota['numero_nf']) ?>')"
              style="display:flex; flex-direction:column; align-items:center; gap:4px; background:#F6F6F6; border:1px solid #EAEAEA; border-radius:8px; padding:10px 16px; cursor:pointer; width:100px"
              onmouseover="this.style.borderColor='#2347C8'" onmouseout="this.style.borderColor='#EAEAEA'">
        <i data-lucide="file-code-2" width="22" height="22" style="color:#00A960"></i>
        <span style="font-size:11px; font-weight:700; color:#606060">XML</span>
      </button>
      <button type="button" onclick="capVerDanfe('<?= h($nota['id']) ?>', '<?= h($nota['numero_nf']) ?>', '<?= h($nota['tipo_documento'] ?? 'NFe') ?>')"
              style="display:flex; flex-direction:column; align-items:center; gap:4px; background:#F6F6F6; border:1px solid #EAEAEA; border-radius:8px; padding:10px 16px; cursor:pointer; width:100px"
              onmouseover="this.style.borderColor='#2347C8'" onmouseout="this.style.borderColor='#EAEAEA'">
        <i data-lucide="file-text" width="22" height="22" style="color:#B3261E"></i>
        <span style="font-size:11px; font-weight:700; color:#606060">DANFE</span>
      </button>
    </div>

    <!-- Emitente -->
    <div style="padding:15px 20px; border-right:1px solid #EAEAEA">
      <div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; margin-bottom:7px; display:flex; align-items:center; gap:6px">
        <i data-lucide="building-2" width="14" height="14"></i> Emitente
      </div>
      <div style="font-size:14px; font-weight:700; color:#141922; margin-bottom:4px"><?= h($nota['fornecedor_nome']) ?></div>
      <div style="font-size:13px; color:#606060">CNPJ: <?= capFmtCnpj($nota['fornecedor_cnpj']) ?></div>
      <div style="font-size:13px; color:#606060">Emissão: <?= date('d/m/Y', strtotime($nota['data_emissao'])) ?></div>
      <div style="font-size:11px; color:#888; margin-top:6px; display:flex; align-items:flex-start; gap:4px" title="Chave de acesso">
        <i data-lucide="key-round" width="11" height="11" style="flex-shrink:0; margin-top:1px"></i>
        <span style="word-break:break-all"><?= h($nota['chave_nf']) ?></span>
      </div>
    </div>

    <!-- Destinatário + valor -->
    <div style="padding:15px 20px; border-right:1px solid #EAEAEA">
      <div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; margin-bottom:7px; display:flex; align-items:center; gap:6px">
        <i data-lucide="map-pin" width="14" height="14"></i> Destinatário
      </div>
      <div style="font-size:14px; font-weight:700; color:#141922; margin-bottom:4px"><?= h($nota['filial_nome'] ?? '—') ?></div>
      <div style="font-size:13px; color:#606060">CNPJ: <?= capFmtCnpj($nota['destinatario_cnpj']) ?></div>
      <div style="margin-top:9px">
        <span style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase">Valor da nota</span>
        <div style="font-size:20px; font-weight:700; color:#2347C8"><?= capFmtValor($nota['valor']) ?></div>
      </div>
    </div>

    <!-- Ações -->
    <div style="padding:15px 20px; display:flex; flex-direction:column; gap:8px; justify-content:center">
      <?php if (!$isResolvida): ?>
        <?php if ($isSugerida): ?>
        <button type="button"
                onclick='capAbrirVinculo(<?= json_encode($nota['id']) ?>, <?= json_encode($nota['regra_template_id']) ?>, <?= $nota['regra_campos_json'] ?: '{}' ?>, <?= json_encode('Regra: ' . ($nota['regra_nome'] ?? '')) ?>, <?= h(json_encode($nota['parcelas_xml'] ?? [], JSON_UNESCAPED_UNICODE)) ?>, <?= h(json_encode($notaInfo, JSON_UNESCAPED_UNICODE)) ?>)'
                style="display:flex; align-items:center; justify-content:center; gap:6px; background:#FF8A2D; color:#fff; border:none; border-radius:4px; padding:10px; font-size:13px; font-weight:700; cursor:pointer">
          <i data-lucide="check-circle-2" width="15" height="15"></i> Confirmar sugestão
        </button>
        <?php endif; ?>
        <button type="button"
                onclick='capAbrirVinculo(<?= json_encode($nota['id']) ?>, null, {}, null, <?= h(json_encode($nota['parcelas_xml'] ?? [], JSON_UNESCAPED_UNICODE)) ?>, <?= h(json_encode($notaInfo, JSON_UNESCAPED_UNICODE)) ?>)'
                style="display:flex; align-items:center; justify-content:center; gap:6px; background:<?= $isSugerida ? 'transparent' : '#2347C8' ?>; color:<?= $isSugerida ? '#2347C8' : '#fff' ?>; border:<?= $isSugerida ? '1px solid #2347C8' : 'none' ?>; border-radius:4px; padding:10px; font-size:13px; font-weight:700; cursor:pointer">
          <i data-lucide="git-branch" width="15" height="15"></i> Vincular a processo
        </button>
        <form method="POST" action="?module=capturas&amp;action=ignorar" onsubmit="return confirm('Remover esta nota da fila de vinculação?')" style="margin:0">
          <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
          <input type="hidden" name="nota_id" value="<?= h($nota['id']) ?>">
          <input type="hidden" name="motivo" value="">
          <button type="submit"
                  style="display:flex; align-items:center; justify-content:center; gap:6px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; padding:10px; font-size:13px; font-weight:700; cursor:pointer; width:100%">
            <i data-lucide="archive-x" width="15" height="15"></i> Ignorar
          </button>
        </form>
      <?php elseif ($nota['status'] === 'vinculada'): ?>
        <div style="text-align:center; font-size:13px; color:#00A960; font-weight:700">
          <i data-lucide="check-circle-2" width="18" height="18"></i><br>
          Processo <?= h($nota['processo_codigo'] ?? '') ?>
        </div>
      <?php else: ?>
        <div style="text-align:center; font-size:13px; color:#888">Fora da fila</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- Paginação (50 por página) -->
<?php if (($totalPaginas ?? 1) > 1):
  $pagBase = '?module=capturas&action=index&f=1'
           . '&status=' . urlencode($filters['status'])
           . '&tipo='   . urlencode($filters['tipo'] ?? '')
           . '&busca='  . urlencode($filters['busca'])
           . implode('', array_map(
               static fn(string $fid): string => '&filial[]=' . urlencode($fid),
               array_map('strval', (array) ($filters['filial_id'] ?? []))
             ))
           . '&pagina=';
  $pagIni = max(1, $pagina - 2);
  $pagFim = min($totalPaginas, $pagina + 2);
?>
<div style="display:flex; align-items:center; justify-content:space-between; gap:12px; background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:12px 20px; margin-bottom:16px; flex-wrap:wrap">
  <div style="font-size:13px; color:#888">
    <?= (int)$totalNotas ?> nota(s) · página <?= (int)$pagina ?> de <?= (int)$totalPaginas ?>
  </div>
  <div style="display:flex; gap:6px; align-items:center">
    <?php if ($pagina > 1): ?>
    <a href="<?= h($pagBase . ($pagina - 1)) ?>" style="display:inline-flex; align-items:center; gap:4px; border:1px solid #DFDFDF; color:#606060; border-radius:4px; padding:7px 12px; font-size:13px; font-weight:700; text-decoration:none">
      <i data-lucide="chevron-left" width="14" height="14"></i> Anterior
    </a>
    <?php endif; ?>
    <?php if ($pagIni > 1): ?>
    <a href="<?= h($pagBase . '1') ?>" style="border:1px solid #DFDFDF; color:#606060; border-radius:4px; padding:7px 12px; font-size:13px; font-weight:700; text-decoration:none">1</a>
    <?php if ($pagIni > 2): ?><span style="color:#888">…</span><?php endif; ?>
    <?php endif; ?>
    <?php for ($p = $pagIni; $p <= $pagFim; $p++): ?>
      <?php if ($p === $pagina): ?>
      <span style="background:#2347C8; color:#fff; border-radius:4px; padding:7px 12px; font-size:13px; font-weight:700"><?= $p ?></span>
      <?php else: ?>
      <a href="<?= h($pagBase . $p) ?>" style="border:1px solid #DFDFDF; color:#606060; border-radius:4px; padding:7px 12px; font-size:13px; font-weight:700; text-decoration:none"><?= $p ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($pagFim < $totalPaginas): ?>
    <?php if ($pagFim < $totalPaginas - 1): ?><span style="color:#888">…</span><?php endif; ?>
    <a href="<?= h($pagBase . $totalPaginas) ?>" style="border:1px solid #DFDFDF; color:#606060; border-radius:4px; padding:7px 12px; font-size:13px; font-weight:700; text-decoration:none"><?= (int)$totalPaginas ?></a>
    <?php endif; ?>
    <?php if ($pagina < $totalPaginas): ?>
    <a href="<?= h($pagBase . ($pagina + 1)) ?>" style="display:inline-flex; align-items:center; gap:4px; border:1px solid #DFDFDF; color:#606060; border-radius:4px; padding:7px 12px; font-size:13px; font-weight:700; text-decoration:none">
      Próxima <i data-lucide="chevron-right" width="14" height="14"></i>
    </a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ══════════ Barra flutuante: combinar notas selecionadas ══════════ -->
<div id="cap-barra-selecao" style="display:none; position:sticky; bottom:16px; align-items:center; justify-content:space-between; gap:16px; background:#141922; color:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.25) 0 8px 24px; padding:14px 20px; margin-top:8px; z-index:100">
  <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap">
    <span style="font-size:14px; font-weight:700"><span id="cap-sel-count">0</span> notas selecionadas</span>
    <span style="font-size:13px; color:#D8D4E4">Total: <b id="cap-sel-total"></b></span>
  </div>
  <div style="display:flex; gap:10px">
    <button type="button" onclick="capLimparSelecao()"
            style="background:transparent; color:#fff; border:1px solid #606060; border-radius:4px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer">Limpar</button>
    <button type="button" onclick="capAbrirVinculoMultiplo()"
            style="background:#2347C8; color:#fff; border:none; border-radius:4px; padding:9px 18px; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px">
      <i data-lucide="git-merge" width="15" height="15"></i> Combinar em um processo
    </button>
  </div>
</div>

<!-- ══════════ Modal: vincular a processo ══════════ -->
<div id="cap-modal-vinculo" style="display:none; position:fixed; inset:0; background:rgba(24,19,31,0.55); z-index:1000; align-items:center; justify-content:center; padding:20px">
  <div style="background:#fff; border-radius:12px; width:100%; max-width:560px; max-height:90vh; overflow:auto; box-shadow:rgba(0,0,0,0.25) 0 8px 32px">
    <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 24px; border-bottom:1px solid #EAEAEA">
      <h3 id="cap-v-titulo" style="font-size:20px; font-weight:700; color:#141922; margin:0">Vincular <span style="color:#2347C8">a processo</span></h3>
      <button type="button" onclick="capFecharModais()" style="background:none; border:none; cursor:pointer; color:#888"><i data-lucide="x" width="20" height="20"></i></button>
    </div>

    <?php // Dois caminhos para a mesma nota: abrir processo novo ou juntar a um
          // que já existe (NFS-e que chega depois da NF-e do mesmo atendimento).
          // Some no modo "combinar N notas", que é sempre processo novo. ?>
    <div id="cap-v-modo" style="display:flex; gap:6px; padding:16px 24px 0">
      <button type="button" id="cap-v-modo-novo" onclick="capModoVinculo('novo')"
              style="flex:1; padding:9px 12px; border-radius:4px; font-size:13px; font-weight:700; cursor:pointer">Criar novo processo</button>
      <button type="button" id="cap-v-modo-existente" onclick="capModoVinculo('existente')"
              style="flex:1; padding:9px 12px; border-radius:4px; font-size:13px; font-weight:700; cursor:pointer">Processo existente</button>
    </div>

    <form method="POST" action="?module=capturas&amp;action=vincular_existente" id="cap-ve-form" style="display:none; padding:20px 24px"
          onsubmit="return capConfirmarExistente()">
      <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
      <input type="hidden" name="nota_id" id="cap-ve-nota-id" value="">
      <input type="hidden" name="processo_id" id="cap-ve-processo-id" value="">

      <div id="cap-ve-nota" style="background:#F6F6F6; border-left:4px solid #2347C8; border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:13px; color:#141922"></div>

      <label for="cap-ve-codigo" style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Número do processo</label>
      <div style="display:flex; gap:8px; margin-bottom:6px">
        <input type="text" id="cap-ve-codigo" placeholder="Ex.: PROC-2026-0323 ou 323" autocomplete="off"
               onkeydown="if(event.key==='Enter'){event.preventDefault();capBuscarProcesso();}"
               oninput="capLimparPreviaProcesso()"
               style="flex:1; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <button type="button" id="cap-ve-buscar" onclick="capBuscarProcesso()"
                style="background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:0 18px; font-size:14px; font-weight:700; cursor:pointer">Buscar</button>
      </div>
      <div id="cap-ve-erro" style="display:none; font-size:12px; font-weight:700; color:#B3261E; margin-bottom:6px"></div>

      <div id="cap-ve-previa" style="display:none; margin-top:12px"></div>

      <label id="cap-ve-somar-wrap" style="display:none; align-items:flex-start; gap:8px; margin-top:14px; font-size:13px; color:#141922; cursor:pointer">
        <input type="checkbox" name="somar_valor" value="1" id="cap-ve-somar" checked onchange="capRenderPreviaValor()"
               style="width:16px; height:16px; margin-top:1px; accent-color:#2347C8">
        <span>Somar o valor desta nota ao valor do processo
          <span style="display:block; font-size:12px; color:#888; margin-top:2px">As parcelas já lançadas não mudam — o financeiro ajusta se precisar.</span>
        </span>
      </label>

      <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px">
        <button type="button" onclick="capFecharModais()"
                style="background:#EAEAEA; color:#606060; border:none; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; cursor:pointer">Cancelar</button>
        <button type="submit" id="cap-ve-submit" disabled
                style="background:#2347C8; color:#fff; border:none; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; cursor:pointer; opacity:0.5">Vincular ao processo</button>
      </div>
    </form>

    <form method="POST" action="?module=capturas&amp;action=vincular" id="cap-v-form" style="padding:20px 24px">
      <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
      <input type="hidden" name="nota_id" id="cap-v-nota-id" value="">
      <div id="cap-v-nota-ids-multi"></div>
      <div id="cap-v-multi-resumo" style="display:none; background:#F6F6F6; border-left:4px solid #2347C8; border-radius:8px; padding:12px 14px; margin-bottom:14px"></div>
      <div id="cap-v-origem" style="display:none; background:#FFF3E0; color:#FF8A2D; font-size:12px; font-weight:700; border-radius:8px; padding:8px 12px; margin-bottom:14px"></div>

      <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Departamento</label>
      <select id="cap-v-departamento" onchange="capFiltrarTemplatesPorDepto(this.value)"
              style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff; margin-bottom:16px">
        <option value="">Todos os departamentos</option>
        <?php foreach ($departamentos as $d): ?>
        <option value="<?= h($d['id']) ?>"><?= h($d['valor']) ?></option>
        <?php endforeach; ?>
      </select>

      <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Template de fluxo</label>
      <select name="template_id" id="cap-v-template" required onchange="capRenderCampos(this.value, {})"
              style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff; margin-bottom:16px">
        <option value="">Selecione o fluxo de aprovação...</option>
        <?php foreach ($templates as $t): ?>
        <option value="<?= h($t['id']) ?>"><?= h($t['nome']) ?></option>
        <?php endforeach; ?>
      </select>

      <div id="cap-v-campos"></div>

      <!-- Parcelas lidas do XML (duplicatas) — informativo, não é campo do form -->
      <div id="cap-v-parcelas" style="display:none; background:#F6F6F6; border-left:4px solid #00A960; border-radius:8px; padding:12px 14px; margin-top:4px"></div>

      <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px">
        <button type="button" onclick="capFecharModais()"
                style="background:#EAEAEA; color:#606060; border:none; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; cursor:pointer">Cancelar</button>
        <button type="submit"
                style="background:#2347C8; color:#fff; border:none; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; cursor:pointer">Criar processo</button>
      </div>
    </form>
  </div>
</div>

<!-- ══════════ Modal: visualizador de documento ══════════ -->
<div id="cap-modal-doc" style="display:none; position:fixed; inset:0; background:rgba(24,19,31,0.55); z-index:1000; align-items:center; justify-content:center; padding:20px">
  <div style="background:#fff; border-radius:12px; width:100%; max-width:900px; max-height:92vh; display:flex; flex-direction:column; box-shadow:rgba(0,0,0,0.25) 0 8px 32px">
    <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 24px; border-bottom:1px solid #EAEAEA">
      <h3 id="cap-doc-titulo" style="font-size:20px; font-weight:700; color:#141922; margin:0">Documento</h3>
      <div style="display:flex; gap:10px; align-items:center">
        <a id="cap-doc-download" href="#" download style="display:none; align-items:center; gap:6px; border:1px solid #2347C8; color:#2347C8; border-radius:4px; padding:8px 16px; font-size:13px; font-weight:700; text-decoration:none">
          <i data-lucide="download" width="15" height="15"></i> Download
        </a>
        <button id="cap-doc-imprimir" type="button" onclick="capImprimirDanfe()" style="display:none; align-items:center; gap:6px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:8px 16px; font-size:13px; font-weight:700; cursor:pointer">
          <i data-lucide="printer" width="15" height="15"></i> Imprimir
        </button>
        <button type="button" onclick="capFecharModais()" style="background:none; border:none; cursor:pointer; color:#888"><i data-lucide="x" width="20" height="20"></i></button>
      </div>
    </div>
    <div id="cap-doc-corpo" style="padding:20px 24px; overflow:auto; flex:1"></div>
  </div>
</div>

<script>
const CAP_TEMPLATES = <?= $templatesJson ?>;
const capSelecionadas = new Map();

function capFecharModais() {
  document.getElementById('cap-modal-vinculo').style.display = 'none';
  document.getElementById('cap-modal-doc').style.display = 'none';
}
// Modais fecham apenas pelos botões (sem ESC / clique-fora).

/* Seleção de notas pra combinar em um único processo (produto + serviço,
   ou qualquer outra combinação que o operador decidir que é uma obrigação
   só). Estado vive só no JS — cada checkbox carrega os dados de exibição
   da própria nota em data-nota, não precisa de mapa vindo do servidor. */
function capToggleSelecao(chk) {
  const id = chk.dataset.id;
  if (chk.checked) {
    capSelecionadas.set(id, JSON.parse(chk.dataset.nota));
  } else {
    capSelecionadas.delete(id);
  }
  capAtualizarBarraSelecao();
}

function capAtualizarBarraSelecao() {
  const barra = document.getElementById('cap-barra-selecao');
  const n = capSelecionadas.size;
  if (n < 2) { barra.style.display = 'none'; return; }
  let total = 0;
  capSelecionadas.forEach(v => total += Number(v.valor || 0));
  document.getElementById('cap-sel-count').textContent = n;
  document.getElementById('cap-sel-total').textContent = capMoeda(total);
  barra.style.display = 'flex';
}

function capLimparSelecao() {
  capSelecionadas.clear();
  document.querySelectorAll('.cap-select').forEach(c => c.checked = false);
  capAtualizarBarraSelecao();
}

/* Abre o mesmo modal de vinculação, em modo "combinar N notas": troca o
   destino do form pra vincular_multiplas, manda um nota_ids[] por nota
   selecionada em vez do nota_id único, e mostra o resumo com a soma —
   pré-preenchida, mas o valor do processo continua editável normalmente
   na tela de edição de campos depois de criado. */
function capAbrirVinculoMultiplo() {
  if (capSelecionadas.size < 2) return;

  // Combinar N notas é sempre processo novo — sem o seletor de modo.
  document.getElementById('cap-v-modo').style.display = 'none';
  capModoVinculo('novo');

  document.getElementById('cap-v-form').action = '?module=capturas&action=vincular_multiplas';
  const notaIdField = document.getElementById('cap-v-nota-id');
  notaIdField.value = '';
  notaIdField.disabled = true;

  const cont = document.getElementById('cap-v-nota-ids-multi');
  cont.innerHTML = '';
  let resumo = '';
  let total = 0;
  capSelecionadas.forEach((n, id) => {
    cont.insertAdjacentHTML('beforeend', `<input type="hidden" name="nota_ids[]" value="${capEsc(id)}">`);
    total += Number(n.valor || 0);
    resumo += `<div style="display:flex; justify-content:space-between; gap:12px; font-size:13px; padding:3px 0">
                 <span>${capEsc(n.tipo_documento)} ${capEsc(n.numero_nf)} — ${capEsc(n.fornecedor_nome || '')}</span>
                 <span><b>${capMoeda(n.valor)}</b></span>
               </div>`;
  });
  document.getElementById('cap-v-multi-resumo').innerHTML =
    `<div style="font-size:10px; font-weight:700; color:#2347C8; text-transform:uppercase; margin-bottom:8px">${capSelecionadas.size} notas combinadas neste processo</div>
     ${resumo}
     <div style="display:flex; justify-content:space-between; font-size:14px; font-weight:700; border-top:1px solid #EAEAEA; margin-top:8px; padding-top:8px">
       <span>Valor total do processo</span><span>${capMoeda(total)}</span>
     </div>
     <div style="font-size:12px; color:#888; margin-top:6px">Soma pré-preenchida — pode ajustar depois na edição de campos do processo.</div>`;
  document.getElementById('cap-v-multi-resumo').style.display = 'block';
  document.getElementById('cap-v-parcelas').style.display = 'none';
  document.getElementById('cap-v-origem').style.display = 'none';
  document.getElementById('cap-v-titulo').innerHTML = 'Combinar <span style="color:#2347C8">em um processo</span>';

  const deptoSel = document.getElementById('cap-v-departamento');
  deptoSel.value = '';
  capFiltrarTemplatesPorDepto('');
  document.getElementById('cap-v-template').value = '';
  capRenderCampos('', {});

  document.getElementById('cap-modal-vinculo').style.display = 'flex';
}

/* Repovoa o select de templates conforme o departamento escolhido.
   deptoId vazio → todos. Templates sem departamento aparecem sempre. */
function capFiltrarTemplatesPorDepto(deptoId) {
  const sel = document.getElementById('cap-v-template');
  const atual = sel.value;
  const lista = CAP_TEMPLATES.filter(t =>
    !deptoId || !t.departamento_id || t.departamento_id === deptoId);

  let html = '<option value="">Selecione o fluxo de aprovação...</option>';
  lista.forEach(t => {
    html += `<option value="${capEsc(t.id)}">${capEsc(t.nome)}</option>`;
  });
  sel.innerHTML = html;

  // Preserva a seleção anterior se ainda estiver disponível na lista filtrada
  if (atual && lista.some(t => t.id === atual)) {
    sel.value = atual;
  } else {
    sel.value = '';
    capRenderCampos('', {});
  }
}

/* Renderiza os campos dinâmicos do template no modal de vinculação */
function capRenderCampos(templateId, valores) {
  const cont = document.getElementById('cap-v-campos');
  cont.innerHTML = '';
  const tpl = CAP_TEMPLATES.find(t => t.id === templateId);
  if (!tpl) return;

  tpl.campos.forEach(c => {
    const val = (valores && valores[c.nome]) || '';
    const req = c.obrigatorio ? ' <span style="color:#B3261E">*</span>' : '';
    let input;
    if (c.tipo === 'textarea') {
      input = `<textarea name="campo_${c.nome}" rows="2" ${c.obrigatorio ? 'required' : ''}
                style="width:100%; padding:10px 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box; font-family:inherit">${capEsc(val)}</textarea>`;
    } else if (c.tipo === 'select' && Array.isArray(c.opcoes)) {
      const opts = c.opcoes.map(o =>
        `<option value="${capEsc(o)}" ${o === val ? 'selected' : ''}>${capEsc(o)}</option>`).join('');
      input = `<select name="campo_${c.nome}" ${c.obrigatorio ? 'required' : ''}
                style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff">
                <option value="">Selecione...</option>${opts}</select>`;
    } else {
      input = `<input type="text" name="campo_${c.nome}" value="${capEsc(val)}" ${c.obrigatorio ? 'required' : ''}
                style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box">`;
    }
    cont.insertAdjacentHTML('beforeend',
      `<div style="margin-bottom:14px">
         <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">${capEsc(c.label || c.nome)}${req}</label>
         ${input}
       </div>`);
  });
}

/* Prévia das parcelas que virão das duplicatas do XML. Não são campos do
   formulário: a vinculação relê a cobrança no servidor. É só conferência —
   nota sem duplicata não mostra nada e o processo nasce sem parcela. */
function capRenderParcelas(parcelas) {
  const box = document.getElementById('cap-v-parcelas');
  if (!parcelas || !parcelas.length) {
    box.style.display = 'none';
    box.innerHTML = '';
    return;
  }

  const total = parcelas.reduce((s, p) => s + Number(p.valor || 0), 0);
  const linhas = parcelas.map(p => {
    const [a, m, d] = String(p.vencimento).split('-');
    const forma = p.forma_pagamento_nome ? ' · ' + capEsc(p.forma_pagamento_nome) : '';
    const dup   = p.origem_duplicata ? 'Dup ' + capEsc(p.origem_duplicata) : '—';
    return `<div style="display:flex; justify-content:space-between; gap:12px; font-size:13px; color:#141922; padding:3px 0">
              <span style="color:#606060">${dup}${forma}</span>
              <span>${d}/${m}/${a} &nbsp;<b>${capMoeda(p.valor)}</b></span>
            </div>`;
  }).join('');

  box.innerHTML = `<div style="font-size:10px; font-weight:700; color:#00A960; text-transform:uppercase; margin-bottom:8px">
                     Parcelas do XML — ${parcelas.length} de ${capMoeda(total)}
                   </div>${linhas}
                   <div style="font-size:12px; color:#888; margin-top:8px">
                     Geradas a partir das duplicatas da nota. O financeiro pode ajustar depois.
                   </div>`;
  box.style.display = 'block';
}

function capMoeda(v) {
  return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/* ── Modo do modal: criar processo novo × juntar a processo existente ── */
let capNotaInfo = null;      // nota aberta no modal (resumo + CNPJs)
let capProcPrevia = null;    // processo encontrado pela busca

function capModoVinculo(modo) {
  const existente = modo === 'existente';
  document.getElementById('cap-v-form').style.display  = existente ? 'none' : 'block';
  document.getElementById('cap-ve-form').style.display = existente ? 'block' : 'none';
  [['cap-v-modo-novo', !existente], ['cap-v-modo-existente', existente]].forEach(([id, ativo]) => {
    const b = document.getElementById(id);
    b.style.background = ativo ? '#2347C8' : 'transparent';
    b.style.color      = ativo ? '#fff' : '#2347C8';
    b.style.border     = '1px solid #2347C8';
  });
  if (existente) setTimeout(() => document.getElementById('cap-ve-codigo').focus(), 50);
}

function capFmtDoc(d) {
  d = String(d || '').replace(/\D/g, '');
  if (d.length === 14) return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
  if (d.length === 11) return d.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
  return d || '—';
}

function capPrepararExistente(notaId, info) {
  capNotaInfo = info || null;
  document.getElementById('cap-ve-nota-id').value = notaId;
  document.getElementById('cap-ve-codigo').value = '';
  const tipo = info && info.tipo_documento === 'NFSe' ? 'NFS-e' : (info ? info.tipo_documento : '');
  document.getElementById('cap-ve-nota').innerHTML = info
    ? `<div style="font-size:10px; font-weight:700; color:#2347C8; text-transform:uppercase; margin-bottom:4px">Nota a vincular</div>
       <div style="display:flex; justify-content:space-between; gap:12px">
         <span>${capEsc(tipo)} <b>${capEsc(info.numero_nf)}</b> — ${capEsc(info.fornecedor_nome || '')}</span>
         <b>${capMoeda(info.valor)}</b>
       </div>`
    : '';
  capLimparPreviaProcesso();
}

function capLimparPreviaProcesso() {
  capProcPrevia = null;
  document.getElementById('cap-ve-processo-id').value = '';
  document.getElementById('cap-ve-previa').style.display = 'none';
  document.getElementById('cap-ve-previa').innerHTML = '';
  document.getElementById('cap-ve-erro').style.display = 'none';
  document.getElementById('cap-ve-somar-wrap').style.display = 'none';
  const sub = document.getElementById('cap-ve-submit');
  sub.disabled = true; sub.style.opacity = '0.5';
}

function capBuscarProcesso() {
  const codigo = document.getElementById('cap-ve-codigo').value.trim();
  capLimparPreviaProcesso();
  const erro = document.getElementById('cap-ve-erro');
  if (!codigo) {
    erro.textContent = 'Digite o número do processo.';
    erro.style.display = 'block';
    return;
  }
  const btn = document.getElementById('cap-ve-buscar');
  btn.disabled = true; btn.textContent = 'Buscando…';
  fetch('?module=capturas&action=buscar_processo&codigo=' + encodeURIComponent(codigo))
    .then(r => r.json())
    .then(p => {
      if (!p || !p.success) {
        erro.textContent = (p && p.message) || 'Processo não encontrado.';
        erro.style.display = 'block';
        return;
      }
      capProcPrevia = p;
      capRenderPreviaProcesso();
    })
    .catch(() => { erro.textContent = 'Falha de conexão ao buscar o processo.'; erro.style.display = 'block'; })
    .finally(() => { btn.disabled = false; btn.textContent = 'Buscar'; });
}

const CAP_STATUS_PROC = {
  aberto: 'Em aprovação', devolvido: 'Devolvido', aprovado: 'Concluído',
  cancelado: 'Cancelado', cancelamento_solicitado: 'Cancelamento solicitado', encerrado_apollo: 'Encerrado no Apollo'
};

/* Prévia do processo + avisos de conferência. Fornecedor ou loja diferentes
   não bloqueiam (a nota de serviço pode vir de outro CNPJ do mesmo grupo),
   mas ficam em destaque para o operador não juntar no processo errado. */
function capRenderPreviaProcesso() {
  const p = capProcPrevia;
  const n = capNotaInfo || {};
  const box = document.getElementById('cap-ve-previa');
  const avisos = [];
  if (!p.aceita_vinculo) {
    avisos.push(['#B3261E', '#FFE8EE', `Processo ${CAP_STATUS_PROC[p.status] || p.status} — não aceita novas notas.`]);
  } else {
    if (n.fornecedor_cnpj && p.fornecedor_doc && n.fornecedor_cnpj !== p.fornecedor_doc)
      avisos.push(['#C75B00', '#FFF3E0', 'Fornecedor do processo é diferente do emitente da nota.']);
    if (n.destinatario_cnpj && p.filial_doc && n.destinatario_cnpj !== p.filial_doc)
      avisos.push(['#C75B00', '#FFF3E0', 'Loja do processo é diferente do destinatário da nota.']);
    if (p.status === 'aprovado')
      avisos.push(['#C75B00', '#FFF3E0', 'Processo já concluído — a nota entra, mas o fluxo não reabre.']);
  }

  const linha = (rot, val) => `<div style="display:flex; justify-content:space-between; gap:12px; font-size:13px; padding:3px 0">
      <span style="color:#606060">${rot}</span><span style="color:#141922; font-weight:700; text-align:right">${val}</span></div>`;

  box.innerHTML =
    `<div style="border:1px solid #EAEAEA; border-left:4px solid ${p.aceita_vinculo ? '#00A960' : '#B3261E'}; border-radius:8px; padding:12px 14px">
       <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px">
         <span style="font-size:16px; font-weight:700; color:#141922">${capEsc(p.codigo)}</span>
         <span style="font-size:10px; font-weight:700; text-transform:uppercase; padding:3px 8px; border-radius:8px;
               background:${p.aceita_vinculo ? '#D8F5E5' : '#FFE8EE'}; color:${p.aceita_vinculo ? '#00A960' : '#B3261E'}">${capEsc(CAP_STATUS_PROC[p.status] || p.status)}</span>
       </div>
       ${linha('Fornecedor', capEsc(p.fornecedor || '—') + '<div style="font-weight:400; color:#888; font-size:12px">' + capFmtDoc(p.fornecedor_doc) + '</div>')}
       ${linha('Loja', capEsc(p.filial || '—'))}
       ${linha('Fluxo', capEsc(p.template || '—') + (p.etapa && p.status === 'aberto' ? ' · ' + capEsc(p.etapa) : ''))}
       ${linha('NF principal', capEsc(p.numero_nf || '—'))}
       <div id="cap-ve-valores"></div>
     </div>` +
    avisos.map(([cor, bg, txt]) =>
      `<div style="margin-top:8px; padding:8px 12px; border-radius:6px; font-size:12.5px; font-weight:700; color:${cor}; background:${bg}">${capEsc(txt)}</div>`
    ).join('');
  box.style.display = 'block';

  document.getElementById('cap-ve-processo-id').value = p.aceita_vinculo ? p.id : '';
  document.getElementById('cap-ve-somar-wrap').style.display = p.aceita_vinculo ? 'flex' : 'none';
  const sub = document.getElementById('cap-ve-submit');
  sub.disabled = !p.aceita_vinculo; sub.style.opacity = p.aceita_vinculo ? '1' : '0.5';
  capRenderPreviaValor();
}

function capRenderPreviaValor() {
  const alvo = document.getElementById('cap-ve-valores');
  if (!alvo || !capProcPrevia) return;
  const atual = Number(capProcPrevia.valor || 0);
  const somar = document.getElementById('cap-ve-somar').checked && capProcPrevia.aceita_vinculo;
  const novo  = atual + (somar ? Number((capNotaInfo || {}).valor || 0) : 0);
  alvo.innerHTML = `<div style="display:flex; justify-content:space-between; gap:12px; font-size:14px; font-weight:700; border-top:1px solid #EAEAEA; margin-top:8px; padding-top:8px">
      <span>Valor do processo</span>
      <span>${somar ? '<span style="color:#888; font-weight:400; text-decoration:line-through; margin-right:6px">' + capMoeda(atual) + '</span>' : ''}<span style="color:#2347C8">${capMoeda(novo)}</span></span>
    </div>`;
}

function capConfirmarExistente() {
  const p = capProcPrevia;
  if (!p || !p.aceita_vinculo || !document.getElementById('cap-ve-processo-id').value) return false;
  const n = capNotaInfo || {};
  const ok = confirm('Vincular a nota ' + (n.numero_nf || '') + ' ao processo ' + p.codigo + '?');
  if (ok) {
    const sub = document.getElementById('cap-ve-submit');
    sub.disabled = true; sub.textContent = 'Vinculando…';
  }
  return ok;
}

/* Abre o modal de vinculação; se veio de sugestão, pré-seleciona template + payload da regra */
function capAbrirVinculo(notaId, templateId, campos, origem, parcelas, info) {
  document.getElementById('cap-v-modo').style.display = 'flex';
  capPrepararExistente(notaId, info);
  capModoVinculo('novo');
  document.getElementById('cap-v-form').action = '?module=capturas&action=vincular';
  const notaIdField = document.getElementById('cap-v-nota-id');
  notaIdField.disabled = false;
  notaIdField.value = notaId;
  document.getElementById('cap-v-nota-ids-multi').innerHTML = '';
  document.getElementById('cap-v-multi-resumo').style.display = 'none';
  document.getElementById('cap-v-titulo').innerHTML = 'Vincular <span style="color:#2347C8">a processo</span>';
  capRenderParcelas(parcelas);
  // Se veio de sugestão com template, posiciona o departamento correspondente
  const tplPre = CAP_TEMPLATES.find(t => t.id === templateId);
  const deptoSel = document.getElementById('cap-v-departamento');
  deptoSel.value = (tplPre && tplPre.departamento_id) ? tplPre.departamento_id : '';
  capFiltrarTemplatesPorDepto(deptoSel.value);
  const sel = document.getElementById('cap-v-template');
  sel.value = templateId || '';
  capRenderCampos(sel.value, campos || {});
  const box = document.getElementById('cap-v-origem');
  if (origem) { box.textContent = origem + ' — campos pré-preenchidos pelo pré-fluxo.'; box.style.display = 'block'; }
  else        { box.style.display = 'none'; }
  document.getElementById('cap-modal-vinculo').style.display = 'flex';
}

/* Mostra/oculta os botões de ação do modal de documento */
function capDocAcoes(downloadUrl, mostrarImprimir) {
  const dl = document.getElementById('cap-doc-download');
  const pr = document.getElementById('cap-doc-imprimir');
  if (downloadUrl) { dl.href = downloadUrl; dl.style.display = 'inline-flex'; }
  else             { dl.style.display = 'none'; }
  pr.style.display = mostrarImprimir ? 'inline-flex' : 'none';
}

/* Visualizador de XML — arquivo inteiro, com as tags, direto do servidor */
function capVerXml(notaId, numero) {
  document.getElementById('cap-doc-titulo').innerHTML = 'XML — NF ' + capEsc(numero);
  capDocAcoes('?module=capturas&action=xml&id=' + encodeURIComponent(notaId) + '&download=1', false);
  const corpo = document.getElementById('cap-doc-corpo');
  corpo.innerHTML = '<div style="color:#888; font-size:14px">Carregando...</div>';
  document.getElementById('cap-modal-doc').style.display = 'flex';
  fetch('?module=capturas&action=xml&id=' + encodeURIComponent(notaId))
    .then(r => r.text())
    .then(xml => {
      corpo.innerHTML = '<pre style="background:#141922; color:#7EE787; font-size:12px; line-height:1.6; padding:16px; border-radius:8px; overflow:auto; margin:0; white-space:pre-wrap; word-break:break-word; tab-size:2">' + capEsc(capFormatXml(xml)) + '</pre>';
    })
    .catch(() => { corpo.innerHTML = '<div style="color:#B3261E">Falha ao carregar o XML.</div>'; });
}

/* DANFE/DACTE no layout oficial, renderizado no servidor a partir do XML autêntico */
function capVerDanfe(notaId, numero, tipo) {
  const sigla = (tipo === 'CTe' || tipo === 'CTOs') ? 'DACTE' : 'DANFE';
  document.getElementById('cap-doc-titulo').innerHTML = sigla + ' — NF ' + capEsc(numero);
  capDocAcoes(null, true);
  document.getElementById('cap-doc-corpo').innerHTML =
    '<iframe id="cap-doc-iframe" src="?module=capturas&action=danfe&id=' + encodeURIComponent(notaId) + '"' +
    ' style="width:100%; height:calc(92vh - 140px); min-height:480px; border:1px solid #EAEAEA; border-radius:8px; background:#fff"></iframe>';
  document.getElementById('cap-modal-doc').style.display = 'flex';
}

/* Imprime o DANFE aberto no iframe do modal */
function capImprimirDanfe() {
  const frame = document.getElementById('cap-doc-iframe');
  if (frame && frame.contentWindow) {
    frame.contentWindow.focus();
    frame.contentWindow.print();
  }
}

function capEsc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/* Formata o XML "achatado" do servidor com quebras de linha e indentação por nível de tag */
function capFormatXml(xml) {
  xml = String(xml ?? '').trim();
  // Remove espaços em branco entre tags e coloca cada tag em sua própria linha
  xml = xml.replace(/>\s+</g, '><').replace(/(>)(<)(\/*)/g, '$1\n$2$3');
  const PAD = '  ';
  let pad = 0;
  const out = [];
  xml.split('\n').forEach(node => {
    node = node.trim();
    if (!node) return;
    let indent = 0;
    if (/^<\/.+>$/.test(node)) {
      // tag de fechamento pura → recua antes de imprimir
      pad = Math.max(pad - 1, 0);
    } else if (/^<[^!?][^>]*[^/]>.*<\/.+>$/.test(node)) {
      // abre e fecha na mesma linha (com texto) → não altera o nível
      indent = 0;
    } else if (/^<[^!?][^>]*[^/]>$/.test(node)) {
      // tag de abertura sozinha → aumenta o nível para os filhos
      indent = 1;
    }
    out.push(PAD.repeat(pad) + node);
    pad += indent;
  });
  return out.join('\n');
}
</script>
