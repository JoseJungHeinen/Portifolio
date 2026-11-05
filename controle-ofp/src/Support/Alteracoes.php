<?php
declare(strict_types=1);

namespace App\Support;

/** Diferença entre dois estados de uma OFP, para o histórico (puro, sem banco). */
final class Alteracoes
{
    public const NUMERICOS = ['quantidade', 'valor_reclamado', 'valor_nf_devolucao', 'valor_icms_st', 'valor_creditado_peca', 'valor_creditado_st'];

    /**
     * @param string[] $campos campos a comparar; os ausentes em $depois são ignorados
     * @return list<array{campo: string, de: ?string, para: ?string}>
     */
    public static function calcular(array $antes, array $depois, array $campos): array
    {
        $saida = [];
        foreach ($campos as $campo) {
            if (!array_key_exists($campo, $depois)) {
                continue;
            }
            $de = self::texto($campo, $antes[$campo] ?? null);
            $para = self::texto($campo, $depois[$campo]);
            if ($de !== $para) {
                $saida[] = ['campo' => $campo, 'de' => $de, 'para' => $para];
            }
        }
        return $saida;
    }

    /** Normaliza para comparar: campos numéricos com 2 casas, vazio = null. */
    private static function texto(string $campo, mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (in_array($campo, self::NUMERICOS, true) && is_numeric($valor)) {
            return number_format((float)$valor, 2, '.', '');
        }
        return trim((string)$valor);
    }
}
