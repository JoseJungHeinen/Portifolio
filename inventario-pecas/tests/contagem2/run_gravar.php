<?php
/**
 * Runner de um caso do contagem_gravar.php.
 *
 * Uso: php -d display_errors=0 tests/contagem2/run_gravar.php <sessao.json> < <corpo.json>
 *
 * A sessão vai por arquivo e o corpo por stdin, porque o endpoint lê o corpo
 * com file_get_contents('php://input'). Em produção (Apache/XAMPP) isso é o
 * corpo do POST. Nesta máquina, porém, o SAPI `cli` do PHP 8.2.30 (ZTS) NÃO
 * alimenta o wrapper `php://input` a partir da entrada padrão — verificado
 * diretamente: `file_get_contents('php://input')` volta string vazia mesmo
 * com o corpo canalizado via `<`, embora `stream_get_contents(STDIN)` veja o
 * mesmo corpo sem problema. É a mesma lacuna do SAPI cli que a memória
 * "Harness PHP no Windows" já registra para $_GET/$_POST — só que aqui pega
 * php://input também, e contagem_gravar.php não lê $_POST.
 *
 * Contorno: substituímos o wrapper de stream 'php' por um que devolve, para
 * php://input, o conteúdo já lido de STDIN. Isso não toca contagem_gravar.php
 * nem seu comportamento em produção — é só o transporte deste runner.
 */
chdir(__DIR__ . '/../..');

$GLOBALS['__stdin_bruto'] = stream_get_contents(STDIN);

class RunnerPhpInputStream
{
    public $context;
    private int $pos = 0;

    public function stream_open($path, $mode, $options, &$opened_path): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        $dados = (string)$GLOBALS['__stdin_bruto'];
        $ret   = substr($dados, $this->pos, $count);
        $this->pos += strlen($ret);
        return $ret;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen((string)$GLOBALS['__stdin_bruto']);
    }

    public function stream_stat()
    {
        return [];
    }
}

stream_wrapper_unregister('php');
stream_wrapper_register('php', 'RunnerPhpInputStream');

session_start();

$arquivo = (string)($argv[1] ?? '');
$_SESSION = ($arquivo !== '' && is_file($arquivo))
    ? (json_decode((string)file_get_contents($arquivo), true) ?: [])
    : [];

require 'contagem_gravar.php';
