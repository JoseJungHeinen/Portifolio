CREATE TABLE inv_conferencias (
  id               INT(11)      NOT NULL AUTO_INCREMENT,
  id_inventario    INT(11)      NOT NULL,
  empresa          INT(11)      NOT NULL,
  revenda          INT(11)      NOT NULL,
  coletor          INT(11)      NOT NULL DEFAULT 0,
  dupla_label      VARCHAR(120) NULL,
  item_estoque_pub VARCHAR(50)  NOT NULL,
  cod_item_estoque VARCHAR(50)  NULL,
  descricao        VARCHAR(255) NULL,
  locacao_geral    VARCHAR(30)  NULL,
  saldo_oracle     INT(11)      NULL,
  origem           VARCHAR(10)  NOT NULL DEFAULT 'oracle',
  conferido_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inv_item (id_inventario, empresa, revenda, item_estoque_pub),
  KEY idx_inv_data (id_inventario, conferido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
