-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 057 — Intervalo mínimo entre execuções, por filial
--
-- 17/09/2026: a janela de busca (dist_horario_inicio/fim) só cobre "roda uma
-- vez por dia, num horário fixo" — não dá pra pedir "de hora em hora" com
-- ela sozinha (janela é um intervalo contínuo por dia, não um padrão que se
-- repete). Pra isso, `dist_intervalo_min` impõe um espaçamento mínimo entre
-- runs da mesma filial, usando o horário do último log (nf_adn_log/
-- nf_dist_log) — dentro da janela, mas só de X em X minutos.
--
-- NULL (padrão) = comportamento de antes, sem intervalo mínimo — só a janela
-- decide.
--
-- Banco: compras
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/057_dist_intervalo_minimo.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

ALTER TABLE `filiais`
  ADD COLUMN `dist_intervalo_min` int unsigned DEFAULT NULL
      COMMENT 'Minutos mínimos entre duas execuções desta filial (NULL = só a janela decide, roda a cada tick do cron)';
