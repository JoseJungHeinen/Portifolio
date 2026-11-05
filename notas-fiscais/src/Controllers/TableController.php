<?php
// @versao Tab_04 V_14_30/09/2026
declare(strict_types=1);

/**
 * Controller do módulo Listas de Referência.
 * Acessado via ?module=tabelas&action=X
 */

require_once SRC . '/Models/TableList.php';

Auth::check();
Permissions::require('tabelas', 'ver');

$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

$actionsEscrita = ['nova', 'salvar', 'editar', 'atualizar', 'toggle_status', 'excluir',
                   'novo_item', 'editar_item', 'toggle_item', 'excluir_item',
                   'fornecedor_salvar', 'fornecedor_toggle', 'fornecedor_excluir',
                   'fornecedor_ws', 'modelo_salvar', 'modelo_toggle'];

if (in_array($action, $actionsEscrita, true)) {
    Permissions::require('tabelas', 'criar');
}

match ($action) {
    'index'         => tableIndex(),
    'itens'         => tableItens(),
    'nova'          => tableNova(),
    'salvar'        => tableSalvar(),
    'editar'        => tableEditar(),
    'atualizar'     => tableAtualizar(),
    'toggle_status' => tableToggleStatus(),
    'novo_item'     => tableNovoItem(),
    'editar_item'   => tableEditarItem(),
    'toggle_item'   => tableToggleItem(),
    'excluir_item'  => tableExcluirItem(),
    'excluir'       => tableExcluir(),
    'modelos'            => tableModelos(),
    'modelo_salvar'      => tableModeloSalvar(),
    'modelo_toggle'      => tableModeloToggle(),
    'fornecedores'       => tableFornecedores(),
    'fornecedor_salvar'  => tableFornecedorSalvar(),
    'fornecedor_toggle'  => tableFornecedorToggle(),
    'fornecedor_excluir' => tableFornecedorExcluir(),
    'fornecedor_ws'      => tableFornecedorWs(),
    default         => tableNotFound(),
};

// ─── INDEX ────────────────────────────────────────────────

function tableIndex(): void
{
    $get = Sanitize::fromGet(['status', 'q', 'page']);

    // Busca e aba de status valem até o usuário limpar — voltar pelo menu
    // (sem query string) reabre a tela no mesmo recorte.
    $sel = FiltroSessao::resolver('tabelas.index', [
        'status' => $get['status'],
        'q'      => trim($get['q']),
    ]);

    $filters = [
        'status' => $sel['status'],
        'q'      => $sel['q'],
        'page'   => max(1, (int)$get['page']),
    ];

    $tabelas     = TableList::list($filters);
    $total       = TableList::count($filters);
    $currentPage = $filters['page'];
    $totalPages  = (int)ceil($total / ITEMS_PER_PAGE);
    $totalAtivas   = TableList::count(['status' => 'ativo']);
    $totalInativas = TableList::count(['status' => 'inativo']);

    $pageTitle    = 'Listas';
    $activeModule = 'tabelas';
    $currentUser  = Auth::user();
    $unreadCount  = 0;
    $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/tables.css">';

    ob_start();
    include VIEWS . '/tables/list.php';
    $content = ob_get_clean();
    include VIEWS . '/layout/base.php';
}

// ─── ITENS ────────────────────────────────────────────────

function tableItens(): void
{
    $get = Sanitize::fromGet(['id', 'status', 'q', 'page']);

    try {
        $id = Sanitize::uuid($get['id']);
    } catch (InvalidArgumentException $e) {
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        return;
    }

    $lista = TableList::findById($id);
    if (!$lista) {
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        return;
    }

    // Filtro por lista: cada lista guarda o seu, senão a busca feita nos
    // Setores reapareceria ao abrir os Tipos de Nota.
    $sel = FiltroSessao::resolver('tabelas.itens.' . $id, [
        'status' => $get['status'],
        'q'      => trim($get['q']),
    ]);

    $filters = [
        'status' => $sel['status'],
        'q'      => $sel['q'],
        'page'   => max(1, (int)$get['page']),
    ];

    $itens       = TableList::getItens($id, $filters);
    $total       = TableList::countItens($id, $filters);
    $currentPage = $filters['page'];
    $totalPages  = (int)ceil($total / ITEMS_PER_PAGE);
    $totalAtivos = TableList::countItens($id, ['status' => 'ativo']);

    // Departamentos alimentam dois vínculos diferentes:
    //  · Setores      → um departamento por setor (select)
    //  · Tipos de nota → vários departamentos por tipo (checklist), que limita
    //    onde o tipo aparece na abertura do processo
    $ehTiposNota   = $id === TableList::ID_LISTA_TIPOS_NOTA;
    $departamentos = ($id === TableList::ID_SETORES || $ehTiposNota)
        ? TableList::getDepartamentosAtivos() : [];
    $marcacaoDepto = $ehTiposNota ? TableList::mapaDepartamentosDaLista($id) : [];

    // Etapa de decisão: as listas que podem governar uma ramificação. Só as
    // personalizadas entram — as de sistema (setores, filiais, etapas) não têm
    // índice, então não classificam nada.
    $listasDecisao = $id === TableList::ID_ETAPAS ? TableList::listasClassificaveis() : [];

    $pageTitle    = h($lista['nome']) . ' — Listas';
    $activeModule = 'tabelas';
    $currentUser  = Auth::user();
    $unreadCount  = 0;
    $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/tables.css">';

    ob_start();
    include VIEWS . '/tables/items.php';
    $content = ob_get_clean();
    include VIEWS . '/layout/base.php';
}

// ─── NOVA ─────────────────────────────────────────────────

function tableNova(): void
{
    $lista        = null;
    $pageTitle    = 'Nova Lista';
    $activeModule = 'tabelas';
    $currentUser  = Auth::user();
    $unreadCount  = 0;
    $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/tables.css">';

    ob_start();
    include VIEWS . '/tables/form.php';
    $content = ob_get_clean();
    include VIEWS . '/layout/base.php';
}

// ─── SALVAR (criar) ───────────────────────────────────────

function tableSalvar(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?module=tabelas&action=nova');
        exit;
    }

    Csrf::validate($_POST['csrf_token'] ?? '');
    $post = Sanitize::fromPost(['nome', 'descricao']);

    $nome = trim(strip_tags($post['nome']));
    if ($nome === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'O nome da lista é obrigatório.'];
        header('Location: ?module=tabelas&action=nova');
        exit;
    }

    $descricao = trim(strip_tags($post['descricao']));

    $id = TableList::create([
        'nome'      => $nome,
        'descricao' => $descricao !== '' ? $descricao : null,
    ]);

    AuditLog::registrar('nf_lista', $id, 'nf_lista.criada', null, ['nome' => $nome]);

    $_SESSION['flash'] = ['type' => 'success', 'message' => "Lista \"{$nome}\" criada com sucesso!"];
    header("Location: ?module=tabelas&action=itens&id={$id}");
    exit;
}

// ─── EDITAR ───────────────────────────────────────────────

function tableEditar(): void
{
    $get = Sanitize::fromGet(['id']);

    try {
        $id = Sanitize::uuid($get['id']);
    } catch (InvalidArgumentException $e) {
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        return;
    }

    $lista = TableList::findById($id);
    if (!$lista) {
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        return;
    }

    if ($lista['tipo'] === 'sistema') {
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Listas de sistema não podem ser renomeadas — gerencie apenas os itens.'];
        header("Location: ?module=tabelas&action=itens&id={$id}");
        exit;
    }

    $pageTitle    = 'Editar ' . h($lista['nome']);
    $activeModule = 'tabelas';
    $currentUser  = Auth::user();
    $unreadCount  = 0;
    $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/tables.css">';

    ob_start();
    include VIEWS . '/tables/form.php';
    $content = ob_get_clean();
    include VIEWS . '/layout/base.php';
}

// ─── ATUALIZAR ────────────────────────────────────────────

function tableAtualizar(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?module=tabelas&action=index');
        exit;
    }

    Csrf::validate($_POST['csrf_token'] ?? '');
    $post = Sanitize::fromPost(['id', 'nome', 'descricao']);

    try {
        $id = Sanitize::uuid($post['id']);
    } catch (InvalidArgumentException $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
        header('Location: ?module=tabelas&action=index');
        exit;
    }

    $lista = TableList::findById($id);
    if (!$lista) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Lista não encontrada.'];
        header('Location: ?module=tabelas&action=index');
        exit;
    }

    if ($lista['tipo'] === 'sistema') {
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Listas de sistema não podem ser alteradas.'];
        header("Location: ?module=tabelas&action=itens&id={$id}");
        exit;
    }

    $nome      = trim(strip_tags($post['nome']));
    $descricao = trim(strip_tags($post['descricao']));
    $isPreConfig = $lista['tipo'] === 'pre_configurada';

    TableList::update($id, [
        'nome'      => $isPreConfig ? $lista['nome'] : ($nome ?: $lista['nome']),
        'descricao' => $descricao !== '' ? $descricao : null,
    ]);

    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Lista atualizada com sucesso!'];
    header("Location: ?module=tabelas&action=itens&id={$id}");
    exit;
}

// ─── TOGGLE STATUS ────────────────────────────────────────

function tableToggleStatus(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?module=tabelas&action=index');
        return;
    }

    Csrf::validate($_POST['csrf_token'] ?? '');
    $post = Sanitize::fromPost(['id']);

    try {
        $id = Sanitize::uuid($post['id']);
    } catch (InvalidArgumentException $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
        header('Location: ?module=tabelas&action=index');
        return;
    }

    if (TableList::isSystem($id)) {
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Listas de sistema não podem ser desativadas.'];
        header('Location: ?module=tabelas&action=index');
        return;
    }

    TableList::toggleStatus($id);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Status alterado.'];
    header('Location: ?module=tabelas&action=index');
}

// ─── NOVO ITEM ────────────────────────────────────────────

/**
 * Departamentos marcados no formulário do item (checklist).
 * Lista vazia = item visível em todos os departamentos.
 *
 * @return list<string>
 */
function tableDepartamentosDoPost(): array
{
    $out = [];
    foreach ((array) ($_POST['departamentos'] ?? []) as $dep) {
        try { $out[] = Sanitize::uuid((string) $dep); }
        catch (InvalidArgumentException $e) {}
    }
    return $out;
}

function tableNovoItem(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?module=tabelas&action=index');
        return;
    }

    Csrf::validate($_POST['csrf_token'] ?? '');
    $post = Sanitize::fromPost(['lista_id', 'valor', 'departamento_id', 'codigo', 'cnpj']);

    try {
        $listaId = Sanitize::uuid($post['lista_id']);
    } catch (InvalidArgumentException $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
        header('Location: ?module=tabelas&action=index');
        return;
    }

    $valor = trim(strip_tags($post['valor']));
    if ($valor === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'O valor do item é obrigatório.'];
        header("Location: ?module=tabelas&action=itens&id={$listaId}");
        return;
    }

    $extra = ['indice' => tableExtrairIndice()];
    if ($post['departamento_id'] !== '') {
        try { $extra['departamento_id'] = Sanitize::uuid($post['departamento_id']); }
        catch (InvalidArgumentException $e) {}
    }
    if ($post['codigo'] !== '') {
        $extra['codigo'] = mb_substr(trim(strip_tags($post['codigo'])), 0, 20);
    }
    // Filiais: CNPJ (opcional — 14 dígitos, armazenado sem máscara)
    if ($listaId === TableList::ID_FILIAIS) {
        $cnpj = tableValidarCnpj($post['cnpj']);
        if ($cnpj === false) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CNPJ inválido — informe 14 dígitos.'];
            header("Location: ?module=tabelas&action=itens&id={$listaId}");
            return;
        }
        $extra['cnpj'] = $cnpj !== '' ? $cnpj : null;
    }
    // Etapas: atributos de fluxo
    if ($listaId === TableList::ID_ETAPAS) {
        $extra = array_merge($extra, tableExtrairAtributosEtapa());
        $extra['criado_por'] = Auth::user()['id'] ?? null;
    }
    if ($listaId === TableList::ID_LISTA_TIPOS_NOTA) {
        $extra['exige_valor'] = isset($_POST['exige_valor']) ? 1 : 0;
    }

    $id = TableList::createItem($listaId, $valor, $extra);
    if ($listaId === TableList::ID_LISTA_TIPOS_NOTA) {
        TableList::setDepartamentosDoItem($id, tableDepartamentosDoPost());
    }
    AuditLog::registrar('nf_lista_item', $id, 'nf_lista_item.criado', null, ['valor' => $valor]);

    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Item adicionado!'];
    header("Location: ?module=tabelas&action=itens&id={$listaId}");
}

// ─── EDITAR ITEM ──────────────────────────────────────────

function tableEditarItem(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?module=tabelas&action=index');
        return;
    }

    Csrf::validate($_POST['csrf_token'] ?? '');
    $post = Sanitize::fromPost(['id', 'lista_id', 'valor', 'departamento_id', 'codigo', 'cnpj']);

    try {
        $itemId  = Sanitize::uuid($post['id']);
        $listaId = Sanitize::uuid($post['lista_id']);
    } catch (InvalidArgumentException $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
        header('Location: ?module=tabelas&action=index');
        return;
    }

    $valor = trim(strip_tags($post['valor']));
    if ($valor === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'O valor do item é obrigatório.'];
        header("Location: ?module=tabelas&action=itens&id={$listaId}");
        return;
    }

    // Setores: vínculo com departamento ('' = sem vínculo → NULL) + código do centro de custo
    $extra = ['indice' => tableExtrairIndice()];
    if ($listaId === TableList::ID_SETORES) {
        $extra['departamento_id'] = null;
        if ($post['departamento_id'] !== '') {
            try { $extra['departamento_id'] = Sanitize::uuid($post['departamento_id']); }
            catch (InvalidArgumentException $e) {}
        }
        $codigo = trim(strip_tags($post['codigo']));
        $extra['codigo'] = $codigo !== '' ? mb_substr($codigo, 0, 20) : null;
    }

    // Filiais: CNPJ (opcional — 14 dígitos, armazenado sem máscara; '' → NULL)
    if ($listaId === TableList::ID_FILIAIS) {
        $cnpj = tableValidarCnpj($post['cnpj']);
        if ($cnpj === false) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CNPJ inválido — informe 14 dígitos.'];
            header("Location: ?module=tabelas&action=itens&id={$listaId}");
            return;
        }
        $extra['cnpj'] = $cnpj !== '' ? $cnpj : null;
    }

    // Etapas: atributos de fluxo
    if ($listaId === TableList::ID_ETAPAS) {
        $extra = array_merge($extra, tableExtrairAtributosEtapa());
    }
    // Tipos de nota: exige valor na abertura do processo
    if ($listaId === TableList::ID_LISTA_TIPOS_NOTA) {
        $extra['exige_valor'] = isset($_POST['exige_valor']) ? 1 : 0;
    }

    TableList::updateItem($itemId, $valor, $listaId, $extra);
    if ($listaId === TableList::ID_LISTA_TIPOS_NOTA) {
        TableList::setDepartamentosDoItem($itemId, tableDepartamentosDoPost());
    }
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Item atualizado!'];
    header("Location: ?module=tabelas&action=itens&id={$listaId}");
}

// ─── TOGGLE ITEM ──────────────────────────────────────────

function tableToggleItem(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?module=tabelas&action=index');
        return;
    }

    Csrf::validate($_POST['csrf_token'] ?? '');
    $post = Sanitize::fromPost(['id', 'lista_id']);
    $redirectFilters = $_POST['redirect_filters'] ?? [];

    try {
        $itemId  = Sanitize::uuid($post['id']);
        $listaId = Sanitize::uuid($post['lista_id']);
    } catch (InvalidArgumentException $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
        header('Location: ?module=tabelas&action=index');
        return;
    }

    TableList::toggleItemStatus($itemId, $listaId);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Status do item alterado.'];

    $qs = http_build_query(array_filter([
        'module' => 'tabelas',
        'action' => 'itens',
        'id'     => $listaId,
        'status' => $redirectFilters['status'] ?? '',
        'q'      => $redirectFilters['q'] ?? '',
        'page'   => $redirectFilters['page'] ?? '',
    ]));
    header("Location: ?{$qs}");
}

// ─── EXCLUIR ITEM ─────────────────────────────────────────

function tableExcluirItem(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?module=tabelas&action=index');
        return;
    }

    Csrf::validate($_POST['csrf_token'] ?? '');
    $post = Sanitize::fromPost(['id', 'lista_id']);

    try {
        $itemId  = Sanitize::uuid($post['id']);
        $listaId = Sanitize::uuid($post['lista_id']);
    } catch (InvalidArgumentException $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
        header('Location: ?module=tabelas&action=index');
        return;
    }

    $lista = TableList::findById($listaId);
    if (!$lista) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Lista não encontrada.'];
        header('Location: ?module=tabelas&action=index');
        return;
    }

    if ($lista['tipo'] === 'pre_configurada') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Itens de listas pré-configuradas não podem ser excluídos.'];
        header("Location: ?module=tabelas&action=itens&id={$listaId}");
        return;
    }

    // Listas de sistema (setores/filiais): bloquear exclusão se houver
    // qualquer registro no banco apontando para o item (FKs são suaves)
    if (TableList::isSystem($listaId)) {
        $uso = TableList::systemItemUso($listaId, $itemId);
        if ($uso['total'] > 0) {
            $_SESSION['flash'] = [
                'type'    => 'warning',
                'message' => 'Não é possível excluir: em uso por ' . $uso['total']
                           . ' registro(s) — ' . implode(', ', array_slice($uso['tabelas'], 0, 5))
                           . '. Desative o item em vez de excluir.',
            ];
            header("Location: ?module=tabelas&action=itens&id={$listaId}");
            return;
        }
    }

    if (TableList::deleteItem($itemId, $listaId)) {
        AuditLog::registrar('nf_lista_item', $itemId, 'nf_lista_item.excluido', null, ['lista_id' => $listaId]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Item excluído com sucesso.'];
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Item não encontrado.'];
    }
    header("Location: ?module=tabelas&action=itens&id={$listaId}");
}

// ─── EXCLUIR ──────────────────────────────────────────────

function tableExcluir(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?module=tabelas&action=index');
        return;
    }

    Csrf::validate($_POST['csrf_token'] ?? '');
    $post = Sanitize::fromPost(['id']);

    try {
        $id = Sanitize::uuid($post['id']);
    } catch (InvalidArgumentException $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
        header('Location: ?module=tabelas&action=index');
        return;
    }

    $lista = TableList::findById($id);
    if (!$lista) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Lista não encontrada.'];
        header('Location: ?module=tabelas&action=index');
        return;
    }

    if (in_array($lista['tipo'], ['pre_configurada', 'sistema'], true)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Listas pré-configuradas e de sistema não podem ser excluídas.'];
        header("Location: ?module=tabelas&action=itens&id={$id}");
        return;
    }

    if (TableList::emUso($id)) {
        // Em uso → inativar em vez de excluir
        if ($lista['ativo']) {
            TableList::toggleStatus($id);
            AuditLog::registrar('nf_lista', $id, 'nf_lista.desativada');
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Esta lista está em uso por processos ou templates. Foi inativada em vez de excluída.'];
        } else {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Esta lista está em uso e já está inativa. Não é possível excluir.'];
        }
        header("Location: ?module=tabelas&action=itens&id={$id}");
        return;
    }

    $nome = $lista['nome'];
    TableList::delete($id);
    AuditLog::registrar('nf_lista', $id, 'nf_lista.excluida', null, ['nome' => $nome]);

    $_SESSION['flash'] = ['type' => 'success', 'message' => "Lista \"{$nome}\" excluída com sucesso."];
    header('Location: ?module=tabelas&action=index');
}

// ─── FORNECEDORES ─────────────────────────────────────────

// ─── MODELOS DE VEÍCULOS (Listas › Modelos de Veículos) ───

function tableModelos(): void
{
    require_once SRC . '/Models/ModeloVeiculo.php';
    $get = Sanitize::fromGet(['q', 'marca', 'status', 'page']);

    $filters = [
        'q'      => Sanitize::text($get['q'], 100),
        'marca'  => Sanitize::text($get['marca'], 40),
        'status' => in_array($get['status'], ['ativo', 'inativo'], true) ? $get['status'] : '',
        'page'   => max(1, (int) $get['page']),
    ];

    $porPagina     = 25;
    $total         = ModeloVeiculo::contar($filters);
    $totalPages    = max(1, (int) ceil($total / $porPagina));
    $currentPage   = min($filters['page'], $totalPages);
    $modelos       = ModeloVeiculo::listar($filters, ($currentPage - 1) * $porPagina, $porPagina);
    $totalAtivos   = ModeloVeiculo::contar(['status' => 'ativo']);
    $totalInativos = ModeloVeiculo::contar(['status' => 'inativo']);
    $marcas        = ModeloVeiculo::marcas();

    $pageTitle    = 'Modelos de Veículos — Listas';
    $activeModule = 'tabelas';
    $currentUser  = Auth::user();
    $unreadCount  = 0;
    $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/tables.css">';

    ob_start();
    include VIEWS . '/tables/modelos.php';
    $content = ob_get_clean();
    include VIEWS . '/layout/base.php';
}

function tableModeloSalvar(): void
{
    require_once SRC . '/Models/ModeloVeiculo.php';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?module=tabelas&action=modelos');
        exit;
    }
    Csrf::validate($_POST['csrf_token'] ?? '');
    $post = Sanitize::fromPost(['id', 'marca', 'mvs', 'ano_modelo', 'descricao', 'combustivel', 'preco_sugerido', 'preco_vigencia', 'cores', 'ativo']);

    try {
        $id = $post['id'] !== '' ? Sanitize::uuid($post['id']) : null;
        $novoId = ModeloVeiculo::salvar($id, $post);
        AuditLog::registrar('nf_fat_modelo', $novoId, $id === null ? 'nf_fat_modelo.criado' : 'nf_fat_modelo.editado', null, ['mvs' => $post['mvs']]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => $id === null ? 'Modelo incluído.' : 'Modelo atualizado.'];
    } catch (InvalidArgumentException $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
    }
    header('Location: ?module=tabelas&action=modelos');
    exit;
}

function tableModeloToggle(): void
{
    require_once SRC . '/Models/ModeloVeiculo.php';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?module=tabelas&action=modelos');
        exit;
    }
    Csrf::validate($_POST['csrf_token'] ?? '');
    try {
        $id    = Sanitize::uuid(Sanitize::fromPost(['id'])['id']);
        $ativo = ModeloVeiculo::alternarAtivo($id);
        AuditLog::registrar('nf_fat_modelo', $id, $ativo ? 'nf_fat_modelo.ativado' : 'nf_fat_modelo.desativado', null, []);
        $_SESSION['flash'] = ['type' => 'success', 'message' => $ativo ? 'Modelo ativado.' : 'Modelo desativado — não aparece mais no pedido.'];
    } catch (InvalidArgumentException $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
    }
    header('Location: ?module=tabelas&action=modelos');
    exit;
}

function tableFornecedores(): void
{
    require_once SRC . '/Models/Supplier.php';
    $get = Sanitize::fromGet(['status', 'situacao', 'pis', 'q', 'page']);

    // Situações da Receita aceitas no filtro (+ '__sem__' = sem informação)
    $situacoesReceita = ['Ativa', 'Suspensa', 'Inapta', 'Baixada', 'Nula', '__sem__'];

    // Validado antes de guardar: o que vai para a sessão já é valor de whitelist.
    $sel = FiltroSessao::resolver('tabelas.fornecedores', [
        'status'   => in_array($get['status'], ['ativo', 'inativo'], true) ? $get['status'] : '',
        'situacao' => in_array($get['situacao'], $situacoesReceita, true) ? $get['situacao'] : '',
        'pis'      => $get['pis'] === 'pendente' ? 'pendente' : '',
        'q'        => trim($get['q']),
    ]);

    $filters = [
        'status'   => $sel['status'],
        'situacao' => $sel['situacao'],
        'pis'      => $sel['pis'],
        'q'        => $sel['q'],
        'page'     => max(1, (int) $get['page']),
    ];

    $porPagina    = ITEMS_PER_PAGE;
    $total        = Supplier::count($filters);
    $totalPages   = max(1, (int) ceil($total / $porPagina));
    $currentPage  = min($filters['page'], $totalPages);
    $fornecedores = Supplier::list($filters, ($currentPage - 1) * $porPagina, $porPagina);
    $totalAtivos  = Supplier::count(['status' => 'ativo']);
    $totalInativos = Supplier::count(['status' => 'inativo']);
    // Fila do botão "WS" — CNPJ do cadastro ainda sem situação na Receita.
    $pendentesWs  = Supplier::contarPendentesReceita();

    $pageTitle    = 'Fornecedores — Listas';
    $activeModule = 'tabelas';
    $currentUser  = Auth::user();
    $unreadCount  = 0;
    $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/tables.css">';

    ob_start();
    include VIEWS . '/tables/fornecedores.php';
    $content = ob_get_clean();
    include VIEWS . '/layout/base.php';
}

/**
 * POST/AJAX: consulta na Receita o PRÓXIMO fornecedor sem situação.
 *
 * Uma consulta por chamada, como na tela Fiscal: a API pública do cnpj.ws
 * aceita 3/min por IP e a trava de 20s é global (CnpjReceita) — o navegador
 * é quem percorre a fila, com o progresso à vista e podendo parar.
 */
function tableFornecedorWs(): void
{
    require_once SRC . '/Models/Supplier.php';
    require_once SRC . '/Models/FiscalCnpj.php';
    require_once SRC . '/Helpers/CnpjReceita.php';

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

    $pendentes = Supplier::pendentesReceita();
    if ($pendentes === []) {
        echo json_encode(['sucesso' => true, 'fim' => true, 'restantes' => 0,
                          'mensagem' => 'Todos os CNPJ do cadastro já têm situação.']);
        exit;
    }

    $espera = CnpjReceita::esperaRestante();
    if ($espera > 0) {
        // Trava do servidor inteiro: outra tela pode ter consultado agora há
        // pouco. Devolve o tempo em vez de queimar a tentativa.
        echo json_encode(['sucesso' => false, 'esperar' => $espera,
                          'restantes' => count($pendentes),
                          'mensagem' => "Aguardando {$espera}s (limite da Receita)."]);
        exit;
    }

    $cnpj = $pendentes[0];
    $r    = CnpjReceita::consultar($cnpj);

    if (!($r['sucesso'] ?? false)) {
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

    // O CNPJ veio do próprio cadastro, então gravar() atualiza `fornecedores`.
    FiscalCnpj::gravar($cnpj, $r['dados']);

    echo json_encode([
        'sucesso'   => true,
        'cnpj'      => $cnpj,
        'situacao'  => $r['dados']['situacao_cadastral'],
        'simples'   => $r['dados']['optante_simples'],
        'mei'       => $r['dados']['optante_mei'],
        'restantes' => count($pendentes) - 1,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Lê e valida o formulário do modal; devolve [erros, dados]. */
function tableFornecedorLerForm(array $post): array
{
    $erros = [];

    $razao = trim(strip_tags($post['razao_social']));
    if ($razao === '' || mb_strlen($razao) > 200) {
        $erros[] = 'Razão social é obrigatória (máx. 200 caracteres).';
    }

    $cnpj = preg_replace('/\D/', '', $post['cnpj']);
    if (!in_array(strlen($cnpj), [11, 14], true)) {
        $erros[] = 'CNPJ/CPF inválido — informe 11 ou 14 dígitos.';
    }

    // PIS/NIT — opcional no cadastro (a exigência é na abertura do processo de
    // serviço), mas se vier preenchido tem que ser um número válido.
    require_once SRC . '/Helpers/PisNit.php';
    $pis = PisNit::digitos($post['pis_nit']);
    if ($pis !== '' && !PisNit::valido($pis)) {
        $erros[] = 'PIS/NIT inválido — confira os 11 dígitos (o dígito verificador não bate).';
    }

    $estado = strtoupper(preg_replace('/[^A-Za-z]/', '', $post['estado']));
    $dataAbertura = trim($post['data_abertura']);

    return [$erros, [
        'razao_social'       => mb_substr($razao, 0, 200),
        'nome_fantasia'      => mb_substr(trim(strip_tags($post['nome_fantasia'])), 0, 200) ?: null,
        'cnpj'               => $cnpj,
        'email'              => mb_substr(trim($post['email']), 0, 150) ?: null,
        'telefone'           => mb_substr(trim($post['telefone']), 0, 20) ?: null,
        'endereco'           => mb_substr(trim(strip_tags($post['endereco'])), 0, 200) ?: null,
        'cidade'             => mb_substr(trim(strip_tags($post['cidade'])), 0, 100) ?: null,
        'estado'             => strlen($estado) === 2 ? $estado : null,
        'data_abertura'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAbertura) ? $dataAbertura : null,
        'situacao_cadastral' => mb_substr(strip_tags(trim($post['situacao_cadastral'])), 0, 30) ?: null,
        'optante_mei'        => in_array($post['optante_mei'], ['0', '1'], true) ? (int) $post['optante_mei'] : null,
        'optante_simples'    => in_array($post['optante_simples'], ['0', '1'], true) ? (int) $post['optante_simples'] : null,
        'pis_nit'            => $pis !== '' ? $pis : null,
    ]];
}

/** Cria (id vazio) ou atualiza (id preenchido) um fornecedor. */
function tableFornecedorSalvar(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?module=tabelas&action=fornecedores');
        return;
    }
    require_once SRC . '/Models/Supplier.php';

    Csrf::validate($_POST['csrf_token'] ?? '');
    $post = Sanitize::fromPost(['id', 'razao_social', 'nome_fantasia', 'cnpj', 'email', 'telefone',
                                'endereco', 'cidade', 'estado', 'data_abertura', 'situacao_cadastral',
                                'optante_mei', 'optante_simples', 'pis_nit']);

    $id = '';
    if ($post['id'] !== '') {
        try {
            $id = Sanitize::uuid($post['id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
            header('Location: ?module=tabelas&action=fornecedores');
            return;
        }
        if (!Supplier::findById($id)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Fornecedor não encontrado.'];
            header('Location: ?module=tabelas&action=fornecedores');
            return;
        }
    }

    [$erros, $dados] = tableFornecedorLerForm($post);
    if (empty($erros) && Supplier::cnpjEmUsoPorOutro($dados['cnpj'], $id)) {
        $outro   = Supplier::cnpjEmUsoPorOutro($dados['cnpj'], $id);
        $erros[] = 'CNPJ já cadastrado para "' . $outro['razao_social'] . '".';
    }
    if (empty($erros) && $dados['pis_nit'] !== null) {
        $outroPis = Supplier::pisEmUsoPorOutro($dados['pis_nit'], $id);
        if ($outroPis) {
            $erros[] = 'PIS/NIT já cadastrado para "' . $outroPis['razao_social'] . '" — o número é individual.';
        }
    }
    if (!empty($erros)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => implode(' ', $erros)];
        header('Location: ?module=tabelas&action=fornecedores');
        return;
    }

    if ($id === '') {
        $novoId = Supplier::create($dados);
        AuditLog::registrar('fornecedor', $novoId, 'fornecedor.criado', null, [
            'razao_social' => $dados['razao_social'], 'cnpj' => $dados['cnpj'],
        ]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Fornecedor "' . $dados['razao_social'] . '" cadastrado.'];
    } else {
        Supplier::update($id, $dados);
        AuditLog::registrar('fornecedor', $id, 'fornecedor.atualizado', null, [
            'razao_social' => $dados['razao_social'],
        ]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Fornecedor "' . $dados['razao_social'] . '" atualizado.'];
    }
    header('Location: ?module=tabelas&action=fornecedores');
}

function tableFornecedorToggle(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?module=tabelas&action=fornecedores');
        return;
    }
    require_once SRC . '/Models/Supplier.php';

    Csrf::validate($_POST['csrf_token'] ?? '');
    try {
        $id = Sanitize::uuid(Sanitize::fromPost(['id'])['id']);
    } catch (InvalidArgumentException $e) {
        header('Location: ?module=tabelas&action=fornecedores');
        return;
    }

    $ativo = Supplier::toggleAtivo($id);
    if ($ativo !== null) {
        AuditLog::registrar('fornecedor', $id, $ativo ? 'fornecedor.ativado' : 'fornecedor.desativado');
        $_SESSION['flash'] = ['type' => 'success', 'message' => $ativo ? 'Fornecedor ativado.' : 'Fornecedor desativado.'];
    }
    header('Location: ?module=tabelas&action=fornecedores');
}

function tableFornecedorExcluir(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?module=tabelas&action=fornecedores');
        return;
    }
    require_once SRC . '/Models/Supplier.php';

    Csrf::validate($_POST['csrf_token'] ?? '');
    try {
        $id = Sanitize::uuid(Sanitize::fromPost(['id'])['id']);
    } catch (InvalidArgumentException $e) {
        header('Location: ?module=tabelas&action=fornecedores');
        return;
    }

    $forn = Supplier::findById($id);
    if (!$forn) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Fornecedor não encontrado.'];
        header('Location: ?module=tabelas&action=fornecedores');
        return;
    }

    // Tabela compartilhada com compras: só exclui sem NENHUM vínculo no banco
    $uso = Supplier::emUso($id);
    if ($uso['total'] > 0) {
        $_SESSION['flash'] = [
            'type'    => 'warning',
            'message' => 'Não é possível excluir "' . $forn['razao_social'] . '": em uso por '
                       . $uso['total'] . ' registro(s) — ' . implode(', ', array_slice($uso['tabelas'], 0, 5))
                       . '. Desative o fornecedor em vez de excluir.',
        ];
        header('Location: ?module=tabelas&action=fornecedores');
        return;
    }

    Supplier::delete($id);
    AuditLog::registrar('fornecedor', $id, 'fornecedor.excluido', null, [
        'razao_social' => $forn['razao_social'], 'cnpj' => $forn['cnpj'],
    ]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Fornecedor "' . $forn['razao_social'] . '" excluído.'];
    header('Location: ?module=tabelas&action=fornecedores');
}

// ─── HELPERS ──────────────────────────────────────────────

/**
 * Lê e normaliza os atributos de etapa (lista Etapas) do POST.
 * SLA vem como valor + unidade (minutos/horas/dias) e é armazenado em horas.
 *
 * @return array{descricao:?string, encerra_automaticamente:int, sla_horas:?float,
 *               tipo_aprovador:string, visualizacao_restrita:int, somente_observacao:int}
 */
/**
 * Índice do item de lista — classificação usada pela etapa de decisão.
 *
 * Vazio devolve null: item sem classificação é o normal enquanto nenhuma etapa
 * de decisão apontar para a lista. Fora de 1..255 também vira null em vez de
 * gravar lixo — o TINYINT UNSIGNED não aceitaria, e um erro de banco aqui não
 * ajudaria ninguém.
 */
function tableExtrairIndice(): ?int
{
    $bruto = trim(Sanitize::fromPost(['indice'])['indice']);
    if ($bruto === '' || !ctype_digit($bruto)) {
        return null;
    }
    $n = (int) $bruto;
    return ($n >= 1 && $n <= 255) ? $n : null;
}

function tableExtrairAtributosEtapa(): array
{
    $post = Sanitize::fromPost(['descricao', 'sla_valor', 'sla_unidade', 'tipo_aprovador']);

    $descricao = trim(strip_tags($post['descricao']));

    $slaHoras = null;
    $slaValor = str_replace(',', '.', trim($post['sla_valor']));
    if ($slaValor !== '' && is_numeric($slaValor) && (float) $slaValor > 0) {
        $slaHoras = match ($post['sla_unidade']) {
            'minutos' => (float) $slaValor / 60,
            'dias'    => (float) $slaValor * 24,
            default   => (float) $slaValor,
        };
        $slaHoras = round($slaHoras, 2);
    }

    $tipoAprovador = in_array($post['tipo_aprovador'], ['grupo', 'usuario', 'ambos'], true)
        ? $post['tipo_aprovador'] : 'ambos';

    // Observação e arquivamento automático se excluem: a de arquivamento
    // encerra o processo sem aprovador, a de observação só olha. Marcar as duas
    // deixaria o comportamento na dependência da ordem de leitura — observação
    // vence, que é a mais restritiva (não encerra nada).
    $observacao = isset($_POST['somente_observacao']) ? 1 : 0;

    // Lista que governa a ramificação. Etapa vinculada a uma lista é ETAPA DE
    // DECISÃO: o template que a usar terá de mapear cada índice da lista para
    // uma etapa destino. Sem vínculo, a etapa é comum e nada muda.
    //
    // Observação e decisão se excluem: quem só observa não decide caminho —
    // o fluxo passa direto pela etapa de observação, então ela nunca chegaria
    // a avaliar a lista.
    // "Ir para": salto incondicional. Sem lista e sem aprovador — ao ser
    // atingida, manda o processo para a etapa definida no template. Exclui
    // observação (que nunca é atingida de fato) e arquivamento (que encerra o
    // processo, então não teria para onde saltar).
    $saltoLivre = (!$observacao && isset($_POST['salto_incondicional'])) ? 1 : 0;

    // "Abrir Fluxo": abre um processo de outro template e encerra o atual. Não
    // convive com salto (as duas mandam o processo embora, para lugares
    // diferentes) nem com observação.
    $abreFluxo = (!$observacao && !$saltoLivre && isset($_POST['abre_fluxo'])) ? 1 : 0;

    // Encerramento automático perde para as duas: uma etapa não pode arquivar e
    // mandar adiante ao mesmo tempo. ("Abrir Fluxo" já encerra o processo por
    // conta própria, depois de abrir o filho.)
    $encerra = (!$observacao && !$saltoLivre && !$abreFluxo && isset($_POST['encerra_automaticamente'])) ? 1 : 0;

    $decisaoLista = null;
    // Decisão exclui observação (quem só observa não decide caminho, o fluxo
    // passa direto por ela), salto e abertura de fluxo (as duas já têm destino
    // fixo — decidir e mandar embora incondicionalmente na mesma etapa é
    // contraditório) e arquivamento (etapa que encerra não tem onde ramificar).
    if (!$observacao && !$saltoLivre && !$abreFluxo && !$encerra) {
        $bruto = trim(Sanitize::fromPost(['decisao_lista_id'])['decisao_lista_id']);
        if ($bruto !== '') {
            try { $decisaoLista = Sanitize::uuid($bruto); }
            catch (InvalidArgumentException $e) { $decisaoLista = null; }
        }
    }

    return [
        'descricao'               => $descricao !== '' ? mb_substr($descricao, 0, 255) : null,
        'encerra_automaticamente' => $encerra,
        'sla_horas'               => $slaHoras,
        'tipo_aprovador'          => $tipoAprovador,
        'visualizacao_restrita'   => isset($_POST['visualizacao_restrita']) ? 1 : 0,
        'somente_observacao'      => $observacao,
        'decisao_lista_id'        => $decisaoLista,
        'salto_incondicional'     => $saltoLivre,
        'abre_fluxo'              => $abreFluxo,
    ];
}

/**
 * Valida e normaliza um CNPJ vindo do formulário de filiais.
 *
 * @return string|false  '' quando vazio (→ NULL no banco), os 14 dígitos
 *                        quando válido, ou false quando o formato é inválido.
 */
function tableValidarCnpj(string $raw)
{
    $digitos = preg_replace('/\D/', '', $raw);
    if ($digitos === '') {
        return ''; // opcional — sem CNPJ
    }
    return strlen($digitos) === 14 ? $digitos : false;
}

// ─── 404 ──────────────────────────────────────────────────

function tableNotFound(): void
{
    http_response_code(404);
    include VIEWS . '/errors/404.php';
}
