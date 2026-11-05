-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 031 — PIS/NIT do fornecedor
--
-- Obrigação legal: em nota de SERVIÇO prestada por MEI ou pessoa física, a
-- empresa precisa do PIS/PASEP/NIT do prestador para a GPS/DCTFWeb (retenção
-- de INSS). O número é do prestador, então mora no cadastro do fornecedor —
-- mas a exigência é verificada na abertura do processo (ver src/Helpers/PisNit.php).
--
-- Coluna nullable e sem UNIQUE: `fornecedores` é compartilhada com o sistema
-- de compras — a unicidade é validada na aplicação para não quebrar inserts
-- que não conhecem esta regra.
--
-- Executar: mysql -u notas_app -p Notas < database/migrations/031_fornecedor_pis_nit.sql
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE `fornecedores`
  ADD COLUMN IF NOT EXISTS `pis_nit` varchar(11) DEFAULT NULL
      COMMENT 'PIS/PASEP/NIT do prestador (11 digitos, sem mascara) — exigido de MEI/pessoa fisica em nota de servico'
      AFTER `optante_simples`;

ALTER TABLE `fornecedores`
  ADD INDEX IF NOT EXISTS `idx_fornecedores_pis` (`pis_nit`);
