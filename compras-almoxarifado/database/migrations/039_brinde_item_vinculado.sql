-- 039_brinde_item_vinculado.sql
-- Vinculo opcional entre um brinde e um item do catalogo geral, para que
-- entrada/saida de brinde tambem reflitam no estoque geral quando vinculado.
ALTER TABLE brindes
    ADD COLUMN item_id CHAR(36) NULL AFTER codigo,
    ADD CONSTRAINT fk_brinde_item FOREIGN KEY (item_id) REFERENCES itens(id);

ALTER TABLE movimentos_estoque
    MODIFY COLUMN motivo ENUM(
        'compra','inventario','perda','avaria','correcao','transferencia','requisicao','ajuste_valor','brinde','outro'
    ) NULL COMMENT 'Motivo do movimento';
