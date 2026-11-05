<?php
// @versao Imp_01 V_05_07/08/2026
/**
 * views/importar/index.php
 * Importação de PDFs em lote — fatura e NFS-e viram processos.
 *
 * Três estados na mesma view, controlados por $etapa:
 *   selecao     → departamento, setor e arquivos
 *   conferencia → uma linha por PDF, tudo editável, nada gravado ainda
 *   resultado   → o que foi criado, o que foi pulado e o que falhou
 *
 * O template é escolhido POR LINHA, não pelo lote: o setor recorta a lista e a
 * leitura do PDF sugere um. Data e forma de pagamento só aparecem quando o
 * template escolhido tem financeiro.
 */

$etapa         = $etapa         ?? 'selecao';
$departamentos = $departamentos ?? [];
$setores       = $setores       ?? [];
$templates     = $templates     ?? [];
$formasPgto    = $formasPgto    ?? [];
$linhas        = $linhas        ?? [];
$filiais       = $filiais       ?? [];
$setor         = $setor         ?? null;
$resultado     = $resultado     ?? ['criados' => [], 'ignorados' => [], 'erros' => []];
$extratorOk    = $extratorOk    ?? true;

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
/** 11.222.333/0001-81 — CNPJ cru do PDF fica ilegível na conferência. */
if (!function_exists('imp_doc')) {
    function imp_doc(string $d): string {
        if (strlen($d) === 14) {
            return substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12,2);
        }
        if (strlen($d) === 11) {
            return substr($d,0,3).'.'.substr($d,3,3).'.'.substr($d,6,3).'-'.substr($d,9,2);
        }
        return $d;
    }
}
?>
<meta name="csrf-token" content="<?= h(Csrf::token()) ?>">

<?php include VIEWS . '/components/alert.php'; ?>

<div style="margin-bottom:24px">
  <h1 style="font-size:32px;font-weight:700;color:#141922;margin:0 0 4px">
    Importar <span style="color:#2347C8">PDFs</span>
  </h1>
  <p style="font-size:14px;color:#888;margin:0">
    Fatura e NFS-e viram processos em lote — o sistema lê os campos e você confere antes de abrir.
  </p>
</div>

<?php if ($etapa === 'selecao'): ?>

  <?php if (!$extratorOk): ?>
  <div style="background:#FFE8EE;border-left:4px solid #B3261E;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#141922">
    <strong>Leitor de PDF indisponível no servidor.</strong>
    O `pdftotext` (poppler-utils) não foi encontrado — a importação não consegue ler os arquivos.
  </div>
  <?php endif; ?>

  <form method="POST" action="?module=importar&amp;action=analisar" enctype="multipart/form-data"
        style="background:#fff;border-radius:12px;box-shadow:rgba(0,0,0,0.10) 0 2px 8px;padding:28px 32px;max-width:720px">
    <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
      <div>
        <label style="display:block;font-size:13px;font-weight:700;color:#606060;margin-bottom:6px">Departamento</label>
        <select id="imp-departamento" required
                style="width:100%;height:40px;padding:0 12px;border:1px solid #DFDFDF;border-radius:4px;font-size:14px;background:#fff">
          <option value="">Selecione…</option>
          <?php foreach ($departamentos as $d): ?>
          <option value="<?= h($d['id']) ?>"><?= h($d['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="display:block;font-size:13px;font-weight:700;color:#606060;margin-bottom:6px">Setor</label>
        <select name="setor_id" id="imp-setor" required disabled
                style="width:100%;height:40px;padding:0 12px;border:1px solid #DFDFDF;border-radius:4px;font-size:14px;background:#F6F6F6">
          <option value="">Selecione o departamento primeiro</option>
        </select>
      </div>
    </div>
    <p style="font-size:12px;color:#888;margin:-12px 0 20px">
      O setor define quais templates ficam disponíveis na conferência — cada arquivo escolhe o seu.
    </p>

    <label style="display:block;font-size:13px;font-weight:700;color:#606060;margin-bottom:6px">Arquivos PDF</label>
    <input type="file" name="pdfs[]" accept="application/pdf" multiple required
           style="width:100%;padding:10px;border:1px dashed #DFDFDF;border-radius:4px;font-size:14px;background:#F8F8F8;margin-bottom:8px">
    <p style="font-size:12px;color:#888;margin:0 0 8px" id="imp-dica">
      Pode selecionar vários de uma vez. O nome do arquivo não importa — os dados são lidos de dentro do PDF.
      PDF escaneado (imagem) não tem texto: a linha vem em branco para você preencher.
    </p>
    <?php
      // Limites REAIS do PHP deste servidor. Se o envio passar de post_max_size,
      // o corpo é descartado antes de chegar ao controller — por isso avisamos
      // antes, no navegador, em vez de deixar o usuário levar um erro obscuro.
      $emBytes = static function (string $v): int {
          $v = trim($v); if ($v === '') return 0;
          $n = (int) $v;
          return match (strtolower(substr($v, -1))) {
              'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
          };
      };
      $maxArquivos = (int) ini_get('max_file_uploads');
      $maxPorArq   = min($emBytes((string) ini_get('upload_max_filesize')), MAX_UPLOAD_SIZE);
      $maxTotal    = $emBytes((string) ini_get('post_max_size'));
      $mb          = static fn(int $b): string => number_format($b / 1048576, 1, ',', '.') . ' MB';
    ?>
    <p style="font-size:12px;color:#606060;background:#F8F8F8;border-radius:6px;padding:10px 12px;margin:0 0 22px">
      <strong>Limites deste servidor:</strong>
      até <strong><?= $maxArquivos ?></strong> arquivos por envio ·
      <strong><?= $mb($maxPorArq) ?></strong> por arquivo ·
      <strong><?= $mb($maxTotal) ?></strong> somando todos.
      Passando disso, envie em levas menores.
    </p>

    <script>
      (function () {
        var SETORES = <?= json_encode(array_map(fn($s) => [
              'id'   => $s['id'],
              'nome' => $s['nome'],
              'dep'  => $s['departamento_id'],
            ], $setores), JSON_UNESCAPED_UNICODE) ?>;

        var dep   = document.getElementById('imp-departamento');
        var setor = document.getElementById('imp-setor');

        dep.addEventListener('change', function () {
          // Setor sem departamento aparece em todos: cadastro incompleto não
          // pode deixar um setor inalcançável na importação.
          var lista = SETORES.filter(function (s) { return s.dep === dep.value || !s.dep; });
          setor.innerHTML = '';
          if (!lista.length) {
            setor.disabled = true;
            setor.style.background = '#F6F6F6';
            setor.appendChild(new Option(dep.value ? 'Nenhum setor neste departamento' : 'Selecione o departamento primeiro', ''));
            return;
          }
          setor.disabled = false;
          setor.style.background = '#fff';
          setor.appendChild(new Option('Selecione…', ''));
          lista.forEach(function (s) { setor.appendChild(new Option(s.nome, s.id)); });
        });

        var MAX_ARQ   = <?= $maxArquivos ?>;
        var MAX_ARQ_B = <?= $maxPorArq ?>;
        var MAX_TOT_B = <?= $maxTotal ?>;
        var input = document.querySelector('input[name="pdfs[]"]');
        var dica  = document.getElementById('imp-dica');
        if (!input || !dica) return;

        function mb(b) { return (b / 1048576).toFixed(1).replace('.', ',') + ' MB'; }

        input.addEventListener('change', function () {
          var arqs = Array.prototype.slice.call(input.files || []);
          var total = arqs.reduce(function (s, f) { return s + f.size; }, 0);
          var erros = [];

          if (arqs.length > MAX_ARQ) erros.push(arqs.length + ' arquivos — o servidor aceita ' + MAX_ARQ + ' por envio');
          arqs.forEach(function (f) {
            if (f.size > MAX_ARQ_B) erros.push(f.name + ' tem ' + mb(f.size) + ' (máx. ' + mb(MAX_ARQ_B) + ')');
          });
          if (total > MAX_TOT_B) erros.push('a soma dá ' + mb(total) + ' (máx. ' + mb(MAX_TOT_B) + ')');

          if (erros.length) {
            dica.style.color = '#B3261E';
            dica.textContent = 'Ajuste antes de enviar: ' + erros.join(' · ') + '.';
          } else {
            dica.style.color = '#00A960';
            dica.textContent = arqs.length + ' arquivo(s) selecionado(s) · ' + mb(total) + ' no total.';
          }
        });
      })();
    </script>

    <button type="submit" <?= $extratorOk ? '' : 'disabled' ?>
            style="background:#2347C8;color:#fff;border:none;border-radius:4px;padding:10px 22px;font-size:14px;font-weight:700;cursor:pointer">
      Ler PDFs
    </button>
  </form>

<?php elseif ($etapa === 'conferencia'): ?>

  <?php
    $comErro   = count(array_filter($linhas, fn($l) => !empty($l['erro'])));
    $prontas   = count($linhas) - $comErro;
    $semTpl    = count(array_filter($linhas, fn($l) => empty($l['erro']) && empty($l['template_id'])));
  ?>
  <div style="background:#fff;border-radius:12px;box-shadow:rgba(0,0,0,0.10) 0 2px 8px;padding:16px 20px;margin-bottom:16px;font-size:13px;color:#606060">
    Setor: <strong style="color:#141922"><?= h($setor['nome'] ?? '—') ?></strong> ·
    <?= count($templates) ?> template(s) disponível(is) ·
    <?= count($linhas) ?> arquivo(s) lido(s) · <?= $prontas ?> pronta(s)
    <?php if ($comErro): ?> · <span style="color:#B3261E"><?= $comErro ?> com problema</span><?php endif; ?>
    <?php if ($semTpl): ?> · <span style="color:#FF8A2D"><?= $semTpl ?> sem template sugerido</span><?php endif; ?>
    <div style="margin-top:6px;color:#888">
      Confira cada linha antes de abrir os processos — os campos vieram da leitura automática e são editáveis.
      O template sugerido considera a filial do destinatário e a faixa de valor; troque quando não bater.
    </div>
  </div>

  <form method="POST" action="?module=importar&amp;action=criar">
    <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">

    <div style="background:#fff;border-radius:12px;box-shadow:rgba(0,0,0,0.10) 0 2px 8px;overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;min-width:1560px">
        <thead>
          <tr style="background:#606060">
            <?php foreach ([
              '', 'Arquivo', 'Tipo', 'Fornecedor (CNPJ/CPF / nome)', 'Template',
              'Destinatário / filial', 'Número', 'Data', 'Valor', 'Data pagamento', 'Forma de pagamento',
            ] as $th): ?>
            <th style="padding:12px 14px;text-align:left;font-size:12px;font-weight:700;color:#fff;text-transform:uppercase;white-space:nowrap"><?= h($th) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($linhas as $i => $l):
            $temErro = !empty($l['erro']);
            $bg      = $temErro ? '#FFF6F8' : ($i % 2 ? '#F8F8F8' : '#FFFFFF');
            $inp     = 'width:100%;height:34px;padding:0 8px;border:1px solid #DFDFDF;border-radius:4px;font-size:13px;box-sizing:border-box';
            $tplSel  = (string) ($l['template_id'] ?? '');
            $financeiro = $tplSel !== '' && (int) ($templates[$tplSel]['tem_financeiro'] ?? 0) === 1;
          ?>
          <tr style="background:<?= $bg ?>;border-bottom:1px solid #EAEAEA;vertical-align:top">
            <td style="padding:12px 14px">
              <input type="checkbox" name="incluir[<?= $i ?>]" value="1"
                     <?= $temErro ? '' : 'checked' ?> style="width:16px;height:16px;accent-color:#2347C8">
            </td>
            <td style="padding:12px 14px;font-size:12px;color:#141922;max-width:190px;word-break:break-all">
              <?= h($l['nome_arquivo']) ?>
              <?php if ($temErro): ?>
              <div style="color:#B3261E;font-size:11px;margin-top:4px"><?= h($l['erro']) ?></div>
              <?php endif; ?>
              <?php if (!empty($l['aviso'])): ?>
              <div style="color:#FF8A2D;font-size:11px;margin-top:4px"><?= h($l['aviso']) ?></div>
              <?php endif; ?>
              <?php if (!empty($l['duplicado'])): ?>
              <div style="color:#B3261E;font-size:11px;margin-top:4px;font-weight:700">
                Já existe: <?= h($l['duplicado']) ?>
              </div>
              <?php endif; ?>
              <input type="hidden" name="chave[<?= $i ?>]" value="<?= h($l['chave']) ?>">
            </td>
            <td style="padding:12px 14px;font-size:12px;color:#606060"><?= h($l['tipo']) ?></td>

            <td style="padding:12px 14px;min-width:210px">
              <input type="text" name="fornecedor_cnpj[<?= $i ?>]" value="<?= h($l['fornecedor_cnpj']) ?>"
                     placeholder="CNPJ ou CPF" style="<?= $inp ?>;margin-bottom:4px">
              <input type="text" name="fornecedor_nome[<?= $i ?>]" value="<?= h($l['fornecedor_nome']) ?>"
                     placeholder="Razão social" style="<?= $inp ?>">
            </td>

            <!-- Template da linha: sugerido pela leitura, sempre trocável -->
            <td style="padding:12px 14px;min-width:230px">
              <select name="template_id[<?= $i ?>]" class="imp-tpl" data-linha="<?= $i ?>"
                      style="<?= $inp ?>;<?= $tplSel === '' ? 'border-color:#FF8A2D' : '' ?>">
                <option value="">Selecione o template…</option>
                <?php foreach ($templates as $tid => $t): ?>
                <option value="<?= h($tid) ?>" data-financeiro="<?= (int) $t['tem_financeiro'] ?>"
                        <?= $tplSel === (string) $tid ? 'selected' : '' ?>><?= h($t['nome']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($tplSel === ''): ?>
              <div style="color:#FF8A2D;font-size:11px;margin-top:4px">Sem sugestão — escolha o fluxo.</div>
              <?php endif; ?>
            </td>

            <!-- CNPJ lido do destinatário + a filial que ele resolveu -->
            <td style="padding:12px 14px;min-width:200px">
              <div style="height:34px;display:flex;align-items:center;padding:0 8px;margin-bottom:4px;
                          background:#F6F6F6;border:1px solid #EAEAEA;border-radius:4px;
                          font-size:13px;color:<?= $l['destinatario_cnpj'] !== '' ? '#141922' : '#888' ?>">
                <?= $l['destinatario_cnpj'] !== '' ? h(imp_doc($l['destinatario_cnpj'])) : 'não lido' ?>
              </div>
              <select name="filial_id[<?= $i ?>]"
                      style="<?= $inp ?>;<?= empty($l['filial_id']) ? 'border-color:#FF8A2D' : '' ?>">
                <option value="">Selecione a filial…</option>
                <?php foreach ($filiais as $f): ?>
                <option value="<?= h($f['id']) ?>" <?= ($l['filial_id'] ?? '') === $f['id'] ? 'selected' : '' ?>>
                  <?= h($f['nome']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </td>

            <td style="padding:12px 14px;min-width:110px">
              <input type="text" name="numero[<?= $i ?>]" value="<?= h($l['numero']) ?>" style="<?= $inp ?>">
            </td>
            <td style="padding:12px 14px;min-width:110px">
              <input type="text" name="data[<?= $i ?>]" value="<?= h($l['data']) ?>" placeholder="dd/mm/aaaa"
                     class="imp-data" style="<?= $inp ?>">
            </td>
            <td style="padding:12px 14px;min-width:110px">
              <input type="text" name="valor[<?= $i ?>]" value="<?= h($l['valor']) ?>" placeholder="0,00"
                     style="<?= $inp ?>;<?= $l['valor'] === '' ? 'border-color:#B3261E' : '' ?>">
            </td>

            <!-- Pagamento: só vale em template financeiro; vira 1 parcela de previsão -->
            <td style="padding:12px 14px;min-width:120px">
              <input type="text" name="vencimento[<?= $i ?>]" value="<?= h($l['vencimento']) ?>"
                     class="imp-pgto imp-pgto-<?= $i ?> imp-data" placeholder="dd/mm/aaaa"
                     <?= $financeiro ? '' : 'disabled' ?>
                     style="<?= $inp ?><?= $financeiro ? '' : ';background:#F6F6F6;cursor:not-allowed' ?>">
            </td>
            <td style="padding:12px 14px;min-width:170px">
              <select name="forma_pagamento_id[<?= $i ?>]" class="imp-pgto imp-pgto-<?= $i ?>"
                      <?= $financeiro ? '' : 'disabled' ?>
                      style="<?= $inp ?><?= $financeiro ? '' : ';background:#F6F6F6;cursor:not-allowed' ?>">
                <option value="">Selecione…</option>
                <?php foreach ($formasPgto as $fp): ?>
                <option value="<?= h($fp['id']) ?>" <?= ($l['forma_pagamento_id'] ?? '') === $fp['id'] ? 'selected' : '' ?>>
                  <?= h($fp['nome']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p style="font-size:12px;color:#888;margin:12px 0 0">
      A data e a forma de pagamento abrem <strong>uma parcela de previsão</strong> com o valor cheio da nota.
      O desdobramento em mais parcelas e o ajuste de datas continuam sendo feitos no processo, durante o fluxo.
      Template sem financeiro não usa esses campos.
    </p>

    <div style="display:flex;gap:10px;margin-top:20px">
      <button type="submit"
              style="background:#2347C8;color:#fff;border:none;border-radius:4px;padding:11px 24px;font-size:14px;font-weight:700;cursor:pointer">
        Abrir processos das linhas marcadas
      </button>
      <a href="?module=importar&amp;action=index"
         style="background:#EAEAEA;color:#606060;border-radius:4px;padding:11px 20px;font-size:14px;font-weight:700;text-decoration:none">
        Descartar
      </a>
    </div>
  </form>

  <script>
    // Trocar o template da linha liga/desliga os campos de pagamento: fluxo sem
    // financeiro não gera parcela, então os campos não podem ficar pedindo dado.
    (function () {
      document.querySelectorAll('.imp-tpl').forEach(function (sel) {
        sel.addEventListener('change', function () {
          var opt  = sel.options[sel.selectedIndex];
          var temF = opt && opt.getAttribute('data-financeiro') === '1';
          document.querySelectorAll('.imp-pgto-' + sel.dataset.linha).forEach(function (campo) {
            campo.disabled = !temF;
            campo.style.background = temF ? '#fff' : '#F6F6F6';
            campo.style.cursor     = temF ? 'auto' : 'not-allowed';
            if (!temF) { campo.value = ''; }
          });
          sel.style.borderColor = sel.value === '' ? '#FF8A2D' : '#DFDFDF';
        });
      });
    })();

    // Máscara dd/mm/aaaa nos campos de data (Data da nota e Data de pagamento).
    // O backend exige o formato exato (/^\d{2}\/\d{2}\/\d{4}$/); quem digitava
    // "03082026" perdia a linha inteira na validação e tinha de refazer. A barra
    // entra sozinha durante a digitação — não há como salvar sem ela.
    (function () {
      function aplicarMascara(campo) {
        // Quantos dígitos existiam antes do cursor — é por eles que o cursor
        // volta ao lugar depois de reescrever o valor, senão pula para o fim
        // sempre que alguém corrige um dígito no meio da data.
        var digitosAntesDoCursor = campo.value.slice(0, campo.selectionStart).replace(/\D/g, '').length;

        var digitos = campo.value.replace(/\D/g, '').slice(0, 8);
        var valor   = digitos;
        if (digitos.length > 4) {
          valor = digitos.slice(0, 2) + '/' + digitos.slice(2, 4) + '/' + digitos.slice(4);
        } else if (digitos.length > 2) {
          valor = digitos.slice(0, 2) + '/' + digitos.slice(2);
        }
        campo.value = valor;

        var pos = 0, contados = 0;
        while (pos < valor.length && contados < digitosAntesDoCursor) {
          if (/\d/.test(valor[pos])) { contados++; }
          pos++;
        }
        campo.setSelectionRange(pos, pos);
      }

      // A máscara garante o formato, não a data. "24/11/0020" tem 4 dígitos no
      // ano e passa no regex do backend — foi assim que uma parcela nasceu
      // vencida no ano 20. Aqui a data é conferida de verdade: dia/mês que
      // existem no calendário e ano plausível. Campo errado fica vermelho na
      // hora, antes de o usuário enviar e perder a linha.
      function dataPlausivel(valor) {
        var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(valor);
        if (!m) { return false; }
        var dia = +m[1], mes = +m[2], ano = +m[3];
        var anoAtual = new Date().getFullYear();
        if (ano < anoAtual - 10 || ano > anoAtual + 10) { return false; }
        var d = new Date(ano, mes - 1, dia);
        return d.getFullYear() === ano && d.getMonth() === mes - 1 && d.getDate() === dia;
      }

      function sinalizar(campo) {
        // Vazio não é erro aqui — a obrigatoriedade é do backend, que sabe se o
        // template tem financeiro. Só marca o que foi digitado e está errado.
        campo.style.borderColor = (campo.value === '' || dataPlausivel(campo.value)) ? '#DFDFDF' : '#B3261E';
      }

      document.querySelectorAll('.imp-data').forEach(function (campo) {
        campo.setAttribute('inputmode', 'numeric');
        campo.setAttribute('maxlength', '10');
        campo.addEventListener('input', function () {
          aplicarMascara(campo);
          sinalizar(campo);
        });
        sinalizar(campo);
      });
    })();
  </script>

<?php else: ?>

  <div style="background:#fff;border-radius:12px;box-shadow:rgba(0,0,0,0.10) 0 2px 8px;padding:24px 28px;max-width:760px">
    <p style="font-size:15px;font-weight:700;color:#141922;margin:0 0 14px">
      <?= count($resultado['criados']) ?> processo(s) aberto(s)
      <?php if (!empty($setor['nome'])): ?>
      <span style="font-size:13px;font-weight:400;color:#888">· setor <?= h($setor['nome']) ?></span>
      <?php endif; ?>
    </p>

    <?php if ($resultado['criados']): ?>
    <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:18px">
      <?php foreach ($resultado['criados'] as $c): ?>
      <div style="display:flex;gap:10px;align-items:center;font-size:13px">
        <span style="background:#D8F5E5;color:#00A960;font-size:11px;font-weight:700;padding:3px 8px;border-radius:8px">OK</span>
        <a href="?module=processos&amp;action=detalhe&amp;id=<?= h($c['id']) ?>"
           style="color:#2347C8;font-weight:700;text-decoration:none"><?= h($c['codigo']) ?></a>
        <span style="color:#606060"><?= h($c['template'] ?? '') ?></span>
        <span style="color:#888"><?= h($c['arquivo']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($resultado['erros']): ?>
    <p style="font-size:13px;font-weight:700;color:#B3261E;margin:0 0 8px">Não abertos</p>
    <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:18px">
      <?php foreach ($resultado['erros'] as $e): ?>
      <div style="font-size:13px;color:#606060">
        <strong style="color:#141922"><?= h($e['arquivo']) ?></strong> — <?= h($e['msg']) ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($resultado['ignorados']): ?>
    <p style="font-size:12px;color:#888;margin:0 0 18px">
      <?= count($resultado['ignorados']) ?> linha(s) desmarcada(s) — nada foi criado para elas.
    </p>
    <?php endif; ?>

    <a href="?module=importar&amp;action=index"
       style="display:inline-block;background:#2347C8;color:#fff;border-radius:4px;padding:10px 22px;font-size:14px;font-weight:700;text-decoration:none">
      Importar outros PDFs
    </a>
  </div>

<?php endif; ?>
