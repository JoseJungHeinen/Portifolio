-- 035_numero_transferencia.sql
-- Adiciona numero sequencial em transferencias, no mesmo padrão de
-- requisicoes.numero (migration 020) — usado para exibir "TRAN-AAAA-NNNNN".

ALTER TABLE transferencias
  ADD COLUMN numero INT UNSIGNED NULL
    COMMENT 'Numero sequencial da transferencia'
    AFTER id;

-- Backfill: numerar registros existentes na ordem de criacao
UPDATE transferencias t
JOIN (
    SELECT id, ROW_NUMBER() OVER (ORDER BY criado_em ASC) AS rn
    FROM transferencias
) ranked ON ranked.id = t.id
SET t.numero = ranked.rn;

-- Tornar NOT NULL apos backfill
ALTER TABLE transferencias
  MODIFY COLUMN numero INT UNSIGNED NOT NULL;

-- Indice para performance no MAX()
ALTER TABLE transferencias
  ADD KEY idx_transferencias_numero (numero);
