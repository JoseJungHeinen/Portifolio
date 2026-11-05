<?php
declare(strict_types=1);

/**
 * Realocação: item que chegou sem locação ganha uma. Bipa o item, bipa a
 * etiqueta da prateleira, grava no Apollo — e já leva a locação para o
 * inventário aberto da loja, como o ajuste da conferência.
 *
 * Só item com locação indefinida (zona ZZ ou A-1-1-A-1): ver realocacao_lib.php.
 *
 * Mesmas garantias do conferencia_ajuste.php: sessão que passou pelo PIN, loja
 * da sessão, token contra POST forjado, item resolvido no servidor a cada
 * requisição, locação validada pelas regras do Apollo, gravação que só confirma
 * com exatamente uma linha, e digitação só pelo botão de ilegível, com rastro.
 */

session_start();
require 'conexao_inventario.php';
require 'conexao_oracle.php';
require 'realocacao_lib.php';

if (empty($_SESSION['inventario']) || (int)($_SESSION['inventario']['contagem'] ?? 0) !== 4
    || (int)($_SESSION['inventario']['conferente_id'] ?? 0) <= 0) {
    header('Location: login.php?contagem=4');
    exit;
}

$idInv      = (int)($_SESSION['inventario']['id']          ?? 0);
$empresa    = (int)($_SESSION['inventario']['empresa']     ?? 0);
$revenda    = (int)($_SESSION['inventario']['revenda']     ?? 0);
$coletor    = (int)($_SESSION['inventario']['coletor']     ?? 0);
$duplaLabel = (string)($_SESSION['inventario']['dupla_label'] ?? '');

if (empty($_SESSION['realocacao_csrf'])) {
    $_SESSION['realocacao_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['realocacao_csrf'];

$lojas    = conferencia_lojas_da_cidade($pdo, $empresa, $revenda);
$lojaNome = '';
foreach ($lojas as $l) {
    if ($l['atual']) {
        $lojaNome = $l['nome'];
    }
}
$stmt = $pdo->prepare("SELECT empresa FROM inv_lojas WHERE codigo = :cod AND ativo = 1 LIMIT 1");
$stmt->execute([':cod' => $empresa . '.' . $revenda]);
$cidade = (string)($stmt->fetchColumn() ?: '');

// Confirmação da última gravação, mostrada uma vez só.
$flash = $_SESSION['realocacao_flash'] ?? null;
unset($_SESSION['realocacao_flash']);

$ehPost       = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$acao         = $ehPost ? (string)($_POST['acao'] ?? '') : '';
$itemPub      = $ehPost ? trim((string)($_POST['item'] ?? '')) : '';
$entradaItem  = $ehPost ? (string)($_POST['entrada_item'] ?? '') : '';
$entradaLoc   = $ehPost ? (string)($_POST['entrada'] ?? '') : '';

$etapa     = 'item';   // 'item': esperando a peça; 'locacao': peça aceita, esperando a prateleira
$erroGeral = '';
$erros     = [];
$checagem  = null;

if ($ehPost && in_array($acao, ['item', 'gravar'], true)) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $erroGeral = 'Formulário expirado. Bipe o item de novo.';
    } elseif (!in_array($entradaItem, CONFERENCIA_ENTRADAS, true)) {
        // O item só chega aqui por um bipe ou depois do botão de ilegível.
        $erroGeral = 'Bipe o código do item.';
    } elseif (!($ora = oracle_conectar())) {
        $erroGeral = 'Apollo indisponível. Tente de novo.';
    } else {
        try {
            // A cada requisição, inclusive no gravar: entre o bipe do item e o
            // da prateleira, outra pessoa pode ter dado locação a ele.
            $checagem = realocacao_checar_item($ora, $empresa, $revenda, $itemPub);

            if (!$checagem['ok']) {
                $erroGeral = $checagem['erro'];
            } elseif ($acao === 'item') {
                $etapa = 'locacao';
            } elseif (!in_array($entradaLoc, CONFERENCIA_ENTRADAS, true)) {
                $etapa     = 'locacao';
                $erroGeral = 'Bipe a etiqueta da prateleira antes de gravar.';
            } else {
                $etapa     = 'locacao';
                $validacao = realocacao_validar_locacao($_POST);
                $erros     = $validacao['erros'];

                if ($erros === []) {
                    $item        = $checagem['item'];
                    $pubGravado  = (string)($item['item_estoque_pub'] ?? $itemPub);
                    $locacaoNova = realocacao_juntar($validacao['valores']);

                    try {
                        conferencia_gravar_locacao($ora, $empresa, $revenda, (int)$checagem['item_estoque'], $validacao['valores']);
                    } catch (RuntimeException $e) {
                        error_log('realocacao: ' . $e->getMessage());
                        throw new RuntimeException('gravar');
                    }

                    // Rastro e inventário só depois do Apollo dar certo; se
                    // falharem, a locação já mudou e não dá para desfazer com
                    // segurança — fica no log, como no ajuste da conferência.
                    try {
                        conferencia_registrar_ajuste($pdo, [
                            'id_inventario'    => $idInv,
                            'empresa'          => $empresa,
                            'revenda'          => $revenda,
                            'coletor'          => $coletor,
                            'dupla_label'      => $duplaLabel,
                            'item_estoque_pub' => $pubGravado,
                            'cod_item_estoque' => (string)$checagem['item_estoque'],
                            // Locação indefinida como anterior é o que distingue a
                            // realocação dos ajustes da conferência.
                            'locacao_anterior' => realocacao_juntar($checagem['atual']),
                            'locacao_nova'     => $locacaoNova,
                            'entrada'          => realocacao_entrada($entradaItem, $entradaLoc),
                        ]);
                    } catch (Throwable $e) {
                        error_log('realocacao: locação gravada no Apollo, mas o rastro falhou: ' . $e->getMessage());
                    }

                    try {
                        conferencia_atualizar_locacao_inventario(
                            $pdo, $empresa, $revenda, (string)$checagem['item_estoque'], $pubGravado, $validacao['valores']
                        );
                    } catch (Throwable $e) {
                        error_log('realocacao: locação gravada no Apollo, mas o inventário não acompanhou: ' . $e->getMessage());
                    }

                    $_SESSION['realocacao_flash'] = ['item' => $pubGravado, 'locacao' => $locacaoNova];
                    oci_close($ora);
                    // Post/Redirect/Get: recarregar não regrava, e a tela volta
                    // pronta para o próximo item.
                    header('Location: realocacao.php');
                    exit;
                }
            }
        } catch (RuntimeException $e) {
            $erroGeral = $e->getMessage() === 'gravar'
                ? 'O Apollo não gravou a locação. Nada foi alterado — tente de novo.'
                : 'O Apollo recusou a consulta. Tente de novo.';
        }
        oci_close($ora);
    }
}

$item        = $checagem['item'] ?? null;
$digitando   = $etapa === 'locacao' && $entradaLoc === 'digitado' && $erros !== [];
$primeiroErro = array_key_first($erros);
$form        = $ehPost ? $_POST : [];

// Na etapa do item com erro, mostra o que foi lido.
$itemComErro = ($etapa === 'item' && $erroGeral !== '') ? $itemPub : '';

$lidoComErro = ($etapa === 'locacao' && $entradaLoc === 'bipado' && $erros !== [])
    ? implode('-', array_map(fn($c) => trim((string)($_POST[$c] ?? '')), array_keys(CONFERENCIA_LOCACAO_CAMPOS)))
    : '';

function campo_locacao(string $campo, array $regra, array $form, array $erros, ?string $primeiroErro, bool $digitando): string
{
    $valor    = htmlspecialchars((string)($form[$campo] ?? ''));
    $temErro  = isset($erros[$campo]);
    $numerico = $regra['tipo'] === 'numero';
    $max      = $numerico ? strlen((string)$regra['max']) : $regra['max'];
    $foco     = ($digitando && $primeiroErro === $campo) ? ' autofocus' : '';

    return '<div class="field">'
        . '<label class="form-label" for="f-' . $campo . '">' . htmlspecialchars($regra['rotulo']) . '</label>'
        . '<input class="form-input' . ($temErro ? ' form-input--error' : '') . '"'
        . ' id="f-' . $campo . '" name="' . $campo . '" value="' . $valor . '"'
        . ' maxlength="' . $max . '" autocomplete="off" spellcheck="false"'
        . ($numerico
            ? ' inputmode="numeric" pattern="[0-9]*"'
            : ' autocapitalize="characters"')
        . ($temErro ? ' aria-invalid="true"' : '')
        . $foco . '>'
        . '</div>';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <?php $pageTitle = 'Realocação'; require __DIR__ . '/includes/head.php'; ?>
</head>
<body class="coletor">
  <header class="coletor-bar">
    <div class="coletor-bar__texto">
      <div class="coletor-bar__titulo">Realocação <span><?php echo htmlspecialchars($cidade . ($lojaNome !== '' ? ' - ' . $lojaNome : '')); ?></span></div>
      <div class="coletor-bar__sub"><?php echo htmlspecialchars($duplaLabel); ?> · item sem locação</div>
    </div>
    <a href="logout.php?de=sem_inventario" class="btn btn-tertiary btn-sm">Sair</a>
  </header>

  <main class="coletor-main">
    <?php if (is_array($flash)): ?>
    <div class="alert alert--success" id="flash" role="status">
      <div class="alert-text">
        <strong><?php echo htmlspecialchars((string)$flash['item']); ?></strong>
        realocado em <strong><?php echo htmlspecialchars((string)$flash['locacao']); ?></strong>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($erroGeral !== ''): ?>
    <div class="alert alert--danger" role="alert">
      <div class="alert-text"><?php echo htmlspecialchars($erroGeral); ?></div>
    </div>
    <?php endif; ?>

    <?php if ($etapa === 'item'): ?>
    <!-- Etapa 1: a peça. -->
    <form method="post" action="realocacao.php" id="form-item" novalidate>
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
      <input type="hidden" name="acao" value="item">
      <input type="hidden" name="item" id="item-valor">
      <input type="hidden" name="entrada_item" id="entrada-item">
      <div class="card card--compacto card--primary">
        <label class="coletor-label" for="codigo">1 · Código do item · bipe a peça</label>
        <input class="form-input scan-input" id="codigo" type="text"
               autocapitalize="off" aria-describedby="codigo-erro" autofocus>
        <div class="form-error hidden" id="codigo-erro" role="alert"></div>
        <?php if ($itemComErro !== ''): ?>
        <div class="form-hint">Lido: <?php echo htmlspecialchars($itemComErro); ?></div>
        <?php endif; ?>
        <div class="d-flex justify-between align-center mt-2">
          <span class="form-hint" id="codigo-modo" style="margin:0;">Só leitor</span>
          <button type="button" class="btn btn-tertiary btn-sm" id="btn-item-ilegivel">Código ilegível</button>
        </div>
      </div>
    </form>
    <?php else: ?>
    <!-- Etapa 2: a prateleira. -->
    <form method="post" action="realocacao.php" id="form-locacao" novalidate>
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
      <input type="hidden" name="acao" value="gravar">
      <input type="hidden" name="item" value="<?php echo htmlspecialchars($itemPub); ?>">
      <input type="hidden" name="entrada_item" value="<?php echo htmlspecialchars($entradaItem); ?>">
      <!-- 'bipado' depois de ler a etiqueta, 'digitado' depois do botão de
           ilegível. Vazio, o servidor recusa. -->
      <input type="hidden" name="entrada" id="entrada" value="<?php echo $digitando ? 'digitado' : ''; ?>">

      <?php if ($item !== null): ?>
      <div class="card card--compacto">
        <div class="coletor-label">Item · sem locação</div>
        <div class="item-codigo"><?php echo htmlspecialchars((string)$item['item_estoque_pub']); ?></div>
        <div class="item-desc"><?php echo htmlspecialchars((string)$item['descricao']); ?></div>
      </div>
      <?php endif; ?>

      <div class="card card--compacto card--primary">
        <div id="bloco-bipe" class="<?php echo $digitando ? 'hidden' : ''; ?>">
          <label class="coletor-label" for="etiqueta">2 · Locação · bipe a etiqueta da prateleira</label>
          <input class="form-input scan-input" id="etiqueta" type="text"
                 autocapitalize="off" placeholder="ex.: 1-22-13-A-1"
                 aria-describedby="etiqueta-erro"
                 <?php echo $digitando ? '' : 'autofocus'; ?>>
          <div class="form-error hidden" id="etiqueta-erro" role="alert"></div>

          <div class="coletor-label mt-3">Locação lida</div>
          <div class="locacao-grande" id="locacao-lida"><?php echo $lidoComErro !== '' ? htmlspecialchars($lidoComErro) : '—'; ?></div>

          <div class="d-flex justify-between align-center mt-2">
            <span class="form-hint" style="margin:0;">Só leitor</span>
            <button type="button" class="btn btn-tertiary btn-sm" id="btn-ilegivel">Etiqueta ilegível</button>
          </div>
        </div>

        <!-- Os campos existem sempre, porque são eles que vão ao servidor; o
             bipe só os preenche. Visíveis só depois do botão de ilegível. -->
        <div id="bloco-digitar" class="<?php echo $digitando ? '' : 'hidden'; ?>">
          <div class="coletor-label">2 · Locação · digitada</div>
          <div class="alert alert--warning mb-2" role="status">
            <div class="alert-text">Digitação liberada. Esta realocação fica registrada como digitada.</div>
          </div>
          <div class="loc-grid">
            <?php foreach (CONFERENCIA_LOCACAO_CAMPOS as $campo => $regra): ?>
              <?php echo campo_locacao($campo, $regra, $form, $erros, $primeiroErro, $digitando); ?>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($erros !== []): ?>
        <div class="alert alert--danger mt-3" role="alert">
          <div class="alert-text">
            <?php if (!$digitando): ?>
              <div><strong>Etiqueta com valor inválido — bipe de novo.</strong></div>
            <?php endif; ?>
            <?php foreach ($erros as $msg): ?>
              <div><?php echo htmlspecialchars($msg); ?></div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </form>
    <?php endif; ?>
  </main>

  <?php if ($etapa === 'locacao'): ?>
  <div class="coletor-acoes">
    <a href="realocacao.php" class="btn btn-coletor btn-tertiary">Outro item</a>
    <!-- Desabilitado até haver locação lida: o Enter de um segundo bipe não grava
         sem a pessoa conferir. -->
    <button type="submit" form="form-locacao" class="btn btn-coletor btn-primary" id="btn-gravar"
            <?php echo $digitando ? '' : 'disabled'; ?>>Gravar</button>
  </div>
  <?php endif; ?>

  <script src="includes/coletor-scanner.js?v=<?php echo (int)@filemtime(__DIR__ . '/includes/coletor-scanner.js'); ?>"></script>
  <script>
    // Etapa 1: o bipe do item envia na hora.
    const formItem = document.getElementById('form-item');
    if (formItem) {
      const inputCodigo = document.getElementById('codigo');
      const erroCodigo  = document.getElementById('codigo-erro');
      const modoCodigo  = document.getElementById('codigo-modo');
      const btnIleg     = document.getElementById('btn-item-ilegivel');
      let enviando = false;

      const trava = ColetorScanner.travarCampoDeBipe(inputCodigo, {
        aoLer: (codigo, entrada) => {
          if (enviando) { return; }
          enviando = true;
          erroCodigo.classList.add('hidden');
          document.getElementById('item-valor').value   = codigo;
          document.getElementById('entrada-item').value = entrada;
          formItem.submit();
        },
        aoRecusar: motivo => {
          erroCodigo.textContent = ColetorScanner.mensagemDeRecusa(motivo, 'Código ilegível');
          erroCodigo.classList.remove('hidden');
        }
      });

      btnIleg.addEventListener('click', () => {
        erroCodigo.classList.add('hidden');
        trava.liberar();
        modoCodigo.textContent = 'Digitação liberada — fica registrada';
        btnIleg.disabled = true;
      });
    }

    // Etapa 2: o bipe da etiqueta preenche; quem grava é o botão.
    const formLoc = document.getElementById('form-locacao');
    if (formLoc) {
      // Ordem da etiqueta: zona-rua-estante-prateleira-número.
      const CAMPOS_ETIQUETA = <?php echo json_encode(array_keys(CONFERENCIA_LOCACAO_CAMPOS)); ?>;
      const inputEtiqueta = document.getElementById('etiqueta');
      const erroEtiqueta  = document.getElementById('etiqueta-erro');
      const locacaoLida   = document.getElementById('locacao-lida');
      const btnIlegivel   = document.getElementById('btn-ilegivel');
      const btnGravar     = document.getElementById('btn-gravar');
      const inputEntrada  = document.getElementById('entrada');

      function lerEtiqueta(texto) {
        const partes = texto.split(/\s*[-–—]\s*/);
        if (partes.length !== CAMPOS_ETIQUETA.length || partes.some(p => p === '')) {
          return null;
        }
        return partes.map(p => p.toUpperCase());
      }

      function mostrarErroEtiqueta(texto) {
        erroEtiqueta.textContent = texto;
        erroEtiqueta.classList.remove('hidden');
      }

      ColetorScanner.travarCampoDeBipe(inputEtiqueta, {
        aoLer: (texto, entrada) => {
          const partes = lerEtiqueta(texto);
          if (partes === null) {
            mostrarErroEtiqueta('"' + texto + '" não é uma etiqueta de locação. O formato é 1-22-13-A-1.');
            return;
          }
          erroEtiqueta.classList.add('hidden');
          CAMPOS_ETIQUETA.forEach((campo, i) => {
            document.getElementById('f-' + campo).value = partes[i];
          });
          locacaoLida.textContent = partes.join('-');
          inputEntrada.value = entrada;
          btnGravar.disabled = false;
          // O foco fica no campo de bipe: um segundo bipe só preenche de novo.
          inputEtiqueta.focus();
        },
        aoRecusar: motivo => {
          mostrarErroEtiqueta(ColetorScanner.mensagemDeRecusa(motivo, 'Etiqueta ilegível'));
        }
      });

      btnIlegivel.addEventListener('click', () => {
        document.getElementById('bloco-bipe').classList.add('hidden');
        document.getElementById('bloco-digitar').classList.remove('hidden');
        inputEntrada.value = 'digitado';
        btnGravar.disabled = false;
        document.getElementById('f-' + CAMPOS_ETIQUETA[0]).focus();
      });

      document.querySelectorAll('.loc-grid .form-input').forEach(el => {
        el.addEventListener('focus', () => el.select());
      });

      // Escreve no Apollo: um segundo toque não vira segunda gravação.
      formLoc.addEventListener('submit', ev => {
        if (btnGravar.disabled) { ev.preventDefault(); return; }
        btnGravar.disabled = true;
        btnGravar.textContent = 'Gravando…';
      });
    }
  </script>
</body>
</html>
