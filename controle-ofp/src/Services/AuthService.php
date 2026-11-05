<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Usuario;
use DateTimeImmutable;
use App\Support\RegraException;

class AuthService
{
    /** Perfis que, sem unidade vinculada, enxergam todas. */
    private const GLOBAIS = ['gerente', 'financeiro', 'diretor', 'admin'];

    /** Hash bcrypt fixo (senha descartável) usado só para igualar o tempo de resposta. */
    private const HASH_FALSO = '$2y$12$.GMQe3hCVilqK0pCOGJEtugHyKKyuEqJehPmXr1xbZmvDes8caCnu';

    private Usuario $usuarios;

    public function __construct()
    {
        $this->usuarios = new Usuario();
    }

    public function login(string $email, string $senha): array
    {
        $u = $this->usuarios->findByEmail($email);
        if (!$u || !(int)$u['ativo']) {
            // Equaliza o tempo de resposta com o de um usuário ativo.
            password_verify($senha, self::HASH_FALSO);
        }
        if (!$u || !(int)$u['ativo']) {
            throw new RegraException('E-mail ou senha inválidos.');
        }
        if ($u['bloqueado_ate'] && new DateTimeImmutable($u['bloqueado_ate']) > new DateTimeImmutable()) {
            throw new RegraException('Conta bloqueada por excesso de tentativas. Tente novamente em 15 minutos.');
        }
        if (!password_verify($senha, $u['senha_hash'])) {
            $this->usuarios->incrementarTentativas($u['id']);
            throw new RegraException('E-mail ou senha inválidos.');
        }
        $this->usuarios->registrarAcesso($u['id']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['_csrf_token']);
        $_SESSION['usuario'] = ['id' => $u['id'], 'nome' => $u['nome'], 'email' => $u['email'], 'perfil' => $u['perfil']];
        $_SESSION['unidades'] = self::escopoUnidades($u['perfil'], $this->usuarios->unidadesIds($u['id']));
        return $_SESSION['usuario'];
    }

    /**
     * Revalida o usuário logado contra o banco (uma vez por requisição).
     * false = usuário inexistente/inativo: o login da sessão foi encerrado.
     */
    public function revalidarSessao(): bool
    {
        $logado = $_SESSION['usuario'] ?? null;
        if (!$logado) {
            return true;
        }
        $atual = $this->usuarios->statusAtual($logado['id']);
        if (!$atual || !(int)$atual['ativo']) {
            unset($_SESSION['usuario'], $_SESSION['unidades'], $_SESSION['_csrf_token']);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            return false;
        }
        $_SESSION['usuario']['perfil'] = $atual['perfil'];
        $_SESSION['unidades'] = self::escopoUnidades($atual['perfil'], $this->usuarios->unidadesIds($logado['id']));
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /** null = todas as unidades; [] = nenhuma (estoque/comprador sem vínculo). */
    public static function escopoUnidades(string $perfil, array $ids): ?array
    {
        if ($ids) {
            return array_values($ids);
        }
        return in_array($perfil, self::GLOBAIS, true) ? null : [];
    }
}
