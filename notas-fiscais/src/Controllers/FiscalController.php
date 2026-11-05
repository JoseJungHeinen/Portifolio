<?php
// @versao Fis_03 V_03_26/08/2026
declare(strict_types=1);

/**
 * Controller do módulo Fiscal — IBS/CBS por fornecedor.
 *
 * Roteamento: ?module=fiscal&action=X
 *
 * A tela nasce VAZIA: só há resumo e listagem depois que o usuário informa
 * data inicial e final e clica em Filtrar. Sem período não há consulta —
 * varrer o acervo inteiro por engano não ajudaria ninguém.
 *
 * "Atualizar" relê os XMLs que já estão em storage/nfe_xml. Não chama a
 * Brobot: quem busca nota nova é o poller de capturas.
 */

Auth::check();
Permissions::require('fiscal', 'ver');

require_once SRC . '/Models/FiscalNota.php';
require_once SRC . '/Models/FiscalCnpj.php';
require_once SRC . '/Helpers/CnpjReceita.php';

$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

/**
 * Período + filiais vindos de $_GET (tela) ou $_POST (retorno de ação).
 * `aplicado` = o usuário já filtrou; enquanto for false a tela mostra só o
 * formulário, sem consultar nada.
 *
 * @return array{aplicado:bool, data_ini:string, data_fim:string,
 *               filial_ids:list<string>, cbs:string, erro:string}
 */
$lerFiltro = static function (array $fonte, bool $exigeFlag = true): array {
    $f = ['aplicado' => false, 'data_ini' => '', 'data_fim' => '',
          'filial_ids' => [], 'cbs' => '', 'erro' => ''];

    // Adesão ao CBS: '' = todos · 'sem' = não destacou · 'com' = destacou.
    $cbs = (string) ($fonte['cbs'] ?? '');
    $f['cbs'] = in_array($cbs, ['sem', 'com'], true) ? $cbs : '';

    // Filiais: multi-seleção (`filial[]`), já recortada pelo escopo do usuário.
    $f['filial_ids'] = class_exists('FilialScope')
        ? FilialScope::idsDoFiltro($fonte['filial'] ?? null)
        : [];

    if ($exigeFlag && (string) ($fonte['f'] ?? '') !== '1') {
        return $f;
    }

    try {
        $f['data_ini'] = Sanitize::date((string) ($fonte['data_ini'] ?? ''));
        $f['data_fim'] = Sanitize::date((string) ($fonte['data_fim'] ?? ''));
    } catch (InvalidArgumentException $e) {
        $f['erro'] = 'Informe a data inicial e a data final para filtrar.';
        return $f;
    }

    // Datas invertidas são intenção óbvia — corrige em vez de recusar.
    if ($f['data_ini'] > $f['data_fim']) {
        [$f['data_ini'], $f['data_fim']] = [$f['data_fim'], $f['data_ini']];
    }

    $f['aplicado'] = true;
    return $f;
};

/**
 * Querystring que carrega o filtro entre as telas do módulo. Montada aqui,
 * a partir de valores já validados — nenhuma URL de retorno vem do POST.
 */
$qsFiltro = static function (array $f): string {
    if (!$f['aplicado']) return '';
    $qs = '&f=1&data_ini=' . urlencode($f['data_ini']) . '&data_fim=' . urlencode($f['data_fim']);
    foreach ($f['filial_ids'] as $id) {
        $qs .= '&filial%5B%5D=' . urlencode($id);
    }
    if ($f['cbs'] !== '') {
        $qs .= '&cbs=' . urlencode($f['cbs']);
    }
    return $qs;
};

switch ($action) {

    // ── GET: resumo do período + lista por CNPJ/fornecedor ────────
    case 'index':
        $filtro  = $lerFiltro($_GET);
        $filiais = FiscalNota::filiaisDoFiltro();

        $resumo       = null;
        $fornecedores = [];
        $situacoes    = [];
        $pendentesCnpj = 0;
        if ($filtro['aplicado']) {
            $resumo       = FiscalNota::resumo($filtro);
            $fornecedores = FiscalNota::porFornecedor($filtro);

            // Situação na Receita das empresas listadas: cadastro primeiro,
            // cache da consulta depois. Duas queries, não uma por linha.
            $cnpjs         = array_column($fornecedores, 'emitente_cnpj');
            $situacoes     = FiscalCnpj::mapa($cnpjs);
            $pendentesCnpj = count(FiscalCnpj::pendentes($cnpjs));
        }

        $ultimaCarga  = FiscalNota::ultimaCarga();
        $qs           = $qsFiltro($filtro);
        $pageTitle    = 'Fiscal — IBS/CBS';
        $activeModule = 'fiscal';
        $currentUser  = Auth::user();

        ob_start();
        include VIEWS . '/fiscal/index.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET: notas de um fornecedor dentro do mesmo período ───────
    case 'fornecedor':
        $filtro = $lerFiltro($_GET);
        $cnpj   = (string) preg_replace('/\D+/', '', Sanitize::fromGet(['cnpj'])['cnpj']);

        if (!$filtro['aplicado'] || strlen($cnpj) !== 14) {
            header('Location: ?module=fiscal&action=index');
            exit;
        }

        $fornecedor = FiscalNota::fornecedor($cnpj, $filtro);
        if ($fornecedor === null) {
            $_SESSION['flash'] = ['type' => 'info', 'message' => 'Nenhuma nota deste fornecedor no período filtrado.'];
            header('Location: ?module=fiscal&action=index' . $qsFiltro($filtro));
            exit;
        }

        $notas        = FiscalNota::notasDoFornecedor($cnpj, $filtro);
        $qs           = $qsFiltro($filtro);
        $pageTitle    = 'Fiscal — ' . $fornecedor['emitente_nome'];
        $activeModule = 'fiscal';
        $currentUser  = Auth::user();

        ob_start();
        include VIEWS . '/fiscal/fornecedor.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── POST: relê os XMLs do storage e materializa os totais ─────
    case 'atualizar':
        Permissions::require('fiscal', 'atualizar');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=fiscal&action=index');
            exit;
        }
        Csrf::validate(Sanitize::fromPost(['csrf_token'])['csrf_token']);

        // Acervo grande: a primeira carga lê milhares de arquivos.
        @set_time_limit(600);
        $r = FiscalNota::carregar();

        $msg = sprintf(
            'Leitura dos XMLs: %d novas, %d atualizadas, %d sem alteração.',
            $r['novos'], $r['atualizados'], $r['pulados']
        );
        if ($r['ignorados'] > 0) {
            $msg .= sprintf(' %d ignoradas (XML ilegível ou sem emitente/emissão).', $r['ignorados']);
        }
        if ($r['restantes'] > 0) {
            $msg .= sprintf(' Faltam %d — clique em Atualizar novamente.', $r['restantes']);
        }

        AuditLog::registrar('nf_fiscal_totais', Auth::id(), 'nf_fiscal.carga', null, $r);

        $_SESSION['flash'] = ['type' => $r['restantes'] > 0 ? 'warning' : 'success', 'message' => $msg];
        header('Location: ?module=fiscal&action=index' . $qsFiltro($lerFiltro($_POST)));
        exit;

    // ── POST/AJAX: consulta na Receita o PRÓXIMO CNPJ sem situação ───
    // Uma consulta por chamada, de propósito: a API pública do cnpj.ws
    // aceita 3/min por IP e a trava de 20s é global (CnpjReceita). Quem
    // percorre a fila é o navegador, chamando de novo a cada 20s — assim o
    // usuário vê o progresso e pode parar quando quiser.
    case 'consultar_situacao':
        Permissions::require('fiscal', 'atualizar');
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Método não permitido.']);
            exit;
        }
        try {
            Csrf::validate(Sanitize::fromPost(['csrf_token'])['csrf_token']);
        } catch (\RuntimeException $e) {
            http_response_code(403);
            echo json_encode(['sucesso' => false, 'mensagem' => 'Token CSRF inválido.']);
            exit;
        }

        $filtro = $lerFiltro($_POST);
        if (!$filtro['aplicado']) {
            echo json_encode(['sucesso' => false, 'mensagem' => 'Filtre um período antes de consultar.']);
            exit;
        }

        $cnpjs     = array_column(FiscalNota::porFornecedor($filtro), 'emitente_cnpj');
        $pendentes = FiscalCnpj::pendentes($cnpjs);

        if ($pendentes === []) {
            echo json_encode(['sucesso' => true, 'fim' => true, 'restantes' => 0,
                              'mensagem' => 'Todos os CNPJ do período já têm situação.']);
            exit;
        }

        $espera = CnpjReceita::esperaRestante();
        if ($espera > 0) {
            // Outra tela (ou outro usuário) consultou agora há pouco — a
            // trava é do servidor inteiro. Devolve o tempo para o navegador
            // reagendar em vez de queimar a tentativa.
            echo json_encode(['sucesso' => false, 'esperar' => $espera,
                              'restantes' => count($pendentes),
                              'mensagem' => "Aguardando {$espera}s (limite da Receita)."]);
            exit;
        }

        $cnpj = $pendentes[0];
        $r    = CnpjReceita::consultar($cnpj);

        if (!($r['sucesso'] ?? false)) {
            // Falha de limite não é falha do CNPJ: não carimba erro, só espera.
            if (isset($r['esperar'])) {
                echo json_encode(['sucesso' => false, 'esperar' => $r['esperar'],
                                  'restantes' => count($pendentes),
                                  'mensagem' => $r['erro'] ?? 'Aguardando.']);
                exit;
            }
            FiscalCnpj::registrarErro($cnpj, (string) ($r['erro'] ?? 'Falha na consulta'));
            echo json_encode(['sucesso' => false, 'cnpj' => $cnpj,
                              'restantes' => count($pendentes) - 1,
                              'mensagem' => $r['erro'] ?? 'Falha na consulta.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $onde = FiscalCnpj::gravar($cnpj, $r['dados']);
        $info = FiscalCnpj::mapa([$cnpj])[$cnpj] ?? null;

        echo json_encode([
            'sucesso'   => true,
            'cnpj'      => $cnpj,
            'onde'      => $onde,
            'badge'     => FiscalCnpj::badge($info),
            'restantes' => count($pendentes) - 1,
        ], JSON_UNESCAPED_UNICODE);
        exit;

    // ── POST: marca/desmarca conferência das notas selecionadas ───
    case 'conferir':
        Permissions::require('fiscal', 'conferir');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=fiscal&action=index');
            exit;
        }
        $post = Sanitize::fromPost(['csrf_token', 'marcar', 'cnpj']);
        Csrf::validate($post['csrf_token']);

        $filtro = $lerFiltro($_POST);
        $cnpj   = (string) preg_replace('/\D+/', '', $post['cnpj']);

        $chaves = array_map(
            static fn($c) => (string) preg_replace('/\D+/', '', (string) $c),
            is_array($_POST['chave'] ?? null) ? $_POST['chave'] : []
        );

        $conferido = $post['marcar'] !== '0';
        $n = FiscalNota::conferir($chaves, $conferido, Auth::id());

        if ($n === 0) {
            $_SESSION['flash'] = ['type' => 'info', 'message' => 'Nenhuma nota selecionada.'];
        } else {
            $texto = $n === 1
                ? ($conferido ? '1 nota marcada como conferida.' : '1 nota desmarcada.')
                : ($conferido ? "{$n} notas marcadas como conferidas." : "{$n} notas desmarcadas.");
            $_SESSION['flash'] = ['type' => 'success', 'message' => $texto];

            AuditLog::registrar('nf_fiscal_conferencia', $cnpj,
                $conferido ? 'nf_fiscal.conferida' : 'nf_fiscal.desconferida',
                null, ['notas' => $n, 'chaves' => $chaves]);
        }

        // Volta reconstruída no servidor a partir do filtro validado.
        $destino = $filtro['aplicado'] && strlen($cnpj) === 14
            ? '?module=fiscal&action=fornecedor&cnpj=' . urlencode($cnpj) . $qsFiltro($filtro)
            : '?module=fiscal&action=index' . $qsFiltro($filtro);

        header('Location: ' . $destino);
        exit;

    default:
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        exit;
}
