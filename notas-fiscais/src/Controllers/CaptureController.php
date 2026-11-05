<?php
// @versao Cap_04 V_11_21/10/2026
declare(strict_types=1);

/**
 * Controller do módulo Notas Capturadas (integração Brobot).
 *
 * Roteamento: ?module=capturas&action=X
 * Inbox de NF-e capturadas — mostra apenas as não resolvidas.
 */

Auth::check();
Permissions::require('capturas', 'ver');

require_once SRC . '/Models/CapturedNote.php';
require_once SRC . '/Models/CaptureRule.php';
require_once SRC . '/Models/BrobotSync.php';
require_once SRC . '/Models/TableList.php';

$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

$actionsEscrita = ['vincular', 'vincular_multiplas', 'vincular_existente', 'buscar_processo', 'ignorar', 'aplicar_regras', 'sincronizar'];
if (in_array($action, $actionsEscrita, true)) {
    Permissions::require('capturas', 'vincular');
}

switch ($action) {

    // ── GET: inbox de notas capturadas ────────────────────
    case 'index':
        $get = Sanitize::fromGet(['status', 'busca', 'tipo', 'pagina', 'f', 'limpar']);

        // Botão "Limpar": zera os filtros persistidos e volta à inbox limpa.
        if ($get['limpar'] === '1') {
            unset($_SESSION['cap_filtros'], $_SESSION['cap_pagina']);
            header('Location: ?module=capturas&action=index');
            exit;
        }

        if ($get['f'] === '1') {
            // Formulário de filtro submetido (ou paginação): lê do GET e persiste.
            $filters = [
                'status' => in_array($get['status'], ['capturada','sugerida','vinculada','ignorada','todos'], true) ? $get['status'] : '',
                'tipo'   => in_array($get['tipo'], ['NFe','CTe','NFSe','CTOs'], true) ? $get['tipo'] : '',
                'busca'  => $get['busca'],
            ];
            // Lojas: multi-seleção (`filial[]`), já recortada pelo escopo do
            // usuário. Fora do fromGet, que só trata escalar — link antigo com
            // um id só continua valendo.
            $filiaisSel = FilialScope::idsDoFiltro($_GET['filial'] ?? null);
            if ($filiaisSel !== []) {
                $filters['filial_id'] = $filiaisSel;
            }
            $_SESSION['cap_filtros'] = $filters;
        } else {
            // Navegação sem submeter filtro (ex.: retorno após vincular/ignorar):
            // reutiliza o recorte persistido em sessão até o usuário clicar em Limpar.
            $filters = $_SESSION['cap_filtros'] ?? ['status' => '', 'tipo' => '', 'busca' => ''];
        }

        $porPagina    = 50;
        $totalNotas   = CapturedNote::count($filters);
        $totalPaginas = max(1, (int) ceil($totalNotas / $porPagina));

        // A página acompanha o filtro na persistência: vincular/ignorar redireciona
        // para a inbox sem querystring, e sem isso o usuário que estava na página 4
        // de um recorte voltava para a página 1 a cada nota resolvida. Clamp no
        // total atual — resolver notas encolhe a lista.
        $paginaGet = (int) ($get['pagina'] ?: 0);
        $pagina    = $paginaGet > 0 ? $paginaGet : (int) ($_SESSION['cap_pagina'] ?? 1);
        $pagina    = min(max(1, $pagina), $totalPaginas);
        $_SESSION['cap_pagina'] = $pagina;

        $notas     = CapturedNote::list($filters, ($pagina - 1) * $porPagina, $porPagina);

        // Prévia das parcelas que a vinculação vai criar a partir das duplicatas
        // do XML — o operador confere antes de abrir o processo. Só para as
        // notas ainda na fila; nota antiga aproveita a passagem para gravar o
        // cache da cobrança e não reler o XML na próxima abertura da tela.
        foreach ($notas as $i => $n) {
            $notas[$i]['parcelas_xml'] = in_array($n['status'], ['capturada', 'sugerida'], true)
                ? CapturedNote::parcelasDaNota($notas[$i])
                : [];
        }

        $totais    = CapturedNote::getTotais();
        $templates = CaptureRule::getTemplatesPublicados();
        $filiais   = CaptureRule::getFiliais();
        $departamentos = TableList::getDepartamentosAtivos();
        $brobotStatus = [
            'sync'          => BrobotSync::ultimoStatus('sync'),
            'classificacao' => BrobotSync::ultimoStatus('classificacao'),
        ];

        $pageTitle    = 'Notas Capturadas';
        $activeModule = 'capturas';
        $currentUser  = Auth::user();

        ob_start();
        include VIEWS . '/capturas/index.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET: XML da nota (inteiro; &download=1 baixa o arquivo) ──
    case 'xml':
        try {
            $id = Sanitize::uuid(Sanitize::fromGet(['id'])['id']);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            exit('ID inválido.');
        }
        $nota = CapturedNote::findById($id);
        if (!$nota) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            exit('Nota não encontrada.');
        }

        // XML autêntico do storage; preview montado só como fallback
        $xml = CapturedNote::xmlReal($nota) ?? CapturedNote::gerarXmlPreview($nota);

        if (Sanitize::fromGet(['download'])['download'] === '1') {
            $arquivo = $nota['tipo_documento'] . '_' . $nota['numero_nf'] . '_' . $nota['chave_nf'] . '.xml';
            $arquivo = preg_replace('/[^A-Za-z0-9._-]+/', '_', $arquivo);
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $arquivo . '"');
            header('Content-Length: ' . strlen($xml));
        } else {
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $xml;
        exit;

    // ── GET: DANFE/DACTE em HTML (layout oficial) — aberto no modal via iframe ──
    case 'danfe':
        try {
            $id = Sanitize::uuid(Sanitize::fromGet(['id'])['id']);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            exit('ID inválido.');
        }
        $nota = CapturedNote::findById($id);
        if (!$nota) {
            http_response_code(404);
            exit('Nota não encontrada.');
        }

        require_once SRC . '/Helpers/XmlDfeParser.php';
        require_once SRC . '/Helpers/DfeHtml.php';

        $xml = CapturedNote::xmlReal($nota);
        $det = $xml !== null ? XmlDfeParser::detalhes($xml, (string) $nota['tipo_documento']) : null;

        header('Content-Type: text/html; charset=utf-8');
        echo DfeHtml::gerar($nota, $det);
        exit;

    // ── POST: vincular nota a um processo ─────────────────
    case 'vincular':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=capturas&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'nota_id', 'template_id']);
        Csrf::validate($post['csrf_token']);

        try {
            $notaId     = Sanitize::uuid($post['nota_id']);
            $templateId = Sanitize::uuid($post['template_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados inválidos.'];
            header('Location: ?module=capturas&action=index');
            exit;
        }

        // Campos dinâmicos do template: inputs prefixados com campo_
        $campos = [];
        foreach ($_POST as $k => $v) {
            if (str_starts_with($k, 'campo_') && is_string($v)) {
                $nome = substr($k, 6);
                $campos[$nome] = strip_tags(trim($v));
            }
        }

        $user = Auth::user();

        try {
            $resultado = CapturedNote::vincular($notaId, $templateId, $campos, $user['id'], $user['id']);
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Falha ao vincular: ' . $e->getMessage()];
            header('Location: ?module=capturas&action=index');
            exit;
        }

        AuditLog::registrar('nf_nota_capturada', $notaId, 'nf_captura.vinculada', null, [
            'processo_id' => $resultado['id'],
            'template_id' => $templateId,
        ]);

        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => 'Nota vinculada! Processo ' . $resultado['codigo'] . ' criado e enviado para aprovação.',
        ];
        header('Location: ?module=capturas&action=index');
        exit;

    // ── POST: combinar 2+ notas capturadas em um único processo ───
    case 'vincular_multiplas':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=capturas&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'template_id']);
        Csrf::validate($post['csrf_token']);

        try {
            $templateId = Sanitize::uuid($post['template_id']);
            $notaIds    = array_map(
                static fn($v) => Sanitize::uuid((string) $v),
                $_POST['nota_ids'] ?? []
            );
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados inválidos.'];
            header('Location: ?module=capturas&action=index');
            exit;
        }

        $campos = [];
        foreach ($_POST as $k => $v) {
            if (str_starts_with($k, 'campo_') && is_string($v)) {
                $nome = substr($k, 6);
                $campos[$nome] = strip_tags(trim($v));
            }
        }

        $user = Auth::user();

        try {
            $resultado = CapturedNote::vincularMultiplas($notaIds, $templateId, $campos, $user['id'], $user['id']);
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Falha ao combinar notas: ' . $e->getMessage()];
            header('Location: ?module=capturas&action=index');
            exit;
        }

        AuditLog::registrar('nf_nota_capturada', implode(',', $notaIds), 'nf_captura.vinculada_multiplas', null, [
            'processo_id' => $resultado['id'],
            'template_id' => $templateId,
            'notas'       => $notaIds,
        ]);

        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => count($notaIds) . ' notas combinadas! Processo ' . $resultado['codigo'] . ' criado e enviado para aprovação.',
        ];
        header('Location: ?module=capturas&action=index');
        exit;

    // ── GET (AJAX): prévia do processo digitado no modal "processo existente" ──
    case 'buscar_processo':
        header('Content-Type: application/json; charset=utf-8');
        $codigo = (string) Sanitize::fromGet(['codigo'])['codigo'];

        $p = CapturedNote::buscarProcessoParaVinculo($codigo);
        if (!$p) {
            echo json_encode(['success' => false, 'message' => 'Processo não encontrado.']);
            exit;
        }
        echo json_encode([
            'success'        => true,
            'id'             => $p['id'],
            'codigo'         => $p['codigo'],
            'status'         => $p['status'],
            'aceita_vinculo' => $p['aceita_vinculo'],
            'fornecedor'     => $p['fornecedor_nome'] ?? '',
            'fornecedor_doc' => preg_replace('/\D+/', '', (string) ($p['fornecedor_doc'] ?? '')),
            'filial'         => $p['filial_nome'] ?? '',
            'filial_doc'     => preg_replace('/\D+/', '', (string) ($p['filial_doc'] ?? '')),
            'template'       => $p['template_nome'] ?? '',
            'etapa'          => $p['etapa_nome'] ?? '',
            'numero_nf'      => $p['numero_nf'] ?? '',
            'valor'          => (float) ($p['valor_nf'] ?? 0),
        ]);
        exit;

    // ── POST: vincular nota a um processo que já existe ───
    case 'vincular_existente':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=capturas&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'nota_id', 'processo_id', 'somar_valor']);
        Csrf::validate($post['csrf_token']);

        try {
            $notaId     = Sanitize::uuid($post['nota_id']);
            $processoId = Sanitize::uuid($post['processo_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Busque e confirme o processo antes de vincular.'];
            header('Location: ?module=capturas&action=index');
            exit;
        }

        $user = Auth::user();

        try {
            $resultado = CapturedNote::vincularExistente(
                $notaId, $processoId, $user['id'], (string) $user['nome'], $post['somar_valor'] === '1'
            );
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Falha ao vincular: ' . $e->getMessage()];
            header('Location: ?module=capturas&action=index');
            exit;
        }

        AuditLog::registrar('nf_nota_capturada', $notaId, 'nf_captura.vinculada_existente', null, [
            'processo_id' => $resultado['id'],
            'somar_valor' => $post['somar_valor'] === '1',
        ]);

        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => 'Nota vinculada ao processo ' . $resultado['codigo'] . '.',
        ];
        header('Location: ?module=capturas&action=index');
        exit;

    // ── POST: ignorar nota ────────────────────────────────
    case 'ignorar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=capturas&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'nota_id', 'motivo']);
        Csrf::validate($post['csrf_token']);

        try {
            $notaId = Sanitize::uuid($post['nota_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
            header('Location: ?module=capturas&action=index');
            exit;
        }

        $user = Auth::user();
        $ok   = CapturedNote::ignorar($notaId, $user['id'], strip_tags(trim($post['motivo'])));

        if ($ok) {
            AuditLog::registrar('nf_nota_capturada', $notaId, 'nf_captura.ignorada');
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Nota removida da fila de vinculação.'];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Nota não encontrada ou já resolvida.'];
        }
        header('Location: ?module=capturas&action=index');
        exit;

    // ── POST: rodar o motor de regras agora ───────────────
    // (em produção roda de hora em hora via cron / Agendador de Tarefas)
    case 'aplicar_regras':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=capturas&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token']);
        Csrf::validate($post['csrf_token']);

        // Limpa sugestões pendentes antes de reaplicar — o motor reavalia
        // tudo com as regras atuais (sugestões antigas não ficam presas).
        $resetadas = CapturedNote::resetarSugestoes();
        $r = CapturedNote::aplicarRegras();

        $partes = [];
        if ($resetadas      > 0) $partes[] = $resetadas . ' sugestão(ões) anterior(es) reavaliada(s)';
        if ($r['vinculadas'] > 0) $partes[] = $r['vinculadas'] . ' processo(s) aberto(s) automaticamente';
        if ($r['sugeridas']  > 0) $partes[] = $r['sugeridas'] . ' vínculo(s) sugerido(s)';
        if ($r['ambiguas']   > 0) $partes[] = $r['ambiguas'] . ' nota(s) com regras ambíguas (decisão manual)';

        $_SESSION['flash'] = [
            'type'    => empty($partes) ? 'info' : 'success',
            'message' => empty($partes)
                ? 'Nenhuma nota pendente casou com as regras ativas.'
                : 'Regras aplicadas: ' . implode(' · ', $partes) . '.',
        ];
        header('Location: ?module=capturas&action=index');
        exit;

    // ── POST: buscar na Brobot agora (manual) ─────────────
    // (em produção roda a cada 5 min via cli/brobot_sync.php)
    case 'sincronizar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=capturas&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token']);
        Csrf::validate($post['csrf_token']);

        require_once SRC . '/Helpers/BrobotClient.php';
        require_once SRC . '/Helpers/XmlDfeParser.php';

        $r = BrobotSync::run();

        $tipoFlash = match ($r['status']) {
            'ok', 'skip' => 'success',
            'parcial'    => 'warning',
            default      => 'danger',
        };
        if ($r['status'] === 'skip') {
            $tipoFlash = 'info';
        }
        $_SESSION['flash'] = ['type' => $tipoFlash, 'message' => 'NF-e / NFS-e: ' . $r['detalhe']];
        header('Location: ?module=capturas&action=index');
        exit;

    default:
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        exit;
}
