<?php
// @versao Ver.004.281026

namespace App\Controllers;

use App\Core\Controller;
use App\Models\{Item, GrupoProduto, Setor, Filial};
use App\Config\Database;
use App\Services\AuditoriaService;
use App\Services\BrindeSyncService;

class ItemController extends Controller
{
    public function index(): void
    {
        $this->requirePerfil(['almoxarife', 'comprador', 'gestor', 'admin']);

        $filtros = [];
        if (!empty($_GET['busca']))            $filtros['busca']            = $_GET['busca'];
        if (!empty($_GET['grupo_produto_id'])) $filtros['grupo_produto_id'] = $_GET['grupo_produto_id'];
        if (isset($_GET['ativo']) && $_GET['ativo'] !== '') $filtros['ativo'] = $_GET['ativo'];
        if (!empty($_GET['setor_id']))         $filtros['setor_id']         = $_GET['setor_id'];
        if (!empty($_GET['col_codigo']))       $filtros['col_codigo']       = $_GET['col_codigo'];
        if (!empty($_GET['col_nome']))         $filtros['col_nome']         = $_GET['col_nome'];
        if (!empty($_GET['curva']))            $filtros['curva']            = $_GET['curva'];
        if (!empty($_GET['sort']))             $filtros['sort']             = $_GET['sort'];
        if (!empty($_GET['dir']))              $filtros['dir']              = $_GET['dir'];

        $model      = new Item();
        $itens      = $model->allComGrupo($filtros);
        $grupos     = (new GrupoProduto())->all(['ativo' => 1], 'nome');
        $setores    = (new Setor())->allAtivos();
        $estatisticas = $model->estatisticas();

        $this->view('itens/index', compact('itens', 'grupos', 'setores', 'estatisticas'));
    }

    public function form(?string $id = null): void
    {
        $this->requirePerfil(['almoxarife', 'comprador', 'gestor', 'admin']);

        $item       = $id ? (new Item())->find($id) : null;
        $grupos     = (new GrupoProduto())->all(['ativo' => 1], 'nome');
        $db         = Database::getInstance();
        $categorias = $db->query("SELECT * FROM categorias WHERE ativo = 1 ORDER BY nome")->fetchAll();
        $setores    = (new Setor())->allAtivos();

        $setoresSelecionados = [];
        if ($id) {
            $stmt = $db->prepare("SELECT setor_id FROM itens_setores WHERE item_id = ?");
            $stmt->execute([$id]);
            $setoresSelecionados = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        $filiais = (new Filial())->all(['ativo' => 1], 'nome');

        $this->view('itens/form', compact('item', 'grupos', 'categorias', 'setores', 'setoresSelecionados', 'filiais'));
    }

    public function store(): void
    {
        $this->requirePerfil(['almoxarife', 'comprador', 'gestor', 'admin']);
        $user = auth();

        // Gera codigo sequencial automaticamente
        $prefixo = strtoupper(trim($this->input('codigo_prefixo', '')));
        $dbC     = \App\Config\Database::getInstance();
        if ($prefixo) {
            $seqSt = $dbC->prepare(
                'SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo, \'-\', -1) AS UNSIGNED)), 0) FROM itens WHERE codigo LIKE ?'
            );
            $seqSt->execute([$prefixo . '-%']);
            $next   = (int)$seqSt->fetchColumn() + 1;
            $codigo = $prefixo . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
        } else {
            $codigo = strtoupper(trim($this->input('codigo', '')));
        }

        $data = [
            'codigo'           => $codigo,
            'nome'             => $this->input('nome'),
            'referencia'       => $this->input('referencia') ?: null,
            'marca'            => $this->input('marca') ?: null,
            'descricao'        => $this->input('descricao') ?: null,
            'categoria_id'     => $this->input('categoria_id'),
            'grupo_produto_id' => $this->input('grupo_produto_id'),
            'unidade'          => $this->input('unidade') ?: 'UN',
            'curva_abc'        => $this->input('curva_abc') ?: 'C',
            'estoque_minimo'   => (float)str_replace(',', '.', $this->input('estoque_minimo') ?: '0'),
            'valor_referencia' => trim((string)$this->input('valor_referencia', '')) === ''
                                    ? null
                                    : parse_decimal($this->input('valor_referencia')),
            'ativo'            => 1,
        ];

        if (!$data['codigo'] || !$data['nome'] || !$data['categoria_id'] || !$data['grupo_produto_id']) {
            session_flash('error', 'Preencha os campos obrigatorios: codigo, nome, categoria e grupo.');
            redirect('/itens/novo');
        }

        $duplicado = $dbC->prepare("SELECT id FROM itens WHERE LOWER(nome) = LOWER(?) LIMIT 1");
        $duplicado->execute([$data['nome']]);
        if ($duplicado->fetchColumn()) {
            session_flash('error', 'Já existe um item cadastrado com o nome "' . $data['nome'] . '".');
            redirect('/itens/novo');
        }

        try {
            $id  = (new Item())->create($data);
            $db  = Database::getInstance();
            $sids = array_filter($_POST['setor_ids'] ?? [], fn($v) => !empty($v));
            foreach ($sids as $sid) {
                try {
                    $db->prepare("INSERT IGNORE INTO itens_setores (item_id, setor_id) VALUES (?, ?)")
                       ->execute([$id, $sid]);
                } catch (\Throwable) {}
            }

            if ($data['valor_referencia'] !== null) {
                $db->prepare(
                    "INSERT INTO historico_precos (id, item_id, fornecedor_id, valor_unitario, origem, origem_id, registrado_em)
                     VALUES (?, ?, NULL, ?, 'manual', NULL, NOW())"
                )->execute([uuid(), $id, $data['valor_referencia']]);
            }

            (new AuditoriaService())->log($user['id'], 'item', $id, 'item.criado', null, $data);

            // Estoque inicial opcional
            $qtdInicial = (float)str_replace(',', '.', $this->input('estoque_inicial') ?: '0');
            $filialId   = $this->input('estoque_filial_id') ?: $user['filial_id'];
            if ($qtdInicial > 0 && $filialId) {
                $db->prepare(
                    "INSERT INTO estoque (id, item_id, filial_id, quantidade, atualizado_em)
                     VALUES (?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), atualizado_em = NOW()"
                )->execute([uuid(), $id, $filialId, $qtdInicial]);

                $db->prepare(
                    "INSERT INTO movimentos_estoque
                     (id, item_id, filial_id, tipo, motivo, justificativa, quantidade, referencia_tipo, usuario_id, criado_em)
                     VALUES (?, ?, ?, 'entrada', 'inventario', 'Estoque inicial no cadastro do item', ?, 'ajuste', ?, NOW())"
                )->execute([uuid(), $id, $filialId, $qtdInicial, $user['id']]);

                (new AuditoriaService())->log($user['id'], 'estoque', $id, 'estoque.inicial', null, ['quantidade' => $qtdInicial, 'filial_id' => $filialId]);
            }

            // Item marcado como Brinde: cria o cadastro correspondente em brindes,
            // ja vinculado (brindes.item_id) para que futuras entradas/saidas de
            // brinde reflitam automaticamente no estoque geral deste item.
            if ($this->input('eh_brinde')) {
                $brindeId = uuid();
                $db->prepare(
                    "INSERT INTO brindes (id, nome, descricao, codigo, marca, item_id, valor_referencia, ativo, criado_por)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)"
                )->execute([
                    $brindeId, $data['nome'], $data['descricao'], $data['codigo'], $data['marca'],
                    $id, $data['valor_referencia'] ?? 0, $user['id'],
                ]);

                if ($qtdInicial > 0 && $filialId) {
                    $db->prepare(
                        "INSERT INTO brinde_estoque (id, brinde_id, filial_id, quantidade)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade)"
                    )->execute([uuid(), $brindeId, $filialId, $qtdInicial]);

                    $db->prepare(
                        "INSERT INTO movimentos_brinde (id, brinde_id, filial_id, tipo, quantidade, referencia_tipo, criado_por)
                         VALUES (?, ?, ?, 'entrada', ?, 'ajuste', ?)"
                    )->execute([uuid(), $brindeId, $filialId, $qtdInicial, $user['id']]);
                }

                (new AuditoriaService())->log($user['id'], 'brinde', $brindeId, 'brinde.criado', null, ['item_id' => $id]);
            }

            // Item de marketing entra na vitrine de Brindes na hora, sem
            // depender de alguém abrir /brindes.
            if (str_starts_with(strtoupper((string)$data['codigo']), 'MKT')) {
                (new BrindeSyncService())->sincronizarItensMkt($user['id']);
            }

            session_flash('success', 'Item criado com sucesso.' . ($qtdInicial > 0 ? ' Estoque inicial registrado.' : ''));
            redirect('/itens');
        } catch (\Throwable $e) {
            session_flash('error', 'Erro ao criar item: ' . $this->mensagemErro($e));
            redirect('/itens/novo');
        }
    }

    public function toggleAtivo(string $id): void
    {
        $this->requirePerfil(['almoxarife', 'comprador', 'gestor', 'admin']);
        $user  = auth();
        $model = new Item();
        $item  = $model->find($id);

        if (!$item) {
            session_flash('error', 'Item não encontrado.');
            redirect('/itens');
        }

        $novoStatus = $item['ativo'] ? 0 : 1;
        $model->update($id, ['ativo' => $novoStatus]);

        (new AuditoriaService())->log($user['id'], 'item', $id,
            $novoStatus ? 'item.ativado' : 'item.inativado',
            ['ativo' => $item['ativo']], ['ativo' => $novoStatus]);

        $msg = $novoStatus ? 'Item ativado.' : 'Item inativado.';
        session_flash('success', $msg);
        redirect('/itens');
    }

    public function excluir(string $id): void
    {
        $this->requirePerfil(['admin']);
        $user  = auth();
        $db    = Database::getInstance();
        $model = new Item();
        $item  = $model->find($id);

        if (!$item) {
            session_flash('error', 'Item não encontrado.');
            redirect('/itens');
        }

        // Bloquear se o item já foi usado em qualquer operação
        $tabelas = [
            'requisicao_itens'  => 'requisições',
            'cotacao_itens'     => 'cotações',
            'oc_itens'          => 'ordens de compra',
            'transferencia_itens' => 'transferências',
            'historico_precos'  => 'histórico de preços',
        ];

        foreach ($tabelas as $tabela => $label) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM {$tabela} WHERE item_id = ?");
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() > 0) {
                session_flash('error', "Este item possui {$label} vinculadas e não pode ser excluído. Inative-o em vez disso.");
                redirect('/itens');
            }
        }

        // Limpar tabelas sem CASCADE
        $db->prepare("DELETE FROM estoque       WHERE item_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM itens_setores WHERE item_id = ?")->execute([$id]);

        (new AuditoriaService())->log($user['id'], 'item', $id, 'item.excluido', $item, null);
        $db->prepare("DELETE FROM itens WHERE id = ?")->execute([$id]);

        session_flash('success', 'Item excluído com sucesso.');
        redirect('/itens');
    }

    public function update(string $id): void
    {
        $this->requirePerfil(['almoxarife', 'comprador', 'gestor', 'admin']);
        $user  = auth();
        $model = new Item();
        $antes = $model->find($id);

        if (!$antes) {
            session_flash('error', 'Item nao encontrado.');
            redirect('/itens');
        }

        $data = [
            'codigo'           => $this->input('codigo'),
            'nome'             => $this->input('nome'),
            'referencia'       => $this->input('referencia') ?: null,
            'marca'            => $this->input('marca') ?: null,
            'fabricante'       => $this->input('fabricante') ?: null,
            'descricao'        => $this->input('descricao') ?: null,
            'categoria_id'     => $this->input('categoria_id'),
            'grupo_produto_id' => $this->input('grupo_produto_id'),
            'unidade'          => $this->input('unidade') ?: 'UN',
            'curva_abc'        => $this->input('curva_abc') ?: 'C',
            'estoque_minimo'   => (float)str_replace(',', '.', $this->input('estoque_minimo') ?: '0'),
            'valor_referencia' => trim((string)$this->input('valor_referencia', '')) === ''
                                    ? null
                                    : parse_decimal($this->input('valor_referencia')),
            'ativo'            => (int)$this->input('ativo', 1),
        ];

        if (!$data['codigo'] || !$data['nome'] || !$data['categoria_id'] || !$data['grupo_produto_id']) {
            session_flash('error', 'Preencha os campos obrigatorios: codigo, nome, categoria e grupo.');
            redirect('/itens/' . $id . '/editar');
        }

        $db2 = Database::getInstance();
        $dup = $db2->prepare("SELECT id FROM itens WHERE LOWER(nome) = LOWER(?) AND id != ? LIMIT 1");
        $dup->execute([$data['nome'], $id]);
        if ($dup->fetchColumn()) {
            session_flash('error', 'Já existe um item cadastrado com o nome "' . $data['nome'] . '".');
            redirect('/itens/' . $id . '/editar');
        }

        try {
            $model->update($id, $data);
            $db = Database::getInstance();
            $db->prepare("DELETE FROM itens_setores WHERE item_id = ?")->execute([$id]);
            $sids = array_filter($_POST['setor_ids'] ?? [], fn($v) => !empty($v));
            foreach ($sids as $sid) {
                try {
                    $db->prepare("INSERT IGNORE INTO itens_setores (item_id, setor_id) VALUES (?, ?)")
                       ->execute([$id, $sid]);
                } catch (\Throwable) {}
            }

            $valorMudou = $data['valor_referencia'] !== null
                && (float)$data['valor_referencia'] !== (float)($antes['valor_referencia'] ?? 0);
            if ($valorMudou) {
                $db->prepare(
                    "INSERT INTO historico_precos (id, item_id, fornecedor_id, valor_unitario, origem, origem_id, registrado_em)
                     VALUES (?, ?, NULL, ?, 'manual', NULL, NOW())"
                )->execute([uuid(), $id, $data['valor_referencia']]);
            }

            (new AuditoriaService())->log($user['id'], 'item', $id, 'item.atualizado', $antes, $data);
            // Cobre também a renomeação de um item para um código MKT e a
            // reativação de um item MKT desativado.
            if (str_starts_with(strtoupper((string)$data['codigo']), 'MKT')) {
                (new BrindeSyncService())->sincronizarItensMkt($user['id']);
            }

            session_flash('success', 'Item atualizado com sucesso.');
            $back = trim($_POST['_back'] ?? '');
            redirect('/itens' . ($back ? '?' . $back : ''));
        } catch (\Throwable $e) {
            session_flash('error', 'Erro ao atualizar item: ' . $this->mensagemErro($e));
            redirect('/itens/' . $id . '/editar' . (!empty($_POST['_back']) ? '?_back=' . urlencode($_POST['_back']) : ''));
        }
    }

    public function relatorio(string $id): void
    {
        $this->requireAuth();
        $db = \App\Config\Database::getInstance();

        // Dados do item
        $stmt = $db->prepare(
            "SELECT i.*, gp.nome AS grupo_nome
             FROM itens i
             LEFT JOIN grupos_produtos gp ON gp.id = i.grupo_produto_id
             WHERE i.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) { redirect('/itens'); }

        // Historico de precos por fornecedor
        $stmtPrecos = $db->prepare(
            "SELECT hp.valor_unitario, hp.origem, hp.registrado_em,
                    CASE WHEN hp.origem = 'manual' THEN 'Ajuste manual'
                         ELSE COALESCE(f.nome_fantasia, '(fornecedor removido)') END AS fornecedor_nome,
                    f.razao_social AS fornecedor_razao
             FROM historico_precos hp
             LEFT JOIN fornecedores f ON f.id = hp.fornecedor_id
             WHERE hp.item_id = ?
             ORDER BY hp.registrado_em DESC
             LIMIT 50"
        );
        $stmtPrecos->execute([$id]);
        $historico = $stmtPrecos->fetchAll();

        // Resumo de precos por fornecedor
        $stmtResumo = $db->prepare(
            "SELECT f.nome_fantasia AS fornecedor_nome,
                    COUNT(*)                           AS total_compras,
                    MIN(hp.valor_unitario)             AS preco_min,
                    MAX(hp.valor_unitario)             AS preco_max,
                    AVG(hp.valor_unitario)             AS preco_medio,
                    MAX(hp.registrado_em)              AS ultima_compra,
                    (SELECT hp2.valor_unitario FROM historico_precos hp2
                     WHERE hp2.item_id = hp.item_id AND hp2.fornecedor_id = hp.fornecedor_id
                     ORDER BY hp2.registrado_em DESC LIMIT 1) AS ultimo_preco
             FROM historico_precos hp
             JOIN fornecedores f ON f.id = hp.fornecedor_id
             WHERE hp.item_id = ?
             GROUP BY hp.fornecedor_id, f.nome_fantasia
             ORDER BY preco_medio ASC"
        );
        $stmtResumo->execute([$id]);
        $resumoFornecedores = $stmtResumo->fetchAll();

        // Estoque por filial
        $stmtEstoque = $db->prepare(
            "SELECT e.quantidade, e.quantidade_reservada, fi.nome AS filial_nome
             FROM estoque e
             JOIN filiais fi ON fi.id = e.filial_id
             WHERE e.item_id = ?
             ORDER BY fi.nome"
        );
        $stmtEstoque->execute([$id]);
        $estoqueFiliais = $stmtEstoque->fetchAll();

        // Movimentos recentes
        $stmtMov = $db->prepare(
            "SELECT me.tipo, me.motivo, me.quantidade, me.criado_em,
                    fi.nome AS filial_nome, u.nome AS usuario_nome
             FROM movimentos_estoque me
             JOIN filiais fi ON fi.id = me.filial_id
             LEFT JOIN usuarios u ON u.id = me.usuario_id
             WHERE me.item_id = ?
             ORDER BY me.criado_em DESC
             LIMIT 20"
        );
        $stmtMov->execute([$id]);
        $movimentos = $stmtMov->fetchAll();

        $dias    = in_array((int)($_GET['dias'] ?? 30), [30, 60, 365], true) ? (int)($_GET['dias'] ?? 30) : 30;
        $periodo = $this->evolucaoPeriodo($db, $item, $estoqueFiliais, $dias);

        $this->view('itens/relatorio', compact('item', 'historico', 'resumoFornecedores', 'estoqueFiliais', 'movimentos', 'dias', 'periodo'));
    }

    /**
     * Entradas/saídas do período e valor do estoque ao longo dele.
     *
     * Movimentos só guardam quantidade: cada um é valorizado pelo preço da
     * época (ValorEstoqueService).
     * O saldo de cada data é reconstruído de trás para frente a partir do saldo atual.
     */
    private function evolucaoPeriodo(\PDO $db, array $item, array $estoqueFiliais, int $dias): array
    {
        $hoje   = new \DateTimeImmutable('today');
        $inicio = $hoje->modify('-' . ($dias - 1) . ' days');

        // 30 dias → por dia; 60 → por semana; 365 → por mês
        $granularidade = $dias === 30 ? 'dia' : ($dias === 60 ? 'semana' : 'mes');
        $chaveDe = function (\DateTimeInterface $d) use ($granularidade, $inicio): string {
            if ($granularidade === 'dia')    return $d->format('Y-m-d');
            if ($granularidade === 'mes')    return $d->format('Y-m');
            $semana = intdiv((int)$inicio->diff(\DateTimeImmutable::createFromInterface($d)->setTime(0, 0))->days, 7);
            return 'S' . str_pad((string)$semana, 2, '0', STR_PAD_LEFT);
        };

        // Buckets em ordem, com o último dia de cada um (onde o saldo é medido)
        $buckets = [];
        for ($d = $inicio; $d <= $hoje; $d = $d->modify('+1 day')) {
            $k = $chaveDe($d);
            if (!isset($buckets[$k])) {
                $rotulo = match ($granularidade) {
                    'dia'    => $d->format('d/m'),
                    'semana' => $d->format('d/m'),
                    'mes'    => $this->mesAbreviado((int)$d->format('n')) . '/' . $d->format('y'),
                };
                $buckets[$k] = ['rotulo' => $rotulo, 'fim' => $d, 'entrada_qtd' => 0.0, 'saida_qtd' => 0.0,
                                'entrada_valor' => 0.0, 'saida_valor' => 0.0];
            }
            $buckets[$k]['fim'] = $d;
        }

        $qtdAtual = array_sum(array_map(fn($e) => (float)$e['quantidade'], $estoqueFiliais));
        $valores  = new \App\Services\ValorEstoqueService($db);
        $precoEm  = fn(string $dataHora): float => $valores->precoEm($item['id'], $dataHora);

        // Movimentos do período (ajuste de valor tem quantidade 0 e fica de fora)
        $stmtM = $db->prepare("SELECT tipo, quantidade, criado_em FROM movimentos_estoque
                               WHERE item_id = ? AND quantidade > 0 AND criado_em >= ?
                               ORDER BY criado_em ASC");
        $stmtM->execute([$item['id'], $inicio->format('Y-m-d 00:00:00')]);
        $movs = $stmtM->fetchAll();

        $tot = ['entrada_qtd' => 0.0, 'saida_qtd' => 0.0, 'entrada_valor' => 0.0, 'saida_valor' => 0.0];
        foreach ($movs as $m) {
            $k = $chaveDe(new \DateTimeImmutable($m['criado_em']));
            if (!isset($buckets[$k])) continue; // movimento com data futura
            $qtd   = (float)$m['quantidade'];
            $valor = $qtd * $precoEm($m['criado_em']);
            $lado  = $m['tipo'] === 'entrada' ? 'entrada' : 'saida';
            $buckets[$k]["{$lado}_qtd"]   += $qtd;
            $buckets[$k]["{$lado}_valor"] += $valor;
            $tot["{$lado}_qtd"]   += $qtd;
            $tot["{$lado}_valor"] += $valor;
        }

        // Saldo no fim de cada bucket: parte do saldo atual e desfaz os buckets seguintes
        $saldo = $qtdAtual;
        foreach (array_reverse(array_keys($buckets)) as $k) {
            $buckets[$k]['saldo_qtd']   = $saldo;
            $buckets[$k]['saldo_valor'] = $saldo * $precoEm($buckets[$k]['fim']->format('Y-m-d 23:59:59'));
            $saldo = $saldo - $buckets[$k]['entrada_qtd'] + $buckets[$k]['saida_qtd'];
        }
        $saldoInicialQtd   = $saldo;
        $saldoInicialValor = $saldo * $precoEm($inicio->modify('-1 day')->format('Y-m-d 23:59:59'));
        $ultimo = end($buckets);

        return [
            'inicio'        => $inicio,
            'granularidade' => $granularidade,
            'buckets'       => array_values($buckets),
            'totais'        => $tot + [
                'saldo_inicial_qtd'   => $saldoInicialQtd,
                'saldo_inicial_valor' => $saldoInicialValor,
                'saldo_final_qtd'     => $ultimo['saldo_qtd'],
                'saldo_final_valor'   => $ultimo['saldo_valor'],
            ],
            'sem_preco'     => !$valores->temPreco($item['id']),
        ];
    }

    private function mesAbreviado(int $mes): string
    {
        return ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'][$mes - 1];
    }

    public function comparativo(): void
    {
        $this->requireAuth();
        $db = \App\Config\Database::getInstance();

        $ids = $_GET['ids'] ?? [];
        if (!is_array($ids)) $ids = [$ids];
        $ids = array_filter(array_map('trim', $ids));

        $itens = [];
        $comparativo = [];

        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $stmtItens = $db->prepare(
                "SELECT i.*, gp.nome AS grupo_nome
                 FROM itens i
                 LEFT JOIN grupos_produtos gp ON gp.id = i.grupo_produto_id
                 WHERE i.id IN ({$placeholders})"
            );
            $stmtItens->execute(array_values($ids));
            $itens = $stmtItens->fetchAll();

            foreach ($itens as $item) {
                $stmtPreco = $db->prepare(
                    "SELECT MIN(valor_unitario) AS preco_min,
                            MAX(valor_unitario) AS preco_max,
                            AVG(valor_unitario) AS preco_medio,
                            COUNT(*)            AS total_compras,
                            (SELECT valor_unitario FROM historico_precos
                             WHERE item_id = ? ORDER BY registrado_em DESC LIMIT 1) AS ultimo_preco,
                            (SELECT f.nome_fantasia FROM historico_precos hp2
                             JOIN fornecedores f ON f.id = hp2.fornecedor_id
                             WHERE hp2.item_id = ? ORDER BY hp2.registrado_em DESC LIMIT 1) AS ultimo_fornecedor
                     FROM historico_precos WHERE item_id = ?"
                );
                $stmtPreco->execute([$item['id'], $item['id'], $item['id']]);
                $precos = $stmtPreco->fetch();

                $stmtEst = $db->prepare(
                    "SELECT COALESCE(SUM(quantidade),0) AS total,
                            COALESCE(SUM(quantidade_reservada),0) AS reservado
                     FROM estoque WHERE item_id = ?"
                );
                $stmtEst->execute([$item['id']]);
                $estoque = $stmtEst->fetch();

                $comparativo[$item['id']] = array_merge($precos, $estoque);
            }
        }

        // Lista de todos os itens ativos para selecao
        $todosItens = $db->query(
            "SELECT i.id, i.nome, i.codigo, gp.nome AS grupo_nome
             FROM itens i
             LEFT JOIN grupos_produtos gp ON gp.id = i.grupo_produto_id
             WHERE i.ativo = 1 ORDER BY i.nome"
        )->fetchAll();

        $this->view('itens/comparativo', compact('itens', 'comparativo', 'todosItens', 'ids'));
    }
    public function proximoCodigo(): void
    {
        $this->requireAuth();
        $prefixo = strtoupper(trim($_GET['prefixo'] ?? ''));
        if (!$prefixo) { $this->json(['codigo' => '']); return; }

        $db = \App\Config\Database::getInstance();
        $st = $db->prepare(
            'SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo, \'-\', -1) AS UNSIGNED)), 0) FROM itens WHERE codigo LIKE ?'
        );
        $st->execute([$prefixo . '-%']);
        $next   = (int)$st->fetchColumn() + 1;
        $codigo = $prefixo . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
        $this->json(['codigo' => $codigo]);
    }

    private function calcularMediaPreco(string $id): ?float
    {
        $db   = \App\Config\Database::getInstance();
        $stmt = $db->prepare(
            "SELECT AVG(v) AS media, COUNT(*) AS total
             FROM (
                 SELECT valor_unitario AS v
                 FROM requisicao_itens
                 WHERE item_id = ? AND valor_unitario > 0
                 UNION ALL
                 SELECT valor_unitario AS v
                 FROM ordens_compra_itens
                 WHERE item_id = ? AND valor_unitario > 0
             ) AS precos"
        );
        $stmt->execute([$id, $id]);
        $row = $stmt->fetch();
        return ($row && $row['total'] > 0) ? round((float)$row['media'], 2) : null;
    }

    public function mediaPreco(string $id): void
    {
        $this->requireAuth();
        $media = $this->calcularMediaPreco($id);
        $db    = \App\Config\Database::getInstance();
        $stmt  = $db->prepare(
            "SELECT COUNT(*) AS total
             FROM (
                 SELECT id FROM requisicao_itens WHERE item_id = ? AND valor_unitario > 0
                 UNION ALL
                 SELECT id FROM ordens_compra_itens WHERE item_id = ? AND valor_unitario > 0
             ) AS t"
        );
        $stmt->execute([$id, $id]);
        $total = (int)$stmt->fetchColumn();
        $this->json(['media' => $media, 'total' => $total]);
    }
}
