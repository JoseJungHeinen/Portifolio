-- 020_numero_requisicao.sql
-- Adiciona numero sequencial em requisicoes e numera registros existentes

ALTER TABLE requisicoes
  ADD COLUMN numero INT UNSIGNED NULL
    COMMENT 'Numero sequencial da requisicao'
    AFTER id;

-- Backfill: numerar registros existentes na ordem de criacao
UPDATE requisicoes r
JOIN (
    SELECT id, ROW_NUMBER() OVER (ORDER BY criado_em ASC) AS rn
    FROM requisicoes
) ranked ON ranked.id = r.id
SET r.numero = ranked.rn;

-- Tornar NOT NULL apos backfill
ALTER TABLE requisicoes
  MODIFY COLUMN numero INT UNSIGNED NOT NULL;

-- Indice para performance no MAX()
ALTER TABLE requisicoes
  ADD KEY idx_req_numero (numero);
