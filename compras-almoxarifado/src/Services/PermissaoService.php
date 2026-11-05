<?php
// @versao Ver.001.211026

namespace App\Services;

use App\Config\Database;

/**
 * Permissões do catálogo (src/Config/permissoes.php) por cargo e por usuário.
 *
 * O cargo guarda o padrão de todas as chaves em permissoes_perfil. O usuário
 * guarda em permissoes_usuario só as exceções — chaves em que difere do cargo —
 * para que mudar o cargo alcance quem não tem exceção naquela chave.
 * Marcada = 'ver', desmarcada = 'nenhum' (compatível com podeModulo()).
 */
class PermissaoService
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** @return array<string,bool> chave => marcada no padrão do cargo */
    public function padraoPerfil(string $perfil): array
    {
        $stmt = $this->db->prepare("SELECT modulo, acesso FROM permissoes_perfil WHERE perfil = ?");
        $stmt->execute([$perfil]);
        $linhas = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        $padrao = [];
        foreach (permissoes_chaves() as $chave) {
            $padrao[$chave] = ($linhas[$chave] ?? 'nenhum') !== 'nenhum';
        }
        return $padrao;
    }

    /** @return array<string,bool> chave => marcada para o usuário (exceção > cargo) */
    public function efetivasUsuario(string $usuarioId, string $perfil): array
    {
        $efetivas = $this->padraoPerfil($perfil);

        $stmt = $this->db->prepare("SELECT modulo, acesso FROM permissoes_usuario WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_KEY_PAIR) as $chave => $acesso) {
            if (array_key_exists($chave, $efetivas)) {
                $efetivas[$chave] = $acesso !== 'nenhum';
            }
        }
        return $efetivas;
    }

    /**
     * Grava as permissões do usuário guardando só o que difere do cargo.
     *
     * @param string[] $marcadas chaves marcadas na tela
     */
    public function salvarUsuario(string $usuarioId, string $perfil, array $marcadas): void
    {
        $padrao = $this->padraoPerfil($perfil);
        $chaves = array_keys($padrao);
        $ph     = implode(',', array_fill(0, count($chaves), '?'));

        $this->db->beginTransaction();
        try {
            $this->db->prepare("DELETE FROM permissoes_usuario WHERE usuario_id = ? AND modulo IN ({$ph})")
                     ->execute(array_merge([$usuarioId], $chaves));

            $ins = $this->db->prepare(
                "INSERT INTO permissoes_usuario (usuario_id, modulo, acesso) VALUES (?, ?, ?)"
            );
            foreach ($padrao as $chave => $noCargo) {
                $marcada = in_array($chave, $marcadas, true);
                if ($marcada !== $noCargo) {
                    $ins->execute([$usuarioId, $chave, $marcada ? 'ver' : 'nenhum']);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Grava o padrão do cargo para todas as chaves do catálogo.
     *
     * @param string[] $marcadas chaves marcadas na tela
     */
    public function salvarPerfil(string $perfil, array $marcadas): void
    {
        $up = $this->db->prepare(
            "INSERT INTO permissoes_perfil (perfil, modulo, acesso) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE acesso = VALUES(acesso)"
        );

        $this->db->beginTransaction();
        try {
            foreach (permissoes_chaves() as $chave) {
                $up->execute([$perfil, $chave, in_array($chave, $marcadas, true) ? 'ver' : 'nenhum']);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
