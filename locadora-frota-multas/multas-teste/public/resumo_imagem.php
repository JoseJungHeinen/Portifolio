<?php
/**
 * Imagem PNG do "Painel Andamento das Multas" para mandar no grupo do WhatsApp.
 * Largura de celular (1080 px), gerada com o GD do PHP (sem bibliotecas externas).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/painel.php';

$d = dados_painel();
$FONTE = 'C:/Windows/Fonts/segoeui.ttf';
$FONTE_B = 'C:/Windows/Fonts/segoeuib.ttf';
if (!is_file($FONTE)) { $FONTE = 'C:/Windows/Fonts/arial.ttf'; $FONTE_B = 'C:/Windows/Fonts/arialbd.ttf'; }

$L = 1080;
$M = 56;                     // margem
$alturaLinha = 74;
$H = 2400; // recortada no final para a altura real
/** Rótulos curtos para caber na largura do celular. */
const ROTULO_CURTO = [
    'nova' => 'Novas infrações', 'em_processo' => 'Identificação do condutor', 'a_faturar' => 'Faturar ao cliente',
    'aguardando_vcto' => 'Aguardando vencimento', 'a_pagar' => 'Pagar a infração', 'aguardando_baixar' => 'Pagas, aguardando baixa',
];
$img = imagecreatetruecolor($L, $H);
imageantialias($img, true);
$cor = fn($hex) => imagecolorallocate($img, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
$C = [
    'bg' => $cor('#f5f6f4'), 'card' => $cor('#ffffff'), 'txt' => $cor('#1d2421'), 'muted' => $cor('#66706b'),
    'borda' => $cor('#dfe3e0'), 'verde' => $cor('#0f6e56'), 'verdebg' => $cor('#e1f5ee'), 'branco' => $cor('#ffffff'),
    'nova' => $cor('#b42318'), 'em_processo' => $cor('#b54708'), 'a_faturar' => $cor('#6941c6'),
    'aguardando_vcto' => $cor('#175cd3'), 'a_pagar' => $cor('#c11574'), 'aguardando_baixar' => $cor('#067647'),
];
imagefilledrectangle($img, 0, 0, $L, $H, $C['bg']);

/** Escreve texto; $alinhar = 'e' (esquerda) ou 'd' (direita, x = borda direita). */
$texto = function (string $s, int $tam, int $x, int $y, $c, bool $negrito = false, string $alinhar = 'e') use ($img, $FONTE, $FONTE_B) {
    $f = $negrito ? $FONTE_B : $FONTE;
    if ($alinhar === 'd') { $b = imagettfbbox($tam, 0, $f, $s); $x -= ($b[2] - $b[0]); }
    imagettftext($img, $tam, 0, $x, $y, $c, $f, $s);
};
$retArred = function (int $x1, int $y1, int $x2, int $y2, int $r, $c) use ($img) {
    imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $c);
    imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $c);
    foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
        imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $c);
    }
};

// ---- Cabeçalho
imagefilledrectangle($img, 0, 0, $L, 190, $C['verde']);
$texto('Painel Andamento das Multas', 44, $M, 92, $C['branco'], true);
$texto($d['data'] . ($d['ultimaCaptura'] ? '   ·   captura DETRAN ' . $d['ultimaCaptura'] : ''), 26, $M, 146, $C['verdebg']);

// ---- Status
$y = 230;
$alturaCard = 70 + count($d['linhas']) * $alturaLinha + 170;
$retArred($M - 16, $y, $L - $M + 16, $y + $alturaCard, 22, $C['card']);
$y += 62;
$texto('STATUS', 21, $M + 10, $y, $C['muted'], true);
$texto('QTD.', 21, 700, $y, $C['muted'], true, 'd');
$texto('VALOR', 21, $L - $M - 10, $y, $C['muted'], true, 'd');
$y += 22;
foreach ($d['linhas'] as $l) {
    $y += $alturaLinha;
    imagefilledellipse($img, $M + 22, $y - 13, 22, 22, $C[$l['chave']]);
    $texto(ROTULO_CURTO[$l['chave']] ?? $l['rotulo'], 27, $M + 48, $y, $C['txt']);
    $texto((string)$l['q'], 30, 700, $y, $C['txt'], true, 'd');
    $texto(brl_curto($l['v']), 27, $L - $M - 10, $y, $C['txt'], false, 'd');
    imageline($img, $M + 10, $y + 24, $L - $M - 10, $y + 24, $C['borda']);
}
$y += 82;
imagefilledrectangle($img, $M + 10, $y - 50, $L - $M - 10, $y - 47, $C['txt']);
$texto('Total em aberto no DETRAN', 28, $M + 10, $y, $C['txt'], true);
$texto((string)$d['aberto']['q'], 32, 700, $y, $C['txt'], true, 'd');
$texto(brl_curto($d['aberto']['v']), 28, $L - $M - 10, $y, $C['txt'], true, 'd');
$y += 58;
$texto('Finalizadas · total geral ' . $d['totalGeral'], 24, $M + 10, $y, $C['muted']);
$texto((string)$d['finalizadas']['q'], 26, 700, $y, $C['muted'], false, 'd');
$texto(brl_curto($d['finalizadas']['v']), 24, $L - $M - 10, $y, $C['muted'], false, 'd');

// ---- Lojas
$y += 70;
$alturaLojas = 90 + count($d['lojas']) * 78;
$retArred($M - 16, $y, $L - $M + 16, $y + $alturaLojas, 22, $C['card']);
$y += 62;
$texto('LOJAS', 21, $M + 10, $y, $C['muted'], true);
$texto('EM ABERTO / TOTAL', 21, $L - $M - 10, $y, $C['muted'], true, 'd');
foreach ($d['lojas'] as $l) {
    $y += 78;
    $texto($l['nome'], 28, $M + 10, $y, $C['txt'], true);
    $bx = 340; $bw = 290;
    $retArred($bx, $y - 26, $bx + $bw, $y - 6, 10, $C['borda']);
    if ($l['pct'] > 0) $retArred($bx, $y - 26, $bx + max(20, (int)round($bw * $l['pct'])), $y - 6, 10, $C['verde']);
    $texto(number_format($l['pct'] * 100, 1, ',', '') . '%', 24, $bx + $bw + 20, $y - 2, $C['muted']);
    $texto("{$l['aberto']} / {$l['total']}", 28, $L - $M - 10, $y, $C['txt'], false, 'd');
}

// ---- Rodapé: o que aconteceu hoje
$y += 70;
$retArred($M - 16, $y, $L - $M + 16, $y + 120, 22, $C['verdebg']);
$texto('Hoje', 22, $M + 10, $y + 46, $C['verde'], true);
$texto("{$d['dia']['novas_hoje']} novas   ·   {$d['dia']['sairam_hoje']} saíram do DETRAN   ·   {$d['dia']['vencidas_detran']} vencidas no DETRAN",
    25, $M + 10, $y + 92, $C['txt']);

$img2 = imagecrop($img, ['x' => 0, 'y' => 0, 'width' => $L, 'height' => min($H, $y + 156)]);
header('Content-Type: image/png');
header('Cache-Control: no-store');
if (isset($_GET['baixar'])) header('Content-Disposition: attachment; filename="painel_multas_' . date('Y-m-d') . '.png"');
imagepng($img2 ?: $img);
