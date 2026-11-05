-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 053 — Horário de busca por filial (captura direta NF-e/NFS-e)
--
-- Um horário por filial evita que todas as lojas rodem no mesmo minuto contra
-- o Portal Nacional/ADN — importante depois do bloqueio de 1h (cStat 656)
-- observado em produção: espalhar o horário reduz o custo de qualquer engano
-- futuro de cursor a uma única filial, não a todas de uma vez.
--
-- Um único horário serve tanto o poller de NFe (nfe_dist_sync.php) quanto o
-- de NFSe (adn_sync.php) — é "quando esta loja busca", não por tipo de
-- documento (os tipos já são ligados/desligados via nfe_dist_ativo/adn_nfse_ativo).
--
-- Banco: compras
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/053_dist_horario_filial.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

ALTER TABLE `filiais`
  ADD COLUMN `dist_horario_busca` time NOT NULL DEFAULT '02:00:00'
      COMMENT 'Horário diário do poller direto ADN/SEFAZ (NFe/NFSe) desta filial';
