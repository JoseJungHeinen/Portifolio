<?php
declare(strict_types=1);

/**
 * Configuração de segurança da sessão PHP.
 * Carregado ANTES de session_start() em public/index.php.
 *
 * Requer que IS_PRODUCTION já esteja definida (via constants.php).
 */

// Impede que JavaScript acesse o cookie de sessão (proteção contra XSS)
ini_set('session.cookie_httponly', '1');

// Protege contra CSRF baseado em cookie (requisições cross-site não enviam o cookie)
ini_set('session.cookie_samesite', 'Strict');

// Impede que o PHP aceite session IDs fornecidos pelo usuário via URL ou cookie não inicializado
ini_set('session.use_strict_mode', '1');

// Impede session ID na URL (session.use_trans_sid = 0 é padrão mas forçamos aqui)
ini_set('session.use_trans_sid', '0');
ini_set('session.use_only_cookies', '1');

// Em produção, o cookie só é enviado em conexões HTTPS
ini_set('session.cookie_secure', (defined('IS_PRODUCTION') && IS_PRODUCTION) ? '1' : '0');

// Tempo de vida do garbage collector de sessões (30 minutos)
ini_set('session.gc_maxlifetime', (string) (defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 1800));

// Nome personalizado do cookie de sessão (evita fingerprinting do PHPSESSID padrão)
session_name('bsid');
