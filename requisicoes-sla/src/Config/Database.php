<?php
// @versao Ver.001.140926

namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $host   = $_ENV['DB_HOST']     ?? '127.0.0.1';
            $port   = $_ENV['DB_PORT']     ?? '3306';
            $dbname = $_ENV['DB_DATABASE'] ?? 'compras';
            $user   = $_ENV['DB_USERNAME'] ?? 'root';
            $pass   = $_ENV['DB_PASSWORD'] ?? '';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; SET character_set_client=utf8mb4; SET character_set_connection=utf8mb4; SET character_set_results=utf8mb4; SET time_zone = '-03:00';",
                ]);

                // Garante UTF-8 mesmo que MYSQL_ATTR_INIT_COMMAND seja ignorado
                self::$instance->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $e) {
                throw new RuntimeException('Erro de conexão com o banco de dados: ' . $e->getMessage());
            }
        }

        return self::$instance;
    }

    private static ?PDO $veiculos = null;

    /**
     * Conexão com o banco do projeto Veiculos, fonte única dos ramais — eles são
     * mantidos no config_ramais.php de lá e o compras só exibe.
     *
     * Sem VEICULOS_DB_USERNAME no .env, reaproveita host e credenciais do compras:
     * basta o usuário do compras ter SELECT no banco Veiculos. Com ele, usa
     * VEICULOS_DB_* inteiro, para nunca misturar usuário de um com senha de
     * outro. O banco é 'Veiculos' com V maiúsculo porque é assim que o próprio
     * Veiculos se conecta, e em Linux nome de banco diferencia maiúsculas.
     *
     * Timeout curto: se o banco do Veiculos não responder, a tela de ramais
     * mostra aviso em segundos em vez de travar a página.
     */
    public static function veiculos(): PDO
    {
        if (self::$veiculos === null) {
            $proprio = !empty($_ENV['VEICULOS_DB_USERNAME']);

            $host   = $_ENV['VEICULOS_DB_HOST']     ?? $_ENV['DB_HOST'] ?? '127.0.0.1';
            $port   = $_ENV['VEICULOS_DB_PORT']     ?? $_ENV['DB_PORT'] ?? '3306';
            $dbname = $_ENV['VEICULOS_DB_DATABASE'] ?? 'Veiculos';
            $user   = $proprio ? $_ENV['VEICULOS_DB_USERNAME']          : ($_ENV['DB_USERNAME'] ?? 'root');
            $pass   = $proprio ? ($_ENV['VEICULOS_DB_PASSWORD'] ?? '') : ($_ENV['DB_PASSWORD'] ?? '');

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

            try {
                self::$veiculos = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => 3,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                ]);
            } catch (PDOException $e) {
                throw new RuntimeException('Erro de conexão com o banco do Veiculos: ' . $e->getMessage());
            }
        }

        return self::$veiculos;
    }

    private function __construct() {}
    private function __clone() {}
}
