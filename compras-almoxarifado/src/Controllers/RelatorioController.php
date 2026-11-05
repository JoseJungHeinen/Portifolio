<?php
// @versao Ver.008.291026

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Anomalia;
use App\Services\AuditoriaService;
use App\Services\EscopoGestorService;
use App\Services\ValorEstoqueService;
use App\Config\Database;

class RelatorioController extends Controller
{
    use Concerns\EscopoRelatorio;

    public function index(): void
    {
        $this->requireAuth();
        // Cada card confere a permissão do próprio relatório; aqui basta ter o módulo
        // (antes era só gestor/diretor/admin e o comprador não chegava à página).
        if (!podeModulo('relatorios')) {
            http_response_code(403);
            $this->view('errors/403', [], 'main');
            exit;
        }
        $this->view('relatorios/index');
    }

    public function movimentacaoEstoque(): void
    {
        $this->requirePermissaoRelatorio('movimentacao_estoque');

        $dataIni  = $_GET['data_ini'] ?? date('Y-m-d', strtotime('-30 days'));
        $dataFim  = $_GET['data_fim'] ?? date('Y-m-d');
        $filiaisEscopo = $this->filiaisRelatorio();
        $filialId = ve_todas_filiais() ? ($_GET['filial_id'] ?? null) : (auth()['filial_id'] ?? null);
        $setorId  = $_GET['setor_id'] ?? null;
        $tipo     = in_array($_GET['tipo'] ?? '', ['entrada','saida'], true) ? $_GET['tipo'] : null;

        $db = Database::getInstance();

        $where  = "me.criado_em BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)";
        $params = [$dataIni, $dataFim];
        [$filialCond, $filialParams] = sql_escopo_in('me.filial_id', $filiaisEscopo);
        $where .= $filialCond;
        $params = [...$params, ...$filialParams];
        if ($tipo)     { $where .= " AND me.tipo = ?";      $params[] = $tipo; }
        if ($setorId)  { $where .= " AND r.setor_id = ?";   $params[] = $setorId; }

        $joinBase = "FROM movimentos_estoque me
                     JOIN itens i    ON i.id = me.item_id
                     JOIN filiais f  ON f.id = me.filial_id
                     JOIN usuarios u ON u.id = me.usuario_id
                     LEFT JOIN requisicoes r ON r.id = me.referencia_id AND me.referencia_tipo = 'requisicao'
                     LEFT JOIN setores s      ON s.id = r.setor_id";

        // Valor = quantidade × preço da época; a agregação em si é feita no banco
        // (SUM por dia) para não carregar todo movimento do período na memória do PHP.
        $stmt = $db->prepare(
            "SELECT me.item_id, me.tipo, SUM(me.quantidade) AS quantidade, DATE(me.criado_em) AS dia,
                    f.id AS filial_id, f.nome AS filial,
                    s.id AS setor_id, s.nome AS setor,
                    i.codigo, i.nome AS item, i.unidade
             {$joinBase}
             WHERE {$where}
             GROUP BY me.item_id, me.tipo, f.id, f.nome, s.id, s.nome, i.codigo, i.nome, i.unidade, DATE(me.criado_em)
             ORDER BY f.nome"
        );
        $stmt->execute($params);
        $todos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $valores = new ValorEstoqueService($db);
        $valores->carregar(array_column($todos, 'item_id'));

        $totais     = ['entrada' => ['qtd' => 0, 'valor' => 0], 'saida' => ['qtd' => 0, 'valor' => 0]];
        $porSetor   = [];
        $porFilial  = [];
        $itensSetor = [];
        foreach ($todos as $m) {
            $qtd   = (float)$m['quantidade'];
            $valor = $qtd * $valores->precoEm($m['item_id'], $m['dia'] . ' 23:59:59');
            $lado  = $m['tipo'];

            $totais[$lado]['qtd']   += $qtd;
            $totais[$lado]['valor'] += $valor;

            $fid = $m['filial_id'];
            $porFilial[$fid] ??= ['filial' => $m['filial'], 'entrada_qtd' => 0, 'entrada_valor' => 0,
                                  'saida_qtd' => 0, 'saida_valor' => 0];
            $porFilial[$fid][$lado . '_qtd']   += $qtd;
            $porFilial[$fid][$lado . '_valor'] += $valor;

            // Por setor e drill-down: só saídas originadas de requisição
            if ($lado !== 'saida' || $m['setor_id'] === null) {
                continue;
            }
            $sid = $m['setor_id'];
            $porSetor[$sid] ??= ['setor_id' => $sid, 'setor' => $m['setor'], 'qtd_total' => 0, 'valor_total' => 0];
            $porSetor[$sid]['qtd_total']   += $qtd;
            $porSetor[$sid]['valor_total'] += $valor;

            if ($setorId) {
                $iid = $m['item_id'];
                $itensSetor[$iid] ??= ['item_id' => $iid, 'codigo' => $m['codigo'], 'item' => $m['item'],
                                       'unidade' => $m['unidade'], 'qtd_total' => 0, 'valor_total' => 0];
                $itensSetor[$iid]['qtd_total']   += $qtd;
                $itensSetor[$iid]['valor_total'] += $valor;
            }
        }

        $porSetor = array_values($porSetor);
        usort($porSetor, fn($a, $b) => $b['valor_total'] <=> $a['valor_total']);
        $totalSetores = array_sum(array_column($porSetor, 'valor_total'));

        // $porFilial já vem na ordem de f.nome (ORDER BY da consulta agregada acima).

        $itensSetor = array_values($itensSetor);
        usort($itensSetor, fn($a, $b) => $b['valor_total'] <=> $a['valor_total']);

        $setorNome = null;
        if ($setorId) {
            $stmt = $db->prepare("SELECT nome FROM setores WHERE id = ?");
            $stmt->execute([$setorId]);
            $setorNome = $stmt->fetchColumn() ?: null;
        }

        // ── Lista detalhada de movimentações ─────────────────────────────────
        $stmt = $db->prepare(
            "SELECT me.id, me.item_id, me.criado_em, me.tipo, me.motivo, me.quantidade,
                    i.codigo, i.nome AS item, i.unidade, i.valor_referencia,
                    f.nome AS filial, u.nome AS usuario, s.nome AS setor
             {$joinBase}
             WHERE {$where}
             ORDER BY me.criado_em DESC
             LIMIT 500"
        );
        $stmt->execute($params);
        $movimentos = $stmt->fetchAll();

        foreach ($movimentos as &$mov) {
            $diaMov       = substr($mov['criado_em'], 0, 10) . ' 23:59:59';
            $mov['valor'] = (float)$mov['quantidade'] * $valores->precoEm($mov['item_id'], $diaMov);
        }
        unset($mov);

        $filiais = ve_todas_filiais()
            ? $db->query("SELECT id, nome FROM filiais WHERE ativo=1 ORDER BY nome")->fetchAll()
            : [];
        $setores = $db->query("SELECT id, nome FROM setores ORDER BY nome")->fetchAll();

        $this->view('relatorios/movimentacao_estoque', compact(
            'dataIni','dataFim','filialId','setorId','tipo','filiais','setores',
            'totais','porSetor','totalSetores','porFilial','itensSetor','setorNome','movimentos'
        ));
    }

    /**
     * Saving & Anomalias: uma aba para cada permissão (relatorios.saving, relatorios.anomalias).
     * Entra quem tiver qualquer uma das duas; a aba padrão é a primeira permitida.
     */
    public function saving(): void
    {
        $this->requireAuth();
        $abasPermitidas = array_values(array_filter(
            ['saving', 'anomalias'],
            fn(string $aba) => podeModulo("relatorios.$aba")
        ));
        if (!podeModulo('relatorios') || !$abasPermitidas) {
            http_response_code(403);
            $this->view('errors/403', [], 'main');
            exit;
        }

        $pedida = is_string($_GET['aba'] ?? null) ? $_GET['aba'] : '';
        $aba    = in_array($pedida, $abasPermitidas, true) ? $pedida : $abasPermitidas[0];

        $db = Database::getInstance();
        [$escopo, $filiaisFiltro] = $this->escopoEFiltroFiliais($db);
        $filialSel = is_string($_GET['filial_id'] ?? null) && $_GET['filial_id'] !== '' ? $_GET['filial_id'] : null;

        $savings      = [];
        $anomalias    = [];
        $impactoTotal = 0.0;

        if (in_array('saving', $abasPermitidas, true)) {
            [$condFilial, $params] = sql_escopo_in('o.filial_id', $escopo);
            $stmt = $db->prepare(
                "SELECT o.mes, o.ano, o.setor_id, s.nome AS setor, f.nome AS filial,
                        o.valor_mes_anterior, o.valor_consumido,
                        (o.valor_mes_anterior - o.valor_consumido) AS saving,
                        ROUND((o.valor_mes_anterior - o.valor_consumido) /
                              NULLIF(o.valor_mes_anterior, 0) * 100, 1) AS saving_pct
                 FROM orcamentos o
                 JOIN setores s ON s.id = o.setor_id
                 JOIN filiais f ON f.id = o.filial_id
                 WHERE o.valor_mes_anterior IS NOT NULL{$condFilial}
                 ORDER BY saving DESC
                 LIMIT 50"
            );
            $stmt->execute($params);
            $savings = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        if (in_array('anomalias', $abasPermitidas, true)) {
            $modelo       = new Anomalia();
            $anomalias    = $modelo->ativas($escopo);
            $impactoTotal = $modelo->totalImpacto($escopo);
        }

        $this->view('relatorios/saving', compact(
            'aba', 'abasPermitidas', 'filiaisFiltro', 'filialSel', 'savings', 'anomalias', 'impactoTotal'
        ));
    }

    public function auditoria(): void
    {
        $this->requirePermissaoRelatorio('auditoria');

        $filtros = [
            'usuario_id'    => $_GET['usuario_id']    ?? null,
            'entidade_tipo' => $_GET['entidade_tipo'] ?? null,
            'acao'          => $_GET['acao']          ?? null,
            'data_inicio'   => $_GET['data_inicio']   ?? null,
            'data_fim'      => $_GET['data_fim']      ?? null,
        ];
        $filtros = array_filter($filtros);

        $logs = (new AuditoriaService())->buscar($filtros, 200, (int)($_GET['offset'] ?? 0));
        $this->view('relatorios/auditoria', compact('logs','filtros'));
    }

    /** Endereço antigo: o conteúdo agora é a aba Anomalias do Saving & Anomalias. */
    public function anomalias(): void
    {
        redirect('/relatorios/saving?aba=anomalias');
    }

    // ── Relatório de Brindes ──────────────────────────────────────────────────
    public function brindes(): void
    {
        $this->requirePermissaoRelatorio('brindes');
        $user = auth();
        $db   = Database::getInstance();

        $dataIni  = $_GET['data_ini'] ?? date('Y-m-d', strtotime('-3 months'));
        $dataFim  = $_GET['data_fim'] ?? date('Y-m-d');
        $filialId = ve_todas_filiais() ? ($_GET['filial_id'] ?? null) : $user['filial_id'];

        $periodoWhere  = 'mb.criado_em BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)';
        $periodoParams = [$dataIni, $dataFim];
        $filialCondMb  = $filialId ? ' AND mb.filial_id = ?' : '';
        if ($filialId) $periodoParams[] = $filialId;

        // ── KPIs: saldo atual em estoque ──────────────────────────────────────
        $filtroEstoque   = $filialId ? 'AND e.filial_id = ?'  : '';
        $filtroBrindeEst = $filialId ? 'AND be.filial_id = ?' : '';
        // Dois parâmetros por brinde: um em cada subselect do COALESCE.
        $estParams = $filialId ? [$filialId, $filialId] : [];

        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(t.saldo),0) AS qtd,
                    COALESCE(SUM(t.saldo * t.valor),0) AS valor
             FROM (
                SELECT COALESCE(
                           (SELECT SUM(e.quantidade)  FROM estoque e
                             WHERE e.item_id   = b.item_id {$filtroEstoque}),
                           (SELECT SUM(be.quantidade) FROM brinde_estoque be
                             WHERE be.brinde_id = b.id AND b.item_id IS NULL {$filtroBrindeEst}),
                           0
                       ) AS saldo,
                       COALESCE(i.valor_referencia, b.valor_referencia) AS valor
                  FROM brindes b
                  LEFT JOIN itens i ON i.id = b.item_id
                 WHERE b.ativo = 1
             ) t"
        );
        $stmt->execute($estParams);
        $saldoAtual = $stmt->fetch();

        // ── KPIs: saídas no período ────────────────────────────────────────────
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(mb.quantidade),0) AS qtd, COALESCE(SUM(mb.quantidade * b.valor_referencia),0) AS valor
             FROM movimentos_brinde mb
             JOIN brindes b ON b.id = mb.brinde_id
             WHERE mb.tipo = 'saida' AND {$periodoWhere}{$filialCondMb}"
        );
        $stmt->execute($periodoParams);
        $saidasPeriodo = $stmt->fetch();

        // ── Requisições no período, por status ────────────────────────────────
        $reqWhere  = 'rb.criado_em BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)';
        $reqParams = [$dataIni, $dataFim];
        $filialCondRb = $filialId ? ' AND rb.filial_id = ?' : '';
        if ($filialId) $reqParams[] = $filialId;

        $stmt = $db->prepare(
            "SELECT rb.status, COUNT(*) AS total, COALESCE(SUM(rb.valor_total),0) AS valor
             FROM requisicoes_brinde rb
             WHERE {$reqWhere}{$filialCondRb}
             GROUP BY rb.status"
        );
        $stmt->execute($reqParams);
        $requisicoesPorStatus = [
            'em_aprovacao' => ['total' => 0, 'valor' => 0],
            'aprovado'     => ['total' => 0, 'valor' => 0],
            'rejeitado'    => ['total' => 0, 'valor' => 0],
            'cancelado'    => ['total' => 0, 'valor' => 0],
        ];
        foreach ($stmt->fetchAll() as $r) {
            $requisicoesPorStatus[$r['status']] = ['total' => (int)$r['total'], 'valor' => (float)$r['valor']];
        }

        // ── Saldo atual por filial ──────────────────────────────────────────────
        $filialFiltroSaldo  = $filialId ? 'AND f.id = ?' : '';
        $paramsSaldoFilial  = $filialId ? [$filialId] : [];
        $stmt = $db->prepare(
            "SELECT f.id, f.nome,
                    COALESCE(SUM(t.saldo),0) AS qtd,
                    COALESCE(SUM(t.saldo * t.valor),0) AS valor
             FROM filiais f
             LEFT JOIN (
                SELECT b.id AS brinde_id,
                       COALESCE(e.filial_id, be.filial_id) AS filial_id,
                       COALESCE(e.quantidade, be.quantidade, 0) AS saldo,
                       COALESCE(i.valor_referencia, b.valor_referencia) AS valor
                  FROM brindes b
                  LEFT JOIN itens i          ON i.id  = b.item_id
                  LEFT JOIN estoque e        ON e.item_id   = b.item_id
                  LEFT JOIN brinde_estoque be ON be.brinde_id = b.id
                                             AND b.item_id IS NULL
                 WHERE b.ativo = 1
             ) t ON t.filial_id = f.id
             WHERE f.ativo = 1 {$filialFiltroSaldo}
             GROUP BY f.id, f.nome
             ORDER BY f.nome"
        );
        $stmt->execute($paramsSaldoFilial);
        $saldoPorFilial = $stmt->fetchAll();

        // ── Ranking de brindes mais solicitados no período (saídas) ─────────────
        $stmt = $db->prepare(
            "SELECT b.id, b.nome, b.codigo,
                    SUM(mb.quantidade) AS qtd,
                    SUM(mb.quantidade * b.valor_referencia) AS valor
             FROM movimentos_brinde mb
             JOIN brindes b ON b.id = mb.brinde_id
             WHERE mb.tipo = 'saida' AND {$periodoWhere}{$filialCondMb}
             GROUP BY b.id, b.nome, b.codigo
             ORDER BY valor DESC
             LIMIT 15"
        );
        $stmt->execute($periodoParams);
        $rankingBrindes = $stmt->fetchAll();

        // ── Consumo por filial no período (saídas) ──────────────────────────────
        $stmt = $db->prepare(
            "SELECT f.id, f.nome,
                    SUM(mb.quantidade) AS qtd,
                    SUM(mb.quantidade * b.valor_referencia) AS valor
             FROM movimentos_brinde mb
             JOIN filiais f ON f.id = mb.filial_id
             JOIN brindes b ON b.id = mb.brinde_id
             WHERE mb.tipo = 'saida' AND {$periodoWhere}{$filialCondMb}
             GROUP BY f.id, f.nome
             ORDER BY valor DESC"
        );
        $stmt->execute($periodoParams);
        $consumoPorFilial = $stmt->fetchAll();
        $totalConsumoFilial = array_sum(array_column($consumoPorFilial, 'valor'));

        // ── Lista detalhada de requisições no período ────────────────────────────
        $stmt = $db->prepare(
            "SELECT rb.*, f.nome AS filial_nome, u.nome AS criador_nome,
                    (SELECT COUNT(*) FROM requisicao_brinde_itens ri WHERE ri.requisicao_brinde_id = rb.id) AS total_itens
             FROM requisicoes_brinde rb
             JOIN filiais f  ON f.id = rb.filial_id
             JOIN usuarios u ON u.id = rb.criado_por
             WHERE {$reqWhere}{$filialCondRb}
             ORDER BY rb.criado_em DESC
             LIMIT 200"
        );
        $stmt->execute($reqParams);
        $requisicoes = $stmt->fetchAll();

        $filiais = ve_todas_filiais()
            ? $db->query("SELECT id, nome FROM filiais WHERE ativo=1 ORDER BY nome")->fetchAll()
            : [];

        $this->view('relatorios/brindes', compact(
            'dataIni','dataFim','filialId','filiais',
            'saldoAtual','saidasPeriodo','requisicoesPorStatus',
            'saldoPorFilial','rankingBrindes','consumoPorFilial','totalConsumoFilial',
            'requisicoes'
        ));
    }

}
