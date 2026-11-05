<?php
// @versao Sys_20 V_02_17/07/2026
declare(strict_types=1);

/**
 * Calculadora centralizada de SLA para processos/tarefas.
 *
 * Classificação baseada em sla_horas configurado por etapa do template.
 * Regra: ≤79% = ok, 80-100% = atenção, >100% = vencido.
 */
class SlaCalculator
{
    // Situações possíveis
    public const SEM_SLA  = 'sem-sla';
    public const OK       = 'ok';
    public const ATENCAO  = 'atencao';
    public const VENCIDO  = 'vencido';

    // Limiar de atenção: 80% do SLA consumido
    private const ATENCAO_PCT = 0.80;

    /**
     * Classifica o SLA de uma tarefa/processo.
     *
     * @param string      $entradaEtapa  Datetime de entrada na etapa atual (ISO 8601 ou MySQL)
     * @param float|null  $slaHoras      SLA configurado em horas — pode ser fracionário (ex: 0.5 = 30min); null = sem SLA
     * @param string|null $agora         Override para testes (default: now)
     *
     * @return array{
     *   situacao: string,
     *   classe: string,
     *   label: string,
     *   label_sla: string,
     *   horas_parado: float,
     *   horas_sla: float|null,
     *   pct_consumido: float|null,
     *   dias_parado: int,
     *   vencido_horas: float|null
     * }
     */
    public static function classificar(string $entradaEtapa, ?float $slaHoras = null, ?string $agora = null): array
    {
        try {
            $dtEntrada = new DateTimeImmutable($entradaEtapa);
            $dtAgora   = $agora ? new DateTimeImmutable($agora) : new DateTimeImmutable();
        } catch (\Exception $e) {
            return self::buildResult(0, null);
        }

        $diffSeg     = max(0, $dtAgora->getTimestamp() - $dtEntrada->getTimestamp());
        $horasParado = $diffSeg / 3600;

        return self::buildResult($horasParado, $slaHoras);
    }

    /**
     * Extrai sla_horas do JSON de etapas para uma etapa específica.
     *
     * Prioridade: SLA padrão do catálogo de Etapas (nf_etapas) — quando a
     * etapa está catalogada e define SLA, ele vale para TODOS os fluxos que a
     * usam — e, na ausência dele, o sla_horas configurado no template.
     *
     * Armazenado sempre em horas (podendo ser fracionário — ex: 0.5 = 30 minutos),
     * independente da unidade escolhida na configuração (minutos/horas/dias).
     *
     * @param string $etapasJson  JSON da coluna nf_template_versoes.etapas
     * @param int    $numeroEtapa Número da etapa atual (1-based)
     * @return float|null          sla_horas ou null se não configurado
     */
    public static function extrairSlaHoras(string $etapasJson, int $numeroEtapa): ?float
    {
        $etapas = json_decode($etapasJson, true);
        if (!is_array($etapas)) {
            return null;
        }

        // Etapas são 1-based; array é 0-based
        $idx = $numeroEtapa - 1;
        if (!isset($etapas[$idx])) {
            return null;
        }

        // SLA padrão do catálogo sobrepõe o SLA do template
        try {
            require_once SRC . '/Models/Etapa.php';
            $slaCatalogo = Etapa::slaCatalogo($etapas[$idx]);
            if ($slaCatalogo !== null) {
                return $slaCatalogo;
            }
        } catch (\Throwable $e) {
            // Catálogo indisponível (ex.: banco ainda não migrado) — segue com o template
        }

        $sla = $etapas[$idx]['sla_horas'] ?? null;
        return $sla !== null ? (float) $sla : null;
    }

    /**
     * Determina quando o processo entrou na etapa atual.
     *
     * Estratégia:
     * 1. Se etapa_atual > 1: usa atuado_em da etapa anterior (quando foi aprovada)
     * 2. Se etapa_atual = 1: usa criado_em do processo (primeira etapa)
     * 3. Fallback: criado_em do processo
     *
     * @param array $etapasDoProcesso Array de etapas com numero_etapa, status, atuado_em
     * @param int   $etapaAtual       Número da etapa atual
     * @param string $criadoEm        criado_em do processo (fallback)
     * @return string                  Datetime de entrada na etapa
     */
    public static function determinarEntradaEtapa(array $etapasDoProcesso, int $etapaAtual, string $criadoEm): string
    {
        if ($etapaAtual <= 1) {
            return $criadoEm;
        }

        // Buscar etapa anterior (já aprovada)
        foreach ($etapasDoProcesso as $etapa) {
            if ((int) $etapa['numero_etapa'] === ($etapaAtual - 1) && !empty($etapa['atuado_em'])) {
                return $etapa['atuado_em'];
            }
        }

        // Fallback
        return $criadoEm;
    }

    /**
     * Gera o label de tempo parado legível.
     */
    public static function labelTempo(float $horasParado): string
    {
        $dias = (int) floor($horasParado / 24);

        if ($dias === 0) {
            return 'Hoje';
        }
        if ($dias === 1) {
            return '1 dia';
        }
        return $dias . ' dias';
    }

    /**
     * Gera o label de SLA (ex: "Restam 30min", "SLA: 48h", "Vencido há 1 dia").
     */
    public static function labelSla(float $horasParado, ?float $slaHoras): string
    {
        if ($slaHoras === null) {
            return 'Sem SLA';
        }

        $excedido = $horasParado - $slaHoras;
        if ($excedido <= 0) {
            $restante = $slaHoras - $horasParado;
            if ($restante < 1) {
                return 'Restam ' . max(1, (int) round($restante * 60)) . 'min';
            }
            if ($restante < 24) {
                return 'Restam ' . max(1, (int) round($restante)) . 'h';
            }
            $diasRestantes = (int) ceil($restante / 24);
            if ($diasRestantes === 1) return 'Resta 1 dia';
            return 'Restam ' . $diasRestantes . ' dias';
        }

        // Vencido
        if ($excedido < 1) {
            return 'Vencido há ' . max(1, (int) round($excedido * 60)) . 'min';
        }
        if ($excedido < 24) {
            return 'Vencido há ' . max(1, (int) round($excedido)) . 'h';
        }
        $diasVencido = (int) floor($excedido / 24);
        if ($diasVencido === 1) return 'Vencido há 1 dia';
        return 'Vencido há ' . $diasVencido . ' dias';
    }

    /**
     * Monta o resultado padronizado.
     */
    private static function buildResult(float $horasParado, ?float $slaHoras): array
    {
        $dias = (int) floor($horasParado / 24);

        if ($slaHoras === null) {
            return [
                'situacao'       => self::SEM_SLA,
                'classe'         => 'sla-sem',
                'label'          => self::labelTempo($horasParado),
                'label_sla'      => 'Sem SLA',
                'horas_parado'   => round($horasParado, 1),
                'horas_sla'      => null,
                'pct_consumido'  => null,
                'dias_parado'    => $dias,
                'vencido_horas'  => null,
            ];
        }

        $pct = $slaHoras > 0 ? ($horasParado / $slaHoras) : 999;

        if ($pct > 1.0) {
            $situacao = self::VENCIDO;
            $classe   = 'sla-vencido';
        } elseif ($pct >= self::ATENCAO_PCT) {
            $situacao = self::ATENCAO;
            $classe   = 'sla-atencao';
        } else {
            $situacao = self::OK;
            $classe   = 'sla-ok';
        }

        $vencidoH = $horasParado > $slaHoras ? round($horasParado - $slaHoras, 1) : null;

        return [
            'situacao'       => $situacao,
            'classe'         => $classe,
            'label'          => self::labelTempo($horasParado),
            'label_sla'      => self::labelSla($horasParado, $slaHoras),
            'horas_parado'   => round($horasParado, 1),
            'horas_sla'      => $slaHoras,
            'pct_consumido'  => round($pct * 100, 1),
            'dias_parado'    => $dias,
            'vencido_horas'  => $vencidoH,
        ];
    }

    /**
     * Badge HTML padronizado para exibir situação SLA.
     */
    public static function badge(array $slaInfo): string
    {
        $label   = htmlspecialchars($slaInfo['label'], ENT_QUOTES, 'UTF-8');
        $classe  = htmlspecialchars($slaInfo['classe'], ENT_QUOTES, 'UTF-8');
        $slaTip  = htmlspecialchars($slaInfo['label_sla'], ENT_QUOTES, 'UTF-8');

        return '<span class="sla-badge ' . $classe . '" title="' . $slaTip . '">' . $label . '</span>';
    }

    /**
     * Badge HTML com situação textual (para tabelas de relatório/dashboard).
     */
    public static function badgeSituacao(array $slaInfo): string
    {
        $map = [
            self::SEM_SLA => 'Sem SLA',
            self::OK      => 'Dentro do SLA',
            self::ATENCAO => 'Atenção',
            self::VENCIDO => 'Vencido',
        ];
        $texto  = $map[$slaInfo['situacao']] ?? 'Sem SLA';
        $classe = htmlspecialchars($slaInfo['classe'], ENT_QUOTES, 'UTF-8');

        return '<span class="sla-badge ' . $classe . '">' . $texto . '</span>';
    }
}
