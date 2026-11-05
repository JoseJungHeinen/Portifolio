-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 055 — Certificado único (matriz) para a captura direta NF-e/NFS-e
--
-- 16/09/2026: confirmado que um certificado emitido para a matriz cobre
-- qualquer filial da MESMA raiz de CNPJ (8 primeiros dígitos) — tanto na
-- distribuição de NF-e (SOAP, tag <CNPJ> separada do certificado) quanto na
-- do ADN/NFSe Nacional (REST, parâmetro `cnpjConsulta` separado do mTLS).
-- Não precisa mais de um certificado por filial.
--
-- Sai: filiais.adn_cert_path / adn_cert_senha (um por filial).
-- Entra: nf_configuracoes grupo 'dist' — um certificado só, reaproveitado
-- para todas as filiais ativas (cada chamada informa o CNPJ da filial via
-- cnpjConsulta/tag CNPJ, o certificado da matriz autentica a conexão).
--
-- Preserva o certificado já configurado na Fiat Cidade A (matriz) antes de
-- apagar as colunas antigas.
--
-- Banco: compras
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/055_dist_certificado_unico.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

INSERT INTO `nf_configuracoes` (`chave`, `valor`, `grupo`)
SELECT 'dist.cert_path', `adn_cert_path`, 'dist' FROM `filiais`
 WHERE `adn_cert_path` IS NOT NULL AND `adn_cert_path` <> '' LIMIT 1;

INSERT INTO `nf_configuracoes` (`chave`, `valor`, `grupo`)
SELECT 'dist.cert_senha', `adn_cert_senha`, 'dist' FROM `filiais`
 WHERE `adn_cert_senha` IS NOT NULL AND `adn_cert_senha` <> '' LIMIT 1;

INSERT IGNORE INTO `nf_configuracoes` (`chave`, `valor`, `grupo`) VALUES
  ('dist.cert_path',  '', 'dist'),
  ('dist.cert_senha', '', 'dist');

ALTER TABLE `filiais`
  DROP COLUMN `adn_cert_path`,
  DROP COLUMN `adn_cert_senha`;
