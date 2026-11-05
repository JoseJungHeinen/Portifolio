-- 061_requisicao_finalidade_prazo.sql
-- Política de Compras COMP-001, §7: a requisição informa a finalidade do item
-- (exigida para o crédito de IBS/CBS) e a data em que ele é necessário.
-- Obrigatórios para Compra e Ordem de Compra (validados no RequisicaoController);
-- NULL nas requisições antigas e nos tipos Interna/Transferência.
-- Deploy não envia migrations: rodar à mão no servidor, ANTES do código.

ALTER TABLE requisicoes
  ADD COLUMN finalidade ENUM('revenda','insumo','uso_consumo','imobilizado','servico') NULL
      COMMENT 'Finalidade fiscal (política §7)' AFTER justificativa,
  ADD COLUMN necessario_ate DATE NULL
      COMMENT 'Data em que o item é necessário (política §7)' AFTER finalidade;
