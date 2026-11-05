<?php
// @versao Ver.001.301026

namespace App\Services;

/**
 * Condição de pagamento da OC em parcelas ("30/60/90", "30, 60 e 90 dias", "28 DDL",
 * "à vista"). Funções puras — o mesmo parser alimenta o painel (diasPagamento) e a
 * validação da OC. Espelho em JS: public/assets/js/condicao_pagamento.js (manter igual).
 */
final class CondicaoPagamento
{
    public const MAX_PARCELAS = 12;
    public const MAX_DIAS     = 365;
    public const MAX_TEXTO    = 100;

    /**
     * Dias de cada parcela, na ordem escrita; null = não reconhecido / vazio.
     * Percentuais ("50%") não são dias e são ignorados. "3x" é contagem de parcelas:
     * só vale se bater com os dias citados ("3x 30/60/90"); sozinho não diz os dias.
     * "à vista"/"antecipado" sem número → [0].
     *
     * @return list<int>|null
     */
    public static function parcelas(string $condicao): ?array
    {
        $t = mb_strtolower(trim($condicao));
        if ($t === '') return null;
        $t = strtr($t, ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']);
        // Prefixo que o "Gerar cotação" da OC direta grava em cotacao_fornecedores.observacao.
        $t = preg_replace('/^condicao de pagamento\s*:\s*/u', '', $t);
        $t = preg_replace('/\d+(?:[.,]\d+)?\s*%/u', ' ', $t);

        $vezes = null;
        if (preg_match_all('/(\d+)\s*x\b/u', $t, $mx)) {
            if (count($mx[1]) > 1) return null;
            $vezes = (int)$mx[1][0];
            $t = preg_replace('/\d+\s*x\b/u', ' ', $t);
        }

        if (preg_match_all('/\d+/', $t, $m) && $m[0]) {
            $dias = array_map('intval', $m[0]);
            if ($vezes !== null && $vezes !== count($dias)) return null;
            return $dias;
        }
        if ($vezes === null && preg_match('/\bvista\b|antecipad/u', $t)) return [0];
        return null;
    }

    /** @param list<int> $parcelas */
    public static function prazoMedio(array $parcelas): float
    {
        return (float)(array_sum($parcelas) / count($parcelas));
    }

    /** @param list<int> $parcelas "30/60/90" */
    public static function normalizar(array $parcelas): string
    {
        return implode('/', $parcelas);
    }

    /**
     * Valida a condição digitada (obrigatória ao enviar/gerar a OC).
     *
     * @return array{condicao_pagamento: string, parcelas_dias: string, prazo_pagamento_dias: int}|string
     */
    public static function validar(mixed $condicao): array|string
    {
        $texto = trim((string)$condicao);
        if ($texto === '') {
            return 'Informe a condição de pagamento da ordem de compra (ex.: 30/60/90).';
        }
        if (mb_strlen($texto) > self::MAX_TEXTO) {
            return 'A condição de pagamento pode ter até ' . self::MAX_TEXTO . ' caracteres.';
        }
        $parcelas = self::parcelas($texto);
        if ($parcelas === null) {
            return 'Condição de pagamento não reconhecida — use os dias separados por /, ex.: 30/60/90 (ou "à vista").';
        }
        if (count($parcelas) > self::MAX_PARCELAS) {
            return 'A condição de pagamento pode ter no máximo ' . self::MAX_PARCELAS . ' parcelas.';
        }
        foreach ($parcelas as $dias) {
            if ($dias > self::MAX_DIAS) {
                return 'Cada parcela deve vencer em 0 a ' . self::MAX_DIAS . ' dias.';
            }
        }

        return [
            'condicao_pagamento'   => $texto,
            'parcelas_dias'        => self::normalizar($parcelas),
            'prazo_pagamento_dias' => (int)round(self::prazoMedio($parcelas)),
        ];
    }

    /** Dica ao lado do campo: "3 parcelas: 30, 60 e 90 dias (prazo médio 60)". @param list<int> $parcelas */
    public static function descricao(array $parcelas): string
    {
        $n = count($parcelas);
        if ($n === 1 && $parcelas[0] === 0) return 'À vista';
        $lista = $n === 1 ? (string)$parcelas[0]
            : implode(', ', array_slice($parcelas, 0, -1)) . ' e ' . $parcelas[$n - 1];
        $txt = $n . ($n === 1 ? ' parcela: ' : ' parcelas: ') . $lista . ' dias';
        if ($n > 1) {
            $txt .= ' (prazo médio ' . str_replace('.', ',', (string)round(self::prazoMedio($parcelas), 1)) . ')';
        }
        return $txt;
    }

    /**
     * Texto para o detalhe e o PDF da OC: "30/60/90 (3 parcelas)". OC antiga, só com
     * prazo_pagamento_dias: "30 dias". Nada: null.
     */
    public static function rotulo(?string $condicao, ?string $parcelasDias, int|string|null $prazoDias): ?string
    {
        $condicao = trim((string)$condicao);
        $parcelasDias = trim((string)$parcelasDias);
        if ($condicao === '' && $parcelasDias === '') {
            return $prazoDias !== null && $prazoDias !== '' ? (int)$prazoDias . ' dias' : null;
        }
        if ($condicao === '') $condicao = $parcelasDias;
        if ($parcelasDias === '') return $condicao;

        $n = count(explode('/', $parcelasDias));
        if ($parcelasDias === '0') return $condicao;
        $txt = preg_match('/^\d+$/', $condicao) ? $condicao . ' dias' : $condicao;
        $sufixo = $n . ($n === 1 ? ' parcela' : ' parcelas');
        if ($n > 1 && $condicao !== $parcelasDias) $sufixo .= ': ' . $parcelasDias;
        return $txt . ' (' . $sufixo . ')';
    }
}
