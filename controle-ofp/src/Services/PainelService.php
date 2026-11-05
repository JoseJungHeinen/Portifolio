<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Models\Ofp;
use App\Models\Unidade;
use DateTimeImmutable;
use PDO;

/** Consultas do painel (spec, seção 8.1). Todas respeitam o escopo de unidades do usuário. */
class PainelService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** @return array{0: string, 1: array} */
    private function filtro(array $f, bool $comAno = true): array
    {
        [$sql, $params] = sql_unidades('o.unidade_id');
        $w = [$sql];
        if (!empty($f['unidade_id'])) {
            $w[] = 'o.unidade_id = ?';
            $params[] = $f['unidade_id'];
        }
        if (!empty($f['causal_id'])) {
            $w[] = 'o.causal_id = ?';
            $params[] = $f['causal_id'];
        }
        if ($comAno && !empty($f['ano'])) {
            $w[] = 'YEAR(COALESCE(o.data_abertura, o.data_recebimento, o.created_at)) = ?';
            $params[] = (int)$f['ano'];
        }
        return [implode(' AND ', $w), $params];
    }

    private function linhas(string $sql, array $params): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    private static function aberto(): string
    {
        return '(' . Ofp::sqlAbertoPeca() . ' + ' . Ofp::sqlAbertoSt() . ')';
    }

    public function cards(array $f): array
    {
        [$w, $p] = $this->filtro($f);
        $inativos = Ofp::sqlInativos();
        $r = $this->linhas(
            'SELECT COALESCE(SUM(' . Ofp::sqlAbertoPeca() . '), 0) AS aberto_peca,
                    COALESCE(SUM(' . Ofp::sqlAbertoSt() . "), 0) AS aberto_st,
                    COALESCE(SUM(o.status NOT IN ({$inativos})), 0) AS ativas,
                    COALESCE(SUM(o.status NOT IN ({$inativos}) AND o.prazo_data < CURDATE()), 0) AS vencidas
             FROM ofps o WHERE {$w}",
            $p
        )[0];
        // Creditado no mês corrente: ignora o filtro de ano; exclui canceladas e estornadas.
        [$wm, $pm] = $this->filtro($f, false);
        $mes = $this->linhas(
            "SELECT COALESCE(SUM(COALESCE(o.valor_creditado_peca, 0) + COALESCE(o.valor_creditado_st, 0)), 0) AS valor
             FROM ofps o WHERE {$wm} AND o.status NOT IN ('cancelada', 'estornada')
               AND o.data_credito >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
               AND o.data_credito < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)",
            $pm
        )[0]['valor'];
        $provavel = $this->linhas(
            'SELECT COALESCE(SUM(' . self::aberto() . "), 0) AS v FROM ofps o WHERE {$w} AND o.perda_sugerida IS NOT NULL",
            $p
        )[0]['v'];
        return [
            'aberto_peca'   => (float)$r['aberto_peca'],
            'aberto_st'     => (float)$r['aberto_st'],
            'ativas'        => (int)$r['ativas'],
            'vencidas'      => (int)$r['vencidas'],
            'creditado_mes' => (float)$mes,
            'provavel_perda' => (float)$provavel,
        ];
    }

    /** Perda real (PerdaService::STATUS_PERDA) agrupada por status e motivo registrado. */
    public function perdasPorMotivo(array $f): array
    {
        [$w, $p] = $this->filtro($f);
        return $this->linhas(
            "SELECT o.status, COALESCE(m.descricao, 'Sem motivo registrado') AS motivo, COUNT(*) AS qtd,
                    SUM(COALESCE(o.valor_nf_devolucao, o.valor_reclamado) + COALESCE(o.valor_icms_st, 0)) AS valor
             FROM ofps o LEFT JOIN motivos m ON m.id = o.motivo_id
             WHERE {$w} AND o.status IN (" . PerdaService::sqlStatusPerda() . ")
             GROUP BY o.status, m.id, m.descricao ORDER BY valor DESC, o.status, motivo",
            $p
        );
    }

    /** Valor em aberto por status (linhas) × unidade (colunas) — antiga aba "Consolidado". */
    public function matriz(array $f): array
    {
        [$w, $p] = $this->filtro($f);
        $rows = $this->linhas(
            'SELECT o.status, o.unidade_id, COUNT(*) AS qtd, SUM(' . self::aberto() . ") AS valor
             FROM ofps o WHERE {$w} AND o.status NOT IN (" . Ofp::sqlInativos() . ') GROUP BY o.status, o.unidade_id',
            $p
        );
        $unidades = [];
        foreach ((new Unidade())->permitidas() as $u) {
            if (empty($f['unidade_id']) || $f['unidade_id'] === $u['id']) {
                $unidades[$u['id']] = $u['nome'];
            }
        }
        // Unidades com valor no resultado entram sempre (mesmo inativas), para o total bater com os cards.
        $faltantes = array_values(array_diff(array_unique(array_column($rows, 'unidade_id')), array_keys($unidades)));
        if ($faltantes) {
            $marcas = implode(',', array_fill(0, count($faltantes), '?'));
            foreach ($this->linhas("SELECT id, nome FROM unidades WHERE id IN ({$marcas}) ORDER BY nome", $faltantes) as $u) {
                $unidades[$u['id']] = $u['nome'];
            }
        }
        $linhas = [];
        $totalColunas = array_fill_keys(array_keys($unidades), 0.0);
        $total = 0.0;
        foreach (array_keys(StatusService::LABELS) as $status) {
            foreach ($rows as $r) {
                if ($r['status'] !== $status || !isset($unidades[$r['unidade_id']])) {
                    continue;
                }
                $valor = (float)$r['valor'];
                $linhas[$status]['celulas'][$r['unidade_id']] = ['valor' => $valor, 'qtd' => (int)$r['qtd']];
                $linhas[$status]['total'] = ($linhas[$status]['total'] ?? 0.0) + $valor;
                $linhas[$status]['qtd'] = ($linhas[$status]['qtd'] ?? 0) + (int)$r['qtd'];
                $totalColunas[$r['unidade_id']] += $valor;
                $total += $valor;
            }
        }
        return ['unidades' => $unidades, 'linhas' => $linhas, 'totalColunas' => $totalColunas, 'total' => $total];
    }

    public function aging(array $f): array
    {
        [$w, $p] = $this->filtro($f);
        $rows = $this->linhas(
            'SELECT ' . Ofp::SQL_IDADE . ' AS idade, ' . self::aberto() . " AS aberto FROM ofps o
             WHERE {$w} AND o.status NOT IN (" . Ofp::sqlInativos() . ')',
            $p
        );
        $saida = [];
        foreach (array_keys(Ofp::FAIXAS_IDADE) as $faixa) {
            $saida[$faixa] = ['faixa' => $faixa, 'valor' => 0.0, 'qtd' => 0];
        }
        foreach ($rows as $r) {
            $idade = max(0, (int)$r['idade']);
            foreach (Ofp::FAIXAS_IDADE as $faixa => [$min, $max]) {
                if ($idade >= $min && $idade <= $max) {
                    $saida[$faixa]['valor'] += (float)$r['aberto'];
                    $saida[$faixa]['qtd']++;
                    break;
                }
            }
        }
        return array_values($saida);
    }

    public function topCausais(array $f): array
    {
        $decidida = "o.data_decisao IS NOT NULL OR o.status IN ('rejeitada', 'rejeitada_final', 'em_reanalise', 'aprovada', 'aguardando_tp41', 'nf_emitida', 'aguardando_coleta', 'coletada', 'aguardando_credito', 'creditada', 'estornada', 'encerrada', 'peca_vendida')";
        [$w, $p] = $this->filtro($f);
        $rows = $this->linhas(
            "SELECT c.id, c.codigo, c.descricao, COUNT(*) AS total,
                    SUM({$decidida}) AS decididas,
                    SUM(({$decidida}) AND (o.status IN ('rejeitada', 'rejeitada_final', 'em_reanalise')
                        OR EXISTS (SELECT 1 FROM ofp_eventos ev WHERE ev.ofp_id = o.id AND ev.status_para IN ('rejeitada', 'rejeitada_final')))) AS rejeitadas,
                    SUM(" . self::aberto() . ") AS aberto
             FROM ofps o JOIN causais c ON c.id = o.causal_id
             WHERE {$w} AND o.status <> 'cancelada'
             GROUP BY c.id, c.codigo, c.descricao ORDER BY aberto DESC, total DESC LIMIT 10",
            $p
        );
        return array_map(fn(array $r) => $r + [
            'taxa_rejeicao' => (int)$r['decididas'] > 0 ? round(100 * (int)$r['rejeitadas'] / (int)$r['decididas'], 1) : 0.0,
        ], $rows);
    }

    /** Últimos 12 meses (ignora o filtro de ano): valor reclamado por mês de abertura × creditado por mês do crédito. */
    public function evolucao(array $f): array
    {
        [$w, $p] = $this->filtro($f, false);
        $primeiro = new DateTimeImmutable('first day of this month');
        $inicio = $primeiro->modify('-11 months')->format('Y-m-d');
        $base = 'COALESCE(o.data_abertura, o.data_recebimento, DATE(o.created_at))';
        $reclamado = $this->linhas(
            "SELECT DATE_FORMAT({$base}, '%Y-%m') AS mes, SUM(COALESCE(o.valor_nf_devolucao, o.valor_reclamado) + COALESCE(o.valor_icms_st, 0)) AS valor
             FROM ofps o WHERE {$w} AND o.status <> 'cancelada' AND {$base} >= ? GROUP BY mes",
            [...$p, $inicio]
        );
        $creditado = $this->linhas(
            "SELECT DATE_FORMAT(o.data_credito, '%Y-%m') AS mes, SUM(COALESCE(o.valor_creditado_peca, 0) + COALESCE(o.valor_creditado_st, 0)) AS valor
             FROM ofps o WHERE {$w} AND o.status NOT IN ('cancelada', 'estornada') AND o.data_credito >= ? GROUP BY mes",
            [...$p, $inicio]
        );
        $r = array_column($reclamado, 'valor', 'mes');
        $c = array_column($creditado, 'valor', 'mes');
        $saida = [];
        for ($i = 11; $i >= 0; $i--) {
            $mes = $primeiro->modify("-{$i} months");
            $chave = $mes->format('Y-m');
            $saida[] = ['mes' => $mes->format('m/Y'), 'reclamado' => (float)($r[$chave] ?? 0), 'creditado' => (float)($c[$chave] ?? 0)];
        }
        return $saida;
    }

    /** @return int[] anos com OFP, para o filtro */
    public function anos(): array
    {
        [$w, $p] = sql_unidades('o.unidade_id');
        $rows = $this->linhas("SELECT DISTINCT YEAR(COALESCE(o.data_abertura, o.data_recebimento, o.created_at)) AS ano FROM ofps o WHERE {$w} ORDER BY ano DESC", $p);
        return array_map('intval', array_column($rows, 'ano'));
    }
}
