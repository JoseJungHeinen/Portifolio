<?php
// @versao Rel_02 V_04_12/08/2026
declare(strict_types=1);

/**
 * Controller do módulo Relatórios.
 *
 * Roteamento: ?module=relatorios&action=X
 *
 * Actions:
 *   index     — lista de relatórios (3 abas: financeiro, operacional e meus)
 *   exportar  — exporta o relatório selecionado em XLSX, CSV ou PDF
 *
 * Acesso: `relatorios.ver` libera o módulo; cada relatório tem a sua própria
 * ação no RBAC (ver ReportAccess). Quem enxerga o relatório exporta nos três
 * formatos — não existe permissão separada de exportação.
 */

Auth::check();
Permissions::require('relatorios', 'ver');

require_once SRC . '/Models/ReportQueries.php';
require_once SRC . '/Helpers/XlsxWriter.php';
require_once SRC . '/Helpers/PdfWriter.php';

$currentUser = Auth::user();
$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

// Tetos de linhas de "Meus processos": a tela mostra um recorte (com aviso
// quando bate o teto) e a exportação leva o período inteiro.
const RPT_MEUS_LIMITE_TELA   = 500;
const RPT_MEUS_LIMITE_EXPORT = 20000;

// Mesmos tetos para os relatórios detalhados por departamento/setor/fluxo:
// só Peças tem 2.673 processos, então a tela mostra um recorte e a exportação
// leva o período inteiro.
const RPT_DETALHE_LIMITE_TELA   = 500;
const RPT_DETALHE_LIMITE_EXPORT = 20000;

// ─── Helper: sanitiza filtros comuns ────────────────────────────
function rptFiltros(): array
{
    $filtros = [];

    $periodoPreset = Sanitize::fromGet(['periodo_preset'])['periodo_preset'];
    $presetValidos = ['esta-semana', 'este-mes', 'este-trimestre'];
    $filtros['periodo_preset'] = in_array($periodoPreset, $presetValidos, true)
        ? $periodoPreset : 'este-mes';

    $periodoInicio = Sanitize::fromGet(['periodo_inicio'])['periodo_inicio'];
    $periodoFim    = Sanitize::fromGet(['periodo_fim'])['periodo_fim'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodoInicio)) {
        $filtros['periodo_inicio'] = $periodoInicio;
        $filtros['periodo_preset'] = 'personalizado';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodoFim)) {
        $filtros['periodo_fim'] = $periodoFim;
    }

    $busca = trim(Sanitize::fromGet(['busca'])['busca']);
    if ($busca !== '') $filtros['busca'] = $busca;

    $agruparPor = Sanitize::fromGet(['agrupar_por'])['agrupar_por'];
    if (in_array($agruparPor, ['fornecedor','loja','departamento'], true)) {
        $filtros['agrupar_por'] = $agruparPor;
    }

    $situacao = Sanitize::fromGet(['situacao'])['situacao'];
    if (in_array($situacao, ['vencido','vence-hoje','a-vencer'], true)) {
        $filtros['situacao'] = $situacao;
    }

    $etapa = Sanitize::fromGet(['etapa'])['etapa'];
    if ($etapa !== '') $filtros['etapa'] = $etapa;

    // Lojas: multi-seleção (`filial_id[]`), já recortada pelo escopo do usuário.
    // Fica fora do laço porque fromGet só trata escalar — link antigo com um
    // id só continua valendo.
    $filiaisSel = FilialScope::idsDoFiltro($_GET['filial_id'] ?? null);
    if ($filiaisSel !== []) {
        $filtros['filial_id'] = $filiaisSel;
    }

    foreach (['setor_id', 'grupo_id', 'departamento_id', 'template_id'] as $f) {
        $v = Sanitize::fromGet([$f])[$f];
        if ($v !== '') {
            try { $filtros[$f] = Sanitize::uuid($v); } catch (\InvalidArgumentException) {}
        }
    }

    return $filtros;
}

// ─── Helper: filtros da aba "Meus processos" ────────────────────
/**
 * Acrescenta os dois tipos de participação aos filtros comuns.
 *
 * Checkbox desmarcado não vem no GET — por isso o formulário manda `part=1`
 * junto: sem esse marcador é a primeira carga da tela e os dois tipos entram
 * ligados; com ele, vale exatamente o que o usuário deixou marcado (inclusive
 * nenhum, que devolve lista vazia de propósito).
 */
function rptFiltrosMeus(): array
{
    $filtros = rptFiltros();

    $enviado = Sanitize::fromGet(['part'])['part'] === '1';
    $filtros['part_direta'] = !$enviado || Sanitize::fromGet(['part_direta'])['part_direta'] === '1';
    $filtros['part_grupo']  = !$enviado || Sanitize::fromGet(['part_grupo'])['part_grupo'] === '1';

    return $filtros;
}

/** Rótulo do status do processo — mesmo texto da tela de Processos. */
function rptStatusLabel(string $status): string
{
    return match ($status) {
        'aberto'                  => 'Aberto',
        'aprovado'                => 'Finalizado',
        'devolvido'               => 'Devolvido',
        'cancelado'               => 'Cancelado',
        'cancelamento_solicitado' => 'Canc. Solicitado',
        'encerrado_apollo'        => 'Encerrado',
        default                   => ucfirst($status),
    };
}

/**
 * Linha de contexto impressa embaixo do título no PDF: período, filtros
 * aplicados e quem gerou — para o arquivo não circular sem procedência.
 */
function rptSubtituloExport(string $relatorio, array $filtros, array $usuario): string
{
    [$ini, $fim] = ReportQueries::resolvePeriodo($filtros);
    $partes = ['Período: ' . DateHelper::formatBr($ini) . ' a ' . DateHelper::formatBr($fim)];

    if (!empty($filtros['filial_id'])) {
        $qtdLojas = count((array) $filtros['filial_id']);
        $partes[] = $qtdLojas > 1 ? "Lojas filtradas ({$qtdLojas})" : 'Empresa filtrada';
    }
    if (!empty($filtros['setor_id'])) {
        $partes[] = 'Setor filtrado';
    }
    if ($relatorio === 'meus_processos') {
        $partes[] = 'Participação: ' . rptParticipacaoLabel(
            !empty($filtros['part_direta']), !empty($filtros['part_grupo'])
        );
    }
    $partes[] = 'Gerado por ' . ($usuario['nome'] ?? '—');

    return implode('  ·  ', $partes);
}

/** Como o usuário participa do processo, em texto. */
function rptParticipacaoLabel(bool $direta, bool $grupo): string
{
    if ($direta && $grupo) return 'Direta + grupo';
    if ($direta)           return 'Direta';
    if ($grupo)            return 'Via grupo';
    return '—';
}

// ─── Action: index ───────────────────────────────────────────────
if ($action === 'index') {
    // Abas e relatórios liberados para este usuário — a tela só monta o que
    // ele pode acessar, e a exportação repete a mesma checagem.
    $abasPermitidas = ReportAccess::abasPermitidas();

    $aba = Sanitize::fromGet(['aba'])['aba'];
    if (!isset($abasPermitidas[$aba])) {
        $aba = (string) (array_key_first($abasPermitidas) ?? 'financeiro');
    }
    $liberados = ReportAccess::permitidos($aba);
    $podeVer   = static fn(string $chave): bool => in_array($chave, $liberados, true);

    $filtros = $aba === 'meus' ? rptFiltrosMeus() : rptFiltros();

    // Qual relatório está sendo gerado (botão "Gerar relatório")
    $relatorio = Sanitize::fromGet(['relatorio'])['relatorio'];
    if (!$podeVer($relatorio)) {
        $relatorio = '';
    }

    $dados = [];

    // Carrega dados do relatório selecionado (lazy: só quando clicado "Gerar")
    if ($aba === 'financeiro') {
        if ($podeVer('fluxo_caixa')) {
            $dados['fluxo_caixa'] = [
                'kpis'   => ReportQueries::fluxoCaixaKpis($filtros),
                'tabela' => ReportQueries::fluxoCaixaTabela($filtros),
            ];
        }
        if ($podeVer('pagamentos')) {
            $dados['pagamentos'] = [
                'kpis'   => ReportQueries::pagamentosKpis($filtros),
                'tabela' => ReportQueries::pagamentosTabela($filtros),
            ];
        }
        if ($podeVer('contas_pagar')) {
            $dados['contas_pagar'] = [
                'kpis'   => ReportQueries::contasPagarKpis($filtros),
                'tabela' => ReportQueries::contasPagarTabela($filtros),
            ];
        }
        if ($podeVer('gastos_grupo')) {
            $dados['gastos_grupo'] = [
                'tabela' => ReportQueries::gastosPorGrupoTabela($filtros),
            ];
        }
    }

    if ($aba === 'operacional') {
        if ($podeVer('volume_processos')) {
            $dados['volume_processos'] = [
                'kpis'   => ReportQueries::volumeProcessosKpis($filtros),
                'tabela' => ReportQueries::volumeProcessosTabela($filtros),
            ];
        }
        if ($podeVer('sla')) {
            $dados['sla'] = [
                'kpis'   => ReportQueries::slaKpis($filtros),
                'tabela' => ReportQueries::slaTabela($filtros),
            ];
        }
        if ($podeVer('devolucoes')) {
            $dados['devolucoes'] = [
                'kpis'   => ReportQueries::devolucoesKpis($filtros),
                'tabela' => ReportQueries::devolucoesTabela($filtros),
            ];
        }
        if ($podeVer('desempenho_usuarios')) {
            $dados['desempenho_usuarios'] = [
                'tabela' => ReportQueries::desempenhoUsuariosTabela($filtros),
            ];
        }
        // Os três detalhados compartilham a mesma consulta — muda só a
        // dimensão que o usuário filtra na tela.
        foreach (['processos_departamento', 'processos_setor', 'processos_fluxo'] as $rel) {
            if ($podeVer($rel)) {
                $dados[$rel] = [
                    'kpis'   => ReportQueries::processosDetalhadoKpis($filtros),
                    'tabela' => ReportQueries::processosDetalhadoTabela($filtros, RPT_DETALHE_LIMITE_TELA),
                ];
            }
        }
    }

    if ($aba === 'meus' && $podeVer('meus_processos')) {
        $dados['meus_processos'] = [
            'kpis'   => ReportQueries::meusProcessosKpis($filtros, $currentUser['id']),
            'tabela' => ReportQueries::meusProcessosTabela($filtros, $currentUser['id'], RPT_MEUS_LIMITE_TELA),
        ];
    }

    // Dados para dropdowns
    $dropdowns = [
        'filiais'    => ReportQueries::filiais(),
        'setores'    => ReportQueries::setores(),
        // Já vem recortado pela regra de participação — o select não oferece
        // departamento que a query vai negar.
        'departamentos' => ReportQueries::departamentos(),
        'fluxos'        => ReportQueries::fluxos(),
        'tipos_nota' => ReportQueries::tiposNota(),
        'grupos'     => ReportQueries::grupos(),
        // Em "Meus processos" o select de Empresa respeita o escopo por filial
        // do próprio usuário — não adianta oferecer filial que ele não enxerga.
        'empresas'   => FilialScope::filiaisVisiveis($currentUser['id']),
    ];

    $pageTitle    = 'Relatórios';
    $activeModule = 'relatorios';
    $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/dashboard.css">'
                  . '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/relatorios.css">';

    ob_start();
    include VIEWS . '/relatorios/index.php';
    $content = ob_get_clean();
    include VIEWS . '/layout/base.php';
    exit;
}

// ─── Action: exportar ───────────────────────────────────────────
if ($action === 'exportar') {
    $relatorio = Sanitize::fromGet(['relatorio'])['relatorio'];
    $formato   = Sanitize::fromGet(['formato'])['formato'];
    $formato   = in_array($formato, ['xlsx','csv','pdf'], true) ? $formato : 'csv';

    // Mesma permissão da tela: quem acessa o relatório exporta em qualquer
    // formato; quem não acessa leva 403 mesmo chamando a URL direto.
    ReportAccess::exigir($relatorio);

    $filtros   = $relatorio === 'meus_processos' ? rptFiltrosMeus() : rptFiltros();
    $data      = date('Y-m-d');

    // Resolve dados e headers por relatório
    [$cabecalhos, $linhas] = match($relatorio) {
        'fluxo_caixa' => [
            ['Semana', 'Parcelas', 'Valor previsto', 'Valor agendado', 'Não agendado'],
            array_map(fn($r) => [
                substr($r['semana_ini'] ?? '', 0, 10),
                $r['parcelas'],
                'R$ ' . number_format((float)$r['valor_previsto'], 2, ',', '.'),
                'R$ ' . number_format((float)$r['valor_agendado'], 2, ',', '.'),
                'R$ ' . number_format((float)$r['nao_agendado'],   2, ',', '.'),
            ], ReportQueries::fluxoCaixaTabela($filtros)),
        ],
        'pagamentos' => [
            ['Grupo', 'Pagamentos', 'Total pago', '% do total'],
            array_map(fn($r) => [
                $r['grupo'],
                $r['pagamentos'],
                'R$ ' . number_format((float)$r['total_pago'], 2, ',', '.'),
                $r['pct'] . '%',
            ], ReportQueries::pagamentosTabela($filtros)),
        ],
        'contas_pagar' => [
            ['Processo', 'Fornecedor', 'Loja', 'Valor', 'Vencimento', 'Situação'],
            array_map(fn($r) => [
                $r['proc_codigo'],
                $r['fornecedor'] ?? '—',
                $r['loja'] ?? '—',
                'R$ ' . number_format((float)$r['valor'], 2, ',', '.'),
                $r['vencimento'],
                match($r['situacao_calc']) {
                    'vencido'    => 'Vencido +' . $r['dias_atraso'] . 'd',
                    'vence-hoje' => 'Vence hoje',
                    default      => 'A vencer',
                },
            ], ReportQueries::contasPagarTabela($filtros)),
        ],
        'gastos_grupo' => [
            ['Grupo', 'Processos', 'Total pago', 'Em aberto', 'Total movimentado', '% do total'],
            array_map(fn($r) => [
                $r['grupo'],
                $r['processos'],
                'R$ ' . number_format((float)$r['total_pago'], 2, ',', '.'),
                'R$ ' . number_format((float)$r['em_aberto'],  2, ',', '.'),
                'R$ ' . number_format((float)$r['total_movimentado'], 2, ',', '.'),
                $r['pct'] . '%',
            ], ReportQueries::gastosPorGrupoTabela($filtros)),
        ],
        'volume_processos' => [
            ['Mês', 'Abertos', 'Concluídos', 'Cancelados', 'Em andamento', 'Taxa de conclusão'],
            array_map(fn($r) => [
                $r['mes'], $r['abertos'], $r['concluidos'],
                $r['cancelados'], $r['em_andamento'], $r['taxa_conclusao'] . '%',
            ], ReportQueries::volumeProcessosTabela($filtros)),
        ],
        'sla' => [
            ['Etapa', 'SLA esperado (dias)', 'Tempo médio (dias)', 'Variação %', '% do ciclo', 'Situação'],
            array_map(fn($r) => [
                $r['etapa'],
                $r['esperado_dias'] ?? '—',
                $r['media_dias'],
                $r['variacao_pct'] !== null ? $r['variacao_pct'] . '%' : '—',
                $r['pct_ciclo'] . '%',
                match($r['situacao']) {
                    'gargalo' => 'Gargalo', 'atencao' => 'Atenção',
                    'ok'      => 'Ok', default => 'Sem dados',
                },
            ], ReportQueries::slaTabela($filtros)),
        ],
        'devolucoes' => [
            ['Motivo', 'Ocorrências', '% do total', 'Etapa mais comum'],
            array_map(fn($r) => [
                $r['motivo'], $r['ocorrencias'], $r['pct'] . '%', $r['etapa_comum'] ?? '—',
            ], ReportQueries::devolucoesTabela($filtros)),
        ],
        'desempenho_usuarios' => [
            ['Usuário', 'Função', 'Processos abertos', 'Aprovados/Concluídos', 'Tempo médio resp.', 'Devoluções', 'Taxa devolução'],
            array_map(fn($r) => [
                $r['nome'], ucfirst($r['funcao'] ?? '—'),
                $r['processos_abertos'], $r['aprovados_concluidos'],
                ($r['media_resposta'] ?? '—') . ' dias',
                $r['devolucoes'], $r['taxa_devolucao'] . '%',
            ], ReportQueries::desempenhoUsuariosTabela($filtros)),
        ],
        'meus_processos' => [
            ['Código', 'Fornecedor', 'Nº NF', 'Valor', 'Filial', 'Setor', 'Etapa',
             'Responsável', 'Status', 'Abertura', 'Participação'],
            array_map(fn($r) => [
                $r['codigo'],
                $r['fornecedor_nome'] ?? '—',
                $r['numero_nf'] ?? '—',
                'R$ ' . number_format((float)$r['valor_nf'], 2, ',', '.'),
                $r['filial_nome'] ?? '—',
                $r['setor_nome'] ?? '—',
                (int)$r['etapa_atual'] . '/' . (int)$r['total_etapas'],
                in_array($r['status'], ['aberto','devolvido','cancelamento_solicitado'], true)
                    ? trim((string)($r['responsavel_nome'] ?? '—')
                        . ($r['etapa_nome'] ? ' — ' . $r['etapa_nome'] : ''))
                    : '—',
                rptStatusLabel((string)$r['status']),
                date('d/m/Y', strtotime((string)$r['criado_em'])),
                rptParticipacaoLabel((bool)$r['part_direta'], (bool)$r['part_grupo']),
            ], ReportQueries::meusProcessosTabela($filtros, $currentUser['id'], RPT_MEUS_LIMITE_EXPORT)),
        ],
        // Os três detalhados exportam as mesmas colunas — o que muda é o
        // filtro que o usuário aplicou na tela, já embutido em $filtros.
        'processos_departamento', 'processos_setor', 'processos_fluxo' => [
            ['Código', 'Fornecedor', 'Nº NF', 'Valor', 'Filial', 'Departamento',
             'Setor', 'Fluxo', 'Etapa', 'Responsável', 'Status', 'Abertura', 'Conclusão'],
            array_map(fn($r) => [
                $r['codigo'],
                $r['fornecedor_nome'] ?? '—',
                $r['numero_nf'] ?? '—',
                'R$ ' . number_format((float)$r['valor_nf'], 2, ',', '.'),
                $r['filial_nome'] ?? '—',
                $r['departamento_nome'] ?? '—',
                $r['setor_nome'] ?? '—',
                $r['template_nome'] ?? '—',
                (int)$r['etapa_atual'] . '/' . (int)$r['total_etapas'],
                in_array($r['status'], ['aberto','devolvido','cancelamento_solicitado'], true)
                    ? trim((string)($r['responsavel_nome'] ?? '—')
                        . ($r['etapa_nome'] ? ' — ' . $r['etapa_nome'] : ''))
                    : '—',
                rptStatusLabel((string)$r['status']),
                date('d/m/Y', strtotime((string)$r['criado_em'])),
                $r['concluido_em'] ? date('d/m/Y', strtotime((string)$r['concluido_em'])) : '—',
            ], ReportQueries::processosDetalhadoTabela($filtros, RPT_DETALHE_LIMITE_EXPORT)),
        ],
        default => [['Relatório não encontrado'], []],
    };

    $nomeArquivo = 'relatorio-' . $relatorio . '-' . $data;

    if ($formato === 'pdf') {
        $pdf = PdfWriter::generate(
            $cabecalhos,
            $linhas,
            ReportAccess::RELATORIOS[$relatorio]['label'],
            rptSubtituloExport($relatorio, $filtros, $currentUser)
        );
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    if ($formato === 'xlsx') {
        $xlsx = XlsxWriter::generate($cabecalhos, $linhas);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '.xlsx"');
        header('Content-Length: ' . strlen($xlsx));
        echo $xlsx;
        exit;
    }

    // CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
    fputcsv($out, $cabecalhos, ';');
    foreach ($linhas as $linha) {
        fputcsv($out, $linha, ';');
    }
    fclose($out);
    exit;
}

http_response_code(404);
include VIEWS . '/errors/404.php';
