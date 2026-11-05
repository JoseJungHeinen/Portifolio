<?php
// @versao Ver.003.291026

namespace App\Controllers;

use App\Config\Database;
use App\Core\Controller;
use App\Services\AuditoriaService;

class AuditoriaEstoqueController extends Controller
{
    private function requireAcesso(): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
    }

    public function index(): void
    {
        $this->requireAcesso();
        $user = auth();
        $db   = Database::getInstance();

        $where  = ve_todas_filiais() ? '' : ' AND ae.filial_id = ?';
        $params = ve_todas_filiais() ? [] : [$user['filial_id']];

        $stmt = $db->prepare(
            "SELECT ae.*, f.nome AS filial_nome, u.nome AS criador_nome,
                    (SELECT COUNT(*) FROM auditoria_itens ai WHERE ai.auditoria_id = ae.id) AS total_itens,
                    (SELECT COUNT(*) FROM auditoria_itens ai WHERE ai.auditoria_id = ae.id AND ai.qtd_fisica IS NOT NULL) AS itens_conferidos
             FROM auditorias_estoque ae
             JOIN filiais f  ON f.id = ae.filial_id
             JOIN usuarios u ON u.id = ae.criado_por
             WHERE 1=1 $where
             ORDER BY ae.criado_em DESC
             LIMIT 100"
        );
        $stmt->execute($params);
        $auditorias = $stmt->fetchAll();

        $filiais = $db->query("SELECT id, nome FROM filiais WHERE ativo=1 ORDER BY nome")->fetchAll();

        $this->view('auditoria_estoque/index', compact('auditorias', 'filiais', 'user'));
    }

    public function nova(): void
    {
        $this->requireAcesso();
        $db = Database::getInstance();

        $filiais   = $db->query("SELECT id, nome FROM filiais WHERE ativo=1 ORDER BY nome")->fetchAll();
        $grupos    = $db->query("SELECT id, nome FROM grupos_produtos WHERE ativo=1 ORDER BY nome")->fetchAll();
        $categorias= $db->query("SELECT id, nome FROM categorias WHERE ativo=1 ORDER BY nome")->fetchAll();

        $this->view('auditoria_estoque/nova', compact('filiais', 'grupos', 'categorias'));
    }

    public function criar(): void
    {
        $this->requireAcesso();
        $user = auth();
        $db   = Database::getInstance();

        $filialId  = $this->input('filial_id');
        $escopo    = $this->input('escopo', 'todos');
        $escopoId  = $this->input('escopo_id') ?: null;
        $obs       = $this->input('observacao') ?: null;

        if (!$filialId) {
            session_flash('error', 'Selecione uma filial.');
            redirect('/auditoria-estoque/nova');
        }

        // Nome do escopo
        $escopoNome = null;
        if ($escopoId && $escopo === 'grupo') {
            $r = $db->prepare("SELECT nome FROM grupos_produtos WHERE id = ? LIMIT 1");
            $r->execute([$escopoId]);
            $escopoNome = $r->fetchColumn() ?: null;
        } elseif ($escopoId && $escopo === 'categoria') {
            $r = $db->prepare("SELECT nome FROM categorias WHERE id = ? LIMIT 1");
            $r->execute([$escopoId]);
            $escopoNome = $r->fetchColumn() ?: null;
        }

        // Busca itens e saldo atual na filial — só os que a unidade tem ou já teve
        // (linha de estoque na filial ou alguma movimentação nela)
        $sqlItens = "SELECT i.id AS item_id, COALESCE(e.quantidade, 0) AS qtd_sistema
                     FROM itens i
                     LEFT JOIN estoque e ON e.item_id = i.id AND e.filial_id = ?
                     WHERE i.ativo = 1
                       AND (e.id IS NOT NULL
                            OR EXISTS (SELECT 1 FROM movimentos_estoque m
                                       WHERE m.item_id = i.id AND m.filial_id = ?))";
        $paramsItens = [$filialId, $filialId];

        if ($escopo === 'grupo' && $escopoId) {
            $sqlItens .= " AND i.grupo_produto_id = ?";
            $paramsItens[] = $escopoId;
        } elseif ($escopo === 'categoria' && $escopoId) {
            $sqlItens .= " AND i.categoria_id = ?";
            $paramsItens[] = $escopoId;
        }
        $sqlItens .= " ORDER BY i.nome";

        $stmtItens = $db->prepare($sqlItens);
        $stmtItens->execute($paramsItens);
        $itens = $stmtItens->fetchAll();

        if (empty($itens)) {
            session_flash('error', 'Nenhum item encontrado para o escopo selecionado.');
            redirect('/auditoria-estoque/nova');
        }

        $db->beginTransaction();
        try {
            $audId = uuid();
            $db->prepare(
                "INSERT INTO auditorias_estoque (id, filial_id, escopo, escopo_id, escopo_nome, status, observacao, criado_por, criado_em)
                 VALUES (?, ?, ?, ?, ?, 'aberta', ?, ?, NOW())"
            )->execute([$audId, $filialId, $escopo, $escopoId, $escopoNome, $obs, $user['id']]);

            $ins = $db->prepare(
                "INSERT INTO auditoria_itens (id, auditoria_id, item_id, qtd_sistema) VALUES (?, ?, ?, ?)"
            );
            foreach ($itens as $it) {
                $ins->execute([uuid(), $audId, $it['item_id'], $it['qtd_sistema']]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao criar auditoria: ' . $this->mensagemErro($e));
            redirect('/auditoria-estoque/nova');
        }

        session_flash('success', 'Auditoria criada com ' . count($itens) . ' itens.');
        redirect('/auditoria-estoque/' . $audId);
    }

    public function show(string $id): void
    {
        $this->requireAcesso();
        $db   = Database::getInstance();
        $aud  = $this->buscarAuditoria($db, $id);

        $itens = $db->prepare(
            "SELECT ai.*, i.nome AS item_nome, i.unidade, i.codigo,
                    gp.nome AS grupo_nome
             FROM auditoria_itens ai
             JOIN itens i ON i.id = ai.item_id
             LEFT JOIN grupos_produtos gp ON gp.id = i.grupo_produto_id
             WHERE ai.auditoria_id = ?
             ORDER BY i.nome"
        );
        $itens->execute([$id]);
        $itens = $itens->fetchAll();

        $fotos = $this->buscarFotos($db, $id);

        $this->view('auditoria_estoque/show', compact('aud', 'itens', 'fotos'));
    }

    public function imprimir(string $id): void
    {
        $this->requireAcesso();
        $db   = Database::getInstance();
        $aud  = $this->buscarAuditoria($db, $id);

        $stmt = $db->prepare(
            "SELECT ai.*, i.nome AS item_nome, i.unidade, i.codigo,
                    gp.nome AS grupo_nome
             FROM auditoria_itens ai
             JOIN itens i ON i.id = ai.item_id
             LEFT JOIN grupos_produtos gp ON gp.id = i.grupo_produto_id
             WHERE ai.auditoria_id = ?
             ORDER BY i.nome"
        );
        $stmt->execute([$id]);
        $itens = $stmt->fetchAll();

        $this->view('auditoria_estoque/imprimir', compact('aud', 'itens'), '');
    }

    public function conferir(string $id): void
    {
        $this->requireAcesso();
        $db  = Database::getInstance();
        $aud = $this->buscarAuditoria($db, $id);

        if ($aud['status'] === 'finalizada') {
            session_flash('error', 'Esta auditoria já foi finalizada.');
            redirect('/auditoria-estoque/' . $id);
        }

        $qtds     = $_POST['qtd_fisica'] ?? [];
        $finalizar = isset($_POST['finalizar']);

        $db->beginTransaction();
        try {
            $upd = $db->prepare(
                "UPDATE auditoria_itens SET qtd_fisica = ? WHERE id = ? AND auditoria_id = ?"
            );
            foreach ($qtds as $itemId => $qtd) {
                $qtdVal = trim($qtd) === '' ? null : (float)str_replace(',', '.', $qtd);
                $upd->execute([$qtdVal, $itemId, $id]);
            }

            $novoStatus = $finalizar ? 'finalizada' : 'em_conferencia';
            $finEm      = $finalizar ? date('Y-m-d H:i:s') : null;
            $db->prepare(
                "UPDATE auditorias_estoque SET status = ?, finalizado_em = ? WHERE id = ?"
            )->execute([$novoStatus, $finEm, $id]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao salvar conferência.');
            redirect('/auditoria-estoque/' . $id);
        }

        $msg = $finalizar ? 'Auditoria finalizada.' : 'Contagens salvas.';
        session_flash('success', $msg);
        redirect($finalizar ? '/auditoria-estoque/' . $id . '/relatorio' : '/auditoria-estoque/' . $id);
    }

    public function aplicarAjustes(string $id): void
    {
        $this->requireAcesso();
        $user = auth();
        $db   = Database::getInstance();
        $aud  = $this->buscarAuditoria($db, $id);

        if ($aud['status'] !== 'finalizada') {
            session_flash('error', 'Finalize a auditoria antes de aplicar ajustes.');
            redirect('/auditoria-estoque/' . $id);
        }

        $qtdsEditadas = $_POST['qtd_fisica'] ?? [];

        $stmtItens = $db->prepare(
            "SELECT ai.id, ai.item_id, ai.qtd_sistema, ai.qtd_fisica, i.nome AS item_nome
             FROM auditoria_itens ai
             JOIN itens i ON i.id = ai.item_id
             WHERE ai.auditoria_id = ? AND ai.qtd_fisica IS NOT NULL AND ai.ajustado_em IS NULL
             ORDER BY ai.item_id"
        );
        $stmtItens->execute([$id]);
        $itens = $stmtItens->fetchAll();

        $aplicados = 0;

        $db->beginTransaction();
        try {
            $updQtd      = $db->prepare("UPDATE auditoria_itens SET qtd_fisica = ? WHERE id = ? AND ajustado_em IS NULL");
            $updAjustado = $db->prepare("UPDATE auditoria_itens SET ajustado_em = NOW(), ajustado_por = ? WHERE id = ? AND ajustado_em IS NULL");

            foreach ($itens as $it) {
                $qtdFisica = (float)$it['qtd_fisica'];
                if (array_key_exists($it['id'], $qtdsEditadas)) {
                    $raw = trim((string)$qtdsEditadas[$it['id']]);
                    if ($raw !== '') {
                        $novaQtd = (float)str_replace(',', '.', $raw);
                        if ($novaQtd !== $qtdFisica) {
                            $updQtd->execute([$novaQtd, $it['id']]);
                        }
                        $qtdFisica = $novaQtd;
                    }
                }

                $updAjustado->execute([$user['id'], $it['id']]);
                if ($updAjustado->rowCount() === 0) {
                    // Já foi marcado como ajustado por uma execução concorrente (duplo clique/retry) — pula este item.
                    continue;
                }

                $lockStmt = $db->prepare(
                    "SELECT COALESCE(quantidade, 0) FROM estoque WHERE item_id = ? AND filial_id = ? FOR UPDATE"
                );
                $lockStmt->execute([$it['item_id'], $aud['filial_id']]);
                $saldoAtual = (float)($lockStmt->fetchColumn() ?: 0);

                $delta = $qtdFisica - $saldoAtual;

                if ($delta > 0) {
                    $db->prepare(
                        "INSERT INTO estoque (id, item_id, filial_id, quantidade, atualizado_em)
                         VALUES (?, ?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), atualizado_em = NOW()"
                    )->execute([uuid(), $it['item_id'], $aud['filial_id'], $delta]);

                    $db->prepare(
                        "INSERT INTO movimentos_estoque
                         (id, item_id, filial_id, tipo, motivo, quantidade, referencia_tipo, referencia_id, usuario_id, criado_em)
                         VALUES (?, ?, ?, 'entrada', 'inventario', ?, 'auditoria', ?, ?, NOW())"
                    )->execute([uuid(), $it['item_id'], $aud['filial_id'], $delta, $id, $user['id']]);
                    (new \App\Models\Estoque())->espelharBrinde($it['item_id'], $aud['filial_id'], $delta, 'entrada', $user['id'], 'auditoria');
                } elseif ($delta < 0) {
                    $abs = abs($delta);
                    if ($saldoAtual < $abs) {
                        throw new \RuntimeException('Saldo insuficiente para ajustar "' . $it['item_nome'] . '". Saldo atual: ' . number_format($saldoAtual, 2, ',', '.'));
                    }
                    $db->prepare(
                        "UPDATE estoque SET quantidade = quantidade - ?, atualizado_em = NOW() WHERE item_id = ? AND filial_id = ?"
                    )->execute([$abs, $it['item_id'], $aud['filial_id']]);

                    $db->prepare(
                        "INSERT INTO movimentos_estoque
                         (id, item_id, filial_id, tipo, motivo, quantidade, referencia_tipo, referencia_id, usuario_id, criado_em)
                         VALUES (?, ?, ?, 'saida', 'inventario', ?, 'auditoria', ?, ?, NOW())"
                    )->execute([uuid(), $it['item_id'], $aud['filial_id'], $abs, $id, $user['id']]);
                    (new \App\Models\Estoque())->espelharBrinde($it['item_id'], $aud['filial_id'], $abs, 'saida', $user['id'], 'auditoria');
                }

                $aplicados++;
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao aplicar ajustes: ' . $this->mensagemErro($e));
            redirect('/auditoria-estoque/' . $id . '/relatorio');
        }

        if ($aplicados === 0) {
            session_flash('warning', 'Nenhum ajuste pendente para aplicar.');
        } else {
            (new AuditoriaService())->log($user['id'], 'auditoria_estoque', $id, 'auditoria.aplicar_ajustes',
                null, ['itens_ajustados' => $aplicados]);
            session_flash('success', $aplicados . ' item(ns) ajustado(s) no estoque.');
        }
        redirect('/auditoria-estoque/' . $id . '/relatorio');
    }

    public function relatorio(string $id): void
    {
        $this->requireAcesso();
        $db   = Database::getInstance();
        $aud  = $this->buscarAuditoria($db, $id);

        $stmt = $db->prepare(
            "SELECT ai.*, i.nome AS item_nome, i.unidade, i.codigo,
                    gp.nome AS grupo_nome, uaj.nome AS ajustado_por_nome,
                    (ai.qtd_fisica - ai.qtd_sistema) AS diferenca
             FROM auditoria_itens ai
             JOIN itens i ON i.id = ai.item_id
             LEFT JOIN grupos_produtos gp ON gp.id = i.grupo_produto_id
             LEFT JOIN usuarios uaj ON uaj.id = ai.ajustado_por
             WHERE ai.auditoria_id = ?
             ORDER BY ABS(COALESCE(ai.qtd_fisica,0) - ai.qtd_sistema) DESC, i.nome"
        );
        $stmt->execute([$id]);
        $itens = $stmt->fetchAll();

        $divergencias  = array_filter($itens, fn($it) => $it['qtd_fisica'] !== null && (float)$it['diferenca'] !== 0.0);
        $semContagem   = array_filter($itens, fn($it) => $it['qtd_fisica'] === null);
        $conformes     = array_filter($itens, fn($it) => $it['qtd_fisica'] !== null && (float)$it['diferenca'] === 0.0);
        $pendentes     = array_filter($divergencias, fn($it) => $it['ajustado_em'] === null);

        $this->view('auditoria_estoque/relatorio', compact('aud', 'itens', 'divergencias', 'semContagem', 'conformes', 'pendentes'));
    }

    public function excluir(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['diretor', 'admin']);
        $db  = Database::getInstance();
        $aud = $this->buscarAuditoria($db, $id);

        // Apaga arquivos físicos das fotos antes de remover os registros
        foreach ($this->buscarFotos($db, $id) as $foto) {
            $fullPath = ROOT . '/storage/auditoria_estoque/' . basename($foto['arquivo_path']);
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }

        // auditoria_itens e auditoria_fotos saem em cascata (ON DELETE CASCADE)
        $db->prepare("DELETE FROM auditorias_estoque WHERE id = ?")->execute([$id]);

        session_flash('success', 'Auditoria excluída.');
        redirect('/auditoria-estoque');
    }

    public function uploadFoto(string $id): void
    {
        $this->requireAcesso();
        $user = auth();
        $db   = Database::getInstance();
        $aud  = $this->buscarAuditoria($db, $id);

        if ($aud['status'] === 'finalizada') {
            session_flash('error', 'Auditoria finalizada não aceita novas fotos.');
            redirect('/auditoria-estoque/' . $id);
        }

        $file = $_FILES['foto'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            session_flash('error', 'Erro no upload da foto.');
            redirect('/auditoria-estoque/' . $id);
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            session_flash('error', 'Foto muito grande. Máximo permitido: 10MB.');
            redirect('/auditoria-estoque/' . $id);
        }

        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            session_flash('error', 'Formato não permitido. Use JPG, PNG ou WEBP.');
            redirect('/auditoria-estoque/' . $id);
        }

        if (!upload_mime_valido($file['tmp_name'], $ext)) {
            session_flash('error', 'O conteúdo do arquivo não corresponde a uma imagem.');
            redirect('/auditoria-estoque/' . $id);
        }

        $dirPath = ROOT . '/storage/auditoria_estoque/';
        if (!is_dir($dirPath)) {
            @mkdir($dirPath, 0775, true);
        }

        $nome = uuid() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dirPath . $nome)) {
            session_flash('error', 'Falha ao salvar a foto. Verifique permissões do diretório.');
            redirect('/auditoria-estoque/' . $id);
        }

        $legenda = trim((string)$this->input('legenda', '')) ?: null;

        $db->prepare(
            "INSERT INTO auditoria_fotos (id, auditoria_id, arquivo_path, nome_original, legenda, tamanho_bytes, criado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([uuid(), $id, $nome, $file['name'], $legenda, $file['size'], $user['id']]);

        session_flash('success', 'Foto adicionada.');
        redirect('/auditoria-estoque/' . $id);
    }

    public function verFoto(string $fotoId): void
    {
        $this->requireAcesso();
        $db   = Database::getInstance();

        $stmt = $db->prepare("SELECT * FROM auditoria_fotos WHERE id = ? LIMIT 1");
        $stmt->execute([$fotoId]);
        $foto = $stmt->fetch();

        $fullPath = $foto ? ROOT . '/storage/auditoria_estoque/' . basename($foto['arquivo_path']) : null;
        if (!$foto || !is_file($fullPath)) {
            http_response_code(404);
            echo 'Foto não encontrada.';
            return;
        }

        $ext  = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png'  => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($fullPath));
        header('Cache-Control: private, max-age=86400');
        readfile($fullPath);
    }

    public function excluirFoto(string $fotoId): void
    {
        $this->requireAcesso();
        $db   = Database::getInstance();

        $stmt = $db->prepare("SELECT * FROM auditoria_fotos WHERE id = ? LIMIT 1");
        $stmt->execute([$fotoId]);
        $foto = $stmt->fetch();
        if (!$foto) {
            redirect('/auditoria-estoque');
        }

        $aud = $this->buscarAuditoria($db, $foto['auditoria_id']);
        if ($aud['status'] === 'finalizada') {
            session_flash('error', 'Auditoria finalizada não permite excluir fotos.');
            redirect('/auditoria-estoque/' . $foto['auditoria_id']);
        }

        $fullPath = ROOT . '/storage/auditoria_estoque/' . basename($foto['arquivo_path']);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
        $db->prepare("DELETE FROM auditoria_fotos WHERE id = ?")->execute([$fotoId]);

        session_flash('success', 'Foto removida.');
        redirect('/auditoria-estoque/' . $foto['auditoria_id']);
    }

    private function buscarFotos(\PDO $db, string $auditoriaId): array
    {
        $stmt = $db->prepare(
            "SELECT * FROM auditoria_fotos WHERE auditoria_id = ? ORDER BY criado_em ASC"
        );
        $stmt->execute([$auditoriaId]);
        return $stmt->fetchAll();
    }

    private function buscarAuditoria(\PDO $db, string $id): array
    {
        $stmt = $db->prepare(
            "SELECT ae.*, f.nome AS filial_nome, u.nome AS criador_nome
             FROM auditorias_estoque ae
             JOIN filiais f  ON f.id = ae.filial_id
             JOIN usuarios u ON u.id = ae.criado_por
             WHERE ae.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $aud = $stmt->fetch();
        if (!$aud) redirect('/auditoria-estoque');
        return $aud;
    }
}
