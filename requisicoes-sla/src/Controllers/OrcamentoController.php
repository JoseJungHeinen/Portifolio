<?php
// @versao Ver.002.291026

namespace App\Controllers;

use App\Core\Controller;
use App\Models\{Orcamento, SolicitacaoOrcamento, Filial, Setor, GrupoProduto};
use App\Services\{OrcamentoService, AuditoriaService};

class OrcamentoController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $user = auth();

        $mes = (int)($_GET['mes'] ?? date('n'));
        $ano = (int)($_GET['ano'] ?? date('Y'));
        $filialFiltro = ve_todas_filiais() ? ($_GET['filial_id'] ?? null) : $user['filial_id'];

        $orc     = new Orcamento();
        $porSetor= $orc->dashboardPorSetor($mes, $ano, $filialFiltro);
        $filiais = (new Filial())->all(['ativo' => 1], 'nome');
        $setores = (new Setor())->all(['ativo' => 1], 'nome');

        $this->view('orcamentos/index', compact('porSetor','filiais','setores','mes','ano','filialFiltro','user'));
    }

    public function form(): void
    {
        $this->requirePerfil(['diretor','admin']);

        $filiais = (new Filial())->all(['ativo' => 1], 'nome');
        $setores = (new Setor())->all(['ativo' => 1], 'nome');
        $grupos  = (new GrupoProduto())->all(['ativo' => 1], 'nome');

        $this->view('orcamentos/form', compact('filiais','setores','grupos'));
    }

    public function store(): void
    {
        $this->requirePerfil(['diretor','admin']);
        $user = auth();

        $orc = new Orcamento();
        $data = [
            'filial_id'        => $this->input('filial_id'),
            'setor_id'         => $this->input('setor_id'),
            'grupo_produto_id' => $this->input('grupo_produto_id'),
            'mes'              => (int)$this->input('mes'),
            'ano'              => (int)$this->input('ano'),
            'valor_aprovado'   => (float)str_replace(['.', ','], ['', '.'], $this->input('valor_aprovado', '0')),
            'criado_por'       => $user['id'],
            'aprovado_por'     => $user['id'],
        ];

        $id = $orc->create($data);
        (new AuditoriaService())->log($user['id'], 'orcamento', $id, 'orcamento.criado', null, $data);

        session_flash('success', 'Orçamento cadastrado com sucesso.');
        redirect('/orcamentos');
    }

    public function update(string $id): void
    {
        $this->requirePerfil(['diretor','admin']);
        $user = auth();

        $orc    = new Orcamento();
        $antes  = $orc->find($id);

        if (!$antes) {
            session_flash('error', 'Orçamento não encontrado.');
            redirect('/orcamentos');
        }

        $novoValor = (float)str_replace(['.', ','], ['', '.'], $this->input('valor_aprovado', '0'));

        $orc->update($id, ['valor_aprovado' => $novoValor, 'aprovado_por' => $user['id']]);

        (new AuditoriaService())->log($user['id'], 'orcamento', $id, 'orcamento.atualizado',
            ['valor_aprovado' => $antes['valor_aprovado']], ['valor_aprovado' => $novoValor]);

        session_flash('success', 'Orçamento atualizado.');
        redirect('/orcamentos');
    }

    public function solicitacoes(): void
    {
        $this->requirePerfil(['gestor','diretor','admin']);
        $user = auth();

        $sol      = new SolicitacaoOrcamento();
        $filtros  = [];
        if (!ve_todas_filiais()) $filtros['filial_id'] = $user['filial_id'];
        if (!empty($_GET['status']))   $filtros['status']    = $_GET['status'];

        $solicitacoes = $sol->allComDetalhes($filtros);
        $grupos  = (new GrupoProduto())->all(['ativo' => 1], 'nome');
        $setores = (new \App\Models\Setor())->allAtivos();
        $filiais = (new \App\Models\Filial())->all(['ativo' => 1], 'nome');
        $this->view('orcamentos/solicitacoes', compact('solicitacoes', 'grupos', 'setores', 'filiais', 'user'));
    }

    public function solicitar(): void
    {
        $this->requireAuth();
        $user = auth();

        $svc = new OrcamentoService();
        try {
            $filialId = $this->input('filial_id') ?: $user['filial_id'];
            $setorId  = $this->input('setor_id')  ?: $user['setor_id'];
            $id = $svc->solicitarAumento(
                $this->input('orcamento_id'),
                $this->input('requisicao_id'),
                $filialId,
                $setorId,
                $this->input('grupo_produto_id'),
                (float)$this->input('valor_necessario', 0),
                (float)str_replace(['.', ','], ['', '.'], $this->input('valor_solicitado', '0')),
                $this->input('justificativa', ''),
                $user['id']
            );
            session_flash('success', 'Solicitação de aumento enviada à Direção.');
        } catch (\Throwable $e) {
            session_flash('error', $this->mensagemErro($e));
        }

        redirect('/orcamentos/solicitacoes');
    }

    public function aprovar(string $id): void
    {
        $this->requirePerfil(['diretor','admin']);
        $user = auth();

        $sol = new SolicitacaoOrcamento();
        try {
            $sol->aprovar($id, $user['id'], $this->input('observacao', ''));
            (new AuditoriaService())->log($user['id'], 'solicitacao_orcamento', $id, 'solicitacao.aprovada');
            session_flash('success', 'Aumento de orçamento aprovado e liberado.');
        } catch (\Throwable $e) {
            session_flash('error', $this->mensagemErro($e));
        }

        redirect('/orcamentos/solicitacoes');
    }

    public function rejeitar(string $id): void
    {
        $this->requirePerfil(['diretor','admin']);
        $user = auth();

        $sol = new SolicitacaoOrcamento();
        $sol->rejeitar($id, $user['id'], $this->input('observacao', 'Solicitação não aprovada.'));

        (new AuditoriaService())->log($user['id'], 'solicitacao_orcamento', $id, 'solicitacao.rejeitada');
        session_flash('warning', 'Solicitação rejeitada.');
        redirect('/orcamentos/solicitacoes');
    }
}
