<?php
declare(strict_types=1);

/**
 * Geração de etiquetas para Zebra em EPL2.
 *
 * Etiqueta de locação: borda + logo Exemplo + QR com a locação + a locação
 * escrita. Mantém o cabeçalho do arquivo que já se usava (bobina de 104 × 20 mm,
 * deslocamento R158, tudo impresso girado 180°). A etiqueta de item é de outra
 * impressora (Honeywell, Fingerprint) e fica em etiqueta_fingerprint_lib.php.
 *
 * Como funciona: o modelo monta uma lista de elementos (caixa, imagem,
 * texto) em "coordenadas de leitura", isto é, como a pessoa lê a etiqueta
 * colada. etq_epl() converte essa lista para o EPL, girando tudo 180°, e a
 * prévia desenha a mesma lista. Assim o giro fica num lugar só.
 *
 * QR e logo vão como imagem (comando GW). O QR é gerado pelo qrcode_lib.php e
 * não pelo comando nativo "b" do EPL, que só existe nos firmwares da linha G
 * (GK/GC/GX420): as LP/TLP 2844 antigas ignoram. A Zebra térmica só imprime
 * preto: a borda sai preta, e o logo vermelho vira quadrado preto com o B vazado.
 *
 * Medidas em pontos de 203 dpi (8 pontos por mm). Compatível com PHP 7.4.
 */

require_once __DIR__ . '/qrcode_lib.php';

const ETQ_LARGURA = 831;   // q831
const ETQ_ALTURA  = 160;   // Q160
const ETQ_REF_X   = 158;   // R158,0: x = 0 do EPL fica 158 pontos para a direita

// Área útil em coordenadas do EPL (já descontado o R158), a mesma que o
// modelo antigo ocupava: da borda esquerda da impressão até x = 408. Em
// coordenadas de leitura o intervalo é o mesmo, espelhado.
const ETQ_AREA_ESQ = -ETQ_REF_X;
const ETQ_AREA_DIR = 408;
const ETQ_ESPACO   = 20;   // entre logo, QR e texto (2,5 mm): margem clara do QR

// Borda: afastada da beira para não sumir se o corte da bobina variar ~1 mm.
const ETQ_BORDA_MARGEM    = 6;
const ETQ_BORDA_ESPESSURA = 4;
const ETQ_BORDA_RESPIRO   = 10;  // entre a borda e o conteúdo

// Logo: o PNG é convertido uma vez para 1 bit e guardado em texto (PBM), para
// que produção não precise da extensão GD.
const ETQ_LOGO_PNG  = __DIR__ . '/img/logo.svg';
const ETQ_LOGO_PBM  = __DIR__ . '/img/logo.svg';
const ETQ_LOGO_LADO = 112;  // 14 mm

// Fontes residentes do EPL a 203 dpi: [largura, altura, passo] em pontos
// (o passo inclui 2 pontos de espaço entre letras).
const ETQ_FONTES = [1 => [8, 12, 10], 2 => [10, 16, 12], 3 => [12, 20, 14], 4 => [14, 24, 16]];

/**
 * Escapa um valor para dentro de "..." no EPL e converte para Latin-1, que é o
 * que I8,A espera. Quebras de linha virariam fim de comando, então saem.
 */
function etq_epl_valor(string $valor): string
{
    $valor = str_replace(["\r", "\n"], ' ', $valor);
    $valor = str_replace(['\\', '"'], ['\\\\', '\\"'], $valor);
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($valor, 'ABC1D23-1', 'UTF-8');
    }
    return utf8_decode($valor);
}

/**
 * Converte o PNG do logo em PBM de 1 bit (precisa de GD; roda uma vez, na
 * máquina de desenvolvimento). Vermelho e escuro viram preto, branco e
 * transparente ficam brancos.
 */
function etq_logo_gerar_pbm(): void
{
    $src = imagecreatefrompng(ETQ_LOGO_PNG);
    $lado = ETQ_LOGO_LADO;
    $dst = imagecreatetruecolor($lado, $lado);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $lado, $lado, imagesx($src), imagesy($src));
    $linhas = [];
    for ($y = 0; $y < $lado; $y++) {
        $l = '';
        for ($x = 0; $x < $lado; $x++) {
            $c = imagecolorat($dst, $x, $y);
            $lum = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
            $l .= $lum < 190 ? '1' : '0';
        }
        $linhas[] = $l;
    }
    file_put_contents(ETQ_LOGO_PBM, "P1\n# logo Exemplo para etiqueta Zebra, gerado por etq_logo_gerar_pbm()\n$lado $lado\n" . implode("\n", $linhas) . "\n");
}

/** Matriz do logo (true = preto), como se lê. */
function etq_logo_matriz(): array
{
    if (!is_file(ETQ_LOGO_PBM)) {
        etq_logo_gerar_pbm();
    }
    $txt = preg_replace('/#.*$/m', '', (string)file_get_contents(ETQ_LOGO_PBM));
    $tok = preg_split('/\s+/', trim($txt));
    [, $w, $h] = $tok;
    $bits = implode('', array_slice($tok, 3));
    $m = [];
    for ($y = 0; $y < (int)$h; $y++) {
        $m[] = array_map(function ($b) { return $b === '1'; }, str_split(substr($bits, $y * (int)$w, (int)$w)));
    }
    return $m;
}

/** Gira uma matriz 180°. */
function etq_girar180(array $m): array
{
    return array_map('array_reverse', array_reverse($m));
}

/** Largura e altura de um texto numa fonte residente, em pontos. */
function etq_texto_medida(string $texto, int $fonte, int $hmult, int $vmult): array
{
    [, $alt, $passo] = ETQ_FONTES[$fonte];
    // Conta caracteres como a impressora (Latin-1), sem os escapes do EPL.
    $n = function_exists('mb_strlen') ? mb_strlen($texto, 'UTF-8') : strlen(utf8_decode($texto));
    return [$n * $passo * $hmult, $alt * $vmult];
}

function etq_el_texto(int $u, int $v, string $texto, int $fonte, int $hmult = 1, int $vmult = 1): array
{
    [$w, $h] = etq_texto_medida($texto, $fonte, $hmult, $vmult);
    return ['tipo' => 'texto', 'u' => $u, 'v' => $v, 'w' => $w, 'h' => $h,
            'texto' => $texto, 'fonte' => $fonte, 'hmult' => $hmult, 'vmult' => $vmult];
}

function etq_el_imagem(int $u, int $v, array $matriz, int $escala, string $nome): array
{
    return ['tipo' => 'imagem', 'u' => $u, 'v' => $v, 'w' => count($matriz[0]) * $escala,
            'h' => count($matriz) * $escala, 'matriz' => $matriz, 'escala' => $escala, 'nome' => $nome];
}

/**
 * Parte comum dos modelos: borda e logo. Devolve os elementos e a área que
 * sobra para o resto, em coordenadas de leitura.
 */
function etq_moldura(bool $logo, bool $borda): array
{
    $esq = ETQ_AREA_ESQ;
    $dir = ETQ_AREA_DIR;
    $top = 0;
    $baixo = ETQ_ALTURA;
    $els = [];
    if ($borda) {
        $m = ETQ_BORDA_MARGEM;
        $els[] = ['tipo' => 'caixa', 'u' => $esq + $m, 'v' => $m, 'w' => $dir - $esq - 2 * $m,
                  'h' => ETQ_ALTURA - 2 * $m, 'espessura' => ETQ_BORDA_ESPESSURA];
        $folga = $m + ETQ_BORDA_ESPESSURA + ETQ_BORDA_RESPIRO;
        $esq += $folga;
        $dir -= $folga;
        $top += $m + ETQ_BORDA_ESPESSURA;
        $baixo -= $m + ETQ_BORDA_ESPESSURA;
    }
    $area = ['esq' => $esq, 'dir' => $dir, 'top' => $top, 'baixo' => $baixo];
    if ($logo) {
        $lm = etq_logo_matriz();
        $els[] = etq_el_imagem($esq, etq_centro_v($area, count($lm)), $lm, 1, 'logo');
        $area['esq'] += count($lm) + ETQ_ESPACO;
    }
    return [$els, $area];
}

/**
 * Posição vertical (de leitura) que centraliza um elemento de altura $h na
 * área. Arredonda como a impressora veria: pelo lado de baixo da leitura,
 * que é o de cima no papel.
 */
function etq_centro_v(array $area, int $h): int
{
    return ETQ_ALTURA - ($area['top'] + intdiv($area['baixo'] - $area['top'] - $h, 2)) - $h;
}

/** QR à esquerda da área, centralizado na altura; avança a área. */
function etq_qr(array &$area, string $conteudo, bool $borda): array
{
    $qr     = qr_matriz($conteudo);
    $alt    = $area['baixo'] - $area['top'];
    // Maior módulo que deixa ~4 módulos de margem clara em cima e embaixo
    // (com borda, a margem até ela conta: ~2 módulos de cada lado bastam).
    $escala = max(2, min(6, intdiv($alt, count($qr) + ($borda ? 4 : 8))));
    $el     = etq_el_imagem($area['esq'], etq_centro_v($area, count($qr) * $escala), $qr, $escala, 'QR');
    $area['esq'] += $el['w'] + ETQ_ESPACO;
    return $el;
}

/** Etiqueta de locação: [logo] QR(locação) + locação escrita. */
function etq_locacao_layout(string $locacao, bool $logo = true, bool $borda = true): array
{
    [$els, $area] = etq_moldura($logo, $borda);
    $qr = etq_qr($area, $locacao, $borda);
    $els[] = $qr;

    // Locação em fonte 4 com 9 mm de altura, na maior largura que couber,
    // centralizada no espaço que sobrou.
    $disp  = $area['dir'] - $area['esq'];
    $hmult = 1;
    foreach ([3, 2] as $m) {
        if (etq_texto_medida($locacao, 4, $m, 3)[0] <= $disp) {
            $hmult = $m;
            break;
        }
    }
    $txt = etq_el_texto(0, 0, $locacao, 4, $hmult, 3);
    $txt['u'] = $area['esq'] + max(0, intdiv($disp - $txt['w'], 2));
    $txt['v'] = ETQ_ALTURA - intdiv(ETQ_ALTURA + $txt['h'], 2);
    $els[] = $txt;

    return ['elementos' => $els, 'qr' => $qr, 'disponivel' => $disp, 'estreito' => $hmult < 2,
            'cortado' => $txt['w'] > $disp];
}

/**
 * Bitmap no formato do GW: cada linha com ceil(largura/8) bytes, bit 0
 * imprime (preto) e bit 1 fica em branco.
 */
function etq_bitmap_gw(array $m, int $escala = 1): array
{
    $lado   = count($m[0]) * $escala;
    $bytesL = intdiv($lado + 7, 8);
    $dados  = '';
    foreach ($m as $linha) {
        $bits = '';
        foreach ($linha as $preto) {
            $bits .= str_repeat($preto ? '0' : '1', $escala);
        }
        $bits = str_pad($bits, $bytesL * 8, '1');
        $bin = '';
        foreach (str_split($bits, 8) as $b) {
            $bin .= chr(bindec($b));
        }
        $dados .= str_repeat($bin, $escala);
    }
    return [$bytesL, count($m) * $escala, $dados];
}

/**
 * Converte os elementos (coordenadas de leitura) no arquivo EPL, girando tudo
 * 180°. Com $legivel, os bitmaps (binários) são trocados por um resumo, para
 * exibir o arquivo na tela.
 */
function etq_epl(array $elementos, int $copias = 1, bool $legivel = false): string
{
    $copias = max(1, min(999, $copias));
    // Um ponto u (leitura) cai em x = S - u no EPL; v cai em y = ALTURA - v.
    $s = ETQ_AREA_ESQ + ETQ_AREA_DIR;
    $h = ETQ_ALTURA;

    $cmd = ['I8,A,001', '', 'Q160,024', 'q831', 'rN', 'S3', 'D7', 'ZT', 'JF', 'O', 'R158,0', 'f100', 'N'];
    foreach ($elementos as $e) {
        switch ($e['tipo']) {
            case 'caixa':
                $cmd[] = 'X' . ($s - $e['u'] - $e['w']) . ',' . ($h - $e['v'] - $e['h']) . ',' . $e['espessura']
                    . ',' . ($s - $e['u']) . ',' . ($h - $e['v']);
                break;
            case 'imagem':
                // GW não gira: a imagem vai girada e ancorada no canto de cima.
                [$bytesL, $alt, $dados] = etq_bitmap_gw(etq_girar180($e['matriz']), $e['escala']);
                if ($legivel) {
                    $dados = '<' . strlen($dados) . ' bytes do ' . $e['nome'] . ' em binário>';
                }
                $cmd[] = 'GW' . ($s - $e['u'] - $e['w']) . ',' . ($h - $e['v'] - $e['h']) . ',' . $bytesL . ',' . $alt . ',' . $dados;
                break;
            case 'texto':
                // Rotação 2 (180°): a âncora é o canto que, lido, fica em cima à esquerda.
                $cmd[] = 'A' . ($s - $e['u']) . ',' . ($h - $e['v']) . ',2,' . $e['fonte'] . ',' . $e['hmult'] . ','
                    . $e['vmult'] . ',N,"' . etq_epl_valor($e['texto']) . '"';
                break;
        }
    }
    $cmd[] = 'P' . $copias;
    return implode("\n", $cmd) . "\n";
}

/** Arquivo EPL da etiqueta de locação. */
function etq_zebra_locacao(string $locacao, int $copias = 1, bool $legivel = false, bool $logo = true, bool $borda = true): string
{
    return etq_epl(etq_locacao_layout($locacao, $logo, $borda)['elementos'], $copias, $legivel);
}
