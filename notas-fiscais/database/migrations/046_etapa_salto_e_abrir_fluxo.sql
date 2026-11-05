-- ════════════════════════════════════════════════════════════════════
-- 046 — Duas etapas novas de catálogo: "Ir para" e "Abrir Fluxo"
--
-- A etapa de DECISÃO (041/045) salta conforme o índice de um campo do
-- formulário. Faltavam dois casos:
--
--   "Ir para"     — o ramo não tem nada a decidir, só precisa reencontrar o
--                   tronco (dois ramos que se dividem e voltam a se juntar).
--                   Sem isto a saída seria uma decisão com TODOS os índices no
--                   mesmo destino: funciona, mas mente sobre o que o fluxo faz.
--
--   "Abrir Fluxo" — ao ser atingida, abre um processo NOVO a partir de outro
--                   template e encerra o processo atual. O filho segue sozinho.
--
-- Em ambas, quem carrega o destino é o TEMPLATE, não o catálogo — do mesmo
-- jeito que a decisão: o catálogo diz "esta etapa salta"/"esta etapa abre
-- fluxo", e cada template diz para onde. Assim uma única etapa de catálogo
-- serve a todos os fluxos.
--
-- O destino do "Ir para" reaproveita `nf_processo_etapas.decisao_saltos` (045)
-- sob o índice 0 — o índice que nenhuma lista usa, porque o formulário só
-- aceita índice a partir de 1.
--
-- Idempotente: rodar duas vezes não quebra.
-- ════════════════════════════════════════════════════════════════════

SET @db := DATABASE();

-- ── nf_etapas.salto_incondicional ────────────────────────────────────
SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'nf_etapas'
     AND COLUMN_NAME = 'salto_incondicional'
);
SET @sql := IF(@existe = 0,
  "ALTER TABLE nf_etapas
     ADD COLUMN salto_incondicional TINYINT(1) NOT NULL DEFAULT 0
     COMMENT 'Etapa Ir para: manda o processo para a etapa definida no template. Sem aprovador e sem lista.'
     AFTER decisao_lista_id",
  'SELECT ''046: salto_incondicional ja existe'''
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── nf_etapas.abre_fluxo ─────────────────────────────────────────────
SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'nf_etapas'
     AND COLUMN_NAME = 'abre_fluxo'
);
SET @sql := IF(@existe = 0,
  "ALTER TABLE nf_etapas
     ADD COLUMN abre_fluxo TINYINT(1) NOT NULL DEFAULT 0
     COMMENT 'Etapa Abrir Fluxo: abre um processo de outro template e encerra o atual. O template diz qual.'
     AFTER salto_incondicional",
  'SELECT ''046: abre_fluxo ja existe'''
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── nf_processo_etapas.abre_template_id ──────────────────────────────
-- Denormalizado do template na criação, como nome_etapa e decisao_saltos: um
-- processo em andamento não muda de destino porque alguém editou o template.
SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'nf_processo_etapas'
     AND COLUMN_NAME = 'abre_template_id'
);
SET @sql := IF(@existe = 0,
  "ALTER TABLE nf_processo_etapas
     ADD COLUMN abre_template_id CHAR(36) NULL DEFAULT NULL
     COMMENT 'Template do processo a ser aberto por esta etapa. NULL = etapa comum.'
     AFTER decisao_saltos",
  'SELECT ''046: abre_template_id ja existe'''
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── nf_processos.processo_pai_id ─────────────────────────────────────
-- Liga o filho ao processo que o abriu. Sem FK: o pai pode ser apagado
-- logicamente (deletado_em) e o filho continua válido por conta própria.
SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'nf_processos'
     AND COLUMN_NAME = 'processo_pai_id'
);
SET @sql := IF(@existe = 0,
  "ALTER TABLE nf_processos
     ADD COLUMN processo_pai_id CHAR(36) NULL DEFAULT NULL
     COMMENT 'Processo que abriu este, via etapa Abrir Fluxo. NULL = processo aberto normalmente.',
     ADD KEY idx_nfp_processo_pai (processo_pai_id)",
  'SELECT ''046: processo_pai_id ja existe'''
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
