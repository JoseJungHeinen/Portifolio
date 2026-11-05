<?php
// @versao Ver.006.291026

namespace App\Controllers;

use App\Core\Controller;
use App\Models\{Usuario, Filial, Setor};
use App\Services\{AuthService, AuditoriaService, PermissaoService};
use App\Config\Database;

class UsuarioController extends Controller
{
    public function index(): void
    {
        $this->requirePerfil(['admin']);

        $model    = new Usuario();
        $filtros  = [];
        if (!empty($_GET['filial_id']))             $filtros['filial_id'] = $_GET['filial_id'];
        if (!empty($_GET['setor_id']))              $filtros['setor_id']  = $_GET['setor_id'];
        if (!empty($_GET['perfil']))                $filtros['perfil']    = $_GET['perfil'];
        if (isset($_GET['ativo']) && $_GET['ativo'] !== '') $filtros['ativo'] = $_GET['ativo'];
        if (!empty($_GET['busca']))                 $filtros['busca']    = $_GET['busca'];

        $usuarios = $model->allComDetalhes($filtros);
        $filiais  = (new Filial())->all(['ativo' => 1], 'nome');
        $setores  = (new Setor())->all(['ativo' => 1], 'nome');
        $perfis   = ['solicitante','almoxarife','comprador','gestor','diretor','admin'];

        $this->view('usuarios/index', compact('usuarios','filiais','setores','perfis'));
    }

    public function form(?string $id = null): void
    {
        $this->requirePerfil(['admin']);

        $usuario = $id ? (new Usuario())->find($id) : null;
        $filiais = (new Filial())->all(['ativo' => 1], 'nome');
        $setores = (new Setor())->all(['ativo' => 1], 'nome');
        $perfis  = ['solicitante','almoxarife','comprador','gestor','diretor','admin'];

        // Permissões do catálogo: padrão do cargo e o efetivo do usuário
        // (o que difere do cargo aparece como exceção na tela)
        $catalogo     = permissoes_catalogo();
        $permPadrao   = [];
        $permMarcadas = [];
        $db = Database::getInstance();

        if ($usuario) {
            $permissoes   = new PermissaoService();
            $permPadrao   = $permissoes->padraoPerfil($usuario['perfil']);
            $permMarcadas = $permissoes->efetivasUsuario($usuario['id'], $usuario['perfil']);
        }

        $dashboardBloqueios = [];
        if ($usuario) {
            $rowsBloq = $db->prepare("SELECT widget FROM dashboard_bloqueios_admin WHERE usuario_id = ?");
            $rowsBloq->execute([$usuario['id']]);
            $dashboardBloqueios = $rowsBloq->fetchAll(\PDO::FETCH_COLUMN);
        }

        // Gerar senha temporária para novo usuário
        $senhaTemp = null;
        if (!$usuario) {
            $upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
            $lower  = 'abcdefghjkmnpqrstuvwxyz';
            $digits = '23456789';
            $pool   = $upper . $lower . $digits;
            $chars  = [$upper[random_int(0, strlen($upper) - 1)], $digits[random_int(0, strlen($digits) - 1)]];
            for ($i = 0; $i < 8; $i++) $chars[] = $pool[random_int(0, strlen($pool) - 1)];
            for ($i = count($chars) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
            }
            $senhaTemp = implode('', $chars);
        }

        // Gestor só enxerga requisições das unidades+setores em que tem vínculo
        // (gestores_setor). Sem vínculo, não vê nada — daí o aviso no formulário.
        $vinculosGestor = $usuario
            ? count((new \App\Services\EscopoGestorService())->paresDoUsuario($usuario['id']))
            : 0;

        $this->view('usuarios/form', compact('usuario','filiais','setores','perfis','catalogo','permPadrao','permMarcadas','senhaTemp','dashboardBloqueios','vinculosGestor'));
    }

    public function store(): void
    {
        $this->requirePerfil(['admin']);
        $user  = auth();
        $nome  = $this->input('nome');
        $email = $this->input('email');
        $senha = $this->input('senha');

        try {
            $svc = new AuthService();
            $svc->criar([
                'nome'      => $nome,
                'email'     => $email,
                'senha'     => $senha,
                'perfil'    => $this->input('perfil'),
                'filial_id' => $this->input('filial_id'),
                'setor_id'  => $this->input('setor_id'),
            ], $user['id']);

            if ($this->input('todas_filiais') === '1') {
                Database::getInstance()->prepare("UPDATE usuarios SET todas_filiais = 1 WHERE email = ?")
                    ->execute([$email]);
            }

            // Gera token de uso único (expira em 1h) para o novo usuário definir a
            // própria senha. Substitui a antiga URL de autologin que expunha a senha
            // em texto plano (logs do servidor, histórico do navegador, Referer).
            $token    = (new Usuario())->gerarTokenReset($email);
            $setupUrl = $token ? url('senha/nova/' . $token) : url('login');
            session_flash('acesso_link', ['nome' => $nome, 'email' => $email, 'url' => $setupUrl]);
            redirect('/usuarios');
        } catch (\RuntimeException $e) {
            session_flash('error', $e->getMessage());
            redirect('/usuarios/novo');
        }
    }

    public function update(string $id): void
    {
        $this->requirePerfil(['admin']);
        $user  = auth();
        $model = new Usuario();
        $antes = $model->find($id);

        $data = [
            'nome'               => $this->input('nome'),
            'perfil'             => $this->input('perfil'),
            'filial_id'          => $this->input('filial_id'),
            'setor_id'           => $this->input('setor_id'),
            'ativo'              => (int)$this->input('ativo', 1),
            'force_change_senha' => (int)($this->input('force_change_senha') === '1'),
            'todas_filiais'      => (int)($this->input('todas_filiais') === '1'),
        ];

        // Reset de senha se informada
        if ($senha = $this->input('nova_senha')) {
            $model->alterarSenha($id, $senha);
        }

        $model->update($id, $data);

        // Salvar permissões: só o que difere do cargo vira exceção do usuário.
        // Só salva quando o card foi exibido (perm_enviado): ele não aparece para
        // admin, e sem essa marca um admin rebaixado perderia tudo. Checkbox
        // desmarcado não é enviado pelo HTML — ausente = desmarcada.
        // Na troca de cargo os switches ainda mostravam o cargo antigo: o usuário
        // passa a seguir o padrão do novo cargo, sem exceções.
        if ($data['perfil'] !== 'admin' && $this->input('perm_enviado') === '1') {
            $permissoes = new PermissaoService();
            $marcadas   = ($antes['perfil'] ?? null) === $data['perfil']
                ? array_keys($_POST['perm'] ?? [])
                : array_keys(array_filter($permissoes->padraoPerfil($data['perfil'])));
            $permissoes->salvarUsuario($id, $data['perfil'], $marcadas);
        }
        $db = Database::getInstance();

        // Salvar bloqueios administrativos do Dashboard (delete-then-insert)
        $widgetKeysValidos = ['kpis','grafico_historico','resumo_requisicoes',
                              'consumo_setor','comparativo_filial','contratos','eventuais','anomalias'];
        $bloqueios = $_POST['dashboard_bloqueio'] ?? [];
        $db->prepare("DELETE FROM dashboard_bloqueios_admin WHERE usuario_id = ?")->execute([$id]);
        foreach ($bloqueios as $widget => $marcado) {
            if (in_array($widget, $widgetKeysValidos, true)) {
                $db->prepare("INSERT INTO dashboard_bloqueios_admin (usuario_id, widget) VALUES (?, ?)")
                   ->execute([$id, $widget]);
            }
        }

        (new AuditoriaService())->log($user['id'], 'usuario', $id, 'usuario.atualizado', $antes, $data);
        session_flash('success', 'Usuário atualizado.');
        redirect('/usuarios');
    }

    public function destroy(string $id): void
    {
        $this->requirePerfil(['admin']);
        $user = auth();

        if ($id === $user['id']) {
            session_flash('error', 'Você não pode desativar sua própria conta.');
            redirect('/usuarios');
        }

        (new Usuario())->update($id, ['ativo' => 0]);
        (new AuditoriaService())->log($user['id'], 'usuario', $id, 'usuario.desativado');

        session_flash('success', 'Usuário desativado.');
        redirect('/usuarios');
    }
}
