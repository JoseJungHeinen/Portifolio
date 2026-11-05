<?php
// @versao Ver.006.301026

namespace App\Controllers;

use App\Core\Controller;
use App\Models\{Setor, GrupoProduto, Filial, GestorSetor};
use App\Config\Database;
use App\Services\AuditoriaService;
use App\Services\PermissaoService;
use App\Services\EscopoGestorService;

class ConfiguracaoController extends Controller
{

    /** Cargos com padrão editável — admin tem acesso total e não entra aqui. */
    private const PERFIS_EDITAVEIS = ['solicitante', 'almoxarife', 'comprador', 'gestor', 'diretor'];

    public function perfis(): void
    {
        $this->requirePermissao('configuracoes.perfis');

        $perfis = self::PERFIS_EDITAVEIS;
        $perfil = in_array($_GET['perfil'] ?? '', $perfis, true) ? $_GET['perfil'] : $perfis[0];

        $catalogo     = permissoes_catalogo();
        $permMarcadas = (new PermissaoService())->padraoPerfil($perfil);

        $this->view('configuracoes/perfis', compact('perfis', 'perfil', 'catalogo', 'permMarcadas'));
    }

    public function savePerfis(): void
    {
        $this->requirePermissao('configuracoes.perfis');

        $perfil = $this->input('perfil');
        if (!in_array($perfil, self::PERFIS_EDITAVEIS, true)) {
            session_flash('error', 'Cargo inválido.');
            redirect('/configuracoes/perfis');
        }

        $permissoes = new PermissaoService();
        $antes      = $permissoes->padraoPerfil($perfil);
        $permissoes->salvarPerfil($perfil, array_keys($_POST['perm'] ?? []));

        (new AuditoriaService())->log(
            auth()['id'], 'perfil', $perfil, 'permissoes.perfil_atualizado',
            $antes, $permissoes->padraoPerfil($perfil)
        );
        session_flash('success', 'Permissões do cargo ' . ucfirst($perfil) . ' atualizadas.');
        redirect('/configuracoes/perfis?perfil=' . $perfil);
    }
    /**
     * Alçadas: admin e diretor no Compras; no Requisições só admin (decisão de
     * 30/10 — os cadastros liberados lá são exclusivos do admin).
     */
    private function perfisAlcadas(): array
    {
        return sistema() === 'requisicoes' ? ['admin'] : ['admin', 'diretor'];
    }

    public function alcadas(): void
    {
        $this->requirePerfil($this->perfisAlcadas());

        $db   = Database::getInstance();
        $stmt = $db->query("SELECT * FROM alcadas ORDER BY nivel, valor_min");
        $alcadas = $stmt->fetchAll();

        $this->view('configuracoes/alcadas', compact('alcadas'));
    }

    public function saveAlcadas(): void
    {
        $this->requirePerfil($this->perfisAlcadas());
        $user = auth();
        $db   = Database::getInstance();

        $perfis     = $_POST['perfil']       ?? [];
        $minimos    = $_POST['valor_min']    ?? [];
        $maximos    = $_POST['valor_max']    ?? [];
        $niveis     = $_POST['nivel']        ?? [];
        $redirectTo = $_POST['redirect_to']  ?? '';
        // Sem Dashboard no Requisições: volta sempre para a própria tela.
        // Caminho relativo: redirect() já prefixa o APP_URL (com url() o Location saía duplicado).
        $backUrl    = ($redirectTo === 'dashboard' && sistema() !== 'requisicoes') ? '/dashboard' : '/configuracoes/alcadas';

        // Valida antes de deletar
        $novas = [];
        foreach ($perfis as $i => $perfil) {
            if (!$perfil) continue;
            $novas[] = [uuid(), $perfil, (float)$minimos[$i], $maximos[$i] ?: null, (int)$niveis[$i]];
        }

        if (empty($novas)) {
            session_flash('error', 'Informe ao menos uma alçada.');
            redirect($backUrl);
        }

        $db->beginTransaction();
        try {
            $db->exec("DELETE FROM alcadas");
            foreach ($novas as $n) {
                $db->prepare(
                    "INSERT INTO alcadas (id, perfil, valor_min, valor_max, nivel, ativo) VALUES (?,?,?,?,?,1)"
                )->execute($n);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao salvar alçadas: ' . $this->mensagemErro($e));
            redirect($backUrl);
        }

        (new AuditoriaService())->log($user['id'], 'configuracao', null, 'alcadas.salvas');
        session_flash('success', 'Alçadas atualizadas.');
        redirect($backUrl);
    }

    public function setores(): void
    {
        $this->requirePerfil(['admin']);
        $setores = (new Setor())->all([], 'tipo, nome');
        $this->view('configuracoes/setores', compact('setores'));
    }

    public function saveSetor(): void
    {
        $this->requirePerfil(['admin']);
        $user = auth();

        $id   = $this->input('id') ?: null;
        $data = [
            'nome' => $this->input('nome'),
            'tipo' => $this->input('tipo'),
        ];

        if (!$data['nome']) {
            session_flash('error', 'O nome do setor é obrigatório.');
            redirect('/configuracoes/setores');
        }

        $model = new Setor();
        try {
            if ($id) {
                $model->update($id, $data);
            } else {
                $data['ativo'] = 1;
                $id = $model->create($data);
            }
        } catch (\PDOException $e) {
            // uk_setor_nome: nome repetido virava erro 500
            if ($e->getCode() === '23000') {
                session_flash('error', 'Já existe um setor com esse nome.');
                redirect('/configuracoes/setores');
            }
            throw $e;
        }

        (new AuditoriaService())->log($user['id'], 'setor', $id, 'setor.salvo', null, $data);
        session_flash('success', 'Setor salvo.');
        redirect('/configuracoes/setores');
    }

    public function toggleSetor(string $id): void
    {
        $this->requirePerfil(['admin']);
        $user  = auth();
        $model = new Setor();
        $setor = $model->find($id);

        if (!$setor) {
            session_flash('error', 'Setor não encontrado.');
            redirect('/configuracoes/setores');
        }

        $novoAtivo = $setor['ativo'] ? 0 : 1;
        $model->update($id, ['ativo' => $novoAtivo]);
        (new AuditoriaService())->log($user['id'], 'setor', $id,
            $novoAtivo ? 'setor.ativado' : 'setor.desativado',
            ['ativo' => $setor['ativo']], ['ativo' => $novoAtivo]
        );

        session_flash('success', $novoAtivo ? 'Setor ativado.' : 'Setor desativado.');
        redirect('/configuracoes/setores');
    }

    public function deleteSetor(string $id): void
    {
        $this->requirePerfil(['admin']);
        $user  = auth();
        $db    = \App\Config\Database::getInstance();
        $model = new Setor();
        $setor = $model->find($id);

        if (!$setor) {
            session_flash('error', 'Setor não encontrado.');
            redirect('/configuracoes/setores');
        }

        // Bloqueia se estiver em uso
        $usos = [
            'usuários'    => "SELECT COUNT(*) FROM usuarios WHERE setor_id = ?",
            'requisições' => "SELECT COUNT(*) FROM requisicoes WHERE setor_id = ?",
            'orçamentos'  => "SELECT COUNT(*) FROM orcamentos WHERE setor_id = ?",
            // Demais tabelas com FK para setores: sem a checagem o DELETE estourava
            // a FK e virava erro 500.
            'solicitações de compra'    => "SELECT COUNT(*) FROM solicitacoes_compra WHERE setor_id = ?",
            'solicitações de orçamento' => "SELECT COUNT(*) FROM solicitacoes_orcamento WHERE setor_id = ?",
            'gestores de setor'         => "SELECT COUNT(*) FROM gestores_setor WHERE setor_id = ?",
            'itens'                     => "SELECT COUNT(*) FROM itens_setores WHERE setor_id = ?",
        ];
        foreach ($usos as $label => $sql) {
            $stmt = $db->prepare($sql);
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() > 0) {
                session_flash('error', "Não é possível excluir: setor vinculado a {$label}.");
                redirect('/configuracoes/setores');
            }
        }

        (new \App\Services\AuditoriaService())->log($user['id'], 'setor', $id, 'setor.excluido', $setor, null);
        $db->prepare("DELETE FROM setores WHERE id = ?")->execute([$id]);

        session_flash('success', 'Setor excluído.');
        redirect('/configuracoes/setores');
    }

    public function grupos(): void
    {
        $this->requirePerfil(['admin']);
        $grupos = (new GrupoProduto())->all([], 'nome');
        $this->view('configuracoes/grupos', compact('grupos'));
    }

    public function saveGrupo(): void
    {
        $this->requirePerfil(['admin']);
        $user = auth();

        $id   = $this->input('id') ?: null;
        $data = ['nome' => $this->input('nome'), 'descricao' => $this->input('descricao'), 'ativo' => 1];

        $model = new GrupoProduto();
        if ($id) {
            $model->update($id, $data);
        } else {
            $id = $model->create($data);
        }

        (new AuditoriaService())->log($user['id'], 'grupo_produto', $id, 'grupo.salvo', null, $data);
        session_flash('success', 'Grupo de produto salvo.');
        redirect('/configuracoes/grupos');
    }

    public function parametros(): void
    {
        $this->requirePerfil(['admin']);

        $db      = Database::getInstance();
        $stmt    = $db->query("SELECT * FROM parametros_sistema ORDER BY chave");
        $params  = $stmt->fetchAll();

        $this->view('configuracoes/parametros', compact('params'));
    }

    public function saveParametros(): void
    {
        $this->requirePerfil(['admin']);
        $user = auth();
        $db   = Database::getInstance();

        $chaves  = $_POST['chave']  ?? [];
        $valores = $_POST['valor']  ?? [];

        foreach ($chaves as $i => $chave) {
            $db->prepare(
                "UPDATE parametros_sistema SET valor = ?, atualizado_por = ? WHERE chave = ?"
            )->execute([$valores[$i], $user['id'], $chave]);
        }

        (new AuditoriaService())->log($user['id'], 'configuracao', null, 'parametros.salvos');
        session_flash('success', 'Parâmetros atualizados.');
        redirect('/configuracoes/parametros');
    }

    public function integracoes(): void
    {
        $this->requirePerfil(['admin']);

        $db   = Database::getInstance();
        $rows = $db->query(
            "SELECT chave, valor FROM parametros_sistema WHERE chave LIKE 'cnpj.%'"
        )->fetchAll();

        $cfg = [];
        foreach ($rows as $r) { $cfg[$r['chave']] = (string)$r['valor']; }

        // O token não vai para a view: só o fato de existir. Mandar o valor
        // faria qualquer admin lê-lo no fonte da página.
        $tokenConfigurado = trim($cfg['cnpj.token'] ?? '') !== '';
        $consultaAtiva    = ($cfg['cnpj.consulta_ativa'] ?? '1') !== '0';
        $timeoutSeg       = (int)($cfg['cnpj.timeout_seg'] ?? 8);

        $this->view('configuracoes/integracoes', compact('tokenConfigurado', 'consultaAtiva', 'timeoutSeg'));
    }

    public function saveIntegracoes(): void
    {
        $this->requirePerfil(['admin']);
        $user = auth();
        $db   = Database::getInstance();

        $set = $db->prepare("UPDATE parametros_sistema SET valor = ?, atualizado_por = ? WHERE chave = ?");

        $ativa = isset($_POST['consulta_ativa']) && $_POST['consulta_ativa'] !== '0' ? '1' : '0';
        $set->execute([$ativa, $user['id'], 'cnpj.consulta_ativa']);

        $timeout = (string)max(2, min(30, (int)($_POST['timeout_seg'] ?? 8)));
        $set->execute([$timeout, $user['id'], 'cnpj.timeout_seg']);

        // Campo vazio significa "não mexi no token", não "apague o token".
        // Para remover existe o botão próprio, que manda remover_token=1.
        if (!empty($_POST['remover_token'])) {
            $set->execute(['', $user['id'], 'cnpj.token']);
        } elseif (trim((string)($_POST['token'] ?? '')) !== '') {
            $set->execute([trim((string)$_POST['token']), $user['id'], 'cnpj.token']);
        }

        (new AuditoriaService())->log($user['id'], 'configuracao', null, 'integracoes.salvas');
        session_flash('success', 'Integrações atualizadas.');
        redirect('/configuracoes/integracoes');
    }

    public function filiais(): void
    {
        $this->requirePerfil(['admin']);
        $filiais = (new Filial())->all([], 'nome');
        $this->view('configuracoes/filiais', compact('filiais'));
    }

    public function filialStore(): void
    {
        $this->requirePerfil(['admin']);
        $user = auth();

        $cnpjRaw = preg_replace('/\D/', '', $this->input('cnpj', ''));
        $data = [
            'nome'               => $this->input('nome'),
            'cnpj'               => $cnpjRaw !== '' ? $cnpjRaw : null,
            'endereco'           => $this->input('endereco') ?: null,
            'telefone'           => $this->input('telefone') ?: null,
            'email'              => $this->input('email')    ?: null,
            'usa_estoque_matriz' => (int)($this->input('usa_estoque_matriz') === '1'),
            'ativo'              => 1,
        ];
        // Requisições não tem estoque: o campo não aparece no formulário e a
        // coluna fica com o default do banco (assim como is_matriz e budget_mensal).
        if (sistema() === 'requisicoes') {
            unset($data['usa_estoque_matriz']);
        }

        if (!$data['nome']) {
            session_flash('error', 'O nome da unidade é obrigatório.');
            redirect('/configuracoes/filiais');
        }

        $id = (new Filial())->create($data);
        (new AuditoriaService())->log($user['id'], 'filial', $id, 'filial.criada', null, $data);
        session_flash('success', 'Unidade criada.');
        redirect('/configuracoes/filiais');
    }

    public function filialUpdate(string $id): void
    {
        $this->requirePerfil(['admin']);
        $user = auth();

        $cnpjRaw = preg_replace('/\D/', '', $this->input('cnpj', ''));
        $data = [
            'nome'               => $this->input('nome'),
            'cnpj'               => $cnpjRaw !== '' ? $cnpjRaw : null,
            'endereco'           => $this->input('endereco') ?: null,
            'telefone'           => $this->input('telefone') ?: null,
            'email'              => $this->input('email')    ?: null,
            'usa_estoque_matriz' => (int)($this->input('usa_estoque_matriz') === '1'),
        ];
        // Requisições: só grava o que veio no POST — os campos de estoque
        // (usa_estoque_matriz) não estão no formulário e não podem virar 0.
        if (sistema() === 'requisicoes') {
            $data = array_intersect_key($data, $_POST);
        }

        if (empty($data['nome'])) {
            session_flash('error', 'O nome da unidade é obrigatório.');
            redirect('/configuracoes/filiais');
        }

        (new Filial())->update($id, $data);
        (new AuditoriaService())->log($user['id'], 'filial', $id, 'filial.atualizada', null, $data);
        session_flash('success', 'Unidade atualizada.');
        redirect('/configuracoes/filiais');
    }

    public function filialToggle(string $id): void
    {
        $this->requirePerfil(['admin']);
        $user   = auth();
        $model  = new Filial();
        $filial = $model->find($id);

        if (!$filial) {
            session_flash('error', 'Unidade não encontrada.');
            redirect('/configuracoes/filiais');
        }

        $novoAtivo = $filial['ativo'] ? 0 : 1;
        $model->update($id, ['ativo' => $novoAtivo]);
        $acao = $novoAtivo ? 'filial.ativada' : 'filial.desativada';
        (new AuditoriaService())->log($user['id'], 'filial', $id, $acao);
        session_flash('success', $novoAtivo ? 'Unidade ativada.' : 'Unidade desativada.');
        redirect('/configuracoes/filiais');
    }

    public function gestores(): void
    {
        $this->requirePerfil(['admin']);

        $filtroFilial = $this->input('filial_id') ?: null;
        $filtroSetor  = $this->input('setor_id') ?: null;

        $vinculos = (new GestorSetor())->listar($filtroFilial, $filtroSetor);
        $filiais  = (new Filial())->allAtivas();
        $setores  = (new Setor())->all(['ativo' => 1], 'nome');

        // Só gestor e diretor podem ser designados responsáveis (spec: perfis elegíveis)
        $gestores = Database::getInstance()->query(
            "SELECT id, nome, perfil FROM usuarios
             WHERE ativo = 1 AND perfil IN ('gestor','diretor')
             ORDER BY nome"
        )->fetchAll();

        $this->view('configuracoes/gestores', compact(
            'vinculos', 'filiais', 'setores', 'gestores', 'filtroFilial', 'filtroSetor'
        ));
    }

    public function saveGestor(): void
    {
        $this->requirePerfil(['admin']);
        $user = auth();

        $filialId  = $this->input('filial_id');
        $setorId   = $this->input('setor_id');
        $usuarioId = $this->input('usuario_id');

        if (!$filialId || !$setorId || !$usuarioId) {
            session_flash('error', 'Unidade, setor e gestor são obrigatórios.');
            redirect('/configuracoes/gestores');
        }

        $model     = new GestorSetor();
        $existente = $model->existente($filialId, $setorId, $usuarioId);

        if ($existente && (int)$existente['ativo'] === 1) {
            session_flash('error', 'Esse gestor já responde por essa unidade e setor.');
            redirect('/configuracoes/gestores');
        }

        if ($existente) {
            // Vínculo inativo: reativa, em vez de recusar por duplicidade numa
            // linha que o admin não enxerga como ativa.
            $model->update($existente['id'], ['ativo' => 1]);
            $id   = $existente['id'];
            $acao = 'gestor_setor.reativado';
        } else {
            $id = $model->create([
                'filial_id'  => $filialId,
                'setor_id'   => $setorId,
                'usuario_id' => $usuarioId,
                'ativo'      => 1,
                'criado_por' => $user['id'],
            ]);
            $acao = 'gestor_setor.criado';
        }

        EscopoGestorService::limparCache();

        (new AuditoriaService())->log($user['id'], 'gestor_setor', $id, $acao, null, [
            'filial_id'  => $filialId,
            'setor_id'   => $setorId,
            'usuario_id' => $usuarioId,
        ]);

        session_flash('success', 'Vínculo salvo.');
        redirect('/configuracoes/gestores');
    }

    public function toggleGestor(string $id): void
    {
        $this->requirePerfil(['admin']);
        $user  = auth();
        $model = new GestorSetor();
        $vinc  = $model->find($id);

        if (!$vinc) {
            session_flash('error', 'Vínculo não encontrado.');
            redirect('/configuracoes/gestores');
        }

        $novoAtivo = $vinc['ativo'] ? 0 : 1;
        $model->update($id, ['ativo' => $novoAtivo]);
        EscopoGestorService::limparCache();

        (new AuditoriaService())->log($user['id'], 'gestor_setor', $id,
            $novoAtivo ? 'gestor_setor.ativado' : 'gestor_setor.desativado',
            ['ativo' => $vinc['ativo']], ['ativo' => $novoAtivo]
        );

        session_flash('success', $novoAtivo ? 'Vínculo ativado.' : 'Vínculo desativado.');
        redirect('/configuracoes/gestores');
    }

    public function deleteGestor(string $id): void
    {
        $this->requirePerfil(['admin']);
        $user  = auth();
        $model = new GestorSetor();
        $vinc  = $model->find($id);

        if (!$vinc) {
            session_flash('error', 'Vínculo não encontrado.');
            redirect('/configuracoes/gestores');
        }

        (new AuditoriaService())->log($user['id'], 'gestor_setor', $id, 'gestor_setor.excluido', $vinc, null);
        $model->hardDelete($id);
        EscopoGestorService::limparCache();

        session_flash('success', 'Vínculo excluído. Se era o último daquela unidade e setor, as aprovações passam a ser resolvidas apenas por admin e diretor.');
        redirect('/configuracoes/gestores');
    }
}
