-- Onda 1. Rodar UMA vez por banco (ALTER TABLE não é reexecutável no MySQL 8).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS motivos (
    id               CHAR(36)     NOT NULL PRIMARY KEY,
    codigo           VARCHAR(30)  NOT NULL,
    descricao        VARCHAR(150) NOT NULL,
    aplica_a         VARCHAR(255) NOT NULL COMMENT 'status de destino separados por vírgula',
    exige_observacao TINYINT(1)   NOT NULL DEFAULT 0,
    ordem            INT          NOT NULL DEFAULT 0,
    ativo            TINYINT(1)   NOT NULL DEFAULT 1,
    UNIQUE KEY uk_motivos_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ofps
    ADD COLUMN data_rejeicao    DATE        NULL AFTER data_decisao,
    ADD COLUMN data_aprovacao   DATE        NULL AFTER data_rejeicao,
    ADD COLUMN data_reanalise   DATE        NULL AFTER protocolo_grow,
    ADD COLUMN motivo_id        CHAR(36)    NULL AFTER observacao,
    ADD COLUMN nf_compartilhada TINYINT(1)  NOT NULL DEFAULT 0 AFTER valor_nf_devolucao,
    ADD COLUMN perda_sugerida   VARCHAR(20) NULL AFTER prazo_data,
    ADD KEY ix_ofps_nf_unidade (unidade_id, nf_devolucao),
    ADD KEY ix_ofps_perda (perda_sugerida),
    ADD CONSTRAINT fk_ofps_motivo FOREIGN KEY (motivo_id) REFERENCES motivos(id);

ALTER TABLE ofp_eventos
    ADD COLUMN motivo_id  CHAR(36) NULL AFTER status_para,
    ADD COLUMN alteracoes TEXT     NULL AFTER observacao,
    ADD CONSTRAINT fk_eventos_motivo FOREIGN KEY (motivo_id) REFERENCES motivos(id);

-- Datas de decisão a partir do que já existe.
UPDATE ofps SET data_aprovacao = data_decisao
 WHERE data_decisao IS NOT NULL
   AND status IN ('aprovada','aguardando_tp41','nf_emitida','aguardando_coleta','coletada','aguardando_credito','creditada','estornada','encerrada','peca_vendida');
UPDATE ofps SET data_rejeicao = data_decisao
 WHERE data_decisao IS NOT NULL AND status IN ('rejeitada','em_reanalise','rejeitada_final');
