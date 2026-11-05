<?php
declare(strict_types=1);

/**
 * Etiqueta de item para Honeywell/Intermec (linguagem Fingerprint / Direct
 * Protocol), a partir do arquivo que o BarTender gerava (driver Seagull).
 *
 * É o arquivo original com <parametro1> e <parametro2> preenchidos; nenhuma
 * posição foi mudada. O QR é o nativo da impressora (BARSET "QRCODE").
 *
 * Medidas em pontos. A área é 580 × 390 com passo de 40 mm, o que só fecha
 * numa impressora de 300 dpi (11,8 pontos/mm): etiqueta de ~49 × 33 mm.
 * Origem embaixo à esquerda, y cresce para cima; AN7 ancora cada elemento
 * pelo canto superior esquerdo. De cima para baixo:
 *   y 315  "EMPRESA EXEMPLO"      (Andale Mono Bold 12)
 *   y 257  <parametro1>         (Andale Mono 12)
 *   y 193  QR com <parametro2>  (módulo de 6 pontos)
 *   y  54  <parametro2>         (Andale Mono 10)
 *
 * NASC 8 põe a impressora em UTF-8: o texto vai como está.
 * Compatível com PHP 7.4.
 */

const FP_LARGURA = 580;
const FP_ALTURA  = 390;

// Posição x e largura aproximada de cada caractere (fonte monoespaçada:
// 0,6 × o tamanho em pontos tipográficos convertido para 300 dpi).
const FP_P1_X     = 50;
const FP_P1_PASSO = 30;  // Andale Mono 12
const FP_P2_X     = 156;
const FP_P2_PASSO = 25;  // Andale Mono 10

/**
 * Transforma um valor numa expressão de string do Fingerprint. Aspas não têm
 * escape dentro de "...": entram concatenadas como CHR$(34). Quebras de linha
 * encerrariam o comando, então saem.
 */
function fp_string(string $valor): string
{
    $valor = str_replace(["\r", "\n"], ' ', $valor);
    return '"' . str_replace('"', '"+CHR$(34)+"', $valor) . '"';
}

/** Quantos caracteres cabem até a borda direita (o resto é cortado: Clip Default On). */
function fp_max_caracteres(int $x, int $passo): int
{
    return intdiv(FP_LARGURA - $x, $passo);
}

/**
 * Arquivo completo da etiqueta de item.
 *
 * @param string $parametro1 texto grande acima do QR
 * @param string $parametro2 código: vai no QR e escrito embaixo dele
 */
function fp_item(string $parametro1, string $parametro2, int $copias = 1): string
{
    $copias = max(1, min(999, $copias));
    $p1 = fp_string($parametro1);
    $p2 = fp_string($parametro2);

    $setup = [
        "<xpml><page quantity='0' pitch='40.0 mm'></xpml>'Seagull:2.1:DP",
        'INPUT OFF',
        'VERBOFF',
        'INPUT ON',
        'SYSVAR(48) = 0',
        'ERROR 15,"FONT NOT FOUND"',
        'ERROR 18,"DISK FULL"',
        'ERROR 26,"PARAMETER TOO LARGE"',
        'ERROR 27,"PARAMETER TOO SMALL"',
        'ERROR 37,"CUTTER DEVICE NOT FOUND"',
        'ERROR 1003,"FIELD OUT OF LABEL"',
        'SYSVAR(35)=0',
        'OPEN "tmp:setup.sys" FOR OUTPUT AS #1',
        'PRINT#1,"Printing,Media,Print Area,Media Margin (X),0"',
        'PRINT#1,"Printing,Media,Print Area,Media Width,' . FP_LARGURA . '"',
        'PRINT#1,"Printing,Media,Print Area,Media Length,' . FP_ALTURA . '"',
        'PRINT#1,"Printing,Media,Clip Default,On"',
        'CLOSE #1',
        'SETUP "tmp:setup.sys"',
        'KILL "tmp:setup.sys"',
    ];

    // Uma página por cópia, como o BarTender faz com quantity: cada página
    // monta a etiqueta do zero (CLL) e imprime (PF).
    $pagina = [
        "<xpml></page></xpml><xpml><page quantity='1' pitch='40.0 mm'></xpml>CLL",
        'OPTIMIZE "BATCH" ON',
        'PP170,193:AN7',
        'BARSET "QRCODE",1,1,6,2,1',
        'PB ' . $p2,
        'PP156,54:NASC 8',
        'FT "Andale Mono",10,0,99',
        'PT ' . $p2,
        'PP50,257:FT "Andale Mono",12,0,99',
        'PT ' . $p1,
        'PP103,315:FT "Andale Mono Bold",12,0,99',
        'PT "EMPRESA EXEMPLO"',
        'LAYOUT RUN ""',
        'PF',
        'PRINT KEY OFF',
    ];

    $linhas = $setup;
    for ($i = 0; $i < $copias; $i++) {
        $linhas = array_merge($linhas, $pagina);
    }
    $linhas[] = '<xpml></page></xpml><xpml><end/></xpml>';
    return implode("\r\n", $linhas) . "\r\n";
}
