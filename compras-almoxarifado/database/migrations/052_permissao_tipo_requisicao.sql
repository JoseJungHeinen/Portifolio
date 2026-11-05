-- 052_permissao_tipo_requisicao.sql
-- Permissao por tipo de requisicao, como sub-chaves do modulo requisicoes:
--   requisicoes.compra        cria requisicao do tipo Compra
--   requisicoes.interna       cria Requisicao Interna
--   requisicoes.transferencia cria Solicitacao de Transferencia
--
-- Os padroes reproduzem EXATAMENTE o comportamento anterior, para ninguem
-- perder acesso no deploy:
--   Compra: antes era can(['comprador','admin']) -> perfil comprador
--           (admin nao precisa de linha: podeModulo() libera admin sempre)
--   Interna e Transferencia: antes eram livres -> os cinco perfis nao-admin
--
-- INSERT IGNORE sobre a PK (perfil, modulo): reexecutar nao sobrescreve um
-- padrao ajustado a mao. A migration 038 usou ON DUPLICATE KEY UPDATE, que
-- zeraria esse ajuste; a diferenca aqui e deliberada.

INSERT IGNORE INTO permissoes_perfil (perfil, modulo, acesso) VALUES
  ('comprador',   'requisicoes.compra',        'ver'),
  ('solicitante', 'requisicoes.interna',       'ver'),
  ('almoxarife',  'requisicoes.interna',       'ver'),
  ('comprador',   'requisicoes.interna',       'ver'),
  ('gestor',      'requisicoes.interna',       'ver'),
  ('diretor',     'requisicoes.interna',       'ver'),
  ('solicitante', 'requisicoes.transferencia', 'ver'),
  ('almoxarife',  'requisicoes.transferencia', 'ver'),
  ('comprador',   'requisicoes.transferencia', 'ver'),
  ('gestor',      'requisicoes.transferencia', 'ver'),
  ('diretor',     'requisicoes.transferencia', 'ver');
