<?php
// @versao Ver.001.301026

namespace App\Services;

use App\Config\Database;

/**
 * Barra de andamento da compra — as 11 etapas do fluxograma "Sistema de Compras — Mapa Decisório".
 * Spec: docs/2026-10-30-sistema-compras-proprio.md (Etapa 2 — Fluxo).
 *
 * calcular() é puro (só arrays, sem banco) e tem teste em tests/andamento_test.php.
 * Os métodos de instância carregam os dados do banco e chamam calcular().
 *
 * Estados: concluida · atual (a próxima a acontecer) · pendente · nao_se_aplica.
 */
class AndamentoService
{
    public const ETAPAS = [
        1  => 'Requisição',
        2  => 'Aprovação do gestor',
        3  => 'Solicitação de OC',
        4  => 'Cotação',
        5  => 'Decisão',
        6  => 'Envio da OC',
        7  => 'NF e XML',
        8  => 'CT-e / trânsito',
        9  => 'Recebimento físico',
        10 => 'Conferência',
        11 => 'Disponível / entregue',
    ];

    /**
     * @param array{
     *   sol?: array|null,         status, finalidade, recebido_em, entregue_em
     *   requisicao?: array|null,  status, tipo
     *   cotacoes?: int,           cotações não canceladas da requisição
     *   respostas?: int,          respostas de fornecedor nessas cotações
     *   ocs?: array[]             status, enviada_em, transito_em, cte_numero, recebido_fisico_em,
     *                             recebido_fisico_por, nf (status da melhor NF não rejeitada ou null)
     * } $d
     * @return array{etapas: array<int, array{numero:int, rotulo:string, estado:string, detalhe:?string}>,
     *               atual: ?int, interrompida: ?string, concluida: bool, resumo: string}
     */
    public static function calcular(array $d): array
    {
        $sol = $d['sol'] ?? null;
        $req = $d['requisicao'] ?? null;
        $ocs = array_values(array_filter($d['ocs'] ?? [], fn($o) => ($o['status'] ?? '') !== 'cancelado'));
        $temOc = $ocs !== [];
        $todas = fn(callable $f) => $temOc && count(array_filter($ocs, $f)) === count($ocs);

        $e = array_fill_keys(array_keys(self::ETAPAS), 'pendente');
        $det = array_fill_keys(array_keys(self::ETAPAS), null);

        // 01–02: só existem quando há solicitação.
        if ($sol) {
            $e[1] = 'concluida';
            if (($sol['finalidade'] ?? null) === 'revenda') {
                $e[2] = 'nao_se_aplica';
                $det[2] = 'Revenda: segue direto para a solicitação de OC';
            } elseif (in_array($sol['status'] ?? '', ['aprovada', 'em_compra', 'concluida'], true)) {
                $e[2] = 'concluida';
            }
        } else {
            $e[1] = $e[2] = 'nao_se_aplica';
        }

        // 03: requisição gerada pelo comprador e aprovada na alçada.
        if ($req) {
            $st = $req['status'] ?? '';
            if ($st === 'aprovado' || $temOc) {
                $e[3] = 'concluida';
            } else {
                $det[3] = [
                    'rascunho'     => 'Requisição em rascunho',
                    'pendente'     => 'Requisição aguardando envio',
                    'em_aprovacao' => 'Em aprovação na alçada',
                    'rejeitado'    => 'Rejeitada na alçada — em revisão pelo comprador',
                ][$st] ?? null;
            }
        }

        // 04–05: cotação respondida; decisão = OC gerada a partir do comparativo.
        if ($temOc || (int)($d['respostas'] ?? 0) > 0) {
            $e[4] = 'concluida';
        } elseif ((int)($d['cotacoes'] ?? 0) > 0) {
            $det[4] = 'Aguardando respostas';
        }
        if ($temOc) $e[5] = 'concluida';

        // 06–10: todas as OCs vivas precisam cumprir.
        if ($todas(fn($o) => !empty($o['enviada_em']))) {
            $e[6] = 'concluida';
            $det[6] = self::datas($ocs, 'enviada_em', 'Enviada');
        }
        if ($todas(fn($o) => !empty($o['nf']))) $e[7] = 'concluida';
        if ($todas(fn($o) => !empty($o['transito_em']))) {
            $e[8] = 'concluida';
            $ctes = array_filter(array_map(fn($o) => trim((string)($o['cte_numero'] ?? '')), $ocs));
            $det[8] = self::datas($ocs, 'transito_em', 'Em trânsito') . ($ctes ? ' · CT-e ' . implode(', ', $ctes) : '');
        }
        if ($todas(fn($o) => !empty($o['recebido_fisico_em']))) {
            $e[9] = 'concluida';
            $quem = array_unique(array_filter(array_map(fn($o) => trim((string)($o['recebido_fisico_por'] ?? '')), $ocs)));
            $det[9] = self::datas($ocs, 'recebido_fisico_em', 'Recebida') . ($quem ? ' por ' . implode(', ', $quem) : '');
        }
        if ($todas(fn($o) => ($o['nf'] ?? null) === 'aprovada')) {
            $e[10] = 'concluida';
        } elseif ($todas(fn($o) => !empty($o['nf']))) {
            $det[10] = 'NF aguardando conferência';
        }

        // 11: com SOL, o solicitante confirma; sem SOL, NF conferida = disponível.
        if ($sol) {
            if (!empty($sol['entregue_em']) || ($sol['status'] ?? '') === 'concluida') $e[11] = 'concluida';
            elseif (!empty($sol['recebido_em'])) $det[11] = 'Aguardando confirmação do solicitante';
        } elseif ($e[10] === 'concluida') {
            $e[11] = 'concluida';
        }

        $interrompida = null;
        $stSol = $sol['status'] ?? '';
        if ($stSol === 'recusada')  $interrompida = 'Solicitação recusada';
        if ($stSol === 'cancelada') $interrompida = 'Solicitação cancelada';
        if (($req['status'] ?? '') === 'cancelado') $interrompida = 'Requisição cancelada';

        $concluida = !in_array('pendente', $e, true);
        $atual = null;
        if ($interrompida === null && !$concluida && $stSol !== 'concluida') {
            foreach ($e as $n => $estado) {
                if ($estado === 'pendente') { $e[$n] = 'atual'; $atual = $n; break; }
            }
        }

        $etapas = [];
        foreach (self::ETAPAS as $n => $rotulo) {
            $etapas[] = ['numero' => $n, 'rotulo' => $rotulo, 'estado' => $e[$n], 'detalhe' => $det[$n]];
        }

        $total = count(self::ETAPAS);
        if ($interrompida !== null) {
            $resumo = $interrompida;
        } elseif ($concluida) {
            $resumo = 'Concluída · ' . $total . '/' . $total;
        } elseif ($atual === null) {
            $resumo = 'Concluída (encerrada pelo comprador)';
        } else {
            $resumo = 'Etapa ' . $atual . '/' . $total . ' · ' . self::ETAPAS[$atual];
        }

        return compact('etapas', 'atual', 'interrompida', 'concluida', 'resumo');
    }

    /** "Enviada 30/10" (uma OC) ou "Enviada 30/10, 31/10" (várias). */
    private static function datas(array $ocs, string $campo, string $prefixo): string
    {
        $ds = array_unique(array_map(fn($o) => date('d/m', strtotime((string)$o[$campo])), $ocs));
        return $prefixo . ' ' . implode(', ', $ds);
    }

    // ── Carregamento (banco) ─────────────────────────────────────────────────

    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /** Detalhe da SOL ($sol de SolicitacaoCompraService::buscar). */
    public function daSolicitacao(array $sol): array
    {
        $reqId = $sol['requisicao_id'] ?? null;
        $dados = $reqId ? ($this->dadosRequisicoes([$reqId])[$reqId] ?? []) : [];
        return self::calcular(['sol' => $sol] + $dados);
    }

    /** Detalhe da requisição; a SOL de origem (se houver) entra nas etapas 01, 02 e 11. */
    public function daRequisicao(string $requisicaoId): array
    {
        $sols  = $this->solsDasRequisicoes([$requisicaoId]);
        $dados = $this->dadosRequisicoes([$requisicaoId])[$requisicaoId] ?? [];
        return self::calcular(['sol' => $sols[$requisicaoId] ?? null] + $dados);
    }

    /**
     * Lista de SOLs (versão compacta). @param array[] $sols linhas com id, status, finalidade,
     * recebido_em, entregue_em, requisicao_id. @return array<string, array> por id da SOL
     */
    public function dasSolicitacoes(array $sols): array
    {
        $reqIds = array_values(array_filter(array_column($sols, 'requisicao_id')));
        $dados  = $reqIds ? $this->dadosRequisicoes($reqIds) : [];
        $out = [];
        foreach ($sols as $s) {
            $out[$s['id']] = self::calcular(['sol' => $s] + ($dados[$s['requisicao_id'] ?? ''] ?? []));
        }
        return $out;
    }

    /** Lista de requisições (versão compacta). @return array<string, array> por id da requisição */
    public function dasRequisicoes(array $reqIds): array
    {
        $reqIds = array_values(array_unique(array_filter($reqIds)));
        if (!$reqIds) return [];
        $sols  = $this->solsDasRequisicoes($reqIds);
        $dados = $this->dadosRequisicoes($reqIds);
        $out = [];
        foreach ($reqIds as $id) {
            $out[$id] = self::calcular(['sol' => $sols[$id] ?? null] + ($dados[$id] ?? []));
        }
        return $out;
    }

    /** @return array<string, array> requisicao_id => SOL (campos usados no cálculo) */
    private function solsDasRequisicoes(array $reqIds): array
    {
        $in = implode(',', array_fill(0, count($reqIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, requisicao_id, status, finalidade, recebido_em, entregue_em
             FROM solicitacoes_compra WHERE requisicao_id IN ($in)"
        );
        $stmt->execute($reqIds);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $s) $out[$s['requisicao_id']] = $s;
        return $out;
    }

    /** @return array<string, array> requisicao_id => [requisicao, cotacoes, respostas, ocs] */
    private function dadosRequisicoes(array $reqIds): array
    {
        $in  = implode(',', array_fill(0, count($reqIds), '?'));
        $out = [];

        $stmt = $this->db->prepare("SELECT id, status, tipo FROM requisicoes WHERE id IN ($in)");
        $stmt->execute($reqIds);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[$r['id']] = ['requisicao' => $r, 'cotacoes' => 0, 'respostas' => 0, 'ocs' => []];
        }

        $stmt = $this->db->prepare(
            "SELECT c.requisicao_id, COUNT(DISTINCT c.id) AS cotacoes,
                    COUNT(DISTINCT CASE WHEN cf.status = 'respondida' THEN cf.id END) AS respostas
             FROM cotacoes c
             LEFT JOIN cotacao_fornecedores cf ON cf.cotacao_id = c.id
             WHERE c.requisicao_id IN ($in) AND c.status <> 'cancelada'
             GROUP BY c.requisicao_id"
        );
        $stmt->execute($reqIds);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            if (!isset($out[$c['requisicao_id']])) continue;
            $out[$c['requisicao_id']]['cotacoes']  = (int)$c['cotacoes'];
            $out[$c['requisicao_id']]['respostas'] = (int)$c['respostas'];
        }

        // nf = status da melhor NF não rejeitada (aprovada primeiro).
        $stmt = $this->db->prepare(
            "SELECT oc.requisicao_id, oc.status, oc.enviada_em, oc.transito_em, oc.cte_numero,
                    oc.recebido_fisico_em, oc.recebido_fisico_por,
                    (SELECT nf.status FROM notas_fiscais nf
                     WHERE nf.oc_id = oc.id AND nf.status <> 'rejeitada'
                     ORDER BY nf.status = 'aprovada' DESC LIMIT 1) AS nf
             FROM ordens_compra oc
             WHERE oc.requisicao_id IN ($in)
             ORDER BY oc.parte"
        );
        $stmt->execute($reqIds);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $o) {
            if (isset($out[$o['requisicao_id']])) $out[$o['requisicao_id']]['ocs'][] = $o;
        }
        return $out;
    }
}
