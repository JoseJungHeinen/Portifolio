<?php
// @versao Ver.004.301026

namespace App\Services;

use App\Config\Database;
use App\Models\Fornecedor;
use App\Models\Requisicao;

/**
 * Painel de compras — fase 1 (dados existentes) + fase 2 (entrega prevista, prazo
 * de pagamento em dias, CND/regime do fornecedor, confirmação de entrega na SOL).
 * Specs: docs/2026-10-29-painel-compras-fase1.md e docs/2026-10-29-painel-compras-fase2.md
 * Etapa 4 (docs/2026-10-30-sistema-compras-proprio.md): pedidos abertos pelas 11 etapas do
 * fluxograma (AndamentoService, em lote), alertas das marcações 06/08/09 e avaliação por período.
 *
 * Tudo agregado no banco: cada bloco é uma consulta agrupada (nada de consulta
 * por linha). O PHP só converte o que o SQL não sabe — dias úteis entre pares
 * de datas e o texto da condição de pagamento em dias.
 */
class PainelComprasService
{
    public const PERIODOS = [
        'mes'  => 'Este mês',
        '3m'   => 'Últimos 3 meses',
        'ano'  => 'Este ano',
        'tudo' => 'Tudo',
    ];
    public const PERIODO_PADRAO = 'mes';

    // Metas dos indicadores (política de compras).
    public const META_PRAZO_ATENDIMENTO = 5;     // ≤ dias úteis (aprovação da requisição → OC)
    public const META_SAVING            = 0.05;  // ≥ 5%
    public const META_EMERGENCIAIS      = 0.05;  // ≤ 5% das OCs
    public const META_NF_DIVERGENTE     = 0.03;  // ≤ 3% das NFs
    public const META_PRAZO_PAGAMENTO   = 30;    // ≥ dias (ponderado pelo valor)
    public const META_ENTREGA_NO_PRAZO  = 0.90;  // ≥ 90% das OCs com previsão e entregues (recebimento físico ou NF aprovada)

    // Alertas
    public const ALERTA_CONTRATO_VALOR_12M   = 30000.0;
    public const ALERTA_EMERGENCIAL_DIAS     = 2;   // dias úteis da abertura sem OC
    public const ALERTA_ENVIO_OC_DIAS        = 1;   // OC gerada e não marcada como enviada há mais de N dias úteis
    public const ALERTA_CND_DIAS             = 15;  // CND vence em até N dias
    public const ALERTA_REGIME_MESES         = 6;   // regime confirmado há mais de N meses
    public const ALERTA_CONFIRMACAO_DIAS     = 1;   // recebido e não confirmado há mais de N dias úteis
    public const ALERTAS_MAX                 = 15;

    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ── Funções puras ─────────────────────────────────────────────────────────

    /**
     * Condição de pagamento (texto livre) em dias: média das parcelas citadas
     * ("30/60/90" → 60, "28 DDL" → 28); "à vista"/"antecipado" → 0; sem número
     * nem palavra conhecida → null (fica fora da conta). Percentuais ("50%") e
     * número de parcelas ("3x") não são dias e são ignorados. O parser é o de
     * CondicaoPagamento::parcelas (o mesmo da OC).
     */
    public static function diasPagamento(string $condicao): ?float
    {
        $parcelas = CondicaoPagamento::parcelas($condicao);
        return $parcelas === null ? null : CondicaoPagamento::prazoMedio($parcelas);
    }

    /** null = sem dado; senão se o valor cumpre a meta ("<=" ou ">="). */
    public static function avaliarMeta(?float $valor, string $op, float $meta): ?bool
    {
        if ($valor === null) return null;
        return match ($op) {
            '<=' => $valor <= $meta,
            '>=' => $valor >= $meta,
            default => throw new \InvalidArgumentException('Operador de meta inválido: ' . $op),
        };
    }

    /** Primeiro dia (Y-m-d) do período; null = sem limite. Período desconhecido = mês corrente. */
    public static function periodoInicio(string $per, \DateTimeImmutable $hoje): ?string
    {
        switch ($per) {
            case 'tudo':
                return null;
            case 'ano':
                return $hoje->format('Y') . '-01-01';
            case '3m':
                // Mesmo dia 3 meses antes, sem estourar mês curto (31/05 → 28/02).
                $mes = $hoje->modify('first day of -3 months');
                $dia = min((int)$hoje->format('j'), (int)$mes->format('t'));
                return $mes->format('Y-m-') . str_pad((string)$dia, 2, '0', STR_PAD_LEFT);
            default:
                return $hoje->format('Y-m-01');
        }
    }

    /** Fase 2: OC entregue no prazo = data da NF aprovada (só o dia) ≤ entrega prevista. */
    public static function entregaNoPrazo(string $dataNf, string $entregaPrevista): bool
    {
        return substr($dataNf, 0, 10) <= substr($entregaPrevista, 0, 10);
    }

    /**
     * Prazo de pagamento de uma OC: o campo prazo_pagamento_dias (fase 2) vale;
     * sem ele, cai no texto da condição (diasPagamento).
     * @return array{0: ?float, 1: bool} [dias ou null, true se veio do texto (aproximado)]
     */
    public static function diasPagamentoOc(?int $campo, string $condicao): array
    {
        if ($campo !== null) return [(float)$campo, false];
        $dias = self::diasPagamento($condicao);
        return [$dias, $dias !== null];
    }

    /**
     * Alertas da CND Federal do fornecedor: vencida (bad), vence em ≤ ALERTA_CND_DIAS (warn),
     * situação positiva (warn). "Positiva com efeitos de negativa" vale como regular.
     * @return list<array{sev: string, texto: string}>
     */
    public static function alertasCnd(?string $validade, ?string $situacao, \DateTimeImmutable $hoje): array
    {
        $out = [];
        if ($validade) {
            $v    = new \DateTimeImmutable(substr($validade, 0, 10));
            $h    = new \DateTimeImmutable($hoje->format('Y-m-d'));
            $dias = (int)$h->diff($v)->format('%r%a');
            if ($dias < 0) {
                $out[] = ['sev' => 'bad', 'texto' => 'CND Federal vencida em ' . $v->format('d/m/Y') . ' — pedir certidão atualizada antes de comprar.'];
            } elseif ($dias <= self::ALERTA_CND_DIAS) {
                $out[] = ['sev' => 'warn', 'texto' => 'CND Federal ' . ($dias === 0 ? 'vence hoje' : 'vence em ' . $dias . ($dias === 1 ? ' dia' : ' dias'))
                                                     . ' (' . $v->format('d/m/Y') . ') — pedir a renovação.'];
            }
        }
        if ($situacao === 'positiva') {
            $out[] = ['sev' => 'warn', 'texto' => 'CND Federal positiva (débitos em aberto) — avaliar antes de novas compras.'];
        }
        return $out;
    }

    /** Regime tributário nunca confirmado ou confirmado há mais de ALERTA_REGIME_MESES. */
    public static function regimeDesatualizado(?string $confirmadoEm, \DateTimeImmutable $hoje): bool
    {
        if (!$confirmadoEm) return true;
        return substr($confirmadoEm, 0, 10) < $hoje->modify('-' . self::ALERTA_REGIME_MESES . ' months')->format('Y-m-d');
    }

    /** Produto recebido (NF aprovada) e o solicitante não confirmou há mais de ALERTA_CONFIRMACAO_DIAS dias úteis. */
    public static function confirmacaoAtrasada(string $recebidoEm, \DateTimeImmutable $agora): bool
    {
        $dias = SolicitacaoCompraService::diasUteisEntre(new \DateTimeImmutable(substr($recebidoEm, 0, 10)),
                                                         new \DateTimeImmutable($agora->format('Y-m-d')));
        return $dias > self::ALERTA_CONFIRMACAO_DIAS;
    }

    // ── Etapa 4: painel ajustado ao fluxograma (docs/2026-10-30-sistema-compras-proprio.md) ──

    /**
     * Quantos pedidos estão em cada etapa (a "atual" de AndamentoService::calcular).
     * Concluídos e interrompidos (sem etapa atual) ficam fora.
     * @param array<array{atual: ?int}> $andamentos
     * @return array<int, int> 1..11 => quantidade
     */
    public static function contarPorEtapa(array $andamentos): array
    {
        $n = array_fill_keys(array_keys(AndamentoService::ETAPAS), 0);
        foreach ($andamentos as $a) {
            $e = $a['atual'] ?? null;
            if ($e !== null && isset($n[$e])) $n[$e]++;
        }
        return $n;
    }

    /**
     * Linhas do bloco "Onde estão os pedidos abertos": as 11 etapas, SOLs + requisições sem SOL.
     * Link só onde a lista tem o filtro exato (02 = SOL aguardando aprovação do gestor).
     * fase: 1 = pedido (01–03), 2 = compra (04–06), 3 = entrega (07–11).
     */
    public static function etapasPipeline(array $porSol, array $porReq): array
    {
        $links = [2 => 'solicitacoes?aba=todas&status=aguardando_aprovacao'];
        $out = [];
        foreach (AndamentoService::ETAPAS as $num => $rotulo) {
            $s = (int)($porSol[$num] ?? 0);
            $r = (int)($porReq[$num] ?? 0);
            $out[] = ['numero' => $num, 'rotulo' => $rotulo, 'n' => $s + $r, 'sol' => $s, 'req' => $r,
                      'link' => $links[$num] ?? null, 'fase' => $num <= 3 ? 1 : ($num <= 6 ? 2 : 3)];
        }
        return $out;
    }

    /** Data (Y-m-d) em que a OC foi entregue: recebimento físico (09), senão NF aprovada; null = não entregue. */
    public static function dataEntregaOc(?string $recebidoFisico, ?string $nfAprovada): ?string
    {
        if ($recebidoFisico !== null && $recebidoFisico !== '') return substr($recebidoFisico, 0, 10);
        if ($nfAprovada !== null && $nfAprovada !== '') return substr($nfAprovada, 0, 10);
        return null;
    }

    /**
     * OC com entrega prevista vencida e ainda não entregue (sem recebimento físico nem NF aprovada).
     * Em trânsito (08) = azul (acompanhar a transportadora); sem trânsito = laranja (cobrar o fornecedor).
     * @param array{entrega_prevista: ?string, recebido_fisico_em: ?string, nf_aprovada: ?string,
     *              transito_em: ?string, cte_numero: ?string, enviada_em: ?string, fornecedor: string} $oc
     * @return array{sev: string, dias: int, texto: string}|null
     */
    public static function entregaAtrasada(array $oc, \DateTimeImmutable $hoje): ?array
    {
        $prev = $oc['entrega_prevista'] ?? null;
        if (!$prev) return null;
        if (self::dataEntregaOc($oc['recebido_fisico_em'] ?? null, $oc['nf_aprovada'] ?? null) !== null) return null;
        $p = new \DateTimeImmutable(substr($prev, 0, 10));
        $h = new \DateTimeImmutable($hoje->format('Y-m-d'));
        if ($p >= $h) return null;

        $dias  = (int)$p->diff($h)->format('%a');
        $texto = 'Entrega atrasada ' . $dias . ($dias === 1 ? ' dia' : ' dias') . ' (prevista para '
               . $p->format('d/m/Y') . ', ' . ($oc['fornecedor'] ?? '') . ')';
        if (!empty($oc['transito_em'])) {
            $cte = trim((string)($oc['cte_numero'] ?? ''));
            return ['sev' => 'info', 'dias' => $dias,
                    'texto' => $texto . ' — em trânsito desde ' . date('d/m/Y', strtotime((string)$oc['transito_em']))
                             . ($cte !== '' ? ' (CT-e ' . $cte . ')' : '') . '; acompanhar a transportadora.'];
        }
        if (empty($oc['enviada_em'])) {
            return ['sev' => 'warn', 'dias' => $dias,
                    'texto' => $texto . ' e a OC não foi marcada como enviada — confirmar o envio e cobrar o fornecedor.'];
        }
        return ['sev' => 'warn', 'dias' => $dias, 'texto' => $texto . ' sem trânsito nem recebimento — cobrar o fornecedor.'];
    }

    /** OC gerada/aprovada e não marcada como enviada (06) há mais de ALERTA_ENVIO_OC_DIAS dias úteis. */
    public static function envioAtrasado(string $desde, \DateTimeImmutable $agora): bool
    {
        $dias = SolicitacaoCompraService::diasUteisEntre(new \DateTimeImmutable(substr($desde, 0, 10)),
                                                         new \DateTimeImmutable($agora->format('Y-m-d')));
        return $dias > self::ALERTA_ENVIO_OC_DIAS;
    }

    /**
     * Fornecedores cujas duas últimas avaliações por PERÍODO ficaram abaixo de Fornecedor::NOTA_BLOQUEIO
     * (regra de Fornecedor::sugereBloqueio). @param array[] $linhas fornecedor_id, periodo, nota, criado_em
     * @return list<string> ids, na ordem em que aparecem
     */
    public static function fornecedoresSugeridosBloqueio(array $linhas): array
    {
        $por = [];
        foreach ($linhas as $l) $por[(string)$l['fornecedor_id']][] = $l;
        $out = [];
        foreach ($por as $id => $avs) {
            if (Fornecedor::sugereBloqueio($avs)) $out[] = (string)$id;
        }
        return $out;
    }

    // ── Painel ────────────────────────────────────────────────────────────────

    /**
     * @param string|null $inicio Y-m-d (periodoInicio) ou null para tudo
     * @param string|null $filialId filtro de unidade (filial da requisição/solicitação)
     */
    public function montar(?string $inicio, ?string $filialId): array
    {
        $kpis = $this->indicadores($inicio, $filialId);
        return [
            'kpis'     => $kpis['cartoes'],
            'pipeline' => $this->pipeline($filialId),
            'alertas'  => $this->alertas($filialId),
            'valor'    => $this->valorEmOcs($inicio, $filialId, $kpis['frete']),
        ];
    }

    /**
     * Filtro das OCs consideradas: não canceladas, no período (data da OC) e na unidade.
     * @return array{0: string, 1: array}
     */
    private function filtroOc(?string $inicio, ?string $filialId): array
    {
        $where  = ["oc.status <> 'cancelado'"];
        $params = [];
        if ($inicio !== null) {
            $where[]  = 'oc.criado_em >= ?';
            $params[] = $inicio . ' 00:00:00';
        }
        if ($filialId !== null) {
            $where[]  = 'r.filial_id = ?';
            $params[] = $filialId;
        }
        return [implode(' AND ', $where), $params];
    }

    private function indicadores(?string $inicio, ?string $filialId): array
    {
        [$w, $p] = $this->filtroOc($inicio, $filialId);

        // 1) Contagem, valor e emergenciais (SOL de regularização ou despesa eventual).
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(oc.frete_previsto), 0) AS frete,
                    COALESCE(SUM(CASE WHEN r.tipo_despesa = 'eventual'
                                        OR EXISTS (SELECT 1 FROM solicitacoes_compra s
                                                   WHERE s.requisicao_id = r.id AND s.emergencial = 1)
                                      THEN 1 ELSE 0 END), 0) AS emergenciais
             FROM ordens_compra oc
             JOIN requisicoes r ON r.id = oc.requisicao_id
             WHERE $w"
        );
        $stmt->execute($p);
        $base = $stmt->fetch();
        $nOc  = (int)$base['n'];

        // 2) Prazo de atendimento: pares (aprovação final, data da OC) agrupados;
        //    o PHP só conta os dias úteis de cada par distinto.
        $stmt = $this->db->prepare(
            "SELECT DATE(ap.aprovada_em) AS aprovada, DATE(oc.criado_em) AS emitida, COUNT(*) AS n
             FROM ordens_compra oc
             JOIN requisicoes r ON r.id = oc.requisicao_id
             JOIN (SELECT entidade_id, MAX(resolvido_em) AS aprovada_em
                   FROM aprovacoes
                   WHERE entidade_tipo = 'requisicao' AND status = 'aprovado' AND resolvido_em IS NOT NULL
                   GROUP BY entidade_id) ap ON ap.entidade_id = r.id
             WHERE $w
             GROUP BY DATE(ap.aprovada_em), DATE(oc.criado_em)"
        );
        $stmt->execute($p);
        $somaDias = 0;
        $nPrazo   = 0;
        foreach ($stmt->fetchAll() as $par) {
            $de  = new \DateTimeImmutable($par['aprovada']);
            $ate = new \DateTimeImmutable($par['emitida']);
            $somaDias += ($ate > $de ? SolicitacaoCompraService::diasUteisEntre($de, $ate) : 0) * (int)$par['n'];
            $nPrazo   += (int)$par['n'];
        }
        $prazo = $nPrazo ? $somaDias / $nPrazo : null;

        // 3) Saving: por cotação com ≥ 2 propostas respondidas, média das propostas
        //    (itens + frete) contra a do fornecedor da OC; ponderado pela média.
        $propostas = "SELECT cf.cotacao_id, cf.fornecedor_id,
                             SUM(ci.quantidade * ci.valor_unitario) + COALESCE(cf.frete, 0) AS valor
                      FROM cotacao_fornecedores cf
                      JOIN cotacao_itens ci ON ci.cotacao_fornecedor_id = cf.id
                      WHERE cf.status = 'respondida'
                      GROUP BY cf.id, cf.cotacao_id, cf.fornecedor_id, cf.frete";
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS n, COALESCE(SUM(m.media), 0) AS soma_media,
                    COALESCE(SUM(m.media - v.valor), 0) AS economia
             FROM (SELECT DISTINCT oc.cotacao_id, oc.fornecedor_id
                   FROM ordens_compra oc
                   JOIN requisicoes r ON r.id = oc.requisicao_id
                   WHERE $w AND oc.cotacao_id IS NOT NULL) x
             JOIN (SELECT cotacao_id, AVG(valor) AS media
                   FROM ($propostas) p
                   GROUP BY cotacao_id
                   HAVING COUNT(*) >= 2) m ON m.cotacao_id = x.cotacao_id
             JOIN ($propostas) v ON v.cotacao_id = x.cotacao_id AND v.fornecedor_id = x.fornecedor_id"
        );
        $stmt->execute($p);
        $sv     = $stmt->fetch();
        $saving = (float)$sv['soma_media'] > 0 ? (float)$sv['economia'] / (float)$sv['soma_media'] : null;

        // 4) Prazo de pagamento: ordens_compra.prazo_pagamento_dias (fase 2); OC sem o
        //    campo cai na condição da proposta vencedora, agrupada pelo texto.
        //    OC direta: requisicao_cotacoes.condicao_pagamento da vencedora; cotação
        //    pelo portal: o campo "Condição de pagamento" grava em cotacao_fornecedores.observacao.
        $stmt = $this->db->prepare(
            "SELECT oc.prazo_pagamento_dias AS dias_campo,
                    CASE WHEN oc.prazo_pagamento_dias IS NULL
                         THEN COALESCE(NULLIF(TRIM(rc.condicao_pagamento), ''), cf.observacao, '')
                         ELSE '' END AS condicao,
                    COUNT(*) AS n, SUM(oc.valor_total + COALESCE(oc.frete_previsto, 0)) AS valor
             FROM ordens_compra oc
             JOIN requisicoes r ON r.id = oc.requisicao_id
             LEFT JOIN requisicao_cotacoes rc
                    ON rc.requisicao_id = oc.requisicao_id AND rc.vencedora = 1 AND rc.fornecedor_id = oc.fornecedor_id
             LEFT JOIN cotacao_fornecedores cf
                    ON cf.cotacao_id = oc.cotacao_id AND cf.fornecedor_id = oc.fornecedor_id
             WHERE $w
             GROUP BY dias_campo, condicao"
        );
        $stmt->execute($p);
        $somaPond = 0.0;
        $somaVal  = 0.0;
        $nPag     = 0;
        $pagAprox = false;
        foreach ($stmt->fetchAll() as $c) {
            [$dias, $doTexto] = self::diasPagamentoOc(
                $c['dias_campo'] === null ? null : (int)$c['dias_campo'], (string)$c['condicao']);
            if ($dias === null) continue;
            $somaPond += $dias * (float)$c['valor'];
            $somaVal  += (float)$c['valor'];
            $nPag     += (int)$c['n'];
            $pagAprox  = $pagAprox || $doTexto;
        }
        $pagamento = $somaVal > 0 ? $somaPond / $somaVal : null;

        // 4b) Entregas no prazo (etapa 4): OCs do período com entrega prevista e já entregues;
        //     data da entrega = recebimento físico (09), senão a última NF aprovada
        //     (conferência, senão lançamento) — dataEntregaOc. Agrupado; o PHP decide cada grupo.
        $stmt = $this->db->prepare(
            "SELECT e.fisico, e.nf, e.prevista, COUNT(*) AS n
             FROM (SELECT oc.entrega_prevista AS prevista, oc.recebido_fisico_em AS fisico,
                          (SELECT DATE(MAX(COALESCE(nf.conferido_em, nf.criado_em))) FROM notas_fiscais nf
                           WHERE nf.oc_id = oc.id AND nf.status = 'aprovada') AS nf
                   FROM ordens_compra oc
                   JOIN requisicoes r ON r.id = oc.requisicao_id
                   WHERE $w AND oc.entrega_prevista IS NOT NULL) e
             WHERE e.fisico IS NOT NULL OR e.nf IS NOT NULL
             GROUP BY e.fisico, e.nf, e.prevista"
        );
        $stmt->execute($p);
        $nEnt     = 0;
        $nNoPrazo = 0;
        foreach ($stmt->fetchAll() as $g) {
            $entregue = self::dataEntregaOc($g['fisico'], $g['nf']);
            if ($entregue === null) continue;
            $nEnt += (int)$g['n'];
            if (self::entregaNoPrazo($entregue, (string)$g['prevista'])) $nNoPrazo += (int)$g['n'];
        }
        $noPrazo = $nEnt ? $nNoPrazo / $nEnt : null;

        // 5) NFs do período (data da NF) com divergência.
        $wNf = ['1=1'];
        $pNf = [];
        if ($inicio !== null) { $wNf[] = 'nf.criado_em >= ?'; $pNf[] = $inicio . ' 00:00:00'; }
        if ($filialId !== null) { $wNf[] = 'r.filial_id = ?'; $pNf[] = $filialId; }
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(CASE WHEN nf.status = 'divergente'
                                        OR (nf.divergencias_json IS NOT NULL
                                            AND TRIM(nf.divergencias_json) NOT IN ('', '[]', '{}', 'null'))
                                      THEN 1 ELSE 0 END), 0) AS divergentes
             FROM notas_fiscais nf
             JOIN ordens_compra oc ON oc.id = nf.oc_id
             JOIN requisicoes r    ON r.id  = oc.requisicao_id
             WHERE " . implode(' AND ', $wNf)
        );
        $stmt->execute($pNf);
        $nf   = $stmt->fetch();
        $nNf  = (int)$nf['n'];
        $nDiv = (int)$nf['divergentes'];

        $emerg = $nOc ? (int)$base['emergenciais'] / $nOc : null;
        $divPc = $nNf ? $nDiv / $nNf : null;

        $cartoes = [
            [
                'nome'  => 'Prazo de atendimento',
                'icone' => 'bi-stopwatch',
                'valor' => $prazo === null ? null : number_format($prazo, 1, ',', '.') . ' d.u.',
                'meta'  => 'Meta ≤ ' . self::META_PRAZO_ATENDIMENTO . ' dias úteis (requisição aprovada → OC)',
                'ok'    => self::avaliarMeta($prazo, '<=', self::META_PRAZO_ATENDIMENTO),
                'base'  => $nPrazo . ' de ' . $nOc . ' OC' . ($nOc === 1 ? '' : 's'),
                'nota'  => null,
            ],
            [
                'nome'  => 'Economia (saving)',
                'icone' => 'bi-piggy-bank',
                'valor' => $saving === null ? null : self::pct($saving),
                'meta'  => 'Meta ≥ ' . self::pct(self::META_SAVING, 0)
                         . ($saving === null ? '' : ' · ' . money((float)$sv['economia']) . ' abaixo da média'),
                'ok'    => self::avaliarMeta($saving, '>=', self::META_SAVING),
                'base'  => (int)$sv['n'] . ' cotaç' . ((int)$sv['n'] === 1 ? 'ão' : 'ões') . ' com 2+ propostas',
                'nota'  => 'aproximado: contra a média das cotações',
            ],
            [
                'nome'  => 'Compras emergenciais',
                'icone' => 'bi-lightning-charge',
                'valor' => $emerg === null ? null : self::pct($emerg),
                'meta'  => 'Meta ≤ ' . self::pct(self::META_EMERGENCIAIS, 0) . ' das OCs',
                'ok'    => self::avaliarMeta($emerg, '<=', self::META_EMERGENCIAIS),
                'base'  => (int)$base['emergenciais'] . ' de ' . $nOc,
                'nota'  => null,
            ],
            [
                'nome'  => 'NF com divergência',
                'icone' => 'bi-receipt-cutoff',
                'valor' => $divPc === null ? null : self::pct($divPc),
                'meta'  => 'Meta ≤ ' . self::pct(self::META_NF_DIVERGENTE, 0) . ' das NFs',
                'ok'    => self::avaliarMeta($divPc, '<=', self::META_NF_DIVERGENTE),
                'base'  => $nDiv . ' de ' . $nNf,
                'nota'  => null,
            ],
            [
                'nome'  => 'Prazo médio de pagamento',
                'icone' => 'bi-calendar2-check',
                'valor' => $pagamento === null ? null : number_format($pagamento, 0, ',', '.') . ' dias',
                'meta'  => 'Meta ≥ ' . self::META_PRAZO_PAGAMENTO . ' dias (ponderado pelo valor)',
                'ok'    => self::avaliarMeta($pagamento, '>=', self::META_PRAZO_PAGAMENTO),
                'base'  => $nPag . ' de ' . $nOc . ' OC' . ($nOc === 1 ? '' : 's'),
                'nota'  => $pagAprox ? 'aproximado: parte das OCs sem prazo em dias (lido da condição)' : null,
            ],
            [
                'nome'  => 'Entregas no prazo',
                'icone' => 'bi-truck',
                'valor' => $noPrazo === null ? null : self::pct($noPrazo),
                'meta'  => 'Meta ≥ ' . self::pct(self::META_ENTREGA_NO_PRAZO, 0) . ' (recebida — ou NF aprovada — até a entrega prevista)',
                'ok'    => self::avaliarMeta($noPrazo, '>=', self::META_ENTREGA_NO_PRAZO),
                'base'  => $nNoPrazo . ' de ' . $nEnt . ' OC' . ($nEnt === 1 ? '' : 's'),
                'nota'  => null,
            ],
        ];

        return ['cartoes' => $cartoes, 'frete' => (float)$base['frete']];
    }

    /**
     * Pedidos abertos pela etapa atual do fluxograma (sem período): SOLs abertas e requisições
     * de compra sem SOL. Duas consultas + os carregadores em lote do AndamentoService.
     * Concluídos, cancelados e recusados ficam fora (status no SQL; o resto pelo cálculo).
     */
    private function pipeline(?string $filialId): array
    {
        $fSol = $filialId !== null ? ' AND s.filial_id = ?' : '';
        $fReq = $filialId !== null ? ' AND r.filial_id = ?' : '';
        $pF   = $filialId !== null ? [$filialId] : [];

        $stmt = $this->db->prepare(
            "SELECT s.id, s.status, s.finalidade, s.recebido_em, s.entregue_em, s.requisicao_id
             FROM solicitacoes_compra s
             WHERE s.status NOT IN ('concluida','cancelada','recusada')$fSol"
        );
        $stmt->execute($pF);
        $sols = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare(
            "SELECT r.id
             FROM requisicoes r
             WHERE r.tipo IN ('compra','ordem_compra') AND r.status <> 'cancelado'$fReq
               AND NOT EXISTS (SELECT 1 FROM solicitacoes_compra s WHERE s.requisicao_id = r.id)"
        );
        $stmt->execute($pF);
        $reqIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $and = new AndamentoService($this->db);
        return self::etapasPipeline(
            self::contarPorEtapa($sols ? $and->dasSolicitacoes($sols) : []),
            self::contarPorEtapa($reqIds ? $and->dasRequisicoes($reqIds) : [])
        );
    }

    /**
     * Itens que precisam de atenção, vermelho → laranja → azul, até ALERTAS_MAX.
     * @return array{itens: array, total: int}
     */
    private function alertas(?string $filialId): array
    {
        $out   = [];
        $agora = new \DateTimeImmutable();
        $fSol  = $filialId !== null ? ' AND s.filial_id = ?' : '';
        $pSol  = $filialId !== null ? [$filialId] : [];

        // SOL emergencial (regularização) sem OC há mais de N dias úteis da abertura.
        $stmt = $this->db->prepare(
            "SELECT s.id, s.numero, s.titulo, s.criado_em
             FROM solicitacoes_compra s
             WHERE s.emergencial = 1 AND s.status IN ('aguardando_aprovacao','aprovada','em_compra')$fSol
               AND NOT EXISTS (SELECT 1 FROM ordens_compra oc
                               WHERE oc.requisicao_id = s.requisicao_id AND oc.status <> 'cancelado')
             ORDER BY s.criado_em
             LIMIT 100"
        );
        $stmt->execute($pSol);
        foreach ($stmt->fetchAll() as $s) {
            $dias = SolicitacaoCompraService::diasUteisEntre(new \DateTimeImmutable(substr($s['criado_em'], 0, 10)),
                                                             new \DateTimeImmutable($agora->format('Y-m-d')));
            if ($dias <= self::ALERTA_EMERGENCIAL_DIAS) continue;
            $out[] = ['sev' => 'bad', 'link' => 'solicitacoes/' . $s['id'],
                      'ref' => SolicitacaoCompraService::numeroFormatado($s),
                      'texto' => 'Compra emergencial sem OC há ' . $dias . ' dias úteis da abertura — regularizar: ' . $s['titulo']];
        }

        // NF divergente ainda não resolvida.
        $fReq = $filialId !== null ? ' AND r.filial_id = ?' : '';
        $stmt = $this->db->prepare(
            "SELECT nf.id, nf.numero_nf, oc.numero_oc,
                    COALESCE(NULLIF(f.nome_fantasia, ''), f.razao_social) AS fornecedor
             FROM notas_fiscais nf
             JOIN ordens_compra oc ON oc.id = nf.oc_id
             JOIN requisicoes r    ON r.id  = oc.requisicao_id
             JOIN fornecedores f   ON f.id  = oc.fornecedor_id
             WHERE nf.status = 'divergente'$fReq
             ORDER BY nf.criado_em
             LIMIT " . self::ALERTAS_MAX
        );
        $stmt->execute($pSol);
        foreach ($stmt->fetchAll() as $nf) {
            $out[] = ['sev' => 'bad', 'link' => 'fiscal/' . $nf['id'], 'ref' => 'NF ' . $nf['numero_nf'],
                      'texto' => 'Divergência na conferência da NF (OC ' . $nf['numero_oc'] . ', ' . $nf['fornecedor'] . ') — resolver com o fornecedor.'];
        }

        // Etapa 4 — fornecedor (ainda não bloqueado) com nota < 2,0 nos dois últimos PERÍODOS
        // avaliados (Fornecedor::sugereBloqueio). O SQL traz só as 2 últimas por período de cada um.
        $stmt = $this->db->query(
            "SELECT a.fornecedor_id, a.periodo, a.nota, a.criado_em,
                    COALESCE(NULLIF(f.nome_fantasia, ''), f.razao_social) AS nome
             FROM (SELECT fornecedor_id, periodo, nota, criado_em,
                          ROW_NUMBER() OVER (PARTITION BY fornecedor_id ORDER BY periodo DESC, criado_em DESC, id DESC) AS rn
                   FROM avaliacoes_fornecedor) a
             JOIN fornecedores f ON f.id = a.fornecedor_id
             WHERE a.rn <= 2 AND f.situacao <> 'bloqueado'
             ORDER BY nome"
        );
        $linhas = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $nomes  = array_column($linhas, 'nome', 'fornecedor_id');
        foreach (self::fornecedoresSugeridosBloqueio($linhas) as $fid) {
            $out[] = ['sev' => 'bad', 'link' => 'fornecedores/' . $fid, 'ref' => null,
                      'texto' => $nomes[$fid] . ': nota abaixo de ' . Fornecedor::formatarNota(Fornecedor::NOTA_BLOQUEIO)
                               . ' nos dois últimos períodos avaliados — a política prevê bloqueio.'];
        }

        // Etapa 4 — fornecedor bloqueado com OC em aberto (não cancelada nem conferida).
        $stmt = $this->db->prepare(
            "SELECT f.id, COALESCE(NULLIF(f.nome_fantasia, ''), f.razao_social) AS nome,
                    COUNT(*) AS n, GROUP_CONCAT(oc.numero_oc ORDER BY oc.criado_em SEPARATOR ', ') AS ocs
             FROM ordens_compra oc
             JOIN requisicoes r  ON r.id = oc.requisicao_id
             JOIN fornecedores f ON f.id = oc.fornecedor_id
             WHERE f.situacao = 'bloqueado' AND oc.status NOT IN ('cancelado','recebido_total')
               AND NOT EXISTS (SELECT 1 FROM notas_fiscais nf WHERE nf.oc_id = oc.id AND nf.status = 'aprovada')$fReq
             GROUP BY f.id, nome
             ORDER BY nome
             LIMIT " . self::ALERTAS_MAX
        );
        $stmt->execute($pSol);
        foreach ($stmt->fetchAll() as $f) {
            $n = (int)$f['n'];
            $out[] = ['sev' => 'warn', 'link' => 'fornecedores/' . $f['id'], 'ref' => null,
                      'texto' => $f['nome'] . ' está bloqueado e tem ' . $n . ($n === 1 ? ' OC' : ' OCs') . ' em aberto (OC '
                               . $f['ocs'] . ') — acompanhar a entrega ou cancelar.'];
        }

        // SOL com SLA atrasado (o filtro no SQL só pré-seleciona; situacaoSla decide).
        $stmt = $this->db->prepare(
            "SELECT s.id, s.numero, s.titulo, s.status, s.sla_vence_em, s.concluida_em, s.criado_em
             FROM solicitacoes_compra s
             WHERE s.status IN ('aguardando_aprovacao','aprovada','em_compra')
               AND s.sla_vence_em IS NOT NULL AND s.sla_vence_em < ?$fSol
             ORDER BY s.sla_vence_em
             LIMIT " . self::ALERTAS_MAX
        );
        $stmt->execute(array_merge([$agora->format('Y-m-d H:i:s')], $pSol));
        foreach ($stmt->fetchAll() as $s) {
            $sit = SolicitacaoCompraService::situacaoSla($s, $agora);
            if (($sit['chave'] ?? '') !== 'atrasada') continue;
            $out[] = ['sev' => 'warn', 'link' => 'solicitacoes/' . $s['id'],
                      'ref' => SolicitacaoCompraService::numeroFormatado($s),
                      'texto' => 'SLA: ' . mb_strtolower($sit['texto']) . ' (' . (SolicitacaoCompraService::STATUS_LABEL[$s['status']] ?? $s['status']) . ') — ' . $s['titulo']];
        }

        // Fornecedor com muito volume em 12 meses e sem contrato ativo (todas as unidades).
        $stmt = $this->db->prepare(
            "SELECT f.id, COALESCE(NULLIF(f.nome_fantasia, ''), f.razao_social) AS nome,
                    SUM(oc.valor_total + COALESCE(oc.frete_previsto, 0)) AS total
             FROM ordens_compra oc
             JOIN fornecedores f ON f.id = oc.fornecedor_id
             WHERE oc.status <> 'cancelado' AND oc.criado_em >= ?
               AND NOT EXISTS (SELECT 1 FROM contratos c
                               WHERE c.fornecedor_id = f.id AND c.status = 'ativo'
                                 AND c.data_inicio <= CURDATE() AND c.data_fim >= CURDATE())
             GROUP BY f.id, nome
             HAVING total > ?
             ORDER BY total DESC
             LIMIT " . self::ALERTAS_MAX
        );
        $stmt->execute([$agora->modify('-12 months')->format('Y-m-d H:i:s'), self::ALERTA_CONTRATO_VALOR_12M]);
        foreach ($stmt->fetchAll() as $f) {
            $out[] = ['sev' => 'info', 'link' => 'fornecedores/' . $f['id'], 'ref' => null,
                      'texto' => $f['nome'] . ': ' . money((float)$f['total']) . ' em OCs nos últimos 12 meses e sem contrato ativo — negociar anualmente / formalizar contrato.'];
        }

        // OC com entrega atrasada: prevista antes de hoje e não entregue — etapa 4: entregue =
        // recebimento físico (09) ou NF aprovada; em trânsito (08) vira azul (entregaAtrasada).
        $stmt = $this->db->prepare(
            "SELECT oc.id, oc.numero_oc, oc.entrega_prevista, oc.enviada_em, oc.transito_em, oc.cte_numero,
                    oc.recebido_fisico_em, NULL AS nf_aprovada,
                    COALESCE(NULLIF(f.nome_fantasia, ''), f.razao_social) AS fornecedor
             FROM ordens_compra oc
             JOIN requisicoes r  ON r.id = oc.requisicao_id
             JOIN fornecedores f ON f.id = oc.fornecedor_id
             WHERE oc.entrega_prevista IS NOT NULL AND oc.entrega_prevista < ?
               AND oc.status NOT IN ('recebido_total','cancelado') AND oc.recebido_fisico_em IS NULL
               AND NOT EXISTS (SELECT 1 FROM notas_fiscais nf WHERE nf.oc_id = oc.id AND nf.status = 'aprovada')$fReq
             ORDER BY oc.entrega_prevista
             LIMIT " . self::ALERTAS_MAX
        );
        $stmt->execute(array_merge([$agora->format('Y-m-d')], $pSol));
        foreach ($stmt->fetchAll() as $oc) {
            $a = self::entregaAtrasada($oc, $agora);
            if ($a === null) continue;
            $out[] = ['sev' => $a['sev'], 'link' => 'compras/ordens/' . $oc['id'], 'ref' => 'OC ' . $oc['numero_oc'],
                      'texto' => $a['texto']];
        }

        // Etapa 4 — OC gerada/aprovada e não marcada como enviada (06) há mais de 1 dia útil.
        // Data-base: última aprovação da OC na alçada (reaprovação), senão a geração.
        $stmt = $this->db->prepare(
            "SELECT oc.id, oc.numero_oc,
                    COALESCE((SELECT MAX(ap.resolvido_em) FROM aprovacoes ap
                              WHERE ap.entidade_tipo = 'ordem_compra' AND ap.entidade_id = oc.id
                                AND ap.status = 'aprovado'), oc.criado_em) AS desde,
                    COALESCE(NULLIF(f.nome_fantasia, ''), f.razao_social) AS fornecedor
             FROM ordens_compra oc
             JOIN requisicoes r  ON r.id = oc.requisicao_id
             JOIN fornecedores f ON f.id = oc.fornecedor_id
             WHERE oc.enviada_em IS NULL AND oc.status NOT IN ('rascunho','cancelado')$fReq
             ORDER BY oc.criado_em
             LIMIT 100"
        );
        $stmt->execute($pSol);
        foreach ($stmt->fetchAll() as $oc) {
            if (!self::envioAtrasado((string)$oc['desde'], $agora)) continue;
            $dias = SolicitacaoCompraService::diasUteisEntre(new \DateTimeImmutable(substr($oc['desde'], 0, 10)),
                                                             new \DateTimeImmutable($agora->format('Y-m-d')));
            $out[] = ['sev' => 'warn', 'link' => 'compras/ordens/' . $oc['id'], 'ref' => 'OC ' . $oc['numero_oc'],
                      'texto' => 'Gerada em ' . date('d/m/Y', strtotime($oc['desde'])) . ' e não marcada como enviada a '
                               . $oc['fornecedor'] . ' há ' . $dias . ' dias úteis — enviar e marcar (etapa 06).'];
        }

        // Fase 2 — produto recebido (NF aprovada) e o solicitante ainda não confirmou.
        $stmt = $this->db->prepare(
            "SELECT s.id, s.numero, s.titulo, s.criado_em, s.recebido_em
             FROM solicitacoes_compra s
             WHERE s.status = 'em_compra' AND s.recebido_em IS NOT NULL AND s.entregue_em IS NULL$fSol
             ORDER BY s.recebido_em
             LIMIT 100"
        );
        $stmt->execute($pSol);
        foreach ($stmt->fetchAll() as $s) {
            if (!self::confirmacaoAtrasada($s['recebido_em'], $agora)) continue;
            $out[] = ['sev' => 'warn', 'link' => 'solicitacoes/' . $s['id'],
                      'ref' => SolicitacaoCompraService::numeroFormatado($s),
                      'texto' => 'Recebido em ' . date('d/m/Y', strtotime($s['recebido_em']))
                               . ' e o solicitante não confirmou a entrega — ' . $s['titulo']];
        }

        // Fase 2 — CND Federal (vencida / vencendo / positiva) e regime não confirmado
        // (este só para fornecedor com OC nos últimos 12 meses). Todas as unidades.
        $limiteCnd = $agora->modify('+' . self::ALERTA_CND_DIAS . ' days')->format('Y-m-d');
        $limiteReg = $agora->modify('-' . self::ALERTA_REGIME_MESES . ' months')->format('Y-m-d');
        $desde12m  = $agora->modify('-12 months')->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "SELECT f.id, COALESCE(NULLIF(f.nome_fantasia, ''), f.razao_social) AS nome,
                    f.cnd_federal_validade, f.cnd_federal_situacao, f.regime_confirmado_em,
                    EXISTS (SELECT 1 FROM ordens_compra oc
                            WHERE oc.fornecedor_id = f.id AND oc.status <> 'cancelado' AND oc.criado_em >= ?) AS com_oc
             FROM fornecedores f
             WHERE f.ativo = 1
               AND (f.cnd_federal_validade <= ? OR f.cnd_federal_situacao = 'positiva'
                    OR ((f.regime_confirmado_em IS NULL OR f.regime_confirmado_em < ?)
                        AND EXISTS (SELECT 1 FROM ordens_compra oc
                                    WHERE oc.fornecedor_id = f.id AND oc.status <> 'cancelado' AND oc.criado_em >= ?)))
             ORDER BY nome
             LIMIT 100"
        );
        $stmt->execute([$desde12m, $limiteCnd, $limiteReg, $desde12m]);
        $hoje = new \DateTimeImmutable($agora->format('Y-m-d'));
        foreach ($stmt->fetchAll() as $f) {
            foreach (self::alertasCnd($f['cnd_federal_validade'], $f['cnd_federal_situacao'], $hoje) as $a) {
                $out[] = ['sev' => $a['sev'], 'link' => 'fornecedores/' . $f['id'], 'ref' => null,
                          'texto' => $f['nome'] . ': ' . $a['texto']];
            }
            if ((int)$f['com_oc'] === 1 && self::regimeDesatualizado($f['regime_confirmado_em'], $hoje)) {
                $out[] = ['sev' => 'info', 'link' => 'fornecedores/' . $f['id'], 'ref' => null,
                          'texto' => $f['nome'] . ': regime tributário '
                                   . ($f['regime_confirmado_em'] ? 'confirmado pela última vez em ' . date('d/m/Y', strtotime($f['regime_confirmado_em'])) : 'nunca confirmado')
                                   . ' — confirmar (compra nos últimos 12 meses).'];
            }
        }

        $ordem = ['bad' => 0, 'warn' => 1, 'info' => 2];
        usort($out, fn($a, $b) => $ordem[$a['sev']] <=> $ordem[$b['sev']]);
        return ['itens' => array_slice($out, 0, self::ALERTAS_MAX), 'total' => count($out)];
    }

    /** Valor em OCs no período: total, por unidade e por finalidade fiscal (+ frete). */
    private function valorEmOcs(?string $inicio, ?string $filialId, float $frete): array
    {
        [$w, $p] = $this->filtroOc($inicio, $filialId);

        $stmt = $this->db->prepare(
            "SELECT COALESCE(fi.nome, 'Sem unidade') AS rotulo,
                    SUM(oc.valor_total + COALESCE(oc.frete_previsto, 0)) AS valor
             FROM ordens_compra oc
             JOIN requisicoes r   ON r.id  = oc.requisicao_id
             LEFT JOIN filiais fi ON fi.id = r.filial_id
             WHERE $w
             GROUP BY r.filial_id, fi.nome
             ORDER BY valor DESC"
        );
        $stmt->execute($p);
        $porUnidade = array_map(fn($r) => ['rotulo' => $r['rotulo'], 'valor' => (float)$r['valor']], $stmt->fetchAll());

        $stmt = $this->db->prepare(
            "SELECT r.finalidade, SUM(oc.valor_total) AS valor
             FROM ordens_compra oc
             JOIN requisicoes r ON r.id = oc.requisicao_id
             WHERE $w
             GROUP BY r.finalidade"
        );
        $stmt->execute($p);
        $porFinalidade = [];
        foreach ($stmt->fetchAll() as $r) {
            if ((float)$r['valor'] == 0.0) continue;
            $porFinalidade[] = ['rotulo' => Requisicao::FINALIDADES[$r['finalidade'] ?? ''] ?? 'Não informada',
                                'valor'  => (float)$r['valor']];
        }
        if ($frete > 0) $porFinalidade[] = ['rotulo' => 'Frete', 'valor' => $frete];
        usort($porFinalidade, fn($a, $b) => $b['valor'] <=> $a['valor']);

        return [
            'total'         => array_sum(array_column($porUnidade, 'valor')),
            'porUnidade'    => $porUnidade,
            'porFinalidade' => $porFinalidade,
        ];
    }

    private static function pct(float $v, int $casas = 1): string
    {
        return number_format($v * 100, $casas, ',', '.') . '%';
    }
}
