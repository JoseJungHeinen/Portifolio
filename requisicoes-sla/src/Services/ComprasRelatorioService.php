<?php
// @versao Ver.001.160926

namespace App\Services;

/**
 * Consultas do Relatório de Compras. Todas recebem o mesmo array de filtros:
 *   de, ate        'Y-m-d' — data da OC, inclusive
 *   filiais        null = todas; [] = nenhuma; [ids] — filial da requisição
 *   setor_id       ?string — setor da requisição
 *   fornecedor_id  ?string
 *   status         ?string — sem status válido, exclui rascunho e cancelado
 *
 * Spec: docs/superpowers/specs/2026-09-16-reestruturacao-relatorios-design.md (Parte 2)
 */
class ComprasRelatorioService
{
    public const STATUS_OC = [
        'rascunho'         => 'Rascunho',
        'aprovado'         => 'Aprovada',
        'enviado'          => 'Enviada',
        'recebido_parcial' => 'Recebida parcial',
        'recebido_total'   => 'Recebida',
        'cancelado'        => 'Cancelada',
    ];

    public const STATUS_NF = [
        'pendente_conferencia' => 'Pendente',
        'aprovada'             => 'Aprovada',
        'divergente'           => 'Divergente',
        'rejeitada'            => 'Rejeitada',
    ];

    private const BASE = " FROM ordens_compra oc
        JOIN requisicoes r   ON r.id  = oc.requisicao_id
        JOIN setores s       ON s.id  = r.setor_id
        JOIN filiais fi      ON fi.id = r.filial_id
        JOIN fornecedores fo ON fo.id = oc.fornecedor_id";

    /**
     * Valor de produtos (sem impostos/frete) das NFs não rejeitadas de cada OC — comparável com
     * `ordens_compra.valor_total`, que também é só produtos (usar dentro de consultas sobre `oc`).
     */
    private const PRODUTOS_NF_DA_OC = "(SELECT COALESCE(SUM(nfi.quantidade_nf * nfi.valor_unitario_nf), 0)
                                         FROM notas_fiscais nfo
                                         JOIN nota_fiscal_itens nfi ON nfi.nota_fiscal_id = nfo.id
                                         WHERE nfo.oc_id = oc.id AND nfo.status <> 'rejeitada')";

    private const NOME_FORNECEDOR = "COALESCE(NULLIF(fo.nome_fantasia, ''), fo.razao_social)";

    public function __construct(private \PDO $db) {}

    /** @return array{valor_ocs: float, qtd_ocs: int, valor_nfs: float, qtd_nfs: int, pendente: float, itens_distintos: int} */
    public function resumo(array $f): array
    {
        [$where, $params] = $this->where($f);

        $oc = $this->consulta(
            "SELECT COUNT(*) AS qtd_ocs,
                    COALESCE(SUM(oc.valor_total), 0) AS valor_ocs,
                    COALESCE(SUM(CASE WHEN oc.status IN ('rascunho', 'cancelado') THEN 0
                                       ELSE GREATEST(0, oc.valor_total - " . self::PRODUTOS_NF_DA_OC . ") END), 0) AS pendente"
            . self::BASE . $where,
            $params
        )[0];

        $nf = $this->consulta(
            "SELECT COUNT(nf.id) AS qtd_nfs, COALESCE(SUM(nf.valor_total), 0) AS valor_nfs"
            . self::BASE . " JOIN notas_fiscais nf ON nf.oc_id = oc.id AND nf.status <> 'rejeitada'" . $where,
            $params
        )[0];

        $itens = $this->consulta(
            "SELECT COUNT(DISTINCT oci.item_id) AS n"
            . self::BASE . " JOIN ordens_compra_itens oci ON oci.oc_id = oc.id" . $where,
            $params
        )[0];

        return [
            'valor_ocs'       => (float)$oc['valor_ocs'],
            'qtd_ocs'         => (int)$oc['qtd_ocs'],
            'valor_nfs'       => (float)$nf['valor_nfs'],
            'qtd_nfs'         => (int)$nf['qtd_nfs'],
            'pendente'        => (float)$oc['pendente'],
            'itens_distintos' => (int)$itens['n'],
        ];
    }

    /** @return list<array{label: string, total: float}> */
    public function porSetor(array $f): array
    {
        [$where, $params] = $this->where($f);
        $linhas = $this->consulta(
            "SELECT s.nome AS label, SUM(oc.valor_total) AS total"
            . self::BASE . $where . " GROUP BY s.id, s.nome ORDER BY total DESC",
            $params
        );
        return array_map(fn($l) => ['label' => $l['label'], 'total' => (float)$l['total']], $linhas);
    }

    /** @return list<array{label: string, total: float}> */
    public function porFornecedor(array $f, int $limite = 8): array
    {
        [$where, $params] = $this->where($f);
        $linhas = $this->consulta(
            "SELECT " . self::NOME_FORNECEDOR . " AS label, SUM(oc.valor_total) AS total"
            . self::BASE . $where
            . " GROUP BY fo.id, fo.nome_fantasia, fo.razao_social ORDER BY total DESC LIMIT " . max(1, $limite),
            $params
        );
        return array_map(fn($l) => ['label' => $l['label'], 'total' => (float)$l['total']], $linhas);
    }

    /** @return list<array{id: string, numero_oc: string, criado_em: string, status: string, filial: string, setor: string, fornecedor: string, valor_oc: float, valor_nfs: float, diferenca: float}> */
    public function ocs(array $f, ?int $limite = null, int $offset = 0): array
    {
        [$where, $params] = $this->where($f);
        $linhas = $this->consulta(
            "SELECT oc.id, oc.numero_oc, oc.criado_em, oc.status,
                    fi.nome AS filial, s.nome AS setor, " . self::NOME_FORNECEDOR . " AS fornecedor,
                    oc.valor_total AS valor_oc, " . self::PRODUTOS_NF_DA_OC . " AS valor_nfs"
            . self::BASE . $where
            . " ORDER BY oc.criado_em DESC, oc.numero_oc" . $this->limite($limite, $offset),
            $params
        );
        return array_map(function ($l) {
            $l['valor_oc']  = (float)$l['valor_oc'];
            $l['valor_nfs'] = (float)$l['valor_nfs'];
            $l['diferenca'] = $l['valor_oc'] - $l['valor_nfs'];
            return $l;
        }, $linhas);
    }

    public function contarOcs(array $f): int
    {
        [$where, $params] = $this->where($f);
        return (int)$this->consulta("SELECT COUNT(*) AS n" . self::BASE . $where, $params)[0]['n'];
    }

    /** Todas as NFs das OCs filtradas, inclusive rejeitadas (a aba mostra o status da conferência). */
    public function nfs(array $f, ?int $limite = null, int $offset = 0): array
    {
        [$where, $params] = $this->where($f);
        $linhas = $this->consulta(
            "SELECT nf.id, nf.numero_nf, nf.criado_em, nf.valor_total AS valor, nf.frete_total AS frete, nf.status,
                    oc.id AS oc_id, oc.numero_oc, " . self::NOME_FORNECEDOR . " AS fornecedor"
            . self::BASE . " JOIN notas_fiscais nf ON nf.oc_id = oc.id" . $where
            . " ORDER BY nf.criado_em DESC, nf.numero_nf" . $this->limite($limite, $offset),
            $params
        );
        return array_map(function ($l) {
            $l['valor'] = (float)$l['valor'];
            $l['frete'] = (float)$l['frete'];
            return $l;
        }, $linhas);
    }

    public function contarNfs(array $f): int
    {
        [$where, $params] = $this->where($f);
        return (int)$this->consulta(
            "SELECT COUNT(*) AS n" . self::BASE . " JOIN notas_fiscais nf ON nf.oc_id = oc.id" . $where,
            $params
        )[0]['n'];
    }

    /** Por item: comprado em OC e recebido em NF (quantidade já convertida; só NFs conferidas e não rejeitadas). */
    public function itens(array $f, ?int $limite = null, int $offset = 0): array
    {
        [$where, $params] = $this->where($f);
        $linhas = $this->consulta(
            "SELECT i.id AS item_id, i.codigo, i.nome, i.unidade,
                    a.qtd_oc, a.valor_oc, COALESCE(b.qtd_nf, 0) AS qtd_nf,
                    a.valor_oc / NULLIF(a.qtd_oc, 0) AS preco_medio
             FROM (
                 SELECT oci.item_id, SUM(oci.quantidade) AS qtd_oc, SUM(oci.quantidade * oci.valor_unitario) AS valor_oc"
                 . self::BASE . " JOIN ordens_compra_itens oci ON oci.oc_id = oc.id" . $where . "
                 GROUP BY oci.item_id
             ) a
             JOIN itens i ON i.id = a.item_id
             LEFT JOIN (
                 SELECT nfi.item_id, SUM(nfi.quantidade) AS qtd_nf"
                 . self::BASE . "
                 JOIN notas_fiscais nf      ON nf.oc_id = oc.id AND nf.status <> 'rejeitada' AND nf.conferido_em IS NOT NULL
                 JOIN nota_fiscal_itens nfi ON nfi.nota_fiscal_id = nf.id" . $where . "
                   AND nfi.item_id IS NOT NULL
                 GROUP BY nfi.item_id
             ) b ON b.item_id = a.item_id
             ORDER BY a.valor_oc DESC, i.nome" . $this->limite($limite, $offset),
            [...$params, ...$params]
        );
        return array_map(function ($l) {
            foreach (['qtd_oc', 'valor_oc', 'qtd_nf', 'preco_medio'] as $campo) {
                $l[$campo] = (float)$l[$campo];
            }
            return $l;
        }, $linhas);
    }

    public function contarItens(array $f): int
    {
        [$where, $params] = $this->where($f);
        return (int)$this->consulta(
            "SELECT COUNT(DISTINCT oci.item_id) AS n" . self::BASE . " JOIN ordens_compra_itens oci ON oci.oc_id = oc.id" . $where,
            $params
        )[0]['n'];
    }

    /**
     * Linhas completas (sem paginação) da aba, já formatadas para CSV.
     *
     * @return array{0: list<string>, 1: list<list<string>>} [cabeçalho, linhas]
     */
    public function exportar(string $aba, array $f): array
    {
        $num  = fn($v): string => number_format((float)$v, 2, ',', '');
        $data = fn(string $v): string => date('d/m/Y', strtotime($v));

        return match ($aba) {
            'nfs' => [
                ['NF', 'Data', 'Fornecedor', 'OC', 'Valor', 'Frete', 'Status'],
                array_map(fn($l) => [
                    $l['numero_nf'], $data($l['criado_em']), $l['fornecedor'], $l['numero_oc'],
                    $num($l['valor']), $num($l['frete']), self::STATUS_NF[$l['status']] ?? $l['status'],
                ], $this->nfs($f)),
            ],
            'itens' => [
                ['Código', 'Item', 'Unidade', 'Qtd. OC', 'Valor OC', 'Qtd. recebida NF', 'Preço médio'],
                array_map(fn($l) => [
                    (string)$l['codigo'], $l['nome'], (string)$l['unidade'], $num($l['qtd_oc']),
                    $num($l['valor_oc']), $num($l['qtd_nf']), $num($l['preco_medio']),
                ], $this->itens($f)),
            ],
            default => [
                ['OC', 'Data', 'Filial', 'Setor', 'Fornecedor', 'Status', 'Valor OC', 'Produtos nas NFs', 'Diferença'],
                array_map(fn($l) => [
                    $l['numero_oc'], $data($l['criado_em']), $l['filial'], $l['setor'], $l['fornecedor'],
                    self::STATUS_OC[$l['status']] ?? $l['status'],
                    $num($l['valor_oc']), $num($l['valor_nfs']), $num($l['diferenca']),
                ], $this->ocs($f)),
            ],
        };
    }

    private function limite(?int $limite, int $offset): string
    {
        return $limite === null ? '' : ' LIMIT ' . max(1, $limite) . ' OFFSET ' . max(0, $offset);
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function where(array $f): array
    {
        $sql    = " WHERE oc.criado_em BETWEEN ? AND ?";
        $params = [$f['de'] . ' 00:00:00', $f['ate'] . ' 23:59:59'];

        [$condFilial, $paramsFilial] = sql_escopo_in('r.filial_id', $f['filiais'] ?? null);
        $sql   .= $condFilial;
        $params = [...$params, ...$paramsFilial];

        if (!empty($f['setor_id'])) {
            $sql     .= " AND r.setor_id = ?";
            $params[] = $f['setor_id'];
        }
        if (!empty($f['fornecedor_id'])) {
            $sql     .= " AND oc.fornecedor_id = ?";
            $params[] = $f['fornecedor_id'];
        }
        if (is_string($f['status'] ?? null) && array_key_exists($f['status'], self::STATUS_OC)) {
            $sql     .= " AND oc.status = ?";
            $params[] = $f['status'];
        } else {
            $sql .= " AND oc.status NOT IN ('rascunho', 'cancelado')";
        }

        return [$sql, $params];
    }

    private function consulta(string $sql, array $params): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
}
