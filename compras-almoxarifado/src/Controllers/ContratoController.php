<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\{Contrato, Fornecedor, Setor};
use App\Services\AuditoriaService;

class ContratoController extends Controller
{
    public function index(): void
    {
        $this->requirePerfil(['comprador','gestor','diretor','admin']);

        $model     = new Contrato();
        $model->atualizarStatusVencidos();
        $contratos = $model->allComFornecedor([
            'status'   => $_GET['status']   ?? 'ativo',
            'setor_id' => $_GET['setor_id'] ?? '',
        ]);
        $setores = (new Setor())->allAtivos();
        $vencendo  = $model->vencendoEmDias(30);
        $totalMensal = $model->totalMensalRecorrentes();

        $this->view('contratos/index', compact('contratos','vencendo','totalMensal','setores'));
    }

    public function form(?string $id = null): void
    {
        $this->requirePerfil(['comprador','admin']);
        $contrato     = $id ? (new Contrato())->find($id) : null;
        $fornecedores = (new Fornecedor())->allParaContratos();
        $setores      = (new Setor())->allAtivos();

        // Gera próximo número sugerido para novo contrato
        $proximoNumero = null;
        if (!$contrato) {
            $db  = \App\Config\Database::getInstance();
            $seq = $db->query(
                "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(numero, '-', -1) AS UNSIGNED)), 0)
                 FROM contratos WHERE numero LIKE 'CONT-%'"
            )->fetchColumn();
            $proximoNumero = 'CONT-' . str_pad((int)$seq + 1, 3, '0', STR_PAD_LEFT);
        }

        $this->view('contratos/form', compact('contrato','fornecedores','setores','proximoNumero'));
    }

    /**
     * Valida e move o arquivo de contrato enviado. Aplica allowlist de extensão,
     * limite de tamanho e checagem de erro de upload — impede que arquivos
     * executáveis (.php/.phar) sejam gravados em storage/contratos/.
     * Retorna o caminho relativo ('contratos/uuid.ext') ou null se nenhum arquivo.
     * Em caso de arquivo inválido, redireciona com mensagem de erro.
     */
    private function processarUploadContrato(): ?string
    {
        if (empty($_FILES['arquivo']['name'])) {
            return null;
        }

        $file = $_FILES['arquivo'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            session_flash('error', 'Erro no upload do arquivo.');
            redirect('/contratos');
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            session_flash('error', 'Arquivo muito grande. Máximo permitido: 10MB.');
            redirect('/contratos');
        }

        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
        if (!in_array($ext, $allowed, true)) {
            session_flash('error', 'Tipo de arquivo não permitido. Use: PDF, imagens, Word ou Excel.');
            redirect('/contratos');
        }

        // Valida o MIME real (magic bytes) — impede arquivo executável renomeado
        if (!upload_mime_valido($file['tmp_name'], $ext)) {
            session_flash('error', 'O conteúdo do arquivo não corresponde à extensão informada.');
            redirect('/contratos');
        }

        $nome    = uuid() . '.' . $ext;
        $dirPath = ROOT . '/storage/contratos/';
        if (!is_dir($dirPath)) {
            @mkdir($dirPath, 0755, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $dirPath . $nome)) {
            session_flash('error', 'Falha ao salvar o arquivo. Verifique permissões do diretório.');
            redirect('/contratos');
        }

        return 'contratos/' . $nome;
    }

    public function store(): void
    {
        $this->requirePerfil(['comprador','admin']);
        $user = auth();

        $semValor     = $this->input('sem_valor') === '1' ? 1 : 0;
        $isValorTotal = $semValor ? 0 : (int)($this->input('is_valor_total') === '1');
        $numParcelas  = $isValorTotal ? ((int)$this->input('num_parcelas', 1) ?: 1) : null;
        $valorTotal   = $semValor ? 0 : (float)str_replace(['.', ','], ['', '.'], $this->input('valor', '0'));
        $valorMensal  = $semValor ? 0 : (float)str_replace(['.', ','], ['', '.'], $this->input('valor_mensal', '0'));

        if (!$semValor && $isValorTotal) {
            $valorMensal = $numParcelas > 0 ? round($valorTotal / $numParcelas, 2) : $valorTotal;
        } elseif (!$semValor) {
            $valorTotal  = $valorMensal;
            $numParcelas = null;
        }

        $data = [
            'fornecedor_id'           => $this->input('fornecedor_id'),
            'numero'                  => $this->input('numero'),
            'objeto'                  => $this->input('objeto'),
            'sem_valor'               => $semValor,
            'valor'                   => $valorTotal,
            'is_valor_total'          => $isValorTotal,
            'num_parcelas'            => $numParcelas,
            'valor_mensal'            => $valorMensal,
            'data_inicio'             => $this->input('data_inicio'),
            'data_fim'                => $this->input('data_fim'),
            'reajuste_indexador'      => $this->input('reajuste_indexador'),
            'percentual_reajuste'     => (float)$this->input('percentual_reajuste', 0),
            'alerta_antecipacao_dias' => (int)$this->input('alerta_antecipacao_dias', 30),
            'recorrente'              => (int)$this->input('recorrente', 1),
            'categoria_dashboard'     => $this->input('categoria_dashboard', 'operacional'),
            'setor_id'                => $this->input('setor_id') ?: null,
            'status'                  => 'ativo',
        ];

        // Upload do arquivo do contrato (validado: allowlist, tamanho, erro)
        $caminhoArquivo = $this->processarUploadContrato();
        if ($caminhoArquivo !== null) {
            $data['arquivo_path'] = $caminhoArquivo;
        }

        $model = new Contrato();
        $id    = $model->create($data);
        (new AuditoriaService())->log($user['id'], 'contrato', $id, 'contrato.criado', null, $data);

        session_flash('success', 'Contrato cadastrado.');
        redirect('/contratos');
    }

    public function update(string $id): void
    {
        $this->requirePerfil(['comprador','admin']);
        $user  = auth();
        $model = new Contrato();
        $antes = $model->find($id);

        $semValor     = $this->input('sem_valor') === '1' ? 1 : 0;
        $isValorTotal = $semValor ? 0 : (int)($this->input('is_valor_total') === '1');
        $numParcelas  = $isValorTotal ? ((int)$this->input('num_parcelas', 1) ?: 1) : null;
        $valorTotal   = $semValor ? 0 : (float)str_replace(['.', ','], ['', '.'], $this->input('valor', '0'));
        $valorMensal  = $semValor ? 0 : (float)str_replace(['.', ','], ['', '.'], $this->input('valor_mensal', '0'));

        if (!$semValor && $isValorTotal) {
            $valorMensal = $numParcelas > 0 ? round($valorTotal / $numParcelas, 2) : $valorTotal;
        } elseif (!$semValor) {
            $valorTotal  = $valorMensal;
            $numParcelas = null;
        }

        $data = [
            'numero'               => $this->input('numero'),
            'objeto'               => $this->input('objeto'),
            'sem_valor'            => $semValor,
            'valor'                => $valorTotal,
            'is_valor_total'       => $isValorTotal,
            'num_parcelas'         => $numParcelas,
            'valor_mensal'         => $valorMensal,
            'data_inicio'          => $this->input('data_inicio'),
            'data_fim'             => $this->input('data_fim'),
            'reajuste_indexador'   => $this->input('reajuste_indexador'),
            'percentual_reajuste'  => (float)$this->input('percentual_reajuste', 0),
            'recorrente'           => (int)$this->input('recorrente', 1),
            'setor_id'             => $this->input('setor_id') ?: null,
            'status'               => $this->input('status', 'ativo'),
        ];

        // Upload do arquivo do contrato (validado: allowlist, tamanho, erro)
        $caminhoArquivo = $this->processarUploadContrato();
        if ($caminhoArquivo !== null) {
            $data['arquivo_path'] = $caminhoArquivo;
        }

        $model->update($id, $data);
        (new AuditoriaService())->log($user['id'], 'contrato', $id, 'contrato.atualizado', $antes, $data);

        session_flash('success', 'Contrato atualizado.');
        redirect('/contratos');
    }

    public function downloadArquivo(string $id): void
    {
        $this->requirePerfil(['comprador','gestor','diretor','admin']);

        $contrato = (new Contrato())->find($id);

        if (!$contrato || empty($contrato['arquivo_path'])) {
            session_flash('error', 'Arquivo não encontrado.');
            redirect('/contratos');
        }

        $fullPath = ROOT . '/storage/' . $contrato['arquivo_path'];

        if (!file_exists($fullPath)) {
            session_flash('error', 'Arquivo não encontrado no servidor.');
            redirect('/contratos');
        }

        $ext  = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mime = match($ext) {
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="contrato-' . addslashes($contrato['numero']) . '.' . $ext . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    public function uploadDocumento(string $id): void
    {
        $this->requirePerfil(['comprador', 'admin']);
        $user = auth();

        $contrato = (new Contrato())->find($id);
        if (!$contrato) {
            session_flash('error', 'Contrato não encontrado.');
            redirect('/contratos');
        }

        $file = $_FILES['arquivo'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            session_flash('error', $this->mensagemErroUpload($file['error'] ?? null));
            redirect('/contratos');
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            session_flash('error', 'Arquivo muito grande. Máximo permitido: 10MB.');
            redirect('/contratos');
        }

        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
        if (!in_array($ext, $allowed)) {
            session_flash('error', 'Tipo de arquivo não permitido. Use: PDF, imagens, Word ou Excel.');
            redirect('/contratos');
        }

        // Valida o MIME real (magic bytes) — impede arquivo executável renomeado
        if (!upload_mime_valido($file['tmp_name'], $ext)) {
            session_flash('error', 'O conteúdo do arquivo não corresponde à extensão informada.');
            redirect('/contratos');
        }

        $nomeArquivo = uuid() . '.' . $ext;
        $dirPath     = ROOT . '/storage/contratos/';
        if (!is_dir($dirPath)) {
            @mkdir($dirPath, 0755, true);
        }
        $fullPath    = $dirPath . $nomeArquivo;

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            session_flash('error', 'Falha ao salvar o arquivo. Verifique permissões do diretório.');
            redirect('/contratos');
        }

        $db = \App\Config\Database::getInstance();
        $db->prepare(
            "INSERT INTO documentos_contrato (id, contrato_id, nome_original, arquivo_path, tamanho_bytes, criado_por)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([uuid(), $id, $file['name'], $nomeArquivo, $file['size'], $user['id']]);

        (new AuditoriaService())->log($user['id'], 'contrato', $id, 'contrato.documento_adicionado',
            null, ['arquivo' => $file['name']]);

        session_flash('success', 'Anexo enviado com sucesso.');
        redirect('/contratos');
    }

    public function listarDocumentos(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador', 'gestor', 'diretor', 'admin']);

        $db   = \App\Config\Database::getInstance();
        $stmt = $db->prepare(
            "SELECT id, nome_original, tamanho_bytes, criado_em
             FROM documentos_contrato
             WHERE contrato_id = ?
             ORDER BY criado_em DESC"
        );
        $stmt->execute([$id]);
        $documentos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($documentos as &$doc) {
            $doc['url_download'] = url('contratos/documentos/' . $doc['id'] . '/download');
            $doc['data_br']      = date_br($doc['criado_em']);
        }

        $this->json(['documentos' => $documentos]);
    }

    public function downloadDocumento(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador', 'gestor', 'diretor', 'admin']);

        $db   = \App\Config\Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM documentos_contrato WHERE id = ?");
        $stmt->execute([$id]);
        $doc  = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$doc) {
            session_flash('error', 'Anexo não encontrado.');
            redirect('/contratos');
        }

        // basename() impede path traversal mesmo que arquivo_path seja adulterado no banco
        $fullPath = ROOT . '/storage/contratos/' . basename($doc['arquivo_path']);

        if (!file_exists($fullPath)) {
            session_flash('error', 'Arquivo não encontrado no servidor.');
            redirect('/contratos');
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

    public function excluirDocumento(string $id): void
    {
        $this->requirePerfil(['comprador', 'admin']);
        $user = auth();

        $db   = \App\Config\Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM documentos_contrato WHERE id = ?");
        $stmt->execute([$id]);
        $doc  = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$doc) { redirect('/contratos'); }

        $fullPath = ROOT . '/storage/contratos/' . $doc['arquivo_path'];
        if (file_exists($fullPath)) { @unlink($fullPath); }

        $db->prepare("DELETE FROM documentos_contrato WHERE id = ?")->execute([$id]);

        (new AuditoriaService())->log($user['id'], 'contrato', $doc['contrato_id'], 'contrato.documento_removido',
            ['arquivo' => $doc['nome_original']], null);

        session_flash('success', 'Anexo removido.');
        redirect('/contratos');
    }
}
