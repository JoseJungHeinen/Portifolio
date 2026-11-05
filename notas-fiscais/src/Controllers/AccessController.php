<?php
// @versao Ace_02 V_12_30/09/2026
declare(strict_types=1);

/**
 * Controller do módulo Controle de Acesso (permissões granulares por usuário).
 *
 * Roteamento: ?module=acessos&action=X
 * Entry point: public/index.php
 *
 * Gate direto via Permissions::isAdminCadastros() (admin + super_admin) —
 * nunca via a
 * própria tabela nf_usuario_permissoes que este controller gerencia,
 * para não haver risco de um admin se trancar fora da própria tela.
 */

// ─── Autenticação obrigatória (admin apenas) ─────────────
Auth::check();
if (!Permissions::isAdminCadastros()) {
    http_response_code(403);
    include VIEWS . '/errors/403.php';
    exit;
}

/**
 * Ações que mexem em PERMISSÃO (perfil de um usuário, override granular, mapa
 * de um perfil) valem para admin e super admin — o administrador de cadastros
 * também gerencia o acesso do time. A marca `super_admin` continua reservada:
 * o admin não promove ninguém a super admin nem mexe no acesso de quem já é
 * (ver acessoBloquearAlvoSuperAdmin) — sem isso a marca invisível cairia.
 */
function acessoSomenteGestorDePermissoes(): void
{
    if (!Permissions::isAdminCadastros()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'Somente administradores alteram perfis e permissões.']);
        exit;
    }
}

/**
 * Barra qualquer ação de permissão cujo ALVO seja um super admin, quando quem
 * opera não é super admin. Na tela ele aparece como 'admin' (perfilVisivel), e
 * salvar permissões por cima derrubaria o acesso do super admin sem que quem
 * clicou percebesse o que fez.
 */
function acessoBloquearAlvoSuperAdmin(string $idUsuario): void
{
    if (Permissions::isSuperAdmin()) {
        return;
    }
    $alvo = UserPermission::buscarUsuario($idUsuario);
    if ($alvo && ($alvo['perfil'] ?? '') === 'super_admin') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'Somente o super administrador altera o acesso deste usuário.']);
        exit;
    }
}

// ─── Carregar Model ──────────────────────────────────────
require_once SRC . '/Models/UserPermission.php';
require_once SRC . '/Models/Process.php';
require_once SRC . '/Models/Group.php';
require_once SRC . '/Models/AccessStats.php';
require_once SRC . '/Models/AccessSchedule.php';

/** Perfis válidos — mesma whitelist de config/permissions.php */
// super_admin só entra na lista quando quem está operando JÁ é super admin —
// a marca é invisível para todos os outros, inclusive para o admin de
// cadastros (ver UserPermission::perfilVisivel).
$perfisValidos = ['solicitante', 'almoxarife', 'comprador', 'gestor', 'diretor', 'admin'];
if (Permissions::isSuperAdmin()) {
    $perfisValidos[] = 'super_admin';        // só super admin promove super admin
}
$perfisFixos   = ['admin', 'super_admin'];   // mapa não editável na tela de perfis

// ─── Determinar action ───────────────────────────────────
$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

switch ($action) {

    // ── GET: tela principal ───────────────────────────────
    case 'index':
        $get   = Sanitize::fromGet(['busca']);
        $busca = $get['busca'];

        $usuarios = UserPermission::mascararPerfis(UserPermission::listarUsuarios($busca));

        // Jornada de cada um, para a lista mostrar quem tem limite de horário
        // sem uma consulta por linha (migration 043).
        $horarioPorUsuario = [];
        foreach (Database::getInstance()->query(
            'SELECT uh.usuario_id, h.nome, h.ativo
               FROM nf_usuario_horario uh
               JOIN nf_horarios_acesso h ON h.id = uh.horario_id'
        )->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $horarioPorUsuario[$r['usuario_id']] = $r;
        }

        $horariosDisponiveis = AccessSchedule::listar();

        $catalogo = UserPermission::catalogo();
        $filiais  = Process::getFiliais();
        $setores  = Process::getSetores();

        // Painel de estatísticas do lado direito, enquanto ninguém está
        // selecionado. Só leitura — se algo falhar no banco, a tela do admin
        // continua funcionando sem o painel.
        try {
            $stats = AccessStats::painel();
        } catch (\Throwable $e) {
            error_log('AccessStats::painel falhou: ' . $e->getMessage());
            $stats = [];
        }


        $pageTitle    = 'Controle de Acesso';
        $activeModule = 'acessos';
        $currentUser  = Auth::user();
        $unreadCount  = 0;
        $cssVer       = filemtime(ROOT . '/public/assets/css/modules/acessos.css');
        $extraHead    = '<link rel="stylesheet" href="' . BASE_URL . '/public/assets/css/modules/acessos.css?v=' . $cssVer . '">';

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        ob_start();
        include VIEWS . '/acessos/index.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── GET AJAX: dados + permissões efetivas de um usuário ──
    case 'usuario_dados':
        header('Content-Type: application/json; charset=utf-8');

        $get = Sanitize::fromGet(['id']);
        try {
            $id = Sanitize::uuid($get['id']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['ok' => false, 'msg' => 'Usuário inválido.']);
            exit;
        }

        $usuario = UserPermission::buscarUsuario($id);
        if (!$usuario) {
            echo json_encode(['ok' => false, 'msg' => 'Usuário não encontrado.']);
            exit;
        }

        $override    = UserPermission::buscarOverride($id);
        $permissoes  = UserPermission::permissoesParaExibicao($usuario['perfil'], $override);
        $usuario['perfil'] = UserPermission::perfilVisivel($usuario['perfil']);

        echo json_encode([
            'ok'                => true,
            'usuario'           => $usuario,
            'tem_override'      => $override !== null,
            'permissoes'        => $permissoes,
            'grupos'            => Group::gruposDoUsuario($id),
            'templates_liberados' => Process::listarAcessosPorUsuario($id),
            'filiais_usuario'     => FilialScope::filiaisDoUsuario($id),
            // null = sem limite; o select do modal usa '' para isso
            'horario_id'          => AccessSchedule::doUsuario($id)['id'] ?? '',
        ]);
        exit;

    // ── GET AJAX: buscar template por nome (autocomplete) ──
    case 'buscar_template':
        header('Content-Type: application/json; charset=utf-8');

        $q = trim(Sanitize::fromGet(['q'])['q']);
        if (mb_strlen($q) < 2) {
            echo json_encode(['ok' => true, 'templates' => []]);
            exit;
        }

        echo json_encode(['ok' => true, 'templates' => Process::buscarTemplatesParaAcesso($q)]);
        exit;

    // ── POST AJAX: conceder liberação granular pontual de um template ──
    case 'conceder_template':
        header('Content-Type: application/json; charset=utf-8');
        acessoSomenteGestorDePermissoes();

        $post = Sanitize::fromPost(['csrf_token', 'id_usuario', 'template_id', 'motivo']);
        try {
            Csrf::validate($post['csrf_token'] ?? '');
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            exit;
        }

        try {
            $idUsuario  = Sanitize::uuid($post['id_usuario']);
            $templateId = Sanitize::uuid($post['template_id']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['ok' => false, 'msg' => 'ID inválido.']);
            exit;
        }

        if (!UserPermission::buscarUsuario($idUsuario)) {
            echo json_encode(['ok' => false, 'msg' => 'Usuário não encontrado.']);
            exit;
        }
        acessoBloquearAlvoSuperAdmin($idUsuario);

        $motivo = trim($post['motivo']) ?: null;
        Process::concederAcessoTemplate($templateId, $idUsuario, Auth::id(), $motivo);

        AuditLog::registrar('nf_template_acesso', $templateId, 'nf_template_acesso.concedido', null, [
            'usuario_id' => $idUsuario,
            'motivo'     => $motivo,
        ]);

        echo json_encode(['ok' => true, 'msg' => 'Acesso concedido com sucesso.']);
        exit;

    // ── POST AJAX: revogar liberação granular pontual de um template ──
    case 'revogar_template':
        header('Content-Type: application/json; charset=utf-8');
        acessoSomenteGestorDePermissoes();

        $post = Sanitize::fromPost(['csrf_token', 'id_usuario', 'template_id']);
        try {
            Csrf::validate($post['csrf_token'] ?? '');
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            exit;
        }

        try {
            $idUsuario  = Sanitize::uuid($post['id_usuario']);
            $templateId = Sanitize::uuid($post['template_id']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['ok' => false, 'msg' => 'ID inválido.']);
            exit;
        }
        acessoBloquearAlvoSuperAdmin($idUsuario);

        Process::revogarAcessoTemplate($templateId, $idUsuario, Auth::id());

        AuditLog::registrar('nf_template_acesso', $templateId, 'nf_template_acesso.revogado', null, [
            'usuario_id' => $idUsuario,
        ]);

        echo json_encode(['ok' => true, 'msg' => 'Acesso revogado.']);
        exit;

    // ── POST AJAX: escopo de filiais visíveis de um usuário ──
    // ── POST AJAX: marca "Vendedor Veículos" do usuário ──────
    case 'salvar_vendedor_veiculos':
        header('Content-Type: application/json; charset=utf-8');
        acessoSomenteGestorDePermissoes();

        $post = Sanitize::fromPost(['csrf_token', 'id_usuario', 'valor']);
        try {
            Csrf::validate($post['csrf_token'] ?? '');
            $idUsuario = Sanitize::uuid($post['id_usuario']);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'Requisição inválida.']);
            exit;
        }
        acessoBloquearAlvoSuperAdmin($idUsuario);

        $marcado = $post['valor'] === '1';
        UserPermission::definirVendedorVeiculos($idUsuario, $marcado);
        AuditLog::registrar('usuario', $idUsuario, 'nf_usuario.vendedor_veiculos', null, ['vendedor_veiculos' => $marcado]);

        echo json_encode([
            'ok'  => true,
            'msg' => $marcado
                ? 'Usuário marcado como Vendedor Veículos — já aparece como opção no pedido.'
                : 'Marca removida — o usuário deixa de aparecer como vendedor no pedido.',
        ]);
        exit;

    case 'salvar_filiais':
        header('Content-Type: application/json; charset=utf-8');
        acessoSomenteGestorDePermissoes();

        $post = Sanitize::fromPost(['csrf_token', 'id_usuario']);
        try {
            Csrf::validate($post['csrf_token'] ?? '');
            $idUsuario = Sanitize::uuid($post['id_usuario']);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'msg' => 'Requisição inválida.']);
            exit;
        }
        acessoBloquearAlvoSuperAdmin($idUsuario);

        $filiais = [];
        foreach ((array) ($_POST['filiais'] ?? []) as $fid) {
            try { $filiais[] = Sanitize::uuid((string) $fid); } catch (InvalidArgumentException $e) {}
        }

        FilialScope::definirFiliais($idUsuario, $filiais, Auth::id());

        echo json_encode([
            'ok'  => true,
            'msg' => $filiais
                ? count($filiais) . ' filial(is) vinculada(s) — o usuário passa a ver só elas.'
                : 'Escopo removido — o usuário volta a ver todas as filiais.',
        ]);
        exit;

    // ── POST AJAX: salvar permissões de um usuário ───────────
    case 'salvar':
        header('Content-Type: application/json; charset=utf-8');
        acessoSomenteGestorDePermissoes();

        $post = Sanitize::fromPost(['csrf_token', 'id_usuario']);
        try {
            Csrf::validate($post['csrf_token'] ?? '');
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            exit;
        }

        try {
            $idUsuario = Sanitize::uuid($post['id_usuario']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['ok' => false, 'msg' => 'Usuário inválido.']);
            exit;
        }

        if (!UserPermission::buscarUsuario($idUsuario)) {
            echo json_encode(['ok' => false, 'msg' => 'Usuário não encontrado.']);
            exit;
        }
        acessoBloquearAlvoSuperAdmin($idUsuario);

        // $_POST['perms'] chega como ['modulo.acao' => '1', ...] — só as marcadas
        $permsPost = $_POST['perms'] ?? [];
        if (!is_array($permsPost)) {
            $permsPost = [];
        }

        $permissoes = [];
        foreach (UserPermission::catalogo() as $modulo => $grupo) {
            foreach ($grupo['acoes'] as $acao => $label) {
                $chave = $modulo . '.' . $acao;
                $permissoes[$modulo][$acao] = isset($permsPost[$chave]);
            }
        }

        UserPermission::salvar($idUsuario, $permissoes, Auth::id());

        AuditLog::registrar(
            'nf_usuario_permissoes',
            $idUsuario,
            'nf_permissoes.atualizadas',
            null,
            ['permissoes' => $permissoes]
        );

        echo json_encode(['ok' => true, 'msg' => 'Permissões salvas com sucesso.']);
        exit;

    // ── POST AJAX: reverter para o padrão do perfil ──────────
    case 'remover_override':
        header('Content-Type: application/json; charset=utf-8');
        acessoSomenteGestorDePermissoes();

        $post = Sanitize::fromPost(['csrf_token', 'id_usuario']);
        try {
            Csrf::validate($post['csrf_token'] ?? '');
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            exit;
        }

        try {
            $idUsuario = Sanitize::uuid($post['id_usuario']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['ok' => false, 'msg' => 'Usuário inválido.']);
            exit;
        }
        acessoBloquearAlvoSuperAdmin($idUsuario);

        UserPermission::removerOverride($idUsuario);

        AuditLog::registrar(
            'nf_usuario_permissoes',
            $idUsuario,
            'nf_permissoes.override_removido',
            null,
            null
        );

        echo json_encode(['ok' => true, 'msg' => 'Usuário voltou a seguir o padrão do perfil.']);
        exit;

    // ── POST AJAX: criar novo usuário ────────────────────────
    case 'criar_usuario':
        header('Content-Type: application/json; charset=utf-8');

        $post = Sanitize::fromPost(['csrf_token', 'nome', 'email', 'perfil', 'filial_id', 'setor_id', 'vendedor_veiculos']);
        try {
            Csrf::validate($post['csrf_token'] ?? '');
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            exit;
        }

        try {
            $nome = Sanitize::text($post['nome'], 100);
            if ($nome === '') {
                throw new InvalidArgumentException('Informe o nome.');
            }
            $email    = Sanitize::email($post['email']);
            $filialId = Sanitize::uuid($post['filial_id']);
            $setorId  = Sanitize::uuid($post['setor_id']);

            // Admin escolhe o perfil do novo funcionário. `super_admin` não
            // entra em $perfisValidos para ele, então a marca segue reservada.
            $perfilNovo = $post['perfil'];
            if (!in_array($perfilNovo, $perfisValidos, true)) {
                throw new InvalidArgumentException('Perfil inválido.');
            }
        } catch (InvalidArgumentException $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            exit;
        }

        if (UserPermission::emailJaExiste($email)) {
            echo json_encode(['ok' => false, 'msg' => 'Já existe um usuário com este e-mail.']);
            exit;
        }

        $resultado = UserPermission::criarUsuario($nome, $email, $perfilNovo, $filialId, $setorId, Auth::id(), $post['vendedor_veiculos'] === '1');

        AuditLog::registrar(
            'usuario',
            $resultado['id'],
            'nf_usuario.criado',
            null,
            ['nome' => $nome, 'email' => $email, 'perfil' => $post['perfil']]
        );

        echo json_encode([
            'ok'               => true,
            'msg'              => 'Usuário criado com sucesso.',
            'id'               => $resultado['id'],
            'nome'             => $nome,
            'email'            => $email,
            'senha_temporaria' => $resultado['senha_temporaria'],
        ]);
        exit;

    // ── GET AJAX: permissões padrão de um PERFIL ─────────────
    case 'perfil_dados':
        header('Content-Type: application/json; charset=utf-8');

        $perfil = trim(Sanitize::fromGet(['perfil'])['perfil']);
        if (!in_array($perfil, $perfisValidos, true)) {
            echo json_encode(['ok' => false, 'msg' => 'Perfil inválido.']);
            exit;
        }

        echo json_encode([
            'ok'          => true,
            'perfil'      => $perfil,
            'editavel'    => !in_array($perfil, $perfisFixos, true),
            'customizado' => UserPermission::perfilCustomizado($perfil),
            'permissoes'  => $perfil === 'super_admin'
                ? array_map(fn($g) => array_map(fn() => true, $g['acoes']), UserPermission::catalogo())
                : UserPermission::permissoesDoPerfil($perfil),
        ]);
        exit;

    // ── POST AJAX: salvar permissões padrão de um PERFIL ─────
    case 'salvar_perfil':
        header('Content-Type: application/json; charset=utf-8');
        acessoSomenteGestorDePermissoes();

        $post = Sanitize::fromPost(['csrf_token', 'perfil']);
        try {
            Csrf::validate($post['csrf_token'] ?? '');
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            exit;
        }

        $perfil = trim($post['perfil']);
        if (!in_array($perfil, $perfisValidos, true) || in_array($perfil, $perfisFixos, true)) {
            echo json_encode(['ok' => false, 'msg' => 'Este perfil não pode ser alterado.']);
            exit;
        }

        $permsPost = is_array($_POST['perms'] ?? null) ? $_POST['perms'] : [];
        $permissoes = [];
        foreach (UserPermission::catalogo() as $modulo => $grupo) {
            foreach ($grupo['acoes'] as $acao => $label) {
                $permissoes[$modulo][$acao] = isset($permsPost[$modulo . '.' . $acao]);
            }
        }

        UserPermission::salvarPerfil($perfil, $permissoes, Auth::id());
        AuditLog::registrar('nf_perfil_permissoes', $perfil, 'nf_perfil.permissoes_atualizadas', null, ['permissoes' => $permissoes]);

        echo json_encode(['ok' => true, 'msg' => 'Permissões do perfil salvas. Valem para todos os usuários do perfil sem personalização própria.']);
        exit;

    // ── POST AJAX: reverter perfil ao padrão do sistema ──────
    case 'reverter_perfil':
        header('Content-Type: application/json; charset=utf-8');
        acessoSomenteGestorDePermissoes();

        $post = Sanitize::fromPost(['csrf_token', 'perfil']);
        try {
            Csrf::validate($post['csrf_token'] ?? '');
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            exit;
        }

        $perfil = trim($post['perfil']);
        if (!in_array($perfil, $perfisValidos, true)) {
            echo json_encode(['ok' => false, 'msg' => 'Perfil inválido.']);
            exit;
        }

        UserPermission::reverterPerfil($perfil);
        AuditLog::registrar('nf_perfil_permissoes', $perfil, 'nf_perfil.permissoes_revertidas');

        echo json_encode(['ok' => true, 'msg' => 'Perfil voltou ao padrão do sistema.']);
        exit;

    // ── POST AJAX: atualizar cadastro completo do usuário ────
    // Correção de dados (nome, e-mail, perfil, loja, setor, ativo).
    // Admin apenas (gate no topo do controller).
    case 'atualizar_usuario':
        header('Content-Type: application/json; charset=utf-8');

        $post = Sanitize::fromPost(['csrf_token', 'id_usuario', 'nome', 'email', 'perfil', 'filial_id', 'setor_id', 'ativo', 'horario_id']);
        try {
            Csrf::validate($post['csrf_token'] ?? '');
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            exit;
        }

        try {
            $idUsuario = Sanitize::uuid($post['id_usuario']);
            $nome      = Sanitize::text($post['nome'], 100);
            if ($nome === '') {
                throw new InvalidArgumentException('Informe o nome.');
            }
            $email    = Sanitize::email($post['email']);
            $filialId = Sanitize::uuid($post['filial_id']);
            $setorId  = Sanitize::uuid($post['setor_id']);

            // Admin também troca o perfil. `super_admin` fica fora de
            // $perfisValidos para ele — a marca continua sendo do super admin.
            $perfilNovo = $post['perfil'];
            if (!in_array($perfilNovo, $perfisValidos, true)) {
                throw new InvalidArgumentException('Perfil inválido.');
            }
        } catch (InvalidArgumentException $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            exit;
        }

        $usuarioAntes = UserPermission::buscarUsuario($idUsuario);
        if (!$usuarioAntes) {
            echo json_encode(['ok' => false, 'msg' => 'Usuário não encontrado.']);
            exit;
        }

        $ativo = $post['ativo'] === '1';

        // Proteção anti-bloqueio: o admin não pode se desativar nem
        // rebaixar o próprio perfil — outro admin precisa fazer isso.
        if ($idUsuario === Auth::id()) {
            if (!$ativo) {
                echo json_encode(['ok' => false, 'msg' => 'Você não pode desativar o próprio usuário.']);
                exit;
            }
            if (!in_array($post['perfil'], ['admin', 'super_admin'], true)) {
                echo json_encode(['ok' => false, 'msg' => 'Você não pode remover o próprio perfil de administrador.']);
                exit;
            }
        }

        // Marca invisível: quem não é super admin nunca rebaixa um super admin —
        // a tela dele mostra 'admin', então um "salvar" ingênuo mandaria 'admin'
        // e derrubaria o acesso sem ninguém perceber. Nesse caso o valor do
        // formulário é ignorado e o perfil real é preservado. Fora isso o admin
        // troca o perfil normalmente ($perfilNovo já validado; `super_admin` só
        // está em $perfisValidos para quem já é super admin).
        $alvoEhSuperAdmin = ($usuarioAntes['perfil'] ?? '') === 'super_admin';
        $perfilFinal = (!Permissions::isSuperAdmin() && $alvoEhSuperAdmin)
            ? 'super_admin'
            : $perfilNovo;

        if (UserPermission::emailJaExiste($email, $idUsuario)) {
            echo json_encode(['ok' => false, 'msg' => 'Já existe outro usuário com este e-mail.']);
            exit;
        }

        UserPermission::atualizarUsuario($idUsuario, [
            'nome'      => $nome,
            'email'     => $email,
            'perfil'    => $perfilFinal,
            'filial_id' => $filialId,
            'setor_id'  => $setorId,
            'ativo'     => $ativo,
        ]);

        // Horário de acesso (migration 043). Vazio = tira o vínculo, e a pessoa
        // volta a entrar a qualquer hora — que é o padrão do sistema. UUID
        // inválido é ignorado em silêncio para não derrubar o resto do cadastro,
        // que já foi gravado acima.
        $horarioAntes = AccessSchedule::doUsuario($idUsuario)['id'] ?? '';
        $horarioNovo  = trim((string) $post['horario_id']);
        if ($horarioNovo === '') {
            AccessSchedule::atribuir($idUsuario, null, Auth::id());
        } else {
            try {
                AccessSchedule::atribuir($idUsuario, Sanitize::uuid($horarioNovo), Auth::id());
            } catch (InvalidArgumentException) {
                $horarioNovo = $horarioAntes;
            }
        }
        if ($horarioNovo !== $horarioAntes) {
            AuditLog::registrar('usuario', $idUsuario, 'nf_usuario.horario_alterado',
                ['horario_id' => $horarioAntes ?: null], ['horario_id' => $horarioNovo ?: null]);
        }

        AuditLog::registrar('usuario', $idUsuario, 'nf_usuario.cadastro_atualizado', [
            'nome'   => $usuarioAntes['nome'],
            'email'  => $usuarioAntes['email'],
            'perfil' => $usuarioAntes['perfil'],
            'ativo'  => $usuarioAntes['ativo'],
        ], [
            'nome'   => $nome,
            'email'  => $email,
            'perfil' => $post['perfil'],
            'ativo'  => $ativo ? 1 : 0,
        ]);

        echo json_encode(['ok' => true, 'msg' => 'Cadastro atualizado com sucesso.']);
        exit;

    // ── POST AJAX: trocar senha de um usuário ────────────────
    case 'trocar_senha':
        header('Content-Type: application/json; charset=utf-8');

        $post = Sanitize::fromPost(['csrf_token', 'id_usuario', 'nova_senha']);
        try {
            Csrf::validate($post['csrf_token'] ?? '');
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
            exit;
        }

        try {
            $idUsuario = Sanitize::uuid($post['id_usuario']);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['ok' => false, 'msg' => 'Usuário inválido.']);
            exit;
        }

        $novaSenha = trim($post['nova_senha']);
        if (mb_strlen($novaSenha) < 6) {
            echo json_encode(['ok' => false, 'msg' => 'A senha deve ter no mínimo 6 caracteres.']);
            exit;
        }

        if (!UserPermission::buscarUsuario($idUsuario)) {
            echo json_encode(['ok' => false, 'msg' => 'Usuário não encontrado.']);
            exit;
        }

        UserPermission::trocarSenha($idUsuario, $novaSenha);

        AuditLog::registrar('usuario', $idUsuario, 'nf_usuario.senha_redefinida');

        echo json_encode(['ok' => true, 'msg' => 'Senha redefinida com sucesso.']);
        exit;

    // ── Fallback 404 ─────────────────────────────────────
    default:
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        exit;
}
