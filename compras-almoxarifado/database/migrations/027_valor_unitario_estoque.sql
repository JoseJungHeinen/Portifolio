-- Migration 027: Valor unitário por filial no estoque + ajuste de valor
ALTER TABLE estoque
    ADD COLUMN valor_unitario DECIMAL(14,4) NULL AFTER quantidade;

ALTER TABLE movimentos_estoque
    ADD COLUMN valor_unit_anterior DECIMAL(14,4) NULL,
    ADD COLUMN valor_unit_novo     DECIMAL(14,4) NULL;

ALTER TABLE movimentos_estoque
    MODIFY COLUMN motivo ENUM(
        'compra','inventario','perda','avaria','correcao','transferencia','requisicao','ajuste_valor','outro'
    ) NULL COMMENT 'Motivo do movimento';
