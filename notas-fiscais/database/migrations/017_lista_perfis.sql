-- ─────────────────────────────────────────────────────────────────
-- 017 — Vincular a lista "Perfil" (módulo Tabelas) ao Controle de
-- Acesso.
--
-- Antes: o dropdown Perfil do modal "Novo usuário" era hardcoded na
-- view. A lista "Perfil" em nf_listas era só referência solta, com
-- itens que o motor de permissões não reconhece (Usuário, Gerente).
--
-- Agora: cada item da lista guarda em `dados` o slug interno do
-- perfil ({"slug":"..."}), e a tela de Acessos passa a ler a lista.
-- Só itens ativos com slug válido aparecem no dropdown; o rótulo
-- (valor) pode ser renomeado livremente pelas Tabelas.
--
-- Idempotente — pode rodar mais de uma vez sem efeito colateral.
-- ─────────────────────────────────────────────────────────────────

SET @lista = '9e7f1a00-0000-4000-8000-000000000001';

-- Garante a lista Perfil (já existe em produção; cobre ambientes novos)
INSERT IGNORE INTO nf_listas (id, nome, slug, tipo, descricao, ativo)
VALUES (@lista, 'Perfil', 'perfil', 'pre_configurada',
        'Perfis de usuário — vinculada ao Controle de Acesso (modal Novo usuário)', 1);

-- Itens existentes que correspondem a perfis reais ganham o slug
UPDATE nf_lista_itens SET dados = '{"slug":"diretor"}'
 WHERE lista_id = @lista AND valor = 'Diretor';

UPDATE nf_lista_itens SET dados = '{"slug":"admin"}'
 WHERE lista_id = @lista AND valor = 'Administrador';

-- Itens sem correspondência no motor de permissões saem de cena
UPDATE nf_lista_itens SET ativo = 0
 WHERE lista_id = @lista AND valor IN ('Usuário', 'Gerente');

-- Perfis que faltavam (IDs fixos para manter a migration idempotente)
INSERT IGNORE INTO nf_lista_itens (id, lista_id, valor, dados, ativo) VALUES
  ('9e7f1a01-0000-4000-8000-000000000001', @lista, 'Solicitante', '{"slug":"solicitante"}', 1),
  ('9e7f1a01-0000-4000-8000-000000000002', @lista, 'Almoxarife',  '{"slug":"almoxarife"}',  1),
  ('9e7f1a01-0000-4000-8000-000000000003', @lista, 'Comprador',   '{"slug":"comprador"}',   1),
  ('9e7f1a01-0000-4000-8000-000000000004', @lista, 'Gestor',      '{"slug":"gestor"}',      1);
