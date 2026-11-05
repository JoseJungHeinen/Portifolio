/* Conferentes cadastrados pelo admin no dashboard. O login da Conferencia
   so aceita quem esta aqui e ativo, provando quem e com um PIN de 4 numeros.
   O PIN fica so como password_hash. tentativas_erradas e bloqueado_ate
   ficam no banco para recarregar a pagina nao zerar o bloqueio.
   Comentarios so neste formato: o cliente SQL de producao junta tudo numa linha. */
CREATE TABLE inv_conferentes (
  id                 INT(11)      NOT NULL AUTO_INCREMENT,
  nome               VARCHAR(120) NOT NULL,
  pin_hash           VARCHAR(255) NOT NULL,
  ativo              TINYINT(1)   NOT NULL DEFAULT 1,
  tentativas_erradas TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_ate      DATETIME     NULL,
  criado_em          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inv_conferentes_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
