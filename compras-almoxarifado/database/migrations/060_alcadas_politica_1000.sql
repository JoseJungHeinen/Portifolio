-- 060_alcadas_politica_1000.sql
-- Alçadas combinadas com o setor de Compras (27/10/2026), para a Política
-- de Compras COMP-001:
--   até R$ 999,99         → gestor aprova (1 nível)
--   a partir de R$ 1.000  → nível 1: diretor  →  nível 2: gestor verifica
--
-- A mesma tabela vale para requisições e para saídas de brinde.
-- Os níveis passam a ser sequenciais e casados pelo valor (Aprovacao.php);
-- sem essa versão do código no servidor, esta configuração NÃO garante a
-- ordem diretor → gestor.
--
-- Deploy não envia migrations: rodar à mão no servidor, DEPOIS do deploy do
-- Aprovacao.php / AprovacaoController.php. Aprovações já pendentes mantêm os
-- níveis com que foram criadas — conferir antes:
--   SELECT entidade_tipo, nivel, COUNT(*) FROM aprovacoes WHERE status='pendente' GROUP BY 1,2;

DELETE FROM alcadas;

INSERT INTO alcadas (id, perfil, valor_min, valor_max, nivel, ativo) VALUES
  ('56000001-0000-0000-0000-000000000001', 'gestor',  0.00,    999.99, 1, 1),
  ('56000002-0000-0000-0000-000000000002', 'diretor', 1000.00, NULL,   1, 1),
  ('56000003-0000-0000-0000-000000000003', 'gestor',  1000.00, NULL,   2, 1);
