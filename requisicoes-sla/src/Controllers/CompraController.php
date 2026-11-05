<?php
// @versao Ver.013.301026

namespace App\Controllers;

use App\Core\Controller;
use App\Config\Database;
use App\Models\{Fornecedor, Item, Filial};
use App\Services\AuditoriaService;
use App\Services\MailService;
use App\Services\CotacaoPolicy;

class CompraController extends Controller
{
    // â”€â”€ CotaÃ§Ãµes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function cotacoes(): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
        $user = auth();
        $db   = Database::getInstance();

        // cotacoes: id, requisicao_id, status, prazo_resposta, criado_por, criado_em
        $sql = "SELECT c.*,
                       u.nome AS solicitante_nome,
                       r.filial_id,
                       fi.nome AS filial_nome,
                       (SELECT COUNT(*) FROM cotacao_fornecedores cf WHERE cf.cotacao_id = c.id) AS total_fornecedores,
                       (SELECT COUNT(*) FROM cotacao_fornecedores cf WHERE cf.cotacao_id = c.id AND cf.status = 'respondida') AS total_respondidas,
                       (SELECT COUNT(*) FROM cotacao_fornecedores cf WHERE cf.cotacao_id = c.id AND cf.email_manual_enviado_at IS NOT NULL) AS total_enviados,
                       (SELECT oc.numero_oc FROM ordens_compra oc WHERE oc.cotacao_id = c.id LIMIT 1) AS numero_oc,
                       (SELECT oc.id        FROM ordens_compra oc WHERE oc.cotacao_id = c.id LIMIT 1) AS oc_id,
                       (SELECT COALESCE(
                                   GROUP_CONCAT(DISTINCT gp2.nome ORDER BY gp2.nome SEPARATOR ', '),
                                   GROUP_CONCAT(DISTINCT SUBSTRING_INDEX(ri2.descricao_livre, ' ', 3) SEPARATOR ', ')
                               )
                        FROM requisicao_itens ri2
                        LEFT JOIN itens i2 ON i2.id = ri2.item_id
                        LEFT JOIN grupos_produtos gp2 ON gp2.id = i2.grupo_produto_id
                        WHERE ri2.requisicao_id = c.requisicao_id) AS grupo_nome_req,
                       (SELECT GROUP_CONCAT(DISTINCT COALESCE(gp3.nome, cis3.descricao_livre)
                                            ORDER BY 1 SEPARATOR ', ')
                        FROM cotacao_itens_solicitados cis3
                        LEFT JOIN itens i3           ON i3.id  = cis3.item_id
                        LEFT JOIN grupos_produtos gp3 ON gp3.id = i3.grupo_produto_id
                        WHERE cis3.cotacao_id = c.id) AS grupo_nome_cotacao
                FROM cotacoes c
                JOIN usuarios u        ON u.id  = c.criado_por
                LEFT JOIN requisicoes r ON r.id = c.requisicao_id
                LEFT JOIN filiais fi    ON fi.id = COALESCE(r.filial_id, u.filial_id)
                WHERE 1=1";
        $params = [];

        // Cotação avulsa não tem requisição, logo não tem filial própria: ela
        // herda a lotação de quem a criou. Quando a requisição for vinculada na
        // geração da OC, o escopo volta a ser o dela.
        if (!ve_todas_filiais()) {
            $sql .= " AND COALESCE(r.filial_id, u.filial_id) = ?";
            $params[] = $user['filial_id'];
        }
        if (!empty($_GET['status'])) {
            $sql .= " AND c.status = ?";
            $params[] = $_GET['status'];
        } else {
            // Canceladas só aparecem quando pedidas explicitamente no filtro.
            $sql .= " AND c.status <> 'cancelada'";
        }
        $sql .= " ORDER BY c.criado_em DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $cotacoes     = $stmt->fetchAll();
        foreach ($cotacoes as &$c) {
            $c['grupo_nome'] = $c['grupo_nome_req'] ?: ($c['grupo_nome_cotacao'] ?: 'Diversos');
        }
        unset($c);
        // Bloqueado não aparece para convite (a defesa é fornecedorSemCotacao no POST).
        $fornecedores = array_values(array_filter((new Fornecedor())->all(['ativo' => 1], 'nome_fantasia'),
            fn($f) => Fornecedor::podeCotar($f)));
        $requisicoes = $db->prepare(
            "SELECT r.id, r.valor_total, r.atualizado_em,
                    COALESCE(GROUP_CONCAT(DISTINCT gp.nome ORDER BY gp.nome SEPARATOR ', '), 'Diversos') AS grupo_nome,
                    u.nome  AS solicitante_nome,
                    fi.nome AS filial_nome,
                    (SELECT COUNT(*) FROM requisicao_itens ri WHERE ri.requisicao_id = r.id) AS total_itens,
                    (SELECT COUNT(*) FROM ordens_compra oc WHERE oc.requisicao_id = r.id) AS total_ocs
             FROM requisicoes r
             JOIN usuarios u         ON u.id  = r.usuario_id
             JOIN filiais fi         ON fi.id = r.filial_id
             LEFT JOIN requisicao_itens ri_g ON ri_g.requisicao_id = r.id
             LEFT JOIN itens i_g             ON i_g.id = ri_g.item_id
             LEFT JOIN grupos_produtos gp    ON gp.id = i_g.grupo_produto_id
             WHERE r.status = 'aprovado'
               AND r.tipo IN ('compra','ordem_compra')
               AND NOT EXISTS (
                   SELECT 1 FROM cotacoes c2
                   WHERE c2.requisicao_id = r.id AND c2.status <> 'cancelada'
               )
               -- Só requisições em aberto: com OC emitida ela já foi atendida.
               -- Compra fracionada continua possível pela cotação avulsa.
               AND NOT EXISTS (
                   SELECT 1 FROM ordens_compra oc2
                   WHERE oc2.requisicao_id = r.id AND oc2.status <> 'cancelado'
               )
             GROUP BY r.id, r.valor_total, r.atualizado_em, u.nome, fi.nome
             ORDER BY r.criado_em DESC LIMIT 50"
        );
        $requisicoes->execute();
        $requisicoes = $requisicoes->fetchAll();

        // Links de resposta por cotaÃ§Ã£o (para geraÃ§Ã£o de e-mail manual)
        $linksPorCotacao = [];
        if (!empty($cotacoes)) {
            $ids       = implode(',', array_fill(0, count($cotacoes), '?'));
            $cotIds    = array_column($cotacoes, 'id');
            $linksStmt = $db->prepare(
                "SELECT cf.cotacao_id, cf.token_acesso, cf.status,
                        cf.email_manual_enviado_at,
                        fo.nome_fantasia, fo.email,
                        c.prazo_resposta
                 FROM cotacao_fornecedores cf
                 JOIN fornecedores fo ON fo.id = cf.fornecedor_id
                 JOIN cotacoes c      ON c.id  = cf.cotacao_id
                 WHERE cf.cotacao_id IN ({$ids})
                 ORDER BY fo.nome_fantasia"
            );
            $linksStmt->execute($cotIds);
            foreach ($linksStmt->fetchAll() as $row) {
                $linksPorCotacao[$row['cotacao_id']][] = $row;
            }

            // Itens de cada cotaÃ§Ã£o para o e-mail manual
            // Vêm do escopo da própria cotação, então valem também para a avulsa —
            // que não tem requisicao_id e, pelo caminho antigo, ficava sem item
            // nenhum no e-mail. A chave já é a cotação, o que dispensa o mapa
            // requisicao_id -> cotacao_id que existia aqui (e que colidia entre
            // avulsas, todas com a chave NULL).
            //
            // O COALESCE também corrige um caso antigo: o JOIN interno em itens
            // descartava item de descrição livre do corpo do e-mail.
            $itensPorCotacao = [];
            $itensStmt = $db->prepare(
                "SELECT cis.cotacao_id,
                        cis.quantidade,
                        COALESCE(i.nome, cis.descricao_livre) AS item_nome,
                        COALESCE(i.unidade, 'un') AS unidade
                 FROM cotacao_itens_solicitados cis
                 LEFT JOIN itens i ON i.id = cis.item_id
                 WHERE cis.cotacao_id IN ({$ids})
                 ORDER BY item_nome"
            );
            $itensStmt->execute($cotIds);

            foreach ($itensStmt->fetchAll() as $row) {
                $itensPorCotacao[$row['cotacao_id']][] = [
                    'nome' => $row['item_nome'],
                    'qtd'  => (float)$row['quantidade'],
                    'un'   => $row['unidade'],
                ];
            }

            foreach ($linksPorCotacao as $cid => $links) {
                foreach (array_keys($links) as $idx) {
                    $linksPorCotacao[$cid][$idx]['itens'] = $itensPorCotacao[$cid] ?? [];
                }
            }
        }

        $statusFiltro = $_GET['status'] ?? '';

        $itensCatalogo = $db->query(
            "SELECT id, nome, unidade FROM itens WHERE ativo = 1 ORDER BY nome"
        )->fetchAll();

        $this->view('compras/cotacoes', compact('cotacoes', 'fornecedores', 'requisicoes', 'user', 'linksPorCotacao', 'statusFiltro', 'itensCatalogo'));
    }

    public function criarCotacao(): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','admin']);
        $user = auth();
        $db   = Database::getInstance();

        $ehAvulsa     = $this->input('origem') === 'avulsa';
        $requisicaoId = $ehAvulsa ? null : ($this->input('requisicao_id') ?: null);

        // Itens só vêm do formulário na avulsa; com requisição eles são copiados dela.
        $itemIds  = $ehAvulsa ? ($_POST['item_id'] ?? []) : [];
        $itemQtds = $ehAvulsa ? ($_POST['item_quantidade'] ?? []) : [];

        if ($ehAvulsa && empty($itemIds)) {
            session_flash('error', 'Cotação avulsa exige ao menos um item.');
            redirect('/compras/cotacoes');
        }
        // Fornecedor bloqueado (ou inativo) não recebe cotação — antes de gravar qualquer coisa.
        if ($msg = $this->fornecedorSemCotacao((array)($_POST['fornecedor_id'] ?? []))) {
            session_flash('error', $msg);
            redirect('/compras/cotacoes');
        }
        if (!$ehAvulsa && !$requisicaoId) {
            session_flash('error', 'Selecione a requisição.');
            redirect('/compras/cotacoes');
        }

        $prazoRaw      = $this->input('prazo_resposta') ?? '';
        $prazoResposta = \DateTime::createFromFormat('Y-m-d\TH:i', $prazoRaw)
            ? (new \DateTime($prazoRaw))->format('Y-m-d H:i:s')
            : date('Y-m-d H:i:s', strtotime('+48 hours'));
        $usarCredito = (int)($this->input('usar_credito') === '1');

        $id = uuid();
        $db->prepare(
            "INSERT INTO cotacoes (id, requisicao_id, prazo_resposta, usar_credito, criado_por, criado_em)
             VALUES (?, ?, ?, ?, ?, NOW())"
        )->execute([$id, $requisicaoId, $prazoResposta, $usarCredito, $user['id']]);

        // Congela o escopo da cotação. Antes os itens eram lidos de requisicao_itens
        // ao vivo, então editar a requisição mudava o que já tinha sido enviado ao
        // fornecedor. Agora o que vale é o que foi pedido no momento da cotação.
        if ($ehAvulsa) {
            // Allowlist do catálogo: impede injetar item_id inexistente ou inativo
            $stmtItem = $db->prepare("SELECT 1 FROM itens WHERE id = ? AND ativo = 1 LIMIT 1");
            $stmtIns  = $db->prepare(
                "INSERT INTO cotacao_itens_solicitados (id, cotacao_id, item_id, descricao_livre, quantidade)
                 VALUES (?, ?, ?, NULL, ?)"
            );

            $jaGravado = [];
            foreach ($itemIds as $i => $itemId) {
                $qtd = parse_decimal($itemQtds[$i] ?? 0);
                if ($qtd <= 0 || isset($jaGravado[$itemId])) {
                    continue;
                }
                $stmtItem->execute([$itemId]);
                if (!$stmtItem->fetchColumn()) {
                    continue;
                }
                $stmtIns->execute([uuid(), $id, $itemId, $qtd]);
                $jaGravado[$itemId] = true;
            }
        } else {
            // O GROUP BY existe porque requisicao_itens NÃO tem unique em
            // (requisicao_id, item_id): a mesma requisição pode listar o mesmo
            // item em duas linhas, e sem agregar isso estoura o uk_cotacao_item.
            // Somar é a semântica correta — a cotação pede a quantidade total —
            // e casa com salvarResposta(), que já rejeita item repetido.
            $db->prepare(
                "INSERT INTO cotacao_itens_solicitados (id, cotacao_id, item_id, descricao_livre, quantidade)
                 SELECT UUID(), ?, ri.item_id,
                        CASE WHEN ri.item_id IS NULL THEN ri.descricao_livre END,
                        SUM(ri.quantidade)
                 FROM requisicao_itens ri
                 WHERE ri.requisicao_id = ?
                 GROUP BY ri.item_id,
                          CASE WHEN ri.item_id IS NULL THEN ri.descricao_livre END"
            )->execute([$id, $requisicaoId]);
        }

        // Busca dados da requisiÃ§Ã£o para o e-mail (grupo, filial, itens)
        $req = null;
        if (!$ehAvulsa) {
            $reqInfo = $db->prepare(
                "SELECT COALESCE(GROUP_CONCAT(DISTINCT gp.nome ORDER BY gp.nome SEPARATOR ', '), 'Diversos') AS grupo_nome,
                        fi.nome AS filial_nome,
                        (SELECT COUNT(*) FROM requisicao_itens WHERE requisicao_id = r.id) AS total_itens
                 FROM requisicoes r
                 JOIN filiais fi              ON fi.id = r.filial_id
                 LEFT JOIN requisicao_itens ri_g ON ri_g.requisicao_id = r.id
                 LEFT JOIN itens i_g             ON i_g.id = ri_g.item_id
                 LEFT JOIN grupos_produtos gp    ON gp.id = i_g.grupo_produto_id
                 WHERE r.id = ?
                 GROUP BY r.id, fi.nome
                 LIMIT 1"
            );
            $reqInfo->execute([$requisicaoId]);
            $req = $reqInfo->fetch();

            if ($req) {
                $itensStmt = $db->prepare(
                    "SELECT i.nome AS item_nome, ri.quantidade, i.unidade
                     FROM requisicao_itens ri
                     JOIN itens i ON i.id = ri.item_id
                     WHERE ri.requisicao_id = ?
                     ORDER BY i.nome"
                );
                $itensStmt->execute([$requisicaoId]);
                $req['itens'] = $itensStmt->fetchAll();
            }
        }

        // Associa fornecedores com token Ãºnico e envia e-mail de convite
        $fornIds = $_POST['fornecedor_id'] ?? [];
        foreach ($fornIds as $fid) {
            $token = bin2hex(random_bytes(16));
            $db->prepare(
                "INSERT INTO cotacao_fornecedores (id, cotacao_id, fornecedor_id, token_acesso, status)
                 VALUES (?, ?, ?, ?, 'enviada')"
            )->execute([uuid(), $id, $fid, $token]);

            // E-mail de convite desativado — envio manual pela tela de cotações
        }

        (new AuditoriaService())->log($user['id'], 'cotacao', $id, 'cotacao.criada');

        session_flash('success', 'CotaÃ§Ã£o criada com sucesso.');
        redirect('/compras/cotacoes');
    }

    public function comparativo(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT c.*, r.filial_id, r.valor_total AS valor_requisicao,
                    fi.nome AS filial_nome,
                    COALESCE(us.nome, '—') AS solicitante_nome
             FROM cotacoes c
             LEFT JOIN requisicoes r ON r.id = c.requisicao_id
             JOIN usuarios uc        ON uc.id = c.criado_por
             LEFT JOIN filiais fi    ON fi.id = COALESCE(r.filial_id, uc.filial_id)
             LEFT JOIN usuarios us   ON us.id = r.usuario_id
             WHERE c.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $cotacao = $stmt->fetch();
        if (!$cotacao) { redirect('/compras/cotacoes'); }

        // Fornecedores e suas respostas
        $stmt = $db->prepare(
            "SELECT cf.*, fo.nome_fantasia, fo.razao_social,
                    (SELECT COUNT(*) FROM cotacao_itens ci WHERE ci.cotacao_fornecedor_id = cf.id) AS qtd_itens_respondidos
             FROM cotacao_fornecedores cf
             JOIN fornecedores fo ON fo.id = cf.fornecedor_id
             WHERE cf.cotacao_id = ?"
        );
        $stmt->execute([$id]);
        $fornecedores = $stmt->fetchAll();

        // Itens respondidos por fornecedor
        $itensComparativos = [];
        foreach ($fornecedores as $cf) {
            $stmt = $db->prepare(
                "SELECT ci.*,
                        COALESCE(i.nome, ci.descricao) AS item_nome,
                        COALESCE(i.unidade, 'UN')      AS unidade
                 FROM cotacao_itens ci
                 LEFT JOIN itens i ON i.id = ci.item_id
                 WHERE ci.cotacao_fornecedor_id = ?"
            );
            $stmt->execute([$cf['id']]);
            $itensComparativos[$cf['id']] = $stmt->fetchAll();
        }

        // Só faz sentido para avulsa; nas demais fica array vazio e a view não
        // renderiza o seletor. É um mapa por cotacao_fornecedor_id porque a
        // regra depende do que AQUELE fornecedor cotou.
        $requisicoesCompativeis = [];
        if (empty($cotacao['requisicao_id'])) {
            foreach ($fornecedores as $f) {
                $requisicoesCompativeis[$f['id']] = array_map(
                    fn($r) => [
                        'id'    => $r['id'],
                        'label' => formatar_numero_requisicao($r['tipo'], $r['criado_em'], (int)$r['numero'])
                                   . ' — ' . $r['filial_nome'] . ' · ' . $r['setor_nome']
                                   . ' · ' . $r['solicitante_nome'],
                    ],
                    $this->requisicoesCompativeis($f['id'])
                );
            }
        }

        // Excesso por fornecedor. Na cotação avulsa a requisição só é escolhida
        // dentro do modal, então o excesso depende da opção selecionada — por isso
        // a chave carrega as duas partes ("fornecedor|requisicao"). Nas cotações
        // que já nascem com requisição, a chave é só o fornecedor.
        $excessos = [];
        foreach ($fornecedores as $f) {
            if (!empty($cotacao['requisicao_id'])) {
                $excessos[$f['id']] = $this->excessoDeItens($f['id'], $cotacao['requisicao_id']);
            } else {
                foreach ($requisicoesCompativeis[$f['id']] ?? [] as $r) {
                    $excessos[$f['id'] . '|' . $r['id']] = $this->excessoDeItens($f['id'], $r['id']);
                }
            }
        }

        // Política §8: mínimo de cotações pela estimativa (o gerarOC confere de novo
        // com o maior entre estimado e cotado).
        $politicaCot     = new \App\Services\PoliticaComprasService($db);
        $cotacoesValidas = $politicaCot->cotacoesValidas($id);
        $minimoPolitica  = $politicaCot->minimoCotacoes((float)($cotacao['valor_requisicao'] ?? 0));
        $regraCotacoes   = $politicaCot->descricaoRegraCotacoes();

        $prazosOc = $this->prazosSugeridosOC($cotacao, $fornecedores);

        $this->view('compras/comparativo', compact('cotacao', 'fornecedores', 'itensComparativos', 'requisicoesCompativeis', 'excessos',
            'cotacoesValidas', 'minimoPolitica', 'regraCotacoes', 'prazosOc'));
    }

    /**
     * Pré-preenchimento do "Gerar OC" (painel fase 2 + R005), por cotacao_fornecedor_id:
     * entrega prevista e condição de pagamento informadas na requisição tipo Ordem de
     * Compra (RequisicaoCotacao::prazosOc da vencedora); senão a condição sai da proposta
     * — da requisição (requisicao_cotacoes.condicao_pagamento) ou do portal
     * (cotacao_fornecedores.observacao, só quando CondicaoPagamento a reconhece).
     * O que foi digitado num POST recusado (oc_prazos_old) tem precedência.
     *
     * @return array<string, array{entrega: string, condicao_oc: string, condicao: string}>
     */
    private function prazosSugeridosOC(array $cotacao, array $fornecedores): array
    {
        $rcPorFornecedor = [];
        if (!empty($cotacao['requisicao_id'])) {
            $stmt = Database::getInstance()->prepare(
                "SELECT fornecedor_id, condicao_pagamento, observacao, vencedora
                 FROM requisicao_cotacoes WHERE requisicao_id = ?"
            );
            $stmt->execute([$cotacao['requisicao_id']]);
            foreach ($stmt->fetchAll() as $rc) {
                $rcPorFornecedor[$rc['fornecedor_id']] = $rc;
            }
        }

        $old = $_SESSION['oc_prazos_old'] ?? null;
        unset($_SESSION['oc_prazos_old']);

        $hoje = date('Y-m-d');
        $out  = [];
        foreach ($fornecedores as $f) {
            $rc       = $rcPorFornecedor[$f['fornecedor_id']] ?? null;
            $condicao = trim((string)($rc['condicao_pagamento'] ?? ''));
            if ($condicao === '') {
                $condicao = trim((string)($f['observacao'] ?? ''));
            }
            $informado = $rc && (int)$rc['vencedora'] === 1
                ? \App\Models\RequisicaoCotacao::prazosOc($rc['observacao'] ?? null)
                : \App\Models\RequisicaoCotacao::prazosOc(null);

            $condicaoOc = (string)($informado['condicao_pagamento'] ?? '');
            if ($condicaoOc === '' && $condicao !== '') {
                // "Condição de pagamento: 30/60" (OC direta) → "30/60"; texto livre do
                // portal só entra se for uma condição reconhecível e curta.
                $semPrefixo = trim((string)preg_replace('/^condi(ç|c)(ã|a)o de pagamento\s*:\s*/iu', '', $condicao));
                if ($semPrefixo !== '' && mb_strlen($semPrefixo) <= \App\Services\CondicaoPagamento::MAX_TEXTO
                    && \App\Services\CondicaoPagamento::parcelas($semPrefixo) !== null) {
                    $condicaoOc = $semPrefixo;
                }
            }
            $entrega = ($informado['entrega_prevista'] ?? '') >= $hoje ? (string)$informado['entrega_prevista'] : '';

            $out[$f['id']] = [
                'entrega'     => $entrega,
                'condicao_oc' => $condicaoOc,
                'condicao'    => mb_substr($condicao, 0, 120),
            ];
            if (is_array($old) && ($old['cotacao_fornecedor_id'] ?? '') === $f['id']) {
                $out[$f['id']]['entrega']     = (string)($old['entrega'] ?? '');
                if (trim((string)($old['condicao'] ?? '')) !== '') {
                    $out[$f['id']]['condicao_oc'] = (string)$old['condicao'];
                }
            }
        }

        return $out;
    }

    /**
     * Requisições que podem receber esta cotação: aprovadas, sem OC, e que
     * contenham TODOS os itens cotados pelo fornecedor vencedor.
     *
     * A regra é aplicada como filtro do seletor e revalidada no POST — o filtro
     * é conveniência, não é a defesa.
     */
    private function requisicoesCompativeis(string $cotacaoFornecedorId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT r.id, r.numero, r.criado_em, r.tipo, r.valor_total,
                    f.nome AS filial_nome, s.nome AS setor_nome, u.nome AS solicitante_nome
             FROM requisicoes r
             JOIN filiais  f ON f.id = r.filial_id
             JOIN setores  s ON s.id = r.setor_id
             JOIN usuarios u ON u.id = r.usuario_id
             WHERE r.status = 'aprovado'
               -- Requisição com OC continua elegível: com compra fracionada ela
               -- pode receber outra parte. Sem isso o sistema ficaria incoerente,
               -- permitindo abrir segunda cotação pela requisição mas não deixando
               -- uma avulsa se ligar a ela.
               AND NOT EXISTS (
                   SELECT 1 FROM cotacao_itens ci
                   WHERE ci.cotacao_fornecedor_id = ?
                     AND ci.item_id NOT IN (
                         SELECT ri.item_id FROM requisicao_itens ri
                         WHERE ri.requisicao_id = r.id AND ri.item_id IS NOT NULL
                     )
               )
             ORDER BY r.criado_em DESC"
        );
        $stmt->execute([$cotacaoFornecedorId]);
        return $stmt->fetchAll();
    }

    /**
     * Itens em que esta compra faria a requisição ultrapassar o que foi pedido.
     *
     * Compara três quantidades por item: o que a requisição pediu, o que as OCs
     * dela já compraram, e o que este fornecedor está vendendo agora. OC
     * cancelada não conta como comprada — ela não comprou nada.
     *
     * @return array<int, array{nome: string, pedida: float, comprada: float, agora: float, excesso: float}>
     */
    private function excessoDeItens(string $cotacaoFornecedorId, string $requisicaoId): array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT i.nome,
                    COALESCE(ped.qtd, 0)  AS pedida,
                    COALESCE(comp.qtd, 0) AS comprada,
                    ci.qtd                AS agora
             FROM (
                 SELECT item_id, SUM(quantidade) AS qtd
                 FROM cotacao_itens
                 WHERE cotacao_fornecedor_id = ?
                 GROUP BY item_id
             ) ci
             JOIN itens i ON i.id = ci.item_id
             LEFT JOIN (
                 SELECT item_id, SUM(quantidade) AS qtd
                 FROM requisicao_itens
                 WHERE requisicao_id = ? AND item_id IS NOT NULL
                 GROUP BY item_id
             ) ped ON ped.item_id = ci.item_id
             LEFT JOIN (
                 SELECT oci.item_id, SUM(oci.quantidade) AS qtd
                 FROM ordens_compra_itens oci
                 JOIN ordens_compra oc ON oc.id = oci.oc_id
                 WHERE oc.requisicao_id = ? AND oc.status <> 'cancelado'
                 GROUP BY oci.item_id
             ) comp ON comp.item_id = ci.item_id
             HAVING comprada + agora > pedida
             ORDER BY i.nome"
        );
        $stmt->execute([$cotacaoFornecedorId, $requisicaoId, $requisicaoId]);

        return array_map(fn($r) => [
            'nome'     => $r['nome'],
            'pedida'   => (float)$r['pedida'],
            'comprada' => (float)$r['comprada'],
            'agora'    => (float)$r['agora'],
            'excesso'  => (float)$r['comprada'] + (float)$r['agora'] - (float)$r['pedida'],
        ], $stmt->fetchAll());
    }

    public function gerarOC(string $cotacaoId): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','admin']);
        $user = auth();
        $db   = Database::getInstance();

        $cotFornId = $this->input('cotacao_fornecedor_id');
        if (!$cotFornId) {
            session_flash('error', 'Selecione um fornecedor para gerar a OC.');
            redirect('/compras/cotacoes/' . $cotacaoId . '/comparativo');
        }

        // Painel fase 2: entrega prevista (>= hoje) e condição de pagamento (parcelas
        // 0–365 dias, até 12 — R005) são obrigatórias. Validadas antes de qualquer
        // gravação; o que foi digitado volta para o modal (comparativo lê oc_prazos_old).
        $prazosOc = \App\Models\OrdemCompra::validarPrazos(
            $this->input('entrega_prevista', ''), $this->input('condicao_pagamento', ''), date('Y-m-d')
        );
        if (is_string($prazosOc)) {
            $_SESSION['oc_prazos_old'] = [
                'cotacao_fornecedor_id' => (string)$cotFornId,
                'entrega'               => mb_substr(trim((string)$this->input('entrega_prevista', '')), 0, 10),
                'condicao'              => mb_substr(trim((string)$this->input('condicao_pagamento', '')), 0, \App\Services\CondicaoPagamento::MAX_TEXTO),
            ];
            session_flash('error', $prazosOc);
            redirect('/compras/cotacoes/' . $cotacaoId . '/comparativo');
        }

        // Busca cotaÃ§Ã£o e fornecedor
        $cf = $db->prepare(
            "SELECT cf.*, c.requisicao_id, fo.id AS forn_id
             FROM cotacao_fornecedores cf
             JOIN cotacoes c    ON c.id  = cf.cotacao_id
             JOIN fornecedores fo ON fo.id = cf.fornecedor_id
             WHERE cf.id = ? LIMIT 1"
        );
        $cf->execute([$cotFornId]);
        $cf = $cf->fetch();
        if (!$cf) { redirect('/compras/cotacoes'); }

        // Fornecedor vencedor bloqueado (ou inativo) não recebe OC.
        if ($msg = $this->fornecedorSemCotacao([$cf['forn_id']], 'OC')) {
            session_flash('error', $msg);
            redirect('/compras/cotacoes/' . $cotacaoId . '/comparativo');
        }

        // Cotação avulsa: a requisição é escolhida agora, e revalidada aqui —
        // o filtro do seletor é conveniência, não é a defesa.
        if (empty($cf['requisicao_id'])) {
            $reqEscolhida = $this->input('requisicao_id');

            if (!$reqEscolhida) {
                session_flash('error', 'Esta cotação não tem requisição. Selecione uma para gerar a OC.');
                redirect('/compras/cotacoes/' . $cotacaoId . '/comparativo');
            }

            $compativeis = array_column($this->requisicoesCompativeis($cotFornId), 'id');
            if (!in_array($reqEscolhida, $compativeis, true)) {
                session_flash('error', 'Requisição inválida: precisa estar aprovada, sem ordem de compra, e conter todos os itens cotados.');
                redirect('/compras/cotacoes/' . $cotacaoId . '/comparativo');
            }

            $db->prepare("UPDATE cotacoes SET requisicao_id = ? WHERE id = ?")
               ->execute([$reqEscolhida, $cotacaoId]);

            $cf['requisicao_id'] = $reqEscolhida;

            (new AuditoriaService())->log(
                $user['id'], 'cotacao', $cotacaoId, 'cotacao.requisicao_vinculada',
                null, ['requisicao_id' => $reqEscolhida]
            );
        }
        // â”€â”€ Aviso de limite de crÃ©dito â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $stmtCred = $db->prepare(
            "SELECT f.credito_limite,
                    COALESCE((SELECT SUM(valor_total) FROM ordens_compra
                              WHERE fornecedor_id = f.id
                                AND status IN ('aprovado','enviado','recebido_parcial')), 0) AS utilizado
             FROM fornecedores f WHERE f.id = ?"
        );
        $stmtCred->execute([$cf['forn_id']]);
        $credInfo = $stmtCred->fetch();

        $stmtVal = $db->prepare(
            "SELECT COALESCE(SUM(quantidade * valor_unitario), 0)
             FROM cotacao_itens WHERE cotacao_fornecedor_id = ?"
        );
        $stmtVal->execute([$cotFornId]);
        $valorNovaOC = (float)$stmtVal->fetchColumn();

        $credLimite    = (float)($credInfo['credito_limite'] ?? 0);
        $credUtilizado = (float)($credInfo['utilizado'] ?? 0);
        if ($credLimite > 0 && ($credUtilizado + $valorNovaOC) > $credLimite) {
            session_flash('warning', sprintf(
                'AtenÃ§Ã£o: esta OC (R$ %s) ultrapassou o limite de crÃ©dito do fornecedor. ' .
                'Saldo utilizado apÃ³s emissÃ£o: R$ %s / Limite: R$ %s.',
                number_format($valorNovaOC, 2, ',', '.'),
                number_format($credUtilizado + $valorNovaOC, 2, ',', '.'),
                number_format($credLimite, 2, ',', '.')
            ));
        }
        // â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€


        // Aviso de duplicidade: comprar acima do pedido é permitido, mas exige
        // confirmação explícita. O aviso na tela é conveniência; a defesa é aqui.
        // Política de Compras §8: mínimo de cotações pelo valor (2 até R$ 1.000; 3
        // acima), sobre o maior entre o estimado e o cotado. Abaixo do mínimo, só com
        // justificativa (fornecedor exclusivo / emergência), gravada na OC.
        $politica = new \App\Services\PoliticaComprasService($db);
        $stmtEst  = $db->prepare("SELECT valor_total FROM requisicoes WHERE id = ?");
        $stmtEst->execute([$cf['requisicao_id']]);
        $valorRef           = max((float)$stmtEst->fetchColumn(), $valorNovaOC);
        $minimoCotacoes     = $politica->minimoCotacoes($valorRef);
        $cotacoesValidas    = $politica->cotacoesValidas($cotacaoId);
        $justificativaExc   = trim((string)$this->input('justificativa_excecao', ''));
        if ($cotacoesValidas < $minimoCotacoes && mb_strlen($justificativaExc) < 15) {
            session_flash('error', sprintf(
                'A Política de Compras exige %d cotações para uma compra de R$ %s; esta tem %d. '
                . 'Para seguir assim, informe a justificativa (fornecedor exclusivo ou emergência).',
                $minimoCotacoes, number_format($valorRef, 2, ',', '.'), $cotacoesValidas
            ));
            redirect('/compras/cotacoes/' . $cotacaoId . '/comparativo');
        }
        $excecaoCotacoes = $cotacoesValidas < $minimoCotacoes ? $justificativaExc : null;

        $excesso = $this->excessoDeItens($cotFornId, $cf['requisicao_id']);
        if (!empty($excesso) && $this->input('confirma_excesso') !== '1') {
            $itensTexto = implode('; ', array_map(
                fn($e) => $e['nome'] . ' (pedido ' . rtrim(rtrim(number_format($e['pedida'], 2, ',', '.'), '0'), ',')
                        . ', excede em ' . rtrim(rtrim(number_format($e['excesso'], 2, ',', '.'), '0'), ',') . ')',
                $excesso
            ));
            session_flash('error', 'Esta compra ultrapassa o pedido da requisição: ' . $itensTexto . '. Confirme no formulário para prosseguir.');
            redirect('/compras/cotacoes/' . $cotacaoId . '/comparativo');
        }

        // Numero da OC herda o numero sequencial da requisicao: OC-YYYY-NNNNN.
        // Com compra fracionada a mesma requisicao gera varias OCs, e numero_oc
        // tem UNIQUE (uk_oc_numero) — da segunda em diante entra o sufixo da parte.
        //
        // O calculo da parte e o INSERT ficam numa transacao curta: sem ela, o
        // FOR UPDATE nao segura lock nenhum (autocommit libera na hora) e duas
        // geracoes simultaneas leriam a mesma parte, estourando no unique.
        // So isso entra na transacao — itens, historico e orcamento seguem fora,
        // como sempre estiveram.
        $stmtReqNum = $db->prepare(
            "SELECT numero, YEAR(criado_em) AS ano FROM requisicoes WHERE id = ?"
        );
        $stmtReqNum->execute([$cf['requisicao_id']]);
        $reqNum = $stmtReqNum->fetch(\PDO::FETCH_ASSOC);

        $ocId = uuid();

        try {
            $db->beginTransaction();

            $stmtParte = $db->prepare(
                "SELECT COALESCE(MAX(parte), 0) + 1
                 FROM ordens_compra
                 WHERE requisicao_id = ?
                 FOR UPDATE"
            );
            $stmtParte->execute([$cf['requisicao_id']]);
            $parte = (int)$stmtParte->fetchColumn();

            $numOC = 'OC-' . ($reqNum['ano'] ?? date('Y'))
                   . '-' . str_pad((int)($reqNum['numero'] ?? 0), 5, '0', STR_PAD_LEFT)
                   . ($parte > 1 ? '-' . $parte : '');

            $db->prepare(
                "INSERT INTO ordens_compra (id, numero_oc, requisicao_id, parte, cotacao_id, fornecedor_id, valor_total, valor_credito_aplicado,
                                            entrega_prevista, prazo_pagamento_dias, condicao_pagamento, parcelas_dias,
                                            status, criado_por, criado_em)
                 VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, 'aprovado', ?, NOW())"
            )->execute([$ocId, $numOC, $cf['requisicao_id'], $parte, $cotacaoId, $cf['forn_id'],
                        $prazosOc['entrega_prevista'], $prazosOc['prazo_pagamento_dias'],
                        $prazosOc['condicao_pagamento'], $prazosOc['parcelas_dias'], $user['id']]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('[CompraController] Falha ao numerar/criar OC: ' . $e->getMessage());
            session_flash('error', 'Não foi possível gerar a ordem de compra. Tente novamente.');
            redirect('/compras/cotacoes/' . $cotacaoId . '/comparativo');
        }

        // Copia itens da resposta do fornecedor. item_id nulo = linha de texto
        // livre (Ordem de Compra): a descrição vai junto, e o histórico de preços
        // é pulado porque historico_precos.item_id exige item de catálogo.
        $itens = $db->prepare(
            "SELECT ci.item_id, ci.descricao, ci.quantidade, ci.valor_unitario
             FROM cotacao_itens ci
             WHERE ci.cotacao_fornecedor_id = ?"
        );
        $itens->execute([$cotFornId]);

        foreach ($itens->fetchAll() as $item) {
            $db->prepare(
                "INSERT INTO ordens_compra_itens (id, oc_id, item_id, descricao, quantidade, valor_unitario)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([uuid(), $ocId, $item['item_id'], $item['descricao'], $item['quantidade'], $item['valor_unitario']]);

            if (!empty($item['item_id'])) {
                $db->prepare(
                    "INSERT INTO historico_precos (id, item_id, fornecedor_id, valor_unitario, origem, origem_id)
                     VALUES (?, ?, ?, ?, 'ordem_compra', ?)"
                )->execute([uuid(), $item['item_id'], $cf['forn_id'], $item['valor_unitario'], $ocId]);
            }
        }

        // Calcula e atualiza total da OC
        $db->prepare(
            "UPDATE ordens_compra SET valor_total = (
                SELECT COALESCE(SUM(quantidade * valor_unitario), 0)
                FROM ordens_compra_itens WHERE oc_id = ?
             ) WHERE id = ?"
        )->execute([$ocId, $ocId]);

        // O frete é do fornecedor na cotação, e fica separado do valor dos produtos
        // (é assim que o Relatório de Compras compara OC com nota fiscal). Sem esta
        // cópia, o pedido aprovado por produtos+frete vira uma OC só de produtos.
        $db->prepare("UPDATE ordens_compra SET frete_previsto = ? WHERE id = ?")
           ->execute([(float)($cf['frete'] ?? 0), $ocId]);

        // Encerra a cotaÃ§Ã£o
        $db->prepare("UPDATE cotacoes SET status = 'encerrada' WHERE id = ?")->execute([$cotacaoId]);

        // â”€â”€ Aplicar crÃ©ditos: POST tem prioridade sobre flag da cotaÃ§Ã£o â”€â”€â”€â”€â”€â”€
        // Aplicar credito parcial: usuario digita o valor a usar
        $valorCreditoPOST = parse_decimal($this->input('valor_credito', '0'));
        $usarCreditoPOST  = $this->input('usar_credito');
        $aplicarCredito   = ($usarCreditoPOST === '1') && $valorCreditoPOST > 0;

        if ($aplicarCredito) {
            $credStmt = $db->prepare(
                "SELECT id, valor FROM creditos_fornecedor
                 WHERE fornecedor_id = ? AND usado = 0
                   AND (validade IS NULL OR validade >= CURDATE())
                 ORDER BY validade ASC, valor ASC"
            );
            $credStmt->execute([$cf['forn_id']]);
            $creditos = $credStmt->fetchAll();

            $restante     = round($valorCreditoPOST, 2);
            $totalCredito = 0;

            foreach ($creditos as $cred) {
                if ($restante <= 0) break;
                $valorCred = round((float)$cred['valor'], 2);
                if ($valorCred <= $restante) {
                    // Usa credito inteiro
                    $db->prepare(
                        "UPDATE creditos_fornecedor SET usado = 1, cotacao_id = ?, oc_id = ? WHERE id = ?"
                    )->execute([$cotacaoId, $ocId, $cred['id']]);
                    $totalCredito += $valorCred;
                    $restante     -= $valorCred;
                } else {
                    // Usa parcialmente: marca original como usado, cria saldo residual
                    $db->prepare(
                        "UPDATE creditos_fornecedor SET usado = 1, cotacao_id = ?, oc_id = ? WHERE id = ?"
                    )->execute([$cotacaoId, $ocId, $cred['id']]);
                    $totalCredito += $restante;
                    // Cria credito residual
                    $db->prepare(
                        "INSERT INTO creditos_fornecedor (id, fornecedor_id, valor, descricao, validade, usado, criado_por)
                         SELECT ?, fornecedor_id, ?, CONCAT(descricao, ' (saldo)'), validade, 0, criado_por
                         FROM creditos_fornecedor WHERE id = ?"
                    )->execute([uuid(), round($valorCred - $restante, 2), $cred['id']]);
                    $restante = 0;
                }
            }

            if ($totalCredito > 0) {
                $db->prepare(
                    "UPDATE ordens_compra SET valor_credito_aplicado = ? WHERE id = ?"
                )->execute([round($totalCredito, 2), $ocId]);
                session_flash('info',
                    'Credito aplicado a OC: R$ ' . number_format($totalCredito, 2, ',', '.') .
                    '. A cobrar do fornecedor: R$ ' . number_format(max(0, $valorNovaOC - $totalCredito), 2, ',', '.'));
            }
        }
        // Nota: o estoque só entra de fato na conferência da NF (FiscalController::confirmarConferencia),
        // que é quando a mercadoria é efetivamente recebida. A reserva feita na aprovação da requisição
        // (AprovacaoController) só é liberada nesse momento — gerar a OC é apenas formalizar o pedido.

        (new AuditoriaService())->log($user['id'], 'ordem_compra', $ocId, 'oc.gerada',
            null, ['numero_oc' => $numOC] + $prazosOc);

        if ($excecaoCotacoes !== null) {
            $db->prepare("UPDATE ordens_compra SET justificativa_excecao = ? WHERE id = ?")
               ->execute([$excecaoCotacoes, $ocId]);
            (new AuditoriaService())->log($user['id'], 'ordem_compra', $ocId, 'oc.excecao_cotacoes', null, [
                'minimo' => $minimoCotacoes, 'validas' => $cotacoesValidas, 'justificativa' => $excecaoCotacoes,
            ]);
        }

        // Reaprovação: a alçada foi aplicada sobre o valor estimado da requisição.
        // Se o total cotado (todas as OCs vivas da requisição) subiu de faixa, a
        // OC fica em rascunho — sem efeito no Fiscal e nos relatórios — até a
        // nova alçada aprovar (AprovacaoController::liberarEntidade).
        $vals = $db->prepare(
            "SELECT r.valor_total AS estimado,
                    (SELECT COALESCE(SUM(o.valor_total), 0) FROM ordens_compra o
                     WHERE o.requisicao_id = r.id AND o.status <> 'cancelado') AS cotado
             FROM requisicoes r WHERE r.id = ?"
        );
        $vals->execute([$cf['requisicao_id']]);
        $vals = $vals->fetch();
        $aprovModel = new \App\Models\Aprovacao();
        if ($vals && $aprovModel->exigeReaprovacao((float)$vals['estimado'], (float)$vals['cotado'])) {
            $db->prepare("UPDATE ordens_compra SET status = 'rascunho' WHERE id = ?")->execute([$ocId]);
            $aprovModel->criarParaOrdemCompra($ocId, (float)$vals['cotado']);
            (new AuditoriaService())->log($user['id'], 'ordem_compra', $ocId, 'oc.reaprovacao',
                null, ['estimado' => (float)$vals['estimado'], 'cotado' => (float)$vals['cotado']]);
            session_flash('warning', sprintf(
                'Ordem de Compra %s gerada, mas o valor cotado (R$ %s) passou da faixa aprovada para a estimativa (R$ %s). '
                . 'Ela fica em rascunho até a nova alçada aprovar em Aprovações.',
                $numOC,
                number_format((float)$vals['cotado'], 2, ',', '.'),
                number_format((float)$vals['estimado'], 2, ',', '.')
            ));
            redirect('/compras/ordens/' . $ocId);
        }

        (new \App\Services\SolicitacaoCompraService())->aoGerarOrdemCompra($ocId, $user);

        session_flash('success', "Ordem de Compra {$numOC} gerada com sucesso.");
        redirect('/compras/ordens/' . $ocId);
    }

    // â”€â”€ Ordens de Compra â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function ordens(): void
    {
        $this->requireAuth();
        $user = auth();
        $db   = Database::getInstance();

        $sql = "SELECT oc.*, fo.nome_fantasia AS fornecedor_nome,
                       r.filial_id, fi.nome AS filial_nome,
                       u.nome AS solicitante_nome
                FROM ordens_compra oc
                JOIN fornecedores fo ON fo.id = oc.fornecedor_id
                JOIN requisicoes  r  ON r.id  = oc.requisicao_id
                JOIN filiais fi      ON fi.id = r.filial_id
                JOIN usuarios u      ON u.id  = oc.criado_por
                WHERE 1=1";
        $params = [];

        if (!ve_todas_filiais()) {
            $sql .= " AND r.filial_id = ?";
            $params[] = $user['filial_id'];
        }
        $sql .= " ORDER BY oc.criado_em DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $ordens = $stmt->fetchAll();

        $this->view('compras/ordens', compact('ordens', 'user'));
    }

    public function showOC(string $id): void
    {
        $this->requireAuth();
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT oc.*, fo.nome_fantasia AS fornecedor_nome, fo.cnpj AS fornecedor_cnpj,
                    r.filial_id, fi.nome AS filial_nome, u.nome AS solicitante_nome
             FROM ordens_compra oc
             JOIN fornecedores fo ON fo.id = oc.fornecedor_id
             JOIN requisicoes  r  ON r.id  = oc.requisicao_id
             JOIN filiais fi      ON fi.id = r.filial_id
             JOIN usuarios u      ON u.id  = oc.criado_por
             WHERE oc.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $oc = $stmt->fetch();
        if (!$oc) { redirect('/compras/ordens'); }
        $this->exigirFilialDaOC($oc);

        $itensStmt = $db->prepare(
            "SELECT oci.*, i.nome AS item_nome, i.unidade
             FROM ordens_compra_itens oci
             LEFT JOIN itens i ON i.id = oci.item_id
             WHERE oci.oc_id = ?"
        );
        $itensStmt->execute([$id]);
        $itens = $itensStmt->fetchAll();

        // Marcações 06/08/09: quem marcou + barra de andamento da requisição.
        $stmtNomes = $db->prepare(
            "SELECT ue.nome AS enviada_por_nome, ut.nome AS transito_por_nome, ur.nome AS recebimento_registrado_por_nome
             FROM ordens_compra oc
             LEFT JOIN usuarios ue ON ue.id = oc.enviada_por
             LEFT JOIN usuarios ut ON ut.id = oc.transito_por
             LEFT JOIN usuarios ur ON ur.id = oc.recebimento_registrado_por
             WHERE oc.id = ?"
        );
        $stmtNomes->execute([$id]);
        $oc += $stmtNomes->fetch() ?: [];
        $podeMarcar = can(self::PERFIS_MARCAM_OC) && !in_array($oc['status'], ['rascunho', 'cancelado'], true);
        $andamento  = (new \App\Services\AndamentoService($db))->daRequisicao($oc['requisicao_id']);

        $this->view('compras/oc_detalhe', compact('oc', 'itens', 'podeMarcar', 'andamento'));
    }

    /**
     * Corrige entrega prevista e prazo de pagamento no detalhe da OC (OCs antigas,
     * sem os campos, ou correção). Comprador/admin; OC cancelada não muda. A entrega
     * pode ficar no passado (OC antiga), mas não antes da emissão da OC.
     */
    public function salvarPrazosOC(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','admin']);
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT oc.id, oc.status, oc.criado_em, oc.entrega_prevista, oc.prazo_pagamento_dias,
                    oc.condicao_pagamento, oc.parcelas_dias, r.filial_id
             FROM ordens_compra oc
             JOIN requisicoes r ON r.id = oc.requisicao_id
             WHERE oc.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $oc = $stmt->fetch();
        if (!$oc) { redirect('/compras/ordens'); }
        $this->exigirFilialDaOC($oc);

        if ($oc['status'] === 'cancelado') {
            session_flash('error', 'Ordem de compra cancelada não pode ser alterada.');
            redirect('/compras/ordens/' . $id);
        }

        $prazos = \App\Models\OrdemCompra::validarPrazos(
            $this->input('entrega_prevista', ''), $this->input('condicao_pagamento', ''), substr((string)$oc['criado_em'], 0, 10)
        );
        if (is_string($prazos)) {
            session_flash('error', $prazos);
            redirect('/compras/ordens/' . $id);
        }

        $db->prepare(
            "UPDATE ordens_compra SET entrega_prevista = ?, prazo_pagamento_dias = ?, condicao_pagamento = ?, parcelas_dias = ?
             WHERE id = ?"
        )->execute([$prazos['entrega_prevista'], $prazos['prazo_pagamento_dias'],
                    $prazos['condicao_pagamento'], $prazos['parcelas_dias'], $id]);

        (new AuditoriaService())->log(auth()['id'], 'ordem_compra', $id, 'oc.prazos_alterados',
            ['entrega_prevista'     => $oc['entrega_prevista'],
             'prazo_pagamento_dias' => $oc['prazo_pagamento_dias'] !== null ? (int)$oc['prazo_pagamento_dias'] : null,
             'condicao_pagamento'   => $oc['condicao_pagamento'],
             'parcelas_dias'        => $oc['parcelas_dias']],
            $prazos);

        session_flash('success', 'Entrega prevista e condição de pagamento atualizadas.');
        redirect('/compras/ordens/' . $id);
    }

    // ── Marcações manuais da OC (S5, spec 2026-10-30): etapas 06, 08 e 09 ──────
    // Comprador, almoxarife e admin. Cada marcação é feita uma vez (UPDATE
    // condicionado), vai para logs_auditoria e vira andamento na SOL de origem.

    /** Perfis que fazem as marcações 06/08/09 na OC. */
    public const PERFIS_MARCAM_OC = ['comprador', 'almoxarife', 'admin'];

    /** 06 — OC enviada ao fornecedor: grava a data e passa o status de 'aprovado' para 'enviado'. */
    public function marcarEnviadaOC(string $id): void
    {
        $oc = $this->ocParaMarcar($id);
        $upd = Database::getInstance()->prepare(
            "UPDATE ordens_compra
             SET enviada_em = NOW(), enviada_por = ?, status = IF(status = 'aprovado', 'enviado', status)
             WHERE id = ? AND enviada_em IS NULL AND status NOT IN ('rascunho', 'cancelado')"
        );
        $upd->execute([auth()['id'], $id]);
        if ($upd->rowCount() === 0) {
            session_flash('error', 'Esta OC já foi marcada como enviada.');
            redirect('/compras/ordens/' . $id);
        }
        (new AuditoriaService())->log(auth()['id'], 'ordem_compra', $id, 'oc.enviada',
            ['status' => $oc['status']], ['status' => $oc['status'] === 'aprovado' ? 'enviado' : $oc['status']]);
        (new \App\Services\SolicitacaoCompraService())->aoMarcarOc($id, 'enviada', auth());
        session_flash('success', 'OC marcada como enviada ao fornecedor.');
        redirect('/compras/ordens/' . $id);
    }

    /** 08 — Em trânsito, com o nº do CT-e opcional. */
    public function marcarTransitoOC(string $id): void
    {
        $this->ocParaMarcar($id);
        $cte = trim((string)$this->input('cte_numero', ''));
        if (!mb_check_encoding($cte, 'UTF-8') || mb_strlen($cte) > 60) {
            session_flash('error', 'O nº do CT-e pode ter no máximo 60 caracteres (letras e números).');
            redirect('/compras/ordens/' . $id);
        }
        $upd = Database::getInstance()->prepare(
            "UPDATE ordens_compra SET transito_em = NOW(), transito_por = ?, cte_numero = ?
             WHERE id = ? AND transito_em IS NULL AND status NOT IN ('rascunho', 'cancelado')"
        );
        $upd->execute([auth()['id'], $cte !== '' ? $cte : null, $id]);
        if ($upd->rowCount() === 0) {
            session_flash('error', 'Esta OC já foi marcada como em trânsito.');
            redirect('/compras/ordens/' . $id);
        }
        (new AuditoriaService())->log(auth()['id'], 'ordem_compra', $id, 'oc.em_transito',
            null, ['cte_numero' => $cte !== '' ? $cte : null]);
        (new \App\Services\SolicitacaoCompraService())->aoMarcarOc($id, 'transito', auth(),
            $cte !== '' ? 'CT-e ' . $cte : '');
        session_flash('success', 'OC marcada como em trânsito.');
        redirect('/compras/ordens/' . $id);
    }

    /** 09 — Mercadoria recebida: data (entre a emissão da OC e hoje) e quem recebeu. */
    public function marcarRecebimentoOC(string $id): void
    {
        $oc   = $this->ocParaMarcar($id);
        $data = trim((string)$this->input('recebido_fisico_em', ''));
        $quem = trim((string)$this->input('recebido_fisico_por', ''));
        $dt   = \DateTime::createFromFormat('!Y-m-d', $data);
        $erro = null;
        if (!$dt || $dt->format('Y-m-d') !== $data) {
            $erro = 'Informe a data do recebimento.';
        } elseif ($data > date('Y-m-d')) {
            $erro = 'A data do recebimento não pode estar no futuro.';
        } elseif ($data < substr((string)$oc['criado_em'], 0, 10)) {
            $erro = 'A data do recebimento não pode ser anterior à emissão da OC.';
        } elseif ($quem === '') {
            $erro = 'Informe quem recebeu a mercadoria.';
        } elseif (!mb_check_encoding($quem, 'UTF-8')) {
            $erro = '"Quem recebeu" tem caracteres inválidos.';
        } elseif (mb_strlen($quem) > 150) {
            $erro = '"Quem recebeu" pode ter no máximo 150 caracteres.';
        }
        if ($erro) {
            session_flash('error', $erro);
            redirect('/compras/ordens/' . $id);
        }
        $upd = Database::getInstance()->prepare(
            "UPDATE ordens_compra SET recebido_fisico_em = ?, recebido_fisico_por = ?, recebimento_registrado_por = ?
             WHERE id = ? AND recebido_fisico_em IS NULL AND status NOT IN ('rascunho', 'cancelado')"
        );
        $upd->execute([$data, $quem, auth()['id'], $id]);
        if ($upd->rowCount() === 0) {
            session_flash('error', 'O recebimento desta OC já foi registrado.');
            redirect('/compras/ordens/' . $id);
        }
        (new AuditoriaService())->log(auth()['id'], 'ordem_compra', $id, 'oc.recebimento_fisico',
            null, ['recebido_fisico_em' => $data, 'recebido_fisico_por' => $quem]);
        (new \App\Services\SolicitacaoCompraService())->aoMarcarOc($id, 'recebimento', auth(),
            'em ' . $dt->format('d/m/Y') . ' por ' . $quem);
        session_flash('success', 'Recebimento físico registrado.');
        redirect('/compras/ordens/' . $id);
    }

    /**
     * Mensagem de erro se algum dos fornecedores não pode receber cotação/OC
     * (situação 'bloqueado' ou inativo — Fornecedor::podeCotar); null se todos podem.
     * @param string $oque 'cotação' ou 'OC', só para o texto
     */
    private function fornecedorSemCotacao(array $ids, string $oque = 'cotação'): ?string
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        if (!$ids) return null;
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT id, ativo, situacao, COALESCE(NULLIF(nome_fantasia, ''), razao_social) AS nome
             FROM fornecedores WHERE id IN ($in)"
        );
        $stmt->execute($ids);
        $achados = 0;
        foreach ($stmt->fetchAll() as $f) {
            $achados++;
            if (!Fornecedor::podeCotar($f)) {
                return sprintf('Fornecedor %s está %s e não pode receber %s.', $f['nome'],
                    ($f['situacao'] ?? '') === 'bloqueado' ? 'bloqueado' : 'inativo', $oque);
            }
        }
        return $achados === count($ids) ? null : 'Fornecedor inválido.';
    }

    /**
     * OC para as marcações: perfil e filial. Rascunho (aguarda reaprovação da
     * alçada) e cancelada não recebem marcação.
     */
    private function ocParaMarcar(string $id): array
    {
        $this->requireAuth();
        $this->requirePerfil(self::PERFIS_MARCAM_OC);
        $stmt = Database::getInstance()->prepare(
            "SELECT oc.id, oc.status, oc.criado_em, r.filial_id
             FROM ordens_compra oc
             JOIN requisicoes r ON r.id = oc.requisicao_id
             WHERE oc.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $oc = $stmt->fetch();
        if (!$oc) { redirect('/compras/ordens'); }
        $this->exigirFilialDaOC($oc);
        if (in_array($oc['status'], ['rascunho', 'cancelado'], true)) {
            session_flash('error', $oc['status'] === 'rascunho'
                ? 'Esta OC aguarda reaprovação da alçada e ainda não pode receber marcações.'
                : 'Ordem de compra cancelada não pode ser alterada.');
            redirect('/compras/ordens/' . $id);
        }
        return $oc;
    }

    // â”€â”€ Resposta de CotaÃ§Ã£o (link externo para fornecedor) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function responderCotacao(string $token): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT cf.*, fo.nome_fantasia AS fornecedor_nome,
                    c.prazo_resposta,
                    (SELECT COUNT(*) FROM cotacao_itens ci WHERE ci.cotacao_fornecedor_id = cf.id) AS ja_respondido
             FROM cotacao_fornecedores cf
             JOIN cotacoes c      ON c.id  = cf.cotacao_id
             JOIN fornecedores fo ON fo.id = cf.fornecedor_id
             WHERE cf.token_acesso = ? LIMIT 1"
        );
        $stmt->execute([$token]);
        $cotacaoForn = $stmt->fetch();

        if (!$cotacaoForn || $cotacaoForn['status'] === 'respondida') {
            http_response_code(404);
            echo '<p style="font-family:sans-serif;text-align:center;margin-top:4rem">Link invÃ¡lido ou cotaÃ§Ã£o jÃ¡ respondida.</p>';
            return;
        }

        if (new \DateTime() > new \DateTime($cotacaoForn['prazo_resposta'])) {
            http_response_code(410);
            echo '<p style="font-family:sans-serif;text-align:center;margin-top:4rem">O prazo para resposta desta cotaÃ§Ã£o expirou.</p>';
            return;
        }

        // Itens vêm do escopo da própria cotação (vale para avulsa e para as que
        // nasceram de requisição). O JOIN em itens continua interno: o formulário
        // público só precifica item de catálogo.
        $itensStmt = $db->prepare(
            "SELECT cis.item_id, cis.quantidade, i.nome AS item_nome, i.unidade
             FROM cotacao_itens_solicitados cis
             JOIN itens i ON i.id = cis.item_id
             WHERE cis.cotacao_id = ?"
        );
        $itensStmt->execute([$cotacaoForn['cotacao_id']]);
        $itens = $itensStmt->fetchAll();

        $this->view('compras/responder_cotacao', compact('cotacaoForn', 'itens', 'token'), '');
    }

    public function salvarResposta(string $token): void
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT * FROM cotacao_fornecedores WHERE token_acesso = ? AND status = 'enviada' LIMIT 1"
        );
        $stmt->execute([$token]);
        $cf = $stmt->fetch();

        if (!$cf) {
            echo '<p style="font-family:sans-serif;text-align:center;margin-top:4rem">Link invÃ¡lido ou cotaÃ§Ã£o jÃ¡ respondida.</p>';
            return;
        }

        $itemIds = $_POST['item_id']       ?? [];
        $valores = $_POST['valor_unitario']?? [];
        $qtds    = $_POST['quantidade']    ?? [];
        $prazo   = (int)($this->input('prazo_entrega_dias') ?? 7);

        // Allowlist: item_ids que pertencem ao escopo desta cotação.
        // Impede IDOR — fornecedor não pode injetar item_id de outra cotação.
        $allowStmt = $db->prepare(
            "SELECT cis.item_id
             FROM cotacao_itens_solicitados cis
             WHERE cis.cotacao_id = ? AND cis.item_id IS NOT NULL"
        );
        $allowStmt->execute([$cf['cotacao_id']]);
        $itensValidos = array_flip(array_column($allowStmt->fetchAll(), 'item_id'));

        $jaInserido = [];
        foreach ($itemIds as $i => $iid) {
            // Rejeita item fora da allowlist ou duplicado na mesma submissão
            if (!isset($itensValidos[$iid]) || isset($jaInserido[$iid])) {
                continue;
            }
            $val = parse_decimal($valores[$i] ?? 0);
            if ($val > 0) {
                $db->prepare(
                    "INSERT INTO cotacao_itens
                     (id, cotacao_fornecedor_id, item_id, quantidade, valor_unitario, prazo_entrega_dias)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([uuid(), $cf['id'], $iid, parse_decimal($qtds[$i] ?? 0), $val, $prazo]);
                $jaInserido[$iid] = true;
            }
        }

        $db->prepare(
            "UPDATE cotacao_fornecedores
             SET status = 'respondida', respondido_em = NOW()
             WHERE id = ?"
        )->execute([$cf['id']]);

        $db->prepare("UPDATE cotacoes SET status = 'aguardando_respostas' WHERE id = ?")
           ->execute([$cf['cotacao_id']]);

        // â”€â”€ NotificaÃ§Ã£o por e-mail ao comprador â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        try {
            $infoStmt = $db->prepare(
                "SELECT u.email AS comprador_email,
                        u.nome  AS comprador_nome,
                        fo.nome_fantasia AS fornecedor_nome,
                        (SELECT COUNT(*) FROM cotacao_fornecedores WHERE cotacao_id = c.id) AS total_fornecedores,
                        (SELECT COUNT(*) FROM cotacao_fornecedores WHERE cotacao_id = c.id AND status = 'respondida') AS total_respondidas,
                        (SELECT COUNT(*) FROM cotacao_itens WHERE cotacao_fornecedor_id = ?) AS total_itens
                 FROM cotacao_fornecedores cf
                 JOIN cotacoes c      ON c.id  = cf.cotacao_id
                 JOIN usuarios u      ON u.id  = c.criado_por
                 JOIN fornecedores fo ON fo.id = cf.fornecedor_id
                 WHERE cf.id = ? LIMIT 1"
            );
            $infoStmt->execute([$cf['id'], $cf['id']]);
            $info = $infoStmt->fetch();

            if ($info && !empty($info['comprador_email'])) {
                (new MailService())->notificarRespostaCotacao(
                    $info['comprador_email'],
                    $info['comprador_nome'],
                    $info['fornecedor_nome'],
                    $cf['cotacao_id'],
                    (int)$info['total_itens'],
                    (int)$info['total_respondidas'],
                    (int)$info['total_fornecedores']
                );
            }
        } catch (\Throwable $e) {
            error_log('[CompraController] Erro ao enviar e-mail de notificaÃ§Ã£o: ' . $e->getMessage());
        }

        $this->view('compras/cotacao_confirmada', [], '');
    }

    // â”€â”€ Rastreio manual de e-mail â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function marcarEmailEnviado(): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','admin']);
        $db   = Database::getInstance();
        $data = json_decode(file_get_contents('php://input'), true);
        $token = $data['token'] ?? '';

        $stmt = $db->prepare(
            "SELECT cf.id, fo.nome_fantasia
             FROM cotacao_fornecedores cf
             JOIN fornecedores fo ON fo.id = cf.fornecedor_id
             WHERE cf.token_acesso = ? AND cf.status = 'enviada' LIMIT 1"
        );
        $stmt->execute([$token]);
        $cf = $stmt->fetch();

        if (!$cf) {
            $this->json(['success' => false, 'message' => 'Fornecedor nÃ£o encontrado ou jÃ¡ respondido.']);
            return;
        }

        $db->prepare(
            "UPDATE cotacao_fornecedores SET email_manual_enviado_at = NOW() WHERE id = ?"
        )->execute([$cf['id']]);

        $this->json(['success' => true, 'fornecedor_nome' => $cf['nome_fantasia']]);
    }

    public function itensPararespostaManual(): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','admin']);
        $db    = Database::getInstance();
        $token = $_GET['token'] ?? '';

        $stmt = $db->prepare(
            "SELECT cf.id, cf.cotacao_id, cf.fornecedor_id
             FROM cotacao_fornecedores cf
             WHERE cf.token_acesso = ? LIMIT 1"
        );
        $stmt->execute([$token]);
        $cf = $stmt->fetch();

        if (!$cf) {
            $this->json(['success' => false, 'message' => 'Token invÃ¡lido.']);
            return;
        }

        $itensStmt = $db->prepare(
            "SELECT cis.item_id,
                    cis.quantidade,
                    COALESCE(i.nome, cis.descricao_livre) AS item_nome,
                    COALESCE(i.unidade, 'un') AS unidade,
                    COALESCE(
                        (SELECT hp.valor_unitario FROM historico_precos hp
                         WHERE hp.item_id = cis.item_id AND hp.fornecedor_id = ?
                         ORDER BY hp.registrado_em DESC LIMIT 1),
                        i.valor_referencia,
                        0
                    ) AS valor_sugerido
             FROM cotacao_itens_solicitados cis
             LEFT JOIN itens i ON i.id = cis.item_id
             WHERE cis.cotacao_id = ?
             ORDER BY item_nome"
        );
        $itensStmt->execute([$cf['fornecedor_id'], $cf['cotacao_id']]);
        $itens = $itensStmt->fetchAll();

        $this->json(['success' => true, 'cotacao_id' => $cf['cotacao_id'], 'itens' => $itens]);
    }

    public function salvarRespostaManual(): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','admin']);
        $db   = Database::getInstance();
        $user = auth();
        $data = json_decode(file_get_contents('php://input'), true);

        $token = $data['token'] ?? '';
        $prazo = (int)($data['prazo_entrega_dias'] ?? 7);
        $frete = (float)($data['frete'] ?? 0);
        $itens = $data['itens'] ?? [];

        $stmt = $db->prepare(
            "SELECT cf.* FROM cotacao_fornecedores cf
             WHERE cf.token_acesso = ? AND cf.status = 'enviada' LIMIT 1"
        );
        $stmt->execute([$token]);
        $cf = $stmt->fetch();

        if (!$cf) {
            $this->json(['success' => false, 'message' => 'Fornecedor nÃ£o encontrado ou resposta jÃ¡ registrada.']);
            return;
        }

        if (empty($itens)) {
            $this->json(['success' => false, 'message' => 'Nenhum item informado.']);
            return;
        }

        // Allowlist do escopo da cotação — o item_id vem cru do corpo JSON.
        // Item de texto livre (Ordem de Compra) tem item_id nulo: quem identifica
        // a linha é a descrição, que o front manda de volta. A posição entre os
        // itens livres (mesma ordem de itensPararespostaManual()) fica só como
        // reserva, para uma resposta antiga que não traga a descrição.
        $allowStmt = $db->prepare(
            "SELECT cis.item_id, cis.descricao_livre
             FROM cotacao_itens_solicitados cis
             LEFT JOIN itens i ON i.id = cis.item_id
             WHERE cis.cotacao_id = ?
             ORDER BY COALESCE(i.nome, cis.descricao_livre)"
        );
        $allowStmt->execute([$cf['cotacao_id']]);

        $permitidosPorId        = [];
        $permitidosLivres       = [];
        $permitidosPorDescricao = [];
        foreach ($allowStmt->fetchAll() as $linha) {
            if ($linha['item_id'] !== null) {
                $permitidosPorId[$linha['item_id']] = $linha;
            } else {
                $permitidosLivres[] = $linha;
                $permitidosPorDescricao[mb_strtolower(trim((string)$linha['descricao_livre']))] = $linha;
            }
        }

        $posLivre = 0;
        foreach ($itens as $item) {
            $val = (float)($item['valor_unitario'] ?? 0);
            $qtd = (float)($item['quantidade']     ?? 0);
            if ($val <= 0) continue;

            $itemIdRaw = $item['item_id'] ?? null;
            $itemId    = ($itemIdRaw !== null && $itemIdRaw !== '' && $itemIdRaw !== 'null')
                ? $itemIdRaw : null;

            if ($itemId !== null) {
                if (!isset($permitidosPorId[$itemId])) {
                    continue;
                }
                $descricao = null;
            } else {
                $chaveDesc = mb_strtolower(trim((string)($item['descricao'] ?? '')));
                $permitido = $permitidosPorDescricao[$chaveDesc] ?? ($permitidosLivres[$posLivre] ?? null);
                $posLivre++;

                if (!$permitido) {
                    continue;
                }
                $descricao = mb_substr((string)$permitido['descricao_livre'], 0, 255);
            }

            $db->prepare(
                "INSERT INTO cotacao_itens
                 (id, cotacao_fornecedor_id, item_id, descricao, quantidade, valor_unitario, prazo_entrega_dias)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([uuid(), $cf['id'], $itemId, $descricao, $qtd, $val, $prazo]);
        }

        $db->prepare(
            "UPDATE cotacao_fornecedores
             SET status = 'respondida', respondido_em = NOW(), frete = ?
             WHERE id = ?"
        )->execute([$frete, $cf['id']]);

        $pendentes = $db->prepare(
            "SELECT COUNT(*) FROM cotacao_fornecedores
             WHERE cotacao_id = ? AND status = 'enviada'"
        );
        $pendentes->execute([$cf['cotacao_id']]);
        if ((int)$pendentes->fetchColumn() === 0) {
            $db->prepare("UPDATE cotacoes SET status = 'aguardando_respostas' WHERE id = ?")
               ->execute([$cf['cotacao_id']]);
        }

        (new AuditoriaService())->log($user['id'], 'cotacao', $cf['cotacao_id'], 'resposta.manual');

        $this->json(['success' => true]);
    }

    public function itensDaCotacao(string $id): void
    {
        $this->requireAuth();
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT COALESCE(i.nome, cis.descricao_livre) AS item_nome,
                    cis.quantidade,
                    COALESCE(i.unidade, 'un') AS unidade
             FROM cotacao_itens_solicitados cis
             LEFT JOIN itens i ON i.id = cis.item_id
             WHERE cis.cotacao_id = ?
             ORDER BY item_nome"
        );
        $stmt->execute([$id]);
        $itens = array_map(fn($r) => [
            'nome' => $r['item_nome'],
            'qtd'  => (float)$r['quantidade'],
            'un'   => $r['unidade'],
        ], $stmt->fetchAll());

        $this->json(['itens' => $itens]);
    }

    public function creditosFornecedor(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT id, valor, descricao, validade
             FROM creditos_fornecedor
             WHERE fornecedor_id = ? AND usado = 0
               AND (validade IS NULL OR validade >= CURDATE())
             ORDER BY validade ASC"
        );
        $stmt->execute([$id]);
        $creditos = $stmt->fetchAll();

        $total = array_sum(array_column($creditos, 'valor'));

        $this->json([
            'total'    => (float)$total,
            'creditos' => $creditos,
        ]);
    }

    /**
     * Mesma regra da lista de OCs (ordens()): fora diretor/admin, só a própria
     * filial. Antes, qualquer logado abria qualquer OC pelo id (auditoria M1).
     */
    private function exigirFilialDaOC(array $oc): void
    {
        if (ve_todas_filiais()) return;
        if (($oc['filial_id'] ?? null) !== (auth()['filial_id'] ?? null)) {
            http_response_code(403);
            $this->view('errors/403', [], 'main');
            exit;
        }
    }

    public function pdfOC(string $id): void
    {
        $this->requireAuth();
        $db = Database::getInstance();

        // Campos mínimos da OC pela Política de Compras §10: empresa/CNPJ, regime do
        // fornecedor, requisitante/setor, motivo, finalidade, nº da requisição,
        // aprovadores, prazo e local de entrega.
        $stmt = $db->prepare(
            "SELECT oc.*, fo.nome_fantasia AS fornecedor_nome, fo.razao_social AS fornecedor_razao,
                    fo.cnpj AS fornecedor_cnpj, fo.endereco AS fornecedor_endereco,
                    fo.telefone AS fornecedor_telefone, fo.email AS fornecedor_email,
                    fo.regime_tributario AS fornecedor_regime,
                    r.filial_id, fi.nome AS filial_nome, fi.cnpj AS filial_cnpj,
                    fi.endereco AS filial_endereco, fi.cidade AS filial_cidade, fi.estado AS filial_estado,
                    u.nome AS comprador_nome, ur.nome AS requisitante_nome, st.nome AS setor_nome,
                    r.numero AS req_numero, r.tipo AS req_tipo, r.criado_em AS req_criado_em,
                    r.finalidade, r.necessario_ate, r.justificativa AS motivo,
                    (SELECT MAX(ci.prazo_entrega_dias)
                       FROM cotacao_fornecedores cf
                       JOIN cotacao_itens ci ON ci.cotacao_fornecedor_id = cf.id
                      WHERE cf.cotacao_id = oc.cotacao_id AND cf.fornecedor_id = oc.fornecedor_id) AS prazo_dias
             FROM ordens_compra oc
             JOIN fornecedores fo ON fo.id = oc.fornecedor_id
             JOIN requisicoes  r  ON r.id  = oc.requisicao_id
             JOIN filiais fi      ON fi.id = r.filial_id
             JOIN usuarios u      ON u.id  = oc.criado_por
             LEFT JOIN usuarios ur ON ur.id = r.usuario_id
             LEFT JOIN setores st  ON st.id = r.setor_id
             WHERE oc.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $oc = $stmt->fetch();
        if (!$oc) { redirect('/compras/ordens'); }
        $this->exigirFilialDaOC($oc);

        // Quem aprovou: alçada da requisição e, se houve, reaprovação da própria OC.
        $aprovStmt = $db->prepare(
            "SELECT a.entidade_tipo, a.nivel, u.nome, u.perfil, a.resolvido_em
             FROM aprovacoes a
             JOIN usuarios u ON u.id = a.aprovado_por
             WHERE a.status = 'aprovado'
               AND ((a.entidade_tipo = 'requisicao' AND a.entidade_id = ?)
                 OR (a.entidade_tipo = 'ordem_compra' AND a.entidade_id = ?))
             ORDER BY a.entidade_tipo DESC, a.nivel"
        );
        $aprovStmt->execute([$oc['requisicao_id'], $id]);
        $aprovadores = $aprovStmt->fetchAll();

        $itensStmt = $db->prepare(
            "SELECT oci.*, i.nome AS item_nome, i.unidade
             FROM ordens_compra_itens oci
             LEFT JOIN itens i ON i.id = oci.item_id
             WHERE oci.oc_id = ?"
        );
        $itensStmt->execute([$id]);
        $itens = $itensStmt->fetchAll();

        // Renderiza sem layout principal
        $this->view('compras/oc_pdf', compact('oc', 'itens', 'aprovadores'), '');
    }

    public function editarCotacaoForm(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','admin']);
        $db = Database::getInstance();

        $cotacao = $this->carregarCotacaoEditavel($id);

        // Fornecedores já na cotação, com o status — quem respondeu não pode sair.
        $atuaisStmt = $db->prepare(
            "SELECT fornecedor_id, status FROM cotacao_fornecedores WHERE cotacao_id = ?"
        );
        $atuaisStmt->execute([$id]);
        $atuais = [];
        foreach ($atuaisStmt->fetchAll() as $row) {
            $atuais[$row['fornecedor_id']] = $row['status'];
        }

        // Bloqueado não aparece para convite (a defesa é fornecedorSemCotacao no POST).
        $fornecedores = array_values(array_filter((new Fornecedor())->all(['ativo' => 1], 'nome_fantasia'),
            fn($f) => Fornecedor::podeCotar($f)));

        $this->view('compras/cotacao_editar', compact('cotacao', 'atuais', 'fornecedores'));
    }

    public function salvarCotacao(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','admin']);
        $user = auth();
        $db   = Database::getInstance();

        $this->carregarCotacaoEditavel($id);

        $prazoRaw      = $this->input('prazo_resposta') ?? '';
        $prazoResposta = \DateTime::createFromFormat('Y-m-d\TH:i', $prazoRaw)
            ? (new \DateTime($prazoRaw))->format('Y-m-d H:i:s')
            : date('Y-m-d H:i:s', strtotime('+48 hours'));
        $usarCredito = (int)($this->input('usar_credito') === '1');

        $marcados = $_POST['fornecedor_id'] ?? [];

        // Só os NOVOS convidados passam pela checagem: quem já estava na cotação
        // segue nela (bloquear depois não apaga resposta já dada).
        $jaConvidados = $db->prepare("SELECT fornecedor_id FROM cotacao_fornecedores WHERE cotacao_id = ?");
        $jaConvidados->execute([$id]);
        $novos = array_diff((array)$marcados, $jaConvidados->fetchAll(\PDO::FETCH_COLUMN));
        if ($msg = $this->fornecedorSemCotacao($novos)) {
            session_flash('error', $msg);
            redirect('/compras/cotacoes/' . $id . '/editar');
        }

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE cotacoes SET prazo_resposta = ?, usar_credito = ? WHERE id = ?")
               ->execute([$prazoResposta, $usarCredito, $id]);

            $atuaisStmt = $db->prepare(
                "SELECT id, fornecedor_id, status FROM cotacao_fornecedores WHERE cotacao_id = ?"
            );
            $atuaisStmt->execute([$id]);
            $atuais = $atuaisStmt->fetchAll();

            $idsAtuais = [];
            foreach ($atuais as $a) {
                $idsAtuais[$a['fornecedor_id']] = $a;
            }

            // Adiciona os novos, com token próprio — mesmo padrão de criarCotacao().
            $ins = $db->prepare(
                "INSERT INTO cotacao_fornecedores (id, cotacao_id, fornecedor_id, token_acesso, status)
                 VALUES (?, ?, ?, ?, 'enviada')"
            );
            foreach ($marcados as $fid) {
                if (!isset($idsAtuais[$fid])) {
                    $ins->execute([uuid(), $id, $fid, bin2hex(random_bytes(16))]);
                }
            }

            // Remove os desmarcados, EXCETO quem já respondeu. A decisão é tomada
            // pelo status no banco, nunca pelo que veio no POST: o checkbox de um
            // fornecedor respondido é renderizado `disabled` e, por isso, não é
            // enviado pelo navegador — seria lido como "desmarcado".
            $del = $db->prepare("DELETE FROM cotacao_fornecedores WHERE id = ?");
            foreach ($atuais as $a) {
                if (in_array($a['fornecedor_id'], $marcados, true)) continue;
                if ($a['status'] === 'respondida') continue;
                $del->execute([$a['id']]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao salvar a cotação: ' . $this->mensagemErro($e));
            redirect('/compras/cotacoes/' . $id . '/editar');
        }

        (new AuditoriaService())->log($user['id'], 'cotacao', $id, 'cotacao.editada');

        session_flash('success', 'Cotação atualizada.');
        redirect('/compras/cotacoes');
    }

    /**
     * Reabre a cotação para uma nova rodada: descarta os preços recebidos, volta
     * todos os fornecedores para "enviada" e troca os tokens. A troca de token é
     * essencial — sem ela os fornecedores continuariam respondendo pelo link da
     * rodada anterior.
     */
    public function refazerCotacao(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','admin']);
        $user = auth();
        $db   = Database::getInstance();

        $this->carregarCotacaoEditavel($id);

        $db->beginTransaction();
        try {
            $db->prepare(
                "DELETE ci FROM cotacao_itens ci
                   JOIN cotacao_fornecedores cf ON cf.id = ci.cotacao_fornecedor_id
                  WHERE cf.cotacao_id = ?"
            )->execute([$id]);

            $db->prepare(
                "UPDATE cotacao_fornecedores
                    SET status = 'enviada', respondido_em = NULL, frete = 0,
                        observacao = NULL, email_manual_enviado_at = NULL
                  WHERE cotacao_id = ?"
            )->execute([$id]);

            // token_acesso é UNIQUE: precisa de um valor novo por linha.
            $fornStmt = $db->prepare("SELECT id FROM cotacao_fornecedores WHERE cotacao_id = ?");
            $fornStmt->execute([$id]);
            $tokenStmt = $db->prepare("UPDATE cotacao_fornecedores SET token_acesso = ? WHERE id = ?");
            foreach ($fornStmt->fetchAll() as $f) {
                $tokenStmt->execute([bin2hex(random_bytes(16)), $f['id']]);
            }

            $db->prepare("UPDATE cotacoes SET status = 'aberta' WHERE id = ?")->execute([$id]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao refazer a cotação: ' . $this->mensagemErro($e));
            redirect('/compras/cotacoes');
        }

        (new AuditoriaService())->log($user['id'], 'cotacao', $id, 'cotacao.refeita');

        session_flash('success', 'Cotação reaberta. Os fornecedores receberam links novos.');
        redirect('/compras/cotacoes');
    }

    /**
     * "Excluir" na tela: marca como cancelada e some da listagem padrão. Nada é
     * removido — `cotacao_fornecedores` e `cotacao_itens` seguem intactos, e o
     * comparativo continua acessível.
     */
    public function cancelarCotacao(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','admin']);
        $user = auth();
        $db   = Database::getInstance();

        $this->carregarCotacaoEditavel($id);

        $db->prepare("UPDATE cotacoes SET status = 'cancelada' WHERE id = ?")->execute([$id]);

        (new AuditoriaService())->log($user['id'], 'cotacao', $id, 'cotacao.cancelada');

        session_flash('success', 'Cotação cancelada. O histórico foi preservado.');
        redirect('/compras/cotacoes');
    }

    /**
     * Carrega a cotação já com `oc_id` e aplica a CotacaoPolicy. Redireciona com
     * a mensagem apropriada quando a cotação não existe ou não pode ser alterada,
     * então quem chama pode assumir que o retorno é sempre editável.
     */
    private function carregarCotacaoEditavel(string $id): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT c.*,
                    (SELECT oc.id FROM ordens_compra oc WHERE oc.cotacao_id = c.id LIMIT 1) AS oc_id
             FROM cotacoes c WHERE c.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $cotacao = $stmt->fetch();

        if (!$cotacao) {
            session_flash('error', 'Cotação não encontrada.');
            redirect('/compras/cotacoes');
        }

        $regra = CotacaoPolicy::avaliar($cotacao);
        if (!$regra['permitido']) {
            session_flash('error', $regra['motivo']);
            redirect('/compras/cotacoes');
        }

        return $cotacao;
    }
}


