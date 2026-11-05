<?php
// @versao Ver.006.051126

namespace App\Controllers;

use App\Controllers\Concerns\RespostaCsv;
use App\Core\Controller;
use App\Models\Fornecedor;
use App\Services\AuditoriaService;
use App\Services\FornecedorListaService;
use App\Services\ImportacaoFornecedoresService;
use App\Services\PlanilhaReader;

class FornecedorController extends Controller
{
    use RespostaCsv;

    /** Chave da prévia da importação na sessão. */
    private const SESSAO_IMPORTACAO = 'importacao_fornecedores';
    /** Prévia vale por 1 hora. */
    private const IMPORTACAO_VALIDADE = 3600;

    public function index(): void
    {
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
        $model = new Fornecedor();
        $hoje  = date('Y-m-d');
        $todos = $model->listaClassificacao();

        $filtros = [];
        foreach (array_merge(array_keys(FornecedorListaService::campos()), ['pend', 'q']) as $k) {
            $filtros[$k] = trim((string)($_GET[$k] ?? ''));
        }
        $ordem = isset(FornecedorListaService::ORDENS[$_GET['ord'] ?? '']) ? $_GET['ord'] : 'nome';

        $lista = FornecedorListaService::ordenarAgrupar(
            FornecedorListaService::filtrar($todos, $filtros, $hoje), $ordem
        );

        if (($_GET['csv'] ?? '') === '1') {
            $this->csv('Fornecedores-' . $hoje . '.csv', FornecedorListaService::linhasCsv($lista, $hoje));
        }

        $opcoes = [];
        foreach (FornecedorListaService::campos() as $k => [, $get]) {
            $opcoes[$k] = FornecedorListaService::opcoes($todos, $filtros, $k, $get, $hoje);
        }
        $contPend = FornecedorListaService::contagemPendencias($todos, $filtros, $hoje);

        // Matriz/filiais: quantos CNPJs por raiz no cadastro todo (para o selo "Matriz · N filiais").
        $porRaiz = [];
        foreach ($todos as $f) {
            if ($r = Fornecedor::cnpjRaiz($f['cnpj'])) $porRaiz[$r] = ($porRaiz[$r] ?? 0) + 1;
        }
        // Chips do topo (cadastro todo, sem filtros).
        $chips = ['cnd_vencida' => 0, 'cnd_vence' => 0, 'cnd_positiva' => 0, 'sem_regime' => 0];
        foreach ($todos as $f) {
            foreach (Fornecedor::pendencias($f, $hoje) as $k => $_) {
                if (isset($chips[$k])) $chips[$k]++;
            }
        }

        $total = count($todos);
        $this->view('fornecedores/index', compact('lista', 'total', 'filtros', 'ordem', 'opcoes', 'contPend', 'porRaiz', 'chips', 'hoje'));
    }

    /** Envia CSV (UTF-8 com BOM, separador ;) e encerra. */
    private function csv(string $arquivo, array $linhas): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $arquivo . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($linhas as $l) {
            fputcsv($out, $l, ';', '"', '');
        }
        fclose($out);
        exit;
    }

    public function form(?string $id = null): void
    {
        $this->requirePerfil(['comprador','admin']);
        $fornecedor = $id ? (new Fornecedor())->find($id) : null;
        $cnpjAtivo = (new \App\Services\CnpjService())->ativo();
        $this->view('fornecedores/form', compact('fornecedor', 'cnpjAtivo'));
    }

    public function store(): void
    {
        $this->requirePerfil(['comprador','admin']);
        $user = auth();

        $data = [
            'razao_social' => $this->input('razao_social'),
            'nome_fantasia'=> $this->input('nome_fantasia'),
            'cnpj'         => preg_replace('/\D/', '', $this->input('cnpj', '')),
            'email'        => $this->input('email'),
            'telefone'     => $this->input('telefone'),
            'endereco'     => $this->input('endereco'),
            'cep'          => \App\Services\CnpjService::apenasDigitos($this->input('cep', '')) ?: null,
            'cidade'            => $this->input('cidade'),
            'estado'            => $this->input('estado'),
            'regime_tributario' => $this->input('regime_tributario') ?: null,
            'credito_limite'    => $this->input('credito_limite') !== '' ? parse_decimal($this->input('credito_limite', '0')) : null,
            'descricao_servico' => trim($this->input('descricao_servico', '')) ?: null,
            'observacoes'    => trim($this->input('observacoes', '')),
            'para_compras'   => (int)$this->input('para_compras', 0),
            'para_contratos' => (int)$this->input('para_contratos', 0),
            'ativo'          => 1,
        ];

        $cnd = $this->camposCnd();
        if (is_string($cnd)) {
            session_flash('error', $cnd);
            redirect('/fornecedores/novo');
        }
        $data += $cnd + $this->camposClassificacao();
        if (($data['situacao'] ?? '') === 'bloqueado') {
            $data['situacao'] = 'pendente'; // cadastro novo não nasce bloqueado
        }

        $model = new Fornecedor();
        $id    = $model->create($data);
        (new AuditoriaService())->log($user['id'], 'fornecedor', $id, 'fornecedor.criado', null, $data);

        session_flash('success', 'Fornecedor cadastrado.');
        redirect('/fornecedores');
    }

    /**
     * Cadastro rápido usado pelas cotações da Ordem de Compra: só nome e CNPJ.
     * Responde JSON. CNPJ já cadastrado devolve o fornecedor existente, para o
     * usuário não criar duplicata ao digitar um fornecedor que já existe.
     */
    public function rapido(): void
    {
        $this->requireAuth();
        if (!podeModulo('requisicoes.ordem_compra') && !podeModulo('fornecedores')) {
            $this->json(['erro' => 'Sem permissão para cadastrar fornecedor.'], 403);
        }

        $nome = trim((string)$this->input('nome', ''));
        // razao_social/nome_fantasia são varchar(200) — cortar antes de gravar
        // em vez de deixar o banco rejeitar o INSERT.
        $nome = mb_substr($nome, 0, 200);
        $cnpj = preg_replace('/\D/', '', (string)$this->input('cnpj', ''));

        if ($nome === '') {
            $this->json(['erro' => 'Informe o nome do fornecedor.'], 422);
        }
        if (strlen($cnpj) !== 14) {
            $this->json(['erro' => 'CNPJ deve ter 14 dígitos.'], 422);
        }

        $model = new Fornecedor();
        if ($existente = $model->findBy(['cnpj' => $cnpj])) {
            if ((int)($existente['ativo'] ?? 1) !== 1) {
                $this->json(['erro' => 'Fornecedor com este CNPJ está inativo.'], 422);
            }
            if (!Fornecedor::podeCotar($existente)) {
                $this->json(['erro' => 'Fornecedor com este CNPJ está bloqueado: não recebe cotação nem OC.'], 422);
            }
            $this->json([
                'id'        => $existente['id'],
                'nome'      => $existente['nome_fantasia'] ?: $existente['razao_social'],
                'cnpj'      => $existente['cnpj'],
                'existente' => true,
            ]);
        }

        try {
            $id = $model->create([
                'razao_social'  => $nome,
                'nome_fantasia' => $nome,
                'cnpj'          => $cnpj,
                'ativo'         => 1,
                'para_compras'  => 1,
            ]);
        } catch (\Throwable $e) {
            // Corrida: outra requisição cadastrou o mesmo CNPJ entre o SELECT e o
            // INSERT (cnpj é UNIQUE) — devolve o fornecedor que ganhou a corrida
            // em vez de estourar erro 500 pro usuário.
            $concorrente = $model->findBy(['cnpj' => $cnpj]);
            if (!$concorrente) {
                throw $e;
            }
            if ((int)($concorrente['ativo'] ?? 1) !== 1) {
                $this->json(['erro' => 'Fornecedor com este CNPJ está inativo.'], 422);
            }
            $this->json([
                'id'        => $concorrente['id'],
                'nome'      => $concorrente['nome_fantasia'] ?: $concorrente['razao_social'],
                'cnpj'      => $concorrente['cnpj'],
                'existente' => true,
            ]);
        }

        (new AuditoriaService())->log(auth()['id'], 'fornecedor', $id, 'fornecedor.criado_rapido', null,
            ['nome' => $nome, 'cnpj' => $cnpj]);

        $this->json(['id' => $id, 'nome' => $nome, 'cnpj' => $cnpj, 'existente' => false]);
    }

    public function update(string $id): void
    {
        $this->requirePerfil(['comprador','admin']);
        $user  = auth();
        $model = new Fornecedor();
        $antes = $model->find($id);

        $data = [
            'razao_social' => $this->input('razao_social'),
            'nome_fantasia'=> $this->input('nome_fantasia'),
            'email'        => $this->input('email'),
            'telefone'     => $this->input('telefone'),
            'endereco'     => $this->input('endereco'),
            'cep'          => \App\Services\CnpjService::apenasDigitos($this->input('cep', '')) ?: null,
            'cidade'            => $this->input('cidade'),
            'estado'            => $this->input('estado'),
            'regime_tributario' => $this->input('regime_tributario') ?: null,
            'credito_limite'    => $this->input('credito_limite') !== '' ? parse_decimal($this->input('credito_limite', '0')) : null,
            'descricao_servico' => trim($this->input('descricao_servico', '')) ?: null,
            'observacoes'    => trim($this->input('observacoes', '')),
            'para_compras'   => (int)$this->input('para_compras', 0),
            'para_contratos' => (int)$this->input('para_contratos', 0),
            'ativo'          => (int)$this->input('ativo', 1),
        ];

        // Só o formulário de edição manda os campos de CND/regime; o form de limite
        // de crédito do detalhe posta aqui sem eles e não pode apagá-los.
        $cnd = $this->camposCnd();
        if (is_string($cnd)) {
            session_flash('error', $cnd);
            redirect('/fornecedores/' . $id . '/editar');
        }
        $data += $cnd + $this->camposClassificacao();

        $model->update($id, $data);
        (new AuditoriaService())->log($user['id'], 'fornecedor', $id, 'fornecedor.atualizado', $antes, $data);

        session_flash('success', 'Fornecedor atualizado.');
        redirect(isset($data['situacao']) ? '/fornecedores/' . $id : '/fornecedores');
    }

    /**
     * Situação e cadastro da classificação (etapa 3). Só o formulário de cadastro
     * manda o marcador _classificacao; o form de limite de crédito do detalhe não,
     * e não pode zerar os checkboxes.
     *
     * @return array<string, mixed>
     */
    private function camposClassificacao(): array
    {
        if (empty($_POST['_classificacao'])) return [];
        $sit = (string)($_POST['situacao'] ?? 'pendente');
        $out = [
            'situacao'       => isset(Fornecedor::SITUACOES[$sit]) ? $sit : 'pendente',
            'contato_fiscal' => mb_substr(trim((string)($_POST['contato_fiscal'] ?? '')), 0, 150) ?: null,
        ];
        foreach (['banco_proprio', 'mei', 'mao_de_obra', 'doc_trabalhista', 'tem_contrato', 'clausula_reequilibrio', 'curva_a'] as $k) {
            $out[$k] = !empty($_POST[$k]) ? 1 : 0;
        }
        return $out;
    }

    /**
     * CND Federal (validade, situação) e "regime confirmado em" do POST (painel fase 2).
     * Só devolve as chaves que vieram no POST. Erro = mensagem.
     *
     * @return array<string, ?string>|string
     */
    private function camposCnd(): array|string
    {
        $out = [];
        foreach (['cnd_federal_validade' => 'Validade da CND Federal', 'regime_confirmado_em' => 'Regime confirmado em'] as $campo => $rotulo) {
            if (!array_key_exists($campo, $_POST)) continue;
            $valor = trim((string)$_POST[$campo]);
            if ($valor === '') { $out[$campo] = null; continue; }
            $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
            if (!$data || $data->format('Y-m-d') !== $valor) {
                return $rotulo . ': data inválida.';
            }
            if ($campo === 'regime_confirmado_em' && $valor > date('Y-m-d')) {
                return 'A confirmação do regime não pode ter data futura.';
            }
            $out[$campo] = $valor;
        }
        if (array_key_exists('cnd_federal_situacao', $_POST)) {
            $sit = trim((string)$_POST['cnd_federal_situacao']);
            if ($sit !== '' && !isset(Fornecedor::CND_SITUACOES[$sit])) {
                return 'Situação da CND Federal inválida.';
            }
            $out['cnd_federal_situacao'] = $sit !== '' ? $sit : null;
        }

        return $out;
    }

    /** "Confirmar regime hoje": grava regime_confirmado_em = hoje. */
    public function confirmarRegime(string $id): void
    {
        $this->requirePerfil(['comprador','admin']);
        $model = new Fornecedor();
        $antes = $model->find($id);
        if (!$antes) {
            session_flash('error', 'Fornecedor não encontrado.');
            redirect('/fornecedores');
        }

        $hoje = date('Y-m-d');
        $model->update($id, ['regime_confirmado_em' => $hoje]);
        (new AuditoriaService())->log(auth()['id'], 'fornecedor', $id, 'fornecedor.regime_confirmado',
            ['regime_confirmado_em' => $antes['regime_confirmado_em'] ?? null],
            ['regime_confirmado_em' => $hoje, 'regime_tributario' => $antes['regime_tributario'] ?? null]);

        session_flash('success', 'Regime tributário confirmado hoje.');
        redirect('/fornecedores/' . $id);
    }

    public function toggleAtivo(string $id): void
    {
        $this->requirePerfil(['comprador','admin']);
        $user  = auth();
        $model = new Fornecedor();
        $antes = $model->find($id);

        if (!$antes) {
            session_flash('error', 'Fornecedor nÃ£o encontrado.');
            redirect('/fornecedores');
        }

        $novoAtivo = $antes['ativo'] ? 0 : 1;
        $model->update($id, ['ativo' => $novoAtivo]);

        (new AuditoriaService())->log(
            $user['id'],
            'fornecedor',
            $id,
            $novoAtivo ? 'fornecedor.ativado' : 'fornecedor.inativado',
            ['ativo' => $antes['ativo']],
            ['ativo' => $novoAtivo]
        );

        session_flash('success', $novoAtivo ? 'Fornecedor ativado.' : 'Fornecedor inativado.');
        redirect('/fornecedores');
    }

    /**
     * Exclui o fornecedor de vez. Só deixa se não tiver movimentação
     * (OC, cotação, contrato, crédito, histórico de preço...). Com movimentação,
     * o caminho é inativar, pra não quebrar o histórico.
     */
    public function excluir(string $id): void
    {
        $this->requirePerfil(['comprador','admin']);
        $model = new Fornecedor();
        $antes = $model->find($id);
        if (!$antes) {
            session_flash('error', 'Fornecedor não encontrado.');
            redirect('/fornecedores');
        }

        $db = \App\Config\Database::getInstance();
        $vinculos = [
            'ordens_compra'           => 'ordens de compra',
            'cotacao_fornecedores'    => 'cotações',
            'requisicao_cotacoes'     => 'cotações de requisição',
            'contratos'               => 'contratos',
            'certificados_fornecedor' => 'certificados',
            'creditos_fornecedor'     => 'créditos',
            'historico_precos'        => 'histórico de preços',
        ];
        $achados = [];
        foreach ($vinculos as $tabela => $rotulo) {
            try {
                $st = $db->prepare("SELECT COUNT(*) FROM `$tabela` WHERE fornecedor_id = ?");
                $st->execute([$id]);
                if ((int)$st->fetchColumn() > 0) $achados[] = $rotulo;
            } catch (\PDOException $e) {
                // tabela não existe nesta base: ignora
            }
        }
        if ($achados) {
            session_flash('error', 'Não dá pra excluir: o fornecedor tem ' . implode(', ', $achados)
                . '. Use "Inativar" para tirá-lo de uso.');
            redirect('/fornecedores/' . $id . '/editar');
        }

        // Arquivos físicos dos documentos (as linhas saem por cascade)
        $st = $db->prepare("SELECT arquivo_path FROM documentos_fornecedor WHERE fornecedor_id = ?");
        $st->execute([$id]);
        $arquivos = $st->fetchAll(\PDO::FETCH_COLUMN);

        try {
            $db->beginTransaction();
            $db->prepare("DELETE FROM avaliacoes_fornecedor WHERE fornecedor_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM documentos_fornecedor WHERE fornecedor_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM fornecedores WHERE id = ?")->execute([$id]);
            $db->commit();
        } catch (\PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            session_flash('error', 'Não dá pra excluir: o fornecedor está ligado a outros registros. Use "Inativar".');
            redirect('/fornecedores/' . $id . '/editar');
        }

        foreach ($arquivos as $arq) {
            $full = __DIR__ . '/../../storage/fornecedores/' . $arq;
            if ($arq && file_exists($full)) @unlink($full);
        }

        (new AuditoriaService())->log(auth()['id'], 'fornecedor', $id, 'fornecedor.excluido',
            ['razao_social' => $antes['razao_social'] ?? null, 'cnpj' => $antes['cnpj'] ?? null], null);

        session_flash('success', 'Fornecedor excluído.');
        redirect('/fornecedores');
    }

    public function show(string $id): void
    {
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
        $model = new Fornecedor();
        $fornecedor = $model->find($id);
        
        if (!$fornecedor) { 
            redirect('/fornecedores'); 
        }

        $historico = $model->historicoCompras($id, 20);
        
        // Classificação (etapa 3)
        $avaliacoes     = $model->avaliacoes($id);
        $pendencias     = Fornecedor::pendencias($fornecedor);
        $sugereBloqueio = Fornecedor::sugereBloqueio($avaliacoes);
        $mesmaRaiz      = $model->mesmaRaiz($fornecedor);
        $valorOc12m     = $model->valorOc12m($id);
        
        $db   = \App\Config\Database::getInstance();
        $stmt = $db->prepare(
            "SELECT d.*, u.nome AS enviado_por_nome
             FROM documentos_fornecedor d
             JOIN usuarios u ON u.id = d.criado_por
             WHERE d.fornecedor_id = ?
             ORDER BY d.criado_em DESC"
        );
        $stmt->execute([$id]);
        $documentos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Crédito utilizado = OCs em aberto (aprovado / enviado / recebido_parcial)
        $stmtCred = $db->prepare(
            "SELECT COALESCE(SUM(valor_total), 0)
             FROM ordens_compra
             WHERE fornecedor_id = ?
               AND status IN ('aprovado','enviado','recebido_parcial')"
        );
        $stmtCred->execute([$id]);
        $creditoUtilizado = (float)$stmtCred->fetchColumn();

        // Créditos disponíveis e histórico
        $stmtCredDisp = $db->prepare(
            "SELECT cf.*, u.nome AS criado_por_nome
             FROM creditos_fornecedor cf
             LEFT JOIN usuarios u ON u.id = cf.criado_por
             WHERE cf.fornecedor_id = ?
             ORDER BY cf.usado ASC, cf.criado_em DESC"
        );
        $stmtCredDisp->execute([$id]);
        $creditosFornecedor = $stmtCredDisp->fetchAll();
        $totalCreditosDisp  = array_sum(array_column(
            array_filter($creditosFornecedor, fn($c) => !$c['usado']), 'valor'
        ));

        $this->view('fornecedores/show', compact(
            'fornecedor',
            'historico',
            'avaliacoes',
            'pendencias',
            'sugereBloqueio',
            'mesmaRaiz',
            'valorOc12m',
            'documentos',
            'creditoUtilizado',
            'creditosFornecedor',
            'totalCreditosDisp'
        ));
    }

    /** Avaliação periódica (4 critérios 0–5 → nota ponderada). Refazer o período substitui. */
    public function avaliar(string $id): void
    {
        $this->requirePerfil(['comprador','admin']);
        $model = new Fornecedor();
        if (!$model->find($id)) {
            session_flash('error', 'Fornecedor não encontrado.');
            redirect('/fornecedores');
        }

        $criterios = [];
        foreach (array_keys(Fornecedor::CRITERIOS) as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            $criterios[$k] = $v === '' ? null : str_replace(',', '.', $v);
        }
        if ($erro = Fornecedor::validarCriterios($criterios)) {
            session_flash('error', $erro);
            redirect('/fornecedores/' . $id . '#avaliacao');
        }
        $periodo = strtoupper(trim((string)$this->input('periodo', '')));
        if (!Fornecedor::periodoValido($periodo)) {
            session_flash('error', 'Período inválido (use o formato 2026-T4).');
            redirect('/fornecedores/' . $id . '#avaliacao');
        }
        $comentario = mb_substr(trim((string)$this->input('comentario', '')), 0, 2000);

        $r = $model->salvarAvaliacao($id, auth()['id'], $periodo, $criterios, $comentario);
        (new AuditoriaService())->log(auth()['id'], 'fornecedor', $id,
            $r['atualizou'] ? 'avaliacao.atualizada' : 'avaliacao.criada',
            $r['antes'], ['periodo' => $periodo] + $criterios + ['nota' => $r['nota'], 'comentario' => $comentario]);

        $msg = 'Avaliação de ' . Fornecedor::periodoRotulo($periodo) . ($r['atualizou'] ? ' atualizada' : ' registrada')
             . ': nota ' . Fornecedor::formatarNota($r['nota']) . '.';
        if ($r['nota'] < Fornecedor::NOTA_BLOQUEIO) {
            $msg .= ' Abaixo de 2,0: se repetir no próximo período, bloquear.';
        } elseif (Fornecedor::precisaPlanoAcao($r['nota'])) {
            $msg .= ' Abaixo de 3,0: gerar plano de ação com o fornecedor.';
        }
        session_flash('success', $msg);
        redirect('/fornecedores/' . $id . '#avaliacao');
    }

    public function removerAvaliacao(string $id): void
    {
        $this->requirePerfil(['comprador','admin']);
        $av = (new Fornecedor())->removerAvaliacao($id, (string)$this->input('avaliacao_id', ''));
        if (!$av) {
            session_flash('error', 'Avaliação não encontrada.');
            redirect('/fornecedores/' . $id . '#avaliacao');
        }
        (new AuditoriaService())->log(auth()['id'], 'fornecedor', $id, 'avaliacao.removida', $av, null);
        session_flash('success', 'Avaliação removida.');
        redirect('/fornecedores/' . $id . '#avaliacao');
    }

    /** Bloqueia o fornecedor (situação = bloqueado): não recebe cotação nem OC. */
    public function bloquear(string $id): void
    {
        $this->requirePerfil(['comprador','admin']);
        $model = new Fornecedor();
        $antes = $model->find($id);
        if (!$antes) {
            session_flash('error', 'Fornecedor não encontrado.');
            redirect('/fornecedores');
        }
        if (($antes['situacao'] ?? '') !== 'bloqueado') {
            $model->update($id, ['situacao' => 'bloqueado']);
            (new AuditoriaService())->log(auth()['id'], 'fornecedor', $id, 'fornecedor.bloqueado',
                ['situacao' => $antes['situacao'] ?? null],
                ['situacao' => 'bloqueado', 'motivo' => trim((string)$this->input('motivo', '')) ?: null]);
        }
        session_flash('success', 'Fornecedor bloqueado: não recebe cotação nem OC.');
        redirect('/fornecedores/' . $id);
    }

    public function uploadDocumento(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador', 'gestor', 'admin']);
        $user = auth();

        $fornecedor = (new Fornecedor())->find($id);
        if (!$fornecedor) {
            session_flash('error', 'Fornecedor não encontrado.');
            redirect('/fornecedores');
        }

        $file = $_FILES['arquivo'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            session_flash('error', 'Nenhum arquivo enviado ou erro no upload.');
            redirect('/fornecedores/' . $id);
        }

        // Validate size (10MB max)
        if ($file['size'] > 10 * 1024 * 1024) {
            session_flash('error', 'Arquivo muito grande. Máximo permitido: 10MB.');
            redirect('/fornecedores/' . $id);
        }

        // Validate extension
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
        if (!in_array($ext, $allowed)) {
            session_flash('error', 'Tipo de arquivo não permitido. Use: PDF, imagens, Word ou Excel.');
            redirect('/fornecedores/' . $id);
        }

        // Valida o MIME real (magic bytes) — impede arquivo executável renomeado
        if (!upload_mime_valido($file['tmp_name'], $ext)) {
            session_flash('error', 'O conteúdo do arquivo não corresponde à extensão informada.');
            redirect('/fornecedores/' . $id);
        }

        // Build storage path
        $nomeArquivo = uuid() . '.' . $ext;
        $dirPath     = __DIR__ . '/../../storage/fornecedores/';
        $fullPath    = $dirPath . $nomeArquivo;

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            session_flash('error', 'Falha ao salvar o arquivo. Verifique permissões do diretório.');
            redirect('/fornecedores/' . $id);
        }

        $tipo = $this->input('tipo', 'outros');
        $db   = \App\Config\Database::getInstance();
        $db->prepare(
            "INSERT INTO documentos_fornecedor (id, fornecedor_id, tipo, nome_original, arquivo_path, tamanho_bytes, criado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([uuid(), $id, $tipo, $file['name'], $nomeArquivo, $file['size'], $user['id']]);

        (new AuditoriaService())->log($user['id'], 'fornecedor', $id, 'fornecedor.documento_adicionado',
            null, ['tipo' => $tipo, 'arquivo' => $file['name']]);

        session_flash('success', 'Documento enviado com sucesso.');
        redirect('/fornecedores/' . $id);
    }

    public function downloadDocumento(string $docId): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador', 'gestor', 'diretor', 'admin']);

        $db   = \App\Config\Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM documentos_fornecedor WHERE id = ?");
        $stmt->execute([$docId]);
        $doc  = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$doc) {
            session_flash('error', 'Documento não encontrado.');
            redirect('/fornecedores');
        }

        // basename() impede path traversal mesmo que arquivo_path seja adulterado no banco
        $fullPath = __DIR__ . '/../../storage/fornecedores/' . basename($doc['arquivo_path']);

        if (!file_exists($fullPath)) {
            session_flash('error', 'Arquivo não encontrado no servidor.');
            redirect('/fornecedores/' . $doc['fornecedor_id']);
        }

        $ext  = strtolower(pathinfo($doc['arquivo_path'], PATHINFO_EXTENSION));
        $mime = match($ext) {
            'pdf'        => 'application/pdf',
            'jpg','jpeg' => 'image/jpeg',
            'png'        => 'image/png',
            'doc'        => 'application/msword',
            'docx'       => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'        => 'application/vnd.ms-excel',
            'xlsx'       => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default      => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . addslashes($doc['nome_original']) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    public function excluirDocumento(string $docId): void
    {
        $this->requireAuth();
        $this->requirePerfil(['gestor', 'admin']);
        $user = auth();

        $db   = \App\Config\Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM documentos_fornecedor WHERE id = ?");
        $stmt->execute([$docId]);
        $doc  = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$doc) { redirect('/fornecedores'); }

        // Remove physical file
        $fullPath = __DIR__ . '/../../storage/fornecedores/' . $doc['arquivo_path'];
        if (file_exists($fullPath)) @unlink($fullPath);

        $db->prepare("DELETE FROM documentos_fornecedor WHERE id = ?")->execute([$docId]);

        (new AuditoriaService())->log($user['id'], 'fornecedor', $doc['fornecedor_id'], 'fornecedor.documento_removido',
            ['arquivo' => $doc['nome_original']], null);

        session_flash('success', 'Documento removido.');
        redirect('/fornecedores/' . $doc['fornecedor_id']);
    }

    public function adicionarCredito(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','admin']);
        $user = auth();
        $db   = \App\Config\Database::getInstance();

        $valor     = parse_decimal($this->input('valor', 0));
        $descricao = trim($this->input('descricao', ''));
        $validade  = $this->input('validade') ?: null;

        if ($valor <= 0 || strlen($descricao) < 3) {
            session_flash('error', 'Valor e descrição são obrigatórios.');
            redirect('/fornecedores/' . $id);
        }

        $db->prepare(
            "INSERT INTO creditos_fornecedor (id, fornecedor_id, valor, descricao, validade, criado_por)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([uuid(), $id, $valor, $descricao, $validade, $user['id']]);

        (new AuditoriaService())->log($user['id'], 'fornecedor', $id, 'credito.adicionado',
            null, ['valor' => $valor, 'descricao' => $descricao]);

        session_flash('success', 'Crédito de R$ ' . number_format($valor, 2, ',', '.') . ' adicionado.');
        redirect('/fornecedores/' . $id);
    }

    public function excluirCredito(string $creditoId): void
    {
        $this->requireAuth();
        $this->requirePerfil(['gestor','admin']);
        $user = auth();
        $db   = \App\Config\Database::getInstance();

        $stmt = $db->prepare("SELECT * FROM creditos_fornecedor WHERE id = ? AND usado = 0");
        $stmt->execute([$creditoId]);
        $cred = $stmt->fetch();

        if (!$cred) {
            session_flash('error', 'Crédito não encontrado ou já utilizado.');
            redirect('/fornecedores');
        }

        $db->prepare("DELETE FROM creditos_fornecedor WHERE id = ?")->execute([$creditoId]);

        (new AuditoriaService())->log($user['id'], 'fornecedor', $cred['fornecedor_id'], 'credito.excluido',
            ['valor' => $cred['valor']], null);

        session_flash('success', 'Crédito removido.');
        redirect('/fornecedores/' . $cred['fornecedor_id']);
    }

    // ── Importação por planilha (spec 2026-10-30) ─────────────────────────

    /** GET /fornecedores/importar — tela de upload. */
    public function importarForm(): void
    {
        $this->requirePerfil(['comprador','admin']);
        $this->view('fornecedores/importar', [
            'previa' => null, 'erro' => null, 'hoje' => date('Y-m-d'),
            'confirmacao' => date('Y-m-d'), 'modo' => 'atualizar',
        ]);
    }

    /** POST /fornecedores/importar — lê a planilha e mostra a prévia (nada é gravado). */
    public function importarPrevia(): void
    {
        $this->requirePerfil(['comprador','admin']);
        $hoje = date('Y-m-d');
        $modo = ($_POST['modo'] ?? '') === 'manter' ? 'manter' : 'atualizar';
        $conf = trim((string)($_POST['confirmacao'] ?? ''));
        $tela = ['previa' => null, 'erro' => null, 'hoje' => $hoje, 'confirmacao' => $conf ?: $hoje, 'modo' => $modo];
        unset($_SESSION[self::SESSAO_IMPORTACAO]);

        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $conf);
        if (!$d || $d->format('Y-m-d') !== $conf) {
            $tela['erro'] = 'Informe a data de confirmação do regime.';
        } elseif ($conf > $hoje) {
            $tela['erro'] = 'A confirmação do regime não pode ter data futura.';
        } else {
            $arq = $this->validarPlanilhaEnviada($_FILES['arquivo'] ?? null);
            if (is_string($arq)) {
                $tela['erro'] = $arq;
            } else {
                try {
                    $lido   = (new PlanilhaReader())->ler($arq['tmp_name'], $arq['name']);
                    $previa = ImportacaoFornecedoresService::analisar($lido['linhas'], [
                        'existentes'  => ImportacaoFornecedoresService::existentes(),
                        'modo'        => $modo,
                        'confirmacao' => $conf,
                        'hoje'        => $hoje,
                    ]);
                    $previa += [
                        'hash'      => hash_file('sha256', $arq['tmp_name']),
                        'arquivo'   => mb_substr(basename((string)$arq['name']), 0, 150),
                        'aba'       => $lido['aba'],
                        'modo'      => $modo,
                        'confirmacao' => $conf,
                        'usuario_id'  => auth()['id'],
                        'criada_em'   => time(),
                    ];
                    $_SESSION[self::SESSAO_IMPORTACAO] = $previa;
                    $tela['previa'] = $previa;
                } catch (\RuntimeException $e) {
                    $tela['erro'] = $e->getMessage();
                } catch (\Throwable $e) {
                    $tela['erro'] = 'Não foi possível ler a planilha: ' . $this->mensagemErro($e);
                }
            }
        }
        $this->view('fornecedores/importar', $tela);
    }

    /** POST /fornecedores/importar/confirmar — grava exatamente a prévia guardada na sessão. */
    public function importarConfirmar(): void
    {
        $this->requirePerfil(['comprador','admin']);
        $previa = $_SESSION[self::SESSAO_IMPORTACAO] ?? null;
        $hash   = (string)($_POST['hash'] ?? '');

        if (!is_array($previa) || $hash === '' || !hash_equals((string)$previa['hash'], $hash)
            || ($previa['usuario_id'] ?? null) !== auth()['id']
            || time() - (int)($previa['criada_em'] ?? 0) > self::IMPORTACAO_VALIDADE) {
            unset($_SESSION[self::SESSAO_IMPORTACAO]);
            session_flash('error', 'A prévia expirou ou não é a mesma planilha. Envie o arquivo de novo.');
            redirect('/fornecedores/importar');
        }
        unset($_SESSION[self::SESSAO_IMPORTACAO]);

        try {
            $r = ImportacaoFornecedoresService::aplicar($previa, auth());
        } catch (\Throwable $e) {
            session_flash('error', 'Importação não gravada (nada foi alterado): ' . $this->mensagemErro($e));
            redirect('/fornecedores/importar');
        }

        $erros = count($r['erros']) + (int)$previa['totais']['erro'];
        $msg = 'Importação concluída: ' . $r['criados'] . ' criado' . ($r['criados'] === 1 ? '' : 's') . ', '
             . $r['atualizados'] . ' atualizado' . ($r['atualizados'] === 1 ? '' : 's') . ', '
             . $erros . ' com erro.';
        if ($r['erros']) {
            $msg .= ' ' . implode(' ', array_slice($r['erros'], 0, 10));
        }
        session_flash($r['erros'] ? 'warning' : 'success', $msg);
        redirect('/fornecedores');
    }

    /** GET /fornecedores/importar/modelo — CSV ";" com BOM e o cabeçalho reconhecido. */
    public function modelo(): void
    {
        $this->requirePerfil(['comprador','admin']);
        $this->enviarCsv('modelo_importacao_fornecedores.csv', PlanilhaReader::cabecalhoModelo(), []);
        exit;
    }

    /**
     * Upload da planilha: até 2 MB, .xlsx ou .csv, MIME real conferido.
     *
     * @return array{tmp_name: string, name: string}|string arquivo ou mensagem de erro
     */
    private function validarPlanilhaEnviada(?array $f): array|string
    {
        if (!$f || !isset($f['error']) || is_array($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
            return 'Escolha a planilha (.xlsx ou .csv).';
        }
        if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || ($f['error'] === UPLOAD_ERR_OK && (int)$f['size'] > PlanilhaReader::MAX_ARQUIVO)) {
            return 'Arquivo maior que 2 MB.';
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return 'Erro no envio do arquivo. Tente de novo.';
        }
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if ($ext === 'xls') {
            return 'Arquivo .xls (Excel 97-2003) não é suportado: abra no Excel e use "Salvar como" → Pasta de Trabalho do Excel (.xlsx).';
        }
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            return 'Envie a planilha em .xlsx ou .csv.';
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
        $ok = $ext === 'xlsx'
            ? in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], true)
            : (str_starts_with($mime, 'text/') || in_array($mime, ['application/csv', 'application/vnd.ms-excel'], true));
        if (!$ok) {
            return 'O conteúdo do arquivo não corresponde à extensão .' . $ext . '.';
        }
        return ['tmp_name' => $f['tmp_name'], 'name' => (string)$f['name']];
    }

    /**
     * GET /api/cnpj/{cnpj} — consulta usada pelo botão "Buscar dados" do
     * formulário. Devolve os campos já mapeados; erro vem em 'erro' com
     * mensagem pronta para exibir, e a resposta é sempre HTTP 200 para o JS
     * poder tratar tudo num caminho só.
     */
    public function consultarCnpj(string $cnpj): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','diretor','admin']);

        $this->json((new \App\Services\CnpjService())->consultar($cnpj));
    }
}
