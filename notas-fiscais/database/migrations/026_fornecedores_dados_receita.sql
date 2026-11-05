-- 026: dados cadastrais da Receita (consulta cnpj.ws) no fornecedor
-- Colunas nullable — seguras para a tabela compartilhada com o sistema de compras.

ALTER TABLE fornecedores
  ADD COLUMN data_abertura      DATE        NULL COMMENT 'Data de inicio de atividade na Receita (cnpj.ws)',
  ADD COLUMN situacao_cadastral VARCHAR(30) NULL COMMENT 'Situacao cadastral na Receita: Ativa, Baixada, Suspensa...',
  ADD COLUMN optante_mei        TINYINT(1)  NULL COMMENT '1=optante MEI, 0=nao, NULL=desconhecido',
  ADD COLUMN optante_simples    TINYINT(1)  NULL COMMENT '1=optante Simples Nacional, 0=nao, NULL=desconhecido';
