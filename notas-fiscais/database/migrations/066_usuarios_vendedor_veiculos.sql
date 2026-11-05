-- @migration 066_usuarios_vendedor_veiculos
-- Marca "Vendedor Veículos" no Controle de Acesso: quem tem a marca aparece na
-- lista de Vendedor (A6) do pedido de Faturamento.
-- Quem já estava nos grupos Vendedores / Gerentes de Vendas (critério anterior)
-- entra marcado, para a lista não mudar sozinha.
USE `compras`;

ALTER TABLE `usuarios`
  ADD COLUMN IF NOT EXISTS `vendedor_veiculos` tinyint(1) NOT NULL DEFAULT 0
  COMMENT 'Aparece como opção de Vendedor no pedido de Faturamento' AFTER `ativo`;

UPDATE `usuarios` u
   SET u.`vendedor_veiculos` = 1
 WHERE u.`vendedor_veiculos` = 0
   AND EXISTS (SELECT 1 FROM `nf_grupo_membros` gm
                 JOIN `nf_grupos` g ON g.`id` = gm.`grupo_id`
                WHERE gm.`usuario_id` = u.`id`
                  AND g.`nome` IN ('Vendedores', 'Gerentes de Vendas'));
