-- ════════════════════════════════════════════════════════════════════
-- 047 — ENUM de nf_processo_etapas.tipo_aprovador aceita 'decisao' e
--       'abre_fluxo'
--
-- O BUG
--   A migration 022 fixou a coluna em ENUM('grupo','usuario','arquivo'). As
--   etapas de Decisão / "Ir para" (045, 046) gravam 'decisao', e a etapa
--   "Abrir Fluxo" (046) grava 'abre_fluxo' — dois valores que o ENUM nunca
--   passou a aceitar. Nenhuma das duas migrations mexeu na coluna.
--
--   O efeito depende do sql_mode, e é por isso que passou batido:
--     - local (XAMPP, SEM strict): MySQL trunca em silêncio e grava ''.
--       O fluxo continua funcionando porque `etapaEhDecisao()` olha primeiro
--       `decisao_saltos`, não o tipo — a corrupção fica invisível.
--     - Server-APP (STRICT_TRANS_TABLES): o INSERT falha com
--       "SQLSTATE[01000] 1265 Data truncated for column 'tipo_aprovador'",
--       a transação inteira volta atrás e o processo não é criado.
--
--   Sintoma relatado em 20/08/2026: abrir processo pelo template "Cadastro de
--   Cliente" (que tem duas etapas de Decisão) morria no cadastro, no
--   Server-APP. Confirmado: `SELECT tipo_aprovador, COUNT(*)` de lá não tinha
--   uma única linha 'decisao' — a Decisão nunca chegou a funcionar naquele
--   servidor.
--
-- O CONSERTO
--   1. Estende o ENUM com os dois valores que o código já escreve.
--   2. Repara as linhas que o modo não-strict truncou para '', deduzindo o
--      tipo do dado que sobreviveu: `decisao_saltos` preenchido = decisão,
--      `abre_template_id` preenchido = abre fluxo. Onde não houver ENUM
--      antigo com '' (caso do Server-APP), os dois UPDATEs são no-op.
--
--   'solicitante' NÃO entra no ENUM de propósito: ele existe só dentro de
--   nf_template_versoes.etapas e é resolvido para 'usuario' + criado_por na
--   criação do processo (Process::resolverAprovadorTemplate).
--
-- Idempotente: rodar duas vezes não quebra.
-- ════════════════════════════════════════════════════════════════════

SET @db := DATABASE();

-- ── Estende o ENUM ───────────────────────────────────────────────────
SET @tipo := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'nf_processo_etapas'
     AND COLUMN_NAME = 'tipo_aprovador'
);

SET @sql := IF(@tipo LIKE '%decisao%' AND @tipo LIKE '%abre_fluxo%',
  'SELECT ''047: ENUM ja aceita decisao e abre_fluxo''',
  "ALTER TABLE nf_processo_etapas
     MODIFY tipo_aprovador
       ENUM('grupo','usuario','arquivo','decisao','abre_fluxo') NOT NULL
       COMMENT 'Quem resolve a etapa. grupo/usuario = pessoa; arquivo, decisao e abre_fluxo = o proprio sistema.'"
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── Repara o que o modo não-strict truncou para '' ───────────────────
-- Só toca em linha com tipo vazio: nenhuma linha válida é reescrita.
UPDATE nf_processo_etapas
   SET tipo_aprovador = 'decisao'
 WHERE tipo_aprovador = ''
   AND decisao_saltos IS NOT NULL
   AND decisao_saltos <> '';

UPDATE nf_processo_etapas
   SET tipo_aprovador = 'abre_fluxo'
 WHERE tipo_aprovador = ''
   AND abre_template_id IS NOT NULL;

-- ── Conferência ──────────────────────────────────────────────────────
SELECT COLUMN_TYPE AS enum_final
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nf_processo_etapas'
   AND COLUMN_NAME = 'tipo_aprovador';

SELECT tipo_aprovador, COUNT(*) AS qtd
  FROM nf_processo_etapas
 GROUP BY tipo_aprovador
 ORDER BY qtd DESC;
