-- 045_gestores_setor.sql
-- Vínculo de gestor responsável por unidade + setor.
-- N gestores por combinação: qualquer um deles decide.
--
-- COLLATE explícito é obrigatório: sem ele a tabela herda utf8mb4_general_ci
-- e a FK para usuarios.id (utf8mb4_unicode_ci) falha com errno 150 — foi o que
-- quebrou a migration 017.

CREATE TABLE IF NOT EXISTS gestores_setor (
  id         char(36)   NOT NULL,
  filial_id  char(36)   NOT NULL,
  setor_id   char(36)   NOT NULL,
  usuario_id char(36)   NOT NULL,
  ativo      tinyint(1) NOT NULL DEFAULT 1,
  criado_por char(36)   NULL,
  criado_em  timestamp  NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uk_gestor_setor (filial_id, setor_id, usuario_id),
  KEY idx_gs_usuario (usuario_id),
  KEY idx_gs_par (filial_id, setor_id),
  CONSTRAINT fk_gs_filial  FOREIGN KEY (filial_id)  REFERENCES filiais(id),
  CONSTRAINT fk_gs_setor   FOREIGN KEY (setor_id)   REFERENCES setores(id),
  CONSTRAINT fk_gs_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: cada gestor ativo passa a responder pela própria lotação, para que
-- ninguém perca acesso no dia do deploy. INSERT IGNORE torna re-execução segura.
INSERT IGNORE INTO gestores_setor (id, filial_id, setor_id, usuario_id, ativo, criado_por)
SELECT UUID(), u.filial_id, u.setor_id, u.id, 1, NULL
FROM usuarios u
WHERE u.ativo = 1
  AND u.perfil = 'gestor'
  AND u.filial_id IS NOT NULL
  AND u.setor_id  IS NOT NULL;
