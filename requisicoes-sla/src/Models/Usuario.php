<?php

namespace App\Models;

class Usuario extends BaseModel
{
    protected string $table = 'usuarios';

    public function findByEmail(string $email): ?array
    {
        return $this->queryOne(
            "SELECT u.*, f.nome AS filial_nome, s.nome AS setor_nome
             FROM usuarios u
             LEFT JOIN filiais f ON f.id = u.filial_id
             LEFT JOIN setores s ON s.id = u.setor_id
             WHERE u.email = ? LIMIT 1",
            [$email]
        );
    }

    /**
     * Login por e-mail OU nome (modo de testes).
     * Permite informar o nome do usuário no lugar do e-mail.
     */
    public function findByEmailOrNome(string $login): ?array
    {
        return $this->queryOne(
            "SELECT u.*, f.nome AS filial_nome, s.nome AS setor_nome
             FROM usuarios u
             LEFT JOIN filiais f ON f.id = u.filial_id
             LEFT JOIN setores s ON s.id = u.setor_id
             WHERE u.email = ? OR u.nome = ? LIMIT 1",
            [$login, $login]
        );
    }

    public function allComDetalhes(array $filtros = []): array
    {
        $where  = '1=1';
        $params = [];

        if (!empty($filtros['filial_id'])) {
            $where .= ' AND u.filial_id = ?';
            $params[] = $filtros['filial_id'];
        }
        if (!empty($filtros['setor_id'])) {
            $where .= ' AND u.setor_id = ?';
            $params[] = $filtros['setor_id'];
        }
        if (!empty($filtros['perfil'])) {
            $where .= ' AND u.perfil = ?';
            $params[] = $filtros['perfil'];
        }
        if (isset($filtros['ativo']) && $filtros['ativo'] !== '') {
            $where .= ' AND u.ativo = ?';
            $params[] = (int)$filtros['ativo'];
        }
        if (!empty($filtros['busca'])) {
            $where .= ' AND (u.nome LIKE ? OR u.email LIKE ?)';
            $params[] = '%' . $filtros['busca'] . '%';
            $params[] = '%' . $filtros['busca'] . '%';
        }

        return $this->query(
            "SELECT u.*, f.nome AS filial_nome, s.nome AS setor_nome
             FROM usuarios u
             LEFT JOIN filiais f ON f.id = u.filial_id
             LEFT JOIN setores s ON s.id = u.setor_id
             WHERE {$where}
             ORDER BY u.nome",
            $params
        );
    }

    public function registrarAcesso(string $id): void
    {
        $this->execute(
            "UPDATE usuarios SET ultimo_acesso = NOW(), tentativas_login = 0, bloqueado_ate = NULL WHERE id = ?",
            [$id]
        );
    }

    public function incrementarTentativas(string $id): void
    {
        $maxTent  = (int)($_ENV['LOGIN_MAX_TENTATIVAS'] ?? 5);
        $bloqueioMin = (int)($_ENV['LOGIN_BLOQUEIO_MINUTOS'] ?? 30);

        $this->execute(
            "UPDATE usuarios SET tentativas_login = tentativas_login + 1 WHERE id = ?",
            [$id]
        );

        $user = $this->find($id);
        if ($user && (int)$user['tentativas_login'] >= $maxTent) {
            $ate = date('Y-m-d H:i:s', strtotime("+{$bloqueioMin} minutes"));
            $this->execute(
                "UPDATE usuarios SET bloqueado_ate = ? WHERE id = ?",
                [$ate, $id]
            );
        }
    }

    public function gerarTokenReset(string $email): ?string
    {
        $user = $this->findByEmail($email);
        if (!$user) return null;

        $token  = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->update($user['id'], [
            'token_reset_senha'  => $token,
            'token_reset_expiry' => $expiry,
        ]);

        return $token;
    }

    public function findByToken(string $token): ?array
    {
        return $this->queryOne(
            "SELECT * FROM usuarios
             WHERE token_reset_senha = ?
               AND token_reset_expiry > NOW()
             LIMIT 1",
            [$token]
        );
    }

    public function alterarSenha(string $id, string $novaSenha): void
    {
        $hash = password_hash($novaSenha, PASSWORD_BCRYPT, [
            'cost' => (int)($_ENV['BCRYPT_COST'] ?? 12)
        ]);
        $this->update($id, [
            'senha_hash'         => $hash,
            'force_change_senha' => 0,
            'token_reset_senha'  => null,
            'token_reset_expiry' => null,
        ]);
    }
}
