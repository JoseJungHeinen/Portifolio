<?php
declare(strict_types=1);

/**
 * Ajuste de locação, aberto pelo botão "Trocar locação Apollo" do aviso de
 * locação diferente da conferência.
 *
 * Mesma operação do acerto_locacao.php (que continua existindo para o gestor,
 * pelo dashboard), refeita para o coletor: exige a sessão do coletor, a loja
 * vem da sessão e não de um seletor, valida pelas regras reais das colunas do
 * Apollo antes de gravar, e só confirma quando exatamente uma linha foi gravada.
 *
 * A locação nova entra pelo leitor, bipando a etiqueta da prateleira; digitar
 * só com o botão "Etiqueta ilegível", e isso fica registrado em
 * inv_ajustes_locacao — é o que impede a digitação de virar atalho para erro
 * proposital.
 */

session_start();
require 'conexao_inventario.php';
require 'conexao_oracle.php';
require 'conferencia_lib.php';

// Só a Conferência que passou pelo PIN (conferente_id); sessão de antes do PIN
// volta para o login.
if (empty($_SESSION['inventario']) || (int)($_SESSION['inventario']['contagem'] ?? 0) !== 3
    || (int)($_SESSION['inventario']['conferente_id'] ?? 0) <= 0) {
    header('Location: login.php');
    exit;
}

$idInv      = (int)($_SESSION['inventario']['id']          ?? 0);
$empresa    = (int)($_SESSION['inventario']['empresa']     ?? 0);
$revenda    = (int)($_SESSION['inventario']['revenda']     ?? 0);
$coletor    = (int)($_SESSION['inventario']['coletor']     ?? 0);
$duplaLabel = (string)($_SESSION['inventario']['dupla_label'] ?? '');

// Esta tela escreve no Apollo: um POST só vale se veio de um formulário que
// esta mesma sessão desenhou.
if (empty($_SESSION['conferencia_csrf'])) {
    $_SESSION['conferencia_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['conferencia_csrf'];

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

$ehPost  = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$itemPub = trim((string)($ehPost ? ($_POST['item'] ?? '') : ($_GET['item'] ?? '')));

$erroGeral   = '';
$erros       = [];
$item        = null;   // cabeçalho: código público, descrição
$itemEstoque = null;   // código interno, resolvido no servidor a cada requisição
$atual       = null;   // locação atual no Apollo
$entrada     = '';     // 'bipado' ou 'digitado', vindo do formulário

$ora = oracle_conectar();

if ($itemPub === '') {
    $erroGeral = 'Nenhum item informado.';
} elseif (!$ora) {
    // Sem Apollo não há o que ajustar: a locação mora lá.
    $erroGeral = 'Apollo indisponível. Não é possível ajustar a locação agora.';
} else {
    try {
        // O item é resolvido aqui, pelo código público, e nunca aceito pronto
        // do formulário: o que se grava é o item que o servidor encontrou.
        $itemEstoque = conferencia_resolver_item($ora, $empresa, $itemPub);

        if ($itemEstoque === null) {
            $erroGeral = 'Item ' . $itemPub . ' não encontrado no Apollo.';
        } else {
            $busca = conferencia_buscar_oracle($ora, $empresa, $revenda, [], $itemPub);
            $item  = $busca['item'] ?? null;
            $atual = conferencia_locacao_da_loja($ora, $empresa, $revenda, $itemEstoque);

            if ($atual === null) {
                $erroGeral = 'Este item não tem cadastro de estoque nesta loja, então não há locação para ajustar.';
            }
        }
    } catch (RuntimeException $e) {
        $erroGeral = 'O Apollo recusou a consulta. Tente de novo.';
    }
}

$juntar = fn(array $v): string =>
    implode('-', [$v['zona'], $v['rua'], $v['estante'], $v['prateleira'], $v['numero']]);

if ($ehPost && $erroGeral === '' && $itemEstoque !== null && $atual !== null) {
    $entrada = (string)($_POST['entrada'] ?? '');

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $erroGeral = 'Formulário expirado. Abra o ajuste de novo pela conferência.';
    } elseif (!in_array($entrada, CONFERENCIA_ENTRADAS, true)) {
        // O Gravar só se habilita depois de um bipe ou do botão de ilegível; sem
        // entrada, o POST não veio da tela.
        $erroGeral = 'Bipe a etiqueta da prateleira antes de gravar.';
    } else {
        $validacao = conferencia_validar_locacao($_POST);
        $erros     = $validacao['erros'];

        if ($erros === []) {
            try {
                conferencia_gravar_locacao($ora, $empresa, $revenda, $itemEstoque, $validacao['valores']);
                $locacaoNova = $juntar($validacao['valores']);

                // O rastro é gravado depois do Apollo dar certo: registrar um
                // ajuste que não aconteceu seria pior que não registrar. Se só
                // o rastro falhar, a locação já mudou — não dá para desfazer
                // com segurança —, então fica no log do servidor.
                try {
                    conferencia_registrar_ajuste($pdo, [
                        'id_inventario'    => $idInv,
                        'empresa'          => $empresa,
                        'revenda'          => $revenda,
                        'coletor'          => $coletor,
                        'dupla_label'      => $duplaLabel,
                        'item_estoque_pub' => $item['item_estoque_pub'] ?? $itemPub,
                        'cod_item_estoque' => (string)$itemEstoque,
                        'locacao_anterior' => $juntar($atual),
                        'locacao_nova'     => $locacaoNova,
                        'entrada'          => $entrada,
                    ]);
                } catch (Throwable $e) {
                    error_log('conferencia_ajuste: locação gravada no Apollo, mas o rastro falhou: ' . $e->getMessage());
                }

                // A locação nova entra na hora no inventário aberto da loja.
                // Mesmo raciocínio do rastro: o Apollo já gravou, então uma
                // falha aqui fica no log e não desfaz nada.
                try {
                    conferencia_atualizar_locacao_inventario(
                        $pdo, $empresa, $revenda, (string)$itemEstoque,
                        (string)($item['item_estoque_pub'] ?? $itemPub), $validacao['valores']
                    );
                } catch (Throwable $e) {
                    error_log('conferencia_ajuste: locação gravada no Apollo, mas o inventário não acompanhou: ' . $e->getMessage());
                }

                $_SESSION['conferencia_flash'] = [
                    'item'    => $item['item_estoque_pub'] ?? $itemPub,
                    'locacao' => $locacaoNova,
                ];

                oci_close($ora);
                // Post/Redirect/Get: recarregar a conferência não regrava nada.
                header('Location: conferencia.php');
                exit;
            } catch (RuntimeException $e) {
                $erroGeral = 'O Apollo não gravou a locação. Nada foi alterado — tente de novo.';
            }
        }
    }
}

if ($ora) {
    oci_close($ora);
}

// Em que estado a tela abre:
// - digitando: a dupla já tinha apertado "Etiqueta ilegível" e voltou com erro;
// - senão: esperando o bipe da etiqueta.
$digitando    = $ehPost && $entrada === 'digitado';
$podeEditar   = $atual !== null && ($erroGeral === '' || $erros !== []);
$primeiroErro = array_key_first($erros);

// Com erro, a dupla vê de volta o que mandou; sem, os campos começam na
// locação atual, só para servir de base se ela precisar digitar.
$form = $ehPost ? $_POST : ($atual ?? []);

// Valor que um bipe com erro deixou, para mostrar o que foi lido.
$lidoComErro = ($ehPost && $entrada === 'bipado' && $erros !== [])
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
  <?php $pageTitle = 'Ajuste de locação'; require __DIR__ . '/includes/head.php'; ?>
</head>
<body class="coletor">
  <form method="post" action="conferencia_ajuste.php" id="form-ajuste" novalidate>
    <input type="hidden" name="item" value="<?php echo htmlspecialchars($itemPub); ?>">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
    <!-- Preenchido pela tela: 'bipado' depois de ler a etiqueta, 'digitado'
         depois do botão de etiqueta ilegível. Vazio, o servidor recusa. -->
    <input type="hidden" name="entrada" id="entrada" value="<?php echo $digitando ? 'digitado' : ''; ?>">

    <header class="coletor-bar">
      <div class="coletor-bar__texto">
        <div class="coletor-bar__titulo">Ajuste de <span>locação</span></div>
        <div class="coletor-bar__sub">
          <?php echo htmlspecialchars(trim($cidade . ($lojaNome !== '' ? ' · ' . $lojaNome : ''))); ?>
        </div>
      </div>
    </header>

    <main class="coletor-main">
      <?php if ($erroGeral !== ''): ?>
      <div class="alert alert--danger" role="alert">
        <div class="alert-text"><?php echo htmlspecialchars($erroGeral); ?></div>
      </div>
      <?php endif; ?>

      <?php if ($item !== null): ?>
      <div class="card card--compacto">
        <div class="coletor-label">Item</div>
        <div class="item-codigo"><?php echo htmlspecialchars((string)$item['item_estoque_pub']); ?></div>
        <div class="item-desc"><?php echo htmlspecialchars((string)$item['descricao']); ?></div>
      </div>
      <?php endif; ?>

      <?php if ($atual !== null): ?>
      <div class="card card--compacto">
        <div class="coletor-label">Locação atual</div>
        <div class="locacao-grande"><?php echo htmlspecialchars($juntar($atual)); ?></div>
      </div>

      <div class="card card--compacto card--primary">
        <!-- Leitura: o caminho normal. -->
        <div id="bloco-bipe" class="<?php echo $digitando ? 'hidden' : ''; ?>">
          <label class="coletor-label" for="etiqueta">Nova locação · bipe a etiqueta</label>
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

        <!-- Digitação: só depois do botão de etiqueta ilegível. Os campos existem
             sempre, porque são eles que vão ao servidor; o bipe só os preenche. -->
        <div id="bloco-digitar" class="<?php echo $digitando ? '' : 'hidden'; ?>">
          <div class="coletor-label">Nova locação · digitada</div>
          <div class="alert alert--warning mb-2" role="status">
            <div class="alert-text">Digitação liberada. Este ajuste fica registrado como digitado.</div>
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
      <?php endif; ?>
    </main>

    <div class="coletor-acoes">
      <a href="conferencia.php" class="btn btn-coletor btn-tertiary">Voltar</a>
      <?php if ($podeEditar): ?>
      <!-- Desabilitado até haver uma locação nova: sem isso, Gravar regravaria a
           locação atual e o rastro diria que ela foi bipada. -->
      <button type="submit" class="btn btn-coletor btn-primary" id="btn-gravar"
              <?php echo $digitando ? '' : 'disabled'; ?>>Gravar</button>
      <?php endif; ?>
    </div>
  </form>

  <script src="includes/coletor-scanner.js?v=<?php echo (int)@filemtime(__DIR__ . '/includes/coletor-scanner.js'); ?>"></script>
  <script>
    // Ordem dos campos na etiqueta — a mesma do locacao_geral que o
    // gerador_inventario.php monta: zona-rua-estante-prateleira-número.
    const CAMPOS_ETIQUETA = <?php echo json_encode(array_keys(CONFERENCIA_LOCACAO_CAMPOS)); ?>;

    const form          = document.getElementById('form-ajuste');
    const inputEtiqueta = document.getElementById('etiqueta');
    const erroEtiqueta  = document.getElementById('etiqueta-erro');
    const locacaoLida   = document.getElementById('locacao-lida');
    const blocoBipe     = document.getElementById('bloco-bipe');
    const blocoDigitar  = document.getElementById('bloco-digitar');
    const btnIlegivel   = document.getElementById('btn-ilegivel');
    const btnGravar     = document.getElementById('btn-gravar');
    const inputEntrada  = document.getElementById('entrada');

    // Devolve as cinco partes, ou null se o que foi bipado não é uma etiqueta
    // de locação — o caso comum é bipar o código do item por engano.
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

    if (inputEtiqueta) {
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
          if (btnGravar) { btnGravar.disabled = false; }

          // O foco fica no campo de bipe, e não vai para o Gravar: o leitor às
          // vezes lê a mesma etiqueta duas vezes, e o Enter do segundo bipe
          // gravaria sem a dupla conferir. Aqui, ele só preenche de novo.
          inputEtiqueta.focus();
        },
        aoRecusar: motivo => {
          mostrarErroEtiqueta(ColetorScanner.mensagemDeRecusa(motivo, 'Etiqueta ilegível'));
        }
      });
    }

    if (btnIlegivel) {
      btnIlegivel.addEventListener('click', () => {
        blocoBipe.classList.add('hidden');
        blocoDigitar.classList.remove('hidden');
        inputEntrada.value = 'digitado';
        if (btnGravar) { btnGravar.disabled = false; }
        document.getElementById('f-' + CAMPOS_ETIQUETA[0]).focus();
      });
    }

    // Ao entrar num campo digitado, o conteúdo fica selecionado: digitar
    // substitui o valor em vez de emendar nele.
    document.querySelectorAll('.loc-grid .form-input').forEach(el => {
      el.addEventListener('focus', () => el.select());
    });

    // Esta tela escreve no Apollo: um segundo toque em Gravar, antes da resposta,
    // não pode virar uma segunda gravação.
    if (form && btnGravar) {
      form.addEventListener('submit', ev => {
        if (btnGravar.disabled) { ev.preventDefault(); return; }
        btnGravar.disabled = true;
        btnGravar.textContent = 'Gravando…';
      });
    }
  </script>
</body>
</html>
