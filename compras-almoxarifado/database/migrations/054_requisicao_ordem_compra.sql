-- 054_requisicao_ordem_compra.sql
-- Quarto tipo de requisicao: Ordem de Compra (cotacoes digitadas a mao).
--
-- O setor descreve o que precisa, registra as cotacoes que levantou e marca a
-- vencedora. Na aprovacao o sistema gera a OC. Nao mexe em estoque.
--
-- COLLATE explicito e obrigatorio: sem ele a tabela herda utf8mb4_general_ci e
-- as FKs falham com errno 150 (ver nota da migration 047).

ALTER TABLE requisicoes
  MODIFY tipo enum('compra','interna','transferencia','ordem_compra') NOT NULL DEFAULT 'compra';

CREATE TABLE IF NOT EXISTS requisicao_cotacoes (
  id                 char(36)          NOT NULL,
  requisicao_id      char(36)          NOT NULL,
  fornecedor_id      char(36)          NOT NULL,
  valor_total        decimal(14,2)     NOT NULL,
  frete              decimal(14,2)     NOT NULL DEFAULT 0.00,
  prazo_dias         smallint unsigned NULL,
  condicao_pagamento varchar(60)       NULL,
  observacao         varchar(255)      NULL,
  vencedora          tinyint(1)        NOT NULL DEFAULT 0,
  criado_em          timestamp         NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uk_reqcot_forn (requisicao_id, fornecedor_id),
  KEY idx_reqcot_req (requisicao_id),
  CONSTRAINT fk_reqcot_req  FOREIGN KEY (requisicao_id) REFERENCES requisicoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_reqcot_forn FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissao do tipo novo, no mesmo esquema da migration 052. Todo setor precisa
-- gerar ordem de compra, por isso os cinco perfis nao-admin entram liberados.
-- Admin nao precisa de linha: podeModulo() libera admin sempre.
INSERT IGNORE INTO permissoes_perfil (perfil, modulo, acesso) VALUES
  ('solicitante', 'requisicoes.ordem_compra', 'ver'),
  ('almoxarife',  'requisicoes.ordem_compra', 'ver'),
  ('comprador',   'requisicoes.ordem_compra', 'ver'),
  ('gestor',      'requisicoes.ordem_compra', 'ver'),
  ('diretor',     'requisicoes.ordem_compra', 'ver');

INSERT IGNORE INTO parametros_sistema (id, chave, valor, descricao) VALUES
  (UUID(), 'ordem_compra.minimo_cotacoes',     '2', 'Minimo de cotacoes para enviar requisicao tipo Ordem de Compra'),
  (UUID(), 'ordem_compra.recomendado_cotacoes','3', 'Numero recomendado de cotacoes na requisicao tipo Ordem de Compra');
