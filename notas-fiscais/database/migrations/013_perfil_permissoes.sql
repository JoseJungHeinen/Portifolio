-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 013 — Permissões padrão POR PERFIL administráveis pela tela
--
-- Antes: o padrão de cada perfil vivia fixo em config/permissions.php.
-- Agora: se houver linha aqui, ela substitui o padrão do arquivo para o
-- perfil. Sem linha = continua valendo o arquivo (fallback). O override
-- por usuário (nf_usuario_permissoes) segue tendo precedência sobre ambos.
-- O perfil 'admin' nunca é customizável (acesso total garantido no código).
-- ═══════════════════════════════════════════════════════════════════════════
USE `compras`;

CREATE TABLE IF NOT EXISTS `nf_perfil_permissoes` (
  `perfil`          varchar(20)  NOT NULL COMMENT 'solicitante | almoxarife | comprador | gestor | diretor',
  `permissoes`      longtext     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
                    COMMENT 'JSON: { modulo: { acao: bool } }',
  `atualizado_por`  char(36)     NOT NULL,
  `atualizado_em`   timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`perfil`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Permissões padrão por perfil (editáveis) — fallback: config/permissions.php';
