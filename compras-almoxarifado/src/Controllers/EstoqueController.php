<?php
// @versao Ver.002.291026

namespace App\Controllers;

use App\Core\Controller;
use App\Config\Database;
use App\Models\{Item, Filial, Estoque};
use App\Services\AuditoriaService;

class EstoqueController extends Controller
{
    // estoque schema: id, item_id, filial_id, quantidade, localizacao, qr_code, atualizado_em
    // movimentos_estoque: id, item_id, filial_id, tipo, quantidade, referencia_tipo, referencia_id, usuario_id, criado_em

    public function index(): void
    {
        $this->requireAuth();
        $user     = auth();
        $db       = Database::getInstance();

        if (ve_todas_filiais()) {
            if (isset($_GET['filial_id'])) {
                $filtroFilial = $_GET['filial_id'] ?: null;
                session_set('filtro_filial_estoque', $filtroFilial);
            } else {
                $filtroFilial = session_get('filtro_filial_estoque', null);
            }
        } else {
            $filtroFilial = $user['filial_id'];
        }
        $filtroSetor  = $_GET['setor_id'] ?? null;
        $filtroGrupo  = $_GET['grupo_id'] ?? null;
        $filtroStatus = $_GET['status'] ?? null;
        $page         = max(1, (int)($_GET['page'] ?? 1));
        $perPage      = in_array((int)($_GET['per_page'] ?? 50), [25, 50, 100]) ? (int)($_GET['per_page'] ?? 50) : 50;
        $offset       = ($page - 1) * $perPage;

        $where  = "WHERE 1=1";
        $params = [];

        $colCodigo = trim($_GET['col_codigo'] ?? '');
        $colItem   = trim($_GET['col_item']   ?? '');

        // Ordenação dinâmica
        $sortMap = [
            'codigo'      => 'i.codigo',
            'item'        => 'i.nome',
            'filial'      => 'f.nome',
            'quantidade'  => 'e.quantidade',
            'minimo'      => 'e.estoque_minimo',
            'atualizado'  => 'e.atualizado_em',
            'valor_unit'  => 'valor_unitario',
            'valor_total' => '(e.quantidade * valor_unitario)',
        ];
        $sortCol = $_GET['sort'] ?? null;
        $sortDir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $orderBy = isset($sortMap[$sortCol])
            ? $sortMap[$sortCol] . ' ' . $sortDir
            : 'f.is_matriz DESC, abaixo_minimo DESC, i.nome ASC';

        if ($filtroFilial) {
            $where .= " AND e.filial_id = ?";
            $params[] = $filtroFilial;
        }
        if (!empty($_GET['busca'])) {
            $where .= " AND (i.nome LIKE ? OR i.codigo LIKE ?)";
            $params[] = '%' . $_GET['busca'] . '%';
            $params[] = '%' . $_GET['busca'] . '%';
        }
        if ($colCodigo !== '') {
            $where .= " AND i.codigo LIKE ?";
            $params[] = '%' . $colCodigo . '%';
        }
        if ($colItem !== '') {
            $where .= " AND i.nome LIKE ?";
            $params[] = '%' . $colItem . '%';
        }
        if ($filtroSetor) {
            $where .= " AND EXISTS (SELECT 1 FROM itens_setores iset WHERE iset.item_id = e.item_id AND iset.setor_id = ?)";
            $params[] = $filtroSetor;
        }
        if ($filtroGrupo) {
            $where .= " AND i.grupo_produto_id = ?";
            $params[] = $filtroGrupo;
        }
        if ($filtroStatus === 'abaixo') {
            $where .= " AND e.quantidade < e.estoque_minimo AND e.estoque_minimo > 0";
        } elseif ($filtroStatus === 'zerado') {
            $where .= " AND e.quantidade <= 0";
        } elseif ($filtroStatus === 'ok') {
            $where .= " AND (e.quantidade >= e.estoque_minimo OR e.estoque_minimo = 0)";
        }

        $baseFrom = "FROM estoque e
                JOIN itens i   ON i.id = e.item_id
                JOIN filiais f ON f.id = e.filial_id
                $where";

        // Total para paginação
        $stmtTotal = $db->prepare("SELECT COUNT(*) $baseFrom");
        $stmtTotal->execute($params);
        $totalRegistros = (int)$stmtTotal->fetchColumn();
        $totalPaginas   = max(1, (int)ceil($totalRegistros / $perPage));
        $page           = min($page, $totalPaginas);
        $offset         = ($page - 1) * $perPage;

        $sql = "SELECT e.*, i.nome AS item_nome, i.codigo, i.unidade,
                       i.grupo_produto_id,
                       f.nome AS filial_nome,
                       CASE WHEN e.quantidade < e.estoque_minimo AND e.estoque_minimo > 0 THEN 1 ELSE 0 END AS abaixo_minimo,
                       COALESCE(
                           e.valor_unitario,
                           (SELECT hp.valor_unitario FROM historico_precos hp
                            WHERE hp.item_id = e.item_id ORDER BY hp.registrado_em DESC LIMIT 1),
                           i.valor_referencia, 0) AS valor_unitario
                $baseFrom
                ORDER BY $orderBy
                LIMIT ? OFFSET ?";

        $stmtEstoque = $db->prepare($sql);
        $stmtEstoque->execute(array_merge($params, [$perPage, $offset]));
        $estoque = $stmtEstoque->fetchAll();

        // Dataset completo (sem paginação/filtros de busca) para o modal de transferência
        $whereTransfer  = "WHERE 1=1";
        $paramsTransfer = [];
        if (!ve_todas_filiais()) {
            $whereTransfer .= " AND e.filial_id = ?";
            $paramsTransfer[] = $user['filial_id'];
        }
        $stmtTransfer = $db->prepare(
            "SELECT e.item_id, e.filial_id, e.quantidade, i.nome AS item_nome, i.unidade
             FROM estoque e
             JOIN itens i ON i.id = e.item_id
             $whereTransfer
             ORDER BY i.nome"
        );
        $stmtTransfer->execute($paramsTransfer);
        $estoqueTransferencia = $stmtTransfer->fetchAll();

        $paginacao = [
            'pagina_atual'    => $page,
            'total_paginas'   => $totalPaginas,
            'total_registros' => $totalRegistros,
            'per_page'        => $perPage,
            'inicio'          => $totalRegistros > 0 ? $offset + 1 : 0,
            'fim'             => min($offset + $perPage, $totalRegistros),
        ];

        $filiais = (new Filial())->all(['ativo' => 1], 'nome');
        $filialMatriz = (new Estoque())->getFilialMatriz();
        $itens   = (new Item())->all(['ativo' => 1], 'nome');
        $setores = (new \App\Models\Setor())->allAtivos();
        $grupos  = $db->query(
            "SELECT id, nome FROM grupos_produtos WHERE ativo = 1 ORDER BY nome"
        )->fetchAll();

        // ── KPIs ──────────────────────────────────────────────────────────
        $kpiWhere  = $filtroFilial ? "AND e.filial_id = ?" : "";
        $kpiParams = $filtroFilial ? [$filtroFilial] : [];

        $kpis = $db->prepare(
            "SELECT
                COUNT(*)                                                                          AS total_registros,
                COUNT(DISTINCT e.item_id)                                                         AS total_itens,
                SUM(CASE WHEN e.quantidade  > 0  THEN 1 ELSE 0 END)                              AS com_estoque,
                SUM(CASE WHEN e.quantidade <= 0  THEN 1 ELSE 0 END)                              AS sem_estoque,
                SUM(CASE WHEN e.quantidade < e.estoque_minimo AND e.estoque_minimo > 0 THEN 1 ELSE 0 END) AS abaixo_minimo,
                SUM(e.quantidade)                                                                 AS total_disponivel,
                SUM(e.quantidade * COALESCE(
                    e.valor_unitario,
                    (SELECT hp.valor_unitario FROM historico_precos hp
                     WHERE hp.item_id = e.item_id
                     ORDER BY hp.registrado_em DESC LIMIT 1), i.valor_referencia, 0))             AS valor_estimado
             FROM estoque e
             JOIN itens i ON i.id = e.item_id
             WHERE 1=1 $kpiWhere"
        );
        $kpis->execute($kpiParams);
        $kpis = $kpis->fetch();

        // ── Por Grupo ─────────────────────────────────────────────────────
        $porGrupo = $db->prepare(
            "SELECT
                COALESCE(gp.nome, 'Sem grupo')                                                    AS grupo,
                COUNT(DISTINCT e.item_id)                                                         AS total_itens,
                SUM(e.quantidade)                                                                 AS quantidade_total,
                SUM(CASE WHEN e.quantidade <= 0 THEN 1 ELSE 0 END)                               AS sem_estoque,
                SUM(CASE WHEN e.quantidade < e.estoque_minimo AND e.estoque_minimo > 0 THEN 1 ELSE 0 END) AS abaixo_minimo,
                SUM(e.quantidade * COALESCE(
                    e.valor_unitario,
                    (SELECT hp.valor_unitario FROM historico_precos hp
                     WHERE hp.item_id = e.item_id
                     ORDER BY hp.registrado_em DESC LIMIT 1), i.valor_referencia, 0))             AS valor_estimado
             FROM estoque e
             JOIN itens i ON i.id = e.item_id
             JOIN filiais f ON f.id = e.filial_id
             LEFT JOIN grupos_produtos gp ON gp.id = i.grupo_produto_id
             $where
             GROUP BY gp.id, gp.nome
             ORDER BY valor_estimado DESC"
        );
        $porGrupo->execute($params);
        $porGrupo = $porGrupo->fetchAll();

        $this->view('estoque/index', compact(
            'estoque', 'estoqueTransferencia', 'filiais', 'itens', 'user',
            'filtroFilial', 'filtroSetor', 'filtroGrupo', 'filtroStatus',
            'setores', 'grupos', 'kpis', 'porGrupo', 'paginacao',
            'colCodigo', 'colItem', 'sortCol', 'sortDir', 'filialMatriz'
        ));
    }

    public function formEntrada(): void
    {
        $this->requireAuth();
        $this->requirePerfil(['admin']);
        $itens   = (new Item())->allComGrupo(['ativo' => 1]);
        $filiais = (new Filial())->all(['ativo' => 1], 'nome');
        $db      = Database::getInstance();
        $grupos  = $db->query(
            "SELECT id, nome FROM grupos_produtos WHERE ativo = 1 ORDER BY nome"
        )->fetchAll();

        // Mapa de estoque atual: [filial_id][item_id] => quantidade
        $estoqueRows = $db->query("SELECT filial_id, item_id, quantidade FROM estoque")->fetchAll();
        $estoqueMap  = [];
        foreach ($estoqueRows as $row) {
            $estoqueMap[$row['filial_id']][$row['item_id']] = (float)$row['quantidade'];
        }

        $this->view('estoque/entrada', compact('itens', 'filiais', 'grupos', 'estoqueMap'));
    }

    public function entrada(): void
    {
        $this->requireAuth();
        $this->requirePerfil(['admin']);
        $user          = auth();
        $db            = Database::getInstance();
        $filialId      = $this->input('filial_id');
        $motivo        = $this->input('motivo') ?: 'compra';
        $justificativa = trim($this->input('justificativa', ''));

        if (!$filialId) {
            session_flash('error', 'Selecione a filial.');
            redirect('/estoque/entrada');
        }

        $itemIds    = $_POST['item_id']    ?? [];
        $quantidades = $_POST['quantidade'] ?? [];

        if (empty($itemIds)) {
            session_flash('error', 'Adicione ao menos um item.');
            redirect('/estoque/entrada');
        }

        $motivosComJustificativa = ['inventario', 'correcao', 'outro'];
        if (in_array($motivo, $motivosComJustificativa) && strlen($justificativa) < 5) {
            session_flash('error', 'Justificativa obrigatória para o motivo selecionado (mínimo 5 caracteres).');
            redirect('/estoque/entrada');
        }

        $db->beginTransaction();
        try {
            $totalItens = 0;
            foreach ($itemIds as $idx => $itemId) {
                if (empty($itemId)) continue;
                $qtd = parse_decimal($quantidades[$idx] ?? 0);
                if ($qtd <= 0) continue;

                $db->prepare(
                    "INSERT INTO estoque (id, item_id, filial_id, quantidade, atualizado_em)
                     VALUES (?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                       quantidade    = quantidade + VALUES(quantidade),
                       atualizado_em = NOW()"
                )->execute([uuid(), $itemId, $filialId, $qtd]);

                $db->prepare(
                    "INSERT INTO movimentos_estoque
                     (id, item_id, filial_id, tipo, motivo, justificativa, quantidade, referencia_tipo, usuario_id, criado_em)
                     VALUES (?, ?, ?, 'entrada', ?, ?, ?, ?, ?, NOW())"
                )->execute([uuid(), $itemId, $filialId, $motivo, $justificativa ?: null, $qtd, $motivo, $user['id']]);

                (new Estoque())->espelharBrinde($itemId, $filialId, $qtd, 'entrada', $user['id']);

                (new AuditoriaService())->log($user['id'], 'estoque', $itemId, 'estoque.entrada',
                    null, ['quantidade' => $qtd, 'filial_id' => $filialId]);

                $totalItens++;
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao registrar entrada: ' . $this->mensagemErro($e));
            redirect('/estoque/entrada');
        }

        session_flash('success', $totalItens . ' item(ns) registrado(s) com entrada no estoque.');
        redirect('/estoque');
    }

    public function saida(): void
    {
        $this->requireAuth();
        $this->requirePerfil(['admin']);
        $user          = auth();
        $db            = Database::getInstance();
        $filialId      = $this->input('filial_id');
        $justificativa = trim($this->input('justificativa', ''));

        if (!$filialId) {
            session_flash('error', 'Selecione a filial.');
            redirect('/estoque');
        }

        $motivosValidos = ['compra','inventario','perda','avaria','correcao','transferencia','requisicao','outro'];
        $motivo = $this->input('motivo', 'outro');
        if (!in_array($motivo, $motivosValidos, true)) $motivo = 'outro';

        // Se for requisição interna, prefixar a justificativa com o número
        $numReq = trim($this->input('num_requisicao', ''));
        if ($motivo === 'requisicao' && $numReq !== '') {
            $justificativa = $numReq . ($justificativa !== '' ? ' — ' . $justificativa : '');
        }

        $itemIds    = $_POST['item_id']    ?? [];
        $quantidades = $_POST['quantidade'] ?? [];

        if (empty($itemIds)) {
            session_flash('error', 'Adicione ao menos um item.');
            redirect('/estoque');
        }

        if (strlen($justificativa) < 5) {
            session_flash('error', 'Justificativa obrigatória (mínimo 5 caracteres).');
            redirect('/estoque');
        }

        $db->beginTransaction();
        try {
            $totalItens = 0;
            foreach ($itemIds as $idx => $itemId) {
                if (empty($itemId)) continue;
                $qtd = parse_decimal($quantidades[$idx] ?? 0);
                if ($qtd <= 0) continue;

                // Verifica saldo com FOR UPDATE — bloqueia a linha até o commit,
                // impedindo race condition entre saídas simultâneas do mesmo item.
                $stmt = $db->prepare(
                    "SELECT COALESCE(quantidade, 0) FROM estoque WHERE item_id = ? AND filial_id = ? LIMIT 1 FOR UPDATE"
                );
                $stmt->execute([$itemId, $filialId]);
                $saldo = (float)($stmt->fetchColumn() ?: 0);

                if ($saldo < $qtd) {
                    $db->rollBack();
                    $nomeStmt = $db->prepare("SELECT nome FROM itens WHERE id = ?");
                    $nomeStmt->execute([$itemId]);
                    $nome = $nomeStmt->fetchColumn() ?: $itemId;
                    session_flash('error', "Saldo insuficiente para \"$nome\". Disponível: " . number_format($saldo, 2, ',', '.'));
                    redirect('/estoque');
                }

                $db->prepare(
                    "UPDATE estoque SET quantidade = quantidade - ?, atualizado_em = NOW()
                     WHERE item_id = ? AND filial_id = ?"
                )->execute([$qtd, $itemId, $filialId]);

                $db->prepare(
                    "INSERT INTO movimentos_estoque
                     (id, item_id, filial_id, tipo, motivo, justificativa, quantidade, referencia_tipo, usuario_id, criado_em)
                     VALUES (?, ?, ?, 'saida', ?, ?, ?, 'saida_manual', ?, NOW())"
                )->execute([uuid(), $itemId, $filialId, $motivo, $justificativa, $qtd, $user['id']]);

                (new Estoque())->espelharBrinde($itemId, $filialId, $qtd, 'saida', $user['id']);

                (new AuditoriaService())->log($user['id'], 'estoque', $itemId, 'estoque.saida',
                    null, ['quantidade' => $qtd]);

                $totalItens++;
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao registrar saída: ' . $this->mensagemErro($e));
            redirect('/estoque');
        }

        session_flash('success', $totalItens . ' item(ns) registrado(s) com saída no estoque.');
        redirect('/estoque');
    }

    public function qrcode(string $id): void
    {
        $this->requireAuth();
        $item = (new Item())->find($id);
        if (!$item) { redirect('/estoque'); }
        $this->view('estoque/qrcode', compact('item'));
    }

    public function saldoItem(string $itemId): void
    {
        $this->requireAuth();
        $user    = auth();
        $db      = \App\Config\Database::getInstance();

        // Stock by branch
        $stmt = $db->prepare(
            "SELECT e.filial_id, e.quantidade, e.localizacao, f.nome AS filial_nome,
                    COALESCE(e.valor_unitario,
                        (SELECT hp.valor_unitario FROM historico_precos hp
                         WHERE hp.item_id = e.item_id ORDER BY hp.registrado_em DESC LIMIT 1),
                        i.valor_referencia, 0) AS valor_unitario
             FROM estoque e
             JOIN filiais f ON f.id = e.filial_id
             JOIN itens i   ON i.id = e.item_id
             WHERE e.item_id = ?
             ORDER BY (e.filial_id = ?) DESC, e.quantidade DESC"
        );
        $stmt->execute([$itemId, $user['filial_id']]);
        $estoques = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $total = array_sum(array_column($estoques, 'quantidade'));

        header('Content-Type: application/json');
        echo json_encode([
            'total'    => $total,
            'estoques' => $estoques,
        ]);
        exit;
    }

    public function saldoPorFilial(string $filialId): void
    {
        $this->requireAuth();

        $saldo = array_column((new Estoque())->porFilial($filialId), 'quantidade', 'item_id');

        header('Content-Type: application/json');
        echo json_encode($saldo);
        exit;
    }


    public function transferencia(): void
    {
        $this->requireAuth();
        $this->requirePerfil(['admin']);
        $user = auth();
        $db   = Database::getInstance();

        $origemId   = $this->input('filial_origem_id');
        $destinoId  = $this->input('filial_destino_id');
        $observacao = trim($this->input('observacao', ''));
        $modelo         = trim($this->input('modelo', '')) ?: null;
        $cor            = trim($this->input('cor', '')) ?: null;
        $placa          = strtoupper(trim($this->input('placa', ''))) ?: null;
        $transportadora = trim($this->input('transportadora', '')) ?: null;
        $itemIds    = $_POST['item_id']    ?? [];
        $quantidades= $_POST['quantidade'] ?? [];

        if (!$origemId || !$destinoId || empty($itemIds)) {
            session_flash('error', 'Preencha todos os campos obrigatorios.');
            redirect('/estoque');
        }

        if ($origemId === $destinoId) {
            session_flash('error', 'Origem e destino nao podem ser a mesma unidade.');
            redirect('/estoque');
        }

        // Valida saldo de cada item antes de iniciar a transação
        foreach ($itemIds as $i => $itemId) {
            $qtd = parse_decimal($quantidades[$i] ?? 0);
            if (!$itemId || $qtd <= 0) continue;
            $stmt = $db->prepare(
                "SELECT COALESCE(quantidade, 0) FROM estoque WHERE item_id = ? AND filial_id = ? LIMIT 1"
            );
            $stmt->execute([$itemId, $origemId]);
            $saldo = (float)($stmt->fetchColumn() ?: 0);
            if ($saldo < $qtd) {
                session_flash('error', 'Saldo insuficiente para o item ' . ($i + 1) . '. Disponivel: ' . number_format($saldo, 2, ',', '.'));
                redirect('/estoque');
            }
        }

        $transferId = uuid();
        $totalItens = 0;

        $db->beginTransaction();
        try {
            $stmtNumTransf = $db->query("SELECT COALESCE(MAX(numero), 0) + 1 FROM transferencias FOR UPDATE");
            $proximoNumeroTransf = (int)$stmtNumTransf->fetchColumn();

            $db->prepare(
                "INSERT INTO transferencias
                 (id, numero, filial_origem_id, filial_destino_id, usuario_solicitante, status, modelo, cor, placa, transportadora, observacao, criado_em)
                 VALUES (?, ?, ?, ?, ?, 'aprovado', ?, ?, ?, ?, ?, NOW())"
            )->execute([$transferId, $proximoNumeroTransf, $origemId, $destinoId, $user['id'], $modelo, $cor, $placa, $transportadora, $observacao ?: null]);

            foreach ($itemIds as $i => $itemId) {
                $qtd = parse_decimal($quantidades[$i] ?? 0);
                if (!$itemId || $qtd <= 0) continue;

                // Re-checa o saldo com FOR UPDATE DENTRO da transação. A validação
                // pré-transação acima é apenas UX; esta é a verificação autoritativa
                // que impede saldo negativo sob concorrência.
                $lockStmt = $db->prepare(
                    "SELECT COALESCE(quantidade, 0) FROM estoque WHERE item_id = ? AND filial_id = ? LIMIT 1 FOR UPDATE"
                );
                $lockStmt->execute([$itemId, $origemId]);
                $saldoLock = (float)($lockStmt->fetchColumn() ?: 0);
                if ($saldoLock < $qtd) {
                    $db->rollBack();
                    session_flash('error', 'Saldo insuficiente para o item ' . ($i + 1) . ' (concorrência). Disponivel: ' . number_format($saldoLock, 2, ',', '.'));
                    redirect('/estoque');
                }

                $db->prepare(
                    "UPDATE estoque SET quantidade = quantidade - ?, atualizado_em = NOW()
                     WHERE item_id = ? AND filial_id = ?"
                )->execute([$qtd, $itemId, $origemId]);

                $db->prepare(
                    "INSERT INTO movimentos_estoque
                     (id, item_id, filial_id, tipo, motivo, justificativa, quantidade, referencia_tipo, referencia_id, usuario_id, criado_em)
                     VALUES (?, ?, ?, 'saida', 'transferencia', ?, ?, 'transferencia', ?, ?, NOW())"
                )->execute([uuid(), $itemId, $origemId, $observacao ?: null, $qtd, $transferId, $user['id']]);

                (new Estoque())->espelharBrinde($itemId, $origemId, $qtd, 'saida', $user['id'], 'transferencia');

                // Registra o item esperado — a entrada no estoque destino só acontece quando
                // a filial confirmar o recebimento (ver TransferenciaController::confirmar()).
                $db->prepare(
                    "INSERT INTO transferencia_itens (id, transferencia_id, item_id, quantidade)
                     VALUES (?, ?, ?, ?)"
                )->execute([uuid(), $transferId, $itemId, $qtd]);

                $totalItens++;
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao registrar transferencia: ' . $this->mensagemErro($e));
            redirect('/estoque');
        }

        (new AuditoriaService())->log($user['id'], 'estoque', $transferId, 'estoque.transferencia', null, [
            'origem'  => $origemId,
            'destino' => $destinoId,
            'itens'   => $totalItens,
        ]);

        session_flash('success', $totalItens . ' item(ns) transferido(s) com sucesso.');
        redirect('/estoque');
    }

    /**
     * Define o estoque mínimo de um item para uma filial específica (admin).
     * Cada filial pode ter um mínimo diferente para o mesmo item.
     */
    public function definirMinimo(): void
    {
        $this->requireAuth();
        $this->requirePerfil(['admin']);

        $itemId   = $this->input('item_id');
        $filialId = $this->input('filial_id');
        $minimo   = (float)str_replace(',', '.', $this->input('estoque_minimo') ?: '0');

        if (!$itemId || !$filialId || $minimo < 0) {
            session_flash('error', 'Dados inválidos para definir o estoque mínimo.');
            redirect('/estoque');
        }

        $db = Database::getInstance();
        $db->prepare(
            "INSERT INTO estoque (id, item_id, filial_id, quantidade, estoque_minimo, atualizado_em)
             VALUES (?, ?, ?, 0, ?, NOW())
             ON DUPLICATE KEY UPDATE estoque_minimo = VALUES(estoque_minimo), atualizado_em = NOW()"
        )->execute([uuid(), $itemId, $filialId, $minimo]);

        session_flash('success', 'Estoque mínimo atualizado.');
        redirect('/estoque');
    }

    public function ajusteEstoque(string $itemId): void
    {
        $this->requireAuth();
        $this->requirePerfil(['admin']);
        $user = auth();
        $db   = Database::getInstance();

        $filialId      = $this->input('filial_id') ?: $user['filial_id'];
        $justificativa = trim($this->input('justificativa', ''));

        if (strlen($justificativa) < 5) {
            session_flash('error', 'Justificativa obrigatória (mínimo 5 caracteres).');
            redirect('/estoque');
        }

        $valStmt = $db->prepare(
            "SELECT COALESCE(e.valor_unitario,
                        (SELECT hp.valor_unitario FROM historico_precos hp
                         WHERE hp.item_id = e.item_id ORDER BY hp.registrado_em DESC LIMIT 1),
                        i.valor_referencia, 0)
             FROM estoque e JOIN itens i ON i.id = e.item_id
             WHERE e.item_id = ? AND e.filial_id = ? LIMIT 1"
        );
        $valStmt->execute([$itemId, $filialId]);
        $vAtual     = $valStmt->fetchColumn();
        $valorAtual = $vAtual === false ? null : (float)$vAtual;

        $stmt = $db->prepare("SELECT COALESCE(quantidade, 0) FROM estoque WHERE item_id = ? AND filial_id = ? LIMIT 1");
        $stmt->execute([$itemId, $filialId]);
        $saldoAtual = (float)($stmt->fetchColumn() ?: 0);

        $qtdInput = trim((string)$this->input('nova_quantidade', ''));
        $novaQtd  = $qtdInput === '' ? $saldoAtual : parse_decimal($qtdInput);
        if ($novaQtd < 0) {
            session_flash('error', 'Quantidade inválida.');
            redirect('/estoque');
        }

        $valInput   = trim((string)$this->input('novo_valor_unitario', ''));
        $novoValor  = $valInput === '' ? null : parse_decimal($valInput);
        $valorMudou = $novoValor !== null && ($valorAtual === null || abs($novoValor - $valorAtual) > 0.00001);

        $deltaQtd = $novaQtd - $saldoAtual;

        if ($deltaQtd == 0 && !$valorMudou) {
            session_flash('info', 'Nenhuma alteração informada.');
            redirect('/estoque');
        }

        $db->beginTransaction();
        try {
            $lockStmt = $db->prepare("SELECT COALESCE(quantidade, 0) FROM estoque WHERE item_id = ? AND filial_id = ? LIMIT 1 FOR UPDATE");
            $lockStmt->execute([$itemId, $filialId]);
            $saldoAtual = (float)($lockStmt->fetchColumn() ?: 0);
            $deltaQtd = $novaQtd - $saldoAtual;

            if ($deltaQtd > 0) {
                $db->prepare(
                    "INSERT INTO estoque (id, item_id, filial_id, quantidade, atualizado_em)
                     VALUES (?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), atualizado_em = NOW()"
                )->execute([uuid(), $itemId, $filialId, $deltaQtd]);
                $db->prepare(
                    "INSERT INTO movimentos_estoque
                     (id, item_id, filial_id, tipo, motivo, justificativa, quantidade, referencia_tipo, usuario_id, criado_em)
                     VALUES (?, ?, ?, 'entrada', 'correcao', ?, ?, 'ajuste', ?, NOW())"
                )->execute([uuid(), $itemId, $filialId, $justificativa, $deltaQtd, $user['id']]);
                (new Estoque())->espelharBrinde($itemId, $filialId, $deltaQtd, 'entrada', $user['id'], 'ajuste');
            } elseif ($deltaQtd < 0) {
                $abs = abs($deltaQtd);
                if ($saldoAtual < $abs) {
                    $db->rollBack();
                    session_flash('error', 'Saldo insuficiente para o ajuste. Saldo atual: ' . number_format($saldoAtual, 2, ',', '.'));
                    redirect('/estoque');
                }
                $db->prepare("UPDATE estoque SET quantidade = quantidade - ?, atualizado_em = NOW() WHERE item_id = ? AND filial_id = ?")
                   ->execute([$abs, $itemId, $filialId]);
                $db->prepare(
                    "INSERT INTO movimentos_estoque
                     (id, item_id, filial_id, tipo, motivo, justificativa, quantidade, referencia_tipo, usuario_id, criado_em)
                     VALUES (?, ?, ?, 'saida', 'correcao', ?, ?, 'ajuste', ?, NOW())"
                )->execute([uuid(), $itemId, $filialId, $justificativa, $abs, $user['id']]);
                (new Estoque())->espelharBrinde($itemId, $filialId, $abs, 'saida', $user['id'], 'ajuste');
            }

            if ($valorMudou) {
                $db->prepare(
                    "INSERT INTO estoque (id, item_id, filial_id, quantidade, valor_unitario, atualizado_em)
                     VALUES (?, ?, ?, 0, ?, NOW())
                     ON DUPLICATE KEY UPDATE valor_unitario = VALUES(valor_unitario), atualizado_em = NOW()"
                )->execute([uuid(), $itemId, $filialId, $novoValor]);
                $db->prepare(
                    "INSERT INTO movimentos_estoque
                     (id, item_id, filial_id, tipo, motivo, justificativa, quantidade, valor_unit_anterior, valor_unit_novo, referencia_tipo, usuario_id, criado_em)
                     VALUES (?, ?, ?, 'entrada', 'ajuste_valor', ?, 0, ?, ?, 'ajuste', ?, NOW())"
                )->execute([uuid(), $itemId, $filialId, $justificativa, $valorAtual, $novoValor, $user['id']]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao registrar ajuste: ' . $this->mensagemErro($e));
            redirect('/estoque');
        }

        (new AuditoriaService())->log($user['id'], 'estoque', $itemId, 'estoque.ajuste',
            ['saldo_anterior' => $saldoAtual, 'valor_anterior' => $valorAtual],
            ['nova_quantidade' => $novaQtd, 'novo_valor' => $novoValor, 'filial_id' => $filialId, 'justificativa' => $justificativa]);

        session_flash('success', 'Ajuste registrado com sucesso.');
        redirect('/estoque');
    }

    // ── Histórico de movimentos por item ─────────────────────────────────────

    public function historico(string $itemId): void
    {
        $this->requirePerfil(['almoxarife','gestor','diretor','admin']);
        $db   = Database::getInstance();
        $user = auth();

        // Dados do item
        $stmtItem = $db->prepare(
            "SELECT i.id, i.nome, i.codigo, i.unidade, gp.nome AS grupo
             FROM itens i
             LEFT JOIN grupos_produtos gp ON gp.id = i.grupo_produto_id
             WHERE i.id = ?"
        );
        $stmtItem->execute([$itemId]);
        $item = $stmtItem->fetch();

        if (!$item) {
            session_flash('error', 'Item não encontrado.');
            redirect('/estoque');
            return;
        }

        // Filtro de filial
        if (ve_todas_filiais()) {
            if (isset($_GET['filial_id'])) {
                $filialId = $_GET['filial_id'] ?: null;
                session_set('filtro_filial_estoque_historico', $filialId);
            } else {
                $filialId = session_get('filtro_filial_estoque_historico', null);
            }
        } else {
            $filialId = $user['filial_id'];
        }

        $sql = "SELECT me.id, me.criado_em, me.tipo, me.motivo, me.quantidade,
                       me.valor_unit_anterior, me.valor_unit_novo,
                       me.justificativa, me.referencia_tipo, me.referencia_id,
                       f.nome AS filial,
                       u.nome AS usuario,
                       r.numero AS req_numero, r.criado_em AS req_criado_em,
                       r.tipo AS req_tipo,
                       r.justificativa AS req_justificativa,
                       oc.numero_oc AS oc_numero,
                       tr.numero AS tr_numero, tr.criado_em AS tr_criado_em,
                       tf_origem.nome AS tr_origem_nome, tf_destino.nome AS tr_destino_nome
                FROM movimentos_estoque me
                JOIN filiais f  ON f.id  = me.filial_id
                JOIN usuarios u ON u.id  = me.usuario_id
                LEFT JOIN requisicoes r  ON r.id  = me.referencia_id AND me.referencia_tipo = 'requisicao'
                LEFT JOIN ordens_compra oc ON oc.id = me.referencia_id AND me.referencia_tipo = 'ordem_compra'
                LEFT JOIN transferencias tr ON tr.id = me.referencia_id AND me.referencia_tipo = 'transferencia'
                LEFT JOIN filiais tf_origem  ON tf_origem.id  = tr.filial_origem_id
                LEFT JOIN filiais tf_destino ON tf_destino.id = tr.filial_destino_id
                WHERE me.item_id = ?";
        $params = [$itemId];

        if ($filialId) {
            $sql .= " AND me.filial_id = ?";
            $params[] = $filialId;
        }
        $sql .= " ORDER BY me.criado_em DESC LIMIT 300";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $movimentos = $stmt->fetchAll();

        // Filiais para o filtro (somente diretor/admin)
        $filiais = ve_todas_filiais()
            ? $db->query("SELECT id, nome FROM filiais WHERE ativo=1 ORDER BY nome")->fetchAll()
            : [];

        $this->view('estoque/historico', compact('item','movimentos','filiais','filialId','itemId'));
    }
}