<?php
// @versao Pre_03 V_09_05/08/2026
declare(strict_types=1);

/**
 * Controller do módulo Pré-fluxo (configurador de vínculos).
 *
 * Roteamento: ?module=prefluxo&action=X
 * Regras CNPJ × CNPJ → template, com campos pré-configurados.
 */

Auth::check();
Permissions::require('prefluxo', 'ver');

require_once SRC . '/Models/CaptureRule.php';

$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

$actionsEscrita = ['salvar', 'atualizar', 'toggle', 'excluir', 'aplicar'];
if (in_array($action, $actionsEscrita, true)) {
    Permissions::require('prefluxo', 'editar');
}

/** Extrai e valida os dados comuns do formulário de regra. */
function prefluxoLerFormulario(array $post): array
{
    $erros = [];

    $nome = strip_tags(trim($post['nome']));
    if ($nome === '' || mb_strlen($nome) > 150) {
        $erros[] = 'Nome da regra é obrigatório (máx. 150 caracteres).';
    }

    $modo = in_array($post['modo'], ['sugerir', 'automatico', 'arquivar'], true)
        ? $post['modo'] : 'sugerir';

    // Fornecedor é opcional (vazio = qualquer fornecedor); se preenchido, valida.
    $fornCnpj = preg_replace('/\D/', '', $post['fornecedor_cnpj']);
    if ($fornCnpj !== '' && strlen($fornCnpj) !== 14) {
        $erros[] = 'CNPJ do fornecedor (origem) inválido — informe 14 dígitos ou deixe vazio para qualquer fornecedor.';
    }

    $destCnpj = preg_replace('/\D/', '', $post['destinatario_cnpj']);
    if (strlen($destCnpj) !== 14) {
        $erros[] = 'CNPJ da filial (destino) inválido.';
    }

    $cfop = preg_replace('/\D/', '', $post['cfop']);

    // Critérios opcionais: tipo de documento e tomador do frete (CTe)
    $tipoDocumento = in_array($post['tipo_documento'] ?? '', ['NFe', 'CTe', 'NFSe', 'CTOs'], true)
        ? $post['tipo_documento'] : null;
    $tomador = in_array($post['tomador'] ?? '', ['filial', 'terceiro'], true)
        ? $post['tomador'] : null;

    // Natureza da operação: critério CONTÉM sobre o natOp do XML
    $naturezaOperacao = mb_substr(strip_tags(trim($post['natureza_operacao'] ?? '')), 0, 60);
    $naturezaOperacao = $naturezaOperacao !== '' ? $naturezaOperacao : null;

    // Faixa de valor da nota (aceita formato BR: 1.234,56)
    $valorMin = prefluxoParseValor($post['valor_min'] ?? '');
    $valorMax = prefluxoParseValor($post['valor_max'] ?? '');
    if ($valorMin === false || $valorMax === false) {
        $erros[] = 'Valor da nota inválido — use números (ex.: 1.500,00).';
        $valorMin = $valorMax = null;
    }
    if ($valorMin !== null && $valorMax !== null && $valorMin > $valorMax) {
        $erros[] = 'Valor mínimo da nota não pode ser maior que o máximo.';
    }

    // Código dos itens (NF-e): condição + prefixos separados por vírgula (ex.: VN,VU)
    $itemCondicao = in_array($post['item_condicao'] ?? '', ['todos_iniciam', 'nenhum_inicia'], true)
        ? $post['item_condicao'] : null;
    $prefixos = [];
    foreach (preg_split('/[,\s;]+/', strtoupper(trim($post['item_prefixo'] ?? ''))) ?: [] as $p) {
        $p = preg_replace('/[^A-Z0-9]/', '', $p);
        if ($p !== '' && !in_array($p, $prefixos, true)) {
            $prefixos[] = mb_substr($p, 0, 10);
        }
    }
    $itemPrefixo = $prefixos ? mb_substr(implode(',', $prefixos), 0, 50) : null;
    if (($itemCondicao === null) !== ($itemPrefixo === null)) {
        $erros[] = 'Critério de itens: preencha a condição E os prefixos (ex.: VN,VU), ou deixe ambos vazios.';
        $itemCondicao = $itemPrefixo = null;
    }

    // No modo arquivar não abre processo — template é dispensado.
    $templateId = '';
    if ($modo !== 'arquivar') {
        try {
            $templateId = Sanitize::uuid($post['template_id']);
        } catch (InvalidArgumentException $e) {
            $erros[] = 'Selecione um template de fluxo.';
        }
    }

    // Campos pré-configurados: inputs prefixados com campo_
    $campos = [];
    foreach ($_POST as $k => $v) {
        if (str_starts_with($k, 'campo_') && is_string($v) && trim($v) !== '') {
            $campos[substr($k, 6)] = strip_tags(trim($v));
        }
    }

    return [$erros, [
        'nome'              => $nome,
        'fornecedor_cnpj'   => $fornCnpj,
        'destinatario_cnpj' => $destCnpj,
        'cfop'              => $cfop,
        'natureza_operacao' => $naturezaOperacao,
        'tipo_documento'    => $tipoDocumento,
        'tomador'           => $tomador,
        'valor_min'         => $valorMin,
        'valor_max'         => $valorMax,
        'item_condicao'     => $itemCondicao,
        'item_prefixo'      => $itemPrefixo,
        'template_id'       => $templateId,
        'campos'            => $campos,
        'modo'              => $modo,
    ]];
}

/**
 * Converte valor monetário do formulário para float.
 * '' → null · inválido → false · aceita "1.234,56", "1234.56" e "1500".
 */
function prefluxoParseValor(string $bruto): float|false|null
{
    $bruto = trim(str_replace(['R$', ' '], '', $bruto));
    if ($bruto === '') {
        return null;
    }
    // Formato BR: remove separador de milhar e troca vírgula decimal por ponto
    if (str_contains($bruto, ',')) {
        $bruto = str_replace('.', '', $bruto);
        $bruto = str_replace(',', '.', $bruto);
    }
    if (!is_numeric($bruto) || (float) $bruto < 0) {
        return false;
    }
    return round((float) $bruto, 2);
}

switch ($action) {

    // ── GET: lista de regras ──────────────────────────────
    case 'index':
        // Filtros da lista (selects Origem / Destino / Template)
        $g = Sanitize::fromGet(['origem', 'destino', 'template', 'status']);

        // A seleção sobrevive à saída da tela: volta igual até o usuário limpar.
        $g = FiltroSessao::resolver('prefluxo.index', $g);

        $filtros = [
            'origem'   => $g['origem'] === '__qualquer__' ? '__qualquer__' : preg_replace('/\D/', '', $g['origem']),
            'destino'  => preg_replace('/\D/', '', $g['destino']),
            'template' => '',
            // Padrão: só regras ativas — as desativadas ficam a um clique no select.
            'status'   => in_array($g['status'], ['ativas', 'inativas', 'todas'], true) ? $g['status'] : 'ativas',
        ];
        if ($g['template'] === '__sem__') {
            $filtros['template'] = '__sem__';
        } elseif ($g['template'] !== '') {
            try {
                $filtros['template'] = Sanitize::uuid($g['template']);
            } catch (InvalidArgumentException $e) {
                $filtros['template'] = '';
            }
        }

        $regras    = CaptureRule::list($filtros);
        $opcoes    = CaptureRule::getOpcoesFiltro();
        $templates = CaptureRule::getTemplatesPublicados();

        // Colisões da última aplicação de regra (action=aplicar) — consumidas
        // uma única vez: a view abre o modal e a sessão é limpa.
        $ambiguidades = $_SESSION['prefluxo_ambiguidades'] ?? [];
        unset($_SESSION['prefluxo_ambiguidades']);

        $pageTitle    = 'Pré-fluxo';
        $activeModule = 'prefluxo';
        $currentUser  = Auth::user();

        ob_start();
        include VIEWS . '/prefluxo/index.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET: nova regra / editar regra ────────────────────
    case 'novo':
    case 'detalhe':
        $regra = null;
        if ($action === 'detalhe') {
            try {
                $id = Sanitize::uuid(Sanitize::fromGet(['id'])['id']);
            } catch (InvalidArgumentException $e) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID de regra inválido.'];
                header('Location: ?module=prefluxo&action=index');
                exit;
            }
            $regra = CaptureRule::findById($id);
            if (!$regra) {
                http_response_code(404);
                include VIEWS . '/errors/404.php';
                exit;
            }
        }

        $templates = CaptureRule::getTemplatesPublicados();
        $filiais   = CaptureRule::getFiliais();

        $pageTitle    = $regra ? 'Editar Regra' : 'Nova Regra';
        $activeModule = 'prefluxo';
        $currentUser  = Auth::user();

        ob_start();
        include VIEWS . '/prefluxo/form.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET: duplicar regra (abre form de NOVA pré-preenchido) ──
    case 'duplicar':
        try {
            $id = Sanitize::uuid(Sanitize::fromGet(['id'])['id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID de regra inválido.'];
            header('Location: ?module=prefluxo&action=index');
            exit;
        }
        $regra = CaptureRule::findById($id);
        if (!$regra) {
            http_response_code(404);
            include VIEWS . '/errors/404.php';
            exit;
        }

        // Vira rascunho de regra NOVA: perde o id e ganha nome de cópia.
        // O save passa pelo fluxo normal de 'salvar' (validação + auditoria).
        $duplicandoDe = $regra['nome'];
        unset($regra['id']);
        $regra['nome'] = mb_substr('Cópia de ' . $regra['nome'], 0, 150);
        $duplicando    = true;

        $templates = CaptureRule::getTemplatesPublicados();
        $filiais   = CaptureRule::getFiliais();

        $pageTitle    = 'Duplicar Regra';
        $activeModule = 'prefluxo';
        $currentUser  = Auth::user();

        ob_start();
        include VIEWS . '/prefluxo/form.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── POST: criar regra ─────────────────────────────────
    case 'salvar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=prefluxo&action=novo');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'nome', 'fornecedor_cnpj', 'destinatario_cnpj', 'cfop', 'natureza_operacao', 'tipo_documento', 'tomador', 'valor_min', 'valor_max', 'item_condicao', 'item_prefixo', 'template_id', 'modo']);
        Csrf::validate($post['csrf_token']);

        [$erros, $dados] = prefluxoLerFormulario($post);
        if (!empty($erros)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => implode(' ', $erros)];
            header('Location: ?module=prefluxo&action=novo');
            exit;
        }

        $user   = Auth::user();
        $novoId = CaptureRule::create($dados, $user['id']);

        AuditLog::registrar('nf_captura_regra', $novoId, 'nf_captura_regra.criada', null, [
            'nome' => $dados['nome'], 'modo' => $dados['modo'],
        ]);

        $modosLabel = ['sugerir' => 'Sugerir', 'automatico' => 'Automático', 'arquivar' => 'Arquivar'];
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Regra criada em modo "' . $modosLabel[$dados['modo']] . '".'];
        header('Location: ?module=prefluxo&action=index');
        exit;

    // ── POST: atualizar regra ─────────────────────────────
    case 'atualizar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=prefluxo&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'id', 'nome', 'fornecedor_cnpj', 'destinatario_cnpj', 'cfop', 'natureza_operacao', 'tipo_documento', 'tomador', 'valor_min', 'valor_max', 'item_condicao', 'item_prefixo', 'template_id', 'modo']);
        Csrf::validate($post['csrf_token']);

        try {
            $id = Sanitize::uuid($post['id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
            header('Location: ?module=prefluxo&action=index');
            exit;
        }

        if (!CaptureRule::findById($id)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Regra não encontrada.'];
            header('Location: ?module=prefluxo&action=index');
            exit;
        }

        [$erros, $dados] = prefluxoLerFormulario($post);
        if (!empty($erros)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => implode(' ', $erros)];
            header('Location: ?module=prefluxo&action=detalhe&id=' . $id);
            exit;
        }

        CaptureRule::update($id, $dados);
        AuditLog::registrar('nf_captura_regra', $id, 'nf_captura_regra.atualizada');

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Regra atualizada.'];
        header('Location: ?module=prefluxo&action=index');
        exit;

    // ── POST: ativar / desativar ──────────────────────────
    case 'toggle':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=prefluxo&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'id']);
        Csrf::validate($post['csrf_token']);

        try {
            $id = Sanitize::uuid($post['id']);
        } catch (InvalidArgumentException $e) {
            header('Location: ?module=prefluxo&action=index');
            exit;
        }

        $ativo = CaptureRule::toggleAtivo($id);
        if ($ativo !== null) {
            AuditLog::registrar('nf_captura_regra', $id, $ativo ? 'nf_captura_regra.ativada' : 'nf_captura_regra.desativada');
            $_SESSION['flash'] = ['type' => 'success', 'message' => $ativo ? 'Regra ativada.' : 'Regra desativada.'];
        }
        header('Location: ?module=prefluxo&action=index');
        exit;

    // ── POST: aplicar só esta regra às notas pendentes ────
    case 'aplicar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=prefluxo&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'id']);
        Csrf::validate($post['csrf_token']);

        try {
            $id = Sanitize::uuid($post['id']);
        } catch (InvalidArgumentException $e) {
            header('Location: ?module=prefluxo&action=index');
            exit;
        }

        $regra = CaptureRule::findById($id);
        if (!$regra) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Regra não encontrada.'];
            header('Location: ?module=prefluxo&action=index');
            exit;
        }
        if ((int) $regra['ativo'] !== 1 || (int) $regra['precisa_revisao'] === 1) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Regra inativa ou aguardando revisão — ative/revise antes de aplicar.'];
            header('Location: ?module=prefluxo&action=index');
            exit;
        }

        require_once SRC . '/Models/CapturedNote.php';

        // Reavalia as sugestões DESTA regra (as das demais ficam intactas)
        // e roda o motor filtrado — o match considera todas as regras ativas,
        // então nota ambígua continua indo para a decisão manual.
        $resetadas = CapturedNote::resetarSugestoes($id);
        $r         = CapturedNote::aplicarRegras($id);

        AuditLog::registrar('nf_captura_regra', $id, 'nf_captura_regra.aplicada', null, [
            'sugeridas' => $r['sugeridas'], 'vinculadas' => $r['vinculadas'],
            'arquivadas' => $r['arquivadas'], 'ambiguas' => $r['ambiguas'],
        ]);

        // Detalhe das colisões → modal na lista (consumido no case 'index')
        if (!empty($r['ambiguidades'])) {
            $_SESSION['prefluxo_ambiguidades'] = $r['ambiguidades'];
        }

        $partes = [];
        if ($r['sugeridas']  > 0) $partes[] = $r['sugeridas'] . ' nota(s) sugerida(s)';
        if ($r['vinculadas'] > 0) $partes[] = $r['vinculadas'] . ' processo(s) aberto(s)';
        if ($r['arquivadas'] > 0) $partes[] = $r['arquivadas'] . ' nota(s) arquivada(s)';
        if ($r['ambiguas']   > 0) $partes[] = $r['ambiguas'] . ' ambígua(s) (decisão manual)';

        $_SESSION['flash'] = $partes
            ? ['type' => 'success', 'message' => 'Regra "' . $regra['nome'] . '" aplicada: ' . implode(', ', $partes) . '.']
            : ['type' => 'info', 'message' => 'Regra "' . $regra['nome'] . '" aplicada: nenhuma nota pendente casou com os critérios.'];
        header('Location: ?module=prefluxo&action=index');
        exit;

    // ── POST: excluir regra ───────────────────────────────
    case 'excluir':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=prefluxo&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'id']);
        Csrf::validate($post['csrf_token']);

        try {
            $id = Sanitize::uuid($post['id']);
        } catch (InvalidArgumentException $e) {
            header('Location: ?module=prefluxo&action=index');
            exit;
        }

        if (CaptureRule::delete($id)) {
            AuditLog::registrar('nf_captura_regra', $id, 'nf_captura_regra.excluida');
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Regra excluída. Sugestões pendentes voltaram para a fila manual.'];
        }
        header('Location: ?module=prefluxo&action=index');
        exit;

    default:
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        exit;
}
