<?php
// @versao Fis_01 V_04_26/08/2026
/**
 * views/fiscal/index.php
 * Fiscal — IBS/CBS: resumo do período e valores somados por fornecedor.
 *
 * A tela nasce vazia de propósito: sem período informado não há consulta.
 *
 * Variáveis esperadas:
 *   $filtro       array  [aplicado, data_ini, data_fim, filial_ids, cbs, erro]
 *   $filiais      array  [{id, nome}] filiais que o usuário pode escolher
 *   $resumo       array|null  somas do período (null enquanto não filtrou)
 *   $fornecedores array  uma linha por CNPJ, com status de conferência
 *   $qs           string querystring do filtro, para os links de detalhe
 *   $ultimaCarga  string|null  carimbo da última leitura dos XMLs
 *   $situacoes    array  cnpj => situação na Receita (cadastro ou cache)
 *   $pendentesCnpj int   quantos CNPJ do período ainda não têm situação
 */

$filtro       = $filtro       ?? ['aplicado' => false, 'data_ini' => '', 'data_fim' => '', 'filial_ids' => [], 'cbs' => '', 'erro' => ''];
$filiais      = $filiais      ?? [];
$resumo       = $resumo       ?? null;
$fornecedores = $fornecedores ?? [];
$qs           = $qs           ?? '';
$ultimaCarga  = $ultimaCarga  ?? null;
$situacoes    = $situacoes    ?? [];
$pendentesCnpj = $pendentesCnpj ?? 0;

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('fisCnpj')) {
    function fisCnpj(string $c): string {
        if (strlen($c) !== 14) return $c;
        return substr($c,0,2).'.'.substr($c,2,3).'.'.substr($c,5,3).'/'.substr($c,8,4).'-'.substr($c,12,2);
    }
    function fisValor($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
    function fisNum($v): string    { return number_format((float)$v, 0, ',', '.'); }

    /** Os três estágios do fornecedor — derivados das notas do período. */
    function fisStatus(string $s): array {
        return match ($s) {
            'total'   => ['Conferido Total',   '#D8F5E5', '#00A960'],
            'parcial' => ['Conferido Parcial', '#FFF3E0', '#FF8A2D'],
            default   => ['Não Conferido',     '#EAEAEA', '#888888'],
        };
    }
}
?>
<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">

<?php include VIEWS . '/components/alert.php'; ?>

<!-- Cabeçalho -->
<div class="page-header" style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:28px; flex-wrap:wrap; gap:12px">
  <div>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
      Fiscal <span style="color:#2347C8">IBS e CBS</span>
    </h1>
    <p style="font-size:14px; color:#888; margin:0">
      Leitura dos XML já recebidos, por data de emissão — acompanhamento de quem já está emitindo com IBS/CBS.
      Não é base de pagamento nem de apuração.
    </p>
  </div>
  <?php if (Auth::can('fiscal', 'atualizar')): ?>
  <form method="POST" action="?module=fiscal&amp;action=atualizar" style="margin:0">
    <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
    <input type="hidden" name="f" value="<?= $filtro['aplicado'] ? '1' : '' ?>">
    <input type="hidden" name="data_ini" value="<?= h($filtro['data_ini']) ?>">
    <input type="hidden" name="data_fim" value="<?= h($filtro['data_fim']) ?>">
    <input type="hidden" name="cbs" value="<?= h($filtro['cbs']) ?>">
    <?php foreach ($filtro['filial_ids'] as $fid): ?>
    <input type="hidden" name="filial[]" value="<?= h($fid) ?>">
    <?php endforeach; ?>
    <button type="submit"
            style="display:inline-flex; align-items:center; gap:8px; background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:10px 18px; font-size:14px; font-weight:700; cursor:pointer">
      <i data-lucide="refresh-cw" width="16" height="16"></i>
      Atualizar
    </button>
    <div style="font-size:11px; color:#888; margin-top:4px; text-align:center; max-width:200px">
      <?= $ultimaCarga ? 'Última leitura: ' . h(date('d/m/Y H:i', strtotime($ultimaCarga))) : 'Nunca executado' ?>
    </div>
  </form>
  <?php endif; ?>
</div>

<!-- Filtro: período obrigatório -->
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:18px 20px; margin-bottom:24px">
  <form method="GET" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap">
    <input type="hidden" name="module" value="fiscal">
    <input type="hidden" name="action" value="index">
    <input type="hidden" name="f" value="1">
    <div>
      <label for="fis-ini" style="display:block; font-size:12px; font-weight:700; color:#606060; margin-bottom:4px">Emissão — de</label>
      <input type="date" id="fis-ini" name="data_ini" required value="<?= h($filtro['data_ini']) ?>"
             style="height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; color:#141922; background:#fff; outline:none"
             onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
    </div>
    <div>
      <label for="fis-fim" style="display:block; font-size:12px; font-weight:700; color:#606060; margin-bottom:4px">Emissão — até</label>
      <input type="date" id="fis-fim" name="data_fim" required value="<?= h($filtro['data_fim']) ?>"
             style="height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; color:#141922; background:#fff; outline:none"
             onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
    </div>
    <div>
      <span style="display:block; font-size:12px; font-weight:700; color:#606060; margin-bottom:4px">Filial de destino</span>
      <?php
      // Sem nada marcado vale "todas as filiais que enxergo".
      $flSufixo   = 'fis';
      $flOpcoes   = $filiais;
      $flSel      = $filtro['filial_ids'];
      $flCampo    = 'filial';
      $flVariante = 'inline';
      $flLabel    = 'Todas as filiais';
      include VIEWS . '/components/filtro-lojas.php';
      ?>
    </div>
    <div>
      <label for="fis-cbs" style="display:block; font-size:12px; font-weight:700; color:#606060; margin-bottom:4px">Destaque de IBS/CBS</label>
      <select id="fis-cbs" name="cbs"
              title="Destacou = o XML trouxe o grupo IBS/CBS (vBCIBSCBS). Base zerada dentro do grupo pode ser correta conforme a operação."
              style="height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; color:#141922; background:#fff">
        <option value=""    <?= $filtro['cbs'] === ''    ? 'selected' : '' ?>>Todos os fornecedores</option>
        <option value="sem" <?= $filtro['cbs'] === 'sem' ? 'selected' : '' ?>>Não destacaram o grupo</option>
        <option value="com" <?= $filtro['cbs'] === 'com' ? 'selected' : '' ?>>Destacaram o grupo</option>
      </select>
    </div>
    <button type="submit"
            style="height:40px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:0 24px; font-size:14px; font-weight:700; cursor:pointer">
      Filtrar
    </button>
    <?php if ($filtro['aplicado']): ?>
    <a href="?module=fiscal&amp;action=index" title="Limpar o período e recomeçar"
       style="height:40px; display:inline-flex; align-items:center; gap:6px; background:#EAEAEA; color:#606060; border-radius:4px; padding:0 16px; font-size:14px; font-weight:700; text-decoration:none">
      <i data-lucide="x" width="15" height="15"></i> Limpar
    </a>
    <?php endif; ?>
  </form>
  <?php if ($filtro['erro'] !== ''): ?>
  <div style="margin-top:10px; font-size:13px; color:#B3261E; font-weight:700"><?= h($filtro['erro']) ?></div>
  <?php endif; ?>
</div>

<?php if (!$filtro['aplicado']): ?>

  <!-- Estado inicial: nada é consultado antes do período -->
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:56px 24px; text-align:center">
    <i data-lucide="calendar-search" width="40" height="40" style="color:#888"></i>
    <div style="font-size:20px; font-weight:700; color:#141922; margin-top:12px">Informe o período</div>
    <div style="font-size:14px; color:#888; margin-top:6px">
      Escolha a data inicial e a data final de emissão e clique em <strong>Filtrar</strong> para carregar o resumo e os fornecedores.
    </div>
  </div>

<?php else: ?>

  <!-- Resumo do período: todas as notas e todos os CNPJ -->
  <?php
    $adesao = ($resumo['fornecedores'] ?? 0) > 0
      ? round(100 * $resumo['fornecedores_destacaram'] / $resumo['fornecedores'])
      : 0;
    $cards = [
      ['Notas no período', fisNum($resumo['notas']),          'file-text',    '#606060', fisNum($resumo['conferidas']) . ' conferidas'],
      ['Fornecedores',     fisNum($resumo['fornecedores']),   'building-2',   '#606060', fisNum($resumo['fornecedores_destacaram']) . ' destacaram o grupo (' . $adesao . '%)'],
      ['Total produtos',   fisValor($resumo['valor_produtos']), 'package',    '#606060', ''],
      ['Total nota fiscal',fisValor($resumo['valor_nota']),   'receipt',      '#141922', ''],
      ['Base IBS e CBS',   fisValor($resumo['base_ibs_cbs']), 'calculator',   '#606060', ''],
      ['Valor IBS',        fisValor($resumo['valor_ibs']),    'landmark',     '#2347C8', ''],
      ['Valor CBS',        fisValor($resumo['valor_cbs']),    'landmark',     '#2347C8', ''],
    ];
  ?>
  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px">
    <?php foreach ($cards as [$rotulo, $valor, $icone, $cor, $rodape]): ?>
    <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:16px 18px; border-left:4px solid <?= $cor ?>">
      <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px">
        <i data-lucide="<?= $icone ?>" width="16" height="16" style="color:<?= $cor ?>"></i>
        <span style="font-size:11px; font-weight:700; color:#888; text-transform:uppercase"><?= h($rotulo) ?></span>
      </div>
      <div style="font-size:22px; font-weight:700; color:#141922"><?= h($valor) ?></div>
      <?php if ($rodape !== ''): ?>
      <div style="font-size:12px; color:#888; margin-top:2px"><?= h($rodape) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($fornecedores === []): ?>

  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:56px 24px; text-align:center">
    <i data-lucide="inbox" width="40" height="40" style="color:#888"></i>
    <?php if ($filtro['cbs'] !== '' && $resumo['fornecedores'] > 0): ?>
    <!-- Há notas no período; foi o recorte de adesão que esvaziou a lista -->
    <div style="font-size:20px; font-weight:700; color:#141922; margin-top:12px">
      Nenhum fornecedor <?= $filtro['cbs'] === 'sem' ? 'sem' : 'com' ?> o grupo IBS/CBS
    </div>
    <div style="font-size:14px; color:#888; margin-top:6px">
      <?php $umSo = (int) $resumo['fornecedores'] === 1; ?>
      <?= $umSo ? 'O único fornecedor do período' : 'Os ' . fisNum($resumo['fornecedores']) . ' fornecedores do período' ?>
      <?php if ($filtro['cbs'] === 'sem'): ?>
        <?= $umSo ? 'destacou' : 'destacaram' ?> o grupo em alguma nota.
      <?php else: ?>
        <?= $umSo ? 'não destacou' : 'não destacaram' ?> o grupo em nenhuma nota.
      <?php endif; ?>
      Escolha <strong>Todos os fornecedores</strong> para ver a lista completa.
    </div>
    <?php else: ?>
    <div style="font-size:20px; font-weight:700; color:#141922; margin-top:12px">Nenhuma nota no período</div>
    <div style="font-size:14px; color:#888; margin-top:6px">
      Não há XML carregado com emissão entre <?= h(date('d/m/Y', strtotime($filtro['data_ini']))) ?>
      e <?= h(date('d/m/Y', strtotime($filtro['data_fim']))) ?>.
      Se os XML acabaram de chegar, clique em <strong>Atualizar</strong> para relê-los.
    </div>
    <?php endif; ?>
  </div>

  <?php else: ?>

  <!-- Valores somados por CNPJ — clicar abre as notas do fornecedor -->
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden">
    <div style="padding:16px 20px; border-bottom:1px solid #EAEAEA; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px">
      <div style="font-size:20px; font-weight:700; color:#141922">
        Fornecedores
        <span style="color:#888; font-size:14px; font-weight:400">
          <?php if ($filtro['cbs'] === ''): ?>
            (<?= fisNum(count($fornecedores)) ?> no período)
          <?php else: ?>
            (<?= fisNum(count($fornecedores)) ?> de <?= fisNum($resumo['fornecedores']) ?> —
            <?= $filtro['cbs'] === 'sem' ? 'não destacaram' : 'destacaram' ?> o grupo IBS/CBS)
          <?php endif; ?>
        </span>
      </div>
      <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap">
        <?php if ($pendentesCnpj > 0 && Auth::can('fiscal', 'atualizar')): ?>
        <!-- A consulta é 1 CNPJ a cada 20s (limite da API pública do cnpj.ws),
             então quem percorre a fila é o navegador, com o progresso à vista. -->
        <div style="display:flex; align-items:center; gap:8px">
          <button type="button" id="fis-sit-btn"
                  style="display:inline-flex; align-items:center; gap:6px; background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:7px 14px; font-size:13px; font-weight:700; cursor:pointer">
            <i data-lucide="search" width="15" height="15"></i>
            Consultar situação (<?= fisNum($pendentesCnpj) ?>)
          </button>
          <span id="fis-sit-msg" style="font-size:12px; color:#888"></span>
        </div>
        <?php endif; ?>
        <div style="font-size:12px; color:#888">Clique na linha para ver as notas e conferir</div>
      </div>
    </div>
    <div style="overflow-x:auto">
      <table style="width:100%; border-collapse:collapse; font-size:13px">
        <thead>
          <tr style="background:#606060; color:#fff; text-align:left">
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px">CNPJ</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px">Fornecedor</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px">Situação</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px; text-align:right">Notas</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px; text-align:right">Total produtos</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px; text-align:right">Total NF</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px; text-align:right">Base IBS/CBS</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px; text-align:right">Valor IBS</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px; text-align:right">Valor CBS</th>
            <th style="padding:10px 14px; font-weight:700; text-transform:uppercase; font-size:13px">Conferência</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fornecedores as $i => $f): ?>
          <?php
            [$rotulo, $bg, $fg] = fisStatus((string) $f['status']);
            $url = '?module=fiscal&action=fornecedor&cnpj=' . urlencode((string) $f['emitente_cnpj']) . $qs;
            $zebra = $i % 2 === 0 ? '#FFFFFF' : '#F8F8F8';
          ?>
          <tr style="background:<?= $zebra ?>; border-bottom:1px solid #EAEAEA; cursor:pointer"
              onmouseover="this.style.background='#EEF2FD'" onmouseout="this.style.background='<?= $zebra ?>'"
              onclick="window.location='<?= h($url) ?>'">
            <td style="padding:10px 14px; color:#141922; white-space:nowrap"><?= h(fisCnpj((string) $f['emitente_cnpj'])) ?></td>
            <td style="padding:10px 14px; color:#141922; font-weight:700">
              <a href="<?= h($url) ?>" style="color:#141922; text-decoration:none"><?= h($f['emitente_nome']) ?></a>
              <?php if ((int) $f['notas_destacaram'] === 0): ?>
              <!-- Nenhuma nota trouxe o grupo: é este o fornecedor que ainda nao se adequou -->
              <span title="Nenhuma nota deste fornecedor trouxe o grupo IBS/CBS no periodo"
                    style="display:inline-block; background:#EAEAEA; color:#888888; border-radius:8px; padding:2px 6px; font-size:10px; font-weight:700; text-transform:uppercase; margin-left:6px">sem grupo</span>
              <?php elseif ((int) $f['notas_destacaram'] < (int) $f['notas']): ?>
              <span title="<?= h($f['notas_destacaram'] . ' de ' . $f['notas'] . ' notas trouxeram o grupo IBS/CBS') ?>"
                    style="display:inline-block; background:#FFF3E0; color:#FF8A2D; border-radius:8px; padding:2px 6px; font-size:10px; font-weight:700; text-transform:uppercase; margin-left:6px">grupo parcial</span>
              <?php endif; ?>
            </td>
            <?php $sit = FiscalCnpj::badge($situacoes[$f['emitente_cnpj']] ?? null); ?>
            <td style="padding:10px 14px; white-space:nowrap" data-sit-cnpj="<?= h($f['emitente_cnpj']) ?>">
              <span class="fis-sit-badge"
                    style="display:inline-block; background:<?= $sit['bg'] ?>; color:<?= $sit['fg'] ?>; border-radius:8px; padding:3px 8px; font-size:10px; font-weight:700; text-transform:uppercase"
                    title="<?= h($sit['titulo']) ?>"><?= h($sit['texto']) ?></span>
              <div class="fis-sit-detalhe" style="font-size:11px; color:#888; margin-top:2px"><?= h($sit['detalhe']) ?></div>
            </td>
            <td style="padding:10px 14px; text-align:right; color:#141922"><?= fisNum($f['notas']) ?></td>
            <td style="padding:10px 14px; text-align:right; color:#141922"><?= h(fisValor($f['valor_produtos'])) ?></td>
            <td style="padding:10px 14px; text-align:right; color:#141922; font-weight:700"><?= h(fisValor($f['valor_nota'])) ?></td>
            <td style="padding:10px 14px; text-align:right; color:#606060"><?= h(fisValor($f['base_ibs_cbs'])) ?></td>
            <td style="padding:10px 14px; text-align:right; color:<?= ((float) $f['valor_ibs']) > 0 ? '#141922' : '#888' ?>"><?= h(fisValor($f['valor_ibs'])) ?></td>
            <td style="padding:10px 14px; text-align:right; color:<?= ((float) $f['valor_cbs']) > 0 ? '#141922' : '#888' ?>"><?= h(fisValor($f['valor_cbs'])) ?></td>
            <td style="padding:10px 14px; white-space:nowrap">
              <span style="display:inline-block; background:<?= $bg ?>; color:<?= $fg ?>; border-radius:8px; padding:3px 8px; font-size:10px; font-weight:700; text-transform:uppercase"><?= h($rotulo) ?></span>
              <span style="font-size:11px; color:#888; margin-left:4px"><?= fisNum($f['conferidas']) ?>/<?= fisNum($f['notas']) ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php endif; ?>
<?php endif; ?>

<?php if ($filtro['aplicado'] && $pendentesCnpj > 0 && Auth::can('fiscal', 'atualizar')): ?>
<script>
/**
 * Consulta a situação na Receita dos CNPJ que ainda não têm, um a cada 20s.
 * O servidor é quem escolhe o próximo pendente e quem guarda a trava — aqui
 * só pedimos "o próximo" e reagendamos com o tempo que ele mandar.
 */
(function () {
  var btn = document.getElementById('fis-sit-btn');
  var msg = document.getElementById('fis-sit-msg');
  if (!btn) return;

  var INTERVALO = <?= (int) CnpjReceita::INTERVALO ?> * 1000;
  var filtro = <?= json_encode([
      'f'        => '1',
      'data_ini' => $filtro['data_ini'],
      'data_fim' => $filtro['data_fim'],
      'cbs'      => $filtro['cbs'],
      'filial'   => $filtro['filial_ids'],
  ], JSON_UNESCAPED_UNICODE) ?>;
  var csrf  = document.querySelector('meta[name="csrf-token"]').content;
  var timer = null, rodando = false, falhas = 0;

  function corpo() {
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    Object.keys(filtro).forEach(function (k) {
      if (Array.isArray(filtro[k])) {
        filtro[k].forEach(function (v) { fd.append(k + '[]', v); });
      } else {
        fd.append(k, filtro[k]);
      }
    });
    return fd;
  }

  function pintar(cnpj, badge) {
    var td = document.querySelector('[data-sit-cnpj="' + cnpj + '"]');
    if (!td) return;
    var b = td.querySelector('.fis-sit-badge');
    var d = td.querySelector('.fis-sit-detalhe');
    if (b) {
      b.textContent = badge.texto;
      b.style.background = badge.bg;
      b.style.color = badge.fg;
      b.title = badge.titulo || '';
    }
    if (d) d.textContent = badge.detalhe || '';
  }

  function parar(texto, concluido) {
    rodando = false;
    if (timer) { clearTimeout(timer); timer = null; }
    if (concluido) {
      // Fila vazia: oferecer "retomar" só confundiria — não há o que retomar.
      btn.disabled = true;
      btn.style.opacity = '0.5';
      btn.style.cursor = 'default';
      btn.innerHTML = '<i data-lucide="check" width="15" height="15"></i> Situação consultada';
    } else {
      btn.innerHTML = '<i data-lucide="search" width="15" height="15"></i> Retomar consulta';
    }
    if (window.lucide) window.lucide.createIcons();
    if (texto) msg.textContent = texto;
  }

  function proximo() {
    if (!rodando) return;
    fetch('?module=fiscal&action=consultar_situacao', {
      method: 'POST', body: corpo(), credentials: 'same-origin'
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (j.fim) { parar('Consulta concluída — todos os CNPJ do período têm situação.', true); return; }

      if (j.esperar) {                       // trava global: reagenda, não gasta tentativa
        msg.textContent = j.mensagem || 'Aguardando o limite da Receita...';
        timer = setTimeout(proximo, j.esperar * 1000);
        return;
      }

      if (j.sucesso) {
        falhas = 0;
        pintar(j.cnpj, j.badge);
        msg.textContent = j.restantes + ' restante(s) — cerca de '
                        + Math.ceil(j.restantes * (INTERVALO / 1000) / 60) + ' min.';
      } else {
        falhas++;
        msg.textContent = (j.mensagem || 'Falha na consulta.') + ' Seguindo para o próximo.';
        // Três falhas seguidas = problema no serviço, não no CNPJ. Parar de
        // martelar a API e deixar o usuário decidir.
        if (falhas >= 3) { parar('Consulta interrompida após 3 falhas seguidas.'); return; }
      }

      if (j.restantes === 0) { parar('Consulta concluída.', true); return; }
      timer = setTimeout(proximo, INTERVALO);
    })
    .catch(function () { parar('Erro de rede — consulta interrompida.'); });
  }

  btn.addEventListener('click', function () {
    if (rodando) { parar('Consulta pausada.'); return; }
    rodando = true;
    falhas  = 0;
    btn.innerHTML = '<i data-lucide="pause" width="15" height="15"></i> Pausar consulta';
    if (window.lucide) window.lucide.createIcons();
    msg.textContent = 'Consultando — 1 CNPJ a cada <?= (int) CnpjReceita::INTERVALO ?>s (limite da Receita).';
    proximo();
  });
})();
</script>
<?php endif; ?>
