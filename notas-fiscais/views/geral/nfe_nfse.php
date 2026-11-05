<?php
// @versao Ger_08 V_19_01/10/2026
/**
 * views/geral/nfe_nfse.php — aba "NF-e / NFS-e" do módulo Geral.
 *
 * Captura direta do Portal Nacional/ADN — sem Brobot. Prova de conceito
 * validada em 16/09/2026 (NFSe Nacional em homologação, NF-e em produção).
 *
 * Certificado ÚNICO (matriz) — confirmado em 16/09/2026 que um certificado
 * emitido pra matriz cobre qualquer filial da mesma raiz de CNPJ, tanto na
 * distribuição de NF-e (SOAP, tag <CNPJ> separada do certificado) quanto na
 * do ADN/NFSe Nacional (REST, parâmetro `cnpjConsulta`). Por isso o
 * certificado é configurado uma vez só aqui; por filial só entra o
 * liga/desliga (NF-e/NFS-e) e a janela de busca.
 *
 * Variáveis: $filiaisDist, $cfg (via $g)
 */
$filiaisDist = $filiaisDist ?? [];
$certificadosMatriz = $certificadosMatriz ?? [];

$fmtCnpjRaiz = function (string $raiz): string {
    return substr($raiz, 0, 2) . '.' . substr($raiz, 2, 3) . '.' . substr($raiz, 5, 3);
};

$statusBadge = function (?string $status): string {
    $map = [
        'ok'        => ['#D8F5E5', '#00A960', 'OK'],
        'parcial'   => ['#FFF3E0', '#FF8A2D', 'Parcial'],
        'erro'      => ['#FFE8EE', '#B3261E', 'Erro'],
        'bloqueado' => ['#FFE8EE', '#B3261E', 'Bloqueado 1h'],
    ];
    [$bg, $fg, $lbl] = $map[$status ?? ''] ?? ['#EAEAEA', '#888', 'Sem execução'];
    return "<span style=\"font-size:10px; font-weight:700; background:{$bg}; color:{$fg}; padding:2px 8px; border-radius:8px\">{$lbl}</span>";
};

// $certInfo vem de CertificadoPfx::inspecionar() — null quando não há
// certificado nenhum pra testar (nem próprio, nem padrão configurado).
$validadeBadge = function (?array $certInfo): string {
    if ($certInfo === null) {
        return '<span style="font-size:12px; color:#BBB">—</span>';
    }
    if (!$certInfo['ok']) {
        return '<span style="font-size:10px; font-weight:700; background:#FFE8EE; color:#B3261E; padding:2px 8px; border-radius:8px" title="'
            . h((string) $certInfo['erro']) . '">Senha inválida</span>';
    }
    if (empty($certInfo['validade'])) {
        return '<span style="font-size:11px; color:#888">Senha OK</span>';
    }
    $ts   = strtotime($certInfo['validade']);
    $dias = (int) floor(($ts - time()) / 86400);
    if ($dias < 0) {
        [$bg, $fg, $lbl] = ['#FFE8EE', '#B3261E', 'Vencido'];
    } elseif ($dias <= 30) {
        [$bg, $fg, $lbl] = ['#FFF3E0', '#FF8A2D', 'Vence em breve'];
    } else {
        [$bg, $fg, $lbl] = ['#D8F5E5', '#00A960', 'OK'];
    }
    return '<div><span style="font-size:10px; font-weight:700; background:' . $bg . '; color:' . $fg . '; padding:2px 8px; border-radius:8px; white-space:nowrap">' . $lbl . '</span>'
        . '<div style="color:#606060; font-size:11px; margin-top:3px">' . h(date('d/m/Y', $ts)) . '</div></div>';
};
?>

<div style="margin-bottom:20px">
  <h2 style="font-size:20px; font-weight:700; color:#141922; margin:0 0 4px">NF-e / NFS-e <span style="color:#2347C8">Portal Nacional</span></h2>
</div>

<!-- Filiais -->
<div style="background:#fff; border-radius:12px; box-shadow:rgba(0,0,0,0.10) 0 2px 8px; overflow:hidden">
  <?php if (empty($filiaisDist)): ?>
  <div style="padding:32px; text-align:center; color:#888; font-size:13px">Nenhuma filial ativa.</div>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table style="width:100%; border-collapse:collapse; font-size:13px; table-layout:fixed">
      <thead>
        <tr style="background:#606060; color:#fff; text-align:left">
          <th style="padding:8px 12px; font-weight:700; width:220px">Filial</th>
          <th style="padding:8px 12px; font-weight:700; text-align:center; width:70px">NF-e</th>
          <th style="padding:8px 12px; font-weight:700; text-align:center; width:70px">NFS-e</th>
          <th style="padding:8px 12px; font-weight:700; width:110px">Janela de busca</th>
          <th style="padding:8px 12px; font-weight:700; width:90px">Certificado</th>
          <th style="padding:8px 12px; font-weight:700; width:130px">Validade</th>
          <th style="padding:8px 12px; font-weight:700; width:130px">Última NF-e</th>
          <th style="padding:8px 12px; font-weight:700; width:130px">Última NFS-e</th>
          <th style="padding:8px 12px; font-weight:700; text-align:right; width:80px">Qtd NF-e</th>
          <th style="padding:8px 12px; font-weight:700; text-align:right; width:80px">Qtd NFS-e</th>
          <th style="padding:8px 12px; font-weight:700; text-align:right; width:80px">Total NF</th>
          <th style="padding:8px 12px; font-weight:700; width:70px; text-align:right">Ação</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($filiaisDist as $i => $f): ?>
        <tr style="background:<?= $i % 2 ? '#F8F8F8' : '#fff' ?>; border-bottom:1px solid #EAEAEA">
          <td style="padding:8px 12px; color:#141922">
            <div style="font-weight:700"><?= h($f['nome']) ?></div>
            <div style="color:#888; font-size:11px; font-family:Consolas,monospace"><?= h($f['cnpj']) ?></div>
          </td>
          <td style="padding:8px 12px; text-align:center">
            <?= (int) $f['nfe_dist_ativo'] === 1
              ? '<span style="color:#00A960" title="Ligado"><i data-lucide="check-circle-2" width="16" height="16"></i></span>'
              : '<span style="color:#BBB" title="Desligado"><i data-lucide="circle" width="16" height="16"></i></span>' ?>
          </td>
          <td style="padding:8px 12px; text-align:center">
            <?= (int) $f['adn_nfse_ativo'] === 1
              ? '<span style="color:#00A960" title="Ligado"><i data-lucide="check-circle-2" width="16" height="16"></i></span>'
              : '<span style="color:#BBB" title="Desligado"><i data-lucide="circle" width="16" height="16"></i></span>' ?>
          </td>
          <td style="padding:8px 12px; color:#606060; font-family:Consolas,monospace">
            <?= h(substr((string) $f['dist_horario_inicio'], 0, 5)) ?>–<?= h(substr((string) $f['dist_horario_fim'], 0, 5)) ?>
            <?php if (!empty($f['dist_intervalo_min'])): ?>
            <div style="color:#888; font-size:10px; margin-top:2px">a cada <?= (int) $f['dist_intervalo_min'] ?>min</div>
            <?php endif; ?>
          </td>
          <td style="padding:8px 12px">
            <?php if (!empty($f['dist_cert_path'])): ?>
            <span style="font-size:10px; font-weight:700; background:#D8D4E4; color:#606060; padding:2px 8px; border-radius:8px" title="Usa certificado próprio, não o da matriz">Próprio</span>
            <?php else: ?>
            <span style="font-size:10px; color:#888" title="Usa o certificado padrão de reserva">padrão</span>
            <?php endif; ?>
          </td>
          <td style="padding:8px 12px"><?= $validadeBadge($f['cert_info'] ?? null) ?></td>
          <td style="padding:8px 12px">
            <?= $statusBadge($f['nfe_status']) ?>
            <?php if (!empty($f['nfe_quando'])): ?>
            <div style="color:#888; font-size:10px; margin-top:3px"><?= h(date('d/m H:i', strtotime((string) $f['nfe_quando']))) ?></div>
            <?php endif; ?>
          </td>
          <td style="padding:8px 12px">
            <?= $statusBadge($f['adn_status']) ?>
            <?php if (!empty($f['adn_quando'])): ?>
            <div style="color:#888; font-size:10px; margin-top:3px"><?= h(date('d/m H:i', strtotime((string) $f['adn_quando']))) ?></div>
            <?php endif; ?>
          </td>
          <?php $qtdNfe = (int) $f['qtd_nfe']; $qtdNfse = (int) $f['qtd_nfse']; ?>
          <td style="padding:8px 12px; text-align:right; color:#141922; font-weight:700"><?= number_format($qtdNfe, 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#141922; font-weight:700"><?= number_format($qtdNfse, 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#2347C8; font-weight:700"><?= number_format($qtdNfe + $qtdNfse, 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right">
            <button type="button" class="btn-edit-dist"
                    data-id="<?= h($f['id']) ?>" data-nome="<?= h($f['nome']) ?>"
                    data-nfe="<?= (int) $f['nfe_dist_ativo'] ?>" data-nfse="<?= (int) $f['adn_nfse_ativo'] ?>"
                    data-horario-inicio="<?= h(substr((string) $f['dist_horario_inicio'], 0, 5)) ?>"
                    data-horario-fim="<?= h(substr((string) $f['dist_horario_fim'], 0, 5)) ?>"
                    data-intervalo="<?= h((string) ($f['dist_intervalo_min'] ?? '')) ?>"
                    data-cert="<?= !empty($f['dist_cert_path']) ? '1' : '0' ?>"
                    style="width:30px; height:30px; border-radius:4px; border:1px solid #EAEAEA; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#888"
                    title="Configurar" onmouseover="this.style.color='#2347C8';this.style.borderColor='#2347C8'" onmouseout="this.style.color='#888';this.style.borderColor='#EAEAEA'">
              <i data-lucide="settings-2" width="14" height="14"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php $totalNfe = array_sum(array_column($filiaisDist, 'qtd_nfe')); $totalNfse = array_sum(array_column($filiaisDist, 'qtd_nfse')); ?>
      <tfoot>
        <tr style="background:#F6F6F6; border-top:2px solid #EAEAEA">
          <td colspan="8" style="padding:8px 12px; color:#606060; font-weight:700; text-align:right">Total</td>
          <td style="padding:8px 12px; text-align:right; color:#141922; font-weight:700"><?= number_format($totalNfe, 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#141922; font-weight:700"><?= number_format($totalNfse, 0, ',', '.') ?></td>
          <td style="padding:8px 12px; text-align:right; color:#2347C8; font-weight:700"><?= number_format($totalNfe + $totalNfse, 0, ',', '.') ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<div style="height:32px"></div>

<!-- Notas capturadas por mês (NF-e/NFS-e direto da SEFAZ/ADN) -->
<?php
$capPorMes    = $nfePorMes ?? [];
$capDescricao = '';
$capVazio     = 'Nenhuma nota capturada até agora.';
$capComOrigem = true;
include VIEWS . '/geral/capturas_por_mes.php';
?>

<!-- Certificados por matriz (raiz de CNPJ) -->
<div style="<?= $cardStyle ?>; max-width:820px; margin-bottom:20px">
  <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:4px">
    <div>
      <h3 style="font-size:15px; font-weight:700; color:#141922; margin:0 0 4px">Certificados por matriz</h3>
      <p style="font-size:13px; color:#888; margin:0 0 16px; max-width:600px">
        O grupo tem mais de uma raiz de CNPJ (empresas separadas) — cada uma precisa do próprio certificado,
        já que um certificado emitido pra uma raiz não autentica consulta de outra.
      </p>
    </div>
    <button type="button" onclick="document.getElementById('modal-add-cert-matriz').style.display='flex'"
            style="height:36px; padding:0 16px; background:#2347C8; color:#fff; border:none; border-radius:4px; font-size:13px; font-weight:700; cursor:pointer; white-space:nowrap; flex-shrink:0">
      + Adicionar
    </button>
  </div>

  <?php if (empty($certificadosMatriz)): ?>
  <div style="padding:20px; text-align:center; color:#888; font-size:13px; background:#F6F6F6; border-radius:8px">
    Nenhuma matriz cadastrada ainda — clique em "+ Adicionar".
  </div>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table style="width:100%; border-collapse:collapse; font-size:13px">
      <thead>
        <tr style="background:#606060; color:#fff; text-align:left">
          <th style="padding:8px 12px; font-weight:700">CNPJ Matriz</th>
          <th style="padding:8px 12px; font-weight:700">Rótulo</th>
          <th style="padding:8px 12px; font-weight:700">Filiais que usam</th>
          <th style="padding:8px 12px; font-weight:700; text-align:center; width:110px">Certificado</th>
          <th style="padding:8px 12px; font-weight:700; width:120px">Validade</th>
          <th style="padding:8px 12px; font-weight:700; width:70px; text-align:right">Ação</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($certificadosMatriz as $i => $c): ?>
        <tr style="background:<?= $i % 2 ? '#F8F8F8' : '#fff' ?>; border-bottom:1px solid #EAEAEA">
          <td style="padding:8px 12px; color:#141922; font-family:Consolas,monospace"><?= h($fmtCnpjRaiz($c['cnpj_raiz'])) ?></td>
          <td style="padding:8px 12px; color:#606060"><?= h($c['nome'] ?: '—') ?></td>
          <td style="padding:8px 12px; color:#606060"><?= h($c['filiais'] ? implode(', ', $c['filiais']) : '—') ?></td>
          <td style="padding:8px 12px; text-align:center">
            <?php if (!empty($c['cert_path'])): ?>
            <span style="font-size:10px; font-weight:700; background:#D8F5E5; color:#00A960; padding:2px 8px; border-radius:8px">Configurado</span>
            <?php else: ?>
            <span style="font-size:10px; font-weight:700; background:#FFE8EE; color:#B3261E; padding:2px 8px; border-radius:8px">Não configurado</span>
            <?php endif; ?>
          </td>
          <td style="padding:8px 12px"><?= $validadeBadge($c['cert_info'] ?? null) ?></td>
          <td style="padding:8px 12px; text-align:right">
            <form method="post" action="?module=geral&amp;action=remover_certificado_matriz"
                  onsubmit="return confirm('Remover o certificado da matriz <?= h($fmtCnpjRaiz($c['cnpj_raiz'])) ?>?')" style="display:inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= h($c['id']) ?>">
              <button type="submit" title="Remover"
                      style="width:30px; height:30px; border-radius:4px; border:1px solid #EAEAEA; background:#fff; cursor:pointer; color:#888">
                <i data-lucide="trash-2" width="14" height="14"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Modal: adicionar certificado de matriz -->
<div id="modal-add-cert-matriz" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:100; align-items:center; justify-content:center">
  <form method="post" action="?module=geral&amp;action=salvar_certificado_matriz" enctype="multipart/form-data"
        style="background:#fff; border-radius:12px; padding:24px; width:100%; max-width:420px; margin:20px">
    <?= Csrf::field() ?>
    <h3 style="font-size:16px; font-weight:700; color:#141922; margin:0 0 16px">Adicionar certificado de matriz</h3>

    <div style="margin-bottom:14px">
      <label style="<?= $lblStyle ?>">CNPJ da matriz <span style="font-weight:400; color:#888">(raiz — 8 primeiros dígitos)</span></label>
      <input type="text" name="cnpj_raiz" required maxlength="18" placeholder="00.000.000" style="<?= $inpStyle ?>">
      <div style="<?= $hintStyle ?>">Se já existir certificado pra essa raiz, os campos abaixo atualizam o que já tem.</div>
    </div>
    <div style="margin-bottom:14px">
      <label style="<?= $lblStyle ?>">Rótulo <span style="font-weight:400; color:#888">(opcional)</span></label>
      <input type="text" name="nome" maxlength="150" placeholder="Ex.: Exemplo" style="<?= $inpStyle ?>">
    </div>
    <div style="margin-bottom:14px">
      <label style="<?= $lblStyle ?>">Arquivo <span style="font-weight:400; color:#888">(.pfx/.p12)</span></label>
      <input type="file" name="cert" accept=".pfx,.p12" style="<?= $inpStyle ?>; padding-top:8px">
    </div>
    <div style="margin-bottom:20px">
      <label style="<?= $lblStyle ?>">Senha do certificado</label>
      <input type="password" name="cert_senha" placeholder="deixe em branco para manter" style="<?= $inpStyle ?>">
    </div>

    <div style="display:flex; gap:8px; justify-content:flex-end">
      <button type="button" onclick="document.getElementById('modal-add-cert-matriz').style.display='none'"
              style="padding:9px 18px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; font-size:13px; font-weight:700; cursor:pointer">
        Cancelar
      </button>
      <button type="submit" style="<?= $btnSalvar ?>">Salvar</button>
    </div>
  </form>
</div>

<!-- Agendamento (Linux) -->
<div style="<?= $cardStyle ?>; max-width:760px; margin-top:20px; background:#F6F6F6">
  <h3 style="font-size:15px; font-weight:700; color:#141922; margin:0 0 12px">Agendamento (Linux/cron)</h3>
  <p style="font-size:12.5px; color:#606060; margin:0 0 12px">
    Diferente da Brobot (agenda local no Windows do dev), isso roda direto nos servidores (Server_novo/Server-APP),
    que são Linux. Registre no <code>crontab</code> do usuário do site — o caminho abaixo já reflete onde este
    código está rodando agora.
  </p>
  <pre style="background:#141922; color:#7EE787; font-size:12px; line-height:1.7; padding:14px 16px; border-radius:8px; overflow:auto; margin:0 0 12px; white-space:pre-wrap">*/5 * * * * php <?= h(ROOT) ?>/cli/adn_sync.php      >> <?= h((defined('STORAGE') ? STORAGE : ROOT . '/storage')) ?>/logs/adn_sync.log 2>&1
*/5 * * * * php <?= h(ROOT) ?>/cli/nfe_dist_sync.php >> <?= h((defined('STORAGE') ? STORAGE : ROOT . '/storage')) ?>/logs/nfe_dist_sync.log 2>&1</pre>
  <p style="font-size:12px; color:#888; margin:0">
    O lock (<code>flock</code>) em cada CLI já impede sobreposição — rodar a cada 5 min é seguro mesmo se uma
    execução demorar mais que isso. Para a NF-e especificamente, prefira <b>janelas estreitas</b> por filial
    (15–30 min, não o dia inteiro) — é a janela que limita quantas vezes por dia a SEFAZ é consultada, não o
    cron; janela larga + cron de 5 em 5 min foi o que gerou o bloqueio de "consumo indevido" nos testes de hoje.
  </p>
</div>

<!-- Modal: Configurar filial (liga/desliga + janela) -->
<div id="modal-dist" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center">
  <div style="background:#fff; border-radius:12px; width:100%; max-width:420px; margin:20px; box-shadow:0 8px 32px rgba(0,0,0,0.2)">
    <div style="padding:20px 24px 0; display:flex; align-items:center; justify-content:space-between">
      <h3 id="modal-dist-titulo" style="font-size:18px; font-weight:700; color:#141922; margin:0">Configurar filial</h3>
      <button type="button" onclick="document.getElementById('modal-dist').style.display='none'" style="background:none; border:none; cursor:pointer; color:#888; font-size:20px">×</button>
    </div>
    <form method="post" action="?module=geral&amp;action=salvar_filial_dist" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" id="d-id" value="">
      <div style="padding:20px 24px">
        <div style="display:flex; gap:24px; margin-bottom:18px">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px; font-weight:700; color:#141922">
            <input type="checkbox" name="nfe_dist_ativo" id="d-nfe" value="1" style="accent-color:#2347C8; width:16px; height:16px"> NF-e
          </label>
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px; font-weight:700; color:#141922">
            <input type="checkbox" name="adn_nfse_ativo" id="d-nfse" value="1" style="accent-color:#2347C8; width:16px; height:16px"> NFS-e
          </label>
        </div>

        <div style="margin-bottom:18px">
          <label style="<?= $lblStyle ?>">Janela de busca</label>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; max-width:280px">
            <div>
              <input type="time" name="dist_horario_inicio" id="d-horario-inicio" style="<?= $inpStyle ?>">
              <div style="font-size:10px; color:#888; margin-top:3px">Início</div>
            </div>
            <div>
              <input type="time" name="dist_horario_fim" id="d-horario-fim" style="<?= $inpStyle ?>">
              <div style="font-size:10px; color:#888; margin-top:3px">Fim</div>
            </div>
          </div>
          <div style="<?= $hintStyle ?>">O poller só roda esta filial dentro da janela. Se o fim for menor que o início, a janela cruza a meia-noite. Espalhe as filiais para não concentrar chamadas no mesmo horário.</div>
        </div>

        <div style="margin-bottom:18px; max-width:180px">
          <label style="<?= $lblStyle ?>">Intervalo mínimo <span style="font-weight:400; color:#888">(opcional)</span></label>
          <div style="position:relative">
            <input type="number" name="dist_intervalo_min" id="d-intervalo" min="0" step="1" placeholder="sem intervalo" style="<?= $inpStyle ?>">
            <span style="position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:12px; color:#888">min</span>
          </div>
          <div style="<?= $hintStyle ?>">A janela sozinha só roda uma vez por dia. Preencha aqui pra repetir dentro da janela — ex.: janela 02:00–22:00 + intervalo 60 = de hora em hora. Vazio = roda a cada tick do cron (5 min), só uma vez por dia se a janela for estreita.</div>
        </div>

        <div style="height:1px; background:#EAEAEA; margin:18px 0"></div>

        <div style="margin-bottom:6px; display:flex; align-items:center; justify-content:space-between; gap:8px">
          <label style="<?= $lblStyle ?>; margin-bottom:0">Certificado próprio <span style="font-weight:400; color:#888">(só se a raiz do CNPJ for diferente da matriz)</span></label>
        </div>
        <div id="d-cert-status" style="font-size:12px; color:#888; margin-bottom:8px"></div>
        <div style="margin-bottom:14px">
          <input type="file" name="dist_cert" accept=".pfx,.p12" style="<?= $inpStyle ?>; padding-top:8px">
          <div style="<?= $hintStyle ?>">Envie um novo arquivo pra substituir o atual — deixe em branco pra manter (ou pra continuar usando o padrão da matriz, se não tiver nenhum).</div>
        </div>
        <div>
          <input type="password" name="dist_cert_senha" id="d-senha" value="" placeholder="senha do certificado próprio" style="<?= $inpStyle ?>">
        </div>
      </div>
      <div style="padding:0 24px 20px; display:flex; gap:8px; justify-content:space-between; align-items:center">
        <button type="submit" form="form-remover-cert-filial" id="d-btn-remover-cert"
                style="display:none; padding:9px 14px; background:none; border:1px solid #B3261E; color:#B3261E; border-radius:4px; font-size:13px; font-weight:700; cursor:pointer">
          Remover certificado
        </button>
        <div style="display:flex; gap:8px; margin-left:auto">
          <button type="button" onclick="document.getElementById('modal-dist').style.display='none'" style="padding:10px 20px; background:#EAEAEA; color:#606060; border:none; border-radius:4px; font-size:14px; font-weight:700; cursor:pointer">Cancelar</button>
          <button type="submit" style="<?= $btnSalvar ?>">Salvar</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Form separado (fora do form de salvar) pra remover o certificado próprio
     da filial aberta no modal acima — botão fica dentro do modal via atributo
     form="", mas o submit é deste form, não do de salvar. -->
<form method="post" action="?module=geral&amp;action=remover_certificado_filial" id="form-remover-cert-filial"
      onsubmit="return confirm('Remover o certificado próprio desta filial? Ela volta a usar o certificado da matriz/padrão.')">
  <?= Csrf::field() ?>
  <input type="hidden" name="id" id="d-remover-cert-id" value="">
</form>

<script>
document.querySelectorAll('.btn-edit-dist').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('modal-dist-titulo').textContent = 'Configurar — ' + this.dataset.nome;
    document.getElementById('d-id').value      = this.dataset.id;
    document.getElementById('d-nfe').checked   = this.dataset.nfe === '1';
    document.getElementById('d-nfse').checked  = this.dataset.nfse === '1';
    document.getElementById('d-horario-inicio').value = this.dataset.horarioInicio || '00:00';
    document.getElementById('d-horario-fim').value     = this.dataset.horarioFim || '06:00';
    document.getElementById('d-intervalo').value = this.dataset.intervalo || '';
    document.getElementById('d-senha').value = '';
    document.getElementById('d-cert-status').textContent = this.dataset.cert === '1'
      ? 'Certificado próprio já configurado — envie um novo arquivo só se for trocar.'
      : 'Sem certificado próprio — usando o padrão da matriz.';
    document.getElementById('d-remover-cert-id').value = this.dataset.id;
    document.getElementById('d-btn-remover-cert').style.display = this.dataset.cert === '1' ? 'block' : 'none';
    document.getElementById('modal-dist').style.display = 'flex';
  });
});
</script>
