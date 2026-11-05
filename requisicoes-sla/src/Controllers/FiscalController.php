<?php
// @versao Ver.009.301026

namespace App\Controllers;

use App\Core\Controller;
use App\Config\Database;
use App\Services\AuditoriaService;

class FiscalController extends Controller
{
    // notas_fiscais schema:
    // id, oc_id (NOT NULL FK ordens_compra), numero_nf, chave_acesso CHAR(44),
    // emitente_cnpj CHAR(14), valor_total, xml_path (NOT NULL),
    // status ENUM('pendente_conferencia','aprovada','divergente','rejeitada'),
    // divergencias_json JSON, tolerancia_pct, conferido_por, conferido_em, criado_em

    public function index(): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
        $user = auth();
        $db   = Database::getInstance();

        $sql = "SELECT nf.*, oc.numero_oc,
                       fo.nome_fantasia AS fornecedor_nome,
                       r.filial_id, fi.nome AS filial_nome
                FROM notas_fiscais nf
                JOIN ordens_compra oc ON oc.id = nf.oc_id
                JOIN fornecedores  fo ON fo.id = oc.fornecedor_id
                JOIN requisicoes   r  ON r.id  = oc.requisicao_id
                JOIN filiais fi        ON fi.id = r.filial_id
                WHERE 1=1";
        $params = [];

        if (!ve_todas_filiais()) {
            $sql .= " AND r.filial_id = ?";
            $params[] = $user['filial_id'];
        }
        if (!empty($_GET['status'])) {
            $sql .= " AND nf.status = ?";
            $params[] = $_GET['status'];
        }
        $sql .= " ORDER BY nf.criado_em DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $notas = $stmt->fetchAll();

        // Ordens de compra disponíveis para vincular NF
        $ocsStmt = $db->prepare(
            "SELECT oc.id, oc.numero_oc, fo.nome_fantasia AS fornecedor_nome
             FROM ordens_compra oc
             JOIN fornecedores fo ON fo.id = oc.fornecedor_id
             WHERE oc.status IN ('aprovado','enviado')
               AND NOT EXISTS (
                   SELECT 1 FROM notas_fiscais nf
                   WHERE nf.oc_id = oc.id AND nf.status NOT IN ('rejeitada')
               )
             ORDER BY oc.criado_em DESC LIMIT 100"
        );
        $ocsStmt->execute();
        $ordensDisponiveis = $ocsStmt->fetchAll();

        $this->view('fiscal/index', compact('notas', 'ordensDisponiveis', 'user'));
    }

    public function upload(): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','admin']);
        $user = auth();

        if (empty($_FILES['xml_nf']['tmp_name'])) {
            session_flash('error', 'Selecione um arquivo XML de NF-e.');
            redirect('/fiscal');
        }

        $ocId = $this->input('oc_id');
        if (!$ocId) {
            session_flash('error', 'Selecione a Ordem de Compra relacionada.');
            redirect('/fiscal');
        }

        $xmlContent = file_get_contents($_FILES['xml_nf']['tmp_name']);
        $dados = $this->parseXmlNFe($xmlContent);

        if (!$dados) {
            session_flash('error', 'Arquivo XML inválido ou não é uma NF-e.');
            redirect('/fiscal');
        }

        $db = Database::getInstance();

        // Só OC que autoriza faturamento (a lista da tela já filtra; isto barra o POST
        // montado à mão — inclusive OC em rascunho aguardando reaprovação).
        $ocValida = $db->prepare("SELECT 1 FROM ordens_compra WHERE id = ? AND status IN ('aprovado','enviado','recebido_parcial')");
        $ocValida->execute([$ocId]);
        if (!$ocValida->fetchColumn()) {
            session_flash('error', 'A Ordem de Compra selecionada não está aprovada para receber nota fiscal.');
            redirect('/fiscal');
        }

        // Verifica chave duplicada
        $existe = $db->prepare("SELECT id FROM notas_fiscais WHERE chave_acesso = ? LIMIT 1");
        $existe->execute([$dados['chave']]);
        if ($existe->fetch()) {
            session_flash('error', 'Esta NF-e já foi importada anteriormente.');
            redirect('/fiscal');
        }

        // Salva XML em storage
        $xmlDir  = ROOT . '/storage/nfe/';
        if (!is_dir($xmlDir)) mkdir($xmlDir, 0755, true);
        $xmlPath = 'storage/nfe/' . $dados['chave'] . '.xml';
        file_put_contents(ROOT . '/' . $xmlPath, $xmlContent);

        // Compara com OC
        [$status, $divergencias] = $this->compararComOC($db, $ocId, $dados);

        $id = uuid();
        $db->prepare(
            "INSERT INTO notas_fiscais
             (id, oc_id, numero_nf, chave_acesso, emitente_cnpj, valor_total, frete_total,
              xml_path, status, divergencias_json, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $id, $ocId,
            $dados['numero'],
            $dados['chave'],
            $dados['cnpj_emit'],
            $dados['valor_total'],
            $dados['frete_total'] ?? 0,
            $xmlPath,
            $status,
            json_encode($divergencias),
        ]);

        $this->ratearEGravarItens($db, $id, $ocId, $dados['itens'] ?? [], (float)($dados['frete_total'] ?? 0), 'valor');

        (new AuditoriaService())->log($user['id'], 'nota_fiscal', $id, 'nf.importada',
            null, ['numero' => $dados['numero'], 'status' => $status]);

        $msg = $status === 'divergente'
            ? 'NF-e importada com divergências. Verifique os detalhes.'
            : 'NF-e importada — aguardando conferência de custos (IPI/frete).';
        session_flash('warning', $msg);
        redirect('/fiscal/' . $id);
    }

    public function show(string $id): void
    {
        $this->requireAuth();
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT nf.*, oc.numero_oc, oc.valor_total AS valor_oc,
                    fo.nome_fantasia AS fornecedor_nome, fo.cnpj AS fornecedor_cnpj,
                    r.filial_id, fi.nome AS filial_nome,
                    u.nome AS conferente_nome
             FROM notas_fiscais nf
             LEFT JOIN ordens_compra oc ON oc.id  = nf.oc_id
             LEFT JOIN fornecedores  fo ON fo.id  = oc.fornecedor_id
             LEFT JOIN requisicoes   r  ON r.id   = oc.requisicao_id
             LEFT JOIN filiais fi       ON fi.id  = r.filial_id
             LEFT JOIN usuarios u       ON u.id   = nf.conferido_por
             WHERE nf.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $nf = $stmt->fetch();
        if (!$nf) { redirect('/fiscal'); }

        // Mesma regra da lista do Fiscal: fora diretor/admin, só a própria filial (auditoria M1).
        if (!ve_todas_filiais() && ($nf['filial_id'] ?? null) !== (auth()['filial_id'] ?? null)) {
            http_response_code(403);
            $this->view('errors/403', [], 'main');
            exit;
        }

        $nf['divergencias'] = json_decode($nf['divergencias_json'] ?? '[]', true) ?: [];

        $itensStmt = $db->prepare(
            "SELECT nfi.*, i.nome AS item_nome_catalogo, i.unidade AS item_unidade
             FROM nota_fiscal_itens nfi
             LEFT JOIN itens i ON i.id = nfi.item_id
             WHERE nfi.nota_fiscal_id = ?
             ORDER BY nfi.descricao_nf"
        );
        $itensStmt->execute([$id]);
        $nfItens = $itensStmt->fetchAll();

        $itens = (new \App\Models\Item())->allComGrupo(['ativo' => 1]);

        $this->view('fiscal/show', compact('nf', 'nfItens', 'itens'));
    }

    public function aprovar(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
        $user = auth();
        $db   = Database::getInstance();

        if ($msg = $this->segregacaoCompraRecebimento($db, $id, $user)) {
            session_flash('error', $msg);
            redirect('/fiscal/' . $id);
        }

        $db->prepare(
            "UPDATE notas_fiscais SET status = 'aprovada', conferido_por = ?, conferido_em = NOW() WHERE id = ?"
        )->execute([$user['id'], $id]);

        // Atualiza status da OC para recebido_total
        $db->prepare(
            "UPDATE ordens_compra oc
             JOIN notas_fiscais nf ON nf.oc_id = oc.id
             SET oc.status = 'recebido_total'
             WHERE nf.id = ?"
        )->execute([$id]);

        (new AuditoriaService())->log($user['id'], 'nota_fiscal', $id, 'nf.aprovada');
        (new \App\Services\SolicitacaoCompraService())->aoAprovarNotaFiscal($id, $user);
        session_flash('success', 'NF-e aprovada e OC atualizada.');
        redirect('/fiscal/' . $id);
    }

    public function rejeitar(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
        $user   = auth();
        $motivo = $this->input('motivo', 'Sem motivo informado.');
        $db     = Database::getInstance();

        $db->prepare(
            "UPDATE notas_fiscais
             SET status = 'rejeitada', conferido_por = ?, conferido_em = NOW(),
                 divergencias_json = JSON_SET(COALESCE(divergencias_json, '[]'), '$[0].motivo_rejeicao', ?)
             WHERE id = ?"
        )->execute([$user['id'], $motivo, $id]);

        (new AuditoriaService())->log($user['id'], 'nota_fiscal', $id, 'nf.rejeitada',
            null, ['motivo' => $motivo]);
        session_flash('success', 'NF-e rejeitada.');
        redirect('/fiscal/' . $id);
    }

    public function confirmarConferencia(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
        $user = auth();
        $db   = Database::getInstance();

        if ($msg = $this->segregacaoCompraRecebimento($db, $id, $user)) {
            $this->json(['success' => false, 'message' => $msg]);
            return;
        }

        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $itens   = $payload['itens'] ?? [];
        $frete   = (float)($payload['frete_total'] ?? 0);
        $metodo  = ($payload['rateio_metodo'] ?? 'valor') === 'quantidade' ? 'quantidade' : 'valor';

        // Limite de decimal(10,2), que é o tipo de nota_fiscal_itens.quantidade,
        // estoque.quantidade e movimentos_estoque.quantidade. Sem esta checagem o
        // MySQL trunca em silêncio (o sql_mode do projeto não é estrito) e a nota
        // fica com uma quantidade que ninguém digitou.
        $qtdMax = 99999999.99;

        foreach ($itens as $it) {
            if (empty($it['item_id'])) {
                $this->json(['success' => false, 'message' => 'Vincule um item do catálogo em todas as linhas antes de confirmar.']);
                return;
            }

            // O campo Qtd é texto livre e parseBR devolve 0 para qualquer coisa
            // que não seja número. Sem validar aqui, uma quantidade zerada ou
            // negativa era aceita: a nota ia para 'aprovada' sem entrada de
            // estoque (o insert é guardado por $qtd > 0) e o custo unitário caía
            // para zero, zerando itens.valor_referencia — que alimenta a
            // precificação de requisição.
            $qtd = (float)($it['quantidade'] ?? 0);
            if (!($qtd > 0) || $qtd > $qtdMax) {
                $rot = $db->prepare("SELECT codigo_nf, descricao_nf FROM nota_fiscal_itens WHERE id = ? LIMIT 1");
                $rot->execute([$it['id'] ?? '']);
                $linha = $rot->fetch();
                $nome  = $linha ? trim(($linha['codigo_nf'] ?? '') . ' ' . ($linha['descricao_nf'] ?? '')) : 'sem identificação';
                $msg = !($qtd > 0)
                    ? "Quantidade inválida na linha \"{$nome}\": informe um número maior que zero."
                    : "Quantidade fora da faixa suportada na linha \"{$nome}\": o máximo é 99.999.999,99.";
                $this->json(['success' => false, 'message' => $msg]);
                return;
            }
        }

        $nfStmt = $db->prepare(
            "SELECT nf.id, nf.status, nf.conferido_por, oc.fornecedor_id, r.filial_id
             FROM notas_fiscais nf
             JOIN ordens_compra oc ON oc.id = nf.oc_id
             JOIN requisicoes   r  ON r.id  = oc.requisicao_id
             WHERE nf.id = ? LIMIT 1"
        );
        $nfStmt->execute([$id]);
        $nf = $nfStmt->fetch();
        if (!$nf) { $this->json(['success' => false, 'message' => 'NF não encontrada.']); return; }
        $podeConferir = $nf['status'] === 'pendente_conferencia'
            || $nf['status'] === 'divergente'
            || ($nf['status'] === 'aprovada' && empty($nf['conferido_por']));
        if (!$podeConferir) {
            $this->json(['success' => false, 'message' => 'Esta NF não pode ser conferida no estado atual.']);
            return;
        }

        $db->beginTransaction();
        try {
            // quantidade, valor_unitario e unidade recebem o valor EFETIVO, já
            // convertido pelo fator. O que veio no XML fica nas colunas *_nf,
            // que nenhum UPDATE daqui toca.
            $upd = $db->prepare(
                "UPDATE nota_fiscal_itens
                 SET item_id=?, fator_conversao=?, quantidade=?, valor_unitario=?, unidade=?,
                     ipi_percentual=?, ipi_valor=?, frete_rateado=?, custo_final_unit=?
                 WHERE id=? AND nota_fiscal_id=?"
            );
            // Com fator != 1 a linha deixa de estar na unidade do fornecedor e
            // passa para a do item; com fator 1 ela volta a ser a do documento.
            $unItemStmt = $db->prepare("SELECT unidade FROM itens WHERE id = ? LIMIT 1");
            $unNfStmt   = $db->prepare("SELECT unidade_nf FROM nota_fiscal_itens WHERE id = ? LIMIT 1");
            $hist = $db->prepare(
                "INSERT INTO historico_precos (id,item_id,fornecedor_id,valor_unitario,origem,origem_id)
                 VALUES (?,?,?,?, 'nota_fiscal', ?)"
            );
            $updItem = $db->prepare("UPDATE itens SET valor_referencia=? WHERE id=?");
            $entradaEstoque = $db->prepare(
                "INSERT INTO estoque (id, item_id, filial_id, quantidade, quantidade_reservada, atualizado_em)
                 VALUES (?, ?, ?, ?, 0, NOW())
                 ON DUPLICATE KEY UPDATE
                   quantidade           = quantidade + VALUES(quantidade),
                   quantidade_reservada = GREATEST(0, quantidade_reservada - VALUES(quantidade)),
                   atualizado_em        = NOW()"
            );
            $movEstoque = $db->prepare(
                "INSERT INTO movimentos_estoque
                 (id, item_id, filial_id, tipo, motivo, quantidade, referencia_tipo, referencia_id, usuario_id, criado_em)
                 VALUES (?, ?, ?, 'entrada', 'compra', ?, 'nota_fiscal', ?, ?, NOW())"
            );

            foreach ($itens as $it) {
                $itemId = $it['item_id'];

                // Fator zero ou negativo não converte nada: cai para 1. Guardar o
                // fator aqui é o registro do que foi aplicado — a conversão em si
                // já veio feita, calculada sobre quantidade_nf / valor_unitario_nf.
                $fator = (float)($it['fator_conversao'] ?? 1);
                if ($fator <= 0) { $fator = 1.0; }

                // Valores efetivos, na unidade do item: a quantidade já está
                // multiplicada e o valor unitário já está dividido pelo fator.
                // Por isso nada mais é convertido daqui para baixo — estoque,
                // movimento, histórico de preço e valor_referencia usam o mesmo
                // par, e o total da nota não muda (qtd × valor é invariante).
                $qtd     = (float)$it['quantidade'];   // > 0, validado acima
                $vlrUnit = (float)$it['valor_unitario'];
                $frete   = (float)$it['frete_rateado'];

                // IPI e custo são DERIVADOS e por isso recalculados aqui, em vez
                // de aceitos do payload: a tela os manda como texto formatado com
                // 2 casas, e um custo unitário abaixo de meio centavo — fator alto
                // ou item barato — chegava como 0,00. Zerava historico_precos e
                // itens.valor_referencia. O frete rateado continua vindo do
                // payload porque é distribuição, não derivação: o conferente pode
                // sobrescrever linha por linha.
                $produto = $qtd * $vlrUnit;
                $ipiVlr  = $produto * (float)$it['ipi_percentual'] / 100;
                $custo   = ($produto + $ipiVlr + $frete) / $qtd;

                $unidade = null;
                if ($fator != 1.0) {
                    $unItemStmt->execute([$itemId]);
                    $unidade = $unItemStmt->fetchColumn() ?: null;
                }
                if ($unidade === null) {
                    $unNfStmt->execute([$it['id']]);
                    $unidade = $unNfStmt->fetchColumn() ?: null;
                }

                $upd->execute([
                    $itemId, $fator,
                    $qtd, $vlrUnit, $unidade,
                    (float)$it['ipi_percentual'], round($ipiVlr, 2),
                    $frete, round($custo, 4),
                    $it['id'], $id,
                ]);

                // historico_precos.valor_unitario e itens.valor_referencia são
                // decimal(14,2): custo abaixo de meio centavo não é representável
                // e arredonda para zero. Gravar zero seria pior que não gravar —
                // destruiria o preço de referência do item e sujaria a média
                // histórica por causa de um limite de coluna. Nesse caso o preço
                // anterior é preservado.
                $custoRef = round($custo, 2);
                if ($custoRef > 0) {
                    $hist->execute([uuid(), $itemId, $nf['fornecedor_id'], $custoRef, $id]);
                    $updItem->execute([$custoRef, $itemId]);
                }

                // S4 (spec 2026-10-30): o Requisições não tem estoque — a conferência
                // da NF não dá entrada nem espelha brinde. Bloco desligado de propósito.
                if (false && $qtd > 0) {
                    $entradaEstoque->execute([uuid(), $itemId, $nf['filial_id'], $qtd]);
                    $movEstoque->execute([uuid(), $itemId, $nf['filial_id'], $qtd, $id, $user['id']]);
                    (new \App\Models\Estoque())->espelharBrinde($itemId, $nf['filial_id'], $qtd, 'entrada', $user['id'], 'nota_fiscal');
                }
            }

            $statusUpd = $db->prepare(
                "UPDATE notas_fiscais SET frete_total=?, rateio_metodo=?, status='aprovada', conferido_por=?, conferido_em=NOW()
                 WHERE id=? AND (status IN ('pendente_conferencia','divergente') OR (status = 'aprovada' AND conferido_por IS NULL))"
            );
            $statusUpd->execute([$frete, $metodo, $user['id'], $id]);
            if ($statusUpd->rowCount() === 0) {
                $db->rollBack();
                $this->json(['success' => false, 'message' => 'Esta NF já foi conferida.']);
                return;
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            $this->json(['success' => false, 'message' => 'Erro ao salvar: ' . $this->mensagemErro($e)]);
            return;
        }

        (new AuditoriaService())->log($user['id'], 'nota_fiscal', $id, 'nf.conferida');
        (new \App\Services\SolicitacaoCompraService())->aoAprovarNotaFiscal($id, $user);
        $this->json(['success' => true]);
    }

    // ── Helpers privados ────────────────────────────────────────────────────

    private function ratearEGravarItens(\PDO $db, string $nfId, string $ocId, array $itens, float $freteTotal, string $metodo): void
    {
        if (empty($itens)) return;

        $base = 0.0;
        foreach ($itens as $it) {
            $base += $metodo === 'quantidade' ? (float)$it['quantidade'] : (float)$it['valor_total'];
        }

        $matchItem   = $db->prepare("SELECT id FROM itens WHERE codigo = ? LIMIT 1");
        $matchOcItem = $db->prepare("SELECT id FROM ordens_compra_itens WHERE oc_id = ? AND item_id = ? LIMIT 1");
        $ins = $db->prepare(
            // Na importação não existe conversão ainda: efetivo == documento.
            // As colunas *_nf guardam o XML e nunca são reescritas depois — são
            // a âncora do fator (que recalcula sempre a partir delas) e o que
            // permite conferir a linha contra o papel.
            "INSERT INTO nota_fiscal_itens
             (id, nota_fiscal_id, oc_item_id, item_id, codigo_nf, descricao_nf,
              unidade, unidade_nf, quantidade, quantidade_nf,
              valor_unitario, valor_unitario_nf, ipi_percentual, ipi_valor, frete_rateado, custo_final_unit)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        foreach ($itens as $it) {
            $qtd   = (float)$it['quantidade'];
            $prod  = (float)$it['valor_total'];
            $ipiV  = (float)($it['ipi_valor'] ?? 0);
            $peso  = $base > 0 ? (($metodo === 'quantidade' ? $qtd : $prod) / $base) : 0;
            $frete = $freteTotal * $peso;
            $custoUnit = $qtd > 0 ? ($prod + $ipiV + $frete) / $qtd : 0;

            $matchItem->execute([$it['codigo'] ?? '']);
            $itemId = $matchItem->fetchColumn() ?: null;
            $ocItemId = null;
            if ($itemId) {
                $matchOcItem->execute([$ocId, $itemId]);
                $ocItemId = $matchOcItem->fetchColumn() ?: null;
            }

            $unidadeNf = $it['unidade'] ?? null;
            $vlrUnit   = (float)$it['valor_unit'];

            $ins->execute([
                uuid(), $nfId, $ocItemId, $itemId,
                $it['codigo'] ?? null, $it['descricao'],
                $unidadeNf, $unidadeNf, $qtd, $qtd,
                $vlrUnit, $vlrUnit, (float)($it['ipi_percentual'] ?? 0),
                $ipiV, round($frete, 2), round($custoUnit, 4),
            ]);
        }
    }

    private function parseXmlNFe(string $xml): ?array
    {
        // NF-e é sempre UTF-8. Sem esta checagem, um XML em UTF-16 escreve
        // "<!DOCTYPE" com bytes nulos intercalados e passa pelo filtro abaixo.
        if (!mb_check_encoding($xml, 'UTF-8') || str_contains($xml, "\0")) {
            return null;
        }

        // Bloqueia XXE antes de qualquer parse: rejeita DOCTYPE e ENTITY externos
        if (preg_match('/<!(?:DOCTYPE|ENTITY)\s/i', $xml)) {
            return null;
        }

        libxml_use_internal_errors(true);
        // LIBXML_NONET → impede acesso a rede (SSRF via schema externo).
        // NUNCA usar LIBXML_NOENT aqui: apesar do nome, ele LIGA a substituição
        // de entidades — inclusive externas (file:///…), o que abre leitura de
        // arquivos do servidor (XXE). Sem ele, a libxml não expande entidades.
        $obj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        if (!$obj) return null;

        try {
            $obj->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
            $infNFe = $obj->xpath('//nfe:infNFe')[0]
                ?? $obj->xpath('//infNFe')[0]
                ?? null;

            if (!$infNFe) return null;

            $attrs  = $infNFe->attributes();
            $chave  = ltrim((string)($attrs['Id'] ?? ''), 'NFe');
            $chave  = preg_replace('/\D/', '', $chave);

            if (strlen($chave) !== 44) return null;

            $ide   = $infNFe->ide;
            $emit  = $infNFe->emit;
            $total = $infNFe->total->ICMSTot;

            // Extrai itens dos elementos <det>
            $itens = [];
            foreach ($infNFe->det as $det) {
                $prod = $det->prod;
                $ipiPct = 0.0; $ipiVal = 0.0;
                if (isset($det->imposto->IPI->IPITrib)) {
                    $ipiPct = (float)($det->imposto->IPI->IPITrib->pIPI ?? 0);
                    $ipiVal = (float)($det->imposto->IPI->IPITrib->vIPI ?? 0);
                }
                $itens[] = [
                    'codigo'         => (string)($prod->cProd  ?? ''),
                    'descricao'      => (string)($prod->xProd  ?? ''),
                    'ncm'            => (string)($prod->NCM    ?? ''),
                    'unidade'        => (string)($prod->uCom   ?? ''),
                    'quantidade'     => (float)($prod->qCom   ?? 0),
                    'valor_unit'     => (float)($prod->vUnCom ?? 0),
                    'valor_total'    => (float)($prod->vProd  ?? 0),
                    'ipi_percentual' => $ipiPct,
                    'ipi_valor'      => $ipiVal,
                ];
            }

            // Número do pedido do comprador: xPed por item, xPed do grupo <compra>
            // e o texto livre de informações complementares (Política §10).
            $pedidos = [];
            foreach ($infNFe->det as $det) {
                $xPed = trim((string)($det->prod->xPed ?? ''));
                if ($xPed !== '') $pedidos[] = $xPed;
            }
            $xPedCompra = trim((string)($infNFe->compra->xPed ?? ''));
            if ($xPedCompra !== '') $pedidos[] = $xPedCompra;

            return [
                'numero'       => (string)($ide->nNF  ?? ''),
                'serie'        => (string)($ide->serie ?? ''),
                'chave'        => $chave,
                'valor_total'  => (float)($total->vNF ?? 0),
                'frete_total'  => (float)($total->vFrete ?? 0),
                'cnpj_emit'    => preg_replace('/\D/', '', (string)($emit->CNPJ ?? '')),
                'itens'        => $itens,
                'pedidos'      => array_values(array_unique($pedidos)),
                'inf_cpl'      => (string)($infNFe->infAdic->infCpl ?? ''),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function compararComEstoque(\PDO $db, array $itensNF, string $filialId): array
    {
        $resultado = [];
        foreach ($itensNF as $item) {
            // 1) Tenta match exato por código
            $match = $db->prepare(
                "SELECT i.id, i.codigo, i.nome, i.unidade, i.estoque_minimo,
                        COALESCE(SUM(e.quantidade), 0) AS saldo_total,
                        COALESCE((SELECT quantidade FROM estoque WHERE item_id = i.id AND filial_id = ? LIMIT 1), 0) AS saldo_filial
                 FROM itens i
                 LEFT JOIN estoque e ON e.item_id = i.id
                 WHERE i.codigo = ?
                 GROUP BY i.id LIMIT 1"
            );
            $match->execute([$filialId, $item['codigo']]);
            $cadastrado = $match->fetch();

            // 2) Se não achou, tenta match parcial por nome
            if (!$cadastrado) {
                $palavras = array_filter(explode(' ', $item['descricao']), fn($p) => strlen($p) > 3);
                $busca    = implode('%', array_slice($palavras, 0, 3));
                if ($busca) {
                    $match2 = $db->prepare(
                        "SELECT i.id, i.codigo, i.nome, i.unidade, i.estoque_minimo,
                                COALESCE(SUM(e.quantidade), 0) AS saldo_total,
                                COALESCE((SELECT quantidade FROM estoque WHERE item_id = i.id AND filial_id = ? LIMIT 1), 0) AS saldo_filial
                         FROM itens i
                         LEFT JOIN estoque e ON e.item_id = i.id
                         WHERE i.nome LIKE ?
                         GROUP BY i.id LIMIT 1"
                    );
                    $match2->execute([$filialId, '%' . $busca . '%']);
                    $cadastrado = $match2->fetch() ?: null;
                }
            }

            $resultado[] = [
                'nf'          => $item,
                'cadastrado'  => $cadastrado ?: null,
                'saldo_apos'  => $cadastrado
                    ? (float)$cadastrado['saldo_filial'] + $item['quantidade']
                    : null,
            ];
        }
        return $resultado;
    }

    public function comparativoEstoque(string $id): void
    {
        $this->requireAuth();
        $this->requirePerfil(['comprador','gestor','diretor','admin']);
        $db = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT nf.*, oc.numero_oc, fo.nome_fantasia AS fornecedor_nome,
                    r.filial_id, fi.nome AS filial_nome
             FROM notas_fiscais nf
             JOIN ordens_compra oc ON oc.id = nf.oc_id
             JOIN fornecedores  fo ON fo.id = oc.fornecedor_id
             JOIN requisicoes   r  ON r.id  = oc.requisicao_id
             JOIN filiais fi        ON fi.id = r.filial_id
             WHERE nf.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $nf = $stmt->fetch();
        if (!$nf) { redirect('/fiscal'); }

        $xmlPath = ROOT . '/' . $nf['xml_path'];
        if (!file_exists($xmlPath)) {
            session_flash('error', 'Arquivo XML não encontrado.');
            redirect('/fiscal/' . $id);
        }

        $dados = $this->parseXmlNFe(file_get_contents($xmlPath));
        if (!$dados || empty($dados['itens'])) {
            session_flash('error', 'Nenhum item encontrado no XML.');
            redirect('/fiscal/' . $id);
        }

        $comparativo = $this->compararComEstoque($db, $dados['itens'], $nf['filial_id']);

        $this->view('fiscal/comparativo_estoque', compact('nf', 'comparativo'));
    }

    /**
     * Política de Compras §4: "quem compra não recebe". Quem gerou a OC não confere
     * nem aprova a NF dela (o admin fica liberado para destravar exceções).
     * Devolve a mensagem de bloqueio, ou null quando pode seguir.
     */
    private function segregacaoCompraRecebimento(\PDO $db, string $nfId, array $user): ?string
    {
        if (($user['perfil'] ?? '') === 'admin') return null;
        $stmt = $db->prepare(
            "SELECT oc.criado_por FROM notas_fiscais nf JOIN ordens_compra oc ON oc.id = nf.oc_id WHERE nf.id = ? LIMIT 1"
        );
        $stmt->execute([$nfId]);
        return $stmt->fetchColumn() === $user['id']
            ? 'Você gerou a ordem de compra desta nota — pela Política de Compras, outra pessoa precisa conferir o recebimento.'
            : null;
    }

    private function compararComOC(\PDO $db, string $ocId, array $nfDados): array
    {
        $oc = $db->prepare("SELECT valor_total, numero_oc FROM ordens_compra WHERE id = ? LIMIT 1");
        $oc->execute([$ocId]);
        $oc = $oc->fetch();
        if (!$oc) return ['pendente_conferencia', []];

        $divergencias = [];

        // Política §10/§11: o nº da OC tem que constar na NF (xPed ou informações
        // complementares). Comparação só com dígitos e letras, sem caixa, porque o
        // fornecedor escreve "OC-2026-00012", "OC 2026 00012", "oc2026-00012"...
        if (!empty($oc['numero_oc'])) {
            $normaliza = fn(string $s) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s));
            $alvo      = $normaliza($oc['numero_oc']);
            $textoNf   = $normaliza(implode(' ', $nfDados['pedidos'] ?? []) . ' ' . ($nfDados['inf_cpl'] ?? ''));
            if ($alvo !== '' && !str_contains($textoNf, $alvo)) {
                $divergencias[] = [
                    'tipo'   => 'numero_oc',
                    'oc'     => $oc['numero_oc'],
                    'nf'     => implode(', ', $nfDados['pedidos'] ?? []) ?: 'não informado',
                ];
            }
        }
        $tolerancia   = 0.01; // 1%

        $difPct = abs($nfDados['valor_total'] - (float)$oc['valor_total'])
                / max((float)$oc['valor_total'], 0.01);

        if ($difPct > $tolerancia) {
            $divergencias[] = [
                'tipo'     => 'valor_total',
                'oc'       => (float)$oc['valor_total'],
                'nf'       => $nfDados['valor_total'],
                'diff_pct' => round($difPct * 100, 2),
            ];
        }

        $status = empty($divergencias) ? 'pendente_conferencia' : 'divergente';
        return [$status, $divergencias];
    }
}
