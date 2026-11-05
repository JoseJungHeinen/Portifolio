-- 040_auditoria_itens_ajuste.sql
-- Rastreia se a divergencia de um item de auditoria ja foi aplicada ao estoque,
-- para permitir corrigir a contagem antes de aplicar e travar depois de aplicado.
ALTER TABLE auditoria_itens
    ADD COLUMN ajustado_em  DATETIME NULL AFTER qtd_fisica,
    ADD COLUMN ajustado_por CHAR(36) NULL AFTER ajustado_em,
    ADD CONSTRAINT fk_auditem_ajustado_por FOREIGN KEY (ajustado_por) REFERENCES usuarios(id);
