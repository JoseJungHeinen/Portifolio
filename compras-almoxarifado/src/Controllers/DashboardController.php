<?php
// @versao Ver.003.291026

namespace App\Controllers;

use App\Core\Controller;
use App\Models\{Requisicao, Contrato, Anomalia, Aprovacao, Filial};
use App\Services\NotificacaoService;
use App\Services\EscopoGestorService;
use App\Config\Database;

class DashboardController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $user   = auth();

        // Solicitante não tem acesso ao Dashboard Executivo — redireciona pro módulo que usa
        if (in_array($user['perfil'] ?? '', ['solicitante'], true)) {
            redirect(rota_inicial($user['perfil']));
        }
        $mes    = (int)date('n');
        $ano    = (int)date('Y');
        $filial = $user['filial_id'] ?? null;
        $podeSelecionarFilial = ve_todas_filiais();
        $filiaisDashboard = [];
        $filialSelecionada = '';
        $filialSelecionadaNome = $user['filial_nome'] ?? '';

        if ($podeSelecionarFilial) {
            $filiaisDashboard = (new Filial())->allAtivas();
            $filiaisValidas = array_column($filiaisDashboard, 'id');
            if (isset($_GET['filial'])) {
                $filialParam = trim((string)$_GET['filial']);
                session_set('filtro_filial_dashboard', $filialParam);
            } else {
                $filialParam = (string)session_get('filtro_filial_dashboard', '');
            }

            if ($filialParam !== '' && in_array($filialParam, $filiaisValidas, true)) {
                $filialSelecionada = $filialParam;
                foreach ($filiaisDashboard as $filialOpcao) {
                    if ($filialOpcao['id'] === $filialSelecionada) {
                        $filialSelecionadaNome = $filialOpcao['nome'];
                        break;
                    }
                }
            } else {
                $filialSelecionadaNome = 'Todas as Filiais';
            }
        }

        $req      = new Requisicao();
        $cont     = new Contrato();
        $anomalia = new Anomalia();
        $notif    = new NotificacaoService();

        // Restringe visao por perfil.
        //   null  → todas as filiais (diretor/admin sem filtro)
        //   []    → nenhuma (gestor sem vínculo em gestores_setor)
        //   [ids] → escopo do gestor, ou a filial escolhida por diretor/admin
        //
        // $paresFiltro só é preenchido para o gestor: nas consultas que partem de
        // requisicoes (que tem setor_id) o escopo dele é o par unidade+setor, o
        // mesmo de /requisicoes e /aprovacoes. Estoque e anomalias continuam por
        // unidade — anomalias.setor_id é nullable, e escopar por par esconderia
        // as anomalias sem setor.
        $paresFiltro = null;
        if ($podeSelecionarFilial) {
            $filiaisFiltro = $filialSelecionada ? [$filialSelecionada] : null;
        } elseif (can('gestor')) {
            $escopo        = new EscopoGestorService();
            $filiaisFiltro = $escopo->filiaisDoUsuario($user['id']);
            $paresFiltro   = $escopo->paresDoUsuario($user['id']);
        } else {
            $filiaisFiltro = $filial ? [$filial] : [];
        }

        $db = Database::getInstance();
        [$filialClause, $filialParams] = $paresFiltro !== null
            ? sql_escopo_pares('r.filial_id', 'r.setor_id', $paresFiltro)
            : sql_escopo_in('r.filial_id', $filiaisFiltro);

        // KPIs: aprovações do mês atual
        $paramsAprovMes = [$mes, $ano, ...$filialParams];
        $stmtAprovMes = $db->prepare(
            "SELECT COUNT(*) AS total_aprov, COALESCE(SUM(valor_total), 0) AS total_valor
             FROM (
                 SELECT DISTINCT r.id, r.valor_total
                 FROM aprovacoes a
                 JOIN requisicoes r ON r.id = a.entidade_id
                 WHERE a.entidade_tipo = 'requisicao'
                   AND a.status = 'aprovado'
                   AND MONTH(a.resolvido_em) = ? AND YEAR(a.resolvido_em) = ?{$filialClause}
             ) AS _aprov"
        );
        $stmtAprovMes->execute($paramsAprovMes);
        $aprovMesRow      = $stmtAprovMes->fetch(\PDO::FETCH_ASSOC) ?: [];
        $totalAprovadoMes = (float)($aprovMesRow['total_valor'] ?? 0);
        $totalAprovMes    = (int)($aprovMesRow['total_aprov'] ?? 0);

        // Histórico 12 meses (para o gráfico) — baseado em aprovações
        // Mesmo escopo dos KPIs — a query também parte de requisicoes (alias r)
        // DISTINCT: requisição com mais de um nível de alçada tem uma linha
        // aprovada por nível e não pode ser somada uma vez por nível.
        [$filialHistClause, $paramsHist] = [$filialClause, $filialParams];
        $stmtHist = $db->prepare(
            "SELECT ano, mes, COALESCE(SUM(valor_total), 0) AS realizado
             FROM (
                 SELECT DISTINCT r.id, YEAR(a.resolvido_em) AS ano, MONTH(a.resolvido_em) AS mes, r.valor_total
                 FROM aprovacoes a
                 JOIN requisicoes r ON r.id = a.entidade_id
                 WHERE a.entidade_tipo = 'requisicao'
                   AND a.status = 'aprovado'
                   AND a.resolvido_em >= DATE_SUB(NOW(), INTERVAL 12 MONTH){$filialHistClause}
             ) AS _hist
             GROUP BY ano, mes
             ORDER BY ano, mes"
        );
        $stmtHist->execute($paramsHist);
        $historico12m = $stmtHist->fetchAll(\PDO::FETCH_ASSOC);

        // Comparativo por filial (só quando "todas filiais")
        $comparativoFiliais = [];
        if ($podeSelecionarFilial && !$filialSelecionada) {
            $stmtFiliais = $db->prepare(
                "SELECT fi.id AS filial_id, fi.nome AS filial,
                        COUNT(ra.id) AS total_aprov,
                        COALESCE(SUM(ra.valor_total), 0) AS realizado
                 FROM filiais fi
                 LEFT JOIN (
                     SELECT DISTINCT r.id, r.filial_id, r.valor_total
                     FROM aprovacoes a
                     JOIN requisicoes r ON r.id = a.entidade_id
                     WHERE a.entidade_tipo = 'requisicao'
                       AND a.status = 'aprovado'
                       AND MONTH(a.resolvido_em) = ? AND YEAR(a.resolvido_em) = ?
                 ) AS ra ON ra.filial_id = fi.id
                 WHERE fi.ativo = 1
                 GROUP BY fi.id, fi.nome
                 ORDER BY realizado DESC"
            );
            $stmtFiliais->execute([$mes, $ano]);
            $comparativoFiliais = $stmtFiliais->fetchAll(\PDO::FETCH_ASSOC);
        }

        // Aprovações por setor
        $paramsStor = [$mes, $ano, ...$filialParams];
        // Mesmo critério do KPI: cada requisição conta uma vez, mesmo com
        // vários níveis de alçada aprovados no mês.
        $stmtSetor = $db->prepare(
            "SELECT s.nome AS setor,
                    COUNT(*) AS total_aprov,
                    COALESCE(SUM(ra.valor_total), 0) AS realizado
             FROM (
                 SELECT DISTINCT r.id, r.setor_id, r.valor_total
                 FROM aprovacoes a
                 JOIN requisicoes r ON r.id = a.entidade_id
                 WHERE a.entidade_tipo = 'requisicao'
                   AND a.status = 'aprovado'
                   AND MONTH(a.resolvido_em) = ? AND YEAR(a.resolvido_em) = ?{$filialClause}
             ) AS ra
             JOIN setores s ON s.id = ra.setor_id
             GROUP BY s.id, s.nome
             HAVING realizado > 0
             ORDER BY realizado DESC"
        );
        $stmtSetor->execute($paramsStor);
        $porSetor = $stmtSetor->fetchAll(\PDO::FETCH_ASSOC);

        // Contratos Recorrentes
        $cont->atualizarStatusVencidos();
        $contratos    = $cont->dashboardRecorrentes();
        $vencendo30d  = $cont->vencendoEmDias(30);
        $totalRecorr  = $cont->totalMensalRecorrentes();

        // Despesas Eventuais — também partem de requisicoes, então seguem o par
        $resumoReq = $req->dashboardResumo($mes, $ano, $filiaisFiltro, $paresFiltro);

        $eventuais = $req->allComDetalhes($paresFiltro !== null
            ? ['tipo_despesa' => 'eventual', 'pares'   => $paresFiltro]
            : ['tipo_despesa' => 'eventual', 'filiais' => $filiaisFiltro]
        );
        $eventuais = array_slice($eventuais, 0, 10);

        // Anomalias e Alertas
        $anomalias            = $anomalia->ativas($filiaisFiltro);
        $totalImpactoAnomalia = $anomalia->totalImpacto($filiaisFiltro);

        // Badge de aprovacoes pendentes no sidebar
        $nAprovacoesPendentes = 0;
        if (can(['gestor', 'diretor', 'admin'])) {
            $nAprovacoesPendentes = (new Aprovacao())->totalPendentes($user['id'], $user['perfil']);
        }

        // Notificacoes
        $notificacoes = $notif->paraUsuario($user['id'], 5);
        $naoLidas     = $notif->naoLidas($user['id']);

        // Labels para Chart.js
        $labelsHistorico    = [];
        $realizadoHistorico = [];
        foreach ($historico12m as $h) {
            $dt = new \DateTime("{$h['ano']}-{$h['mes']}-01");
            $labelsHistorico[]    = $dt->format('M/y');
            $realizadoHistorico[] = (float)$h['realizado'];
        }

        // Preferencias do dashboard por usuario (sem registro = tudo visivel)
        $widgetKeys = ['kpis','grafico_historico','resumo_requisicoes',
                       'consumo_setor','comparativo_filial','contratos','eventuais','anomalias'];
        $prefs = array_fill_keys($widgetKeys, true);
        try {
            $stmtPrefs = $db->prepare(
                "SELECT widget, visivel FROM dashboard_preferencias WHERE usuario_id = ?"
            );
            $stmtPrefs->execute([$user['id']]);
            foreach ($stmtPrefs->fetchAll(\PDO::FETCH_KEY_PAIR) as $w => $v) {
                if (isset($prefs[$w])) {
                    $prefs[$w] = (bool)$v;
                }
            }
        } catch (\Throwable $e) {
            // tabela ainda nao existe - usa padrao (tudo visivel)
        }

        // Bloqueios administrativos: o admin pode forcar um widget oculto para
        // um usuario especifico, e isso tem prioridade sobre a preferencia
        // pessoal calculada acima (aplicado por ultimo, sempre vence).
        $widgetsBloqueados = [];
        try {
            $stmtBloq = $db->prepare(
                "SELECT widget FROM dashboard_bloqueios_admin WHERE usuario_id = ?"
            );
            $stmtBloq->execute([$user['id']]);
            $widgetsBloqueados = $stmtBloq->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($widgetsBloqueados as $w) {
                if (isset($prefs[$w])) {
                    $prefs[$w] = false;
                }
            }
        } catch (\Throwable $e) {
            // tabela ainda nao existe - sem bloqueios
        }

        $this->view('dashboard/index', compact(
            'totalAprovadoMes', 'totalAprovMes',
            'historico12m', 'labelsHistorico', 'realizadoHistorico',
            'porSetor', 'contratos', 'vencendo30d', 'totalRecorr',
            'resumoReq', 'eventuais',
            'anomalias', 'totalImpactoAnomalia',
            'notificacoes', 'naoLidas',
            'nAprovacoesPendentes',
            'mes', 'ano', 'user',
            'podeSelecionarFilial', 'filiaisDashboard',
            'filialSelecionada', 'filialSelecionadaNome',
            'comparativoFiliais',
            'prefs',
            'widgetsBloqueados'
        ));
    }

    public function notificacoes(): void
    {
        $this->requireAuth();
        $user  = auth();
        $notif = new NotificacaoService();
        $this->json([
            'items'    => $notif->paraUsuario($user['id']),
            'nao_lidas'=> $notif->naoLidas($user['id']),
        ]);
    }

    public function marcarLida(string $id): void
    {
        $this->requireAuth();
        $user = auth();
        (new NotificacaoService())->marcarLida($id, $user['id']);
        $this->json(['ok' => true]);
    }

    public function savePrefs(): void
    {
        $this->requireAuth();
        $user    = auth();
        $db      = Database::getInstance();
        $widgets = $_POST['widgets'] ?? [];

        $widgetKeys = ['kpis','grafico_historico','resumo_requisicoes',
                       'consumo_setor','comparativo_filial','contratos','eventuais','anomalias'];

        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM dashboard_preferencias WHERE usuario_id = ?")
               ->execute([$user['id']]);

            foreach ($widgetKeys as $w) {
                if (!isset($widgets[$w])) {
                    $db->prepare(
                        "INSERT INTO dashboard_preferencias (usuario_id, widget, visivel) VALUES (?,?,0)"
                    )->execute([$user['id'], $w]);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao salvar preferencias.');
            redirect('/dashboard');
        }

        session_flash('success', 'Preferencias do dashboard atualizadas.');
        redirect('/dashboard');
    }
}
