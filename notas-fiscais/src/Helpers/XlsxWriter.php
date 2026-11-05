<?php
// @versao Rel_04 V_01_16/07/2026
declare(strict_types=1);

/**
 * XlsxWriter — gerador mínimo de XLSX via ZipArchive + SimpleXML.
 *
 * Usa apenas extensões nativas do PHP 8.3 (ZipArchive).
 * Cada célula usa inline string (t="inlineStr") ou numérica (t="n").
 * A primeira linha (cabeçalho) recebe estilo bold via s="1".
 */
class XlsxWriter
{
    /**
     * Gera o conteúdo binário de um arquivo XLSX.
     *
     * @param string[] $headers  Nomes das colunas (primeira linha, bold)
     * @param array[]  $rows     Linhas de dados (arrays indexados ou associativos)
     * @return string            Conteúdo binário do arquivo .xlsx
     * @throws RuntimeException  Se ZipArchive não estiver disponível
     */
    public static function generate(array $headers, array $rows): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Extensão ZipArchive não está disponível neste servidor.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_') . '.xlsx';

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar arquivo ZIP temporário.');
        }

        $zip->addFromString('[Content_Types].xml',        self::contentTypes());
        $zip->addFromString('_rels/.rels',                self::rels());
        $zip->addFromString('xl/workbook.xml',            self::workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels());
        $zip->addFromString('xl/styles.xml',              self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml',   self::sheet($headers, $rows));

        $zip->close();

        $content = file_get_contents($tmpFile);
        @unlink($tmpFile);

        if ($content === false) {
            throw new RuntimeException('Falha ao ler arquivo XLSX temporário.');
        }

        return $content;
    }

    // ─────────────────────────────────────────
    // Partes do ZIP
    // ─────────────────────────────────────────

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml"  ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml"' .
            ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml"' .
            ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml"' .
            ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '</Types>';
    }

    private static function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1"' .
            ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"' .
            ' Target="xl/workbook.xml"/>' .
            '</Relationships>';
    }

    private static function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"' .
            ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets>' .
            '<sheet name="Planilha1" sheetId="1" r:id="rId1"/>' .
            '</sheets>' .
            '</workbook>';
    }

    private static function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1"' .
            ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"' .
            ' Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2"' .
            ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"' .
            ' Target="styles.xml"/>' .
            '</Relationships>';
    }

    /**
     * Estilos mínimos:
     *   xfId=0 (normal, índice 0)
     *   xfId=1 (bold,   índice 1) — usado no cabeçalho via s="1"
     */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="2">' .
              '<font><sz val="11"/><name val="Calibri"/></font>' .
              '<font><b/><sz val="11"/><name val="Calibri"/></font>' .
            '</fonts>' .
            '<fills count="2">' .
              '<fill><patternFill patternType="none"/></fill>' .
              '<fill><patternFill patternType="gray125"/></fill>' .
            '</fills>' .
            '<borders count="1">' .
              '<border><left/><right/><top/><bottom/><diagonal/></border>' .
            '</borders>' .
            '<cellStyleXfs count="1">' .
              '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' .
            '</cellStyleXfs>' .
            '<cellXfs count="2">' .
              '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
              '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>' .
            '</cellXfs>' .
            '</styleSheet>';
    }

    /**
     * Conteúdo da planilha: cabeçalho bold + linhas de dados.
     */
    private static function sheet(array $headers, array $rows): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetData>';

        // Linha 1 — cabeçalho (bold: s="1")
        $xml .= '<row r="1">';
        foreach (array_values($headers) as $colIdx => $header) {
            $ref  = self::cellRef($colIdx, 1);
            $val  = self::escapeXml((string)$header);
            $xml .= "<c r=\"{$ref}\" t=\"inlineStr\" s=\"1\"><is><t>{$val}</t></is></c>";
        }
        $xml .= '</row>';

        // Linhas de dados
        foreach (array_values($rows) as $rowIdx => $row) {
            $rowNum = $rowIdx + 2; // 1-based, +1 pelo cabeçalho
            $xml   .= "<row r=\"{$rowNum}\">";
            foreach (array_values($row) as $colIdx => $value) {
                $ref = self::cellRef($colIdx, $rowNum);
                $xml .= self::cell($ref, $value);
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData>';
        $xml .= '</worksheet>';

        return $xml;
    }

    // ─────────────────────────────────────────
    // Utilitários
    // ─────────────────────────────────────────

    /**
     * Converte índice de coluna (0-based) + número de linha (1-based) → referência de célula (ex: A1, Z3, AA10).
     */
    private static function cellRef(int $colIdx, int $rowNum): string
    {
        return self::colLetter($colIdx) . $rowNum;
    }

    /**
     * Converte índice de coluna 0-based → letra(s) Excel (A…Z, AA…AZ, …).
     */
    private static function colLetter(int $n): string
    {
        $letter = '';
        $n++;  // tornar 1-based
        while ($n > 0) {
            $n--;
            $letter = chr(ord('A') + ($n % 26)) . $letter;
            $n      = (int)floor($n / 26);
        }
        return $letter;
    }

    /**
     * Gera o XML de uma célula.
     * Valores numéricos (int/float ou string que representa número BR "1.234,56") → t="n".
     * Demais valores → t="inlineStr".
     */
    private static function cell(string $ref, mixed $value): string
    {
        if ($value === null || $value === '') {
            return "<c r=\"{$ref}\" t=\"inlineStr\"><is><t></t></is></c>";
        }

        // Detectar número puro (int/float PHP)
        if (is_int($value) || is_float($value)) {
            return "<c r=\"{$ref}\" t=\"n\"><v>{$value}</v></c>";
        }

        $str = (string)$value;

        // Detectar número em formato BR "1.234,56" ou "1234,56" ou "1234.56"
        $numClean = str_replace('.', '', $str);   // remove separador de milhar
        $numClean = str_replace(',', '.', $numClean); // troca decimal BR → ponto
        if (is_numeric($numClean) && preg_match('/^\d[\d.,]*$/', $str)) {
            return "<c r=\"{$ref}\" t=\"n\"><v>{$numClean}</v></c>";
        }

        $val = self::escapeXml($str);
        return "<c r=\"{$ref}\" t=\"inlineStr\"><is><t>{$val}</t></is></c>";
    }

    /**
     * Escapa caracteres especiais XML e remove controles ilegais.
     */
    private static function escapeXml(string $str): string
    {
        // Remover caracteres de controle ilegais em XML (exceto tab \x09, LF \x0A, CR \x0D)
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);
        return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
