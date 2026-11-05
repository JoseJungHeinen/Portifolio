<?php
// @versao Ver.002.061026

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Fornecedor;
use App\Services\AuditoriaService;

class FornecedorController extends Controller
{
    public function index(): void
    {
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
        $busca  = trim($_GET['busca'] ?? '');
        $model  = new Fornecedor();
        $fornecedores  = $busca !== '' ? $model->buscar($busca) : $model->allOrdenados();
        $top10         = $model->topPorValor(10);
        $totalAtivos   = $model->contarPorStatus(1);
        $totalInativos = $model->contarPorStatus(0);
        $this->view('fornecedores/index', compact('fornecedores','top10','busca','totalAtivos','totalInativos'));
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

        $model->update($id, $data);
        (new AuditoriaService())->log($user['id'], 'fornecedor', $id, 'fornecedor.atualizado', $antes, $data);

        session_flash('success', 'Fornecedor atualizado.');
        redirect('/fornecedores');
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

    public function show(string $id): void
    {
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
        $model = new Fornecedor();
        $fornecedor = $model->find($id);
        
        if (!$fornecedor) { 
            redirect('/fornecedores'); 
        }

        $historico = $model->historicoCompras($id, 20);
        
        // Dados de avaliação
        $user = auth();
        $avaliacoes = $model->avaliacoes($id);
        $minhaAvaliacao = $model->jaAvaliou($id, $user['id']);
        $distribuicao = $model->distribuicaoNotas($id);
        $totalAvaliacoes = $model->totalAvaliacoes($id);
        $mediaAvaliacoes = $model->mediaAvaliacoes($id);
        
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
            'minhaAvaliacao',
            'distribuicao',
            'totalAvaliacoes',
            'mediaAvaliacoes',
            'documentos',
            'creditoUtilizado',
            'creditosFornecedor',
            'totalCreditosDisp'
        ));
    }

    public function avaliar(string $id): void
    {
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
        $user = auth();
        $model = new Fornecedor();
        
        // Validar nota
        $nota = (int)$this->input('nota');
        if ($nota < 1 || $nota > 5) {
            session_flash('error', 'Nota deve ser entre 1 e 5 estrelas.');
            redirect("/fornecedores/{$id}");
            return;
        }
        
        $comentario = trim($this->input('comentario', ''));
        
        // Verificar se já tinha avaliação
        $jaAvaliou = $model->jaAvaliou($id, $user['id']);
        
        // Salvar (retorna true se atualizou, false se criou)
        $foiAtualizacao = $model->salvarAvaliacao($id, $user['id'], $nota, $comentario);
        
        // Atualizar média no fornecedor
        $model->atualizarMediaAvaliacao($id);
        
        // Log de auditoria
        (new AuditoriaService())->log(
            $user['id'], 
            'fornecedor', 
            $id, 
            $foiAtualizacao ? 'avaliacao.atualizada' : 'avaliacao.criada',
            $jaAvaliou,
            ['nota' => $nota, 'comentario' => $comentario]
        );
        
        session_flash('success', $foiAtualizacao ? 'Sua avaliação foi atualizada!' : 'Avaliação registrada com sucesso!');
        redirect("/fornecedores/{$id}");
    }

    public function removerAvaliacao(string $id): void
    {
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
        $user = auth();
        $model = new Fornecedor();
        
        // Buscar antes para log
        $avaliacao = $model->jaAvaliou($id, $user['id']);
        
        // Remover
        $removido = $model->removerAvaliacaoUsuario($id, $user['id']);
        
        if (!$removido) {
            session_flash('error', 'Você não avaliou este fornecedor.');
            redirect("/fornecedores/{$id}");
            return;
        }
        
        // Atualizar média
        $model->atualizarMediaAvaliacao($id);
        
        // Log
        (new AuditoriaService())->log(
            $user['id'], 
            'fornecedor', 
            $id, 
            'avaliacao.removida',
            $avaliacao,
            null
        );
        
        session_flash('success', 'Avaliação removida.');
        redirect("/fornecedores/{$id}");
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
