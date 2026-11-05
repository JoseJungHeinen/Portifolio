-- Recalcula valor_total de todas as requisições usando valor_referencia como fallback
-- quando valor_unitario = 0 (itens cujo preço não foi informado pelo solicitante)

UPDATE requisicoes r
SET valor_total = (
    SELECT COALESCE(SUM(
        ri.quantidade * COALESCE(NULLIF(ri.valor_unitario, 0), i.valor_referencia, 0)
    ), 0)
    FROM requisicao_itens ri
    LEFT JOIN itens i ON i.id = ri.item_id
    WHERE ri.requisicao_id = r.id
);
