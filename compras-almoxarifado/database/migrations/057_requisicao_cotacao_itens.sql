-- 057_requisicao_cotacao_itens.sql
-- Ordem de Compra: a cotação passa a ter valor por item. A descrição é copiada
-- (não é FK para requisicao_itens) porque as linhas de item são apagadas e
-- regravadas a cada salvamento do pedido — a âncora é a posição no formulário.

CREATE TABLE IF NOT EXISTS requisicao_cotacao_itens (
    id                    char(36)             NOT NULL,
    requisicao_cotacao_id char(36)             NOT NULL,
    posicao               smallint(5) unsigned NOT NULL,
    descricao             varchar(255)         NOT NULL,
    valor                 decimal(14,2)        NOT NULL DEFAULT 0.00,
    cotado                tinyint(1)           NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_reqcotitem (requisicao_cotacao_id, posicao),
    CONSTRAINT fk_reqcotitem_cot FOREIGN KEY (requisicao_cotacao_id)
        REFERENCES requisicao_cotacoes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
