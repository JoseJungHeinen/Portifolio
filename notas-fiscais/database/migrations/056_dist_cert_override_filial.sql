-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 056 — Certificado por filial (override do certificado da matriz)
--
-- 16/09/2026: descoberto que o Empresa Exemplo tem MAIS de uma raiz de CNPJ
-- operando sob o mesmo Notas — Clubmotors (07.961.646) e B7 Participações
-- (12.941.032) são raízes diferentes da matriz Fiat/Jeep (09.327.318). O
-- certificado único (migration 055) só cobre filiais da MESMA raiz do
-- certificado configurado — não serve pra essas.
--
-- Design híbrido: `nf_configuracoes` (grupo 'dist') continua com o
-- certificado PADRÃO (matriz) — cobre a maioria das filiais sem precisar
-- configurar nada. `filiais.dist_cert_path/senha`, quando preenchidos,
-- SOBRESCREVEM o padrão só pra aquela filial — é o que uma filial de raiz
-- diferente (Clubmotors, B7) precisa.
--
-- Banco: compras
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/056_dist_cert_override_filial.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

ALTER TABLE `filiais`
  ADD COLUMN `dist_cert_path`  varchar(255) DEFAULT NULL
      COMMENT 'Certificado .pfx PRÓPRIO desta filial — sobrescreve o padrão da matriz (dist.cert_path). NULL = usa o padrão.',
  ADD COLUMN `dist_cert_senha` varchar(255) DEFAULT NULL
      COMMENT 'Senha do certificado próprio — só usada quando dist_cert_path está preenchido.';
