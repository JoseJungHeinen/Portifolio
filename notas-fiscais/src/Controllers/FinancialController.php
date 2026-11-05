<?php
// @versao Fin_03 V_07_13/08/2026
declare(strict_types=1);

/**
 * Controller do módulo Financeiro.
 *
 * Roteamento: ?module=financeiro&action=X
 *
 * Actions disponíveis:
 *   index           — lista de parcelas com filtros, abas, KPIs
 *   agendar         — POST: agenda parcela individual
 *   agendar_lote    — POST: agenda múltiplas parcelas
 *   pagar           — POST: marca parcela como paga
 *   pagar_lote      — POST: marca múltiplas parcelas como pagas
 *   exportar        — GET: exporta XLSX
 */

Auth::check();

require_once SRC . '/Models/Financial.php';

$action      = Sanitize::fromGet(['action'])['action'];
$action      = $action !== '' ? $action : 'index';
$currentUser = Auth::user();

// A tela "Pagamentos" tem permissão própria (módulo 'pagamentos');
// as demais ações pertencem ao "Controle Financeiro" (módulo 'financeiro').
$acoesPagamentos = ['pagamentos', 'pagar_v2', 'pagar_lote_v2', 'exportar_v2'];
if (in_array($action, $acoesPagamentos, true)) {
    Permissions::require('pagamentos', 'ver');
} else {
    Permissions::require('financeiro', 'ver');
}

// Verifica se o usuário tem acesso à filial da parcela (IDOR guard).
// Admin sempre tem acesso. Outros só acessam parcelas da própria filial.
$podeAcessarParcela = function(string $id) use ($currentUser): bool {
    if (Auth::perfil() === 'super_admin') return true;
    $p = Financial::findById($id);
    if (!$p) return false;
    $filialUser = $currentUser['filial_id'] ?? '';
    return $filialUser !== '' && $p['filial_id'] === $filialUser;
};

// ─────────────────────────────────────────
// Roteamento
// ─────────────────────────────────────────

// POST: agendar parcela individual
if ($action === 'agendar') {
    Permissions::require('financeiro', 'agendar');
    Csrf::validate($_POST['csrf_token'] ?? '');
    try {
        $id   = Sanitize::uuid($_POST['id'] ?? '');
        $data = Sanitize::date($_POST['data_agendamento'] ?? '');
        $obs  = Sanitize::text($_POST['observacao'] ?? '', 500);
    } catch (InvalidArgumentException) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados inválidos.'];
        header('Location: ?module=financeiro');
        exit;
    }

    if (!$id || !$data) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados inválidos.'];
        header('Location: ?module=financeiro');
        exit;
    }

    if (!$podeAcessarParcela($id)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Acesso negado.'];
        header('Location: ?module=financeiro');
        exit;
    }

    $ok = Financial::agendar($id, $data, $obs, $currentUser);
    $_SESSION['flash'] = $ok
        ? ['type' => 'success', 'message' => 'Parcela agendada com sucesso.']
        : ['type' => 'danger',   'message' => 'Não foi possível agendar esta parcela.'];
    header('Location: ?module=financeiro');
    exit;
}

// POST: agendar em lote
if ($action === 'agendar_lote') {
    Permissions::require('financeiro', 'agendar');
    Csrf::validate($_POST['csrf_token'] ?? '');
    $idsRaw = array_filter(array_map('trim', (array)($_POST['ids'] ?? [])));
    try {
        $data = Sanitize::date($_POST['data_agendamento'] ?? '');
        $obs  = Sanitize::text($_POST['observacao'] ?? '', 500);
    } catch (InvalidArgumentException) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados inválidos.'];
        header('Location: ?module=financeiro');
        exit;
    }

    // sanitizar cada id — UUIDs malformados são descartados silenciosamente
    $ids = array_filter(array_map(function ($v) {
        try { return Sanitize::uuid($v); } catch (InvalidArgumentException) { return ''; }
    }, $idsRaw));

    if (empty($ids) || !$data) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados inválidos.'];
        header('Location: ?module=financeiro');
        exit;
    }

    // Scoping: descartar silenciosamente IDs de outras filiais
    $ids = array_values(array_filter($ids, $podeAcessarParcela));
    if (empty($ids)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Acesso negado.'];
        header('Location: ?module=financeiro');
        exit;
    }

    $n = Financial::agendarLote($ids, $data, $obs, $currentUser);
    $_SESSION['flash'] = ['type' => 'success', 'message' => "{$n} parcela(s) agendada(s) com sucesso."];
    header('Location: ?module=financeiro');
    exit;
}

// POST: marcar pago individual
if ($action === 'pagar') {
    Permissions::require('financeiro', 'registrar_pagamento');
    Csrf::validate($_POST['csrf_token'] ?? '');
    try {
        $id          = Sanitize::uuid($_POST['id'] ?? '');
        $data        = Sanitize::date($_POST['data_pagamento'] ?? '');
        $obs         = Sanitize::text($_POST['observacao'] ?? '', 500);
        $comprovante = Sanitize::text($_POST['numero_comprovante'] ?? '', 100);
    } catch (InvalidArgumentException) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados inválidos.'];
        header('Location: ?module=financeiro');
        exit;
    }

    if (!$id || !$data) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados inválidos.'];
        header('Location: ?module=financeiro');
        exit;
    }

    if (!$podeAcessarParcela($id)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Acesso negado.'];
        header('Location: ?module=financeiro');
        exit;
    }

    $ok = Financial::pagar($id, $data, $obs, $currentUser, $comprovante);
    $_SESSION['flash'] = $ok
        ? ['type' => 'success', 'message' => 'Pagamento registrado com sucesso.']
        : ['type' => 'danger',   'message' => 'Não foi possível registrar o pagamento.'];
    header('Location: ?module=financeiro');
    exit;
}

// POST: pagar em lote
if ($action === 'pagar_lote') {
    Permissions::require('financeiro', 'registrar_pagamento');
    Csrf::validate($_POST['csrf_token'] ?? '');
    $idsRaw = array_filter(array_map('trim', (array)($_POST['ids'] ?? [])));
    try {
        $data        = Sanitize::date($_POST['data_pagamento'] ?? '');
        $obs         = Sanitize::text($_POST['observacao'] ?? '', 500);
        $comprovante = Sanitize::text($_POST['numero_comprovante'] ?? '', 100);
    } catch (InvalidArgumentException) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados inválidos.'];
        header('Location: ?module=financeiro');
        exit;
    }

    // sanitizar cada id — UUIDs malformados são descartados silenciosamente
    $ids = array_filter(array_map(function ($v) {
        try { return Sanitize::uuid($v); } catch (InvalidArgumentException) { return ''; }
    }, $idsRaw));

    if (empty($ids) || !$data) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados inválidos.'];
        header('Location: ?module=financeiro');
        exit;
    }

    // Scoping: descartar silenciosamente IDs de outras filiais
    $ids = array_values(array_filter($ids, $podeAcessarParcela));
    if (empty($ids)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Acesso negado.'];
        header('Location: ?module=financeiro');
        exit;
    }

    $n = Financial::pagarLote($ids, $data, $obs, $currentUser, $comprovante);
    $_SESSION['flash'] = ['type' => 'success', 'message' => "{$n} parcela(s) marcada(s) como pagas."];
    header('Location: ?module=financeiro');
    exit;
}

/**
 * Lê o multi-select resp[] ("grupo:<uuid>" | "usuario:<uuid>") do GET.
 * @return array<int, array{tipo:string, id:string}>
 */
/**
 * Recorte de lojas das telas de Pagamentos.
 *
 * Antes a trava era `usuarios.filial_id` — a loja de cadastro do funcionário,
 * uma só, mecanismo próprio do Financeiro. Passou a ser o mesmo escopo do resto
 * do sistema (`nf_usuario_filiais`, via FilialScope), que é o que o Controle de
 * Acesso configura e o que o filtro multi-loja da tela oferece.
 *
 * Precedência: o que o usuário marcou na tela; se não marcou nada, o escopo
 * inteiro dele; sem escopo configurado, nada (vê todas).
 *
 * @return list<string> ids de filial — vazio = sem recorte
 */
function pagamentosLojasFiltro(array $currentUser): array
{
    $selecionadas = FilialScope::idsDoFiltro($_GET['filial_id'] ?? null);
    if ($selecionadas !== []) {
        return $selecionadas;   // idsDoFiltro já cortou o que está fora do escopo
    }
    return FilialScope::idsVisiveis((string) ($currentUser['id'] ?? '')) ?? [];
}

function financialLerResp(): array
{
    $out = [];
    foreach ((array)($_GET['resp'] ?? []) as $v) {
        if (preg_match('/^(grupo|usuario):([0-9a-fA-F-]{36})$/', trim((string)$v), $m)) {
            try {
                $out[] = ['tipo' => strtolower($m[1]), 'id' => Sanitize::uuid($m[2])];
            } catch (InvalidArgumentException) {}
        }
    }
    return $out;
}

// GET: exportar XLSX
if ($action === 'exportar') {
    $get   = Sanitize::fromGet(['aba','busca','periodo_inicio','periodo_fim','ordem']);
    $aba   = in_array($get['aba'], ['todos','vencido','previsto','agendado','pago','cancelado'], true) ? $get['aba'] : 'todos';
    $ordem = $get['ordem'] ?: 'venc-asc';

    // Multi-select: fornecedor_id[], setor_id[] e proc_status[]
    $fornecedorIds = [];
    foreach ((array)($_GET['fornecedor_id'] ?? []) as $v) {
        try { $fornecedorIds[] = Sanitize::uuid(trim((string)$v)); } catch (InvalidArgumentException) {}
    }
    $setorIds = [];
    foreach ((array)($_GET['setor_id'] ?? []) as $v) {
        try { $setorIds[] = Sanitize::uuid(trim((string)$v)); } catch (InvalidArgumentException) {}
    }
    $procStatusValidos = ['aberto', 'aprovado', 'devolvido', 'cancelado', 'cancelamento_solicitado'];
    $procStatusIds = [];
    foreach ((array)($_GET['proc_status'] ?? []) as $v) {
        if (in_array($v, $procStatusValidos, true)) $procStatusIds[] = $v;
    }
    $respIds = financialLerResp();

    $filtros = array_filter([
        'busca'          => $get['busca'],
        'fornecedor_ids' => !empty($fornecedorIds) ? $fornecedorIds : null,
        'setor_ids'      => !empty($setorIds) ? $setorIds : null,
        'proc_status'    => !empty($procStatusIds) ? $procStatusIds : null,
        'resp_ids'       => !empty($respIds) ? $respIds : null,
        'periodo_inicio' => $get['periodo_inicio'],
        'periodo_fim'    => $get['periodo_fim'],
    ]);
    // Recorte de lojas — o mesmo da tela, para o export não divergir dela.
    $lojasExp = pagamentosLojasFiltro($currentUser);
    if ($lojasExp !== []) {
        $filtros['filial_id'] = $lojasExp;
    }
    $is_teste = (Auth::perfil() === 'super_admin') && !empty($_GET['testes']);

    Financial::atualizarStatusVencidos();
    $rows = Financial::listarTodos($filtros, $aba, $ordem, $is_teste);

    $data = date('Y-m-d');
    $hoje = date('Y-m-d');

    // Mesmos rótulos e regras usados na tabela visível (views/financeiro/list.php)
    $statusLabels = [
        'previsto'  => 'Previsto',  'agendado' => 'Agendado',
        'agvenc'    => 'Ag. Vencido','vencido' => 'Vencido',
        'pago'      => 'Pago',       'cancelado'=> 'Cancelado',
    ];
    $procStatusLabels = [
        'aberto'                  => 'Aberto',
        'aprovado'                => 'Finalizado',
        'devolvido'               => 'Devolvido',
        'cancelado'               => 'Cancelado',
        'cancelamento_solicitado' => 'Canc. Solic.',
    ];
    $etapasVisiveis = ['aberto', 'devolvido', 'cancelamento_solicitado'];

    $fmtDataExport = function (?string $d): string {
        if (!$d) return '—';
        try { return (new DateTime($d))->format('d/m/Y'); } catch (\Throwable) { return $d; }
    };

    // Cabeçalho e conteúdo de cada linha — espelham 1:1 as colunas exibidas no painel
    $cabecalhos = [
        'Processo', 'NF', 'Fornecedor', 'Departamento', 'Status Proc.', 'Etapa',
        'Tarefa Atual', 'Responsável', 'Parcela', 'Vencimento', 'SLA', 'Valor',
        'Agendamento', 'Status Parc.',
    ];

    $montarLinha = function (array $r) use ($hoje, $statusLabels, $procStatusLabels, $etapasVisiveis, $fmtDataExport): array {
        $emAndamento = in_array($r['proc_status'] ?? '', $etapasVisiveis, true);

        $sla = '—';
        if (!empty($r['vencimento'])) {
            $diff = (new DateTime($r['vencimento']))->diff(new DateTime($hoje));
            if ($r['vencimento'] < $hoje) {
                $sla = $diff->days . 'd atraso';
            } elseif ($diff->days <= 7) {
                $sla = $diff->days . 'd restantes';
            } else {
                $sla = 'em ' . $diff->days . 'd';
            }
        }

        $responsavel = '—';
        if ($emAndamento && !empty($r['proc_responsavel_nome'])) {
            $responsavel = (($r['proc_tipo_aprovador'] ?? '') === 'grupo' ? 'GRUPO: ' : '') . $r['proc_responsavel_nome'];
        }

        $agendamento = '—';
        if (!empty($r['data_agendamento'])) {
            $agendamento = $fmtDataExport($r['data_agendamento']);
        } elseif (!in_array($r['status'], ['pago', 'cancelado'], true)) {
            $agendamento = 'Não agendado';
        }

        return [
            $r['proc_codigo'] . (!empty($r['is_teste']) ? ' (TESTE)' : ''),
            $r['numero_nf'] ?? '—',
            $r['fornecedor_nome'] ?? '—',
            $r['setor_nome'] ?? '—',
            $procStatusLabels[$r['proc_status'] ?? ''] ?? ($r['proc_status'] ?? '—'),
            $emAndamento ? ((int)($r['proc_etapa_atual'] ?? 1) . '/' . (int)($r['proc_total_etapas'] ?? 1)) : '—',
            ($emAndamento && !empty($r['proc_etapa_nome'])) ? $r['proc_etapa_nome'] : '—',
            $responsavel,
            $r['numero_parcela'] . '/' . $r['total_parcelas'],
            $fmtDataExport($r['vencimento']),
            $sla,
            number_format((float)$r['valor'], 2, ',', '.'),
            $agendamento,
            $statusLabels[$r['status']] ?? $r['status'],
        ];
    };

    // ── Exportação XLSX ──────────────────────────────────────
    require_once SRC . '/Helpers/XlsxWriter.php';

    if (!class_exists('ZipArchive')) {
        // Fallback de infraestrutura: servidor sem suporte a XLSX. Não é uma opção do usuário.
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"financeiro-{$data}-ziparchive-indisponivel.csv\"");
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, ['AVISO: extensão ZipArchive não está disponível. Exportação XLSX indisponível.'], ';');
        fclose($out);
        exit;
    }

    $linhas = array_map($montarLinha, $rows);

    try {
        $xlsx = XlsxWriter::generate($cabecalhos, $linhas);
    } catch (\Throwable $e) {
        error_log('[financeiro/exportar/xlsx] ' . $e->getMessage());
        http_response_code(500);
        echo 'Erro ao gerar XLSX: ' . htmlspecialchars($e->getMessage());
        exit;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"financeiro-{$data}.xlsx\"");
    header('Content-Length: ' . strlen($xlsx));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $xlsx;
    exit;
}

// POST: pagar individual (tela Pagamentos / V2)
if ($action === 'pagar_v2') {
    Permissions::require('pagamentos', 'registrar_pagamento');
    Csrf::validate($_POST['csrf_token'] ?? '');
    try {
        $id          = Sanitize::uuid($_POST['id'] ?? '');
        $data        = Sanitize::date($_POST['data_pagamento'] ?? '');
        $obs         = Sanitize::text($_POST['observacao'] ?? '', 500);
        $comprovante = Sanitize::text($_POST['numero_comprovante'] ?? '', 100);
    } catch (InvalidArgumentException) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados inválidos.'];
        header('Location: ?module=financeiro&action=pagamentos');
        exit;
    }
    if (!$id || !$data || !$podeAcessarParcela($id)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados inválidos ou acesso negado.'];
        header('Location: ?module=financeiro&action=pagamentos');
        exit;
    }
    $ok = Financial::pagarV2($id, $data, $obs, $currentUser, $comprovante);
    $_SESSION['flash'] = $ok
        ? ['type' => 'success', 'message' => 'Pagamento registrado com sucesso.']
        : ['type' => 'danger',  'message' => 'Não foi possível registrar o pagamento.'];
    header('Location: ?module=financeiro&action=pagamentos&aba=' . urlencode($_POST['aba'] ?? 'liberados'));
    exit;
}

// POST: pagar em lote (tela Pagamentos / V2)
if ($action === 'pagar_lote_v2') {
    Permissions::require('pagamentos', 'registrar_pagamento');
    Csrf::validate($_POST['csrf_token'] ?? '');
    $idsRaw = array_filter(array_map('trim', (array)($_POST['ids'] ?? [])));
    try {
        $data        = Sanitize::date($_POST['data_pagamento'] ?? '');
        $obs         = Sanitize::text($_POST['observacao'] ?? '', 500);
        $comprovante = Sanitize::text($_POST['numero_comprovante'] ?? '', 100);
    } catch (InvalidArgumentException) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados inválidos.'];
        header('Location: ?module=financeiro&action=pagamentos');
        exit;
    }
    $ids = array_filter(array_map(function ($v) {
        try { return Sanitize::uuid($v); } catch (InvalidArgumentException) { return ''; }
    }, $idsRaw));
    $ids = array_values(array_filter($ids, $podeAcessarParcela));
    if (empty($ids) || !$data) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Selecione ao menos uma parcela e a data.'];
        header('Location: ?module=financeiro&action=pagamentos');
        exit;
    }
    $n = Financial::pagarLoteV2($ids, $data, $obs, $currentUser, $comprovante);
    $_SESSION['flash'] = ['type' => 'success', 'message' => "{$n} parcela(s) marcada(s) como pagas."];
    header('Location: ?module=financeiro&action=pagamentos&aba=' . urlencode($_POST['aba'] ?? 'liberados'));
    exit;
}

/**
 * Monta o array de filtros da tela Pagamentos a partir de $_GET.
 * Reutilizado por 'pagamentos' e 'exportar_v2'.
 */
function pagamentosFiltrosV2(array $currentUser): array {
    $busca = trim((string)($_GET['busca'] ?? ''));

    $fornecedorIds = [];
    foreach ((array)($_GET['fornecedor_id'] ?? []) as $v) {
        try { $fornecedorIds[] = Sanitize::uuid(trim((string)$v)); } catch (InvalidArgumentException) {}
    }
    $setorIds = [];
    foreach ((array)($_GET['setor_id'] ?? []) as $v) {
        try { $setorIds[] = Sanitize::uuid(trim((string)$v)); } catch (InvalidArgumentException) {}
    }
    $procStatusValidos = ['aberto','aprovado','devolvido','cancelado','cancelamento_solicitado'];
    $procStatus = [];
    foreach ((array)($_GET['proc_status'] ?? []) as $v) {
        if (in_array($v, $procStatusValidos, true)) $procStatus[] = $v;
    }

    // Período (preset → datas)
    $periodoInicio = trim((string)($_GET['periodo_inicio'] ?? ''));
    $periodoFim    = trim((string)($_GET['periodo_fim'] ?? ''));
    $preset        = trim((string)($_GET['periodo_preset'] ?? ''));
    if ($preset && !$periodoInicio && !$periodoFim) {
        $hoje = date('Y-m-d');
        switch ($preset) {
            case 'hoje':              $periodoInicio = $hoje; $periodoFim = $hoje; break;
            case 'esta-semana':       $periodoInicio = date('Y-m-d', strtotime('monday this week')); $periodoFim = date('Y-m-d', strtotime('sunday this week')); break;
            case 'proximos-7':        $periodoInicio = $hoje; $periodoFim = date('Y-m-d', strtotime('+7 days')); break;
            case 'este-mes':          $periodoInicio = date('Y-m-01'); $periodoFim = date('Y-m-t'); break;
            case 'proximos-30':       $periodoInicio = $hoje; $periodoFim = date('Y-m-d', strtotime('+30 days')); break;
            case 'proximos-60':       $periodoInicio = $hoje; $periodoFim = date('Y-m-d', strtotime('+60 days')); break;
            case 'proximo-trimestre': $periodoInicio = $hoje; $periodoFim = date('Y-m-d', strtotime('+90 days')); break;
            case 'vencidos':          $periodoFim = date('Y-m-d', strtotime('-1 day')); break;
        }
    }
    $validData = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : '';
    $periodoInicio = $validData($periodoInicio);
    $periodoFim    = $validData($periodoFim);

    $respIds = financialLerResp();

    $filtros = array_filter([
        'busca'          => $busca !== '' ? $busca : null,
        'fornecedor_ids' => !empty($fornecedorIds) ? $fornecedorIds : null,
        'setor_ids'      => !empty($setorIds) ? $setorIds : null,
        'proc_status'    => !empty($procStatus) ? $procStatus : null,
        'resp_ids'       => !empty($respIds) ? $respIds : null,
        'periodo_inicio' => $periodoInicio !== '' ? $periodoInicio : null,
        'periodo_fim'    => $periodoFim !== '' ? $periodoFim : null,
    ]);
    $lojasV2 = pagamentosLojasFiltro($currentUser);
    if ($lojasV2 !== []) {
        $filtros['filial_id'] = $lojasV2;
    }
    return $filtros;
}

// GET: exportar XLSX da tela Pagamentos (respeita filtros + aba)
if ($action === 'exportar_v2') {
    Permissions::require('pagamentos', 'exportar');
    Financial::atualizarStatusVencidos();
    $get   = Sanitize::fromGet(['aba','ordem','testes']);
    $aba   = in_array($get['aba'], ['aberto','atraso','andamento','liberados','pagos'], true) ? $get['aba'] : 'aberto';
    $ordem = in_array($get['ordem'], Financial::ORDENS, true) ? $get['ordem'] : 'venc-asc';
    $is_teste = (Auth::perfil() === 'super_admin') && ($get['testes'] === '1');
    $filtros  = pagamentosFiltrosV2($currentUser);

    $rows = Financial::listarV2($filtros, $aba, $ordem, 0, 100000, $is_teste);

    $fmtD = function (?string $d): string {
        if (!$d) return '—';
        try { return (new DateTime($d))->format('d/m/Y'); } catch (\Throwable) { return $d; }
    };
    $abaLabels = ['aberto'=>'Em Aberto','atraso'=>'Em Atraso','andamento'=>'Em Andamento','liberados'=>'Liberados','pagos'=>'Pagos'];
    $cabecalhos = ['Processo','NFE / NFSE','Fornecedor','Responsável','Parcela','Vencimento','Valor'];
    $linhas = array_map(function (array $r) use ($fmtD): array {
        $nfe = trim((string)($r['numero_nf'] ?? ''));
        $nfse = trim((string)($r['num_nota_servico'] ?? ''));
        $nf = implode(' / ', array_filter([$nfe, $nfse], fn($x) => $x !== '')) ?: '—';
        $resp = '—';
        if (!empty($r['proc_responsavel_nome'])) {
            $resp = (($r['proc_tipo_aprovador'] ?? '') === 'grupo' ? 'Grupo: ' : '') . $r['proc_responsavel_nome'];
        }
        return [
            $r['proc_codigo'] . (!empty($r['is_teste']) ? ' (TESTE)' : ''),
            $nf,
            $r['fornecedor_nome'] ?? $r['fornecedor_razao'] ?? '—',
            $resp,
            (int)$r['numero_parcela'] . '/' . (int)$r['total_parcelas'],
            $fmtD($r['vencimento']),
            number_format((float)$r['valor'], 2, ',', '.'),
        ];
    }, $rows);

    require_once SRC . '/Helpers/XlsxWriter.php';
    $dataArq = date('Y-m-d');
    if (!class_exists('ZipArchive')) {
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"pagamentos-{$aba}-{$dataArq}.csv\"");
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, $cabecalhos, ';');
        foreach ($linhas as $l) fputcsv($out, $l, ';');
        fclose($out);
        exit;
    }
    try {
        $xlsx = XlsxWriter::generate($cabecalhos, $linhas);
    } catch (\Throwable $e) {
        error_log('[financeiro/exportar_v2] ' . $e->getMessage());
        http_response_code(500);
        echo 'Erro ao gerar XLSX.';
        exit;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"pagamentos-{$aba}-{$dataArq}.xlsx\"");
    header('Content-Length: ' . strlen($xlsx));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $xlsx;
    exit;
}

// GET: tela "Pagamentos" (segunda tela, baseada no financeiro)
if ($action === 'pagamentos') {
    Financial::atualizarStatusVencidos();

    // ── Filtros persistentes até o "Limpar" ────────────────────────────────
    // Registrar um pagamento redireciona para cá sem querystring; sem isto o
    // recorte que o usuário montou se perdia a cada parcela paga. A sessão
    // guarda o último recorte e ele volta quando a URL chega vazia — quando a
    // URL traz filtro (troca de aba, paginação, novo filtro), a URL manda.
    //
    // A restauração é feita em $_GET de propósito: a tela inteira (controller,
    // view e o pagUrl() que monta os links) lê de lá, então repor ali mantém
    // filtro, links e paginação coerentes sem duplicar a leitura em dois lugares.
    $pagChavesFiltro = ['aba','ordem','por_pagina','busca','fornecedor_id','setor_id',
                        'proc_status','resp','filial_id','periodo_inicio','periodo_fim','periodo_preset'];

    if (($_GET['limpar'] ?? '') === '1') {
        unset($_SESSION['pag_filtros']);
        header('Location: ?module=financeiro&action=pagamentos');
        exit;
    }

    $pagTemFiltroNaUrl = false;
    foreach ($pagChavesFiltro as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '' && $_GET[$k] !== []) { $pagTemFiltroNaUrl = true; break; }
    }
    if ($pagTemFiltroNaUrl) {
        $pagGuardar = [];
        foreach ($pagChavesFiltro as $k) {
            if (isset($_GET[$k]) && $_GET[$k] !== '' && $_GET[$k] !== []) $pagGuardar[$k] = $_GET[$k];
        }
        $_SESSION['pag_filtros'] = $pagGuardar;
    } elseif (!empty($_SESSION['pag_filtros'])) {
        foreach ($_SESSION['pag_filtros'] as $k => $v) {
            if (!isset($_GET[$k])) $_GET[$k] = $v;
        }
    }

    $get = Sanitize::fromGet(['aba','ordem','por_pagina','testes']);
    $aba       = in_array($get['aba'], ['aberto','atraso','andamento','liberados','pagos'], true) ? $get['aba'] : 'aberto';
    $ordem     = in_array($get['ordem'], Financial::ORDENS, true) ? $get['ordem'] : 'venc-asc';
    $porPagina = in_array((int)$get['por_pagina'], [20, 50], true) ? (int)$get['por_pagina'] : 20;
    $pagina    = max(1, (int)($_GET['page'] ?? 1));
    $is_teste  = (Auth::perfil() === 'super_admin') && ($get['testes'] === '1');

    $filtros = pagamentosFiltrosV2($currentUser);

    $total    = Financial::totalV2($filtros, $aba, $is_teste);
    $totalPgs = max(1, (int)ceil($total / $porPagina));
    $pagina   = min($pagina, $totalPgs);
    $offset   = ($pagina - 1) * $porPagina;
    $parcelas = Financial::listarV2($filtros, $aba, $ordem, $offset, $porPagina, $is_teste);
    $contagens = Financial::contagensV2($filtros, $is_teste);
    $fornecedores = Financial::fornecedores();
    $setores      = Financial::setores();
    $responsaveis = Financial::responsaveis();
    // Lojas do filtro = as filiais liberadas no cadastro do usuário (Controle de
    // Acesso). Sem nenhuma liberada, o padrão do FilialScope vale: enxerga todas.
    $filiaisPag   = FilialScope::filiaisVisiveis((string) ($currentUser['id'] ?? ''));

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    $pageTitle    = 'Pagamentos';
    $activeModule = 'pagamentos';
    $unreadCount  = 0;
    $cssVer    = filemtime(ROOT . '/public/assets/css/modules/financeiro.css');
    $extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/financeiro.css?v=' . $cssVer . '">';

    ob_start();
    include VIEWS . '/financeiro/pagamentos.php';
    $content = ob_get_clean();
    include VIEWS . '/layout/base.php';
    exit;
}

// ─────────────────────────────────────────
// action = index (GET: lista principal)
// ─────────────────────────────────────────

// Atualizar status vencidos antes de qualquer listagem
Financial::atualizarStatusVencidos();

// Parâmetros de filtro e paginação
$get = Sanitize::fromGet(['aba','busca','periodo_inicio','periodo_fim','periodo_preset','ordem','pagina','por_pagina','testes']);

$aba       = in_array($get['aba'], ['todos','vencido','previsto','agendado','pago','cancelado'], true) ? $get['aba'] : 'todos';
$ordem     = in_array($get['ordem'], Financial::ORDENS, true) ? $get['ordem'] : 'venc-asc';
$porPagina = in_array((int)$get['por_pagina'], [20, 50], true) ? (int)$get['por_pagina'] : 20;
$pagina    = max(1, (int)$get['pagina']);
$is_teste  = (Auth::perfil() === 'super_admin') && ($get['testes'] === '1');

// Resolução de período preset → datas
$periodoInicio = $get['periodo_inicio'];
$periodoFim    = $get['periodo_fim'];
$preset        = $get['periodo_preset'];

if ($preset && !$periodoInicio && !$periodoFim) {
    $hoje = date('Y-m-d');
    switch ($preset) {
        case 'esta-semana':
            $periodoInicio = date('Y-m-d', strtotime('monday this week'));
            $periodoFim    = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'proximos-7':
            $periodoInicio = $hoje;
            $periodoFim    = date('Y-m-d', strtotime('+7 days'));
            break;
        case 'este-mes':
            $periodoInicio = date('Y-m-01');
            $periodoFim    = date('Y-m-t');
            break;
        case 'proximos-30':
            $periodoInicio = $hoje;
            $periodoFim    = date('Y-m-d', strtotime('+30 days'));
            break;
        case 'proximos-60':
            $periodoInicio = $hoje;
            $periodoFim    = date('Y-m-d', strtotime('+60 days'));
            break;
        case 'proximo-trimestre':
            $periodoInicio = $hoje;
            $periodoFim    = date('Y-m-d', strtotime('+90 days'));
            break;
        case 'vencidos':
            $periodoFim    = date('Y-m-d', strtotime('-1 day'));
            break;
    }
}

// Multi-select: fornecedor_id[], setor_id[] e proc_status[]
$fornecedorIds = [];
foreach ((array)($_GET['fornecedor_id'] ?? []) as $v) {
    try { $fornecedorIds[] = Sanitize::uuid(trim((string)$v)); } catch (InvalidArgumentException) {}
}
$setorIds = [];
foreach ((array)($_GET['setor_id'] ?? []) as $v) {
    try { $setorIds[] = Sanitize::uuid(trim((string)$v)); } catch (InvalidArgumentException) {}
}
$procStatusValidos = ['aberto', 'aprovado', 'devolvido', 'cancelado', 'cancelamento_solicitado'];
$procStatusIds = [];
foreach ((array)($_GET['proc_status'] ?? []) as $v) {
    if (in_array($v, $procStatusValidos, true)) $procStatusIds[] = $v;
}
$respIds = financialLerResp();

$filtros = array_filter([
    'busca'          => $get['busca'],
    'fornecedor_ids' => !empty($fornecedorIds) ? $fornecedorIds : null,
    'setor_ids'      => !empty($setorIds) ? $setorIds : null,
    'proc_status'    => !empty($procStatusIds) ? $procStatusIds : null,
    'resp_ids'       => !empty($respIds) ? $respIds : null,
    'periodo_inicio' => $periodoInicio,
    'periodo_fim'    => $periodoFim,
]);
// Recorte de lojas: escolha do filtro da tela, senão o escopo do funcionário.
$lojasTela = pagamentosLojasFiltro($currentUser);
if ($lojasTela !== []) {
    $filtros['filial_id'] = $lojasTela;
}

// Consultas
$total     = Financial::total($filtros, $aba, $is_teste);
$totalPgs  = max(1, (int)ceil($total / $porPagina));
$pagina    = min($pagina, $totalPgs);
$offset    = ($pagina - 1) * $porPagina;
$parcelas  = Financial::listar($filtros, $aba, $ordem, $offset, $porPagina, $is_teste);

$contagens  = Financial::contagens($filtros, $is_teste);
$kpis       = Financial::kpis($filtros, '_global', $is_teste);
$fornecedores = Financial::fornecedores();
$setores      = Financial::setores();
$responsaveis = Financial::responsaveis();
$alertasVenc  = Financial::alertasVencidos();
$alertasProx  = Financial::alertasProximos();

// Flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Renderizar view
$pageTitle    = 'Controle Financeiro';
$activeModule = 'financeiro';
$unreadCount  = 0;
$cssVer    = filemtime(ROOT . '/public/assets/css/modules/financeiro.css');
$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/financeiro.css?v=' . $cssVer . '">';

ob_start();
include VIEWS . '/financeiro/list.php';
$content = ob_get_clean();
include VIEWS . '/layout/base.php';
