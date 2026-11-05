<?php
declare(strict_types=1);

session_start();
require 'conexao_inventario.php';
require 'recontagem_lib.php';
require 'conferentes_lib.php';

// grupos de coletores definidos na tela do gestor (gerador de inventário)
$coletoresGrupos = $_SESSION['coletores_grupos'] ?? [];
$qtColetores     = count($coletoresGrupos); // 0 se não tiver nenhum

// -----------------------------------------------------------------------
// Busca INVENTÁRIOS ABERTOS com dados da loja
// -----------------------------------------------------------------------
$sqlInventarios = "
    SELECT 
        i.id,
        i.empresa,
        i.revenda,
        i.coletores_json,
        l.codigo,
        l.empresa AS nome_empresa,   -- ex.: Cidade A
        l.revenda AS nome_revenda    -- ex.: Fiat
    FROM inv_inventarios i
    INNER JOIN inv_lojas l
        ON l.ativo = 1
       AND CAST(SUBSTRING_INDEX(l.codigo, '.', 1) AS UNSIGNED) = i.empresa
       AND CAST(SUBSTRING_INDEX(l.codigo, '.', -1) AS UNSIGNED) = i.revenda
    WHERE i.status = 'aberto'
    ORDER BY i.id DESC
";
$stmtInventarios    = $pdo->query($sqlInventarios);
$inventariosAbertos = $stmtInventarios->fetchAll(PDO::FETCH_ASSOC);

$coletoresPorInventario = [];
foreach ($inventariosAbertos as $inv) {
    $idInventario = (int)($inv['id'] ?? 0);
    $tmp = json_decode((string)($inv['coletores_json'] ?? ''), true);
    $coletoresPorInventario[$idInventario] = is_array($tmp) ? $tmp : [];
}

$erro = '';

// Lojas da Conferência: ela não depende de inventário aberto, só da loja.
$lojasConferencia = $pdo->query("
    SELECT codigo, empresa AS cidade, revenda AS nome
      FROM inv_lojas
     WHERE ativo = 1
     ORDER BY codigo
")->fetchAll(PDO::FETCH_ASSOC);

// Só entra na Conferência quem o admin cadastrou, com o PIN dele.
// Sem a tabela (sql/2026-10-16-conferentes.sql não rodou no servidor), a lista
// fica vazia em vez de derrubar o login inteiro: a contagem não depende dela.
try {
    $conferentesAtivos = conferentes_listar($pdo, true);
} catch (Throwable $e) {
    error_log('login: lista de conferentes indisponível: ' . $e->getMessage());
    $conferentesAtivos = [];
}
$conferenteId      = (int)($_POST['conferente_id'] ?? 0);
$lojaConferencia  = (string)($_POST['loja_conferencia'] ?? '');
$contagemInicial  = in_array((int)($_POST['contagem'] ?? $_GET['contagem'] ?? 1), [1, 2, 3, 4], true)
    ? (int)($_POST['contagem'] ?? $_GET['contagem'] ?? 1) : 1;
if ($inventariosAbertos === [] && $contagemInicial !== 4) {
    $contagemInicial = 3;   // sem inventário aberto, só a Conferência e a Realocação
}

// -----------------------------------------------------------------------
// PRIMEIRO CARREGAMENTO (GET): se não tiver sessão de coletores,
// tenta puxar do primeiro inventário aberto (coletores_json)
// -----------------------------------------------------------------------
if (empty($coletoresGrupos) && !empty($inventariosAbertos)) {
    $primeiro = $inventariosAbertos[0];
    $tmp = $coletoresPorInventario[(int)$primeiro['id']] ?? [];
    if ($tmp !== []) {
        $coletoresGrupos = $tmp;
        $_SESSION['coletores_grupos'] = $coletoresGrupos;
        $qtColetores = count($coletoresGrupos);
    }
}

// -----------------------------------------------------------------------
// Pré-carrega locações com falta para cada inventário (para dropdown no login)
// -----------------------------------------------------------------------
$locacoesPorInventario = [];
foreach ($inventariosAbertos as $inv) {
    $idInv  = (int)$inv['id'];
    $empInv = (int)$inv['empresa'];
    $revInv = (int)$inv['revenda'];

    // A recontagem anda por item: cada coletor tem a sua fila, e a locação
    // inicial é escolhida entre as locações dela.
    $locacoesPorInventario[$idInv] = [];

    try {
        for ($num = 1; $num <= CONTAGEM2_MAX_COLETORES; $num++) {
            $locacoesPorInventario[$idInv][$num] = recontagem_locacoes_pendentes($pdo, $idInv, $empInv, $revInv, $num);
        }
    } catch (Throwable $e) {
        $locacoesPorInventario[$idInv] = [];
    }
}

// -----------------------------------------------------------------------
// POST (clicou em Iniciar Contagem)
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inventarioRaw   = $_POST['inventario'] ?? '';
    $coletorSelecion = (int)($_POST['coletor'] ?? 0);
    $contagem        = in_array((int)($_POST['contagem'] ?? 1), [1, 2, 3, 4]) ? (int)$_POST['contagem'] : 1;
    $locacaoInicial  = trim($_POST['locacao_inicial'] ?? '');

    // Conferência sem inventário: loja + conferente cadastrado com PIN. id 0 no
    // lugar do inventário em inv_conferencias/inv_ajustes_locacao; o nome do
    // conferente vai em dupla_label.
    //
    // A Realocação (4) entra pelo mesmo caminho: escreve no Apollo do mesmo
    // jeito que o ajuste da conferência, então exige o mesmo PIN. Só muda o
    // destino — e a contagem 4 na sessão não abre as telas da conferência.
    if ($contagem === 3 || $contagem === 4) {
        $lojaOk = null;
        foreach ($lojasConferencia as $l) {
            if ($l['codigo'] === $lojaConferencia) {
                $lojaOk = $l;
            }
        }

        $pin  = (string)($_POST['pin'] ?? '');
        $auth = null;

        if ($lojaOk === null) {
            $erro = 'Selecione a loja.';
        } elseif ($conferenteId <= 0) {
            $erro = 'Escolha quem está conferindo.';
        } elseif (!conferentes_pin_valido($pin)) {
            $erro = 'O PIN tem 4 números.';
        } else {
            $auth = conferentes_autenticar($pdo, $conferenteId, $pin);
            if (!$auth['ok']) {
                if ($auth['motivo'] === 'bloqueado') {
                    $erro = 'Muitas tentativas. Tente de novo às ' . $auth['ate'] . '.';
                } elseif ($auth['motivo'] === 'pin') {
                    $erro = 'PIN incorreto. ' . ($auth['restantes'] === 1
                        ? 'Resta 1 tentativa.'
                        : 'Restam ' . $auth['restantes'] . ' tentativas.');
                } else {
                    $erro = 'Conferente não encontrado.';
                }
            }
        }

        if ($erro === '' && $auth !== null && $auth['ok']) {
            [$empConf, $revConf] = array_map('intval', explode('.', $lojaOk['codigo']));

            // inventario_login não é tocado: um gestor logado continua logado.
            $_SESSION['inventario'] = [
                'id'             => 0,
                'empresa'        => $empConf,
                'revenda'        => $revConf,
                'coletor'        => 0,
                'contagem'       => $contagem,
                // O nome cadastrado: é ele que fica em inv_conferencias e
                // inv_ajustes_locacao, como antes.
                'dupla_label'    => $auth['nome'],
                'conferente_id'  => $conferenteId,
                'sem_inventario' => true,
            ];
            header('Location: ' . ($contagem === 4 ? 'realocacao.php' : 'conferencia.php'));
            exit;
        }
    }

    // inventarioRaw vem no formato "id|empresa|revenda"
    $partes       = explode('|', $inventarioRaw);
    $inventarioId = (int)($partes[0] ?? 0);
    $empresa      = (int)($partes[1] ?? 0);
    $revenda      = (int)($partes[2] ?? 0);

    // recarrega coletores_grupos a partir do inventário selecionado (coletores_json)
    if ($inventarioId > 0) {
        $tmp = $coletoresPorInventario[$inventarioId] ?? [];
        if (is_array($tmp)) {
            $coletoresGrupos = $tmp;
            $_SESSION['coletores_grupos'] = $coletoresGrupos;
            $qtColetores = count($coletoresGrupos);
        }
    }

    if ($contagem === 3 || $contagem === 4) {
        // erro da Conferência/Realocação já definido acima
    } elseif (
        $inventarioId    <= 0 ||
        $empresa         <= 0 ||
        $revenda         <= 0 ||
        $coletorSelecion <= 0 ||
        $coletorSelecion > 8
    ) {
        $erro = 'Selecione um inventário e um coletor válido.';
    } else {
        $check = $pdo->prepare("
            SELECT id 
              FROM inv_inventarios 
             WHERE id      = ? 
               AND status  = 'aberto' 
               AND empresa = ? 
               AND revenda = ?
        ");
        $check->execute([$inventarioId, $empresa, $revenda]);

        if (!$check->fetch()) {
            $erro = 'Inventário não encontrado ou fechado.';
        } elseif ($contagem === 2
                  && recontagem_fila($pdo, $inventarioId, $empresa, $revenda, $coletorSelecion) === []) {
            // A 1ª e a Conferência estão sempre abertas enquanto o inventário
            // estiver; a Recontagem só entra com item designado.
            $erro = 'Nenhum item designado para este coletor.';
        } else {
            // monta label do coletor para exibir em outras telas
            $nomesColetor = $coletoresGrupos[$coletorSelecion] ?? [];
            $label = !empty($nomes) ? implode(' e ', $nomes) : 'Coletor ' . $num;

            // label legível da dupla
            $nomesColetor2 = $coletoresGrupos[$coletorSelecion] ?? [];
            $dupla_label   = is_array($nomesColetor2) && count($nomesColetor2) > 0
                ? implode(' e ', $nomesColetor2)
                : 'Dupla ' . $coletorSelecion;

            // sessão principal do inventário/contagem
            $_SESSION['inventario'] = [
                'id'              => $inventarioId,
                'empresa'         => $empresa,
                'revenda'         => $revenda,
                'coletor'         => $coletorSelecion,   // número 1..8
                'contagem'        => $contagem,
                'dupla_label'     => $dupla_label,
                'locacao_inicial' => $locacaoInicial,
            ];

            // sessão de "login" do coletor (se quiser proteger outras telas)
            $_SESSION['inventario_login'] = [
                'usuario'       => $labelColetor,
                'coletor_num'   => $coletorSelecion,
                'coletor_nomes' => $nomesColetor,
            ];

            // opcional: guardar nomes da dupla para uso em contagem.php
            $_SESSION['inventario_coletor_nomes'] = $nomesColetor;

            $destino = 'contagem.php';
            if ($contagem === 2) { $destino = 'contagem_2.php'; }
            if ($contagem === 3) { $destino = 'conferencia.php'; }
            header('Location: ' . $destino);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Inventário - Selecionar Inventário</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php require __DIR__ . '/includes/icones.php'; ?>
  <style>
    :root {
      --red: #2347C8;
      --red-dark: #B71C1C;
      --gray-25: #FCFCFD;
      --gray-50: #F5F5F7;
      --gray-100: #ECEFF1;
      --gray-200: #E0E0E0;
      --gray-400: #B0BEC5;
      --gray-800: #263238;
      --white: #FFFFFF;
      --radius-card: 18px;
      --radius-input: 14px;
      --shadow-soft: 0 12px 25px rgba(15,23,42,0.25);
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      background:
        radial-gradient(circle at top, #FFFFFF 0, #E3F2FD 50%, #CFD8DC 100%);
      color: var(--gray-800);
      -webkit-font-smoothing: antialiased;
    }

    .screen {
      min-height: 100vh;
      padding: 48px 24px 16px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 32px;
    }

    .logo-box {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
    }
    .logo-box img {
      width: 90px;
      height: 90px;
      border-radius: 24px;
      object-fit: contain;
      background: rgba(255,255,255,0.96);
      box-shadow: 0 6px 14px rgba(0,0,0,0.25);
    }
    .title {
      font-size: 22px;
      font-weight: 700;
      letter-spacing: .28em;
      text-transform: uppercase;
      text-align: center;
    }
    .subtitle {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .16em;
      color: var(--gray-400);
    }

    .card {
      width: 100%;
      max-width: 420px;
      background: rgba(255,255,255,0.96);
      border-radius: var(--radius-card);
      padding: 24px 22px 20px;
      box-shadow: var(--shadow-soft);
      display: flex;
      flex-direction: column;
      gap: 16px;
      border: 1px solid rgba(148,163,184,0.25);
      backdrop-filter: blur(4px);
    }

    .field-label {
      font-size: 13px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .08em;
      margin-bottom: 4px;
      color: var(--gray-800);
    }

    .input,
    .select {
      height: 52px;
      border-radius: var(--radius-input);
      border: 1px solid var(--gray-200);
      padding: 0 14px;
      font-size: 15px;
      background: #FAFAFA;
      width: 100%;
      transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }
    .input::placeholder { color: var(--gray-400); }

    .input:focus,
    .select:focus {
      outline: none;
      border-color: var(--red);
      box-shadow: 0 0 0 2px rgba(35,71,200,0.18);
      background: #FFFFFF;
    }

    .btn-primary {
      width: 100%;
      max-width: 420px;
      height: 54px;
      border-radius: 18px;
      border: none;
      background: linear-gradient(135deg, var(--red-dark), var(--red));
      color: var(--white);
      font-size: 15px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .12em;
      margin-top: 16px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      box-shadow: 0 12px 24px rgba(183,28,28,0.55);
      transition: box-shadow 0.15s ease, transform 0.05s ease, filter 0.1s;
    }
    .btn-primary:hover {
      box-shadow: 0 14px 28px rgba(183,28,28,0.65);
      transform: translateY(-1px);
      filter: brightness(1.03);
    }
    .btn-primary:active {
      transform: translateY(0);
      box-shadow: 0 8px 16px rgba(183,28,28,0.45);
    }

    .version {
      margin-top: auto;
      font-size: 12px;
      color: var(--gray-400);
      text-align: center;
    }

    .erro {
      color: #C62828;
      font-size: 13px;
      text-align: center;
      background: #FFEBEE;
      border-radius: 999px;
      padding: 6px 14px;
      border: 1px solid #FFCDD2;
      max-width: 420px;
      width: 100%;
    }

    .no-inventario {
      text-align: center;
      color: var(--gray-400);
      font-size: 14px;
    }

    @media (min-width: 768px) {
      .screen {
        padding-top: 72px;
      }
    }
  </style>
</head>
<body>
  <div class="screen">
    <div class="logo-box">
      <img src="img/logo.svg" alt="Exemplo">
      <div class="title">INVENTÁRIO</div>
      <div class="subtitle">Selecionar inventário aberto</div>
    </div>

    <?php if (!empty($erro)): ?>
      <div class="erro"><?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>

      <form class="card" id="login-form" method="post" autocomplete="off">
        <div>
          <div class="field-label">Contagem</div>
          <select class="select" id="contagem" name="contagem" required onchange="onContagemChange()">
            <option value="1" <?php echo $inventariosAbertos === [] ? 'disabled' : ''; ?>>1ª Contagem</option>
            <option value="2" <?php echo $inventariosAbertos === [] ? 'disabled' : ''; ?>>Recontagem</option>
            <option value="3" <?php echo $contagemInicial === 3 ? 'selected' : ''; ?>>Conferência</option>
            <option value="4" <?php echo $contagemInicial === 4 ? 'selected' : ''; ?>>Realocação</option>
          </select>
          <?php if ($inventariosAbertos === []): ?>
            <div class="no-inventario" style="padding: 8px 0 0; text-align: left;">
              Nenhum inventário aberto: só a Conferência e a Realocação estão disponíveis.
            </div>
          <?php endif; ?>
        </div>

        <!-- Conferência: não depende de inventário, só da loja. -->
        <div class="campo-conferencia" style="display:none;">
          <div class="field-label">Loja</div>
          <select class="select" id="loja_conferencia" name="loja_conferencia">
            <option value="">Selecione...</option>
            <?php foreach ($lojasConferencia as $l): ?>
              <option value="<?php echo htmlspecialchars($l['codigo']); ?>"
                      <?php echo $l['codigo'] === $lojaConferencia ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($l['cidade'] . ' - ' . $l['nome']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="campo-conferencia" style="display:none;">
          <div class="field-label">Quem está conferindo</div>
          <select class="select" id="conferente_id" name="conferente_id"
                  <?php echo $conferentesAtivos === [] ? 'disabled' : ''; ?>>
            <option value="">Selecione...</option>
            <?php foreach ($conferentesAtivos as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>"
                      <?php echo (int)$c['id'] === $conferenteId ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($c['nome']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($conferentesAtivos === []): ?>
            <div class="no-inventario" style="padding: 8px 0 0; text-align: left;">
              Nenhum conferente cadastrado — peça ao administrador.
            </div>
          <?php endif; ?>
        </div>

        <div class="campo-conferencia" style="display:none;">
          <div class="field-label">PIN</div>
          <!-- Volta sempre vazio: o PIN nunca é devolvido para a tela. -->
          <input class="input" id="pin" name="pin" type="password" inputmode="numeric"
                 maxlength="4" pattern="\d{4}" title="4 números" autocomplete="off">
        </div>

        <div class="campo-inventario">
          <div class="field-label">Inventário Número</div>
          <select class="select" id="inventario" name="inventario" required>
            <option value="">Selecione...</option>
            <?php foreach ($inventariosAbertos as $inv): ?>
              <?php
                $id     = (int)$inv['id'];
                $codigo = $inv['codigo'];                    // ex.: 1.1
                $empCod = (int)explode('.', $codigo)[0];     // 1
                $revCod = (int)explode('.', $codigo)[1];     // 1
                $val    = $id . '|' . $empCod . '|' . $revCod;

                $empresaN = $inv['nome_empresa'];            // Cidade A
                $revendaN = $inv['nome_revenda'];            // Fiat
              ?>
              <option value="<?php echo htmlspecialchars($val); ?>">
                Nº <?php echo $id; ?> - 
                <?php echo htmlspecialchars($empresaN); ?> - 
                <?php echo htmlspecialchars($revendaN); ?> 
                (<?php echo htmlspecialchars($codigo); ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="campo-inventario">
          <div class="field-label">Coletor / Dupla</div>
          <select class="select" id="coletor" name="coletor" required>
            <option value="">Selecione...</option>
            <?php for ($num = 1; $num <= 8; $num++):
                  $nomes = $coletoresGrupos[$num] ?? [];
                  $label = 'Coletor ' . $num;
                  if (!empty($nomes)) {
                      $label .= ' - ' . implode(' e ', $nomes);
                  }
            ?>
              <option value="<?php echo (int)$num; ?>">
                <?php echo htmlspecialchars($label); ?>
              </option>
            <?php endfor; ?>
          </select>
        </div>

        <div id="campo-locacao" style="display:none;">
          <div class="field-label">Locação da Dupla</div>
          <select class="select" id="locacao_inicial" name="locacao_inicial">
            <option value="">— Selecione a locação —</option>
          </select>
        </div>
      </form>

      <button class="btn-primary" type="submit" form="login-form">
        Iniciar Contagem
      </button>

    <div class="version">
      <?php echo count($inventariosAbertos ?? []); ?> inventário(s) aberto(s) | v1.0.3
    </div>
  </div>
  <script>
    const inventarioSelect = document.getElementById('inventario');
    const coletorSelect    = document.getElementById('coletor');
    const contagemSelect   = document.getElementById('contagem');
    const campoLocacao     = document.getElementById('campo-locacao');
    const selLocacao       = document.getElementById('locacao_inicial');

    const coletoresPorInventario  = <?php echo json_encode($coletoresPorInventario,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const locacoesPorInventario   = <?php echo json_encode($locacoesPorInventario,   JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    function montarLabelColetor(numero, nomes) {
  if (Array.isArray(nomes) && nomes.length > 0) {
    return nomes.join(' e ');
  }
  return 'Coletor ' + numero;
}

    function preencherColetoresDoInventario() {
      if (!inventarioSelect || !coletorSelect) return;

      const valorInventario = inventarioSelect.value || '';
      const inventarioId = (valorInventario.split('|')[0] || '').trim();
      const grupos = coletoresPorInventario[inventarioId] || {};
      const valorAtual = coletorSelect.value;

      coletorSelect.innerHTML = '';

      const optionPadrao = document.createElement('option');
      optionPadrao.value = '';
      optionPadrao.textContent = 'Selecione...';
      coletorSelect.appendChild(optionPadrao);

      for (let num = 1; num <= 8; num++) {
        const nomes = grupos[num] || grupos[String(num)] || [];
        const option = document.createElement('option');
        option.value = String(num);
        option.textContent = montarLabelColetor(num, nomes);
        coletorSelect.appendChild(option);
      }

      if (valorAtual && Array.from(coletorSelect.options).some(opt => opt.value === valorAtual)) {
        coletorSelect.value = valorAtual;
      }
    }

    function popularLocacoes() {
      if (!selLocacao) return;
      const invId  = (inventarioSelect?.value || '').split('|')[0].trim();
      // Uma lista por coletor: cada um só vê as locações que o gestor lhe deu.
      const locs   = (locacoesPorInventario[invId] || {})[coletorSelect?.value || ''] || [];
      selLocacao.innerHTML = '<option value="">— Selecione a locação —</option>' +
        locs.map(l => `<option value="${l}">${l}</option>`).join('');
    }

    function onContagemChange() {
      // Conferência troca inventário/coletor por loja/nome — e o required
      // acompanha, senão o navegador barra o envio num campo escondido.
      // A Realocação usa os mesmos campos da Conferência.
      const is3 = contagemSelect?.value === '3' || contagemSelect?.value === '4';
      document.querySelectorAll('.campo-inventario').forEach(el => {
        el.style.display = is3 ? 'none' : '';
        el.querySelectorAll('select').forEach(s => { s.required = !is3; });
      });
      document.querySelectorAll('.campo-conferencia').forEach(el => {
        el.style.display = is3 ? '' : 'none';
        el.querySelectorAll('select, input').forEach(s => { s.required = is3; });
      });

      const is2 = contagemSelect?.value === '2';
      if (campoLocacao) campoLocacao.style.display = is2 ? '' : 'none';
      if (is2) popularLocacoes();
    }

    if (inventarioSelect && coletorSelect) {
      inventarioSelect.addEventListener('change', function() {
        preencherColetoresDoInventario();
        if (contagemSelect?.value === '2') popularLocacoes();
      });
      preencherColetoresDoInventario();
      coletorSelect.addEventListener('change', function() {
        if (contagemSelect?.value === '2') popularLocacoes();
      });
    }

    inventarioSelect?.addEventListener('change', onContagemChange);
    onContagemChange();
  </script>
</body>
</html>
