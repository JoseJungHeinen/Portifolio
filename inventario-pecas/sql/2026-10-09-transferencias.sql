/* Transferencia de saldo entre filiais da mesma cidade (Fiat e Jeep), feita
   pela conferencia quando a filial conferida esta zerada no Apollo e a peca esta
   na prateleira. A linha nasce pendente ANTES de o Apollo ser alterado e vira
   gravado ou erro depois: assim nenhuma alteracao no Apollo fica sem rastro.
   Comentarios so neste formato: o cliente SQL de producao junta tudo numa linha. */
CREATE TABLE inv_transferencias (
  id                  INT(11)      NOT NULL AUTO_INCREMENT,
  id_inventario       INT(11)      NOT NULL,
  empresa             INT(11)      NOT NULL,
  revenda_origem      INT(11)      NOT NULL,
  revenda_destino     INT(11)      NOT NULL,
  coletor             INT(11)      NOT NULL DEFAULT 0,
  dupla_label         VARCHAR(120) NULL,
  item_estoque_pub    VARCHAR(50)  NOT NULL,
  cod_item_estoque    VARCHAR(50)  NOT NULL,
  descricao           VARCHAR(255) NULL,
  quantidade          INT(11)      NOT NULL,
  saldo_origem_antes  INT(11)      NULL,
  saldo_destino_antes INT(11)      NULL,
  locacao_destino     VARCHAR(30)  NOT NULL,
  entrada_locacao     VARCHAR(10)  NOT NULL,
  status              VARCHAR(10)  NOT NULL DEFAULT 'pendente',
  erro                VARCHAR(255) NULL,
  criado_em           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  gravado_em          DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_transf_data (criado_em),
  KEY idx_transf_item (empresa, item_estoque_pub),
  KEY idx_transf_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
