-- 035_template_departamento.sql
--
-- Departamento como critério próprio do template (fluxo).
--
-- Motivo: o funil do sistema é Filial → Departamento → Setor → Template. Hoje o
-- template só declara SETOR, e a busca casa com "setor_id IS NULL OR = ?" — ou
-- seja, todo template sem setor aparece em TODOS os departamentos. Na produção
-- são 11 templates publicados nessa situação (Frete - Pagar, Diretoria,
-- Veiculos - Clubmotors, Pagamento - Comissão...), que poluem a escolha de quem
-- abre processo em Peças, RH ou qualquer outro departamento.
--
-- Departamento é coluna nova, e não "derive do setor", porque existe o caso
-- legítimo do template que vale para o departamento INTEIRO sem ser de um setor
-- específico — hoje isso só era possível deixando o setor vazio, que é
-- justamente o que vaza para os outros departamentos.
--
-- BACKFILL: todo template que já tem setor herda o departamento do setor
-- (setores.departamento_id). Isso resolve a maioria sem intervenção manual.
-- Os que ficarem com departamento NULL precisam ser configurados no editor de
-- fluxo — enquanto estiverem assim, deixam de aparecer na abertura, que é o
-- comportamento pedido pelo funil.

ALTER TABLE nf_template_versoes
  ADD COLUMN departamento_id CHAR(36) NULL
    COMMENT 'nf_lista_itens.id da lista Departamentos - primeiro recorte do funil'
    AFTER filial_id,
  ADD KEY idx_tv_departamento (departamento_id);

-- Herda o departamento do setor já declarado.
UPDATE nf_template_versoes tv
  JOIN setores s ON s.id = tv.setor_id
   SET tv.departamento_id = s.departamento_id
 WHERE tv.setor_id IS NOT NULL
   AND s.departamento_id IS NOT NULL;
