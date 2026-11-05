<?php
// @versao Rel_08 V_01_03/08/2026
declare(strict_types=1);

/**
 * PdfWriter — gerador mínimo de PDF de TABELA, sem dependência externa.
 *
 * Escreve o PDF na mão (objetos + xref + trailer), como o XlsxWriter faz com
 * o XLSX. Usa só as fontes base do PDF (Helvetica / Helvetica-Bold), que todo
 * leitor tem embutidas — por isso não há arquivo de fonte para embarcar e o
 * texto sai em WinAnsi (CP1252), suficiente para português.
 *
 * Layout: A4 paisagem, cabeçalho de tabela no cinza #606060 do design system,
 * linhas zebradas, quebra de página automática e rodapé "Página X de Y".
 */
class PdfWriter
{
    // A4 paisagem, em pontos
    private const LARGURA = 842.0;
    private const ALTURA  = 595.0;
    private const MARGEM  = 28.0;

    private const FONTE_TITULO   = 15.0;
    private const FONTE_SUB      = 8.5;
    private const FONTE_CABECALHO = 8.0;
    private const FONTE_CORPO    = 8.0;
    private const ALTURA_LINHA   = 15.0;

    private const PADDING_CELULA = 4.0;

    /**
     * Largura de cada caractere ASCII (32–126) em Helvetica e Helvetica-Bold,
     * em milésimos do corpo da fonte — valores das AFM oficiais das fontes
     * base do PDF. Sem isso a coluna seria dimensionada por média e cortaria
     * texto que caberia (ou sobraria espaço em coluna numérica).
     */
    private const LARGURAS = [
        false => [ // Helvetica
            278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
            556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
            1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
            667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
            333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
            556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,
        ],
        true => [  // Helvetica-Bold
            278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
            556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
            975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,
            667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,
            333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,
            611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,
        ],
    ];

    /** Reticências (CP1252 0x85) e fallback para acentuados, em milésimos. */
    private const LARGURA_RETICENCIAS = 1000;
    private const LARGURA_MAIUSCULA   = 700;
    private const LARGURA_MINUSCULA   = 556;

    /**
     * Gera o conteúdo binário de um PDF com uma tabela.
     *
     * @param string[] $cabecalhos Nomes das colunas
     * @param array[]  $linhas     Linhas (arrays indexados, mesma ordem)
     * @param string   $titulo     Título impresso no topo da primeira página
     * @param string   $subtitulo  Linha de contexto (filtros, período, usuário)
     */
    public static function generate(
        array $cabecalhos,
        array $linhas,
        string $titulo = '',
        string $subtitulo = ''
    ): string {
        $larguraUtil = self::LARGURA - 2 * self::MARGEM;
        $colunas     = self::dimensionarColunas($cabecalhos, $linhas, $larguraUtil);
        $alinhaDir   = self::colunasNumericas($cabecalhos, $linhas);

        // ─── Monta o conteúdo de cada página ────────────────────
        $paginas = [];
        $buffer  = '';
        $y       = self::ALTURA - self::MARGEM;
        $primeira = true;

        $novaPagina = static function () use (&$buffer, &$paginas, &$y): void {
            $paginas[] = $buffer;
            $buffer    = '';
            $y         = self::ALTURA - self::MARGEM;
        };

        foreach ($linhas as $i => $linha) {
            // Topo da página: título (só na 1ª) e cabeçalho da tabela
            if ($buffer === '') {
                if ($primeira && $titulo !== '') {
                    $y -= self::FONTE_TITULO;
                    $buffer .= self::texto(self::MARGEM, $y, $titulo, self::FONTE_TITULO, true, [1.0, 0.0, 0.0]);
                    $y -= 4;
                    if ($subtitulo !== '') {
                        $y -= self::FONTE_SUB + 4;
                        $buffer .= self::texto(self::MARGEM, $y, $subtitulo, self::FONTE_SUB, false, [0.38, 0.38, 0.38]);
                    }
                    $y -= 12;
                }
                $primeira = false;
                $buffer  .= self::linhaCabecalho($cabecalhos, $colunas, $alinhaDir, $y);
                $y       -= self::ALTURA_LINHA;
            }

            $buffer .= self::linhaDados(array_values($linha), $colunas, $alinhaDir, $y, $i % 2 === 1);
            $y      -= self::ALTURA_LINHA;

            if ($y < self::MARGEM + self::ALTURA_LINHA) {
                $novaPagina();
            }
        }

        if ($buffer !== '' || $paginas === []) {
            if ($buffer === '') {
                // Relatório sem linhas: imprime título e um aviso
                if ($titulo !== '') {
                    $y -= self::FONTE_TITULO;
                    $buffer .= self::texto(self::MARGEM, $y, $titulo, self::FONTE_TITULO, true, [1.0, 0.0, 0.0]);
                    $y -= 4;
                    if ($subtitulo !== '') {
                        $y -= self::FONTE_SUB + 4;
                        $buffer .= self::texto(self::MARGEM, $y, $subtitulo, self::FONTE_SUB, false, [0.38, 0.38, 0.38]);
                    }
                    $y -= 24;
                }
                $buffer .= self::texto(self::MARGEM, $y, 'Nenhum registro para os filtros selecionados.',
                    self::FONTE_CORPO, false, [0.53, 0.53, 0.53]);
            }
            $paginas[] = $buffer;
        }

        // ─── Rodapé (precisa do total de páginas) ───────────────
        $total = count($paginas);
        foreach ($paginas as $n => $conteudo) {
            $rodape = 'Gerado em ' . date('d/m/Y H:i') . '  ·  Página ' . ($n + 1) . ' de ' . $total;
            $paginas[$n] = $conteudo . self::texto(
                self::MARGEM, self::MARGEM - 10, $rodape, 7.5, false, [0.53, 0.53, 0.53]
            );
        }

        return self::montarDocumento($paginas);
    }

    // ─────────────────────────────────────────
    // Layout da tabela
    // ─────────────────────────────────────────

    /**
     * Larguras das colunas: cada uma pede o que precisa para caber sem cortar
     * (medido com as larguras reais da fonte) e o conjunto é ajustado à
     * largura útil da página.
     *
     * Quando falta espaço, o cabeçalho é o que sobrevive: cada coluna tem
     * como piso a largura do próprio título (limitada à divisão justa entre
     * todas), e só o excedente encolhe proporcionalmente. Assim o corte cai
     * no conteúdo longo, nunca no nome da coluna.
     *
     * @return list<array{x: float, w: float}>
     */
    private static function dimensionarColunas(array $cabecalhos, array $linhas, float $larguraUtil): array
    {
        $qtd     = count($cabecalhos);
        $pad     = 2 * self::PADDING_CELULA;
        $tetoCol = $larguraUtil * 0.22;   // nenhuma coluna come a página inteira

        // Piso de conteúdo: espaço para ~14 caracteres médios. É o que faz
        // código de processo, valor e data saírem inteiros mesmo quando um
        // fornecedor de nome quilométrico está disputando a mesma página.
        $pisoConteudo = 14 * 0.58 * self::FONTE_CORPO + $pad;

        $pisos = [];
        $desejo = [];
        foreach ($cabecalhos as $c => $titulo) {
            $wCab       = self::larguraTexto(self::paraCp1252((string) $titulo), self::FONTE_CABECALHO, true) + $pad;
            $pisos[$c]  = min(max($wCab, $pisoConteudo), $larguraUtil / max(1, $qtd) * 1.6);
            $desejo[$c] = $wCab;
        }
        foreach ($linhas as $linha) {
            foreach (array_values($linha) as $c => $valor) {
                if (!isset($desejo[$c])) {
                    continue;
                }
                $w = self::larguraTexto(self::paraCp1252((string) $valor), self::FONTE_CORPO, false) + $pad;
                $desejo[$c] = max($desejo[$c], min($w, $tetoCol));
            }
        }

        // Coluna curta não precisa do piso inteiro — devolve a sobra ao bolo.
        foreach ($pisos as $c => $piso) {
            $pisos[$c] = min($piso, $desejo[$c]);
        }

        $somaDesejo = array_sum($desejo) ?: 1.0;
        $larguras   = [];

        if ($somaDesejo <= $larguraUtil) {
            // Sobra espaço: distribui a folga proporcionalmente, para a tabela
            // ocupar a página inteira em vez de ficar espremida à esquerda.
            $fator = $larguraUtil / $somaDesejo;
            foreach ($desejo as $c => $w) {
                $larguras[$c] = $w * $fator;
            }
        } else {
            $somaPisos = array_sum($pisos);
            $folga     = max(0.0, $larguraUtil - $somaPisos);
            $somaFlex  = max(0.000001, $somaDesejo - $somaPisos);
            foreach ($desejo as $c => $w) {
                $flex = max(0.0, $w - $pisos[$c]);
                $larguras[$c] = $pisos[$c] + $folga * ($flex / $somaFlex);
            }
        }

        $colunas = [];
        $x       = self::MARGEM;
        foreach ($larguras as $w) {
            $colunas[] = ['x' => $x, 'w' => $w];
            $x += $w;
        }
        return $colunas;
    }

    /**
     * Colunas cujo conteúdo é todo numérico/monetário — saem alinhadas à
     * direita, como na tela.
     *
     * @return array<int,bool>
     */
    private static function colunasNumericas(array $cabecalhos, array $linhas): array
    {
        $num = array_fill(0, count($cabecalhos), true);
        $viu = array_fill(0, count($cabecalhos), false);

        foreach ($linhas as $linha) {
            foreach (array_values($linha) as $c => $valor) {
                if (!isset($num[$c])) {
                    continue;
                }
                $v = trim((string) $valor);
                if ($v === '' || $v === '—') {
                    continue;
                }
                $viu[$c] = true;
                if (!preg_match('/^(R\$\s*)?-?[\d.,]+\s*%?$/u', $v)) {
                    $num[$c] = false;
                }
            }
        }

        foreach ($num as $c => $_) {
            $num[$c] = $num[$c] && $viu[$c];
        }
        return $num;
    }

    private static function linhaCabecalho(array $cabecalhos, array $colunas, array $alinhaDir, float $y): string
    {
        $out  = self::retangulo(self::MARGEM, $y - self::ALTURA_LINHA + 4,
                    self::LARGURA - 2 * self::MARGEM, self::ALTURA_LINHA, [0.376, 0.376, 0.376]);

        foreach ($cabecalhos as $c => $titulo) {
            if (!isset($colunas[$c])) {
                continue;
            }
            $out .= self::celula((string) $titulo, $colunas[$c], $y, self::FONTE_CABECALHO,
                        true, [1.0, 1.0, 1.0], !empty($alinhaDir[$c]));
        }
        return $out;
    }

    private static function linhaDados(array $linha, array $colunas, array $alinhaDir, float $y, bool $zebra): string
    {
        $out = $zebra
            ? self::retangulo(self::MARGEM, $y - self::ALTURA_LINHA + 4,
                self::LARGURA - 2 * self::MARGEM, self::ALTURA_LINHA, [0.973, 0.973, 0.973])
            : '';

        foreach ($linha as $c => $valor) {
            if (!isset($colunas[$c])) {
                continue;
            }
            $out .= self::celula((string) $valor, $colunas[$c], $y, self::FONTE_CORPO,
                        false, [0.09, 0.07, 0.12], !empty($alinhaDir[$c]));
        }
        return $out;
    }

    /** Uma célula: trunca no que couber e posiciona à esquerda ou à direita. */
    private static function celula(string $texto, array $col, float $y, float $tamanho, bool $negrito, array $cor, bool $direita): string
    {
        $largura = $col['w'] - 2 * self::PADDING_CELULA;
        $cp      = self::truncar(self::paraCp1252($texto), $largura, $tamanho, $negrito);

        $x = $direita
            ? $col['x'] + $col['w'] - self::PADDING_CELULA - self::larguraTexto($cp, $tamanho, $negrito)
            : $col['x'] + self::PADDING_CELULA;

        return self::textoCp($x, $y - self::ALTURA_LINHA + 9, $cp, $tamanho, $negrito, $cor);
    }

    /** Largura de um texto JÁ em CP1252, em pontos. */
    private static function larguraTexto(string $cp, float $tamanho, bool $negrito): float
    {
        $total = 0;
        $len   = strlen($cp);
        for ($i = 0; $i < $len; $i++) {
            $total += self::larguraChar(ord($cp[$i]), $negrito);
        }
        return $total * $tamanho / 1000;
    }

    /** Largura de um byte CP1252, em milésimos do corpo da fonte. */
    private static function larguraChar(int $byte, bool $negrito): int
    {
        if ($byte >= 32 && $byte <= 126) {
            return self::LARGURAS[$negrito][$byte - 32];
        }
        if ($byte === 0x85) {                       // reticências
            return self::LARGURA_RETICENCIAS;
        }
        // Acentuados: aproximados pela caixa da letra base — erro pequeno e
        // sempre a favor de caber (a maiúscula acentuada é a mais larga).
        return ($byte >= 0xC0 && $byte <= 0xDE && $byte !== 0xD7)
            ? self::LARGURA_MAIUSCULA
            : self::LARGURA_MINUSCULA;
    }

    /** Corta um texto CP1252 no último caractere que cabe, com reticências. */
    private static function truncar(string $cp, float $largura, float $tamanho, bool $negrito): string
    {
        if (self::larguraTexto($cp, $tamanho, $negrito) <= $largura) {
            return $cp;
        }

        $limite = $largura * 1000 / $tamanho - self::LARGURA_RETICENCIAS;
        $soma   = 0;
        $len    = strlen($cp);
        for ($i = 0; $i < $len; $i++) {
            $soma += self::larguraChar(ord($cp[$i]), $negrito);
            if ($soma > $limite) {
                return $i > 0 ? substr($cp, 0, $i) . "\x85" : '';
            }
        }
        return $cp;
    }

    // ─────────────────────────────────────────
    // Primitivas do content stream
    // ─────────────────────────────────────────

    /** Texto em UTF-8 (título, subtítulo, rodapé). @param array{0:float,1:float,2:float} $cor */
    private static function texto(float $x, float $y, string $txt, float $tamanho, bool $negrito, array $cor): string
    {
        return self::textoCp($x, $y, self::paraCp1252($txt), $tamanho, $negrito, $cor);
    }

    /** Texto JÁ convertido para CP1252. @param array{0:float,1:float,2:float} $cor */
    private static function textoCp(float $x, float $y, string $cp, float $tamanho, bool $negrito, array $cor): string
    {
        $fonte = $negrito ? '/F2' : '/F1';
        return sprintf(
            "BT %s %.2f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET\n",
            $fonte, $tamanho, $cor[0], $cor[1], $cor[2], $x, $y, self::escapar($cp)
        );
    }

    /** @param array{0:float,1:float,2:float} $cor */
    private static function retangulo(float $x, float $y, float $w, float $h, array $cor): string
    {
        return sprintf(
            "%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n",
            $cor[0], $cor[1], $cor[2], $x, $y, $w, $h
        );
    }

    /** UTF-8 → WinAnsi (CP1252), a codificação das fontes base do PDF. */
    private static function paraCp1252(string $txt): string
    {
        $txt = str_replace(["\r", "\n", "\t"], ' ', $txt);

        $convertido = @iconv('UTF-8', 'CP1252//TRANSLIT', $txt);
        if ($convertido === false) {
            $convertido = preg_replace('/[^\x20-\x7E]/', '?', $txt) ?? '';
        }
        return $convertido;
    }

    /** Escape dos caracteres reservados dentro de uma string literal do PDF. */
    private static function escapar(string $cp): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $cp);
    }

    // ─────────────────────────────────────────
    // Estrutura do arquivo PDF
    // ─────────────────────────────────────────

    /**
     * Monta catálogo, páginas, fontes e streams; devolve o arquivo completo
     * com a tabela xref correta.
     *
     * @param list<string> $paginas content stream de cada página
     */
    private static function montarDocumento(array $paginas): string
    {
        $totalPaginas = count($paginas);

        // Numeração: 1 catálogo, 2 pages, 3 e 4 fontes, depois N páginas e N streams
        $idPrimeiraPagina = 5;
        $idPrimeiroStream = $idPrimeiraPagina + $totalPaginas;

        $objetos = [];

        $kids = [];
        for ($i = 0; $i < $totalPaginas; $i++) {
            $kids[] = ($idPrimeiraPagina + $i) . ' 0 R';
        }

        $objetos[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objetos[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count {$totalPaginas} >>";
        $objetos[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objetos[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        for ($i = 0; $i < $totalPaginas; $i++) {
            $objetos[$idPrimeiraPagina + $i] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] "
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>",
                self::LARGURA, self::ALTURA, $idPrimeiroStream + $i
            );
        }

        foreach ($paginas as $i => $conteudo) {
            $objetos[$idPrimeiroStream + $i] =
                "<< /Length " . strlen($conteudo) . " >>\nstream\n" . $conteudo . "endstream";
        }

        ksort($objetos);

        $pdf        = "%PDF-1.4\n";
        $offsets    = [];
        foreach ($objetos as $id => $corpo) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$corpo}\nendobj\n";
        }

        $inicioXref = strlen($pdf);
        $totalObj   = count($objetos) + 1;

        $pdf .= "xref\n0 {$totalObj}\n0000000000 65535 f \n";
        for ($id = 1; $id < $totalObj; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size {$totalObj} /Root 1 0 R >>\n"
              . "startxref\n{$inicioXref}\n%%EOF\n";

        return $pdf;
    }
}
