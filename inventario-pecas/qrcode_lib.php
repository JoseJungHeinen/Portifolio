<?php
declare(strict_types=1);

/**
 * Gerador de QR Code em PHP puro (sem dependências), modo byte, correção M,
 * versões 1 a 6 (até 106 bytes). É o bastante para locações e códigos de item.
 *
 * Segue o algoritmo da especificação ISO/IEC 18004 na forma organizada por
 * Project Nayuki. Versões 7+ exigiriam o bloco de informação de versão, que
 * não foi implementado.
 *
 * qr_matriz('1-22-13-A-1') devolve array de linhas; cada linha é array de bool
 * (true = módulo escuro). Sem zona de silêncio.
 */

// Por versão (índice 1..6), correção M: total de codewords, EC por bloco, blocos.
const QR_TOTAL_CW  = [0, 26, 44, 70, 100, 134, 172];
const QR_EC_BLOCO  = [0, 10, 16, 26, 18, 24, 16];
const QR_N_BLOCOS  = [0, 1, 1, 1, 2, 2, 4];
const QR_ALINHAM   = [0, [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34]];

function qr_gf_mul(int $x, int $y): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z;
}

function qr_rs_divisor(int $grau): array
{
    $r = array_fill(0, $grau, 0);
    $r[$grau - 1] = 1;
    $raiz = 1;
    for ($i = 0; $i < $grau; $i++) {
        for ($j = 0; $j < $grau; $j++) {
            $r[$j] = qr_gf_mul($r[$j], $raiz);
            if ($j + 1 < $grau) {
                $r[$j] ^= $r[$j + 1];
            }
        }
        $raiz = qr_gf_mul($raiz, 0x02);
    }
    return $r;
}

function qr_rs_resto(array $dados, array $div): array
{
    $r = array_fill(0, count($div), 0);
    foreach ($dados as $b) {
        $f = $b ^ array_shift($r);
        $r[] = 0;
        foreach ($div as $i => $d) {
            $r[$i] ^= qr_gf_mul($d, $f);
        }
    }
    return $r;
}

/** Menor versão em que $n bytes cabem, ou 0 se não couber em nenhuma. */
function qr_versao_para(int $n): int
{
    for ($v = 1; $v <= 6; $v++) {
        $dadosCw = QR_TOTAL_CW[$v] - QR_EC_BLOCO[$v] * QR_N_BLOCOS[$v];
        if (4 + 8 + $n * 8 <= $dadosCw * 8) {
            return $v;
        }
    }
    return 0;
}

/** Codewords finais (dados + correção, intercalados) para o texto. */
function qr_codewords(string $texto, int $v): array
{
    $total   = QR_TOTAL_CW[$v];
    $ec      = QR_EC_BLOCO[$v];
    $nBlocos = QR_N_BLOCOS[$v];
    $cap     = ($total - $ec * $nBlocos) * 8;

    $bits = '0100' . str_pad(decbin(strlen($texto)), 8, '0', STR_PAD_LEFT);
    for ($i = 0, $n = strlen($texto); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($texto[$i])), 8, '0', STR_PAD_LEFT);
    }
    $bits .= str_repeat('0', min(4, $cap - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    $dados = [];
    foreach (str_split($bits, 8) as $byte) {
        $dados[] = bindec($byte);
    }
    for ($pad = 0xEC; count($dados) < $cap / 8; $pad ^= 0xEC ^ 0x11) {
        $dados[] = $pad;
    }

    $curtos   = $nBlocos - $total % $nBlocos;
    $lenCurto = intdiv($total, $nBlocos);
    $div      = qr_rs_divisor($ec);
    $blocos   = [];
    $k = 0;
    for ($i = 0; $i < $nBlocos; $i++) {
        $len = $lenCurto - $ec + ($i < $curtos ? 0 : 1);
        $d = array_slice($dados, $k, $len);
        $k += $len;
        $e = qr_rs_resto($d, $div);
        if ($i < $curtos) {
            $d[] = 0;
        }
        $blocos[] = array_merge($d, $e);
    }
    $saida = [];
    $lenBloco = count($blocos[0]);
    for ($i = 0; $i < $lenBloco; $i++) {
        foreach ($blocos as $j => $b) {
            if ($i !== $lenCurto - $ec || $j >= $curtos) {
                $saida[] = $b[$i];
            }
        }
    }
    return $saida;
}

function qr_mascara(int $m, int $x, int $y): bool
{
    switch ($m) {
        case 0: return ($x + $y) % 2 === 0;
        case 1: return $y % 2 === 0;
        case 2: return $x % 3 === 0;
        case 3: return ($x + $y) % 3 === 0;
        case 4: return (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0;
        case 5: return ($x * $y) % 2 + ($x * $y) % 3 === 0;
        case 6: return (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0;
        default: return (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0;
    }
}

/** Grava os 15 bits de formato (correção M = 00) para a máscara dada. */
function qr_formato(array &$mod, array &$func, int $tam, int $mascara): void
{
    $dado = (0 << 3) | $mascara;
    $rem = $dado;
    for ($i = 0; $i < 10; $i++) {
        $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
    }
    $bits = (($dado << 10) | $rem) ^ 0x5412;
    $b = function (int $i) use ($bits): bool { return (($bits >> $i) & 1) === 1; };
    $set = function (int $x, int $y, bool $v) use (&$mod, &$func): void { $mod[$y][$x] = $v; $func[$y][$x] = true; };

    for ($i = 0; $i <= 5; $i++) { $set(8, $i, $b($i)); }
    $set(8, 7, $b(6));
    $set(8, 8, $b(7));
    $set(7, 8, $b(8));
    for ($i = 9; $i < 15; $i++) { $set(14 - $i, 8, $b($i)); }
    for ($i = 0; $i < 8; $i++) { $set($tam - 1 - $i, 8, $b($i)); }
    for ($i = 8; $i < 15; $i++) { $set(8, $tam - 15 + $i, $b($i)); }
    $set(8, $tam - 8, true); // módulo escuro fixo
}

function qr_penalidade(array $m, int $tam): int
{
    $p = 0;
    // Regra 1 (sequências de 5+ iguais) e regra 3 (padrão parecido com localizador), em linhas e colunas.
    for ($eixo = 0; $eixo < 2; $eixo++) {
        for ($a = 0; $a < $tam; $a++) {
            $linha = '';
            for ($c = 0; $c < $tam; $c++) {
                $linha .= ($eixo === 0 ? $m[$a][$c] : $m[$c][$a]) ? '1' : '0';
            }
            if (preg_match_all('/0{5,}|1{5,}/', $linha, $r)) {
                foreach ($r[0] as $seq) { $p += 3 + strlen($seq) - 5; }
            }
            $p += 40 * (substr_count($linha, '10111010000') + substr_count($linha, '00001011101'));
        }
    }
    // Regra 2: blocos 2x2 da mesma cor.
    $escuros = 0;
    for ($y = 0; $y < $tam; $y++) {
        for ($x = 0; $x < $tam; $x++) {
            if ($m[$y][$x]) { $escuros++; }
            if ($x < $tam - 1 && $y < $tam - 1
                && $m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                $p += 3;
            }
        }
    }
    // Regra 4: equilíbrio entre claro e escuro.
    $total = $tam * $tam;
    $k = intdiv(abs($escuros * 20 - $total * 10) + $total - 1, $total) - 1;
    return $p + max(0, $k) * 10;
}

/**
 * Matriz do QR para o texto (bytes como vierem; use ASCII para locações).
 * Lança InvalidArgumentException se o texto não couber na versão 6.
 */
function qr_matriz(string $texto): array
{
    $v = qr_versao_para(strlen($texto));
    if ($v === 0) {
        throw new InvalidArgumentException('Texto longo demais para o QR (máximo 106 bytes).');
    }
    $tam  = 17 + 4 * $v;
    $mod  = array_fill(0, $tam, array_fill(0, $tam, false));
    $func = $mod;
    $set = function (int $x, int $y, bool $val) use (&$mod, &$func): void { $mod[$y][$x] = $val; $func[$y][$x] = true; };

    for ($i = 0; $i < $tam; $i++) {
        $set(6, $i, $i % 2 === 0);
        $set($i, 6, $i % 2 === 0);
    }
    foreach ([[3, 3], [$tam - 4, 3], [3, $tam - 4]] as [$cx, $cy]) {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx; $y = $cy + $dy;
                if ($x >= 0 && $x < $tam && $y >= 0 && $y < $tam) {
                    $d = max(abs($dx), abs($dy));
                    $set($x, $y, $d !== 2 && $d !== 4);
                }
            }
        }
    }
    $al = QR_ALINHAM[$v];
    $n = count($al);
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                continue;
            }
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $set($al[$i] + $dx, $al[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                }
            }
        }
    }
    qr_formato($mod, $func, $tam, 0); // reserva a área do formato

    $cw = qr_codewords($texto, $v);
    $nBits = count($cw) * 8;
    $i = 0;
    for ($dir = $tam - 1; $dir >= 1; $dir -= 2) {
        if ($dir === 6) { $dir = 5; }
        for ($vert = 0; $vert < $tam; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $dir - $j;
                $subindo = (($dir + 1) & 2) === 0;
                $y = $subindo ? $tam - 1 - $vert : $vert;
                if (!$func[$y][$x] && $i < $nBits) {
                    $mod[$y][$x] = (($cw[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                    $i++;
                }
            }
        }
    }

    $melhor = null;
    $melhorPen = PHP_INT_MAX;
    for ($m = 0; $m < 8; $m++) {
        $t = $mod;
        $f = $func;
        for ($y = 0; $y < $tam; $y++) {
            for ($x = 0; $x < $tam; $x++) {
                if (!$func[$y][$x] && qr_mascara($m, $x, $y)) {
                    $t[$y][$x] = !$t[$y][$x];
                }
            }
        }
        qr_formato($t, $f, $tam, $m);
        $pen = qr_penalidade($t, $tam);
        if ($pen < $melhorPen) {
            $melhorPen = $pen;
            $melhor = $t;
        }
    }
    return $melhor;
}
