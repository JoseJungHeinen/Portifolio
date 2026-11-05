-- 038_permissoes_menu_adicionais.sql
-- Adiciona controle de permissao por usuario para os itens do menu que ainda
-- nao tinham toggle na tela de Permissoes Individuais: Auditoria (estoque),
-- Confirmacao de Recebimento, Ramais e Links Uteis. Replica exatamente as
-- regras de papel que ja existem hoje (can() no sidebar / ausencia de gate)
-- para que ninguem perca acesso quando a checkbox entrar no ar.
-- Auditoria e Confirmacao de Recebimento mantem requirePerfil() intocado nos
-- controllers — esta permissao controla so a visibilidade no menu (mesmo
-- padrao ja usado por Itens/Estoque/etc., ver podeModulo() em helpers.php).
-- admin nao precisa de linha — podeModulo() tem bypass total para admin.

INSERT INTO permissoes_perfil (perfil, modulo, acesso) VALUES
  ('comprador', 'auditoria_estoque', 'ver'),
  ('gestor',    'auditoria_estoque', 'ver'),
  ('diretor',   'auditoria_estoque', 'ver'),

  ('almoxarife','confirmacao_recebimento', 'ver'),
  ('gestor',    'confirmacao_recebimento', 'ver'),
  ('diretor',   'confirmacao_recebimento', 'ver'),

  ('solicitante','ramais', 'ver'),
  ('almoxarife', 'ramais', 'ver'),
  ('comprador',  'ramais', 'ver'),
  ('gestor',     'ramais', 'ver'),
  ('diretor',    'ramais', 'ver'),

  ('solicitante','links_uteis', 'ver'),
  ('almoxarife', 'links_uteis', 'ver'),
  ('comprador',  'links_uteis', 'ver'),
  ('gestor',     'links_uteis', 'ver'),
  ('diretor',    'links_uteis', 'ver')
ON DUPLICATE KEY UPDATE acesso = VALUES(acesso);
