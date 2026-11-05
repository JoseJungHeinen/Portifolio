<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'conferentes_lib.php';
require __DIR__ . '/assert.php';

echo "test_cadastro\n";

// Nomes com prefixo proprio: o teste apaga so o que ele criou.
$PREFIXO = 'ZZTESTE ';
$pdo->prepare("DELETE FROM inv_conferentes WHERE nome LIKE ?")->execute([$PREFIXO . '%']);

// ── Formato do PIN ──────────────────────────────────────────────────────────
t_true('quatro digitos vale', conferentes_pin_valido('1234'));
t_true('zero a esquerda vale', conferentes_pin_valido('0007'));
t_eq('tres digitos nao vale', false, conferentes_pin_valido('123'));
t_eq('cinco digitos nao vale', false, conferentes_pin_valido('12345'));
t_eq('letra nao vale', false, conferentes_pin_valido('12a4'));
t_eq('vazio nao vale', false, conferentes_pin_valido(''));
t_eq('espaco nao vale', false, conferentes_pin_valido(' 1234'));

// ── Cadastrar ───────────────────────────────────────────────────────────────
$id = conferentes_cadastrar($pdo, $PREFIXO . 'Ana', '1234');
t_true('cadastrar devolve id', $id > 0);

$linha = $pdo->query("SELECT * FROM inv_conferentes WHERE id = " . (int)$id)->fetch();
t_eq('nome gravado sem espaco sobrando', $PREFIXO . 'Ana', $linha['nome']);
t_true('PIN nao fica em texto puro', $linha['pin_hash'] !== '1234');
t_true('hash confere com o PIN', password_verify('1234', $linha['pin_hash']));
t_eq('nasce ativo', 1, (int)$linha['ativo']);

$erroDe = function (callable $f): string {
    try {
        $f();
        return '';
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
};

t_eq('nome repetido recusado', 'Já existe um conferente com esse nome.',
    $erroDe(function () use ($pdo, $PREFIXO) { conferentes_cadastrar($pdo, $PREFIXO . 'Ana', '9999'); }));
t_eq('nome repetido com outra caixa recusado', 'Já existe um conferente com esse nome.',
    $erroDe(function () use ($pdo, $PREFIXO) { conferentes_cadastrar($pdo, $PREFIXO . 'ANA', '9999'); }));
t_eq('nome vazio recusado', 'Informe o nome.',
    $erroDe(function () use ($pdo) { conferentes_cadastrar($pdo, '   ', '1234'); }));
t_eq('PIN invalido recusado', 'O PIN tem 4 números.',
    $erroDe(function () use ($pdo, $PREFIXO) { conferentes_cadastrar($pdo, $PREFIXO . 'Bia', '12'); }));

// ── Listar e ativar ─────────────────────────────────────────────────────────
$idBia = conferentes_cadastrar($pdo, $PREFIXO . 'Bia', '4321');

$nomes = function (bool $soAtivos) use ($pdo, $PREFIXO): array {
    $r = [];
    foreach (conferentes_listar($pdo, $soAtivos) as $c) {
        if (strpos($c['nome'], $PREFIXO) === 0) {
            $r[] = $c['nome'];
        }
    }
    return $r;
};

t_eq('lista em ordem de nome', [$PREFIXO . 'Ana', $PREFIXO . 'Bia'], $nomes(true));

conferentes_definir_ativo($pdo, $idBia, false);
t_eq('inativo some da lista de ativos', [$PREFIXO . 'Ana'], $nomes(true));
t_eq('inativo continua na lista completa', [$PREFIXO . 'Ana', $PREFIXO . 'Bia'], $nomes(false));

conferentes_definir_ativo($pdo, $idBia, true);
t_eq('reativado volta', [$PREFIXO . 'Ana', $PREFIXO . 'Bia'], $nomes(true));

$umDaLista = null;
foreach (conferentes_listar($pdo, false) as $c) {
    if ((int)$c['id'] === $id) {
        $umDaLista = $c;
    }
}
t_eq('listar traz bloqueado = false para quem nunca errou', false, $umDaLista['bloqueado']);
t_eq('listar nao traz o hash', false, array_key_exists('pin_hash', $umDaLista));

$pdo->prepare("DELETE FROM inv_conferentes WHERE nome LIKE ?")->execute([$PREFIXO . '%']);
t_fim();
