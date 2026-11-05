-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 015 — nome_armazenado comporta UUID + extensão
--
-- Upload::salvar gera "uuid.ext" (40-41 chars), mas a coluna era char(36).
-- Local (MariaDB não-estrito) truncava em silêncio; produção (modo estrito)
-- rejeita com erro 1406 "Data too long". Corrige para varchar(100).
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE `nf_processo_anexos`
  MODIFY `nome_armazenado` varchar(100) NOT NULL;
