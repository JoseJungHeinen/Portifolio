<?php
/**
 * includes/admin_provisionamento.php
 *
 * Funções usadas só pela tela de administração (admin/usuarios.php):
 * listar quem tem conta em quais sistemas, e criar/atualizar a conta
 * real em cada sistema quando o admin concede acesso.
 */
require_once __DIR__ . '/db_connect.php';

function gerar_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Visão federada: e-mail => ['nome' => ..., 'sistemas' => ['estoque' => ['papel'=>'admin','ativo'=>true], ...]]
 * A ausência de uma chave em 'sistemas' significa que a pessoa não tem conta lá.
 * 'ativo' é sempre true pra sistemas sem coluna_ativo (Estoque) — lá, sem conta = sem linha nenhuma.
 */
function listar_usuarios_federado(array $sistemas): array
{
    $lista = [];

    foreach ($sistemas as $id => $cfg) {
        try {
            $pdo = conectar_sistema($cfg['db']);
            $colunaNome  = $cfg['coluna_nome'] ?? 'nome';
            $colunaRole  = $cfg['coluna_role'] ?? null;
            $colunaAtivo = $cfg['coluna_ativo'] ?? null;
            $selectRole  = $colunaRole ? ", {$colunaRole} AS papel" : ", NULL AS papel";
            $selectAtivo = $colunaAtivo ? ", {$colunaAtivo} AS ativo" : "";
            $sql = "SELECT {$cfg['coluna_email']} AS email, {$colunaNome} AS nome {$selectRole} {$selectAtivo} FROM {$cfg['tabela']}";
            $rows = $pdo->query($sql)->fetchAll();

            foreach ($rows as $row) {
                $email = $row['email'];
                if (!isset($lista[$email])) {
                    $lista[$email] = ['nome' => $row['nome'], 'sistemas' => []];
                }
                $lista[$email]['sistemas'][$id] = [
                    'papel' => $row['papel'],
                    'ativo' => $colunaAtivo ? (bool)$row['ativo'] : true,
                ];
            }
        } catch (\PDOException $e) {
            error_log("Falha ao listar usuários do sistema '$id': " . $e->getMessage());
        }
    }

    ksort($lista);
    return $lista;
}

/**
 * Busca o hash de senha já gravado para esse e-mail nesse sistema, ou null se não existir lá ainda.
 * Usado pra decidir se dá pra deixar a senha em branco no formulário (reaproveitar o hash atual)
 * e pra saber se a pessoa já tem conta nesse sistema (presença de conta).
 */
function buscar_hash_atual(array $cfg, string $email): ?string
{
    $pdo  = conectar_sistema($cfg['db']);
    $stmt = $pdo->prepare("SELECT {$cfg['coluna_senha']} AS h FROM {$cfg['tabela']} WHERE {$cfg['coluna_email']} = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row['h'] ?? null;
}

/**
 * Busca filial_id/setor_id já gravados pra esse e-mail no Compras, ou null se não existir lá.
 * Usado só pra pré-preencher o formulário de edição.
 */
function buscar_compras_extra(array $cfg, string $email): ?array
{
    $pdo  = conectar_sistema($cfg['db']);
    $stmt = $pdo->prepare("SELECT id, perfil, filial_id, setor_id FROM {$cfg['tabela']} WHERE {$cfg['coluna_email']} = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Busca o CPF já gravado pra esse e-mail no Veículos, ou null se não existir lá.
 * Usado só pra pré-preencher o formulário de edição.
 */
function buscar_veiculos_extra(array $cfg, string $email): ?array
{
    $pdo  = conectar_sistema($cfg['db']);
    $stmt = $pdo->prepare("SELECT id, cpf FROM {$cfg['tabela']} WHERE {$cfg['coluna_email']} = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Busca id/usuario já gravados pra esse e-mail no Inventário, ou null se não
 * existir lá. Usado só pra pré-preencher o formulário de edição.
 */
function buscar_inventario_extra(array $cfg, string $email): ?array
{
    $pdo  = conectar_sistema($cfg['db']);
    $stmt = $pdo->prepare("SELECT id, usuario FROM {$cfg['tabela']} WHERE {$cfg['coluna_email']} = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Busca id/role já gravados pra esse e-mail na Locadora (tabela profiles,
 * banco da locadora), ou null se não existir lá. Usado só pra pré-preencher
 * o formulário de edição.
 */
function buscar_locadora_extra(array $cfg, string $email): ?array
{
    $pdo  = conectar_sistema($cfg['db']);
    $stmt = $pdo->prepare("SELECT id, role FROM {$cfg['tabela']} WHERE {$cfg['coluna_email']} = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** tenant_id único já usado em todos os perfis existentes da locadora. */
const TENANT_ID_LOCADORA = '29bbf935-41d4-4f3d-8903-d0e58092f807';

function provisionar_estoque(array $cfg, string $email, string $nome, string $senhaHash, string $cargo): void
{
    $pdo = conectar_sistema($cfg['db']);
    $sql = "INSERT INTO usuarios (nome, email, senha, cargo)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE nome = VALUES(nome), senha = VALUES(senha), cargo = VALUES(cargo)";
    $pdo->prepare($sql)->execute([$nome, $email, $senhaHash, $cargo]);
}

function provisionar_compras(
    array $cfg,
    string $email,
    string $nome,
    string $senhaHash,
    string $perfil,
    string $filialId,
    string $setorId
): void {
    $pdo = conectar_sistema($cfg['db']);
    $sql = "INSERT INTO usuarios (id, nome, email, senha_hash, perfil, filial_id, setor_id, ativo)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE nome = VALUES(nome), senha_hash = VALUES(senha_hash),
                perfil = VALUES(perfil), filial_id = VALUES(filial_id), setor_id = VALUES(setor_id),
                ativo = 1";
    $pdo->prepare($sql)->execute([gerar_uuid_v4(), $nome, $email, $senhaHash, $perfil, $filialId, $setorId]);
}

/**
 * Busca id/perfil/filial_id/setor_id já gravados pra esse e-mail no Notas,
 * ou null se não existir lá. Usado só pra pré-preencher o formulário de edição.
 */
function buscar_notas_extra(array $cfg, string $email): ?array
{
    $pdo  = conectar_sistema($cfg['db']);
    $stmt = $pdo->prepare("SELECT id, perfil, filial_id, setor_id FROM {$cfg['tabela']} WHERE {$cfg['coluna_email']} = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function provisionar_notas(
    array $cfg,
    string $email,
    string $nome,
    string $senhaHash,
    string $perfil,
    string $filialId,
    string $setorId
): void {
    $pdo = conectar_sistema($cfg['db']);
    $sql = "INSERT INTO usuarios (id, nome, email, senha_hash, perfil, filial_id, setor_id, ativo)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE nome = VALUES(nome), senha_hash = VALUES(senha_hash),
                perfil = VALUES(perfil), filial_id = VALUES(filial_id), setor_id = VALUES(setor_id),
                ativo = 1";
    $pdo->prepare($sql)->execute([gerar_uuid_v4(), $nome, $email, $senhaHash, $perfil, $filialId, $setorId]);
}

function provisionar_projeto(array $cfg, string $email, string $nome, string $senhaHash, string $perfil): void
{
    $pdo = conectar_sistema($cfg['db']);
    $sql = "INSERT INTO usuarios (nome, email, senha, perfil, ativo)
            VALUES (?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE nome = VALUES(nome), senha = VALUES(senha), perfil = VALUES(perfil), ativo = 1";
    $pdo->prepare($sql)->execute([$nome, $email, $senhaHash, $perfil]);
}

function provisionar_veiculos(array $cfg, string $email, string $nome, string $senhaHash, string $cpf): void
{
    $pdo = conectar_sistema($cfg['db']);
    $sql = "INSERT INTO usuarios (cpf, email, nome, senha_hash, is_ativo)
            VALUES (?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE nome = VALUES(nome), senha_hash = VALUES(senha_hash), is_ativo = 1";
    $pdo->prepare($sql)->execute([$cpf, $email, $nome, $senhaHash]);
}

function provisionar_inventario(
    array $cfg,
    string $email,
    string $nome,
    string $senhaHash,
    string $perfil,
    string $usuario
): void {
    $pdo = conectar_sistema($cfg['db']);
    $sql = "INSERT INTO inv_usuarios (usuario, email, nome, senha_hash, perfil, ativo)
            VALUES (?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE nome = VALUES(nome), senha_hash = VALUES(senha_hash),
                perfil = VALUES(perfil), ativo = 1";
    $pdo->prepare($sql)->execute([$usuario, $email, $nome, $senhaHash, $perfil]);
}

/**
 * A tabela profiles (banco da locadora) não tem chave única em email, então
 * não dá pra confiar em ON DUPLICATE KEY UPDATE — verifica manualmente se
 * já existe uma linha pra esse e-mail e decide entre UPDATE/INSERT.
 */
function provisionar_locadora(array $cfg, string $email, string $nome, string $senhaHash, string $role): void
{
    $pdo = conectar_sistema($cfg['db']);

    $existente = $pdo->prepare("SELECT id FROM {$cfg['tabela']} WHERE {$cfg['coluna_email']} = ?");
    $existente->execute([$email]);
    $linha = $existente->fetch();

    if ($linha) {
        $pdo->prepare("UPDATE {$cfg['tabela']} SET name = ?, password_hash = ?, role = ?, is_active = 1 WHERE id = ?")
            ->execute([$nome, $senhaHash, $role, $linha['id']]);
    } else {
        $pdo->prepare("INSERT INTO {$cfg['tabela']} (id, tenant_id, name, email, password_hash, role, is_active)
                       VALUES (?, ?, ?, ?, ?, ?, 1)")
            ->execute([gerar_uuid_v4(), TENANT_ID_LOCADORA, $nome, $email, $senhaHash, $role]);
    }
}

/**
 * Revoga o acesso ao Estoque. Esse sistema não tem coluna 'ativo', então a
 * única forma de tirar o acesso é apagando a linha (reversível: basta
 * conceder de novo pelo formulário que ela é recriada).
 */
function revogar_estoque(array $cfg, string $email): void
{
    $pdo = conectar_sistema($cfg['db']);
    $pdo->prepare("DELETE FROM {$cfg['tabela']} WHERE {$cfg['coluna_email']} = ?")->execute([$email]);
}

function revogar_compras(array $cfg, string $email): void
{
    $pdo = conectar_sistema($cfg['db']);
    $pdo->prepare("UPDATE {$cfg['tabela']} SET ativo = 0 WHERE {$cfg['coluna_email']} = ?")->execute([$email]);
}

function revogar_notas(array $cfg, string $email): void
{
    $pdo = conectar_sistema($cfg['db']);
    $pdo->prepare("UPDATE {$cfg['tabela']} SET ativo = 0 WHERE {$cfg['coluna_email']} = ?")->execute([$email]);
}

function revogar_projeto(array $cfg, string $email): void
{
    $pdo = conectar_sistema($cfg['db']);
    $pdo->prepare("UPDATE {$cfg['tabela']} SET ativo = 0 WHERE {$cfg['coluna_email']} = ?")->execute([$email]);
}

function revogar_veiculos(array $cfg, string $email): void
{
    $pdo = conectar_sistema($cfg['db']);
    $pdo->prepare("UPDATE {$cfg['tabela']} SET is_ativo = 0 WHERE {$cfg['coluna_email']} = ?")->execute([$email]);
}

function revogar_inventario(array $cfg, string $email): void
{
    $pdo = conectar_sistema($cfg['db']);
    $pdo->prepare("UPDATE {$cfg['tabela']} SET ativo = 0 WHERE {$cfg['coluna_email']} = ?")->execute([$email]);
}

function revogar_locadora(array $cfg, string $email): void
{
    $pdo = conectar_sistema($cfg['db']);
    $pdo->prepare("UPDATE {$cfg['tabela']} SET {$cfg['coluna_ativo']} = 0 WHERE {$cfg['coluna_email']} = ?")->execute([$email]);
}

/**
 * Busca id/perfil já gravados pra esse e-mail no Imóveis (tabela funcionarios,
 * banco de imóveis), ou null se não existir lá. Usado só pra pré-preencher
 * o formulário de edição.
 */
function buscar_imoveis_extra(array $cfg, string $email): ?array
{
    $pdo  = conectar_sistema($cfg['db']);
    $stmt = $pdo->prepare("SELECT id, perfil FROM {$cfg['tabela']} WHERE {$cfg['coluna_email']} = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function provisionar_imoveis(array $cfg, string $email, string $nome, string $senhaHash, string $perfil): void
{
    $pdo = conectar_sistema($cfg['db']);
    $sql = "INSERT INTO funcionarios (nome, email, senha_hash, perfil, ativo)
            VALUES (?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE nome = VALUES(nome), senha_hash = VALUES(senha_hash), perfil = VALUES(perfil), ativo = 1";
    $pdo->prepare($sql)->execute([$nome, $email, $senhaHash, $perfil]);
}

function revogar_imoveis(array $cfg, string $email): void
{
    $pdo = conectar_sistema($cfg['db']);
    $pdo->prepare("UPDATE {$cfg['tabela']} SET ativo = 0 WHERE {$cfg['coluna_email']} = ?")->execute([$email]);
}
