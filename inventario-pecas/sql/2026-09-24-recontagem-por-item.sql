/*
  Recontagem por item.

  id_linha: a estoque não tinha chave. A coluna id existe, mas nunca foi
  preenchida (é NULL em todas as linhas) e nenhum código a usa. id_linha é a
  chave de cada linha a partir daqui, o ADD COLUMN numera as linhas que já
  existem, e as inserções atuais (que não citam a coluna) numeram sozinhas.
  A coluna id antiga fica como está.

  Cada linha da estoque passa a ter a sua contagem atual (rodada_item, 1 a 4)
  e o coletor designado para ela (coletor_recontagem, nulo na 1ª, que é livre).
  cont_3 e cont_4 ficam ao lado de cont_1 e cont_2. "Feita" se lê pela data
  (contN_em), não pelo valor: por isso cont_3 e cont_4 são nuláveis.

  Comandos simples, sem variáveis e sem comentário de linha: o cliente SQL de
  produção roda cada comando numa conexão separada e junta o script numa linha.
  Rodar de novo dá "Duplicate column" nos ALTER, o que só quer dizer que já
  foram aplicados, o UPDATE só mexe em linha ainda em rodada_item = 1.
*/

ALTER TABLE estoque ADD COLUMN id_linha INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST;

ALTER TABLE estoque ADD COLUMN cont_3 INT NULL;

ALTER TABLE estoque ADD COLUMN cont_4 INT NULL;

ALTER TABLE estoque ADD COLUMN cont3_em DATETIME NULL;

ALTER TABLE estoque ADD COLUMN cont4_em DATETIME NULL;

ALTER TABLE estoque ADD COLUMN cont3_entrada ENUM('bipado','digitado') NULL;

ALTER TABLE estoque ADD COLUMN cont4_entrada ENUM('bipado','digitado') NULL;

ALTER TABLE estoque ADD COLUMN dupla_cont3 VARCHAR(120) NULL;

ALTER TABLE estoque ADD COLUMN dupla_cont4 VARCHAR(120) NULL;

ALTER TABLE estoque ADD COLUMN rodada_item TINYINT NOT NULL DEFAULT 1;

ALTER TABLE estoque ADD COLUMN coletor_recontagem TINYINT NULL;

/*
  Inventário que já estava na 2ª pelo modelo antigo (rodada do inventário):
  as linhas que estavam na fila da 2ª passam a rodada_item = 2, com o coletor
  da divisão. Sem divisão gravada, todas as divergentes entram, sem coletor —
  o gestor designa pela grade ("Trocar coletor").
*/
UPDATE estoque e JOIN inv_inventarios i ON i.id = e.id_inventario AND i.empresa = e.empresa AND i.revenda = e.revenda LEFT JOIN inv_cont2_locacoes l ON l.id_inventario = e.id_inventario AND l.empresa = e.empresa AND l.revenda = e.revenda AND l.locacao_geral = e.locacao_geral SET e.rodada_item = 2, e.coletor_recontagem = l.coletor WHERE i.rodada_atual = 2 AND i.status = 'aberto' AND e.rodada_item = 1 AND e.cont_1 <> e.quantidade AND e.situacao <> 'D' AND (l.locacao_geral IS NOT NULL OR NOT EXISTS (SELECT 1 FROM inv_cont2_locacoes x WHERE x.id_inventario = e.id_inventario AND x.empresa = e.empresa AND x.revenda = e.revenda));

/* Conferência: tem que voltar 11. */
SELECT COUNT(*) AS colunas_novas FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'estoque' AND column_name IN ('id_linha','cont_3','cont_4','cont3_em','cont4_em','cont3_entrada','cont4_entrada','dupla_cont3','dupla_cont4','rodada_item','coletor_recontagem');
