-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 017 — Seed de Departamentos + vínculo setores.departamento_id +
-- sincronização de nomes/status das lojas (filiais)
--
-- Fonte: backup real do banco compras (database/127_0_0_1.sql, colega em
-- 2026-07-13). Só traz o que existe literalmente no backup — setores sem
-- correspondência exata de ID no backup ficam sem departamento_id (o wizard
-- de Novo Processo tem a opção "— Setores sem departamento —" pra esses).
--
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/017_departamentos_lojas_seed.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

-- ── 1. Lista "Departamentos" + itens (ids idênticos ao backup) ──────────────
INSERT IGNORE INTO `nf_listas` (`id`, `nome`, `slug`, `tipo`, `descricao`, `ativo`, `criado_em`, `atualizado_em`) VALUES
('de9a47a0-0000-4000-8000-000000000001', 'Departamentos', 'departamentos', 'personalizada', 'Departamentos internos — lista de referência para formulários de processos', 1, '2026-07-08 15:23:35', '2026-07-08 15:58:40');

-- nota: nossa nf_lista_itens não tem a coluna `dados` (multi-coluna) que o
-- backup tem — schema mais simples aqui; todos os itens de Departamentos
-- têm `dados` vazio no backup mesmo, então não perde nada.
INSERT IGNORE INTO `nf_lista_itens` (`id`, `lista_id`, `valor`, `ativo`, `criado_em`) VALUES
('4b3be04b-b21b-4c68-bf88-42bb8d0ced3e', 'de9a47a0-0000-4000-8000-000000000001', 'Administrativo', 1, '2026-07-08 17:01:27'),
('f32d40ef-2665-4cd8-bbef-aae8d4e0be4d', 'de9a47a0-0000-4000-8000-000000000001', 'Novos', 1, '2026-07-08 20:09:08'),
('f4cb2c5a-e724-4a93-9913-f511ddaf5153', 'de9a47a0-0000-4000-8000-000000000001', 'Seminovos', 1, '2026-07-08 20:09:01'),
('f6b3b957-7b65-41e9-bd6b-514b284f225e', 'de9a47a0-0000-4000-8000-000000000001', 'Peças', 1, '2026-07-08 15:59:57'),
('2c35384d-2c31-4dc6-9cb9-24e5ab0c2d99', 'de9a47a0-0000-4000-8000-000000000001', 'Locadora', 1, '2026-07-08 20:08:32'),
('20595ee7-dba0-4b1e-a341-33a02ac0d7b5', 'de9a47a0-0000-4000-8000-000000000001', 'Venda Direta', 1, '2026-07-08 20:09:16'),
('00a28b2b-2522-44e4-8a7f-77fc1f695755', 'de9a47a0-0000-4000-8000-000000000001', 'Oficina e Funilaria', 1, '2026-07-08 20:08:53');

-- ── 2. Vínculo setores.departamento_id + codigo — só os 8 setores cujo ID ──
--    bate exatamente com o backup (Peças, Locadora, Marketing, TI, RH,
--    Financeiro, Documental, Compras). Os demais ficam como estão.
UPDATE `setores` SET `departamento_id` = 'f6b3b957-7b65-41e9-bd6b-514b284f225e', `codigo` = '300' WHERE `id` = '22000005-0000-0000-0000-000000000005'; -- Peças
UPDATE `setores` SET `departamento_id` = '2c35384d-2c31-4dc6-9cb9-24e5ab0c2d99', `codigo` = '102' WHERE `id` = '22000006-0000-0000-0000-000000000006'; -- Locadora
UPDATE `setores` SET `departamento_id` = '4b3be04b-b21b-4c68-bf88-42bb8d0ced3e', `codigo` = '840' WHERE `id` = '22000008-0000-0000-0000-000000000008'; -- Marketing
UPDATE `setores` SET `departamento_id` = '4b3be04b-b21b-4c68-bf88-42bb8d0ced3e', `codigo` = '870' WHERE `id` = '22000009-0000-0000-0000-000000000009'; -- TI
UPDATE `setores` SET `departamento_id` = '4b3be04b-b21b-4c68-bf88-42bb8d0ced3e', `codigo` = '860' WHERE `id` = '22000010-0000-0000-0000-000000000010'; -- RH
UPDATE `setores` SET `departamento_id` = '4b3be04b-b21b-4c68-bf88-42bb8d0ced3e', `codigo` = '830' WHERE `id` = '22000013-0000-0000-0000-000000000013'; -- Financeiro
UPDATE `setores` SET `departamento_id` = '4b3be04b-b21b-4c68-bf88-42bb8d0ced3e', `codigo` = '850' WHERE `id` = '22000014-0000-0000-0000-000000000014'; -- Documental
UPDATE `setores` SET `departamento_id` = '4b3be04b-b21b-4c68-bf88-42bb8d0ced3e', `codigo` = NULL  WHERE `id` = '22000015-0000-0000-0000-000000000015'; -- Compras

-- ── 3. Lojas (filiais) — sincronizar nomes e status com o backup ───────────
UPDATE `filiais` SET `nome` = '1.1 - Fiat Cidade A'       WHERE `id` = 'f33dcedb-5548-11f1-a517-b4450690442b';
UPDATE `filiais` SET `nome` = '1.2 - Seminovos Cidade A', `ativo` = 0 WHERE `id` = 'f33e4e49-5548-11f1-a517-b4450690442b';
UPDATE `filiais` SET `nome` = '1.3 - Seminovos Canoas'   WHERE `id` = 'f33f248a-5548-11f1-a517-b4450690442b';
UPDATE `filiais` SET `nome` = '1.4 - Fiat Cidade C'    WHERE `id` = 'f33f2c60-5548-11f1-a517-b4450690442b';
UPDATE `filiais` SET `nome` = '1.5 - Jeep Cidade A'       WHERE `id` = 'f33f31ad-5548-11f1-a517-b4450690442b';
UPDATE `filiais` SET `nome` = '1.6 - Seminovos Caxias'   WHERE `id` = 'f33f33c1-5548-11f1-a517-b4450690442b';
UPDATE `filiais` SET `nome` = '1.7 - Seminovos Cidade B'  WHERE `id` = 'f33f3524-5548-11f1-a517-b4450690442b';
UPDATE `filiais` SET `nome` = '1.8 - Jeep Santa Cruz'    WHERE `id` = 'f33f381f-5548-11f1-a517-b4450690442b';
