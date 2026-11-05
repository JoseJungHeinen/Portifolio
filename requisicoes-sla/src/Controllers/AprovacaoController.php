<?php
// @versao Ver.007.301026

namespace App\Controllers;

use App\Core\Controller;
use App\Models\{Aprovacao, Requisicao, OrdemCompra, Estoque};
use App\Config\Database;
use App\Services\{OrcamentoService, AuditoriaService, NotificacaoService};

class AprovacaoController extends Controller
{
    /** Motivo da última liberação de entidade que falhou, para exibir ao usuário. */
    private ?string $erroLiberacao = null;

    /**
     * true quando a falha em liberarEntidade() deve impedir o registro da
     * aprovação (ex.: Ordem de Compra sem conseguir gerar a OC) — igual ao que
     * já acontecia para requisicao_brinde, mas para entidade_tipo 'requisicao'.
     */
    private bool $bloqueiaAprovacao = false;

    public function index(): void
    {
        $this->requirePerfil(['gestor','diretor','admin','almoxarife']);
        $user = auth();

        $model    = new Aprovacao();
        $pendentes = $model->pendentesParaUsuario($user['id'], $user['perfil']);

        // Coleta os IDs de requisição para buscar detalhes e itens EM LOTE.
        // Evita N+1: antes eram 2 queries por aprovação; agora são 2 queries no total.
        $reqIds = [];
        foreach ($pendentes as $p) {
            if ($p['entidade_tipo'] === 'requisicao') {
                $reqIds[] = $p['entidade_id'];
            }
        }
        $reqIds = array_values(array_unique($reqIds));

        $detalhesPorId = [];
        $itensPorId    = [];
        if (!empty($reqIds)) {
            $db     = Database::getInstance();
            $inList = implode(',', array_fill(0, count($reqIds), '?'));

            // 1 query para todos os detalhes das requisições
            $stmt = $db->prepare(
                "SELECT r.*,
                        u.nome  AS solicitante_nome,
                        u.email AS solicitante_email,
                        f.nome  AS filial_nome,
                        s.nome  AS setor_nome,
                        gp.nome AS grupo_nome
                 FROM requisicoes r
                 JOIN usuarios u         ON u.id  = r.usuario_id
                 JOIN filiais f          ON f.id  = r.filial_id
                 JOIN setores s          ON s.id  = r.setor_id
                 LEFT JOIN grupos_produtos gp ON gp.id = r.grupo_produto_id
                 WHERE r.id IN ($inList)"
            );
            $stmt->execute($reqIds);
            foreach ($stmt->fetchAll() as $row) {
                $detalhesPorId[$row['id']] = $row;
            }

            // 1 query para todos os itens; agrupados por requisicao_id em PHP
            $stmtItens = $db->prepare(
                "SELECT ri.*,
                        i.nome AS item_nome, i.unidade, i.grupo_produto_id, i.valor_referencia,
                        gp.nome AS grupo_nome,
                        COALESCE(
                            NULLIF(ri.valor_unitario, 0),
                            e.valor_unitario,
                            (SELECT hp.valor_unitario FROM historico_precos hp WHERE hp.item_id = i.id ORDER BY hp.registrado_em DESC LIMIT 1),
                            i.valor_referencia, 0
                        ) AS valor_unit_resolvido
                 FROM requisicao_itens ri
                 LEFT JOIN itens i ON i.id = ri.item_id
                 LEFT JOIN grupos_produtos gp ON gp.id = i.grupo_produto_id
                 LEFT JOIN requisicoes req ON req.id = ri.requisicao_id
                 LEFT JOIN estoque e ON e.item_id = ri.item_id AND e.filial_id = req.filial_id
                 WHERE ri.requisicao_id IN ($inList)"
            );
            $stmtItens->execute($reqIds);
            foreach ($stmtItens->fetchAll() as $item) {
                $itensPorId[$item['requisicao_id']][] = $item;
            }
        }

        // Mapeia os dados em lote de volta para cada aprovação pendente
        foreach ($pendentes as &$p) {
            if ($p['entidade_tipo'] === 'requisicao') {
                $p['req_detalhes'] = $detalhesPorId[$p['entidade_id']] ?? null;
                $p['req_itens']    = $itensPorId[$p['entidade_id']]    ?? [];
            }
        }
        unset($p);

        // Enriquecimento das aprovações de brinde (entidade_tipo = 'requisicao_brinde')
        $brindeReqIds = [];
        foreach ($pendentes as $p) {
            if ($p['entidade_tipo'] === 'requisicao_brinde') {
                $brindeReqIds[] = $p['entidade_id'];
            }
        }
        $brindeReqIds = array_values(array_unique($brindeReqIds));

        $brindeDetalhes = [];
        $brindeItens    = [];
        if (!empty($brindeReqIds)) {
            $db     = Database::getInstance();
            $inList = implode(',', array_fill(0, count($brindeReqIds), '?'));

            $stmt = $db->prepare(
                "SELECT rb.*, f.nome AS filial_nome, u.nome AS solicitante_nome
                 FROM requisicoes_brinde rb
                 JOIN filiais f  ON f.id = rb.filial_id
                 JOIN usuarios u ON u.id = rb.criado_por
                 WHERE rb.id IN ($inList)"
            );
            $stmt->execute($brindeReqIds);
            foreach ($stmt->fetchAll() as $row) {
                $brindeDetalhes[$row['id']] = $row;
            }

            $stmtItens = $db->prepare(
                "SELECT ri.*, b.nome AS brinde_nome
                 FROM requisicao_brinde_itens ri
                 JOIN brindes b ON b.id = ri.brinde_id
                 WHERE ri.requisicao_brinde_id IN ($inList)"
            );
            $stmtItens->execute($brindeReqIds);
            foreach ($stmtItens->fetchAll() as $item) {
                $brindeItens[$item['requisicao_brinde_id']][] = $item;
            }
        }

        foreach ($pendentes as &$p) {
            if ($p['entidade_tipo'] === 'requisicao_brinde') {
                $det = $brindeDetalhes[$p['entidade_id']] ?? null;
                $p['brinde_detalhes'] = $det;
                $p['brinde_itens']    = $brindeItens[$p['entidade_id']] ?? [];
                if ($det) {
                    $p['valor']            = $det['valor_total'];
                    $p['solicitante_nome'] = $det['solicitante_nome'];
                }
            }
        }
        unset($p);

        $historico = $model->historicoParaUsuario($user['id'], $user['perfil']);

        $this->view('aprovacoes/index', compact('pendentes', 'historico', 'user'));
    }

    public function aprovar(string $id): void
    {
        $this->requirePerfil(['gestor','diretor','admin','almoxarife']);
        $user = auth();

        $model = new Aprovacao();
        $aprov = $model->find($id);
        if (!$aprov) { session_flash('error', 'Aprovação não encontrada.'); redirect('/aprovacoes'); }

        // Sem isto, aprovar duas vezes a mesma aprovação (duplo clique, duas abas)
        // gera a OC/reserva de estoque duas vezes e consome o orçamento em dobro.
        if ($aprov['status'] !== 'pendente') {
            session_flash('error', 'Esta aprovação já foi decidida.');
            redirect('/aprovacoes');
        }

        // Mesma regra da fila: alçada pelo valor, nível corrente e escopo.
        // Sem isto, um POST com o id aprovava fora da vez ou de outra unidade.
        if (!$model->podeDecidir($id, $user['id'], $user['perfil'])) {
            session_flash('error', 'Esta aprovação não está na sua fila (aguarda o nível anterior ou é de outra alçada).');
            redirect('/aprovacoes');
        }

        if ($user['perfil'] === 'almoxarife' && !$this->almoxarifePodeDecidir($aprov, $user['id'])) {
            session_flash('error', 'Você não tem permissão para decidir esta aprovação.');
            redirect('/aprovacoes');
        }

        $obs = $this->input('observacao', '');

        // Verifica se há próximo nível
        $proximoNivel = $model->findBy([
            'entidade_tipo' => $aprov['entidade_tipo'],
            'entidade_id'   => $aprov['entidade_id'],
            'nivel'         => $aprov['nivel'] + 1,
            'status'        => 'pendente',
        ]);

        // A liberação roda ANTES de gravar o status da aprovação. Ela tem
        // transação própria, então marcar antes deixaria um registro órfão
        // quando a baixa falhasse: aprovação resolvida, entidade ainda
        // pendente e sem estoque movimentado — e, pior, fora da fila, sem
        // ninguém conseguir processá-la de novo.
        $transferOk = true;
        if (!$proximoNivel) {
            // Aprovação final — libera a entidade
            $transferOk = $this->liberarEntidade($aprov);

            // Saída de brinde é tudo-ou-nada: sem baixa não há o que aprovar.
            // A aprovação segue pendente para ser refeita depois de acertar o
            // estoque. Transferência não entra aqui de propósito — lá a
            // aprovação vale, e a movimentação pode ser feita manualmente.
            // Ordem de Compra entra pelo mesmo motivo: sem OC gerada não há o
            // que aprovar (ver liberarEntidade/$bloqueiaAprovacao — I3).
            if (!$transferOk && ($aprov['entidade_tipo'] === 'requisicao_brinde' || $this->bloqueiaAprovacao)) {
                session_flash('error', $this->erroLiberacao ?? 'Falha ao liberar a entidade.');
                redirect('/aprovacoes');
            }

            // Requisição vinda de Solicitação de Compra: o solicitante acompanha.
            if ($aprov['entidade_tipo'] === 'requisicao') {
                (new \App\Services\SolicitacaoCompraService())->aoAprovarRequisicao($aprov['entidade_id'], $user);
            }
        }

        $model->update($id, [
            'status'      => 'aprovado',
            'aprovado_por'=> $user['id'],
            'observacao'  => $obs,
            'resolvido_em'=> date('Y-m-d H:i:s'),
        ]);

        (new AuditoriaService())->log($user['id'], 'aprovacao', $id, 'aprovacao.aprovada',
            ['status' => 'pendente'], ['status' => 'aprovado', 'obs' => $obs]);

        if (!$transferOk) {
            session_flash('warning', 'Aprovação registrada, mas a transferência de estoque não foi concluída automaticamente. Verifique o Monitor de Fluxo da requisição e movimente o estoque manualmente se necessário.');
        } else {
            session_flash('success', 'Aprovação registrada.');
        }
        redirect('/aprovacoes');
    }

    public function rejeitar(string $id): void
    {
        $this->requirePerfil(['gestor','diretor','admin','almoxarife']);
        $user = auth();

        $model = new Aprovacao();
        $aprov = $model->find($id);
        if (!$aprov) { session_flash('error', 'Aprovação não encontrada.'); redirect('/aprovacoes'); }

        if ($user['perfil'] === 'almoxarife' && !$this->almoxarifePodeDecidir($aprov, $user['id'])) {
            session_flash('error', 'Você não tem permissão para decidir esta aprovação.');
            redirect('/aprovacoes');
        }

        if ($aprov['status'] !== 'pendente' || !$model->podeDecidir($id, $user['id'], $user['perfil'])) {
            session_flash('error', 'Esta aprovação não está na sua fila (já decidida, aguarda o nível anterior ou é de outra alçada).');
            redirect('/aprovacoes');
        }

        $obs = $this->input('observacao', 'Rejeitado pelo aprovador.');

        $model->update($id, [
            'status'       => 'rejeitado',
            'aprovado_por' => $user['id'],
            'observacao'   => $obs,
            'resolvido_em' => date('Y-m-d H:i:s'),
        ]);

        // Rejeita a entidade
        if ($aprov['entidade_tipo'] === 'requisicao') {
            (new Requisicao())->update($aprov['entidade_id'], ['status' => 'rejeitado']);
            (new \App\Services\SolicitacaoCompraService())->aoRejeitarRequisicao($aprov['entidade_id'], $user, (string)$obs);
        } elseif ($aprov['entidade_tipo'] === 'ordem_compra') {
            (new OrdemCompra())->update($aprov['entidade_id'], ['status' => 'cancelado']);
        } elseif ($aprov['entidade_tipo'] === 'requisicao_brinde') {
            \App\Config\Database::getInstance()
                ->prepare("UPDATE requisicoes_brinde SET status='rejeitado', resolvido_em=NOW() WHERE id = ?")
                ->execute([$aprov['entidade_id']]);
        }

        (new AuditoriaService())->log($user['id'], 'aprovacao', $id, 'aprovacao.rejeitada',
            null, ['obs' => $obs]);

        session_flash('warning', 'Requisição rejeitada.');
        redirect('/aprovacoes');
    }

    /**
     * Almoxarife decide aprovações de movimentação de material (Requisição
     * Interna) da própria requisição, ou de uma Requisição Interna criada por
     * um Gestor da mesma filial — e de requisição de brinde. Compra e
     * transferência continuam exigindo gestor/diretor/admin.
     */
    private function almoxarifePodeDecidir(array $aprov, string $usuarioId): bool
    {
        $db = Database::getInstance();

        if ($aprov['entidade_tipo'] === 'requisicao') {
            $stmt = $db->prepare(
                "SELECT 1 FROM requisicoes r
                 JOIN usuarios ru ON ru.id = r.usuario_id
                 WHERE r.id = ? AND r.tipo = 'interna'
                   AND (
                        r.usuario_id = ?
                     OR (ru.perfil = 'gestor' AND r.filial_id = (SELECT filial_id FROM usuarios WHERE id = ?))
                   )"
            );
            $stmt->execute([$aprov['entidade_id'], $usuarioId, $usuarioId]);
            return (bool)$stmt->fetchColumn();
        }

        if ($aprov['entidade_tipo'] === 'requisicao_brinde') {
            $stmt = $db->prepare(
                "SELECT 1 FROM requisicoes_brinde WHERE id = ? AND criado_por = ?"
            );
            $stmt->execute([$aprov['entidade_id'], $usuarioId]);
            return (bool)$stmt->fetchColumn();
        }

        return false;
    }

    /**
     * Dá baixa no estoque da saída de brinde. Tudo-ou-nada: devolve false sem
     * ter movimentado nada se algum item não tiver saldo, deixando a mensagem
     * em $erroLiberacao. Quem chama decide o que fazer — aprovar() usa isso
     * para NÃO registrar a aprovação, já que sem baixa não há saída liberada.
     */
    private function liberarRequisicaoBrinde(string $reqId): bool
    {
        $db   = \App\Config\Database::getInstance();
        $user = auth();

        $reqStmt = $db->prepare("SELECT * FROM requisicoes_brinde WHERE id = ? LIMIT 1");
        $reqStmt->execute([$reqId]);
        $req = $reqStmt->fetch();
        // Já processada (ou inexistente): não há baixa a fazer, e isso não é
        // uma falha de estoque que deva impedir o registro da aprovação.
        if (!$req || $req['status'] !== 'em_aprovacao') return true;

        $itensStmt = $db->prepare(
            "SELECT rbi.brinde_id, rbi.quantidade, b.item_id
             FROM requisicao_brinde_itens rbi
             JOIN brindes b ON b.id = rbi.brinde_id
             WHERE rbi.requisicao_brinde_id = ?"
        );
        $itensStmt->execute([$reqId]);
        $itens = $itensStmt->fetchAll();

        $db->beginTransaction();
        try {
            foreach ($itens as $it) {
                $qtd = (float)$it['quantidade'];

                if (!empty($it['item_id'])) {
                    // Brinde vinculado: o estoque do item é a fonte única.
                    $saldoStmt = $db->prepare(
                        "SELECT COALESCE(quantidade, 0) FROM estoque
                         WHERE item_id = ? AND filial_id = ? FOR UPDATE"
                    );
                    $saldoStmt->execute([$it['item_id'], $req['filial_id']]);
                    $saldo = (float)($saldoStmt->fetchColumn() ?: 0);
                    if ($saldo < $qtd) {
                        throw new \RuntimeException('Estoque insuficiente para um dos brindes.');
                    }

                    $db->prepare(
                        "UPDATE estoque SET quantidade = quantidade - ?, atualizado_em = NOW()
                         WHERE item_id = ? AND filial_id = ?"
                    )->execute([$qtd, $it['item_id'], $req['filial_id']]);

                    $db->prepare(
                        "INSERT INTO movimentos_estoque
                         (id, item_id, filial_id, tipo, motivo, quantidade, referencia_tipo, referencia_id, usuario_id, criado_em)
                         VALUES (?, ?, ?, 'saida', 'brinde', ?, 'brinde', ?, ?, NOW())"
                    )->execute([uuid(), $it['item_id'], $req['filial_id'], $qtd, $reqId, $user['id']]);
                } else {
                    // Brinde legado sem vínculo: comportamento anterior preservado.
                    $saldoStmt = $db->prepare(
                        "SELECT quantidade FROM brinde_estoque
                         WHERE brinde_id = ? AND filial_id = ? FOR UPDATE"
                    );
                    $saldoStmt->execute([$it['brinde_id'], $req['filial_id']]);
                    $saldo = (float)($saldoStmt->fetchColumn() ?: 0);
                    if ($saldo < $qtd) {
                        throw new \RuntimeException('Estoque insuficiente para um dos brindes.');
                    }

                    $db->prepare(
                        "UPDATE brinde_estoque SET quantidade = quantidade - ?
                         WHERE brinde_id = ? AND filial_id = ?"
                    )->execute([$qtd, $it['brinde_id'], $req['filial_id']]);
                }

                // Histórico do módulo de brindes, para os dois casos.
                $db->prepare(
                    "INSERT INTO movimentos_brinde
                     (id, brinde_id, filial_id, tipo, quantidade, referencia_tipo, referencia_id, criado_por)
                     VALUES (?,?,?,'saida',?,'requisicao_brinde',?,?)"
                )->execute([uuid(), $it['brinde_id'], $req['filial_id'], $qtd, $reqId, $user['id']]);
            }
            $db->prepare("UPDATE requisicoes_brinde SET status='aprovado', resolvido_em=NOW() WHERE id = ?")->execute([$reqId]);
            $db->commit();
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            $this->erroLiberacao = 'Falha ao dar baixa no estoque do brinde: ' . $e->getMessage();
            return false;
        }
    }

    private function liberarEntidade(array $aprov): bool
    {
        if ($aprov['entidade_tipo'] === 'requisicao_brinde') {
            return $this->liberarRequisicaoBrinde($aprov['entidade_id']);
        }

        // OC em rascunho por reaprovação (valor cotado mudou de faixa): só agora vale.
        if ($aprov['entidade_tipo'] === 'ordem_compra') {
            $upd = \App\Config\Database::getInstance()->prepare(
                "UPDATE ordens_compra SET status = 'aprovado' WHERE id = ? AND status = 'rascunho'"
            );
            $upd->execute([$aprov['entidade_id']]);
            if ($upd->rowCount() > 0) {
                (new \App\Services\SolicitacaoCompraService())->aoGerarOrdemCompra($aprov['entidade_id'], auth());
            }
            return true;
        }

        if ($aprov['entidade_tipo'] === 'requisicao') {
            $req = (new Requisicao())->find($aprov['entidade_id']);
            if (!$req) return true;

            if ($req['tipo'] === 'ordem_compra') {
                // Ordem de Compra: a aprovação leva as cotações digitadas para
                // Compras > Cotações e o comprador gera a OC pelo comparativo
                // (fluxo normal). A cotação é criada ANTES de marcar a requisição
                // como aprovada: se falhar, a aprovação continua pendente e o
                // pedido não fica aprovado sem destino (ver aprovar()).
                $cotacaoId = (new \App\Services\OrdemCompraDiretaService())->gerarCotacao($req['id'], auth()['id']);
                if (!$cotacaoId) {
                    $this->erroLiberacao     = 'Não foi possível abrir a cotação: confirme se as cotações do pedido continuam cadastradas.';
                    $this->bloqueiaAprovacao = true;
                    return false;
                }

                (new Requisicao())->update($aprov['entidade_id'], ['status' => 'aprovado']);

                // Sem grupo de produto no pedido, o consumo sai do orçamento do
                // setor no mês. Sem orçamento cadastrado não há o que consumir —
                // e isso não bloqueia a aprovação.
                //
                // O "if" do status protege contra efeito dobrado: $req foi lido
                // antes do update acima, então guarda o status anterior. Reaprovar
                // (duplo clique, retentativa) devolve a mesma cotação e não pode
                // comer orçamento, registrar auditoria nem notificar de novo.
                if (($req['status'] ?? '') !== 'aprovado') {
                    $orcReg = (new \App\Models\Orcamento())->findDoSetor(
                        $req['filial_id'],
                        $req['setor_id'],
                        (int)date('n'),
                        (int)date('Y')
                    );
                    if ($orcReg) {
                        (new OrcamentoService())->consumir($orcReg['id'], (float)$req['valor_total']);
                    }

                    (new AuditoriaService())->log(
                        auth()['id'], 'cotacao', $cotacaoId, 'cotacao.gerada_ordem_compra',
                        null, ['requisicao_id' => $req['id']]
                    );

                    // Notifica solicitante — mesmo trecho usado pelos outros tipos, abaixo.
                    (new NotificacaoService())->criar(
                        $req['usuario_id'], 'oc_aprovada',
                        'Requisição aprovada!',
                        sprintf('Sua requisição de %s foi aprovada e já está em Cotações.', money($req['valor_total'])),
                        'requisicao', $req['id']
                    );
                }

                return true;
            }

            (new Requisicao())->update($aprov['entidade_id'], ['status' => 'aprovado']);

            $transferOk = true;
            if ($req['tipo'] === 'transferencia') {
                // Solicitação de Transferência — transfere da matriz para a filial
                // solicitante, com confirmação de recebimento (ver TransferenciaController).
                $transferOk = $this->transferirDaMatriz($req);
            } elseif ($req['tipo'] === 'interna') {
                // Requisição Interna — fluxo direto/antigo: baixa e entrada imediatas,
                // sem etapa de confirmação de recebimento.
                $this->movimentarRequisicaoInterna($req);
            } else {
                // Caminho de compra -- consome orcamento e reserva itens no estoque
                $orcModel = new \App\Models\Orcamento();
                $mes      = (int)date('n');
                $ano      = (int)date('Y');
                $reqModel = new Requisicao();
                $orcSvc   = new OrcamentoService();
                $dbRes    = \App\Config\Database::getInstance();

                foreach ($reqModel->valoresPorGrupo($req['id']) as $grupo) {
                    // Item sem cadastro não tem grupo: consome do orçamento do setor
                    // no mês, como o caminho de Ordem de Compra. Antes passava null
                    // para findMelhorParaSetor (TypeError → 500) e deixava a
                    // requisição aprovada com a aprovação ainda pendente.
                    $orcReg = $grupo['grupo_produto_id'] === null
                        ? $orcModel->findDoSetor($req['filial_id'], $req['setor_id'], $mes, $ano)
                        : $orcModel->findMelhorParaSetor(
                            $req['filial_id'],
                            $req['setor_id'],
                            $grupo['grupo_produto_id'],
                            $mes,
                            $ano
                        );
                    if ($orcReg) {
                        $orcSvc->consumir($orcReg['id'], (float)$grupo['valor_total']);
                    }
                }

                // S4 (spec 2026-10-30): o Requisições não tem estoque — não reserva
                // nada na aprovação. O laço abaixo fica desligado de propósito.
                foreach ([] as $item) {
                    if (empty($item['item_id'])) continue; // item sem cadastro: não há estoque a reservar
                    $dbRes->prepare(
                        "INSERT INTO estoque (id, item_id, filial_id, quantidade, quantidade_reservada, atualizado_em)
                         VALUES (?, ?, ?, 0, ?, NOW())
                         ON DUPLICATE KEY UPDATE
                           quantidade_reservada = quantidade_reservada + VALUES(quantidade_reservada),
                           atualizado_em = NOW()"
                    )->execute([uuid(), $item['item_id'], $req['filial_id'], $item['quantidade']]);
                }
            }

            // Notifica solicitante
            (new NotificacaoService())->criar(
                $req['usuario_id'], 'oc_aprovada',
                'Requisição aprovada!',
                sprintf('Sua requisição de %s foi aprovada.', money($req['valor_total'])),
                'requisicao', $req['id']
            );

            return $transferOk;
        }

        return true;
    }

    /**
     * Transfere os itens de uma requisição interna do estoque da matriz para o
     * estoque da filial solicitante. Regra: toda saída vem da matriz.
     *
     * Tudo-ou-nada: se a matriz não tiver saldo suficiente para QUALQUER item,
     * nenhuma movimentação é feita — apenas registra auditoria de estoque
     * insuficiente. A requisição já foi marcada como aprovada antes desta etapa.
     */
    private function transferirDaMatriz(array $req): bool
    {
        $estModel = new Estoque();
        $reqModel = new Requisicao();
        $user     = auth();
        $audit    = new AuditoriaService();

        $matriz = $estModel->getFilialMatriz();

        $destinoId = $req['filial_destino_id'] ?? $req['filial_id'];

        // Sem matriz cadastrada, ou o destino já é a própria matriz: nada a transferir.
        if (!$matriz || $matriz['id'] === $destinoId) {
            $audit->log(
                $user['id'], 'requisicao', $req['id'],
                'requisicao.transferencia_ignorada', null,
                ['motivo' => !$matriz ? 'sem_matriz' : 'destino_e_matriz']
            );
            return false;
        }

        $matrizId  = $matriz['id'];

        // Apenas itens cadastrados — itens livres não têm estoque.
        $itens = array_values(array_filter(
            $reqModel->itens($req['id']),
            fn($it) => !empty($it['item_id'])
        ));
        if (empty($itens)) {
            $audit->log(
                $user['id'], 'requisicao', $req['id'],
                'requisicao.transferencia_ignorada', null,
                ['motivo' => 'sem_itens_cadastrados']
            );
            return false;
        }

        $db = Database::getInstance();

        // ── Pré-validação tudo-ou-nada: matriz precisa ter saldo de TODOS os itens.
        $faltantes = [];
        foreach ($itens as $item) {
            $stmt = $db->prepare(
                "SELECT COALESCE(quantidade, 0) FROM estoque WHERE item_id = ? AND filial_id = ? LIMIT 1"
            );
            $stmt->execute([$item['item_id'], $matrizId]);
            $saldo = (float)($stmt->fetchColumn() ?: 0);
            if ($saldo < (float)$item['quantidade']) {
                $faltantes[] = $item;
            }
        }

        if (!empty($faltantes)) {
            foreach ($faltantes as $item) {
                $audit->log(
                    $user['id'], 'requisicao', $req['id'],
                    'requisicao.estoque_insuficiente_na_aprovacao', null,
                    [
                        'item_id'   => $item['item_id'],
                        'quantidade'=> $item['quantidade'],
                        'motivo'    => sprintf('Estoque insuficiente na matriz para %s (necessário: %s)', $item['item_nome'] ?? $item['item_id'], $item['quantidade']),
                    ]
                );
            }
            return false; // Nada é movido.
        }

        // ── Transação: cria transferência + movimenta cada item.
        $transferId = uuid();
        $db->beginTransaction();
        try {
            $stmtNumTransf = $db->query("SELECT COALESCE(MAX(numero), 0) + 1 FROM transferencias FOR UPDATE");
            $proximoNumeroTransf = (int)$stmtNumTransf->fetchColumn();

            $db->prepare(
                "INSERT INTO transferencias
                 (id, numero, filial_origem_id, filial_destino_id, requisicao_id, usuario_solicitante, status, observacao, criado_em)
                 VALUES (?, ?, ?, ?, ?, ?, 'aprovado', ?, NOW())"
            )->execute([
                $transferId, $proximoNumeroTransf, $matrizId, $destinoId, $req['id'], $req['usuario_id'],
                'Transferencia automatica por aprovacao de requisicao interna'
            ]);

            foreach ($itens as $item) {
                $itemId = $item['item_id'];
                $qtd    = (float)$item['quantidade'];

                // Verificação autoritativa contra concorrência. A pré-validação acima já
                // garantiu que existe uma linha de estoque com saldo suficiente na matriz,
                // então o FOR UPDATE aqui sempre encontra e trava a linha (nunca é no-op).
                $lockStmt = $db->prepare(
                    "SELECT COALESCE(quantidade, 0) FROM estoque WHERE item_id = ? AND filial_id = ? LIMIT 1 FOR UPDATE"
                );
                $lockStmt->execute([$itemId, $matrizId]);
                $saldoLock = (float)($lockStmt->fetchColumn() ?: 0);
                if ($saldoLock < $qtd) {
                    $db->rollBack();
                    $audit->log(
                        $user['id'], 'requisicao', $req['id'],
                        'requisicao.estoque_insuficiente_na_aprovacao', null,
                        [
                            'item_id'   => $itemId,
                            'quantidade'=> $qtd,
                            'motivo'    => sprintf('Estoque insuficiente na matriz para %s (necessário: %s)', $item['item_nome'] ?? $itemId, $qtd),
                        ]
                    );
                    return false;
                }

                // Saída na matriz.
                $db->prepare(
                    "UPDATE estoque SET quantidade = quantidade - ?, atualizado_em = NOW()
                     WHERE item_id = ? AND filial_id = ?"
                )->execute([$qtd, $itemId, $matrizId]);

                // Histórico: saída na matriz. A entrada na filial destino só acontece quando
                // ela confirmar o recebimento (ver TransferenciaController::confirmar()).
                $db->prepare(
                    "INSERT INTO movimentos_estoque
                     (id, item_id, filial_id, tipo, motivo, quantidade, referencia_tipo, referencia_id, usuario_id, criado_em)
                     VALUES (?, ?, ?, 'saida', 'transferencia', ?, 'transferencia', ?, ?, NOW())"
                )->execute([uuid(), $itemId, $matrizId, $qtd, $transferId, $user['id']]);

                $estModel->espelharBrinde($itemId, $matrizId, $qtd, 'saida', $user['id'], 'transferencia');

                // Registra o item esperado — quantidade_recebida fica em aberto até a confirmação.
                $db->prepare(
                    "INSERT INTO transferencia_itens (id, transferencia_id, item_id, quantidade)
                     VALUES (?, ?, ?, ?)"
                )->execute([uuid(), $transferId, $itemId, $qtd]);
            }

            $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $audit->log(
                $user['id'], 'requisicao', $req['id'],
                'requisicao.transferencia_falhou', null,
                ['erro' => $e->getMessage()]
            );
            return false;
        }
    }

    /**
     * Requisição Interna — fluxo direto/antigo: para cada item, busca uma filial
     * com saldo (própria → matriz → outra) e registra a saída imediatamente.
     * Sem confirmação de recebimento.
     */
    private function movimentarRequisicaoInterna(array $req): void
    {
        $estModel = new Estoque();
        $reqModel = new Requisicao();
        $user     = auth();

        foreach ($reqModel->itens($req['id']) as $item) {
            if (empty($item['item_id'])) continue;

            $filial = $estModel->filialComEstoque(
                $item['item_id'],
                (float)$item['quantidade'],
                $req['filial_id']
            );
            if ($filial) {
                $estModel->registrarMovimento(
                    $item['item_id'],
                    $filial['filial_id'],
                    (float)$item['quantidade'],
                    'saida',
                    $req['id'],
                    $user['id']
                );
                $estModel->espelharBrinde($item['item_id'], $filial['filial_id'], (float)$item['quantidade'], 'saida', $user['id'], 'requisicao');
            } else {
                (new AuditoriaService())->log(
                    $user['id'], 'requisicao', $req['id'],
                    'requisicao.estoque_insuficiente_na_aprovacao',
                    null,
                    ['item_id' => $item['item_id'], 'quantidade' => $item['quantidade']]
                );
            }
        }
    }
}