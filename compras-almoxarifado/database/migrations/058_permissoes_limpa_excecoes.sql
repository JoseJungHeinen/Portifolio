-- 058_permissoes_limpa_excecoes.sql
-- Permissões granulares, etapa 1 (docs/superpowers/specs/2026-10-21-permissoes-granulares-design.md).
--
-- A tela antiga de usuário gravava TODAS as chaves em permissoes_usuario a cada
-- salvamento. Com o cargo virando modelo editável, essas linhas congelariam o
-- usuário: mudar o padrão do cargo não teria efeito em quem já foi salvo.
--
-- Remove as linhas do usuário que são iguais ao padrão do cargo (comparando
-- marcada/desmarcada: qualquer acesso diferente de 'nenhum' conta como
-- marcada; ausência de linha no cargo = desmarcada). Ficam só as exceções
-- reais. O acesso efetivo de ninguém muda: podeModulo() cai no padrão do
-- cargo exatamente com o mesmo resultado.
--
-- Idempotente: rodar de novo não remove nada além do que já é igual ao cargo.

DELETE pu
FROM permissoes_usuario pu
JOIN usuarios u ON u.id = pu.usuario_id
LEFT JOIN permissoes_perfil pp ON pp.perfil = u.perfil AND pp.modulo = pu.modulo
WHERE (pu.acesso = 'nenhum') = (COALESCE(pp.acesso, 'nenhum') = 'nenhum');
