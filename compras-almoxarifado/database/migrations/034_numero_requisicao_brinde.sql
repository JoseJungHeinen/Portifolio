-- 034_numero_requisicao_brinde.sql
-- Adiciona numero sequencial em requisicoes_brinde, no mesmo padrão de
-- requisicoes.numero (migration 020) — usado para exibir "BRN-AAAA-NNNNN".

ALTER TABLE requisicoes_brinde
  ADD COLUMN numero INT UNSIGNED NULL
    COMMENT 'Numero sequencial da requisicao de brinde'
    AFTER id;

-- Backfill: numerar registros existentes na ordem de criacao
UPDATE requisicoes_brinde rb
JOIN (
    SELECT id, ROW_NUMBER() OVER (ORDER BY criado_em ASC) AS rn
    FROM requisicoes_brinde
) ranked ON ranked.id = rb.id
SET rb.numero = ranked.rn;

-- Tornar NOT NULL apos backfill
ALTER TABLE requisicoes_brinde
  MODIFY COLUMN numero INT UNSIGNED NOT NULL;

-- Indice para performance no MAX()
ALTER TABLE requisicoes_brinde
  ADD KEY idx_reqbrinde_numero (numero);
