-- Migration 032: Reintroduz "Requisição Interna" como tipo próprio, distinto de
-- "Solicitação de Transferência" (que passou a exigir confirmação de recebimento
-- da matriz — ver migration 029). O tipo 'interna' volta a representar o fluxo
-- direto/antigo (baixa e entrada imediatas); 'transferencia' é o fluxo novo.
ALTER TABLE requisicoes
    MODIFY COLUMN tipo ENUM('compra','interna','transferencia') NOT NULL DEFAULT 'compra';

-- Backfill: todo registro 'interna' já existente foi criado quando o único botão
-- disponível era "Solicitação de Transferência" (fluxo matriz + confirmação) —
-- reclassifica para o novo tipo 'transferencia' antes que 'interna' volte a
-- significar o fluxo direto/antigo.
UPDATE requisicoes SET tipo = 'transferencia' WHERE tipo = 'interna';
