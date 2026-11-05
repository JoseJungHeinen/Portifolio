<?php
declare(strict_types=1);

namespace App\Models;

class Motivo extends BaseModel
{
    protected string $table = 'motivos';

    public function todos(): array
    {
        return $this->all([], 'ordem, descricao');
    }

    public function findByCodigo(string $codigo): ?array
    {
        return $this->queryOne('SELECT * FROM motivos WHERE codigo = ?', [$codigo]);
    }

    /**
     * Status que exigem motivo e ficariam sem nenhum motivo ativo.
     * @param list<array> $motivos linhas com 'ativo' e 'aplica_a'
     * @return string[]
     */
    public static function statusSemMotivoAtivo(array $motivos): array
    {
        $cobertos = [];
        foreach ($motivos as $m) {
            if ((int)$m['ativo']) {
                foreach (explode(',', (string)$m['aplica_a']) as $status) {
                    $cobertos[trim($status)] = true;
                }
            }
        }
        return array_values(array_filter(\App\Services\StatusService::EXIGEM_MOTIVO, fn($s) => !isset($cobertos[$s])));
    }

    /** @return array<string, list<array>> motivos ativos agrupados pelo status de destino */
    public function ativosPorStatus(): array
    {
        $mapa = [];
        foreach ($this->query('SELECT * FROM motivos WHERE ativo = 1 ORDER BY ordem, descricao') as $m) {
            foreach (explode(',', (string)$m['aplica_a']) as $status) {
                $mapa[trim($status)][] = $m;
            }
        }
        return $mapa;
    }
}
