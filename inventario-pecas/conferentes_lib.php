<?php
declare(strict_types=1);

/**
 * Conferentes: quem pode entrar na Conferência, cadastrado pelo admin no
 * dashboard, com PIN de 4 números. O nome cadastrado é o que vai para
 * dupla_label, então o rastro em inv_conferencias/inv_ajustes_locacao
 * continua o mesmo de antes.
 *
 * PHP 7.4 em produção: nada de match, str_starts_with, tipos de união.
 */

const CONFERENTES_MAX_TENTATIVAS = 5;
const CONFERENTES_MINUTOS_BLOQUEIO = 5;

function conferentes_pin_valido(string $pin): bool
{
    return preg_match('/^\d{4}$/', $pin) === 1;
}

/**
 * @return int id do conferente criado
 * @throws InvalidArgumentException com a mensagem para a tela
 */
function conferentes_cadastrar(PDO $pdo, string $nome, string $pin): int
{
    $nome = trim($nome);
    if ($nome === '') {
        throw new InvalidArgumentException('Informe o nome.');
    }
    if (mb_strlen($nome) > 120) {
        throw new InvalidArgumentException('O nome tem no máximo 120 letras.');
    }
    if (!conferentes_pin_valido($pin)) {
        throw new InvalidArgumentException('O PIN tem 4 números.');
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO inv_conferentes (nome, pin_hash) VALUES (:nome, :hash)");
        $stmt->execute([':nome' => $nome, ':hash' => password_hash($pin, PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
        // 1062: a chave única do nome. A collation não diferencia caixa, então
        // "ANA" e "Ana" são o mesmo conferente.
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            throw new InvalidArgumentException('Já existe um conferente com esse nome.');
        }
        throw $e;
    }

    return (int)$pdo->lastInsertId();
}

/**
 * Sem o hash: a lista vai para a tela.
 *
 * @return array<int, array{id:int, nome:string, ativo:bool, bloqueado:bool, ate:?string}>
 */
function conferentes_listar(PDO $pdo, bool $soAtivos): array
{
    $sql = "
        SELECT id, nome, ativo,
               (bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW()) AS bloqueado,
               DATE_FORMAT(bloqueado_ate, '%H:%i') AS ate
          FROM inv_conferentes
         " . ($soAtivos ? "WHERE ativo = 1" : "") . "
         ORDER BY nome
    ";

    $lista = [];
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $bloqueado = (int)$l['bloqueado'] === 1;
        $lista[] = [
            'id'        => (int)$l['id'],
            'nome'      => (string)$l['nome'],
            'ativo'     => (int)$l['ativo'] === 1,
            'bloqueado' => $bloqueado,
            'ate'       => $bloqueado ? (string)$l['ate'] : null,
        ];
    }
    return $lista;
}

function conferentes_definir_ativo(PDO $pdo, int $id, bool $ativo): void
{
    $stmt = $pdo->prepare("UPDATE inv_conferentes SET ativo = :ativo WHERE id = :id");
    $stmt->execute([':ativo' => $ativo ? 1 : 0, ':id' => $id]);
}

/**
 * @throws InvalidArgumentException PIN fora do formato
 */
function conferentes_trocar_pin(PDO $pdo, int $id, string $pin): void
{
    if (!conferentes_pin_valido($pin)) {
        throw new InvalidArgumentException('O PIN tem 4 números.');
    }
    // PIN novo é recomeço: o bloqueio do PIN velho não vale mais.
    $stmt = $pdo->prepare("
        UPDATE inv_conferentes
           SET pin_hash = :hash, tentativas_erradas = 0, bloqueado_ate = NULL
         WHERE id = :id
    ");
    $stmt->execute([':hash' => password_hash($pin, PASSWORD_DEFAULT), ':id' => $id]);
}

function conferentes_desbloquear(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare("UPDATE inv_conferentes SET tentativas_erradas = 0, bloqueado_ate = NULL WHERE id = :id");
    $stmt->execute([':id' => $id]);
}

/**
 * Confere o PIN de um conferente ativo.
 *
 * @return array ['ok'=>true,'nome'=>...]
 *             | ['ok'=>false,'motivo'=>'inexistente']
 *             | ['ok'=>false,'motivo'=>'bloqueado','ate'=>'H:i']
 *             | ['ok'=>false,'motivo'=>'pin','restantes'=>n]
 *
 * Abre a própria transação: quem chama não pode estar com uma aberta
 * (login.php não abre).
 */
function conferentes_autenticar(PDO $pdo, int $id, string $pin): array
{
    // A linha fica travada (FOR UPDATE) da leitura até a gravação: pedidos
    // simultâneos do mesmo conferente esperam um pelo outro, então uma rajada
    // não ganha mais de 5 tentativas.
    $pdo->beginTransaction();
    try {
        $r = conferentes_autenticar_travado($pdo, $id, $pin);
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** O miolo de conferentes_autenticar(), já dentro da transação. */
function conferentes_autenticar_travado(PDO $pdo, int $id, string $pin): array
{
    $stmt = $pdo->prepare("
        SELECT nome, pin_hash, ativo, tentativas_erradas,
               (bloqueado_ate IS NOT NULL AND bloqueado_ate > NOW()) AS bloqueado,
               DATE_FORMAT(bloqueado_ate, '%H:%i') AS ate
          FROM inv_conferentes
         WHERE id = :id
           FOR UPDATE
    ");
    $stmt->execute([':id' => $id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    // Inativo é como não existir, e não conta tentativa: não há o que proteger.
    if (!$c || (int)$c['ativo'] !== 1) {
        return ['ok' => false, 'motivo' => 'inexistente'];
    }

    // Bloqueado nem confere o PIN: senão o bloqueio só atrasaria quem testa PINs.
    if ((int)$c['bloqueado'] === 1) {
        return ['ok' => false, 'motivo' => 'bloqueado', 'ate' => (string)$c['ate']];
    }

    if (password_verify($pin, (string)$c['pin_hash'])) {
        conferentes_desbloquear($pdo, $id);
        return ['ok' => true, 'nome' => (string)$c['nome']];
    }

    // A linha está travada: a conta pode ser feita aqui, ninguém mexeu nela
    // desde a leitura.
    $erros = (int)$c['tentativas_erradas'] + 1;

    if ($erros < CONFERENTES_MAX_TENTATIVAS) {
        $pdo->prepare("UPDATE inv_conferentes SET tentativas_erradas = :erros WHERE id = :id")
            ->execute([':erros' => $erros, ':id' => $id]);
        return ['ok' => false, 'motivo' => 'pin', 'restantes' => CONFERENTES_MAX_TENTATIVAS - $erros];
    }

    // Zera a contagem junto: passado o bloqueio, a pessoa tem as 5 de novo.
    $pdo->prepare("
        UPDATE inv_conferentes
           SET tentativas_erradas = 0,
               bloqueado_ate = NOW() + INTERVAL " . (int)CONFERENTES_MINUTOS_BLOQUEIO . " MINUTE
         WHERE id = :id
    ")->execute([':id' => $id]);
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(bloqueado_ate, '%H:%i') FROM inv_conferentes WHERE id = :id");
    $stmt->execute([':id' => $id]);

    return ['ok' => false, 'motivo' => 'bloqueado', 'ate' => (string)$stmt->fetchColumn()];
}
