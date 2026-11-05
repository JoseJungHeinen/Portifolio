-- Tabela de permissões padrão por perfil
CREATE TABLE IF NOT EXISTS permissoes_perfil (
    perfil  ENUM('solicitante','almoxarife','comprador','gestor','diretor','admin') NOT NULL,
    modulo  VARCHAR(50) NOT NULL,
    acesso  ENUM('nenhum','ver','criar','aprovar','total') NOT NULL DEFAULT 'nenhum',
    PRIMARY KEY (perfil, modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de permissões individuais por usuário (override do perfil)
CREATE TABLE IF NOT EXISTS permissoes_usuario (
    usuario_id CHAR(36)   NOT NULL,
    modulo     VARCHAR(50) NOT NULL,
    acesso     ENUM('nenhum','ver','criar','aprovar','total') NOT NULL DEFAULT 'nenhum',
    PRIMARY KEY (usuario_id, modulo),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Permissões padrão por perfil ──────────────────────────────────────────────

-- solicitante: apenas cria e acompanha suas requisições
INSERT INTO permissoes_perfil (perfil, modulo, acesso) VALUES
  ('solicitante', 'requisicoes', 'criar')
ON DUPLICATE KEY UPDATE acesso = VALUES(acesso);

-- almoxarife: SOMENTE baixa de estoque (saída)
INSERT INTO permissoes_perfil (perfil, modulo, acesso) VALUES
  ('almoxarife', 'estoque', 'criar')
ON DUPLICATE KEY UPDATE acesso = VALUES(acesso);

-- comprador: gestão de compras, cotações, fornecedores, fiscal
INSERT INTO permissoes_perfil (perfil, modulo, acesso) VALUES
  ('comprador', 'requisicoes',  'ver'),
  ('comprador', 'aprovacoes',   'ver'),
  ('comprador', 'estoque',      'ver'),
  ('comprador', 'itens',        'ver'),
  ('comprador', 'cotacoes',     'total'),
  ('comprador', 'fornecedores', 'total'),
  ('comprador', 'contratos',    'criar'),
  ('comprador', 'fiscal',       'criar'),
  ('comprador', 'relatorios',   'ver')
ON DUPLICATE KEY UPDATE acesso = VALUES(acesso);

-- gestor: aprovações e visão gerencial
INSERT INTO permissoes_perfil (perfil, modulo, acesso) VALUES
  ('gestor', 'requisicoes',  'aprovar'),
  ('gestor', 'aprovacoes',   'aprovar'),
  ('gestor', 'estoque',      'total'),
  ('gestor', 'itens',        'total'),
  ('gestor', 'cotacoes',     'total'),
  ('gestor', 'fornecedores', 'total'),
  ('gestor', 'contratos',    'aprovar'),
  ('gestor', 'fiscal',       'aprovar'),
  ('gestor', 'relatorios',   'ver')
ON DUPLICATE KEY UPDATE acesso = VALUES(acesso);

-- diretor: visão estratégica + aprovação final
INSERT INTO permissoes_perfil (perfil, modulo, acesso) VALUES
  ('diretor', 'requisicoes',  'aprovar'),
  ('diretor', 'aprovacoes',   'aprovar'),
  ('diretor', 'estoque',      'ver'),
  ('diretor', 'itens',        'ver'),
  ('diretor', 'cotacoes',     'ver'),
  ('diretor', 'fornecedores', 'ver'),
  ('diretor', 'contratos',    'aprovar'),
  ('diretor', 'fiscal',       'aprovar'),
  ('diretor', 'relatorios',   'ver'),
  ('diretor', 'usuarios',     'ver')
ON DUPLICATE KEY UPDATE acesso = VALUES(acesso);

-- admin: podeModulo() retorna true sem consultar esta tabela (bypass no helpers.php)
