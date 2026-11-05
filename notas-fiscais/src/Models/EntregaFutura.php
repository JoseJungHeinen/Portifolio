<?php
// @versao Proc_14 V_03_05/10/2026
declare(strict_types=1);

/**
 * Venda com entrega futura (migration 071).
 *
 * A nota de venda é um processo normal; aqui fica só o acompanhamento. Cada
 * remessa registrada soma ao entregue; ao atingir o valor da venda o status
 * passa de 'pendente' a 'concluido'.
 */
class EntregaFutura
{
    /** Tolerância de centavos na comparação entregue × venda. */
    private const TOLERANCIA = 0.05;

    private static function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }

    /** Registra a venda (chamado logo depois de criar o processo). */
    public static function criarVenda(string $processoId, string $usuarioId): string
    {
        $id = self::uuid();
        Database::getInstance()->prepare(
            "INSERT INTO nf_entrega_futura (id, processo_id, criado_por) VALUES (?, ?, ?)"
        )->execute([$id, $processoId, $usuarioId]);
        return $id;
    }

    /** A venda deste processo, se ele estiver em acompanhamento. @return array{id:string,status:string,qtd_remessas:int}|null */
    public static function vendaDoProcesso(string $processoId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT ef.id, ef.status,
                    (SELECT COUNT(*) FROM nf_entrega_futura_remessas r
                      WHERE r.entrega_id = ef.id AND r.deletado_em IS NULL) AS qtd_remessas
               FROM nf_entrega_futura ef WHERE ef.processo_id = ? LIMIT 1"
        );
        $stmt->execute([$processoId]);
        $row = $stmt->fetch();
        return $row ? ['id' => $row['id'], 'status' => $row['status'], 'qtd_remessas' => (int) $row['qtd_remessas']] : null;
    }

    /** Tira a venda do acompanhamento — só se ainda não tem remessa lançada. */
    public static function removerVenda(string $entregaId): bool
    {
        $stmt = Database::getInstance()->prepare(
            "DELETE FROM nf_entrega_futura
              WHERE id = ?
                AND NOT EXISTS (SELECT 1 FROM nf_entrega_futura_remessas r
                                 WHERE r.entrega_id = nf_entrega_futura.id AND r.deletado_em IS NULL)"
        );
        $stmt->execute([$entregaId]);
        return $stmt->rowCount() > 0;
    }

    private const SELECT_VENDA = "
        SELECT ef.id, ef.processo_id, ef.status, ef.concluido_em, ef.criado_em,
               p.codigo, p.status AS processo_status, p.fornecedor_nome, p.fornecedor_doc,
               p.numero_nf, p.data_nf, p.valor_nf,
               COALESCE((SELECT SUM(r.valor_nf) FROM nf_entrega_futura_remessas r
                          WHERE r.entrega_id = ef.id AND r.deletado_em IS NULL), 0) AS entregue,
               (SELECT COUNT(*) FROM nf_entrega_futura_remessas r
                 WHERE r.entrega_id = ef.id AND r.deletado_em IS NULL) AS qtd_remessas
          FROM nf_entrega_futura ef
          JOIN nf_processos p ON p.id = ef.processo_id";

    /** @return array<int,array> vendas com valor, entregue e saldo */
    public static function listar(string $status = '', string $busca = ''): array
    {
        $where  = [];
        $params = [];
        if (in_array($status, ['pendente', 'concluido'], true)) {
            $where[]  = 'ef.status = ?';
            $params[] = $status;
        }
        if ($busca !== '') {
            $where[] = '(p.numero_nf LIKE ? OR p.fornecedor_nome LIKE ? OR p.codigo LIKE ?)';
            $like    = '%' . $busca . '%';
            array_push($params, $like, $like, $like);
        }
        $sql = self::SELECT_VENDA
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . " ORDER BY (ef.status = 'pendente') DESC, p.data_nf DESC, ef.criado_em DESC LIMIT 500";
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'comSaldo'], $stmt->fetchAll());
    }

    /** Vendas pendentes para o seletor da etapa 2 (remessa). Uma venda não é remessa de si mesma. */
    public static function pendentes(?string $excluirProcessoId = null): array
    {
        return array_values(array_filter(
            self::listar('pendente'),
            static fn($v) => $v['processo_status'] !== 'cancelado'
                          && ($excluirProcessoId === null || $v['processo_id'] !== $excluirProcessoId)
        ));
    }

    /** O processo já é remessa de alguma venda? (então não exige o Financeiro) */
    public static function ehRemessa(string $processoId): bool
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT 1 FROM nf_entrega_futura_remessas WHERE processo_id = ? AND deletado_em IS NULL LIMIT 1"
        );
        $stmt->execute([$processoId]);
        return (bool) $stmt->fetchColumn();
    }

    /** A venda a que o processo pertence como remessa, se houver. @return array{entrega_id:string}|null */
    public static function remessaDoProcesso(string $processoId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT entrega_id FROM nf_entrega_futura_remessas WHERE processo_id = ? AND deletado_em IS NULL LIMIT 1"
        );
        $stmt->execute([$processoId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(string $id): ?array
    {
        $stmt = Database::getInstance()->prepare(self::SELECT_VENDA . ' WHERE ef.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::comSaldo($row) : null;
    }

    public static function remessas(string $entregaId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT r.*, u.nome AS criado_por_nome, p.codigo AS processo_codigo
               FROM nf_entrega_futura_remessas r
               LEFT JOIN usuarios u ON u.id = r.criado_por
               LEFT JOIN nf_processos p ON p.id = r.processo_id
              WHERE r.entrega_id = ? AND r.deletado_em IS NULL
              ORDER BY r.data_nf, r.criado_em"
        );
        $stmt->execute([$entregaId]);
        return $stmt->fetchAll();
    }

    private static function comSaldo(array $v): array
    {
        $v['valor_nf'] = (float) $v['valor_nf'];
        $v['entregue'] = (float) $v['entregue'];
        $v['saldo']    = max(0.0, round($v['valor_nf'] - $v['entregue'], 2));
        return $v;
    }

    /**
     * Confere se a remessa pode entrar na venda (status, valor × saldo, duplicidade).
     * Usado antes de gravar e também na Etapa 2, para recusar cedo.
     *
     * @return string|null mensagem de erro, ou null se pode registrar
     */
    public static function validarRemessa(string $entregaId, array $d): ?string
    {
        $venda = self::findById($entregaId);
        if (!$venda) {
            return 'Venda para entrega futura não encontrada.';
        }
        if ($venda['status'] === 'concluido') {
            return 'Esta venda já está concluída — todas as entregas foram registradas.';
        }
        if ($d['valor_nf'] <= 0) {
            return 'Informe o valor da remessa.';
        }
        if ($d['valor_nf'] > $venda['saldo'] + self::TOLERANCIA) {
            return sprintf(
                'O valor da remessa (R$ %s) passa do saldo a entregar da venda (R$ %s).',
                number_format($d['valor_nf'], 2, ',', '.'),
                number_format($venda['saldo'], 2, ',', '.')
            );
        }

        // Mesma remessa lançada duas vezes (nº + fornecedor, ou chave)
        $chave = (string) ($d['chave_nf'] ?? '');
        $dup   = Database::getInstance()->prepare(
            "SELECT 1 FROM nf_entrega_futura_remessas
              WHERE entrega_id = ? AND deletado_em IS NULL
                AND ((numero_nf = ? AND COALESCE(fornecedor_doc, '') = ?) OR (? <> '' AND chave_nf = ?))
              LIMIT 1"
        );
        $dup->execute([$entregaId, $d['numero_nf'], (string) ($d['fornecedor_doc'] ?? ''), $chave, $chave]);
        if ($dup->fetchColumn()) {
            return 'Esta remessa (NF ' . $d['numero_nf'] . ') já foi registrada nesta venda.';
        }
        return null;
    }

    /**
     * Registra uma remessa na venda e recalcula o status.
     *
     * @param array{numero_nf:string, chave_nf:?string, data_nf:string, valor_nf:float, processo_id?:?string,
     *              fornecedor_id:?string, fornecedor_nome:?string, fornecedor_doc:?string} $d
     * @return array{ok:bool, mensagem:string, concluida:bool}
     */
    public static function registrarRemessa(string $entregaId, array $d, string $usuarioId): array
    {
        $erro = self::validarRemessa($entregaId, $d);
        if ($erro !== null) {
            return ['ok' => false, 'mensagem' => $erro, 'concluida' => false];
        }
        $pdo   = Database::getInstance();
        $chave = (string) ($d['chave_nf'] ?? '');

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO nf_entrega_futura_remessas
                   (id, entrega_id, processo_id, numero_nf, chave_nf, data_nf, valor_nf,
                    fornecedor_id, fornecedor_nome, fornecedor_doc, criado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                self::uuid(), $entregaId, $d['processo_id'] ?? null, $d['numero_nf'], $chave !== '' ? $chave : null,
                $d['data_nf'], $d['valor_nf'],
                $d['fornecedor_id'] ?: null, $d['fornecedor_nome'] ?: null, $d['fornecedor_doc'] ?: null,
                $usuarioId,
            ]);
            $concluida = self::recalcular($entregaId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['ok' => true, 'concluida' => $concluida, 'mensagem' => $concluida
            ? 'Remessa registrada. Venda concluída — toda a entrega foi realizada.'
            : 'Remessa registrada.'];
    }

    /** @return string|null id da venda afetada, ou null se a remessa não existe */
    public static function excluirRemessa(string $remessaId): ?string
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT entrega_id FROM nf_entrega_futura_remessas WHERE id = ? AND deletado_em IS NULL");
        $stmt->execute([$remessaId]);
        $entregaId = $stmt->fetchColumn();
        if (!$entregaId) return null;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE nf_entrega_futura_remessas SET deletado_em = NOW() WHERE id = ?")->execute([$remessaId]);
            self::recalcular((string) $entregaId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return (string) $entregaId;
    }

    /** Recalcula o status pela soma das remessas. @return bool true se ficou concluída */
    private static function recalcular(string $entregaId): bool
    {
        $v = self::findById($entregaId);
        if (!$v) return false;
        $concluida = $v['entregue'] >= $v['valor_nf'] - self::TOLERANCIA;
        Database::getInstance()->prepare(
            "UPDATE nf_entrega_futura
                SET status = ?, concluido_em = " . ($concluida ? 'COALESCE(concluido_em, NOW())' : 'NULL') . "
              WHERE id = ?"
        )->execute([$concluida ? 'concluido' : 'pendente', $entregaId]);
        return $concluida;
    }
}
