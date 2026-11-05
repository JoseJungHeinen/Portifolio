-- 023_unique_chave_processos.sql
-- Trava de banco contra nota fiscal duplicada em processos.
--
-- A validação de duplicidade era só "consultar e inserir" (Process::notaDuplicada)
-- — dois envios simultâneos podiam passar juntos. Esta migração cria uma trava
-- real no banco: a chave de acesso é ÚNICA entre processos ATIVOS e reais
-- (cancelados, excluídos e testes ficam fora — cancelar LIBERA a chave para
-- relançamento, comportamento já esperado por notaDuplicada).
--
-- Implementação: coluna espelho `chave_nf_ativa` + UNIQUE, mantida por
-- triggers BEFORE INSERT/UPDATE. (Coluna gerada STORED não pôde ser usada:
-- a MariaDB 10.11 recusa ALTER ... GENERATED em tabela com coluna LONGTEXT.)

SET NAMES utf8mb4;

-- Normaliza as chaves existentes: remove espaços/pontos; vazio vira NULL
UPDATE nf_processos
SET chave_nf = NULLIF(REPLACE(REPLACE(TRIM(chave_nf), ' ', ''), '.', ''), '');

ALTER TABLE nf_processos
  ADD COLUMN chave_nf_ativa CHAR(44) NULL
    COMMENT 'Espelho de chave_nf quando o processo é ativo/real — trava UNIQUE antiduplicidade (mantida por trigger)',
  ADD UNIQUE KEY uk_nfp_chave_ativa (chave_nf_ativa);

-- Preenche o espelho para os processos existentes
UPDATE nf_processos
SET chave_nf_ativa = CASE
    WHEN status <> 'cancelado' AND deletado_em IS NULL AND is_teste = 0
    THEN NULLIF(chave_nf, '') ELSE NULL END;

-- Triggers mantêm o espelho em toda escrita (INSERT e UPDATE)
DROP TRIGGER IF EXISTS trg_nfp_chave_ativa_bi;
DROP TRIGGER IF EXISTS trg_nfp_chave_ativa_bu;

CREATE TRIGGER trg_nfp_chave_ativa_bi BEFORE INSERT ON nf_processos
FOR EACH ROW SET NEW.chave_nf_ativa = CASE
    WHEN NEW.status <> 'cancelado' AND NEW.deletado_em IS NULL AND NEW.is_teste = 0
    THEN NULLIF(NEW.chave_nf, '') ELSE NULL END;

CREATE TRIGGER trg_nfp_chave_ativa_bu BEFORE UPDATE ON nf_processos
FOR EACH ROW SET NEW.chave_nf_ativa = CASE
    WHEN NEW.status <> 'cancelado' AND NEW.deletado_em IS NULL AND NEW.is_teste = 0
    THEN NULLIF(NEW.chave_nf, '') ELSE NULL END;
