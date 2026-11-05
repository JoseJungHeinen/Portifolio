-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 019 — Critério de match por código dos itens (pré-fluxo)
--
-- Caso de uso: mesmo fornecedor + mesma filial + mesmo CFOP (ex.: 5152) traz
-- Veículos e Peças na mesma rota fiscal. A distinção está no código do produto
-- (cProd): veículos SEMPRE iniciam com "V", peças nunca. O critério permite
-- duas regras distintas: "todos os itens iniciam com V" (veículos) e
-- "nenhum item inicia com V" (peças).
--
-- nf_captura_regras.item_condicao + item_prefixo: critério opcional (NF-e).
-- nf_notas_capturadas.itens_codigos: cache JSON dos cProd extraídos do XML
--   (preenchido no sync; notas antigas são preenchidas sob demanda pelo motor).
--
-- Banco: compras
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/019_prefluxo_item_criterio.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

ALTER TABLE `nf_captura_regras`
  ADD COLUMN `item_condicao` enum('todos_iniciam','nenhum_inicia') DEFAULT NULL
    COMMENT 'Critério opcional (NF-e): compara o prefixo do cProd dos itens' AFTER `tomador`,
  ADD COLUMN `item_prefixo` varchar(10) DEFAULT NULL
    COMMENT 'Prefixo do código do produto (ex.: V = veículo)' AFTER `item_condicao`;

ALTER TABLE `nf_notas_capturadas`
  ADD COLUMN `itens_codigos` text DEFAULT NULL
    COMMENT 'JSON: lista de cProd dos itens (NF-e) — cache para o motor de regras' AFTER `cfop`;
