<?php
// @versao Tab_10 V_05_26/08/2026
/**
 * views/tables/fornecedores.php
 * Cadastro de fornecedores — Listas → Fornecedores
 *
 * Variáveis (injetadas pelo controller):
 *   $fornecedores  array  página atual
 *   $total, $totalAtivos, $totalInativos, $currentPage, $totalPages
 *   $filters       array  ['status','q','page']
 *   $pendentesWs   int    CNPJ do cadastro ainda sem situação na Receita
 */

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

require_once SRC . '/Helpers/PisNit.php';

$fornecedores = $fornecedores ?? [];
$filters      = $filters ?? ['status' => '', 'situacao' => '', 'pis' => '', 'q' => '', 'page' => 1];
$total        = $total ?? 0;
$totalAtivos   = $totalAtivos   ?? 0;
$totalInativos = $totalInativos ?? 0;
$currentPage  = $currentPage ?? 1;
$totalPages   = $totalPages ?? 1;
$pendentesWs  = $pendentesWs ?? 0;

function fornFmtDoc(?string $d): string {
    $d = (string) $d;
    if (strlen($d) === 14) return substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12,2);
    if (strlen($d) === 11) return substr($d,0,3).'.'.substr($d,3,3).'.'.substr($d,6,3).'-'.substr($d,9,2);
    return h($d);
}
function fornUrl(array $f, int $page = 1): string {
    return '?' . http_build_query(array_filter([
        'module'   => 'tabelas', 'action' => 'fornecedores',
        'status'   => $f['status'] ?? '', 'situacao' => $f['situacao'] ?? '',
        'pis'      => $f['pis'] ?? '',
        'q'        => $f['q'] ?? '',
        'page'     => $page > 1 ? $page : '',
    ]));
}
?>
<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">

<!-- Page Header -->
<div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px">
  <div>
    <a href="?module=tabelas&amp;action=index" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#606060; text-decoration:none; margin-bottom:8px">
      <i data-lucide="arrow-left" width="14" height="14"></i> Voltar para Listas
    </a>
    <h1 style="font-size:32px; font-weight:700; color:#141922; margin:0 0 4px">
      Cadastro de <span style="color:#2347C8">Fornecedores</span>
    </h1>
    <p style="font-size:14px; color:#888; margin:0"><?= (int)$totalAtivos ?> ativo(s) · <?= (int)$totalInativos ?> inativo(s)</p>
  </div>
  <div style="display:flex; align-items:flex-start; gap:10px">
    <?php if ($pendentesWs > 0): ?>
    <!-- Consulta em lote na Receita: 1 CNPJ a cada 20s (limite da API pública
         do cnpj.ws). Quem percorre a fila é o navegador. -->
    <div style="text-align:right">
      <button type="button" id="forn-ws-btn"
              title="Consultar na Receita todos os CNPJ que ainda não foram consultados"
              style="display:inline-flex; align-items:center; gap:8px; background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; padding:10px 18px; font-size:14px; font-weight:700; cursor:pointer">
        <i data-lucide="globe" width="16" height="16"></i> WS (<?= (int) $pendentesWs ?>)
      </button>
      <div id="forn-ws-msg" style="font-size:11px; color:#888; margin-top:4px; max-width:260px"></div>
    </div>
    <?php endif; ?>
    <button type="button" onclick="fornAbrirModal(null)"
            style="display:inline-flex; align-items:center; gap:8px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:10px 20px; font-size:14px; font-weight:700; cursor:pointer">
      <i data-lucide="plus" width="16" height="16"></i> Incluir
    </button>
  </div>
</div>

<?php include VIEWS . '/components/alert.php'; ?>

<!-- Filtros -->
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:16px 20px; margin-bottom:20px">
  <form method="get" action="" style="display:flex; gap:12px; flex-wrap:wrap; align-items:center">
    <input type="hidden" name="module" value="tabelas">
    <input type="hidden" name="action" value="fornecedores">
    <div style="position:relative; flex:1; min-width:240px; max-width:400px">
      <i data-lucide="search" width="16" height="16" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#888"></i>
      <input type="search" name="q" value="<?= h($filters['q']) ?>" placeholder="Pesquisar por razão social, fantasia, CNPJ ou cidade..."
             style="width:100%; height:40px; padding:0 12px 0 34px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box; outline:none"
             onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
    </div>
    <select name="status" style="height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff">
      <option value="">Todos</option>
      <option value="ativo"   <?= $filters['status']==='ativo'   ? 'selected' : '' ?>>Ativos</option>
      <option value="inativo" <?= $filters['status']==='inativo' ? 'selected' : '' ?>>Inativos</option>
    </select>
    <select name="situacao" style="height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff">
      <option value="">Receita: todas</option>
      <?php foreach (['Ativa','Suspensa','Inapta','Baixada','Nula'] as $sit): ?>
      <option value="<?= h($sit) ?>" <?= $filters['situacao']===$sit ? 'selected' : '' ?>><?= h($sit) ?></option>
      <?php endforeach; ?>
      <option value="__sem__" <?= $filters['situacao']==='__sem__' ? 'selected' : '' ?>>Sem informação</option>
    </select>
    <select name="pis" style="height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; background:#fff">
      <option value="">PIS: todos</option>
      <option value="pendente" <?= ($filters['pis'] ?? '')==='pendente' ? 'selected' : '' ?>>MEI / PF sem PIS</option>
    </select>
    <?php // Botão único: sem filtro aplicado ele pesquisa; com filtro aplicado ele
          // limpa. Para trocar de recorte: Limpar → escolher → Pesquisar. ?>
    <?php if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['situacao'] !== '' || ($filters['pis'] ?? '') !== ''): ?>
    <a href="?module=tabelas&amp;action=fornecedores&amp;limpar=1" title="Remover os filtros aplicados"
       style="height:40px; display:inline-flex; align-items:center; gap:6px; background:#EAEAEA; color:#606060; border-radius:4px; padding:0 20px; font-size:14px; font-weight:700; text-decoration:none">
      <i data-lucide="x" width="15" height="15"></i> Limpar
    </a>
    <?php else: ?>
    <button type="submit" style="height:40px; background:#2347C8; color:#fff; border:none; border-radius:4px; padding:0 20px; font-size:14px; font-weight:700; cursor:pointer">Pesquisar</button>
    <?php endif; ?>
  </form>
</div>

<!-- Tabela -->
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden">
  <div style="overflow-x:auto">
  <table style="width:100%; border-collapse:collapse; min-width:900px">
    <thead>
      <tr style="background:#606060">
        <th style="padding:12px 16px; text-align:left; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase">Fornecedor</th>
        <th style="padding:12px 16px; text-align:left; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase">CNPJ / CPF</th>
        <th style="padding:12px 16px; text-align:left; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase">Cidade / UF</th>
        <th style="padding:12px 16px; text-align:left; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase">Contato</th>
        <th style="padding:12px 16px; text-align:center; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase">Receita</th>
        <th style="padding:12px 16px; text-align:center; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase">Status</th>
        <th style="padding:12px 16px; text-align:center; font-size:13px; font-weight:700; color:#fff; text-transform:uppercase">Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($fornecedores)): ?>
      <tr>
        <td colspan="7" style="padding:48px 20px; text-align:center">
          <i data-lucide="building-2" width="40" height="40" style="color:#EAEAEA"></i>
          <div style="font-size:16px; font-weight:700; color:#141922; margin-top:10px">Nenhum fornecedor encontrado</div>
          <p style="font-size:13px; color:#888; margin:6px 0 0">Ajuste a pesquisa ou clique em Incluir para cadastrar.</p>
        </td>
      </tr>
      <?php else: ?>
      <?php foreach ($fornecedores as $i => $f):
        $bgLinha = $i % 2 === 0 ? '#FFFFFF' : '#F8F8F8';
        $json = json_encode([
            'id' => $f['id'], 'razao_social' => $f['razao_social'], 'nome_fantasia' => $f['nome_fantasia'],
            'cnpj' => $f['cnpj'], 'email' => $f['email'], 'telefone' => $f['telefone'],
            'endereco' => $f['endereco'], 'cidade' => $f['cidade'], 'estado' => $f['estado'],
            'data_abertura' => $f['data_abertura'], 'situacao_cadastral' => $f['situacao_cadastral'],
            'optante_mei' => $f['optante_mei'], 'optante_simples' => $f['optante_simples'],
            'pis_nit' => $f['pis_nit'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
        $pisMotivo    = PisNit::motivoExigencia($f);
        $pisPendente  = $pisMotivo !== null && !PisNit::valido($f['pis_nit'] ?? null);
      ?>
      <tr style="background:<?= $bgLinha ?>"
          onmouseover="this.style.background='#EEF2FD'" onmouseout="this.style.background='<?= $bgLinha ?>'">
        <td style="padding:12px 16px">
          <div style="font-weight:700; font-size:14px; color:#141922"><?= h($f['razao_social']) ?></div>
          <?php if (!empty($f['nome_fantasia'])): ?>
          <div style="font-size:12px; color:#888"><?= h($f['nome_fantasia']) ?></div>
          <?php endif; ?>
        </td>
        <td style="padding:12px 16px; font-size:13px; color:#141922; white-space:nowrap">
          <?= fornFmtDoc($f['cnpj']) ?>
          <?php if (!empty($f['pis_nit'])): ?>
          <div style="font-size:11px; color:#888">PIS <?= h(PisNit::formatar($f['pis_nit'])) ?></div>
          <?php elseif ($pisPendente): ?>
          <div style="margin-top:3px">
            <span title="Nota de serviço deste fornecedor fica bloqueada até preencher o PIS/NIT"
                  style="background:#FFE8EE; color:#B3261E; font-size:10px; font-weight:700; text-transform:uppercase; padding:3px 8px; border-radius:8px">PIS pendente</span>
          </div>
          <?php endif; ?>
        </td>
        <td style="padding:12px 16px; font-size:13px; color:#141922">
          <?= h($f['cidade'] ?? '—') ?><?= !empty($f['estado']) ? ' / ' . h($f['estado']) : '' ?>
        </td>
        <td style="padding:12px 16px; font-size:12px; color:#606060">
          <?php if (!empty($f['email'])): ?><div><?= h($f['email']) ?></div><?php endif; ?>
          <?php if (!empty($f['telefone'])): ?><div><?= h($f['telefone']) ?></div><?php endif; ?>
          <?php if (empty($f['email']) && empty($f['telefone'])): ?>—<?php endif; ?>
        </td>
        <td style="padding:12px 16px; text-align:center" data-ws-cnpj="<?= h($f['cnpj']) ?>">
          <?php if (!empty($f['situacao_cadastral'])): ?>
            <?php $ok = $f['situacao_cadastral'] === 'Ativa'; ?>
            <span style="background:<?= $ok ? '#D8F5E5' : '#FFE8EE' ?>; color:<?= $ok ? '#00A960' : '#B3261E' ?>; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px"><?= h($f['situacao_cadastral']) ?></span>
            <?php if ((int)($f['optante_simples'] ?? 0) === 1): ?>
            <div style="font-size:10px; color:#888; margin-top:3px">Simples<?= (int)($f['optante_mei'] ?? 0) === 1 ? ' · MEI' : '' ?></div>
            <?php endif; ?>
          <?php else: ?>
          <span style="color:#888; font-size:12px">—</span>
          <?php endif; ?>
        </td>
        <td style="padding:12px 16px; text-align:center">
          <?php if ((int)$f['ativo'] === 1): ?>
          <span style="background:#D8F5E5; color:#00A960; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px">Ativo</span>
          <?php else: ?>
          <span style="background:#EAEAEA; color:#888; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px">Inativo</span>
          <?php endif; ?>
        </td>
        <td style="padding:12px 16px; text-align:center; white-space:nowrap">
          <button type="button" onclick='fornAbrirModal(<?= h($json) ?>)'
                  style="display:inline-flex; align-items:center; gap:4px; background:transparent; border:1px solid #2347C8; color:#2347C8; border-radius:4px; padding:6px 12px; font-size:12px; font-weight:700; cursor:pointer; margin-right:6px">
            <i data-lucide="pencil" width="13" height="13"></i> Editar
          </button>
          <form method="POST" action="?module=tabelas&amp;action=fornecedor_toggle" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="id" value="<?= h($f['id']) ?>">
            <button type="submit"
                    style="background:#EAEAEA; color:#606060; border:none; border-radius:4px; padding:7px 12px; font-size:12px; font-weight:700; cursor:pointer; margin-right:6px">
              <?= (int)$f['ativo'] === 1 ? 'Desativar' : 'Ativar' ?>
            </button>
          </form>
          <form method="POST" action="?module=tabelas&amp;action=fornecedor_excluir" style="display:inline"
                onsubmit="return confirm('Excluir o fornecedor \'<?= h(addslashes($f['razao_social'])) ?>\'?\n\nSó é possível excluir fornecedor sem processos, contratos ou ordens vinculados.')">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="id" value="<?= h($f['id']) ?>">
            <button type="submit"
                    style="background:#B3261E; color:#fff; border:none; border-radius:4px; padding:7px 12px; font-size:12px; font-weight:700; cursor:pointer">
              Excluir
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 20px; border-top:1px solid #EAEAEA">
    <span style="font-size:13px; color:#888"><?= (int)$total ?> fornecedor(es) · página <?= (int)$currentPage ?> de <?= (int)$totalPages ?></span>
    <div style="display:flex; gap:6px">
      <?php if ($currentPage > 1): ?>
      <a href="<?= fornUrl($filters, $currentPage - 1) ?>" style="padding:6px 14px; border:1px solid #DFDFDF; border-radius:4px; font-size:13px; color:#606060; text-decoration:none">‹ Anterior</a>
      <?php endif; ?>
      <?php if ($currentPage < $totalPages): ?>
      <a href="<?= fornUrl($filters, $currentPage + 1) ?>" style="padding:6px 14px; border:1px solid #DFDFDF; border-radius:4px; font-size:13px; color:#606060; text-decoration:none">Próxima ›</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Modal incluir/editar fornecedor -->
<div id="forn-modal" style="display:none; position:fixed; inset:0; background:rgba(24,19,31,0.55); z-index:1000; align-items:center; justify-content:center; padding:20px"
     onclick="if (event.target === this) fornFecharModal()">
  <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.25) 0 8px 32px; width:100%; max-width:560px; max-height:90vh; overflow-y:auto">
    <div style="display:flex; align-items:center; justify-content:space-between; padding:20px 24px 0">
      <h3 id="forn-modal-titulo" style="font-size:18px; font-weight:700; color:#141922; margin:0; display:flex; align-items:center; gap:8px">
        <i data-lucide="building-2" width="20" height="20" style="color:#2347C8"></i> Incluir Fornecedor
      </h3>
      <button type="button" onclick="fornFecharModal()" style="background:none; border:none; cursor:pointer; color:#888; font-size:20px; padding:4px">×</button>
    </div>

    <form method="POST" action="?module=tabelas&amp;action=fornecedor_salvar" style="padding:20px 24px">
      <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
      <input type="hidden" name="id" id="forn-id" value="">
      <input type="hidden" name="data_abertura" id="forn-data-abertura" value="">
      <input type="hidden" name="situacao_cadastral" id="forn-situacao" value="">
      <input type="hidden" name="optante_mei" id="forn-mei" value="">
      <input type="hidden" name="optante_simples" id="forn-simples" value="">

      <div style="margin-bottom:14px">
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">CNPJ / CPF <span style="color:#B3261E">*</span></label>
        <div style="display:flex; gap:8px">
          <input type="text" name="cnpj" id="forn-cnpj" required placeholder="11.222.333/0001-81"
                 style="flex:1; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box"
                 onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
          <button type="button" id="forn-btn-buscar" onclick="fornBuscarCnpj()"
                  title="Buscar dados na Receita (apenas CNPJ)"
                  style="display:inline-flex; align-items:center; gap:6px; padding:0 16px; height:40px; background:transparent; color:#2347C8; border:1px solid #2347C8; border-radius:4px; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap">Buscar</button>
        </div>
        <div style="font-size:11px; color:#888; margin-top:4px">Preencha o CNPJ e clique em Buscar para trazer os dados da Receita.</div>
      </div>

      <div style="margin-bottom:14px">
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Razão Social <span style="color:#B3261E">*</span></label>
        <input type="text" name="razao_social" id="forn-razao" required maxlength="200"
               style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box">
      </div>
      <div style="margin-bottom:14px">
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Nome Fantasia</label>
        <input type="text" name="nome_fantasia" id="forn-fantasia" maxlength="200"
               style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box">
      </div>
      <div style="margin-bottom:14px">
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Endereço</label>
        <input type="text" name="endereco" id="forn-endereco" maxlength="200" placeholder="Logradouro, número, bairro, CEP"
               style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box">
      </div>
      <div style="display:flex; gap:12px; margin-bottom:14px">
        <div style="flex:1">
          <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Cidade</label>
          <input type="text" name="cidade" id="forn-cidade" maxlength="100"
                 style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box">
        </div>
        <div style="width:90px">
          <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">UF</label>
          <input type="text" name="estado" id="forn-uf" maxlength="2"
                 style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box; text-transform:uppercase">
        </div>
      </div>
      <div style="display:flex; gap:12px; margin-bottom:14px">
        <div style="flex:1">
          <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">E-mail</label>
          <input type="text" name="email" id="forn-email" maxlength="150"
                 style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box">
        </div>
        <div style="flex:1">
          <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Telefone</label>
          <input type="text" name="telefone" id="forn-telefone" maxlength="20"
                 style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box">
        </div>
      </div>

      <!-- PIS/NIT — exigido de MEI e pessoa física em nota de serviço -->
      <div style="margin-bottom:14px">
        <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">
          PIS / PASEP / NIT <span id="forn-pis-req" style="color:#B3261E; display:none">*</span>
        </label>
        <input type="text" name="pis_nit" id="forn-pis" maxlength="14" placeholder="000.00000.00-0" inputmode="numeric"
               style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box"
               onfocus="this.style.borderColor='#2347C8'" onblur="this.style.borderColor='#DFDFDF'">
        <div id="forn-pis-hint" style="font-size:11px; color:#888; margin-top:4px">
          Obrigatório para MEI e pessoa física — sem ele a nota de serviço não pode ser aberta.
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px">
        <div>
          <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Data de cadastro</label>
          <input type="text" id="forn-info-data" readonly value="—"
                 style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box; background:#F6F6F6; color:#606060">
        </div>
        <div>
          <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Situação atual</label>
          <input type="text" id="forn-info-situacao" readonly value="—"
                 style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box; background:#F6F6F6; color:#606060">
        </div>
        <div>
          <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Optante MEI</label>
          <input type="text" id="forn-info-mei" readonly value="—"
                 style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box; background:#F6F6F6; color:#606060">
        </div>
        <div>
          <label style="display:block; font-size:13px; font-weight:700; color:#606060; margin-bottom:6px">Simples Nacional</label>
          <input type="text" id="forn-info-simples" readonly value="—"
                 style="width:100%; height:40px; padding:0 12px; border:1px solid #DFDFDF; border-radius:4px; font-size:14px; box-sizing:border-box; background:#F6F6F6; color:#606060">
        </div>
      </div>

      <div id="forn-modal-msg" style="display:none; padding:8px 12px; border-radius:4px; font-size:13px; margin-bottom:12px"></div>

      <div style="display:flex; gap:8px; justify-content:flex-end">
        <button type="button" onclick="fornFecharModal()"
                style="padding:10px 20px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">Cancelar</button>
        <button type="submit" id="forn-btn-salvar"
                style="padding:10px 24px; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">Salvar</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var cooldownTimer = null;

  function el(id) { return document.getElementById(id); }

  function fmtDoc(v) {
    var d = String(v || '').replace(/\D/g, '');
    if (d.length === 14) return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
    if (d.length === 11) return d.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
    return v || '';
  }
  function fmtDataBr(iso) {
    if (!iso || !/^\d{4}-\d{2}-\d{2}/.test(iso)) return '—';
    var p = iso.slice(0, 10).split('-');
    return p[2] + '/' + p[1] + '/' + p[0];
  }
  function flagTexto(v) {
    if (v === 1 || v === '1') return 'Sim';
    if (v === 0 || v === '0') return 'Não';
    return '—';
  }
  function fmtPis(v) {
    var d = String(v || '').replace(/\D/g, '').slice(0, 11);
    if (d.length !== 11) return d;
    return d.replace(/^(\d{3})(\d{5})(\d{2})(\d)$/, '$1.$2.$3-$4');
  }

  /**
   * MEI (Receita) ou pessoa física → PIS obrigatório para abrir nota de serviço.
   * O bloqueio de verdade é na abertura do processo; aqui é só o aviso visual.
   */
  function atualizarExigenciaPis() {
    var doc  = el('forn-cnpj').value.replace(/\D/g, '');
    var mei  = el('forn-mei').value;
    var exige = doc.length === 11 || mei === '1';
    var pis   = el('forn-pis').value.replace(/\D/g, '');

    el('forn-pis-req').style.display = exige ? 'inline' : 'none';
    var hint = el('forn-pis-hint');
    if (exige && pis.length !== 11) {
      hint.textContent = doc.length === 11
        ? 'Pessoa física: sem o NIT a nota de serviço deste prestador fica bloqueada.'
        : 'Fornecedor MEI: sem o PIS/NIT a nota de serviço deste prestador fica bloqueada.';
      hint.style.color = '#B3261E';
    } else {
      hint.textContent = 'Obrigatório para MEI e pessoa física — sem ele a nota de serviço não pode ser aberta.';
      hint.style.color = '#888';
    }
  }
  window.fornAtualizarExigenciaPis = atualizarExigenciaPis;

  window.fornAbrirModal = function (dados) {
    var m = el('forn-modal');
    el('forn-modal-msg').style.display = 'none';
    el('forn-modal-titulo').lastChild.textContent = ' ' + (dados ? 'Editar Fornecedor' : 'Incluir Fornecedor');

    el('forn-id').value        = dados ? dados.id : '';
    el('forn-cnpj').value      = dados ? fmtDoc(dados.cnpj) : '';
    el('forn-razao').value     = dados ? (dados.razao_social || '') : '';
    el('forn-fantasia').value  = dados ? (dados.nome_fantasia || '') : '';
    el('forn-endereco').value  = dados ? (dados.endereco || '') : '';
    el('forn-cidade').value    = dados ? (dados.cidade || '') : '';
    el('forn-uf').value        = dados ? (dados.estado || '') : '';
    el('forn-email').value     = dados ? (dados.email || '') : '';
    el('forn-telefone').value  = dados ? (dados.telefone || '') : '';

    el('forn-data-abertura').value = dados && dados.data_abertura ? String(dados.data_abertura).slice(0, 10) : '';
    el('forn-situacao').value      = dados ? (dados.situacao_cadastral || '') : '';
    el('forn-mei').value           = dados && dados.optante_mei !== null && dados.optante_mei !== undefined ? String(dados.optante_mei) : '';
    el('forn-simples').value       = dados && dados.optante_simples !== null && dados.optante_simples !== undefined ? String(dados.optante_simples) : '';

    el('forn-info-data').value     = dados ? fmtDataBr(dados.data_abertura) : '—';
    el('forn-info-situacao').value = dados && dados.situacao_cadastral ? dados.situacao_cadastral : '—';
    el('forn-info-mei').value      = dados ? flagTexto(dados.optante_mei) : '—';
    el('forn-info-simples').value  = dados ? flagTexto(dados.optante_simples) : '—';

    el('forn-pis').value = dados ? fmtPis(dados.pis_nit) : '';
    atualizarExigenciaPis();

    m.style.display = 'flex';
    if (window.lucide) window.lucide.createIcons();
    (dados ? el('forn-razao') : el('forn-cnpj')).focus();
  };

  window.fornFecharModal = function () {
    el('forn-modal').style.display = 'none';
  };

  function msg(texto, tipo) {
    var d = el('forn-modal-msg');
    d.textContent = texto;
    d.style.display = 'block';
    d.style.background = tipo === 'danger' ? '#FFE8EE' : '#D8F5E5';
    d.style.color = tipo === 'danger' ? '#B3261E' : '#00A960';
  }

  function iniciarCooldown(segundos) {
    var btn = el('forn-btn-buscar');
    var resta = Math.max(1, parseInt(segundos, 10) || 20);
    btn.disabled = true;
    btn.style.opacity = '0.5';
    btn.textContent = 'Aguarde ' + resta + 's';
    if (cooldownTimer) clearInterval(cooldownTimer);
    cooldownTimer = setInterval(function () {
      resta--;
      if (resta <= 0) {
        clearInterval(cooldownTimer);
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.textContent = 'Buscar';
      } else {
        btn.textContent = 'Aguarde ' + resta + 's';
      }
    }, 1000);
  }

  window.fornBuscarCnpj = function () {
    var c = el('forn-cnpj').value.replace(/\D/g, '');
    if (c.length !== 14) {
      msg('Informe um CNPJ com 14 dígitos para buscar (CPF não tem consulta).', 'danger');
      el('forn-cnpj').focus();
      return;
    }
    var btn = el('forn-btn-buscar');
    btn.disabled = true;
    btn.style.opacity = '0.5';
    btn.textContent = 'Buscando...';

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var fd = new FormData();
    fd.append('csrf_token', csrfMeta ? csrfMeta.content : '');
    fd.append('cnpj', c);

    fetch('?module=processos&action=consultar_cnpj', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (resp) {
        if (!resp.success) {
          msg(resp.message || 'Consulta indisponível.', 'danger');
          iniciarCooldown(resp.retry || 20);
          return;
        }
        var d = resp.dados;
        if (d.razao_social)  el('forn-razao').value    = d.razao_social;
        if (d.nome_fantasia) el('forn-fantasia').value = d.nome_fantasia;
        if (d.endereco)      el('forn-endereco').value = d.endereco;
        if (d.cidade)        el('forn-cidade').value   = d.cidade;
        if (d.uf)            el('forn-uf').value       = d.uf;
        if (d.email)         el('forn-email').value    = d.email;
        if (d.telefone)      el('forn-telefone').value = d.telefone;
        el('forn-cnpj').value = fmtDoc(c);

        el('forn-data-abertura').value = d.data_abertura || '';
        el('forn-situacao').value      = d.situacao_cadastral || '';
        el('forn-mei').value           = d.optante_mei === null ? '' : String(d.optante_mei);
        el('forn-simples').value       = d.optante_simples === null ? '' : String(d.optante_simples);

        el('forn-info-data').value     = d.data_abertura_br || '—';
        el('forn-info-situacao').value = d.situacao_cadastral || '—';
        el('forn-info-mei').value      = flagTexto(d.optante_mei);
        el('forn-info-simples').value  = flagTexto(d.optante_simples);

        atualizarExigenciaPis();
        msg('Dados preenchidos a partir da Receita — confira e ajuste se precisar.', 'success');
        iniciarCooldown(20);
      })
      .catch(function () {
        msg('Erro de conexão na consulta. Tente novamente.', 'danger');
        iniciarCooldown(20);
      });
  };

  // Digitar CPF (11 dígitos) já acende a exigência antes mesmo de salvar.
  el('forn-cnpj').addEventListener('input', atualizarExigenciaPis);
  el('forn-pis').addEventListener('input', atualizarExigenciaPis);
  el('forn-pis').addEventListener('blur', function () {
    this.value = fmtPis(this.value);
    atualizarExigenciaPis();
  });
})();
</script>

<?php if ($pendentesWs > 0): ?>
<script>
/**
 * Botão WS — consulta na Receita todos os CNPJ do cadastro que ainda não têm
 * situação, um a cada 20s. O servidor escolhe o próximo e guarda a trava
 * (compartilhada com as telas de Processos e Fiscal); aqui só pedimos "o
 * próximo" e reagendamos com o tempo que ele devolver.
 *
 * A fila é do CADASTRO INTEIRO, não só da página aberta: as linhas de outras
 * páginas são consultadas do mesmo jeito, só não têm célula para pintar.
 */
(function () {
  var btn = document.getElementById('forn-ws-btn');
  var msg = document.getElementById('forn-ws-msg');
  if (!btn) return;

  var INTERVALO = 20000;
  var csrf  = document.querySelector('meta[name="csrf-token"]');
  csrf = csrf ? csrf.content : '';
  var timer = null, rodando = false, falhas = 0, feitos = 0;
  // Texto do aviso vem do PHP via json_encode: escapa a quebra de linha
  // sozinho, sem barra invertida solta no meio do JavaScript.
  var AVISO = <?= json_encode(
        'Consultar na Receita os ' . (int) $pendentesWs . ' CNPJ sem situação?' . "\n\n" .
        'São 20 segundos por consulta (limite do serviço), cerca de '
        . (int) ceil($pendentesWs * 20 / 60) . ' minutos no total.' . "\n" .
        'A página precisa ficar aberta. Dá para pausar a qualquer momento.',
        JSON_UNESCAPED_UNICODE) ?>;

  function pintar(cnpj, situacao, simples, mei) {
    var td = document.querySelector('[data-ws-cnpj="' + cnpj + '"]');
    if (!td) return;                       // fornecedor de outra página
    var ok = situacao === 'Ativa';
    var extra = '';
    if (simples === 1) {
      extra = '<div style="font-size:10px; color:#888; margin-top:3px">Simples'
            + (mei === 1 ? ' &middot; MEI' : '') + '</div>';
    }
    td.innerHTML =
      '<span style="background:' + (ok ? '#D8F5E5' : '#FFE8EE') + '; color:' + (ok ? '#00A960' : '#B3261E')
      + '; font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:8px">'
      + (situacao || '-') + '</span>' + extra;
  }

  function parar(texto, concluido) {
    rodando = false;
    if (timer) { clearTimeout(timer); timer = null; }
    if (concluido) {
      btn.disabled = true;
      btn.style.opacity = '0.5';
      btn.style.cursor = 'default';
      btn.innerHTML = '<i data-lucide="check" width="16" height="16"></i> WS concluído';
    } else {
      btn.innerHTML = '<i data-lucide="globe" width="16" height="16"></i> Retomar WS';
    }
    if (window.lucide) window.lucide.createIcons();
    if (texto) msg.textContent = texto;
  }

  function proximo() {
    if (!rodando) return;
    var fd = new FormData();
    fd.append('csrf_token', csrf);

    fetch('?module=tabelas&action=fornecedor_ws', {
      method: 'POST', body: fd, credentials: 'same-origin'
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (j.fim) { parar('Concluído — todos os CNPJ do cadastro têm situação.', true); return; }

      if (j.esperar) {                     // trava global: reagenda sem gastar tentativa
        msg.textContent = j.mensagem || 'Aguardando o limite da Receita...';
        timer = setTimeout(proximo, j.esperar * 1000);
        return;
      }

      if (j.sucesso) {
        falhas = 0;
        feitos++;
        pintar(j.cnpj, j.situacao, j.simples, j.mei);
        msg.textContent = feitos + ' consultado(s) · ' + j.restantes + ' restante(s) — cerca de '
                        + Math.ceil(j.restantes * 20 / 60) + ' min.';
      } else {
        falhas++;
        msg.textContent = (j.mensagem || 'Falha na consulta.') + ' Seguindo para o próximo.';
        // Três seguidas = problema no serviço, não no CNPJ. Parar de martelar.
        if (falhas >= 3) { parar('Interrompido após 3 falhas seguidas.'); return; }
      }

      if (j.restantes === 0) { parar('Concluído — ' + feitos + ' consultado(s).', true); return; }
      timer = setTimeout(proximo, INTERVALO);
    })
    .catch(function () { parar('Erro de rede — consulta interrompida.'); });
  }

  btn.addEventListener('click', function () {
    if (rodando) { parar('Pausado — ' + feitos + ' consultado(s).'); return; }
    if (feitos === 0 && !confirm(AVISO)) {
      return;
    }
    rodando = true;
    falhas  = 0;
    btn.innerHTML = '<i data-lucide="pause" width="16" height="16"></i> Pausar WS';
    if (window.lucide) window.lucide.createIcons();
    msg.textContent = 'Consultando — 1 CNPJ a cada 20s.';
    proximo();
  });
})();
</script>
<?php endif; ?>
