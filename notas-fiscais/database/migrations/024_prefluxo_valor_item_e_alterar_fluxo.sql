-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 024 — Pré-fluxo: critérios de valor da nota e código dos itens
--
-- 1. nf_captura_regras.valor_min / valor_max: faixa opcional sobre o valor
--    total da nota (ambos opcionais; só compara o lado preenchido).
-- 2. nf_captura_regras.item_condicao + item_prefixo: critério opcional (NF-e)
--    sobre o prefixo do cProd dos itens. item_prefixo aceita MÚLTIPLOS
--    prefixos separados por vírgula (ex.: "VN,VU" para veículos novos/usados),
--    por isso varchar(50) — supera o varchar(10) previsto na migration 019.
--    · todos_iniciam  → TODOS os itens iniciam com algum dos prefixos (veículos)
--    · nenhum_inicia  → NENHUM item inicia com os prefixos (peças)
-- 3. nf_notas_capturadas.itens_codigos: cache JSON dos cProd extraídos do XML
--    (preenchido no sync; notas antigas são preenchidas sob demanda pelo motor).
--
-- Usa IF NOT EXISTS para ser idempotente com a migration 019 (que previu
-- item_condicao/item_prefixo/itens_codigos mas pode não ter sido aplicada).
--
-- Banco local: compras · Banco servidor: Notas (ajustar o USE conforme o alvo)
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/024_prefluxo_valor_item_e_alterar_fluxo.sql
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE `nf_captura_regras`
  ADD COLUMN IF NOT EXISTS `valor_min` decimal(15,2) DEFAULT NULL
    COMMENT 'Critério opcional: valor total mínimo da nota' AFTER `tomador`,
  ADD COLUMN IF NOT EXISTS `valor_max` decimal(15,2) DEFAULT NULL
    COMMENT 'Critério opcional: valor total máximo da nota' AFTER `valor_min`,
  ADD COLUMN IF NOT EXISTS `item_condicao` enum('todos_iniciam','nenhum_inicia') DEFAULT NULL
    COMMENT 'Critério opcional (NF-e): compara o prefixo do cProd dos itens' AFTER `valor_max`,
  ADD COLUMN IF NOT EXISTS `item_prefixo` varchar(50) DEFAULT NULL
    COMMENT 'Prefixos do código do produto, separados por vírgula (ex.: VN,VU)' AFTER `item_condicao`;

-- Migration 019 aplicada antes desta deixaria item_prefixo em varchar(10)
ALTER TABLE `nf_captura_regras`
  MODIFY COLUMN `item_prefixo` varchar(50) DEFAULT NULL
    COMMENT 'Prefixos do código do produto, separados por vírgula (ex.: VN,VU)';

ALTER TABLE `nf_notas_capturadas`
  ADD COLUMN IF NOT EXISTS `itens_codigos` text DEFAULT NULL
    COMMENT 'JSON: lista de cProd dos itens (NF-e) — cache para o motor de regras' AFTER `cfop`;
