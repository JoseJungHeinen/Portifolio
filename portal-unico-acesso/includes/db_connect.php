<?php
/**
 * includes/db_connect.php
 */
require_once __DIR__ . '/ponte_pdo.php';

/**
 * Se $dbConfig tiver uma chave 'ponte' (url + token), o banco desse sistema
 * não é acessível diretamente daqui — a consulta é enviada por HTTP pra um
 * portal_ponte.php hospedado no próprio servidor do sistema, que executa
 * localmente e devolve o resultado. Ver includes/ponte_pdo.php.
 */
function conectar_sistema(array $dbConfig): PDO|PonteConexao
{
    if (!empty($dbConfig['ponte'])) {
        return new PonteConexao($dbConfig['ponte']['url'], $dbConfig['ponte']['token']);
    }

    $host = $dbConfig['host'];
    $port = $dbConfig['port'] ?? 3306;
    $name = $dbConfig['name'];

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    return new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/**
 * Lê config/sistemas/*.json (dentro do próprio projeto do portal — caminho
 * relativo, funciona igual em qualquer servidor/SO) e monta o array de
 * sistemas a partir do que cada arquivo declara. Cada sistema integrado tem
 * seu próprio arquivo aqui, com uma cópia dos dados que também vivem dentro
 * da pasta daquele sistema (portal.json) — nesta pasta local é só a cópia
 * que o portal realmente usa em tempo de execução.
 *
 * Sem heurística — se não existe um .json aqui pra um sistema, ele
 * simplesmente não aparece. Ver admin/sistemas_sem_manifesto.php.
 */
function carregar_todos_sistemas(): array
{
    $sistemas = [];

    foreach (glob(__DIR__ . '/../config/sistemas/*.json') as $caminho) {
        $dados = json_decode(file_get_contents($caminho), true);
        if (!is_array($dados) || empty($dados['id'])) {
            continue;
        }

        if (isset($dados['instancias']) && is_array($dados['instancias']) && $dados['instancias']) {
            // Sistema com múltiplas instâncias (mesmo cadastro, bancos separados,
            // ex: Projeto em dois servidores). Espelha a primeira instância nos
            // campos "planos" (db/login_url/home_url) pra continuar compatível com
            // o código que só conhece um banco por sistema — provisionamento e
            // edição pelo admin continuam mirando só essa instância principal.
            $primeira = $dados['instancias'][0];
            $dados['db']        = $primeira['db'] ?? null;
            $dados['login_url'] = $primeira['login_url'] ?? null;
            $dados['home_url']  = $primeira['home_url'] ?? null;
        } else {
            // Sistema de instância única (o caso comum): embrulha os campos planos
            // numa "instância" só, pra quem já sabe checar múltiplas instâncias
            // (login federado, SSO) poder tratar todo sistema do mesmo jeito.
            $dados['instancias'] = [[
                'id'        => 'principal',
                'db'        => $dados['db'] ?? null,
                'login_url' => $dados['login_url'] ?? null,
                'home_url'  => $dados['home_url'] ?? null,
            ]];
        }

        $sistemas[$dados['id']] = $dados;
    }

    return $sistemas;
}
