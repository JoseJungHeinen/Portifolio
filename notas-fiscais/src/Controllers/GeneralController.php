<?php
// @versao Ger_02 V_24_01/10/2026
declare(strict_types=1);

/**
 * Controller da tela Geral — configurações do sistema (admin apenas).
 * Roteamento: ?module=geral&action=X
 */

Auth::check();

require_once SRC . '/Helpers/Settings.php';
require_once SRC . '/Helpers/ApolloAccess.php';
require_once SRC . '/Helpers/CertificadoPfx.php';
require_once SRC . '/Models/CertificadoMatriz.php';

$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

// A tela Geral é admin-only, EXCETO o console de consulta Apollo, que é liberado
// POR USUÁRIO (nf_apollo_acessos) — inclusive para não-admins. Um usuário de
// console sem ser admin só pode a aba Apollo e a ação de rodar a consulta.
$isAdmin       = Permissions::isAdmin();
$apolloConsole = ApolloAccess::pode(Auth::id());

$ehApolloIndex = ($action === 'index' && Sanitize::fromGet(['aba'])['aba'] === 'apollo');
if (!$isAdmin) {
    $permitidoConsole = $apolloConsole && ($ehApolloIndex || $action === 'apollo_consultar');
    if (!$permitidoConsole) {
        http_response_code(403);
        include VIEWS . '/errors/403.php';
        exit;
    }
}

switch ($action) {

    // ── GET: tela com abas ────────────────────────────────
    case 'index':
        $aba = Sanitize::fromGet(['aba'])['aba'];
        $aba = in_array($aba, ['email', 'seguranca', 'notificacoes', 'api', 'brobot', 'paginas', 'apollo', 'horarios', 'filiais', 'nfe_nfse', 'faturamento'], true) ? $aba : 'geral';
        // Faturamento ainda não foi publicado em todos os ambientes: sem o controller, a aba não existe.
        if ($aba === 'faturamento' && !is_file(SRC . '/Controllers/FaturamentoController.php')) {
            $aba = 'geral';
        }
        // Usuário de console (não-admin) só enxerga a aba Apollo.
        if (!$isAdmin) {
            $aba = 'apollo';
        }
        // Aba desligada em "Utiliza módulos" (aba Geral) não abre nem por URL direta.
        elseif (!Settings::abaAtiva($aba)) {
            $aba = 'geral';
        }

        $cfg = [
            'geral'        => Settings::grupo('geral'),
            'email'        => Settings::grupo('email'),
            'seguranca'    => Settings::grupo('seguranca'),
            'notificacoes' => Settings::grupo('notificacoes'),
            'api'          => Settings::grupo('api'),
            'brobot'       => Settings::grupo('brobot'),
            'apollo'       => Settings::grupo('apollo'),
            'assinafy'     => Settings::grupo('assinafy'),
            'clicksign'    => Settings::grupo('clicksign'),
            'dist'         => Settings::grupo('dist'),
        ];

        // Aba Páginas e Arquivos: versões lidas direto do cabeçalho dos arquivos
        $arquivosVersao = [];
        $modulosMapa    = [];
        if ($aba === 'paginas') {
            require_once SRC . '/Helpers/FileVersion.php';
            $arquivosVersao = FileVersion::scan();
            $modulosMapa    = FileVersion::MODULOS;
        }

        // Filiais + flags de integração por tipo (aba Brobot)
        $filiaisBrobot  = [];
        $backfillStatus = [];
        $brobotPorMes   = [];
        if ($aba === 'brobot') {
            $filiaisBrobot = Database::getInstance()->query(
                "SELECT id, nome, cnpj, ativo, brobot_nfe, brobot_cte, brobot_nfse, brobot_ctos
                 FROM filiais ORDER BY ativo DESC, nome ASC"
            )->fetchAll();

            // Buscas históricas (backfill) recentes por filial.
            // "Importadas" é contado AO VIVO (notas do CNPJ capturadas desde que
            // a busca entrou na fila), não do docs_importadas gravado: o job
            // conclui em minutos, mas as notas da filial chegam pelo poller nos
            // dias seguintes — o valor congelado na conclusão mostrava ~0 mesmo
            // com as notas já no sistema.
            // Uma linha por FILIAL (a busca mais recente dela), não por job: com
            // LIMIT por data as lojas mais antigas sumiam da tela. Filial sem
            // busca aparece como "não iniciada" — a lista é o retrato de todas.
            // Ordem natural pelo código do nome: 1.1, 1.2, … 1.10, 2.1, 3.1.
            $backfillStatus = Database::getInstance()->query(
                "SELECT f.nome AS filial_nome,
                        b.status, b.docs_varridos, b.detalhe, b.criado_em, b.atualizado_em,
                        (SELECT COUNT(*) FROM nf_notas_capturadas n
                          WHERE n.destinatario_cnpj = b.cnpj
                            AND n.capturada_em >= b.criado_em) AS docs_importadas,
                        -- Total capturado pelo sync corrente, independente de backfill:
                        -- é o que mostra que a filial está operando mesmo sem a
                        -- busca histórica ter rodado.
                        (SELECT COUNT(*) FROM nf_notas_capturadas n2
                          WHERE n2.destinatario_cnpj =
                                REPLACE(REPLACE(REPLACE(f.cnpj,'.',''),'/',''),'-','')) AS notas_filial
                 FROM filiais f
                 LEFT JOIN nf_brobot_backfill b
                        ON b.id = (SELECT b2.id FROM nf_brobot_backfill b2
                                    WHERE b2.filial_id = f.id
                                    ORDER BY b2.criado_em DESC LIMIT 1)
                 WHERE f.ativo = 1
                 ORDER BY CAST(SUBSTRING_INDEX(f.nome, '.', 1) AS UNSIGNED),
                          CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(f.nome, ' ', 1), '.', -1) AS UNSIGNED),
                          f.nome"
            )->fetchAll();

            // Volume mensal da integração: quantas notas entraram pela API do
            // Brobot em cada mês. Conta pela DATA DA CAPTURA (capturada_em), não
            // pela emissão — backfill traz nota antiga, e o que se mede aqui é o
            // que a integração trouxe no mês. Últimos 24 meses.
            $brobotPorMes = Database::getInstance()->query(
                "SELECT DATE_FORMAT(capturada_em, '%Y-%m') AS mes,
                        COUNT(*)                      AS total,
                        SUM(tipo_documento = 'NFe')   AS nfe,
                        SUM(tipo_documento = 'CTe')   AS cte,
                        SUM(tipo_documento = 'NFSe')  AS nfse,
                        SUM(tipo_documento = 'CTOs')  AS ctos,
                        SUM(status = 'vinculada')     AS vinculadas
                 FROM nf_notas_capturadas
                 WHERE origem = 'brobot'
                 GROUP BY mes
                 ORDER BY mes DESC
                 LIMIT 24"
            )->fetchAll();
        }

        // Aba Filiais — cadastro completo (saiu de Listas em 16/09/2026)
        $filiaisCompleto = [];
        if ($aba === 'filiais') {
            $filiaisCompleto = Database::getInstance()->query(
                "SELECT id, nome, cnpj, endereco, telefone, email, cidade, estado, budget_mensal, ativo
                 FROM filiais ORDER BY ativo DESC, nome ASC"
            )->fetchAll();
        }

        // Aba NF-e / NFS-e — captura direta do Portal Nacional/ADN (sem Brobot)
        $filiaisDist = [];
        $certificadosMatriz = [];
        $distCertInfo = null;
        $nfePorMes = [];
        if ($aba === 'nfe_nfse') {
            $certificadosMatriz = CertificadoMatriz::listar();

            // Volume mensal de TODAS as notas capturadas, de qualquer origem, em todos os
            // meses — por data da captura, não da emissão. Assim o total fecha com o "Total"
            // por filial da tabela acima (que também conta todas as origens). As colunas
            // Direta / Brobot / Outras mostram de onde cada nota veio.
            $nfePorMes = Database::getInstance()->query(
                "SELECT DATE_FORMAT(capturada_em, '%Y-%m') AS mes,
                        COUNT(*)                      AS total,
                        SUM(tipo_documento = 'NFe')   AS nfe,
                        SUM(tipo_documento = 'CTe')   AS cte,
                        SUM(tipo_documento = 'NFSe')  AS nfse,
                        SUM(tipo_documento = 'CTOs')  AS ctos,
                        SUM(origem IN ('nfe_dist', 'adn'))                                 AS direta,
                        SUM(origem = 'brobot')                                             AS brobot,
                        SUM(origem IS NULL OR origem NOT IN ('nfe_dist', 'adn', 'brobot')) AS outras,
                        SUM(status = 'vinculada')     AS vinculadas
                 FROM nf_notas_capturadas
                 GROUP BY mes
                 ORDER BY mes DESC"
            )->fetchAll();
            $filiaisDist = Database::getInstance()->query(
                "SELECT f.id, f.nome, f.cnpj, f.estado, f.ativo,
                        f.adn_nfse_ativo, f.nfe_dist_ativo, f.dist_horario_inicio, f.dist_horario_fim,
                        f.dist_intervalo_min, f.dist_cert_path, f.dist_cert_senha,
                        (SELECT status   FROM nf_adn_log  WHERE filial_id = f.id ORDER BY iniciado_em DESC LIMIT 1) AS adn_status,
                        (SELECT detalhe  FROM nf_adn_log  WHERE filial_id = f.id ORDER BY iniciado_em DESC LIMIT 1) AS adn_detalhe,
                        (SELECT iniciado_em FROM nf_adn_log WHERE filial_id = f.id ORDER BY iniciado_em DESC LIMIT 1) AS adn_quando,
                        (SELECT status   FROM nf_dist_log WHERE filial_id = f.id ORDER BY iniciado_em DESC LIMIT 1) AS nfe_status,
                        (SELECT detalhe  FROM nf_dist_log WHERE filial_id = f.id ORDER BY iniciado_em DESC LIMIT 1) AS nfe_detalhe,
                        (SELECT iniciado_em FROM nf_dist_log WHERE filial_id = f.id ORDER BY iniciado_em DESC LIMIT 1) AS nfe_quando,
                        (SELECT COUNT(*) FROM nf_notas_capturadas WHERE filial_id = f.id AND tipo_documento = 'NFe')  AS qtd_nfe,
                        (SELECT COUNT(*) FROM nf_notas_capturadas WHERE filial_id = f.id AND tipo_documento = 'NFSe') AS qtd_nfse
                 FROM filiais f
                 WHERE f.ativo = 1
                 ORDER BY f.nome ASC"
            )->fetchAll();

            // Validade/status de cada certificado — cache por arquivo+senha pra não
            // abrir o mesmo .pfx uma vez por filial (a maioria usa o da matriz).
            $certCache = [];
            $inspecionar = function (?string $path, ?string $senha) use (&$certCache): ?array {
                $path = (string) $path;
                if ($path === '') {
                    return null;
                }
                $chave = $path . '|' . md5((string) $senha);
                if (!isset($certCache[$chave])) {
                    $certCache[$chave] = CertificadoPfx::inspecionar($path, (string) $senha);
                }
                return $certCache[$chave];
            };

            $padraoPath  = $cfg['dist']['cert_path']  ?? '';
            $padraoSenha = $cfg['dist']['cert_senha'] ?? '';
            $distCertInfo = $inspecionar($padraoPath, $padraoSenha);

            foreach ($certificadosMatriz as &$c) {
                $c['cert_info'] = $inspecionar($c['cert_path'] ?? null, $c['cert_senha'] ?? null);
            }
            unset($c);

            foreach ($filiaisDist as &$f) {
                [$pathResolvido, $senhaResolvida] = CertificadoMatriz::resolverParaFilial(
                    ['cnpj' => $f['cnpj'], 'dist_cert_path' => $f['dist_cert_path'], 'dist_cert_senha' => $f['dist_cert_senha']],
                    $padraoPath,
                    $padraoSenha
                );
                $f['cert_info'] = $inspecionar($pathResolvido, $senhaResolvida);
            }
            unset($f);
        }

        // Aba Apollo: dropdown de tipos de processo, gestão de acesso e último resultado
        $apolloTemplates = [];
        $apolloLiberados = [];
        $apolloUsuarios  = [];
        $apolloResultado = $_SESSION['apollo_resultado'] ?? null;
        unset($_SESSION['apollo_resultado']);
        if ($aba === 'apollo') {
            require_once SRC . '/Models/ApolloConsulta.php';
            $apolloTemplates = ApolloConsulta::templatesElegiveis();
            if ($isAdmin) {
                $apolloLiberados = ApolloAccess::listarLiberados();
                $apolloUsuarios  = Database::getInstance()
                    ->query("SELECT id, nome, email FROM usuarios ORDER BY nome ASC")
                    ->fetchAll();
            }
        }

        $pageTitle    = 'Geral';
        $activeModule = 'geral';
        $currentUser  = Auth::user();
        $flash        = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        ob_start();
        include VIEWS . '/geral/index.php';
        $content = ob_get_clean();
        include VIEWS . '/layout/base.php';
        break;

    // ── POST: salvar configurações de uma aba ─────────────
    case 'salvar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=geral&action=index');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $grupo = trim($_POST['grupo'] ?? '');
        if (!in_array($grupo, ['geral', 'email', 'seguranca', 'notificacoes', 'api', 'brobot', 'apollo', 'assinafy', 'clicksign'], true)) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Grupo de configuração inválido.'];
            header('Location: ?module=geral&action=index');
            exit;
        }

        // Só chaves conhecidas do grupo são gravadas (whitelist da própria tabela)
        $existentes = array_keys(Settings::grupo($grupo));
        $pares = [];
        foreach ($existentes as $chave) {
            if (array_key_exists($chave, $_POST['cfg'] ?? [])) {
                $pares[$chave] = strip_tags(trim((string) $_POST['cfg'][$chave]));
            }
        }

        // Senha de e-mail em branco = manter a atual (não sobrescrever com vazio)
        if ($grupo === 'email' && isset($pares['email.senha']) && $pares['email.senha'] === '') {
            unset($pares['email.senha']);
        }

        // Token da Brobot em branco = manter o atual (campo mascarado)
        if ($grupo === 'brobot' && isset($pares['brobot.token']) && $pares['brobot.token'] === '') {
            unset($pares['brobot.token']);
        }

        // Chave da Assinafy em branco = manter a atual (campo mascarado).
        // Vale para o token do webhook também: são segredos que a tela nunca
        // reexibe, então salvar a página não pode apagá-los sem querer.
        if ($grupo === 'assinafy') {
            foreach (['assinafy.api_key', 'assinafy.webhook_token'] as $secreto) {
                if (isset($pares[$secreto]) && $pares[$secreto] === '') {
                    unset($pares[$secreto]);
                }
            }
        }

        // Idem Clicksign: token de acesso e token do webhook nunca são reexibidos.
        if ($grupo === 'clicksign') {
            foreach (['clicksign.access_token', 'clicksign.webhook_token'] as $secreto) {
                if (isset($pares[$secreto]) && $pares[$secreto] === '') {
                    unset($pares[$secreto]);
                }
            }
        }

        // Senha do Apollo em branco = manter a atual (campo mascarado)
        if ($grupo === 'apollo' && isset($pares['apollo.pass']) && $pares['apollo.pass'] === '') {
            unset($pares['apollo.pass']);
        }

        if (!empty($pares)) {
            Settings::salvar($pares, Auth::id());
            AuditLog::registrar('nf_configuracoes', $grupo, 'nf_config.atualizada', null, ['chaves' => array_keys($pares)]);
        }

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Configurações salvas.'];
        header('Location: ?module=geral&action=index&aba=' . ($grupo === 'geral' ? 'geral' : $grupo));
        exit;

    // ── POST: ligar/desligar abas da tela Geral ───────────
    case 'salvar_modulos':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=geral&action=index');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');
        // Checkbox desmarcado não vem no POST: 'no POST' = ligado, 'ausente' = desligado.
        $marcados = array_map('strval', (array) ($_POST['modulos'] ?? []));
        $pares = [];
        foreach (array_keys(Settings::ABAS_OPCIONAIS) as $chaveAba) {
            $pares['modulos.' . $chaveAba] = in_array($chaveAba, $marcados, true) ? '1' : '0';
        }
        Settings::salvar($pares, Auth::id());
        AuditLog::registrar('nf_configuracoes', 'modulos', 'nf_config.atualizada', null, ['modulos' => $pares]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Módulos atualizados.'];
        header('Location: ?module=geral&action=index&aba=geral');
        exit;

    // ── POST: enviar e-mail de teste ──────────────────────
    case 'testar_email':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=geral&action=index&aba=email');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');
        require_once SRC . '/Helpers/Mailer.php';

        $para = filter_var(trim($_POST['email_teste'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$para) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Informe um e-mail válido para o teste.'];
            header('Location: ?module=geral&action=index&aba=email');
            exit;
        }

        $ok = Mailer::enviar($para, '[Workflow NF] E-mail de teste',
            '<p>Este é um e-mail de teste do Workflow NF — Exemplo.</p>'
            . '<p>Se você recebeu esta mensagem, a configuração de e-mail está funcionando.</p>');

        $modo = Settings::get('email.modo', 'log');
        $_SESSION['flash'] = $modo === 'log'
            ? ['type' => 'info', 'message' => 'Modo "log" ativo: o e-mail foi gravado em storage/logs/emails.log (não enviado). Para enviar de verdade, mude o modo para SMTP.']
            : ($ok
                ? ['type' => 'success', 'message' => 'E-mail de teste enviado para ' . $para . '.']
                : ['type' => 'danger', 'message' => 'Falha ao enviar. Verifique host, porta, usuário e senha (detalhes em storage/logs/emails.log).']);

        header('Location: ?module=geral&action=index&aba=email');
        exit;

    // ── POST: testar conexão com a Brobot ─────────────────
    case 'testar_brobot':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=geral&action=index&aba=brobot');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');
        require_once SRC . '/Helpers/BrobotClient.php';

        $r = BrobotClient::fromSettings()->testarConexao();
        $_SESSION['flash'] = ['type' => $r['ok'] ? 'success' : 'danger', 'message' => $r['mensagem']];
        header('Location: ?module=geral&action=index&aba=brobot');
        exit;

    // ── POST: salvar flags de integração por filial ───────
    case 'salvar_filiais_brobot':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=geral&action=index&aba=brobot');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');
        require_once SRC . '/Models/BrobotBackfill.php';

        $pdo   = Database::getInstance();
        $flags = $_POST['brobot_flags'] ?? []; // [filialId => ['nfe'=>'1', 'cte'=>'1', ...]]
        $stmt  = $pdo->prepare(
            "UPDATE filiais SET brobot_nfe = ?, brobot_cte = ?, brobot_nfse = ?, brobot_ctos = ? WHERE id = ?"
        );

        // Estado atual ANTES de gravar — para detectar filiais que estão sendo
        // LIGADAS agora (qualquer tipo 0 → 1) e disparar o backfill histórico.
        $atuais = $pdo->query(
            "SELECT id, cnpj, ativo, brobot_nfe, brobot_cte, brobot_nfse, brobot_ctos FROM filiais"
        )->fetchAll(PDO::FETCH_ASSOC);

        $dias      = Settings::getInt('brobot.backfill_ligar_dias', 90);
        $n         = 0;
        $backfills = 0;
        foreach ($atuais as $f) {
            $fid = $f['id'];
            $sub = $flags[$fid] ?? [];
            $novo = [
                'nfe'  => !empty($sub['nfe'])  ? 1 : 0,
                'cte'  => !empty($sub['cte'])  ? 1 : 0,
                'nfse' => !empty($sub['nfse']) ? 1 : 0,
                'ctos' => !empty($sub['ctos']) ? 1 : 0,
            ];
            $stmt->execute([$novo['nfe'], $novo['cte'], $novo['nfse'], $novo['ctos'], $fid]);
            $n++;

            // Alguma flag passou de 0 → 1 nesta filial ativa? Enfileira backfill.
            $ligou = false;
            foreach (['nfe', 'cte', 'nfse', 'ctos'] as $tk) {
                if ($novo[$tk] === 1 && (int) ($f['brobot_' . $tk] ?? 0) === 0) { $ligou = true; break; }
            }
            if ($ligou && (int) $f['ativo'] === 1) {
                $cnpj = preg_replace('/\D/', '', (string) $f['cnpj']);
                if (strlen($cnpj) === 14) {
                    BrobotBackfill::enfileirar($fid, $cnpj, $dias);
                    $backfills++;
                }
            }
        }

        AuditLog::registrar('filiais', 'brobot', 'nf_config.filiais_brobot', null,
            ['filiais' => $n, 'backfills' => $backfills]);

        $msg = 'Integração por filial atualizada.';
        if ($backfills > 0) {
            $msg .= " {$backfills} filial(is) recém-ligada(s) entraram na fila de busca histórica no Brobot"
                  . " — as notas anteriores entram em segundo plano nos próximos minutos.";
        }
        $_SESSION['flash'] = ['type' => 'success', 'message' => $msg];
        header('Location: ?module=geral&action=index&aba=brobot');
        exit;

    // ── POST: testar conexão com o Apollo (admin) ─────────
    case 'apollo_testar':
        if (!$isAdmin) { http_response_code(403); include VIEWS . '/errors/403.php'; exit; }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?module=geral&action=index&aba=apollo'); exit; }
        Csrf::validate($_POST['csrf_token'] ?? '');
        require_once SRC . '/Helpers/ApolloClient.php';

        $r = ApolloClient::testarConexao();
        $_SESSION['flash'] = ['type' => $r['ok'] ? 'success' : 'danger', 'message' => $r['mensagem']];
        header('Location: ?module=geral&action=index&aba=apollo');
        exit;

    // ── POST: rodar a consulta em lote (console liberado por usuário) ──
    case 'apollo_consultar':
        if (!$isAdmin && !$apolloConsole) { http_response_code(403); include VIEWS . '/errors/403.php'; exit; }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?module=geral&action=index&aba=apollo'); exit; }
        Csrf::validate($_POST['csrf_token'] ?? '');
        require_once SRC . '/Models/ApolloConsulta.php';

        $templateId = trim($_POST['template_id'] ?? '');
        $encerrar   = !empty($_POST['encerrar']);
        if ($templateId === '') {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Selecione um tipo de processo.'];
            header('Location: ?module=geral&action=index&aba=apollo');
            exit;
        }

        $stmtN = Database::getInstance()->prepare("SELECT nome FROM nf_templates WHERE id = ? LIMIT 1");
        $stmtN->execute([$templateId]);
        $templateNome = (string) ($stmtN->fetchColumn() ?: '—');

        try {
            $usuario = Auth::user();
            $resumo  = ApolloConsulta::executar(
                $templateId, $templateNome, $encerrar,
                Auth::id(), (string) ($usuario['nome'] ?? '—')
            );
            $_SESSION['apollo_resultado'] = $resumo + ['template_nome' => $templateNome, 'encerrar' => $encerrar];
            $_SESSION['flash'] = ($resumo['status'] ?? '') === 'falha'
                ? ['type' => 'danger', 'message' => 'Falha ao consultar o Apollo: ' . ($resumo['erro'] ?? 'erro desconhecido')]
                : ['type' => 'success', 'message' => 'Consulta concluída.'];
        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Erro: ' . $e->getMessage()];
        }
        header('Location: ?module=geral&action=index&aba=apollo');
        exit;

    // ── POST: conceder acesso ao console Apollo (admin) ───
    case 'apollo_liberar':
        if (!$isAdmin) { http_response_code(403); include VIEWS . '/errors/403.php'; exit; }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?module=geral&action=index&aba=apollo'); exit; }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $uid = trim($_POST['usuario_id'] ?? '');
        if ($uid !== '') {
            ApolloAccess::conceder($uid, Auth::id());
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Acesso ao console Apollo concedido.'];
        }
        header('Location: ?module=geral&action=index&aba=apollo');
        exit;

    // ── POST: revogar acesso ao console Apollo (admin) ────
    case 'apollo_revogar':
        if (!$isAdmin) { http_response_code(403); include VIEWS . '/errors/403.php'; exit; }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?module=geral&action=index&aba=apollo'); exit; }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $uid = trim($_POST['usuario_id'] ?? '');
        if ($uid !== '') {
            ApolloAccess::revogar($uid, Auth::id());
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Acesso ao console Apollo revogado.'];
        }
        header('Location: ?module=geral&action=index&aba=apollo');
        exit;

    // ── POST: criar/editar filial (cadastro completo) ─────
    case 'salvar_filial':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=geral&action=index&aba=filiais');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $id     = trim($_POST['id'] ?? '');
        $nome   = trim(strip_tags($_POST['nome'] ?? ''));
        $cnpjD  = preg_replace('/\D/', '', (string) ($_POST['cnpj'] ?? ''));
        // Campo é <input type="number"> — o navegador já manda formato de máquina
        // ("15000.50", ponto decimal, sem milhar). Nada de desfazer máscara BR aqui.
        $budget = (float) ($_POST['budget_mensal'] ?? 0);

        if ($nome === '') {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Informe o nome da filial.'];
            header('Location: ?module=geral&action=index&aba=filiais');
            exit;
        }
        if ($cnpjD !== '' && strlen($cnpjD) !== 14) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CNPJ inválido — informe 14 dígitos ou deixe em branco.'];
            header('Location: ?module=geral&action=index&aba=filiais');
            exit;
        }

        $campos = [
            'nome'          => $nome,
            'cnpj'          => $cnpjD !== '' ? $cnpjD : null,
            'endereco'      => trim(strip_tags($_POST['endereco'] ?? '')) ?: null,
            'telefone'      => trim(strip_tags($_POST['telefone'] ?? '')) ?: null,
            'email'         => trim(strip_tags($_POST['email'] ?? '')) ?: null,
            'cidade'        => trim(strip_tags($_POST['cidade'] ?? '')) ?: null,
            'estado'        => mb_substr(trim(strip_tags($_POST['estado'] ?? '')), 0, 2) ?: null,
            'budget_mensal' => $budget,
        ];

        $pdo = Database::getInstance();
        if ($id !== '') {
            $pdo->prepare(
                "UPDATE filiais SET nome=?, cnpj=?, endereco=?, telefone=?, email=?, cidade=?, estado=?, budget_mensal=?
                 WHERE id = ?"
            )->execute([...array_values($campos), $id]);
            $acao = 'nf_filial.atualizada';
        } else {
            $id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
                random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
                random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff));
            $pdo->prepare(
                "INSERT INTO filiais (id, nome, cnpj, endereco, telefone, email, cidade, estado, budget_mensal, ativo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
            )->execute([$id, ...array_values($campos)]);
            $acao = 'nf_filial.criada';
        }

        AuditLog::registrar('filiais', $id, $acao, null, ['nome' => $nome]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Filial salva.'];
        header('Location: ?module=geral&action=index&aba=filiais');
        exit;

    // ── POST: ativar/desativar filial ─────────────────────
    case 'toggle_filial_ativo':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=geral&action=index&aba=filiais');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $id = trim($_POST['id'] ?? '');
        if ($id !== '') {
            Database::getInstance()
                ->prepare("UPDATE filiais SET ativo = NOT ativo WHERE id = ?")
                ->execute([$id]);
            AuditLog::registrar('filiais', $id, 'nf_filial.status_alterado');
        }
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Status da filial atualizado.'];
        header('Location: ?module=geral&action=index&aba=filiais');
        exit;

    // ── POST: liga/desliga + janela + certificado PRÓPRIO (opcional) ──────
    // Certificado por filial só é preciso quando a raiz de CNPJ dela é
    // diferente da matriz (Clubmotors, B7 Participações...) — na maioria das
    // filiais isso fica em branco e usa o padrão configurado abaixo.
    case 'salvar_filial_dist':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=geral&action=index&aba=nfe_nfse');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $id = trim($_POST['id'] ?? '');
        if ($id === '') {
            header('Location: ?module=geral&action=index&aba=nfe_nfse');
            exit;
        }

        $filialAtualStmt = Database::getInstance()->prepare('SELECT dist_cert_path, dist_cert_senha FROM filiais WHERE id = ?');
        $filialAtualStmt->execute([$id]);
        $filialAtual = $filialAtualStmt->fetch() ?: ['dist_cert_path' => null, 'dist_cert_senha' => null];

        $nfeAtivo  = !empty($_POST['nfe_dist_ativo'])  ? 1 : 0;
        $nfseAtivo = !empty($_POST['adn_nfse_ativo'])  ? 1 : 0;

        $horaOk = fn(string $v) => preg_match('/^\d{2}:\d{2}$/', $v) === 1;
        $horaIni = trim($_POST['dist_horario_inicio'] ?? '00:00');
        $horaFim = trim($_POST['dist_horario_fim'] ?? '06:00');
        if (!$horaOk($horaIni)) $horaIni = '00:00';
        if (!$horaOk($horaFim)) $horaFim = '06:00';

        // Intervalo mínimo entre runs — opcional. Em branco = null (só a
        // janela decide, comportamento de sempre).
        $intervaloRaw = trim($_POST['dist_intervalo_min'] ?? '');
        $intervalo    = ($intervaloRaw !== '' && ctype_digit($intervaloRaw)) ? (int) $intervaloRaw : null;

        $novoArquivoTmp = null;
        if (!empty($_FILES['dist_cert']['tmp_name']) && is_uploaded_file($_FILES['dist_cert']['tmp_name'])) {
            $nomeOriginal = (string) ($_FILES['dist_cert']['name'] ?? '');
            $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pfx', 'p12'], true)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Certificado deve ser um arquivo .pfx ou .p12.'];
                header('Location: ?module=geral&action=index&aba=nfe_nfse');
                exit;
            }
            $novoArquivoTmp = $_FILES['dist_cert']['tmp_name'];
        }

        $senhaFilial = trim($_POST['dist_cert_senha'] ?? '');

        // Confere a senha ABRINDO o .pfx antes de sobrescrever o que já está
        // salvo — se a senha estiver errada, nada é gravado e o certificado
        // (e a senha) antigos continuam valendo.
        $pathParaTestar  = $novoArquivoTmp ?? ($filialAtual['dist_cert_path'] ?: null);
        $senhaParaTestar = $senhaFilial !== '' ? $senhaFilial : (string) ($filialAtual['dist_cert_senha'] ?? '');
        if ($pathParaTestar !== null) {
            $chk = CertificadoPfx::inspecionar($pathParaTestar, $senhaParaTestar);
            if (!$chk['ok']) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => $chk['erro']];
                header('Location: ?module=geral&action=index&aba=nfe_nfse');
                exit;
            }
        }

        $sets = ['nfe_dist_ativo = ?', 'adn_nfse_ativo = ?', 'dist_horario_inicio = ?', 'dist_horario_fim = ?', 'dist_intervalo_min = ?'];
        $vals = [$nfeAtivo, $nfseAtivo, $horaIni . ':00', $horaFim . ':00', $intervalo];

        if ($novoArquivoTmp !== null) {
            $dirCert = (defined('STORAGE') ? STORAGE : ROOT . '/storage') . '/certificados';
            if (!is_dir($dirCert)) @mkdir($dirCert, 0770, true);
            $certPath = $dirCert . '/' . $id . '.pfx';
            if (!move_uploaded_file($novoArquivoTmp, $certPath)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Falha ao salvar o certificado enviado.'];
                header('Location: ?module=geral&action=index&aba=nfe_nfse');
                exit;
            }
            $sets[] = 'dist_cert_path = ?';
            $vals[] = $certPath;
        }

        if ($senhaFilial !== '') {
            $sets[] = 'dist_cert_senha = ?';
            $vals[] = $senhaFilial;
        }

        $vals[] = $id;
        Database::getInstance()
            ->prepare('UPDATE filiais SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($vals);

        AuditLog::registrar('filiais', $id, 'nf_filial.dist_atualizada', null,
            ['nfe_ativo' => $nfeAtivo, 'nfse_ativo' => $nfseAtivo, 'janela' => "{$horaIni}-{$horaFim}",
             'intervalo_min' => $intervalo]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Configuração de captura direta salva.'];
        header('Location: ?module=geral&action=index&aba=nfe_nfse');
        exit;

    // ── POST: remover o certificado PRÓPRIO de uma filial ──────────────────
    // Volta a usar o certificado da matriz/padrão. Útil quando o próprio está
    // quebrado (ex.: PFX num formato que o OpenSSL do servidor não abre) e a
    // matriz cobre a mesma raiz de CNPJ.
    case 'remover_certificado_filial':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=geral&action=index&aba=nfe_nfse');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $id = trim($_POST['id'] ?? '');
        if ($id !== '') {
            $stmt = Database::getInstance()->prepare('SELECT dist_cert_path FROM filiais WHERE id = ?');
            $stmt->execute([$id]);
            $filialCert = $stmt->fetch();

            if ($filialCert) {
                Database::getInstance()
                    ->prepare('UPDATE filiais SET dist_cert_path = NULL, dist_cert_senha = NULL WHERE id = ?')
                    ->execute([$id]);
                if (!empty($filialCert['dist_cert_path']) && is_file($filialCert['dist_cert_path'])) {
                    @unlink($filialCert['dist_cert_path']);
                }
                AuditLog::registrar('filiais', $id, 'nf_filial.certificado_proprio_removido');
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Certificado próprio removido — a filial volta a usar o certificado da matriz/padrão.'];
            }
        }
        header('Location: ?module=geral&action=index&aba=nfe_nfse');
        exit;

    // ── POST: certificado por MATRIZ (raiz de CNPJ) — Portal Nacional/ADN ─
    // Um grupo pode ter mais de uma raiz de CNPJ (empresas separadas); cada
    // raiz precisa do próprio certificado. Cadastro manual (não auto-detecta
    // raiz nenhuma) — o admin digita o CNPJ da matriz e sobe o certificado.
    case 'salvar_certificado_matriz':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=geral&action=index&aba=nfe_nfse');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $raiz = preg_replace('/\D/', '', (string) ($_POST['cnpj_raiz'] ?? ''));
        if (strlen($raiz) !== 8) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CNPJ da matriz inválido — informe os 8 primeiros dígitos (raiz).'];
            header('Location: ?module=geral&action=index&aba=nfe_nfse');
            exit;
        }
        $nome = trim(strip_tags((string) ($_POST['nome'] ?? '')));
        $existente = CertificadoMatriz::porRaiz($raiz);

        $novoArquivoTmp = null;
        if (!empty($_FILES['cert']['tmp_name']) && is_uploaded_file($_FILES['cert']['tmp_name'])) {
            $nomeOriginal = (string) ($_FILES['cert']['name'] ?? '');
            $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pfx', 'p12'], true)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Certificado deve ser um arquivo .pfx ou .p12.'];
                header('Location: ?module=geral&action=index&aba=nfe_nfse');
                exit;
            }
            $novoArquivoTmp = $_FILES['cert']['tmp_name'];
        }

        $senha = trim($_POST['cert_senha'] ?? '');

        // Confere a senha ABRINDO o .pfx antes de sobrescrever o que já está
        // salvo — se a senha estiver errada, nada é gravado e o certificado
        // (e a senha) antigos continuam valendo.
        $pathParaTestar  = $novoArquivoTmp ?? ($existente['cert_path'] ?? null);
        $senhaParaTestar = $senha !== '' ? $senha : (string) ($existente['cert_senha'] ?? '');
        if (!empty($pathParaTestar)) {
            $chk = CertificadoPfx::inspecionar($pathParaTestar, $senhaParaTestar);
            if (!$chk['ok']) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => $chk['erro']];
                header('Location: ?module=geral&action=index&aba=nfe_nfse');
                exit;
            }
        }

        $certPath = null;
        if ($novoArquivoTmp !== null) {
            $dirCert = (defined('STORAGE') ? STORAGE : ROOT . '/storage') . '/certificados';
            if (!is_dir($dirCert)) @mkdir($dirCert, 0770, true);
            $certPath = $dirCert . '/matriz_' . $raiz . '.pfx';
            if (!move_uploaded_file($novoArquivoTmp, $certPath)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Falha ao salvar o certificado enviado.'];
                header('Location: ?module=geral&action=index&aba=nfe_nfse');
                exit;
            }
        }

        CertificadoMatriz::salvar($raiz, $nome ?: null, $certPath, $senha !== '' ? $senha : null, Auth::id());
        AuditLog::registrar('nf_certificados_matriz', $raiz, 'nf_config.certificado_matriz_salvo');

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Certificado da matriz salvo.'];
        header('Location: ?module=geral&action=index&aba=nfe_nfse');
        exit;

    // ── POST: remover certificado de uma matriz ────────────────────────
    case 'remover_certificado_matriz':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=geral&action=index&aba=nfe_nfse');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $id = trim($_POST['id'] ?? '');
        if ($id !== '') {
            $cert = CertificadoMatriz::findById($id);
            if ($cert && CertificadoMatriz::excluir($id)) {
                if (!empty($cert['cert_path']) && is_file($cert['cert_path'])) {
                    @unlink($cert['cert_path']);
                }
                AuditLog::registrar('nf_certificados_matriz', $id, 'nf_config.certificado_matriz_removido');
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Certificado da matriz removido.'];
            }
        }
        header('Location: ?module=geral&action=index&aba=nfe_nfse');
        exit;

    // ── POST: certificado único (matriz) — Portal Nacional/ADN ────────────
    // Um só certificado, reaproveitado pra todas as filiais via cnpjConsulta
    // (REST/ADN) ou a tag <CNPJ> (SOAP/SEFAZ) — confirmado em 16/09/2026 que
    // isso vale pra qualquer filial da mesma raiz de CNPJ da matriz.
    case 'salvar_dist_certificado':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=geral&action=index&aba=nfe_nfse');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $pares = [];

        $novoArquivoTmp = null;
        if (!empty($_FILES['dist_cert']['tmp_name']) && is_uploaded_file($_FILES['dist_cert']['tmp_name'])) {
            $nomeOriginal = (string) ($_FILES['dist_cert']['name'] ?? '');
            $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pfx', 'p12'], true)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Certificado deve ser um arquivo .pfx ou .p12.'];
                header('Location: ?module=geral&action=index&aba=nfe_nfse');
                exit;
            }
            $novoArquivoTmp = $_FILES['dist_cert']['tmp_name'];
        }

        $senha = trim($_POST['dist_cert_senha'] ?? '');

        // Confere a senha ABRINDO o .pfx antes de sobrescrever o que já está
        // salvo — se a senha estiver errada, nada é gravado e o certificado
        // (e a senha) antigos continuam valendo.
        $pathParaTestar  = $novoArquivoTmp ?? (Settings::get('dist.cert_path', '') ?: null);
        $senhaParaTestar = $senha !== '' ? $senha : (string) Settings::get('dist.cert_senha', '');
        if (!empty($pathParaTestar)) {
            $chk = CertificadoPfx::inspecionar($pathParaTestar, $senhaParaTestar);
            if (!$chk['ok']) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => $chk['erro']];
                header('Location: ?module=geral&action=index&aba=nfe_nfse');
                exit;
            }
        }

        if ($novoArquivoTmp !== null) {
            $dirCert = (defined('STORAGE') ? STORAGE : ROOT . '/storage') . '/certificados';
            if (!is_dir($dirCert)) @mkdir($dirCert, 0770, true);
            $certPath = $dirCert . '/matriz.pfx';
            if (!move_uploaded_file($novoArquivoTmp, $certPath)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Falha ao salvar o certificado enviado.'];
                header('Location: ?module=geral&action=index&aba=nfe_nfse');
                exit;
            }
            $pares['dist.cert_path'] = $certPath;
        }

        if ($senha !== '') {
            $pares['dist.cert_senha'] = $senha;
        }

        if (!empty($pares)) {
            Settings::salvar($pares, Auth::id());
            AuditLog::registrar('nf_configuracoes', 'dist', 'nf_config.atualizada', null,
                ['chaves' => array_keys($pares)]);
        }

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Certificado da matriz salvo.'];
        header('Location: ?module=geral&action=index&aba=nfe_nfse');
        exit;

    default:
        http_response_code(404);
        include VIEWS . '/errors/404.php';
        exit;
}
