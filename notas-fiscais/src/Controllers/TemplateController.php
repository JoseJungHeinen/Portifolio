<?php
// @versao Tpl_04 V_17_01/10/2026
declare(strict_types=1);

/**
 * src/Controllers/TemplateController.php
 * Módulo de Templates de Fluxo NF
 *
 * Roteamento: ?module=templates&action=X
 * Entry point: public/index.php
 */

// ─── Autenticação obrigatória ────────────────────────────
Auth::check();
Permissions::require('templates', 'ver');

// ─── Carregar Model ──────────────────────────────────────
require_once SRC . '/Models/Template.php';
require_once SRC . '/Models/Etapa.php';
require_once SRC . '/Models/TableList.php';
// Só pela constante APROVADOR_SOLICITANTE, usada na validação das etapas —
// quem resolve esse responsável em usuário de verdade é o Process, na criação.
require_once SRC . '/Models/Process.php';

// ─── Determinar action ───────────────────────────────────
$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

// Actions de escrita exigem permissão extra
$actionsEscrita = ['criar', 'salvar', 'avancar_status', 'novo_rascunho', 'descartar_rascunho', 'excluir', 'duplicar'];
if (in_array($action, $actionsEscrita, true)) {
    Permissions::require('templates', 'criar');
}

// ─────────────────────────────────────────────────────────
// Helpers locais
// ─────────────────────────────────────────────────────────

function tpl_loadFiliais(): array {
    $pdo  = Database::getInstance();
    $stmt = $pdo->query('SELECT id, nome FROM filiais WHERE ativo = 1 ORDER BY nome');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function tpl_loadSetores(): array {
    $pdo  = Database::getInstance();
    $stmt = $pdo->query('SELECT id, nome, departamento_id FROM setores WHERE ativo = 1 ORDER BY nome');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Departamentos ativos — primeiro recorte do funil na configuração do fluxo. */
function tpl_loadDepartamentos(): array {
    require_once SRC . '/Models/TableList.php';
    return TableList::getDepartamentosAtivos();
}

function tpl_loadTiposNota(): array {
    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare(
        'SELECT i.id, i.valor AS nome
         FROM nf_lista_itens i
         JOIN nf_listas l ON l.id = i.lista_id
         WHERE l.slug = :slug AND i.ativo = 1
         ORDER BY i.valor'
    );
    $stmt->execute([':slug' => 'tipos-nota']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function tpl_loadGrupos(): array {
    $pdo  = Database::getInstance();
    $stmt = $pdo->query('SELECT id, nome FROM nf_grupos WHERE ativo = 1 ORDER BY nome');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function tpl_loadUsuarios(): array {
    $pdo  = Database::getInstance();
    $stmt = $pdo->query('SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Renderiza view dentro do layout base.
 * $vars pode incluir 'extraHead' para CSS/JS específico do módulo.
 */
function tpl_render(string $view, array $vars = []): void {
    $currentUser  = Auth::user();
    extract($vars, EXTR_SKIP);
    $pageTitle    = 'Templates';
    $activeModule = 'templates';
    $extraHead    = $vars['extraHead'] ?? '';

    ob_start();
    require VIEWS . '/' . $view . '.php';
    $content = ob_get_clean();

    require VIEWS . '/layout/base.php';
}

function tpl_isAjax(): bool {
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json';
}

function tpl_jsonError(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => $msg]);
    exit;
}

/**
 * Erro fora do fluxo normal de salvamento (parâmetros inválidos, versão não
 * encontrada, etc). Requisição AJAX recebe JSON; navegação normal do browser
 * recebe flash + redirect — nunca um JSON cru sem estilo na tela.
 */
function tpl_errorRedirect(string $msg, string $templateId = '', int $code = 400): void {
    if (tpl_isAjax()) {
        tpl_jsonError($msg, $code);
    }
    $_SESSION['flash'] = ['type' => 'danger', 'message' => $msg];
    header('Location: ' . ($templateId !== ''
        ? '?module=templates&action=editor&id=' . $templateId
        : '?module=templates&action=index'));
    exit;
}

// ─────────────────────────────────────────────────────────
// Roteamento de actions
// ─────────────────────────────────────────────────────────

switch ($action) {

    // ── GET: lista de templates ────────────────────────────
    case 'index':
        $get    = Sanitize::fromGet(['q', 'page', 'setor_id', 'tipo_nota_id', 'tem_financeiro', 'ativo']);
        $page   = max(1, (int) ($get['page'] ?: 1));
        $limit  = ITEMS_PER_PAGE;
        $offset = ($page - 1) * $limit;

        // Filtro escolhido vale até o usuário limpar — inclusive ao voltar
        // para a tela pelo menu, que não carrega query string nenhuma.
        $sel = FiltroSessao::resolver('templates.index', [
            'q'              => $get['q'],
            'setor_id'       => $get['setor_id'],
            'tipo_nota_id'   => $get['tipo_nota_id'],
            'tem_financeiro' => $get['tem_financeiro'],
            'ativo'          => $get['ativo'],
        ]);
        $busca = $sel['q'];

        $filtros = [
            'busca'          => $busca,
            'setor_id'       => $sel['setor_id'],
            'tipo_nota_id'   => $sel['tipo_nota_id'],
            'tem_financeiro' => $sel['tem_financeiro'],
            'ativo'          => $sel['ativo'],
        ];

        $templates  = Template::listAll($filtros, $offset, $limit);
        $total      = Template::countAll($filtros);
        $totalPages = (int) ceil($total / $limit);

        $setores   = tpl_loadSetores();
        $tiposNota = tpl_loadTiposNota();

        tpl_render('templates/list', compact('templates', 'total', 'totalPages', 'page', 'busca', 'filtros', 'setores', 'tiposNota'));
        break;

    // ── GET: formulário de novo template ──────────────────
    case 'novo':
        tpl_render('templates/novo');
        break;

    // ── POST: criar template ──────────────────────────────
    case 'criar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=templates&action=novo');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'O nome do template é obrigatório.'];
            header('Location: ?module=templates&action=novo');
            exit;
        }

        $currentUser = Auth::user();

        try {
            $templateId = Template::criar($nome, $currentUser['id']);
            AuditLog::registrar('nf_template', $templateId, 'criado', null, ['nome' => $nome]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Template criado. Configure as etapas e campos abaixo.'];
            header('Location: ?module=templates&action=editor&id=' . $templateId);
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro ao criar template: ' . $e->getMessage()];
            header('Location: ?module=templates&action=novo');
        }
        exit;

    // ── GET: editor de template ───────────────────────────
    case 'editor':
        $get        = Sanitize::fromGet(['id', 'versao_id', 'aba']);
        $templateId = $get['id'];
        $versaoIdGet = $get['versao_id'];

        if ($templateId === '') {
            http_response_code(400);
            echo 'Parâmetro id obrigatório.';
            exit;
        }

        $template = Template::findWithVersoes($templateId);
        if (!$template) {
            http_response_code(404);
            echo 'Template não encontrado.';
            exit;
        }

        // Versão sendo visualizada/editada
        $versaoAtual    = null;
        $somenteLeitura = false;

        if ($versaoIdGet !== '') {
            $versaoAtual = Template::findVersaoById($versaoIdGet);
            if (!$versaoAtual || $versaoAtual['template_id'] !== $templateId) {
                http_response_code(404);
                echo 'Versão não encontrada.';
                exit;
            }
            $somenteLeitura = ($versaoAtual['status'] !== 'rascunho');
        } elseif ($template['versao_rascunho']) {
            $versaoAtual = $template['versao_rascunho'];
        } elseif ($template['versao_publicada']) {
            $versaoAtual    = $template['versao_publicada'];
            $somenteLeitura = true;
        }

        $filiais        = tpl_loadFiliais();
        $setores        = tpl_loadSetores();
        $departamentos  = tpl_loadDepartamentos();
        $tiposNota      = tpl_loadTiposNota();
        $grupos         = tpl_loadGrupos();
        $todosUsuarios  = tpl_loadUsuarios();
        $historico      = Template::listVersoes($templateId);
        $etapasCatalogo = Etapa::listAtivas();

        $jsVer  = filemtime(ROOT . '/public/assets/js/modules/templates.js');
        $cssVer = filemtime(ROOT . '/public/assets/css/modules/templates.css');
        $extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/templates.css?v=' . $cssVer . '">'
                   . '<script src="' . BASE_URL . '/public/assets/js/modules/templates.js?v=' . $jsVer . '" defer></script>';

        // Confidencialidade: mora no fluxo, não no Controle de Acesso — é uma
        // característica do tipo de processo, e quem desenha o fluxo é quem
        // sabe se ele pode circular.
        $sigiloLiberados = TemplateSigilo::liberados($templateId);
        $podeGerirSigilo = Permissions::isSuperAdmin();

        tpl_render('templates/editor', compact(
            'template', 'versaoAtual', 'somenteLeitura',
            'filiais', 'setores', 'departamentos', 'tiposNota', 'grupos', 'todosUsuarios', 'historico',
            'etapasCatalogo', 'extraHead', 'sigiloLiberados', 'podeGerirSigilo'
        ));
        break;

    // ── POST AJAX: confidencialidade do fluxo ─────────────
    // Marcar/desmarcar sigilo e liberar/revogar grupos. Fica fora do form do
    // editor de propósito: não é conteúdo versionado do fluxo, é acesso — e
    // continua editável mesmo com a versão publicada (somente leitura).
    case 'sigilo':
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'msg' => 'Método inválido.']);
            exit;
        }
        try {
            Csrf::validate($_POST['csrf_token'] ?? '');
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'Sessão expirada — recarregue a página.']);
            exit;
        }
        if (!Permissions::isSuperAdmin()) {
            echo json_encode(['ok' => false, 'msg' => 'Somente o super administrador altera a confidencialidade de um fluxo.']);
            exit;
        }

        $post = Sanitize::fromPost(['op', 'template_id', 'grupo_id', 'valor', 'motivo']);
        try {
            $templateId = Sanitize::uuid($post['template_id']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['ok' => false, 'msg' => 'Template inválido.']);
            exit;
        }

        switch ($post['op']) {
            case 'marcar':
                $marcar = $post['valor'] === '1';
                TemplateSigilo::definirConfidencial($templateId, $marcar, Auth::id());
                echo json_encode([
                    'ok'  => true,
                    'msg' => $marcar
                        ? 'Fluxo marcado como confidencial — fora do fluxo, ninguém enxerga, nem quem tem visão global.'
                        : 'Confidencialidade removida deste fluxo.',
                ]);
                exit;

            case 'conceder_grupo':
            case 'revogar_grupo':
                try {
                    $grupoId = Sanitize::uuid($post['grupo_id']);
                } catch (InvalidArgumentException $e) {
                    echo json_encode(['ok' => false, 'msg' => 'Grupo inválido.']);
                    exit;
                }
                if ($post['op'] === 'conceder_grupo') {
                    TemplateSigilo::concederGrupo($templateId, $grupoId, Auth::id(), trim($post['motivo']) ?: null);
                    echo json_encode(['ok' => true, 'msg' => 'Grupo liberado para este fluxo.']);
                } else {
                    TemplateSigilo::revogarGrupo($templateId, $grupoId, Auth::id());
                    echo json_encode(['ok' => true, 'msg' => 'Liberação do grupo revogada.']);
                }
                exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'Operação inválida.']);
        exit;

    // ── POST: salvar rascunho ─────────────────────────────
    case 'salvar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=templates&action=index');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $versaoId   = trim($_POST['versao_id']   ?? '');
        $templateId = trim($_POST['template_id'] ?? '');

        if ($versaoId === '' || $templateId === '') {
            tpl_errorRedirect('Parâmetros inválidos.', $templateId);
        }

        $versao = Template::findVersaoById($versaoId);
        if (!$versao || $versao['template_id'] !== $templateId) {
            tpl_errorRedirect('Versão não encontrada.', $templateId, 404);
        }
        if ($versao['status'] !== 'rascunho') {
            tpl_errorRedirect(
                'Esta versão não é mais um rascunho (ela foi publicada ou avançada de status em outra aba). '
                . 'Recarregue a página para ver o estado atual antes de salvar.',
                $templateId
            );
        }

        // Nome do template
        $nome = trim($_POST['nome'] ?? '');
        if ($nome !== '') {
            Template::atualizarNome($templateId, $nome);
        }

        // Dados da versão
        $dados = [];
        $dados['filial_id']       = $_POST['filial_id']       ?: null;
        $dados['departamento_id'] = $_POST['departamento_id'] ?: null;
        $dados['setor_id']        = $_POST['setor_id']        ?: null;
        $dados['tipo_nota_id']   = $_POST['tipo_nota_id'] ?: null;
        $dados['valor_min']      = isset($_POST['valor_min']) && $_POST['valor_min'] !== ''
                                   ? (float) str_replace(',', '.', str_replace('.', '', $_POST['valor_min'])) : null;
        $dados['valor_max']      = isset($_POST['valor_max']) && $_POST['valor_max'] !== ''
                                   ? (float) str_replace(',', '.', str_replace('.', '', $_POST['valor_max'])) : null;
        $dados['tem_financeiro'] = isset($_POST['tem_financeiro']) ? 1 : 0;
        $dados['tem_assinatura'] = isset($_POST['tem_assinatura']) ? 1 : 0;

        // Config de assinatura — só faz sentido com o bloco ligado. Desligar o
        // toggle zera a config: religar depois recomeça do padrão, sem herdar
        // uma quantidade de signatários que ninguém lembra de ter escolhido.
        if ($dados['tem_assinatura'] === 1) {
            $cfg = Template::configAssinaturaPadrao();
            $cfg['prazo_dias'] = max(0, (int) ($_POST['assinatura_prazo_dias'] ?? 7));
            if (isset(Template::AO_EXPIRAR[$_POST['assinatura_ao_expirar'] ?? ''])) {
                $cfg['ao_expirar'] = $_POST['assinatura_ao_expirar'];
            }
            // Lista de papéis — vem serializada do editor, igual a campos/etapas
            $lista = json_decode((string) ($_POST['assinatura_signatarios'] ?? '[]'), true);
            $cfg['signatarios'] = Template::sanearSignatarios(
                json_last_error() === JSON_ERROR_NONE ? $lista : []
            );
            $dados['assinatura_config'] = $cfg;
        } else {
            $dados['assinatura_config'] = [];
        }
        $dados['permite_sem_nota'] = isset($_POST['permite_sem_nota']) ? 1 : 0;
        $dados['libera_excecao_horario'] = isset($_POST['libera_excecao_horario']) ? 1 : 0;
        $dados['tem_rateio']     = isset($_POST['tem_rateio']) ? 1 : 0;
        $dados['tem_tipo_despesa'] = isset($_POST['tem_tipo_despesa']) ? 1 : 0;
        // Obrigatoriedade só faz sentido com o bloco ligado
        $dados['tipo_despesa_obrigatorio'] =
            ($dados['tem_tipo_despesa'] === 1 && isset($_POST['tipo_despesa_obrigatorio'])) ? 1 : 0;

        foreach (['campos', 'etapas', 'tipos_anexo', 'uat_usuario_ids'] as $campo) {
            if (isset($_POST[$campo])) {
                $decoded = json_decode($_POST[$campo], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $dados[$campo] = $decoded;
                }
            }
        }

        // Provedor da assinatura: quem manda é a ETAPA escolhida no fluxo (a
        // etapa de catálogo carrega assinatura_provedor), não um campo à parte —
        // assim o provedor nunca diverge da etapa que de fato dispara o envio.
        if ($dados['tem_assinatura'] === 1 && is_array($dados['assinatura_config'] ?? null)) {
            require_once SRC . '/Models/Etapa.php';
            $provedor = 'assinafy';
            foreach (($dados['etapas'] ?? []) as $et) {
                if (!is_array($et)) { continue; }
                $cat = Etapa::resolver($et);
                if ($cat !== null && (int) ($cat['assinatura_externa'] ?? 0) === 1) {
                    $provedor = Template::provedorValido($cat['assinatura_provedor'] ?? 'assinafy');
                    break;
                }
            }
            $dados['assinatura_config']['provedor'] = $provedor;
            if ($provedor === 'clicksign') {
                $dados['assinatura_config']['signatarios'] =
                    Template::signatariosClicksign($dados['assinatura_config']['signatarios'] ?? []);
            }
        }

        // O nome do tipo de anexo é CHAVE: ele é copiado para cada anexo enviado
        // e depois comparado para saber se a exigência foi cumprida. Espaço
        // sobrando na digitação vira um nome que não casa com nada — foi o que
        // aconteceu com "Consulta CPF " no template Cadastro de Cliente.
        // Aparar aqui é o que impede o problema de nascer; Process já sabe
        // conviver com os que já existem.
        if (isset($dados['tipos_anexo']) && is_array($dados['tipos_anexo'])) {
            foreach ($dados['tipos_anexo'] as $i => $ta) {
                if (isset($ta['nome'])) {
                    $dados['tipos_anexo'][$i]['nome'] = trim((string) $ta['nome']);
                }
            }
        }

        try {
            Template::salvarRascunho($versaoId, $dados);
            AuditLog::registrar('nf_template_versao', $versaoId, 'rascunho_salvo', null, ['template_id' => $templateId]);

            if (tpl_isAjax()) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'message' => 'Salvo com sucesso.']);
                exit;
            }

            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Rascunho salvo com sucesso.'];
        } catch (Throwable $e) {
            if (tpl_isAjax()) {
                tpl_jsonError('Erro ao salvar: ' . $e->getMessage());
            }
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro ao salvar: ' . $e->getMessage()];
        }

        // 'rateio' e 'financeiro' viraram a aba única 'configuracao' em
        // 27/07/2026 — aceitos ainda para não quebrar link antigo.
        $abasValidas  = ['geral', 'etapas', 'campos', 'anexos', 'configuracao', 'rateio', 'financeiro'];
        $abaProxima   = trim($_POST['aba_proxima'] ?? '');
        $sufixoAba    = in_array($abaProxima, $abasValidas, true) ? '&aba=' . $abaProxima : '';

        header('Location: ?module=templates&action=editor&id=' . $templateId . $sufixoAba);
        exit;

    // ── POST: avançar status da versão ────────────────────
    case 'avancar_status':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=templates&action=index');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $versaoId   = trim($_POST['versao_id']   ?? '');
        $templateId = trim($_POST['template_id'] ?? '');
        $novoStatus = trim($_POST['novo_status'] ?? '');

        $statusMap = [
            'rascunho'      => ['teste_interno'],
            'teste_interno' => ['rascunho', 'uat', 'publicado'],
            'uat'           => ['rascunho', 'publicado', 'teste_interno'],
        ];

        $versao = Template::findVersaoById($versaoId);
        if (!$versao || $versao['template_id'] !== $templateId) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Versão não encontrada.'];
            header('Location: ?module=templates&action=editor&id=' . $templateId);
            exit;
        }

        $statusValidos = $statusMap[$versao['status']] ?? [];
        if (!in_array($novoStatus, $statusValidos, true)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Transição de status inválida.'];
            header('Location: ?module=templates&action=editor&id=' . $templateId);
            exit;
        }

        // Financeiro habilitado exige etapa "Aprovação Financeiro" com responsável definido
        // manualmente (grupo ou usuário fixo) — validado em toda transição pra fora de
        // rascunho, já que processos de teste também podem ser abertos a partir de teste_interno/uat.
        if (in_array($novoStatus, ['teste_interno', 'uat', 'publicado'], true)
            && !empty($versao['tem_financeiro'])
            && !Template::possuiEtapaFinanceiroValida($versao['etapas'] ?? [])
        ) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' =>
                'Este template tem o controle financeiro habilitado — é obrigatório ter uma etapa "'
                . Template::ETAPA_FINANCEIRO_NOME . '" com responsável definido manualmente (grupo ou usuário). '
                . 'Corrija na aba Etapas antes de continuar.'];
            header('Location: ?module=templates&action=editor&id=' . $templateId . '&aba=etapas');
            exit;
        }

        // Assinatura habilitada exige etapa + anexo obrigatório + campos do
        // signatário. Mesma regra do financeiro (validado em toda transição
        // para fora de rascunho, não só ao publicar), só que a assinatura
        // depende de TRÊS abas — por isso a validação devolve uma lista.
        if (in_array($novoStatus, ['teste_interno', 'uat', 'publicado'], true)
            && !empty($versao['tem_assinatura'])
        ) {
            $problemas = Template::validarAssinatura($versao);
            if (!empty($problemas)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' =>
                    'Este template exige assinatura eletrônica, mas está incompleto: '
                    . implode(' · ', $problemas)];
                header('Location: ?module=templates&action=editor&id=' . $templateId . '&aba=configuracao');
                exit;
            }
        }

        // Etapas de decisão: destino faltando ou campo errado trava o processo
        // no meio do caminho. Vale para toda saída de rascunho — processo de
        // teste nasce de teste_interno/uat e trava igual.
        if (in_array($novoStatus, ['teste_interno', 'uat', 'publicado'], true)) {
            $problemasDecisao = Template::validarDecisoes($versao);
            if (!empty($problemasDecisao)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' =>
                    'As etapas de decisão deste template estão incompletas: '
                    . implode(' · ', $problemasDecisao)];
                header('Location: ?module=templates&action=editor&id=' . $templateId . '&aba=etapas');
                exit;
            }

            // Com os destinos todos preenchidos, sobra o erro que só aparecia
            // com processo aberto: duas decisões que se devolvem uma para a
            // outra e giram sem parar em nenhuma etapa acionável. A validação
            // acima olha destino a destino e não enxerga isso — só simulando o
            // caminho, que é o que validarLacosDecisao() faz.
            $lacos = Template::validarLacosDecisao($versao);
            if (!empty($lacos)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' =>
                    'As etapas de decisão deste template entram em laço: '
                    . implode(' · ', $lacos)];
                header('Location: ?module=templates&action=editor&id=' . $templateId . '&aba=etapas');
                exit;
            }
        }

        // Etapas sem aprovador só são válidas quando encerram automaticamente
        // (etapa de arquivamento do catálogo) — senão o processo ficaria travado
        // numa etapa que não notifica ninguém.
        if (in_array($novoStatus, ['teste_interno', 'uat', 'publicado'], true)) {
            foreach (($versao['etapas'] ?? []) as $i => $et) {
                $nomeEtapa = trim((string) ($et['nome'] ?? ''));
                if ($nomeEtapa === '' && empty($et['etapa_id'])) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' =>
                        'A etapa ' . ($i + 1) . ' está sem etapa selecionada. Corrija na aba Etapas antes de continuar.'];
                    header('Location: ?module=templates&action=editor&id=' . $templateId . '&aba=etapas');
                    exit;
                }
                // Decisão, "Ir para" e "Abrir Fluxo" dispensam aprovador: quem
                // as resolve é o sistema — a primeira lendo o campo do
                // formulário, a segunda saltando direto, a terceira abrindo
                // outro processo e encerrando este.
                $catEtapa  = Etapa::resolver($et);
                $semDono   = !empty($catEtapa['decisao_lista_id'])
                          || !empty($catEtapa['salto_incondicional'])
                          || !empty($catEtapa['abre_fluxo'])
                          // "Solicitante": tem dono sim, só não dá para nomeá-lo
                          // aqui — é quem abrir o processo. Resolvido na criação.
                          || ($et['tipo_aprovador'] ?? '') === Process::APROVADOR_SOLICITANTE;
                if (empty($et['aprovador_id']) && !$semDono && !Etapa::encerraAutomaticamente($et)) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' =>
                        'A etapa "' . ($nomeEtapa !== '' ? $nomeEtapa : 'Etapa ' . ($i + 1))
                        . '" está sem responsável definido. Somente etapas de arquivamento automático dispensam aprovador. '
                        . 'Corrija na aba Etapas antes de continuar.'];
                    header('Location: ?module=templates&action=editor&id=' . $templateId . '&aba=etapas');
                    exit;
                }
            }
        }

        // Validação mínima para publicar
        // Anexos vinculados a etapas: vínculo órfão bloqueia a publicação
        if ($novoStatus === 'publicado') {
            $totalEtapas = count($versao['etapas'] ?? []);
            foreach (($versao['tipos_anexo'] ?? []) as $ta) {
                $etapaRef = (int) ($ta['etapa_ordem'] ?? 0);
                if ($etapaRef > $totalEtapas) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => sprintf(
                        'O anexo "%s" está vinculado à etapa %d, mas o template só tem %d etapa(s). Corrija na aba Anexos antes de publicar.',
                        $ta['nome'] ?? 'sem nome', $etapaRef, $totalEtapas
                    )];
                    header('Location: ?module=templates&action=editor&id=' . $templateId . '&aba=anexos');
                    exit;
                }
            }
        }

        if ($novoStatus === 'publicado' && empty($versao['etapas'])) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'O template precisa ter ao menos uma etapa para ser publicado.'];
            header('Location: ?module=templates&action=editor&id=' . $templateId);
            exit;
        }

        // Fluxo de exceção de horário: a gravação automática lê três campos com
        // nome fixo. Se faltarem, a aprovação passaria e a liberação NÃO seria
        // gravada — falha silenciosa, e a pessoa só descobre no dia, sem acesso.
        // Por isso barra na publicação, e não em produção.
        if ($novoStatus === 'publicado' && (int) ($versao['libera_excecao_horario'] ?? 0) === 1) {
            $nomes    = array_column($versao['campos'] ?? [], 'nome');
            $faltando = array_diff(['data_acesso', 'hora_inicio', 'hora_fim'], $nomes);

            if ($faltando) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' =>
                    'Este fluxo libera acesso fora do horário, então precisa dos campos '
                    . implode(', ', $faltando) . ' no formulário — é deles que a liberação é lida. '
                    . 'Adicione na aba Campos antes de publicar.'];
                header('Location: ?module=templates&action=editor&id=' . $templateId . '&aba=campos');
                exit;
            }
            if ((int) ($versao['permite_sem_nota'] ?? 0) !== 1) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' =>
                    'Este fluxo libera acesso fora do horário e precisa de "Permitir abertura sem nota fiscal" '
                    . 'marcado — solicitação de acesso não tem NF, e sem isso a Etapa 2 exigiria número e valor.'];
                header('Location: ?module=templates&action=editor&id=' . $templateId . '&aba=config');
                exit;
            }
        }

        // Avanço para UAT: salvar usuários selecionados
        if ($novoStatus === 'uat') {
            $uatUsuarioIds = json_decode($_POST['uat_usuario_ids'] ?? '[]', true);
            if (empty($uatUsuarioIds) || !is_array($uatUsuarioIds)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Selecione ao menos um usuário para o teste com usuários.'];
                header('Location: ?module=templates&action=editor&id=' . $templateId);
                exit;
            }
            Template::salvarRascunho($versaoId, ['uat_usuario_ids' => $uatUsuarioIds]);
        }

        $currentUser = Auth::user();

        // Retrocesso uat → teste_interno: limpar usuários UAT
        if ($novoStatus === 'teste_interno' && $versao['status'] === 'uat') {
            Template::salvarRascunho($versaoId, ['uat_usuario_ids' => []]);
        }

        try {
            Template::avancarStatus($versaoId, $novoStatus, $currentUser['id']);

            $labels = [
                'rascunho'      => 'voltou para rascunho — agora pode ser editado',
                'teste_interno' => 'enviado para Teste Interno',
                'uat'           => 'aberto para Teste com Usuários',
                'publicado'     => 'publicado com sucesso',
            ];
            $msg = 'Template ' . ($labels[$novoStatus] ?? $novoStatus) . '.';

            AuditLog::registrar('nf_template_versao', $versaoId, 'status_avancado',
                ['status' => $versao['status']], ['status' => $novoStatus]);

            $_SESSION['flash'] = ['type' => 'success', 'message' => $msg];
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro: ' . $e->getMessage()];
        }

        header('Location: ?module=templates&action=editor&id=' . $templateId);
        exit;

    // ── POST: criar novo rascunho ─────────────────────────
    case 'novo_rascunho':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=templates&action=index');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $templateId  = trim($_POST['template_id'] ?? '');
        $currentUser = Auth::user();

        try {
            Template::criarNovoRascunho($templateId, $currentUser['id']);
            AuditLog::registrar('nf_template', $templateId, 'novo_rascunho', null, []);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Novo rascunho criado a partir da versão publicada.'];
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro: ' . $e->getMessage()];
        }

        header('Location: ?module=templates&action=editor&id=' . $templateId);
        exit;

    // ── POST: duplicar template ─────────────────────────────
    case 'duplicar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=templates&action=index');
            exit;
        }

        Csrf::validate($_POST['csrf_token'] ?? '');

        $templateId = trim($_POST['template_id'] ?? '');
        $novoNome   = trim($_POST['novo_nome'] ?? '');

        if ($novoNome === '') {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Informe um nome para a cópia.'];
            header('Location: ?module=templates&action=index');
            exit;
        }

        try {
            $templateId = Sanitize::uuid($templateId);
            $novoNome   = Sanitize::text($novoNome, 200);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados inválidos.'];
            header('Location: ?module=templates&action=index');
            exit;
        }

        $currentUser = Auth::user();

        try {
            $novoId = Template::duplicar($templateId, $novoNome, $currentUser['id']);
            AuditLog::registrar('nf_template', $novoId, 'nf_template.duplicado', null, [
                'origem' => $templateId,
                'nome'   => $novoNome,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Template duplicado com sucesso! Edite os dados conforme necessário.'];
            header('Location: ?module=templates&action=editor&id=' . $novoId);
        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro ao duplicar: ' . $e->getMessage()];
            header('Location: ?module=templates&action=index');
        }
        exit;

    // ── GET AJAX: itens de uma fonte de lista ───────────────
    case 'listar_fonte':
        header('Content-Type: application/json; charset=utf-8');

        $fonteId = trim($_GET['fonte_id'] ?? '');
        if ($fonteId === '') {
            echo json_encode(['itens' => [], 'editavel' => false]);
            exit;
        }

        $pdo   = Database::getInstance();
        $itens = [];
        $editUrl = '';

        if ($fonteId === '_filiais') {
            $stmt = $pdo->query("SELECT nome FROM filiais WHERE ativo = 1 ORDER BY nome");
            $itens = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'nome');
        } elseif ($fonteId === '_setores') {
            $stmt = $pdo->query("SELECT nome FROM setores WHERE ativo = 1 ORDER BY nome");
            $itens = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'nome');
        } else {
            $stmt = $pdo->prepare(
                "SELECT valor AS nome FROM nf_lista_itens WHERE lista_id = ? AND ativo = 1 ORDER BY valor"
            );
            $stmt->execute([$fonteId]);
            $itens = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'nome');
            $editUrl = '?module=tabelas&action=itens&id=' . urlencode($fonteId);
        }

        echo json_encode([
            'itens'    => $itens,
            'editavel' => $editUrl !== '',
            'edit_url' => $editUrl,
            'total'    => count($itens),
        ]);
        exit;

    // ── POST: excluir template ────────────────────────────
    case 'descartar_rascunho':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=templates&action=index');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $templateId = trim($_POST['template_id'] ?? '');

        try {
            $ok = Template::descartarRascunho($templateId);
            if ($ok) {
                AuditLog::registrar('nf_template', $templateId, 'rascunho_descartado', null, []);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Rascunho descartado com sucesso.'];
            } else {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Não foi possível descartar: o rascunho possui processos vinculados.'];
            }
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro: ' . $e->getMessage()];
        }

        header('Location: ?module=templates&action=editor&id=' . $templateId);
        exit;

    case 'excluir':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=templates&action=index');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $templateId = trim($_POST['template_id'] ?? '');

        try {
            $ok = Template::excluir($templateId);
            if ($ok) {
                AuditLog::registrar('nf_template', $templateId, 'excluido', null, []);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Template excluído com sucesso.'];
                header('Location: ?module=templates&action=index');
            } else {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Não é possível excluir: template publicado ou com processos vinculados.'];
                header('Location: ?module=templates&action=editor&id=' . $templateId);
            }
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro: ' . $e->getMessage()];
            header('Location: ?module=templates&action=editor&id=' . $templateId);
        }
        exit;

    // ── POST: desativar / reativar o fluxo (só super_admin) ─
    //
    // Desativar fecha uma porta só: a de ABRIR processo novo. O que já está
    // rodando naquele fluxo segue até o fim — quem barra a abertura é
    // Process::criar(), e ele não olha para processo existente.
    case 'toggle_ativo':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=templates&action=index');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        // Gate próprio, acima do `templates.criar` do topo: montar e publicar
        // fluxo é do admin de cadastros; tirar um do ar é só do super admin.
        if (!Permissions::isSuperAdmin()) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' =>
                'Só um super admin pode desativar ou reativar um fluxo.'];
            header('Location: ?module=templates&action=index');
            exit;
        }

        $templateId = trim($_POST['template_id'] ?? '');
        $ativar     = ($_POST['ativo'] ?? '') === '1';
        $template   = Template::findById($templateId);

        if (!$template) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Template não encontrado.'];
            header('Location: ?module=templates&action=index');
            exit;
        }

        $currentUser = Auth::user();

        try {
            Template::setAtivo($templateId, $ativar, $currentUser['id']);
            AuditLog::registrar(
                'nf_template',
                $templateId,
                $ativar ? 'reativado' : 'desativado',
                ['ativo' => (int) ($template['ativo'] ?? 1)],
                ['ativo' => $ativar ? 1 : 0, 'nome' => $template['nome']]
            );

            if ($ativar) {
                $msg = 'Fluxo "' . $template['nome'] . '" reativado — já aceita processos novos.';
            } else {
                $emAndamento = Template::processosEmAndamento($templateId);
                $msg = 'Fluxo "' . $template['nome'] . '" desativado — não aceita mais processos novos.'
                     . ($emAndamento > 0
                        ? ' Os ' . $emAndamento . ' processo(s) em andamento seguem normalmente.'
                        : '');
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => $msg];
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro: ' . $e->getMessage()];
        }

        // Volta para onde a ação partiu: o editor manda `voltar=editor`, a
        // listagem não manda nada.
        $destino = ($_POST['voltar'] ?? '') === 'editor'
            ? '?module=templates&action=editor&id=' . urlencode($templateId)
            : '?module=templates&action=index';
        header('Location: ' . $destino);
        exit;

    // ── 404 ───────────────────────────────────────────────
    default:
        http_response_code(404);
        echo 'Ação não encontrada.';
        exit;
}
