<?php
// @versao Proc_06 V_06_18/09/2026
/**
 * views/processos/pesquisa.php
 * Pesquisa dedicada de processos — não pré-carrega nenhuma lista.
 * Só consulta o banco quando o usuário efetivamente busca (?q=...).
 *
 * Variáveis esperadas:
 *   $processos  array|null   null = nenhuma busca feita ainda; array = resultado (pode ser vazio)
 *   $total      int          total de resultados encontrados
 *   $busca      string       termo pesquisado
 */

$processos       = $processos       ?? null;
$total           = $total           ?? 0;
$busca           = $busca           ?? '';
$notasCapturadas = $notasCapturadas ?? [];
$camposBusca     = $camposBusca     ?? [];

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('capturaStatusBadge')) {
    function capturaStatusBadge(string $status): string {
        $mapa = [
            'capturada' => ['bg' => '#EAEAEA', 'color' => '#888888', 'label' => 'Capturada'],
            'sugerida'  => ['bg' => '#FFF3E0', 'color' => '#FF8A2D', 'label' => 'Sugerida'],
            'ignorada'  => ['bg' => '#FFE8EE', 'color' => '#B3261E', 'label' => 'Arquivada'],
            'vinculada' => ['bg' => '#D8F5E5', 'color' => '#00A960', 'label' => 'Vinculada'],
        ];
        $s = $mapa[$status] ?? ['bg' => '#EAEAEA', 'color' => '#888888', 'label' => ucfirst($status)];
        return '<span style="display:inline-block; padding:2px 10px; border-radius:8px; font-size:10px; font-weight:700;'
             . ' background:' . $s['bg'] . '; color:' . $s['color'] . '">' . h($s['label']) . '</span>';
    }
}

if (!function_exists('processoStatusBadge')) {
    function processoStatusBadge(string $status): string {
        $mapa = [
            'aberto'                  => ['bg' => '#E3F0FF', 'color' => '#1565C0', 'label' => 'Aberto'],
            'aprovado'                => ['bg' => '#D8F5E5', 'color' => '#00A960', 'label' => 'Finalizado'],
            'devolvido'               => ['bg' => '#FFF3E0', 'color' => '#FF8A2D', 'label' => 'Devolvido'],
            'cancelado'               => ['bg' => '#FFE8EE', 'color' => '#B3261E', 'label' => 'Cancelado'],
            'cancelamento_solicitado' => ['bg' => '#FFE8EE', 'color' => '#B3261E', 'label' => 'Canc. Solicitado'],
            'encerrado_apollo'        => ['bg' => '#D8D4E4', 'color' => '#606060', 'label' => 'Encerrado'],
        ];
        $s = $mapa[$status] ?? ['bg' => '#EAEAEA', 'color' => '#888888', 'label' => ucfirst($status)];
        return '<span style="display:inline-block; padding:2px 10px; border-radius:8px; font-size:10px; font-weight:700;'
             . ' background:' . $s['bg'] . '; color:' . $s['color'] . '">' . h($s['label']) . '</span>';
    }
}
?>

<!-- Cabeçalho da página -->
<div style="margin-bottom:28px">
  <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
    Pesquisar <span style="color:#2347C8">processos</span>
  </h1>
  <p style="font-size:14px; color:#888; margin:0">Busque por código, fornecedor, CNPJ, número da NF ou qualquer campo do formulário (placa, etc.) — os resultados só aparecem depois da busca.</p>
</div>

<!-- Campo de busca -->
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:20px; margin-bottom:20px">
  <form method="get" action="" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap">
    <input type="hidden" name="module" value="processos">
    <input type="hidden" name="action" value="pesquisar">

    <div style="flex:1; min-width:280px; position:relative">
      <i data-lucide="search" width="18" height="18"
         style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#888; pointer-events:none"></i>
      <input type="text" name="q" id="pf-input-busca" value="<?= h($busca) ?>" autofocus
             placeholder="Digite ao menos 2 caracteres — código, fornecedor, CNPJ, NF, placa, valor..."
             style="width:100%; height:48px; border:1px solid #DFDFDF; border-radius:4px; padding:0 14px 0 42px;
                    font-size:15px; color:#141922; box-sizing:border-box; outline:none"
             onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
    </div>

    <div style="position:relative">
      <button type="button" id="pf-filtro-btn" title="Escolher em quais campos buscar"
              style="height:48px; width:48px; border:1px solid #DFDFDF; border-radius:4px; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#606060">
        <i data-lucide="sliders-horizontal" width="18" height="18"></i>
      </button>
      <div id="pf-filtro-pop" style="display:none; position:absolute; top:54px; right:0; z-index:20; background:#fff; border:1px solid #EAEAEA; border-radius:8px; box-shadow:rgba(0,0,0,0.12) 0 4px 16px; padding:14px; min-width:230px">
        <div style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase; margin-bottom:8px">Buscar nos campos</div>

        <label style="display:flex; align-items:center; gap:8px; font-size:14px; color:#141922; padding:6px 0; cursor:pointer">
          <input type="checkbox" class="pf-campo" name="campos[]" value="nf" <?= in_array('nf', $camposBusca, true) ? 'checked' : '' ?>>
          Número da Nota
        </label>
        <label style="display:flex; align-items:center; gap:8px; font-size:14px; color:#141922; padding:6px 0; cursor:pointer">
          <input type="checkbox" class="pf-campo" name="campos[]" value="valor" <?= in_array('valor', $camposBusca, true) ? 'checked' : '' ?>>
          Valor
        </label>
        <label style="display:flex; align-items:center; gap:8px; font-size:14px; color:#141922; padding:6px 0; cursor:pointer">
          <input type="checkbox" class="pf-campo" name="campos[]" value="cnpj" <?= in_array('cnpj', $camposBusca, true) ? 'checked' : '' ?>>
          CNPJ
        </label>
        <label style="display:flex; align-items:center; gap:8px; font-size:14px; color:#141922; padding:6px 0; cursor:pointer">
          <input type="checkbox" class="pf-campo" name="campos[]" value="placa" <?= in_array('placa', $camposBusca, true) ? 'checked' : '' ?>>
          Placa
        </label>

        <div style="border-top:1px solid #EAEAEA; margin-top:8px; padding-top:8px; display:flex; justify-content:space-between; align-items:center">
          <span style="font-size:11px; color:#888">Nenhum marcado = busca em tudo</span>
          <button type="button" id="pf-filtro-limpar" style="border:none; background:none; color:#2347C8; font-size:12px; font-weight:700; cursor:pointer; padding:0">Limpar</button>
        </div>
      </div>
    </div>

    <button type="submit"
            style="height:48px; padding:0 28px; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:8px">
      <i data-lucide="search" width="16" height="16"></i>
      Pesquisar
    </button>

    <?php if ($busca !== ''): ?>
    <a href="?module=processos&amp;action=pesquisar"
       style="height:48px; padding:0 18px; background:#EAEAEA; color:#606060; border-radius:4px; font-size:14px; font-weight:700; display:inline-flex; align-items:center; text-decoration:none">
      Limpar
    </a>
    <?php endif; ?>
  </form>
</div>

<?php if ($processos === null): ?>

  <!-- Nenhuma busca feita ainda — nada foi carregado do banco -->
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden">
    <?php
    $emptyIcon    = 'search';
    $emptyTitle   = 'Digite algo para pesquisar';
    $emptyMessage = 'Nenhum processo é exibido até você buscar por código, fornecedor, número da NF ou um campo do formulário (placa, etc.).';
    $emptyAction  = null;
    include VIEWS . '/components/empty-state.php';
    ?>
  </div>

<?php elseif (empty($processos)): ?>

  <!-- Busca feita, sem resultados -->
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden">
    <?php
    $emptyIcon    = 'file-x';
    $emptyTitle   = 'Nenhum processo encontrado';
    $emptyMessage = 'Não encontramos processos para "' . h($busca) . '". Tente outro termo.';
    $emptyAction  = null;
    include VIEWS . '/components/empty-state.php';
    ?>
  </div>

<?php else: ?>

  <!-- Resultados -->
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden">
    <div style="overflow-x:auto">
      <table style="width:100%; border-collapse:collapse; font-size:13px">
        <thead>
          <tr style="background:#606060; color:#fff">
            <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase; white-space:nowrap">Código</th>
            <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase">Fornecedor</th>
            <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase; white-space:nowrap">Nº NF</th>
            <th style="padding:12px 16px; text-align:right; font-weight:700; font-size:13px; text-transform:uppercase; white-space:nowrap">Valor</th>
            <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase">Filial</th>
            <th style="padding:12px 16px; text-align:center; font-weight:700; font-size:13px; text-transform:uppercase">Etapa</th>
            <th style="padding:12px 16px; text-align:center; font-weight:700; font-size:13px; text-transform:uppercase">Status</th>
            <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase; white-space:nowrap">Abertura</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($processos as $i => $proc): ?>
          <tr onclick="window.location='<?= h(linkDetalheProcesso($proc['id'], 'processos')) ?>'"
              style="background:<?= $i % 2 === 0 ? '#FFFFFF' : '#F8F8F8' ?>; cursor:pointer; transition:background 0.15s"
              onmouseover="this.style.background='#EEF2FD'"
              onmouseout="this.style.background='<?= $i % 2 === 0 ? '#FFFFFF' : '#F8F8F8' ?>'">

            <td style="padding:12px 16px; white-space:nowrap">
              <a href="<?= h(linkDetalheProcesso($proc['id'], 'processos')) ?>"
                 style="font-weight:700; color:#2347C8; text-decoration:none">
                <?= h($proc['codigo']) ?>
              </a>
              <?php if ($proc['is_teste']): ?>
              <span style="display:inline-block; padding:1px 6px; border-radius:8px; font-size:10px; font-weight:700; background:#FFF3CD; color:#856404; margin-left:4px">TESTE</span>
              <?php endif; ?>
            </td>

            <td style="padding:12px 16px; color:#141922; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
              <?= h($proc['fornecedor_nome']) ?>
            </td>

            <td style="padding:12px 16px; color:#606060"><?= h($proc['numero_nf']) ?></td>

            <td style="padding:12px 16px; text-align:right; font-weight:700; color:#141922; white-space:nowrap">
              R$ <?= number_format((float) $proc['valor_nf'], 2, ',', '.') ?>
            </td>

            <td style="padding:12px 16px; color:#606060; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
              <?= h($proc['filial_nome'] ?? '—') ?>
            </td>

            <td style="padding:12px 16px; text-align:center; color:#606060">
              <?= (int)$proc['etapa_atual'] ?>/<?= (int)$proc['total_etapas'] ?>
            </td>

            <td style="padding:12px 16px; text-align:center">
              <?= processoStatusBadge($proc['status']) ?>
            </td>

            <td style="padding:12px 16px; color:#888; white-space:nowrap; font-size:12px">
              <?= date('d/m/Y', strtotime($proc['criado_em'])) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="padding:12px 20px; border-top:1px solid #EAEAEA">
      <span style="font-size:13px; color:#888">
        <?= $total ?> processo<?= $total !== 1 ? 's' : '' ?> encontrado<?= $total !== 1 ? 's' : '' ?><?= $total > count($processos) ? ' (mostrando os ' . count($processos) . ' primeiros)' : '' ?>
      </span>
    </div>
  </div>

<?php endif; ?>

<?php if (!empty($notasCapturadas)): ?>

  <!-- Notas capturadas que casam com a busca mas ainda não viraram processo
       (inclui arquivadas/ignoradas por regra) -->
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden; margin-top:20px">
    <div style="padding:16px 20px; border-bottom:1px solid #EAEAEA; display:flex; align-items:center; gap:8px">
      <i data-lucide="inbox" width="18" height="18" style="color:#606060"></i>
      <h2 style="font-size:16px; font-weight:700; color:#141922; margin:0">
        Notas capturadas <span style="color:#2347C8">sem processo</span>
      </h2>
    </div>
    <p style="font-size:13px; color:#888; margin:0; padding:10px 20px 0">
      Estas notas foram capturadas mas ainda não geraram processo (podem ter sido arquivadas por uma regra). Clique para abrir em Notas Capturadas.
    </p>
    <div style="overflow-x:auto; padding-top:8px">
      <table style="width:100%; border-collapse:collapse; font-size:13px">
        <thead>
          <tr style="background:#606060; color:#fff">
            <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase; white-space:nowrap">Nº NF</th>
            <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase">Fornecedor</th>
            <th style="padding:12px 16px; text-align:right; font-weight:700; font-size:13px; text-transform:uppercase; white-space:nowrap">Valor</th>
            <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase">Filial</th>
            <th style="padding:12px 16px; text-align:center; font-weight:700; font-size:13px; text-transform:uppercase">Situação</th>
            <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase">Regra / Motivo</th>
            <th style="padding:12px 16px; text-align:left; font-weight:700; font-size:13px; text-transform:uppercase; white-space:nowrap">Capturada</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($notasCapturadas as $j => $nota):
            $linkNota = '?module=capturas&action=index&f=1&status=todos&busca=' . urlencode($nota['chave_nf'] ?: $nota['numero_nf']);
          ?>
          <tr onclick="window.location='<?= h($linkNota) ?>'"
              style="background:<?= $j % 2 === 0 ? '#FFFFFF' : '#F8F8F8' ?>; cursor:pointer; transition:background 0.15s"
              onmouseover="this.style.background='#EEF2FD'"
              onmouseout="this.style.background='<?= $j % 2 === 0 ? '#FFFFFF' : '#F8F8F8' ?>'">

            <td style="padding:12px 16px; white-space:nowrap">
              <a href="<?= h($linkNota) ?>" style="font-weight:700; color:#2347C8; text-decoration:none"><?= h($nota['numero_nf']) ?></a>
            </td>

            <td style="padding:12px 16px; color:#141922; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
              <?= h($nota['fornecedor_nome']) ?>
            </td>

            <td style="padding:12px 16px; text-align:right; font-weight:700; color:#141922; white-space:nowrap">
              R$ <?= number_format((float) ($nota['valor'] ?? 0), 2, ',', '.') ?>
            </td>

            <td style="padding:12px 16px; color:#606060; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
              <?= h($nota['filial_nome'] ?? '—') ?>
            </td>

            <td style="padding:12px 16px; text-align:center">
              <?= capturaStatusBadge((string) $nota['status']) ?>
            </td>

            <td style="padding:12px 16px; color:#606060; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
              <?= h($nota['motivo_ignorada'] ?: ($nota['regra_nome'] ?? '—')) ?>
            </td>

            <td style="padding:12px 16px; color:#888; white-space:nowrap; font-size:12px">
              <?= !empty($nota['capturada_em']) ? date('d/m/Y', strtotime($nota['capturada_em'])) : '—' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="padding:12px 20px; border-top:1px solid #EAEAEA">
      <span style="font-size:13px; color:#888">
        <?= count($notasCapturadas) ?> nota<?= count($notasCapturadas) !== 1 ? 's' : '' ?> capturada<?= count($notasCapturadas) !== 1 ? 's' : '' ?> sem processo
      </span>
    </div>
  </div>

<?php endif; ?>

<script>
(function () {
  var STORAGE_KEY = 'notas_pesquisa_campos';
  var btn    = document.getElementById('pf-filtro-btn');
  var pop    = document.getElementById('pf-filtro-pop');
  var checks = document.querySelectorAll('.pf-campo');
  var input  = document.getElementById('pf-input-busca');
  var limpar = document.getElementById('pf-filtro-limpar');

  function ativos() {
    return Array.prototype.filter.call(checks, function (c) { return c.checked; })
                .map(function (c) { return c.value; });
  }

  function salvarPreferencia() {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(ativos())); } catch (e) {}
  }

  // Sem busca na URL ainda (primeira visita/reload sem pesquisar): restaura
  // a última seleção deste navegador. Com busca na URL, os checkboxes já
  // vieram marcados pelo PHP a partir de "campos[]" — só resta gravar de
  // novo pra manter o localStorage em dia.
  if (!/[?&]q=/.test(location.search)) {
    try {
      var salvo = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
      checks.forEach(function (c) { c.checked = salvo.indexOf(c.value) !== -1; });
    } catch (e) {}
  } else {
    salvarPreferencia();
  }

  checks.forEach(function (c) { c.addEventListener('change', salvarPreferencia); });

  if (btn && pop) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      pop.style.display = pop.style.display === 'none' ? 'block' : 'none';
    });
    document.addEventListener('click', function (e) {
      if (pop.style.display !== 'none' && !pop.contains(e.target) && e.target !== btn) {
        pop.style.display = 'none';
      }
    });
  }

  if (limpar) {
    limpar.addEventListener('click', function () {
      checks.forEach(function (c) { c.checked = false; });
      salvarPreferencia();
    });
  }

  // Máscara de valor — só entra em ação quando o filtro "Valor" está
  // marcado explicitamente (evita atrapalhar quem está digitando CNPJ/NF,
  // que também são só dígitos). "1450" -> "1.450,00 " (o espaço no fim é
  // proposital: sinaliza que a busca vai bater no valor da nota).
  //
  // O buffer de dígitos é mantido à parte (não reextraído do texto já
  // mascarado) de propósito: reler dígitos de "1.450,00 " pra descobrir o
  // que o usuário "realmente" digitou é ambíguo — o ",00" que NÓS
  // acrescentamos vira dígito de novo na tecla seguinte e o valor cresce
  // sozinho a cada tecla (bug real: "565" virava "5.006.005,00").
  var checkValor   = document.querySelector('.pf-campo[value="valor"]');
  var valorBuffer  = '';

  function renderMascaraValor() {
    var num = parseInt(valorBuffer, 10);
    input.value = valorBuffer === '' || isNaN(num) ? '' : num.toLocaleString('pt-BR') + ',00 ';
  }

  if (input && checkValor) {
    checkValor.addEventListener('change', function () {
      // Ativou o filtro Valor: começa do zero (o que estava digitado antes
      // não tinha por que já valer como valor). Desativou: solta o campo,
      // volta a ser busca livre normal.
      if (this.checked) {
        valorBuffer = input.value.replace(/\D/g, '');
        renderMascaraValor();
      }
    });

    input.addEventListener('beforeinput', function (e) {
      if (!checkValor.checked) return; // busca livre normal, sem máscara

      e.preventDefault();
      if (e.inputType === 'insertText' && e.data) {
        valorBuffer += e.data.replace(/\D/g, '');
      } else if (e.inputType === 'insertFromPaste') {
        var texto = (e.dataTransfer || (window.clipboardData || null));
        texto = texto && texto.getData ? texto.getData('text') : '';
        valorBuffer += texto.replace(/\D/g, '');
      } else if (e.inputType === 'deleteContentBackward' || e.inputType === 'deleteContentForward') {
        valorBuffer = valorBuffer.slice(0, -1);
      } else if (e.inputType === 'deleteByCut' || e.inputType === 'insertReplacementText') {
        valorBuffer = '';
      }
      renderMascaraValor();
    });
  }
})();
</script>
