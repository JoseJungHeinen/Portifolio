/**
 * includes/coletor-scanner.js
 *
 * Trava dos campos de bipe das telas de coletor: o campo aceita o que vem do
 * leitor de código de barras e recusa digitação, para não abrir espaço a erro
 * proposital (digitar o código de um item para dar OK sem ter achado a peça, ou
 * lançar uma locação que não é a da prateleira).
 *
 * Uma página web não consegue saber de onde vem uma tecla: o leitor funciona
 * como um teclado. O que separa os dois é o ritmo — o leitor manda o código
 * inteiro em poucos milissegundos, uma pessoa leva mais de 100ms por tecla — e
 * é isso que esta trava mede. Ela resiste à digitação do dia a dia; não resiste
 * a um teclado com macro. Para isso, o caminho é configurar o próprio leitor
 * (no Zebra, o DataWedge) para mandar um prefixo que a tela exija.
 *
 * Uso:
 *   const trava = ColetorScanner.travarCampoDeBipe(input, {
 *     aoLer:     (texto, entrada) => { ... },  // entrada: 'bipado' | 'digitado'
 *     aoRecusar: (motivo) => { ... },          // 'digitado' | 'colado' | 'curto'
 *   });
 *   trava.liberar();  // botão de etiqueta ilegível: aceita digitação, uma vez
 *   trava.travar();
 */
(function (global) {
  'use strict';

  // Média máxima entre caracteres para aceitar como leitor. O leitor fica bem
  // abaixo (tipicamente menos de 30ms); uma pessoa, bem acima (mais de 100ms).
  // Se o leitor for configurado com atraso entre caracteres e começar a ter
  // bipe recusado, é este número que se ajusta.
  const LIMITE_MS_POR_CARACTERE = 50;

  // Abaixo disso não há ritmo para medir: um ou dois caracteres chegam do mesmo
  // jeito pelo leitor e pelo dedo. (No Apollo, 5 itens em 245 mil têm código
  // tão curto; eles passam pelo botão de ilegível.)
  const MINIMO_CARACTERES = 3;

  // Mesma limpeza que a contagem.php faz: alguns leitores mandam caracteres de
  // controle antes ou depois do código.
  function limparScanner(valor) {
    return (valor || '').replace(/[\x00-\x1F\x7F-\x9F]/g, '').trim();
  }

  // Um carimbo de tempo por caractere recebido.
  function ritmoDeLeitor(tempos) {
    const media = (tempos[tempos.length - 1] - tempos[0]) / (tempos.length - 1);
    return media < LIMITE_MS_POR_CARACTERE;
  }

  function travarCampoDeBipe(input, opcoes) {
    let liberado = false;
    let tempos   = [];
    // Apagar, corrigir, autocompletar: nada disso o leitor faz. Qualquer uma
    // dessas marca a entrada como feita à mão.
    let editadoAMao = false;

    function zerar() {
      input.value = '';
      tempos = [];
      editadoAMao = false;
    }

    function modoTeclado(ligado) {
      // 'none' impede o teclado virtual de abrir: no celular, sem teclado na
      // tela, não há onde digitar. Trocar o modo com o campo já focado não abre
      // o teclado em todo aparelho, por isso o blur/focus.
      input.setAttribute('inputmode', ligado ? 'text' : 'none');
      if (document.activeElement === input) {
        input.blur();
        input.focus();
      }
    }

    input.setAttribute('autocomplete', 'off');
    input.setAttribute('autocorrect', 'off');
    input.setAttribute('spellcheck', 'false');
    modoTeclado(false);

    ['paste', 'drop'].forEach(function (tipo) {
      input.addEventListener(tipo, function (ev) {
        if (liberado) { return; }
        ev.preventDefault();
        zerar();
        opcoes.aoRecusar('colado');
      });
    });

    input.addEventListener('input', function (ev) {
      if (liberado) { return; }
      if (input.value === '') { tempos = []; editadoAMao = false; return; }

      const tipo = ev.inputType || 'insertText';
      if (tipo !== 'insertText' && tipo !== 'insertCompositionText') {
        editadoAMao = true;
        return;
      }

      // Alguns leitores injetam o código inteiro num único evento: cada
      // caractere recebe o mesmo carimbo, o que conta como ritmo de leitor.
      const agora = performance.now();
      const n = (ev.data && ev.data.length) || 1;
      for (let i = 0; i < n; i++) { tempos.push(agora); }
    });

    input.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter') { return; }
      // O Enter do leitor nunca envia formulário: quem decide é a tela.
      ev.preventDefault();

      const texto  = limparScanner(input.value);
      const ritmo  = tempos;
      const aMao   = editadoAMao;
      zerar();

      if (texto === '') { return; }

      if (liberado) {
        opcoes.aoLer(texto, 'digitado');
        return;
      }
      if (aMao) {
        opcoes.aoRecusar('digitado');
        return;
      }
      if (ritmo.length < MINIMO_CARACTERES) {
        opcoes.aoRecusar('curto');
        return;
      }
      if (!ritmoDeLeitor(ritmo)) {
        opcoes.aoRecusar('digitado');
        return;
      }
      opcoes.aoLer(texto, 'bipado');
    });

    return {
      liberar: function () { liberado = true;  zerar(); modoTeclado(true);  input.focus(); },
      travar:  function () { liberado = false; zerar(); modoTeclado(false); },
      estaLiberado: function () { return liberado; }
    };
  }

  // Mensagem para cada recusa, igual nas duas telas.
  function mensagemDeRecusa(motivo, botao) {
    if (motivo === 'colado') {
      return 'Colar não é aceito. Use o leitor.';
    }
    if (motivo === 'curto') {
      return 'Código curto demais para o leitor conferir. Use "' + botao + '".';
    }
    return 'Digitação não é aceita. Use o leitor — ou "' + botao + '" se a etiqueta não lê.';
  }

  global.ColetorScanner = {
    travarCampoDeBipe: travarCampoDeBipe,
    mensagemDeRecusa:  mensagemDeRecusa,
    limparScanner:     limparScanner,
    LIMITE_MS_POR_CARACTERE: LIMITE_MS_POR_CARACTERE,
    MINIMO_CARACTERES: MINIMO_CARACTERES
  };
})(window);
