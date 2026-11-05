<?php
// @versao Ver.001.270826

namespace App\Services;

use App\Config\Database;

/**
 * Mantém a vitrine de Brindes sincronizada com o catálogo geral: todo item
 * ativo com código MKT tem um registro correspondente em `brindes`, vinculado
 * por `brindes.item_id`. Nome, marca, valor e saldo são lidos do item na hora
 * da exibição — este serviço cuida apenas da existência do vínculo.
 */
class BrindeSyncService
{
    /** Prefixo de código que define um item de marketing (brinde). */
    private const PREFIXO_MKT = 'MKT%';

    /**
     * Idempotente e seguro para rodar a cada request.
     *
     * @return int Quantidade de brindes criados nesta execução.
     */
    public function sincronizarItensMkt(string $usuarioId): int
    {
        $db = Database::getInstance();

        // Transação própria só quando não há uma em andamento — o serviço pode
        // ser chamado de dentro de um fluxo que já abriu a sua.
        $transacaoPropria = !$db->inTransaction();
        if ($transacaoPropria) $db->beginTransaction();

        try {
            $this->adotarBrindesLegados($db);
            $criados = $this->criarBrindesFaltantes($db, $usuarioId);
            $this->sincronizarAtivos($db);

            if ($transacaoPropria) $db->commit();
            return $criados;
        } catch (\Throwable $e) {
            if ($transacaoPropria && $db->inTransaction()) $db->rollBack();
            error_log('[BrindeSyncService] Falha na sincronização: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Passo 1 — brindes cadastrados antes do vínculo existir adotam o item de
     * mesmo código. Roda antes da criação para não duplicar a vitrine.
     */
    private function adotarBrindesLegados(\PDO $db): void
    {
        $db->prepare(
            "UPDATE brindes b
               JOIN itens i ON i.codigo = b.codigo AND i.codigo LIKE ?
                SET b.item_id = i.id
              WHERE b.item_id IS NULL"
        )->execute([self::PREFIXO_MKT]);
    }

    /** Passo 2 — cria o brinde de todo item MKT ativo que ainda não tem um. */
    private function criarBrindesFaltantes(\PDO $db, string $usuarioId): int
    {
        $stmt = $db->prepare(
            "SELECT i.id, i.nome, i.codigo, i.marca, i.descricao, i.valor_referencia
               FROM itens i
              WHERE i.ativo = 1
                AND i.codigo LIKE ?
                AND NOT EXISTS (SELECT 1 FROM brindes b WHERE b.item_id = i.id)"
        );
        $stmt->execute([self::PREFIXO_MKT]);
        $faltantes = $stmt->fetchAll();

        if (empty($faltantes)) return 0;

        $ins = $db->prepare(
            "INSERT INTO brindes
             (id, nome, descricao, codigo, item_id, marca, valor_referencia, ativo, criado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)"
        );
        foreach ($faltantes as $i) {
            $ins->execute([
                uuid(), $i['nome'], $i['descricao'], $i['codigo'],
                $i['id'], $i['marca'], $i['valor_referencia'] ?? 0, $usuarioId,
            ]);
        }
        return count($faltantes);
    }

    /**
     * Passo 3 — item desativado some da vitrine, reativado volta. Brindes sem
     * vínculo não são tocados.
     */
    private function sincronizarAtivos(\PDO $db): void
    {
        $db->exec(
            "UPDATE brindes b
               JOIN itens i ON i.id = b.item_id
                SET b.ativo = i.ativo
              WHERE b.ativo <> i.ativo"
        );
    }
}
