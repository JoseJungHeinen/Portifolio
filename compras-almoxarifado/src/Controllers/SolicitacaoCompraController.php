<?php
// @versao Ver.002.281026

namespace App\Controllers;

use App\Core\Controller;
use App\Services\SolicitacaoCompraService;

class SolicitacaoCompraController extends Controller
{
    private SolicitacaoCompraService $svc;

    public function __construct()
    {
        $this->svc = new SolicitacaoCompraService();
    }

    public function index(): void
    {
        $this->requirePermissao('solicitacoes');
        $user = auth();

        $ehAprovador = $this->svc->ehAprovador($user);
        $veTodas     = $this->svc->veTodas($user);

        $abas = ['minhas' => 'Minhas solicitações'];
        if ($ehAprovador) $abas['aprovar'] = 'Aguardando minha aprovação';
        if ($veTodas)     $abas['todas']   = 'Todas';

        $aba = is_string($_GET['aba'] ?? null) ? $_GET['aba'] : '';
        if (!isset($abas[$aba])) {
            $aba = $veTodas ? 'todas' : ($ehAprovador ? 'aprovar' : 'minhas');
        }
        $status = is_string($_GET['status'] ?? null) && $_GET['status'] !== '' ? $_GET['status'] : null;

        $solicitacoes = $this->svc->listar($user, $aba, $status);
        $nAprovar = 0;
        if ($ehAprovador) {
            $nAprovar = ($aba === 'aprovar' && $status === null)
                ? count($solicitacoes)
                : count($this->svc->listar($user, 'aprovar', null));
        }

        // Texto digitado volta para o modal depois de erro de validação.
        $old = $_SESSION['sol_old'] ?? [];
        unset($_SESSION['sol_old']);
        $abrirModal = !empty($old);

        $emNomeDeOutro = $this->svc->podeAbrirEmNomeDeOutro($user);
        $usuarios      = $emNomeDeOutro ? $this->svc->usuariosAtivos() : [];
        // Unidade/setor do próprio usuário, mostrados no modal quando não há select.
        $eu            = $this->svc->usuarioAtivo($user['id']) ?? [];
        $userUnidade   = ($eu['filial_nome'] ?? '—') . ' / ' . ($eu['setor_nome'] ?? '—');

        // Comprador/admin escolhem unidade e setor da SOL (padrão: os do solicitante).
        $podeDefinirUnidade = $this->svc->podeDefinirUnidade($user);
        $filiais = $podeDefinirUnidade ? (new \App\Models\Filial())->allAtivas() : [];
        $setores = $podeDefinirUnidade ? (new \App\Models\Setor())->allAtivos() : [];

        $this->view('solicitacoes/index', compact('abas', 'aba', 'status', 'solicitacoes', 'nAprovar', 'old', 'abrirModal',
            'emNomeDeOutro', 'usuarios', 'userUnidade', 'podeDefinirUnidade', 'filiais', 'setores'));
    }

    public function store(): void
    {
        $this->requirePermissao('solicitacoes.criar');
        $user = auth();

        $motivo        = trim((string)$this->input('motivo', ''));
        $necessarioAte = trim((string)$this->input('necessario_ate', ''));
        $finalidade    = (string)$this->input('finalidade', '');
        $fornecedor    = trim((string)$this->input('fornecedor_sugerido', ''));
        $recorrencia   = (string)$this->input('recorrencia', 'unica');
        $foraOrcamento = $this->input('fora_orcamento') === '1';
        $emergencial   = $this->input('emergencial') === '1';
        $urgencia      = (string)$this->input('urgencia', SolicitacaoCompraService::URGENCIA_PADRAO);
        $observacoes   = trim((string)$this->input('observacoes', ''));
        $solicitanteId = trim((string)$this->input('solicitante_id', '')) ?: $user['id'];
        $itens         = $this->itensDoPost();
        $dataOk        = \DateTime::createFromFormat('!Y-m-d', $necessarioAte);

        // Dados frescos do banco (filial/setor/ativo), nunca a cópia da sessão.
        // Em nome de outro: só perfis autorizados e só usuário ativo.
        $solicitante = null;
        if ($solicitanteId === $user['id'] || $this->svc->podeAbrirEmNomeDeOutro($user)) {
            $solicitante = $this->svc->usuarioAtivo($solicitanteId);
        }

        // Limites das colunas DECIMAL (quantidade 10,2 / valor_unitario 14,2).
        $itemInvalido = array_filter($itens, fn($it) => $it['descricao'] === '' || mb_strlen($it['descricao']) > 255
            || round($it['quantidade'], 2) <= 0 || $it['quantidade'] > 99999999.99
            || ($it['valor_unitario'] !== null && $it['valor_unitario'] > 999999999999.99));

        // Unidade/setor escolhidos: só comprador/admin; para os demais o POST é ignorado.
        $filialId = $setorId = null;
        $unidadeOk = true;
        if ($solicitante && $this->svc->podeDefinirUnidade($user)) {
            $filialId  = trim((string)$this->input('filial_id', '')) ?: null;
            $setorId   = trim((string)$this->input('setor_id', '')) ?: null;
            $unidadeOk = $filialId !== null && $this->svc->unidadeSetorValidos($filialId, $setorId);
        }

        $erro = null;
        $id   = null;
        if (!$solicitante) {
            $erro = 'Solicitante inválido.';
        } elseif (!$unidadeOk) {
            $erro = 'Unidade ou setor inválido.';
        } elseif (!$itens) {
            $erro = 'Informe ao menos um item.';
        } elseif (count($itens) > SolicitacaoCompraService::MAX_ITENS) {
            $erro = 'No máximo ' . SolicitacaoCompraService::MAX_ITENS . ' itens por solicitação.';
        } elseif ($itemInvalido) {
            $erro = 'Cada item precisa de descrição (até 255 caracteres) e quantidade entre 0,01 e 99.999.999,99; valor unitário até R$ 999.999.999.999,99.';
        } elseif ($motivo === '') {
            $erro = 'Informe o motivo da compra.';
        } elseif (!$dataOk || $dataOk->format('Y-m-d') !== $necessarioAte) {
            $erro = 'Informe até quando você precisa do item.';
        } elseif ($necessarioAte < date('Y-m-d')) {
            $erro = 'A data "necessário até" não pode estar no passado.';
        } elseif ($finalidade !== '' && !isset(\App\Models\Requisicao::FINALIDADES[$finalidade])) {
            $erro = 'Finalidade inválida.';
        } elseif (!isset(SolicitacaoCompraService::RECORRENCIAS[$recorrencia])) {
            $erro = 'Recorrência inválida.';
        } elseif (!isset(SolicitacaoCompraService::URGENCIAS[$urgencia])) {
            $erro = 'Urgência inválida.';
        } elseif (mb_strlen($fornecedor) > 150) {
            $erro = 'O fornecedor sugerido pode ter no máximo 150 caracteres.';
        } else {
            $unidade = $this->svc->podeDefinirUnidade($user) ? ['filial_id' => $filialId, 'setor_id' => $setorId] : [];
            try {
                $id = $this->svc->abrir($user, $unidade + [
                    'solicitante'         => $solicitante,
                    'itens'               => $itens,
                    'motivo'              => $motivo,
                    'necessario_ate'      => $necessarioAte,
                    'finalidade'          => $finalidade === '' ? null : $finalidade,
                    'fornecedor_sugerido' => $fornecedor,
                    'recorrencia'         => $recorrencia,
                    'fora_orcamento'      => $foraOrcamento,
                    'emergencial'         => $emergencial,
                    'urgencia'            => $urgencia,
                    'observacoes'         => $observacoes,
                ], $_FILES['fotos'] ?? []);
            } catch (\InvalidArgumentException $e) {
                $erro = $e->getMessage();
            } catch (\Throwable $e) {
                error_log('[SolicitacaoCompraController] Falha ao abrir solicitação: ' . $e->getMessage());
                $erro = 'Não foi possível abrir a solicitação. Tente novamente.';
            }
        }

        if ($erro) {
            $_SESSION['sol_old'] = compact('motivo', 'finalidade', 'recorrencia', 'observacoes', 'itens') + [
                'necessario_ate'      => $necessarioAte,
                'fornecedor_sugerido' => $fornecedor,
                'fora_orcamento'      => $foraOrcamento,
                'emergencial'         => $emergencial,
                'urgencia'            => $urgencia,
                'solicitante_id'      => $solicitanteId,
                'filial_id'           => $filialId,
                'setor_id'            => $setorId,
                '_erro'               => $erro,
            ];
            redirect('/solicitacoes');
        }
        session_flash('success', 'Solicitação aberta.');
        redirect('/solicitacoes/' . $id);
    }

    /** Linhas do modal → itens. Linha sem descrição e sem quantidade é descartada. */
    private function itensDoPost(): array
    {
        $desc = (array)($_POST['item_descricao'] ?? []);
        $qtd  = (array)($_POST['item_quantidade'] ?? []);
        $un   = (array)($_POST['item_unidade'] ?? []);
        $val  = (array)($_POST['item_valor'] ?? []);

        $itens = [];
        foreach ($desc as $i => $d) {
            $d = trim((string)$d);
            $q = trim((string)($qtd[$i] ?? ''));
            if ($d === '' && $q === '') continue;
            // Valor vem como texto BR ("R$ 1.500,00"); parse_decimal não ignora o "R$".
            $v = preg_replace('/[^\d.,]/', '', (string)($val[$i] ?? ''));
            $itens[] = [
                'descricao'      => $d,
                'quantidade'     => $q === '' ? 0.0 : parse_decimal($q),
                'unidade'        => mb_substr(trim((string)($un[$i] ?? '')) ?: 'un', 0, 10),
                'valor_unitario' => $v === '' ? null : parse_decimal($v),
            ];
        }
        return $itens;
    }

    public function show(string $id): void
    {
        $sol  = $this->carregar($id);
        $user = auth();

        $itens   = $this->svc->itens($id);
        $eventos = $this->svc->eventos($id);
        $anexosPorEvento = [];
        foreach ($this->svc->anexos($id) as $a) {
            $anexosPorEvento[$a['evento_id'] ?? ''][] = $a;
        }

        $podeAprovar   = $sol['status'] === 'aguardando_aprovacao' && $this->svc->podeAprovar($user, $sol);
        $podeCancelar  = $sol['status'] === 'aguardando_aprovacao'
            && ($sol['solicitante_id'] === $user['id'] || ($sol['aberto_por'] ?? null) === $user['id']);
        $podeGerarReq  = $sol['status'] === 'aprovada'  && $this->svc->podeComprar($user);
        $podeConcluir  = $sol['status'] === 'em_compra' && $this->svc->podeComprar($user);
        $podeMudarUrgencia = $this->svc->podeMudarUrgencia($user, $sol);
        $podeVerReq    = !empty($sol['requisicao_id']) && $user['perfil'] !== 'solicitante';

        $this->view('solicitacoes/show', compact(
            'sol', 'itens', 'eventos', 'anexosPorEvento',
            'podeAprovar', 'podeCancelar', 'podeGerarReq', 'podeConcluir', 'podeVerReq', 'podeMudarUrgencia'
        ));
    }

    public function comentar(string $id): void
    {
        $sol  = $this->carregar($id);
        $user = auth();
        try {
            $this->svc->comentar($sol, $user, trim((string)$this->input('mensagem', '')), $_FILES['fotos'] ?? []);
            session_flash('success', 'Comentário adicionado.');
        } catch (\InvalidArgumentException $e) {
            session_flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[SolicitacaoCompraController] Falha ao comentar ' . $id . ': ' . $e->getMessage());
            session_flash('error', 'Não foi possível salvar o comentário. Tente novamente.');
        }
        redirect('/solicitacoes/' . $id . '#timeline');
    }

    public function aprovar(string $id): void
    {
        $sol = $this->carregar($id);
        if (!$this->svc->podeAprovar(auth(), $sol)) $this->negar();
        $this->transicao($sol, 'aprovada', trim((string)$this->input('mensagem', '')) ?: null, 'Solicitação aprovada.');
    }

    public function recusar(string $id): void
    {
        $sol = $this->carregar($id);
        if (!$this->svc->podeAprovar(auth(), $sol)) $this->negar();
        $justificativa = trim((string)$this->input('mensagem', ''));
        if ($justificativa === '') {
            session_flash('error', 'Informe o motivo da recusa.');
            redirect('/solicitacoes/' . $id);
        }
        $this->transicao($sol, 'recusada', $justificativa, 'Solicitação recusada.');
    }

    public function cancelar(string $id): void
    {
        $sol = $this->carregar($id);
        $uid = auth()['id'];
        if ($sol['solicitante_id'] !== $uid && ($sol['aberto_por'] ?? null) !== $uid) $this->negar();
        $this->transicao($sol, 'cancelada', null, 'Solicitação cancelada.');
    }

    public function concluir(string $id): void
    {
        $sol = $this->carregar($id);
        if (!$this->svc->podeComprar(auth())) $this->negar();
        $this->transicao($sol, 'concluida', trim((string)$this->input('mensagem', '')) ?: null, 'Solicitação concluída.');
    }

    public function urgencia(string $id): void
    {
        $sol  = $this->carregar($id);
        $user = auth();
        if (!$this->svc->podeMudarUrgencia($user, $sol)) $this->negar();
        try {
            $this->svc->mudarUrgencia($sol, $user, (string)$this->input('urgencia', ''));
            session_flash('success', 'Urgência atualizada.');
        } catch (\InvalidArgumentException $e) {
            session_flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[SolicitacaoCompraController] Falha ao mudar urgência: ' . $e->getMessage());
            session_flash('error', 'Não foi possível alterar a urgência.');
        }
        redirect('/solicitacoes/' . $id);
    }

    public function verAnexo(string $anexoId): void
    {
        $this->requirePermissao('solicitacoes');
        $anexo = $this->svc->buscarAnexo($anexoId);
        if (!$anexo) {
            http_response_code(404);
            echo 'Foto não encontrada.';
            return;
        }
        $this->carregar($anexo['solicitacao_id']); // confere permissão de ver

        $full = SolicitacaoCompraService::diretorioAnexos() . basename($anexo['arquivo_path']);
        if (!is_file($full)) {
            http_response_code(404);
            echo 'Foto não encontrada.';
            return;
        }
        $mime = match (strtolower(pathinfo($full, PATHINFO_EXTENSION))) {
            'png'   => 'image/png',
            'webp'  => 'image/webp',
            default => 'image/jpeg',
        };
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($full));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        session_write_close();
        readfile($full);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Busca a solicitação e barra quem não pode vê-la. */
    private function carregar(string $id): array
    {
        $this->requirePermissao('solicitacoes');
        $sol = $this->svc->buscar($id);
        if (!$sol) {
            session_flash('error', 'Solicitação não encontrada.');
            redirect('/solicitacoes');
        }
        if (!$this->svc->podeVer(auth(), $sol)) $this->negar();
        return $sol;
    }

    private function negar(): never
    {
        http_response_code(403);
        $this->view('errors/403', [], 'main');
        exit;
    }

    private function transicao(array $sol, string $para, ?string $mensagem, string $sucesso): void
    {
        try {
            $this->svc->mudarStatus($sol, auth(), $para, $mensagem);
            session_flash('success', $sucesso);
        } catch (\DomainException | \InvalidArgumentException $e) {
            session_flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[SolicitacaoCompraController] Falha na transição ' . $sol['id'] . ' → ' . $para . ': ' . $e->getMessage());
            session_flash('error', 'Não foi possível atualizar a solicitação. Tente novamente.');
        }
        redirect('/solicitacoes/' . $sol['id']);
    }
}
