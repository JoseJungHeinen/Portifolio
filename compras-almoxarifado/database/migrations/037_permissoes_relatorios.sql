-- 037_permissoes_relatorios.sql
-- Padrao por cargo para as 8 chaves compostas de relatorio (relatorios.<chave>),
-- usadas em permissoes_perfil/permissoes_usuario (modulo e VARCHAR livre,
-- reaproveitando a coluna existente). Replica exatamente as regras de cargo que
-- ja existem hoje via requirePerfil() em RelatorioController — ninguem perde
-- acesso quando a checkbox por relatorio entrar no ar. Ausencia de linha ja
-- significa 'nenhum' (fallback existente em podeModulo(), src/helpers.php).
-- admin nao precisa de linha — podeModulo() tem bypass total para admin.

-- A coluna modulo estava VARCHAR(30) neste banco (menor que os 50 caracteres
-- que a migration 017 ja pretendia) — 'relatorios.movimentacao_estoque' tem
-- 31 caracteres e nao caberia. Expande para VARCHAR(50) antes de inserir.
ALTER TABLE permissoes_perfil MODIFY COLUMN modulo VARCHAR(50) NOT NULL;
ALTER TABLE permissoes_usuario MODIFY COLUMN modulo VARCHAR(50) NOT NULL;

INSERT INTO permissoes_perfil (perfil, modulo, acesso) VALUES
  ('comprador', 'relatorios.compras', 'ver'),
  ('gestor', 'relatorios.compras', 'ver'),
  ('gestor', 'relatorios.filiais', 'ver'),
  ('gestor', 'relatorios.consumo', 'ver'),
  ('gestor', 'relatorios.movimentacao_estoque', 'ver'),
  ('gestor', 'relatorios.saving', 'ver'),
  ('gestor', 'relatorios.anomalias', 'ver'),
  ('gestor', 'relatorios.brindes', 'ver'),
  ('diretor', 'relatorios.compras', 'ver'),
  ('diretor', 'relatorios.filiais', 'ver'),
  ('diretor', 'relatorios.consumo', 'ver'),
  ('diretor', 'relatorios.movimentacao_estoque', 'ver'),
  ('diretor', 'relatorios.saving', 'ver'),
  ('diretor', 'relatorios.anomalias', 'ver'),
  ('diretor', 'relatorios.brindes', 'ver'),
  ('diretor', 'relatorios.auditoria', 'ver')
ON DUPLICATE KEY UPDATE acesso = VALUES(acesso);
