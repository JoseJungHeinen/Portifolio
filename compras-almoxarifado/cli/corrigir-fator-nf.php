<?php
// @versao Ver.004.100926
/**
 * Corrige linhas de nota fiscal cuja quantidade foi inflada pelo bug do parseBR.
 *
 * O DEFEITO: até 10/09/2026 a tela de conferência renderizava a quantidade com
 * ponto decimal ("20.1"), e parseBR() trata ponto como separador de milhar.
 * Uma nota de 20,1 era lida como 201 — dez vezes mais. Ao confirmar a
 * conferência, esse valor virava a quantidade da linha, o IPI, o total e a
 * entrada de estoque. Só linhas com quantidade fracionada foram afetadas.
 *
 * O que este script usa como verdade: o XML guardado em storage/nfe/. Ele
 * compara qCom/vUnCom/uCom com as colunas *_nf e conserta o que divergir.
 *
 * A DISTINÇÃO QUE IMPORTA: uma quantidade diferente do XML também pode ser
 * correção legítima do conferente (nota veio errada, ele ajustou). Por isso o
 * script só corrige automaticamente o que tem a ASSINATURA do bug —
 * quantidade_nf == qCom × 10^casas_decimais. Divergência de outro formato é
 * apenas relatada, nunca alterada.
 *
 * Modos:
 *   php cli/corrigir-fator-nf.php                    diagnóstico (padrão)
 *   php cli/corrigir-fator-nf.php --aplicar          aplica as correções do bug
 *   php cli/corrigir-fator-nf.php --inferir-fator    registra o fator das
 *                                                    conversões feitas à mão
 *   php cli/corrigir-fator-nf.php --nf=1234          restringe a uma nota
 *   php cli/corrigir-fator-nf.php --aplicar --usuario=a@b.com   autor do ajuste
 *
 * Sai com 0 se não há nada a corrigir ou se aplicou tudo, 1 se algo falhou ou
 * precisa de decisão humana.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ROOT', dirname(__DIR__));
require_once ROOT . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(ROOT)->load();
date_default_timezone_set('America/Sao_Paulo');

use App\Config\Database;

$opts    = getopt('', ['aplicar', 'inferir-fator', 'nf::', 'usuario::']);
$aplicar = array_key_exists('aplicar', $opts);
$inferir = array_key_exists('inferir-fator', $opts);
$soNf    = $opts['nf'] ?? null;
$emailUs = $opts['usuario'] ?? null;

$db = Database::getInstance();

function out(string $msg = ''): void { echo $msg . PHP_EOL; }
function n(float $v, int $dec = 2): string { return number_format($v, $dec, ',', '.'); }

/**
 * Mesmo parse e mesmas proteções do FiscalController: DOCTYPE/ENTITY barrados
 * antes de qualquer leitura, LIBXML_NONET e LIBXML_NOENT no load.
 */
function itensDoXml(string $caminho): ?array
{
    if (!is_file($caminho)) return null;
    $xml = file_get_contents($caminho);
    if ($xml === false || preg_match('/<!(?:DOCTYPE|ENTITY)\s/i', $xml)) return null;

    libxml_use_internal_errors(true);
    $obj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT);
    if (!$obj) return null;

    $obj->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
    $infNFe = $obj->xpath('//nfe:infNFe')[0] ?? $obj->xpath('//infNFe')[0] ?? null;
    if (!$infNFe) return null;

    $porCodigo = [];
    foreach ($infNFe->det as $det) {
        $prod   = $det->prod;
        $codigo = (string)($prod->cProd ?? '');
        $pct    = isset($det->imposto->IPI->IPITrib)
            ? (float)($det->imposto->IPI->IPITrib->pIPI ?? 0) : 0.0;
        $q = (float)($prod->qCom   ?? 0);
        $v = (float)($prod->vUnCom ?? 0);

        // Guarda TODAS as ocorrências do código. Código repetido na mesma nota
        // normalmente deixa o casamento ambíguo — as linhas do banco não
        // guardam a ordem do XML. Mas duas perguntas ainda têm resposta: se as
        // ocorrências são idênticas, qualquer uma serve de referência; e se a
        // quantidade do banco confere com alguma delas, não há divergência a
        // investigar, mesmo sem saber qual linha é qual.
        $porCodigo[$codigo]['ocorrencias'][] = [
            'quantidade' => $q,
            'valor'      => $v,
            'total'      => (float)($prod->vProd ?? 0),
            'unidade'    => (string)($prod->uCom ?? ''),
            'ipi_pct'    => $pct,
        ];
    }

    foreach ($porCodigo as $codigo => $dados) {
        $ocs      = $dados['ocorrencias'];
        $primeira = $ocs[0];
        $iguais   = true;
        foreach ($ocs as $o) {
            if (abs($o['quantidade'] - $primeira['quantidade']) >= 0.005
             || abs($o['valor'] - $primeira['valor']) >= 0.00005) { $iguais = false; break; }
        }
        $porCodigo[$codigo] = $primeira + ['ambiguo' => !$iguais, 'ocorrencias' => $ocs];
    }

    return $porCodigo;
}

/**
 * Assinatura do bug: o valor gravado é o do XML com o ponto decimal removido.
 * 20,1 -> "20.1" -> 201 (×10). 20,15 -> "20.15" -> 2015 (×100).
 * Devolve o multiplicador (10 ou 100) ou null se não for esse defeito.
 */
function assinaturaParseBr(float $doBanco, float $doXml): ?int
{
    foreach ([10, 100] as $mult) {
        $casas = $mult === 10 ? 1 : 2;
        // O render cortava zeros à direita, então só há inflação quando a casa
        // decimal correspondente é significativa.
        $texto = rtrim(rtrim(number_format($doXml, 2, '.', ''), '0'), '.');
        if (substr_count($texto, '.') !== 1) continue;
        if (strlen(explode('.', $texto)[1]) !== $casas) continue;
        if (abs($doBanco - $doXml * $mult) < 0.005) return $mult;
    }
    return null;
}

// Autor do movimento de correção: movimentos_estoque.usuario_id é NOT NULL.
$usuario = null;
if ($emailUs) {
    $st = $db->prepare("SELECT id, nome, email FROM usuarios WHERE email = ? AND ativo = 1 LIMIT 1");
    $st->execute([$emailUs]);
    $usuario = $st->fetch();
    if (!$usuario) { out("Usuário {$emailUs} não encontrado ou inativo."); exit(1); }
} else {
    $usuario = $db->query(
        "SELECT id, nome, email FROM usuarios WHERE perfil = 'admin' AND ativo = 1 ORDER BY criado_em LIMIT 1"
    )->fetch();
}

out('=== Correção de quantidade inflada em nota fiscal ===');
$modos = [];
if ($aplicar) $modos[] = 'corrigir o bug';
if ($inferir) $modos[] = 'inferir fator das conversões manuais';
out($modos ? 'MODO: GRAVANDO — ' . implode(' + ', $modos) : 'MODO: diagnóstico (nada é gravado)');
if ($usuario) out('Ajustes serão registrados como: ' . $usuario['nome'] . ' <' . $usuario['email'] . '>');
out();

$sqlNf = "SELECT nf.id, nf.numero_nf, nf.xml_path, nf.frete_total, nf.rateio_metodo,
                 nf.conferido_por, r.filial_id
          FROM notas_fiscais nf
          JOIN ordens_compra oc ON oc.id = nf.oc_id
          LEFT JOIN requisicoes r ON r.id = oc.requisicao_id
          WHERE nf.status <> 'rejeitada'";
$par = [];
if ($soNf !== null) { $sqlNf .= " AND nf.numero_nf = ?"; $par[] = $soNf; }
$sqlNf .= " ORDER BY nf.criado_em";

$stNf = $db->prepare($sqlNf);
$stNf->execute($par);
$notas = $stNf->fetchAll();

out(count($notas) . ' nota(s) para analisar.');
out();

$totCorrigiveis = 0; $totOutras = 0; $totSemXml = 0; $totAplicadas = 0; $totProblemas = 0;
$totInferiveis = 0; $totInferidos = 0;

foreach ($notas as $nf) {
    $xmlItens = itensDoXml(ROOT . '/' . $nf['xml_path']);
    if ($xmlItens === null) {
        out("NF {$nf['numero_nf']}: XML ilegível ou ausente ({$nf['xml_path']}) — pulada.");
        $totSemXml++;
        continue;
    }

    $stL = $db->prepare(
        "SELECT nfi.*, i.unidade AS item_unidade
         FROM nota_fiscal_itens nfi
         LEFT JOIN itens i ON i.id = nfi.item_id
         WHERE nfi.nota_fiscal_id = ?"
    );
    $stL->execute([$nf['id']]);
    $linhas = $stL->fetchAll();

    $paraCorrigir = [];
    $paraInferir  = [];
    $avisos = [];

    foreach ($linhas as $l) {
        $x = $xmlItens[$l['codigo_nf']] ?? null;
        if ($x === null) {
            $avisos[] = "  linha {$l['codigo_nf']}: sem código correspondente no XML — ignorada.";
            $totOutras++;
            continue;
        }
        $qNf = (float)$l['quantidade_nf'];

        if ($x['ambiguo']) {
            // Sem saber qual ocorrência é esta linha, ainda dá para concluir:
            // se a quantidade do banco é igual à de alguma ocorrência, essa
            // linha não foi convertida nem inflada, e não há o que corrigir.
            foreach ($x['ocorrencias'] as $o) {
                // continue 2 = próxima LINHA (1 seria a próxima ocorrência,
                // 3 pularia a nota inteira).
                if (abs($qNf - $o['quantidade']) < 0.005) continue 2;
            }
            $qtds = implode(', ', array_map(fn($o) => n($o['quantidade']), $x['ocorrencias']));
            $avisos[] = sprintf(
                "  linha %s: código repetido no XML com ocorrências diferentes (qtd %s) e o banco tem %s — nenhuma confere, casamento manual necessário.",
                $l['codigo_nf'], $qtds, n($qNf)
            );
            $totOutras++;
            $totProblemas++;
            continue;
        }

        if (abs($qNf - $x['quantidade']) < 0.005) continue; // já bate com o XML

        $mult = assinaturaParseBr($qNf, $x['quantidade']);
        if ($mult === null) {
            // Não é o bug. O padrão dominante aqui é conversão de unidade feita
            // à mão, antes de existir o campo de fator: o conferente digitava a
            // quantidade na unidade de estoque. O que decide se isso é
            // recuperável é o TOTAL da linha: se q × v ainda fecha com o vProd
            // da nota, o preço foi ajustado junto e o fator aplicado pode ser
            // inferido exatamente (fator = q_banco / qCom, e daí
            // v_banco == vUnCom / fator por consequência aritmética).
            // Se o total não fecha, a linha tem divergência de valor que
            // nenhum fator explica — e aí é decisão humana.
            $razao    = $x['quantidade'] > 0 ? $qNf / $x['quantidade'] : 0;
            $totalNf  = $x['total'] > 0 ? $x['total'] : $x['quantidade'] * $x['valor'];
            $vBanco   = (float)$l['valor_unitario_nf'];
            $totalBco = $qNf * $vBanco;
            $residuo  = abs($totalBco - $totalNf);

            // Tolerância derivada da precisão do preço, não um número fixo: quem
            // dividiu à mão digitou o preço com as casas que digitou, e cada
            // unidade carrega até meio passo dessa precisão. 7,59 ÷ 2 = 3,795
            // digitado como 3,79 são 0,005 por unidade — em 24 unidades, os
            // 0,12 que aparecem no total. Preço com 4 casas erra mil vezes
            // menos, e a tolerância acompanha.
            $txt   = rtrim(number_format($vBanco, 4, '.', ''), '0');
            $pos   = strpos($txt, '.');
            $casas = $pos === false ? 0 : strlen(substr($txt, $pos + 1));
            $passo = pow(10, -max(2, $casas));
            $tol   = $qNf * $passo / 2 + 0.01;
            $fecha = $residuo <= $tol;

            $avisos[] = sprintf(
                "  linha %s: qtd banco %s x XML %s (%s) | vlr banco %s x XML %s | total linha %s x nota %s -> %s",
                $l['codigo_nf'], n($qNf), n($x['quantidade']),
                $razao >= 1 ? '×' . rtrim(rtrim(number_format($razao, 4, ',', '.'), '0'), ',')
                            : '÷' . rtrim(rtrim(number_format($razao > 0 ? 1 / $razao : 0, 4, ',', '.'), '0'), ','),
                n($vBanco, 4), n($x['valor'], 4),
                n($totalBco), n($totalNf),
                $fecha
                    ? 'CONVERSÃO CONSISTENTE (fator inferível)'
                      . ($residuo > 0.005 ? ' — resíduo de arredondamento ' . n($residuo) : '')
                    : 'TOTAL NÃO FECHA (diferença de ' . n($residuo) . ', acima do resíduo de ' . n($tol) . ' que o arredondamento explica)'
            );

            if ($fecha) {
                $paraInferir[] = ['l' => $l, 'x' => $x, 'fator' => $razao];
                $totInferiveis++;
            } else {
                $totProblemas++;
            }
            $totOutras++;
            continue;
        }

        // Quantas vezes esta linha entrou no estoque. Mais de uma indica nota
        // conferida repetidas vezes, e aí o delta simples não fecha.
        $stM = $db->prepare(
            "SELECT COUNT(*) FROM movimentos_estoque
             WHERE referencia_id = ? AND item_id = ? AND tipo = 'entrada' AND motivo = 'compra'"
        );
        $stM->execute([$nf['id'], $l['item_id']]);
        $entradas = (int)$stM->fetchColumn();
        if ($entradas > 1) {
            $avisos[] = sprintf(
                "  linha %s: %d entradas de estoque para a mesma NF — conferida mais de uma vez. Precisa de inventário manual.",
                $l['codigo_nf'], $entradas
            );
            $totProblemas++;
            continue;
        }

        $paraCorrigir[] = ['l' => $l, 'x' => $x, 'mult' => $mult, 'entradas' => $entradas];
        $totCorrigiveis++;
    }

    if (!$paraCorrigir && !$avisos) continue;

    out("NF {$nf['numero_nf']} (" . ($nf['conferido_por'] ? 'conferida' : 'não conferida') . '):');
    foreach ($avisos as $a) out($a);

    // Conversões manuais: registra o fator que já foi aplicado, sem tocar em
    // quantidade, preço, estoque ou histórico — só nas colunas de documento e
    // no fator. Depois disso a linha fica consistente (quantidade ==
    // quantidade_nf × fator) e o campo de fator na tela passa a calcular a
    // partir do XML, em vez de a partir do valor já convertido.
    if ($paraInferir && $inferir) {
        $db->beginTransaction();
        try {
            $updInf = $db->prepare(
                "UPDATE nota_fiscal_itens
                 SET quantidade_nf=?, valor_unitario_nf=?, unidade_nf=?, unidade=?, fator_conversao=?
                 WHERE id=? AND nota_fiscal_id=?"
            );
            foreach ($paraInferir as $c) {
                $l  = $c['l'];
                $un = !empty($l['item_unidade']) ? $l['item_unidade'] : (string)$l['unidade'];
                $updInf->execute([
                    $c['x']['quantidade'], $c['x']['valor'], $c['x']['unidade'], $un,
                    round($c['fator'], 4), $l['id'], $nf['id'],
                ]);
                out(sprintf(
                    '  %s: fator registrado %s (documento restaurado: %s %s x %s)',
                    $l['codigo_nf'], rtrim(rtrim(number_format($c['fator'], 4, ',', '.'), '0'), ','),
                    n($c['x']['quantidade']), $c['x']['unidade'], n($c['x']['valor'], 4)
                ));
                $totInferidos++;
            }
            (new App\Services\AuditoriaService())->log(
                $usuario['id'], 'nota_fiscal', $nf['id'], 'nf.fator_inferido',
                null, ['linhas' => count($paraInferir)]
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            out('  FALHOU ao inferir fator: ' . $e->getMessage() . ' — nada gravado nesta nota.');
            $totProblemas++;
        }
    }

    if (!$paraCorrigir) { out(); continue; }

    // Recalcula a nota inteira com as quantidades corretas — o rateio de frete
    // depende do peso de cada linha, então uma linha inflada distorcia o frete
    // (e o custo) das outras também.
    $fator = fn(array $l) => ((float)$l['fator_conversao'] > 0 ? (float)$l['fator_conversao'] : 1.0);
    $novo = [];
    $base = 0.0;
    foreach ($linhas as $l) {
        $corr = null;
        foreach ($paraCorrigir as $c) { if ($c['l']['id'] === $l['id']) { $corr = $c; break; } }
        $f    = $fator($l);
        $qDoc = $corr ? $corr['x']['quantidade'] : (float)$l['quantidade_nf'];
        $vDoc = $corr ? $corr['x']['valor']      : (float)$l['valor_unitario_nf'];
        $q    = round($qDoc * $f, 2);
        $v    = round($vDoc / $f, 4);
        $prod = $q * $v;
        $ipi  = $prod * (float)$l['ipi_percentual'] / 100;
        $novo[$l['id']] = ['l' => $l, 'corr' => $corr, 'f' => $f, 'qDoc' => $qDoc, 'vDoc' => $vDoc,
                           'q' => $q, 'v' => $v, 'prod' => $prod, 'ipi' => $ipi];
        $base += $nf['rateio_metodo'] === 'quantidade' ? $q : $prod;
    }
    $freteTotal = (float)$nf['frete_total'];
    foreach ($novo as $id => $d) {
        $peso = $base > 0 ? (($nf['rateio_metodo'] === 'quantidade' ? $d['q'] : $d['prod']) / $base) : 0;
        $novo[$id]['frete'] = round($freteTotal * $peso, 2);
        $novo[$id]['custo'] = $d['q'] > 0
            ? round(($d['prod'] + $d['ipi'] + $novo[$id]['frete']) / $d['q'], 2)
            : 0.0;
    }

    foreach ($novo as $d) {
        $l = $d['l'];
        $mudou = abs((float)$l['quantidade'] - $d['q']) >= 0.005
              || abs((float)$l['custo_final_unit'] - $d['custo']) >= 0.005;
        if (!$mudou) continue;
        out(sprintf(
            "  %-20s qtd %s -> %s | vlr %s -> %s | custo %s -> %s%s",
            $l['codigo_nf'],
            n((float)$l['quantidade']), n($d['q']),
            n((float)$l['valor_unitario'], 4), n($d['v'], 4),
            n((float)$l['custo_final_unit']), n($d['custo']),
            $d['corr'] ? '   [inflada ' . $d['corr']['mult'] . 'x]' : '   [efeito do rateio]'
        ));
    }

    if (!$aplicar) { out('  (diagnóstico — nada gravado)'); out(); continue; }

    $db->beginTransaction();
    try {
        $updL = $db->prepare(
            "UPDATE nota_fiscal_itens
             SET quantidade_nf=?, valor_unitario_nf=?, unidade_nf=?,
                 quantidade=?, valor_unitario=?, unidade=?,
                 ipi_valor=?, frete_rateado=?, custo_final_unit=?
             WHERE id=? AND nota_fiscal_id=?"
        );
        $estoqueModel = new App\Models\Estoque();

        foreach ($novo as $d) {
            $l = $d['l'];
            $unDoc = $d['corr'] ? $d['corr']['x']['unidade'] : (string)$l['unidade_nf'];
            $unEf  = ($d['f'] != 1.0 && !empty($l['item_unidade'])) ? $l['item_unidade'] : $unDoc;

            $updL->execute([
                $d['qDoc'], $d['vDoc'], $unDoc,
                $d['q'], $d['v'], $unEf,
                round($d['ipi'], 2), $d['frete'], $d['custo'],
                $l['id'], $nf['id'],
            ]);

            // Estoque: o que entrou na conferência foi exatamente a quantidade
            // efetiva de então — vale para o código antigo (quantidade × fator)
            // e para o novo (quantidade já convertida), porque a migration 050
            // alinhou as duas formas. O delta é a diferença para a correta.
            $delta = round($d['q'] - (float)$l['quantidade'], 2);
            $temEstoque = $nf['conferido_por'] && !empty($l['item_id']) && !empty($nf['filial_id']);

            // Nota não conferida nunca movimentou estoque nem gravou preço: a
            // correção dela para aqui.
            if (!$nf['conferido_por'] || empty($l['item_id'])) continue;

            if ($temEstoque && abs($delta) >= 0.005) {
                $stS = $db->prepare("SELECT quantidade FROM estoque WHERE item_id=? AND filial_id=? LIMIT 1");
                $stS->execute([$l['item_id'], $nf['filial_id']]);
                $saldo = $stS->fetchColumn();

                if ($saldo === false) {
                    if ($delta > 0) {
                        $db->prepare(
                            "INSERT INTO estoque (id, item_id, filial_id, quantidade, quantidade_reservada, atualizado_em)
                             VALUES (?,?,?,?,0,NOW())"
                        )->execute([uuid(), $l['item_id'], $nf['filial_id'], $delta]);
                        out("  {$l['codigo_nf']}: estoque criado com " . n($delta));
                    } else {
                        out("  {$l['codigo_nf']}: sem registro de estoque para baixar " . n(abs($delta)) . " — precisa de inventário.");
                        $totProblemas++;
                    }
                } else {
                    $novoSaldo = (float)$saldo + $delta;
                    if ($novoSaldo < 0) {
                        out(sprintf(
                            "  %s: ATENÇÃO saldo %s com ajuste de %s ficaria negativo (%s). Zerado — confira o inventário.",
                            $l['codigo_nf'], n((float)$saldo), n($delta), n($novoSaldo)
                        ));
                        $novoSaldo = 0.0;
                        $totProblemas++;
                    }
                    $db->prepare("UPDATE estoque SET quantidade=?, atualizado_em=NOW() WHERE item_id=? AND filial_id=?")
                       ->execute([$novoSaldo, $l['item_id'], $nf['filial_id']]);
                    out("  {$l['codigo_nf']}: saldo " . n((float)$saldo) . ' -> ' . n($novoSaldo));
                }

                $db->prepare(
                    "INSERT INTO movimentos_estoque
                     (id, item_id, filial_id, tipo, motivo, justificativa, quantidade,
                      referencia_tipo, referencia_id, usuario_id, criado_em)
                     VALUES (?,?,?,?,'correcao',?,?, 'nota_fiscal', ?, ?, NOW())"
                )->execute([
                    uuid(), $l['item_id'], $nf['filial_id'],
                    $delta > 0 ? 'entrada' : 'saida',
                    'Correção do bug de leitura decimal na conferência da NF ' . $nf['numero_nf']
                        . ' (linha ' . $l['codigo_nf'] . ': ' . n((float)$l['quantidade']) . ' -> ' . n($d['q']) . ')',
                    abs($delta), $nf['id'], $usuario['id'],
                ]);

                $estoqueModel->espelharBrinde(
                    $l['item_id'], $nf['filial_id'], abs($delta),
                    $delta > 0 ? 'entrada' : 'saida', $usuario['id'], 'nota_fiscal'
                );
            }

            // Preço vem DEPOIS e fora do bloco de estoque, porque o custo muda
            // mesmo em linha que não foi inflada: corrigir a quantidade de uma
            // linha redistribui o rateio de frete entre todas. Amarrar esta
            // parte ao delta de quantidade deixaria o histórico dessas linhas
            // com o custo velho.
            $custoAntigo = round((float)$l['custo_final_unit'], 2);
            if (abs($custoAntigo - $d['custo']) < 0.005) continue;

            // O histórico desta NF fica com o custo certo; o valor_referencia do
            // item só é mexido se ainda for o valor errado que esta nota gravou —
            // se outra nota passou por cima depois, a dela é mais recente e não
            // deve ser desfeita.
            $db->prepare(
                "UPDATE historico_precos SET valor_unitario=? WHERE origem_id=? AND item_id=? AND origem='nota_fiscal'"
            )->execute([$d['custo'], $nf['id'], $l['item_id']]);
            $upRef = $db->prepare("UPDATE itens SET valor_referencia=? WHERE id=? AND valor_referencia=?");
            $upRef->execute([$d['custo'], $l['item_id'], $custoAntigo]);
            if ($upRef->rowCount() > 0) {
                out("  {$l['codigo_nf']}: valor_referencia " . n($custoAntigo) . ' -> ' . n($d['custo']));
            } else {
                $refAtual = $db->prepare("SELECT valor_referencia FROM itens WHERE id=?");
                $refAtual->execute([$l['item_id']]);
                out(sprintf(
                    "  %s: histórico ajustado para %s; valor_referencia mantido em %s (não era o valor desta NF).",
                    $l['codigo_nf'], n($d['custo']), n((float)$refAtual->fetchColumn())
                ));
            }
        }

        (new App\Services\AuditoriaService())->log(
            $usuario['id'], 'nota_fiscal', $nf['id'], 'nf.quantidade_corrigida',
            null, ['linhas' => count($paraCorrigir)]
        );

        $db->commit();
        $totAplicadas += count($paraCorrigir);
        out('  aplicado.');
    } catch (\Throwable $e) {
        $db->rollBack();
        out('  FALHOU: ' . $e->getMessage() . ' — nada gravado nesta nota.');
        $totProblemas++;
    }
    out();
}

out('=== Resumo ===');
out('Linhas com a assinatura do bug: ' . $totCorrigiveis);
out('Divergências de outro tipo: ' . $totOutras
    . ' (conversão manual consistente: ' . $totInferiveis . ')');
out('Notas sem XML legível: ' . $totSemXml);
if ($aplicar) out('Linhas corrigidas: ' . $totAplicadas);
if ($inferir) out('Fatores inferidos e gravados: ' . $totInferidos);
out('Situações que precisam de decisão humana: ' . $totProblemas);
if (!$aplicar && $totCorrigiveis > 0) {
    out();
    out('Nada foi gravado. Para aplicar: php cli/corrigir-fator-nf.php --aplicar');
}
if (!$inferir && $totInferiveis > 0) {
    out();
    out($totInferiveis . ' linha(s) com conversão manual consistente. Para registrar o');
    out('fator dessas linhas (sem tocar em quantidade, preço ou estoque):');
    out('  php cli/corrigir-fator-nf.php --inferir-fator');
}

exit($totProblemas > 0 ? 1 : 0);
