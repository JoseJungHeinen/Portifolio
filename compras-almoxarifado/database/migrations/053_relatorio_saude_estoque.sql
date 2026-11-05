-- 053_relatorio_saude_estoque.sql
-- Permissão do relatório Saúde do Estoque (sub-chave de relatorios):
--   relatorios.saude_estoque
--
-- Padrão: gestor, diretor e comprador. Admin não precisa de linha:
-- podeModulo() libera admin sempre.
--
-- INSERT IGNORE sobre a PK (perfil, modulo): reexecutar não sobrescreve um
-- padrão ajustado à mão (mesma escolha da 052).

INSERT IGNORE INTO permissoes_perfil (perfil, modulo, acesso) VALUES
  ('gestor',    'relatorios.saude_estoque', 'ver'),
  ('diretor',   'relatorios.saude_estoque', 'ver'),
  ('comprador', 'relatorios.saude_estoque', 'ver');
