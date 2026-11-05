<?php
// Checagem da importação de fornecedores por planilha (spec 2026-10-30).
// Uso: C:\xampp\php\php.exe C:\Projetos\Requisicoes\tests\importacao_test.php
declare(strict_types=1);
// O autoload do Composer já carrega src/helpers.php (files).
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\PlanilhaReader as P;
use App\Services\ImportacaoFornecedoresService as Imp;

$falhas = 0;
function confere(string $nome, bool $ok): void {
    global $falhas;
    echo ($ok ? 'ok    ' : 'FALHA ') . $nome . PHP_EOL;
    if (!$ok) $falhas++;
}

/** CNPJ com os dígitos verificadores calculados a partir dos 12 primeiros. */
function cnpjCom(string $base12): string {
    $dv = function (string $b): int {
        $pesos = strlen($b) === 12 ? [5,4,3,2,9,8,7,6,5,4,3,2] : [6,5,4,3,2,9,8,7,6,5,4,3,2];
        $s = 0;
        foreach (str_split($b) as $i => $c) $s += (int)$c * $pesos[$i];
        $r = $s % 11;
        return $r < 2 ? 0 : 11 - $r;
    };
    $d1 = $dv($base12);
    return $base12 . $d1 . $dv($base12 . $d1);
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'imp_test_' . bin2hex(random_bytes(4));
mkdir($tmp);

// ── CNPJ ──
confere('cnpj válido 11.222.333/0001-81',   P::cnpjValido('11.222.333/0001-81'));
confere('cnpj dígito errado',               !P::cnpjValido('11222333000181'));
confere('cnpj repetido 000…',               !P::cnpjValido('11222333000181'));
confere('cnpj curto',                       !P::cnpjValido('1122233300018'));
confere('cnpj gerado no teste é válido',    P::cnpjValido(cnpjCom('123456780001')));

// ── Datas ──
confere('data serial 45658 = 2025-01-01',   P::data('45658') === '2025-01-01');
confere('data serial com fração',           P::data('45658.5') === '2025-01-01');
confere('data dd/mm/aaaa',                  P::data('05/03/2027') === '2027-03-05');
confere('data d/m/aa',                      P::data('5/3/27') === '2027-03-05');
confere('data aaaa-mm-dd',                  P::data('2027-03-05') === '2027-03-05');
confere('data vazia = ""',                  P::data('  ') === '');
confere('data inválida = null',             P::data('31/02/2027') === null);
confere('data texto = null',                P::data('amanhã') === null);

// ── Regime ──
confere('regime MEI',                       P::regime('MEI') === 'mei');
confere('regime Simples Nacional',          P::regime('simples nacional') === 'simples_nacional');
confere('regime Lucro Real',                P::regime('Lucro Real') === 'lucro_real');
confere('regime Lucro Presumido',           P::regime('LUCRO PRESUMIDO') === 'lucro_presumido');
confere('regime vazio = ""',                P::regime('') === '');
confere('regime híbrido = null',            P::regime('Híbrido IBS/CBS') === null);
confere('regime pessoa física = null',      P::regime('Pessoa Física') === null);
confere('regime desconhecido = null',       P::regime('xyz') === null);

// ── CND ──
confere('cnd negativa',                     P::cnd('Negativa') === 'negativa');
confere('cnd positiva',                     P::cnd('Positiva') === 'positiva');
confere('cnd positiva c/ efeito',           P::cnd('Positiva com efeito de negativa') === 'positiva_efeitos_negativa');
confere('cnd vazia = ""',                   P::cnd('') === '');
confere('cnd desconhecida = null',          P::cnd('regular') === null);

// ── Situação ──
confere('situação Inativo = bloqueado',     P::situacao('Inativo', '') === 'bloqueado');
confere('situação Não autorizado',          P::situacao('Ativo', 'Não Autorizado') === 'bloqueado');
confere('situação Autorizado',              P::situacao('Ativo', 'Autorizado') === 'homologado');
confere('situação Ressalva',                P::situacao('', 'Ressalvas') === 'pendente');
confere('situação vazia = ""',              P::situacao('Ativo', '') === '');

// ── Análise ──
$matriz  = cnpjCom('123456780001');
$filial  = cnpjCom('123456780002');
$filial2 = cnpjCom('987654320003'); // matriz não existe
$exist   = cnpjCom('555555550001');
$existFil= cnpjCom('555555550002');
$l = fn(int $n, array $c) => $c + ['linha' => $n, 'razao' => '', 'fantasia' => '', 'cnpj' => '', 'cnpj_matriz' => '',
    'cidade' => '', 'uf' => '', 'email' => '', 'fone' => '', 'celular' => '', 'status' => '', 'regime' => '',
    'cnd_federal' => '', 'cnd_federal_validade' => '', 'setor' => '', 'obs' => '', 'classificacao' => ''];

$existentes = [
    $exist => ['id' => 'id-exist', 'cnpj' => $exist, 'razao_social' => 'Existente Ltda', 'nome_fantasia' => 'Existente',
               'cidade' => 'Curitiba', 'estado' => 'PR', 'email' => 'velho@ex.com', 'telefone' => '41 3333-0000',
               'regime_tributario' => 'lucro_real', 'regime_confirmado_em' => '2026-01-01', 'cnd_federal_situacao' => 'negativa',
               'cnd_federal_validade' => '2027-01-01', 'situacao' => 'homologado', 'observacoes' => 'Nota antiga.', 'mei' => 0],
    $existFil => ['id' => 'id-existfil', 'cnpj' => $existFil, 'razao_social' => 'Existente Filial', 'nome_fantasia' => null,
               'cidade' => 'Londrina', 'estado' => 'PR', 'email' => null, 'telefone' => null, 'regime_tributario' => null,
               'regime_confirmado_em' => null, 'cnd_federal_situacao' => null, 'cnd_federal_validade' => null,
               'situacao' => 'pendente', 'observacoes' => null, 'mei' => 0],
];
$linhas = [
    $l(2, ['razao' => 'Matriz Nova SA', 'cnpj' => $matriz, 'regime' => 'Lucro Presumido', 'cnd_federal' => 'Negativa',
           'cnd_federal_validade' => '45658', 'email' => 'contato@matriz.com', 'fone' => '41 3000-0000', 'celular' => '41 99999-0000',
           'setor' => 'Manutenção', 'obs' => 'Entrega rápida', 'classificacao' => 'Autorizado']),
    $l(3, ['razao' => 'Matriz Nova SA (filial)', 'cnpj' => $filial]),
    $l(4, ['razao' => 'Filial sem matriz', 'cnpj' => $filial2]),
    $l(5, ['razao' => 'Sem CNPJ']),
    $l(6, ['razao' => 'CNPJ errado', 'cnpj' => '11222333000181']),
    $l(7, ['razao' => 'Repetido', 'cnpj' => $matriz]),
    $l(8, ['cnpj' => cnpjCom('111111110001')]),
    $l(9, ['razao' => 'Existente Ltda', 'cnpj' => $exist, 'cidade' => 'Maringá', 'email' => '', 'regime' => '',
           'obs' => 'Nota antiga.']),
    $l(10, ['razao' => 'Existente Filial', 'cnpj' => $existFil, 'status' => 'Inativo']),
    $l(11, ['razao' => 'Regime estranho', 'cnpj' => cnpjCom('222222220001'), 'regime' => 'Híbrido', 'email' => 'não-é-email',
            'cnd_federal' => 'talvez', 'cnd_federal_validade' => '01/01/2020', 'uf' => 'Paraná',
            'cidade' => str_repeat('x', 150)]),
    $l(12, ['razao' => 'MEI do bairro', 'cnpj' => cnpjCom('333333330001'), 'regime' => 'MEI', 'classificacao' => 'Não autorizado']),
];
$op = ['existentes' => $existentes, 'modo' => 'atualizar', 'confirmacao' => '2026-10-30', 'hoje' => '2026-10-30'];
$r  = Imp::analisar($linhas, $op);
$it = [];
foreach ($r['itens'] as $x) $it[$x['linha']] = $x;

confere('matriz nova = novo',               $it[2]['tipo'] === 'novo');
confere('matriz: regime presumido',         $it[2]['dados']['regime_tributario'] === 'lucro_presumido');
confere('matriz: regime confirmado na data', $it[2]['dados']['regime_confirmado_em'] === '2026-10-30');
confere('matriz: cnd + validade serial',    $it[2]['dados']['cnd_federal_situacao'] === 'negativa' && $it[2]['dados']['cnd_federal_validade'] === '2025-01-01');
confere('matriz: aviso CND vencida',        (bool)preg_grep('/CND.*vencida/i', $it[2]['avisos']));
confere('matriz: homologado',               $it[2]['dados']['situacao'] === 'homologado');
confere('matriz: telefone unido por " / "', $it[2]['dados']['telefone'] === '41 3000-0000 / 41 99999-0000' || (bool)preg_grep('/telefone/i', $it[2]['avisos']));
confere('matriz: setor e obs em observações', str_contains((string)$it[2]['dados']['observacoes'], 'Manutenção') && str_contains((string)$it[2]['dados']['observacoes'], 'Entrega rápida'));
confere('filial herda regime da matriz da planilha', $it[3]['tipo'] === 'novo' && $it[3]['dados']['regime_tributario'] === 'lucro_presumido'
                                            && (bool)preg_grep('/herdado da matriz/i', $it[3]['avisos']));
confere('filial nova = pendente',           $it[3]['dados']['situacao'] === 'pendente');
confere('filial sem matriz: completar depois', $it[4]['tipo'] === 'novo' && $it[4]['dados']['regime_tributario'] === null
                                            && (bool)preg_grep('/completar depois/i', $it[4]['avisos']));
confere('sem CNPJ = erro',                  $it[5]['tipo'] === 'erro' && (bool)preg_grep('/sem CNPJ/i', $it[5]['erros']));
confere('CNPJ inválido = erro',             $it[6]['tipo'] === 'erro' && (bool)preg_grep('/inválido/i', $it[6]['erros']));
confere('CNPJ repetido = erro',             $it[7]['tipo'] === 'erro' && (bool)preg_grep('/repetido/i', $it[7]['erros']));
confere('sem razão social = erro',          $it[8]['tipo'] === 'erro' && (bool)preg_grep('/razão social/i', $it[8]['erros']));
confere('existente = atualizar',            $it[9]['tipo'] === 'atualizar' && $it[9]['id'] === 'id-exist');
confere('existente: muda só a cidade',      array_keys($it[9]['mudancas']) === ['cidade']
                                            && $it[9]['mudancas']['cidade'] === ['antes' => 'Curitiba', 'depois' => 'Maringá']);
confere('vazio não apaga e-mail/regime',    !isset($it[9]['dados']['email']) && !isset($it[9]['dados']['regime_tributario']));
confere('obs já existente não repete',      !isset($it[9]['dados']['observacoes']));
confere('existente Inativo = bloqueado',    $it[10]['tipo'] === 'atualizar' && $it[10]['dados']['situacao'] === 'bloqueado');
confere('filial existente herda regime da matriz cadastrada', ($it[10]['dados']['regime_tributario'] ?? null) === 'lucro_real');
confere('regime estranho: em branco + aviso', $it[11]['tipo'] === 'novo' && $it[11]['dados']['regime_tributario'] === null
                                            && (bool)preg_grep('/não reconhecido/i', $it[11]['avisos']));
confere('e-mail inválido: aviso, não grava', $it[11]['dados']['email'] === null && (bool)preg_grep('/e-mail/i', $it[11]['avisos']));
confere('CND desconhecida: aviso',          $it[11]['dados']['cnd_federal_situacao'] === null && (bool)preg_grep('/CND Federal/i', $it[11]['avisos']));
confere('UF inválida: aviso, não grava',    $it[11]['dados']['estado'] === null && (bool)preg_grep('/UF/i', $it[11]['avisos']));
confere('cidade longa: cortada + aviso',    mb_strlen($it[11]['dados']['cidade']) === 100 && (bool)preg_grep('/cortad/i', $it[11]['avisos']));
confere('MEI: regime + marca MEI',          $it[12]['dados']['regime_tributario'] === 'mei' && $it[12]['dados']['mei'] === 1);
confere('Não autorizado = bloqueado',       $it[12]['dados']['situacao'] === 'bloqueado');
confere('totais',                           $r['totais']['novo'] === 5 && $r['totais']['atualizar'] === 2 && $r['totais']['erro'] === 4
                                            && $r['totais']['linhas'] === 11);

// Modo manter: existentes ignorados
$r2 = Imp::analisar($linhas, ['modo' => 'manter'] + $op);
$t2 = array_column($r2['itens'], 'tipo', 'linha');
confere('manter: existentes ignorados',     $t2[9] === 'ignorado' && $t2[10] === 'ignorado' && $r2['totais']['ignorado'] === 2);

// Sem mudança
$r3 = Imp::analisar([$l(2, ['razao' => 'Existente Ltda', 'cnpj' => $exist, 'cidade' => 'Curitiba'])], $op);
confere('existente igual = sem mudança',    $r3['itens'][0]['tipo'] === 'sem_mudanca');

// CNPJ numérico sem zero à esquerda (Excel guardou como número)
$zero = cnpjCom('012345670001');
$r4 = Imp::analisar([$l(2, ['razao' => 'Zero', 'cnpj' => ltrim($zero, '0')])], $op);
confere('CNPJ sem zero à esquerda completa', $r4['itens'][0]['tipo'] === 'novo' && $r4['itens'][0]['cnpj'] === $zero);

// ── Leitura de .xlsx mínimo (sharedStrings + números + data serial) ──
function xlsx(string $arq, array $abas): void {
    $z = new ZipArchive();
    $z->open($arq, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $sst = [];
    $sheetsXml = $rels = '';
    $i = 0;
    foreach ($abas as $nome => $linhas) {
        $i++;
        $rows = '';
        foreach ($linhas as $ri => $cols) {
            $cells = '';
            foreach (array_values($cols) as $ci => $v) {
                if ($v === null) continue;
                $ref = chr(65 + $ci) . ($ri + 1);
                if (is_int($v) || is_float($v)) {
                    $cells .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                } elseif (str_starts_with($v, 'inline:')) {
                    $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars(substr($v, 7)) . '</t></is></c>';
                } else {
                    $k = array_search($v, $sst, true);
                    if ($k === false) { $sst[] = $v; $k = count($sst) - 1; }
                    $cells .= '<c r="' . $ref . '" t="s"><v>' . $k . '</v></c>';
                }
            }
            $rows .= '<row r="' . ($ri + 1) . '">' . $cells . '</row>';
        }
        $z->addFromString("xl/worksheets/sheet$i.xml", '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rows . '</sheetData></worksheet>');
        $sheetsXml .= '<sheet name="' . htmlspecialchars($nome) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
    }
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheetsXml . '</sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
    $si = '';
    foreach ($sst as $s) $si .= '<si><t>' . htmlspecialchars($s) . '</t></si>';
    $z->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $si . '</sst>');
    $z->close();
}

$arqX = $tmp . DIRECTORY_SEPARATOR . 'f.xlsx';
xlsx($arqX, [
    'Capa'         => [['Lista de fornecedores'], ['sem cabeçalho aqui']],
    'Fornecedores' => [
        ['Relatório de fornecedores'],
        [],
        ['Observação', 'CNPJ', 'Razão Social', 'Regime Tributário', 'Validade CND Federal', 'E-mail contato', 'CND Estadual'],
        ['obs 1', (int)$matriz, 'inline:Empresa Inline', 'Simples', 45658, 'a@b.com', 'Negativa'],
        [null, null, null, null, null, null, null],
        ['', '11.222.333/0001-81', 'Ação & Cia', 'MEI', '05/03/2027', '', ''],
    ],
]);
$lido = (new P())->ler($arqX, 'f.xlsx');
confere('xlsx: aba com cabeçalho',          $lido['aba'] === 'Fornecedores');
confere('xlsx: 2 linhas (vazia pulada)',    count($lido['linhas']) === 2);
confere('xlsx: número da linha',            $lido['linhas'][0]['linha'] === 4 && $lido['linhas'][1]['linha'] === 6);
confere('xlsx: CNPJ numérico',              $lido['linhas'][0]['cnpj'] === $matriz);
confere('xlsx: inlineStr',                  $lido['linhas'][0]['razao'] === 'Empresa Inline');
confere('xlsx: sharedString com acento/&',  $lido['linhas'][1]['razao'] === 'Ação & Cia');
confere('xlsx: data serial',                P::data($lido['linhas'][0]['cnd_federal_validade']) === '2025-01-01');
confere('xlsx: colunas em qualquer ordem',  $lido['linhas'][0]['obs'] === 'obs 1' && $lido['linhas'][0]['regime'] === 'Simples');
confere('xlsx: CND Estadual ignorada',      !in_array('Negativa', $lido['linhas'][0], true));

// xlsx sem aba reconhecida
$arqX2 = $tmp . DIRECTORY_SEPARATOR . 'g.xlsx';
xlsx($arqX2, ['A' => [['Nome', 'Documento'], ['x', 'y']]]);
$erro = '';
try { (new P())->ler($arqX2, 'g.xlsx'); } catch (\RuntimeException $e) { $erro = $e->getMessage(); }
confere('xlsx sem cabeçalho: erro claro',   str_contains($erro, 'Razão Social'));

// xlsx com DOCTYPE/entidade: recusado
$arqX3 = $tmp . DIRECTORY_SEPARATOR . 'h.xlsx';
xlsx($arqX3, ['A' => [['CNPJ', 'Razão Social']]]);
$z = new ZipArchive(); $z->open($arqX3);
$z->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><!DOCTYPE sst [<!ENTITY x SYSTEM "file:///c:/windows/win.ini">]><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>&x;</t></si></sst>');
$z->close();
$erro = '';
try { (new P())->ler($arqX3, 'h.xlsx'); } catch (\RuntimeException $e) { $erro = $e->getMessage(); }
confere('xlsx com DOCTYPE recusado',        $erro !== '');

// .xls e extensão desconhecida
$arqXls = $tmp . DIRECTORY_SEPARATOR . 'v.xls';
file_put_contents($arqXls, "\xD0\xCF\x11\xE0");
$erro = '';
try { (new P())->ler($arqXls, 'velho.xls'); } catch (\RuntimeException $e) { $erro = $e->getMessage(); }
confere('.xls: pede salvar como .xlsx',     str_contains($erro, '.xlsx'));
$erro = '';
try { (new P())->ler($arqXls, 'a.txt'); } catch (\RuntimeException $e) { $erro = $e->getMessage(); }
confere('.txt recusado',                    $erro !== '');

// zip que não é planilha
$arqZ = $tmp . DIRECTORY_SEPARATOR . 'z.xlsx';
$z = new ZipArchive(); $z->open($arqZ, ZipArchive::CREATE); $z->addFromString('a.txt', 'oi'); $z->close();
$erro = '';
try { (new P())->ler($arqZ, 'z.xlsx'); } catch (\RuntimeException $e) { $erro = $e->getMessage(); }
confere('zip sem workbook recusado',        $erro !== '');

// zip bomb: entrada descompactada grande demais
$arqB = $tmp . DIRECTORY_SEPARATOR . 'b.xlsx';
xlsx($arqB, ['A' => [['CNPJ', 'Razão Social']]]);
$z = new ZipArchive(); $z->open($arqB);
$z->addFromString('xl/worksheets/sheet1.xml', str_repeat(' ', P::MAX_ENTRADA + 1));
$z->close();
$erro = '';
try { (new P())->ler($arqB, 'b.xlsx'); } catch (\RuntimeException $e) { $erro = $e->getMessage(); }
confere('entrada gigante recusada',         $erro !== '');

// ── CSV ──
$csv = function (string $nome, string $conteudo) use ($tmp): array {
    $arq = $tmp . DIRECTORY_SEPARATOR . $nome;
    file_put_contents($arq, $conteudo);
    return (new P())->ler($arq, $nome);
};
$c1 = $csv('a.csv', "\xEF\xBB\xBFRazão Social;CNPJ;Cidade\r\n\"Ação; Ltda\";11.222.333/0001-81;São Paulo\r\n;;\r\n");
confere('csv ; com BOM',                    count($c1['linhas']) === 1 && $c1['linhas'][0]['razao'] === 'Ação; Ltda'
                                            && $c1['linhas'][0]['cidade'] === 'São Paulo' && $c1['linhas'][0]['linha'] === 2);
$c2 = $csv('b.csv', "Razao Social,CNPJ,UF\nEmpresa X,11222333000181,pr\n");
confere('csv , sem BOM',                    count($c2['linhas']) === 1 && $c2['linhas'][0]['razao'] === 'Empresa X' && $c2['linhas'][0]['uf'] === 'pr');
$c3 = $csv('c.csv', mb_convert_encoding("Razão Social;CNPJ;Cidade\nAção Ltda;11222333000181;Maringá\n", 'Windows-1252', 'UTF-8'));
confere('csv Windows-1252',                 $c3['linhas'][0]['razao'] === 'Ação Ltda' && $c3['linhas'][0]['cidade'] === 'Maringá');
$c4 = $csv('d.csv', "Título\n\nRAZÃO SOCIAL;cnpj;E-MAIL\nX;11222333000181;x@y.com\n");
confere('csv cabeçalho abaixo do título',   count($c4['linhas']) === 1 && $c4['linhas'][0]['email'] === 'x@y.com' && $c4['linhas'][0]['linha'] === 4);

// ── Modelo ──
$cab = P::cabecalhoModelo();
confere('modelo: cabeçalho reconhecido',    $cab[0] === 'Razão Social' && in_array('CNPJ', $cab, true) && in_array('Classificação do Fornecedor', $cab, true)
                                            && !in_array('CND Estadual', $cab, true));

// limpeza
foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') as $f) unlink($f);
rmdir($tmp);

echo PHP_EOL . ($falhas ? "$falhas falha(s)" : 'tudo ok') . PHP_EOL;
exit($falhas ? 1 : 0);
