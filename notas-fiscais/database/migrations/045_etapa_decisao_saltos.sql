-- ════════════════════════════════════════════════════════════════════
-- 045 — Etapa de decisão: mapa de saltos no processo
--
-- A etapa de decisão não tem aprovador: quem a resolve é o sistema, lendo um
-- campo do formulário do processo. O valor do campo é um item de lista; o
-- índice desse item diz para QUAL etapa o fluxo salta.
--
-- Tudo que fica entre a etapa de decisão e o destino é marcado 'pulado' — o
-- status que o restante do sistema já ignora (devolução, Minhas Tarefas,
-- dashboards e relatórios). Do destino em diante o fluxo volta a ser
-- sequencial.
--
-- As duas colunas são denormalizadas do template no mesmo espírito de
-- nome_etapa e tipo_aprovador: um processo em andamento não pode mudar de
-- forma porque alguém editou o template depois que ele foi aberto.
--
-- A migration 041 já entregou a outra metade: nf_etapas.decisao_lista_id
-- (qual lista governa a etapa) e nf_lista_itens.indice.
--
-- Idempotente: rodar duas vezes não quebra.
-- ════════════════════════════════════════════════════════════════════

SET @db := DATABASE();

-- ── decisao_campo ────────────────────────────────────────────────────
SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db
     AND TABLE_NAME   = 'nf_processo_etapas'
     AND COLUMN_NAME  = 'decisao_campo'
);
SET @sql := IF(@existe = 0,
  "ALTER TABLE nf_processo_etapas
     ADD COLUMN decisao_campo VARCHAR(100) NULL DEFAULT NULL
     COMMENT 'Campo de nf_processos.dados_formulario que esta etapa de decisao le. NULL = etapa comum.'
     AFTER etapa_catalogo_id",
  'SELECT ''045: decisao_campo ja existe'''
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── decisao_saltos ───────────────────────────────────────────────────
-- JSON { "1": 3, "2": 6 } — indice da lista => numero_etapa de destino.
-- Já resolvido para número na criação do processo: o template guarda o uid da
-- linha (estável sob drag-and-drop), o processo guarda a posição final.
SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db
     AND TABLE_NAME   = 'nf_processo_etapas'
     AND COLUMN_NAME  = 'decisao_saltos'
);
SET @sql := IF(@existe = 0,
  "ALTER TABLE nf_processo_etapas
     ADD COLUMN decisao_saltos TEXT NULL DEFAULT NULL
     COMMENT 'JSON indice=>numero_etapa de destino do salto. NULL = etapa comum.'
     AFTER decisao_campo",
  'SELECT ''045: decisao_saltos ja existe'''
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
