<?php
// @versao Fat_02 V_22_30/09/2026
declare(strict_types=1);

/**
 * views/faturamento/pedido.php
 * Protótipo visual da tela de Pedido do módulo Faturamento (venda de veículos).
 *
 * Estágio: EM CONSTRUÇÃO — layout e interação em revisão com a equipe.
 * Dados de exemplo fixos no HTML/JS; nenhuma consulta a banco, Apollo/Linx
 * ou Assinafy acontece aqui ainda. Estilos e classes são prefixados com
 * "fat-" para não colidir com design-system.css/layout.css compartilhados.
 *
 * Envolvida por views/layout/base.php — sidebar e topbar são os reais do
 * sistema (Auth::user() define o usuário logado na topbar).
 *
 * Grupos A–D (filial, vendedor, cliente, veículo, tipo de venda e valores) já
 * são gravados por Faturamento::salvar (ação `salvar`); E, F e G seguem protótipo.
 */
$pv = is_array($pedidoAtual ?? null) ? $pedidoAtual : [];
$fv = static fn(string $k): string => h((string) ($pv[$k] ?? ''));
$moeda = static fn($n): string => ($n === null || $n === '') ? '' : number_format((float) $n, 2, ',', '.');
/** <option>s de uma lista; garante que o valor já gravado apareça mesmo fora do catálogo. */
$opcoes = static function (array $lista, string $atual, string $vazio = ''): string {
    if ($atual !== '' && !in_array($atual, $lista, true)) {
        $lista[] = $atual;
    }
    $html = $vazio !== '' ? '<option value="">' . h($vazio) . '</option>' : '';
    foreach ($lista as $o) {
        $html .= '<option value="' . h($o) . '"' . ($o === $atual ? ' selected' : '') . '>' . h($o) . '</option>';
    }
    return $html;
};
$cnpjFmt = static fn(string $c): string => strlen($c) === 14
    ? substr($c, 0, 2) . '.' . substr($c, 2, 3) . '.' . substr($c, 5, 3) . '/' . substr($c, 8, 4) . '-' . substr($c, 12, 2)
    : $c;
$pgv = static function (array $d, string $path): string {
    $x = $d;
    foreach (explode('.', $path) as $k) {
        $x = is_array($x) ? ($x[$k] ?? '') : '';
    }
    return is_array($x) ? '' : h((string) $x);
};
$icoBusca = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>';
$selPessoa = static fn(string $atual): string =>
    '<option value="pf"' . ($atual !== 'pj' ? ' selected' : '') . '>Pessoa Física (CPF)</option>'
    . '<option value="pj"' . ($atual === 'pj' ? ' selected' : '') . '>Pessoa Jurídica (CNPJ)</option>';
$selRelacao = static function (string $atual): string {
    $h = '';
    foreach (['Sócio', 'Familiar', 'Outro'] as $o) {
        $h .= '<option' . ($o === $atual ? ' selected' : '') . '>' . h($o) . '</option>';
    }
    return $h;
};
/** Par de linhas do grupo E: a principal (tipo/valor/data) e o detalhe E6 (pagador ou veículo de troca). */
$comprovantes = [];
foreach (($anexos ?? []) as $an) {
    if (!empty($an['pagamento_id'])) {
        $comprovantes[$an['pagamento_id']][] = $an;
    }
}
$linhaPagamento = static function (string $n, array $pg) use ($pgv, $moeda, $icoBusca, $selPessoa, $selRelacao, $comprovantes): string {
    $dados   = is_array($pg['dados'] ?? null) ? $pg['dados'] : [];
    $tipo    = (string) ($pg['tipo'] ?? 'Financiamento');
    $troca   = $tipo === 'Troca';
    $pjPag   = $pgv($dados, 'pagador.tipo_pessoa') === 'pj';
    $donoOn  = $pgv($dados, 'veiculo.em_nome_terceiro') === '1';
    $pjDono  = $pgv($dados, 'veiculo.dono.tipo_pessoa') === 'pj';
    ob_start(); ?>
            <tr class="fat-linha-principal" data-id="<?= h((string) ($pg['id'] ?? '')) ?>">
              <td class="fat-pg-num"><?= h($n) ?></td>
              <td><select class="js-pg-tipo" onchange="onTipoChange(this)">
                <?php foreach (Faturamento::TIPOS_PAGAMENTO as $t): ?><option <?= $t === $tipo ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?>
              </select></td>
              <td><input class="js-pg-valor" inputmode="decimal" placeholder="0,00" value="<?= h($moeda($pg['valor'] ?? null)) ?>"></td>
              <td><input type="date" class="js-pg-data" value="<?= h((string) ($pg['data'] ?? '')) ?>"></td>
              <td class="fat-pg-comprov">
                <?php if (!empty($pg['id'])): ?>
                  <?php foreach (($comprovantes[$pg['id']] ?? []) as $an): ?>
                    <div class="fat-comprov-item"><a href="?module=faturamento&action=anexo_baixar&id=<?= h($an['id']) ?>" target="_blank" rel="noopener"><?= h($an['nome_original']) ?></a>
                      <button type="button" class="fat-link-remover" onclick="removerAnexo('<?= h($an['id']) ?>')" title="Remover comprovante">×</button></div>
                  <?php endforeach; ?>
                  <button type="button" class="fat-btn-anexar" onclick="escolherArquivo('comprovante_pagamento', '<?= h($pg['id']) ?>')">Anexar</button>
                <?php else: ?>
                  <span class="fat-field-hint">Salve para anexar</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="fat-acoes-linha">
                  <div class="fat-coord-wrap"><span class="fat-coord">E6</span><button type="button" class="fat-btn-terceiro" onclick="toggleTerceiro(this)" title="E6 · Detalhes desta forma de pagamento"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg></button></div>
                  <div class="fat-coord-wrap"><span class="fat-coord">E5</span><button type="button" class="fat-btn-remover-linha" onclick="removerLinha(this)" title="E5 · Remover linha"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6"/></svg></button></div>
                </div>
              </td>
            </tr>
            <tr class="fat-linha-terceiro" style="display:none;">
              <td colspan="6"><div class="fat-linha-terceiro__box">
                <div class="fat-sub-pagador" <?= $troca ? 'style="display:none;"' : '' ?>>
                  <div class="fat-linha-terceiro__title">Dados de quem paga (terceiro assina o Termo de Cessão de Crédito)</div>
                  <div class="fat-row fat-cols-2">
                    <div class="fat-field">
                      <label><span class="fat-coord">E6.1</span>Tipo de pessoa</label>
                      <select data-k="pagador.tipo_pessoa" onchange="onTipoPessoaTerceiro(this)"><?= $selPessoa($pgv($dados, 'pagador.tipo_pessoa')) ?></select>
                    </div>
                    <div class="fat-field">
                      <label><span class="fat-coord">E6.2</span>Nome / Razão social</label>
                      <div class="fat-busca-group"><input type="text" data-k="pagador.nome" placeholder="Nome ou razão social" value="<?= $pgv($dados, 'pagador.nome') ?>"><button type="button" class="fat-btn-busca" title="Pesquisa no Linx (em breve)"><?= $icoBusca ?></button></div>
                    </div>
                  </div>
                  <div class="fat-row fat-cols-2" style="margin-top:16px;">
                    <div class="fat-field"><label><span class="fat-coord">E6.3</span>CPF ou CNPJ</label><input data-k="pagador.doc" value="<?= $pgv($dados, 'pagador.doc') ?>"></div>
                    <div class="fat-field"><label><span class="fat-coord">E6.4</span>Relação com o cliente</label><select data-k="pagador.relacao"><?= $selRelacao($pgv($dados, 'pagador.relacao')) ?></select></div>
                  </div>
                  <div class="fat-bloco-representante-pagador fat-row fat-cols-2" style="margin-top:16px; padding-top:16px; border-top:1px dashed var(--color-gray-light); <?= $pjPag ? '' : 'display:none;' ?>">
                    <div class="fat-field">
                      <label><span class="fat-coord">E6.5</span>Quem assina pelo CNPJ <span class="fat-req">*</span></label>
                      <input type="text" data-k="pagador.rep_nome" placeholder="Nome do representante legal" value="<?= $pgv($dados, 'pagador.rep_nome') ?>">
                      <span class="fat-field-hint">Representante legal — quem assina o termo, não a empresa</span>
                    </div>
                    <div class="fat-field"><label><span class="fat-coord">E6.6</span>CPF de quem assina</label><input data-k="pagador.rep_cpf" value="<?= $pgv($dados, 'pagador.rep_cpf') ?>"></div>
                  </div>
                  <div class="fat-row fat-cols-2" style="margin-top:16px;">
                    <div class="fat-field"><label><span class="fat-coord">E6.7</span>Telefone <span class="fat-req">*</span></label><input data-k="pagador.telefone" placeholder="(51) 9XXXX-XXXX" value="<?= $pgv($dados, 'pagador.telefone') ?>"></div>
                    <div class="fat-field"><label><span class="fat-coord">E6.8</span>E-mail <span class="fat-req">*</span></label><input type="email" data-k="pagador.email" placeholder="nome@empresa.com.br" value="<?= $pgv($dados, 'pagador.email') ?>"></div>
                  </div>
                  <span class="fat-field-hint" style="margin-top:8px;">Telefone e e-mail são usados para enviar o Termo de Cessão de Crédito para assinatura (Assinafy)</span>
                </div>

                <div class="fat-sub-veiculo" <?= $troca ? '' : 'style="display:none;"' ?>>
                  <div class="fat-linha-terceiro__title">Dados do veículo entregue na troca</div>
                  <div class="fat-row fat-cols-3">
                    <div class="fat-field">
                      <label><span class="fat-coord">E6.9</span>Placa</label>
                      <div class="fat-busca-group"><input type="text" data-k="veiculo.placa" value="<?= $pgv($dados, 'veiculo.placa') ?>"><button type="button" class="fat-btn-busca" title="Pesquisa no Detran/Apollo (em breve)"><?= $icoBusca ?></button></div>
                    </div>
                    <div class="fat-field"><label><span class="fat-coord">E6.10</span>Modelo</label><input data-k="veiculo.modelo" value="<?= $pgv($dados, 'veiculo.modelo') ?>"></div>
                    <div class="fat-field"><label><span class="fat-coord">E6.11</span>Avaliação</label><input class="js-pg-valor" inputmode="decimal" data-k="veiculo.avaliacao" placeholder="0,00" value="<?= h($moeda($dados['veiculo']['avaliacao'] ?? null)) ?>"></div>
                  </div>
                  <label class="fat-checkbox-inline" style="margin-top:16px;">
                    <input type="checkbox" data-k="veiculo.em_nome_terceiro" <?= $donoOn ? 'checked' : '' ?> onchange="onDonoVeiculoChange(this)">
                    <span class="fat-coord">E6.12</span>Veículo em nome de terceiro?
                  </label>
                  <div class="fat-bloco-dono-veiculo" style="margin-top:12px; <?= $donoOn ? '' : 'display:none;' ?>">
                    <div class="fat-row fat-cols-2">
                      <div class="fat-field">
                        <label><span class="fat-coord">E6.13</span>Tipo de pessoa</label>
                        <select data-k="veiculo.dono.tipo_pessoa" onchange="onTipoPessoaDonoVeiculo(this)"><?= $selPessoa($pgv($dados, 'veiculo.dono.tipo_pessoa')) ?></select>
                      </div>
                      <div class="fat-field">
                        <label><span class="fat-coord">E6.14</span>Nome / Razão social</label>
                        <div class="fat-busca-group"><input type="text" data-k="veiculo.dono.nome" placeholder="Nome ou razão social" value="<?= $pgv($dados, 'veiculo.dono.nome') ?>"><button type="button" class="fat-btn-busca" title="Pesquisa no Linx (em breve)"><?= $icoBusca ?></button></div>
                      </div>
                    </div>
                    <div class="fat-row fat-cols-2" style="margin-top:16px;">
                      <div class="fat-field"><label><span class="fat-coord">E6.15</span>CPF ou CNPJ</label><input data-k="veiculo.dono.doc" value="<?= $pgv($dados, 'veiculo.dono.doc') ?>"></div>
                      <div class="fat-field"><label><span class="fat-coord">E6.16</span>Relação com o cliente</label><select data-k="veiculo.dono.relacao"><?= $selRelacao($pgv($dados, 'veiculo.dono.relacao')) ?></select></div>
                    </div>
                    <div class="fat-bloco-representante-veiculo fat-row fat-cols-2" style="margin-top:16px; padding-top:16px; border-top:1px dashed var(--color-gray-light); <?= $pjDono ? '' : 'display:none;' ?>">
                      <div class="fat-field">
                        <label><span class="fat-coord">E6.17</span>Quem assina pelo CNPJ <span class="fat-req">*</span></label>
                        <input type="text" data-k="veiculo.dono.rep_nome" placeholder="Nome do representante legal" value="<?= $pgv($dados, 'veiculo.dono.rep_nome') ?>">
                      </div>
                      <div class="fat-field"><label><span class="fat-coord">E6.18</span>CPF de quem assina</label><input data-k="veiculo.dono.rep_cpf" value="<?= $pgv($dados, 'veiculo.dono.rep_cpf') ?>"></div>
                    </div>
                    <div class="fat-row fat-cols-2" style="margin-top:16px;">
                      <div class="fat-field"><label><span class="fat-coord">E6.19</span>Telefone <span class="fat-req">*</span></label><input data-k="veiculo.dono.telefone" placeholder="(51) 9XXXX-XXXX" value="<?= $pgv($dados, 'veiculo.dono.telefone') ?>"></div>
                      <div class="fat-field"><label><span class="fat-coord">E6.20</span>E-mail <span class="fat-req">*</span></label><input type="email" data-k="veiculo.dono.email" placeholder="nome@email.com" value="<?= $pgv($dados, 'veiculo.dono.email') ?>"></div>
                    </div>
                    <span class="fat-field-hint" style="margin-top:8px;">Telefone e e-mail alimentam o envio do Termo de Cessão de Veículo para assinatura (Assinafy) — F4 assina</span>
                  </div>
                </div>

                <div style="text-align:right; margin-top:14px;">
                  <button type="button" class="fat-btn-add-linha" onclick="this.closest('.fat-linha-terceiro').style.display='none'" title="Recolher após preencher">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 15l-6-6-6 6"/></svg>
                    Recolher
                  </button>
                </div>
              </div></td>
            </tr>
    <?php return ob_get_clean();
};
$linhasPg = !empty($pagamentos) ? $pagamentos : [[]];
$filialAtual = null;
foreach (($filiaisLista ?? []) as $fl) {
    if ($fl['id'] === ($pv['filial_id'] ?? '')) {
        $filialAtual = $fl;
    }
}
?>
<style>

  /* Tokens de cor/fonte já vêm de design-system.css (base.php) — não redefinidos aqui.
     Tudo neste bloco é prefixado com fat- e escopado dentro de .fat-page — página
     protótipo, ainda sem CSS próprio no design-system.css compartilhado. */
  .fat-page, .fat-page * { box-sizing: border-box; }
  .fat-page { font-family: var(--font-regular); color: var(--text-primary); font-size: 14px; margin: 0; padding: 0; }
  .fat-page button, .fat-page input, .fat-page select { margin: 0; padding: 0; }

  /* ── Coordenadas de campo (referência tipo planilha, ex.: A, A1, A2…) ── */
  .fat-coord {
    display: inline-block; font-family: 'Courier New', monospace; font-size: 10px; font-weight: 700;
    color: var(--color-gray-dark); background: var(--color-gray-light); border-radius: 3px;
    padding: 1px 5px; margin-right: 6px; vertical-align: middle;
  }
  .fat-coord-wrap { position: relative; display: inline-flex; }
  .fat-coord-wrap .fat-coord {
    position: absolute; top: -9px; right: -8px; margin: 0; background: var(--color-dark); color: #fff; z-index: 1;
  }
  .fat-card__title .fat-coord { background: var(--color-tag-bg); color: var(--color-primary); }

  /* ── Cabeçalho do pedido (linha própria — a topbar real é genérica) ── */
  .fat-pedido-header { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
  .fat-pedido-header h1 { font-family: var(--font-bold); font-size: 20px; color: var(--text-primary); }
  .fat-pedido-header h1 span { color: var(--color-primary); }
  .fat-pedido-codigo { font-family: var(--font-medium); font-size: 13px; color: var(--text-muted); margin-left: 10px; }

  .fat-status-pill { display: flex; align-items: center; gap: 8px; padding: 6px 14px 6px 6px; border-radius: 20px; background: var(--color-tag-bg); }
  .fat-status-pill__avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--color-primary); color: #fff; font-family: var(--font-bold); font-size: 11px; display: flex; align-items: center; justify-content: center; }
  .fat-status-pill__text { display: flex; flex-direction: column; line-height: 1.15; }
  .fat-status-pill__label { font-family: var(--font-bold); font-size: 11px; color: var(--color-primary); }
  .fat-status-pill__name { font-family: var(--font-medium); font-size: 12px; color: var(--text-primary); }

  /* ── Conteúdo ────────────────────────────────────────── */
  .fat-content { padding: 32px; max-width: 980px; background: var(--color-bg); }

  .fat-pendencias { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #FFF3E0; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid var(--color-warning); }
  .fat-pendencias svg { color: var(--color-warning); flex-shrink: 0; }
  .fat-pendencias__text { font-size: 13px; color: var(--text-primary); }
  .fat-pendencias__text b { font-family: var(--font-medium); }

  .fat-card { background: var(--color-surface); border-radius: 12px; box-shadow: rgba(0,0,0,0.10) 0px 2px 8px; margin-bottom: 20px; overflow: hidden; }
  .fat-card__header { display: flex; align-items: center; justify-content: space-between; padding: 16px 24px; border-bottom: 1px solid var(--color-gray-light); }
  .fat-card__title { font-family: var(--font-bold); font-size: 16px; color: var(--text-primary); }
  .fat-card__sub { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
  .fat-card__body { padding: 24px; }

  .fat-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 8px; font-family: var(--font-bold); font-size: 10px; text-transform: uppercase; letter-spacing: 0.03em; }
  .fat-badge--success { background: #D8F5E5; color: var(--color-success); }
  .fat-badge--warning { background: #FFF3E0; color: var(--color-warning); }
  .fat-badge--danger  { background: #FFE8EE; color: var(--color-danger); }
  .fat-badge--muted   { background: var(--color-gray-light); color: var(--text-muted); }

  /* Tooltip — informação secundária que só aparece no hover */
  .fat-tip { position: relative; cursor: help; }
  .fat-tip::after {
    content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 0; white-space: nowrap;
    background: var(--color-dark); color: #fff; font-size: 11px; font-family: var(--font-regular);
    padding: 6px 10px; border-radius: 4px; opacity: 0; visibility: hidden; pointer-events: none;
    transition: opacity .15s; z-index: 10;
  }
  .fat-tip:hover::after { opacity: 1; visibility: visible; }

  .fat-row { display: grid; gap: 16px; margin-bottom: 16px; }
  .fat-row.fat-cols-2 { grid-template-columns: 1fr 1fr; }
  .fat-row.fat-cols-3 { grid-template-columns: 1fr 1fr 1fr; }
  .fat-row.fat-cols-4 { grid-template-columns: 1.4fr 1fr 1fr 1fr; }
  .fat-row.fat-cols-5 { grid-template-columns: repeat(5, 1fr); }
  .fat-row:last-child { margin-bottom: 0; }

  .fat-field { display: flex; flex-direction: column; gap: 6px; }
  .fat-field label { font-family: var(--font-medium); font-size: 12px; color: var(--text-secondary); display: flex; align-items: center; }
  .fat-field label .fat-req { color: var(--color-danger); margin-left: 2px; }
  .fat-field input, .fat-field select {
    height: 40px; border: 1px solid #DFDFDF; border-radius: 4px; padding: 0 12px;
    font-family: var(--font-regular); font-size: 14px; color: var(--text-primary); background: var(--color-surface);
  }
  .fat-field input:focus, .fat-field select:focus { outline: none; border-color: var(--color-primary); }
  .fat-field input[readonly] { background: var(--color-bg); color: var(--text-secondary); cursor: not-allowed; }
  .fat-field-hint { font-size: 11px; color: var(--text-muted); display: flex; align-items: center; gap: 4px; }

  .fat-busca-group { display: flex; gap: 8px; }
  .fat-busca-group input { flex: 1; }
  .fat-btn-busca {
    width: 40px; height: 40px; border-radius: 4px; border: 1px solid var(--color-primary);
    background: transparent; color: var(--color-primary); display: flex; align-items: center; justify-content: center;
    cursor: pointer; flex-shrink: 0;
  }
  .fat-btn-busca:hover { background: #EEF2FD; }

  .fat-checkbox-inline { display: flex; align-items: center; gap: 6px; font-family: var(--font-medium); font-size: 13px; color: var(--text-secondary); }
  .fat-checkbox-inline input[type="checkbox"] {
    -webkit-appearance: none; appearance: none; margin: 0; flex: 0 0 16px;
    width: 16px !important; height: 16px !important; min-width: 16px; min-height: 16px;
    border: 1.5px solid var(--color-gray-medium); border-radius: 3px; background: var(--color-surface);
    cursor: pointer; position: relative;
  }
  .fat-checkbox-inline input[type="checkbox"]:checked {
    background: var(--color-primary); border-color: var(--color-primary);
  }
  .fat-checkbox-inline input[type="checkbox"]:checked::after {
    content: ""; position: absolute; left: 4px; top: 1px; width: 4px; height: 8px;
    border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg);
  }

  .fat-dados-auto { background: var(--color-bg); border-radius: 8px; padding: 16px; margin-top: 16px; }
  .fat-dados-auto__title { font-family: var(--font-bold); font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 12px; }
  /* Quadros recolhíveis (Dados do cliente / Dados do veículo) */
  .fat-dados-auto__title--recolhivel { display: flex; align-items: center; gap: 8px; }
  .fat-btn-recolher { width: 24px; height: 24px; border: 1px solid var(--color-gray-light); border-radius: 4px; background: var(--color-surface); color: var(--text-secondary); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; padding: 0; }
  .fat-btn-recolher:hover { border-color: var(--color-primary); color: var(--color-primary); }
  .fat-btn-recolher svg { transition: transform .15s; }
  .fat-dados-auto.fat-recolhido .fat-btn-recolher svg { transform: rotate(180deg); }
  .fat-dados-auto.fat-recolhido > :not(.fat-dados-auto__title) { display: none; }
  .fat-dados-auto.fat-recolhido .fat-dados-auto__title { margin-bottom: 0; }

  /* ── Tabela forma de pagamento ───────────────────────── */
  table.fat-pagamentos { width: 100%; border-collapse: collapse; }
  table.fat-pagamentos thead th {
    background: var(--color-gray-dark); color: #fff; font-family: var(--font-bold); font-size: 12px;
    text-transform: uppercase; text-align: left; padding: 10px 12px;
  }
  table.fat-pagamentos thead th .fat-coord { background: rgba(255,255,255,0.2); color: #fff; }
  table.fat-pagamentos thead th:first-child { border-top-left-radius: 4px; }
  table.fat-pagamentos thead th:last-child { border-top-right-radius: 4px; }
  table.fat-pagamentos tbody td { padding: 8px 12px; border-bottom: 1px solid var(--color-gray-light); }
  table.fat-pagamentos tbody tr.fat-linha-principal:nth-child(4n+1) { background: #F8F8F8; }
  table.fat-pagamentos tbody tr.fat-linha-principal:hover { background: #EEF2FD; }
  table.fat-pagamentos input, table.fat-pagamentos select { height: 36px; width: 100%; border: 1px solid #DFDFDF; border-radius: 4px; padding: 0 8px; font-size: 13px; font-family: var(--font-regular); }
  .fat-acoes-linha { display: flex; gap: 4px; }
  .fat-btn-remover-linha, .fat-btn-terceiro { width: 28px; height: 28px; border-radius: 4px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; }
  .fat-btn-remover-linha { background: transparent; color: var(--color-danger); }
  .fat-btn-remover-linha:hover { background: #FFE8EE; }
  /* Vazio (padrão) = ainda não preenchido. .ok = já tem os dados-chave preenchidos. */
  .fat-btn-terceiro { background: var(--color-gray-light); color: var(--text-muted); }
  .fat-btn-terceiro:hover { background: var(--color-gray-medium); color: #fff; }
  .fat-btn-terceiro.ok { background: #D8F5E5; color: var(--color-success); }
  .fat-btn-terceiro.ok:hover { background: #b8e9cf; }
  .fat-btn-terceiro[hidden] { display: none; }

  .fat-linha-terceiro td { background: #FBF9FE; border-bottom: 1px solid var(--color-gray-light); padding: 14px 12px 18px; }
  .fat-linha-terceiro__box { background: var(--color-surface); border: 1px dashed var(--color-tag-bg); border-radius: 6px; padding: 14px; }
  .fat-linha-terceiro__title { font-family: var(--font-bold); font-size: 11px; color: var(--color-primary); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 10px; }

  .fat-btn-add-linha {
    display: inline-flex; align-items: center; gap: 6px; margin-top: 12px; padding: 8px 14px;
    background: transparent; border: 1px dashed var(--color-gray-medium); border-radius: 4px;
    color: var(--text-secondary); font-family: var(--font-medium); font-size: 12px; cursor: pointer;
  }
  .fat-btn-add-linha:hover { border-color: var(--color-primary); color: var(--color-primary); }

  /* ── Assinaturas ─────────────────────────────────────── */
  .fat-assinaturas { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; }
  .fat-assinatura-item { border: 1px solid var(--color-gray-light); border-radius: 8px; padding: 12px 8px; text-align: center; position: relative; }
  .fat-assinatura-item .fat-coord { position: absolute; top: 8px; left: 8px; }
  .fat-assinatura-item__icone { width: 32px; height: 32px; border-radius: 50%; margin: 0 auto 8px; display: flex; align-items: center; justify-content: center; }
  .fat-assinatura-item.ok .fat-assinatura-item__icone { background: #D8F5E5; color: var(--color-success); }
  .fat-assinatura-item.pendente .fat-assinatura-item__icone { background: var(--color-gray-light); color: var(--text-muted); }
  .fat-assinatura-item__papel { font-family: var(--font-bold); font-size: 12px; color: var(--text-primary); }
  .fat-assinatura-item__status { font-size: 10px; margin-top: 4px; }
  .fat-assinatura-item.ok .fat-assinatura-item__status { color: var(--color-success); }
  .fat-assinatura-item.pendente .fat-assinatura-item__status { color: var(--text-muted); }

  /* ── Anexos ──────────────────────────────────────────── */
  .fat-anexos { display: flex; flex-direction: column; gap: 8px; }
  .fat-anexo-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border: 1px solid var(--color-gray-light); border-radius: 8px; position: relative; }
  .fat-anexo-item .fat-coord { position: static; margin-right: 4px; }
  .fat-anexo-item__icone { width: 30px; height: 30px; border-radius: 6px; background: var(--color-bg); display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--text-secondary); }
  .fat-anexo-item.ok .fat-anexo-item__icone { background: #D8F5E5; color: var(--color-success); }
  .fat-anexo-item__nome { font-family: var(--font-medium); font-size: 13px; color: var(--text-primary); flex: 1; }
  .fat-anexo-item__meta { font-size: 11px; color: var(--text-muted); }
  .fat-btn-anexar { height: 30px; padding: 0 12px; border-radius: 4px; border: 1px solid var(--color-gray-light); background: var(--color-surface); color: var(--text-secondary); font-family: var(--font-medium); font-size: 11px; cursor: pointer; }
  .fat-btn-anexar:hover { border-color: var(--color-primary); color: var(--color-primary); }
  .fat-anexo-item__arquivos { display: flex; flex-direction: column; gap: 2px; align-items: flex-end; }
  .fat-comprov-item { display: flex; align-items: center; gap: 6px; font-size: 12px; margin-bottom: 4px; }
  .fat-comprov-item a, .fat-anexo-item__arquivos a { color: var(--color-primary); text-decoration: none; max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .fat-link-remover { border: none; background: none; color: var(--text-muted); font-size: 16px; line-height: 1; cursor: pointer; padding: 0 4px; }
  .fat-link-remover:hover { color: var(--color-danger); }

  /* ── Ações de rodapé ─────────────────────────────────── */
  .fat-acoes { display: flex; justify-content: space-between; align-items: center; padding: 20px 0 40px; }
  .fat-btn { height: 40px; padding: 0 20px; border-radius: 4px; font-family: var(--font-bold); font-size: 13px; cursor: pointer; border: none; }
  .fat-btn--primary { background: var(--color-primary); color: #fff; }
  .fat-btn--primary:hover { background: var(--color-primary-hover); }
  .fat-btn--secundario { background: transparent; color: var(--color-primary); border: 1px solid var(--color-primary); }
  .fat-btn--terciario { background: var(--color-gray-light); color: var(--color-gray-dark); }
  .fat-acoes__direita { display: flex; gap: 10px; }

  /* ── Modal de busca/cadastro de cliente (B1) ─────────── */
  .fat-modal-overlay {
    display: none; position: fixed; inset: 0; background: rgba(24,19,31,0.55);
    align-items: flex-start; justify-content: center; padding: 60px 20px; z-index: 100; overflow-y: auto;
  }
  .fat-modal-overlay.aberto { display: flex; }
  .fat-modal { background: var(--color-surface); border-radius: 12px; width: 100%; max-width: 640px; box-shadow: rgba(0,0,0,0.25) 0px 12px 32px; }
  .fat-modal__header { display: flex; align-items: center; justify-content: space-between; padding: 18px 24px; border-bottom: 1px solid var(--color-gray-light); }
  .fat-modal__title { font-family: var(--font-bold); font-size: 16px; color: var(--text-primary); }
  .fat-modal__fechar { width: 32px; height: 32px; border: none; background: var(--color-gray-light); border-radius: 4px; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; }
  .fat-modal__fechar:hover { background: var(--color-gray-medium); color: #fff; }
  .fat-modal__body { padding: 24px; }
  .fat-modal__footer { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 24px; border-top: 1px solid var(--color-gray-light); }

  .fat-resultado-item { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1px solid var(--color-gray-light); border-radius: 8px; margin-bottom: 8px; }
  .fat-resultado-item__info { flex: 1; }
  .fat-resultado-item__nome { font-family: var(--font-medium); font-size: 13px; color: var(--text-primary); }
  .fat-resultado-item__meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

  .fat-nao-encontrado { text-align: center; padding: 20px 10px; border: 1px dashed var(--color-gray-light); border-radius: 8px; margin-top: 8px; }
  .fat-nao-encontrado p { font-size: 12px; color: var(--text-muted); margin-bottom: 12px; }

  /* ── Legenda de coordenadas ──────────────────────────── */
  .fat-legenda { background: var(--color-surface); border-radius: 12px; box-shadow: rgba(0,0,0,0.10) 0px 2px 8px; padding: 20px 24px; margin-top: 32px; }
  .fat-legenda__title { font-family: var(--font-bold); font-size: 13px; color: var(--text-primary); margin-bottom: 12px; }
  .fat-legenda table { width: 100%; border-collapse: collapse; font-size: 12px; }
  .fat-legenda th { text-align: left; color: var(--text-muted); font-family: var(--font-bold); text-transform: uppercase; font-size: 10px; padding: 4px 8px; }
  .fat-legenda td { padding: 4px 8px; border-top: 1px solid var(--color-gray-light); }
</style>

<div class="fat-page">
  <div class="fat-content">

<?php if (!empty($pedidoAtual)):
    $sitP = $situacao; ?>
    <a href="?module=faturamento&action=index" style="font-size:13px; color:#606060; text-decoration:none">← Voltar para a lista</a>

    <div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; padding:20px; margin:12px 0 20px; border-left:4px solid #2347C8">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px">
        <div>
          <h1 style="font-size:32px; margin:0">Pedido <span style="color:#2347C8"><?= h($pedidoAtual['numero']) ?></span></h1>
          <div style="font-size:12px; color:#888; margin-top:4px">
            Vendedor: <?= h($pedidoAtual['vendedor_nome'] ?? '—') ?> · início <?= h(date('d/m/Y', strtotime($pedidoAtual['data_inicio']))) ?>
            · <?= $podeEditar ? 'você pode editar nesta fase' : 'somente visualização nesta fase' ?>
          </div>
        </div>
      </div>

      <!-- Trilha de fases -->
      <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:16px">
        <?php $passou = true; foreach ($fases as $f):
            $atual = $f['id'] === $pedidoAtual['fase_id'];
            if ($atual) { $passou = false; $bgF = '#2347C8'; $fgF = '#fff'; }
            elseif ($passou) { $bgF = '#D8F5E5'; $fgF = '#00A960'; }
            else { $bgF = '#EAEAEA'; $fgF = '#888'; } ?>
          <span style="background:<?= $bgF ?>; color:<?= $fgF ?>; border-radius:8px; padding:4px 12px; font-size:11px; font-weight:700; text-transform:uppercase"><?= h($f['nome']) ?></span>
        <?php endforeach; ?>
      </div>

      <!-- Situação -->
      <div style="margin-top:16px; font-size:13px">
        <b>Situação:</b>
        <?= $sitP['completo'] ? '<span style="color:#00A960; font-weight:700">Completo</span>' : '<span style="color:#FF8A2D; font-weight:700">' . (int) $sitP['pendentes'] . ' pendência(s)</span>' ?>
        <ul style="list-style:none; padding:0; margin:8px 0 0; display:flex; flex-wrap:wrap; gap:6px 16px">
          <?php foreach ($sitP['itens'] as $it): ?>
            <li style="color:<?= $it['cumprido'] ? '#00A960' : ($it['da_fase'] ? '#B3261E' : '#888') ?>">
              <?= $it['cumprido'] ? '✓' : '○' ?> <?= h($it['nome']) ?>
              <span style="font-size:11px; color:#888">(<?= h($it['fase_nome']) ?><?= !$it['cumprido'] && $it['da_fase'] ? ' — trava o OK' : '' ?>)</span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
<?php else: ?>

    <div class="fat-pedido-header">
      <div>
        <h1>Novo <span>pedido</span></h1>
        <span class="fat-pedido-codigo">O número do FAT é gerado ao salvar</span>
      </div>
    </div>
<?php endif; ?>

    <!-- Dados gerais: grupos A, B, C -->
    <div class="fat-card">
      <div class="fat-card__header">
        <div>
          <div class="fat-card__title"><span class="fat-coord">A–C</span>Dados gerais</div>
        </div>
      </div>
      <div class="fat-card__body">

        <div class="fat-row fat-cols-2">
          <div class="fat-field">
            <label><span class="fat-coord">A</span>Empresa / Filial <span class="fat-req">*</span></label>
            <select id="campo-filial" name="filial_id" onchange="preencherEmpresa(this)">
              <option value="">Selecione…</option>
              <?php foreach (($filiaisLista ?? []) as $fl): ?>
                <option value="<?= h($fl['id']) ?>" <?= $fl['id'] === ($pv['filial_id'] ?? '') ? 'selected' : '' ?>
                        data-razao="<?= h($fl['nome']) ?>" data-cnpj="<?= h($cnpjFmt((string) $fl['cnpj'])) ?>"
                        data-end="<?= h((string) $fl['endereco']) ?>" data-cidade="<?= h(trim($fl['cidade'] . '/' . $fl['estado'], '/')) ?>"
                        data-tel="<?= h((string) $fl['telefone']) ?>"><?= h($fl['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fat-field">
            <label><span class="fat-coord">A6</span>Vendedor <span class="fat-req">*</span></label>
            <select id="campo-vendedor" name="vendedor_id" <?= ($podeTrocarVendedor ?? false) ? '' : 'disabled title="Só Secretárias, Gerentes e Diretoria trocam o vendedor"' ?>>
              <?php foreach (($vendedores ?? []) as $vd): ?>
                <option value="<?= h($vd['id']) ?>" <?= $vd['id'] === ($vendedorSel ?? '') ? 'selected' : '' ?>><?= h($vd['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <?php /* Quadro A1–A5 oculto por ora (retomar depois): continua sendo preenchido pelo JS, só não é exibido. */ ?>
        <div class="fat-dados-auto" style="display:none;">
          <div class="fat-dados-auto__title">Dados da empresa (auto — preenchido ao escolher A)</div>
          <div class="fat-row fat-cols-3">
            <div class="fat-field"><label><span class="fat-coord">A1</span>Razão social</label><input id="campo-empresa-razao" readonly value="<?= h($filialAtual['nome'] ?? '') ?>"></div>
            <div class="fat-field"><label><span class="fat-coord">A2</span>CNPJ</label><input id="campo-empresa-cnpj" readonly value="<?= h($filialAtual ? $cnpjFmt((string) $filialAtual['cnpj']) : '') ?>"></div>
            <div class="fat-field"><label><span class="fat-coord">A3</span>Endereço</label><input id="campo-empresa-end" readonly value="<?= h($filialAtual['endereco'] ?? '') ?>"></div>
            <div class="fat-field"><label><span class="fat-coord">A4</span>Cidade</label><input id="campo-empresa-cidade" readonly value="<?= h($filialAtual ? trim($filialAtual['cidade'] . '/' . $filialAtual['estado'], '/') : '') ?>"></div>
            <div class="fat-field"><label><span class="fat-coord">A5</span>Telefone</label><input id="campo-empresa-tel" readonly value="<?= h($filialAtual['telefone'] ?? '') ?>"></div>
          </div>
        </div>

        <div class="fat-row fat-cols-2" style="margin-top:16px;">
          <div class="fat-field">
            <label><span class="fat-coord">A7</span>Pedido</label>
            <div class="fat-busca-group">
              <input type="text" id="campo-pedido-apollo" name="pedido_apollo" maxlength="30" inputmode="numeric"
                     placeholder="Nº do pedido no Apollo" value="<?= $fv('pedido_apollo') ?>" onkeydown="if(event.key==='Enter'){event.preventDefault();buscarPedidoApollo();}">
              <div class="fat-coord-wrap">
                <span class="fat-coord">A8</span>
                <button type="button" class="fat-btn-busca" onclick="buscarPedidoApollo()" title="A8 · Buscar o pedido no Apollo e preencher os dados">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                </button>
              </div>
            </div>
          </div>
          <div class="fat-field">
            <label><span class="fat-coord">C2</span>Tipo de venda</label>
            <select id="campo-tipo-venda" name="tipo_venda">
              <option value="">Selecione…</option>
              <?php foreach (['Novos', 'Seminovos', 'Venda Direta', 'Corsia', 'Repasse'] as $tv): ?>
                <option value="<?= h($tv) ?>" <?= $tv === ($pv['tipo_venda'] ?? '') ? 'selected' : '' ?>><?= h($tv) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="fat-row fat-cols-2" style="margin-top:16px;">
          <div class="fat-field">
            <label><span class="fat-coord">B</span>Cliente <span class="fat-req">*</span></label>
            <div class="fat-busca-group">
              <input type="text" id="campo-cliente-nome" value="<?= $fv('cliente_nome') ?>" placeholder="Pesquise o cliente" readonly>
              <div class="fat-coord-wrap">
                <span class="fat-coord">B1</span>
                <button class="fat-btn-busca" onclick="abrirModalCliente()" title="B1 · Pesquisar cliente no Linx">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                </button>
              </div>
            </div>
            <span class="fat-field-hint"><span class="fat-coord">B7</span><span class="fat-badge fat-badge--success fat-tip" data-tip="Atualizado há 12 dias no Linx">Cadastro em dia</span></span>
          </div>
          <div class="fat-field">
            <label><span class="fat-coord">C</span>Código do veículo</label>
            <div class="fat-busca-group">
              <input type="text" id="campo-veiculo-codigo" value="<?= $fv('veiculo_codigo') ?>" placeholder="Aguardando chegada da fábrica…" readonly>
              <div class="fat-coord-wrap">
                <span class="fat-coord">C1</span>
                <button class="fat-btn-busca" onclick="abrirModalVeiculo()" title="C1 · Pesquisar veículo no Apollo">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                </button>
              </div>
            </div>
            <span class="fat-field-hint"><span class="fat-badge fat-badge--warning fat-tip" data-tip="Só bloqueia ao chegar na etapa de faturamento">Pendente</span></span>
          </div>
        </div>

        <div class="fat-dados-auto">
          <div class="fat-dados-auto__title fat-dados-auto__title--recolhivel">Dados do cliente (auto — preenchido ao escolher B) <button type="button" class="fat-btn-recolher" onclick="alternarQuadro(this)" aria-expanded="true" title="Recolher"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 15l-6-6-6 6"/></svg></button></div>
          <div class="fat-row fat-cols-3">
            <div class="fat-field"><label><span class="fat-coord">B2</span>CPF ou CNPJ</label><input id="campo-cliente-doc" readonly value="<?= $fv('cliente_doc') ?>"></div>
            <div class="fat-field"><label><span class="fat-coord">B3</span>Endereço</label><input id="campo-cliente-endereco" readonly value="<?= $fv('cliente_endereco') ?>"></div>
            <div class="fat-field"><label><span class="fat-coord">B4</span>Número</label><input id="campo-cliente-numero" readonly value="<?= $fv('cliente_numero') ?>"></div>
            <div class="fat-field"><label><span class="fat-coord">B5</span>Cidade</label><input id="campo-cliente-cidade" readonly value="<?= $fv('cliente_cidade') ?>"></div>
            <div class="fat-field"><label><span class="fat-coord">B6</span>UF</label><input id="campo-cliente-uf" readonly value="<?= $fv('cliente_uf') ?>"></div>
            <div class="fat-field"><label><span class="fat-coord">B8</span>Telefone</label><input id="campo-cliente-telefone" placeholder="(51) 9XXXX-XXXX" value="<?= $fv('cliente_telefone') ?>"></div>
            <div class="fat-field"><label><span class="fat-coord">B9</span>E-mail</label><input type="email" id="campo-cliente-email" placeholder="nome@email.com" value="<?= $fv('cliente_email') ?>"></div>
          </div>
        </div>

        <div class="fat-dados-auto">
          <div class="fat-dados-auto__title fat-dados-auto__title--recolhivel">Dados do veículo negociado — selecionáveis (catálogo) <button type="button" class="fat-btn-recolher" onclick="alternarQuadro(this)" aria-expanded="true" title="Recolher"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 15l-6-6-6 6"/></svg></button></div>
          <div class="fat-row cols-1">
            <div class="fat-field">
              <label><span class="fat-coord">C3</span>Modelo</label>
              <select id="campo-veiculo-modelo" onchange="aoEscolherModelo(this)">
                <option value="">Selecione…</option>
                <?php
                $modeloAtual = (string) ($pv['veiculo_modelo'] ?? '');
                $porMarca = [];
                foreach (($modelosVeiculo ?? []) as $mv) { $porMarca[$mv['marca']][] = $mv; }
                $achou = false;
                foreach ($porMarca as $marca => $lista): ?>
                  <optgroup label="<?= h($marca) ?>">
                    <?php foreach ($lista as $mv):
                        $sel = $mv['descricao'] === $modeloAtual; $achou = $achou || $sel; ?>
                      <option value="<?= h($mv['descricao']) ?>" <?= $sel ? 'selected' : '' ?>
                              data-comb="<?= h((string) $mv['combustivel']) ?>" data-preco="<?= h((string) $mv['preco_sugerido']) ?>"
                              data-cores="<?= h(json_encode($mv['cores'], JSON_UNESCAPED_UNICODE)) ?>"
                              data-mvs="<?= h($mv['mvs']) ?>"><?= h($mv['descricao']) ?> (<?= (int) $mv['ano_modelo'] ?>)</option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endforeach;
                if ($modeloAtual !== '' && !$achou): ?>
                  <option value="<?= h($modeloAtual) ?>" selected><?= h($modeloAtual) ?> (fora do cadastro)</option>
                <?php endif; ?>
              </select>
            </div>
          </div>
          <div class="fat-row fat-cols-3" style="margin-top:16px;">
            <div class="fat-field">
              <label><span class="fat-coord">C4</span>Cor</label>
              <select id="campo-veiculo-cor"><?php
                $coresPadrao = ['Cinza', 'Branco', 'Preto', 'Prata'];
                $coresDoModelo = [];
                foreach (($modelosVeiculo ?? []) as $mv) {
                    if ($mv['descricao'] === ($pv['veiculo_modelo'] ?? '') && $mv['cores']) { $coresDoModelo = $mv['cores']; }
                }
                echo $opcoes($coresDoModelo ?: $coresPadrao, (string) ($pv['veiculo_cor'] ?? ''), 'Selecione…'); ?></select>
            </div>
            <div class="fat-field">
              <label><span class="fat-coord">C5</span>Ano fab/mod</label>
              <select id="campo-veiculo-ano"><?= $opcoes(['2026/2027', '2025/2026'], (string) ($pv['veiculo_ano_fab_mod'] ?? ''), 'Selecione…') ?></select>
            </div>
            <div class="fat-field">
              <label><span class="fat-coord">C6</span>Combustível</label>
              <select id="campo-veiculo-combustivel"><?= $opcoes(ModeloVeiculo::COMBUSTIVEIS, (string) ($pv['veiculo_combustivel'] ?? ''), 'Selecione…') ?></select>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- Valores: grupo D -->
    <div class="fat-card">
      <div class="fat-card__header"><div class="fat-card__title"><span class="fat-coord">D</span>Valores</div></div>
      <div class="fat-card__body">
        <div class="fat-row fat-cols-4">
          <div class="fat-field"><label><span class="fat-coord">D</span>Valor do veículo <span class="fat-req">*</span></label><input id="campo-valor-veiculo" name="valor_veiculo" inputmode="decimal" class="js-valor" value="<?= h($moeda($pv['valor_veiculo'] ?? null)) ?>" placeholder="0,00"></div>
          <div class="fat-field"><label><span class="fat-coord">D1</span>Opcionais</label><input id="campo-valor-opcionais" name="valor_opcionais" inputmode="decimal" class="js-valor" value="<?= h($moeda($pv['valor_opcionais'] ?? null)) ?>" placeholder="0,00"></div>
          <div class="fat-field"><label><span class="fat-coord">D2</span>Frete</label><input id="campo-valor-frete" name="valor_frete" inputmode="decimal" class="js-valor" value="<?= h($moeda($pv['valor_frete'] ?? null)) ?>" placeholder="0,00"></div>
          <div class="fat-field"><label><span class="fat-coord">D3</span>Total <span class="fat-req">*</span></label><input id="campo-valor-total" name="valor_total" readonly value="<?= h($moeda($pv['valor_total'] ?? null)) ?>" placeholder="0,00" style="font-family:var(--font-bold); color:var(--color-primary);"></div>
        </div>
      </div>
    </div>

    <!-- Forma de pagamento: grupo E -->
    <div class="fat-card">
      <div class="fat-card__header">
        <div>
          <div class="fat-card__title"><span class="fat-coord">E</span>Forma de pagamento</div>
          <div class="fat-card__sub">Tipo vem de uma lista (Listas → Forma de Pagamento). O botão E6 existe em toda linha — o conteúdo que ele abre depende do Tipo: dados de quem paga, ou, se Tipo = Troca, dados do veículo entregue (e de quem é dono)</div>
        </div>
      </div>
      <div class="fat-card__body">
        <table class="fat-pagamentos">
          <thead>
            <tr>
              <th style="width:36px">#</th>
              <th><span class="fat-coord">E</span>Tipo</th>
              <th><span class="fat-coord">E1</span>Valor</th>
              <th><span class="fat-coord">E2</span>Data</th>
              <th><span class="fat-coord">E3</span>Comprovante</th>
              <th style="width:70px"></th>
            </tr>
          </thead>
          <tbody id="linhas-pagamento">
            <?php foreach (array_values($linhasPg) as $i => $pg): ?>
              <?= $linhaPagamento((string) ($i + 1), $pg) ?>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="fat-coord-wrap">
          <span class="fat-coord">E4</span>
          <button type="button" class="fat-btn-add-linha" onclick="adicionarLinha()" title="E4 · Adicionar linha">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
            Adicionar linha
          </button>
        </div>
        <div id="resumo-pagamentos" style="margin-top:12px; font-size:13px; color:#606060"></div>
        <template id="tpl-pagamento"><?= $linhaPagamento('__N__', ['tipo' => 'Financiamento']) ?></template>
      </div>
    </div>

    <!-- Assinaturas: grupo F -->
    <div class="fat-card">
      <div class="fat-card__header"><div class="fat-card__title"><span class="fat-coord">F</span>Assinaturas</div></div>
      <div class="fat-card__body">
        <div class="fat-assinaturas">
          <?php if (empty($pv['id'])): ?>
            <div class="fat-field-hint">Salve o pedido para gerar a lista de quem precisa assinar.</div>
          <?php elseif (empty($assinaturas)): ?>
            <div class="fat-field-hint">Nenhum signatário ainda — clique em <b>Salvar rascunho</b> para gerar a lista a partir dos dados do pedido.</div>
          <?php else:
            $rotStatus = ['pendente' => 'Pendente', 'enviado' => 'Enviado', 'assinado' => 'Assinado', 'recusado' => 'Recusado'];
            foreach ($assinaturas as $k => $as):
                $ok    = $as['status'] === 'assinado';
                $falta = array_filter([
                    trim((string) $as['email']) === '' ? 'e-mail' : null,
                    ($as['papel'] !== 'vendedor' && $as['papel'] !== 'gerente' && trim((string) $as['telefone']) === '') ? 'telefone' : null,
                ]); ?>
          <div class="fat-assinatura-item <?= $ok ? 'ok' : 'pendente' ?>">
            <span class="fat-coord"><?= $k === 0 ? 'F' : 'F' . $k ?></span>
            <div class="fat-assinatura-item__icone"><?= $ok
                ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>'
                : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>' ?></div>
            <div class="fat-assinatura-item__papel"><?= h(Faturamento::PAPEIS_ASSINATURA[$as['papel']] ?? $as['papel']) ?>
              <div class="fat-anexo-item__meta"><?= h($as['nome'] ?: '—') ?><?= $as['email'] ? ' · ' . h($as['email']) : '' ?><?= $as['telefone'] ? ' · ' . h($as['telefone']) : '' ?></div>
              <?php if (!$ok && $falta): ?><div class="fat-anexo-item__meta" style="color:#FF8A2D">Falta: <?= h(implode(' e ', $falta)) ?> para enviar o documento</div><?php endif; ?>
            </div>
            <div class="fat-assinatura-item__status"><?= h($rotStatus[$as['status']] ?? $as['status']) ?></div>
          </div>
            <?php endforeach; ?>
            <div class="fat-field-hint" style="margin-top:8px;">O envio para assinatura pela Assinafy ainda não está habilitado: o status só muda quando a integração for ligada.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Anexos: grupo G -->
    <div class="fat-card">
      <div class="fat-card__header"><div class="fat-card__title"><span class="fat-coord">G</span>Anexos</div></div>
      <div class="fat-card__body">
        <div class="fat-anexos" id="bloco-anexos">
          <?php if (empty($pv['id'])): ?>
            <div class="fat-field-hint">Salve o pedido para poder anexar os arquivos.</div>
          <?php else:
            $icoImg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/></svg>';
            $icoDoc = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/></svg>';
            $n = 0;
            foreach (($tiposAnexo ?? []) as $chave => $rotulo):
                $lista = array_values(array_filter($anexos ?? [], static fn($a) => $a['tipo'] === $chave && empty($a['pagamento_id'])));
                $coord = $n === 0 ? 'G' : 'G' . $n;
                $n++; ?>
          <div class="fat-anexo-item <?= $lista ? 'ok' : '' ?>">
            <span class="fat-coord"><?= h($coord) ?></span>
            <div class="fat-anexo-item__icone"><?= $lista ? $icoImg : $icoDoc ?></div>
            <span class="fat-anexo-item__nome"><?= h($rotulo) ?></span>
            <?php if ($lista): ?>
              <span class="fat-anexo-item__arquivos">
                <?php foreach ($lista as $an): ?>
                  <span class="fat-comprov-item"><a href="?module=faturamento&action=anexo_baixar&id=<?= h($an['id']) ?>" target="_blank" rel="noopener" title="<?= h($an['nome_original']) ?>"><?= h($an['nome_original']) ?></a>
                    <span class="fat-anexo-item__meta"><?= h(date('d/m/Y', strtotime($an['criado_em']))) ?><?= $an['enviado_por_nome'] ? ' · ' . h($an['enviado_por_nome']) : '' ?></span>
                    <button type="button" class="fat-link-remover" onclick="removerAnexo('<?= h($an['id']) ?>')" title="Remover anexo">×</button></span>
                <?php endforeach; ?>
              </span>
              <span class="fat-badge fat-badge--success">Ok</span>
              <button type="button" class="fat-btn-anexar" onclick="escolherArquivo('<?= h($chave) ?>', '')" title="Anexar mais um arquivo">+</button>
            <?php else: ?>
              <span class="fat-anexo-item__meta">aguardando</span>
              <button type="button" class="fat-btn-anexar" onclick="escolherArquivo('<?= h($chave) ?>', '')">Anexar</button>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <div class="fat-anexo-item">
            <span class="fat-coord">G<?= $n ?></span>
            <div class="fat-anexo-item__icone"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 100 20 10 10 0 000-20z"/><path d="M12 8v4l3 3"/></svg></div>
            <span class="fat-anexo-item__nome">Retorno do Assinafy</span>
            <span class="fat-anexo-item__meta">automático — chega pelo webhook</span>
            <span class="fat-badge fat-badge--muted">Aguardando</span>
          </div>
          <?php endif; ?>
        </div>
        <input type="file" id="arquivo-anexo" hidden>
      </div>
    </div>

    <?php if (!empty($pv['id']) && !empty($historico)): ?>
    <!-- Histórico do pedido: criação, OKs, devoluções (com motivo), edições e anexos -->
    <div class="fat-card">
      <div class="fat-card__header"><div class="fat-card__title">Histórico</div></div>
      <div class="fat-card__body">
        <ul style="list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:8px; font-size:13px">
          <?php foreach (array_slice($historico, 0, 30) as $hi):
              $de = $hi['de_nome'] ?? ''; $para = $hi['para_nome'] ?? '';
              switch ($hi['acao']) {
                  case 'criado':    $txt = 'Pedido criado'; $cor = '#606060'; break;
                  case 'ok':        $txt = 'OK: ' . $de . ' → ' . $para; $cor = '#00A960'; break;
                  case 'devolucao': $txt = 'Devolvido: ' . $de . ' → ' . $para; $cor = '#B3261E'; break;
                  case 'anexo':     $txt = (string) $hi['observacao']; $cor = '#606060'; break;
                  default:          $txt = (string) ($hi['observacao'] ?: 'Dados salvos'); $cor = '#606060';
              } ?>
            <li style="display:flex; gap:12px; align-items:baseline">
              <span style="color:#888; font-size:12px; white-space:nowrap; min-width:118px"><?= h(date('d/m/Y H:i', strtotime($hi['criado_em']))) ?></span>
              <span style="color:<?= $cor ?>; font-weight:700"><?= h($txt) ?></span>
              <?php if ($hi['acao'] === 'devolucao' && $hi['observacao']): ?><span>— “<?= h($hi['observacao']) ?>”</span><?php endif; ?>
              <span style="color:#888; font-size:12px; margin-left:auto"><?= h($hi['usuario_nome'] ?? '') ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>

    <!-- Ações: grupo H -->
    <div class="fat-acoes">
      <div class="fat-coord-wrap">
        <?php if (!empty($pv['id']) && ($podeOk ?? false) && !empty($faseAnterior)): ?>
          <span class="fat-coord">H</span><button type="button" class="fat-btn fat-btn--terciario" title="H · Devolver para <?= h($faseAnterior['nome']) ?>" onclick="devolverPedido('<?= h($faseAnterior['nome']) ?>')">Devolver</button>
        <?php endif; ?>
      </div>
      <div class="fat-acoes__direita">
        <div class="fat-coord-wrap"><span class="fat-coord">H1</span><button type="button" class="fat-btn fat-btn--secundario" title="H1 · Salvar rascunho" onclick="salvarProposta()">Salvar rascunho</button></div>
        <?php if (!empty($pv['id']) && ($podeOk ?? false) && !empty($proximaFase)): ?>
        <div class="fat-coord-wrap"><span class="fat-coord">H2</span><button type="button" class="fat-btn fat-btn--primary" title="H2 · Salva e dá o OK para <?= h($proximaFase['nome']) ?>" onclick="avancarPedido('<?= h($proximaFase['nome']) ?>')">Avançar</button></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Legenda de coordenadas -->
    <div class="fat-legenda">
      <div class="fat-legenda__title">Mapa de coordenadas</div>
      <table>
        <thead><tr><th>Grupo</th><th>Coordenada</th><th>Campo / ação</th></tr></thead>
        <tbody>
          <tr><td rowspan="5">A — Empresa/Filial</td><td>A</td><td>Empresa / Filial (select)</td></tr>
          <tr><td>A1</td><td>Razão social (auto)</td></tr>
          <tr><td>A2</td><td>CNPJ (auto)</td></tr>
          <tr><td>A3 / A4</td><td>Endereço / Cidade (auto, campos separados)</td></tr>
          <tr><td>A5</td><td>Telefone (auto)</td></tr>
          <tr><td rowspan="23">B — Cliente</td><td>B</td><td>Cliente — nome (campo + busca)</td></tr>
          <tr><td>B1</td><td>Botão pesquisar cliente (Linx) — abre modal</td></tr>
          <tr><td>B1.1 / B1.2</td><td>Modal: campos de busca — Nome / CPF ou CNPJ</td></tr>
          <tr><td>B1.3</td><td>Modal: botão Buscar</td></tr>
          <tr><td>B1.4</td><td>Modal: resultado encontrado no Linx — botão Selecionar</td></tr>
          <tr><td>B1.5</td><td>Modal: botão "+ Cadastrar novo cliente" (cliente não achado)</td></tr>
          <tr><td>B1.6 / B1.7</td><td>Cadastro: Nome / CPF ou CNPJ</td></tr>
          <tr><td>B1.8 / B1.9</td><td>Cadastro: Endereço / Número</td></tr>
          <tr><td>B1.10 / B1.11</td><td>Cadastro: Cidade / UF</td></tr>
          <tr><td>B1.12 / B1.13</td><td>Cadastro: Telefone / E-mail</td></tr>
          <tr><td>B1.14 / B1.15</td><td>Cadastro: Estado civil / Nacionalidade</td></tr>
          <tr><td>B1.16</td><td>Botão Salvar cadastro — vai pro template de cadastro do Linx</td></tr>
          <tr><td>B2</td><td>CPF ou CNPJ (auto)</td></tr>
          <tr><td>B3 / B4</td><td>Endereço / Número (auto)</td></tr>
          <tr><td>B5 / B6</td><td>Cidade / UF (auto)</td></tr>
          <tr><td>B7</td><td>Selo de cadastro em dia (tooltip com data)</td></tr>
          <tr><td rowspan="11">C — Veículo</td><td>C</td><td>Código do veículo</td></tr>
          <tr><td>C1</td><td>Botão pesquisar veículo (Apollo) — abre modal</td></tr>
          <tr><td>C1.1 / C1.2 / C1.3</td><td>Modal: campos de busca — Código / Placa / Chassi</td></tr>
          <tr><td>C1.4</td><td>Modal: botão Buscar</td></tr>
          <tr><td>C1.5</td><td>Modal: resultado — botão Selecionar (sem opção de cadastrar: veículo vem do estoque ou não existe)</td></tr>
          <tr><td>C2</td><td>Tipo de venda — lista (Novos, Seminovos, Venda Direta, Corsia, Repasse)</td></tr>
          <tr><td>A7</td><td>Pedido — mesmo número do pedido no Apollo</td></tr>
          <tr><td>A8</td><td>Buscar o pedido no Apollo (preenche cliente, vendedor, veículo, valores e pagamentos)</td></tr>
          <tr><td>C3</td><td>Modelo (selectbox — catálogo)</td></tr>
          <tr><td>C4</td><td>Cor (selectbox)</td></tr>
          <tr><td>C5 / C6</td><td>Ano fab/mod / Combustível (selectbox)</td></tr>
          <tr><td rowspan="4">D — Valores</td><td>D</td><td>Valor do veículo</td></tr>
          <tr><td>D1</td><td>Opcionais</td></tr>
          <tr><td>D2</td><td>Frete</td></tr>
          <tr><td>D3</td><td>Total (calculado)</td></tr>
          <tr><td rowspan="21">E — Forma de pagamento</td><td>E</td><td>Tipo (por linha, vem de Lista)</td></tr>
          <tr><td>E1</td><td>Valor (por linha)</td></tr>
          <tr><td>E2</td><td>Data (por linha)</td></tr>
          <tr><td>E3</td><td>Comprovante (por linha)</td></tr>
          <tr><td>E4</td><td>Botão adicionar linha</td></tr>
          <tr><td>E5</td><td>Botão remover linha</td></tr>
          <tr><td>E6</td><td>Botão de detalhes — existe em toda linha. Abre pagador (padrão) ou veículo (quando Tipo = Troca)</td></tr>
          <tr><td>E6.1</td><td>[pagador] Tipo de pessoa (CPF ou CNPJ)</td></tr>
          <tr><td>E6.2</td><td>[pagador] Nome / Razão social (busca)</td></tr>
          <tr><td>E6.3</td><td>[pagador] CPF ou CNPJ (auto)</td></tr>
          <tr><td>E6.4</td><td>[pagador] Relação com o cliente</td></tr>
          <tr><td>E6.5 / E6.6</td><td>[pagador] Quem assina pelo CNPJ + CPF do representante — só se E6.1 = Pessoa Jurídica</td></tr>
          <tr><td>E6.7 / E6.8</td><td>[pagador] Telefone / E-mail — alimentam o envio do Termo de Cessão de Crédito pro Assinafy (F3 assina)</td></tr>
          <tr><td>E6.9 / E6.10 / E6.11</td><td>[veículo, Tipo=Troca] Placa (busca) / Modelo (auto) / Avaliação</td></tr>
          <tr><td>E6.12</td><td>[veículo] Veículo em nome de terceiro? (checkbox — abre E6.13–E6.20)</td></tr>
          <tr><td>E6.13 / E6.14</td><td>[veículo] Tipo de pessoa do dono / Nome ou Razão social (busca)</td></tr>
          <tr><td>E6.15 / E6.16</td><td>[veículo] CPF ou CNPJ do dono / Relação com o cliente</td></tr>
          <tr><td>E6.17 / E6.18</td><td>[veículo] Quem assina pelo CNPJ + CPF do representante — só se E6.13 = Pessoa Jurídica</td></tr>
          <tr><td>E6.19 / E6.20</td><td>[veículo] Telefone / E-mail — alimentam o Termo de Cessão de Veículo pro Assinafy (F4 assina)</td></tr>
          <tr><td rowspan="5">F — Assinaturas</td><td>F</td><td>Vendedor</td></tr>
          <tr><td>F1</td><td>Gerente</td></tr>
          <tr><td>F2</td><td>Cliente</td></tr>
          <tr><td>F3</td><td>Terceiro — quem paga (dados em E6, quando Tipo ≠ Troca)</td></tr>
          <tr><td>F4</td><td>Terceiro — dono do veículo da troca (dados em E6, quando Tipo = Troca)</td></tr>
          <tr><td rowspan="5">G — Anexos</td><td>G</td><td>CNH do cliente</td></tr>
          <tr><td>G1</td><td>Comprovante de endereço</td></tr>
          <tr><td>G2</td><td>Pedido assinado</td></tr>
          <tr><td>G3</td><td>Proposta assinada</td></tr>
          <tr><td>G4</td><td>Retorno do Assinafy (automático)</td></tr>
          <tr><td rowspan="3">H — Ações</td><td>H</td><td>Devolver</td></tr>
          <tr><td>H1</td><td>Salvar rascunho</td></tr>
          <tr><td>H2</td><td>Avançar</td></tr>
        </tbody>
      </table>
    </div>

  
  </div>
</div>

<!-- Modal B1 — buscar/cadastrar cliente no Linx -->
<div class="fat-modal-overlay" id="modal-cliente" onclick="if(event.target===this) fecharModalCliente()">
  <div class="fat-modal">

    <!-- Estado 1: busca -->
    <div id="modal-estado-busca">
      <div class="fat-modal__header">
        <div class="fat-modal__title">Buscar cliente no Linx</div>
        <button class="fat-modal__fechar" onclick="fecharModalCliente()" title="Fechar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </div>
      <div class="fat-modal__body">
        <div class="fat-row fat-cols-2">
          <div class="fat-field"><label><span class="fat-coord">B1.1</span>Nome</label><input type="text" id="modal-busca-nome" placeholder="Nome completo"></div>
          <div class="fat-field"><label><span class="fat-coord">B1.2</span>CPF ou CNPJ</label><input type="text" id="modal-busca-doc" placeholder="000.000.000-00"></div>
        </div>
        <div style="text-align:right; margin-top:8px;">
          <div class="fat-coord-wrap"><span class="fat-coord">B1.3</span><button class="fat-btn fat-btn--primary" onclick="buscarCliente()" title="B1.3 · Buscar">Buscar</button></div>
        </div>

        <div id="modal-resultados" style="margin-top:20px; display:none;">
          <div class="fat-dados-auto__title">Resultados no Linx</div>
          <div class="fat-coord-wrap" style="display:block;">
            <span class="fat-coord" style="position:static; margin-bottom:4px;">B1.4</span>
            <div class="fat-resultado-item">
              <div class="fat-resultado-item__info">
                <div class="fat-resultado-item__nome">GABRIEL DE OLIVEIRA</div>
                <div class="fat-resultado-item__meta">111.444.777-35 · Cidade C/RS · atualizado há 12 dias</div>
              </div>
              <button class="fat-btn-anexar" onclick="selecionarCliente('GABRIEL DE OLIVEIRA','111.444.777-35','EST CYLON ROSA','285','Cidade C','RS')">Selecionar</button>
            </div>
          </div>

          <div class="fat-nao-encontrado">
            <p>Não é o cliente que procura?</p>
            <div class="fat-coord-wrap" style="display:inline-flex;">
              <span class="fat-coord">B1.5</span>
              <button class="fat-btn fat-btn--secundario" onclick="abrirCadastroCliente()" title="B1.5 · Cadastrar novo cliente">+ Cadastrar novo cliente</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Estado 2: cadastro (cliente não encontrado no Linx) -->
    <div id="modal-estado-cadastro" style="display:none;">
      <div class="fat-modal__header">
        <div class="fat-modal__title">Cadastrar cliente</div>
        <button class="fat-modal__fechar" onclick="fecharModalCliente()" title="Fechar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
      </div>
      <div class="fat-modal__body">
        <span class="fat-field-hint" style="margin-bottom:16px;">Estes dados vão para o template de cadastro do Linx — o Notas não guarda um cadastro próprio, só reflete o que está lá</span>

        <div class="fat-row fat-cols-2">
          <div class="fat-field"><label><span class="fat-coord">B1.6</span>Nome <span class="fat-req">*</span></label><input type="text" id="cad-nome"></div>
          <div class="fat-field"><label><span class="fat-coord">B1.7</span>CPF ou CNPJ <span class="fat-req">*</span></label><input type="text" id="cad-doc"></div>
        </div>
        <div class="fat-row fat-cols-3" style="margin-top:16px;">
          <div class="fat-field"><label><span class="fat-coord">B1.8</span>Endereço</label><input type="text" id="cad-endereco"></div>
          <div class="fat-field"><label><span class="fat-coord">B1.9</span>Número</label><input type="text" id="cad-numero"></div>
          <div class="fat-field"><label><span class="fat-coord">B1.10 / B1.11</span>Cidade / UF</label><input type="text" id="cad-cidade" placeholder="Cidade/UF"></div>
        </div>
        <div class="fat-row fat-cols-2" style="margin-top:16px;">
          <div class="fat-field"><label><span class="fat-coord">B1.12</span>Telefone</label><input type="text" placeholder="(51) 9XXXX-XXXX"></div>
          <div class="fat-field"><label><span class="fat-coord">B1.13</span>E-mail</label><input type="email" placeholder="nome@email.com"></div>
        </div>
        <div class="fat-row fat-cols-2" style="margin-top:16px;">
          <div class="fat-field">
            <label><span class="fat-coord">B1.14</span>Estado civil</label>
            <select><option>Solteiro(a)</option><option>Casado(a)</option><option>Divorciado(a)</option><option>Viúvo(a)</option><option>União estável</option></select>
          </div>
          <div class="fat-field">
            <label><span class="fat-coord">B1.15</span>Nacionalidade</label>
            <select><option>Brasileira</option><option>Estrangeira</option></select>
          </div>
        </div>
      </div>
      <div class="fat-modal__footer">
        <button class="fat-btn fat-btn--terciario" onclick="voltarBusca()">Voltar</button>
        <div class="fat-coord-wrap"><span class="fat-coord">B1.16</span><button class="fat-btn fat-btn--primary" onclick="salvarCadastroCliente()" title="B1.16 · Salvar cadastro">Salvar cadastro</button></div>
      </div>
    </div>

  </div>
</div>

<!-- Modal C1 — buscar veículo no Apollo -->
<div class="fat-modal-overlay" id="modal-veiculo" onclick="if(event.target===this) fecharModalVeiculo()">
  <div class="fat-modal">
    <div class="fat-modal__header">
      <div class="fat-modal__title">Buscar veículo no Apollo</div>
      <button class="fat-modal__fechar" onclick="fecharModalVeiculo()" title="Fechar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
    </div>
    <div class="fat-modal__body">
      <div class="fat-row fat-cols-3">
        <div class="fat-field"><label><span class="fat-coord">C1.1</span>Código</label><input type="text" id="modal-busca-veiculo-codigo" placeholder="Código do estoque"></div>
        <div class="fat-field"><label><span class="fat-coord">C1.2</span>Placa</label><input type="text" id="modal-busca-veiculo-placa" placeholder="ABC1D23"></div>
        <div class="fat-field"><label><span class="fat-coord">C1.3</span>Chassi</label><input type="text" id="modal-busca-veiculo-chassi" placeholder="9BD…"></div>
      </div>
      <div style="text-align:right; margin-top:8px;">
        <div class="fat-coord-wrap"><span class="fat-coord">C1.4</span><button class="fat-btn fat-btn--primary" onclick="buscarVeiculo()" title="C1.4 · Buscar">Buscar</button></div>
      </div>

      <div id="modal-resultados-veiculo" style="margin-top:20px; display:none;">
        <div class="fat-dados-auto__title">Resultados no Apollo</div>
        <div class="fat-coord-wrap" style="display:block;">
          <span class="fat-coord" style="position:static; margin-bottom:4px;">C1.5</span>
          <div class="fat-resultado-item">
            <div class="fat-resultado-item__info">
              <div class="fat-resultado-item__nome">VN1113582 — FASTBACK SMART DRIVE TURBO 200 FLEX AT</div>
              <div class="fat-resultado-item__meta">Cinza · 2026/2027 · Álcool/Gasolina · estoque Cidade C</div>
            </div>
            <button class="fat-btn-anexar" onclick="selecionarVeiculo('VN1113582','FASTBACK SMART DRIVE TURBO 200 FLEX AT','Cinza','2026/2027','Álcool/Gasolina')">Selecionar</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>


<script>
  const PEDIDO_ID  = <?= json_encode((string) ($pv['id'] ?? '')) ?>;
  const CSRF_TOKEN = <?= json_encode(Csrf::token()) ?>;

  /* Aviso flutuante: verde = sucesso, vermelho = erro. */
  function mostrarAviso(msg, ok) {
    const antigo = document.getElementById('fat-toast');
    if (antigo) antigo.remove();
    const t = document.createElement('div');
    t.id = 'fat-toast';
    t.setAttribute('role', 'status');
    t.textContent = msg;
    t.style.cssText = 'position:fixed; top:80px; left:50%; transform:translateX(-50%); z-index:9999; ' +
      'background:' + (ok ? '#00A960' : '#B3261E') + '; color:#fff; font-weight:700; font-size:14px; padding:12px 24px; ' +
      'border-radius:8px; box-shadow:rgba(0,0,0,0.20) 0 4px 12px; transition:opacity .4s';
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; }, 3500);
    setTimeout(() => { t.remove(); }, 4000);
  }

  function irParaTopo() {
    window.scrollTo(0, 0);
    document.documentElement.scrollTop = 0;
    document.body.scrollTop = 0;
  }

  /* A — ao escolher a filial, preenche os dados da empresa (A1–A5). */
  function preencherEmpresa(sel) {
    const o = sel.selectedOptions[0];
    const d = (o && o.value) ? o.dataset : {};
    document.getElementById('campo-empresa-razao').value  = d.razao  || '';
    document.getElementById('campo-empresa-cnpj').value   = d.cnpj   || '';
    document.getElementById('campo-empresa-end').value    = d.end    || '';
    document.getElementById('campo-empresa-cidade').value = d.cidade || '';
    document.getElementById('campo-empresa-tel').value    = d.tel    || '';
  }

  /* ── Anexos (G) e comprovantes (E3) ───────────────────── */
  function escolherArquivo(tipo, pagamentoId) {
    const inp = document.getElementById('arquivo-anexo');
    inp.value = '';
    inp.onchange = () => { if (inp.files[0]) enviarAnexo(tipo, pagamentoId, inp.files[0]); };
    inp.click();
  }

  /* Recarrega só os blocos de anexos (G e coluna E3), sem perder o que foi digitado no formulário. */
  async function atualizarAnexos() {
    const html = await (await fetch(location.href)).text();
    const doc  = new DOMParser().parseFromString(html, 'text/html');
    const g    = doc.getElementById('bloco-anexos');
    if (g) document.getElementById('bloco-anexos').innerHTML = g.innerHTML;
    document.querySelectorAll('#linhas-pagamento tr.fat-linha-principal[data-id]').forEach((tr) => {
      if (!tr.dataset.id) return;
      const novo = doc.querySelector('#linhas-pagamento tr.fat-linha-principal[data-id="' + tr.dataset.id + '"] .fat-pg-comprov');
      if (novo) tr.querySelector('.fat-pg-comprov').innerHTML = novo.innerHTML;
    });
  }

  async function chamarAnexo(acao, fd) {
    fd.append('csrf_token', CSRF_TOKEN);
    try {
      const r = await fetch('?module=faturamento&action=' + acao, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
      const d = await r.json();
      if (!d.ok) throw new Error(d.msg || 'Não foi possível concluir.');
      await atualizarAnexos();
      mostrarAviso(d.msg, true);
    } catch (e) {
      mostrarAviso(e.message, false);
    }
  }

  function enviarAnexo(tipo, pagamentoId, arquivo) {
    const fd = new FormData();
    fd.append('id', PEDIDO_ID);
    fd.append('tipo', tipo);
    fd.append('pagamento_id', pagamentoId || '');
    fd.append('arquivo', arquivo);
    return chamarAnexo('anexo_enviar', fd);
  }

  function removerAnexo(id) {
    if (!confirm('Remover este anexo?')) return;
    const fd = new FormData();
    fd.append('id', id);
    return chamarAnexo('anexo_remover', fd);
  }

  /* H1 — Salvar: grava os grupos A–D no servidor; só avisa em verde se o servidor confirmar. */
  async function salvarProposta(opts) {
    const silencioso = !!(opts && opts.silencioso);
    const campos = {
      filial_id: 'campo-filial', vendedor_id: 'campo-vendedor', tipo_venda: 'campo-tipo-venda', pedido_apollo: 'campo-pedido-apollo',
      cliente_nome: 'campo-cliente-nome', cliente_doc: 'campo-cliente-doc', cliente_endereco: 'campo-cliente-endereco',
      cliente_numero: 'campo-cliente-numero', cliente_cidade: 'campo-cliente-cidade', cliente_uf: 'campo-cliente-uf',
      cliente_telefone: 'campo-cliente-telefone', cliente_email: 'campo-cliente-email',
      veiculo_codigo: 'campo-veiculo-codigo', veiculo_modelo: 'campo-veiculo-modelo', veiculo_cor: 'campo-veiculo-cor',
      veiculo_ano_fab_mod: 'campo-veiculo-ano', veiculo_combustivel: 'campo-veiculo-combustivel',
      valor_veiculo: 'campo-valor-veiculo', valor_opcionais: 'campo-valor-opcionais', valor_frete: 'campo-valor-frete'
    };
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('id', PEDIDO_ID);
    Object.keys(campos).forEach((k) => fd.append(k, document.getElementById(campos[k]).value));
    fd.append('pagamentos', JSON.stringify(coletarPagamentos()));

    const btn = document.querySelector('[title="H1 · Salvar rascunho"]');
    btn.disabled = true;
    try {
      const r = await fetch('?module=faturamento&action=salvar', {
        method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd
      });
      const d = await r.json();
      if (!d.ok) { throw new Error(d.msg || 'Não foi possível salvar.'); }
      if (d.novo) { location.href = d.url; return false; }
      if (!silencioso) { irParaTopo(); mostrarAviso(d.msg, true); }
      return true;
    } catch (e) {
      irParaTopo();
      mostrarAviso(e.message, false);
      return false;
    } finally {
      btn.disabled = false;
    }
  }

  /* Recolhe/expande um quadro de dados (cliente, veículo). Os campos continuam no formulário e são salvos. */
  function alternarQuadro(btn) {
    const quadro = btn.closest('.fat-dados-auto');
    const recolhido = quadro.classList.toggle('fat-recolhido');
    btn.setAttribute('aria-expanded', recolhido ? 'false' : 'true');
    btn.title = recolhido ? 'Expandir' : 'Recolher';
  }

  /* C4 — cores do modelo escolhido; modelo sem cores cadastradas: lista padrão. Mantém a cor já escolhida se existir. */
  function repovoarCores(op) {
    const sel = document.getElementById('campo-veiculo-cor');
    const atual = sel.value;
    let lista = [];
    try { lista = JSON.parse(op.dataset.cores || '[]'); } catch (e) { lista = []; }
    if (!lista.length) lista = ['Cinza', 'Branco', 'Preto', 'Prata'];
    sel.innerHTML = '<option value="">Selecione…</option>';
    lista.forEach((c) => {
      const o = document.createElement('option');
      o.value = o.textContent = c;
      sel.appendChild(o);
    });
    if (atual && lista.includes(atual)) sel.value = atual;
  }

  /* C3 — ao escolher o modelo do cadastro: combustível e, se o valor ainda estiver vazio, o preço sugerido. */
  function aoEscolherModelo(sel) {
    const op = sel.selectedOptions[0];
    if (!op || !op.value) return;
    if (op.dataset.comb) definirSelect('campo-veiculo-combustivel', op.dataset.comb);
    repovoarCores(op);
    const v = document.getElementById('campo-valor-veiculo');
    if (op.dataset.preco && v.value.trim() === '') {
      definirCampo('campo-valor-veiculo', pgFmt(Number(op.dataset.preco)), true);
      mostrarAviso('Valor do veículo preenchido com o preço sugerido da tabela — ajuste se precisar.', true);
    }
  }

  /* ── A8 — traz do Apollo os dados do pedido ─────────────── */
  function definirSelect(id, valor) {
    const sel = document.getElementById(id);
    if (!sel || valor === null || valor === undefined || valor === '') return;
    if (![...sel.options].some((o) => o.value === String(valor))) {
      const op = document.createElement('option');
      op.value = op.textContent = String(valor);
      sel.appendChild(op);
    }
    sel.value = String(valor);
  }

  function definirCampo(id, valor, dispararInput) {
    const el = document.getElementById(id);
    if (!el || valor === null || valor === undefined) return;
    el.value = valor;
    if (dispararInput) el.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function aplicarPagamentosApollo(lista) {
    const tbody = document.getElementById('linhas-pagamento');
    const existentes = [...tbody.querySelectorAll('tr.fat-linha-principal')];
    const comDados = existentes.some((tr) => tr.querySelector('.js-pg-valor').value.trim() !== '');
    const jaSalvas = existentes.some((tr) => tr.dataset.id);
    if (comDados) {
      const aviso = 'Substituir as formas de pagamento atuais pelas do Apollo?' +
        (jaSalvas ? '\n\nAs linhas já salvas serão trocadas e os comprovantes ligados a elas perderão o vínculo.' : '');
      if (!confirm(aviso)) return false;
    }
    tbody.innerHTML = '';
    lista.forEach((pg) => {
      adicionarLinha();
      const tr  = [...tbody.querySelectorAll('tr.fat-linha-principal')].pop();
      const sel = tr.querySelector('.js-pg-tipo');
      if (pg.tipo_fat) { sel.value = pg.tipo_fat; sel.dispatchEvent(new Event('change')); }
      tr.querySelector('.js-pg-valor').value = pg.valor !== null ? pgFmt(Number(pg.valor)) : '';
      tr.querySelector('.js-pg-data').value  = pg.data || '';
    });
    if (!lista.length) adicionarLinha();
    renumerarLinhas();
    return true;
  }

  function preencherDoApollo(p) {
    const c = p.cliente || {}, v = p.veiculo || {}, val = p.valores || {};
    definirCampo('campo-cliente-nome', c.nome);       definirCampo('campo-cliente-doc', c.doc);
    definirCampo('campo-cliente-endereco', c.endereco); definirCampo('campo-cliente-numero', c.numero);
    definirCampo('campo-cliente-cidade', c.cidade);   definirCampo('campo-cliente-uf', c.uf);
    definirCampo('campo-cliente-telefone', c.telefone); definirCampo('campo-cliente-email', c.email);

    definirCampo('campo-veiculo-codigo', v.codigo);
    definirSelect('campo-veiculo-modelo', v.modelo);       definirSelect('campo-veiculo-cor', v.cor);
    definirSelect('campo-veiculo-ano', v.ano_fab_mod);     definirSelect('campo-veiculo-combustivel', v.combustivel);

    definirSelect('campo-tipo-venda', p.tipo_venda);
    const vend = document.getElementById('campo-vendedor');
    if (p.vendedor_id && !vend.disabled) vend.value = p.vendedor_id;

    const fmt = (n) => (n === null || n === undefined) ? '' : pgFmt(Number(n));
    definirCampo('campo-valor-veiculo', fmt(val.veiculo), true);
    definirCampo('campo-valor-opcionais', fmt(val.opcionais), true);
    definirCampo('campo-valor-frete', fmt(val.frete), true);

    if (Array.isArray(p.pagamentos) && p.pagamentos.length) aplicarPagamentosApollo(p.pagamentos);
    atualizarResumoPagamentos();
  }

  async function buscarPedidoApollo() {
    const filial = document.getElementById('campo-filial').value;
    const numero = document.getElementById('campo-pedido-apollo').value.trim();
    if (!filial) { mostrarAviso('Escolha a Empresa / Filial antes de buscar o pedido.', false); return; }
    if (!numero) { mostrarAviso('Informe o número do pedido do Apollo.', false); return; }

    const btn = document.querySelector('[title^="A8"]');
    btn.disabled = true;
    try {
      const r = await fetch('?module=faturamento&action=apollo_pedido&filial_id=' + encodeURIComponent(filial) +
        '&pedido=' + encodeURIComponent(numero), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const d = await r.json();
      if (!d.ok) throw new Error(d.msg || 'Não foi possível consultar o Apollo.');
      preencherDoApollo(d.pedido);
      const avisos = [d.pedido.vendedor_aviso, d.pedido.pagamentos_aviso].filter(Boolean).join(' ');
      mostrarAviso((d.pedido.simulado ? 'DADOS SIMULADOS (modo teste) carregados — confira e salve. ' : 'Dados do Apollo carregados — confira e salve. ') + avisos, !avisos);
    } catch (e) {
      mostrarAviso(e.message, false);
    } finally {
      btn.disabled = false;
    }
  }

  /* POST de navegação completa (Avançar/Devolver): o servidor responde com aviso e recarrega o pedido. */
  function postar(acao, campos) {
    const f = document.createElement('form');
    f.method = 'post';
    f.action = '?module=faturamento&action=' + acao;
    Object.entries(Object.assign({ csrf_token: CSRF_TOKEN, id: PEDIDO_ID }, campos || {})).forEach(([k, v]) => {
      const i = document.createElement('input');
      i.type = 'hidden'; i.name = k; i.value = v;
      f.appendChild(i);
    });
    document.body.appendChild(f);
    f.submit();
  }

  /* H2 — salva o que está na tela e dá o OK; o servidor confere as pendências da fase. */
  async function avancarPedido(nomeProxima) {
    if (!confirm('Dar o OK e avançar para ' + nomeProxima + '? Os dados da tela serão salvos antes.')) return;
    if (!(await salvarProposta({ silencioso: true }))) return;
    postar('avancar');
  }

  /* H — devolve à fase anterior, com motivo. */
  function devolverPedido(nomeAnterior) {
    const motivo = prompt('Motivo da devolução para ' + nomeAnterior + ':');
    if (motivo === null) return;
    if (motivo.trim().length < 3) { mostrarAviso('Informe o motivo da devolução.', false); return; }
    postar('devolver', { motivo: motivo.trim() });
  }

  /* Volta do "Novo pedido": a página recarrega no pedido criado e avisa. */
  if (new URLSearchParams(location.search).get('salvo') === '1') {
    mostrarAviso('Proposta salva com sucesso', true);
    history.replaceState(null, '', location.pathname + location.search.replace(/[?&]salvo=1/, ''));
  }

  /* Grupo D — Total = Valor do veículo + Opcionais + Frete (formato pt-BR). */
  (function () {
    const ids = ['campo-valor-veiculo', 'campo-valor-opcionais', 'campo-valor-frete'];
    const num = (txt) => {
      const n = parseFloat(String(txt).replace(/\./g, '').replace(',', '.'));
      return isNaN(n) ? 0 : n;
    };
    const fmt = (n) => n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const recalcular = () => {
      document.getElementById('campo-valor-total').value =
        fmt(ids.reduce((soma, id) => soma + num(document.getElementById(id).value), 0));
    };
    ids.forEach((id) => {
      const el = document.getElementById(id);
      el.addEventListener('input', recalcular);
      el.addEventListener('blur', () => { el.value = fmt(num(el.value)); recalcular(); });
    });
    recalcular();
  })();

  /**
   * O botão E6 existe em toda linha. O que ele abre depende do Tipo:
   * "troca" mostra os dados do veículo entregue; qualquer outro tipo
   * mostra os dados de quem está pagando (pode ser um terceiro).
   */
  function onTipoChange(sel) {
    const tr = sel.closest('tr');
    const detalhe = tr.nextElementSibling;
    if (!detalhe || !detalhe.classList.contains('fat-linha-terceiro')) return;
    const ehTroca = sel.value === 'Troca';
    detalhe.querySelector('.fat-sub-pagador').style.display = ehTroca ? 'none' : '';
    detalhe.querySelector('.fat-sub-veiculo').style.display = ehTroca ? '' : 'none';
    atualizarE6(tr);
  }

  /**
   * Colore o botão E6: cinza (vazio) enquanto o campo-chave do sub-bloco
   * visível não foi preenchido, verde (.ok) assim que ele tem valor.
   */
  function atualizarE6(tr) {
    const detalhe = tr.nextElementSibling;
    const btn = tr.querySelector('.fat-btn-terceiro');
    if (!detalhe || !btn) return;
    const ehTroca = tr.querySelector('td:nth-child(2) select').value === 'Troca';
    const campoChave = ehTroca
      ? detalhe.querySelector('.fat-sub-veiculo .fat-busca-group input')
      : detalhe.querySelector('.fat-sub-pagador .fat-busca-group input');
    btn.classList.toggle('ok', !!(campoChave && campoChave.value.trim() !== ''));
  }

  function onTipoPessoaTerceiro(sel) {
    const bloco = sel.closest('.fat-sub-pagador').querySelector('.fat-bloco-representante-pagador');
    bloco.style.display = (sel.value === 'pj') ? '' : 'none';
  }

  function onDonoVeiculoChange(chk) {
    const bloco = chk.closest('.fat-sub-veiculo').querySelector('.fat-bloco-dono-veiculo');
    bloco.style.display = chk.checked ? '' : 'none';
  }

  function onTipoPessoaDonoVeiculo(sel) {
    const bloco = sel.closest('.fat-bloco-dono-veiculo').querySelector('.fat-bloco-representante-veiculo');
    bloco.style.display = (sel.value === 'pj') ? '' : 'none';
  }

  /* ── Modal B1 — buscar/cadastrar cliente ─────────────── */
  function abrirModalCliente() {
    document.getElementById('modal-cliente').classList.add('aberto');
    document.getElementById('modal-estado-busca').style.display = '';
    document.getElementById('modal-estado-cadastro').style.display = 'none';
    document.getElementById('modal-resultados').style.display = 'none';
    document.getElementById('modal-busca-nome').value = '';
    document.getElementById('modal-busca-doc').value = '';
  }

  function fecharModalCliente() {
    document.getElementById('modal-cliente').classList.remove('aberto');
  }

  function buscarCliente() {
    document.getElementById('modal-resultados').style.display = '';
  }

  function selecionarCliente(nome, doc, endereco, numero, cidade, uf) {
    document.getElementById('campo-cliente-nome').value = nome;
    document.getElementById('campo-cliente-doc').value = doc;
    document.getElementById('campo-cliente-endereco').value = endereco;
    document.getElementById('campo-cliente-numero').value = numero;
    document.getElementById('campo-cliente-cidade').value = cidade;
    document.getElementById('campo-cliente-uf').value = uf;
    fecharModalCliente();
  }

  function abrirCadastroCliente() {
    document.getElementById('cad-nome').value = document.getElementById('modal-busca-nome').value;
    document.getElementById('cad-doc').value = document.getElementById('modal-busca-doc').value;
    document.getElementById('modal-estado-busca').style.display = 'none';
    document.getElementById('modal-estado-cadastro').style.display = '';
  }

  function voltarBusca() {
    document.getElementById('modal-estado-cadastro').style.display = 'none';
    document.getElementById('modal-estado-busca').style.display = '';
  }

  function salvarCadastroCliente() {
    const nome = document.getElementById('cad-nome').value;
    const doc = document.getElementById('cad-doc').value;
    const endereco = document.getElementById('cad-endereco').value;
    const numero = document.getElementById('cad-numero').value;
    const cidade = document.getElementById('cad-cidade').value;
    selecionarCliente(nome || 'Novo cliente', doc, endereco, numero, cidade, '');
  }

  /* ── Modal C1 — buscar veículo no Apollo (sem opção de cadastrar) ── */
  function abrirModalVeiculo() {
    document.getElementById('modal-veiculo').classList.add('aberto');
    document.getElementById('modal-resultados-veiculo').style.display = 'none';
    document.getElementById('modal-busca-veiculo-codigo').value = '';
    document.getElementById('modal-busca-veiculo-placa').value = '';
    document.getElementById('modal-busca-veiculo-chassi').value = '';
  }

  function fecharModalVeiculo() {
    document.getElementById('modal-veiculo').classList.remove('aberto');
  }

  function buscarVeiculo() {
    document.getElementById('modal-resultados-veiculo').style.display = '';
  }

  function selecionarVeiculo(codigo, modelo, cor, ano, combustivel) {
    document.getElementById('campo-veiculo-codigo').value = codigo;
    selecionarOpcao('campo-veiculo-modelo', modelo);
    selecionarOpcao('campo-veiculo-cor', cor);
    selecionarOpcao('campo-veiculo-ano', ano);
    selecionarOpcao('campo-veiculo-combustivel', combustivel);
    fecharModalVeiculo();
  }

  /** Marca a option cujo texto bate com `valor` num <select> — usado ao preencher via busca. */
  function selecionarOpcao(idSelect, valor) {
    const select = document.getElementById(idSelect);
    for (const opt of select.options) {
      if (opt.text === valor) { select.value = opt.value; return; }
    }
  }

  function toggleTerceiro(btn) {
    const tr = btn.closest('tr');
    const next = tr.nextElementSibling;
    if (next && next.classList.contains('fat-linha-terceiro')) {
      next.style.display = (next.style.display === 'none') ? '' : 'none';
    }
  }

  /** Nova linha de pagamento: clona o molde renderizado pelo servidor (mesmo HTML das linhas gravadas). */
  function adicionarLinha() {
    const tbody = document.getElementById('linhas-pagamento');
    tbody.appendChild(document.getElementById('tpl-pagamento').content.cloneNode(true));
    renumerarLinhas();
  }

  function renumerarLinhas() {
    document.querySelectorAll('#linhas-pagamento tr.fat-linha-principal').forEach((tr, i) => {
      tr.querySelector('.fat-pg-num').textContent = i + 1;
    });
    atualizarResumoPagamentos();
  }

  /* "1.234,56" → 1234.56 ; 1234.56 → "1.234,56" */
  function pgNum(txt) {
    const n = parseFloat(String(txt).replace(/\./g, '').replace(',', '.'));
    return isNaN(n) ? 0 : n;
  }
  function pgFmt(n) {
    return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  /* Quanto já está coberto pelas formas de pagamento em relação ao Total (D3). */
  function atualizarResumoPagamentos() {
    const el = document.getElementById('resumo-pagamentos');
    if (!el) return;
    let soma = 0;
    document.querySelectorAll('#linhas-pagamento tr.fat-linha-principal .js-pg-valor').forEach((i) => { soma += pgNum(i.value); });
    const total = pgNum(document.getElementById('campo-valor-total').value);
    const dif = Math.round((total - soma) * 100) / 100;
    let txt = 'Formas de pagamento: R$ ' + pgFmt(soma) + ' de R$ ' + pgFmt(total);
    let cor = '#606060';
    if (total > 0 && dif > 0)      { txt += ' — faltam R$ ' + pgFmt(dif); cor = '#B3261E'; }
    else if (total > 0 && dif < 0) { txt += ' — excedem R$ ' + pgFmt(-dif); cor = '#B3261E'; }
    else if (total > 0)            { txt += ' — fechado'; cor = '#00A960'; }
    el.textContent = txt;
    el.style.color = cor;
  }

  /* Monta o JSON das linhas (E) para enviar ao servidor. */
  function coletarPagamentos() {
    return Array.from(document.querySelectorAll('#linhas-pagamento tr.fat-linha-principal')).map((tr) => {
      const detalhe = tr.nextElementSibling;
      const dados = {};
      detalhe.querySelectorAll('[data-k]').forEach((el) => {
        const partes = el.dataset.k.split('.');
        let alvo = dados;
        partes.slice(0, -1).forEach((p) => { alvo = (alvo[p] = alvo[p] || {}); });
        alvo[partes[partes.length - 1]] = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;
      });
      return {
        id: tr.dataset.id || '',
        tipo: tr.querySelector('.js-pg-tipo').value,
        valor: tr.querySelector('.js-pg-valor').value,
        data: tr.querySelector('.js-pg-data').value,
        dados: dados
      };
    });
  }

  /** Remove a linha de pagamento e a linha de detalhe (E6) que a acompanha. */
  function removerLinha(btn) {
    const tr = btn.closest('tr');
    const detalhe = tr.nextElementSibling;
    if (detalhe && detalhe.classList.contains('fat-linha-terceiro')) detalhe.remove();
    tr.remove();
    renumerarLinhas();
  }

  // Recalcula a cor do E6 sempre que o usuário digita em qualquer campo do bloco.
  document.getElementById('linhas-pagamento').addEventListener('input', function (e) {
    const detalhe = e.target.closest('tr.fat-linha-terceiro');
    if (!detalhe) return;
    const principal = detalhe.previousElementSibling;
    if (principal) atualizarE6(principal);
  });

  // Valores monetários das linhas: formata ao sair do campo e atualiza o resumo.
  document.getElementById('linhas-pagamento').addEventListener('focusout', function (e) {
    if (e.target.classList.contains('js-pg-valor') && e.target.value.trim() !== '') {
      e.target.value = pgFmt(pgNum(e.target.value));
    }
    atualizarResumoPagamentos();
  });
  document.addEventListener('input', function (e) {
    if (e.target.classList.contains('js-pg-valor') || e.target.classList.contains('js-valor')) atualizarResumoPagamentos();
  });

  // Estado inicial: E6 verde nas linhas que já têm dados do pagador/veículo.
  document.querySelectorAll('#linhas-pagamento tr.fat-linha-principal').forEach(atualizarE6);
  atualizarResumoPagamentos();
</script>
