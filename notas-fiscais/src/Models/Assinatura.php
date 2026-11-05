<?php
// @versao Asg_01 V_02_01/10/2026
declare(strict_types=1);

// Dependências explícitas: o projeto não tem autoload, e este model é chamado
// de contextos diferentes (tela do processo, fila de tarefas, CLI, webhook).
// Sem estes requires, `Template` e `Process` só existem por acaso — conforme
// quem carregou o quê antes. Foi exatamente o que quebrou a primeira aprovação
// real: "Class Template not found" no meio da transação de aprovar.
require_once __DIR__ . '/../Helpers/AssinafyClient.php';
require_once __DIR__ . '/../Helpers/ClicksignClient.php';
require_once __DIR__ . '/Template.php';
require_once __DIR__ . '/Process.php';

/**
 * Assinatura eletrônica de um documento do processo.
 *
 * Desenho completo em docs/IDEIA-ASSINATURA-ELETRONICA-ASSINAFY.md.
 *
 * PROVEDORES (desde 01/10/2026): Assinafy e Clicksign convivem. O provedor é
 * definido pela ETAPA de catálogo do template (nf_etapas.assinatura_provedor →
 * assinatura_config.provedor) e gravado em nf_processo_assinaturas.provedor; o
 * disparo e a sincronização escolhem o cliente por essa coluna. O ciclo abaixo
 * vale para os dois. Diferenças da Clicksign: o signatário leva CPF, telefone e
 * tipo de autenticação; a conclusão é o status `closed` do ENVELOPE (a API não
 * dá status por signatário); não há hash/acordo de verificação.
 *
 * CICLO
 *   1. O processo chega numa etapa de assinatura (nf_etapas.assinatura_externa=1).
 *   2. `registrarPendente()` grava a assinatura como 'a_enviar' DENTRO da
 *      transação de aprovação, resolvendo os signatários dos dados do processo.
 *   3. `dispararPendentes()` roda DEPOIS do commit (e no cron) e faz o HTTP.
 *   4. `sincronizar()` acompanha; o webhook só antecipa essa mesma checagem.
 *   5. Assinado → baixa o PDF, salva como anexo e aprova a etapa.
 *      Recusado/expirado → devolve o processo.
 *
 * POR QUE O HTTP NÃO ENTRA NA TRANSAÇÃO
 *   `Process::aprovar()` roda tudo em beginTransaction(). Uma chamada ao
 *   provedor lá dentro seguraria o banco pelo tempo da rede e, num rollback,
 *   deixaria o documento já enviado — cobrança feita e convite na caixa do
 *   signatário, sem nada no banco. Gravar 'a_enviar' e disparar depois também
 *   dá o retry de graça: o que não saiu continua na fila.
 */
class Assinatura
{
    /** Status que ainda esperam alguma coisa acontecer. */
    public const ABERTOS = ['a_enviar', 'enviando', 'pending_signature'];

    // ─────────────────────────────────────────────────────────────────────
    // 1. Registro (dentro da transação de aprovação)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Cria a assinatura pendente e seus signatários.
     *
     * Roda DENTRO da transação de quem aprovou a etapa anterior. Só grava —
     * nenhuma chamada de rede aqui.
     *
     * @return string|null id da assinatura, ou null se não há o que assinar
     * @throws RuntimeException quando falta dado obrigatório (aborta a aprovação)
     */
    public static function registrarPendente(
        PDO $pdo,
        string $processoId,
        int $numeroEtapa,
        string $enviadoPor = ''
    ): ?string {
        // Já existe uma assinatura aberta para esta etapa? Reenvio de fluxo
        // (devolução e nova aprovação) não pode disparar duas cobranças.
        $stmt = $pdo->prepare(
            "SELECT id FROM nf_processo_assinaturas
             WHERE processo_id = ? AND numero_etapa = ?
               AND status NOT IN ('cancelado','rejected_by_signer','expired','failed')
             LIMIT 1"
        );
        $stmt->execute([$processoId, $numeroEtapa]);
        if ($existente = $stmt->fetchColumn()) {
            return (string) $existente;
        }

        $cfg = self::configDoProcesso($pdo, $processoId);
        if ($cfg === null) {
            return null;   // template sem assinatura — nada a fazer
        }

        $anexo = self::anexoParaAssinar($pdo, $processoId);
        if ($anexo === null) {
            throw new \RuntimeException(
                'O documento "' . Template::ANEXO_ASSINATURA_NOME . '" não está anexado ao processo. '
                . 'Anexe-o antes de aprovar esta etapa.'
            );
        }

        $signatarios = self::resolverSignatarios($pdo, $processoId, $cfg);
        if (empty($signatarios)) {
            throw new \RuntimeException(
                'Nenhum signatário foi informado no processo. '
                . 'Complete os dados de nome e e-mail antes de aprovar esta etapa.'
            );
        }

        $id       = Process::uuid_v4();
        $prazo    = (int) ($cfg['prazo_dias'] ?? 0);
        $provedor = Template::provedorValido($cfg['provedor'] ?? 'assinafy');

        $pdo->prepare(
            "INSERT INTO nf_processo_assinaturas
                (id, processo_id, numero_etapa, anexo_id, provedor, status,
                 enviado_por, expira_em, criado_em)
             VALUES (?, ?, ?, ?, ?, 'a_enviar', ?, ?, NOW())"
        )->execute([
            $id, $processoId, $numeroEtapa, $anexo['id'], $provedor,
            $enviadoPor !== '' ? $enviadoPor : null,
            $prazo > 0 ? date('Y-m-d H:i:s', time() + $prazo * 86400) : null,
        ]);

        // A Assinafy mantém o INSERT de sempre (não depende das colunas da
        // migration 070); só a Clicksign grava CPF, telefone e tipo.
        $ins = $pdo->prepare(
            "INSERT INTO nf_assinatura_signatarios
                (id, assinatura_id, nome, email, papel, natureza, passo, status)
             VALUES (?, ?, ?, ?, ?, ?, 1, 'pendente')"
        );
        $insClk = $pdo->prepare(
            "INSERT INTO nf_assinatura_signatarios
                (id, assinatura_id, nome, email, cpf, telefone, tipo_autenticacao,
                 papel, natureza, passo, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'pendente')"
        );
        foreach ($signatarios as $s) {
            if ($provedor === 'clicksign') {
                $insClk->execute([
                    Process::uuid_v4(), $id, $s['nome'], $s['email'],
                    $s['cpf'], $s['telefone'] !== '' ? $s['telefone'] : null, $s['tipo_autenticacao'],
                    $s['papel'], $s['natureza'],
                ]);
                continue;
            }
            $ins->execute([
                Process::uuid_v4(), $id,
                $s['nome'], $s['email'], $s['papel'], $s['natureza'],
            ]);
        }

        return $id;
    }

    /**
     * Config de assinatura do template do processo, ou null se não tem.
     * @return array|null
     */
    private static function configDoProcesso(PDO $pdo, string $processoId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT tv.tem_assinatura, tv.assinatura_config
             FROM nf_processos p
             JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
             WHERE p.id = ? LIMIT 1"
        );
        $stmt->execute([$processoId]);
        $row = $stmt->fetch();
        if (!$row || (int) $row['tem_assinatura'] !== 1) {
            return null;
        }
        return Template::configAssinatura([
            'assinatura_config' => json_decode((string) $row['assinatura_config'], true) ?: [],
        ]);
    }

    /** O anexo que vai para o provedor — o mais recente com o nome do contrato. */
    private static function anexoParaAssinar(PDO $pdo, string $processoId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT id, caminho, nome_original, mime_type
             FROM nf_processo_anexos
             WHERE processo_id = ? AND tipo_anexo_nome = ? AND deletado_em IS NULL
             ORDER BY enviado_em DESC LIMIT 1"
        );
        $stmt->execute([$processoId, Template::ANEXO_ASSINATURA_NOME]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Lê nome e e-mail de cada papel em `dados_formulario`.
     *
     * Os rótulos vêm de Template::camposSignatario() — os mesmos que a trava do
     * template criou e tornou obrigatórios. Se um papel não tiver e-mail aqui,
     * o template não deveria ter publicado; ainda assim conferimos, porque
     * processos antigos podem ter nascido antes de o papel entrar na config.
     *
     * @return array<int,array{papel:string,natureza:string,nome:string,email:string}>
     */
    private static function resolverSignatarios(PDO $pdo, string $processoId, array $cfg): array
    {
        $stmt = $pdo->prepare("SELECT dados_formulario FROM nf_processos WHERE id = ? LIMIT 1");
        $stmt->execute([$processoId]);
        $dados = json_decode((string) $stmt->fetchColumn(), true) ?: [];

        $provedor = Template::provedorValido($cfg['provedor'] ?? 'assinafy');

        $out = [];
        foreach ($cfg['signatarios'] as $s) {
            $rot   = Template::camposSignatario($s['papel'], $provedor);
            $nome  = trim((string) ($dados[$rot['nome']]  ?? ''));
            $email = trim((string) ($dados[$rot['email']] ?? ''));

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException(
                    'O e-mail do papel "' . $s['papel'] . '" está vazio ou inválido no processo ('
                    . ($email === '' ? 'em branco' : $email) . '). '
                    . 'Corrija em "Completar dados" antes de aprovar esta etapa.'
                );
            }
            $item = [
                'papel'    => $s['papel'],
                'natureza' => $s['natureza'],
                'nome'     => $nome !== '' ? $nome : $s['papel'],
                'email'    => $email,
            ];

            if ($provedor === 'clicksign') {
                $item = array_merge($item, self::dadosClicksign($s['papel'], $rot, $dados, $nome));
            }
            $out[] = $item;
        }

        // A mesma pessoa nos dois papéis não faz sentido na Clicksign: seriam
        // dois signatários com o mesmo CPF, e o envelope pediria a assinatura em dobro.
        if ($provedor === 'clicksign') {
            $cpfs = array_column($out, 'cpf');
            if (count($cpfs) !== count(array_unique($cpfs))) {
                throw new \RuntimeException(
                    'Os dois signatários têm o mesmo CPF. Corrija em "Completar dados" antes de aprovar esta etapa.'
                );
            }
        }
        return $out;
    }

    /**
     * Valida e normaliza o que a Clicksign exige de UM signatário.
     *
     * Roda no registro (dentro da transação de aprovar): um dado errado aqui
     * aborta a aprovação com mensagem clara, em vez de o envelope falhar pela
     * metade na hora do disparo — com o documento já criado no provedor.
     *
     * @return array{cpf:string,telefone:string,tipo_autenticacao:string}
     */
    private static function dadosClicksign(string $papel, array $rot, array $dados, string $nome): array
    {
        $onde = 'Corrija em "Completar dados" antes de aprovar esta etapa.';

        if (!ClicksignClient::nomeValido($nome)) {
            throw new \RuntimeException(
                'O nome do papel "' . $papel . '" precisa ter nome e sobrenome — a Clicksign recusa nome com uma palavra só. ' . $onde
            );
        }

        $cpf = ClicksignClient::formatarCpf((string) ($dados[$rot['cpf']] ?? ''));
        if ($cpf === null) {
            throw new \RuntimeException('O CPF do papel "' . $papel . '" está vazio ou inválido. ' . $onde);
        }

        $tipoId = trim((string) ($dados[$rot['tipo']] ?? ''));
        if (!isset(Template::TIPOS_AUTENTICACAO[$tipoId])) {
            throw new \RuntimeException('O tipo de assinatura do papel "' . $papel . '" não foi escolhido ou é inválido. ' . $onde);
        }
        [$auth, $exigeTel] = Template::TIPOS_AUTENTICACAO[$tipoId];

        $tel = preg_replace('/\D/', '', (string) ($dados[$rot['telefone']] ?? ''));
        if (strlen($tel) > 11 && strpos($tel, '55') === 0) {
            $tel = substr($tel, 2);   // tolera +55
        }
        $telOk = strlen($tel) === 10 || strlen($tel) === 11;
        if ($exigeTel && !$telOk) {
            throw new \RuntimeException(
                'O tipo de assinatura "' . $auth . '" do papel "' . $papel . '" exige telefone com DDD (10 ou 11 dígitos). ' . $onde
            );
        }

        return [
            'cpf'               => preg_replace('/\D/', '', $cpf),
            'telefone'          => $telOk ? $tel : '',
            'tipo_autenticacao' => $auth,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. Disparo (fora da transação — pós-commit e cron)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Envia as assinaturas em 'a_enviar'.
     *
     * Idempotente e tolerante: qualquer falha marca a linha e segue para a
     * próxima. Chamado logo após a aprovação (para o usuário ver o efeito na
     * hora) e pelo cron (para o que falhou).
     *
     * @return array{enviadas:int, falhas:int, mensagens:array<int,string>}
     */
    public static function dispararPendentes(int $limite = 20, ?string $apenasId = null): array
    {
        $pdo = Database::getInstance();
        $out = ['enviadas' => 0, 'falhas' => 0, 'mensagens' => []];

        // Um cliente por provedor, criado sob demanda. Provedor desligado ou
        // sem credencial NÃO consome tentativa: a linha fica em 'a_enviar' e sai
        // quando a integração for ligada.
        $clientes = [];
        $avisados = [];
        $clienteDe = static function (string $provedor) use (&$clientes) {
            return $clientes[$provedor] ??= ($provedor === 'clicksign'
                ? ClicksignClient::fromSettings()
                : AssinafyClient::fromSettings());
        };
        if (!$clienteDe('assinafy')->ativo() && !$clienteDe('clicksign')->ativo()) {
            $out['mensagens'][] = 'Integração de assinatura desligada ou sem credencial (Geral → API).';
            return $out;
        }

        if ($apenasId !== null) {
            $stmt = $pdo->prepare("SELECT * FROM nf_processo_assinaturas WHERE id = ? AND status = 'a_enviar'");
            $stmt->execute([$apenasId]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT * FROM nf_processo_assinaturas
                 WHERE status = 'a_enviar' AND tentativas < 5
                 ORDER BY criado_em ASC LIMIT " . max(1, $limite)
            );
            $stmt->execute();
        }

        foreach ($stmt->fetchAll() as $a) {
            $provedor = Template::provedorValido($a['provedor'] ?? 'assinafy');
            $cli      = $clienteDe($provedor);
            if (!$cli->ativo()) {
                if (!isset($avisados[$provedor])) {
                    $avisados[$provedor] = true;
                    $out['mensagens'][] = 'Integração ' . ucfirst($provedor)
                        . ' desligada ou sem credencial (Geral → API) — envios dela aguardam.';
                }
                continue;
            }

            // Trava otimista: marca 'enviando' antes de sair para a rede, para
            // que uma segunda execução (cron sobrepondo o pós-commit) não
            // dispare o mesmo documento duas vezes.
            $trava = $pdo->prepare(
                "UPDATE nf_processo_assinaturas
                 SET status = 'enviando', tentativas = tentativas + 1
                 WHERE id = ? AND status = 'a_enviar'"
            );
            $trava->execute([$a['id']]);
            if ($trava->rowCount() === 0) {
                continue;   // outro processo pegou primeiro
            }

            try {
                self::enviar($pdo, $cli, $a);
                $out['enviadas']++;
            } catch (\Throwable $e) {
                $out['falhas']++;
                $out['mensagens'][] = $a['id'] . ': ' . $e->getMessage();
                $pdo->prepare(
                    "UPDATE nf_processo_assinaturas SET status = 'a_enviar', erro = ? WHERE id = ?"
                )->execute([mb_substr($e->getMessage(), 0, 1000, 'UTF-8'), $a['id']]);
            }
        }

        return $out;
    }

    /** Upload + signatários + assignment. Lança em qualquer falha. */
    private static function enviar(PDO $pdo, $cli, array $a): void
    {
        if ($cli instanceof ClicksignClient) {
            self::enviarClicksign($pdo, $cli, $a);
            return;
        }
        // 1. O arquivo
        $anexo = $pdo->prepare("SELECT * FROM nf_processo_anexos WHERE id = ? LIMIT 1");
        $anexo->execute([$a['anexo_id']]);
        $arq = $anexo->fetch();
        if (!$arq || !is_file($arq['caminho'])) {
            throw new \RuntimeException('Arquivo do anexo não encontrado no storage.');
        }

        // 2. Sobe o documento
        $up = $cli->subirDocumento($arq['caminho'], $arq['nome_original']);
        if (!$up['ok'] || empty($up['data']['id'])) {
            throw new \RuntimeException('Upload falhou — ' . ($up['erro'] ?? 'sem id'));
        }
        $docId = (string) $up['data']['id'];

        $pdo->prepare("UPDATE nf_processo_assinaturas SET documento_externo_id = ? WHERE id = ?")
            ->execute([$docId, $a['id']]);

        // 3. Signatários — reusa por e-mail (senão o workspace enche de duplicados)
        $sigs = $pdo->prepare("SELECT * FROM nf_assinatura_signatarios WHERE assinatura_id = ?");
        $sigs->execute([$a['id']]);
        $linhas = $sigs->fetchAll();

        $assinam = [];
        $copias  = [];
        foreach ($linhas as $s) {
            $r = $cli->acharOuCriarSignatario((string) $s['nome'], (string) $s['email']);
            if (!$r['ok'] || empty($r['data']['id'])) {
                throw new \RuntimeException('Signatário "' . $s['email'] . '" — ' . ($r['erro'] ?? 'sem id'));
            }
            $extId = (string) $r['data']['id'];
            $pdo->prepare("UPDATE nf_assinatura_signatarios SET signer_id_ext = ? WHERE id = ?")
                ->execute([$extId, $s['id']]);

            if ($s['natureza'] === 'copia') { $copias[]  = $extId; }
            else                            { $assinam[] = $extId; }

            self::cachearSigner($pdo, (string) $s['email'], $extId, (string) $s['nome']);
        }

        // Papéis diferentes com o MESMO e-mail resolvem para o mesmo signatário
        // no provedor — comum em teste ("põe meu e-mail nos quatro") e real
        // quando a mesma pessoa assina em dois papéis. Mandar o id repetido no
        // assignment é pedir para o provedor recusar ou cobrar duas vezes pela
        // mesma assinatura. Uma pessoa assina uma vez; os papéis continuam
        // registrados do nosso lado, que é onde eles significam algo.
        $assinam = array_values(array_unique($assinam));
        $copias  = array_values(array_unique($copias));

        // Quem assina não precisa de cópia: já recebe o documento pronto.
        $copias = array_values(array_diff($copias, $assinam));

        if (empty($assinam)) {
            throw new \RuntimeException('Nenhum signatário com natureza "assina".');
        }

        // 4. Pede as assinaturas
        $proc = $pdo->prepare("SELECT codigo FROM nf_processos WHERE id = ? LIMIT 1");
        $proc->execute([$a['processo_id']]);
        $codigo = (string) ($proc->fetchColumn() ?: '');

        $prazoDias = 0;
        if (!empty($a['expira_em'])) {
            $prazoDias = max(1, (int) ceil((strtotime((string) $a['expira_em']) - time()) / 86400));
        }

        $ass = $cli->pedirAssinaturas(
            $docId, $assinam, $copias,
            'Documento do processo ' . $codigo . ' — Empresa Exemplo.',
            $prazoDias
        );
        if (!$ass['ok'] || empty($ass['data']['id'])) {
            throw new \RuntimeException('Assignment falhou — ' . ($ass['erro'] ?? 'sem id'));
        }

        $pdo->prepare(
            "UPDATE nf_processo_assinaturas
             SET assignment_id = ?, status = 'pending_signature', enviado_em = NOW(),
                 sincronizado_em = NOW(), erro = NULL
             WHERE id = ?"
        )->execute([(string) $ass['data']['id'], $a['id']]);

        self::aplicarSignersDoRetorno($pdo, $a['id'], $ass['data']['signers'] ?? []);

        $sistema = self::usuarioSistema($pdo);
        Process::registrarHistorico(
            (string) $a['processo_id'], 'assinatura_enviada',
            $sistema['id'], $sistema['nome'],
            'Documento enviado para assinatura eletrônica de ' . count($assinam) . ' signatário(s)'
            . (count($copias) > 0 ? ' e ' . count($copias) . ' em cópia' : '') . '.',
            (int) $a['numero_etapa']
        );
    }

    /**
     * Clicksign: envelope → documento → signatários (+ requisitos) → ativa → notifica.
     *
     * Lança em qualquer falha ANTES de ativar o envelope — até lá ele é só um
     * rascunho, sem convite nenhum, e repetir o disparo é inofensivo. Depois de
     * ativado, uma falha no aviso (`notificar`) NÃO é tratada como falha de
     * envio: o envelope já está rodando, e repetir criaria um segundo, com
     * convite em dobro para as mesmas pessoas.
     */
    private static function enviarClicksign(PDO $pdo, ClicksignClient $cli, array $a): void
    {
        $anexo = $pdo->prepare("SELECT * FROM nf_processo_anexos WHERE id = ? LIMIT 1");
        $anexo->execute([$a['anexo_id']]);
        $arq = $anexo->fetch();
        if (!$arq || !is_file($arq['caminho'])) {
            throw new \RuntimeException('Arquivo do anexo não encontrado no storage.');
        }

        $sigs = $pdo->prepare("SELECT * FROM nf_assinatura_signatarios WHERE assinatura_id = ? AND natureza = 'assina' ORDER BY papel");
        $sigs->execute([$a['id']]);
        $linhas = $sigs->fetchAll();
        if (empty($linhas)) {
            throw new \RuntimeException('Nenhum signatário com natureza "assina".');
        }

        $proc = $pdo->prepare("SELECT codigo FROM nf_processos WHERE id = ? LIMIT 1");
        $proc->execute([$a['processo_id']]);
        $codigo = (string) ($proc->fetchColumn() ?: '');

        // A Clicksign recusa deadline além de 90 dias.
        $deadline = null;
        if (!empty($a['expira_em'])) {
            $ts = min(strtotime((string) $a['expira_em']), time() + 90 * 86400);
            if ($ts > time()) { $deadline = gmdate('Y-m-d\TH:i:s\Z', $ts); }
        }

        $env = $cli->criarEnvelope('Processo ' . $codigo . ' — Empresa Exemplo', $deadline);
        if (!$env['ok'] || empty($env['data']['id'])) {
            throw new \RuntimeException('Envelope falhou — ' . ($env['erro'] ?? 'sem id'));
        }
        $envId = (string) $env['data']['id'];
        $pdo->prepare("UPDATE nf_processo_assinaturas SET documento_externo_id = ? WHERE id = ?")
            ->execute([$envId, $a['id']]);

        $nomeArq = trim((string) preg_replace('/[\\\\\/:*?"<>|]+/', '-', (string) $arq['nome_original']));
        if ($nomeArq === '') { $nomeArq = 'documento'; }
        if (!preg_match('/\.pdf$/i', $nomeArq)) { $nomeArq .= '.pdf'; }

        $doc = $cli->adicionarDocumento($envId, mb_substr($nomeArq, 0, 120, 'UTF-8'), (string) $arq['caminho']);
        if (!$doc['ok'] || empty($doc['data']['id'])) {
            throw new \RuntimeException('Upload falhou — ' . ($doc['erro'] ?? 'sem id'));
        }
        $docId = (string) $doc['data']['id'];

        foreach ($linhas as $s) {
            $auth = (string) ($s['tipo_autenticacao'] ?: 'email');
            $r = $cli->adicionarSignatario($envId, [
                'nome' => $s['nome'], 'email' => $s['email'], 'cpf' => $s['cpf'],
                'telefone' => $s['telefone'], 'tipo_autenticacao' => $auth,
            ]);
            if (!$r['ok'] || empty($r['data']['id'])) {
                throw new \RuntimeException('Signatário "' . $s['email'] . '" — ' . ($r['erro'] ?? 'sem id'));
            }
            $signerId = (string) $r['data']['id'];
            $pdo->prepare("UPDATE nf_assinatura_signatarios SET signer_id_ext = ? WHERE id = ?")
                ->execute([$signerId, $s['id']]);

            $req = $cli->adicionarRequisitos($envId, $docId, $signerId, $auth);
            if (!$req['ok']) {
                throw new \RuntimeException('Requisitos de "' . $s['email'] . '" — ' . ($req['erro'] ?? 'falhou'));
            }
        }

        $ativ = $cli->ativarEnvelope($envId);
        if (!$ativ['ok']) {
            throw new \RuntimeException('Ativação falhou — ' . ($ativ['erro'] ?? ''));
        }

        $avisoErro = null;
        $not = $cli->notificar($envId);
        if (!$not['ok']) {
            $avisoErro = 'Envelope ativo, mas o aviso aos signatários falhou: ' . ($not['erro'] ?? '');
        }

        $pdo->prepare(
            "UPDATE nf_processo_assinaturas
             SET assignment_id = ?, status = 'pending_signature', enviado_em = NOW(),
                 sincronizado_em = NOW(), erro = ?
             WHERE id = ?"
        )->execute([$docId, $avisoErro !== null ? mb_substr($avisoErro, 0, 1000, 'UTF-8') : null, $a['id']]);

        $pdo->prepare(
            "UPDATE nf_assinatura_signatarios SET status = 'notificado', notificado_em = NOW()
             WHERE assinatura_id = ? AND natureza = 'assina'"
        )->execute([$a['id']]);

        $sistema = self::usuarioSistema($pdo);
        Process::registrarHistorico(
            (string) $a['processo_id'], 'assinatura_enviada',
            $sistema['id'], $sistema['nome'],
            'Documento enviado para assinatura eletrônica (Clicksign) de ' . count($linhas) . ' signatário(s).',
            (int) $a['numero_etapa']
        );
    }

    private static function cachearSigner(PDO $pdo, string $email, string $extId, string $nome): void
    {
        $pdo->prepare(
            "INSERT INTO nf_assinatura_signer_cache (email, provedor, signer_id_ext, nome)
             VALUES (?, 'assinafy', ?, ?)
             ON DUPLICATE KEY UPDATE signer_id_ext = VALUES(signer_id_ext), nome = VALUES(nome)"
        )->execute([mb_strtolower($email, 'UTF-8'), $extId, $nome]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. Sincronização (worker e webhook chamam o mesmo caminho)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Reconsulta o provedor e aplica o que mudou.
     *
     * É a ÚNICA fonte de verdade. O webhook nunca decide nada: ele só informa
     * qual documento olhar, e esta função confere com a nossa própria chave.
     * A API não assina o webhook (sem HMAC, levantado em 17/08/2026), então
     * confiar no corpo dele seria aceitar ordem de quem quisesse mandar.
     *
     * @return string status resultante
     */
    public static function sincronizar(string $assinaturaId): string
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM nf_processo_assinaturas WHERE id = ? LIMIT 1");
        $stmt->execute([$assinaturaId]);
        $a = $stmt->fetch();
        if (!$a)                          { return 'nao_encontrada'; }
        if (empty($a['documento_externo_id'])) { return (string) $a['status']; }

        if (Template::provedorValido($a['provedor'] ?? 'assinafy') === 'clicksign') {
            return self::sincronizarClicksign($pdo, $a);
        }

        $cli = AssinafyClient::fromSettings();
        if (!$cli->configurado()) { return (string) $a['status']; }

        $r = $cli->documento((string) $a['documento_externo_id']);
        if (!$r['ok']) {
            $pdo->prepare("UPDATE nf_processo_assinaturas SET sincronizado_em = NOW(), erro = ? WHERE id = ?")
                ->execute([mb_substr((string) $r['erro'], 0, 1000, 'UTF-8'), $a['id']]);
            return (string) $a['status'];
        }

        $doc      = $r['data'] ?: [];
        $novo     = (string) ($doc['status'] ?? '');
        $anterior = (string) $a['status'];

        // Progresso individual — alimenta o "2 de 3 assinaram" da tela
        self::aplicarSignersDoRetorno($pdo, $a['id'], $doc['assignment']['signers'] ?? []);

        $pdo->prepare("UPDATE nf_processo_assinaturas SET sincronizado_em = NOW() WHERE id = ?")
            ->execute([$a['id']]);

        // "Concluída" sem o PDF anexado é conclusão que não aconteceu — refaz,
        // mesmo que o status não tenha mudado. Sem isto a rede de segurança de
        // `abertas()` recolheria a linha e o retorno antecipado a devolveria
        // intocada, num laço eterno sem efeito.
        $inacabada = $novo === 'certificated' && (
            empty($a['anexo_assinado_id']) || !self::etapaAprovada($pdo, $a)
        );

        if ($novo === '' || ($novo === $anterior && !$inacabada)) {
            return $anterior;
        }

        // ⚠ A ORDEM AQUI É CRÍTICA: aplica o desfecho PRIMEIRO, grava o status
        // DEPOIS — e só se deu certo.
        //
        // Ao contrário (gravar 'certificated' e então tentar baixar o PDF), uma
        // falha no meio deixa a linha marcada como concluída sem o documento e
        // sem a etapa aprovada. Pior: ela sai do conjunto de `abertas()`, então
        // o worker nunca mais tenta, e o processo fica preso em
        // 'aguardando_assinatura' para sempre, em silêncio. Foi exatamente o que
        // aconteceu na primeira execução real, com os quatro já tendo assinado.
        $aplicou = true;
        try {
            switch ($novo) {
                case 'certificated':
                    $aplicou = self::concluir($pdo, $cli, $a, $doc);
                    break;

                case 'rejected_by_signer':
                    $quem   = $doc['declined_by']['full_name'] ?? 'Um signatário';
                    $motivo = trim((string) ($doc['decline_reason'] ?? ''));
                    self::devolver($pdo, $a,
                        $quem . ' recusou assinar o documento'
                        . ($motivo !== '' ? ': ' . $motivo : '.'),
                        $motivo
                    );
                    break;

                case 'rejected_by_user':
                    self::devolver($pdo, $a, 'A assinatura foi cancelada no painel do provedor.', null);
                    break;

                case 'expired':
                    self::expirar($pdo, $a);
                    break;

                case 'failed':
                    self::devolver($pdo, $a,
                        'O provedor não conseguiu processar o documento. Verifique o arquivo e reenvie.', null);
                    break;

                // Estados intermediários (metadata_ready, certificating…) não
                // têm ação própria — só acompanham.
            }
        } catch (\Throwable $e) {
            $aplicou = false;
            $pdo->prepare("UPDATE nf_processo_assinaturas SET erro = ? WHERE id = ?")
                ->execute([mb_substr($e->getMessage(), 0, 1000, 'UTF-8'), $a['id']]);
        }

        if (!$aplicou) {
            // Status NÃO avança: a linha continua aberta e o worker tenta de novo.
            return $anterior;
        }

        $pdo->prepare("UPDATE nf_processo_assinaturas SET status = ?, erro = NULL WHERE id = ?")
            ->execute([$novo, $a['id']]);

        return $novo;
    }

    /**
     * Clicksign: reconsulta o ENVELOPE (única fonte de verdade) e aplica o desfecho.
     *
     * A API não expõe status por signatário — só o do envelope. Por isso a tela
     * mostra "n de n assinaram" apenas no fechamento: enquanto `running`, todos
     * constam como notificados.
     *
     * Mesma regra de ordem do fluxo Assinafy: aplica o desfecho PRIMEIRO e só grava
     * o status se deu certo — senão uma falha ao baixar o PDF deixaria a linha
     * "concluída" sem documento e fora do radar do worker.
     */
    private static function sincronizarClicksign(PDO $pdo, array $a): string
    {
        $anterior = (string) $a['status'];
        $cli = ClicksignClient::fromSettings();
        if (!$cli->configurado()) { return $anterior; }

        $r = $cli->getEnvelope((string) $a['documento_externo_id']);
        $pdo->prepare("UPDATE nf_processo_assinaturas SET sincronizado_em = NOW() WHERE id = ?")->execute([$a['id']]);
        if (!$r['ok']) {
            $pdo->prepare("UPDATE nf_processo_assinaturas SET erro = ? WHERE id = ?")
                ->execute([mb_substr((string) $r['erro'], 0, 1000, 'UTF-8'), $a['id']]);
            return $anterior;
        }

        $statusEnv = (string) ($r['data']['attributes']['status'] ?? '');

        // Mapeia para o vocabulário do fluxo. 'draft' é rascunho de um disparo que
        // não chegou a ativar — não há o que fazer, o reenvio cuida disso.
        $novo = null;
        if ($statusEnv === 'closed')        { $novo = 'certificated'; }
        elseif ($statusEnv === 'canceled')  { $novo = 'rejected_by_user'; }
        elseif ($statusEnv === 'running') {
            // Prazo vencido: a doc não diz o que o envelope vira depois do
            // deadline, então a decisão é nossa, com 1 dia de folga.
            if (!empty($a['expira_em']) && time() > strtotime((string) $a['expira_em']) + 86400) {
                $novo = 'expired';
            } elseif ($anterior === 'enviando') {
                $novo = 'pending_signature';
            }
        }

        $inacabada = $novo === 'certificated' && (
            empty($a['anexo_assinado_id']) || !self::etapaAprovada($pdo, $a)
        );
        if ($novo === null || ($novo === $anterior && !$inacabada)) {
            return $anterior;
        }

        $aplicou = true;
        try {
            switch ($novo) {
                case 'certificated':
                    $nome = $pdo->prepare("SELECT nome_original FROM nf_processo_anexos WHERE id = ? LIMIT 1");
                    $nome->execute([$a['anexo_id']]);
                    $nomeDoc = (string) ($nome->fetchColumn() ?: 'documento.pdf');
                    $aplicou = self::concluir($pdo, $cli, $a, ['name' => $nomeDoc]);
                    if ($aplicou) {
                        $pdo->prepare(
                            "UPDATE nf_assinatura_signatarios SET status = 'assinado', assinado_em = NOW()
                             WHERE assinatura_id = ? AND natureza = 'assina' AND status <> 'assinado'"
                        )->execute([$a['id']]);
                    }
                    break;

                case 'rejected_by_user':
                    self::devolver($pdo, $a, 'A assinatura foi cancelada no painel da Clicksign.', null);
                    break;

                case 'expired':
                    self::expirar($pdo, $a);
                    break;
            }
        } catch (\Throwable $e) {
            $aplicou = false;
            $pdo->prepare("UPDATE nf_processo_assinaturas SET erro = ? WHERE id = ?")
                ->execute([mb_substr($e->getMessage(), 0, 1000, 'UTF-8'), $a['id']]);
        }

        if (!$aplicou) { return $anterior; }

        $pdo->prepare("UPDATE nf_processo_assinaturas SET status = ?, erro = NULL WHERE id = ?")
            ->execute([$novo, $a['id']]);
        return $novo;
    }

    /** A etapa de assinatura já foi aprovada? */
    private static function etapaAprovada(PDO $pdo, array $a): bool
    {
        $stmt = $pdo->prepare(
            "SELECT status FROM nf_processo_etapas
             WHERE processo_id = ? AND numero_etapa = ? LIMIT 1"
        );
        $stmt->execute([$a['processo_id'], $a['numero_etapa']]);
        return $stmt->fetchColumn() === 'aprovado';
    }

    /** Atualiza cada signatário com o que o provedor devolveu. */
    private static function aplicarSignersDoRetorno(PDO $pdo, string $assinaturaId, array $signers): void
    {
        if (empty($signers)) { return; }

        $upd = $pdo->prepare(
            "UPDATE nf_assinatura_signatarios
             SET status = ?, assinado_em = ?, notificado_em = ?, entrega_status = ?, entrega_erro = ?
             WHERE assinatura_id = ? AND (signer_id_ext = ? OR LOWER(email) = LOWER(?))"
        );

        foreach ($signers as $s) {
            $assinou = !empty($s['completed']);
            $notif   = !empty($s['notified']);

            // notification_history traz o resultado real do envio por canal —
            // é o que responde "o e-mail chegou?" sem adivinhação.
            $entregaStatus = null;
            $entregaErro   = null;
            $enviadoEm     = null;
            foreach (($s['notification_history'] ?? []) as $h) {
                if (($h['event'] ?? '') === 'signature_request') {
                    $entregaStatus = (string) ($h['status'] ?? '');
                    $entregaErro   = $h['error_message'] ?? null;
                    $enviadoEm     = self::paraLocal($h['sent_at'] ?? null);
                    break;
                }
            }

            $upd->execute([
                $assinou ? 'assinado' : ($notif ? 'notificado' : 'pendente'),
                $assinou ? date('Y-m-d H:i:s') : null,
                $enviadoEm,
                $entregaStatus,
                $entregaErro !== null ? mb_substr((string) $entregaErro, 0, 255, 'UTF-8') : null,
                $assinaturaId,
                (string) ($s['id'] ?? ''),
                (string) ($s['email'] ?? ''),
            ]);
        }
    }

    /**
     * Converte timestamp do provedor (UTC, sufixo Z) para o fuso da aplicação.
     * A API responde sempre em UTC — gravar cru deixaria a tela 3h à frente.
     */
    private static function paraLocal(?string $iso): ?string
    {
        if ($iso === null || trim($iso) === '') { return null; }
        try {
            return (new DateTime($iso, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. Desfechos
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Assinado: baixa o PDF, anexa ao processo e aprova a etapa.
     * @return bool true só quando TUDO deu certo — false mantém a linha aberta
     *              para o worker tentar de novo.
     */
    private static function concluir(PDO $pdo, $cli, array $a, array $doc): bool
    {
        $docId = (string) $a['documento_externo_id'];

        // Já baixado numa tentativa anterior? Não repetir o download — isso
        // criaria um segundo anexo idêntico no processo. Só falta o que ficou
        // para trás: aprovar a etapa. É o que torna a conclusão repetível com
        // segurança, e o que permite o worker recuperar uma execução que morreu
        // no meio (aconteceu: o anexo entrou, a aprovação quebrou).
        if (!empty($a['anexo_assinado_id'])) {
            self::aprovarEtapaDeAssinatura($pdo, (string) $a['processo_id'], (int) $a['numero_etapa']);
            return true;
        }

        // O artefato `certificated` é auto-contido: original + páginas de
        // certificação (medido em 17/08: 2 páginas viraram 4). Não vale guardar
        // `certificate-page` (subconjunto) nem `bundle` (redundante e maior).
        // Clicksign: o PDF assinado vem do link pré-assinado do documento (o
        // envelope fechado ainda pode levar uns segundos para publicá-lo — nesse
        // caso devolve false e o worker repete na próxima passada).
        $ehClicksign = $cli instanceof ClicksignClient;
        $bin = $ehClicksign
            ? $cli->baixarAssinado($docId)
            : $cli->baixarArtefato($docId, 'certificated');
        if (!$bin['ok'] || strlen($bin['raw']) < 1000) {
            $pdo->prepare("UPDATE nf_processo_assinaturas SET erro = ? WHERE id = ?")
                ->execute(['Falha ao baixar o PDF assinado: ' . ($bin['erro'] ?? 'vazio'), $a['id']]);
            return false;   // o worker tenta de novo na próxima passada
        }

        $processoId = (string) $a['processo_id'];
        $dir        = STORAGE . '/processos/' . $processoId;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Falha ao criar diretório de armazenamento.');
        }
        $nomeArmazenado = Process::uuid_v4() . '.pdf';
        $caminho        = $dir . '/' . $nomeArmazenado;
        if (file_put_contents($caminho, $bin['raw']) === false) {
            throw new \RuntimeException('Falha ao gravar o PDF assinado.');
        }

        $anexoId = Process::salvarAnexo($processoId, [
            'nome_original'   => 'ASSINADO — ' . (string) ($doc['name'] ?? 'documento.pdf'),
            'nome_armazenado' => $nomeArmazenado,
            'caminho'         => $caminho,
            'mime_type'       => 'application/pdf',
            'tamanho_bytes'   => strlen($bin['raw']),
        ], self::TIPO_ANEXO_ASSINADO, 'sistema');

        // Hash de verificação: handle público (assinafy.com.br/verificador/<hash>)
        // que prova a assinatura sem depender de nós.
        // Só a Assinafy imprime HASH/ACORDO no certificado.
        [$hash, $acordo] = $ehClicksign ? [null, null] : self::extrairHashEAcordo($caminho);

        $pdo->prepare(
            "UPDATE nf_processo_assinaturas
             SET anexo_assinado_id = ?, hash_verificacao = ?, codigo_acordo = ?,
                 concluido_em = NOW(), erro = NULL
             WHERE id = ?"
        )->execute([$anexoId, $hash, $acordo, $a['id']]);

        $sistema = self::usuarioSistema($pdo);
        Process::registrarHistorico(
            $processoId, 'assinatura_concluida', $sistema['id'], $sistema['nome'],
            'Todos os signatários assinaram. O documento assinado foi anexado ao processo'
            . ($hash !== null ? ' (verificação: ' . $hash . ')' : '') . '.',
            (int) $a['numero_etapa']
        );

        // A etapa de assinatura se conclui aqui — quem a aprova é o retorno do
        // provedor, nunca um usuário pela tela.
        self::aprovarEtapaDeAssinatura($pdo, $processoId, (int) $a['numero_etapa']);
        return true;
    }

    /** Nome do tipo de anexo em que o PDF assinado é gravado. */
    public const TIPO_ANEXO_ASSINADO = 'Documento Assinado';

    /**
     * Hash e código do acordo, lidos da página de certificação do PDF.
     *
     * A API NÃO expõe esses dois valores em lugar nenhum — conferido em
     * 17/08/2026 em `/documents/{id}` e em `/activities`, cujos payloads de
     * `document_ready` e `signer_signed_document` vêm vazios ou só com o nome
     * do signatário. Eles existem apenas impressos no certificado:
     *
     *     ACORDO: 74513031-75C4-...-79D2CD500004  HASH: EF34D960...C39D7
     *
     * Depende do `pdftotext` (poppler), o mesmo já usado na importação de PDFs.
     * Sem o binário devolve [null, null] e SEGUE — a ausência do hash não pode
     * travar a conclusão de um processo. Nada se perde de fato: o PDF assinado
     * carrega o link de verificação impresso na própria página, então a prova
     * continua com o documento mesmo sem estar no banco.
     */
    private static function extrairHashEAcordo(string $caminhoPdf): array
    {
        require_once __DIR__ . '/../Helpers/PdfExtractor.php';
        if (!PdfExtractor::disponivel()) {
            return [null, null];
        }

        try {
            $r = PdfExtractor::extrair($caminhoPdf);
        } catch (\Throwable $e) {
            return [null, null];
        }
        $texto = (string) ($r['texto'] ?? '');
        if ($texto === '') { return [null, null]; }

        $hash = $acordo = null;
        if (preg_match('/HASH:\s*([A-F0-9]{32,64})/i', $texto, $m)) {
            $hash = strtoupper($m[1]);
        }
        if (preg_match('/ACORDO:\s*([A-F0-9]{8}-[A-F0-9-]{20,40})/i', $texto, $m)) {
            $acordo = strtoupper($m[1]);
        }
        return [$hash, $acordo];
    }

    /** URL pública de verificação do documento assinado (null sem hash). */
    public static function urlVerificacao(?string $hash): ?string
    {
        return ($hash !== null && $hash !== '')
            ? 'https://www.assinafy.com.br/verificador/' . rawurlencode($hash)
            : null;
    }

    /**
     * Aprova a etapa de assinatura em nome do sistema.
     *
     * Reusa Process::aprovar() de propósito: é ele que sabe encadear etapas
     * automáticas, notificar os aprovadores seguintes, concluir o processo e
     * gravar o histórico. Reimplementar aqui faria a etapa de assinatura seguir
     * regras diferentes das outras — a divergência que o gancho do
     * AccessSchedule já avisa que não pode acontecer.
     */
    private static function aprovarEtapaDeAssinatura(PDO $pdo, string $processoId, int $numeroEtapa): void
    {
        $stmt = $pdo->prepare(
            "SELECT id FROM nf_processo_etapas
             WHERE processo_id = ? AND numero_etapa = ? LIMIT 1"
        );
        $stmt->execute([$processoId, $numeroEtapa]);
        $etapaId = $stmt->fetchColumn();
        if (!$etapaId) { return; }

        // Sai de 'aguardando_assinatura' para o estado que aprovar() espera.
        $pdo->prepare("UPDATE nf_processo_etapas SET status = 'pendente' WHERE id = ?")
            ->execute([$etapaId]);

        $sistema = self::usuarioSistema($pdo);
        Process::aprovar($processoId, (string) $etapaId, $sistema['id'], $sistema['nome']);
    }

    /** Recusa/cancelamento/falha: devolve para quem mandou assinar. */
    private static function devolver(PDO $pdo, array $a, string $motivo, ?string $motivoBruto): void
    {
        $pdo->prepare("UPDATE nf_processo_assinaturas SET motivo_recusa = ?, concluido_em = NOW() WHERE id = ?")
            ->execute([$motivoBruto !== null ? mb_substr($motivoBruto, 0, 1000, 'UTF-8') : $motivo, $a['id']]);

        $processoId = (string) $a['processo_id'];
        $etapa      = (int) $a['numero_etapa'];
        $sistema    = self::usuarioSistema($pdo);

        Process::registrarHistorico(
            $processoId, 'assinatura_recusada', $sistema['id'], $sistema['nome'], $motivo, $etapa
        );

        // A etapa está em 'aguardando_assinatura'; devolver() age sobre a etapa
        // atual do processo, então ela precisa voltar ao estado que ele espera.
        $stmt = $pdo->prepare(
            "SELECT id FROM nf_processo_etapas WHERE processo_id = ? AND numero_etapa = ? LIMIT 1"
        );
        $stmt->execute([$processoId, $etapa]);
        $etapaId = $stmt->fetchColumn();
        if (!$etapaId) { return; }

        $pdo->prepare("UPDATE nf_processo_etapas SET status = 'pendente' WHERE id = ?")
            ->execute([$etapaId]);

        // SEMPRE volta para quem enviou. Gravado no ato do envio; a busca por
        // quem aprovou a etapa anterior é só o resgate de assinaturas antigas,
        // criadas antes de `enviado_por` existir.
        $destino = (string) ($a['enviado_por'] ?? '') !== ''
            ? (string) $a['enviado_por']
            : self::quemAprovouEtapaAnterior($pdo, $processoId, $etapa);

        if ($destino === null || $destino === '') {
            // Sem remetente conhecido, a etapa fica pendente e o responsável
            // dela resolve — melhor do que espera eterna.
            return;
        }

        try {
            Process::devolver(
                $processoId, (string) $etapaId, $sistema['id'], $sistema['nome'],
                $motivo, null, $destino
            );
        } catch (\Throwable $e) {
            $pdo->prepare("UPDATE nf_processo_assinaturas SET erro = ? WHERE id = ?")
                ->execute(['Falha ao devolver: ' . $e->getMessage(), $a['id']]);
        }
    }

    /** Prazo vencido: devolve ou só avisa, conforme a config do template. */
    private static function expirar(PDO $pdo, array $a): void
    {
        $cfg = self::configDoProcesso($pdo, (string) $a['processo_id']) ?? [];
        $aoExpirar = (string) ($cfg['ao_expirar'] ?? 'devolver_anterior');

        if ($aoExpirar === 'notificar') {
            $sistema = self::usuarioSistema($pdo);
            Process::registrarHistorico(
                (string) $a['processo_id'], 'assinatura_expirada',
                $sistema['id'], $sistema['nome'],
                'O prazo de assinatura venceu e ninguém assinou. O processo segue aguardando.',
                (int) $a['numero_etapa']
            );
            return;
        }

        self::devolver($pdo, $a,
            'O prazo de assinatura venceu sem que todos assinassem. '
            . 'Reenvie o convite ou cancele o processo.', null);
    }

    /**
     * Quem aprovou a etapa imediatamente anterior à assinatura.
     *
     * É o destino da devolução (decidido com o usuário em 17/08/2026): quem
     * conferiu o documento e mandou assinar é quem decide reenviar ou cancelar.
     * Devolver a quem abriu o processo faria a conferência ser refeita à toa.
     */
    private static function quemAprovouEtapaAnterior(PDO $pdo, string $processoId, int $etapaAssinatura): ?string
    {
        $stmt = $pdo->prepare(
            "SELECT atuado_por FROM nf_processo_etapas
             WHERE processo_id = ? AND numero_etapa < ? AND atuado_por IS NOT NULL
             ORDER BY numero_etapa DESC LIMIT 1"
        );
        $stmt->execute([$processoId, $etapaAssinatura]);
        $id = $stmt->fetchColumn();
        return $id ? (string) $id : null;
    }

    /**
     * Usuário sob o qual o sistema age.
     *
     * O histórico e as etapas guardam FK suave para usuarios.id; usar um id
     * inventado quebraria os JOINs de exibição. Preferimos o usuário-robô da
     * API (o mesmo do RPA) e, sem ele, o criador do processo.
     */
    private static function usuarioSistema(PDO $pdo): array
    {
        $email = (string) Settings::get('api.rpa_usuario_email', '');
        if ($email !== '') {
            $stmt = $pdo->prepare("SELECT id, nome FROM usuarios WHERE email = ? AND ativo = 1 LIMIT 1");
            $stmt->execute([$email]);
            if ($u = $stmt->fetch()) {
                return ['id' => (string) $u['id'], 'nome' => (string) $u['nome']];
            }
        }
        $stmt = $pdo->query("SELECT id, nome FROM usuarios WHERE perfil = 'super_admin' AND ativo = 1 ORDER BY nome LIMIT 1");
        $u = $stmt ? $stmt->fetch() : null;
        return $u
            ? ['id' => (string) $u['id'], 'nome' => 'Assinatura eletrônica']
            : ['id' => '', 'nome' => 'Assinatura eletrônica'];
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. Consulta (tela do processo e worker)
    // ─────────────────────────────────────────────────────────────────────

    /** Assinatura de uma etapa do processo, com os signatários. */
    public static function daEtapa(string $processoId, int $numeroEtapa): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT * FROM nf_processo_assinaturas
             WHERE processo_id = ? AND numero_etapa = ?
             ORDER BY criado_em DESC LIMIT 1"
        );
        $stmt->execute([$processoId, $numeroEtapa]);
        $a = $stmt->fetch();
        if (!$a) { return null; }

        $sig = $pdo->prepare(
            "SELECT * FROM nf_assinatura_signatarios WHERE assinatura_id = ? ORDER BY papel"
        );
        $sig->execute([$a['id']]);
        $a['signatarios'] = $sig->fetchAll();

        $a['total']     = count($a['signatarios']);
        $a['assinados'] = count(array_filter(
            $a['signatarios'],
            static fn(array $s): bool => $s['status'] === 'assinado'
        ));
        return $a;
    }

    /** Todas as assinaturas de um processo (histórico da tela). */
    public static function doProcesso(string $processoId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT * FROM nf_processo_assinaturas WHERE processo_id = ? ORDER BY criado_em DESC"
        );
        $stmt->execute([$processoId]);
        return $stmt->fetchAll();
    }

    /**
     * Ids das assinaturas que o worker ainda precisa olhar.
     *
     * Além das em andamento, recolhe as marcadas como `certificated` que nunca
     * chegaram a ter o PDF anexado. É a rede de segurança para a inconsistência
     * que já aconteceu uma vez: status de concluída sem a conclusão ter
     * ocorrido. Sem esta cláusula, essas linhas somem do radar e o processo
     * fica preso em 'aguardando_assinatura' sem ninguém perceber.
     */
    public static function abertas(int $limite = 100): array
    {
        $pdo = Database::getInstance();
        $ph  = implode(',', array_fill(0, count(self::ABERTOS), '?'));
        $stmt = $pdo->prepare(
            "SELECT a.id FROM nf_processo_assinaturas a
             WHERE a.documento_externo_id IS NOT NULL
               AND (
                     a.status IN ({$ph})
                  -- Concluída no papel mas com trabalho por fazer: sem o PDF
                  -- anexado, ou com a etapa ainda não aprovada. Uma execução
                  -- que morre no meio deixa exatamente esse rastro.
                  OR (a.status = 'certificated' AND (
                        a.anexo_assinado_id IS NULL
                     OR EXISTS (SELECT 1 FROM nf_processo_etapas pe
                                 WHERE pe.processo_id  = a.processo_id
                                   AND pe.numero_etapa = a.numero_etapa
                                   AND pe.status <> 'aprovado')
                  ))
               )
             ORDER BY COALESCE(a.sincronizado_em, a.criado_em) ASC
             LIMIT " . max(1, $limite)
        );
        $stmt->execute(self::ABERTOS);
        return array_column($stmt->fetchAll(), 'id');
    }

    /** Acha a assinatura pelo id do documento no provedor (usado pelo webhook). */
    public static function porDocumentoExterno(string $documentoId): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT * FROM nf_processo_assinaturas WHERE documento_externo_id = ? LIMIT 1"
        );
        $stmt->execute([$documentoId]);
        return $stmt->fetch() ?: null;
    }
}
