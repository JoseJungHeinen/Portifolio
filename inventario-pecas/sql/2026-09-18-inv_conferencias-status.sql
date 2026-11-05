-- Coluna de decisão da conferência: o registro passa a guardar o que a dupla
-- concluiu ('ok' ou 'troca'), e não só que o item foi bipado.
-- O índice é como se monta a lista de trocas pendentes de um inventário.
ALTER TABLE inv_conferencias
  ADD COLUMN status VARCHAR(10) NOT NULL AFTER origem,
  ADD KEY idx_inv_status (id_inventario, status);
