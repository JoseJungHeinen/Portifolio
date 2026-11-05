-- 022: Adiciona is_matriz à tabela filiais para identificar o estoque principal
ALTER TABLE filiais
    ADD COLUMN is_matriz TINYINT(1) NOT NULL DEFAULT 0 AFTER ativo,
    ADD KEY idx_filial_matriz (is_matriz);

-- Ajustar o LIKE conforme o nome real da filial no banco
UPDATE filiais SET is_matriz = 1 WHERE nome LIKE '%Cidade A%Matriz%' OR nome LIKE '%Matriz%Cidade A%';
