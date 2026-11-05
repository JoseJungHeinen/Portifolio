-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 070 — Assinatura eletrônica pela Clicksign (convive com a Assinafy)
--
-- Decisão de 01/10/2026: opção A — as duas etapas ficam no catálogo. O provedor
-- fica em nf_processo_assinaturas.provedor (coluna que já existia) e o disparo e
-- a sincronização escolhem o cliente por ele. Nada aqui altera dado existente:
-- só colunas novas, uma etapa nova, uma lista nova e chaves de configuração.
--
-- Em Geral → API aparece o grupo `clicksign`; no template, escolher a etapa
-- "Assina ClickSign" monta sozinho os campos dos DOIS signatários.
--
-- Banco local: compras · Banco servidor: Notas (o USE fica a cargo do comando)
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/070_assinatura_clicksign.sql
-- ═══════════════════════════════════════════════════════════════════════════

-- 1. Catálogo de etapas: qual provedor atende a etapa de assinatura.
--    DEFAULT 'assinafy' preserva a etapa "Assinatura de Documento" já em uso.
ALTER TABLE `nf_etapas`
  ADD COLUMN `assinatura_provedor` varchar(20) NOT NULL DEFAULT 'assinafy'
      COMMENT 'assinafy|clicksign - so vale quando assinatura_externa=1'
      AFTER `assinatura_externa`;

-- O nome é contrato: Template::ETAPA_CLICKSIGN_NOME compara por ele.
-- ⚠ `descricao` é varchar(255) — o MySQL de produção recusa texto maior.
INSERT INTO `nf_etapas` (`id`, `nome`, `descricao`, `assinatura_externa`, `assinatura_provedor`, `tipo_aprovador`)
SELECT UUID(), 'Assina ClickSign',
       'Assinatura eletrônica pela Clicksign: o documento anexado vai para duas pessoas (nome, CPF, e-mail e tipo de assinatura). Quem conclui é o retorno da Clicksign, não um usuário. O responsável acompanha e é avisado se houver recusa.',
       1, 'clicksign', 'ambos'
WHERE NOT EXISTS (SELECT 1 FROM (SELECT * FROM `nf_etapas`) x WHERE x.`nome` = 'Assina ClickSign');

-- 2. Dados extras do signatário (a Clicksign pede CPF e o tipo de autenticação).
ALTER TABLE `nf_assinatura_signatarios`
  ADD COLUMN `cpf`              varchar(14) NULL COMMENT 'So digitos' AFTER `email`,
  ADD COLUMN `telefone`         varchar(20) NULL COMMENT 'DDD + numero, so digitos - obrigatorio p/ SMS e WhatsApp' AFTER `cpf`,
  ADD COLUMN `tipo_autenticacao` varchar(30) NULL COMMENT 'Codigo `auth` da Clicksign (email, sms, whatsapp, selfie...)' AFTER `telefone`;

-- 3. Lista "Tipos de assinatura" — fonte do campo de seleção do template.
--    IDs fixos: Template::TIPOS_AUTENTICACAO traduz o ID gravado no processo
--    para o código `auth` da Clicksign. Renomear o texto é seguro; trocar o ID não.
INSERT INTO nf_listas (id, nome, slug, tipo, descricao, ativo, criado_em)
SELECT '00000000-0000-4000-8000-0000000000c5',
       'Tipos de assinatura (Clicksign)',
       'tipos-assinatura-clicksign',
       'personalizada',
       'Forma de autenticação do signatário na Clicksign. Biometria facial e documentoscopia têm custo por uso na conta da Clicksign.',
       1,
       NOW()
WHERE NOT EXISTS (SELECT 1 FROM nf_listas WHERE id = '00000000-0000-4000-8000-0000000000c5');

INSERT INTO nf_lista_itens (id, lista_id, valor, ativo, criado_em)
SELECT * FROM (
    SELECT '00000000-0000-4000-8000-00000000c501' AS id, '00000000-0000-4000-8000-0000000000c5' AS lista_id, 'E-mail' AS valor, 1 AS ativo, NOW() AS criado_em
    UNION ALL SELECT '00000000-0000-4000-8000-00000000c502', '00000000-0000-4000-8000-0000000000c5', 'WhatsApp', 1, NOW()
    UNION ALL SELECT '00000000-0000-4000-8000-00000000c503', '00000000-0000-4000-8000-0000000000c5', 'SMS', 1, NOW()
    UNION ALL SELECT '00000000-0000-4000-8000-00000000c504', '00000000-0000-4000-8000-0000000000c5', 'Selfie', 1, NOW()
    UNION ALL SELECT '00000000-0000-4000-8000-00000000c505', '00000000-0000-4000-8000-0000000000c5', 'Selfie com prova de vida (liveness)', 1, NOW()
    UNION ALL SELECT '00000000-0000-4000-8000-00000000c506', '00000000-0000-4000-8000-0000000000c5', 'Documento oficial com foto', 1, NOW()
    UNION ALL SELECT '00000000-0000-4000-8000-00000000c507', '00000000-0000-4000-8000-0000000000c5', 'Certificado digital ICP-Brasil', 1, NOW()
    UNION ALL SELECT '00000000-0000-4000-8000-00000000c508', '00000000-0000-4000-8000-0000000000c5', 'Assinatura manuscrita', 1, NOW()
    UNION ALL SELECT '00000000-0000-4000-8000-00000000c509', '00000000-0000-4000-8000-0000000000c5', 'PIX', 1, NOW()
    UNION ALL SELECT '00000000-0000-4000-8000-00000000c510', '00000000-0000-4000-8000-0000000000c5', 'Biometria facial (custo por uso)', 1, NOW()
    UNION ALL SELECT '00000000-0000-4000-8000-00000000c511', '00000000-0000-4000-8000-0000000000c5', 'Documentoscopia (custo por uso)', 1, NOW()
) AS novos
WHERE NOT EXISTS (
    SELECT 1 FROM nf_lista_itens WHERE lista_id = '00000000-0000-4000-8000-0000000000c5'
);

-- 4. Configuração do provedor (Geral → API). Valores reais NUNCA no git.
INSERT IGNORE INTO `nf_configuracoes` (`chave`, `valor`, `grupo`) VALUES
  ('clicksign.ativo',         '0',       'clicksign'),
  ('clicksign.ambiente',      'sandbox', 'clicksign'),  -- sandbox | producao
  ('clicksign.access_token',  '',        'clicksign'),  -- segredo - nunca versionar
  ('clicksign.webhook_token', '',        'clicksign'),  -- segredo exigido na URL do webhook
  ('clicksign.prazo_dias',    '7',       'clicksign'),
  ('clicksign.allow_ips',     '',        'clicksign'),
  ('clicksign.timeout_seg',   '60',      'clicksign');
