/*
  Locações da 2ª contagem e o coletor designado para cada uma.

  Inventário sem nenhuma linha aqui recebe todas as locações divergentes, para
  qualquer coletor.

  A coluna locacao_geral copia charset e collation de estoque.locacao_geral,
  lidos do próprio banco: a fila compara as duas colunas, e collation diferente
  estoura "Illegal mix of collations". Produção usa utf8mb4_0900_ai_ci (MySQL 8)
  e o XAMPP local usa utf8mb4_unicode_ci (MariaDB), então não dá para fixar.

  Sem comentários de linha dentro dos comandos: há clientes SQL que juntam o
  script numa linha só, e aí um comentário de linha engole o resto do comando.

  Idempotente: pode rodar mais de uma vez.

  Cliente SQL que roda cada comando numa conexão separada perde as variáveis
  (@cs, @col, @s) e não cria nada. Nesse caso, rode só o comando abaixo, com a
  collation de estoque.locacao_geral do banco em questão. Foi assim em produção
  em 2026-09-23, com utf8mb4_0900_ai_ci:

  CREATE TABLE IF NOT EXISTS inv_cont2_locacoes (id_inventario INT NOT NULL, empresa INT NOT NULL, revenda INT NOT NULL, locacao_geral VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL, coletor TINYINT NOT NULL, PRIMARY KEY (id_inventario, empresa, revenda, locacao_geral));
*/

SET @cs  = (SELECT character_set_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'estoque' AND column_name = 'locacao_geral');
SET @col = (SELECT collation_name     FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'estoque' AND column_name = 'locacao_geral');

SET @s = CONCAT('CREATE TABLE IF NOT EXISTS inv_cont2_locacoes (id_inventario INT NOT NULL, empresa INT NOT NULL, revenda INT NOT NULL, locacao_geral VARCHAR(60) CHARACTER SET ', @cs, ' COLLATE ', @col, ' NOT NULL, coletor TINYINT NOT NULL, PRIMARY KEY (id_inventario, empresa, revenda, locacao_geral))');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

/* Quem rodou uma versão anterior desta migração tem a tabela sem a coluna coletor. */
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inv_cont2_locacoes' AND column_name = 'coletor') = 0, 'ALTER TABLE inv_cont2_locacoes ADD COLUMN coletor TINYINT NOT NULL DEFAULT 1', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

/* Conferência: a coluna "igual" tem que voltar 1 na linha de locacao_geral, e a linha do coletor tem que existir. */
SELECT c.column_name, c.collation_name, e.collation_name AS collation_estoque, (c.collation_name <=> e.collation_name) AS igual FROM information_schema.columns c LEFT JOIN information_schema.columns e ON e.table_schema = c.table_schema AND e.table_name = 'estoque' AND e.column_name = 'locacao_geral' WHERE c.table_schema = DATABASE() AND c.table_name = 'inv_cont2_locacoes' AND c.column_name IN ('locacao_geral', 'coletor');
