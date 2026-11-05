<?php
// @versao Ver.015.301026

namespace App\Controllers;

use App\Core\Controller;
use App\Models\{Requisicao, Item, Filial, Setor, GrupoProduto, Estoque, Aprovacao};
use App\Services\{OrcamentoService, AuditoriaService, NotificacaoService};
use App\Services\EscopoGestorService;
use App\Config\Database;

class RequisicaoController extends Controller
{
    /** Rótulos dos tipos, para as mensagens de permissão. */
    private const ROTULO_TIPO = [
        'compra'        => 'Compra',
        'interna'       => 'Requisição Interna',
        'transferencia' => 'Solicitação de Transferência',
        'ordem_compra'  => 'Ordem de Compra',
    ];

    public function __construct()
    {
        // Requisições: o solicitante pede compras só pela Solicitação de Compra.
        if ((auth()['perfil'] ?? '') === 'solicitante') {
            session_flash('warning', 'Para pedir uma compra, abra uma Solicitação de Compra.');
            redirect('/solicitacoes');
        }
    }

    public function index(): void
    {
        $this->requireAuth();
        $user = auth();

        $filtros = $this->escopoFiltrosUsuario($user);
        if (!empty($_GET['status']))      $filtros['status']       = $_GET['status'];
        if (empty($filtros['filial_id']) && !empty($_GET['filial_id'])) $filtros['filial_id'] = $_GET['filial_id'];
        if (!empty($_GET['tipo_despesa']))$filtros['tipo_despesa'] = $_GET['tipo_despesa'];
        if (!empty($_GET['tipo']) && in_array($_GET['tipo'], Requisicao::TIPOS_COMPRA, true)) $filtros['tipo'] = $_GET['tipo'];

        $model       = new Requisicao();
        // S4 (spec 2026-10-30): o Requisições não tem interna/transferência (estoque).
        $requisicoes = array_values(array_filter($model->allComDetalhes($filtros),
            fn($r) => in_array($r['tipo'] ?? 'compra', Requisicao::TIPOS_COMPRA, true)));
        $andamentos  = (new \App\Services\AndamentoService())->dasRequisicoes(array_column($requisicoes, 'id'));

        $this->view('requisicoes/index', compact('requisicoes', 'user', 'andamentos'));
    }

    public function show(string $id): void
    {
        $this->requireAuth();
        $user = auth();

        $model = new Requisicao();
        $req   = $model->find($id);

        if (!$req) {
            session_flash('error', 'Requisição não encontrada.');
            redirect('/requisicoes');
        }

        // Access control: solicitante/almoxarife veem só as próprias; gestor/comprador só filial+setor; diretor/admin veem tudo
        if (!$this->podeAcessarRequisicao($req, $user)) {
            session_flash('error', 'Acesso negado.');
            redirect('/requisicoes');
        }

        // S4 (spec 2026-10-30): interna/transferência mexem em estoque e não existem no
        // Requisições. Com isso os trechos de estoque abaixo (timeline 4a e $estoqueItens)
        // não rodam aqui.
        if (!in_array($req['tipo'], Requisicao::TIPOS_COMPRA, true)) {
            session_flash('error', 'Este tipo de requisição não existe no Requisições (sem estoque).');
            redirect('/requisicoes');
        }

        $itens = $model->itens($id);
        $db    = Database::getInstance();

        // Build timeline
        $timeline = [];

        // Step 1: Created
        $timeline[] = [
            'label'     => 'Criada',
            'icon'      => 'bi-file-earmark-plus',
            'cor'       => 'success',
            'data'      => $req['criado_em'],
            'usuario'   => null,
            'concluida' => true,
        ];

        // Step 2: Sent for approval (from audit log)
        $stmt = $db->prepare(
            "SELECT la.criado_em, u.nome AS usuario_nome
             FROM logs_auditoria la
             LEFT JOIN usuarios u ON u.id = la.usuario_id
             WHERE la.entidade_tipo = 'requisicao' AND la.entidade_id = ?
               AND la.acao = 'requisicao.enviada'
             ORDER BY la.criado_em DESC LIMIT 1"
        );
        $stmt->execute([$id]);
        $logEnvio = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        $statusAtual = $req['status'];

        if ($logEnvio || in_array($statusAtual, ['em_aprovacao','aprovado','rejeitado','cancelado'])) {
            $timeline[] = [
                'label'     => 'Enviada para aprovação',
                'icon'      => 'bi-send',
                'cor'       => 'primary',
                'data'      => $logEnvio['criado_em'] ?? null,
                'usuario'   => $logEnvio['usuario_nome'] ?? null,
                'concluida' => true,
            ];
        }

        // Step 3: Approval / Rejection
        $stmt = $db->prepare(
            "SELECT a.status, a.resolvido_em, u.nome AS usuario_nome
             FROM aprovacoes a
             LEFT JOIN usuarios u ON u.id = a.aprovado_por
             WHERE a.entidade_tipo = 'requisicao' AND a.entidade_id = ?
             ORDER BY a.nivel DESC LIMIT 1"
        );
        $stmt->execute([$id]);
        $aprovacao = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        if ($aprovacao) {
            $aprovada  = $aprovacao['status'] === 'aprovado';
            $rejeitada = $aprovacao['status'] === 'rejeitado';
            $apLabel   = $aprovada ? 'Aprovada' : ($rejeitada ? 'Rejeitada' : 'Em aprovação');
            $apCor     = $aprovada ? 'success' : ($rejeitada ? 'danger' : 'warning');
            $timeline[] = [
                'label'     => $apLabel,
                'icon'      => $aprovada ? 'bi-check-circle' : ($rejeitada ? 'bi-x-circle' : 'bi-hourglass-split'),
                'cor'       => $apCor,
                'data'      => $aprovacao['resolvido_em'] ?? null,
                'usuario'   => $aprovacao['usuario_nome'] ?? null,
                'concluida' => ($aprovada || $rejeitada),
            ];
        } elseif ($statusAtual === 'em_aprovacao') {
            $timeline[] = [
                'label'     => 'Em aprovação',
                'icon'      => 'bi-hourglass-split',
                'cor'       => 'warning',
                'data'      => null,
                'usuario'   => null,
                'concluida' => false,
            ];
        }

        // Step 4a: Stock exit (interna path — fluxo direto/antigo)
        if ($req['tipo'] === 'interna') {
            $stmt = $db->prepare(
                "SELECT me.criado_em, u.nome AS usuario_nome
                 FROM movimentos_estoque me
                 LEFT JOIN usuarios u ON u.id = me.usuario_id
                 WHERE me.referencia_tipo = 'requisicao' AND me.referencia_id = ?
                   AND me.tipo = 'saida'
                 ORDER BY me.criado_em DESC LIMIT 1"
            );
            $stmt->execute([$id]);
            $movEstoque = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

            $timeline[] = [
                'label'     => 'Estoque baixado',
                'icon'      => 'bi-box-arrow-up',
                'cor'       => 'info',
                'data'      => $movEstoque['criado_em'] ?? null,
                'usuario'   => $movEstoque['usuario_nome'] ?? null,
                'concluida' => (bool)$movEstoque,
            ];
        } elseif ($req['tipo'] === 'transferencia') {
            $stmt = $db->prepare(
                "SELECT me.criado_em, u.nome AS usuario_nome
                 FROM movimentos_estoque me
                 LEFT JOIN usuarios u ON u.id = me.usuario_id
                 JOIN transferencias t ON t.id = me.referencia_id
                 WHERE me.referencia_tipo = 'transferencia' AND t.requisicao_id = ?
                   AND me.tipo = 'saida'
                 ORDER BY me.criado_em DESC LIMIT 1"
            );
            $stmt->execute([$id]);
            $movEstoque = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

            $falhouMotivo = null;
            if (!$movEstoque && $req['status'] === 'aprovado') {
                $stmtFalha = $db->prepare(
                    "SELECT payload_depois FROM logs_auditoria
                     WHERE entidade_tipo = 'requisicao' AND entidade_id = ?
                       AND acao IN ('requisicao.transferencia_ignorada','requisicao.transferencia_falhou','requisicao.estoque_insuficiente_na_aprovacao')
                     ORDER BY criado_em DESC LIMIT 1"
                );
                $stmtFalha->execute([$id]);
                $logFalha = $stmtFalha->fetch(\PDO::FETCH_ASSOC) ?: null;
                if ($logFalha) {
                    $payload = json_decode($logFalha['payload_depois'] ?? '{}', true) ?: [];
                    $falhouMotivo = $payload['motivo'] ?? $payload['erro'] ?? 'motivo não registrado';
                }
            }

            $timeline[] = [
                'label'     => $falhouMotivo ? 'Transferência não concluída' : 'Estoque baixado na matriz',
                'icon'      => $falhouMotivo ? 'bi-exclamation-triangle' : 'bi-box-arrow-up',
                'cor'       => $falhouMotivo ? 'danger' : 'info',
                'data'      => $movEstoque['criado_em'] ?? null,
                'usuario'   => $movEstoque['usuario_nome'] ?? null,
                'concluida' => (bool)$movEstoque,
                'falhou'    => (bool)$falhouMotivo,
                'motivo'    => $falhouMotivo,
            ];
        }

        // Step 4b: OC generated (compra path)
        if ($req['tipo'] === 'compra') {
            // Compra fracionada: a requisição pode ter várias OCs, uma por parte.
            // Mostrar só a primeira esconderia o resto da compra.
            $stmt = $db->prepare(
                "SELECT oc.criado_em, oc.numero_oc, oc.parte, u.nome AS usuario_nome
                 FROM ordens_compra oc
                 LEFT JOIN usuarios u ON u.id = oc.criado_por
                 WHERE oc.requisicao_id = ?
                 ORDER BY oc.parte ASC"
            );
            $stmt->execute([$id]);
            $ocs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($ocs)) {
                // Sem OC ainda: a etapa segue aparecendo como pendente, como antes.
                $timeline[] = [
                    'label'     => 'OC Gerada',
                    'icon'      => 'bi-receipt',
                    'cor'       => 'info',
                    'data'      => null,
                    'usuario'   => null,
                    'concluida' => false,
                ];
            } else {
                foreach ($ocs as $ocItem) {
                    $timeline[] = [
                        'label'     => 'OC Gerada — ' . $ocItem['numero_oc'],
                        'icon'      => 'bi-receipt',
                        'cor'       => 'info',
                        'data'      => $ocItem['criado_em'],
                        'usuario'   => $ocItem['usuario_nome'],
                        'concluida' => true,
                    ];
                }
            }
        }

        // Rascunho: show only "created + waiting to send"
        if ($statusAtual === 'rascunho') {
            $timeline = [
                [
                    'label'     => 'Criada',
                    'icon'      => 'bi-file-earmark-plus',
                    'cor'       => 'success',
                    'data'      => $req['criado_em'],
                    'usuario'   => null,
                    'concluida' => true,
                ],
                [
                    'label'     => 'Aguardando envio',
                    'icon'      => 'bi-clock',
                    'cor'       => 'secondary',
                    'data'      => null,
                    'usuario'   => null,
                    'concluida' => false,
                ],
            ];
        }

        $estoqueItens = [];
        if (in_array($req['tipo'], ['interna', 'transferencia'], true) && in_array($req['status'], ['em_aprovacao', 'aprovado'])) {
            $estModel = new Estoque();
            foreach ($itens as $item) {
                if (!empty($item['item_id'])) {
                    $info = $estModel->filialComEstoque(
                        $item['item_id'],
                        (float)$item['quantidade'],
                        $req['filial_id']
                    );
                    $estoqueItens[$item['item_id']] = $info;
                }
            }
        }

        $solicitacaoOrigem = (new \App\Services\SolicitacaoCompraService())->daRequisicao($id);
        $andamento         = (new \App\Services\AndamentoService($db))->daRequisicao($id);

        $this->view('requisicoes/show', compact('req', 'itens', 'timeline', 'user', 'estoqueItens', 'solicitacaoOrigem', 'andamento'));
    }

    public function imprimir(string $id): void
    {
        $this->requireAuth();
        $user = auth();

        $db = Database::getInstance();
        $model = new Requisicao();
        $req = $model->find($id);

        if (!$req) {
            session_flash('error', 'RequisiÃ§Ã£o nÃ£o encontrada.');
            redirect('/requisicoes');
        }

        if (!$this->podeAcessarRequisicao($req, $user)) {
            session_flash('error', 'Acesso negado.');
            redirect('/requisicoes');
        }

        if ($req['status'] !== 'aprovado') {
            session_flash('error', 'Apenas requisiÃ§Ãµes aprovadas podem ser impressas.');
            redirect('/requisicoes');
        }

        $req['solicitante_nome'] = '—';
        $req['solicitante_email'] = '—';
        if (!empty($req['usuario_id'])) {
            $stmtUsuario = $db->prepare("SELECT nome, email FROM usuarios WHERE id = ? LIMIT 1");
            $stmtUsuario->execute([$req['usuario_id']]);
            if ($usuario = $stmtUsuario->fetch()) {
                $req['solicitante_nome'] = $usuario['nome'] ?? '—';
                $req['solicitante_email'] = $usuario['email'] ?? '—';
            }
        }

        $req['filial_nome'] = $this->nomePorId('filiais', $req['filial_id'] ?? null);
        $req['setor_nome'] = $this->nomePorId('setores', $req['setor_id'] ?? null);
        $req['grupo_nome'] = $this->nomePorId('grupos_produtos', $req['grupo_produto_id'] ?? null);
        $req['filial_destino_nome'] = $this->nomePorId('filiais', $req['filial_destino_id'] ?? null);

        $aprovacao = null;
        try {
            $stmtAprov = $db->prepare(
                "SELECT a.resolvido_em, u.nome AS usuario_nome
                 FROM aprovacoes a
                 LEFT JOIN usuarios u ON u.id = a.aprovado_por
                 WHERE a.entidade_tipo = 'requisicao' AND a.entidade_id = ?
                   AND a.status = 'aprovado'
                 ORDER BY a.nivel DESC LIMIT 1"
            );
            $stmtAprov->execute([$id]);
            $aprovacao = $stmtAprov->fetch() ?: null;
        } catch (\Throwable) {
            $aprovacao = null;
        }

        $stmtItens = $db->prepare(
            "SELECT ri.*,
                    i.nome AS item_nome,
                    i.unidade,
                    gp.nome AS grupo_nome
             FROM requisicao_itens ri
             LEFT JOIN itens i ON i.id = ri.item_id
             LEFT JOIN grupos_produtos gp ON gp.id = i.grupo_produto_id
             WHERE ri.requisicao_id = ?"
        );
        $stmtItens->execute([$id]);
        $itens = $stmtItens->fetchAll();

        if (empty($req['grupo_nome'])) {
            foreach ($itens as $item) {
                if (!empty($item['grupo_nome'])) {
                    $req['grupo_nome'] = $item['grupo_nome'];
                    break;
                }
            }
        }

        $this->view('requisicoes/imprimir', compact('req', 'itens', 'aprovacao'), '');
    }

    public function form(?string $id = null): void
    {
        $this->requireAuth();
        $user = auth();

        // Tipo vem das Permissões Individuais (migration 052) — única fonte da
        // verdade. Substitui o antigo can(['comprador','admin']) para Compra.
        $tiposPermitidos = tiposRequisicaoPermitidos();

        $requisicao = null;
        $itens_req  = [];

        // Requisição gerada a partir de uma Solicitação de Compra aprovada.
        $solicitacaoOrigem = null;
        if (!$id && !empty($_GET['solicitacao'])) {
            $svcSol = new \App\Services\SolicitacaoCompraService();
            $sol    = $svcSol->buscar((string)$_GET['solicitacao']);
            if ($sol && $sol['status'] === 'aprovada' && $svcSol->podeComprar($user)) {
                $solicitacaoOrigem = $sol;
                // SOL v2: itens viram itens livres da requisição (o comprador troca por
                // item do catálogo se quiser). Unidade ≠ "un" vai na descrição.
                $solicitacaoOrigem['itens'] = $svcSol->itens($sol['id']);
                foreach ($solicitacaoOrigem['itens'] as $it) {
                    $desc = $it['descricao'] . ($it['unidade'] !== 'un' ? ' (' . $it['unidade'] . ')' : '');
                    $itens_req[] = [
                        'item_id'         => null,
                        'anexo'           => null,
                        'observacao'      => null,
                        'descricao_livre' => mb_substr($desc, 0, 255),
                        'quantidade'      => $it['quantidade'],
                        'valor_unitario'  => $it['valor_unitario'],
                    ];
                }
            }
        }

        if ($id) {
            // Edição abre SEMPRE no tipo gravado, mesmo que não seja mais
            // permitido: quem perdeu Compra ainda precisa corrigir a Compra que
            // criou. O que a regra impede é trocar PARA tipo proibido (atualizar).
            $model      = new Requisicao();
            $requisicao = $model->find($id);
            $itens_req  = $model->itens($id);
            $tipo       = $requisicao['tipo'] ?? 'compra';
            if (!in_array($tipo, Requisicao::TIPOS_COMPRA, true)) {
                session_flash('error', 'Este tipo de requisição não existe no Requisições (sem estoque).');
                redirect('/requisicoes');
            }
        } else {
            if (empty($tiposPermitidos)) {
                session_flash('error', 'Seu usuário não tem permissão para abrir requisição de nenhum tipo.');
                redirect('/requisicoes');
            }
            // ?tipo= não permitido é só um link velho: cai no primeiro permitido.
            $tipo = $_GET['tipo'] ?? '';
            if (!in_array($tipo, $tiposPermitidos, true)) {
                $tipo = $tiposPermitidos[0];
            }
        }

        // Ordem de Compra cota o TOTAL de cada linha (quantidade oculta = 1): a quantidade
        // da SOL vai na descrição e o valor estimado vira o total da linha.
        if ($solicitacaoOrigem && $tipo === 'ordem_compra') {
            foreach ($itens_req as $i => $ir) {
                $q = (float)$ir['quantidade'];
                if ($q == 1.0) continue;
                $sufixo = ' — qtd. ' . rtrim(rtrim(number_format($q, 2, ',', '.'), '0'), ',');
                $itens_req[$i]['descricao_livre'] = mb_substr($ir['descricao_livre'], 0, 255 - mb_strlen($sufixo)) . $sufixo;
                $itens_req[$i]['quantidade']      = 1;
                $itens_req[$i]['valor_unitario']  = $ir['valor_unitario'] !== null ? round((float)$ir['valor_unitario'] * $q, 2) : null;
            }
        }

        $itens   = (new Item())->allComGrupo(['ativo' => 1]);
        $grupos  = (new GrupoProduto())->all(['ativo' => 1], 'nome');
        $filiais = (new Filial())->allAtivas();
        $setores = (new Setor())->allAtivos();
        $estoque = new Estoque();

        // S4 (spec 2026-10-30): sem estoque no Requisições. O saldo por unidade só
        // servia para filtrar o combo de itens na interna/transferência — não lê mais.
        $filialRequisitante = $requisicao['filial_id'] ?? $user['filial_id'];
        $matrizId            = null;
        $estoqueUnidade      = [];
        $estoqueMatriz       = [];

        // filial_id => 0|1. Serve para o filtro inicial e para a troca de unidade
        // pelo diretor, feita no cliente sem nova ida ao servidor.
        $flagsMatrizPorFilial = array_map('intval', array_column(
            Database::getInstance()->query("SELECT id, usa_estoque_matriz FROM filiais")->fetchAll(),
            'usa_estoque_matriz',
            'id'
        ));
        $usaEstoqueMatriz = !empty($flagsMatrizPorFilial[$filialRequisitante]);

        // Itens vinculados a um Brinde só podem ser pedidos pela aba Brindes
        // (Solicitar saída) — mantém um único fluxo de baixa para esses itens.
        $itensBrindeIds = array_column(
            Database::getInstance()->query("SELECT item_id FROM brindes WHERE item_id IS NOT NULL")->fetchAll(),
            'item_id'
        );

        // Dados do tipo Ordem de Compra: cotações já salvas, fornecedores para o
        // campo de busca e os limites de cotações mostrados na tela.
        $svcOc               = new \App\Services\OrdemCompraDiretaService();
        $cotacoes            = $id ? (new \App\Models\RequisicaoCotacao())->daRequisicao($id) : [];
        // Valor por item já gravado: sem isso o JS remonta a tabela de cada
        // cotação em branco e um novo "Salvar" regrava tudo zerado.
        $cotacoesItens       = $id ? (new \App\Models\RequisicaoCotacao())->itensPorCotacao($id) : [];
        $minimoCotacoes      = $svcOc->minimoCotacoes();
        $recomendadoCotacoes = $svcOc->recomendadoCotacoes();
        $regraCotacoes       = (new \App\Services\PoliticaComprasService())->descricaoRegraCotacoes();
        $fornecedores        = \App\Config\Database::getInstance()->query(
            "SELECT id, COALESCE(NULLIF(nome_fantasia, ''), razao_social) AS nome, cnpj
             FROM fornecedores WHERE ativo = 1 AND situacao <> 'bloqueado' ORDER BY nome"
        )->fetchAll(\PDO::FETCH_ASSOC);

        // Ordem de Compra travada (fora de rascunho/rejeitado): a tela mostra as
        // cotações, mas sem os controles de edição — ver C1.
        $cotacoesTravadas = $tipo === 'ordem_compra' && $requisicao
            ? !in_array($requisicao['status'], ['rascunho', 'rejeitado'], true)
            : false;

        $this->view('requisicoes/form', compact('requisicao', 'itens_req', 'itens', 'grupos', 'filiais', 'setores', 'user', 'estoque', 'tipo', 'tiposPermitidos', 'estoqueUnidade', 'estoqueMatriz', 'matrizId', 'itensBrindeIds', 'usaEstoqueMatriz', 'flagsMatrizPorFilial', 'cotacoes', 'cotacoesItens', 'fornecedores', 'minimoCotacoes', 'recomendadoCotacoes', 'regraCotacoes', 'cotacoesTravadas', 'solicitacaoOrigem'));
    }

    public function store(): void
    {
        $this->requireAuth();
        $user = auth();

        // Recusa no servidor: esconder o botão é conforto, um POST montado à mão
        // tem de ser barrado igual. Não cai mais em Transferência, porque ela
        // também pode ser proibida para este usuário (migration 052).
        $tipo = (string)$this->input('tipo');
        // S4: só Compra e Ordem de Compra (interna/transferência mexem em estoque).
        if (!in_array($tipo, Requisicao::TIPOS_COMPRA, true)) {
            session_flash('error', 'Tipo de requisição inválido.');
            redirect('/requisicoes/nova');
        }
        if (!in_array($tipo, tiposRequisicaoPermitidos(), true)) {
            session_flash('error', 'Você não tem permissão para requisição do tipo ' . self::ROTULO_TIPO[$tipo] . '.');
            redirect('/requisicoes/nova');
        }
        $politica = $this->camposPolitica($tipo);
        if (is_string($politica)) {
            session_flash('error', $politica);
            redirect('/requisicoes/nova?tipo=' . $tipo);
        }
        if ($politica['necessario_ate'] !== null && $politica['necessario_ate'] < date('Y-m-d')) {
            session_flash('error', 'A data "necessário até" não pode estar no passado.');
            redirect('/requisicoes/nova?tipo=' . $tipo);
        }

        $filialOrigem = can(['diretor','admin']) ? ($this->input('filial_id') ?: $user['filial_id']) : $user['filial_id'];

        if (in_array($tipo, ['interna', 'transferencia'], true)) {
            $filialDestino = $this->input('filial_destino_id');
            if (empty($filialDestino)) {
                session_flash('error', 'Selecione a filial destino.');
                redirect('/requisicoes/nova?tipo=' . $tipo);
            }
            if ($tipo === 'transferencia') {
                $matrizStore = (new \App\Models\Estoque())->getFilialMatriz();
                if ($matrizStore && $filialDestino === $matrizStore['id']) {
                    session_flash('error', 'A unidade de destino não pode ser a matriz — numa transferência, o material sempre sai da matriz.');
                    redirect('/requisicoes/nova?tipo=' . $tipo);
                }
            }
        }

        $itemTipos    = $_POST['item_tipo']        ?? [];
        $itemIds      = $_POST['item_id']          ?? [];
        $descricoes   = $_POST['descricao_livre']  ?? [];
        $quantidades  = $_POST['quantidade']       ?? [];
        $valores      = $_POST['valor_unit']       ?? [];
        $obs          = $_POST['observacao']       ?? [];
        $arquivos     = $_FILES['anexo']           ?? [];

        // A Ordem de Compra só trabalha com item sem cadastro — linha de catálogo
        // nem conta para "tem ao menos um item".
        if ($tipo === 'ordem_compra') {
            foreach ($itemTipos as $i => $itemTipoLinha) {
                if ($itemTipoLinha !== 'livre') {
                    unset($itemTipos[$i], $itemIds[$i], $descricoes[$i], $quantidades[$i]);
                }
            }
        }

        if (!$this->temItemValido($itemTipos, $itemIds, $descricoes, $quantidades)) {
            session_flash('error', 'Adicione ao menos um item com quantidade informada.');
            redirect($tipo !== 'compra' ? '/requisicoes/nova?tipo=' . $tipo : '/requisicoes/nova');
        }

        if (in_array($tipo, ['interna', 'transferencia'], true) && $this->contemItemBrinde($itemIds)) {
            session_flash('error', 'Itens vinculados a um Brinde só podem ser solicitados pela aba Brindes.');
            redirect('/requisicoes/nova?tipo=' . $tipo);
        }

        // Cotações são validadas ANTES de gravar qualquer coisa (número, itens,
        // requisição): sem isto um erro de cotação (fornecedor duplicado/inativo,
        // valor negativo) deixava a requisição criada com os itens já salvos —
        // meio-caminho que o usuário não pedia (M1).
        if ($tipo === 'ordem_compra') {
            if ($erro = $this->validarCotacoes()) {
                session_flash('error', $erro);
                redirect('/requisicoes/nova?tipo=' . $tipo);
            }
            if (is_string($erro = $this->camposPrazosOc(true))) {
                session_flash('error', $erro);
                redirect('/requisicoes/nova?tipo=' . $tipo);
            }
        }

        $data = [
            'filial_id'         => $filialOrigem,
            'setor_id'          => $this->setorSolicitante($tipo, $this->input('setor_id'), $user['setor_id']),
            'tipo'              => $tipo,
            'grupo_produto_id'  => null,
            'filial_destino_id' => in_array($tipo, ['interna', 'transferencia'], true) ? $this->input('filial_destino_id') : null,
            'usuario_id'        => $user['id'],
            'status'            => 'rascunho',
            'tipo_despesa'      => $this->input('tipo_despesa', 'variavel'),
            'justificativa'     => $this->input('justificativa'),
            'finalidade'        => $politica['finalidade'],
            'necessario_ate'    => $politica['necessario_ate'],
            'frete_tipo'        => $tipo === 'compra' ? ($this->input('frete_tipo') ?: null) : null,
            'ipi_percentual'    => $tipo === 'compra' ? (float)str_replace(',', '.', $this->input('ipi_percentual') ?? '0') : 0.00,
            'cno'               => $tipo === 'compra' ? (isset($_POST['cno']) ? 1 : 0) : 0,
        ];

        $db = \App\Config\Database::getInstance();
        $db->beginTransaction();
        try {
            $stmtNum = $db->query("SELECT COALESCE(MAX(numero), 0) + 1 FROM requisicoes FOR UPDATE");
            $proximoNumero = (int)$stmtNum->fetchColumn();
            $data['numero'] = $proximoNumero;
            $model = new Requisicao();
            $id    = $model->create($data);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao gerar número da requisição. Tente novamente.');
            redirect('/requisicoes/nova');
        }

        $this->gravarItens($id, $tipo);

        // Veio de uma Solicitação de Compra: vincula e passa ela para "em compra".
        $solicitacaoId = trim((string)$this->input('solicitacao_id', ''));
        if ($solicitacaoId !== '') {
            $vinculou = false;
            try {
                $vinculou = (new \App\Services\SolicitacaoCompraService())->vincularRequisicao($solicitacaoId, $id, $user);
            } catch (\Throwable $e) {
                error_log('[RequisicaoController] Falha ao vincular solicitação ' . $solicitacaoId . ' à requisição ' . $id . ': ' . $e->getMessage());
            }
            // Dois compradores ou F5 no POST: a requisição existe, mas a
            // solicitação já não estava aprovada — avisa em vez de calar.
            if (!$vinculou) {
                session_flash('warning', 'A requisição foi criada, mas não ficou vinculada à solicitação (ela já estava em compra ou foi alterada).');
            }
        }

        if ($tipo === 'ordem_compra') {
            if ($erro = $this->salvarCotacoes($id)) {
                session_flash('error', $erro);
                redirect('/requisicoes/' . $id . '/editar');
            }
        }

        (new AuditoriaService())->log($user['id'], 'requisicao', $id, 'requisicao.criada');

        session_flash('success', 'Requisição criada. Revise os itens e envie para aprovação.');
        redirect('/requisicoes/' . $id . '/editar');
    }

    public function atualizar(string $id): void
    {
        $this->requireAuth();
        $user = auth();

        $model = new Requisicao();
        $req   = $model->find($id);

        if (!$req || ($req['usuario_id'] !== $user['id'] && !can(['admin']))) {
            session_flash('error', 'Requisição não encontrada.');
            redirect('/requisicoes');
        }

        if (!in_array($req['status'], ['rascunho', 'rejeitado', 'em_aprovacao'])) {
            session_flash('error', 'Não é possível editar uma requisição com status: ' . $req['status']);
            redirect('/requisicoes/' . $id . '/editar');
        }

        $tipo = in_array($this->input('tipo'), Requisicao::TIPOS_COMPRA, true) ? $this->input('tipo') : $req['tipo'];
        // S4: interna/transferência não existem no Requisições (sem estoque).
        if (!in_array($tipo, Requisicao::TIPOS_COMPRA, true)) {
            session_flash('error', 'Tipo de requisição inválido.');
            redirect('/requisicoes');
        }

        // Ordem de Compra é travada assim que sai de rascunho/rejeitado — senão o
        // dono troca a cotação vencedora (ou o valor) durante a aprovação e
        // furou a alçada. Cobre tanto o tipo já gravado quanto uma tentativa de
        // TROCAR para ordem_compra a partir de outro tipo em aprovação.
        $ehOuFoiOrdemCompra = $req['tipo'] === 'ordem_compra' || $tipo === 'ordem_compra';
        if ($ehOuFoiOrdemCompra && !in_array($req['status'], ['rascunho', 'rejeitado'], true)) {
            session_flash('error', 'Esta ordem de compra já foi enviada e não pode mais ser alterada.');
            redirect('/requisicoes/' . $id);
        }

        // Trocar o tipo (qualquer tipo) só é aceito enquanto a requisição ainda
        // não saiu de rascunho/rejeitado — evita usar a troca de tipo como
        // atalho para escapar de uma trava de status.
        if ($tipo !== $req['tipo'] && !in_array($req['status'], ['rascunho', 'rejeitado'], true)) {
            session_flash('error', 'Não é possível trocar o tipo de uma requisição que já foi enviada.');
            redirect('/requisicoes/' . $id . '/editar');
        }

        // Manter o tipo gravado é sempre aceito (a requisição já existe); só
        // TROCAR para um tipo não permitido é recusado.
        if ($tipo !== $req['tipo'] && !in_array($tipo, tiposRequisicaoPermitidos(), true)) {
            session_flash('error', 'Você não tem permissão para requisição do tipo ' . self::ROTULO_TIPO[$tipo] . '.');
            redirect('/requisicoes/' . $id . '/editar');
        }

        // Na edição não barra data passada: o rascunho pode ter sido salvo antes.
        $politica = $this->camposPolitica($tipo);
        if (is_string($politica)) {
            session_flash('error', $politica);
            redirect('/requisicoes/' . $id . '/editar');
        }

        $campos = [
            'justificativa'  => $this->input('justificativa'),
            'finalidade'     => $politica['finalidade'],
            'necessario_ate' => $politica['necessario_ate'],
            'setor_id'      => $this->setorSolicitante($tipo, $this->input('setor_id'), $user['setor_id'] ?: $req['setor_id']),
            'tipo'          => $tipo,
        ];
        if (can(['diretor','admin']) && $this->input('filial_id')) {
            $campos['filial_id'] = $this->input('filial_id');
        }

        if ($tipo === 'compra') {
            $campos['grupo_produto_id'] = null;
            $campos['tipo_despesa']     = $this->input('tipo_despesa', 'variavel');
            // Tipo de frete e IPI saíram do formulário: a edição mantém o que já estava gravado
            $campos['cno']              = isset($_POST['cno']) ? 1 : 0;
        } elseif ($tipo === 'ordem_compra') {
            // Ordem de Compra não tem filial destino (não é interna/transferência) —
            // por isso um ramo próprio, em vez de cair no "else" abaixo. O orçamento
            // consumido é o do setor, então não há grupo a gravar.
            $campos['grupo_produto_id'] = null;
            $campos['tipo_despesa']     = $this->input('tipo_despesa', 'variavel');
        } else {
            $filialDestino = $this->input('filial_destino_id');
            if (empty($filialDestino)) {
                session_flash('error', 'Selecione a filial destino.');
                redirect('/requisicoes/' . $id . '/editar');
            }
            if ($tipo === 'transferencia') {
                $matrizAtualizar = (new \App\Models\Estoque())->getFilialMatriz();
                if ($matrizAtualizar && $filialDestino === $matrizAtualizar['id']) {
                    session_flash('error', 'A unidade de destino não pode ser a matriz — numa transferência, o material sempre sai da matriz.');
                    redirect('/requisicoes/' . $id . '/editar');
                }
            }
            $campos['filial_destino_id'] = $filialDestino;
        }

        $itemTipos   = $_POST['item_tipo']       ?? [];
        $itemIds     = $_POST['item_id']         ?? [];
        $descricoes  = $_POST['descricao_livre'] ?? [];
        $quantidades = $_POST['quantidade']      ?? [];
        $valores     = $_POST['valor_unit']      ?? [];
        $obs         = $_POST['observacao']      ?? [];
        $arquivos    = $_FILES['anexo']          ?? [];

        // A Ordem de Compra só trabalha com item sem cadastro — linha de catálogo
        // nem conta para "tem ao menos um item".
        if ($tipo === 'ordem_compra') {
            foreach ($itemTipos as $i => $itemTipoLinha) {
                if ($itemTipoLinha !== 'livre') {
                    unset($itemTipos[$i], $itemIds[$i], $descricoes[$i], $quantidades[$i]);
                }
            }
        }

        if (!$this->temItemValido($itemTipos, $itemIds, $descricoes, $quantidades)) {
            session_flash('error', 'Adicione ao menos um item com quantidade informada.');
            redirect('/requisicoes/' . $id . '/editar');
        }

        if (in_array($tipo, ['interna', 'transferencia'], true) && $this->contemItemBrinde($itemIds)) {
            session_flash('error', 'Itens vinculados a um Brinde só podem ser solicitados pela aba Brindes.');
            redirect('/requisicoes/' . $id . '/editar');
        }

        // Ver o comentário em store(): valida as cotações ANTES de gravar a
        // requisição/itens, para um erro não deixar meio-caminho salvo (M1).
        if ($tipo === 'ordem_compra') {
            if ($erro = $this->validarCotacoes()) {
                session_flash('error', $erro);
                redirect('/requisicoes/' . $id . '/editar');
            }
            // Na edição não barra data passada (igual ao "necessário até"); o envio barra.
            if (is_string($erro = $this->camposPrazosOc(false))) {
                session_flash('error', $erro);
                redirect('/requisicoes/' . $id . '/editar');
            }
        }

        $model->update($id, $campos);

        Database::getInstance()->prepare(
            "DELETE FROM requisicao_itens WHERE requisicao_id = ?"
        )->execute([$id]);

        $this->gravarItens($id, $tipo);

        // Recalcula o total da requisição com base nos itens editados.
        // Não altera as aprovações em andamento (apenas o valor próprio da requisição).
        $model->recalcularTotal($id);

        if ($tipo === 'ordem_compra') {
            if ($erro = $this->salvarCotacoes($id)) {
                session_flash('error', $erro);
                redirect('/requisicoes/' . $id . '/editar');
            }
        }

        (new AuditoriaService())->log($user['id'], 'requisicao', $id, 'requisicao.atualizada');

        session_flash('success', 'Requisição atualizada.');
        redirect('/requisicoes/' . $id . '/editar');
    }

    public function enviar(string $id): void
    {
        $this->requireAuth();
        $user = auth();

        $model = new Requisicao();
        $req   = $model->find($id);

        if (!$req || ($req['usuario_id'] !== $user['id'] && !can(['admin']))) {
            session_flash('error', 'Requisição não encontrada.');
            redirect('/requisicoes');
        }

        if (!in_array($req['status'], ['rascunho', 'rejeitado'])) {
            session_flash('error', 'Esta requisição não pode ser enviada.');
            redirect('/requisicoes');
        }

        // Rascunhos de antes da política não têm finalidade/prazo: completa antes de enviar.
        if (in_array($req['tipo'], Requisicao::TIPOS_COMPRA, true)
            && (empty($req['finalidade']) || empty($req['necessario_ate']))) {
            session_flash('error', 'Informe a finalidade e a data "necessário até" antes de enviar (Política de Compras).');
            redirect('/requisicoes/' . $id . '/editar');
        }

        $itens = $model->itens($id);
        if (empty($itens)) {
            session_flash('error', 'Adicione ao menos um item antes de enviar a requisição.');
            redirect('/requisicoes/' . $id . '/editar');
        }

        if ($req['tipo'] === 'compra') {
            // Caminho de compra — vai direto para aprovação (módulo orçamentos não utilizado)
            $model->update($id, ['status' => 'em_aprovacao']);
            (new Aprovacao())->criarParaRequisicao($id, (float)$req['valor_total']);
            $this->notificarAprovadores($req);
            $temEstoque = false;

        } elseif ($req['tipo'] === 'ordem_compra') {
            // Cotações digitadas à mão: valida o conjunto antes de ir para a alçada.
            // A OC só nasce na aprovação (AprovacaoController::liberarEntidade).
            $svc   = new \App\Services\OrdemCompraDiretaService();
            $erros = $svc->erros($id);
            if (!empty($erros)) {
                session_flash('error', $erros[0]);
                redirect('/requisicoes/' . $id . '/editar');
            }

            // Painel fase 2: entrega prevista e condição de pagamento (parcelas, R005)
            // são obrigatórias para a OC; o comprador as recebe pré-preenchidas no comparativo.
            $vencOc   = (new \App\Models\RequisicaoCotacao())->vencedora($id);
            $prazosOc = \App\Models\RequisicaoCotacao::prazosOc($vencOc['observacao'] ?? null);
            $okPrazos = \App\Models\OrdemCompra::validarPrazos(
                $prazosOc['entrega_prevista'] ?? '', $prazosOc['condicao_pagamento'] ?? '', date('Y-m-d')
            );
            if (is_string($okPrazos)) {
                session_flash('error', $okPrazos);
                redirect('/requisicoes/' . $id . '/editar');
            }

            // Reenvio depois de rejeitada: Aprovacao::criarParaRequisicao() sai
            // sem criar nada quando já existe QUALQUER aprovação para a
            // requisição (mesmo rejeitada/aprovada de uma rodada anterior) — sem
            // limpar a cadeia antiga, o reenvio "funcionava" mas não gerava
            // aprovação pendente nenhuma (I7).
            if ($req['status'] === 'rejeitado') {
                Database::getInstance()->prepare(
                    "DELETE FROM aprovacoes WHERE entidade_tipo = 'requisicao' AND entidade_id = ? AND status IN ('rejeitado', 'aprovado')"
                )->execute([$id]);
            }

            $valor = $svc->sincronizarValor($id);
            $model->update($id, ['status' => 'em_aprovacao']);
            (new Aprovacao())->criarParaRequisicao($id, $valor);
            $this->notificarAprovadores($req);
            if ($aviso = $svc->aviso($id)) {
                session_flash('warning', $aviso);
            }
            $temEstoque = false;

        } elseif ($req['tipo'] === 'interna') {
            // Requisição Interna — fluxo direto/antigo: própria filial → matriz → outra filial
            $temEstoque = $this->todosItensTemEstoque($itens, $req['filial_id']);
            if ($temEstoque) {
                $model->update($id, ['status' => 'em_aprovacao']);
                (new Aprovacao())->criarParaRequisicao($id, (float)$req['valor_total']);
                $this->notificarAprovadores($req);
            } else {
                session_flash('error', 'Um ou mais itens não estão disponíveis em nenhuma filial no momento.');
                redirect('/requisicoes/' . $id . '/editar');
            }

        } else {
            // Solicitação de Transferência — só a matriz pode ser origem.
            $temEstoque = $this->todosItensTemEstoqueNaMatriz($itens);
            if ($temEstoque) {
                $model->update($id, ['status' => 'em_aprovacao']);
                (new Aprovacao())->criarParaRequisicao($id, (float)$req['valor_total']);
                $this->notificarAprovadores($req);
            } elseif (podeModulo('requisicoes.compra')) {
                // Sem estoque na matriz, a Transferência vira Compra — só para quem
                // pode comprar. Com a regra antiga (perfil comprador/admin), um
                // comprador de quem se tirou Compra ainda compraria por este atalho.
                $model->update($id, ['status' => 'em_aprovacao', 'tipo' => 'compra']);
                (new Aprovacao())->criarParaRequisicao($id, (float)$req['valor_total']);
                $this->notificarAprovadores($req);
            } else {
                // Usuário sem permissão de compra e a matriz não tem estoque suficiente.
                session_flash('error', 'Um ou mais itens não estão disponíveis no Estoque Principal. Entre em contato com o setor de Compras.');
                redirect('/requisicoes/' . $id . '/editar');
            }
        }

        (new AuditoriaService())->log($user['id'], 'requisicao', $id, 'requisicao.enviada',
            ['status' => $req['status']], ['status' => 'em_aprovacao', 'via_estoque' => $temEstoque]);

        session_flash('success', 'Requisição enviada para aprovação.');
        redirect('/requisicoes');
    }

    public function bloqueioOrcamento(string $id): void
    {
        $this->requireAuth();
        $user = auth();
        $req  = (new Requisicao())->find($id);

        if (!$req || ($req['usuario_id'] !== $user['id'] && !can(['admin']))) {
            session_flash('error', 'Requisição não encontrada.');
            redirect('/requisicoes');
        }

        // Só existe o que fazer aqui enquanto a requisição ainda não foi
        // enviada — sem esta trava, a rota vira um jeito de reenviar (e, pra
        // Ordem de Compra, de trocar o tipo — ver abaixo) uma requisição que já
        // saiu de rascunho/rejeitado (I4).
        if (!in_array($req['status'], ['rascunho', 'rejeitado'], true)) {
            session_flash('error', 'Esta requisição não está mais disponível para reenvio por saldo orçamentário.');
            redirect('/requisicoes');
        }

        // Sempre recalcula em tempo real (não usa cache de sessão)
        $orcSvc = new OrcamentoService();
        $reqModel = new Requisicao();
        $valoresPorGrupo = $reqModel->valoresPorGrupo($id);
        $check  = $orcSvc->verificarSaldoPorGrupos(
            $req['filial_id'],
            $req['setor_id'],
            $valoresPorGrupo
        );

        // Se o saldo agora está OK, envia direto para aprovação
        if ($check['aprovado']) {
            // Só força Compra para quem pode comprar; sem a permissão o tipo
            // original é mantido — senão esta rota viraria porta lateral para Compra.
            // Ordem de Compra NUNCA troca de tipo por aqui: esta rota usa o valor
            // por item (valoresPorGrupo), que ignora a cotação/grupo do pedido —
            // virar Compra silenciosamente fugiria da trava de cotações (I4).
            $tipoFinal = ($req['tipo'] !== 'ordem_compra' && podeModulo('requisicoes.compra')) ? 'compra' : $req['tipo'];
            $reqModel->update($id, ['status' => 'em_aprovacao', 'tipo' => $tipoFinal]);
            (new Aprovacao())->criarParaRequisicao($id, (float)$req['valor_total']);
            $this->notificarAprovadores($req);
            session_flash('success', 'Saldo orçamentário confirmado. Requisição enviada para aprovação.');
            redirect('/requisicoes');
        }

        $grupos = (new GrupoProduto())->all(['ativo' => 1], 'nome');
        $this->view('requisicoes/bloqueio_orcamento', compact('check', 'req', 'grupos'));
    }

    public function cancelar(string $id): void
    {
        $this->requireAuth();
        $user = auth();

        $model = new Requisicao();
        $req   = $model->find($id);

        if (!$req) { redirect('/requisicoes'); }

        // Mesma regra dos botões (lista e bloqueio de orçamento): o dono cancela
        // enquanto não entrou em aprovação; o admin cancela em qualquer status.
        // Antes só exigia login — qualquer usuário cancelava qualquer requisição.
        $ehDono = $req['usuario_id'] === $user['id'];
        if (!$ehDono && !can(['admin'])) {
            session_flash('error', 'Você não pode cancelar esta requisição.');
            redirect('/requisicoes');
        }
        if ($req['status'] === 'cancelado'
            || (!can(['admin']) && !in_array($req['status'], ['rascunho', 'pendente', 'rejeitado'], true))) {
            session_flash('error', 'Esta requisição não pode mais ser cancelada.');
            redirect('/requisicoes/' . $id);
        }

        // Ordem de Compra com cotação ou OC ainda ativa não pode ser cancelada
        // por aqui — cancelar a requisição não desfaz nenhuma das duas, e ficaria
        // um documento vivo apontando para um pedido cancelado (I6). Cancele a
        // cotação/OC primeiro (CompraController), aí sim a requisição pode ser
        // cancelada.
        if (($req['tipo'] ?? '') === 'ordem_compra') {
            $db = \App\Config\Database::getInstance();

            $ocAtiva = $db->prepare(
                "SELECT 1 FROM ordens_compra WHERE requisicao_id = ? AND status <> 'cancelado' LIMIT 1"
            );
            $ocAtiva->execute([$id]);
            if ($ocAtiva->fetchColumn()) {
                session_flash('error', 'Cancele a ordem de compra antes.');
                redirect('/requisicoes/' . $id);
            }

            $cotacaoAtiva = $db->prepare(
                "SELECT 1 FROM cotacoes WHERE requisicao_id = ? AND status <> 'cancelada' LIMIT 1"
            );
            $cotacaoAtiva->execute([$id]);
            if ($cotacaoAtiva->fetchColumn()) {
                session_flash('error', 'Cancele a cotação deste pedido antes.');
                redirect('/requisicoes/' . $id);
            }
        }

        $model->update($id, ['status' => 'cancelado']);

        // Libera reserva de estoque se a requisicao estava aprovada
        if (($req['status'] === 'aprovado') && (($req['tipo'] ?? 'compra') === 'compra')) {
            $db = \App\Config\Database::getInstance();
            foreach ($model->itens($id) as $item) {
                $db->prepare(
                    "UPDATE estoque
                     SET quantidade_reservada = GREATEST(0, quantidade_reservada - ?),
                         atualizado_em = NOW()
                     WHERE item_id = ? AND filial_id = ?"
                )->execute([$item['quantidade'], $item['item_id'], $req['filial_id']]);
            }
        }

        (new AuditoriaService())->log($user['id'], 'requisicao', $id, 'requisicao.cancelada',
            ['status' => $req['status']], ['status' => 'cancelado']);

        session_flash('success', 'Requisicao cancelada.');
        redirect('/requisicoes');
    }
    public function excluir(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['admin']);
        $user = auth();

        $db    = Database::getInstance();
        $model = new Requisicao();
        $req   = $model->find($id);

        if (!$req) {
            session_flash('error', 'Requisição não encontrada.');
            redirect('/requisicoes');
        }

        // Bloquear se já gerou cotação ou ordem de compra
        $cotacoes = $db->prepare("SELECT COUNT(*) FROM cotacoes WHERE requisicao_id = ?");
        $cotacoes->execute([$id]);
        if ((int)$cotacoes->fetchColumn() > 0) {
            session_flash('error', 'Esta requisição possui cotações vinculadas e não pode ser excluída. Cancele-a em vez disso.');
            redirect('/requisicoes');
        }

        $ordens = $db->prepare("SELECT COUNT(*) FROM ordens_compra WHERE requisicao_id = ?");
        $ordens->execute([$id]);
        if ((int)$ordens->fetchColumn() > 0) {
            session_flash('error', 'Esta requisição possui ordens de compra vinculadas e não pode ser excluída. Cancele-a em vez disso.');
            redirect('/requisicoes');
        }

        // Limpar aprovações (sem FK CASCADE)
        $db->prepare("DELETE FROM aprovacoes WHERE entidade_tipo = 'requisicao' AND entidade_id = ?")->execute([$id]);

        // Excluir requisição (requisicao_itens tem CASCADE)
        (new AuditoriaService())->log($user['id'], 'requisicao', $id, 'requisicao.excluida', $req, null);
        $db->prepare("DELETE FROM requisicoes WHERE id = ?")->execute([$id]);

        session_flash('success', 'Requisição excluída com sucesso.');
        redirect('/requisicoes');
    }

    /**
     * Grava as linhas de item do formulário. A Ordem de Compra só aceita item
     * sem cadastro: o pedido é cotado por valor total e não encosta no estoque,
     * então linha de catálogo é descartada aqui — a tela também não oferece,
     * mas a defesa é esta.
     */
    private function gravarItens(string $requisicaoId, string $tipo): void
    {
        $itemTipos   = $_POST['item_tipo']       ?? [];
        $itemIds     = $_POST['item_id']         ?? [];
        $descricoes  = $_POST['descricao_livre'] ?? [];
        $quantidades = $_POST['quantidade']      ?? [];
        $valores     = $_POST['valor_unit']      ?? [];
        $obs         = $_POST['observacao']      ?? [];
        $arquivos    = $_FILES['anexo']          ?? [];

        $model = new Requisicao();

        // $_FILES['anexo'] é indexado pela posição entre os inputs anexo[], e só a
        // linha de item sem cadastro tem esse input — logo o índice NÃO acompanha
        // o índice da linha. O contador anda a cada linha livre, inclusive as que
        // serão puladas adiante, porque o input existe nelas de qualquer forma.
        $posAnexo = 0;

        foreach ($itemTipos as $i => $itemTipo) {
            $ehLivre  = ($itemTipo === 'livre');
            $meuAnexo = $ehLivre ? $posAnexo++ : null;

            if ($tipo === 'ordem_compra' && !$ehLivre) {
                continue;
            }

            $qtd = parse_decimal($quantidades[$i] ?? 0);
            if ($qtd <= 0) continue;

            if ($ehLivre) {
                $desc = trim($descricoes[$i] ?? '');
                if (empty($desc)) continue;
                $anexoPath = $this->salvarAnexo($arquivos, $meuAnexo);
                $model->addItem($requisicaoId, null, $qtd, parse_decimal($valores[$i] ?? 0), $obs[$i] ?? null, $desc, $anexoPath);
            } else {
                $itemId = $itemIds[$i] ?? '';
                if (!$itemId) continue;
                $model->addItem($requisicaoId, $itemId, $qtd, parse_decimal($valores[$i] ?? 0), $obs[$i] ?? null);
            }
        }
    }

    /**
     * Descrições dos itens do pedido, na ordem do formulário — é a âncora que
     * liga cada linha da matriz de cotação ao item certo.
     *
     * @return list<string>
     */
    private function descricoesDosItens(): array
    {
        $descricoes = array_map(
            fn($descricao) => trim((string)$descricao),
            $_POST['descricao_livre'] ?? []
        );

        return array_values(array_filter($descricoes, fn($descricao) => $descricao !== ''));
    }

    /**
     * Valida as cotações do formulário SEM gravar nada — chamada antes de
     * criar/atualizar a requisição e os itens, para um erro de cotação não
     * deixar meio-caminho salvo (M1). Devolve a mensagem de erro, ou null
     * quando o conjunto está ok.
     */
    private function validarCotacoes(): ?string
    {
        $fornecedores = $_POST['cot_fornecedor_id'] ?? [];
        $preenchidos  = array_values(array_filter(array_map('strval', $fornecedores), fn($id) => $id !== ''));

        if (count($preenchidos) !== count(array_unique($preenchidos))) {
            return 'O mesmo fornecedor está em duas cotações. Deixe uma só.';
        }

        $idsAtivos = [];
        if (!empty($preenchidos)) {
            $db     = \App\Config\Database::getInstance();
            $inList = implode(',', array_fill(0, count($preenchidos), '?'));
            $stmt   = $db->prepare("SELECT id FROM fornecedores WHERE id IN ($inList) AND ativo = 1");
            $stmt->execute($preenchidos);
            $idsAtivos = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        foreach ($fornecedores as $i => $fornecedorId) {
            $fornecedorId = (string)$fornecedorId;
            if ($fornecedorId === '') continue;

            if (!in_array($fornecedorId, $idsAtivos, true)) {
                return 'Fornecedor inválido ou inativo na cotação ' . ($i + 1) . '.';
            }

            $frete = parse_decimal((string)($_POST['cot_frete'][$i] ?? '0'));
            if ($frete < 0) {
                return 'O frete não pode ser negativo na cotação ' . ($i + 1) . '.';
            }

            foreach (($_POST['cot_item_valor'][$i] ?? []) as $posicao => $valorItem) {
                if (parse_decimal((string)$valorItem) < 0) {
                    return 'Valor negativo no item ' . ((int)$posicao + 1) . ' da cotação ' . ($i + 1) . '.';
                }
            }
        }

        return null;
    }

    /**
     * Regrava as cotações do formulário e atualiza o valor da requisição.
     * Devolve a mensagem de erro, ou null quando salvou. store()/atualizar()
     * já chamaram validarCotacoes() antes de gravar a requisição/itens; esta
     * segunda validação aqui é só uma rede de segurança.
     */
    private function salvarCotacoes(string $requisicaoId): ?string
    {
        if ($erro = $this->validarCotacoes()) {
            return $erro;
        }

        $fornecedores = $_POST['cot_fornecedor_id'] ?? [];
        $descricoes   = $this->descricoesDosItens();
        $linhas       = [];

        $vencedora = isset($_POST['cot_vencedora']) && $_POST['cot_vencedora'] !== ''
            ? (int)$_POST['cot_vencedora']
            : null;

        // Entrega prevista / condição de pagamento da OC: vão na cotação vencedora
        // (ver RequisicaoCotacao::prazosOc). Já validados em store()/atualizar().
        $prazosOc = $this->camposPrazosOc(false);
        $obsVencedora = is_array($prazosOc)
            ? \App\Models\RequisicaoCotacao::observacaoComPrazos($prazosOc['entrega'], $prazosOc['condicao'])
            : null;

        foreach ($fornecedores as $i => $fornecedorId) {
            $valores = $_POST['cot_item_valor'][$i]  ?? [];
            $cotados = $_POST['cot_item_cotado'][$i] ?? [];

            $itens = [];
            foreach ($descricoes as $posicao => $descricao) {
                $itens[] = [
                    'descricao' => $descricao,
                    'valor'     => $valores[$posicao] ?? '0',
                    'cotado'    => (int)($cotados[$posicao] ?? 1) === 1 ? 1 : 0,
                ];
            }

            $linhas[] = [
                'fornecedor_id'      => (string)$fornecedorId,
                'itens'              => $itens,
                // Sem itens (pedido sem linha de item) o total ainda pode vir do
                // formulário antigo; com itens, o modelo soma os cotados.
                'valor_total'        => $_POST['cot_valor_total'][$i] ?? '0',
                'frete'              => $_POST['cot_frete'][$i] ?? '0',
                // O formulário não pergunta mais prazo de entrega; a coluna fica
                // no banco pelos pedidos antigos.
                'prazo_dias'         => '',
                // maxlength=60 é só do HTML: a coluna é varchar(60) e o sql_mode
                // não é estrito, então um POST montado à mão truncaria em silêncio.
                'condicao_pagamento' => mb_substr((string)($_POST['cot_pagamento'][$i] ?? ''), 0, 60),
                'observacao'         => $vencedora === $i ? $obsVencedora : null,
            ];
        }

        (new \App\Models\RequisicaoCotacao())->substituir($requisicaoId, $linhas, $vencedora);
        (new \App\Services\OrdemCompraDiretaService())->sincronizarValor($requisicaoId);

        return null;
    }

    /**
     * Setor da requisição. Na Requisição Interna quem não é diretor/admin fica
     * preso ao próprio setor (nas filiais, o Almoxarifado da unidade): gestor,
     * comprador e almoxarife só enxergam requisições da própria filial+setor,
     * então gravar outro setor faria a requisição sumir logo após ser criada.
     * Ver podeAcessarRequisicao() e escopoFiltrosUsuario().
     */
    /**
     * Finalidade fiscal e "necessário até" (Política de Compras §7), obrigatórios
     * em Compra e Ordem de Compra. Devolve os campos a gravar ou a mensagem de erro.
     *
     * @return array{finalidade: ?string, necessario_ate: ?string}|string
     */
    private function camposPolitica(string $tipo): array|string
    {
        if (!in_array($tipo, Requisicao::TIPOS_COMPRA, true)) {
            return ['finalidade' => null, 'necessario_ate' => null];
        }
        $finalidade = (string)$this->input('finalidade', '');
        if (!isset(Requisicao::FINALIDADES[$finalidade])) {
            return 'Informe a finalidade da compra (revenda, insumo, uso e consumo, imobilizado ou serviço).';
        }
        $data = \DateTime::createFromFormat('!Y-m-d', (string)$this->input('necessario_ate', ''));
        if (!$data) {
            return 'Informe a data em que o item é necessário.';
        }
        return ['finalidade' => $finalidade, 'necessario_ate' => $data->format('Y-m-d')];
    }

    /**
     * Entrega prevista e condição de pagamento da OC (tipo Ordem de Compra, painel
     * fase 2 + R005: parcelas "30/60/90"). No rascunho podem ficar em branco;
     * preenchidas, são validadas. A exigência fica no envio (enviar()) e de novo ao
     * gerar a OC (CompraController::gerarOC).
     *
     * @return array{entrega: ?string, condicao: ?string}|string
     */
    private function camposPrazosOc(bool $barrarPassado): array|string
    {
        $entrega  = trim((string)$this->input('oc_entrega_prevista', ''));
        $condicao = trim((string)$this->input('oc_condicao_pagamento', ''));
        if ($entrega === '' && $condicao === '') {
            return ['entrega' => null, 'condicao' => null];
        }
        $ok = \App\Models\OrdemCompra::validarPrazos(
            $entrega !== '' ? $entrega : date('Y-m-d'),
            $condicao !== '' ? $condicao : '0',
            $barrarPassado ? date('Y-m-d') : '1900-01-01'
        );
        if (is_string($ok)) {
            return $ok;
        }

        return ['entrega' => $entrega !== '' ? $entrega : null, 'condicao' => $condicao !== '' ? $ok['condicao_pagamento'] : null];
    }

    private function setorSolicitante(string $tipo, ?string $informado, ?string $padrao): ?string
    {
        if ($tipo === 'interna' && !can(['diretor', 'admin'])) {
            return $padrao ?: ($informado ?: null);
        }

        return ($informado ?: $padrao) ?: null;
    }

    private function escopoFiltrosUsuario(array $user): array
    {
        if (ve_todas_filiais()) {
            return [];
        }
        // Gestor: escopo vem de gestores_setor (N pares unidade+setor).
        // Sem vínculo, o filtro 'pares' vazio zera a listagem — decisão da spec.
        if (can('gestor')) {
            return ['pares' => (new EscopoGestorService())->paresDoUsuario($user['id'])];
        }
        if (can(['comprador','almoxarife'])) {
            return ['filial_id' => $user['filial_id'], 'setor_id' => $user['setor_id']];
        }
        return ['usuario_id' => $user['id']];
    }

    private function podeAcessarRequisicao(array $req, array $user): bool
    {
        if (ve_todas_filiais()) {
            return true;
        }
        if (can('gestor')) {
            return (new EscopoGestorService())->gerencia($user['id'], $req['filial_id'], $req['setor_id']);
        }
        if (can(['comprador','almoxarife'])) {
            return $req['filial_id'] === $user['filial_id'] && $req['setor_id'] === $user['setor_id'];
        }
        return $req['usuario_id'] === $user['id'];
    }

    private function temItemValido(array $itemTipos, array $itemIds, array $descricoes, array $quantidades): bool
    {
        foreach ($itemTipos as $i => $tipo) {
            $qtd = parse_decimal($quantidades[$i] ?? 0);
            if ($qtd <= 0) continue;
            if ($tipo === 'livre' && !empty(trim($descricoes[$i] ?? ''))) return true;
            if ($tipo !== 'livre' && !empty($itemIds[$i])) return true;
        }
        return false;
    }

    /**
     * Itens vinculados a um Brinde só podem ser pedidos pela aba Brindes —
     * mantém um único fluxo de baixa de estoque para esses itens.
     */
    private function contemItemBrinde(array $itemIds): bool
    {
        $ids = array_values(array_filter(array_unique($itemIds)));
        if (empty($ids)) return false;

        $inList = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT 1 FROM brindes WHERE item_id IN ($inList) LIMIT 1"
        );
        $stmt->execute($ids);
        return (bool)$stmt->fetchColumn();
    }

    private function salvarAnexo(array $arquivos, int $index): ?string
    {
        $erroUpload = $arquivos['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if (empty($arquivos['tmp_name'][$index]) || $erroUpload !== UPLOAD_ERR_OK) {
            if ($erroUpload !== UPLOAD_ERR_NO_FILE) {
                error_log('[RequisicaoController] Upload de anexo falhou com código UPLOAD_ERR=' . $erroUpload);
            }
            return null;
        }
        $ext     = strtolower(pathinfo($arquivos['name'][$index], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','pdf','webp'];
        if (!in_array($ext, $allowed)) {
            error_log('[RequisicaoController] Anexo rejeitado: extensão não permitida (' . $ext . ')');
            return null;
        }

        // Valida o MIME real (magic bytes) — impede arquivo executável renomeado
        if (!upload_mime_valido($arquivos['tmp_name'][$index], $ext)) {
            $detect = @(new \finfo(FILEINFO_MIME_TYPE))->file($arquivos['tmp_name'][$index]) ?: 'desconhecido';
            error_log('[RequisicaoController] Anexo rejeitado: MIME real não corresponde à extensão .' . $ext . ' (detectado: ' . $detect . ')');
            return null;
        }

        $dir = ROOT . '/uploads/requisicoes/';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[RequisicaoController] Não foi possível criar o diretório de upload: ' . $dir);
            return null;
        }
        $nome = uuid() . '.' . $ext;
        if (move_uploaded_file($arquivos['tmp_name'][$index], $dir . $nome)) {
            return 'uploads/requisicoes/' . $nome;
        }
        error_log('[RequisicaoController] Falha ao mover o anexo para ' . $dir . $nome);
        return null;
    }

    private function nomePorId(string $tabela, ?string $id): string
    {
        if (!$id) return '—';

        $tabelasPermitidas = ['filiais', 'setores', 'grupos_produtos'];
        if (!in_array($tabela, $tabelasPermitidas, true)) return '—';

        $stmt = Database::getInstance()->prepare("SELECT nome FROM {$tabela} WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return (string)($stmt->fetchColumn() ?: '—');
    }

    private function notificarAprovadores(array $req): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT id FROM usuarios
             WHERE perfil IN ('gestor','diretor','admin')
               AND filial_id = ? AND ativo = 1"
        );
        $stmt->execute([$req['filial_id']]);
        $notif = new NotificacaoService();
        foreach ($stmt->fetchAll() as $apr) {
            $notif->notificarAprovacaoPendente(
                $apr['id'], 'requisicao', $req['id'],
                sprintf('Nova requisição de %s aguarda aprovação (valor: %s).', $req['solicitante_nome'] ?? '—', money($req['valor_total']))
            );
        }
    }

    /**
     * Retorna true se TODOS os itens da requisição tiverem estoque suficiente
     * em pelo menos uma filial ativa.
     */
    private function todosItensTemEstoque(array $itens, string $filialId): bool
    {
        $estModel = new Estoque();
        foreach ($itens as $item) {
            if (empty($item['item_id'])) {
                return false; // item livre sem cadastro nao tem estoque
            }
            $filial = $estModel->filialComEstoque(
                $item['item_id'],
                (float)$item['quantidade'],
                $filialId
            );
            if ($filial === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Retorna true se TODOS os itens da requisição tiverem estoque suficiente
     * na matriz (Solicitação de Transferência — sem fallback para outras filiais).
     */
    private function todosItensTemEstoqueNaMatriz(array $itens): bool
    {
        $estModel = new Estoque();
        foreach ($itens as $item) {
            if (empty($item['item_id'])) {
                return false; // item livre sem cadastro nao tem estoque
            }
            if ($estModel->matrizComEstoque($item['item_id'], (float)$item['quantidade']) === null) {
                return false;
            }
        }
        return true;
    }
}
