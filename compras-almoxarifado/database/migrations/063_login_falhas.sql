-- 063_login_falhas.sql
-- Limite de tentativas por IP (auditoria de segurança, A5). O bloqueio por
-- conta (usuarios.tentativas_login) não impede testar uma senha comum em todas
-- as contas; este registro por IP impede.
-- Deploy não envia migrations: rodar à mão no servidor, ANTES do código.

CREATE TABLE IF NOT EXISTS login_falhas (
  id        bigint unsigned NOT NULL AUTO_INCREMENT,
  ip        varchar(45)     NOT NULL,
  login     varchar(150)    NULL,
  tipo      enum('login','reset') NOT NULL,
  criado_em timestamp       NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_lf_ip (ip, tipo, criado_em),
  KEY idx_lf_data (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
