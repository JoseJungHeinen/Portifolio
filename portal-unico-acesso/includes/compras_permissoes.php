<?php
/**
 * includes/compras_permissoes.php
 *
 * Permissões granulares por módulo do Compras (permissoes_perfil = padrão
 * do perfil, permissoes_usuario = exceção por pessoa) — mesmo modelo que o
 * próprio Compras já usa em src/Controllers/UsuarioController.php.
 */
require_once __DIR__ . '/db_connect.php';

const NIVEIS_ACESSO_COMPRAS = ['nenhum', 'ver', 'criar', 'aprovar', 'total'];

function listar_modulos_compras(): array
{
    return [
        'requisicoes'        => 'Requisições',
        'aprovacoes'         => 'Aprovações',
        'estoque'            => 'Estoque',
        'itens'              => 'Itens',
        'cotacoes'           => 'Cotações',
        'fornecedores'       => 'Fornecedores',
        'contratos'          => 'Contratos',
        'fiscal'             => 'Fiscal',
        'relatorios'         => 'Relatórios',
        'usuarios'           => 'Usuários',
        'brindes'            => 'Brindes',
        'relatorios.compras' => 'Relatórios de Compras',
        'relatorios.filiais' => 'Relatórios de Filiais',
        'auditoria_estoque'  => 'Auditoria de Estoque',
    ];
}

/**
 * Mescla o padrão do perfil com a exceção por usuário (override vence),
 * igual à prioridade que o próprio Compras usa.
 *
 * @return array<string,string> modulo => nível ('nenhum'|'ver'|'criar'|'aprovar'|'total')
 */
function buscar_permissoes_compras(array $cfgCompras, string $idUsuario, string $perfil): array
{
    $pdo = conectar_sistema($cfgCompras['db']);

    $niveis = array_fill_keys(array_keys(listar_modulos_compras()), 'nenhum');

    $stmtPerfil = $pdo->prepare('SELECT modulo, acesso FROM permissoes_perfil WHERE perfil = ?');
    $stmtPerfil->execute([$perfil]);
    foreach ($stmtPerfil->fetchAll() as $row) {
        $niveis[$row['modulo']] = $row['acesso'];
    }

    $stmtUsuario = $pdo->prepare('SELECT modulo, acesso FROM permissoes_usuario WHERE usuario_id = ?');
    $stmtUsuario->execute([$idUsuario]);
    foreach ($stmtUsuario->fetchAll() as $row) {
        $niveis[$row['modulo']] = $row['acesso'];
    }

    return $niveis;
}

/**
 * @param array<string,string> $niveis modulo => nível
 */
function salvar_permissoes_compras(array $cfgCompras, string $idUsuario, array $niveis): void
{
    $pdo = conectar_sistema($cfgCompras['db']);

    $pdo->prepare('DELETE FROM permissoes_usuario WHERE usuario_id = ?')->execute([$idUsuario]);

    $stmt = $pdo->prepare('INSERT INTO permissoes_usuario (usuario_id, modulo, acesso) VALUES (?, ?, ?)');
    foreach ($niveis as $modulo => $nivel) {
        if (!in_array($nivel, NIVEIS_ACESSO_COMPRAS, true)) {
            continue;
        }
        $stmt->execute([$idUsuario, $modulo, $nivel]);
    }
}
