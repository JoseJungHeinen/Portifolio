<?php
declare(strict_types=1);

namespace App\Models;

class Usuario extends BaseModel
{
    protected string $table = 'usuarios';

    public const PERFIS = [
        'estoque'    => 'Estoque',
        'comprador'  => 'Comprador',
        'gerente'    => 'Gerente',
        'financeiro' => 'Financeiro',
        'diretor'    => 'Diretor',
        'admin'      => 'Administrador',
    ];

    public function findByEmail(string $email): ?array
    {
        return $this->queryOne('SELECT * FROM usuarios WHERE email = ?', [mb_strtolower(trim($email))]);
    }

    /** 5 erros seguidos bloqueiam por 15 minutos (bloqueado_ate é avaliado antes do incremento). */
    public function incrementarTentativas(string $id): void
    {
        $this->execute(
            // SET avalia da esquerda para a direita: primeiro o contador (reinicia se o bloqueio expirou), depois o bloqueio.
            'UPDATE usuarios SET tentativas_login = IF(bloqueado_ate IS NOT NULL AND bloqueado_ate < NOW(), 1, tentativas_login + 1),
                                 bloqueado_ate = IF(tentativas_login >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), IF(bloqueado_ate < NOW(), NULL, bloqueado_ate)) WHERE id = ?',
            [$id]
        );
    }

    public function registrarAcesso(string $id): void
    {
        $this->execute('UPDATE usuarios SET tentativas_login = 0, bloqueado_ate = NULL, ultimo_acesso = NOW() WHERE id = ?', [$id]);
    }

    /** @return array{ativo:int|string,perfil:string}|null */
    public function statusAtual(string $id): ?array
    {
        return $this->queryOne('SELECT ativo, perfil FROM usuarios WHERE id = ?', [$id]);
    }

    /** @return string[] */
    public function unidadesIds(string $id): array
    {
        return array_column($this->query('SELECT unidade_id FROM usuario_unidades WHERE usuario_id = ?', [$id]), 'unidade_id');
    }

    public function sincronizarUnidades(string $id, array $unidadeIds): void
    {
        $propria = !$this->db->inTransaction();
        if ($propria) {
            $this->db->beginTransaction();
        }
        try {
            $this->execute('DELETE FROM usuario_unidades WHERE usuario_id = ?', [$id]);
            foreach (array_unique($unidadeIds) as $unidadeId) {
                $this->execute('INSERT INTO usuario_unidades (usuario_id, unidade_id) VALUES (?, ?)', [$id, $unidadeId]);
            }
            if ($propria) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($propria && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function listar(): array
    {
        return $this->query(
            "SELECT u.*,
                    (SELECT GROUP_CONCAT(un.nome ORDER BY un.nome SEPARATOR ', ')
                     FROM usuario_unidades uu JOIN unidades un ON un.id = uu.unidade_id
                     WHERE uu.usuario_id = u.id) AS unidades_nomes
             FROM usuarios u ORDER BY u.ativo DESC, u.nome"
        );
    }

    /** Quem pode ser responsável por uma OFP. */
    public function responsaveis(): array
    {
        return $this->query("SELECT id, nome, perfil FROM usuarios WHERE ativo = 1 AND perfil IN ('estoque','comprador','gerente','admin') ORDER BY nome");
    }

    public function gerentesAtivos(): array
    {
        return $this->query("SELECT id, nome, email FROM usuarios WHERE ativo = 1 AND perfil = 'gerente'");
    }

    /** Usuários ativos dos perfis que executam etapas (destinatários de alerta por etapa). */
    public function atoresAtivos(): array
    {
        return $this->query("SELECT id, nome, email, perfil FROM usuarios WHERE ativo = 1 AND perfil IN ('estoque','comprador','financeiro')");
    }
}
