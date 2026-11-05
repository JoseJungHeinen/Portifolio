-- 047_cotacao_avulsa.sql
-- Cotação sem requisição + escopo próprio de itens.
--
-- cotacao_itens_solicitados = o que a cotação PEDE
-- cotacao_itens            = o que cada fornecedor RESPONDEU
--
-- Antes disso o "pedido" era derivado de requisicao_itens ao vivo, o que fazia
-- editar uma requisição alterar retroativamente o escopo de uma cotação já
-- enviada ao fornecedor.
--
-- COLLATE explícito é obrigatório: sem ele a tabela herda utf8mb4_general_ci e
-- as FKs falham com errno 150 — foi o que quebrou a migration 017.

ALTER TABLE cotacoes MODIFY requisicao_id char(36) NULL;

CREATE TABLE IF NOT EXISTS cotacao_itens_solicitados (
  id              char(36)      NOT NULL,
  cotacao_id      char(36)      NOT NULL,
  item_id         char(36)      NULL,
  descricao_livre varchar(255)  NULL,
  quantidade      decimal(10,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_cotacao_item (cotacao_id, item_id),
  KEY idx_cis_cotacao (cotacao_id),
  CONSTRAINT fk_cis_cotacao FOREIGN KEY (cotacao_id) REFERENCES cotacoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_cis_item    FOREIGN KEY (item_id)    REFERENCES itens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill das cotações existentes. Sem ele, cotação antiga fica sem itens nas
-- quatro telas depois que a leitura mudar (Tarefa 3).
--
-- item_id é nullable e acompanha descricao_livre porque requisicao_itens permite
-- item de texto livre (migration 043); com NOT NULL o backfill descartaria essas
-- linhas em silêncio.
--
-- O NOT EXISTS torna a migration idempotente: só semeia cotação que ainda não
-- tem escopo. INSERT IGNORE não serviria, porque o unique não deduplica linhas
-- com item_id NULL.
--
-- O GROUP BY existe porque requisicao_itens NÃO tem unique em
-- (requisicao_id, item_id): a mesma requisição pode listar o mesmo item em duas
-- linhas. Sem agregar, o backfill estoura o uk_cotacao_item. Somar é a semântica
-- correta — a cotação pede a quantidade total daquele item — e é o que o resto do
-- fluxo já assume: salvarResposta() rejeita item_id repetido na mesma submissão,
-- então cotacao_itens sempre teve uma linha por item.
--
-- O CASE no agrupamento mantém item de catálogo agrupado só por item_id, e item
-- de texto livre (item_id NULL) agrupado pela descrição — sem ele, todos os itens
-- livres de uma cotação colapsariam numa linha só.
INSERT INTO cotacao_itens_solicitados (id, cotacao_id, item_id, descricao_livre, quantidade)
SELECT UUID(),
       c.id,
       ri.item_id,
       CASE WHEN ri.item_id IS NULL THEN ri.descricao_livre END,
       SUM(ri.quantidade)
FROM cotacoes c
JOIN requisicao_itens ri ON ri.requisicao_id = c.requisicao_id
WHERE c.requisicao_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM cotacao_itens_solicitados x WHERE x.cotacao_id = c.id
  )
GROUP BY c.id,
         ri.item_id,
         CASE WHEN ri.item_id IS NULL THEN ri.descricao_livre END;
