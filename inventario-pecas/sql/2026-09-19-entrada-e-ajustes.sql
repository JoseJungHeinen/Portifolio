-- Como o código chegou à conferência: pelo leitor ('bipado') ou pelo botão de
-- etiqueta ilegível ('digitado'). NULL nas linhas gravadas antes de existir a
-- trava de digitação: marcar essas como 'bipado' afirmaria algo que ninguém sabe.
ALTER TABLE inv_conferencias
  ADD COLUMN entrada VARCHAR(10) NULL AFTER status,
  ADD KEY idx_inv_entrada (id_inventario, entrada);

-- Cada ajuste de locação feito pelo coletor. Antes disto, o ajuste gravava no
-- Apollo e não deixava rastro nenhum no inventário. É também onde aparece quem
-- usa o botão "Etiqueta ilegível" para digitar a locação.
CREATE TABLE inv_ajustes_locacao (
  id               INT(11)      NOT NULL AUTO_INCREMENT,
  id_inventario    INT(11)      NOT NULL,
  empresa          INT(11)      NOT NULL,
  revenda          INT(11)      NOT NULL,
  coletor          INT(11)      NOT NULL DEFAULT 0,
  dupla_label      VARCHAR(120) NULL,
  item_estoque_pub VARCHAR(50)  NOT NULL,
  cod_item_estoque VARCHAR(50)  NULL,
  locacao_anterior VARCHAR(30)  NOT NULL,
  locacao_nova     VARCHAR(30)  NOT NULL,
  entrada          VARCHAR(10)  NOT NULL,
  ajustado_em      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ajuste_inv  (id_inventario, ajustado_em),
  KEY idx_ajuste_item (id_inventario, item_estoque_pub),
  KEY idx_ajuste_entrada (id_inventario, entrada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
