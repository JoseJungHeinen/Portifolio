<?php
// @versao Sys_01 V_10_17/09/2026
declare(strict_types=1);

/**
 * Entry point único do módulo Workflow NF — Exemplo.
 *
 * Fluxo de execução:
 *   1. Carregar constantes e configurações
 *   2. Configurar sessão e iniciar
 *   3. Carregar helpers e middlewares
 *   4. Aplicar middlewares (autenticação + CSRF)
 *   5. Rotear para o controller correto
 */

// ─────────────────────────────────────────
// 1. Constantes e configurações
// ─────────────────────────────────────────
require_once __DIR__ . '/../config/constants.php';
require_once CONFIG . '/database.php';

// ─────────────────────────────────────────
// 2. Configuração e início de sessão
// ─────────────────────────────────────────
require_once CONFIG . '/session.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─────────────────────────────────────────
// 3. Autoload manual dos Helpers e Middlewares
// ─────────────────────────────────────────
require_once SRC . '/Helpers/Sanitize.php';     // inclui função h() global
require_once SRC . '/Helpers/Settings.php';
require_once SRC . '/Helpers/Auth.php';
require_once SRC . '/Helpers/Permissions.php';
require_once SRC . '/Helpers/Csrf.php';
require_once SRC . '/Helpers/AuditLog.php';
require_once SRC . '/Helpers/DateHelper.php';
require_once SRC . '/Helpers/SlaCalculator.php';
require_once SRC . '/Helpers/ProcessAccess.php';
require_once SRC . '/Helpers/ApolloAccess.php';
require_once SRC . '/Helpers/TemplateSigilo.php';
require_once SRC . '/Helpers/FilialScope.php';
require_once SRC . '/Helpers/ReportAccess.php';
require_once SRC . '/Helpers/FiltroSessao.php';
// Model, não helper — mas a trava de horário roda no middleware, antes de
// qualquer controller carregar seus models.
require_once SRC . '/Models/AccessSchedule.php';
require_once SRC . '/Middleware/AuthMiddleware.php';
require_once SRC . '/Middleware/CsrfMiddleware.php';

// ─────────────────────────────────────────
// 4. Aplicar middlewares
// ─────────────────────────────────────────
AuthMiddleware::handle();   // redireciona se não autenticado
CsrfMiddleware::handle();   // bloqueia POST sem token válido

// ─────────────────────────────────────────
// 5. Roteamento
// ─────────────────────────────────────────

/** Módulo solicitado — padrão: Minhas Tarefas (tela inicial do sistema) */
$module = Sanitize::fromGet(['module'])['module'];
$module = $module !== '' ? $module : 'tarefas';

/** Ação solicitada — padrão: index */
$action = Sanitize::fromGet(['action'])['action'];
$action = $action !== '' ? $action : 'index';

// Validar módulo contra whitelist de módulos válidos
if (!in_array($module, VALID_MODULES, true)) {
    http_response_code(404);
    include VIEWS . '/errors/404.php';
    exit;
}

/**
 * Mapa de módulos para controllers.
 * Cada controller é um arquivo em src/Controllers/ que processa a $action.
 */
$controllerMap = [
    'auth'         => 'AuthController.php',
    'home'         => 'HomeController.php',
    'account'      => 'AccountController.php',
    'dashboard'    => 'DashboardController.php',
    'capturas'     => 'CaptureController.php',
    'prefluxo'     => 'PreFluxoController.php',
    'processos'    => 'ProcessController.php',
    'tarefas'      => 'TaskController.php',
    'financeiro'   => 'FinancialController.php',
    'fiscal'       => 'FiscalController.php',
    'templates'    => 'TemplateController.php',
    'grupos'       => 'GroupController.php',
    'tabelas'      => 'TableController.php',
    'relatorios'   => 'ReportController.php',
    'importar'     => 'ImportController.php',
    'arquivos'     => 'FileController.php',
    'notificacoes' => 'NotificationController.php',
    'acessos'      => 'AccessController.php',
    'horarios'     => 'ScheduleController.php',
    'geral'        => 'GeneralController.php',
    'faturamento'  => 'FaturamentoController.php',
];

$controllerFile = $controllerMap[$module] ?? null;

if ($controllerFile === null) {
    http_response_code(404);
    include VIEWS . '/errors/404.php';
    exit;
}

$controllerPath = SRC . '/Controllers/' . $controllerFile;

if (!file_exists($controllerPath)) {
    // Controller ainda não implementado (fases futuras)
    http_response_code(501);
    $moduloNome = ucfirst($module);
    include VIEWS . '/errors/501.php';
    exit;
}

// Carregar e executar o controller
require_once $controllerPath;
