<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/** Converte valores das planilhas antigas para o formato do sistema. Puro: sem banco. */
class ImportNormalizer
{
    private const ACENTOS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e',
        'í' => 'i', 'ì' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ç' => 'c',
    ];

    /** Planilha "Controle OFP Pós Vendas v4", coluna STATUS. */
    private const STATUS_V4 = [
        'recebida' => 'creditada', 'cancelada' => 'cancelada', 'estornada' => 'estornada', 'anulada' => 'cancelada',
        'aguardando coleta' => 'aguardando_coleta', 'enviada correio' => 'aguardando_credito',
        'recebto pendente pecas' => 'aguardando_credito', 'recebto pendente tributo' => 'aguardando_credito',
        'recebimento tributo' => 'aguardando_credito', 'recebimento parcial' => 'aguardando_credito',
        'aguardando recebimento' => 'aguardando_credito', 'aguardando nfe ajuste' => 'aprovada', 'atendida' => 'aprovada',
        'aprovada' => 'aprovada', 'em analise' => 'em_analise', 'a fazer' => 'em_analise', 'verificar' => 'em_analise',
        'suspenso' => 'em_analise', 'suspensa' => 'em_analise', 'reanalise' => 'em_reanalise', 'rejeitada' => 'rejeitada',
        'rejeitada s prot' => 'rejeitada', 'rejeitada final' => 'rejeitada_final',
    ];

    /** Planilha "OFP - STATUS". "recebida" e "cancelar" não mudam o status (ambíguos) — viram anotação. */
    private const STATUS_OPERACIONAL = [
        'aguardando coleta' => 'aguardando_coleta', 'em analise' => 'em_analise', 'aprovada' => 'aprovada',
        'rejeitada' => 'rejeitada', 'abrir reanalise' => 'rejeitada', 'reanalise' => 'em_reanalise', 'ofp cancelada' => 'cancelada',
    ];

    public static function texto(mixed $valor): string
    {
        $s = strtr(mb_strtolower(trim((string)$valor), 'UTF-8'), self::ACENTOS);
        $s = (string)preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim((string)preg_replace('/\s+/', ' ', $s));
    }

    public static function unidade(mixed $valor): ?string
    {
        $t = str_replace(' ', '', self::texto($valor));
        return match (true) {
            str_contains($t, 'matriz')                                  => 'Eurovale - Matriz',
            str_contains($t, 'montenegro')                              => 'Eurovale - Cidade C',
            str_contains($t, 'jeep')                                    => 'Eurovale - Jeep',
            str_contains($t, 'santacruz') || str_contains($t, 'stacruz') => 'Eurovale - Santa Cruz',
            default                                                     => null,
        };
    }

    public static function codigoCausal(mixed $valor): ?string
    {
        return preg_match('/^\s*([A-Za-z]{2})\s*(-|$)/', (string)$valor, $m) ? strtoupper($m[1]) : null;
    }

    public static function statusV4(mixed $valor): ?string
    {
        return self::STATUS_V4[self::texto($valor)] ?? null;
    }

    public static function statusOperacional(mixed $valor): ?string
    {
        $t = self::texto($valor);
        if (str_starts_with($t, 'peca vendida')) {
            return 'peca_vendida';
        }
        return self::STATUS_OPERACIONAL[$t] ?? null;
    }

    public static function data(mixed $valor): ?string
    {
        $s = trim((string)$valor);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
            return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (is_numeric($s) && (float)$s >= 20000 && (float)$s <= 80000) {
            return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int)$s . ' days')->format('Y-m-d');
        }
        return null;
    }

    public static function decimal(mixed $valor): ?float
    {
        $s = trim((string)$valor);
        if (preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            return round((float)$s, 2);
        }
        if (preg_match('/^-?[\d.]*,\d+$/', $s)) {
            return round(parse_decimal($s), 2);
        }
        return null;
    }

    /**
     * Números de OFP/NF vêm do Excel como "332436" ou "332436.0". Mantém os dígitos como escritos
     * (sem tirar zeros à esquerda); só zeros ou valor maior que $max devolvem null.
     */
    public static function numero(mixed $valor, ?int $max = null): ?string
    {
        if (!preg_match('/^(\d+)(\.0+)?$/', trim((string)$valor), $m)) {
            return null;
        }
        if (trim($m[1], '0') === '' || ($max !== null && strlen($m[1]) > $max)) {
            return null;
        }
        return $m[1];
    }
}
