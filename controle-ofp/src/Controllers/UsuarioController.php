<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Controller;
use App\Models\Unidade;
use App\Models\Usuario;
use PDOException;
use Throwable;

class UsuarioController extends Controller
{
    public function index(): void
    {
        $this->view('usuarios/index', ['title' => 'Usuários', 'usuarios' => (new Usuario())->listar()]);
    }

    public function novo(): void
    {
        $this->view('usuarios/form', [
            'title' => 'Novo usuário', 'usuario' => null, 'dados' => flash('old') ?? ['ativo' => 1],
            'vinculadas' => [], 'unidades' => (new Unidade())->todas(),
        ]);
    }

    public function criar(): void
    {
        $this->gravar(null);
    }

    public function editar(string $id): void
    {
        $usuarios = new Usuario();
        $usuario = $usuarios->find($id) ?? $this->abort(404);
        $old = flash('old');
        $this->view('usuarios/form', [
            'title' => $usuario['nome'], 'usuario' => $usuario, 'dados' => $old ?? $usuario,
            'vinculadas' => $old !== null ? ($old['unidades'] ?? []) : $usuarios->unidadesIds($id), 'unidades' => (new Unidade())->todas(),
        ]);
    }

    public function salvar(string $id): void
    {
        (new Usuario())->find($id) ?? $this->abort(404);
        $this->gravar($id);
    }

    private function gravar(?string $id): void
    {
        $usuarios = new Usuario();
        $dados = [
            'nome'   => trim((string)$this->input('nome', '')),
            'email'  => mb_strtolower(trim((string)$this->input('email', ''))),
            'perfil' => (string)$this->input('perfil', ''),
            'ativo'  => $this->input('ativo') ? 1 : 0,
        ];
        $senha = (string)$this->input('senha', '');
        $erros = [];
        if ($dados['nome'] === '') {
            $erros[] = 'Informe o nome.';
        }
        if (mb_strlen($dados['nome']) > 120 || mb_strlen($dados['email']) > 160) {
            $erros[] = 'Nome: no máximo 120 caracteres; e-mail: no máximo 160.';
        }
        if (!filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'E-mail inválido.';
        }
        if (!isset(Usuario::PERFIS[$dados['perfil']])) {
            $erros[] = 'Perfil inválido.';
        }
        $outro = $usuarios->findByEmail($dados['email']);
        if ($outro && $outro['id'] !== $id) {
            $erros[] = 'E-mail já cadastrado.';
        }
        if (($id === null || $senha !== '') && strlen($senha) < 8) {
            $erros[] = 'A senha precisa de pelo menos 8 caracteres.';
        }
        if (strlen($senha) > 72 || str_contains($senha, "\0")) {
            $erros[] = 'A senha deve ter no máximo 72 bytes e não pode conter caractere nulo.';
        }
        if ($id !== null && $id === auth()['id'] && ($dados['perfil'] !== 'admin' || !$dados['ativo'])) {
            $erros[] = 'Você não pode remover o seu próprio acesso de administrador.';
        }
        if ($erros) {
            session_flash('error', implode(' ', $erros));
            session_flash('old', $this->antigo());
            redirect($id ? "/usuarios/{$id}/editar" : '/usuarios/novo');
        }
        if ($senha !== '') {
            $dados['senha_hash'] = password_hash($senha, PASSWORD_BCRYPT, ['cost' => bcrypt_cost()]);
            $dados['tentativas_login'] = 0;
            $dados['bloqueado_ate'] = null;
        }
        $validas = array_column((new Unidade())->todas(), 'id');
        $escolhidas = array_values(array_intersect(array_filter((array)($_POST['unidades'] ?? []), 'is_string'), $validas));
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            if ($id === null) {
                $id = $usuarios->create($dados);
            } else {
                $usuarios->update($id, $dados);
            }
            $usuarios->sincronizarUnidades($id, $escolhidas);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (!($e instanceof PDOException) || $e->getCode() !== '23000' || ($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
            session_flash('error', 'E-mail já cadastrado.');
            session_flash('old', $this->antigo());
            redirect($id ? "/usuarios/{$id}/editar" : '/usuarios/novo');
        }
        session_flash('success', 'Usuário salvo. Mudanças de perfil e unidade valem imediatamente.');
        redirect('/usuarios');
    }

    /** Nunca guardar a senha na sessão: só campos escalares conhecidos. */
    private function antigo(): array
    {
        $old = array_intersect_key($_POST, array_flip(['nome', 'email', 'perfil', 'ativo', 'unidades']));
        foreach (['nome', 'email', 'perfil', 'ativo'] as $campo) {
            if (isset($old[$campo]) && !is_scalar($old[$campo])) {
                unset($old[$campo]);
            }
        }
        if (isset($old['unidades'])) {
            $old['unidades'] = array_values(array_filter((array)$old['unidades'], 'is_string'));
        }
        return $old;
    }
}
