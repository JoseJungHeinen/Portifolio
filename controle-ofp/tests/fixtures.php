<?php
declare(strict_types=1);

use App\Config\Database;

function fx_db(): PDO
{
    return Database::getInstance();
}

/** Limpa dados transacionais do ofp_test (mantém unidades e causais do seed). */
function fx_limpar(): void
{
    $db = fx_db();
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['ofp_anexos', 'ofp_eventos', 'ofps', 'usuario_unidades', 'usuarios'] as $tabela) {
        $db->exec("TRUNCATE TABLE {$tabela}");
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    $_SESSION = [];
}

function fx_unidade(string $nome = 'Eurovale - Matriz'): string
{
    $st = fx_db()->prepare('SELECT id FROM unidades WHERE nome = ?');
    $st->execute([$nome]);
    return (string)$st->fetchColumn();
}

function fx_causal(string $codigo): array
{
    $st = fx_db()->prepare('SELECT * FROM causais WHERE codigo = ?');
    $st->execute([$codigo]);
    return $st->fetch();
}

/** Cria usuário com senha "Teste@123" e devolve o array que vai para a sessão. */
function fx_usuario(string $perfil, array $unidades = []): array
{
    $id = uuid();
    $nome = ucfirst($perfil) . ' Teste';
    fx_db()->prepare('INSERT INTO usuarios (id, nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, $nome, $perfil . '.' . substr($id, 0, 8) . '@teste.local', password_hash('Teste@123', PASSWORD_BCRYPT, ['cost' => 4]), $perfil]);
    foreach ($unidades as $unidadeId) {
        fx_db()->prepare('INSERT INTO usuario_unidades (usuario_id, unidade_id) VALUES (?, ?)')->execute([$id, $unidadeId]);
    }
    return ['id' => $id, 'nome' => $nome, 'perfil' => $perfil];
}

/** Insere OFP direto no banco (sem regras) e devolve o id. */
function fx_ofp(array $campos = []): string
{
    $dados = array_merge([
        'id'               => uuid(),
        'unidade_id'       => fx_unidade(),
        'causal_id'        => fx_causal('AP')['id'],
        'desenho'          => '51901276',
        'quantidade'       => 1,
        'valor_reclamado'  => 546.73,
        'data_recebimento' => '2026-10-01',
        'status'           => 'registrada',
    ], $campos);
    $colunas = implode(', ', array_keys($dados));
    $marcas = implode(', ', array_fill(0, count($dados), '?'));
    fx_db()->prepare("INSERT INTO ofps ({$colunas}) VALUES ({$marcas})")->execute(array_values($dados));
    return $dados['id'];
}

function fx_ofp_db(string $id): array
{
    $st = fx_db()->prepare('SELECT * FROM ofps WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

function fx_motivo(string $codigo): string
{
    $st = fx_db()->prepare('SELECT id FROM motivos WHERE codigo = ?');
    $st->execute([$codigo]);
    return (string)$st->fetchColumn();
}
