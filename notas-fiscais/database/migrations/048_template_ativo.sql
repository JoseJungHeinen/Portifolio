-- ════════════════════════════════════════════════════════════════════
-- 048 — Desativar fluxo: nf_templates ganha `ativo`
--
-- POR QUE
--   Um fluxo que saiu de uso não pode simplesmente ser apagado: os processos
--   já abertos por ele precisam continuar existindo, com histórico e etapas
--   intactos, e a exclusão é bloqueada justamente por isso. Faltava o meio
--   termo — deixar o fluxo de pé, mas fechado para NOVAS aberturas.
--
-- O QUE MUDA
--   `ativo = 0` significa uma coisa só: **não se abre processo novo neste
--   fluxo**. O que já está rodando segue rodando até o fim, normalmente.
--
--   O corte acontece em Process::criar(), por onde passam TODOS os caminhos
--   de abertura — a tela, a captura/RPA, a importação e a etapa "Abrir
--   Fluxo". Os seletores de template também escondem o inativo, para ninguém
--   escolher o que vai ser recusado depois.
--
--   `desativado_em` / `desativado_por` respondem "quem fechou e quando", que
--   é a primeira pergunta quando alguém estranha o fluxo sumido da abertura.
--   Ficam NULL enquanto o fluxo estiver ativo.
--
-- QUEM PODE
--   Só `super_admin` (Permissions::isSuperAdmin) — o mesmo nível que já
--   governa perfil de usuário e sigilo de template. O `admin` de cadastros
--   monta e publica fluxo, mas não tira um do ar.
--
-- Idempotente: rodar duas vezes não quebra.
-- ════════════════════════════════════════════════════════════════════

SET @db := DATABASE();

-- ── nf_templates.ativo ───────────────────────────────────────────────
SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'nf_templates'
     AND COLUMN_NAME = 'ativo'
);
SET @sql := IF(@existe = 0,
  "ALTER TABLE nf_templates
     ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1
     COMMENT '0 = fluxo desativado: nao aceita processo NOVO. Os ja abertos seguem normalmente.'
     AFTER confidencial,
     ADD COLUMN desativado_em DATETIME NULL DEFAULT NULL
     COMMENT 'Quando foi desativado. NULL enquanto ativo.'
     AFTER ativo,
     ADD COLUMN desativado_por CHAR(36) NULL DEFAULT NULL
     COMMENT 'FK suave -> usuarios.id: quem desativou. NULL enquanto ativo.'
     AFTER desativado_em,
     ADD KEY idx_nft_ativo (ativo)",
  'SELECT ''048: coluna ativo ja existe'''
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── Conferência ──────────────────────────────────────────────────────
SELECT ativo, COUNT(*) AS templates
  FROM nf_templates
 GROUP BY ativo;
