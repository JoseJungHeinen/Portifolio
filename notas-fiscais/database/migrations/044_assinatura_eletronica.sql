-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 044 — Assinatura eletrônica no fluxo (provedor: Assinafy)
--
-- Desenho completo e análise da API em
-- docs/IDEIA-ASSINATURA-ELETRONICA-ASSINAFY.md.
--
-- MODELO: `tem_assinatura` no template é o MESMO padrão de `tem_financeiro` —
-- uma trava de configuração, não de runtime. Ligar o toggle no editor já cria
-- a etapa, o anexo obrigatório e os campos do signatário; publicar sem eles é
-- bloqueado (Template::validarAssinatura). O fluxo não pode quebrar em produção
-- por falta de dado que o template deveria ter exigido na abertura.
--
-- A etapa de assinatura é o TERCEIRO comportamento especial do catálogo
-- nf_etapas, ao lado de `encerra_automaticamente` (Arquivo) e
-- `somente_observacao`: a etapa é atingida, dispara o envio e fica parada até
-- um ator DE FORA responder. Mesmo formato do RPA em public/api/rpa.php.
--
-- Banco local: compras · Banco servidor: Notas (o USE fica a cargo do comando)
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/044_assinatura_eletronica.sql
-- ═══════════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────
-- 1. Catálogo de etapas — comportamento "assinatura externa"
-- ─────────────────────────────────────────

ALTER TABLE `nf_etapas`
  ADD COLUMN `assinatura_externa` tinyint(1) NOT NULL DEFAULT 0
      COMMENT 'A etapa dispara assinatura eletronica e aguarda retorno externo - ninguem aprova pela tela'
      AFTER `somente_observacao`;

-- Etapa padrão. O nome é contrato: Template::ETAPA_ASSINATURA_NOME compara por
-- ele, igual "Aprovação Financeiro". Não renomear sem mexer na constante.
-- ⚠ `descricao` é varchar(255). O texto abaixo tem 241 caracteres e precisa
-- caber: o MySQL local não é estrito e TRUNCA em silêncio, enquanto o de
-- produção recusa com "Data too long" e aborta a migração no meio. Foi o que
-- aconteceu na primeira tentativa do deploy 14.
INSERT INTO `nf_etapas` (`id`, `nome`, `descricao`, `assinatura_externa`, `tipo_aprovador`)
SELECT UUID(), 'Assinatura de Documento',
       'Etapa de assinatura eletrônica: o documento anexado vai ao provedor e o processo aguarda os signatários. Quem conclui a etapa é o retorno da assinatura, não um usuário. O responsável acompanha, reenvia convites e é avisado se alguém recusar.',
       1, 'ambos'
WHERE NOT EXISTS (SELECT 1 FROM (SELECT * FROM `nf_etapas`) x WHERE x.`nome` = 'Assinatura de Documento');

-- ─────────────────────────────────────────
-- 2. Template — a trava
-- ─────────────────────────────────────────

ALTER TABLE `nf_template_versoes`
  ADD COLUMN `tem_assinatura` tinyint(1) NOT NULL DEFAULT 0
      COMMENT 'Fluxo exige assinatura eletronica - espelha tem_financeiro'
      AFTER `tem_financeiro`,
  ADD COLUMN `assinatura_config` text DEFAULT NULL
      COMMENT 'JSON: origem dos signatarios, prazo, metodo, signatarios fixos';

-- ─────────────────────────────────────────
-- 3. Etapa do processo — novo estado de espera
-- ─────────────────────────────────────────

-- 'aguardando_assinatura' é distinto de 'pendente' de propósito: a etapa NÃO
-- está esperando um usuário do Notas agir, e não pode aparecer em Minhas
-- Tarefas como se estivesse. Quem olha a fila precisa ver a diferença entre
-- "parado esperando você" e "parado esperando o mundo lá fora".
ALTER TABLE `nf_processo_etapas`
  MODIFY `status` enum('pendente','aprovado','devolvido','pulado','aguardando_assinatura')
      NOT NULL DEFAULT 'pendente';

-- ─────────────────────────────────────────
-- 4. Assinaturas do processo
-- ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `nf_processo_assinaturas` (
  `id`                   char(36)     NOT NULL,
  `processo_id`          char(36)     NOT NULL,
  `numero_etapa`         tinyint(3) unsigned NOT NULL,
  `anexo_id`             char(36)         NULL
                         COMMENT 'FK suave -> nf_processo_anexos.id - o documento ENVIADO',
  `provedor`             varchar(20)  NOT NULL DEFAULT 'assinafy',
  `documento_externo_id` varchar(64)      NULL COMMENT 'document.id no provedor',
  `assignment_id`        varchar(64)      NULL,

  -- 'a_enviar' é gravado DENTRO da transacao de aprovar(); a chamada HTTP sai
  -- depois do commit (worker ou pos-commit). Chamada de rede dentro de
  -- transacao segura o banco pelo tempo do provedor e pode dar rollback com o
  -- documento ja enviado - duplicando cobranca e convite.
  `status`               varchar(30)  NOT NULL DEFAULT 'a_enviar'
                         COMMENT 'a_enviar|enviando|pending_signature|certificated|rejected_by_signer|expired|failed|cancelado',

  `anexo_assinado_id`    char(36)         NULL
                         COMMENT 'FK suave -> nf_processo_anexos.id - o PDF que VOLTOU assinado',

  -- Hash da pagina de certificacao. E o handle PUBLICO de verificacao:
  -- assinafy.com.br/verificador/<hash> e GET /documents/<hash>/verify.
  -- Guardar permite a tela do processo provar a assinatura sem depender de nos.
  `hash_verificacao`     varchar(64)      NULL,
  `codigo_acordo`        varchar(64)      NULL COMMENT 'Codigo do Acordo impresso no certificado',
  -- Quem aprovou a etapa anterior e, com isso, mandou o documento assinar.
  -- E SEMPRE para ele que o processo volta - em recusa, cancelamento, falha ou
  -- prazo vencido. Gravado no ato do envio em vez de deduzido depois: a etapa
  -- anterior pode ser reatribuida ou reaprovada, e ai `atuado_por` ja nao
  -- aponta para quem de fato mandou assinar.
  `enviado_por`          char(36)         NULL COMMENT 'FK suave -> usuarios.id',

  `tentativas`           tinyint(3) unsigned NOT NULL DEFAULT 0,
  `erro`                 text             NULL,
  `motivo_recusa`        text             NULL,
  `expira_em`            datetime         NULL,
  `criado_em`            timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `enviado_em`           datetime         NULL,
  `sincronizado_em`      datetime         NULL,
  `concluido_em`         datetime         NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pa_processo` (`processo_id`),
  KEY `idx_pa_status`   (`status`),
  UNIQUE KEY `uq_pa_externo` (`documento_externo_id`),
  CONSTRAINT `fk_pa_processo` FOREIGN KEY (`processo_id`)
    REFERENCES `nf_processos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Envios de assinatura eletronica por etapa de processo';

-- Um signatário por linha — é o que permite a tela dizer "2 de 3 assinaram,
-- falta o Fulano" em vez de só "aguardando".
CREATE TABLE IF NOT EXISTS `nf_assinatura_signatarios` (
  `id`             char(36)     NOT NULL,
  `assinatura_id`  char(36)     NOT NULL,
  `nome`           varchar(150) NOT NULL,
  `email`          varchar(150)     NULL,
  `whatsapp`       varchar(20)      NULL COMMENT 'E.164',

  -- Papel e natureza sao NOSSOS, nao do provedor.
  --
  -- A Assinafy so distingue "assina" de "recebe copia" (copy_receivers); papel
  -- de verdade (TemplateRole) so existe nos templates DELES, com campos
  -- posicionados numa pagina fixa - inutil aqui, onde o documento e um anexo
  -- que o solicitante sobe. Entao o rotulo vive aqui, alimenta tela, historico
  -- e relatorio, e NAO aparece no certificado emitido.
  `papel`          varchar(60)  NOT NULL
                   COMMENT 'Rotulo livre: Contratante, Testemunha, Avalista...',
  `natureza`       enum('assina','copia') NOT NULL DEFAULT 'assina'
                   COMMENT 'assina -> signers[] | copia -> copy_receivers[]',
  `signer_id_ext`  varchar(64)      NULL COMMENT 'signer.id no provedor',
  `passo`          tinyint(3) unsigned NOT NULL DEFAULT 1
                   COMMENT 'Vira o `step` do provedor: mesmo passo assina em paralelo',
  `status`         varchar(20)  NOT NULL DEFAULT 'pendente'
                   COMMENT 'pendente|notificado|visualizado|assinado|recusado',
  `notificado_em`  datetime         NULL,
  `visualizado_em` datetime         NULL COMMENT 'signer_viewed_document - base para cobranca',
  `assinado_em`    datetime         NULL,
  -- Espelha notification_history do provedor: responde "o e-mail chegou?"
  `entrega_status` varchar(20)      NULL COMMENT 'sent|failed',
  `entrega_erro`   varchar(255)     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_as_assinatura` (`assinatura_id`),
  CONSTRAINT `fk_as_assinatura` FOREIGN KEY (`assinatura_id`)
    REFERENCES `nf_processo_assinaturas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Signatarios de cada envio de assinatura';

-- Cache de signatários já criados no provedor, por e-mail. Sem isto o workspace
-- do provedor enche de duplicados: cada processo criaria o mesmo fornecedor de
-- novo.
CREATE TABLE IF NOT EXISTS `nf_assinatura_signer_cache` (
  `email`         varchar(150) NOT NULL,
  `provedor`      varchar(20)  NOT NULL DEFAULT 'assinafy',
  `signer_id_ext` varchar(64)  NOT NULL,
  `nome`          varchar(150)     NULL,
  `criado_em`     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`email`, `provedor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='De-duplicacao de signatarios no provedor';

-- ─────────────────────────────────────────
-- 5. Configuração do provedor (Geral → API)
-- ─────────────────────────────────────────
-- Valores reais NUNCA no git — preenchidos pela tela. Aqui só as chaves.

INSERT IGNORE INTO `nf_configuracoes` (`chave`, `valor`, `grupo`) VALUES
  ('assinafy.ativo',         '0',       'assinafy'),  -- integração ligada
  ('assinafy.ambiente',      'sandbox', 'assinafy'),  -- sandbox | producao
  ('assinafy.api_key',       '',        'assinafy'),  -- X-Api-Key — nunca versionar
  ('assinafy.account_id',    '',        'assinafy'),  -- workspace no provedor
  ('assinafy.webhook_token', '',        'assinafy'),  -- segredo exigido na URL do webhook
  ('assinafy.prazo_dias',    '7',       'assinafy'),  -- prazo padrão de assinatura
  ('assinafy.allow_ips',     '',        'assinafy'),  -- allowlist do webhook (vazio = qualquer IP)
  ('assinafy.timeout_seg',   '60',      'assinafy');  -- timeout HTTP (upload é a chamada mais lenta)
