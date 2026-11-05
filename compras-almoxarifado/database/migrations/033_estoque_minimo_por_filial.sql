-- Migration 033: Estoque mínimo passa a ser configurável por filial (antes era
-- um único valor global em itens.estoque_minimo, igual para todas as lojas).
ALTER TABLE estoque
    ADD COLUMN estoque_minimo DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER quantidade;

-- Backfill: usa o valor global do item como ponto de partida em cada filial.
UPDATE estoque e
JOIN itens i ON i.id = e.item_id
SET e.estoque_minimo = i.estoque_minimo;
