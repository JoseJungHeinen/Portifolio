/*
   O gestor decide quando parar de recontar um item: em vez de esperar a 4a
   contagem, ele encerra o item marcado e ele vai para Decisao com o que ja
   tem. Guardamos quando e quem encerrou.

   Comandos simples, um por linha, sem variaveis. Cliente de producao roda
   cada comando numa conexao separada e junta o script numa linha so - por
   isso so comentario de bloco, nunca ponto e virgula dentro de comentario.
*/

ALTER TABLE estoque ADD COLUMN recontagem_encerrada_em DATETIME NULL;

ALTER TABLE estoque ADD COLUMN recontagem_encerrada_por VARCHAR(120) NULL;

/* Conferencia: as duas colunas novas precisam existir. Deve devolver 2. */
SELECT COUNT(*) AS colunas_novas
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name = 'estoque'
   AND column_name IN ('recontagem_encerrada_em', 'recontagem_encerrada_por');
