<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/**
 * Prazos do Manual Fabricante (nov/2025), em dias corridos — exceto a coleta (dias úteis).
 * O prazo depende só dos dados da OFP; o semáforo compara o prazo com o dia de hoje.
 */
class PrazoService
{
    public const DIAS_REANALISE    = 20; // manual cap. 11
    public const DIAS_NF_DEVOLUCAO = 30; // manual cap. 12
    public const DIAS_UTEIS_COLETA = 7;  // manual cap. 15
    public const DIAS_RETORNO_GROW = 6;  // manual cap. 11: 48h úteis ≈ 6 dias corridos
    public const DIAS_AMARELO      = 3;

    public const ROTULOS = [
        'abertura'     => 'Abrir a OFP no CSPS',
        'reanalise'    => 'Pedir reanálise no GROW',
        'nf_devolucao' => 'Emitir a NF de devolução',
        'coleta'       => 'Cobrar a coleta (abrir GROW)',
        'credito'      => 'Receber o crédito',
        'analise'      => 'Cobrar a análise da Fabricante (Canal Único)',
        'grow'         => 'Cobrar o retorno do caso GROW',
    ];

    public function __construct(private int $prazoCreditoDias = 90, private int $slaAnaliseDias = 10)
    {
    }

    public static function padrao(): self
    {
        $credito = (int)($_ENV['PRAZO_CREDITO_DIAS'] ?? 90);
        $sla = (int)($_ENV['SLA_ANALISE_DIAS'] ?? 10);
        return new self($credito > 0 ? $credito : 90, $sla > 0 ? $sla : 10);
    }

    /** @return array{tipo: string, data: string}|null */
    public function calcular(array $ofp, ?int $prazoAberturaDias): ?array
    {
        $base = fn(string $campo): ?string => !empty($ofp[$campo]) ? substr((string)$ofp[$campo], 0, 10) : null;

        switch ($ofp['status'] ?? '') {
            case 'registrada':
                $d = $base('data_recebimento');
                return ($d !== null && $prazoAberturaDias !== null)
                    ? ['tipo' => 'abertura', 'data' => $this->somarDias($d, $prazoAberturaDias)]
                    : null;
            case 'em_analise':
                $d = $this->maisRecente($base('data_abertura'), $base('data_reanalise'));
                return $d !== null ? ['tipo' => 'analise', 'data' => $this->somarDias($d, $this->slaAnaliseDias)] : null;
            case 'em_reanalise':
                $d = $base('data_reanalise');
                return $d !== null ? ['tipo' => 'grow', 'data' => $this->somarDias($d, self::DIAS_RETORNO_GROW)] : null;
            case 'rejeitada':
                $d = $base('data_decisao');
                return $d !== null ? ['tipo' => 'reanalise', 'data' => $this->somarDias($d, self::DIAS_REANALISE)] : null;
            case 'aprovada':
            case 'aguardando_tp41':
                $d = $base('data_decisao');
                return $d !== null ? ['tipo' => 'nf_devolucao', 'data' => $this->somarDias($d, self::DIAS_NF_DEVOLUCAO)] : null;
            case 'aguardando_coleta':
                $d = $base('data_coleta_solicitada');
                return $d !== null ? ['tipo' => 'coleta', 'data' => $this->somarDiasUteis($d, self::DIAS_UTEIS_COLETA)] : null;
            case 'nf_emitida':
            case 'coletada':
            case 'aguardando_credito':
                $d = $this->maisRecente($base('data_nf_devolucao'), $base('data_reabertura_credito'));
                return $d !== null ? ['tipo' => 'credito', 'data' => $this->somarDias($d, $this->prazoCreditoDias)] : null;
            default:
                return null;
        }
    }

    private function maisRecente(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : max($a, $b));
    }

    /** @return array{prazo_tipo: ?string, prazo_data: ?string} */
    public function colunas(array $ofp, ?int $prazoAberturaDias): array
    {
        $prazo = $this->calcular($ofp, $prazoAberturaDias);
        return ['prazo_tipo' => $prazo['tipo'] ?? null, 'prazo_data' => $prazo['data'] ?? null];
    }

    public function somarDias(string $data, int $dias): string
    {
        return (new DateTimeImmutable($data))->modify("+{$dias} days")->format('Y-m-d');
    }

    /** Fase 1: considera só fins de semana (sem feriados). */
    public function somarDiasUteis(string $data, int $dias): string
    {
        $d = new DateTimeImmutable($data);
        while ($dias > 0) {
            $d = $d->modify('+1 day');
            if ((int)$d->format('N') <= 5) {
                $dias--;
            }
        }
        return $d->format('Y-m-d');
    }

    public static function diasRestantes(string $prazoData, DateTimeImmutable $hoje): int
    {
        $prazo = new DateTimeImmutable(substr($prazoData, 0, 10));
        return (int)$hoje->setTime(0, 0)->diff($prazo)->format('%r%a');
    }

    public static function semaforo(?string $prazoData, DateTimeImmutable $hoje): ?string
    {
        if (!$prazoData) {
            return null;
        }
        $dias = self::diasRestantes($prazoData, $hoje);
        return $dias < 0 ? 'vermelho' : ($dias <= self::DIAS_AMARELO ? 'amarelo' : 'verde');
    }
}
