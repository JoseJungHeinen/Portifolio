-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 054 — Janela de busca por filial (substitui horário único)
--
-- 16/09/2026: horário fixo (dist_horario_busca, migration 053) virou janela
-- (início/fim) — a filial só é processada pelo poller direto (ADN/SEFAZ) se o
-- horário atual do servidor estiver dentro do intervalo. Padrão 00:00–06:00
-- (madrugada, fora do expediente).
--
-- Banco: compras
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/054_dist_janela_busca.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

ALTER TABLE `filiais`
  ADD COLUMN `dist_horario_inicio` time NOT NULL DEFAULT '00:00:00'
      COMMENT 'Início da janela em que o poller direto ADN/SEFAZ pode rodar para esta filial',
  ADD COLUMN `dist_horario_fim` time NOT NULL DEFAULT '06:00:00'
      COMMENT 'Fim da janela — se fim < início, a janela cruza a meia-noite';

-- Migra quem já tinha um horário único: vira o INÍCIO de uma janela de 1h.
UPDATE `filiais`
   SET `dist_horario_inicio` = `dist_horario_busca`,
       `dist_horario_fim`    = ADDTIME(`dist_horario_busca`, '01:00:00')
 WHERE `dist_horario_busca` <> '02:00:00';

ALTER TABLE `filiais` DROP COLUMN `dist_horario_busca`;
