<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/** Regras de cadastro da OFP conforme o causal (spec, seção 7). Puro: sem banco. */
class OfpValidator
{
    /**
     * @param array      $d      dados já limpos (quantidade/valor como float)
     * @param array|null $causal linha da tabela causais
     * @return array{erros: array<string, string>, avisos: string[]}
     */
    public static function validarNova(array $d, ?array $causal, DateTimeImmutable $hoje): array
    {
        $erros = [];
        $avisos = [];

        if (empty($d['unidade_id'])) {
            $erros['unidade_id'] = 'Selecione a unidade.';
        }
        if (!$causal) {
            $erros['causal_id'] = 'Selecione o causal.';
        } elseif (!(int)$causal['ativo']) {
            $erros['causal_id'] = 'Causal inativo no manual vigente.';
        }
        if (trim((string)($d['desenho'] ?? '')) === '') {
            $erros['desenho'] = 'Informe o desenho da peça.';
        }
        if ((float)($d['quantidade'] ?? 0) <= 0) {
            $erros['quantidade'] = 'Quantidade deve ser maior que zero.';
        }
        if ((float)($d['valor_reclamado'] ?? 0) <= 0) {
            $erros['valor_reclamado'] = 'Informe o valor reclamado.';
        }

        foreach (['desenho' => 30, 'descricao_peca' => 150, 'nf_origem' => 20, 'serie_origem' => 5, 'numero_volume' => 30, 'observacao' => 2000] as $campo => $max) {
            if (!isset($erros[$campo]) && mb_strlen((string)($d[$campo] ?? '')) > $max) {
                $erros[$campo] = "Máximo de {$max} caracteres.";
            }
        }
        if (!isset($erros['quantidade']) && round((float)($d['quantidade'] ?? 0), 2) >= 100000000) {
            $erros['quantidade'] = 'Quantidade acima do limite.';
        }
        if (!isset($erros['valor_reclamado']) && round((float)($d['valor_reclamado'] ?? 0), 2) >= 10000000000) {
            $erros['valor_reclamado'] = 'Valor acima do limite.';
        }

        $recebimento = (string)($d['data_recebimento'] ?? '');
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $recebimento);
        if (!$dt || $dt->format('Y-m-d') !== $recebimento) {
            $erros['data_recebimento'] = 'Informe a data de recebimento do CTe.';
            $dt = null;
        } elseif ($dt > $hoje) {
            $erros['data_recebimento'] = 'Data de recebimento não pode ser futura.';
            $dt = null;
        }

        if ($causal && !isset($erros['causal_id'])) {
            if ($causal['valor_minimo'] !== null && !isset($erros['valor_reclamado'])
                && (float)$d['valor_reclamado'] < (float)$causal['valor_minimo']) {
                $erros['valor_reclamado'] = 'Causal ' . $causal['codigo'] . ' exige valor mínimo de ' . money($causal['valor_minimo']) . '.';
            }
            if (!empty($causal['transportadora']) && !empty($d['transportadora']) && $d['transportadora'] !== $causal['transportadora']) {
                $avisos[] = 'Causal ' . $causal['codigo'] . ' é da transportadora ' . strtoupper($causal['transportadora'])
                    . ', mas foi informada ' . strtoupper($d['transportadora']) . ' (manual 6.4: OFP no causal errado é rejeitada).';
            }
            if ($dt && $causal['prazo_abertura_dias'] !== null) {
                $limite = $dt->modify('+' . (int)$causal['prazo_abertura_dias'] . ' days');
                if ($hoje > $limite) {
                    $avisos[] = 'Prazo de abertura do causal ' . $causal['codigo'] . ' venceu em ' . $limite->format('d/m/Y') . '. A Fabricante pode rejeitar a OFP.';
                }
            }
        }

        if (($d['numero_ofp'] ?? null) !== null && $d['numero_ofp'] !== '' && !preg_match('/^\d{4,12}$/', (string)$d['numero_ofp'])) {
            $erros['numero_ofp'] = 'Nº OFP deve conter apenas números.';
        }

        return ['erros' => $erros, 'avisos' => $avisos];
    }
}
