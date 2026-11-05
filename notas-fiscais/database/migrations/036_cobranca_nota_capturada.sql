-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 036 — Cobrança da NF-e na nota capturada (fatura + duplicatas)
--
-- Problema: o XML da NF-e traz o bloco <cobr> com a FATURA (<fat><nFat>) e as
-- DUPLICATAS (<dup><nDup>/<dVenc>/<vDup>), além da forma de pagamento em
-- <pag><detPag><tPag>. Nada disso era lido na captura — a nota virava processo
-- SEM parcela nenhuma e o financeiro redigitava vencimento e valor que já
-- estavam no XML. Caso real: PROC-2026-7760 abriu às 03:00 por regra de
-- pré-fluxo e às 08:50 alguém digitou à mão a parcela 03/09/2026 R$ 188,00 —
-- exatamente a duplicata 001 do XML, com tPag 15 (boleto).
--
-- Solução: guardar a cobrança na própria nota capturada, do mesmo jeito que
-- itens_codigos já faz com os cProd. Na vinculação as duplicatas viram as
-- parcelas do processo.
--
--   numero_fatura : nFat, para conferência e busca (o financeiro cobra por ela)
--   cobranca      : JSON {fatura, duplicatas[], forma_pagamento_cod}
--                   NULL = XML ainda não lido (nota anterior a esta migration);
--                   JSON com duplicatas [] = lido e a nota não tem cobrança.
--                   A distinção evita reler o XML do storage a cada tela.
--
-- Banco local: compras · Banco servidor: Notas (o USE fica a cargo do comando)
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/036_cobranca_nota_capturada.sql
--
-- BACKFILL das notas já capturadas e dos processos já abertos sem parcela:
--   php cli/backfill_cobranca.php            (simulação)
--   php cli/backfill_cobranca.php --aplicar  (grava)
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE nf_notas_capturadas
  ADD COLUMN `numero_fatura` VARCHAR(60) NULL
    COMMENT 'nFat do bloco <cobr><fat> da NF-e'
    AFTER `natureza_operacao`,
  ADD COLUMN `cobranca` TEXT NULL
    COMMENT 'JSON {fatura, duplicatas[], forma_pagamento_cod} lido do XML - NULL = nao lido'
    AFTER `numero_fatura`;
