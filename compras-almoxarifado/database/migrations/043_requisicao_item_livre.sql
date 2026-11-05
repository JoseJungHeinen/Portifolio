-- Migration 043: item livre em requisicao_itens (descricao_livre + anexo)
--
-- Estas colunas ja existem no banco de producao ha tempo, mas nunca foram
-- versionadas: foram criadas direto no servidor. Um ambiente montado do zero
-- pelas migrations ficava sem elas, e a tela de Cotacoes quebrava com
-- "Unknown column 'ri2.descricao_livre'" (CompraController::cotacoes).
-- Mesma situacao da migration 009, e mesmo tratamento: IF NOT EXISTS para ser
-- inofensiva onde a coluna ja existe.
--
-- O par descricao_livre/anexo existe para o item que nao esta no catalogo: o
-- solicitante descreve o que precisa em texto livre e anexa uma foto ou PDF.
-- Por isso item_id passa a aceitar NULL — ver Requisicao::addItem(), que grava
-- `$itemId ?: null` quando o item e livre.

-- Atencao a ordem das clausulas: em MySQL/MariaDB COMMENT vem antes de AFTER.
ALTER TABLE requisicao_itens
    ADD COLUMN IF NOT EXISTS descricao_livre VARCHAR(255) NULL
        COMMENT 'Descricao do item quando ele nao esta cadastrado em itens' AFTER item_id,
    ADD COLUMN IF NOT EXISTS anexo VARCHAR(255) NULL
        COMMENT 'Caminho relativo do anexo, ex: uploads/requisicoes/<uuid>.pdf' AFTER descricao_livre;

-- item_id deixa de ser obrigatorio: item livre nao referencia o catalogo.
-- MODIFY e declarativo, entao rodar de novo num banco ja ajustado nao quebra.
-- A FK fk_reqitem_item continua valida — em MySQL/MariaDB uma FK aceita NULL,
-- que simplesmente nao e verificada contra a tabela referenciada.
ALTER TABLE requisicao_itens
    MODIFY COLUMN item_id CHAR(36) NULL;
