-- 033_chave_nfse_50_digitos.sql
--
-- Amplia chave_nf de 44 para 50 caracteres.
--
-- Motivo: a chave de acesso da NFS-e padrao nacional (DANFSe) tem 50 digitos,
-- nao 44 como NF-e e CT-e. Com char(44) o MySQL em modo estrito recusa o INSERT
-- ("1406 Data too long for column 'chave_nf'") e a importacao de PDF de NFS-e
-- morre na criacao do processo.
--
-- char -> varchar porque a coluna passa a guardar dois tamanhos diferentes
-- (44 e 50); varchar evita o padding fixo em toda linha ja existente.
--
-- nf_processos.chave_nf_ativa entra junto por obrigacao: os triggers
-- trg_nfp_chave_ativa_bi/bu copiam chave_nf para ela, entao se so uma das duas
-- crescer o trigger passa a estourar no INSERT.
--
-- Nao destrutiva: apenas alarga. Nenhum dado existente e alterado (a maior
-- chave gravada hoje tem 44 caracteres, em ambas as tabelas).
--
-- nf_apollo_consulta_itens.chave_nf fica em char(44) de proposito: o Apollo
-- consulta CT-e, que nao tem chave de 50.

ALTER TABLE nf_processos
  MODIFY COLUMN chave_nf VARCHAR(50) DEFAULT NULL
    COMMENT 'Chave de acesso do documento fiscal: 44 digitos (NF-e/CT-e) ou 50 (NFS-e padrao nacional)',
  MODIFY COLUMN chave_nf_ativa VARCHAR(50) DEFAULT NULL
    COMMENT 'Espelho de chave_nf quando o processo e ativo/real - trava UNIQUE antiduplicidade (mantida por trigger)';

ALTER TABLE nf_notas_capturadas
  MODIFY COLUMN chave_nf VARCHAR(50) NOT NULL
    COMMENT 'Chave de acesso do documento fiscal (44 ou 50 digitos) - dedup';
