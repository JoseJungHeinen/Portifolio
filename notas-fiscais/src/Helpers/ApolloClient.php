<?php
// @versao Apo_02 V_03_13/10/2026
declare(strict_types=1);

/**
 * Cliente de consulta ao Apollo (Oracle) — módulo Notas.
 *
 * O PHP do servidor não tem oci8/pdo_oci; a conexão é feita por um bridge
 * Python (bin/apollo_chave.py) com python-oracledb — thick quando há Instant
 * Client (o Apollo novo exige criptografia nativa), thin como fallback. Este cliente
 * é AUTOSSUFICIENTE — não depende do sistema Renave nem de nenhum outro.
 *
 * Credenciais vêm das Settings (grupo `apollo`). A senha e as chaves são
 * enviadas ao bridge por STDIN (JSON), nunca por argv — evita exposição em `ps`.
 */
class ApolloClient
{
    /** Config resolvida das Settings (grupo `apollo`) + caminho do bridge. */
    private static function config(): array
    {
        return [
            'dsn'    => (string) Settings::get('apollo.dsn', 'servidor.exemplo.local:1521/APOLLO'),
            'user'   => (string) Settings::get('apollo.user', 'APP_METAS'),
            'pass'   => (string) Settings::get('apollo.pass', ''),
            'python' => (string) Settings::get('apollo.python_bin', 'python3'),
            'script' => ROOT . '/bin/apollo_chave.py',
        ];
    }

    /**
     * Consulta um conjunto de chaves de acesso no Apollo.
     *
     * @param string[] $chaves Chaves (normalizadas para 44 díg. no bridge).
     * @return array<string,array{cancelada:bool,valor:?float,data:?string}>
     *         Mapa: chave-limpa (44 díg.) => dados. Ausência = não consta.
     * @throws RuntimeException em falha de configuração/conexão/execução.
     */
    public static function consultarChaves(array $chaves): array
    {
        $cfg = self::config();
        if ($cfg['pass'] === '') {
            throw new RuntimeException('Apollo: senha de conexão não configurada (Geral › Apollo).');
        }
        if (!is_file($cfg['script'])) {
            throw new RuntimeException('Apollo: bridge Python não encontrado em ' . $cfg['script'] . '.');
        }

        $payload = json_encode([
            'dsn'      => $cfg['dsn'],
            'user'     => $cfg['user'],
            'password' => $cfg['pass'],
            'chaves'   => array_values($chaves),
        ], JSON_UNESCAPED_UNICODE);

        $saida = self::executarBridge($cfg['python'], $cfg['script'], (string) $payload);
        $json  = json_decode($saida, true);
        if (!is_array($json)) {
            throw new RuntimeException('Apollo: retorno inesperado do bridge: ' . substr($saida, 0, 300));
        }
        if (($json['ok'] ?? false) !== true) {
            throw new RuntimeException('Apollo: ' . ($json['erro'] ?? 'erro desconhecido'));
        }

        $mapa = [];
        foreach ($json['encontradas'] ?? [] as $row) {
            $chave = preg_replace('/\D/', '', (string) ($row['chave'] ?? ''));
            if ($chave === '') {
                continue;
            }
            $mapa[$chave] = [
                'cancelada' => (bool) ($row['cancelada'] ?? false),
                'valor'     => isset($row['valor']) && $row['valor'] !== null ? (float) $row['valor'] : null,
                'data'      => $row['data'] ?? null,
            ];
        }
        return $mapa;
    }

    /**
     * Testa a conexão consultando uma chave inexistente — valida só o login,
     * não altera nada no Apollo.
     *
     * @return array{ok:bool,mensagem:string}
     */
    public static function testarConexao(): array
    {
        try {
            self::consultarChaves(['00000000000000000000000000000000000000000000']);
            return ['ok' => true, 'mensagem' => 'Conexão com o Apollo bem-sucedida.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'mensagem' => $e->getMessage()];
        }
    }

    /**
     * Diretório do Oracle Instant Client (modo thick, exigido pelo Apollo novo).
     * Settings `apollo.client_lib_dir` ou o primeiro dos caminhos conhecidos que existir.
     */
    private static function clientLibDir(): ?string
    {
        $candidatos = [
            (string) Settings::get('apollo.client_lib_dir', ''),
            '/opt/oracle/instantclient',
            '/var/www/html/Veiculos/BI/Metas/instantclient',
        ];
        foreach ($candidatos as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                return $dir;
            }
        }
        return null;
    }

    /**
     * Ambiente do bridge. O Instant Client precisa de libaio.so.1, que o
     * Ubuntu 24.04 só publica como libaio.so.1t64 — o link em $dir resolve, mas
     * só via LD_LIBRARY_PATH (o ldconfig indexa por SONAME e o ignora).
     * Sem Instant Client devolve null (herda o ambiente; bridge cai para thin).
     */
    private static function ambienteBridge(): ?array
    {
        $dir = self::clientLibDir();
        $py  = self::pythonLibDir();
        if ($dir === null && $py === null) {
            return null;
        }
        $env = getenv();
        if ($dir !== null) {
            $ld = (string) ($env['LD_LIBRARY_PATH'] ?? '');
            $env['LD_LIBRARY_PATH']       = $dir . ($ld !== '' ? ':' . $ld : '');
            $env['APOLLO_CLIENT_LIB_DIR'] = $dir;
        }
        if ($py !== null) {
            $pp = (string) ($env['PYTHONPATH'] ?? '');
            $env['PYTHONPATH'] = $py . ($pp !== '' ? ':' . $pp : '');
        }
        return $env;
    }

    /**
     * Diretório com um python-oracledb capaz de thick (instalado por
     * `pip install --target`). Necessário onde o python3-oracledb do sistema é
     * só thin (Ubuntu 24.04: 1.2.1, sem init_oracle_client).
     * Settings `apollo.python_lib_dir` ou /opt/oracle/python-lib; null se não existir.
     */
    private static function pythonLibDir(): ?string
    {
        foreach ([(string) Settings::get('apollo.python_lib_dir', ''), '/opt/oracle/python-lib'] as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                return $dir;
            }
        }
        return null;
    }

    /** Executa o bridge Python passando o payload por STDIN e lendo o STDOUT. */
    private static function executarBridge(string $python, string $script, string $stdin): string
    {
        $desc = [
            0 => ['pipe', 'r'], // STDIN
            1 => ['pipe', 'w'], // STDOUT
            2 => ['pipe', 'w'], // STDERR
        ];
        $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script);

        $proc = proc_open($cmd, $desc, $pipes, null, self::ambienteBridge());
        if (!is_resource($proc)) {
            throw new RuntimeException('Apollo: não foi possível iniciar o bridge Python.');
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if (trim($out) === '') {
            throw new RuntimeException(
                'Apollo: bridge não retornou dados (code ' . $code . ')'
                . ($err !== '' ? ': ' . substr(trim($err), 0, 300) : '.')
            );
        }
        return $out;
    }
}
