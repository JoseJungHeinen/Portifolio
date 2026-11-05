-- 046_comunicados_pendencia.sql
-- Registro de comunicados de pendência já enviados.
-- Serve para idempotência: o script consulta antes de enviar, então cron mal
-- configurado ou reexecução manual no mesmo dia não duplicam o e-mail.
--
-- COLLATE explícito é obrigatório: sem ele a tabela herda utf8mb4_general_ci e a
-- FK para usuarios.id (utf8mb4_unicode_ci) falha com errno 150 — foi o que
-- quebrou a migration 017.

CREATE TABLE IF NOT EXISTS comunicados_pendencia (
  id              char(36)    NOT NULL,
  usuario_id      char(36)    NOT NULL,
  data_referencia date        NOT NULL,
  tipo            enum('individual','consolidado') NOT NULL DEFAULT 'individual',
  total_itens     smallint(5) unsigned NOT NULL DEFAULT 0,
  enviado_em      timestamp   NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uk_comunicado_dia (usuario_id, data_referencia, tipo),
  KEY idx_cp_data (data_referencia),
  CONSTRAINT fk_cp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
