-- Migration 018 — Adiciona 'requisicao' ao motivo de movimentos_estoque
ALTER TABLE movimentos_estoque
    MODIFY COLUMN motivo ENUM(
        'compra','inventario','perda','avaria','correcao','transferencia','requisicao','outro'
    ) NULL COMMENT 'Motivo do movimento';
