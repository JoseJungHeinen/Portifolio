<?php
// @versao Ver.001.281026

namespace App\Controllers;

use App\Core\Controller;
use App\Config\Database;
use App\Services\NotificacaoService;

class TransferenciaController extends Controller
{
    public function recebimento(): void
    {
        $this->requirePerfil(['almoxarife', 'gestor', 'diretor', 'admin']);
        $user = auth();
        $db   = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT t.id, t.numero, t.criado_em, t.observacao,
                    fo.nome AS filial_origem_nome,
                    (SELECT COUNT(*) FROM transferencia_itens ti WHERE ti.transferencia_id = t.id) AS total_itens
             FROM transferencias t
             JOIN filiais fo ON fo.id = t.filial_origem_id
             WHERE t.status = 'aprovado' AND t.filial_destino_id = ?
             ORDER BY t.criado_em ASC"
        );
        $stmt->execute([$user['filial_id']]);
        $transferencias = $stmt->fetchAll();

        $this->view('transferencias/recebimento', compact('transferencias', 'user'));
    }

    public function show(string $id): void
    {
        $this->requirePerfil(['almoxarife', 'gestor', 'diretor', 'admin']);
        $user = auth();
        $db   = Database::getInstance();

        $stmt = $db->prepare(
            "SELECT t.*, fo.nome AS filial_origem_nome, fd.nome AS filial_destino_nome
             FROM transferencias t
             JOIN filiais fo ON fo.id = t.filial_origem_id
             JOIN filiais fd ON fd.id = t.filial_destino_id
             WHERE t.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $transferencia = $stmt->fetch();

        if (!$transferencia || $transferencia['filial_destino_id'] !== $user['filial_id']) {
            session_flash('error', 'Transferência não encontrada.');
            redirect('/transferencias/recebimento');
        }

        if ($transferencia['status'] !== 'aprovado') {
            session_flash('error', 'Esta transferência já foi confirmada ou não está pendente.');
            redirect('/transferencias/recebimento');
        }

        $itensStmt = $db->prepare(
            "SELECT ti.id, ti.item_id, ti.quantidade, ti.quantidade_recebida, i.nome AS item_nome, i.unidade
             FROM transferencia_itens ti
             JOIN itens i ON i.id = ti.item_id
             WHERE ti.transferencia_id = ?
             ORDER BY i.nome"
        );
        $itensStmt->execute([$id]);
        $itens = $itensStmt->fetchAll();

        $this->view('transferencias/show', compact('transferencia', 'itens', 'user'));
    }

    public function confirmar(string $id): void
    {
        $this->requirePerfil(['almoxarife', 'gestor', 'diretor', 'admin']);
        $user = auth();
        $db   = Database::getInstance();

        $stmt = $db->prepare("SELECT * FROM transferencias WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $transferencia = $stmt->fetch();

        if (!$transferencia || $transferencia['filial_destino_id'] !== $user['filial_id'] || $transferencia['status'] !== 'aprovado') {
            session_flash('error', 'Transferência não encontrada ou já confirmada.');
            redirect('/transferencias/recebimento');
        }

        $itensStmt = $db->prepare(
            "SELECT ti.*, i.nome AS item_nome
             FROM transferencia_itens ti
             JOIN itens i ON i.id = ti.item_id
             WHERE ti.transferencia_id = ?"
        );
        $itensStmt->execute([$id]);
        $itens = $itensStmt->fetchAll();

        $quantidadesRecebidas = $_POST['quantidade_recebida'] ?? [];

        $db->beginTransaction();
        try {
            // Re-checa o status com FOR UPDATE DENTRO da transação — impede que dois
            // envios concorrentes (duplo clique, duas pessoas na filial) confirmem a
            // mesma transferência duas vezes e creditem o estoque em dobro.
            $lockStmt = $db->prepare("SELECT status FROM transferencias WHERE id = ? FOR UPDATE");
            $lockStmt->execute([$id]);
            if ($lockStmt->fetchColumn() !== 'aprovado') {
                $db->rollBack();
                session_flash('error', 'Esta transferência já foi confirmada.');
                redirect('/transferencias/recebimento');
            }

            $divergencias = [];

            foreach ($itens as $item) {
                $qtdRecebida = isset($quantidadesRecebidas[$item['id']])
                    ? parse_decimal((string)$quantidadesRecebidas[$item['id']])
                    : (float)$item['quantidade'];

                if ($qtdRecebida < 0) {
                    $qtdRecebida = 0.0;
                }

                $db->prepare(
                    "UPDATE transferencia_itens SET quantidade_recebida = ? WHERE id = ?"
                )->execute([$qtdRecebida, $item['id']]);

                if ($qtdRecebida > 0) {
                    $db->prepare(
                        "INSERT INTO estoque (id, item_id, filial_id, quantidade, atualizado_em)
                         VALUES (?, ?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE quantidade = quantidade + VALUES(quantidade), atualizado_em = NOW()"
                    )->execute([uuid(), $item['item_id'], $transferencia['filial_destino_id'], $qtdRecebida]);

                    $db->prepare(
                        "INSERT INTO movimentos_estoque
                         (id, item_id, filial_id, tipo, motivo, quantidade, referencia_tipo, referencia_id, usuario_id, criado_em)
                         VALUES (?, ?, ?, 'entrada', 'transferencia', ?, 'transferencia', ?, ?, NOW())"
                    )->execute([uuid(), $item['item_id'], $transferencia['filial_destino_id'], $qtdRecebida, $id, $user['id']]);

                    (new \App\Models\Estoque())->espelharBrinde(
                        $item['item_id'], $transferencia['filial_destino_id'], $qtdRecebida, 'entrada', $user['id'], 'transferencia'
                    );
                }

                if (abs($qtdRecebida - (float)$item['quantidade']) > 0.001) {
                    $divergencias[] = [
                        'item_nome' => $item['item_nome'],
                        'esperado'  => (float)$item['quantidade'],
                        'recebido'  => $qtdRecebida,
                    ];
                }
            }

            $db->prepare("UPDATE transferencias SET status = 'recebido', atualizado_em = NOW() WHERE id = ? AND status = 'aprovado'")
               ->execute([$id]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            session_flash('error', 'Erro ao confirmar recebimento: ' . $this->mensagemErro($e));
            redirect('/transferencias/' . $id);
        }

        if (!empty($divergencias) && !empty($transferencia['requisicao_id'])) {
            $detalhes = array_map(
                fn($d) => sprintf('%s (enviado: %s, recebido: %s)', $d['item_nome'], $d['esperado'], $d['recebido']),
                $divergencias
            );
            (new NotificacaoService())->criar(
                $transferencia['usuario_solicitante'],
                'transferencia_divergente',
                'Divergência no recebimento da sua transferência',
                'Um ou mais itens foram recebidos com quantidade diferente da enviada: ' . implode('; ', $detalhes),
                'requisicao',
                $transferencia['requisicao_id']
            );
        }

        session_flash('success', 'Recebimento confirmado com sucesso.');
        redirect('/transferencias/recebimento');
    }
}
