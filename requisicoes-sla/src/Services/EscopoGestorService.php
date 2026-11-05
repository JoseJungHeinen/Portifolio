<?php
// @versao Ver.001.090926

namespace App\Services;

use App\Config\Database;

/**
 * Escopo de unidade+setor de um usuário, a partir de gestores_setor.
 *
 * Fonte única de verdade para as telas que liam usuarios.filial_id /
 * usuarios.setor_id direto. Só considera vínculo ativo, de usuário ativo e com
 * perfil ainda elegível — gestor desativado ou rebaixado perde o escopo sem
 * precisar limpar a tabela.
 */
class EscopoGestorService
{
    public const PERFIS_ELEGIVEIS = ['gestor', 'diretor'];

    /** Cache por request: a mesma tela consulta o escopo várias vezes. */
    private static array $cache = [];

    /** @return array<int, array{filial_id: string, setor_id: string}> */
    public function paresDoUsuario(string $usuarioId): array
    {
        if (isset(self::$cache[$usuarioId])) {
            return self::$cache[$usuarioId];
        }

        $placeholders = implode(', ', array_fill(0, count(self::PERFIS_ELEGIVEIS), '?'));

        $stmt = Database::getInstance()->prepare(
            "SELECT gs.filial_id, gs.setor_id
             FROM gestores_setor gs
             JOIN usuarios u ON u.id = gs.usuario_id
             WHERE gs.usuario_id = ?
               AND gs.ativo = 1
               AND u.ativo = 1
               AND u.perfil IN ({$placeholders})
             ORDER BY gs.filial_id, gs.setor_id"
        );
        $stmt->execute([$usuarioId, ...self::PERFIS_ELEGIVEIS]);

        return self::$cache[$usuarioId] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Ids de filial distintos do escopo — para relatórios, dashboard e brindes. @return array<int, string> */
    public function filiaisDoUsuario(string $usuarioId): array
    {
        $ids = array_column($this->paresDoUsuario($usuarioId), 'filial_id');
        return array_values(array_unique($ids));
    }

    /** Controle de acesso item a item. */
    public function gerencia(string $usuarioId, ?string $filialId, ?string $setorId): bool
    {
        if ($filialId === null || $setorId === null) {
            return false;
        }

        foreach ($this->paresDoUsuario($usuarioId) as $par) {
            if ($par['filial_id'] === $filialId && $par['setor_id'] === $setorId) {
                return true;
            }
        }

        return false;
    }

    /** Invalida o cache após gravar vínculos na tela de configuração. */
    public static function limparCache(): void
    {
        self::$cache = [];
    }
}
