-- 067_usuario_todas_filiais.sql
-- Opção no cadastro do usuário: "Acesso a todas as filiais". Com ela ligada o
-- usuário VÊ os dados de todas as filiais (listas, filtros, detalhes, relatórios,
-- dashboard) como diretor/admin, sem ganhar os demais poderes do cargo.
-- Helper: ve_todas_filiais() em src/helpers.php (sem a coluna ele devolve false).
-- Deploy não envia migrations: rodar à mão no servidor,
-- ANTES do código: a tela de usuário grava a coluna e falharia sem ela.

ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS todas_filiais tinyint(1) NOT NULL DEFAULT 0 AFTER setor_id;
