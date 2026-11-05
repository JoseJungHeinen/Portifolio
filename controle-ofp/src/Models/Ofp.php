<?php
declare(strict_types=1);

namespace App\Models;

use App\Services\StatusService;

class Ofp extends BaseModel
{
    protected string $table = 'ofps';

    /** Idade da OFP em dias (base do aging). */
    public const SQL_IDADE = 'GREATEST(DATEDIFF(CURDATE(), COALESCE(o.data_abertura, o.data_recebimento, DATE(o.created_at))), 0)';

    /** Faixas de idade do painel e do filtro "aging". */
    public const FAIXAS_IDADE = ['0-30' => [0, 30], '31-60' => [31, 60], '61-90' => [61, 90], '90+' => [91, 1000000]];

    private const SELECT = "SELECT o.*, c.codigo AS causal_codigo, c.descricao AS causal_descricao, c.tipo_devolucao,
        c.prazo_abertura_dias, c.fotos_obrigatorias, c.exige_tp41, u.nome AS unidade_nome, r.nome AS responsavel_nome,
        m.descricao AS motivo_descricao, %s AS aberto_peca, %s AS aberto_st
        FROM ofps o JOIN causais c ON c.id = o.causal_id JOIN unidades u ON u.id = o.unidade_id LEFT JOIN usuarios r ON r.id = o.responsavel_id LEFT JOIN motivos m ON m.id = o.motivo_id";

    public static function sqlInativos(): string
    {
        return "'" . implode("','", StatusService::INATIVOS) . "'";
    }

    public static function sqlAbertoPeca(): string
    {
        return '(CASE WHEN o.status IN (' . self::sqlInativos() . ') THEN 0 '
            . 'ELSE GREATEST(COALESCE(o.valor_nf_devolucao, o.valor_reclamado) - COALESCE(o.valor_creditado_peca, 0), 0) END)';
    }

    public static function sqlAbertoSt(): string
    {
        return '(CASE WHEN o.status IN (' . self::sqlInativos() . ') THEN 0 '
            . 'ELSE GREATEST(COALESCE(o.valor_icms_st, 0) - COALESCE(o.valor_creditado_st, 0), 0) END)';
    }

    private static function select(): string
    {
        return sprintf(self::SELECT, self::sqlAbertoPeca(), self::sqlAbertoSt());
    }

    /** OFP com dados do causal, unidade, responsável e valores em aberto. */
    public function findComCausal(string $id): ?array
    {
        return $this->queryOne(self::select() . ' WHERE o.id = ?', [$id]);
    }

    public function findByNumero(string $numero): ?array
    {
        return $this->queryOne('SELECT * FROM ofps WHERE numero_ofp = ?', [$numero]);
    }

    /** Outra OFP (não cancelada) da mesma unidade com esta NF de devolução. */
    public function outraComNf(string $nf, string $unidadeId, string $excetoId): ?array
    {
        // Compara sem zeros à esquerda ('029713' = '29713').
        $canonica = ltrim($nf, '0');
        return $this->queryOne(
            "SELECT id, numero_ofp FROM ofps WHERE unidade_id = ? AND TRIM(LEADING '0' FROM nf_devolucao) = ? AND id <> ? AND status <> 'cancelada' LIMIT 1",
            [$unidadeId, $canonica, $excetoId]
        );
    }

    /** @return array<string, array{id: string, origem_importacao: ?string}> nº da OFP → dados mínimos (para a importação). */
    public function mapaNumeros(): array
    {
        $mapa = [];
        foreach ($this->query('SELECT numero_ofp, id, origem_importacao FROM ofps WHERE numero_ofp IS NOT NULL') as $r) {
            $mapa[(string)$r['numero_ofp']] = ['id' => $r['id'], 'origem_importacao' => $r['origem_importacao']];
        }
        return $mapa;
    }

    /** @return array{0: string, 1: array} */
    private function where(array $f): array
    {
        [$sqlUnidades, $params] = sql_unidades('o.unidade_id');
        $w = [$sqlUnidades];

        $status = $f['status'] ?? 'ativos';
        if ($status === 'ativos') {
            $w[] = 'o.status NOT IN (' . self::sqlInativos() . ')';
        } elseif ($status !== '' && $status !== 'todos') {
            $w[] = 'o.status = ?';
            $params[] = $status;
        }
        foreach (['unidade_id', 'causal_id', 'responsavel_id'] as $campo) {
            if (!empty($f[$campo])) {
                $w[] = "o.{$campo} = ?";
                $params[] = $f[$campo];
            }
        }
        if (!empty($f['semaforo'])) {
            $w[] = match ($f['semaforo']) {
                'vermelho' => 'o.prazo_data < CURDATE()',
                'amarelo'  => 'o.prazo_data BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)',
                'verde'    => 'o.prazo_data > DATE_ADD(CURDATE(), INTERVAL 3 DAY)',
                'sem_prazo' => 'o.prazo_data IS NULL AND o.status NOT IN (' . self::sqlInativos() . ')',
                default    => '1=1',
            };
        }
        if (!empty($f['aging']) && isset(self::FAIXAS_IDADE[$f['aging']])) {
            $w[] = self::SQL_IDADE . ' BETWEEN ? AND ?';
            array_push($params, ...self::FAIXAS_IDADE[$f['aging']]);
        }
        if (!empty($f['sugestao'])) {
            if ($f['sugestao'] === 'qualquer') {
                $w[] = 'o.perda_sugerida IS NOT NULL';
            } else {
                $w[] = 'o.perda_sugerida = ?';
                $params[] = (string)$f['sugestao'];
            }
        }
        if (!empty($f['ano'])) {
            $w[] = 'YEAR(COALESCE(o.data_abertura, o.data_recebimento, o.created_at)) = ?';
            $params[] = (int)$f['ano'];
        }
        if (!empty($f['q'])) {
            $q = trim((string)$f['q']);
            $w[] = '(o.numero_ofp = ? OR o.desenho LIKE ? OR o.nf_origem = ? OR o.nf_devolucao = ?)';
            array_push($params, $q, '%' . $q . '%', $q, $q);
        }
        return [implode(' AND ', $w), $params];
    }

    /** $limite = 0 → sem limite (exportação). */
    public function buscar(array $filtros, int $limite = 50, int $offset = 0): array
    {
        [$where, $params] = $this->where($filtros);
        $ordem = ($filtros['ordem'] ?? '') === 'credito'
            ? 'o.data_credito IS NULL, o.data_credito DESC, o.updated_at DESC'
            : 'o.prazo_data IS NULL, o.prazo_data, o.created_at DESC';
        $sql = self::select() . " WHERE {$where} ORDER BY {$ordem}";
        if ($limite > 0) {
            $sql .= ' LIMIT ' . (int)$limite . ' OFFSET ' . (int)$offset;
        }
        return $this->query($sql, $params);
    }

    public function contar(array $filtros): int
    {
        [$where, $params] = $this->where($filtros);
        return (int)$this->queryScalar("SELECT COUNT(*) FROM ofps o WHERE {$where}", $params);
    }

    /** OFPs ativas nos status em que o perfil atua, dentro do escopo de unidades. */
    public function pendencias(array $statuses): array
    {
        if (!$statuses) {
            return [];
        }
        [$sqlUnidades, $params] = sql_unidades('o.unidade_id');
        $marcas = implode(',', array_fill(0, count($statuses), '?'));
        return $this->query(
            self::select() . " WHERE {$sqlUnidades} AND o.status IN ({$marcas}) ORDER BY o.prazo_data IS NULL, o.prazo_data, o.created_at",
            [...$params, ...$statuses]
        );
    }
}
