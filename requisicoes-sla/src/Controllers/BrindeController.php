<?php
// @versao Ver.005.291026

namespace App\Controllers;

use App\Config\Database;
use App\Core\Controller;
use App\Models\Aprovacao;
use App\Services\BrindeSyncService;
use App\Services\EscopoGestorService;

class BrindeController extends Controller
{
    private function requireAcesso(): void
    {
        $this->requireAuth();
        $this->requirePerfil(['solicitante','almoxarife','comprador','gestor','diretor','admin']);
    }

    private function requireMatriz(): void
    {
        $this->requireAcesso();
        if (!is_matriz_user()) {
            session_flash('error', 'Apenas a matriz pode gerenciar brindes.');
            redirect('/brindes');
        }
    }

    /**
     * Unidades visíveis no módulo de brindes.
     *   null  → todas; []  → nenhuma; [ids] → escopo
     *
     * Gestor usa gestores_setor mesmo sendo usuário da matriz: o vínculo é a
     * fonte de verdade do escopo dele.
     *
     * @return array<int, string>|null
     */
    private function filiaisBrinde(): ?array
    {
        $user = auth();

        // "Acesso a todas as filiais" no cadastro vale antes do escopo do gestor.
        $comAcessoTodas = !can(['diretor', 'admin']) && ve_todas_filiais();
        if (can('gestor') && !$comAcessoTodas) {
            return (new EscopoGestorService())->filiaisDoUsuario($user['id']);
        }

        $podeVerTodas = $comAcessoTodas || (is_matriz_user() && ($user['perfil'] ?? '') !== 'solicitante');
        if ($podeVerTodas) {
            $selecionada = $_GET['filial_id'] ?? null;
            return $selecionada ? [$selecionada] : null;
        }

        return !empty($user['filial_id']) ? [$user['filial_id']] : [];
    }

    // ── Catálogo ────────────────────────────────────────────────────────────────
    public function index(): void
    {
        $this->requireAcesso();
        $user = auth();
        $db = Database::getInstance();

        $podeGerenciar       = is_matriz_user();
        $podeVerTodasFiliais = ($podeGerenciar && ($user['perfil'] ?? '') !== 'solicitante' && !can('gestor'))
            || ve_todas_filiais();
        $filiaisEscopo       = $this->filiaisBrinde();
        // View espera uma única filial (dropdown / badge). Com o escopo agora em
        // lista, só há um valor único a mostrar quando o escopo tem exatamente 1 id.
        $filtroFilial = ($filiaisEscopo !== null && count($filiaisEscopo) === 1) ? $filiaisEscopo[0] : null;

        (new BrindeSyncService())->sincronizarItensMkt($user['id']);

        // Saldo vem do estoque do item vinculado; brinde_estoque é o fallback
        // dos brindes legados sem vínculo.
        [$filtroEstoque, $paramsEstoque]     = sql_escopo_in('e.filial_id', $filiaisEscopo);
        [$filtroBrindeEst, $paramsBrindeEst] = sql_escopo_in('be.filial_id', $filiaisEscopo);
        $params = [...$paramsEstoque, ...$paramsBrindeEst];

        $stmt = $db->prepare(
            "SELECT b.id,
                    COALESCE(i.nome,   b.nome)   AS nome,
                    COALESCE(i.codigo, b.codigo) AS codigo,
                    COALESCE(i.marca,  b.marca)  AS marca,
                    COALESCE(i.valor_referencia, b.valor_referencia) AS valor_referencia,
                    COALESCE(
                        (SELECT SUM(e.quantidade)  FROM estoque e
                          WHERE e.item_id   = b.item_id {$filtroEstoque}),
                        (SELECT SUM(be.quantidade) FROM brinde_estoque be
                          WHERE be.brinde_id = b.id AND b.item_id IS NULL {$filtroBrindeEst}),
                        0
                    ) AS saldo_total
             FROM brindes b
             LEFT JOIN itens i ON i.id = b.item_id
             WHERE b.ativo = 1
             ORDER BY COALESCE(i.nome, b.nome)"
        );
        $stmt->execute($params);
        // Padrão da vitrine: só o que a filial (ou o escopo) tem para entregar.
        $filtroDisponib = in_array($_GET['disponibilidade'] ?? '', ['disponiveis', 'indisponiveis', 'todos'], true)
            ? $_GET['disponibilidade'] : 'disponiveis';
        $brindes = $stmt->fetchAll();
        if ($filtroDisponib !== 'todos') {
            $querDisponivel = $filtroDisponib === 'disponiveis';
            $brindes = array_values(array_filter($brindes, fn($b) => ((float)$b['saldo_total'] > 0) === $querDisponivel));
        }

        // Marcas oferecidas no filtro saem da disponibilidade escolhida, antes de aplicar busca/marca.
        $marcas = array_values(array_unique(array_filter(array_map(fn($b) => trim((string)$b['marca']), $brindes))));
        sort($marcas, SORT_NATURAL | SORT_FLAG_CASE);

        $busca       = trim($_GET['busca'] ?? '');
        $filtroMarca = trim($_GET['marca'] ?? '');
        if ($busca !== '') {
            $brindes = array_values(array_filter($brindes, fn($b) =>
                mb_stripos((string)$b['nome'], $busca) !== false || mb_stripos((string)$b['codigo'], $busca) !== false));
        }
        if ($filtroMarca !== '') {
            $brindes = array_values(array_filter($brindes, fn($b) => trim((string)$b['marca']) === $filtroMarca));
        }

        $capas = [];
        foreach ($db->query("SELECT brinde_id, MIN(id) AS foto_id FROM brinde_fotos GROUP BY brinde_id")->fetchAll() as $r) {
            $capas[$r['brinde_id']] = $r['foto_id'];
        }

        $filiais      = $db->query("SELECT id, nome, is_matriz FROM filiais WHERE ativo=1 ORDER BY nome")->fetchAll();
        $filialMatriz = (new \App\Models\Estoque())->getFilialMatriz();

        $this->view('brindes/index', compact('brindes', 'capas', 'podeGerenciar', 'podeVerTodasFiliais', 'filtroFilial', 'filiais', 'filialMatriz', 'marcas', 'busca', 'filtroMarca', 'filtroDisponib'));
    }

    public function show(string $id): void
    {
        $this->requireAcesso();
        $db = Database::getInstance();
        $brinde = $this->buscarBrindeComItem($db, $id);

        // Brinde vinculado: o estoque por filial é o do item. Sem vínculo,
        // mantém a leitura legada de brinde_estoque.
        if (!empty($brinde['item_id'])) {
            $estoque = $db->prepare(
                "SELECT e.quantidade, f.nome AS filial_nome
                 FROM estoque e JOIN filiais f ON f.id = e.filial_id
                 WHERE e.item_id = ? AND e.quantidade > 0 ORDER BY f.nome"
            );
            $estoque->execute([$brinde['item_id']]);
        } else {
            $estoque = $db->prepare(
                "SELECT be.quantidade, f.nome AS filial_nome
                 FROM brinde_estoque be JOIN filiais f ON f.id = be.filial_id
                 WHERE be.brinde_id = ? ORDER BY f.nome"
            );
            $estoque->execute([$id]);
        }
        $estoque = $estoque->fetchAll();

        $fotos = $this->buscarFotos($db, $id);
        $podeGerenciar = is_matriz_user();
        $podeVerEstoquePorLoja = ve_todas_filiais();
        $this->view('brindes/show', compact('brinde', 'estoque', 'fotos', 'podeGerenciar', 'podeVerEstoquePorLoja'));
    }

    // ── CRUD (matriz) ─────────────────────────────────────────────────────────────
    public function form(?string $id = null): void
    {
        $this->requireMatriz();
        $db = Database::getInstance();
        $brinde = $id ? $this->buscarBrinde($db, $id) : null;
        $itensGerais = $db->query("SELECT id, nome, codigo FROM itens WHERE ativo=1 ORDER BY nome")->fetchAll();
        $this->view('brindes/form', compact('brinde', 'itensGerais'));
    }

    public function salvar(?string $id = null): void
    {
        $this->requireMatriz();
        $user = auth();
        $db   = Database::getInstance();

        $nome = trim((string)$this->input('nome', ''));
        if ($nome === '') {
            session_flash('error', 'Informe o nome do brinde.');
            redirect($id ? "/brindes/$id/editar" : '/brindes/novo');
        }
        $descricao = $this->input('descricao') ?: null;
        $codigo    = $this->input('codigo') ?: null;
        $marca     = $this->input('marca') ?: null;
        $itemId    = $this->input('item_id') ?: null;
        $valorRef  = parse_decimal($this->input('valor_referencia') ?: '0');
        $ativo     = (int)$this->input('ativo', 0) ? 1 : 0;

        if ($id) {
            $this->buscarBrinde($db, $id);
            $db->prepare("UPDATE brindes SET nome=?, descricao=?, codigo=?, marca=?, item_id=?, valor_referencia=?, ativo=? WHERE id=?")
               ->execute([$nome, $descricao, $codigo, $marca, $itemId, $valorRef, $ativo, $id]);
        } else {
            $id = uuid();
            $db->prepare("INSERT INTO brindes (id,nome,descricao,codigo,marca,item_id,valor_referencia,ativo,criado_por) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$id, $nome, $descricao, $codigo, $marca, $itemId, $valorRef, $ativo, $user['id']]);
        }
        session_flash('success', 'Brinde salvo.');
        redirect('/brindes/' . $id);
    }

    public function excluir(string $id): void
    {
        $this->requireMatriz();
        $db = Database::getInstance();
        $this->buscarBrinde($db, $id);

        foreach ($this->buscarFotos($db, $id) as $f) {
            $p = ROOT . '/storage/brindes/' . basename($f['arquivo_path']);
            if (is_file($p)) @unlink($p);
        }
        // cascata remove brinde_estoque e brinde_fotos
        $db->prepare("DELETE FROM brindes WHERE id = ?")->execute([$id]);
        session_flash('success', 'Brinde excluído.');
        redirect('/brindes');
    }

    // ── Fotos ─────────────────────────────────────────────────────────────────────
    public function uploadFoto(string $id): void
    {
        $this->requireMatriz();
        $user = auth();
        $db   = Database::getInstance();
        $this->buscarBrinde($db, $id);

        $file = $_FILES['foto'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            session_flash('error', 'Erro no upload da foto.');
            redirect('/brindes/' . $id);
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            session_flash('error', 'Foto muito grande. Máximo permitido: 10MB.');
            redirect('/brindes/' . $id);
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) {
            session_flash('error', 'Formato não permitido. Use JPG, PNG ou WEBP.');
            redirect('/brindes/' . $id);
        }
        if (!upload_mime_valido($file['tmp_name'], $ext)) {
            $detect = @(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'desconhecido';
            session_flash('error', 'O arquivo não é uma imagem válida (detectado: ' . $detect . '). Use JPG, PNG ou WEBP — fotos de iPhone (HEIC) precisam ser convertidas.');
            redirect('/brindes/' . $id);
        }
        $dir = ROOT . '/storage/brindes/';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $nome = uuid() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . $nome)) {
            session_flash('error', 'Falha ao salvar a foto. Verifique permissões do diretório.');
            redirect('/brindes/' . $id);
        }
        $legenda = trim((string)$this->input('legenda', '')) ?: null;
        $db->prepare(
            "INSERT INTO brinde_fotos (id,brinde_id,arquivo_path,nome_original,legenda,tamanho_bytes,criado_por)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([uuid(), $id, $nome, $file['name'], $legenda, $file['size'], $user['id']]);

        session_flash('success', 'Foto adicionada.');
        redirect('/brindes/' . $id);
    }

    public function verFoto(string $fotoId): void
    {
        $this->requireAcesso();
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM brinde_fotos WHERE id = ? LIMIT 1");
        $stmt->execute([$fotoId]);
        $foto = $stmt->fetch();

        $full = $foto ? ROOT . '/storage/brindes/' . basename($foto['arquivo_path']) : null;
        if (!$foto || !is_file($full)) {
            http_response_code(404);
            echo 'Foto não encontrada.';
            return;
        }
        $ext  = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png'  => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($full));
        header('Cache-Control: private, max-age=86400');
        readfile($full);
    }

    public function excluirFoto(string $fotoId): void
    {
        $this->requireMatriz();
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM brinde_fotos WHERE id = ? LIMIT 1");
        $stmt->execute([$fotoId]);
        $foto = $stmt->fetch();
        if (!$foto) redirect('/brindes');

        $full = ROOT . '/storage/brindes/' . basename($foto['arquivo_path']);
        if (is_file($full)) @unlink($full);
        $db->prepare("DELETE FROM brinde_fotos WHERE id = ?")->execute([$fotoId]);

        session_flash('success', 'Foto removida.');
        redirect('/brindes/' . $foto['brinde_id']);
    }

    // ── Entrada de estoque (matriz) ────────────────────────────────────────────────
    public function entradaForm(string $id): void
    {
        $this->requireMatriz();
        $db = Database::getInstance();
        $brinde  = $this->buscarBrinde($db, $id);
        $filiais = $db->query("SELECT id, nome FROM filiais WHERE ativo=1 ORDER BY nome")->fetchAll();
        $this->view('brindes/entrada', compact('brinde', 'filiais'));
    }

    public function entrada(string $id): void
    {
        $this->requireMatriz();
        $user   = auth();
        $db     = Database::getInstance();
        $brinde = $this->buscarBrinde($db, $id);

        $filialId = $this->input('filial_id');
        $qtd      = parse_decimal($this->input('quantidade') ?: '0');
        if (!$filialId || $qtd <= 0) {
            session_flash('error', 'Informe filial e quantidade válida.');
            redirect('/brindes/' . $id . '/entrada');
        }

        $db->beginTransaction();
        try {
            if (!empty($brinde['item_id'])) {
                // Vinculado: o estoque do item é a fonte única de saldo.
                $db->prepare(
                    "INSERT INTO estoque (id, item_id, filial_id, quantidade, atualizado_em)
                     VALUES (?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), atualizado_em = NOW()"
                )->execute([uuid(), $brinde['item_id'], $filialId, $qtd]);

                $db->prepare(
                    "INSERT INTO movimentos_estoque
                     (id, item_id, filial_id, tipo, motivo, quantidade, referencia_tipo, referencia_id, usuario_id, criado_em)
                     VALUES (?, ?, ?, 'entrada', 'brinde', ?, 'brinde', ?, ?, NOW())"
                )->execute([uuid(), $brinde['item_id'], $filialId, $qtd, $id, $user['id']]);
            } else {
                // Legado sem vínculo: mantém o saldo próprio.
                $db->prepare(
                    "INSERT INTO brinde_estoque (id, brinde_id, filial_id, quantidade)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade)"
                )->execute([uuid(), $id, $filialId, $qtd]);
            }

            // Histórico do módulo de brindes, para os dois casos.
            $db->prepare(
                "INSERT INTO movimentos_brinde (id,brinde_id,filial_id,tipo,quantidade,referencia_tipo,criado_por)
                 VALUES (?,?,?,'entrada',?,'entrada_manual',?)"
            )->execute([uuid(), $id, $filialId, $qtd, $user['id']]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao registrar entrada.');
            redirect('/brindes/' . $id . '/entrada');
        }
        session_flash('success', 'Entrada de estoque registrada.');
        redirect('/brindes/' . $id);
    }

    // ── Requisição de saída (lojas) ────────────────────────────────────────────────
    public function novaRequisicao(): void
    {
        $this->requireAcesso();
        $user = auth();
        $db   = Database::getInstance();

        // A subquery derivada permite filtrar pelo alias `saldo` sem depender
        // do comportamento de HAVING sem GROUP BY.
        $brindes = $db->prepare(
            "SELECT * FROM (
                SELECT b.id,
                       COALESCE(i.nome, b.nome) AS nome,
                       COALESCE(i.valor_referencia, b.valor_referencia) AS valor_referencia,
                       COALESCE(
                           (SELECT SUM(e.quantidade)  FROM estoque e
                             WHERE e.item_id   = b.item_id AND e.filial_id  = ?),
                           (SELECT SUM(be.quantidade) FROM brinde_estoque be
                             WHERE be.brinde_id = b.id AND b.item_id IS NULL AND be.filial_id = ?),
                           0
                       ) AS saldo
                  FROM brindes b
                  LEFT JOIN itens i ON i.id = b.item_id
                 WHERE b.ativo = 1
             ) t
             WHERE t.saldo > 0
             ORDER BY t.nome"
        );
        $brindes->execute([$user['filial_id'], $user['filial_id']]);
        $brindes = $brindes->fetchAll();

        $stmt = $db->prepare("SELECT nome FROM filiais WHERE id = ? LIMIT 1");
        $stmt->execute([$user['filial_id']]);
        $lojaNome = $stmt->fetchColumn() ?: '';

        $old = flash('old_brinde_input') ?: [];

        $this->view('brindes/requisicao_nova', compact('brindes', 'lojaNome', 'old'));
    }

    public function criarRequisicao(): void
    {
        $this->requireAcesso();
        $user = auth();
        $db   = Database::getInstance();

        $cliente         = trim((string)$this->input('cliente_nome', ''));
        $vendedor        = trim((string)$this->input('vendedor_nome', ''));
        $numeroPedido    = trim((string)$this->input('numero_pedido_carro', '')) ?: null;
        $obs             = $this->input('observacao') ?: null;
        $brindeIds = $_POST['brinde_id'] ?? [];
        $qtds      = $_POST['quantidade'] ?? [];

        // Preserva o que já foi digitado — se cair em algum erro de validação
        // abaixo, o formulário reabre preenchido em vez de voltar em branco.
        $oldInput = [
            'cliente_nome'        => $cliente,
            'vendedor_nome'       => $vendedor,
            'numero_pedido_carro' => $numeroPedido,
            'observacao'          => $obs,
            'brinde_id'           => $brindeIds,
            'quantidade'          => $qtds,
        ];

        if ($cliente === '') {
            session_flash('error', 'Informe o nome do cliente.');
            session_flash('old_brinde_input', $oldInput);
            redirect('/brindes/requisicao/nova');
        }
        if ($vendedor === '') {
            session_flash('error', 'Informe o nome do vendedor.');
            session_flash('old_brinde_input', $oldInput);
            redirect('/brindes/requisicao/nova');
        }

        $anexoPath = $this->salvarAnexoAutorizacao($_FILES['autorizacao_anexo'] ?? []);
        if (!$anexoPath) {
            session_flash('error', 'Anexe a autorização do gerente (foto ou PDF) para liberação do brinde.');
            session_flash('old_brinde_input', $oldInput);
            redirect('/brindes/requisicao/nova');
        }

        $linhas = [];
        $valorTotal = 0.0;
        $valStmt = $db->prepare(
            "SELECT COALESCE(i.valor_referencia, b.valor_referencia)
             FROM brindes b
             LEFT JOIN itens i ON i.id = b.item_id
             WHERE b.id = ? AND b.ativo = 1 LIMIT 1"
        );
        foreach ($brindeIds as $i => $bid) {
            $q = parse_decimal($qtds[$i] ?? '0');
            if (!$bid || $q <= 0) continue;
            $valStmt->execute([$bid]);
            $vr = $valStmt->fetchColumn();
            if ($vr === false) continue; // brinde inválido/inativo
            $valorTotal += (float)$vr * $q;
            $linhas[] = [$bid, $q];
        }
        if (empty($linhas)) {
            session_flash('error', 'Adicione ao menos um brinde com quantidade.');
            session_flash('old_brinde_input', $oldInput);
            redirect('/brindes/requisicao/nova');
        }

        $reqId = uuid();
        $db->beginTransaction();
        try {
            $stmtNum = $db->query("SELECT COALESCE(MAX(numero), 0) + 1 FROM requisicoes_brinde FOR UPDATE");
            $proximoNumero = (int)$stmtNum->fetchColumn();

            $db->prepare(
                "INSERT INTO requisicoes_brinde (id,numero,filial_id,cliente_nome,vendedor_nome,numero_pedido_carro,observacao,autorizacao_anexo,status,valor_total,criado_por)
                 VALUES (?,?,?,?,?,?,?,?,'em_aprovacao',?,?)"
            )->execute([$reqId, $proximoNumero, $user['filial_id'], $cliente, $vendedor, $numeroPedido, $obs, $anexoPath, $valorTotal, $user['id']]);

            $ins = $db->prepare("INSERT INTO requisicao_brinde_itens (id,requisicao_brinde_id,brinde_id,quantidade) VALUES (?,?,?,?)");
            foreach ($linhas as [$bid, $q]) {
                $ins->execute([uuid(), $reqId, $bid, $q]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao criar requisição de brinde.');
            redirect('/brindes/requisicao/nova');
        }

        (new Aprovacao())->criarParaRequisicaoBrinde($reqId, $valorTotal);

        session_flash('success', 'Requisição de brinde enviada para aprovação.');
        redirect('/brindes/requisicoes');
    }

    public function requisicoes(): void
    {
        $this->requireAcesso();
        $user = auth();
        $db   = Database::getInstance();

        // filiaisBrinde() já aplica o ?filial_id= para quem vê todas as filiais.
        // Gestor tem escopo em lista: o filtro só estreita dentro dele.
        $escopo       = $this->filiaisBrinde();
        $filtroFilial = trim($_GET['filial_id'] ?? '');
        $todasFiliais = $db->query("SELECT id, nome FROM filiais WHERE ativo=1 ORDER BY nome")->fetchAll();
        if (is_matriz_user() && ($user['perfil'] ?? '') !== 'solicitante' && !can('gestor')) {
            $filiaisFiltro = $todasFiliais;
        } else {
            $filiaisFiltro = array_values(array_filter($todasFiliais, fn($f) => in_array($f['id'], $escopo ?? [], true)));
            if ($filtroFilial !== '' && in_array($filtroFilial, $escopo ?? [], true)) {
                $escopo = [$filtroFilial];
            }
        }
        if (count($filiaisFiltro) < 2) {
            $filiaisFiltro = [];
        }

        // "WHERE 1=1" absorve o AND devolvido por sql_escopo_in sem mudar o resultado
        [$filialCond, $params] = sql_escopo_in('rb.filial_id', $escopo);
        $where = 'WHERE 1=1' . $filialCond;

        $filtros = [
            'busca'     => trim($_GET['busca'] ?? ''),
            'status'    => trim($_GET['status'] ?? ''),
            'data_de'   => trim($_GET['data_de'] ?? ''),
            'data_ate'  => trim($_GET['data_ate'] ?? ''),
            'filial_id' => $filtroFilial,
        ];
        if ($filtros['busca'] !== '') {
            $like   = '%' . $filtros['busca'] . '%';
            $where .= ' AND (rb.cliente_nome LIKE ? OR rb.vendedor_nome LIKE ? OR rb.numero_pedido_carro LIKE ? OR u.nome LIKE ?';
            array_push($params, $like, $like, $like, $like);
            // "ABC1D23-00012" ou só "12": compara o número sequencial
            if (preg_match('/(\d+)\s*$/', $filtros['busca'], $m)) {
                $where   .= ' OR rb.numero = ?';
                $params[] = (int)$m[1];
            }
            $where .= ')';
        }
        if (in_array($filtros['status'], ['em_aprovacao', 'aprovado', 'rejeitado', 'cancelado'], true)) {
            $where   .= ' AND rb.status = ?';
            $params[] = $filtros['status'];
        } else {
            $filtros['status'] = '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtros['data_de'])) {
            $where   .= ' AND rb.criado_em >= ?';
            $params[] = $filtros['data_de'] . ' 00:00:00';
        } else {
            $filtros['data_de'] = '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtros['data_ate'])) {
            $where   .= ' AND rb.criado_em <= ?';
            $params[] = $filtros['data_ate'] . ' 23:59:59';
        } else {
            $filtros['data_ate'] = '';
        }

        $stmt = $db->prepare(
            "SELECT rb.*, f.nome AS filial_nome, u.nome AS criador_nome,
                    (SELECT COUNT(*) FROM requisicao_brinde_itens ri WHERE ri.requisicao_brinde_id = rb.id) AS total_itens
             FROM requisicoes_brinde rb
             JOIN filiais f  ON f.id = rb.filial_id
             JOIN usuarios u ON u.id = rb.criado_por
             $where
             ORDER BY rb.criado_em DESC
             LIMIT 200"
        );
        $stmt->execute($params);
        $requisicoes = $stmt->fetchAll();

        $temFiltro = $filtros['busca'] !== '' || $filtros['status'] !== '' || $filtros['data_de'] !== ''
                  || $filtros['data_ate'] !== '' || $filtros['filial_id'] !== '';

        $this->view('brindes/requisicoes', compact('requisicoes', 'filtros', 'filiaisFiltro', 'temFiltro'));
    }

    public function showRequisicao(string $id): void
    {
        $this->requireAcesso();
        $user = auth();
        $db   = Database::getInstance();

        $reqStmt = $db->prepare(
            "SELECT rb.*, f.nome AS filial_nome, u.nome AS criador_nome
             FROM requisicoes_brinde rb
             JOIN filiais f  ON f.id = rb.filial_id
             JOIN usuarios u ON u.id = rb.criado_por
             WHERE rb.id = ? LIMIT 1"
        );
        $reqStmt->execute([$id]);
        $req = $reqStmt->fetch();
        if (!$req) {
            session_flash('error', 'Requisição de brinde não encontrada.');
            redirect('/brindes/requisicoes');
        }

        // Escopo de filial: solicitante só vê a própria filial (mesmo critério de requisicoes()).
        $podeVerTodasFiliais = (is_matriz_user() && ($user['perfil'] ?? '') !== 'solicitante') || ve_todas_filiais();
        if (!$podeVerTodasFiliais && $req['filial_id'] !== $user['filial_id']) {
            session_flash('error', 'Acesso negado.');
            redirect('/brindes/requisicoes');
        }

        $itensStmt = $db->prepare(
            "SELECT rbi.quantidade,
                    COALESCE(i.nome,   b.nome)   AS brinde_nome,
                    COALESCE(i.codigo, b.codigo) AS brinde_codigo,
                    COALESCE(i.valor_referencia, b.valor_referencia) AS valor_referencia
             FROM requisicao_brinde_itens rbi
             JOIN brindes b ON b.id = rbi.brinde_id
             LEFT JOIN itens i ON i.id = b.item_id
             WHERE rbi.requisicao_brinde_id = ?"
        );
        $itensStmt->execute([$id]);
        $itens = $itensStmt->fetchAll();

        $this->view('brindes/requisicao_show', compact('req', 'itens'));
    }

    // ── Helpers privados ────────────────────────────────────────────────────────────
    private function salvarAnexoAutorizacao(array $arquivo): ?string
    {
        $erroUpload = $arquivo['error'] ?? UPLOAD_ERR_NO_FILE;
        if (empty($arquivo['tmp_name']) || $erroUpload !== UPLOAD_ERR_OK) {
            if ($erroUpload !== UPLOAD_ERR_NO_FILE) {
                error_log('[BrindeController] Upload do anexo de autorização falhou com código UPLOAD_ERR=' . $erroUpload);
            }
            return null;
        }
        $ext     = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            error_log('[BrindeController] Anexo de autorização rejeitado: extensão não permitida (' . $ext . ')');
            return null;
        }

        // Valida o MIME real (magic bytes) — impede arquivo executável renomeado
        if (!upload_mime_valido($arquivo['tmp_name'], $ext)) {
            $detect = @(new \finfo(FILEINFO_MIME_TYPE))->file($arquivo['tmp_name']) ?: 'desconhecido';
            error_log('[BrindeController] Anexo de autorização rejeitado: MIME real não corresponde à extensão .' . $ext . ' (detectado: ' . $detect . ')');
            return null;
        }

        $dir = ROOT . '/uploads/brindes/';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[BrindeController] Não foi possível criar o diretório de upload: ' . $dir);
            return null;
        }
        $nome = uuid() . '.' . $ext;
        if (move_uploaded_file($arquivo['tmp_name'], $dir . $nome)) {
            return 'uploads/brindes/' . $nome;
        }
        error_log('[BrindeController] Falha ao mover o anexo de autorização para ' . $dir . $nome);
        return null;
    }

    private function buscarBrinde(\PDO $db, string $id): array
    {
        $stmt = $db->prepare("SELECT * FROM brindes WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $brinde = $stmt->fetch();
        if (!$brinde) redirect('/brindes');
        return $brinde;
    }

    /**
     * Mesma busca de buscarBrinde(), porém com nome, código, marca e valor já
     * resolvidos a partir do item vinculado. Para telas de exibição — o CRUD
     * continua usando buscarBrinde() com os dados crus do brinde.
     */
    private function buscarBrindeComItem(\PDO $db, string $id): array
    {
        $stmt = $db->prepare(
            "SELECT b.*,
                    COALESCE(i.nome,   b.nome)   AS nome,
                    COALESCE(i.codigo, b.codigo) AS codigo,
                    COALESCE(i.marca,  b.marca)  AS marca,
                    COALESCE(i.valor_referencia, b.valor_referencia) AS valor_referencia
             FROM brindes b
             LEFT JOIN itens i ON i.id = b.item_id
             WHERE b.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $brinde = $stmt->fetch();
        if (!$brinde) redirect('/brindes');
        return $brinde;
    }

    private function buscarFotos(\PDO $db, string $brindeId): array
    {
        $stmt = $db->prepare("SELECT * FROM brinde_fotos WHERE brinde_id = ? ORDER BY criado_em ASC");
        $stmt->execute([$brindeId]);
        return $stmt->fetchAll();
    }
}
