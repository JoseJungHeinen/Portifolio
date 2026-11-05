<?php
// @versao Fat_01 V_15_30/09/2026
declare(strict_types=1);

/**
 * Controller do módulo Faturamento (venda de veículos).
 *
 * Roteamento: ?module=faturamento&action=X
 * Entry point: public/index.php
 *
 * Ações:
 *   index   (GET)  — lista de pedidos, filtrada pela visibilidade do usuário
 *   pedido  (GET)  — tela do pedido (?id=...); sem id, mostra o protótipo
 *   salvar  (POST, AJAX) — cria/atualiza o pedido (grupos A–D do formulário)
 *   anexo_enviar / anexo_baixar / anexo_remover — anexos do pedido (G) e comprovantes (E3)
 *   devolver (POST) — devolve à fase anterior, com motivo
 *   avancar (POST) — dá o OK e avança a fase (exige requisitos da fase atual)
 *   config / fase_salvar / req_salvar — tela de controle (só super_admin)
 *
 * Estágio: lista, visibilidade e OK/avanço persistem em nf_fat_*. O corpo da
 * tela do Pedido (formulário completo) ainda é o protótipo visual.
 */

require_once SRC . '/Models/Faturamento.php';
require_once SRC . '/Helpers/Upload.php';
require_once SRC . '/Helpers/ApolloPedido.php';
require_once SRC . '/Models/ModeloVeiculo.php';

Auth::check();

// Módulo em construção: só entra quem tem a função Faturamento liberada no
// Controle de Acesso (mesmo critério do item do menu).
Permissions::require('faturamento', 'ver');

$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

switch ($action) {

    // ── GET: lista de pedidos ─────────────────────────────
    case 'index':
        $currentUser  = Auth::user();
        $pageTitle    = 'Faturamento';
        $activeModule = 'faturamento';

        $g = Sanitize::fromGet(['busca', 'fase_id', 'de', 'ate', 'pagina', 'ordem', 'dir']);
        $filtros = [
            'busca'   => Sanitize::text($g['busca'], 100),
            'fase_id' => $g['fase_id'] !== '' ? Sanitize::uuid($g['fase_id']) : '',
            'de'      => $g['de'] !== '' ? Sanitize::date($g['de']) : '',
            'ate'     => $g['ate'] !== '' ? Sanitize::date($g['ate']) : '',
            'pagina'  => max(1, (int) $g['pagina']),
            'ordem'   => Faturamento::colunaOrdenavel($g['ordem']),
            'dir'     => $g['dir'] === 'asc' ? 'asc' : 'desc',
        ];

        $resultado = Faturamento::listar(Auth::id(), Auth::perfil(), $filtros);
        $pedidos   = $resultado['itens'];
        $total     = $resultado['total'];
        $fases     = Faturamento::fases();
        $escopo    = Faturamento::escopo(Auth::id(), Auth::perfil());

        ob_start();
        include VIEWS . '/faturamento/lista.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET: tela do pedido ───────────────────────────────
    case 'pedido':
        $currentUser  = Auth::user();
        $pageTitle    = 'Faturamento — Pedido';
        $activeModule = 'faturamento';

        $pedidoAtual = null;
        $idParam     = Sanitize::fromGet(['id'])['id'];
        if ($idParam !== '') {
            $pedidoAtual = Faturamento::buscar(Sanitize::uuid($idParam));
            // Mesma resposta para "não existe" e "não pode ver": não revela a existência
            if (!$pedidoAtual || !Faturamento::podeVer($pedidoAtual, Auth::id(), Auth::perfil())) {
                http_response_code(404);
                include VIEWS . '/errors/404.php';
                break;
            }
            $situacao   = Faturamento::situacao($pedidoAtual);
            $fases      = Faturamento::fases();
            $podeEditar = Faturamento::podeEditar($pedidoAtual, Auth::id(), Auth::perfil());
            $podeOk     = Faturamento::podeDarOk($pedidoAtual, Auth::id(), Auth::perfil());
            $proximaFase = Faturamento::proximaFase($pedidoAtual['fase_id']);
            $faseAnterior = Faturamento::faseAnterior($pedidoAtual['fase_id']);
            $historico  = Faturamento::historicoDoPedido($pedidoAtual['id']);
        }

        // Vendedor do pedido: o gravado ou, em pedido novo, o próprio usuário
        $vendedorSel = $pedidoAtual['vendedor_id'] ?? Auth::id();
        $vendedores  = Faturamento::vendedores($vendedorSel);
        $podeTrocarVendedor = Faturamento::podeTrocarVendedor(Auth::id(), Auth::perfil());
        $pagamentos   = $pedidoAtual ? Faturamento::pagamentosDoPedido($pedidoAtual['id']) : [];
        $anexos       = $pedidoAtual ? Faturamento::anexosDoPedido($pedidoAtual['id']) : [];
        $tiposAnexo   = Faturamento::tiposAnexo();
        $modelosVeiculo = ModeloVeiculo::ativos();
        $assinaturas  = $pedidoAtual ? Faturamento::assinaturasDoPedido($pedidoAtual['id']) : [];
        $filiaisLista = Faturamento::filiais();

        ob_start();
        include VIEWS . '/faturamento/pedido.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── POST AJAX: salvar o formulário do pedido (cria ou atualiza) ──
    case 'salvar':
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Método não permitido.']);
            exit;
        }

        $post = Sanitize::fromPost([
            'csrf_token', 'id', 'tipo_venda', 'pedido_apollo', 'filial_id', 'vendedor_id',
            'cliente_nome', 'cliente_doc', 'cliente_endereco', 'cliente_numero', 'cliente_cidade', 'cliente_uf',
            'cliente_telefone', 'cliente_email',
            'veiculo_codigo', 'veiculo_modelo', 'veiculo_cor', 'veiculo_ano_fab_mod', 'veiculo_combustivel',
            'valor_veiculo', 'valor_opcionais', 'valor_frete', 'pagamentos',
        ]);

        try {
            Csrf::validate($post['csrf_token']);
            $idPedido = $post['id'] !== '' ? Sanitize::uuid($post['id']) : null;
            $novo     = $idPedido === null;
            $idPedido = Faturamento::salvar($idPedido, $post, Auth::id(), Auth::perfil());
            echo json_encode([
                'ok'   => true,
                'novo' => $novo,
                'id'   => $idPedido,
                'msg'  => 'Proposta salva com sucesso',
                'url'  => '?module=faturamento&action=pedido&id=' . urlencode($idPedido) . '&salvo=1',
            ]);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[faturamento.salvar] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'msg' => 'Não foi possível salvar. Tente novamente.']);
        }
        exit;

    // ── GET AJAX: traz do Apollo os dados de um pedido (A8) ──────
    case 'apollo_pedido':
        header('Content-Type: application/json; charset=utf-8');
        try {
            $g = Sanitize::fromGet(['pedido', 'filial_id']);
            $numero = trim($g['pedido']);
            if ($numero === '' || !preg_match('/^[0-9A-Za-z.\-\/]{1,30}$/', $numero)) {
                throw new InvalidArgumentException('Informe o número do pedido do Apollo.');
            }
            $filial = null;
            if ($g['filial_id'] !== '') {
                foreach (Faturamento::filiais() as $fl) {
                    if ($fl['id'] === $g['filial_id']) {
                        $filial = $fl;
                    }
                }
            }
            if (!$filial) {
                throw new InvalidArgumentException('Escolha a Empresa / Filial antes de buscar o pedido.');
            }

            $p = ApolloPedido::buscar($filial, $numero);

            // Vendedor: casa o nome do Apollo com os usuários marcados "Vendedor Veículos"
            $norm = static fn(string $t): string => preg_replace('/\s+/', ' ', mb_strtolower(trim($t)));
            $p['vendedor_id']    = null;
            $p['vendedor_aviso'] = null;
            if (!empty($p['vendedor_nome'])) {
                foreach (Faturamento::vendedores() as $vd) {
                    if ($norm($vd['nome']) === $norm($p['vendedor_nome'])) {
                        $p['vendedor_id'] = $vd['id'];
                    }
                }
                if ($p['vendedor_id'] === null) {
                    $p['vendedor_aviso'] = 'Vendedor "' . $p['vendedor_nome'] . '" do Apollo não foi encontrado entre os Vendedores Veículos — escolha manualmente.';
                }
            }
            // Formas de pagamento: traduz o texto do Apollo para a lista do Faturamento
            $naoReconhecidos = 0;
            foreach ($p['pagamentos'] as &$pg) {
                $pg['tipo_fat'] = ApolloPedido::tipoPagamento((string) $pg['tipo']);
                $naoReconhecidos += $pg['tipo_fat'] === null ? 1 : 0;
            }
            unset($pg);
            $p['pagamentos_aviso'] = $naoReconhecidos ? $naoReconhecidos . ' forma(s) de pagamento do Apollo sem correspondência — escolha o tipo.' : null;

            echo json_encode(['ok' => true, 'pedido' => $p], JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[faturamento.apollo_pedido] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'msg' => 'Não foi possível consultar o Apollo.']);
        }
        exit;

    // ── POST AJAX: enviar anexo (grupo G ou comprovante E3) ──────
    case 'anexo_enviar':
        header('Content-Type: application/json; charset=utf-8');
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new RuntimeException('Método não permitido.');
            }
            $post = Sanitize::fromPost(['csrf_token', 'id', 'tipo', 'pagamento_id']);
            Csrf::validate($post['csrf_token']);

            $pedido = Faturamento::buscar(Sanitize::uuid($post['id']));
            if (!$pedido || !Faturamento::podeEditar($pedido, Auth::id(), Auth::perfil())) {
                throw new RuntimeException('Você não pode anexar arquivos a este pedido na fase atual.');
            }
            if (empty($_FILES['arquivo'])) {
                throw new InvalidArgumentException('Nenhum arquivo enviado.');
            }

            $meta = Upload::salvar($_FILES['arquivo'], 'faturamento/' . $pedido['id'], false);
            try {
                Faturamento::registrarAnexo(
                    $pedido, $post['tipo'],
                    $post['pagamento_id'] !== '' ? Sanitize::uuid($post['pagamento_id']) : null,
                    $meta, Auth::id()
                );
            } catch (Throwable $e) {
                @unlink($meta['caminho']); // não deixa arquivo órfão se o registro falhar
                throw $e;
            }
            echo json_encode(['ok' => true, 'msg' => 'Anexo enviado']);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[faturamento.anexo_enviar] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'msg' => 'Não foi possível enviar o anexo.']);
        }
        exit;

    // ── GET: baixar / abrir anexo ─────────────────────────
    case 'anexo_baixar':
        try {
            $anexo  = Faturamento::buscarAnexo(Sanitize::uuid(Sanitize::fromGet(['id'])['id']));
            $pedido = $anexo ? Faturamento::buscar($anexo['pedido_id']) : null;
            $abs    = ($pedido && Faturamento::podeVer($pedido, Auth::id(), Auth::perfil())) ? Faturamento::caminhoDoAnexo($anexo) : null;
        } catch (InvalidArgumentException $e) {
            $abs = null;
        }
        if ($abs === null) {
            http_response_code(404);
            include VIEWS . '/errors/404.php';
            break;
        }
        $mime   = (new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: 'application/octet-stream';
        $inline = $mime === 'application/pdf' || str_starts_with($mime, 'image/');
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($abs));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($anexo['nome_original']) . '"');
        readfile($abs);
        exit;

    // ── POST AJAX: remover anexo ──────────────────────────
    case 'anexo_remover':
        header('Content-Type: application/json; charset=utf-8');
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new RuntimeException('Método não permitido.');
            }
            $post = Sanitize::fromPost(['csrf_token', 'id']);
            Csrf::validate($post['csrf_token']);

            $anexo  = Faturamento::buscarAnexo(Sanitize::uuid($post['id']));
            $pedido = $anexo ? Faturamento::buscar($anexo['pedido_id']) : null;
            if (!$pedido || !Faturamento::podeEditar($pedido, Auth::id(), Auth::perfil())) {
                throw new RuntimeException('Você não pode remover anexos deste pedido na fase atual.');
            }
            Faturamento::removerAnexo($pedido, $anexo, Auth::id());
            echo json_encode(['ok' => true, 'msg' => 'Anexo removido']);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'msg' => 'Anexo inválido.']);
        } catch (RuntimeException $e) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[faturamento.anexo_remover] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'msg' => 'Não foi possível remover o anexo.']);
        }
        exit;

    // ── POST: OK / avançar fase ───────────────────────────
    case 'avancar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit;
        }
        $post = Sanitize::fromPost(['csrf_token', 'id']);
        Csrf::validate($post['csrf_token']);

        $id  = Sanitize::uuid($post['id']);
        $pedido = Faturamento::buscar($id);
        if (!$pedido || !Faturamento::podeVer($pedido, Auth::id(), Auth::perfil())) {
            http_response_code(404);
            include VIEWS . '/errors/404.php';
            break;
        }

        $res = Faturamento::avancar($id, Auth::id(), Auth::perfil());
        if ($res['ok']) {
            AuditLog::registrar('nf_fat_pedido', $id, 'nf_fat_pedido.avancou', null, ['fase_de' => $pedido['fase_id']]);
        }
        $_SESSION['flash'] = ['type' => $res['ok'] ? 'success' : 'danger', 'message' => $res['mensagem']];
        header('Location: ?module=faturamento&action=pedido&id=' . urlencode($id));
        exit;

    // ── POST: devolver à fase anterior (com motivo) ───────
    case 'devolver':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit;
        }
        $post = Sanitize::fromPost(['csrf_token', 'id', 'motivo']);
        Csrf::validate($post['csrf_token']);

        $id     = Sanitize::uuid($post['id']);
        $pedido = Faturamento::buscar($id);
        if (!$pedido || !Faturamento::podeVer($pedido, Auth::id(), Auth::perfil())) {
            http_response_code(404);
            include VIEWS . '/errors/404.php';
            break;
        }

        $res = Faturamento::devolver($id, Auth::id(), Auth::perfil(), mb_substr($post['motivo'], 0, 500));
        if ($res['ok']) {
            AuditLog::registrar('nf_fat_pedido', $id, 'nf_fat_pedido.devolveu', null, ['fase_de' => $pedido['fase_id']]);
        }
        $_SESSION['flash'] = ['type' => $res['ok'] ? 'success' : 'danger', 'message' => $res['mensagem']];
        header('Location: ?module=faturamento&action=pedido&id=' . urlencode($id));
        exit;

    // ── GET: tela de controle (fases, quem edita/OK, exigências) ──
    case 'config':
        if (Auth::perfil() !== 'super_admin') {
            http_response_code(403);
            include VIEWS . '/errors/403.php';
            break;
        }
        $currentUser  = Auth::user();
        $pageTitle    = 'Faturamento — Controle';
        $activeModule = 'faturamento';

        $fasesCfg   = Faturamento::fasesConfig();
        $gruposPapel = Faturamento::gruposPapel();
        $requisitos = Faturamento::requisitosConfig();
        $catalogo   = Faturamento::catalogoCampos();

        ob_start();
        include VIEWS . '/faturamento/config.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── POST: salvar fase + permissões por grupo ──────────
    case 'fase_salvar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::perfil() !== 'super_admin') {
            http_response_code(403);
            exit;
        }
        $post = Sanitize::fromPost(['csrf_token', 'id', 'nome', 'ordem', 'ativo']);
        Csrf::validate($post['csrf_token']);

        $nome = Sanitize::text($post['nome'], 80);
        $id   = $post['id'] !== '' ? Sanitize::uuid($post['id']) : null;
        $ativo = $post['ativo'] === '1';
        if ($nome === '') {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Informe o nome da fase.'];
        } elseif ($id !== null && !$ativo && Faturamento::faseTemPedidos($id)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Fase com pedidos não pode ser desativada. Avance os pedidos antes.'];
        } else {
            $id = Faturamento::salvarFase($id, $nome, (int) $post['ordem'], $ativo);
            $validos = array_column(Faturamento::gruposPapel(), 'id');
            $porGrupo = [];
            foreach ((array) ($_POST['perm'] ?? []) as $gid => $fl) {
                if (in_array($gid, $validos, true) && is_array($fl)) {
                    $porGrupo[$gid] = ['editar' => !empty($fl['editar']), 'ok' => !empty($fl['ok'])];
                }
            }
            Faturamento::salvarPermissoesFase($id, $porGrupo);
            AuditLog::registrar('nf_fat_fase', $id, 'nf_fat_fase.salva', null, ['nome' => $nome]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Fase salva.'];
        }
        header('Location: ?module=faturamento&action=config');
        exit;

    // ── POST: salvar exigência (requisito) ────────────────
    case 'req_salvar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || Auth::perfil() !== 'super_admin') {
            http_response_code(403);
            exit;
        }
        $post = Sanitize::fromPost(['csrf_token', 'id', 'nome', 'tipo', 'chave_campo', 'chave_anexo', 'fase_id',
                                    'condicao_campo', 'condicao_op', 'condicao_valores', 'ordem', 'ativo']);
        Csrf::validate($post['csrf_token']);

        $catalogo = Faturamento::catalogoCampos();
        $tipo  = Sanitize::enum($post['tipo'], ['campo', 'anexo']);
        $chave = $tipo === 'campo'
            ? $post['chave_campo']
            : trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $post['chave_anexo']))), '_');
        $nome  = Sanitize::text($post['nome'], 120);

        $condCampo = $post['condicao_campo'] !== '' ? $post['condicao_campo'] : null;
        $condVals  = implode('|', array_filter(array_map('trim', preg_split('/[|,;]/', (string) $post['condicao_valores']))));
        $erro = null;
        if ($nome === '' || $chave === '') {
            $erro = 'Informe o nome e o campo/tipo de anexo.';
        } elseif ($tipo === 'campo' && !isset($catalogo[$chave])) {
            $erro = 'Campo inválido.';
        } elseif ($condCampo !== null && (!isset($catalogo[$condCampo]) || $condVals === '')) {
            $erro = 'Condição incompleta: escolha o campo e informe os valores.';
        }

        if ($erro) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => $erro];
        } else {
            Faturamento::salvarRequisito($post['id'] !== '' ? Sanitize::uuid($post['id']) : null, [
                'nome' => $nome, 'tipo' => $tipo, 'chave' => $chave, 'fase_id' => Sanitize::uuid($post['fase_id']),
                'condicao_campo' => $condCampo,
                'condicao_op' => $condCampo !== null ? Sanitize::enum($post['condicao_op'], ['em', 'nao_em']) : null,
                'condicao_valores' => $condCampo !== null ? $condVals : null,
                'ordem' => (int) $post['ordem'], 'ativo' => $post['ativo'] === '1',
            ]);
            AuditLog::registrar('nf_fat_requisito', $post['id'] !== '' ? $post['id'] : $chave, 'nf_fat_requisito.salvo', null, ['nome' => $nome]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Exigência salva.'];
        }
        header('Location: ?module=faturamento&action=config#requisitos');
        exit;

    default:
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        break;
}
