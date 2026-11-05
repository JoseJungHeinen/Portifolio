<?php
// @versao Ver.001.160926

namespace App\Controllers\Concerns;

/** Envio de CSV dos relatórios: UTF-8 com BOM, separador ";" e proteção contra fórmulas. */
trait RespostaCsv
{
    /**
     * @param list<string>       $cabecalho
     * @param list<list<string>> $linhas
     */
    protected function enviarCsv(string $arquivo, array $cabecalho, array $linhas): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $arquivo . '"');
        }
        $saida = fopen('php://output', 'w');
        fwrite($saida, "\xEF\xBB\xBF");
        fputcsv($saida, array_map(self::neutralizarFormula(...), $cabecalho), ';');
        foreach ($linhas as $linha) {
            fputcsv($saida, array_map(self::neutralizarFormula(...), $linha), ';');
        }
        fclose($saida);
    }

    /**
     * Evita injeção de fórmula (CSV aberto no Excel/Sheets): prefixa com aspa simples células que
     * começam com =, +, -, @, tab ou carriage return, exceto números no formato pt-BR dos relatórios
     * (ex.: "-30,00").
     */
    protected static function neutralizarFormula(string $celula): string
    {
        if (preg_match('/^-?\d+(,\d+)?$/', $celula)) {
            return $celula;
        }
        return preg_match('/^[=+\-@\t\r]/', $celula) === 1 ? "'" . $celula : $celula;
    }
}
