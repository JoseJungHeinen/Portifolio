<?php
/**
 * includes/ponte_pdo.php
 *
 * Quando um sistema não pode abrir o MySQL pra conexão remota, ele hospeda
 * um "portal_ponte.php" na própria pasta (mesmo servidor, localhost) que
 * executa a consulta local e devolve o resultado por HTTP. Essas classes
 * imitam o pedacinho de interface do PDO que o resto do código já usa
 * (prepare/execute/fetch/fetchAll/rowCount, query()->fetchAll()), pra
 * conectar_sistema() poder devolver isso no lugar de um PDO de verdade sem
 * precisar mudar autenticar_federado(), admin_provisionamento.php, sso.php etc.
 *
 * PonteConexaoException extends PDOException de propósito — assim os
 * `catch (\PDOException $e)` já espalhados pelo código continuam pegando
 * falhas da ponte sem precisar de nenhum catch novo.
 */

class PonteConexaoException extends \PDOException {}

class PonteStatement
{
    public function __construct(private array $rows, private int $affected) {}

    public function fetch(): mixed
    {
        return array_shift($this->rows) ?: false;
    }

    public function fetchAll(): array
    {
        $r = $this->rows;
        $this->rows = [];
        return $r;
    }

    public function rowCount(): int
    {
        return $this->affected;
    }
}

class PontePreparedProxy
{
    private ?PonteStatement $resultado = null;

    public function __construct(private PonteConexao $conexao, private string $sql) {}

    public function execute(array $params = []): bool
    {
        $this->resultado = $this->conexao->executar($this->sql, $params);
        return true;
    }

    public function fetch(): mixed
    {
        return $this->resultado?->fetch() ?? false;
    }

    public function fetchAll(): array
    {
        return $this->resultado?->fetchAll() ?? [];
    }

    public function rowCount(): int
    {
        return $this->resultado?->rowCount() ?? 0;
    }
}

class PonteConexao
{
    public function __construct(private string $url, private string $token) {}

    public function executar(string $sql, array $params): PonteStatement
    {
        $post = [
            'token'  => $this->token,
            'sql'    => $sql,
            'params' => json_encode($params),
        ];

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($post),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $resp       = curl_exec($ch);
        $erroCurl   = curl_error($ch);
        $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $urlEfetiva = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($resp === false) {
            throw new PonteConexaoException("Não foi possível contactar a ponte em {$urlEfetiva}: {$erroCurl}");
        }

        $dados = json_decode($resp, true);
        if (!is_array($dados) || empty($dados['ok'])) {
            $detalhe = $dados['erro'] ?? substr($resp, 0, 200);
            throw new PonteConexaoException("Ponte retornou erro (HTTP {$status}, url {$urlEfetiva}): {$detalhe}");
        }

        return new PonteStatement($dados['rows'] ?? [], (int)($dados['affected'] ?? 0));
    }

    public function prepare(string $sql): PontePreparedProxy
    {
        return new PontePreparedProxy($this, $sql);
    }

    public function query(string $sql): PonteStatement
    {
        return $this->executar($sql, []);
    }
}
