<?php
declare(strict_types=1);

session_start();
require 'conexao_inventario.php';
require 'conferencia_lib.php';

if (empty($_SESSION['inventario'])) {
    header('Location: login.php');
    exit;
}

$contagem = (int)($_SESSION['inventario']['contagem'] ?? 0);
if ($contagem !== 3) {
    header('Location: login.php');
    exit;
}

// Sessão de antes do PIN (nome livre, sem conferente_id) volta para o login.
if ((int)($_SESSION['inventario']['conferente_id'] ?? 0) <= 0) {
    header('Location: login.php');
    exit;
}

$empresa = (int)($_SESSION['inventario']['empresa'] ?? 0);
$revenda = (int)($_SESSION['inventario']['revenda'] ?? 0);
$idInv   = (int)($_SESSION['inventario']['id']      ?? 0);
$dupla   = (string)($_SESSION['inventario']['dupla_label'] ?? 'Dupla');
$semInv  = !empty($_SESSION['inventario']['sem_inventario']);

$lojas = conferencia_lojas_da_cidade($pdo, $empresa, $revenda);

// Nome da cidade para o título.
$stmt = $pdo->prepare("SELECT empresa FROM inv_lojas WHERE codigo = :cod AND ativo = 1 LIMIT 1");
$stmt->execute([':cod' => $empresa . '.' . $revenda]);
$cidade = (string)($stmt->fetchColumn() ?: ('Inventário #' . $idInv));

// Filial (Fiat, Jeep...) ao lado da cidade: só a cidade não diz em qual loja se está.
$filial = '';
foreach ($lojas as $l) {
    if ($l['atual']) {
        $filial = $l['nome'];
    }
}

// Onde a dupla está: bipada antes dos itens, vale até ser trocada. Na sessão
// para sobreviver à ida ao ajuste.
$locacaoAtual = (string)($_SESSION['conferencia_locacao'] ?? '');

// Confirmação deixada pela tela de ajuste, mostrada uma vez só.
$flash = $_SESSION['conferencia_flash'] ?? null;
unset($_SESSION['conferencia_flash']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <?php $pageTitle = 'Conferência'; require __DIR__ . '/includes/head.php'; ?>
</head>
<body class="coletor">
  <header class="coletor-bar">
    <div class="coletor-bar__texto">
      <div class="coletor-bar__titulo">Conferência <span><?php echo htmlspecialchars($cidade . ($filial !== '' ? ' - ' . $filial : '')); ?></span></div>
      <div class="coletor-bar__sub"><?php echo htmlspecialchars($dupla . ($semInv ? ' · sem inventário' : '')); ?></div>
    </div>
    <a href="<?php echo $semInv ? 'logout.php?de=sem_inventario' : 'logout.php'; ?>" class="btn btn-tertiary btn-sm">Sair</a>
  </header>

  <main class="coletor-main">
    <?php if (is_array($flash)): ?>
    <div class="alert alert--success" id="flash" role="status">
      <div class="alert-text">
        Locação de <strong><?php echo htmlspecialchars((string)$flash['item']); ?></strong>
        gravada: <strong><?php echo htmlspecialchars((string)$flash['locacao']); ?></strong>
      </div>
    </div>
    <?php endif; ?>

    <div class="alert alert--success hidden" id="transf-feita" role="status">
      <div class="alert-text" id="transf-feita-texto"></div>
    </div>

    <!-- 1º passo: a locação. Os itens só são bipados depois dela. -->
    <div class="card card--compacto card--primary" id="card-locacao">
      <div id="loc-bipe" class="<?php echo $locacaoAtual !== '' ? 'hidden' : ''; ?>">
        <label class="coletor-label" for="locacao">Locação · bipe a etiqueta da prateleira</label>
        <input class="form-input scan-input" id="locacao" type="text"
               autocapitalize="off" placeholder="ex.: 1-22-13-A-1"
               aria-describedby="locacao-erro">
        <div class="form-error hidden" id="locacao-erro" role="alert"></div>
        <div class="d-flex justify-between align-center mt-2">
          <span class="form-hint" id="locacao-modo" style="margin:0;">Só leitor</span>
          <button type="button" class="btn btn-tertiary btn-sm" id="btn-loc-ilegivel">Etiqueta ilegível</button>
        </div>
      </div>
      <div id="loc-fixa" class="<?php echo $locacaoAtual === '' ? 'hidden' : ''; ?>">
        <div class="coletor-label">Locação</div>
        <div class="d-flex justify-between align-center gap-2">
          <div class="locacao-grande" id="loc-valor"><?php echo htmlspecialchars($locacaoAtual); ?></div>
          <button type="button" class="btn btn-tertiary btn-sm" id="btn-trocar-loc">Trocar locação</button>
        </div>
      </div>
    </div>

    <div class="card card--compacto <?php echo $locacaoAtual === '' ? 'hidden' : ''; ?>" id="card-codigo">
      <label class="coletor-label" for="codigo">Código do item · bipe a peça</label>
      <input class="form-input scan-input" id="codigo" type="text"
             autocapitalize="off" aria-describedby="codigo-erro">
      <div class="form-error hidden" id="codigo-erro" role="alert"></div>
      <div class="d-flex justify-between align-center mt-2">
        <span class="form-hint" id="codigo-modo" style="margin:0;">Só leitor</span>
        <button type="button" class="btn btn-tertiary btn-sm" id="btn-ilegivel">Código ilegível</button>
      </div>
    </div>

    <!-- Confirmação de que o bipe na locação certa gravou a conferência. -->
    <div class="alert alert--success hidden" id="conferido-ok" role="status">
      <div class="alert-text" id="conferido-ok-texto"></div>
    </div>

    <div id="aviso-snapshot" class="alert alert--warning hidden" role="status">
      <div class="alert-text">
        Apollo indisponível. As quantidades vêm do snapshot do inventário, não do Apollo.
      </div>
    </div>

    <div class="card card--compacto hidden" id="card-item">
      <div class="coletor-label">Item</div>
      <div class="d-flex justify-between align-center gap-2">
        <div class="item-codigo" id="item-codigo"></div>
        <span class="badge badge--success hidden" id="selo-conferido">Já conferido</span>
      </div>
      <div class="item-desc" id="item-desc"></div>
    </div>

    <div class="card card--compacto hidden" id="card-lojas">
      <div class="coletor-label" id="lojas-titulo">Lojas</div>
      <div id="lojas-lista"></div>
      <!-- Só com esta filial zerada no Apollo e outra com saldo. -->
      <div id="transf-botoes" class="mt-2"></div>
    </div>
  </main>

  <div class="modal-overlay hidden" id="dlg-backdrop">
    <div class="modal modal--coletor" role="alertdialog" aria-modal="true" aria-labelledby="dlg-msg">
      <div class="modal__body" id="dlg-msg"></div>
      <button type="button" class="btn btn-primary btn-coletor w-100" id="dlg-ok">Entendi</button>
    </div>
  </div>

  <!-- Aviso de locação: só sai no OK, tocado. O botão não recebe foco, para o
       Enter do leitor não fechar o aviso sem a dupla ler. -->
  <div class="modal-overlay hidden" id="aviso-loc-backdrop">
    <div class="modal modal--coletor modal--aviso" role="alertdialog" aria-modal="true"
         aria-labelledby="aviso-loc-titulo" aria-describedby="aviso-loc-texto">
      <div class="modal-aviso__titulo" id="aviso-loc-titulo"></div>
      <div class="modal__body" id="aviso-loc-texto"></div>
      <div class="modal-aviso__lojas" id="aviso-loc-lojas"></div>
      <div class="modal-aviso__locacoes hidden" id="aviso-loc-comparacao">
        <div>
          <div class="coletor-label">Bipada</div>
          <div class="locacao-grande" id="aviso-loc-bipada"></div>
        </div>
        <div>
          <div class="coletor-label">No Apollo</div>
          <div class="locacao-grande" id="aviso-loc-apollo"></div>
        </div>
      </div>
      <button type="button" class="btn btn-danger btn-coletor w-100" id="aviso-loc-ok">OK</button>
      <!-- Locação diferente: a dupla mantém a locação do Apollo (e leva a peça
           para lá) ou troca a do Apollo pela que vai bipar no ajuste. -->
      <div class="hidden" id="aviso-loc-escolha">
        <button type="button" class="btn btn-secondary btn-coletor w-100" id="aviso-loc-manter">Manter locação Apollo</button>
        <button type="button" class="btn btn-danger btn-coletor w-100 mt-2" id="aviso-loc-trocar">Trocar locação Apollo</button>
      </div>
      <!-- Manter: a conferência só grava depois de a dupla levar a peça e bipar
           a etiqueta da locação do Apollo. -->
      <div class="hidden" id="aviso-loc-confirmar">
        <label class="coletor-label" for="manter-locacao">
          Leve a peça para <strong id="manter-destino"></strong> e bipe a etiqueta de lá
        </label>
        <input class="form-input scan-input" id="manter-locacao" type="text"
               autocapitalize="off" placeholder="ex.: 1-22-13-A-1" aria-describedby="manter-erro">
        <div class="form-error hidden" id="manter-erro" role="alert"></div>
        <div class="d-flex justify-between align-center mt-2">
          <span class="form-hint" id="manter-modo" style="margin:0;">Só leitor</span>
          <button type="button" class="btn btn-tertiary btn-sm" id="btn-manter-ilegivel">Etiqueta ilegível</button>
        </div>
        <button type="button" class="btn btn-tertiary btn-coletor w-100 mt-3" id="manter-voltar">Voltar</button>
      </div>
    </div>
  </div>

  <!-- Transferência de saldo de outra filial para esta. Altera o Apollo. -->
  <div class="modal-overlay hidden" id="transf-backdrop">
    <div class="modal modal--coletor modal--transf" role="dialog" aria-modal="true" aria-labelledby="transf-titulo">
      <div class="modal-aviso__titulo modal-transf__titulo" id="transf-titulo"></div>
      <div class="form-hint" id="transf-resumo"></div>

      <label class="coletor-label" for="transf-locacao">Locação · bipe a etiqueta onde a peça está</label>
      <input class="form-input scan-input" id="transf-locacao" type="text"
             autocapitalize="off" placeholder="ex.: 1-22-13-A-1" aria-describedby="transf-locacao-erro">
      <div class="locacao-grande hidden" id="transf-locacao-lida"></div>
      <div class="form-error hidden" id="transf-locacao-erro" role="alert"></div>
      <div class="d-flex justify-between align-center mt-2">
        <span class="form-hint" id="transf-locacao-modo" style="margin:0;">Só leitor</span>
        <button type="button" class="btn btn-tertiary btn-sm" id="btn-transf-ilegivel">Etiqueta ilegível</button>
      </div>

      <label class="coletor-label mt-3" for="transf-qtd">Quantidade</label>
      <input class="form-input" id="transf-qtd" type="number" inputmode="numeric" min="1" step="1">

      <div class="form-error hidden" id="transf-erro" role="alert"></div>

      <div class="d-flex gap-2 mt-3">
        <button type="button" class="btn btn-tertiary btn-coletor" id="transf-cancelar">Cancelar</button>
        <button type="button" class="btn btn-primary btn-coletor" id="transf-confirmar">Transferir</button>
      </div>
    </div>
  </div>

  <script src="includes/coletor-scanner.js?v=<?php echo (int)@filemtime(__DIR__ . '/includes/coletor-scanner.js'); ?>"></script>
  <script>
    const CIDADE = <?php echo json_encode($cidade, JSON_UNESCAPED_UNICODE); ?>;

    const inputCodigo  = document.getElementById('codigo');
    const erroCodigo   = document.getElementById('codigo-erro');
    const modoCodigo   = document.getElementById('codigo-modo');
    const btnIlegivel  = document.getElementById('btn-ilegivel');
    const cardItem     = document.getElementById('card-item');
    const cardLojas    = document.getElementById('card-lojas');
    const avisoSnap    = document.getElementById('aviso-snapshot');
    const elConferido  = document.getElementById('conferido-ok');
    const flash        = document.getElementById('flash');
    const elCodigo     = document.getElementById('item-codigo');
    const elDesc       = document.getElementById('item-desc');
    const elSelo       = document.getElementById('selo-conferido');
    const elLojasLista = document.getElementById('lojas-lista');
    const elLojasTit   = document.getElementById('lojas-titulo');
    const dlgBackdrop  = document.getElementById('dlg-backdrop');
    const dlgMsg       = document.getElementById('dlg-msg');
    const inputLocacao = document.getElementById('locacao');
    const erroLocacao  = document.getElementById('locacao-erro');
    const modoLocacao  = document.getElementById('locacao-modo');
    const btnLocIleg   = document.getElementById('btn-loc-ilegivel');
    const btnTrocarLoc = document.getElementById('btn-trocar-loc');
    const blocoLocBipe = document.getElementById('loc-bipe');
    const blocoLocFixa = document.getElementById('loc-fixa');
    const elLocValor   = document.getElementById('loc-valor');
    const cardCodigo   = document.getElementById('card-codigo');
    const avisoLoc     = document.getElementById('aviso-loc-backdrop');
    const avisoLocTit  = document.getElementById('aviso-loc-titulo');
    const avisoLocTxt  = document.getElementById('aviso-loc-texto');
    const avisoLocComp = document.getElementById('aviso-loc-comparacao');
    const avisoLocOk   = document.getElementById('aviso-loc-ok');
    const avisoLocEsc  = document.getElementById('aviso-loc-escolha');
    const elTransfBotoes = document.getElementById('transf-botoes');
    const elTransfFeita  = document.getElementById('transf-feita');
    const transf = {
      backdrop:  document.getElementById('transf-backdrop'),
      titulo:    document.getElementById('transf-titulo'),
      resumo:    document.getElementById('transf-resumo'),
      locacao:   document.getElementById('transf-locacao'),
      lida:      document.getElementById('transf-locacao-lida'),
      locErro:   document.getElementById('transf-locacao-erro'),
      locModo:   document.getElementById('transf-locacao-modo'),
      ilegivel:  document.getElementById('btn-transf-ilegivel'),
      qtd:       document.getElementById('transf-qtd'),
      erro:      document.getElementById('transf-erro'),
      cancelar:  document.getElementById('transf-cancelar'),
      confirmar: document.getElementById('transf-confirmar')
    };

    // A locação que a dupla bipou. Vazia, a tela espera a etiqueta antes de
    // aceitar item.
    let locacaoAtual = <?php echo json_encode($locacaoAtual, JSON_UNESCAPED_UNICODE); ?>;

    let ocupado = false;
    // O item do aviso de locação diferente, que o Trocar locação Apollo registra.
    let itemDoAviso = null;

    elLojasTit.textContent = 'Lojas — ' + CIDADE;

    const mostrarEl  = el => el && el.classList.remove('hidden');
    const esconderEl = el => el && el.classList.add('hidden');

    document.getElementById('dlg-ok').addEventListener('click', fecharDialogo);

    function abrirDialogo(texto) {
      dlgMsg.textContent = texto;
      mostrarEl(dlgBackdrop);
      document.getElementById('dlg-ok').focus();
    }

    function fecharDialogo() {
      esconderEl(dlgBackdrop);
      prontoParaOProximo();
    }

    function dialogoAberto() {
      return !dlgBackdrop.classList.contains('hidden');
    }

    // O campo que o leitor deve alimentar agora: a etiqueta, enquanto não há
    // locação; depois, o item.
    function campoAtivo() {
      return locacaoAtual === '' ? inputLocacao : inputCodigo;
    }

    function prontoParaOProximo() {
      inputCodigo.value = '';
      inputLocacao.value = '';
      campoAtivo().focus();
    }

    // Some com o resultado anterior, para ninguém confundir bipe novo com tela
    // velha.
    function limparResultado() {
      [cardItem, cardLojas, avisoSnap, elSelo, flash, elTransfFeita, elConferido].forEach(esconderEl);
      elLojasLista.textContent = '';
      elTransfBotoes.textContent = '';
    }

    function classeQtd(saldo) {
      if (saldo === null) { return 'qtd qtd--nd'; }
      return 'qtd ' + (saldo > 0 ? 'qtd--pos' : 'qtd--zero');
    }

    // Vazio: a loja não tem linha de estoque do item, então não há locação.
    // null: fallback de snapshot, que só conhece a loja do inventário.
    function textoLocacao(locacao) {
      if (locacao === null)  { return '—'; }
      if (locacao === '')    { return 'sem locação'; }
      return locacao;
    }

    function mostrar(dados) {
      elCodigo.textContent  = dados.item.item_estoque_pub || '—';
      elDesc.textContent    = dados.item.descricao || '—';
      if (dados.ja_conferido) { mostrarEl(elSelo); }
      if (dados.origem === 'snapshot') { mostrarEl(avisoSnap); }

      elLojasLista.textContent = '';
      dados.lojas.forEach(loja => elLojasLista.appendChild(linhaLoja(loja)));

      montarBotoesTransferencia(dados);

      [cardItem, cardLojas].forEach(mostrarEl);
    }

    // Uma linha da lista de lojas: nome, locação daquela loja e saldo. Usada
    // na tela e no aviso de locação.
    function linhaLoja(loja) {
      const linha = document.createElement('div');
      linha.className = 'loja-row' + (loja.atual ? ' loja-row--atual' : '');

      const textos = document.createElement('div');
      textos.className = 'loja-row__textos';

      const nome = document.createElement('div');
      nome.className = 'loja-row__nome';
      nome.textContent = loja.nome;

      // Cada loja mostra a locação dela: a mesma peça fica em lugar diferente
      // em cada uma, e era isso que a tela antiga escondia.
      const loc = document.createElement('div');
      loc.className = 'loja-row__locacao' + (loja.locacao ? '' : ' loja-row__locacao--vazia');
      loc.textContent = textoLocacao(loja.locacao);

      const qtd = document.createElement('div');
      qtd.className = classeQtd(loja.saldo);
      // null é "não sei" (fallback de snapshot), e não pode parecer zero.
      qtd.textContent = loja.saldo === null ? '—' : String(loja.saldo);

      textos.appendChild(nome);
      textos.appendChild(loc);
      linha.appendChild(textos);
      linha.appendChild(qtd);
      return linha;
    }

    // Peça na prateleira sem saldo em nenhuma filial da cidade: não há de onde
    // transferir. Só com o Apollo — no snapshot as outras lojas são "não sei".
    function semSaldoNaCidade(dados) {
      return dados.origem === 'oracle' &&
        dados.lojas.length > 0 &&
        dados.lojas.every(l => l.saldo !== null && l.saldo <= 0);
    }

    // Sem saldo na cidade, ou o Apollo com o item em outro lugar desta loja ou
    // em lugar nenhum: a dupla precisa ver isso. Devolve true se abriu o aviso.
    function mostrarAvisoLocacao(dados) {
      // A confirmação do Manter é de um item só: cada aviso começa na escolha.
      esconderEl(document.getElementById('aviso-loc-confirmar'));
      if (semSaldoNaCidade(dados)) {
        // Vem antes da locação: a peça sai da prateleira, então onde ela está
        // cadastrada não importa agora.
        avisoLocTit.textContent = 'Sem saldo nas filiais';
        avisoLocTxt.textContent = 'Retirar a peça e avisar a gestão.';
        [avisoLocComp, avisoLocEsc].forEach(esconderEl);
        mostrarEl(avisoLocOk);
      } else if (dados.locacao_situacao === 'outra') {
        avisoLocTit.textContent = 'Locação diferente';
        avisoLocTxt.textContent = 'No Apollo este item está cadastrado em outra locação.';
        document.getElementById('aviso-loc-bipada').textContent = dados.locacao_bipada;
        document.getElementById('aviso-loc-apollo').textContent = dados.item.locacao_geral;
        [avisoLocComp, avisoLocEsc].forEach(mostrarEl);
        esconderEl(avisoLocOk);
      } else if (dados.locacao_situacao === 'sem_locacao') {
        avisoLocTit.textContent = 'Sem locação';
        avisoLocTxt.textContent = 'No Apollo este item não tem locação cadastrada nesta loja.';
        [avisoLocComp, avisoLocEsc].forEach(esconderEl);
        mostrarEl(avisoLocOk);
      } else {
        return false;
      }

      // O saldo de cada loja vai junto, em qualquer aviso.
      const elAvisoLojas = document.getElementById('aviso-loc-lojas');
      elAvisoLojas.textContent = '';
      dados.lojas.forEach(loja => elAvisoLojas.appendChild(linhaLoja(loja)));

      mostrarEl(avisoLoc);
      // Ninguém com foco: o próximo bipe não cai no campo do item nem aperta o OK.
      if (document.activeElement) { document.activeElement.blur(); }
      return true;
    }

    function avisoLocacaoAberto() {
      return !avisoLoc.classList.contains('hidden');
    }

    // OK (sem saldo, sem locação): a dupla segue na mesma locação, com o item
    // na tela.
    function fecharAvisoLocacao() {
      esconderEl(avisoLoc);
      itemDoAviso = null;
      prontoParaOProximo();
    }

    avisoLocOk.addEventListener('click', fecharAvisoLocacao);

    // Manter locação Apollo: a peça vai para a locação do Apollo, e a dupla
    // confirma bipando a etiqueta de lá. Só então grava 'ok'. A locação que a
    // dupla está conferindo continua a mesma: depois, segue no próximo item.
    const avisoLocConf  = document.getElementById('aviso-loc-confirmar');
    const manterInput   = document.getElementById('manter-locacao');
    const manterErro    = document.getElementById('manter-erro');
    const manterModo    = document.getElementById('manter-modo');
    const manterIleg    = document.getElementById('btn-manter-ilegivel');
    let manterOcupado   = false;

    function mostrarErroManter(texto) {
      manterErro.textContent = texto;
      mostrarEl(manterErro);
    }

    function modoLeitorManter() {
      travaManter.travar();
      manterModo.textContent = 'Só leitor';
      manterIleg.disabled = false;
    }

    document.getElementById('aviso-loc-manter').addEventListener('click', () => {
      if (!itemDoAviso) { fecharAvisoLocacao(); return; }
      document.getElementById('manter-destino').textContent = itemDoAviso.item.locacao_geral;
      esconderEl(avisoLocEsc);
      esconderEl(manterErro);
      modoLeitorManter();
      manterInput.value = '';
      mostrarEl(avisoLocConf);
      manterInput.focus();
    });

    // Volta para a escolha Manter / Trocar, sem gravar nada.
    document.getElementById('manter-voltar').addEventListener('click', () => {
      esconderEl(avisoLocConf);
      mostrarEl(avisoLocEsc);
      modoLeitorManter();
      if (document.activeElement) { document.activeElement.blur(); }
    });

    const travaManter = ColetorScanner.travarCampoDeBipe(manterInput, {
      aoLer: async (texto, entradaLoc) => {
        if (manterOcupado || !itemDoAviso) { return; }
        manterOcupado = true;
        esconderEl(manterErro);
        const dados = itemDoAviso;
        try {
          const resp = await fetch('conferencia_ajax.php?modo=confirmar_manter', {
            method: 'POST',
            body: new URLSearchParams({
              item_estoque_pub: dados.item.item_estoque_pub,
              cod_item_estoque: dados.item.cod_item_estoque || '',
              descricao:        dados.item.descricao || '',
              locacao_geral:    dados.item.locacao_geral || '',
              saldo_oracle:     lojaAtualSaldo(dados),
              entrada:          dados.entrada,
              locacao:          texto,
              entrada_locacao:  entradaLoc
            })
          });
          const r = await resp.json();
          if (!r.ok) {
            if ((r.mensagem || '').toLowerCase().includes('sess')) {
              window.location.href = 'login.php';
              return;
            }
            mostrarErroManter(r.mensagem || 'Não foi possível confirmar. Bipe de novo.');
            manterInput.value = '';
            manterInput.focus();
            return;
          }
          esconderEl(avisoLocConf);
          esconderEl(avisoLoc);
          itemDoAviso = null;
          modoLeitorManter();
          // Só depois de o servidor confirmar: a faixa é a prova de que gravou.
          document.getElementById('conferido-ok-texto').textContent =
            '✓ Conferido — ' + dados.item.item_estoque_pub + ' mantido em ' + r.locacao;
          mostrarEl(elConferido);
          prontoParaOProximo();
        } catch (e) {
          mostrarErroManter('Falha de comunicação. Bipe a etiqueta de novo.');
          manterInput.value = '';
          manterInput.focus();
        } finally {
          manterOcupado = false;
        }
      },
      aoRecusar: motivo => {
        mostrarErroManter(ColetorScanner.mensagemDeRecusa(motivo, 'Etiqueta ilegível'));
      }
    });

    manterIleg.addEventListener('click', () => {
      esconderEl(manterErro);
      travaManter.liberar();
      manterModo.textContent = 'Digitação liberada — fica registrada';
      manterIleg.disabled = true;
    });

    // Trocar locação Apollo: grava como troca e abre o ajuste, onde a dupla
    // bipa a locação nova que vai para o Apollo.
    const btnTrocarApollo = document.getElementById('aviso-loc-trocar');
    btnTrocarApollo.addEventListener('click', async () => {
      if (!itemDoAviso) { fecharAvisoLocacao(); return; }
      btnTrocarApollo.disabled = true;
      try {
        // A gravação vem antes, então o item fica marcado mesmo que a dupla
        // desista do ajuste no meio do caminho.
        await registrar(itemDoAviso, 'troca');
        irParaAjuste(itemDoAviso);
      } catch (e) {
        // O aviso fica aberto: a dupla aperta de novo.
        btnTrocarApollo.disabled = false;
        abrirDialogo('Não foi possível registrar. Aperte Trocar locação Apollo de novo.');
      }
    });

    // A loja não vai na URL: a tela de ajuste lê da sessão, a mesma loja que
    // está sendo conferida aqui.
    function irParaAjuste(dados) {
      window.location.href = 'conferencia_ajuste.php?item=' + encodeURIComponent(dados.item.item_estoque_pub);
    }

    function lojaAtualSaldo(dados) {
      const atual = dados.lojas.find(l => l.atual);
      return (atual && atual.saldo !== null) ? String(atual.saldo) : '';
    }

    // Grava a conferência do item. Sem botão de decisão: quem chama é o bipe
    // na locação certa (ok) ou o Trocar locação Apollo do aviso (troca).
    async function registrar(dados, status) {
      const corpo = new URLSearchParams({
        item_estoque_pub: dados.item.item_estoque_pub,
        cod_item_estoque: dados.item.cod_item_estoque || '',
        descricao:        dados.item.descricao || '',
        locacao_geral:    dados.item.locacao_geral || '',
        saldo_oracle:     lojaAtualSaldo(dados),
        origem:           dados.origem,
        status:           status,
        // Como o código deste item chegou: é o rastro do botão "Código ilegível".
        entrada:          dados.entrada
      });

      const resp      = await fetch('conferencia_ajax.php?modo=registrar', { method: 'POST', body: corpo });
      const corpoResp = await resp.json();

      if (!corpoResp.ok) {
        throw new Error(corpoResp.mensagem || 'Não foi possível registrar');
      }

      // Só depois de o servidor confirmar: a faixa é a prova de que gravou.
      document.getElementById('conferido-ok-texto').textContent = '✓ Conferido — ' + dados.item.item_estoque_pub;
      mostrarEl(elConferido);
    }

    // gravar = false: a transferência busca de novo um item que já foi gravado
    // no bipe, só para mostrar os saldos novos.
    async function buscar(codigo, entrada, gravar = true) {
      if (ocupado) { return; }
      ocupado = true;
      limparResultado();

      try {
        const resp  = await fetch('conferencia_ajax.php?modo=buscar&codigo=' + encodeURIComponent(codigo));
        const dados = await resp.json();

        if (!dados.ok) {
          if ((dados.mensagem || '').toLowerCase().includes('sess')) {
            window.location.href = 'login.php';
            return;
          }
          if (dados.sem_locacao) {
            // A sessão perdeu a locação: a tela volta a pedir a etiqueta.
            aplicarLocacao('');
          }
          abrirDialogo(dados.mensagem || 'Item não encontrado');
          return;
        }

        // A entrada fica presa ao item: vai no registro, e a transferência
        // busca o item de novo com ela.
        dados.entrada = entrada;

        mostrar(dados);

        // Locação certa: o bipe já é a conferência. Locação diferente espera
        // o aviso; sem locação no Apollo não grava.
        if (gravar && dados.locacao_situacao === 'confere') {
          try {
            await registrar(dados, 'ok');
          } catch (e) {
            // Não pode se perder em silêncio: a dupla bipa de novo.
            abrirDialogo('Não foi possível registrar. Bipe o item de novo.');
            return;
          }
        }

        itemDoAviso = (gravar && dados.locacao_situacao === 'outra') ? dados : null;

        // Com o aviso de locação aberto, o foco fica com ninguém até o OK.
        if (!mostrarAvisoLocacao(dados)) {
          prontoParaOProximo();
        }
      } catch (e) {
        abrirDialogo('Falha de comunicação. Tente bipar de novo.');
      } finally {
        ocupado = false;
      }
    }

    function mostrarErroCodigo(texto) {
      erroCodigo.textContent = texto;
      mostrarEl(erroCodigo);
    }

    function modoLeitor() {
      trava.travar();
      modoCodigo.textContent = 'Só leitor';
      btnIlegivel.disabled = false;
    }

    const trava = ColetorScanner.travarCampoDeBipe(inputCodigo, {
      aoLer: (codigo, entrada) => {
        // Bipe com o aviso aberto não troca o item por baixo dele.
        if (avisoLocacaoAberto() || transfAberta()) { inputCodigo.value = ''; return; }
        esconderEl(erroCodigo);
        // A liberação vale para um item só: o próximo volta a exigir o leitor.
        modoLeitor();
        buscar(codigo, entrada);
      },
      aoRecusar: motivo => {
        mostrarErroCodigo(ColetorScanner.mensagemDeRecusa(motivo, 'Código ilegível'));
      }
    });

    btnIlegivel.addEventListener('click', () => {
      esconderEl(erroCodigo);
      trava.liberar();
      modoCodigo.textContent = 'Digitação liberada — fica registrada';
      btnIlegivel.disabled = true;
    });

    // ── Locação ────────────────────────────────────────────────────────────
    function aplicarLocacao(locacao) {
      locacaoAtual = locacao;
      elLocValor.textContent = locacao;
      if (locacao === '') {
        limparResultado();
        [blocoLocFixa, cardCodigo].forEach(esconderEl);
        mostrarEl(blocoLocBipe);
      } else {
        esconderEl(blocoLocBipe);
        [blocoLocFixa, cardCodigo].forEach(mostrarEl);
      }
      prontoParaOProximo();
    }

    async function salvarLocacao(texto) {
      const resp  = await fetch('conferencia_ajax.php?modo=locacao', {
        method: 'POST',
        body: new URLSearchParams({ locacao: texto })
      });
      return resp.json();
    }

    function modoLeitorLocacao() {
      travaLocacao.travar();
      modoLocacao.textContent = 'Só leitor';
      btnLocIleg.disabled = false;
    }

    const travaLocacao = ColetorScanner.travarCampoDeBipe(inputLocacao, {
      aoLer: async texto => {
        esconderEl(erroLocacao);
        modoLeitorLocacao();
        try {
          const r = await salvarLocacao(texto);
          if (!r.ok) {
            if ((r.mensagem || '').toLowerCase().includes('sess')) {
              window.location.href = 'login.php';
              return;
            }
            erroLocacao.textContent = r.mensagem || 'Etiqueta de locação inválida';
            mostrarEl(erroLocacao);
            inputLocacao.value = '';
            return;
          }
          aplicarLocacao(r.locacao);
        } catch (e) {
          abrirDialogo('Falha de comunicação. Bipe a locação de novo.');
        }
      },
      aoRecusar: motivo => {
        erroLocacao.textContent = ColetorScanner.mensagemDeRecusa(motivo, 'Etiqueta ilegível');
        mostrarEl(erroLocacao);
      }
    });

    btnLocIleg.addEventListener('click', () => {
      esconderEl(erroLocacao);
      travaLocacao.liberar();
      modoLocacao.textContent = 'Digitação liberada';
      btnLocIleg.disabled = true;
      inputLocacao.focus();
    });

    async function trocarLocacao() {
      try {
        await salvarLocacao('');
      } catch (e) {
        // A tela volta a pedir a etiqueta de qualquer jeito, e o próximo bipe
        // de locação sobrescreve a da sessão.
      }
      aplicarLocacao('');
    }

    btnTrocarLoc.addEventListener('click', trocarLocacao);

    // ── Transferência entre filiais ────────────────────────────────────────
    // Esta filial zerada no Apollo, outra da cidade com saldo: a peça está na
    // prateleira daqui, então a dupla passa o saldo para cá. Altera o Apollo.
    let transfAtual = null;   // {dados, origem}
    let transfLocacao = null; // {texto, entrada}

    function montarBotoesTransferencia(dados) {
      elTransfBotoes.textContent = '';
      if (dados.origem !== 'oracle') { return; }

      const atual = dados.lojas.find(l => l.atual);
      if (!atual || atual.saldo === null || atual.saldo > 0) { return; }

      dados.lojas
        .filter(l => !l.atual && l.saldo !== null && l.saldo > 0)
        .forEach(origem => {
          const b = document.createElement('button');
          b.type = 'button';
          b.className = 'btn btn-secondary w-100 mt-2';
          b.textContent = 'Transferir de ' + origem.nome + ' para ' + atual.nome;
          b.addEventListener('click', () => abrirTransferencia(dados, origem, atual));
          elTransfBotoes.appendChild(b);
        });
    }

    function transfAberta() {
      return !transf.backdrop.classList.contains('hidden');
    }

    function mostrarLocacaoTransf(loc) {
      transfLocacao = loc;
      if (loc) {
        transf.lida.textContent = loc.texto;
        mostrarEl(transf.lida);
        esconderEl(transf.locacao);
      } else {
        transf.lida.textContent = '';
        esconderEl(transf.lida);
        mostrarEl(transf.locacao);
      }
    }

    function abrirTransferencia(dados, origem, atual) {
      transfAtual = { dados: dados, origem: origem };
      transf.titulo.textContent = 'Transferir de ' + origem.nome + ' para ' + atual.nome;
      transf.resumo.textContent = origem.nome + ' tem ' + origem.saldo + ' disponível. ' +
        'O saldo muda no Apollo na hora.';
      transf.qtd.value = '1';
      transf.qtd.max = String(origem.saldo);
      [transf.locErro, transf.erro].forEach(esconderEl);
      modoLeitorTransf();
      mostrarLocacaoTransf(null);
      transf.confirmar.disabled = false;
      mostrarEl(transf.backdrop);
      transf.locacao.focus();
    }

    function fecharTransferencia() {
      esconderEl(transf.backdrop);
      transfAtual = null;
      mostrarLocacaoTransf(null);
      prontoParaOProximo();
    }

    function modoLeitorTransf() {
      travaTransf.travar();
      transf.locModo.textContent = 'Só leitor';
      transf.ilegivel.disabled = false;
    }

    function erroTransf(el, texto) {
      el.textContent = texto;
      mostrarEl(el);
    }

    const travaTransf = ColetorScanner.travarCampoDeBipe(transf.locacao, {
      aoLer: (texto, entrada) => {
        esconderEl(transf.locErro);
        modoLeitorTransf();
        // A checagem de verdade é no servidor; aqui só pega o item bipado por engano.
        if (texto.split(/\s*[-–—]\s*/).length !== 5) {
          erroTransf(transf.locErro, '"' + texto + '" não é uma etiqueta de locação. O formato é 1-22-13-A-1.');
          return;
        }
        mostrarLocacaoTransf({ texto: texto.toUpperCase(), entrada: entrada });
        transf.qtd.focus();
        transf.qtd.select();
      },
      aoRecusar: motivo => {
        erroTransf(transf.locErro, ColetorScanner.mensagemDeRecusa(motivo, 'Etiqueta ilegível'));
      }
    });

    transf.ilegivel.addEventListener('click', () => {
      esconderEl(transf.locErro);
      mostrarLocacaoTransf(null);
      travaTransf.liberar();
      transf.locModo.textContent = 'Digitação liberada — fica registrada';
      transf.ilegivel.disabled = true;
    });

    // Tocar na locação lida volta a pedir o bipe.
    transf.lida.addEventListener('click', () => {
      mostrarLocacaoTransf(null);
      transf.locacao.focus();
    });

    transf.cancelar.addEventListener('click', fecharTransferencia);

    transf.confirmar.addEventListener('click', async () => {
      if (!transfAtual) { return; }
      esconderEl(transf.erro);

      if (!transfLocacao) {
        erroTransf(transf.locErro, 'Bipe a etiqueta da locação.');
        transf.locacao.focus();
        return;
      }
      const qtd = transf.qtd.value.trim();
      const max = transfAtual.origem.saldo;
      if (!/^\d+$/.test(qtd) || Number(qtd) < 1 || Number(qtd) > max) {
        erroTransf(transf.erro, 'Quantidade de 1 a ' + max + '.');
        transf.qtd.focus();
        return;
      }

      const dados  = transfAtual.dados;
      const origem = transfAtual.origem;
      transf.confirmar.disabled = true;

      let r;
      try {
        const resp = await fetch('conferencia_ajax.php?modo=transferir', {
          method: 'POST',
          body: new URLSearchParams({
            item_estoque_pub: dados.item.item_estoque_pub,
            cod_item_estoque: dados.item.cod_item_estoque || '',
            descricao:        dados.item.descricao || '',
            revenda_origem:   String(origem.revenda),
            quantidade:       qtd,
            locacao:          transfLocacao.texto,
            entrada_locacao:  transfLocacao.entrada
          })
        });
        r = await resp.json();
      } catch (e) {
        transf.confirmar.disabled = false;
        erroTransf(transf.erro, 'Falha de comunicação. Confira o saldo no Apollo antes de tentar de novo.');
        return;
      }

      if (!r.ok) {
        if ((r.mensagem || '').toLowerCase().includes('sess')) {
          window.location.href = 'login.php';
          return;
        }
        transf.confirmar.disabled = false;
        if (r.campo === 'locacao') {
          mostrarLocacaoTransf(null);
          erroTransf(transf.locErro, r.mensagem);
          transf.locacao.focus();
        } else {
          erroTransf(transf.erro, r.mensagem || 'Não foi possível transferir.');
        }
        return;
      }

      const msg = 'Transferido: ' + r.quantidade + ' de ' + origem.nome + '. Locação ' + r.locacao + '.';
      fecharTransferencia();
      // Busca de novo para a tela mostrar os saldos novos.
      // A confirmação vem depois, porque a busca limpa a tela; e em faixa, não
      // em diálogo, para não brigar com um aviso de locação que a busca abra.
      await buscar(dados.item.item_estoque_pub, dados.entrada, false);
      document.getElementById('transf-feita-texto').textContent = msg;
      mostrarEl(elTransfFeita);
    });

    // O leitor de barras precisa do foco em qualquer toque na tela.
    document.addEventListener('click', ev => {
      if (!dialogoAberto() && !avisoLocacaoAberto() && !transfAberta() && ev.target !== inputCodigo && ev.target !== inputLocacao) {
        campoAtivo().focus();
      }
    });

    campoAtivo().focus();
  </script>
</body>
</html>
