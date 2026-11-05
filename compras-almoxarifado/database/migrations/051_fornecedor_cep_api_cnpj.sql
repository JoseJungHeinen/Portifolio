-- 051_fornecedor_cep_api_cnpj.sql
-- Consulta de CNPJ no cadastro de fornecedores (cnpj.ws).
--
-- CEP: a API devolve o CEP e o cadastro não tinha onde guardar. Guardado só
-- com dígitos, como já é feito com o CNPJ; a formatação acontece na exibição.
--
-- Configuração em parametros_sistema, a tabela chave/valor que já existe:
--   cnpj.consulta_ativa  1/0    desativada, o botão não aparece no formulário
--   cnpj.token           vazio  vazio usa publica.cnpj.ws; preenchido, comercial.cnpj.ws
--   cnpj.timeout_seg     8      corte da chamada, para não travar a tela
--
-- chave é UNIQUE, então INSERT IGNORE torna a reexecução inócua.

ALTER TABLE fornecedores
  ADD COLUMN IF NOT EXISTS cep varchar(8) DEFAULT NULL
  COMMENT 'CEP so digitos; formatacao na exibicao'
  AFTER endereco;

INSERT IGNORE INTO parametros_sistema (id, chave, valor, descricao) VALUES
  (UUID(), 'cnpj.consulta_ativa', '1', 'Consulta automatica de CNPJ no cadastro de fornecedores (1=ativa, 0=inativa)'),
  (UUID(), 'cnpj.token',          '',  'Token da API cnpj.ws. Vazio usa a API publica, limitada a 3 consultas por minuto'),
  (UUID(), 'cnpj.timeout_seg',    '8', 'Tempo maximo de espera da consulta de CNPJ, em segundos');
