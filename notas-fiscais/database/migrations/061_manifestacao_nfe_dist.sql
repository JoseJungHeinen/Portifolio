-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 061 — Manifestação do Destinatário (Ciência da Operação) + upgrade
-- de resNFe para procNFe na distribuição direta de NF-e (SEFAZ/AN)
--
-- Problema que isso resolve: quando a SEFAZ ainda não tem manifestação do
-- destinatário, a distribuição entrega um resNFe (resumo achatado, sem
-- número/CFOP/itens) em vez do procNFe completo. Sem manifestar, a nota nunca
-- evolui — e o poller (NfeDistSync::processarDoc) até então descartava a
-- entrega tardia do procNFe como "já existe" (dedup só por chave_nf), quando
-- na verdade deveria SUBSTITUIR o resumo pelos dados completos.
--
-- `xml_completo` é o que diferencia os dois estados de uma nota já capturada:
--   0 = só temos o resNFe (resumo) — ainda aguardando o XML de verdade
--   1 = já temos o procNFe (ou é de outra origem, brobot/manual, que sempre
--       entrega completo — por isso o DEFAULT é 1, não quebra o histórico)
--
-- `manifestacao_status` só é relevante para xml_completo = 0:
--   nao_aplica = não é resNFe (não precisa manifestar)
--   pendente   = resNFe capturado, Ciência da Operação ainda não enviada
--   enviada    = evento aceito pela SEFAZ (cStat 135/136/573) — aguardando o
--                próximo NSU trazer o procNFe
--   erro       = tentativa falhou (rede, SOAP, cStat de rejeição) — reenviada
--                automaticamente no próximo run desta filial (ver
--                NfeDistSync::reenviarManifestacoesPendentes)
--
-- Banco: compras
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/061_manifestacao_nfe_dist.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

ALTER TABLE `nf_notas_capturadas`
  ADD COLUMN `xml_completo` tinyint(1) NOT NULL DEFAULT 1
      COMMENT '0 = só resNFe (resumo, aguardando manifestação); 1 = XML completo (procNFe ou outra origem)'
      AFTER `pdf_disponivel`,
  ADD COLUMN `manifestacao_status` enum('nao_aplica','pendente','enviada','erro') NOT NULL DEFAULT 'nao_aplica'
      COMMENT 'Ciência da Operação (tpEvento 210210) — só se aplica a notas capturadas como resNFe'
      AFTER `xml_completo`,
  ADD COLUMN `manifestacao_detalhe` varchar(255) DEFAULT NULL
      COMMENT 'cStat/xMotivo da SEFAZ ou erro de rede/SOAP na última tentativa'
      AFTER `manifestacao_status`,
  ADD COLUMN `manifestacao_em` datetime DEFAULT NULL
      COMMENT 'Quando foi a última tentativa de manifestação'
      AFTER `manifestacao_detalhe`;

-- Notas já capturadas antes desta migration: por padrão tratadas como
-- completas (brobot e importações manuais sempre trouxeram XML inteiro).
UPDATE `nf_notas_capturadas` SET `xml_completo` = 1;

-- Exceção: notas de `nfe_dist` que já foram capturadas como resNFe (resumo)
-- ANTES de existir essa distinção — reconhecidas pela marca registrada desde
-- sempre pelo parser (resNFe não preenche numero_nf nem cfop). Sem este
-- backfill, ficariam presas como "completas" pra sempre, sem nunca receber a
-- Ciência da Operação nem o upgrade quando o procNFe chegar.
UPDATE `nf_notas_capturadas`
SET `xml_completo` = 0, `manifestacao_status` = 'pendente'
WHERE `origem` = 'nfe_dist'
  AND (`numero_nf` = '' OR `numero_nf` IS NULL)
  AND `cfop` IS NULL;

ALTER TABLE `nf_dist_log`
  ADD COLUMN `docs_atualizadas` int unsigned NOT NULL DEFAULT 0
      COMMENT 'resNFe já capturados que foram completados com o procNFe chegando depois'
      AFTER `docs_importadas`;
