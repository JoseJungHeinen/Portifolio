<?php
// @versao Sys_29 V_07_18/09/2026
declare(strict_types=1);

/**
 * Constantes globais do módulo Workflow NF — Exemplo.
 * Carregado no topo de public/index.php antes de qualquer include.
 */

// Fuso horário da aplicação — o php.ini do servidor não define date.timezone
// corretamente para o Brasil, o que exibia horários errados na interface.
date_default_timezone_set('America/Sao_Paulo');

// ─────────────────────────────────────────
// Caminhos do sistema de arquivos
// ─────────────────────────────────────────

/** Raiz do projeto (um nível acima de public/) */
define('ROOT', dirname(__DIR__));

/** Diretório de código-fonte (Models, Controllers, Helpers, Middleware) */
define('SRC', ROOT . '/src');

/** Diretório de templates de views */
define('VIEWS', ROOT . '/views');

/** Diretório de armazenamento privado de arquivos (uploads) */
define('STORAGE', ROOT . '/storage');

/** Diretório de configurações */
define('CONFIG', ROOT . '/config');

// ─────────────────────────────────────────
// Ambiente
// ─────────────────────────────────────────

/** true = produção (HTTPS, logs silenciosos). false = desenvolvimento local (Laragon) */
define('IS_PRODUCTION', false);

/**
 * Prefixo de URL quando o app roda em subdiretório (ex.: http://host/Notas).
 * Definido pelo servidor via `SetEnv APP_BASE_URL /Notas` no .htaccess.
 * Vazio no dev local (app na raiz) — assets ficam em /public/... como antes.
 */
define('BASE_URL', rtrim((string) ($_SERVER['APP_BASE_URL'] ?? getenv('APP_BASE_URL') ?: ''), '/'));

/**
 * URL absoluta (a partir da raiz do domínio) do entry point público
 * (public/index.php) — usada nos redirects internos de login/logout, que
 * não passam pelo RewriteRule do .htaccess e por isso precisam do caminho
 * físico certo.
 *
 * Local e Server_novo: BASE_URL aponta pra raiz do app (`/Notas` → pasta
 * que contém public/), então o entry point físico é BASE_URL + '/public'.
 * Server-APP: o Alias do vhost já mira direto em .../public (BASE_URL JÁ é
 * o public) — com o sufixo, a URL vira public/public/index.php, 404. Esse
 * servidor define `SetEnv APP_PUBLIC_URL /Notas` (sem sufixo) no vhost;
 * sem a env var, cai no padrão BASE_URL + '/public' (local/Server_novo).
 * Ver docs/ACESSO-SERVIDORES.md.
 */
$appPublicUrlEnv = $_SERVER['APP_PUBLIC_URL'] ?? (getenv('APP_PUBLIC_URL') !== false ? getenv('APP_PUBLIC_URL') : null);
define('APP_PUBLIC_URL', $appPublicUrlEnv !== null ? rtrim((string) $appPublicUrlEnv, '/') : BASE_URL . '/public');

// ─────────────────────────────────────────
// Identidade da aplicação
// ─────────────────────────────────────────

define('APP_NAME', 'Workflow NF — Exemplo');
define('APP_VERSION', '1.0.0');

// ─────────────────────────────────────────
// Paginação
// ─────────────────────────────────────────

// Configurações administráveis na tela Geral (nf_configuracoes).
// Settings::getInt retorna o default se a tabela/DB não estiver disponível,
// então o sistema funciona mesmo antes da migration ou com o banco fora.
require_once __DIR__ . '/database.php';
require_once ROOT . '/src/Helpers/Settings.php';

/** Itens por página nas listagens */
define('ITEMS_PER_PAGE', Settings::getInt('geral.itens_por_pagina', 20));

/** Máximo de itens por página que o usuário pode solicitar */
define('MAX_ITEMS_PER_PAGE', 50);

// ─────────────────────────────────────────
// Upload de arquivos
// ─────────────────────────────────────────

/** Tamanho máximo de upload em bytes (10 MB) */
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);

/**
 * Teto próprio para vídeo (5 MB) — menor que o dos documentos, de propósito.
 *
 * Vídeo é a única coisa aqui que cresce o storage em ordem de grandeza: o anexo
 * médio do sistema tem 20 KB, um vídeo de 5 MB vale 250 deles. O limite baixo é
 * o que mantém o custo previsível — e 5 MB ainda dá ~20s de 1080p, suficiente
 * para registrar o que uma foto não mostra.
 *
 * Vale junto com o limite do PHP (`upload_max_filesize`): quem for menor manda.
 */
define('MAX_UPLOAD_SIZE_VIDEO', 5 * 1024 * 1024);

/**
 * MIME types permitidos para upload de anexos de NF.
 * A validação usa o MIME real (finfo), não apenas a extensão.
 */
define('ALLOWED_MIME_TYPES', [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'text/plain',
    'text/csv',
    'video/mp4',
    'video/webm',
    'video/quicktime',
    'audio/ogg',
    'application/ogg',
    'audio/mpeg',
    'audio/mp4',
    'audio/x-m4a',
    'audio/wav',
    'audio/x-wav',
]);

/** Extensões de arquivo permitidas (whitelist — par com ALLOWED_MIME_TYPES) */
define('ALLOWED_EXTENSIONS', [
    'pdf', 'jpg', 'jpeg', 'png', 'webp',
    'xls', 'xlsx', 'doc', 'docx', 'txt', 'csv',
    'mp4', 'webm', 'mov',
    'ogg', 'mp3', 'm4a', 'wav',
]);

/**
 * Subconjunto de vídeo — quem responde pelo teto de 5 MB e pelo visualizador
 * que não tenta reproduzir (só oferece o download). MIME e extensão andam em
 * par: `.mov` de celular é `video/quicktime`, `.mp4` é `video/mp4`.
 */
define('VIDEO_MIME_TYPES', ['video/mp4', 'video/webm', 'video/quicktime']);
define('VIDEO_EXTENSIONS',  ['mp4', 'webm', 'mov']);

/**
 * Subconjunto de áudio — mesma categoria de anexo do vídeo ("Áudio / Vídeo"),
 * mesmo teto de 5 MB. Inclui `.ogg` (nota de voz do WhatsApp, `audio/ogg` —
 * alguns encoders só marcam o contêiner genérico `application/ogg`).
 */
define('AUDIO_MIME_TYPES', ['audio/ogg', 'application/ogg', 'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav']);
define('AUDIO_EXTENSIONS',  ['ogg', 'mp3', 'm4a', 'wav']);

// ─────────────────────────────────────────
// Sessão / Segurança
// ─────────────────────────────────────────

/** Tempo de inatividade em segundos antes de encerrar a sessão (padrão 30 min) */
define('SESSION_TIMEOUT', Settings::getInt('seguranca.session_timeout_min', 30) * 60);

/** Tentativas de login incorretas permitidas antes do bloqueio temporário */
define('MAX_LOGIN_ATTEMPTS', Settings::getInt('seguranca.max_login_attempts', 5));

/** Duração do bloqueio de login por excesso de tentativas (em minutos) */
define('LOGIN_LOCKOUT_MINUTES', Settings::getInt('seguranca.lockout_min', 15));

// ─────────────────────────────────────────
// Módulos disponíveis no roteamento
// ─────────────────────────────────────────

/**
 * Lista de módulos válidos.
 * Usada para validar $module em public/index.php e evitar inclusão arbitrária.
 */
define('VALID_MODULES', [
    'auth',           // login/logout — única rota acessível sem sessão
    'home',           // tela inicial de boas-vindas (Notas) — pós-login
    'account',        // meu perfil — qualquer usuário logado
    'dashboard',
    'capturas',       // inbox de notas capturadas via integração (Brobot)
    'prefluxo',       // configurador de regras de vínculo automático
    'processos',
    'tarefas',
    'financeiro',
    'fiscal',         // IBS/CBS lidos dos XML recebidos — conferencia por fornecedor
    'templates',
    'grupos',
    'tabelas',
    'relatorios',
    'importar',       // importação de PDFs em lote (fatura / NFS-e)
    'arquivos',       // download autenticado de anexos
    'notificacoes',   // AJAX de notificações
    'acessos',        // controle de acesso — admin apenas
    'horarios',       // horários de acesso (jornada) — admin apenas
    'geral',          // configurações gerais — admin apenas
    'faturamento',    // protótipo do módulo de Faturamento de veículos — em construção
]);
