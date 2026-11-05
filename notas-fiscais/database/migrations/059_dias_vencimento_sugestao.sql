-- @migration 059_dias_vencimento_sugestao
-- Nova chave em nf_configuracoes (grupo 'geral'): quantos dias somar a
-- partir de hoje para sugerir o vencimento no botão "Gerar" de parcelas
-- (Financeiro, dentro do wizard de processo). Antes era fixo no JS
-- (1 mês por parcela); virou configurável e o padrão caiu para 15 dias,
-- a pedido do usuário em 18/09/2026. Não afeta nota capturada com
-- vencimento próprio (duplicata do XML) — esse sempre vem da nota.
USE `compras`;

INSERT IGNORE INTO `nf_configuracoes` (`chave`, `valor`, `grupo`) VALUES
  ('geral.dias_vencimento_sugestao', '15', 'geral');
