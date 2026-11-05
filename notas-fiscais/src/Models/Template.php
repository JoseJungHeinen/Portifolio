<?php
// @versao Tpl_05 V_13_01/10/2026
declare(strict_types=1);

/**
 * src/Models/Template.php
 * Modelo de Templates de fluxo NF — tabelas nf_templates + nf_template_versoes
 */
class Template
{
    /** Nome obrigatório da etapa de aprovação financeira quando tem_financeiro=1. */
    public const ETAPA_FINANCEIRO_NOME = 'Aprovação Financeiro';

    /**
     * Quando tem_financeiro=1, exige etapa "Aprovação Financeiro" com responsável
     * definido manualmente — pode ser um grupo ou um usuário fixo, mas precisa
     * estar preenchido (não pode ficar sem aprovador definido).
     */
    public static function possuiEtapaFinanceiroValida(array $etapas): bool
    {
        foreach ($etapas as $etapa) {
            if (($etapa['nome'] ?? '') === self::ETAPA_FINANCEIRO_NOME) {
                return in_array($etapa['tipo_aprovador'] ?? '', ['usuario', 'grupo'], true)
                    && !empty($etapa['aprovador_id']);
            }
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // ASSINATURA ELETRÔNICA — a trava
    //
    // Mesmo padrão de tem_financeiro, indo além: o toggle monta etapa, campos
    // E anexo, e a publicação é bloqueada enquanto faltar qualquer um deles.
    //
    // Por que travar no template e não no runtime: se a exigência só aparecesse
    // na hora de enviar, o processo já estaria andando quando descobrisse que
    // não tem documento nem e-mail de signatário — e pararia no meio do fluxo.
    // Travando na publicação, o template errado nunca chega a produção.
    //
    // Os NOMES abaixo são contrato: a validação, o editor (templates.js) e a
    // resolução em tempo de execução comparam por eles. Não renomear sem mexer
    // nos três lugares.
    // ─────────────────────────────────────────────────────────────────────

    /** Etapa de catálogo com assinatura_externa=1. */
    public const ETAPA_ASSINATURA_NOME = 'Assinatura de Documento';

    /** Anexo obrigatório que vai para o provedor. */
    public const ANEXO_ASSINATURA_NOME = 'Documento para Assinatura';

    /** Teto de signatários por etapa — segura a UI e o custo. */
    public const MAX_SIGNATARIOS = 8;

    /**
     * Naturezas possíveis — é a ÚNICA distinção que o provedor entende.
     *
     * 'assina' vai em signers[]; 'copia' vai em copy_receivers[] (recebe o
     * documento e não assina). O `papel` ao lado é rótulo NOSSO: a Assinafy só
     * tem papel de verdade (TemplateRole) nos templates dela, com campos
     * posicionados em página fixa — inútil aqui, onde o documento é um anexo
     * que o solicitante sobe. Levantado na API em 17/08/2026.
     *
     * Consequência que precisa estar clara para quem configura: o papel NÃO
     * aparece no certificado emitido — lá todos saem como partes iguais.
     */
    public const NATUREZAS = ['assina' => 'Assina', 'copia' => 'Recebe cópia'];

    /**
     * O que fazer quando o prazo de assinatura vence.
     *
     * 'devolver_anterior' devolve para quem aprovou a etapa IMEDIATAMENTE
     * ANTERIOR à assinatura — a pessoa que conferiu o documento e mandou
     * assinar. É ela que decide reenviar ou cancelar; devolver a quem abriu o
     * processo faria a conferência ser refeita à toa. Definido com o usuário
     * em 17/08/2026.
     */
    public const AO_EXPIRAR = [
        'devolver_anterior' => 'Devolver a quem enviou para assinatura',
        'notificar'         => 'Avisar e continuar aguardando',
    ];

    /** Etapa de catálogo da Clicksign (assinatura_provedor='clicksign'). */
    public const ETAPA_CLICKSIGN_NOME = 'Assina ClickSign';

    /** Provedores de assinatura: chave → nome da etapa de catálogo que o aciona. */
    public const PROVEDORES_ASSINATURA = [
        'assinafy'  => self::ETAPA_ASSINATURA_NOME,
        'clicksign' => self::ETAPA_CLICKSIGN_NOME,
    ];

    /** Quantidade fixa de signatários da etapa Clicksign (decidido 01/10/2026). */
    public const SIGNATARIOS_CLICKSIGN = 2;

    /** Lista (nf_listas) que alimenta o campo "Tipo de assinatura". */
    public const LISTA_TIPOS_ASSINATURA_ID = '00000000-0000-4000-8000-0000000000c5';

    /**
     * ID do item da lista → [código `auth` da Clicksign, exige telefone?].
     * O processo grava o ID do item (campo de lista), então é por ele que se
     * traduz — o texto da lista pode ser renomeado sem quebrar o envio.
     */
    public const TIPOS_AUTENTICACAO = [
        '00000000-0000-4000-8000-00000000c501' => ['email',             false],
        '00000000-0000-4000-8000-00000000c502' => ['whatsapp',          true],
        '00000000-0000-4000-8000-00000000c503' => ['sms',               true],
        '00000000-0000-4000-8000-00000000c504' => ['selfie',            false],
        '00000000-0000-4000-8000-00000000c505' => ['liveness',          false],
        '00000000-0000-4000-8000-00000000c506' => ['official_document', false],
        '00000000-0000-4000-8000-00000000c507' => ['icp_brasil',        false],
        '00000000-0000-4000-8000-00000000c508' => ['handwritten',       false],
        '00000000-0000-4000-8000-00000000c509' => ['pix',               false],
        '00000000-0000-4000-8000-00000000c510' => ['facial_biometrics', false],
        '00000000-0000-4000-8000-00000000c511' => ['documentscopy',     false],
    ];

    /** Provedor válido; qualquer outra coisa volta a 'assinafy' (config antiga). */
    public static function provedorValido($p): string
    {
        return isset(self::PROVEDORES_ASSINATURA[(string) $p]) ? (string) $p : 'assinafy';
    }

    /**
     * Nomes dos campos de um signatário, derivados do PAPEL.
     *
     * "Contratante — E-mail" diz muito mais a quem abre o processo do que
     * "Signatário 2 — E-mail". A Clicksign pede mais dados que a Assinafy:
     * CPF, telefone (só usado em SMS/WhatsApp) e o tipo de autenticação.
     *
     * @return array<string,string> qual → rótulo
     */
    public static function camposSignatario(string $papel, string $provedor = 'assinafy'): array
    {
        $papel = trim($papel) !== '' ? trim($papel) : 'Signatário';
        $campos = [
            'nome'  => "{$papel} — Nome completo",
            'email' => "{$papel} — E-mail",
        ];
        if ($provedor === 'clicksign') {
            $campos = [
                'nome'     => "{$papel} — Nome completo",
                'cpf'      => "{$papel} — CPF",
                'email'    => "{$papel} — E-mail",
                'telefone' => "{$papel} — Telefone",
                'tipo'     => "{$papel} — Tipo de assinatura",
            ];
        }
        return $campos;
    }

    /** Config default de assinatura — gravada ao ligar o toggle. */
    public static function configAssinaturaPadrao(): array
    {
        return [
            'provedor'     => 'assinafy',
            'origem'       => 'campos',  // decidido 17/08/2026: signatário vem da abertura
            'metodo'       => 'virtual',
            'prazo_dias'   => 7,
            'ao_expirar'   => 'devolver_anterior',
            'signatarios'  => [
                ['papel' => 'Signatário', 'natureza' => 'assina'],
            ],
        ];
    }

    /** Lê a config da versão, com defaults preenchidos e a lista saneada. */
    public static function configAssinatura(array $versao): array
    {
        $bruto = $versao['assinatura_config'] ?? null;
        $cfg   = is_array($bruto) ? $bruto : (json_decode((string) $bruto, true) ?: []);
        $cfg   = array_merge(self::configAssinaturaPadrao(), $cfg);

        $cfg['prazo_dias']  = max(0, (int) $cfg['prazo_dias']);
        $cfg['provedor']    = self::provedorValido($cfg['provedor'] ?? 'assinafy');
        $cfg['signatarios'] = self::sanearSignatarios($cfg['signatarios'] ?? []);

        // Clicksign: sempre exatamente dois signatários, todos assinam. O papel
        // (rótulo dos campos) continua editável; a quantidade e a natureza não.
        if ($cfg['provedor'] === 'clicksign') {
            $cfg['signatarios'] = self::signatariosClicksign($cfg['signatarios']);
        }

        // Valor desconhecido (config antiga ou POST adulterado) volta ao padrão
        if (!isset(self::AO_EXPIRAR[$cfg['ao_expirar'] ?? ''])) {
            $cfg['ao_expirar'] = 'devolver_anterior';
        }
        return $cfg;
    }

    /**
     * Ajusta a lista para o formato da Clicksign: 2 papéis, natureza 'assina'.
     * Papel faltante vira "Signatário N"; sobra é cortada; repetido é renomeado
     * (cada papel gera campos próprios e o processo guarda valor por rótulo).
     *
     * @return array<int,array{papel:string,natureza:string}>
     */
    public static function signatariosClicksign(array $lista): array
    {
        $out = [];
        $vistos = [];
        for ($i = 0; $i < self::SIGNATARIOS_CLICKSIGN; $i++) {
            $papel = trim((string) ($lista[$i]['papel'] ?? ''));
            if ($papel === '' || isset($vistos[mb_strtolower($papel, 'UTF-8')])) {
                $papel = 'Signatário ' . ($i + 1);
            }
            $vistos[mb_strtolower($papel, 'UTF-8')] = true;
            $out[] = ['papel' => $papel, 'natureza' => 'assina'];
        }
        return $out;
    }

    /**
     * Normaliza a lista vinda do formulário ou do banco.
     * Descarta papéis vazios, corta no teto e força natureza válida.
     *
     * @return array<int,array{papel:string,natureza:string}>
     */
    public static function sanearSignatarios($lista): array
    {
        $out = [];
        foreach ((is_array($lista) ? $lista : []) as $s) {
            $papel = trim((string) ($s['papel'] ?? ''));
            if ($papel === '') continue;
            $nat = (string) ($s['natureza'] ?? 'assina');
            $out[] = [
                'papel'    => mb_substr($papel, 0, 60, 'UTF-8'),
                'natureza' => isset(self::NATUREZAS[$nat]) ? $nat : 'assina',
            ];
            if (count($out) >= self::MAX_SIGNATARIOS) break;
        }
        // Lista vazia volta ao padrão: um template com assinatura ligada e
        // nenhum signatário não teria para quem enviar.
        return $out ?: [['papel' => 'Signatário', 'natureza' => 'assina']];
    }

    /**
     * Tudo que falta para o template com assinatura poder sair de rascunho.
     *
     * Devolve mensagens prontas para a tela — lista vazia = pode publicar.
     * Chamado em TODA transição para fora de rascunho, não só ao publicar:
     * processos de teste também nascem de teste_interno/uat, e um template de
     * teste quebrado trava o processo do mesmo jeito.
     *
     * @return array<int,string>
     */
    /**
     * Problemas nas etapas de DECISÃO — mesma forma de validarAssinatura():
     * devolve a lista de pendências, vazia quando está tudo certo.
     *
     * A decisão lê um campo do formulário, converte o item escolhido em índice
     * e salta para a etapa daquele índice. Cada regra aqui corresponde a um
     * jeito de o processo travar (ou seguir por um caminho errado) em produção
     * — é barato barrar na publicação e caro descobrir com processo aberto.
     *
     * @return array<int,string>
     */
    public static function validarDecisoes(array $versao): array
    {
        require_once SRC . '/Models/Etapa.php';
        require_once SRC . '/Models/TableList.php';

        $problemas = [];
        $etapas    = $versao['etapas'] ?? [];
        $campos    = $versao['campos'] ?? [];

        // uid → posição, para conferir se os destinos ainda existem.
        $posPorUid = [];
        foreach ($etapas as $i => $e) {
            if (!empty($e['uid'])) {
                $posPorUid[(string) $e['uid']] = $i + 1;
            }
        }

        foreach ($etapas as $i => $etapa) {
            $catalogo = Etapa::resolver($etapa);
            $listaId  = $catalogo['decisao_lista_id'] ?? null;
            $pos      = $i + 1;
            $nome     = trim((string) ($etapa['nome'] ?? '')) ?: ('Etapa ' . $pos);

            // ── Etapa "Ir para": um destino só, sob o índice 0 ──
            if (!empty($catalogo['salto_incondicional'])) {
                $uid = (string) (($etapa['decisao_saltos'] ?? [])['0'] ?? '');
                if ($pos === 1) {
                    $problemas[] = "A etapa \"{$nome}\" é um salto e não pode ser a primeira do fluxo — "
                        . 'ela é resolvida ao ser atingida, então precisa de ao menos uma etapa antes.';
                }
                if ($uid === '') {
                    $problemas[] = "A etapa \"{$nome}\" é um salto (\"Ir para\") sem etapa de destino. "
                        . 'Escolha para onde ela manda o processo.';
                } elseif (!isset($posPorUid[$uid])) {
                    $problemas[] = "O destino da etapa \"{$nome}\" aponta para uma etapa que não está "
                        . 'mais no template. Selecione o destino de novo.';
                } elseif ($posPorUid[$uid] === $pos) {
                    $problemas[] = "A etapa \"{$nome}\" aponta para si mesma, o que seria um laço infinito.";
                }
                continue;
            }

            // ── Etapa "Abrir Fluxo": template de destino publicado ──
            if (!empty($catalogo['abre_fluxo'])) {
                $destinoTpl = trim((string) ($etapa['abre_template_id'] ?? ''));
                if ($destinoTpl === '') {
                    $problemas[] = "A etapa \"{$nome}\" abre um fluxo mas não diz qual. "
                        . 'Escolha o template de destino na aba Etapas.';
                } else {
                    $stmt = Database::getInstance()->prepare(
                        "SELECT t.nome, t.versao_publicada_id
                           FROM nf_templates t WHERE t.id = ? LIMIT 1"
                    );
                    $stmt->execute([$destinoTpl]);
                    $tplDestino = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$tplDestino) {
                        $problemas[] = "A etapa \"{$nome}\" aponta para um fluxo que não existe mais. "
                            . 'Escolha o template de destino de novo.';
                    } elseif (empty($tplDestino['versao_publicada_id'])) {
                        $problemas[] = "A etapa \"{$nome}\" abre o fluxo \"{$tplDestino['nome']}\", que não "
                            . 'tem versão publicada. Publique-o antes, senão o processo trava ao chegar aqui.';
                    }
                }
                continue;
            }

            if (!$listaId) {
                continue;   // etapa comum
            }

            // 1. Decisão na primeira posição nunca seria atravessada: o fluxo
            //    começa parado NELA, e não existe aprovação anterior que
            //    dispare a resolução.
            if ($pos === 1) {
                $problemas[] = "A etapa \"{$nome}\" é de decisão e não pode ser a primeira do fluxo — "
                    . 'ela é resolvida ao ser atingida, então precisa de ao menos uma etapa antes.';
            }

            // 2. O campo do formulário de onde sai o valor.
            $candidatos = array_values(array_filter(
                $campos,
                fn($c) => (string) ($c['lista_id'] ?? '') === (string) $listaId
            ));
            $campoEscolhido = trim((string) ($etapa['decisao_campo'] ?? ''));

            if (count($candidatos) === 0) {
                $problemas[] = "A etapa \"{$nome}\" decide por uma lista que nenhum campo do template usa. "
                    . 'Crie na aba Campos um campo do tipo lista apontando para essa lista.';
            } elseif ($campoEscolhido === '') {
                $problemas[] = "A etapa \"{$nome}\" não diz por qual campo decide. "
                    . 'Escolha o campo na aba Etapas.';
            } elseif (!in_array($campoEscolhido, array_column($candidatos, 'nome'), true)) {
                $problemas[] = "A etapa \"{$nome}\" decide pelo campo \"{$campoEscolhido}\", que não existe "
                    . 'na aba Campos (ou não usa a lista dessa decisão).';
            }

            // 3. Todo índice ativo da lista precisa de destino, senão o
            //    processo trava justamente quando alguém escolher aquele item.
            $indices = TableList::indicesDaLista((string) $listaId);
            $saltos  = $etapa['decisao_saltos'] ?? [];

            if ($indices === []) {
                $problemas[] = "A lista da etapa \"{$nome}\" não tem nenhum item com índice. "
                    . 'Defina os índices em Listas para que a decisão tenha caminhos.';
            }

            foreach ($indices as $ix) {
                $chave = (string) $ix['indice'];
                $uid   = (string) ($saltos[$chave] ?? '');

                if ($uid === '') {
                    $problemas[] = "A etapa \"{$nome}\" não tem destino para o índice {$ix['indice']} "
                        . "(\"{$ix['rotulo']}\"). Todo índice precisa de uma etapa de destino.";
                    continue;
                }
                if (!isset($posPorUid[$uid])) {
                    $problemas[] = "O destino do índice {$ix['indice']} (\"{$ix['rotulo']}\") da etapa "
                        . "\"{$nome}\" aponta para uma etapa que não está mais no template. Selecione o destino de novo.";
                    continue;
                }
                if ($posPorUid[$uid] === $pos) {
                    $problemas[] = "O índice {$ix['indice']} (\"{$ix['rotulo']}\") da etapa \"{$nome}\" "
                        . 'aponta para a própria decisão, o que seria um laço infinito.';
                }
            }
        }

        return $problemas;
    }

    /**
     * Teto de combinações simuladas em validarLacosDecisao().
     *
     * A simulação roda uma vez por combinação de respostas das decisões (duas
     * decisões de três índices = nove rodadas). Fluxo com muitas decisões
     * explodiria esse número dentro do request de publicação — passando do
     * teto a checagem é dispensada, em vez de segurar a tela.
     */
    private const MAX_COMBINACOES_LACO = 2000;

    /**
     * Laços de decisão: caminhos em que o processo volta a cair na MESMA
     * decisão sem parar em nenhuma etapa que peça ação.
     *
     * Conferir destino a destino não basta, porque o laço nasce da INTERAÇÃO
     * entre duas decisões. Process::aprovar() caminha pelas etapas marcando o
     * que ficou para trás: salto para frente marca o trecho pulado como
     * 'pulado'; salto para trás só REABRE o que estava 'aprovado' — o que está
     * 'pulado' continua pulado, e etapa pulada não para o fluxo. Então uma
     * decisão que devolve o processo para dentro de um trecho que outra decisão
     * já havia pulado faz o fluxo passar reto por cima de tudo e cair de novo
     * na mesma decisão, que lê o mesmo campo e devolve o mesmo destino. Em
     * produção isso estoura o teto de saltos e derruba a aprovação com processo
     * já aberto — caro de descobrir, barato de barrar aqui.
     *
     * O caminho é simulado com as MESMAS regras do motor, uma vez para cada
     * combinação de respostas: o valor do campo não muda no meio de uma
     * aprovação, então cada decisão responde sempre igual dentro da mesma
     * rodada. Estado repetido dentro de uma aprovação é laço provado — não há
     * falso positivo. Ciclo que PARA numa etapa acionável a cada volta (a
     * devolução para correção, por exemplo) não é acusado: alguém age a cada
     * passagem e o contador de saltos do motor zera.
     *
     * Pressupõe validarDecisoes() já aprovada — destinos e índices completos.
     *
     * @return array<int,string>
     */
    public static function validarLacosDecisao(array $versao): array
    {
        require_once SRC . '/Models/Etapa.php';
        require_once SRC . '/Models/TableList.php';

        $etapas = array_values($versao['etapas'] ?? []);
        $n      = count($etapas);
        if ($n === 0) {
            return [];
        }

        $posPorUid = [];
        foreach ($etapas as $i => $e) {
            if (!empty($e['uid'])) {
                $posPorUid[(string) $e['uid']] = $i + 1;
            }
        }

        // Perfil de cada posição, lido do catálogo do mesmo jeito que o motor lê.
        $perfil = [];
        foreach ($etapas as $i => $e) {
            $p   = $i + 1;
            $cat = Etapa::resolver($e) ?? [];

            $listaId = (string) ($cat['decisao_lista_id'] ?? '');
            $ehSalto = !empty($cat['salto_incondicional']);

            $perfil[$p] = [
                'nome'    => trim((string) ($e['nome'] ?? '')) ?: ('Etapa ' . $p),
                'decisao' => $ehSalto || $listaId !== '',
                'obs'     => (int) ($cat['somente_observacao'] ?? 0) === 1,
                'auto'    => (int) ($cat['encerra_automaticamente'] ?? 0) === 1
                             || ($e['tipo_aprovador'] ?? '') === 'arquivo',
                'fluxo'   => !empty($cat['abre_fluxo']),
                'dest'    => [],
                'rotulo'  => [],
            ];

            if (!$perfil[$p]['decisao']) {
                continue;
            }

            foreach (($e['decisao_saltos'] ?? []) as $chave => $uid) {
                if (isset($posPorUid[(string) $uid])) {
                    $perfil[$p]['dest'][(string) $chave] = $posPorUid[(string) $uid];
                }
            }

            // "Ir para" tem um destino só, sob o índice 0; decisão tem um por
            // índice ativo da lista.
            if ($ehSalto) {
                $perfil[$p]['rotulo'] = isset($perfil[$p]['dest']['0'])
                    ? ['0' => 'salto direto']
                    : [];
                continue;
            }
            foreach (TableList::indicesDaLista($listaId) as $ix) {
                $chave = (string) $ix['indice'];
                if (isset($perfil[$p]['dest'][$chave])) {
                    $perfil[$p]['rotulo'][$chave] = $ix['rotulo'];
                }
            }
        }

        // Uma escolha fixa por decisão = uma rodada de simulação.
        $pontos = [];
        $total  = 1;
        foreach ($perfil as $p => $d) {
            if (!$d['decisao']) {
                continue;
            }
            $qtd = count($d['rotulo']);
            if ($qtd === 0) {
                return [];   // destino ou índice faltando: quem aponta é validarDecisoes()
            }
            $pontos[] = $p;
            $total   *= $qtd;
            if ($total > self::MAX_COMBINACOES_LACO) {
                return [];
            }
        }
        if ($pontos === []) {
            return [];
        }

        $problemas = [];
        $jaVisto   = [];

        for ($k = 0; $k < $total; $k++) {
            $escolha = [];
            $resto   = $k;
            foreach ($pontos as $p) {
                $chaves      = array_keys($perfil[$p]['rotulo']);
                $escolha[$p] = $chaves[$resto % count($chaves)];
                $resto       = intdiv($resto, count($chaves));
            }

            $laco = self::simularCaminho($perfil, $escolha, $n);
            if ($laco === null) {
                continue;
            }

            // Combinações diferentes caem no mesmo laço; o operador só precisa
            // ver cada um uma vez.
            $assinatura = $laco['pos'] . '/' . $laco['chave'];
            if (isset($jaVisto[$assinatura])) {
                continue;
            }
            $jaVisto[$assinatura] = true;

            $pos     = $laco['pos'];
            $chave   = $laco['chave'];
            $destino = $perfil[$pos]['dest'][$chave];

            $problemas[] = "A etapa {$pos} (\"{$perfil[$pos]['nome']}\") fecha um laço quando o índice "
                . "{$chave} (\"{$perfil[$pos]['rotulo'][$chave]}\") é escolhido: ela manda o processo para "
                . "a etapa {$destino} (\"{$perfil[$destino]['nome']}\") e de lá o fluxo volta a cair nesta "
                . 'mesma decisão sem parar em nenhuma etapa que peça ação — o campo não mudou, então ela '
                . 'responde igual e gira sem fim. Reordene o fluxo para que as etapas comuns a vários '
                . 'caminhos fiquem DEPOIS das etapas de ramo, e as decisões saltem só para frente.';

            if (count($problemas) >= 5) {
                break;
            }
        }

        return $problemas;
    }

    /**
     * Roda um processo inteiro no papel, com as respostas das decisões fixas,
     * seguindo passo a passo a caminhada de Process::aprovar().
     *
     * Status: 'p' pendente · 'a' aprovado · 'x' pulado — os três que a etapa do
     * processo assume enquanto o fluxo caminha.
     *
     * Os tetos de passos não são a checagem, são cinto de segurança: a parada de
     * verdade é o estado repetido. Template patológico não pode segurar o
     * request de publicação enquanto o PHP conta.
     *
     * @param  array<int,array>  $perfil  posição => perfil da etapa
     * @param  array<int,string> $escolha posição da decisão => índice escolhido
     * @return array{pos:int,chave:string}|null decisão que fecha o laço, ou null
     */
    private static function simularCaminho(array $perfil, array $escolha, int $n): ?array
    {
        $status = [];
        for ($p = 1; $p <= $n; $p++) {
            $status[$p] = $perfil[$p]['obs'] ? 'x' : 'p';
        }

        $atual      = 1;
        $paradas    = [];
        $aprovacoes = 0;

        while (true) {
            if (++$aprovacoes > 500) {
                return null;
            }

            // Ciclo que passa por etapa acionável não é o laço que derruba a
            // aprovação: alguém age a cada volta e o contador do motor zera.
            // Chegar duas vezes ao mesmo ponto de parada com o mesmo quadro
            // encerra a simulação sem acusar nada.
            $marca = $atual . ':' . implode('', $status);
            if (isset($paradas[$marca])) {
                return null;
            }
            $paradas[$marca] = true;

            $status[$atual] = 'a';        // o responsável aprovou a etapa

            $p       = $atual + 1;
            $andados = [];
            $passos  = 0;
            $ultima  = null;              // última decisão resolvida NESTA aprovação
            $parou   = null;

            while (true) {
                if ($p > $n || ++$passos > 5000) {
                    return null;          // acabaram as etapas: processo concluído
                }

                $marcaP = $p . ':' . implode('', $status);
                if (isset($andados[$marcaP])) {
                    // Mesma posição, mesmo quadro, DENTRO de uma aprovação: daqui
                    // em diante só se repete. É o laço que estoura o teto de
                    // saltos do motor e derruba a aprovação.
                    return $ultima;
                }
                $andados[$marcaP] = true;

                if ($perfil[$p]['decisao'] && $status[$p] !== 'x') {
                    $chave   = $escolha[$p];
                    $destino = $perfil[$p]['dest'][$chave];
                    $ultima  = ['pos' => $p, 'chave' => $chave];

                    $status[$p] = 'a';
                    if ($destino > $p) {
                        // Para frente: o trecho pulado não roda neste processo.
                        for ($q = $p + 1; $q < $destino; $q++) {
                            if ($status[$q] === 'p') {
                                $status[$q] = 'x';
                            }
                        }
                    } else {
                        // Para trás: reabre só o que estava aprovado — pulado
                        // continua pulado, decisão continua aprovada.
                        for ($q = $destino; $q < $p; $q++) {
                            if ($status[$q] === 'a' && !$perfil[$q]['decisao']) {
                                $status[$q] = 'p';
                            }
                        }
                    }
                    $p = $destino;
                    continue;
                }

                if ($perfil[$p]['fluxo'] && $status[$p] !== 'x') {
                    return null;          // abriu o fluxo filho e encerrou este
                }

                $atravessa = $perfil[$p]['obs'] || $status[$p] === 'x';
                if (!$perfil[$p]['auto'] && !$atravessa) {
                    $parou = $p;          // etapa que exige ação: o processo para
                    break;
                }
                if ($perfil[$p]['auto'] && $status[$p] !== 'x') {
                    return null;          // arquivamento atingido: processo encerrado
                }

                $p++;
            }

            $atual = $parou;
        }
    }

    public static function validarAssinatura(array $versao): array
    {
        if (empty($versao['tem_assinatura'])) {
            return [];
        }

        $problemas = [];
        $etapas    = $versao['etapas']      ?? [];
        $campos    = $versao['campos']      ?? [];
        $anexos    = $versao['tipos_anexo'] ?? [];
        $cfg       = self::configAssinatura($versao);
        $provedor  = $cfg['provedor'];
        $nomeEtapa = self::PROVEDORES_ASSINATURA[$provedor];

        // 1. A etapa, com responsável definido.
        //
        // Ninguém do Notas aprova a etapa de assinatura — quem a conclui é o
        // retorno do provedor. Mesmo assim ela exige responsável: é quem
        // acompanha, reenvia o convite e é avisado se alguém recusar. Sem ele,
        // uma recusa não teria para quem ir.
        $posEtapa = null;
        foreach ($etapas as $i => $etapa) {
            if (trim((string) ($etapa['nome'] ?? '')) === $nomeEtapa) {
                $posEtapa = $i + 1;   // 1-based, igual ao numero_etapa do processo
                if (!in_array($etapa['tipo_aprovador'] ?? '', ['usuario', 'grupo'], true)
                    || empty($etapa['aprovador_id'])
                ) {
                    $problemas[] = 'A etapa "' . $nomeEtapa
                        . '" está sem responsável. Defina um grupo ou usuário na aba Etapas — '
                        . 'é quem acompanha a assinatura e recebe o aviso se o signatário recusar.';
                }
                break;
            }
        }
        if ($posEtapa === null) {
            $problemas[] = 'Falta a etapa "' . $nomeEtapa
                . '" na aba Etapas. Desligue e religue o controle de assinatura (ou escolha a etapa) para recriá-la.';
        } elseif ($posEtapa === 1) {
            // Beco sem saída: o documento é anexado e conferido por alguém ANTES
            // de ir para assinatura. Sendo a primeira etapa, não existe etapa
            // anterior onde exigir o anexo — e a própria etapa de assinatura
            // não é aprovada por humano nenhum (quem a fecha é o provedor).
            // O processo nasceria já disparando assinatura de um documento que
            // ninguém conferiu, ou travaria esperando um anexo que o gate pede
            // numa etapa sem aprovador.
            $problemas[] = 'A etapa "' . $nomeEtapa
                . '" não pode ser a primeira do fluxo. Adicione antes dela ao menos uma etapa '
                . 'de conferência — é nela que o documento é anexado e aprovado antes de sair para assinatura.';
        }

        // 2. O anexo obrigatório — é o arquivo que vai para o provedor.
        //
        // Amarrado a uma etapa ANTERIOR à de assinatura: o documento precisa
        // estar no processo antes de chegar a hora de enviar. Preso à própria
        // etapa de assinatura, o gate de anexo pediria o arquivo numa etapa que
        // nenhum humano aprova — e o processo nunca sairia dali.
        $anexoOk = false;
        foreach ($anexos as $anexo) {
            if (trim((string) ($anexo['nome'] ?? '')) !== self::ANEXO_ASSINATURA_NOME) {
                continue;
            }
            $anexoOk = true;
            if (empty($anexo['obrigatorio'])) {
                $problemas[] = 'O anexo "' . self::ANEXO_ASSINATURA_NOME
                    . '" precisa ser obrigatório — é o arquivo que vai para assinatura.';
            }
            $ordem = (int) ($anexo['etapa_ordem'] ?? 0);
            if ($posEtapa !== null && ($ordem < 1 || $ordem >= $posEtapa)) {
                $problemas[] = 'O anexo "' . self::ANEXO_ASSINATURA_NOME
                    . '" precisa ser exigido em uma etapa ANTES da etapa de assinatura '
                    . '(hoje: ' . ($ordem > 0 ? "etapa {$ordem}" : 'nenhuma')
                    . ', assinatura na etapa ' . $posEtapa . '). '
                    . 'O documento tem de existir no processo antes da hora de enviar.';
            }
            break;
        }
        if (!$anexoOk) {
            $problemas[] = 'Falta o anexo obrigatório "' . self::ANEXO_ASSINATURA_NOME
                . '" na aba Anexos — sem ele não há o que assinar.';
        }

        // 3. A lista de signatários e seus campos.
        $signatarios = $cfg['signatarios'];

        // Papel duplicado geraria dois campos com o mesmo nome — e o processo
        // guarda valor por rótulo, então um sobrescreveria o outro em silêncio.
        $vistos = [];
        foreach ($signatarios as $s) {
            $chave = mb_strtolower($s['papel'], 'UTF-8');
            if (isset($vistos[$chave])) {
                $problemas[] = 'O papel "' . $s['papel'] . '" está repetido. '
                    . 'Cada papel gera um par de campos próprio e precisa ser único.';
            }
            $vistos[$chave] = true;
        }

        // Só cópia = ninguém assina. O documento sairia e nunca voltaria assinado.
        $assinam = array_filter($signatarios, fn($s) => $s['natureza'] === 'assina');
        if (empty($assinam)) {
            $problemas[] = 'Nenhum signatário está marcado como "Assina" — '
                . 'com todos apenas recebendo cópia, o documento nunca voltaria assinado.';
        }

        if (($cfg['origem'] ?? 'campos') === 'campos') {
            $nomesCampos = [];
            foreach ($campos as $campo) {
                $nomesCampos[trim((string) ($campo['nome'] ?? ''))] = $campo;
            }
            foreach ($signatarios as $s) {
                foreach (self::camposSignatario($s['papel'], $provedor) as $qual => $rotulo) {
                    if (!isset($nomesCampos[$rotulo])) {
                        $problemas[] = 'Falta o campo "' . $rotulo . '" na aba Campos.';
                        continue;
                    }
                    $campo = $nomesCampos[$rotulo];
                    // Telefone só é cobrado quando o tipo é SMS/WhatsApp — quem
                    // confere isso é o envio, que sabe o tipo escolhido.
                    if ($qual !== 'telefone' && empty($campo['obrigatorio'])) {
                        $problemas[] = 'O campo "' . $rotulo . '" precisa ser obrigatório — '
                            . 'sem ele o processo chega na assinatura sem saber para quem enviar.';
                    }
                    if ($qual === 'email' && ($campo['tipo'] ?? '') !== 'email') {
                        $problemas[] = 'O campo "' . $rotulo . '" precisa ser do tipo E-mail — '
                            . 'é para lá que vai o convite de assinatura.';
                    }
                    if ($qual === 'cpf' && ($campo['tipo'] ?? '') !== 'cpf_cnpj') {
                        $problemas[] = 'O campo "' . $rotulo . '" precisa ser do tipo CPF / CNPJ.';
                    }
                    if ($qual === 'telefone' && ($campo['tipo'] ?? '') !== 'telefone') {
                        $problemas[] = 'O campo "' . $rotulo . '" precisa ser do tipo Telefone.';
                    }
                    if ($qual === 'tipo'
                        && (($campo['tipo'] ?? '') !== 'select'
                            || ($campo['lista_id'] ?? '') !== self::LISTA_TIPOS_ASSINATURA_ID)
                    ) {
                        $problemas[] = 'O campo "' . $rotulo . '" precisa ser uma Seleção de lista '
                            . 'com a fonte "Tipos de assinatura (Clicksign)".';
                    }
                }
            }
        }

        // Clicksign: exatamente dois signatários (a config já força, mas um
        // template salvo à mão no banco poderia fugir disso).
        if ($provedor === 'clicksign' && count($signatarios) !== self::SIGNATARIOS_CLICKSIGN) {
            $problemas[] = 'A assinatura pela Clicksign exige exatamente '
                . self::SIGNATARIOS_CLICKSIGN . ' signatários.';
        }

        return $problemas;
    }

    /** Rótulo de quem entra sem tipo de nota configurado no template. */
    public const ANEXO_SEM_TIPO = 'Documento importado (PDF)';

    /**
     * Nome do tipo de anexo em que o documento fiscal do processo deve entrar,
     * segundo os `tipos_anexo` da versão do template.
     *
     * Existe porque a checagem de obrigatórios compara nome com nome
     * (`nf_processo_anexos.tipo_anexo_nome` × o `nome` do tipo no template, em
     * `Process::anexosPendentesDaEtapa` e `Process::checklistObrigatorios`).
     * Gravar a nota com um rótulo próprio deixava o template pedindo "Nota
     * Fiscal" para um processo que já tinha a nota anexada — pendência de
     * mentira, que só sumia quando alguém subia de novo o MESMO arquivo.
     * Por isso devolvemos o nome EXATO configurado, e não um rótulo nosso.
     *
     * @param string|null $tiposAnexoJson coluna `tipos_anexo` da versão
     */
    public static function tipoAnexoDaNota(?string $tiposAnexoJson): string
    {
        $tipos = json_decode((string) $tiposAnexoJson, true) ?: [];

        // Do mais específico para o mais frouxo: "Nota Fiscal" exato ganha de
        // "Nota Fiscal de Serviço", que ganha de um "NF" solto. Assim um
        // template com vários documentos não manda a nota para o slot errado.
        $niveis = [
            static fn (string $n): bool => $n === 'NOTA FISCAL',
            static fn (string $n): bool => str_contains($n, 'NOTA FISCAL')
                                        || str_contains($n, 'DANFE')
                                        || str_contains($n, 'DANFSE'),
            static fn (string $n): bool => $n === 'NOTA'
                                        || (bool) preg_match('/(^| )(NF|NFE|NFSE|NF-E|NFS-E)( |$)/', $n),
        ];

        foreach ($niveis as $casa) {
            foreach ($tipos as $tipo) {
                $nome = trim((string) ($tipo['nome'] ?? ''));
                if ($nome !== '' && $casa(self::normalizar($nome))) {
                    return $nome;
                }
            }
        }

        return self::ANEXO_SEM_TIPO;
    }

    /** Maiúsculas, sem acento, espaços únicos — só para comparar nomes. */
    private static function normalizar(string $texto): string
    {
        $texto = mb_strtoupper(trim($texto), 'UTF-8');
        $texto = strtr($texto, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ]);
        return preg_replace('/\s+/', ' ', $texto);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LISTAGEM
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lista todos os templates com dados da versão publicada e do rascunho.
     */
    public static function listAll(array $filtros = [], int $offset = 0, int $limit = 20): array
    {
        $pdo = Database::getInstance();

        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['busca'])) {
            $where[]          = 't.nome LIKE :busca';
            $params[':busca'] = '%' . $filtros['busca'] . '%';
        }

        if (!empty($filtros['setor_id'])) {
            $where[]              = 'COALESCE(vp.setor_id, vr.setor_id) = :setor_id';
            $params[':setor_id']  = $filtros['setor_id'];
        }

        if (!empty($filtros['tipo_nota_id'])) {
            $where[]                  = 'COALESCE(vp.tipo_nota_id, vr.tipo_nota_id) = :tipo_nota_id';
            $params[':tipo_nota_id']  = $filtros['tipo_nota_id'];
        }

        if (isset($filtros['tem_financeiro']) && $filtros['tem_financeiro'] !== '') {
            $where[]                    = 'COALESCE(vp.tem_financeiro, vr.tem_financeiro) = :tem_fin';
            $params[':tem_fin']         = (int) $filtros['tem_financeiro'];
        }

        if (!empty($filtros['ativo']) && $filtros['ativo'] !== 'todos') {
            if ($filtros['ativo'] === 'publicado') {
                $where[] = 'vp.id IS NOT NULL';
            } elseif ($filtros['ativo'] === 'rascunho') {
                $where[] = 'vp.id IS NULL AND vr.id IS NOT NULL AND vr.status = \'rascunho\'';
            } elseif ($filtros['ativo'] === 'teste') {
                $where[] = 'vr.id IS NOT NULL AND vr.status IN (\'teste_interno\', \'uat\')';
            } elseif ($filtros['ativo'] === 'inativo') {
                // "Inativos" = fluxo DESATIVADO pelo super admin. É o mesmo
                // sentido do badge "Inativo" que a linha mostra, e é por ele
                // que o usuário procura.
                //
                // Antes esta opção significava "template sem versão nenhuma" —
                // duas coisas diferentes com o mesmo rótulo, e filtrar não
                // achava o fluxo desativado. O sem-versão continua visível em
                // "Todos os status", com o próprio badge "Sem versão".
                $where[] = 't.ativo = 0';
            }
        }

        $whereClause = implode(' AND ', $where);

        $sql = "
            SELECT
                t.id,
                t.nome,
                t.ativo,
                t.desativado_em,
                t.criado_em,
                t.criado_por,
                vp.id            AS versao_publicada_id,
                vp.numero_versao AS versao_publicada_numero,
                vp.publicado_em,
                vr.id            AS versao_rascunho_id,
                vr.numero_versao AS versao_rascunho_numero,
                vr.status        AS versao_rascunho_status,
                COALESCE(vp.setor_id, vr.setor_id) AS setor_id,
                COALESCE(vp.tipo_nota_id, vr.tipo_nota_id) AS tipo_nota_id,
                COALESCE(vp.tem_financeiro, vr.tem_financeiro, 0) AS tem_financeiro,
                s.nome AS setor_nome,
                CASE
                    WHEN vp.id IS NOT NULL AND vr.id IS NOT NULL THEN 'publicado_com_rascunho'
                    WHEN vp.id IS NOT NULL                       THEN 'publicado'
                    WHEN vr.id IS NOT NULL                       THEN vr.status
                    ELSE 'sem_versao'
                END AS status_efetivo
            FROM nf_templates t
            LEFT JOIN nf_template_versoes vp ON vp.id = t.versao_publicada_id
            LEFT JOIN nf_template_versoes vr ON vr.id = t.versao_rascunho_id
            LEFT JOIN setores s ON s.id = COALESCE(vp.setor_id, vr.setor_id)
            WHERE $whereClause
            ORDER BY t.nome ASC
            LIMIT :lim OFFSET :off
        ";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit,  \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function countAll(array $filtros = []): int
    {
        $pdo    = Database::getInstance();
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['busca'])) {
            $where[]          = 't.nome LIKE :busca';
            $params[':busca'] = '%' . $filtros['busca'] . '%';
        }
        if (!empty($filtros['setor_id'])) {
            $where[]              = 'COALESCE(vp.setor_id, vr.setor_id) = :setor_id';
            $params[':setor_id']  = $filtros['setor_id'];
        }
        if (!empty($filtros['tipo_nota_id'])) {
            $where[]                  = 'COALESCE(vp.tipo_nota_id, vr.tipo_nota_id) = :tipo_nota_id';
            $params[':tipo_nota_id']  = $filtros['tipo_nota_id'];
        }
        if (isset($filtros['tem_financeiro']) && $filtros['tem_financeiro'] !== '') {
            $where[]            = 'COALESCE(vp.tem_financeiro, vr.tem_financeiro) = :tem_fin';
            $params[':tem_fin'] = (int) $filtros['tem_financeiro'];
        }
        if (!empty($filtros['ativo']) && $filtros['ativo'] !== 'todos') {
            if ($filtros['ativo'] === 'publicado') {
                $where[] = 'vp.id IS NOT NULL';
            } elseif ($filtros['ativo'] === 'rascunho') {
                $where[] = 'vp.id IS NULL AND vr.id IS NOT NULL AND vr.status = \'rascunho\'';
            } elseif ($filtros['ativo'] === 'teste') {
                $where[] = 'vr.id IS NOT NULL AND vr.status IN (\'teste_interno\', \'uat\')';
            } elseif ($filtros['ativo'] === 'inativo') {
                // "Inativos" = fluxo DESATIVADO pelo super admin. É o mesmo
                // sentido do badge "Inativo" que a linha mostra, e é por ele
                // que o usuário procura.
                //
                // Antes esta opção significava "template sem versão nenhuma" —
                // duas coisas diferentes com o mesmo rótulo, e filtrar não
                // achava o fluxo desativado. O sem-versão continua visível em
                // "Todos os status", com o próprio badge "Sem versão".
                $where[] = 't.ativo = 0';
            }
        }

        $whereClause = implode(' AND ', $where);
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_templates t
             LEFT JOIN nf_template_versoes vp ON vp.id = t.versao_publicada_id
             LEFT JOIN nf_template_versoes vr ON vr.id = t.versao_rascunho_id
             WHERE $whereClause"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BUSCA / FIND
    // ─────────────────────────────────────────────────────────────────────────

    /** Retorna template pelo ID (sem versões). */
    public static function findById(string $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM nf_templates WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Retorna versão pelo ID com dados decodificados. */
    public static function findVersaoById(string $versaoId): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM nf_template_versoes WHERE id = :id');
        $stmt->execute([':id' => $versaoId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;

        // Decodificar JSON
        $row['campos']          = json_decode($row['campos'],          true) ?? [];
        $row['etapas']          = json_decode($row['etapas'],          true) ?? [];
        $row['tipos_anexo']     = json_decode($row['tipos_anexo'],     true) ?? [];
        $row['uat_usuario_ids'] = json_decode($row['uat_usuario_ids'] ?? '[]', true) ?? [];
        $row['assinatura_config'] = json_decode($row['assinatura_config'] ?? '{}', true) ?? [];

        return $row;
    }

    /**
     * Retorna template com suas versões (publicada + rascunho).
     * Decodifica JSONs das versões.
     */
    public static function findWithVersoes(string $id): ?array
    {
        $template = self::findById($id);
        if (!$template) return null;

        if ($template['versao_publicada_id']) {
            $template['versao_publicada'] = self::findVersaoById($template['versao_publicada_id']);
        } else {
            $template['versao_publicada'] = null;
        }

        if ($template['versao_rascunho_id']) {
            $template['versao_rascunho'] = self::findVersaoById($template['versao_rascunho_id']);
        } else {
            $template['versao_rascunho'] = null;
        }

        return $template;
    }

    /** Lista todas as versões de um template. */
    public static function listVersoes(string $templateId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, numero_versao, status, criado_em, publicado_em
             FROM nf_template_versoes
             WHERE template_id = :tid
             ORDER BY numero_versao DESC'
        );
        $stmt->execute([':tid' => $templateId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CRIAÇÃO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cria template + primeira versão rascunho.
     * Retorna ID do template criado.
     */
    public static function criar(string $nome, string $criadoPor): string
    {
        $pdo = Database::getInstance();

        $templateId = self::uuid();
        $versaoId   = self::uuid();

        $pdo->beginTransaction();

        try {
            // 1. Inserir template (sem versão ainda)
            $stmtT = $pdo->prepare(
                'INSERT INTO nf_templates (id, nome, versao_rascunho_id, criado_por)
                 VALUES (:id, :nome, :vrascunho, :criado_por)'
            );
            $stmtT->execute([
                ':id'         => $templateId,
                ':nome'       => $nome,
                ':vrascunho'  => $versaoId,
                ':criado_por' => $criadoPor,
            ]);

            // 2. Inserir versão 1 como rascunho
            $stmtV = $pdo->prepare(
                'INSERT INTO nf_template_versoes
                 (id, template_id, numero_versao, status, criado_por)
                 VALUES (:id, :tid, 1, "rascunho", :criado_por)'
            );
            $stmtV->execute([
                ':id'         => $versaoId,
                ':tid'        => $templateId,
                ':criado_por' => $criadoPor,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $templateId;
    }

    /**
     * Duplica um template existente com um novo nome.
     * Copia a versão ativa (publicada ou rascunho) como rascunho v1 do novo template.
     */
    public static function duplicar(string $templateId, string $novoNome, string $criadoPor): string
    {
        $pdo = Database::getInstance();

        $template = self::findWithVersoes($templateId);
        if (!$template) {
            throw new \RuntimeException("Template não encontrado.");
        }

        $versaoOrigem = $template['versao_publicada'] ?? $template['versao_rascunho'] ?? null;
        if (!$versaoOrigem) {
            throw new \RuntimeException("Template não possui versão para duplicar.");
        }

        $novoTemplateId = self::uuid();
        $novaVersaoId   = self::uuid();

        $pdo->beginTransaction();

        try {
            $stmtT = $pdo->prepare(
                'INSERT INTO nf_templates (id, nome, versao_rascunho_id, criado_por)
                 VALUES (?, ?, ?, ?)'
            );
            $stmtT->execute([$novoTemplateId, $novoNome, $novaVersaoId, $criadoPor]);

            $stmtV = $pdo->prepare(
                "INSERT INTO nf_template_versoes
                 (id, template_id, numero_versao, status,
                  filial_id, departamento_id, setor_id, tipo_nota_id, valor_min, valor_max,
                  tem_financeiro, permite_sem_nota, tem_rateio, tem_tipo_despesa, tipo_despesa_obrigatorio,
                  libera_excecao_horario, tem_assinatura, assinatura_config,
                  campos, etapas, tipos_anexo, criado_por)
                 VALUES (?, ?, 1, 'rascunho', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmtV->execute([
                $novaVersaoId,
                $novoTemplateId,
                $versaoOrigem['filial_id'],
                $versaoOrigem['departamento_id'],
                $versaoOrigem['setor_id'],
                $versaoOrigem['tipo_nota_id'],
                $versaoOrigem['valor_min'],
                $versaoOrigem['valor_max'],
                $versaoOrigem['tem_financeiro'] ?? 0,
                $versaoOrigem['permite_sem_nota'] ?? 0,
                $versaoOrigem['tem_rateio'] ?? 0,
                $versaoOrigem['tem_tipo_despesa'] ?? 0,
                $versaoOrigem['tipo_despesa_obrigatorio'] ?? 0,
                $versaoOrigem['libera_excecao_horario'] ?? 0,
                $versaoOrigem['tem_assinatura'] ?? 0,
                json_encode($versaoOrigem['assinatura_config'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($versaoOrigem['campos'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($versaoOrigem['etapas'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($versaoOrigem['tipos_anexo'] ?? [], JSON_UNESCAPED_UNICODE),
                $criadoPor,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $novoTemplateId;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ATUALIZAÇÃO DE VERSÃO (RASCUNHO)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Salva dados gerais do template e da versão rascunho.
     * Só atualiza campos que forem passados em $dados.
     */
    public static function salvarRascunho(string $versaoId, array $dados): void
    {
        $pdo = Database::getInstance();

        // Colunas permitidas para atualização direta
        $colunasPermitidas = [
            'filial_id', 'departamento_id', 'setor_id', 'tipo_nota_id',
            'valor_min', 'valor_max', 'tem_financeiro', 'permite_sem_nota', 'tem_rateio',
            'tem_tipo_despesa', 'tipo_despesa_obrigatorio', 'libera_excecao_horario',
            'tem_assinatura',
        ];

        $sets    = [];
        $params  = [':id' => $versaoId];

        foreach ($colunasPermitidas as $col) {
            if (array_key_exists($col, $dados)) {
                $sets[]         = "$col = :$col";
                $params[":$col"] = $dados[$col];
            }
        }

        // JSONs — sempre substituídos inteiros
        $jsons = ['campos', 'etapas', 'tipos_anexo', 'uat_usuario_ids', 'assinatura_config'];
        foreach ($jsons as $col) {
            if (array_key_exists($col, $dados)) {
                $sets[]          = "$col = :$col";
                $params[":$col"] = is_string($dados[$col])
                    ? $dados[$col]
                    : json_encode($dados[$col], JSON_UNESCAPED_UNICODE);
            }
        }

        if (empty($sets)) return;

        $stmt = $pdo->prepare(
            'UPDATE nf_template_versoes SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        $stmt->execute($params);
    }

    /** Atualiza o nome do template. */
    public static function atualizarNome(string $templateId, string $nome): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('UPDATE nf_templates SET nome = :nome WHERE id = :id');
        $stmt->execute([':nome' => $nome, ':id' => $templateId]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ATIVAR / DESATIVAR O FLUXO
    //
    // Desativar NÃO é excluir e não é despublicar:
    //   - excluir apaga o fluxo, e é bloqueado quando existe processo aberto
    //     por ele — o histórico não pode ficar órfão;
    //   - despublicar mexe na VERSÃO, e o fluxo volta a ser rascunho;
    //   - desativar deixa tudo de pé e fecha uma porta só: a de abrir processo
    //     NOVO. O que já está rodando segue até o fim, sem saber de nada.
    //
    // Quem barra de verdade é Process::criar(); daqui sai só o estado.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Liga/desliga o fluxo. Só o super_admin chega aqui (gate no controller).
     *
     * @param bool $ativo true = reativar · false = desativar
     * @return bool false quando o template não existe
     */
    public static function setAtivo(string $templateId, bool $ativo, string $usuarioId): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE nf_templates
                SET ativo          = :ativo,
                    desativado_em  = :em,
                    desativado_por = :por
              WHERE id = :id'
        );
        $stmt->execute([
            ':ativo' => $ativo ? 1 : 0,
            // Reativar limpa o rastro: quem/quando só descreve a desativação
            // que está valendo agora, nunca uma antiga já desfeita.
            ':em'    => $ativo ? null : date('Y-m-d H:i:s'),
            ':por'   => $ativo ? null : $usuarioId,
            ':id'    => $templateId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Quantos processos EM ANDAMENTO existem neste fluxo.
     *
     * Mostrado na confirmação de desativar: é a diferença entre fechar um fluxo
     * que já esfriou e fechar um com trabalho em cima. Nenhum deles é impedido
     * — os abertos seguem normalmente —, mas quem decide merece ver o número.
     */
    public static function processosEmAndamento(string $templateId): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM nf_processos p
               JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
              WHERE tv.template_id = ?
                AND p.deletado_em IS NULL
                AND p.status IN ('aberto', 'devolvido', 'cancelamento_solicitado')"
        );
        $stmt->execute([$templateId]);
        return (int) $stmt->fetchColumn();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VERSIONAMENTO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Avança status da versão: rascunho → teste_interno → uat → publicado.
     * Ao publicar: atualiza nf_templates.versao_publicada_id e limpa versao_rascunho_id.
     */
    public static function avancarStatus(string $versaoId, string $novoStatus, string $usuarioId): bool
    {
        $statusPermitidos = ['rascunho', 'teste_interno', 'uat', 'publicado', 'arquivado'];
        if (!in_array($novoStatus, $statusPermitidos, true)) return false;

        $pdo     = Database::getInstance();
        $versao  = self::findVersaoById($versaoId);
        if (!$versao) return false;

        $pdo->beginTransaction();

        try {
            $params = [':status' => $novoStatus, ':id' => $versaoId];

            if ($novoStatus === 'publicado') {
                $params[':publicado_por'] = $usuarioId;
                $pdo->prepare(
                    'UPDATE nf_template_versoes
                     SET status = :status, publicado_em = NOW(), publicado_por = :publicado_por
                     WHERE id = :id'
                )->execute($params);

                // Arquivar versão publicada anterior, se existir
                $template = self::findById($versao['template_id']);
                if ($template && $template['versao_publicada_id']) {
                    $pdo->prepare(
                        'UPDATE nf_template_versoes SET status = "arquivado" WHERE id = :id'
                    )->execute([':id' => $template['versao_publicada_id']]);
                }

                // Atualizar referências no template
                $pdo->prepare(
                    'UPDATE nf_templates
                     SET versao_publicada_id = :vpub, versao_rascunho_id = NULL
                     WHERE id = :id'
                )->execute([
                    ':vpub' => $versaoId,
                    ':id'   => $versao['template_id'],
                ]);
            } else {
                $pdo->prepare(
                    'UPDATE nf_template_versoes SET status = :status WHERE id = :id'
                )->execute($params);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Cria novo rascunho a partir da versão publicada atual.
     * Retorna ID da nova versão.
     */
    public static function criarNovoRascunho(string $templateId, string $criadoPor): string
    {
        $pdo      = Database::getInstance();
        $template = self::findById($templateId);
        if (!$template) throw new \RuntimeException('Template não encontrado');
        if ($template['versao_rascunho_id']) throw new \RuntimeException('Já existe um rascunho ativo');

        // Última versão para calcular numero_versao
        $stmt = $pdo->prepare(
            'SELECT MAX(numero_versao) FROM nf_template_versoes WHERE template_id = :tid'
        );
        $stmt->execute([':tid' => $templateId]);
        $ultimaVersao = (int) $stmt->fetchColumn();

        $novaVersaoId = self::uuid();

        // Clonar dados da versão publicada
        $base = $template['versao_publicada_id']
            ? self::findVersaoById($template['versao_publicada_id'])
            : null;

        $stmt = $pdo->prepare(
            'INSERT INTO nf_template_versoes
             (id, template_id, numero_versao, status,
              filial_id, departamento_id, setor_id, tipo_nota_id, valor_min, valor_max,
              tem_financeiro, permite_sem_nota, tem_rateio, tem_tipo_despesa, tipo_despesa_obrigatorio,
              libera_excecao_horario, tem_assinatura, assinatura_config,
              campos, etapas, tipos_anexo, criado_por)
             VALUES
             (:id, :tid, :nv, "rascunho",
              :filial, :depto, :setor, :tipo_nota, :vmin, :vmax,
              :tf, :psn, :tr, :ttd, :tdo, :leh, :ta, :acfg,
              :campos, :etapas, :anexos, :criado_por)'
        );
        $stmt->execute([
            ':id'         => $novaVersaoId,
            ':tid'        => $templateId,
            ':nv'         => $ultimaVersao + 1,
            ':filial'     => $base['filial_id']       ?? null,
            ':depto'      => $base['departamento_id'] ?? null,
            ':setor'      => $base['setor_id']        ?? null,
            ':tipo_nota'  => $base['tipo_nota_id']  ?? null,
            ':vmin'       => $base['valor_min']      ?? null,
            ':vmax'       => $base['valor_max']      ?? null,
            ':tf'         => $base['tem_financeiro'] ?? 0,
            ':psn'        => $base['permite_sem_nota'] ?? 0,
            ':tr'         => $base['tem_rateio']     ?? 0,
            ':ttd'        => $base['tem_tipo_despesa']         ?? 0,
            ':tdo'        => $base['tipo_despesa_obrigatorio'] ?? 0,
            ':leh'        => $base['libera_excecao_horario']   ?? 0,
            ':ta'         => $base['tem_assinatura']           ?? 0,
            ':acfg'       => json_encode($base['assinatura_config'] ?? [], JSON_UNESCAPED_UNICODE),
            ':campos'     => json_encode($base['campos']      ?? [], JSON_UNESCAPED_UNICODE),
            ':etapas'     => json_encode($base['etapas']      ?? [], JSON_UNESCAPED_UNICODE),
            ':anexos'     => json_encode($base['tipos_anexo'] ?? [], JSON_UNESCAPED_UNICODE),
            ':criado_por' => $criadoPor,
        ]);

        // Atualizar referência no template
        $pdo->prepare(
            'UPDATE nf_templates SET versao_rascunho_id = :vr WHERE id = :id'
        )->execute([':vr' => $novaVersaoId, ':id' => $templateId]);

        return $novaVersaoId;
    }

    /**
     * Descarta o rascunho (versão não-publicada) de um template que possui versão publicada.
     * Remove a versão rascunho do banco e limpa a referência no template.
     * Só permite descartar se houver versão publicada e nenhum processo vinculado ao rascunho.
     */
    public static function descartarRascunho(string $templateId): bool
    {
        $pdo      = Database::getInstance();
        $template = self::findById($templateId);
        if (!$template) return false;

        $rascunhoId = $template['versao_rascunho_id'] ?? null;
        if (!$rascunhoId) return false;
        if (!$template['versao_publicada_id']) return false;

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM nf_processos WHERE template_versao_id = :vid AND deletado_em IS NULL'
        );
        $stmt->execute([':vid' => $rascunhoId]);
        if ((int) $stmt->fetchColumn() > 0) return false;

        $pdo->prepare('DELETE FROM nf_template_versoes WHERE id = :id')->execute([':id' => $rascunhoId]);
        $pdo->prepare('UPDATE nf_templates SET versao_rascunho_id = NULL WHERE id = :id')->execute([':id' => $templateId]);

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EXCLUSÃO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Exclui template (somente se não tiver versão publicada e sem processos vinculados).
     */
    public static function excluir(string $templateId): bool
    {
        $pdo      = Database::getInstance();
        $template = self::findById($templateId);
        if (!$template) return false;

        // Não permitir excluir se tiver versão publicada
        if ($template['versao_publicada_id']) return false;

        // Verificar processos vinculados (usando versões do template)
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM nf_processos p
             JOIN nf_template_versoes v ON v.id = p.template_versao_id
             WHERE v.template_id = :tid'
        );
        $stmt->execute([':tid' => $templateId]);
        if ((int) $stmt->fetchColumn() > 0) return false;

        // Excluir versões e depois o template
        $pdo->prepare('DELETE FROM nf_template_versoes WHERE template_id = :tid')->execute([':tid' => $templateId]);
        $pdo->prepare('DELETE FROM nf_templates WHERE id = :id')->execute([':id' => $templateId]);

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BUSCA PARA WIZARD DE PROCESSO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Busca versões publicadas compatíveis com loja + setor + tipo de nota + valor.
     * Retorna lista ordenada do mais específico para o mais genérico.
     */
    public static function buscarCompativel(
        ?string $filialId,
        ?string $setorId,
        ?string $tipoNotaId,
        ?float  $valor = null
    ): array {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT v.*, t.nome AS template_nome
             FROM nf_template_versoes v
             JOIN nf_templates t ON t.id = v.template_id
             WHERE v.status = "publicado"
               AND (v.filial_id   IS NULL OR v.filial_id   = :filial)
               AND (v.setor_id    IS NULL OR v.setor_id    = :setor)
               AND (v.tipo_nota_id IS NULL OR v.tipo_nota_id = :tipo_nota)
               AND (v.valor_min IS NULL OR :valor >= v.valor_min)
               AND (v.valor_max IS NULL OR :valor <= v.valor_max)
             ORDER BY
               (v.filial_id IS NOT NULL) DESC,
               (v.setor_id IS NOT NULL) DESC,
               (v.tipo_nota_id IS NOT NULL) DESC'
        );
        $stmt->execute([
            ':filial'    => $filialId,
            ':setor'     => $setorId,
            ':tipo_nota' => $tipoNotaId,
            ':valor'     => $valor,
        ]);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['campos']      = json_decode($r['campos'],      true) ?? [];
            $r['etapas']      = json_decode($r['etapas'],      true) ?? [];
            $r['tipos_anexo'] = json_decode($r['tipos_anexo'], true) ?? [];
        }
        unset($r);

        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER
    // ─────────────────────────────────────────────────────────────────────────

    private static function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
