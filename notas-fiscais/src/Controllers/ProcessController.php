<?php
// @versao Proc_07 V_43_05/10/2026
declare(strict_types=1);

/**
 * Controller do módulo Processos de NF.
 *
 * Roteamento: ?module=processos&action=X
 * Entry point: public/index.php
 *
 * Actions disponíveis:
 *   index           — lista com filtros e paginação
 *   pesquisar       — busca dedicada, sem pré-carregar lista (só sob demanda)
 *   novo            — step1 do wizard (seleção de template)
 *   buscar_template — AJAX: retorna templates compatíveis
 *   step2           — GET: formulário da NF | POST: salva sessão
 *   step3           — GET: revisão final
 *   submeter        — POST: cria processo e redireciona
 *   detalhe         — GET: visualização do processo
 *   aprovar         — POST: aprova etapa atual
 *   devolver        — POST: devolve processo
 *   cancelar        — POST: cancela processo
 *   download_anexo  — GET: serve arquivo com headers corretos
 *   buscar_fornecedor — AJAX: autocomplete de fornecedores
 */

// ─── Autenticação obrigatória ────────────────────────────
Auth::check();
Permissions::require('processos', 'ver');

// ─── Carregar Model ──────────────────────────────────────
require_once SRC . '/Models/Process.php';
require_once SRC . '/Models/CapturedNote.php';
require_once SRC . '/Models/TableList.php';
require_once SRC . '/Helpers/Upload.php';

// ─── Determinar action ───────────────────────────────────
$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

// Actions de escrita exigem permissão extra.
// Exceção: 'submeter' em modo edição (wizard carregado a partir de um processo
// existente) é gated pela permissão de edição/dono lá no fluxo, não por 'criar'.
$actionsEscrita = ['submeter', 'aprovar', 'devolver', 'cancelar'];
if (in_array($action, $actionsEscrita, true)) {
    $submeterEdicao = $action === 'submeter' && !empty($_SESSION['wizard_processos']['edit_processo_id']);
    if (!$submeterEdicao) {
        Permissions::require('processos', 'criar');
    }
}

// Usuário autenticado
$currentUser = Auth::user();
$usuarioId   = $currentUser['id'];

// Modo embutido (iframe dentro de modal — ex: visualizador do Financeiro).
// Propagado via querystring em todos os formulários de detalhe.php para
// sobreviver a redirects pós-POST (aprovar, devolver, observação, etc.).
$embed   = ($_GET['embed'] ?? '') === '1';
$embedQS = $embed ? '&embed=1' : '';

// Origem/voltar do botão "Voltar" do detalhe — mesma estratégia do $embedQS:
// propagado via querystring em todos os formulários de detalhe.php para
// sobreviver a redirects pós-POST e sempre retornar para a tela (e filtros)
// de onde o usuário veio.
$origemGet = (string) ($_GET['origem'] ?? '');
$voltarGet = (string) ($_GET['voltar'] ?? '');
$backQS    = '';
if ($origemGet !== '') { $backQS .= '&origem=' . urlencode($origemGet); }
if ($voltarGet !== '') { $backQS .= '&voltar=' . urlencode($voltarGet); }

// Destino depois de concluir uma ação de fila (aprovar/devolver): volta para a
// tela de onde o usuário veio, com filtros e página preservados. Sem origem
// declarada, o padrão continua sendo Minhas Tarefas — que é de onde a maioria
// das aprovações parte. resolverVoltarProcesso() já barra destino externo.
$destinoPosAcao = ($origemGet !== '' || $voltarGet !== '')
    ? resolverVoltarProcesso($origemGet, $voltarGet)['url']
    : '?module=tarefas&action=index';

// ─────────────────────────────────────────────────────────
// Roteamento de actions
// ─────────────────────────────────────────────────────────

switch ($action) {

    // ── GET: lista de processos ───────────────────────────
    case 'index':
        $get      = Sanitize::fromGet(['q', 'status', 'fluxo_id', 'departamento_id', 'setor_id', 'page', 'testes']);
        $busca    = $get['q'];
        $status   = $get['status']   ?: 'todos';
        // Lojas: multi-seleção (`filial_id[]`) — fica fora do fromGet, que só
        // lida com escalar. Links antigos com um id só continuam valendo.
        $filialId = FilialScope::idsDoFiltro($_GET['filial_id'] ?? null);
        $fluxoId  = $get['fluxo_id'];
        $deptoId  = $get['departamento_id'];
        $setorId  = $get['setor_id'];
        $pagina   = max(1, (int) ($get['page'] ?: 1));
        $offset   = ($pagina - 1) * ITEMS_PER_PAGE;
        $is_teste = (Auth::perfil() === 'super_admin') && ($get['testes'] === '1');

        // Sem processos.ver_todos, só enxerga processos que participa —
        // ver ProcessAccess / migration 018.
        $verTodos = Permissions::can('processos', 'ver_todos');

        // Multi-select Responsável: resp[] = "grupo:<uuid>" | "usuario:<uuid>"
        $respIds = [];
        foreach ((array) ($_GET['resp'] ?? []) as $v) {
            if (preg_match('/^(grupo|usuario):([0-9a-fA-F-]{36})$/', trim((string) $v), $m)) {
                try {
                    $respIds[] = ['tipo' => strtolower($m[1]), 'id' => Sanitize::uuid($m[2])];
                } catch (InvalidArgumentException $e) {}
            }
        }

        $filtros = [
            'busca'           => $busca,
            'status'          => $status,
            'filial_id'       => $filialId,
            'fluxo_id'        => $fluxoId,
            'departamento_id' => $deptoId,
            'setor_id'        => $setorId,
            'testes'          => $is_teste,
        ];
        if (!empty($respIds)) {
            $filtros['resp_ids'] = $respIds;
        }
        if (!$verTodos) {
            $filtros['restringir_usuario'] = $usuarioId;
        }

        $processos = Process::listAll($filtros, $offset, ITEMS_PER_PAGE);
        $total     = Process::countAll($filtros);
        $totalPgs  = (int) ceil($total / ITEMS_PER_PAGE);

        $gruposIds = Process::getGruposDoUsuario($usuarioId);
        $totais    = Process::getTotais($usuarioId, $gruposIds, $is_teste, !$verTodos);

        // Filiais para o select de filtro
        $filiais = Process::getFiliais();
        // Responsáveis para o multi-select (respeita o escopo do usuário)
        $responsaveis = Process::getResponsaveis($verTodos ? null : $usuarioId, $is_teste);
        // Fluxo / Departamento / Setor — o fluxo só lista templates que já têm
        // processos visíveis ao usuário; setor carrega o departamento para a
        // cascata Departamento → Setor na tela.
        require_once SRC . '/Models/TableList.php';
        $fluxos        = Process::getFluxos($verTodos ? null : $usuarioId, $is_teste);
        $departamentos = TableList::getDepartamentosAtivos();
        $setores       = Process::getSetores();

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $pageTitle    = 'Processos';
        $activeModule = 'processos';
        $unreadCount  = 0;
        $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/processos.css">';

        ob_start();
        include VIEWS . '/processos/list.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET: pesquisa dedicada de processos — não carrega nada até o
    //         usuário efetivamente buscar (sem lista pré-carregada) ──
    // ── GET: acompanhamento das vendas com entrega futura (migration 071) ──
    //     Com ?id= abre a venda e suas remessas; sem id, lista.
    case 'entrega_futura':
        require_once SRC . '/Models/EntregaFutura.php';
        $efGet      = Sanitize::fromGet(['id', 'status', 'q']);
        $efStatus   = $efGet['status'] !== '' ? $efGet['status'] : 'pendente';
        $efBusca    = $efGet['q'];
        $efVenda    = null;
        $efRemessas = [];
        if ($efGet['id'] !== '') {
            try { $efVenda = EntregaFutura::findById(Sanitize::uuid($efGet['id'])); } catch (InvalidArgumentException $e) {}
            if (!$efVenda) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Venda não encontrada.'];
                header('Location: ?module=processos&action=entrega_futura');
                exit;
            }
            $efRemessas = EntregaFutura::remessas($efVenda['id']);
        }
        $efLista = $efVenda ? [] : EntregaFutura::listar($efStatus === 'todos' ? '' : $efStatus, $efBusca);

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $pageTitle    = 'Entrega Futura';
        $activeModule = 'entrega_futura';
        $unreadCount  = 0;
        $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/processos.css">';

        ob_start();
        include VIEWS . '/processos/entrega_futura.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── POST: exclui uma remessa lançada por engano (recalcula o status) ──
    case 'entrega_futura_excluir_remessa':
        Permissions::require('processos', 'editar');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=processos&action=entrega_futura');
            exit;
        }
        Csrf::validate((string) ($_POST['csrf_token'] ?? ''));
        require_once SRC . '/Models/EntregaFutura.php';
        $remId     = '';
        $entregaId = null;
        try {
            $remId     = Sanitize::uuid((string) ($_POST['remessa_id'] ?? ''));
            $entregaId = EntregaFutura::excluirRemessa($remId);
        } catch (InvalidArgumentException $e) {}
        if ($entregaId) {
            AuditLog::registrar('nf_entrega_futura', $entregaId, 'entrega_futura.remessa_excluida', null, ['remessa_id' => $remId]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Remessa excluída.'];
            header('Location: ?module=processos&action=entrega_futura&id=' . $entregaId);
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Remessa não encontrada.'];
            header('Location: ?module=processos&action=entrega_futura');
        }
        exit;

    case 'pesquisar':
        $get   = Sanitize::fromGet(['q']);
        $busca = trim($get['q']);

        // Filtro opcional de campos (botão de filtro na tela) — nenhum
        // marcado = busca em todos os campos (comportamento de sempre).
        // Whitelist inline: só passa pro model o que a tela realmente oferece.
        $camposBuscaValidos = ['nf', 'valor', 'cnpj', 'placa'];
        $camposBusca = array_values(array_intersect((array) ($_GET['campos'] ?? []), $camposBuscaValidos));

        $processos = null;
        $total     = 0;
        $notasCapturadas = [];

        if ($busca !== '' && mb_strlen($busca) >= 2) {
            // A pesquisa dedicada é busca por código/NF/CNPJ: precisa achar também
            // o que já foi finalizado ou encerrado (que a listagem esconde por padrão).
            $filtrosBusca = ['busca' => $busca, 'busca_campos' => $camposBusca, 'incluir_finalizados' => true];
            if (!Permissions::can('processos', 'ver_todos')) {
                $filtrosBusca['restringir_usuario'] = $usuarioId;
            }
            $processos = Process::listAll($filtrosBusca, 0, 50);
            $total     = Process::countAll($filtrosBusca);

            // Notas capturadas que casam com a busca mas ainda NÃO viraram
            // processo (inclui arquivadas/ignoradas por regra) — para não
            // "sumirem" da pesquisa só por terem sido filtradas na captura.
            if (Permissions::can('capturas', 'ver')) {
                $notasCapturadas = CapturedNote::list(
                    ['busca' => $busca, 'status' => 'todos', 'sem_processo' => true],
                    0,
                    20
                );
            }
        }

        $pageTitle    = 'Pesquisar Processos';
        $activeModule = 'pesquisar_processos';
        $unreadCount  = 0;
        $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/processos.css">';

        ob_start();
        include VIEWS . '/processos/pesquisa.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET: step1 do wizard ──────────────────────────────
    case 'novo':
        Permissions::require('processos', 'criar');

        // Limpar sessão anterior do wizard
        unset($_SESSION['wizard_processos']);

        $filiais   = Process::getFiliais();
        $setores   = Process::getSetores();
        $tiposNota = Process::getListaItens('tipos-nota');

        // Departamentos para a cascata Filial → Departamento → Setor
        require_once SRC . '/Models/TableList.php';
        $departamentos = TableList::getDepartamentosAtivos();
        // Marcação Tipo de Nota × Departamento: o tipo só aparece nos
        // departamentos marcados. Tipo sem marcação vale para todos.
        $tipoNotaDeptos = TableList::mapaDepartamentosDaLista(TableList::ID_LISTA_TIPOS_NOTA);

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $pageTitle    = 'Novo Processo — Etapa 1';
        $activeModule = 'novo_processo';
        $unreadCount  = 0;
        $jsVer  = filemtime(ROOT . '/public/assets/js/modules/processos.js');
        $cssVer = filemtime(ROOT . '/public/assets/css/modules/processos.css');
        $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/processos.css?v=' . $cssVer . '">'
                      . '<script src="' . BASE_URL . '/public/assets/js/modules/processos.js?v=' . $jsVer . '" defer></script>';

        ob_start();
        include VIEWS . '/processos/novo/step1.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET AJAX: buscar templates compatíveis ────────────
    case 'buscar_template':
        header('Content-Type: application/json; charset=utf-8');

        $get        = Sanitize::fromGet(['filial_id', 'setor_id', 'tipo_nota_id', 'valor', 'departamento_id']);
        $filialId   = $get['filial_id'];
        $setorId    = $get['setor_id'];
        $tipoNotaId = $get['tipo_nota_id'];
        $deptoId    = $get['departamento_id'];
        // O JS do step1 já envia com ponto decimal (237.00). Só aplicar a
        // conversão pt-BR quando houver vírgula — senão "237.00" viraria 23700.
        $rawValor = $get['valor'] ?: '0';
        if (str_contains($rawValor, ',')) {
            $rawValor = str_replace(',', '.', str_replace('.', '', $rawValor));
        }
        $valor = (float) $rawValor;

        if (empty($filialId) || empty($setorId) || empty($tipoNotaId)) {
            echo json_encode(['success' => false, 'message' => 'Filial, departamento e tipo de nota são obrigatórios.', 'templates' => []]);
            exit;
        }

        try {
            $filialId   = Sanitize::uuid($filialId);
            $setorId    = Sanitize::uuid($setorId);
            $tipoNotaId = Sanitize::uuid($tipoNotaId);
            $deptoId    = $deptoId !== '' ? Sanitize::uuid($deptoId) : '';
        } catch (InvalidArgumentException $e) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.', 'templates' => []]);
            exit;
        }

        $templates = Process::buscarTemplatesCompatíveis($filialId, $setorId, $tipoNotaId, $valor, $deptoId);

        // Guardar TODAS as opções compatíveis (whitelist para selecionar_template)
        // e pré-selecionar a primeira (mais específica) como padrão.
        if (!empty($templates)) {
            $montarWizard = function (array $tpl) use ($filialId, $setorId, $tipoNotaId, $valor, $deptoId): array {
                return [
                    'filial_id'          => $filialId,
                    'setor_id'           => $setorId,
                    'tipo_nota_id'       => $tipoNotaId,
                    // Guardado para a recusa por faixa de valor conseguir
                    // sugerir o fluxo certo com o mesmo recorte da busca.
                    'departamento_id'    => $deptoId,
                    'valor_aprox'        => $valor,
                    // Decidido no cadastro do tipo de nota; o step2 lê daqui em
                    // vez de reconsultar, e a validação do servidor usa o mesmo.
                    'tipo_nota_exige_valor' => TableList::tipoNotaExigeValor($tipoNotaId),
                    'template_versao_id' => $tpl['id'],
                    'template_nome'      => $tpl['nome'],
                    'campos_json'        => $tpl['campos'],
                    'etapas_json'        => $tpl['etapas'],
                    'tipos_anexo'        => $tpl['tipos_anexo'],
                    'tem_financeiro'     => (bool) $tpl['tem_financeiro'],
                    'permite_sem_nota'   => (bool) ($tpl['permite_sem_nota'] ?? false),
                    'tem_rateio'         => (bool) ($tpl['tem_rateio'] ?? false),
                    'tem_tipo_despesa'   => (bool) ($tpl['tem_tipo_despesa'] ?? false),
                    'tipo_despesa_obrigatorio' => (bool) ($tpl['tipo_despesa_obrigatorio'] ?? false),
                    // Fluxo de solicitação de acesso: não tem nota nem fornecedor
                    // de verdade. O step2 esconde os dois blocos e o servidor
                    // preenche sozinho (migration 043).
                    'libera_excecao_horario' => (bool) ($tpl['libera_excecao_horario'] ?? false),
                    'is_teste'           => $tpl['status'] !== 'publicado',
                    'template_versao_status' => $tpl['status'],
                    'dados_step2'        => [],
                ];
            };

            $opcoes = [];
            foreach ($templates as $tpl) {
                $opcoes[$tpl['id']] = $montarWizard($tpl);
            }
            $_SESSION['wizard_processos_opcoes'] = $opcoes;
            $_SESSION['wizard_processos']        = $opcoes[$templates[0]['id']];
        }

        echo json_encode([
            'success'   => true,
            'templates' => array_map(function ($t) {
                return [
                    'id'             => $t['id'],
                    'nome'           => $t['nome'],
                    'status'         => $t['status'],
                    'is_teste'       => $t['status'] !== 'publicado',
                    'tem_financeiro' => (bool) $t['tem_financeiro'],
                    'total_etapas'   => count($t['etapas']),
                    'total_campos'   => count($t['campos']),
                    'total_anexos'   => count($t['tipos_anexo']),
                ];
            }, $templates),
        ]);
        exit;

    // ── GET: escolher um template específico da busca ─────
    case 'selecionar_template':
        Permissions::require('processos', 'criar');

        try {
            $versaoEscolhida = Sanitize::uuid(Sanitize::fromGet(['id'])['id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Template inválido.'];
            header('Location: ?module=processos&action=novo');
            exit;
        }

        // Só aceita templates que saíram da última busca (whitelist da sessão)
        $opcoes = $_SESSION['wizard_processos_opcoes'] ?? [];
        if (!isset($opcoes[$versaoEscolhida])) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Refaça a busca de templates antes de selecionar.'];
            header('Location: ?module=processos&action=novo');
            exit;
        }

        $_SESSION['wizard_processos'] = $opcoes[$versaoEscolhida];
        header('Location: ?module=processos&action=step2');
        exit;

    // ── GET/POST: step2 do wizard ────────────────────────
    case 'step2':
        $emEdicao = !empty($_SESSION['wizard_processos']['edit_processo_id']);
        if ($emEdicao) {
            // Modo edição: o gate (dono ou processos.editar, etapa 1) já foi
            // aplicado ao entrar por 'editar', que marca edit_pode na sessão.
            if (empty($_SESSION['wizard_processos']['edit_pode'])) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Sessão de edição inválida.'];
                header('Location: ?module=processos&action=index');
                exit;
            }
        } else {
            Permissions::require('processos', 'criar');
        }

        // Verificar que o wizard foi iniciado no step1
        if (empty($_SESSION['wizard_processos']['template_versao_id'])) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Selecione um template antes de prosseguir.'];
            header('Location: ?module=processos&action=novo');
            exit;
        }

        $wizard = &$_SESSION['wizard_processos'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Salvar dados do step2 na sessão e ir para step3
            $post = Sanitize::fromPost(['csrf_token', 'fornecedor_id', 'fornecedor_nome', 'fornecedor_doc',
                                        'numero_nf', 'valor_nf', 'data_nf', 'chave_nf',
                                        'filial_destino_id', 'filial_doc',
                                        // (o valor da etapa 1 é reimposto adiante — campo travado no step2)
                                        'parcelas_count', 'dados_dinamicos']);

            Csrf::validate($post['csrf_token'] ?? '');

            // ── Entrega futura (migrations 071/072) ──────────────────────────
            //  • venda   : processo normal, marcado para acompanhamento;
            //  • remessa : processo normal TAMBÉM (segue o fluxo e é escriturada
            //    no Fiscal), só sem a obrigação do Financeiro (sem parcelas). Ao
            //    gravar, abate o saldo da venda escolhida.
            //  Funciona na abertura e no "Completar dados" (modo edição).
            require_once SRC . '/Models/EntregaFutura.php';
            $emEdicaoEf  = !empty($wizard['edit_processo_id']);
            $efVenda     = isset($_POST['ef_venda']);
            $efRemessa   = isset($_POST['ef_remessa']);
            $efVendaId   = trim((string) ($_POST['ef_venda_id'] ?? ''));
            // Processo que já é remessa de uma venda: continua sem Financeiro
            $efJaRemessa = $emEdicaoEf && EntregaFutura::ehRemessa((string) $wizard['edit_processo_id']);
            $erroEf = null;
            if ($efVenda && $efRemessa) {
                $erroEf = 'Marque só uma opção: venda com entrega futura OU remessa de entrega futura.';
            } elseif ($efRemessa && !preg_match('/^[0-9a-f-]{36}$/i', $efVendaId)) {
                $erroEf = 'Selecione a nota de venda da qual esta remessa faz parte.';
            } elseif ($efRemessa && $emEdicaoEf && EntregaFutura::vendaDoProcesso((string) $wizard['edit_processo_id'])) {
                $erroEf = 'Este processo já é uma venda com entrega futura — não pode ser remessa ao mesmo tempo.';
            }
            if ($erroEf !== null) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => $erroEf];
                header('Location: ?module=processos&action=step2');
                exit;
            }
            $semFinanceiro = $efRemessa || $efJaRemessa;

            // Processo sem nota: só vale se o TEMPLATE permitir. Sem esta amarra,
            // um POST forjado abriria processo sem NF em qualquer fluxo.
            //
            // A exceção é o processo que JÁ nasceu sem nota: ele mantém a
            // condição na edição mesmo que o template tenha desligado a opção
            // depois — senão a edição passaria a exigir uma NF que não existe e
            // o processo ficaria travado. Marcar a opção segue valendo só na
            // abertura; aqui o valor apenas se preserva.
            $semNota = (!empty($wizard['permite_sem_nota']) || !empty($wizard['sem_nota']))
                    && isset($_POST['sem_nota']);

            // Solicitação de acesso fora do horário (migration 043): não há nota
            // nem fornecedor. O step2 esconde os dois blocos; aqui o servidor
            // preenche, para o POST não depender de campo que a tela não mostra.
            //
            // `fornecedor_id` é NOT NULL e aponta para a tabela de fornecedores,
            // então não dá para deixar vazio — e usar o CPF do funcionário criaria
            // um fornecedor por pessoa, que apareceria no autocomplete, nos
            // relatórios por fornecedor e na checagem de nota duplicada. Daí um
            // único fornecedor interno, criado sob demanda.
            if (!empty($wizard['libera_excecao_horario'])) {
                $semNota = true;
                $interno = Process::fornecedorInterno();

                // `fornecedor_id` aponta para a tabela de fornecedores e precisa
                // do registro interno. Mas `fornecedor_nome` é coluna própria do
                // processo — e é ela que a tela de aprovação mostra em destaque.
                // Então vai o NOME DE QUEM PEDIU: quem aprova precisa saber de
                // quem é a solicitação, não ler "SOLICITACAO INTERNA".
                $post['fornecedor_id']   = $interno['id'];
                $post['fornecedor_nome'] = Auth::user()['nome'];
                $post['fornecedor_doc']  = $interno['doc'];
            }

            // Valor informado na etapa 1 é imutável: reimpõe sobre o POST
            // (o campo chega readonly no step2, mas o servidor garante)
            if (!$semNota && (float) ($wizard['valor_aprox'] ?? 0) > 0) {
                $post['valor_nf'] = number_format((float) $wizard['valor_aprox'], 2, ',', '.');
            }

            if ($semNota) {
                $post['numero_nf'] = '';
                $post['valor_nf']  = '';
                $post['data_nf']   = '';
                $post['chave_nf']  = '';
            } else {
                // Os três campos da NF são obrigatórios quando há nota — antes
                // isso vivia só no `required` do HTML.
                if (trim($post['numero_nf']) === '') {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Informe o número da nota fiscal.'];
                    header('Location: ?module=processos&action=step2');
                    exit;
                }
                // Limite da coluna (VARCHAR 255, migration 042). Sem isto o
                // erro só aparecia no INSERT, como "Erro ao criar processo".
                if (mb_strlen(trim($post['numero_nf'])) > 255) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'O número da nota fiscal passa de 255 caracteres. Se o processo cobre várias notas, reduza a lista ou abra outro processo.'];
                    header('Location: ?module=processos&action=step2');
                    exit;
                }
                if (trim($post['data_nf']) === '') {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Informe a data da nota fiscal.'];
                    header('Location: ?module=processos&action=step2');
                    exit;
                }
                // A obrigatoriedade do valor é do TIPO DE NOTA (Tabelas → Tipos
                // de nota, "Exige valor na abertura").
                if (($wizard['tipo_nota_exige_valor'] ?? true)
                    && (float) str_replace(',', '.', str_replace('.', '', $post['valor_nf'])) <= 0) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Informe o valor total da nota.'];
                    header('Location: ?module=processos&action=step2');
                    exit;
                }

                // ── Faixa de valor do fluxo (alçada) ──────────────────
                // O valor da etapa 1 é reimposto acima e o campo chega
                // readonly — mas só quando ele existe. Tipo de nota marcado
                // como "não exige valor" deixa o campo editável aqui E faz a
                // busca do step 1 ignorar valor_min/valor_max: era por essa
                // porta que um valor de qualquer tamanho entrava num fluxo de
                // faixa curta. Process::criar() barra de novo, sem mensagem
                // boa; esta é a que explica o que fazer.
                $valorReal = (float) str_replace(',', '.', str_replace('.', '', $post['valor_nf']));
                if ($valorReal > 0) {
                    $violacaoFaixa = Process::valorForaDaFaixa(
                        (string) ($wizard['template_versao_id'] ?? ''),
                        $valorReal
                    );
                    if ($violacaoFaixa !== null) {
                        $msgFaixa = 'O fluxo "' . $violacaoFaixa['nome'] . '" aceita '
                                  . $violacaoFaixa['limite'] . ', e o valor informado é R$ '
                                  . number_format($valorReal, 2, ',', '.') . '.';

                        $sugestoes = Process::sugerirTemplatesParaValor(
                            $valorReal,
                            [
                                'filial_id'       => $wizard['filial_id']       ?? '',
                                'setor_id'        => $wizard['setor_id']        ?? '',
                                'tipo_nota_id'    => $wizard['tipo_nota_id']    ?? '',
                                'departamento_id' => $wizard['departamento_id'] ?? '',
                            ],
                            (string) ($wizard['template_versao_id'] ?? '')
                        );
                        $msgFaixa .= $sugestoes
                            ? ' Use: ' . implode(' · ', $sugestoes) . '.'
                            : ' Volte à etapa 1 e refaça a busca com este valor para o'
                              . ' sistema oferecer o fluxo da faixa correta.';

                        $_SESSION['flash'] = ['type' => 'danger', 'message' => $msgFaixa];
                        header('Location: ?module=processos&action=step2');
                        exit;
                    }
                }
            }

            // Capturar e validar campos dinâmicos do POST por tipo
            $dadosDinamicos = [];
            $camposDef = $wizard['campos_json'] ?? [];
            $errosCampo = [];

            foreach ($camposDef as $campoDef) {
                $nome = $campoDef['nome'] ?? '';
                $tipo = $campoDef['tipo'] ?? 'texto';
                $obrig = !empty($campoDef['obrigatorio']);
                $label = $campoDef['label'] ?? $campoDef['nome'] ?? 'Campo';
                $raw = trim(strip_tags($_POST['campo'][$nome] ?? ''));

                if ($obrig && $raw === '') {
                    $errosCampo[] = "{$label} é obrigatório.";
                    continue;
                }

                if ($raw === '') {
                    $dadosDinamicos[$nome] = '';
                    continue;
                }

                switch ($tipo) {
                    case 'numero':
                    case 'inteiro':
                        if (!preg_match('/^-?\d+$/', $raw)) {
                            $errosCampo[] = "{$label}: valor numérico inválido.";
                        } else {
                            $dadosDinamicos[$nome] = $raw;
                        }
                        break;

                    case 'valor':
                        $limpo = str_replace('.', '', $raw);
                        $limpo = str_replace(',', '.', $limpo);
                        if (!is_numeric($limpo)) {
                            $errosCampo[] = "{$label}: valor monetário inválido.";
                        } else {
                            $dadosDinamicos[$nome] = $raw;
                        }
                        break;

                    case 'data':
                        $dataValida = false;
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
                            $dataValida = $dt && $dt->format('Y-m-d') === $raw;
                        }
                        if (!$dataValida) {
                            $errosCampo[] = "{$label}: data inválida.";
                        } else {
                            $dadosDinamicos[$nome] = $raw;
                        }
                        break;

                    case 'cpf_cnpj':
                        $digitos = preg_replace('/\D/', '', $raw);
                        if (strlen($digitos) !== 11 && strlen($digitos) !== 14) {
                            $errosCampo[] = "{$label}: CPF (11 dígitos) ou CNPJ (14 dígitos) inválido.";
                        } else {
                            $dadosDinamicos[$nome] = $raw;
                        }
                        break;

                    case 'telefone':
                        $digitos = preg_replace('/\D/', '', $raw);
                        if (strlen($digitos) < 10 || strlen($digitos) > 11) {
                            $errosCampo[] = "{$label}: telefone inválido (10 ou 11 dígitos).";
                        } else {
                            $dadosDinamicos[$nome] = $raw;
                        }
                        break;

                    case 'select':
                    case 'lista':
                        $dadosDinamicos[$nome] = mb_substr($raw, 0, 500);
                        break;

                    default:
                        $dadosDinamicos[$nome] = mb_substr($raw, 0, 2000);
                        break;
                }
            }

            if (!empty($errosCampo)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => implode(' ', $errosCampo)];
                header('Location: ?module=processos&action=step2');
                exit;
            }

            // Validar nota fiscal duplicada (mesmo fornecedor + número, ou mesma chave)
            $numeroNfPost    = strip_tags(trim($post['numero_nf']));
            $fornecedorIdPost = strip_tags(trim($post['fornecedor_id']));
            $fornecedorDocPost = strip_tags(trim($post['fornecedor_doc']));
            $chaveNfPost     = strip_tags(trim($post['chave_nf']));

            if ($numeroNfPost !== '') {
                $dup = Process::notaDuplicada(
                    $numeroNfPost,
                    $fornecedorIdPost ?: null,
                    $fornecedorDocPost ?: null,
                    $chaveNfPost ?: null,
                    $emEdicao ? (string) $wizard['edit_processo_id'] : null
                );
                if ($dup) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => sprintf(
                        'Nota fiscal já lançada no processo %s (status: %s). Verifique antes de duplicar.',
                        $dup['codigo'],
                        $dup['status']
                    )];
                    header('Location: ?module=processos&action=step2');
                    exit;
                }
            }

            // Capturar parcelas
            $parcelas = [];
            if ($wizard['tem_financeiro'] && !$semFinanceiro) {
                $parcelasPost = $_POST['parcela'] ?? [];
                foreach ($parcelasPost as $i => $p) {
                    $parcelas[] = [
                        'vencimento'        => strip_tags(trim($p['vencimento'] ?? '')),
                        'valor'             => (float) str_replace(',', '.', str_replace('.', '', strip_tags(trim($p['valor'] ?? '0')))),
                        'forma_pagamento_id'=> strip_tags(trim($p['forma_pagamento_id'] ?? '')),
                    ];
                }
            }

            if ($wizard['tem_financeiro'] && !empty($parcelas)) {
                foreach ($parcelas as $idx => $parc) {
                    if (empty($parc['vencimento']) || $parc['valor'] <= 0 || empty($parc['forma_pagamento_id'])) {
                        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Preencha vencimento, valor e forma de pagamento de todas as parcelas.'];
                        header('Location: ?module=processos&action=step2');
                        exit;
                    }
                }
            }

            // Capturar rateio entre centros de custo
            $rateios = [];
            if (!empty($wizard['tem_rateio'])) {
                $valorNfPost = (float) str_replace(',', '.', str_replace('.', '', $post['valor_nf']));
                $rateiosPost = $_POST['rateio'] ?? [];
                $setoresVistos = [];

                foreach ($rateiosPost as $r) {
                    $setorId = strip_tags(trim($r['setor_id'] ?? ''));
                    if ($setorId === '') continue;
                    $rateios[] = [
                        'setor_id'   => $setorId,
                        'percentual' => (float) str_replace(',', '.', strip_tags(trim($r['percentual'] ?? '0'))),
                        'valor'      => (float) str_replace(',', '.', str_replace('.', '', strip_tags(trim($r['valor'] ?? '0')))),
                    ];
                    $setoresVistos[] = $setorId;
                }

                if (empty($rateios)) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Informe ao menos um centro de custo no rateio.'];
                    header('Location: ?module=processos&action=step2');
                    exit;
                }
                if (count($setoresVistos) !== count(array_unique($setoresVistos))) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Cada centro de custo pode aparecer apenas uma vez no rateio.'];
                    header('Location: ?module=processos&action=step2');
                    exit;
                }

                $somaValor = array_sum(array_column($rateios, 'valor'));
                if (abs($somaValor - $valorNfPost) > 0.05) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => sprintf(
                        'A soma do rateio (R$ %s) difere do valor da NF (R$ %s). Ajuste os valores.',
                        number_format($somaValor, 2, ',', '.'),
                        number_format($valorNfPost, 2, ',', '.')
                    )];
                    header('Location: ?module=processos&action=step2');
                    exit;
                }

                // Denormalizar nome/código do setor (imutável no histórico do processo)
                $pdoR   = Database::getInstance();
                $stmtR  = $pdoR->prepare("SELECT nome, codigo FROM setores WHERE id = ? LIMIT 1");
                foreach ($rateios as &$r) {
                    $stmtR->execute([$r['setor_id']]);
                    $setorInfo = $stmtR->fetch();
                    if (!$setorInfo) {
                        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Centro de custo inválido no rateio.'];
                        header('Location: ?module=processos&action=step2');
                        exit;
                    }
                    $r['setor_nome']   = $setorInfo['nome'];
                    $r['setor_codigo'] = $setorInfo['codigo'];
                }
                unset($r);
            }

            // Capturar tipos de despesa (classificação do gasto — espelha o rateio)
            $tiposDespesa = [];
            if (!empty($wizard['tem_tipo_despesa'])) {
                $valorNfPost = (float) str_replace(',', '.', str_replace('.', '', $post['valor_nf']));
                $tdPost      = $_POST['tipo_despesa'] ?? [];
                $tiposVistos = [];

                foreach ($tdPost as $t) {
                    $tipoId = strip_tags(trim($t['tipo_id'] ?? ''));
                    if ($tipoId === '') continue;
                    $tiposDespesa[] = [
                        'tipo_id'    => $tipoId,
                        'percentual' => (float) str_replace(',', '.', strip_tags(trim($t['percentual'] ?? '0'))),
                        'valor'      => (float) str_replace(',', '.', str_replace('.', '', strip_tags(trim($t['valor'] ?? '0')))),
                    ];
                    $tiposVistos[] = $tipoId;
                }

                if (empty($tiposDespesa) && !empty($wizard['tipo_despesa_obrigatorio'])) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Informe ao menos um tipo de despesa.'];
                    header('Location: ?module=processos&action=step2');
                    exit;
                }
                if (count($tiposVistos) !== count(array_unique($tiposVistos))) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Cada tipo de despesa pode aparecer apenas uma vez.'];
                    header('Location: ?module=processos&action=step2');
                    exit;
                }
                // Se preencheu algo, a soma tem de fechar com o valor da NF (como o rateio)
                if (!empty($tiposDespesa)) {
                    $somaTd = array_sum(array_column($tiposDespesa, 'valor'));
                    if (abs($somaTd - $valorNfPost) > 0.05) {
                        $_SESSION['flash'] = ['type' => 'danger', 'message' => sprintf(
                            'A soma dos tipos de despesa (R$ %s) difere do valor da NF (R$ %s). Ajuste os valores.',
                            number_format($somaTd, 2, ',', '.'),
                            number_format($valorNfPost, 2, ',', '.')
                        )];
                        header('Location: ?module=processos&action=step2');
                        exit;
                    }
                    // Denormalizar o nome do tipo (imutável no histórico do processo)
                    $pdoT  = Database::getInstance();
                    $stmtT = $pdoT->prepare("SELECT valor FROM nf_lista_itens WHERE id = ? AND ativo = 1 LIMIT 1");
                    foreach ($tiposDespesa as &$t) {
                        $stmtT->execute([$t['tipo_id']]);
                        $nome = $stmtT->fetchColumn();
                        if ($nome === false) {
                            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Tipo de despesa inválido.'];
                            header('Location: ?module=processos&action=step2');
                            exit;
                        }
                        $t['tipo_nome'] = $nome;
                    }
                    unset($t);
                }
            }

            $filialDestinoId = strip_tags(trim($post['filial_destino_id']));
            $filialDocPost   = strip_tags(trim($post['filial_doc']));

            if (!empty($filialDestinoId)) {
                $wizard['filial_id'] = $filialDestinoId;
            }

            // Remessa nova: recusa cedo (saldo, duplicidade) em vez de só na gravação
            if ($efRemessa) {
                $erroEf = EntregaFutura::validarRemessa($efVendaId, [
                    'numero_nf'      => strip_tags(trim($post['numero_nf'])),
                    'valor_nf'       => (float) str_replace(',', '.', str_replace('.', '', $post['valor_nf'])),
                    'chave_nf'       => preg_replace('/\D/', '', (string) $post['chave_nf']),
                    'fornecedor_doc' => strip_tags(trim($post['fornecedor_doc'])),
                ]);
                if ($erroEf !== null) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => $erroEf];
                    header('Location: ?module=processos&action=step2');
                    exit;
                }
            }

            $wizard['dados_step2'] = [
                'fornecedor_id'      => strip_tags(trim($post['fornecedor_id'])),
                'fornecedor_nome'    => strip_tags(trim($post['fornecedor_nome'])),
                'fornecedor_doc'     => strip_tags(trim($post['fornecedor_doc'])),
                'sem_nota'           => $semNota,
                'entrega_futura'     => $efVenda && !$semNota,
                'ef_remessa_venda_id' => $efRemessa ? $efVendaId : '',
                'sem_financeiro'     => $semFinanceiro,
                // NULL é diferente de zero: zero seria uma nota de valor zero.
                'numero_nf'          => $semNota ? null : strip_tags(trim($post['numero_nf'])),
                'valor_nf'           => $semNota ? null : (float) str_replace(',', '.', str_replace('.', '', $post['valor_nf'])),
                'data_nf'            => $semNota ? null : strip_tags(trim($post['data_nf'])),
                'chave_nf'           => $semNota ? '' : strip_tags(trim($post['chave_nf'])),
                'filial_destino_id'  => $filialDestinoId,
                'filial_doc'         => $filialDocPost,
                'dados_dinamicos'    => $dadosDinamicos,
                'parcelas'           => $parcelas,
                'rateios'            => $rateios,
                'tipos_despesa'      => $tiposDespesa,
            ];

            // Mapa de extensões permitidas por tipo_doc
            $extPorTipo = [
                'qualquer'   => ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','txt','csv'],
                'pdf'        => ['pdf'],
                'imagem'     => ['jpg','jpeg','png','webp'],
                'pdf_imagem' => ['pdf','jpg','jpeg','png','webp'],
                'excel'      => ['xls','xlsx'],
                'word'       => ['doc','docx'],
                // Vídeo/áudio só entram em tipo declarado como tal — fora de
                // 'qualquer' de propósito: senão todo anexo do sistema viraria
                // porta de 5 MB.
                'video'      => array_merge(VIDEO_EXTENSIONS, AUDIO_EXTENSIONS),
            ];

            $tipoDocLabels = [
                'qualquer' => 'qualquer formato', 'pdf' => 'PDF', 'imagem' => 'imagem (JPG, PNG, WEBP)',
                'pdf_imagem' => 'PDF ou imagem', 'excel' => 'planilha (XLS, XLSX)', 'word' => 'Word (DOC, DOCX)',
                'video' => 'áudio ou vídeo (MP4, WEBM, MOV, OGG, MP3, M4A, WAV) de até 5 MB',
            ];

            // Processar uploads de anexos (armazenar temporariamente).
            // Cada tipo aceita MÚLTIPLOS arquivos (input name="anexo_<slug>[]").
            $anexosTemp = [];
            foreach ($wizard['tipos_anexo'] as $tipo) {
                $fieldName = 'anexo_' . preg_replace('/[^a-z0-9]/i', '_', $tipo['nome'] ?? 'anexo');
                if (empty($_FILES[$fieldName])) {
                    continue;
                }

                $tipoDoc   = $tipo['tipo_doc'] ?? 'qualquer';
                $extPermit = $extPorTipo[$tipoDoc] ?? $extPorTipo['qualquer'];

                // Normalizar: input pode chegar como único (name="x") ou múltiplo (name="x[]")
                $entrada = $_FILES[$fieldName];
                $arquivos = [];
                if (is_array($entrada['name'])) {
                    $n = count($entrada['name']);
                    for ($i = 0; $i < $n; $i++) {
                        $arquivos[] = [
                            'name'     => $entrada['name'][$i],
                            'type'     => $entrada['type'][$i]     ?? '',
                            'tmp_name' => $entrada['tmp_name'][$i] ?? '',
                            'error'    => $entrada['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                            'size'     => $entrada['size'][$i]     ?? 0,
                        ];
                    }
                } else {
                    $arquivos[] = $entrada;
                }

                foreach ($arquivos as $arquivo) {
                    // Ignorar "slots" vazios (nenhum arquivo escolhido)
                    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }

                    // Validar extensão contra tipo_doc configurado
                    $extArquivo = strtolower(pathinfo($arquivo['name'] ?? '', PATHINFO_EXTENSION));
                    if (!in_array($extArquivo, $extPermit, true)) {
                        $labelTipo = $tipoDocLabels[$tipoDoc] ?? $tipoDoc;
                        $_SESSION['flash'] = [
                            'type'    => 'danger',
                            'message' => "O anexo \"{$tipo['nome']}\" aceita apenas {$labelTipo}. Você enviou um arquivo .{$extArquivo}."
                        ];
                        header('Location: ?module=processos&action=step2');
                        exit;
                    }

                    try {
                        $meta = Upload::salvar($arquivo, 'temp_' . session_id(), false);
                        $meta['tipo_nome'] = $tipo['nome'] ?? 'Anexo';
                        $meta['tipo_doc']  = $tipoDoc;
                        $anexosTemp[] = $meta;
                    } catch (\RuntimeException $e) {
                        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro no upload: ' . $e->getMessage()];
                        header('Location: ?module=processos&action=step2');
                        exit;
                    }
                }
            }
            $wizard['anexos_temp'] = $anexosTemp;

            header('Location: ?module=processos&action=step3');
            exit;
        }

        // GET: exibir step2
        $fornecedores    = [];
        $formasPagamento = $wizard['tem_financeiro'] ? Process::getListaItens('formas-pagamento') : [];
        $filiais         = Process::getFiliais();

        // Rateio: centros de custo disponíveis (setores ativos, com código quando houver)
        $centrosCusto = [];
        if (!empty($wizard['tem_rateio'])) {
            $centrosCusto = Database::getInstance()->query(
                "SELECT s.id, s.nome, s.codigo, d.valor AS departamento_nome
                 FROM setores s
                 LEFT JOIN nf_lista_itens d ON d.id = s.departamento_id
                 WHERE s.ativo = 1
                 ORDER BY s.nome"
            )->fetchAll();
        }

        // Tipo de despesa: itens ativos da lista (mesma mecânica do rateio)
        $tiposDespesa = [];
        if (!empty($wizard['tem_tipo_despesa'])) {
            $tiposDespesa = Process::getListaItens('tipos-despesa');
        }
        $tiposDespesaPrev = $wizard['dados_step2']['tipos_despesa'] ?? [];

        // Buscar nomes da filial, setor e tipo de nota (para card readonly de identificação)
        $pdo = Database::getInstance();

        $filialNome = '';
        if (!empty($wizard['filial_id'])) {
            $stmtF = $pdo->prepare("SELECT nome FROM filiais WHERE id = ? LIMIT 1");
            $stmtF->execute([$wizard['filial_id']]);
            $filialNome = $stmtF->fetchColumn() ?: '';
        }

        $setorNome = '';
        if (!empty($wizard['setor_id'])) {
            $stmtS = $pdo->prepare("SELECT nome FROM setores WHERE id = ? LIMIT 1");
            $stmtS->execute([$wizard['setor_id']]);
            $setorNome = $stmtS->fetchColumn() ?: '';
        }

        $tipoNotaNome = '';
        if (!empty($wizard['tipo_nota_id'])) {
            $stmtT = $pdo->prepare("SELECT valor FROM nf_lista_itens WHERE id = ? LIMIT 1");
            $stmtT->execute([$wizard['tipo_nota_id']]);
            $tipoNotaNome = $stmtT->fetchColumn() ?: '';
        }

        // Entrega futura: vendas pendentes para o seletor de remessa (só na abertura)
        $vendasFuturas  = [];
        $efVendaAtual   = null;   // modo edição: este processo já é uma venda
        $efRemessaAtual = null;   // modo edição: este processo já é remessa de uma venda
        require_once SRC . '/Models/EntregaFutura.php';
        try {
            $vendasFuturas = EntregaFutura::pendentes($wizard['edit_processo_id'] ?? null);
            if (!empty($wizard['edit_processo_id'])) {
                $efVendaAtual   = EntregaFutura::vendaDoProcesso((string) $wizard['edit_processo_id']);
                $efRemessaAtual = EntregaFutura::remessaDoProcesso((string) $wizard['edit_processo_id']);
            }
        } catch (\Throwable $e) { $vendasFuturas = []; }

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $pageTitle    = 'Novo Processo — Etapa 2';
        $activeModule = 'novo_processo';
        $unreadCount  = 0;
        $jsVer  = filemtime(ROOT . '/public/assets/js/modules/processos.js');
        $cssVer = filemtime(ROOT . '/public/assets/css/modules/processos.css');
        $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/processos.css?v=' . $cssVer . '">'
                      . '<script src="' . BASE_URL . '/public/assets/js/modules/processos.js?v=' . $jsVer . '" defer></script>';

        ob_start();
        include VIEWS . '/processos/novo/step2.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET: step3 — revisão final ────────────────────────
    case 'step3':
        if (!empty($_SESSION['wizard_processos']['edit_processo_id'])) {
            if (empty($_SESSION['wizard_processos']['edit_pode'])) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Sessão de edição inválida.'];
                header('Location: ?module=processos&action=index');
                exit;
            }
        } else {
            Permissions::require('processos', 'criar');
        }

        if (empty($_SESSION['wizard_processos']['dados_step2']['numero_nf'])
            && empty($_SESSION['wizard_processos']['dados_step2']['sem_nota'])) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Preencha os dados da NF antes de revisar.'];
            header('Location: ?module=processos&action=step2');
            exit;
        }

        $wizard = $_SESSION['wizard_processos'];

        // Buscar nomes para exibição na revisão
        $pdo = Database::getInstance();

        $filialNome = '';
        if (!empty($wizard['filial_id'])) {
            $stmtF = $pdo->prepare("SELECT nome FROM filiais WHERE id = ? LIMIT 1");
            $stmtF->execute([$wizard['filial_id']]);
            $filialNome = $stmtF->fetchColumn() ?: '';
        }

        $setorNome = '';
        if (!empty($wizard['setor_id'])) {
            $stmtS = $pdo->prepare("SELECT nome FROM setores WHERE id = ? LIMIT 1");
            $stmtS->execute([$wizard['setor_id']]);
            $setorNome = $stmtS->fetchColumn() ?: '';
        }

        $tipoNotaNome = '';
        if (!empty($wizard['tipo_nota_id'])) {
            $stmtT = $pdo->prepare("SELECT valor FROM nf_lista_itens WHERE id = ? LIMIT 1");
            $stmtT->execute([$wizard['tipo_nota_id']]);
            $tipoNotaNome = $stmtT->fetchColumn() ?: '';
        }

        // PIS/NIT do prestador — mostra o impedimento já na revisão, antes do envio
        require_once SRC . '/Helpers/PisNit.php';
        $pisCheck = PisNit::verificarAbertura([
            'fornecedor_id'  => $wizard['dados_step2']['fornecedor_id']  ?? null,
            'fornecedor_doc' => $wizard['dados_step2']['fornecedor_doc'] ?? null,
            'tipo_nota_id'   => $wizard['tipo_nota_id'] ?? null,
            'chave_nf'       => $wizard['dados_step2']['chave_nf'] ?? null,
            'processo_id'    => $wizard['edit_processo_id'] ?? null,
        ]);

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $pageTitle    = 'Novo Processo — Etapa 3 (Revisão)';
        $activeModule = 'novo_processo';
        $unreadCount  = 0;
        $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/processos.css">';

        ob_start();
        include VIEWS . '/processos/novo/step3.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── POST: submeter processo ───────────────────────────
    case 'submeter':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=processos&action=novo');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'is_teste']);
        Csrf::validate($post['csrf_token'] ?? '');

        if (empty($_SESSION['wizard_processos']['dados_step2']['numero_nf'])
            && empty($_SESSION['wizard_processos']['dados_step2']['sem_nota'])) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Dados incompletos. Reinicie o processo.'];
            header('Location: ?module=processos&action=novo');
            exit;
        }

        // Fornecedor obrigatório só na criação; na edição vem do processo (readonly).
        if (empty($_SESSION['wizard_processos']['edit_processo_id'])
            && empty($_SESSION['wizard_processos']['dados_step2']['fornecedor_id'])) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Selecione um fornecedor pelo CNPJ antes de enviar.'];
            header('Location: ?module=processos&action=step2');
            exit;
        }

        $wizard = $_SESSION['wizard_processos'];
        $dados  = $wizard['dados_step2'];

        // ── PIS/NIT: nota de serviço de MEI ou pessoa física não abre sem o
        //    número do prestador (retenção de INSS). Vale para criação e para a
        //    abertura dos processos que vieram da captura.
        require_once SRC . '/Helpers/PisNit.php';
        $pisCheck = PisNit::verificarAbertura([
            'fornecedor_id'  => $dados['fornecedor_id']  ?? null,
            'fornecedor_doc' => $dados['fornecedor_doc'] ?? null,
            'tipo_nota_id'   => $wizard['tipo_nota_id'] ?? null,
            'chave_nf'       => $dados['chave_nf'] ?? null,
            'processo_id'    => $wizard['edit_processo_id'] ?? null,
        ]);
        if ($pisCheck['bloqueio'] !== null) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => $pisCheck['bloqueio']];
            header('Location: ?module=processos&action=step3');
            exit;
        }

        // ── Modo edição: grava no processo existente (parcelas/rateio/tipo de
        //    despesa/campos/anexos) e volta ao detalhe. Não cria processo novo.
        if (!empty($wizard['edit_processo_id'])) {
            $editId = $wizard['edit_processo_id'];
            try {
                Process::atualizar($editId, [
                    'numero_nf'        => empty($dados['sem_nota']) ? trim((string) ($dados['numero_nf'] ?? '')) : null,
                    'dados_formulario' => $dados['dados_dinamicos'] ?? [],
                    'tem_financeiro'   => !empty($wizard['tem_financeiro']) && empty($dados['sem_financeiro']),
                    'parcelas'         => $dados['parcelas'] ?? [],
                    'rateios'          => $dados['rateios'] ?? [],
                    'tipos_despesa'    => $dados['tipos_despesa'] ?? [],
                ], $usuarioId, $currentUser['nome']);

                // Entrega futura no "Completar dados" (migrations 071/072)
                require_once SRC . '/Models/EntregaFutura.php';
                $avisoEf = '';
                if (!EntregaFutura::ehRemessa($editId)) {
                    $vendaAtual = EntregaFutura::vendaDoProcesso($editId);
                    if (!empty($dados['entrega_futura']) && !$vendaAtual) {
                        EntregaFutura::criarVenda($editId, $usuarioId);
                    } elseif (empty($dados['entrega_futura']) && $vendaAtual && !EntregaFutura::removerVenda($vendaAtual['id'])) {
                        $avisoEf = ' A venda continua em Entrega Futura porque já tem remessas lançadas.';
                    }
                }
                if (!empty($dados['ef_remessa_venda_id'])) {
                    $resEf = EntregaFutura::registrarRemessa((string) $dados['ef_remessa_venda_id'], [
                        'processo_id'     => $editId,
                        'numero_nf'       => (string) $dados['numero_nf'],
                        'chave_nf'        => (string) ($dados['chave_nf'] ?? ''),
                        'data_nf'         => (string) $dados['data_nf'],
                        'valor_nf'        => (float) $dados['valor_nf'],
                        'fornecedor_id'   => $dados['fornecedor_id'] ?: null,
                        'fornecedor_nome' => $dados['fornecedor_nome'],
                        'fornecedor_doc'  => $dados['fornecedor_doc'],
                    ], $usuarioId);
                    $avisoEf .= $resEf['ok'] ? ' ' . $resEf['mensagem'] : ' Remessa não registrada: ' . $resEf['mensagem'];
                }

                // Anexos novos enviados na edição — somam aos já existentes
                foreach ($wizard['anexos_temp'] ?? [] as $meta) {
                    Process::salvarAnexo($editId, $meta, $meta['tipo_nome'], $usuarioId, !empty($wizard['is_teste']));
                }

                AuditLog::registrar('nf_processo', $editId, 'nf_processo.editado');
                unset($_SESSION['wizard_processos']);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Dados do processo atualizados com sucesso.' . $avisoEf];
                header('Location: ?module=processos&action=detalhe&id=' . $editId);
                exit;
            } catch (\Throwable $e) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro ao salvar: ' . $e->getMessage()];
                header('Location: ?module=processos&action=step3');
                exit;
            }
        }

        $isTeste = (bool) ($post['is_teste'] ?? false);

        $estagioTeste = null;
        $tplStatus = $wizard['template_versao_status'] ?? 'publicado';
        if ($tplStatus === 'teste_interno') {
            $estagioTeste = 'interno';
        } elseif ($tplStatus === 'uat') {
            $estagioTeste = 'uat';
        }

        // Buscar filial_doc
        $filialDoc = '';
        try {
            $pdo  = Database::getInstance();
            $stmt = $pdo->prepare("SELECT cnpj FROM filiais WHERE id = ? LIMIT 1");
            $stmt->execute([$wizard['filial_id']]);
            $filialRow = $stmt->fetch();
            $filialDoc = $filialRow['cnpj'] ?? '';
        } catch (\Throwable) {}

        try {
            $resultado = Process::criar([
                'template_versao_id' => $wizard['template_versao_id'],
                'fornecedor_id'      => $dados['fornecedor_id'] ?: null,
                'fornecedor_nome'    => $dados['fornecedor_nome'],
                'fornecedor_doc'     => $dados['fornecedor_doc'],
                'sem_nota'           => !empty($dados['sem_nota']),
                'numero_nf'          => $dados['numero_nf'],
                'valor_nf'           => $dados['valor_nf'],
                'data_nf'            => $dados['data_nf'],
                'chave_nf'           => $dados['chave_nf'] ?: null,
                'filial_id'          => $wizard['filial_id'],
                'filial_doc'         => $filialDoc,
                'dados_formulario'   => $dados['dados_dinamicos'] ?? [],
                'is_teste'           => $isTeste,
                'estagio_teste'      => $estagioTeste,
                'etapas_template'    => $wizard['etapas_json'],
                'tem_financeiro'     => $wizard['tem_financeiro'] && empty($dados['sem_financeiro']),
                'parcelas'           => $dados['parcelas'] ?? [],
                'rateios'            => $dados['rateios'] ?? [],
                'tipos_despesa'      => $dados['tipos_despesa'] ?? [],
            ], $usuarioId);

            $processoId    = $resultado['id'];
            $processoCodigo = $resultado['codigo'];

            // Venda com entrega futura: entra no acompanhamento (migration 071)
            if (!empty($dados['entrega_futura'])) {
                require_once SRC . '/Models/EntregaFutura.php';
                EntregaFutura::criarVenda($processoId, $usuarioId);
            }

            // Remessa de entrega futura: abate o saldo da venda escolhida
            $avisoRemessa = '';
            if (!empty($dados['ef_remessa_venda_id'])) {
                require_once SRC . '/Models/EntregaFutura.php';
                $resEf = EntregaFutura::registrarRemessa((string) $dados['ef_remessa_venda_id'], [
                    'processo_id'     => $processoId,
                    'numero_nf'       => (string) $dados['numero_nf'],
                    'chave_nf'        => (string) ($dados['chave_nf'] ?? ''),
                    'data_nf'         => (string) $dados['data_nf'],
                    'valor_nf'        => (float) $dados['valor_nf'],
                    'fornecedor_id'   => $dados['fornecedor_id'] ?: null,
                    'fornecedor_nome' => $dados['fornecedor_nome'],
                    'fornecedor_doc'  => $dados['fornecedor_doc'],
                ], $usuarioId);
                if (!$resEf['ok']) {
                    $avisoRemessa = $resEf['mensagem'];
                }
            }

            // Registrar anexos temporários como definitivos
            foreach ($wizard['anexos_temp'] ?? [] as $meta) {
                Process::salvarAnexo($processoId, $meta, $meta['tipo_nome'], $usuarioId, $isTeste);
            }

            AuditLog::registrar('nf_processo', $processoId, 'nf_processo.criado', null, [
                'codigo'   => $processoCodigo,
                'filial'   => $wizard['filial_id'],
                'is_teste' => $isTeste,
            ]);

            // Limpar wizard da sessão
            unset($_SESSION['wizard_processos']);

            $_SESSION['flash'] = !empty($avisoRemessa)
                ? ['type' => 'warning', 'message' => "Processo {$processoCodigo} criado, mas a remessa NÃO foi registrada na venda: {$avisoRemessa}"]
                : ['type' => 'processo_criado', 'codigo' => $processoCodigo];
            header('Location: ?module=processos&action=index');
            exit;

        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro ao criar processo: ' . $e->getMessage()];
            header('Location: ?module=processos&action=step3');
            exit;
        }

    // ── GET: completar/editar dados do processo (etapa de abertura) ──
    // Processos abertos via captura/pré-fluxo chegam sem parcelas, forma de
    // pagamento, tipo de despesa, centro de custo e anexos. Enquanto na abertura
    // (etapa 1), esta ação recarrega o MESMO fluxo do wizard (step2 → step3) a
    // partir do processo, para o dono (ou quem tem processos.editar) completar
    // e conferir antes de gravar. O submeter, em modo edição, grava no processo.
    case 'editar':
        try {
            $processoId = Sanitize::uuid(Sanitize::fromGet(['id'])['id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Processo inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        $processo = Process::findById($processoId);
        if (!$processo) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Processo não encontrado.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        // Gate: só na abertura (etapa 1, aberto/devolvido) e com permissão.
        // O atalho do dono saiu — o botão passou a ser controlado no acesso.
        $podeEditar = Permissions::can('processos', 'editar');
        $naAbertura = (int) ($processo['etapa_atual'] ?? 1) <= 1
                   && in_array($processo['status'], ['aberto', 'devolvido'], true);

        if (!$podeEditar || !$naAbertura) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => !$naAbertura
                ? 'Este processo já avançou no fluxo — não pode mais ser editado na abertura.'
                : 'Você não tem permissão para editar este processo.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        $cfg = Process::versaoConfig((string) $processo['template_versao_id']);
        if ($cfg === null) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Template do processo não encontrado.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        $valorProc = (float) $processo['valor_nf'];

        // Carrega o processo na sessão do wizard e segue o fluxo real step2→step3.
        // edit_pode = flag de gate já validado (usado por step2/step3/submeter).
        $_SESSION['wizard_processos'] = [
            'edit_processo_id'         => $processoId,
            'edit_pode'                => true,
            'edit_back_qs'             => $embedQS . $backQS,
            'template_versao_id'       => $processo['template_versao_id'],
            'template_nome'            => $processo['codigo'] ?? '',
            'valor_aprox'              => $valorProc,
            // Na edição o tipo de nota vem da versão do template do processo —
            // sem isso, processo aberto sem valor voltaria a exigir valor aqui.
            'tipo_nota_exige_valor'    => TableList::tipoNotaExigeValor($cfg['tipo_nota_id'] ?? null),
            'permite_sem_nota'         => (bool) ($cfg['permite_sem_nota'] ?? false),
            'sem_nota'                 => (int) ($processo['sem_nota'] ?? 0) === 1,
            'campos_json'              => $cfg['campos'],
            'etapas_json'              => $cfg['etapas'],
            'tipos_anexo'              => $cfg['tipos_anexo'],
            'tem_financeiro'           => $cfg['tem_financeiro'],
            'tem_rateio'               => $cfg['tem_rateio'],
            'tem_tipo_despesa'         => $cfg['tem_tipo_despesa'],
            'tipo_despesa_obrigatorio' => $cfg['tipo_despesa_obrigatorio'],
            'is_teste'                 => (int) ($processo['is_teste'] ?? 0) === 1,
            'filial_id'                => $processo['filial_id'],
            'setor_id'                 => '',
            'tipo_nota_id'             => '',
            'dados_step2'              => [
                'fornecedor_id'     => $processo['fornecedor_id'] ?? '',
                'fornecedor_nome'   => $processo['fornecedor_nome'] ?? '',
                'fornecedor_doc'    => $processo['fornecedor_doc'] ?? '',
                'numero_nf'         => $processo['numero_nf'] ?? '',
                'valor_nf'          => $valorProc,
                'data_nf'           => substr((string) ($processo['data_nf'] ?? ''), 0, 10),
                'chave_nf'          => $processo['chave_nf'] ?? '',
                'filial_destino_id' => $processo['filial_id'] ?? '',
                'filial_doc'        => $processo['filial_doc'] ?? '',
                'dados_dinamicos'   => is_array($processo['dados_formulario'] ?? null) ? $processo['dados_formulario'] : [],
                'parcelas'          => Process::getParcelas($processoId),
                'rateios'           => Process::getRateios($processoId),
                'tipos_despesa'     => Process::getTiposDespesa($processoId),
            ],
            'anexos_temp'              => [],
        ];

        header('Location: ?module=processos&action=step2');
        exit;

    // ── GET: detalhe do processo ──────────────────────────
    case 'detalhe':
        $get = Sanitize::fromGet(['id']);

        try {
            $id = Sanitize::uuid($get['id']);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID de processo inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        $processo = Process::findById($id);
        if (!$processo) {
            http_response_code(404);
            include VIEWS . '/errors/404.php';
            exit;
        }

        // Autorização por processo: admin, ver_todos, participação (criador/
        // aprovador) ou liberação granular pontual — nega e audita o resto.
        ProcessAccess::autorizar($id, $usuarioId);

        // Verificar se usuário pode aprovar a etapa atual
        $gruposIds = Process::getGruposDoUsuario($usuarioId);
        $etapaAprovavel = Process::etapaParaUsuario($id, $usuarioId, $gruposIds);

        // Carregar observações
        require_once SRC . '/Models/Observation.php';
        $observacoes      = Observation::listar($id);
        $observacoesCount = count($observacoes);

        // Registrar acesso no histórico (anti-flood: mesmo usuário só a cada 10 min)
        $pdoVis  = Database::getInstance();
        $stmtVis = $pdoVis->prepare(
            "SELECT 1 FROM nf_processo_historico
             WHERE processo_id = ? AND ator_id = ? AND acao = 'processo_visualizado'
               AND criado_em > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
             LIMIT 1"
        );
        $stmtVis->execute([$id, $usuarioId]);
        if (!$stmtVis->fetchColumn()) {
            Process::registrarHistorico($id, 'processo_visualizado', $usuarioId, $currentUser['nome'], 'Processo visualizado');
        }

        // Rateio entre centros de custo (se houver)
        $stmtRat = Database::getInstance()->prepare(
            "SELECT setor_codigo, setor_nome, percentual, valor
             FROM nf_processo_rateios WHERE processo_id = ? ORDER BY valor DESC"
        );
        $stmtRat->execute([$id]);
        $rateios = $stmtRat->fetchAll();

        // Tipos de despesa (classificação do gasto, se houver)
        $stmtTd = Database::getInstance()->prepare(
            "SELECT tipo_nome, percentual, valor
             FROM nf_processo_tipos_despesa WHERE processo_id = ? ORDER BY valor DESC"
        );
        $stmtTd->execute([$id]);
        $tiposDespesaProc = $stmtTd->fetchAll();

        // Cadastro do fornecedor para o modal de visualização (link no card hero).
        // Processos antigos podem não ter fornecedor_id — cai no CNPJ/CPF.
        require_once SRC . '/Models/Supplier.php';
        $fornecedorCad = !empty($processo['fornecedor_id'])
            ? Supplier::findById((string) $processo['fornecedor_id'])
            : null;
        if (!$fornecedorCad && !empty($processo['fornecedor_doc'])) {
            $fornecedorCad = Supplier::findByDoc((string) $processo['fornecedor_doc']);
        }

        // Checklist do que o fluxo exige (dados de abertura + anexos obrigatórios,
        // com a etapa que cobra cada um) — a tela avisa o que falta antes de o
        // usuário tentar aprovar e esbarrar no gate.
        $checklistObrig = Process::checklistObrigatorios($id);

        // Assinaturas eletrônicas do processo, indexadas pela etapa — a linha do
        // tempo mostra quem já assinou em vez de dizer "aguardando fulano",
        // que faria cobrar a pessoa errada (quem aprova a etapa é o provedor).
        require_once SRC . '/Models/Assinatura.php';
        $assinaturasPorEtapa = [];
        foreach (Assinatura::doProcesso($id) as $asg) {
            $num = (int) $asg['numero_etapa'];
            if (!isset($assinaturasPorEtapa[$num])) {
                $assinaturasPorEtapa[$num] = Assinatura::daEtapa($id, $num);
            }
        }

        // Campos que a etapa atual pede — o responsável precisa poder preenchê-los
        // antes de aprovar (o gate da aprovação cobra os obrigatórios).
        $camposDaEtapaAtual = $etapaAprovavel
            ? Process::camposDaEtapa($id, (int) $etapaAprovavel['numero_etapa'])
            : [];

        // Destinos da devolução — pessoas que já agiram no fluxo, não etapas.
        $pessoasDevolucao = $etapaAprovavel ? Process::pessoasParaDevolucao($id) : [];

        // Membros do grupo para reatribuição (quando etapa ativa é de grupo)
        $membrosReatribuicao = [];
        if ($etapaAprovavel && $etapaAprovavel['tipo_aprovador'] === 'grupo') {
            $membrosReatribuicao = Process::getMembrosGrupo($etapaAprovavel['aprovador_id']);
        }

        // Reatribuição administrativa — mesma ação, mas para quem tem a
        // permissão dedicada tarefas.reatribuir_admin agir mesmo sem ser o
        // dono da etapa (cobrir férias/afastamento sem precisar logar como
        // a pessoa). Só entra em jogo se o usuário logado NÃO for já o
        // dono (senão o fluxo normal acima já resolve).
        $etapaAdmin           = null;
        $membrosReatribuicaoAdmin = [];
        if ($etapaAprovavel === null
            && Permissions::can('tarefas', 'reatribuir_admin')
            && !in_array($processo['status'], ['aprovado', 'cancelado', 'encerrado_apollo'], true)) {
            foreach (($processo['etapas'] ?? []) as $et) {
                if ((int) $et['numero_etapa'] === (int) ($processo['etapa_atual'] ?? 1)
                    && !in_array($et['status'], ['pulado', 'aguardando_assinatura'], true)) {
                    $etapaAdmin = $et;
                    break;
                }
            }
            if ($etapaAdmin) {
                $membrosReatribuicaoAdmin = $etapaAdmin['tipo_aprovador'] === 'grupo'
                    ? Process::getMembrosGrupo($etapaAdmin['aprovador_id'])
                    : Process::getUsuariosAtivos();
            }
        }

        // Templates publicados para o modal "Alterar fluxo" (aba Fluxo) — só
        // para quem tem a permissão, com o processo em andamento e ainda na
        // primeira etapa (depois disso o fluxo já andou e não se troca mais)
        $templatesFluxo = [];
        if (Permissions::can('processos', 'alterar_fluxo')
            && in_array($processo['status'], ['aberto', 'devolvido'], true)
            && (int)($processo['etapa_atual'] ?? 1) <= 1) {
            $stmtTpl = Database::getInstance()->query(
                // Fluxo desativado fica fora: mandar um processo PARA ele seria
                // a mesma abertura que o `ativo = 0` fecha, por outra porta.
                "SELECT t.id, t.nome, tv.id AS versao_id
                 FROM nf_templates t
                 JOIN nf_template_versoes tv ON tv.id = t.versao_publicada_id
                 WHERE tv.status = 'publicado' AND t.ativo = 1
                 ORDER BY t.nome ASC"
            );
            $templatesFluxo = $stmtTpl->fetchAll();
        }

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $voltar = resolverVoltarProcesso($origemGet, $voltarGet);

        $pageTitle    = h($processo['codigo']);
        // Sidebar acompanha a origem — veio do Dashboard (ex.: aba "Meus
        // Processos"), continua realçando Dashboard, não "Processos".
        $activeModule = $origemGet === 'dashboard' ? 'dashboard' : 'processos';
        $layoutFull   = true;
        $unreadCount  = 0;
        $jsVer  = filemtime(ROOT . '/public/assets/js/modules/processos.js');
        $cssVer = filemtime(ROOT . '/public/assets/css/modules/processos.css');
        $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/processos.css?v=' . $cssVer . '">'
                      . '<script src="' . BASE_URL . '/public/assets/js/modules/processos.js?v=' . $jsVer . '" defer></script>';

        ob_start();
        include VIEWS . '/processos/detalhe.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── Preencher os campos que a ETAPA ATUAL exige ───────
    //
    // Caminho do responsável pela etapa, separado de 'editar_dados': ali é
    // preciso a permissão global processos.editar_dados e se mexe na nota
    // inteira; aqui só entram os campos marcados para esta etapa, e a
    // autorização é ser o aprovador dela. É o que torna possível cumprir a
    // exigência de um campo que só aparece no meio do fluxo.
    case 'preencher_etapa':
        try {
            $processoId = Sanitize::uuid(Sanitize::fromGet(['id'])['id'] ?: ($_POST['processo_id'] ?? ''));
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Processo inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        ProcessAccess::autorizar($processoId, $usuarioId);

        $processoPre = Process::findById($processoId);
        if (!$processoPre) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Processo não encontrado.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        // Só quem está com a etapa na mão preenche — mesma checagem que libera
        // o botão de aprovar na tela do processo.
        $gruposPre = Process::getGruposDoUsuario($usuarioId);
        $etapaPre  = Process::etapaParaUsuario($processoId, $usuarioId, $gruposPre);
        if (!$etapaPre) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' =>
                'Só o responsável pela etapa atual pode preencher os dados dela.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        $numEtapaPre  = (int) $etapaPre['numero_etapa'];
        $camposEtapa  = Process::camposDaEtapa($processoId, $numEtapaPre);
        $dadosAtuais  = is_array($processoPre['dados_formulario'] ?? null) ? $processoPre['dados_formulario'] : [];

        if (empty($camposEtapa)) {
            $_SESSION['flash'] = ['type' => 'info', 'message' => 'Esta etapa não pede nenhum campo.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::validate($_POST['csrf_token'] ?? '');
            try {
                $mudancas = Process::salvarCamposDaEtapa(
                    $processoId,
                    $numEtapaPre,
                    (array) ($_POST['campo'] ?? []),
                    $usuarioId,
                    (string) $currentUser['nome']
                );
                $_SESSION['flash'] = ['type' => 'success', 'message' => $mudancas
                    ? 'Dados da etapa salvos.'
                    : 'Nada foi alterado.'];
            } catch (Throwable $e) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
            }
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        ob_start();
        include VIEWS . '/processos/preencher_etapa.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── Editar dados do processo em qualquer etapa ────────
    // GET  → formulário · POST → grava e volta para o detalhe.
    case 'editar_dados':
        try {
            $processoId = Sanitize::uuid(Sanitize::fromGet(['id'])['id'] ?: ($_POST['processo_id'] ?? ''));
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Processo inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        if (!Permissions::can('processos', 'editar_dados')) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Você não tem permissão para alterar dados do processo.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        // Ver o processo continua valendo: quem não enxerga também não edita.
        ProcessAccess::autorizar($processoId, $usuarioId);

        $processo = Process::findById($processoId);
        if (!$processo) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Processo não encontrado.'];
            header('Location: ?module=processos&action=index');
            exit;
        }
        if (in_array($processo['status'], ['cancelado', 'encerrado_apollo'], true)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Processo cancelado ou encerrado não pode ser editado.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        $camposTpl  = json_decode((string) ($processo['template_campos'] ?? '[]'), true) ?: [];
        // Campo de lista aponta para tabela auxiliar (lista_id) — sem carregar as
        // opções a tela cairia em texto livre e gravaria o texto por cima do id.
        foreach ($camposTpl as &$campoTpl) {
            $campoTpl['opcoes'] = Process::opcoesCampo($campoTpl);
        }
        unset($campoTpl);
        $filiaisEd  = Process::getFiliais();
        $formasEd   = Database::getInstance()->query(
            "SELECT i.id, i.valor AS nome
               FROM nf_lista_itens i JOIN nf_listas l ON l.id = i.lista_id
              WHERE l.slug = 'formas-pagamento' AND i.ativo = 1 AND l.ativo = 1
              ORDER BY i.valor"
        )->fetchAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::validate($_POST['csrf_token'] ?? '');

            try {
                $doc    = preg_replace('/\D/', '', (string) ($_POST['fornecedor_doc'] ?? ''));
                $nome   = trim(strip_tags((string) ($_POST['fornecedor_nome'] ?? '')));
                $numero = trim(strip_tags((string) ($_POST['numero_nf'] ?? '')));
                $dataBr = trim((string) ($_POST['data_nf'] ?? ''));
                $valorS = trim((string) ($_POST['valor_nf'] ?? ''));
                $chave  = preg_replace('/\D/', '', (string) ($_POST['chave_nf'] ?? ''));
                $filial = Sanitize::uuid((string) ($_POST['filial_id'] ?? ''));

                if (!in_array(strlen($doc), [11, 14], true)) throw new RuntimeException('CNPJ/CPF inválido — informe 11 ou 14 dígitos.');
                if ($nome === '')   throw new RuntimeException('Informe o fornecedor.');
                if ($numero === '') throw new RuntimeException('Informe o número da nota.');
                if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dataBr)) throw new RuntimeException('Data de emissão inválida (dd/mm/aaaa).');

                // A chave só é cobrada quando MUDA. Processo antigo pode ter
                // sido gravado com chave fora do padrão (digitada pela metade,
                // vinda de importação antiga) — exigir 44/50 dela travava a
                // edição inteira: quem quisesse corrigir o fornecedor ou a
                // parcela batia neste erro sem ter a chave certa em mãos.
                $chaveAtual = preg_replace('/\D/', '', (string) ($processo['chave_nf'] ?? ''));
                if ($chave !== '' && $chave !== $chaveAtual && !in_array(strlen($chave), [44, 50], true)) {
                    throw new RuntimeException('Chave de acesso deve ter 44 dígitos (NF-e/CT-e) ou 50 (NFS-e). Apague o campo para gravar sem chave.');
                }

                $valor = (float) str_replace(['.', ','], ['', '.'], $valorS);
                if ($valor <= 0) throw new RuntimeException('Valor inválido.');

                [$d, $m, $a] = explode('/', $dataBr);
                $dataIso = "{$a}-{$m}-{$d}";

                // Duplicidade: ignora o próprio processo, senão ele bate consigo.
                $dup = Process::notaDuplicada($numero, null, $doc, $chave ?: null, $processoId);
                if ($dup) throw new RuntimeException("Já existe o processo {$dup['codigo']} para esta nota.");

                $filialRow = Database::getInstance()->prepare(
                    "SELECT REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','') AS cnpj FROM filiais WHERE id = ? LIMIT 1"
                );
                $filialRow->execute([$filial]);
                $filialDoc = (string) ($filialRow->fetchColumn() ?: '');

                $formNovo = [];
                foreach ($camposTpl as $c) {
                    $nomeCampo = (string) ($c['nome'] ?? '');
                    if ($nomeCampo === '') continue;
                    $formNovo[$nomeCampo] = trim(strip_tags((string) ($_POST['campo'][$nomeCampo] ?? '')));
                }

                $parcelas = [];
                foreach ((array) ($_POST['parcela'] ?? []) as $p) {
                    $venc = trim((string) ($p['vencimento'] ?? ''));
                    $val  = (float) str_replace(['.', ','], ['', '.'], (string) ($p['valor'] ?? '0'));
                    if ($venc === '' && $val <= 0) continue;
                    if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $venc)) throw new RuntimeException('Vencimento de parcela inválido (dd/mm/aaaa).');
                    if ($val <= 0) throw new RuntimeException('Valor de parcela inválido.');
                    [$pd, $pm, $pa] = explode('/', $venc);
                    $parcelas[] = [
                        'vencimento'         => "{$pa}-{$pm}-{$pd}",
                        'valor'              => $val,
                        'forma_pagamento_id' => trim((string) ($p['forma_pagamento_id'] ?? '')),
                    ];
                }

                $mudancas = Process::editarDados($processoId, [
                    'fornecedor_nome'  => $nome,
                    'fornecedor_doc'   => $doc,
                    'numero_nf'        => $numero,
                    'valor_nf'         => $valor,
                    'data_nf'          => $dataIso,
                    'chave_nf'         => $chave,
                    'filial_id'        => $filial,
                    'filial_doc'       => $filialDoc,
                    'dados_formulario' => $formNovo,
                    'parcelas'         => $parcelas,
                    'tem_financeiro'   => !empty($processo['tem_financeiro']),
                ], $usuarioId, $currentUser['nome']);

                AuditLog::registrar('nf_processo', $processoId, 'nf_processo.dados_alterados', null, [
                    'codigo'    => $processo['codigo'],
                    'alteracoes' => $mudancas,
                ]);

                $_SESSION['flash'] = $mudancas
                    ? ['type' => 'success', 'message' => count($mudancas) . ' alteração(ões) gravada(s) e registrada(s) no histórico.']
                    : ['type' => 'info', 'message' => 'Nenhuma alteração a gravar.'];
                header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
                exit;
            } catch (Throwable $e) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
                header('Location: ?module=processos&action=editar_dados&id=' . $processoId . $embedQS . $backQS);
                exit;
            }
        }

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $pageTitle    = 'Editar ' . h($processo['codigo']);
        $activeModule = 'processos';
        $unreadCount  = 0;

        ob_start();
        include VIEWS . '/processos/editar_dados.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── POST: aprovar etapa ───────────────────────────────
    case 'aprovar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=processos&action=index');
            exit;
        }

        if (!Permissions::can('processos', 'aprovar')) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Você não tem permissão para aprovar etapas.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'processo_id', 'etapa_id']);
        Csrf::validate($post['csrf_token'] ?? '');

        try {
            $processoId = Sanitize::uuid($post['processo_id']);
            $etapaId    = Sanitize::uuid($post['etapa_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        // Verificar permissão de aprovação
        $gruposIds = Process::getGruposDoUsuario($usuarioId);
        $etapa = Process::etapaParaUsuario($processoId, $usuarioId, $gruposIds);
        if (!$etapa || $etapa['id'] !== $etapaId) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Você não tem permissão para aprovar este processo.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        try {
            // Última etapa: o processo é concluído, não "encaminhado pra próxima etapa"
            $stmtTotalEtapas = Database::getInstance()->prepare(
                'SELECT COUNT(*) FROM nf_processo_etapas WHERE processo_id = ?'
            );
            $stmtTotalEtapas->execute([$processoId]);
            $totalEtapasProc = (int) $stmtTotalEtapas->fetchColumn();
            $eraUltimaEtapa  = (int) $etapa['numero_etapa'] >= $totalEtapasProc;

            Process::aprovar($processoId, $etapaId, $usuarioId, $currentUser['nome']);
            AuditLog::registrar('nf_processo', $processoId, 'nf_processo.etapa_aprovada', null, ['etapa_id' => $etapaId]);

            if ($eraUltimaEtapa) {
                $processoAprovado = Process::findById($processoId);
                $msgAprovar = !empty($processoAprovado['tem_financeiro'])
                    ? 'Etapa aprovada com sucesso. Processo concluído — encaminhado para o Controle Financeiro.'
                    : 'Etapa aprovada com sucesso. Processo concluído.';
            } else {
                $msgAprovar = 'Etapa aprovada com sucesso. O processo foi encaminhado para a próxima etapa.';
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => $msgAprovar];
        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro ao aprovar: ' . $e->getMessage()];
        }

        header('Location: ' . $destinoPosAcao);
        exit;

    // ── POST: devolver processo ───────────────────────────
    case 'devolver':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=processos&action=index');
            exit;
        }

        if (!Permissions::can('processos', 'devolver')) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Você não tem permissão para devolver etapas.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'processo_id', 'etapa_id', 'motivo']);
        Csrf::validate($post['csrf_token'] ?? '');

        try {
            $processoId = Sanitize::uuid($post['processo_id']);
            $etapaId    = Sanitize::uuid($post['etapa_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        $motivo = strip_tags(trim($post['motivo']));
        if (mb_strlen($motivo) < 10) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'O motivo da devolução deve ter pelo menos 10 caracteres.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        // Verificar permissão de devolução
        $gruposIds = Process::getGruposDoUsuario($usuarioId);
        $etapa = Process::etapaParaUsuario($processoId, $usuarioId, $gruposIds);
        if (!$etapa || $etapa['id'] !== $etapaId) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Você não tem permissão para devolver este processo.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        // Destino escolhido pelo aprovador — uma PESSOA. O número da etapa é
        // derivado dela no model. `etapa_destino` fica só como retaguarda para
        // requisições antigas que ainda mandem o número.
        $usuarioDestino = null;
        if (!empty($_POST['usuario_destino'])) {
            try {
                $usuarioDestino = Sanitize::uuid((string) $_POST['usuario_destino']);
            } catch (InvalidArgumentException $e) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Destino da devolução inválido.'];
                header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
                exit;
            }
        }
        $etapaDestino = null;
        if (isset($_POST['etapa_destino']) && $_POST['etapa_destino'] !== '') {
            $etapaDestino = (int) $_POST['etapa_destino'];
        }

        try {
            Process::devolver($processoId, $etapaId, $usuarioId, $currentUser['nome'], $motivo, $etapaDestino, $usuarioDestino);
            AuditLog::registrar('nf_processo', $processoId, 'nf_processo.devolvido', null, [
                'motivo' => $motivo, 'etapa_destino' => $etapaDestino, 'usuario_destino' => $usuarioDestino,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Processo devolvido com sucesso. A etapa de destino foi reaberta para ajuste.'];
        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro ao devolver: ' . $e->getMessage()];
        }

        header('Location: ' . $destinoPosAcao);
        exit;

    // ── POST: reenviar processo devolvido ───────────────────
    case 'reenviar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=processos&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'processo_id']);
        Csrf::validate($post['csrf_token'] ?? '');

        try {
            $processoId = Sanitize::uuid($post['processo_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        try {
            Process::reenviar($processoId, $usuarioId, $currentUser['nome']);
            AuditLog::registrar('nf_processo', $processoId, 'nf_processo.reenviado');
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Processo reenviado para aprovação.'];
        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro: ' . $e->getMessage()];
        }

        header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
        exit;

    // ── POST: cancelar processo ───────────────────────────
    case 'cancelar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=processos&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'processo_id']);
        Csrf::validate($post['csrf_token'] ?? '');

        try {
            $processoId = Sanitize::uuid($post['processo_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        // Verificar que processo existe e pertence à filial do usuário (ou é gestor)
        $processo = Process::findById($processoId);
        if (!$processo) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Processo não encontrado.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        // 'cancelamento_solicitado' entra na lista: é por aqui que a solicitação
        // é CONFIRMADA (não existe action separada). Sem ele o processo ficava
        // preso no estado — nem cancelava, nem voltava para andamento.
        if (!in_array($processo['status'], ['aberto', 'devolvido', 'cancelamento_solicitado'], true)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Este processo não pode ser cancelado.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        // Cancelar depende só da permissão. O atalho "sou o dono" saiu: com ele,
        // qualquer solicitante cancelava o próprio processo sem liberação, que
        // é justamente o que o controle de acesso deste botão precisa impedir.
        //
        // São DUAS liberações: `cancelar` vale em qualquer etapa; a de abertura
        // só enquanto o processo não passou por nenhuma aprovação (etapa 1) —
        // é a de quem precisa desfazer o próprio lançamento errado.
        //
        // "Descartar teste" posta para cá: processo de teste aceita também quem
        // tem descartar_teste, senão o botão apareceria e o servidor recusaria.
        // A liberação "só na abertura" vale enquanto o processo está em
        // andamento na etapa 1. Confirmar um cancelamento SOLICITADO não entra
        // nela: a solicitação nasce de processo que já andou no fluxo.
        $naAbertura    = (int) ($processo['etapa_atual'] ?? 1) <= 1
                      && in_array($processo['status'], ['aberto', 'devolvido'], true);
        $podesCancelar = Permissions::can('processos', 'cancelar')
            || ($naAbertura && Permissions::can('processos', 'cancelar_abertura'))
            || ((int) ($processo['is_teste'] ?? 0) === 1 && Permissions::can('processos', 'descartar_teste'));

        if (!$podesCancelar) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Você não tem permissão para cancelar este processo.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        try {
            Process::cancelar($processoId, $usuarioId, $currentUser['nome']);
            AuditLog::registrar('nf_processo', $processoId, 'nf_processo.cancelado');
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Processo cancelado.'];
        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro ao cancelar: ' . $e->getMessage()];
        }

        header('Location: ?module=processos&action=index');
        exit;

    // ── GET: download de anexo ────────────────────────────
    case 'download_anexo':
        $get = Sanitize::fromGet(['anexo_id', 'processo_id']);

        try {
            $anexoId    = Sanitize::uuid($get['anexo_id']);
            $processoId = Sanitize::uuid($get['processo_id']);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo 'ID inválido.';
            exit;
        }

        // Verificar que o processo existe
        $processo = Process::findById($processoId);
        if (!$processo) {
            http_response_code(404);
            echo 'Processo não encontrado.';
            exit;
        }

        // Mesmo gate central usado em detalhe/criar_observacao — admin,
        // ver_todos, participação ou liberação granular pontual.
        if (!ProcessAccess::pode($processoId, $usuarioId)) {
            AuditLog::registrar('nf_processo', $processoId, 'nf_processo.acesso_negado', null, ['contexto' => 'download_anexo']);
            http_response_code(403);
            echo 'Acesso negado.';
            exit;
        }

        // Buscar anexo verificando que pertence ao processo
        $anexo = Process::findAnexo($anexoId, $processoId);
        if (!$anexo) {
            http_response_code(404);
            echo 'Anexo não encontrado.';
            exit;
        }

        $caminhoFisico = $anexo['caminho'];
        if (!file_exists($caminhoFisico) || !is_file($caminhoFisico)) {
            http_response_code(404);
            echo 'Arquivo não encontrado no servidor.';
            exit;
        }

        // Servir o arquivo — inline para preview ou attachment para download
        $nomeDownload = preg_replace('/[^a-zA-Z0-9._-]/', '_', $anexo['nome_original']);
        $mode = ($_GET['inline'] ?? '') === '1' ? 'inline' : 'attachment';

        // XML no viewer: servido com o mime gravado o navegador o trata como
        // HTML e as tags somem (texto corrido ilegível). Aqui vai indentado e
        // escapado, em bloco monoespaçado. Download continua sendo o arquivo.
        if ($mode === 'inline' && strtolower(pathinfo($anexo['nome_original'], PATHINFO_EXTENSION)) === 'xml') {
            $bruto = (string) file_get_contents($caminhoFisico);
            $dom   = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput       = true;
            $prev = libxml_use_internal_errors(true);
            $okXml = $dom->loadXML($bruto, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            $texto = $okXml ? (string) $dom->saveXML() : $bruto;
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: private, no-cache');
            header('X-Content-Type-Options: nosniff');
            echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>'
               . 'body{margin:0;padding:16px;background:#fff;color:#141922}'
               . 'pre{margin:0;font:12px/1.5 Consolas,"Courier New",monospace;white-space:pre-wrap;word-break:break-all}'
               . '</style></head><body><pre>' . htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre></body></html>';
            exit;
        }
        header('Content-Type: ' . $anexo['mime_type']);
        header('Content-Disposition: ' . $mode . '; filename="' . $nomeDownload . '"');
        header('Content-Length: ' . $anexo['tamanho_bytes']);
        header('Cache-Control: private, no-cache');
        header('X-Content-Type-Options: nosniff');
        readfile($caminhoFisico);
        exit;

    // ── POST: solicitar cancelamento (com parcelas) ────────
    case 'solicitar_cancelamento':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=processos&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'processo_id', 'motivo_cancelamento']);
        Csrf::validate($post['csrf_token'] ?? '');

        try {
            $processoId = Sanitize::uuid($post['processo_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID de processo inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        if (!Permissions::can('processos', 'solicitar_cancelamento')) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Você não tem permissão para solicitar cancelamento deste processo.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        $motivo = trim($post['motivo_cancelamento']);
        $ok = Process::solicitarCancelamento($processoId, $usuarioId, $currentUser['nome'], $motivo);

        if ($ok) {
            AuditLog::registrar('nf_processo', $processoId, 'nf_processo.cancelamento_solicitado',
                ['motivo' => $motivo], ['status' => 'cancelamento_solicitado']);
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Cancelamento solicitado. Aguardando confirmação do financeiro.'];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Não foi possível solicitar o cancelamento.'];
        }

        header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
        exit;

    // ── POST: desfazer solicitação de cancelamento ─────────
    case 'desfazer_cancelamento':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=processos&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'processo_id']);
        Csrf::validate($post['csrf_token'] ?? '');

        try {
            $processoId = Sanitize::uuid($post['processo_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID de processo inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        if (!Permissions::can('processos', 'cancelar')) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Sem permissão para esta ação.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        $ok = Process::desfazerCancelamento($processoId, $usuarioId, $currentUser['nome']);
        if ($ok) {
            AuditLog::registrar('nf_processo', $processoId, 'nf_processo.cancelamento_desfeito');
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Solicitação de cancelamento desfeita. Processo retornou para andamento.'];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Não foi possível desfazer a solicitação.'];
        }

        header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
        exit;

    // ── POST: reatribuir responsável da etapa atual ────────
    case 'reatribuir':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=processos&action=index');
            exit;
        }

        // Dois caminhos, mesma ação de fundo (Process::reatribuir):
        //   - self-service: só quem já é dono da etapa (usuário ou membro do
        //     grupo dono) reatribui, e só para outro membro do mesmo grupo;
        //   - admin: quem tem tarefas.reatribuir_admin reatribui QUALQUER
        //     etapa ativa, mesmo sem ser o dono — cobre férias/afastamento
        //     sem precisar logar como a pessoa.
        $podeAdmin = Permissions::can('tarefas', 'reatribuir_admin');
        if (!$podeAdmin) {
            Permissions::require('tarefas', 'reatribuir');
        }

        $post = Sanitize::fromPost(['csrf_token', 'processo_id', 'etapa_id', 'novo_aprovador_id']);
        Csrf::validate($post['csrf_token'] ?? '');

        try {
            $processoId     = Sanitize::uuid($post['processo_id']);
            $etapaId        = Sanitize::uuid($post['etapa_id']);
            $novoAprovadorId = Sanitize::uuid($post['novo_aprovador_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Identificador inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        if (!$podeAdmin && $novoAprovadorId === $usuarioId) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Você já é o responsável atual.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . '&aba=fluxo' . $embedQS . $backQS);
            exit;
        }

        if ($podeAdmin) {
            // ── Caminho admin: não exige posse da etapa, só que ela exista,
            // pertença a este processo e ainda esteja pendente. O destino
            // continua restrito ao grupo quando a etapa é de grupo — a posse
            // do time sobre a etapa é regra de negócio, não só de quem pediu.
            $etapaAlvo = Process::getEtapaPendente($processoId, $etapaId);

            if (!$etapaAlvo) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Não foi possível reatribuir. Verifique se a etapa ainda está pendente.'];
                header('Location: ?module=processos&action=detalhe&id=' . $processoId . '&aba=fluxo' . $embedQS . $backQS);
                exit;
            }

            if ($etapaAlvo['tipo_aprovador'] === 'grupo') {
                $membrosDoGrupo = Process::getMembrosGrupo($etapaAlvo['aprovador_id']);
                $idsValidos     = array_column($membrosDoGrupo, 'id');
                if (!in_array($novoAprovadorId, $idsValidos, true)) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'O usuário selecionado não pertence ao time responsável por esta etapa.'];
                    header('Location: ?module=processos&action=detalhe&id=' . $processoId . '&aba=fluxo' . $embedQS . $backQS);
                    exit;
                }
            } else {
                $stmtAtivo = Database::getInstance()->prepare('SELECT 1 FROM usuarios WHERE id = ? AND ativo = 1 LIMIT 1');
                $stmtAtivo->execute([$novoAprovadorId]);
                if (!$stmtAtivo->fetchColumn()) {
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Usuário inválido ou inativo.'];
                    header('Location: ?module=processos&action=detalhe&id=' . $processoId . '&aba=fluxo' . $embedQS . $backQS);
                    exit;
                }
            }
        } else {
            // ── Caminho self-service: o ator precisa ser o responsável atual
            // (dono direto da etapa ou membro do grupo dono) — nunca confiar
            // apenas no botão estar visível no HTML. O destino também precisa
            // pertencer ao mesmo grupo responsável pela etapa; sem essa checagem
            // um POST manipulado poderia reatribuir para qualquer usuário do
            // sistema, mesmo sem acesso ao processo.
            $gruposIdsAtor = Process::getGruposDoUsuario($usuarioId);
            $etapaAtual    = Process::etapaParaUsuario($processoId, $usuarioId, $gruposIdsAtor);

            if (!$etapaAtual || $etapaAtual['id'] !== $etapaId || $etapaAtual['tipo_aprovador'] !== 'grupo') {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Você não tem permissão para reatribuir esta etapa.'];
                header('Location: ?module=processos&action=detalhe&id=' . $processoId . '&aba=fluxo' . $embedQS . $backQS);
                exit;
            }

            $membrosDoGrupo   = Process::getMembrosGrupo($etapaAtual['aprovador_id']);
            $idsValidos       = array_column($membrosDoGrupo, 'id');

            if (!in_array($novoAprovadorId, $idsValidos, true)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'O usuário selecionado não pertence ao time responsável por esta etapa.'];
                header('Location: ?module=processos&action=detalhe&id=' . $processoId . '&aba=fluxo' . $embedQS . $backQS);
                exit;
            }
        }

        $ok = Process::reatribuir($processoId, $etapaId, $novoAprovadorId, $usuarioId, $currentUser['nome']);

        if ($ok) {
            AuditLog::registrar('nf_processo_etapa', $etapaId, 'nf_etapa.reatribuida',
                [], ['novo_aprovador' => $novoAprovadorId, 'por' => $usuarioId, 'admin' => $podeAdmin]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => $podeAdmin
                ? 'Responsável reatribuído com sucesso (ação administrativa).'
                : 'Responsável reatribuído com sucesso.'];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Não foi possível reatribuir. Verifique se a etapa ainda está pendente.'];
        }

        header('Location: ?module=processos&action=detalhe&id=' . $processoId . '&aba=fluxo' . $embedQS . $backQS);
        exit;

    // ── POST: alterar fluxo do processo ───────────────────
    // Processo encaminhado para o template errado: troca para o novo fluxo
    // e volta ao início dele, preservando todo o histórico.
    case 'alterar_fluxo':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=processos&action=index');
            exit;
        }

        Permissions::require('processos', 'alterar_fluxo');

        $post = Sanitize::fromPost(['csrf_token', 'processo_id', 'template_id', 'motivo']);
        Csrf::validate($post['csrf_token'] ?? '');

        try {
            $processoId = Sanitize::uuid($post['processo_id']);
            $templateId = Sanitize::uuid($post['template_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Identificador inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        $motivo = mb_substr(strip_tags(trim($post['motivo'] ?? '')), 0, 500);
        if ($motivo === '') {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Informe o motivo da alteração de fluxo.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . '&aba=fluxo' . $embedQS . $backQS);
            exit;
        }

        // Acesso ao processo segue a mesma régua da tela de detalhe
        ProcessAccess::autorizar($processoId, $usuarioId);

        // Só na primeira etapa: com o fluxo já andado, trocar o template
        // devolveria o processo ao início descartando aprovações dadas.
        $procFluxo = Process::findById($processoId);
        if (!$procFluxo
            || !in_array($procFluxo['status'], ['aberto', 'devolvido'], true)
            || (int)($procFluxo['etapa_atual'] ?? 1) > 1) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'O fluxo só pode ser alterado enquanto o processo estiver na primeira etapa.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . '&aba=fluxo' . $embedQS . $backQS);
            exit;
        }

        try {
            Process::alterarFluxo($processoId, $templateId, $usuarioId, $currentUser['nome'], $motivo);
            AuditLog::registrar('nf_processo', $processoId, 'nf_processo.fluxo_alterado', null,
                ['novo_template' => $templateId, 'motivo' => $motivo]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Fluxo alterado. O processo voltou ao início do novo fluxo e os aprovadores foram notificados.'];
        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
        }

        header('Location: ?module=processos&action=detalhe&id=' . $processoId . '&aba=fluxo' . $embedQS . $backQS);
        exit;

    // ── POST: criar observação no processo ────────────────
    case 'criar_observacao':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=processos&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'processo_id', 'texto']);
        Csrf::validate($post['csrf_token'] ?? '');

        try {
            $processoId = Sanitize::uuid($post['processo_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID de processo inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        ProcessAccess::autorizar($processoId, $usuarioId);

        $texto = trim($post['texto']);
        if ($texto === '') {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'A observação não pode estar vazia.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        require_once SRC . '/Models/Observation.php';
        Observation::criar($processoId, $usuarioId, $currentUser['nome'], $texto);

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Observação adicionada.'];
        header('Location: ?module=processos&action=detalhe&id=' . $processoId . '&aba=observacoes' . $embedQS . $backQS);
        exit;

    // ── POST: substituir ou enviar anexo em processo existente ──
    case 'substituir_anexo':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=processos&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'processo_id', 'tipo_anexo_nome', 'modo', 'anexo_id']);
        Csrf::validate($post['csrf_token'] ?? '');

        try {
            $processoId = Sanitize::uuid($post['processo_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID de processo inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        $processo = Process::findById($processoId);
        if (!$processo) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Processo não encontrado.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        // Modo: 'adicionar' (soma novos, mantém existentes) ou 'substituir' (troca).
        $modo = in_array($post['modo'] ?? '', ['adicionar', 'substituir'], true) ? $post['modo'] : 'substituir';

        // Controle de acesso por etapa (mesma régua da tela de detalhe):
        //  · adicionar   — participantes com o processo em andamento; encerrado, só admin
        //  · substituir  — só o responsável pela etapa ativa (ou admin) e em andamento
        $gruposIdsAnexo = Process::getGruposDoUsuario($usuarioId);
        // 'gerenciar_anexos' substitui o super_admin fixo: é o override que
        // permite mexer em anexo fora da sua etapa ou com o processo encerrado.
        // Quem participa continua anexando pela regra de etapa, como sempre.
        $isAdminAnexo   = Permissions::can('processos', 'gerenciar_anexos');
        $isRespEtapa    = Process::etapaParaUsuario($processoId, $usuarioId, $gruposIdsAnexo) !== null;
        $emAndamento    = in_array($processo['status'], ['aberto', 'devolvido'], true);

        if ($modo === 'substituir') {
            $liberado = $emAndamento && ($isRespEtapa || $isAdminAnexo);
            $recusa   = 'Só o responsável pela etapa atual pode substituir anexos, e apenas com o processo em andamento.';
        } else {
            $liberado = $emAndamento
                ? ($isRespEtapa || $isAdminAnexo || ($processo['criado_por'] ?? '') === $usuarioId)
                : $isAdminAnexo;
            $recusa   = 'Você não tem permissão para anexar documentos neste processo.';
        }
        if (!$liberado) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => $recusa];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        $tipoAnexoNome = trim($post['tipo_anexo_nome']);
        if ($tipoAnexoNome === '') {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Tipo de anexo não informado.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        // anexo_id = alvo específico da substituição (quando trocar um arquivo pontual).
        $anexoIdAlvo = null;
        if (!empty($post['anexo_id'])) {
            try { $anexoIdAlvo = Sanitize::uuid($post['anexo_id']); }
            catch (InvalidArgumentException $e) { $anexoIdAlvo = null; }
        }

        if (empty($_FILES['arquivo'])) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Nenhum arquivo selecionado.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        // Resolver tipo_doc do template para validar extensão
        $tiposAnexoTpl = json_decode($processo['template_tipos_anexo'] ?? '[]', true) ?: [];
        $tipoDoc = 'qualquer';
        foreach ($tiposAnexoTpl as $ta) {
            if (($ta['nome'] ?? '') === $tipoAnexoNome) { $tipoDoc = $ta['tipo_doc'] ?? 'qualquer'; break; }
        }
        $extPorTipo = [
            'qualquer' => ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','txt','csv'],
            'pdf' => ['pdf'], 'imagem' => ['jpg','jpeg','png','webp'],
            'pdf_imagem' => ['pdf','jpg','jpeg','png','webp'],
            'excel' => ['xls','xlsx'], 'word' => ['doc','docx'],
            // Vídeo/áudio só entram em tipo declarado como tal — fora de
            // 'qualquer' de propósito: senão todo anexo do sistema viraria
            // porta de 5 MB.
            'video' => array_merge(VIDEO_EXTENSIONS, AUDIO_EXTENSIONS),
        ];
        $extPermit     = $extPorTipo[$tipoDoc] ?? $extPorTipo['qualquer'];
        $tipoDocLabels = ['qualquer'=>'qualquer formato','pdf'=>'PDF','imagem'=>'imagem (JPG, PNG, WEBP)','pdf_imagem'=>'PDF ou imagem','excel'=>'planilha (XLS, XLSX)','word'=>'Word (DOC, DOCX)','video'=>'áudio ou vídeo (MP4, WEBM, MOV, OGG, MP3, M4A, WAV) de até 5 MB'];

        // Normalizar: input único (name="arquivo") ou múltiplo (name="arquivo[]")
        $entradaArq = $_FILES['arquivo'];
        $arquivos = [];
        if (is_array($entradaArq['name'])) {
            for ($i = 0; $i < count($entradaArq['name']); $i++) {
                $arquivos[] = [
                    'name' => $entradaArq['name'][$i], 'type' => $entradaArq['type'][$i] ?? '',
                    'tmp_name' => $entradaArq['tmp_name'][$i] ?? '', 'error' => $entradaArq['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $entradaArq['size'][$i] ?? 0,
                ];
            }
        } else {
            $arquivos[] = $entradaArq;
        }
        $arquivos = array_values(array_filter($arquivos, fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
        if (empty($arquivos)) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Nenhum arquivo selecionado.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }
        // Substituir troca um por vez
        if ($modo === 'substituir') { $arquivos = array_slice($arquivos, 0, 1); }

        // Validar extensão de cada arquivo
        foreach ($arquivos as $arq) {
            $ext = strtolower(pathinfo($arq['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, $extPermit, true)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => "Este anexo aceita apenas {$tipoDocLabels[$tipoDoc]}. Você enviou .{$ext}."];
                header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
                exit;
            }
        }

        try {
            require_once SRC . '/Helpers/Upload.php';
            $pdo    = Database::getInstance();
            $subdir = $processo['is_teste'] ? 'test' : 'processos';

            // No modo substituir, arquivar o alvo (específico por id, ou todos do tipo se não veio id)
            $hadOld = false;
            if ($modo === 'substituir') {
                if ($anexoIdAlvo) {
                    $stmtOld = $pdo->prepare("UPDATE nf_processo_anexos SET deletado_em = NOW() WHERE processo_id = ? AND id = ? AND deletado_em IS NULL");
                    $stmtOld->execute([$processoId, $anexoIdAlvo]);
                } else {
                    $stmtOld = $pdo->prepare("UPDATE nf_processo_anexos SET deletado_em = NOW() WHERE processo_id = ? AND tipo_anexo_nome = ? AND deletado_em IS NULL");
                    $stmtOld->execute([$processoId, $tipoAnexoNome]);
                }
                $hadOld = $stmtOld->rowCount() > 0;
            }

            $stmtIns = $pdo->prepare(
                "INSERT INTO nf_processo_anexos
                    (id, processo_id, tipo_anexo_nome, nome_original, nome_armazenado,
                     caminho, mime_type, tamanho_bytes, is_teste, enviado_por, enviado_em)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );

            $nomesEnviados = [];
            foreach ($arquivos as $arq) {
                $meta = Upload::salvar($arq, $subdir, false);
                $anexoId = Process::uuid_v4();
                $stmtIns->execute([
                    $anexoId, $processoId, $tipoAnexoNome,
                    $meta['nome_original'], $meta['nome_armazenado'],
                    $meta['caminho'], $meta['mime_type'], $meta['tamanho_bytes'],
                    (int) $processo['is_teste'], $usuarioId,
                ]);
                $nomesEnviados[] = $meta['nome_original'];
            }

            // Histórico + flash conforme o modo
            if ($modo === 'substituir') {
                $acao    = $hadOld ? 'arquivo_substituido' : 'arquivo_enviado';
                $detalhe = ($hadOld ? "Arquivo substituído: " : "Arquivo enviado: ") . "{$tipoAnexoNome} → {$nomesEnviados[0]}";
                $msg     = $hadOld ? 'Documento substituído com sucesso.' : 'Documento enviado com sucesso.';
            } else {
                $acao    = 'arquivo_enviado';
                $n       = count($nomesEnviados);
                $detalhe = "{$n} arquivo(s) adicionado(s) ao tipo {$tipoAnexoNome}: " . implode(', ', $nomesEnviados);
                $msg     = $n === 1 ? 'Documento adicionado com sucesso.' : "{$n} documentos adicionados com sucesso.";
            }
            Process::registrarHistorico($processoId, $acao, $usuarioId, $currentUser['nome'], $detalhe);
            AuditLog::registrar('nf_processo_anexo', $processoId, 'nf_anexo.' . $acao,
                [], ['tipo' => $tipoAnexoNome, 'arquivos' => $nomesEnviados, 'modo' => $modo]);

            $_SESSION['flash'] = ['type' => 'success', 'message' => $msg];

        } catch (\Exception $e) {
            error_log('[ProcessController::substituir_anexo] ' . $e->getMessage());
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro ao salvar o arquivo: ' . $e->getMessage()];
        }

        header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
        exit;

    // ── POST: excluir um anexo específico de processo existente ──
    case 'excluir_anexo':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=processos&action=index');
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'processo_id', 'anexo_id']);
        Csrf::validate($post['csrf_token'] ?? '');

        try {
            $processoId = Sanitize::uuid($post['processo_id']);
            $anexoId    = Sanitize::uuid($post['anexo_id']);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ID inválido.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        $processo = Process::findById($processoId);
        if (!$processo) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Processo não encontrado.'];
            header('Location: ?module=processos&action=index');
            exit;
        }

        // Controle de acesso por etapa: excluir é alteração — só o responsável
        // pela etapa ativa (ou admin) e só com o processo em andamento.
        $gruposIdsDel = Process::getGruposDoUsuario($usuarioId);
        $podeExcluir  = in_array($processo['status'], ['aberto', 'devolvido'], true)
            && (Process::etapaParaUsuario($processoId, $usuarioId, $gruposIdsDel) !== null
                || Permissions::can('processos', 'gerenciar_anexos'));
        if (!$podeExcluir) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Só o responsável pela etapa atual pode excluir anexos, e apenas com o processo em andamento.'];
            header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
            exit;
        }

        try {
            $pdo   = Database::getInstance();
            $stmtA = $pdo->prepare("SELECT tipo_anexo_nome, nome_original FROM nf_processo_anexos WHERE id = ? AND processo_id = ? AND deletado_em IS NULL LIMIT 1");
            $stmtA->execute([$anexoId, $processoId]);
            $anx = $stmtA->fetch();

            if ($anx) {
                $pdo->prepare("UPDATE nf_processo_anexos SET deletado_em = NOW() WHERE id = ? AND processo_id = ?")->execute([$anexoId, $processoId]);
                Process::registrarHistorico($processoId, 'arquivo_excluido', $usuarioId, $currentUser['nome'], "Arquivo removido: {$anx['tipo_anexo_nome']} — {$anx['nome_original']}");
                AuditLog::registrar('nf_processo_anexo', $anexoId, 'nf_anexo.excluido', [], ['tipo' => $anx['tipo_anexo_nome'], 'arquivo' => $anx['nome_original']]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Anexo removido.'];
            } else {
                $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Anexo não encontrado.'];
            }
        } catch (\Exception $e) {
            error_log('[ProcessController::excluir_anexo] ' . $e->getMessage());
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro ao remover o anexo.'];
        }

        header('Location: ?module=processos&action=detalhe&id=' . $processoId . $embedQS . $backQS);
        exit;

    // ── GET AJAX: buscar fornecedores por CNPJ ─────────────
    case 'buscar_fornecedor':
        header('Content-Type: application/json; charset=utf-8');

        $get = Sanitize::fromGet(['q']);
        $q   = trim($get['q']);
        $digitos = preg_replace('/\D/', '', $q);

        if (strlen($digitos) < 3) {
            echo json_encode([]);
            exit;
        }

        $fornecedores = Process::buscarFornecedores($q);
        echo json_encode($fornecedores);
        exit;

    // ── GET: preview de anexo temporário (wizard step3) ─────
    case 'preview_temp':
        $idx = (int) ($_GET['idx'] ?? -1);
        $anexos = $_SESSION['wizard_processos']['anexos_temp'] ?? [];

        if ($idx < 0 || $idx >= count($anexos)) {
            http_response_code(404);
            echo 'Anexo não encontrado.';
            exit;
        }

        $a = $anexos[$idx];
        $caminho = $a['caminho'] ?? '';

        if (!file_exists($caminho) || !is_file($caminho)) {
            http_response_code(404);
            echo 'Arquivo não encontrado no servidor.';
            exit;
        }

        $mime = $a['mime_type'] ?? 'application/octet-stream';
        $nomeSeguro = preg_replace('/[^a-zA-Z0-9._-]/', '_', $a['nome_original']);

        // Imagens e PDFs: inline preview. Demais: download
        $inline = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $nomeSeguro . '"');
        header('Content-Length: ' . filesize($caminho));
        header('Cache-Control: private, no-cache');
        header('X-Content-Type-Options: nosniff');
        readfile($caminho);
        exit;

    // ── POST: substituir anexo temporário (wizard step3) ──
    case 'substituir_temp':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=processos&action=step3');
            exit;
        }

        Csrf::validate($_POST['csrf_token'] ?? '');

        $idx = (int) ($_POST['idx'] ?? -1);
        $wizard = &$_SESSION['wizard_processos'];
        $anexos = $wizard['anexos_temp'] ?? [];

        if ($idx < 0 || $idx >= count($anexos)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Anexo não encontrado.'];
            header('Location: ?module=processos&action=step3');
            exit;
        }

        $fieldName = 'novo_arquivo';
        if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Nenhum arquivo selecionado.'];
            header('Location: ?module=processos&action=step3');
            exit;
        }

        // Validar extensão contra tipo_doc do anexo original
        $tipoDoc   = $anexos[$idx]['tipo_doc'] ?? 'qualquer';
        $extPorTipo = [
            'qualquer' => ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','txt','csv'],
            'pdf' => ['pdf'], 'imagem' => ['jpg','jpeg','png','webp'],
            'pdf_imagem' => ['pdf','jpg','jpeg','png','webp'],
            'excel' => ['xls','xlsx'], 'word' => ['doc','docx'],
            // Vídeo/áudio só entram em tipo declarado como tal — fora de
            // 'qualquer' de propósito: senão todo anexo do sistema viraria
            // porta de 5 MB.
            'video' => array_merge(VIDEO_EXTENSIONS, AUDIO_EXTENSIONS),
        ];
        $extPermit  = $extPorTipo[$tipoDoc] ?? $extPorTipo['qualquer'];
        $extArquivo = strtolower(pathinfo($_FILES[$fieldName]['name'] ?? '', PATHINFO_EXTENSION));

        if (!in_array($extArquivo, $extPermit, true)) {
            $tipoDocLabels = ['qualquer'=>'qualquer formato','pdf'=>'PDF','imagem'=>'imagem (JPG, PNG, WEBP)','pdf_imagem'=>'PDF ou imagem','excel'=>'planilha (XLS, XLSX)','word'=>'Word (DOC, DOCX)','video'=>'áudio ou vídeo (MP4, WEBM, MOV, OGG, MP3, M4A, WAV) de até 5 MB'];
            $_SESSION['flash'] = ['type' => 'danger', 'message' => "Este anexo aceita apenas {$tipoDocLabels[$tipoDoc]}. Você enviou .{$extArquivo}."];
            header('Location: ?module=processos&action=step3');
            exit;
        }

        try {
            // Remover arquivo antigo
            $caminhoAntigo = $anexos[$idx]['caminho'] ?? '';
            if ($caminhoAntigo && file_exists($caminhoAntigo)) {
                @unlink($caminhoAntigo);
            }

            $meta = Upload::salvar($_FILES[$fieldName], 'temp_' . session_id(), false);
            $meta['tipo_nome'] = $anexos[$idx]['tipo_nome'];
            $meta['tipo_doc']  = $tipoDoc;
            $wizard['anexos_temp'][$idx] = $meta;

            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Anexo substituído com sucesso.'];
        } catch (\RuntimeException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro: ' . $e->getMessage()];
        }

        header('Location: ?module=processos&action=step3');
        exit;

    // ── POST AJAX: cadastrar novo fornecedor ────────────────
    // ── POST: consultar CNPJ na Receita via cnpj.ws (preenche o modal) ──
    case 'consultar_cnpj':
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'cnpj']);
        try {
            Csrf::validate($post['csrf_token'] ?? '');
        } catch (\RuntimeException $e) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']);
            exit;
        }

        $cnpjConsulta = preg_replace('/\D/', '', $post['cnpj']);
        if (strlen($cnpjConsulta) !== 14) {
            echo json_encode(['success' => false, 'message' => 'Consulta disponível apenas para CNPJ (14 dígitos).']);
            exit;
        }

        // Limite GLOBAL: 1 consulta a cada 20s (a API pública do cnpj.ws
        // aceita 3/min por IP — o limite protege o IP do servidor inteiro).
        $lockDir = (defined('STORAGE') ? STORAGE : dirname(__DIR__, 2) . '/storage') . '/locks';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }
        $marcaCnpjWs = $lockDir . '/cnpjws.last';
        $ultima      = is_file($marcaCnpjWs) ? (int) filemtime($marcaCnpjWs) : 0;
        $espera      = 20 - (time() - $ultima);
        if ($espera > 0) {
            echo json_encode([
                'success' => false,
                'retry'   => $espera,
                'message' => "Aguarde {$espera}s para consultar novamente.",
            ]);
            exit;
        }
        @touch($marcaCnpjWs);

        $ch = curl_init('https://publica.cnpj.ws/cnpj/' . $cnpjConsulta);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'ExemploNotas/1.0',
        ]);
        $corpo = curl_exec($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($corpo === false) {
            echo json_encode(['success' => false, 'message' => 'Sem resposta da consulta — verifique a conexão e tente de novo.']);
            exit;
        }
        if ($http === 429) {
            echo json_encode(['success' => false, 'retry' => 60, 'message' => 'Limite de consultas do serviço atingido — aguarde um minuto.']);
            exit;
        }
        if ($http === 404) {
            echo json_encode(['success' => false, 'message' => 'CNPJ não encontrado na base da Receita.']);
            exit;
        }
        $dadosWs = json_decode((string) $corpo, true);
        if ($http !== 200 || !is_array($dadosWs)) {
            echo json_encode(['success' => false, 'message' => "Consulta indisponível no momento (HTTP {$http})."]);
            exit;
        }

        $est = $dadosWs['estabelecimento'] ?? [];
        $sim = $dadosWs['simples'] ?? null;

        // Endereço em linha única: "RODOVIA BR-386, 1703 LOJA 01 - ALTO DO PARQUE - CEP 95913-340"
        $partesEnd = trim(($est['tipo_logradouro'] ?? '') . ' ' . ($est['logradouro'] ?? ''));
        if (!empty($est['numero']))      $partesEnd .= ', ' . $est['numero'];
        if (!empty($est['complemento'])) $partesEnd .= ' ' . preg_replace('/\s+/', ' ', trim($est['complemento']));
        if (!empty($est['bairro']))      $partesEnd .= ' - ' . $est['bairro'];
        if (!empty($est['cep'])) {
            $cep = preg_replace('/\D/', '', $est['cep']);
            $partesEnd .= ' - CEP ' . (strlen($cep) === 8 ? substr($cep, 0, 5) . '-' . substr($cep, 5) : $est['cep']);
        }

        $telefoneWs = '';
        if (!empty($est['telefone1'])) {
            $telefoneWs = trim('(' . ($est['ddd1'] ?? '') . ') ' . $est['telefone1']);
        }

        $flag = static fn($v) => $v === 'Sim' ? 1 : ($v === 'Não' ? 0 : null);

        $abertura = (string) ($est['data_inicio_atividade'] ?? '');
        echo json_encode(['success' => true, 'dados' => [
            'razao_social'       => (string) ($dadosWs['razao_social'] ?? ''),
            'nome_fantasia'      => (string) ($est['nome_fantasia'] ?? ''),
            'endereco'           => mb_substr(trim($partesEnd), 0, 200),
            'cidade'             => (string) ($est['cidade']['nome'] ?? ''),
            'uf'                 => (string) ($est['estado']['sigla'] ?? ''),
            'email'              => (string) ($est['email'] ?? ''),
            'telefone'           => mb_substr($telefoneWs, 0, 20),
            'data_abertura'      => $abertura,
            'data_abertura_br'   => $abertura ? date('d/m/Y', strtotime($abertura)) : '',
            'situacao_cadastral' => (string) ($est['situacao_cadastral'] ?? ''),
            'optante_mei'        => $flag($sim['mei'] ?? null),
            'optante_simples'    => $flag($sim['simples'] ?? null),
        ]], JSON_UNESCAPED_UNICODE);
        exit;

    case 'cadastrar_fornecedor':
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
            exit;
        }

        $post = Sanitize::fromPost(['csrf_token', 'razao_social', 'nome_fantasia', 'cnpj', 'email', 'telefone',
                                    'endereco', 'cidade', 'estado', 'data_abertura', 'situacao_cadastral',
                                    'optante_mei', 'optante_simples']);

        try {
            Csrf::validate($post['csrf_token'] ?? '');
        } catch (\RuntimeException $e) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.']);
            exit;
        }

        $razaoSocial  = trim($post['razao_social']);
        $nomeFantasia = trim($post['nome_fantasia']) ?: null;
        $cnpj         = preg_replace('/\D/', '', $post['cnpj']);
        $email        = trim($post['email']) ?: null;
        $telefone     = trim($post['telefone']) ?: null;

        // Dados cadastrais (preenchidos pela consulta cnpj.ws ou manualmente)
        $endereco = mb_substr(trim($post['endereco']), 0, 200) ?: null;
        $cidade   = mb_substr(trim($post['cidade']), 0, 100) ?: null;
        $estado   = strtoupper(preg_replace('/[^A-Za-z]/', '', $post['estado']));
        $estado   = strlen($estado) === 2 ? $estado : null;

        $dataAbertura = trim($post['data_abertura']);
        $dataAbertura = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAbertura) ? $dataAbertura : null;
        $situacao     = mb_substr(strip_tags(trim($post['situacao_cadastral'])), 0, 30) ?: null;
        $optanteMei     = in_array($post['optante_mei'], ['0', '1'], true) ? (int) $post['optante_mei'] : null;
        $optanteSimples = in_array($post['optante_simples'], ['0', '1'], true) ? (int) $post['optante_simples'] : null;

        if ($razaoSocial === '') {
            echo json_encode(['success' => false, 'message' => 'Razão social é obrigatória.']);
            exit;
        }

        if (strlen($cnpj) !== 14 && strlen($cnpj) !== 11) {
            echo json_encode(['success' => false, 'message' => 'CNPJ/CPF inválido. Informe 11 ou 14 dígitos.']);
            exit;
        }

        $pdo = Database::getInstance();

        // Rótulo "RAZÃO SOCIAL - Nome Fantasia" — mesmo formato do autocomplete
        // (Process::buscarFornecedores)
        $rotuloFornecedor = static function (string $razao, ?string $fantasia): string {
            $fantasia = trim((string) $fantasia);
            return ($fantasia === '' || $fantasia === $razao) ? $razao : $razao . ' - ' . $fantasia;
        };

        // Verificar duplicidade
        $stmtCheck = $pdo->prepare("SELECT id, razao_social, nome_fantasia FROM fornecedores WHERE cnpj = ? LIMIT 1");
        $stmtCheck->execute([$cnpj]);
        $existente = $stmtCheck->fetch();
        if ($existente) {
            echo json_encode([
                'success'     => true,
                'message'     => 'Fornecedor já cadastrado.',
                'fornecedor'  => [
                    'id'   => $existente['id'],
                    'nome' => $rotuloFornecedor($existente['razao_social'], $existente['nome_fantasia']),
                    'cnpj' => $cnpj,
                ],
                'ja_existia'  => true,
            ]);
            exit;
        }

        $novoId = Process::uuid_v4();
        $stmt = $pdo->prepare(
            "INSERT INTO fornecedores (id, razao_social, nome_fantasia, cnpj, email, telefone,
                                       endereco, cidade, estado, data_abertura, situacao_cadastral,
                                       optante_mei, optante_simples, ativo, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())"
        );
        $stmt->execute([$novoId, $razaoSocial, $nomeFantasia, $cnpj, $email, $telefone,
                        $endereco, $cidade, $estado, $dataAbertura, $situacao,
                        $optanteMei, $optanteSimples]);

        AuditLog::registrar('fornecedor', $novoId, 'fornecedor.criado', null, [
            'razao_social' => $razaoSocial,
            'cnpj'         => $cnpj,
        ]);

        echo json_encode([
            'success'    => true,
            'message'    => 'Fornecedor cadastrado com sucesso.',
            'fornecedor' => [
                'id'   => $novoId,
                'nome' => $rotuloFornecedor($razaoSocial, $nomeFantasia),
                'cnpj' => $cnpj,
            ],
        ]);
        exit;

    // ── Fallback 404 ──────────────────────────────────────
    default:
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        exit;
}
