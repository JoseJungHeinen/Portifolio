<?php
/**
 * Configuração — ambiente de TESTE local (XAMPP).
 * Credenciais vêm de variáveis de ambiente; os padrões abaixo são os do
 * XAMPP local. Em produção, defina MULTAS_DB_* e não use root.
 */
date_default_timezone_set('America/Sao_Paulo');

define('DB_HOST', getenv('MULTAS_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('MULTAS_DB_NAME') ?: 'multas_teste');
define('DB_USER', getenv('MULTAS_DB_USER') ?: 'root');
define('DB_PASS', getenv('MULTAS_DB_PASS') ?: '');

/** Lojas e grafias aceitas (corrige "Caxias Do Sul", "Cidade A " etc.). */
const LOJAS = ['Cidade A', 'Cidade D', 'Cidade C', 'Cidade B', 'Cidade E'];

require_once __DIR__ . '/lib/status.php';
require_once __DIR__ . '/lib/captura.php';
require_once __DIR__ . '/lib/xlsx.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function loja_canonica(?string $s): ?string
{
    $k = mb_strtolower(trim((string)$s));
    if ($k === '') return null;
    foreach (LOJAS as $l) if (mb_strtolower($l) === $k) return $l;
    return trim($s);
}

function loja_id(?string $nome): ?int
{
    static $cache = null;
    if ($nome === null) return null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT id, nome FROM lojas') as $r) $cache[mb_strtolower($r['nome'])] = (int)$r['id'];
    }
    $k = mb_strtolower($nome);
    if (!isset($cache[$k])) {
        db()->prepare('INSERT INTO lojas (nome) VALUES (?)')->execute([$nome]);
        $cache[$k] = (int)db()->lastInsertId();
    }
    return $cache[$k];
}
