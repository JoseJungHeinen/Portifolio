/*
  Encerrar a última contagem e o aviso de "Terminei" do coletor.

  rodada_fechada_em / rodada_fechada_por: o gestor encerrou a rodada corrente
  sem abrir outra — hoje, a 2ª, que é a última que o sistema conduz. Com isso
  preenchido, nenhuma porta grava mais contagem.

  inv_coletor_terminou: o coletor avisou que terminou a parte dele numa rodada,
  com quantos itens ainda faltavam naquele momento. Uma linha por coletor e
  rodada; avisar de novo sobrescreve.

  Comandos simples, sem variáveis e sem comentário de linha: funcionam em
  cliente SQL que roda cada comando numa conexão separada. Rodar de novo dá
  "Duplicate column" nos dois ALTER, o que só quer dizer que já foram
  aplicados.
*/

ALTER TABLE inv_inventarios ADD COLUMN rodada_fechada_em DATETIME NULL;

ALTER TABLE inv_inventarios ADD COLUMN rodada_fechada_por VARCHAR(120) NULL;

CREATE TABLE IF NOT EXISTS inv_coletor_terminou (id_inventario INT NOT NULL, empresa INT NOT NULL, revenda INT NOT NULL, rodada TINYINT NOT NULL, coletor TINYINT NOT NULL, dupla VARCHAR(120) NULL, pendentes INT NOT NULL DEFAULT 0, terminou_em DATETIME NOT NULL, PRIMARY KEY (id_inventario, empresa, revenda, rodada, coletor));

/* Conferência: as três linhas têm que voltar com 1. */
SELECT 'inv_inventarios.rodada_fechada_em' AS objeto, COUNT(*) AS existe FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inv_inventarios' AND column_name = 'rodada_fechada_em' UNION ALL SELECT 'inv_inventarios.rodada_fechada_por', COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inv_inventarios' AND column_name = 'rodada_fechada_por' UNION ALL SELECT 'inv_coletor_terminou', COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'inv_coletor_terminou';
