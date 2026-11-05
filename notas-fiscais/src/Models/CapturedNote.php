<?php
// @versao Cap_05 V_31_21/10/2026
declare(strict_types=1);

/**
 * Model das notas capturadas via integração (Brobot / SEFAZ).
 *
 * Nota capturada é staging: NÃO é processo. Vira processo por regra de
 * pré-fluxo (nf_captura_regras) ou por vinculação manual do operador.
 */
class CapturedNote
{
    // ─────────────────────────────────────────
    // Listagem / consulta
    // ─────────────────────────────────────────

    /**
     * Lista notas da inbox. Por padrão apenas não resolvidas
     * (status capturada ou sugerida) — a tela só mostra o que precisa de ação.
     */
    /**
     * Aplica os recortes de visibilidade do usuário logado: escopo por filial
     * (CNPJ de destino) e sigilo por template. Fora do contexto web (CLI do
     * poller/brobot) não muda nada — a captura precisa varrer todas as filiais.
     *
     * @param list<string> $where
     * @param list<mixed>  $params
     */
    private static function aplicarEscopoVisibilidade(array &$where, array &$params, string $alias): void
    {
        // Contexto sem os helpers carregados (CLI enxuto: brobot, SLA): sem
        // recorte — esses jobs precisam varrer tudo e não têm sessão.
        if (!class_exists('TemplateSigilo') || !class_exists('FilialScope') || !class_exists('ProcessAccess')) {
            return;
        }

        [$filial, $pFil] = FilialScope::sqlRestringeSessao("{$alias}.destinatario_cnpj");
        if ($filial !== '') {
            $where[] = $filial;
            $params  = array_merge($params, $pFil);
        }

        [$sigilo, $pSig] = TemplateSigilo::sqlExcluiNotasSessao($alias);
        if ($sigilo !== '') {
            $where[] = $sigilo;
            $params  = array_merge($params, $pSig);
        }

        // Nota ainda SEM processo continua visível para todos — a inbox é
        // coletiva. Depois de vinculada, segue a visibilidade do processo.
        $participa = ProcessAccess::sqlParticipaNotaSessao($alias);
        if ($participa !== '') {
            $where[] = $participa;
        }

        // Arquivada por "duplicada" ou "fora do período de produção" é ruído
        // de go-live/teste — só o super_admin precisa ver pra auditoria
        // (pedido do usuário em 21/09/2026, depois de aparecer pra qualquer
        // perfil na tela Pesquisar Processos). Fora isso some da tela e do
        // findById() direto, não só da listagem.
        if (class_exists('Auth') && !empty($_SESSION['usuario_id'] ?? null) && Auth::perfil() !== 'super_admin') {
            $where[]  = "NOT ({$alias}.status = 'ignorada' AND ({$alias}.motivo_ignorada LIKE ? OR {$alias}.motivo_ignorada LIKE ?))";
            $params[] = 'Duplicada do processo%';
            $params[] = '%fora do período de produção%';
        }
    }

    /** Monta WHERE + params compartilhados entre list() e count(). */
    private static function sqlFiltros(array $filters, array &$params): string
    {
        $where = [];

        // Escopo de visibilidade do usuário logado — filial (CNPJ de destino)
        // e sigilo por template (nota já vinculada a processo de RH/Jurídico).
        self::aplicarEscopoVisibilidade($where, $params, 'c');

        // Só notas com XML completo: resumo (resNFe) não tem número/CFOP/itens
        // e some daqui até o procNFe chegar.
        $where[] = 'c.xml_completo = 1';

        if (!empty($filters['status']) && $filters['status'] !== 'todos') {
            $where[]  = 'c.status = ?';
            $params[] = $filters['status'];
        } elseif (($filters['status'] ?? '') !== 'todos') {
            // Padrão "Pendentes": só o que precisa de ação — INCLUSIVE com
            // busca (o select promete pendentes; a busca não muda o recorte).
            // Para varrer arquivadas/ignoradas e vinculadas, o usuário
            // seleciona "Todos os status" explicitamente.
            $where[] = "c.status IN ('capturada', 'sugerida')";
        }

        // Filtro de lojas da tela: um id (link antigo) ou vários (`filial_id[]`).
        // Não amplia visibilidade — o escopo já entrou em aplicarEscopoVisibilidade().
        $fIds = array_values(array_unique(array_filter(
            array_map(static fn($v): string => trim((string) $v), (array) ($filters['filial_id'] ?? [])),
            static fn(string $v): bool => $v !== ''
        )));
        if ($fIds !== []) {
            $where[] = 'c.filial_id IN (' . implode(',', array_fill(0, count($fIds), '?')) . ')';
            $params  = array_merge($params, $fIds);
        }

        if (!empty($filters['tipo'])) {
            $where[]  = 'c.tipo_documento = ?';
            $params[] = $filters['tipo'];
        }

        if (!empty($filters['busca'])) {
            $where[]  = '(c.numero_nf LIKE ? OR c.fornecedor_nome LIKE ? OR c.chave_nf LIKE ?)';
            $like     = '%' . $filters['busca'] . '%';
            array_push($params, $like, $like, $like);
        }

        // Só capturas que ainda não viraram processo (usado pela pesquisa de
        // processos p/ surfacar notas arquivadas/ignoradas que "somem" da busca).
        if (!empty($filters['sem_processo'])) {
            $where[] = 'c.processo_id IS NULL';
        }

        // "Todos os status" sem busca/filial/tipo deixa o WHERE vazio
        return $where ? implode(' AND ', $where) : '1';
    }

    public static function list(array $filters = [], int $offset = 0, int $limit = 50): array
    {
        $pdo      = Database::getInstance();
        $params   = [];
        $whereStr = self::sqlFiltros($filters, $params);

        $stmt = $pdo->prepare(
            "SELECT c.*,
                    r.nome        AS regra_nome,
                    r.modo        AS regra_modo,
                    r.template_id AS regra_template_id,
                    r.campos_json AS regra_campos_json,
                    t.nome        AS regra_template_nome,
                    p.codigo      AS processo_codigo
             FROM nf_notas_capturadas c
             LEFT JOIN nf_captura_regras r ON r.id = c.regra_id
             LEFT JOIN nf_templates t      ON t.id = r.template_id
             LEFT JOIN nf_processos p      ON p.id = c.processo_id
             WHERE {$whereStr}
             ORDER BY c.capturada_em DESC
             LIMIT ? OFFSET ?"
        );
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Total de notas no mesmo recorte de list() — para a paginação. */
    public static function count(array $filters = []): int
    {
        $pdo      = Database::getInstance();
        $params   = [];
        $whereStr = self::sqlFiltros($filters, $params);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM nf_notas_capturadas c WHERE {$whereStr}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Totais para os cards do topo da tela. */
    public static function getTotais(): array
    {
        $pdo = Database::getInstance();

        // Os cards do topo respeitam o mesmo recorte da lista — contagem e
        // valor somado de outra filial (ou de nota sigilosa) também vazam.
        $where  = [];
        $params = [];
        self::aplicarEscopoVisibilidade($where, $params, 'c');
        $where[] = 'c.xml_completo = 1';
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare(
            "SELECT
                SUM(status IN ('capturada','sugerida'))                          AS pendentes,
                SUM(status = 'sugerida')                                         AS sugeridas,
                SUM(CASE WHEN status IN ('capturada','sugerida') THEN valor ELSE 0 END) AS valor_pendente,
                SUM(status = 'vinculada' AND DATE(vinculada_em) = CURDATE())     AS vinculadas_hoje
             FROM nf_notas_capturadas c
             {$whereSql}"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'pendentes'       => (int) ($row['pendentes'] ?? 0),
            'sugeridas'       => (int) ($row['sugeridas'] ?? 0),
            'valor_pendente'  => (float) ($row['valor_pendente'] ?? 0),
            'vinculadas_hoje' => (int) ($row['vinculadas_hoje'] ?? 0),
        ];
    }

    public static function findById(string $id): ?array
    {
        $pdo  = Database::getInstance();
        // O mesmo recorte da lista vale aqui: sem isso bastaria trocar o id na
        // URL para baixar o XML/DANFE de uma nota de outra filial ou sigilosa.
        $where  = ['c.id = ?'];
        $params = [$id];
        self::aplicarEscopoVisibilidade($where, $params, 'c');

        $stmt = $pdo->prepare(
            "SELECT c.* FROM nf_notas_capturadas c WHERE " . implode(' AND ', $where) . " LIMIT 1"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) return null;

        // Captura por resumo (resNFe) não traz nNF: o número existe só dentro da
        // chave (pos. 26–34). Sem isto o processo nascia com numero_nf vazio e a
        // checagem de duplicidade não o casava com o lançamento manual.
        if (trim((string) ($row['numero_nf'] ?? '')) === '' && strlen((string) ($row['chave_nf'] ?? '')) === 44
            && ctype_digit((string) $row['chave_nf'])) {
            $row['numero_nf'] = ltrim(substr($row['chave_nf'], 25, 9), '0');
        }
        return $row;
    }

    // ─────────────────────────────────────────
    // Ações da inbox
    // ─────────────────────────────────────────

    public static function ignorar(string $id, string $usuarioId, string $motivo = ''): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            // ambigua_regras zera junto: nota resolvida saiu da disputa entre
            // regras, e o selo não pode sobreviver ao motivo dela ter saído.
            "UPDATE nf_notas_capturadas
             SET status = 'ignorada', ambigua_regras = NULL,
                 vinculada_por = ?, vinculada_em = NOW(), motivo_ignorada = ?
             WHERE id = ? AND status IN ('capturada','sugerida')"
        );
        $stmt->execute([$usuarioId, $motivo ?: null, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Vincula a nota a um processo recém-criado a partir de um template.
     *
     * @param array       $camposFormulario payload de campos dinâmicos do processo
     * @param string|null $usuarioId        NULL = automação (usa o criador da regra no processo)
     * @param string|null $regraId          regra responsável, se houver
     * @return array{id: string, codigo: string} dados do processo criado
     */
    public static function vincular(
        string $notaId,
        string $templateId,
        array $camposFormulario,
        string $criadoPor,
        ?string $usuarioAcao = null,
        ?string $regraId = null
    ): array {
        $pdo  = Database::getInstance();
        $nota = self::findById($notaId);

        if (!$nota || !in_array($nota['status'], ['capturada', 'sugerida'], true)) {
            throw new RuntimeException('Nota não encontrada ou já resolvida.');
        }

        require_once SRC . '/Models/Process.php';

        // Anti-duplicidade: a mesma nota pode já ter virado processo por outro
        // caminho (lançamento manual no wizard). Bloqueia o vínculo — manual
        // recebe a mensagem; regra automática cai no catch de aplicarRegras e
        // a nota permanece na fila para decisão humana.
        $dup = Process::notaDuplicada(
            (string) $nota['numero_nf'],
            null,
            (string) $nota['fornecedor_cnpj'],
            (string) $nota['chave_nf']
        );
        if ($dup) {
            throw new RuntimeException(sprintf(
                'Esta nota já está lançada no processo %s (status: %s) — vínculo bloqueado para evitar duplicidade. Se for o caso, arquive a nota.',
                $dup['codigo'],
                $dup['status']
            ));
        }

        // Versão publicada do template + estrutura do fluxo
        $stmt = $pdo->prepare(
            "SELECT v.id AS versao_id, v.etapas, v.tem_financeiro, v.tipos_anexo
             FROM nf_templates t
             JOIN nf_template_versoes v ON v.id = t.versao_publicada_id
             WHERE t.id = ? LIMIT 1"
        );
        $stmt->execute([$templateId]);
        $tpl = $stmt->fetch();
        if (!$tpl) {
            throw new RuntimeException('Template sem versão publicada.');
        }

        $fornecedorId = self::garantirFornecedor($nota['fornecedor_cnpj'], $nota['fornecedor_nome']);

        // Parcelas vindas das duplicatas do XML — o vencimento e o valor já
        // estão na nota, não faz sentido o financeiro redigitar. Fluxo sem
        // financeiro ou nota sem duplicata seguem nascendo sem parcela.
        $parcelas = (bool) $tpl['tem_financeiro'] ? self::parcelasDaNota($nota) : [];

        $resultado = Process::criar([
            'template_versao_id' => $tpl['versao_id'],
            'fornecedor_id'      => $fornecedorId,
            'fornecedor_nome'    => $nota['fornecedor_nome'],
            'fornecedor_doc'     => $nota['fornecedor_cnpj'],
            'numero_nf'          => $nota['numero_nf'],
            'valor_nf'           => (float) $nota['valor'],
            'data_nf'            => $nota['data_emissao'],
            'chave_nf'           => $nota['chave_nf'],
            'filial_id'          => $nota['filial_id'],
            'filial_doc'         => $nota['destinatario_cnpj'],
            'dados_formulario'   => $camposFormulario,
            'etapas_template'    => json_decode($tpl['etapas'], true) ?: [],
            'tem_financeiro'     => (bool) $tpl['tem_financeiro'],
            'parcelas'           => $parcelas,
        ], $criadoPor);

        $stmt = $pdo->prepare(
            "UPDATE nf_notas_capturadas
             SET status = 'vinculada', ambigua_regras = NULL,
                 processo_id = ?, regra_id = ?, vinculada_por = ?, vinculada_em = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$resultado['id'], $regraId, $usuarioAcao, $notaId]);

        // Leva os documentos fiscais da nota para o processo (DANFE/DACTE primeiro, XML em seguida)
        self::anexarDocumentosFiscais($resultado['id'], $nota, $criadoPor, $tpl['tipos_anexo']);

        return $resultado;
    }

    /**
     * Vincula 2+ notas capturadas a um ÚNICO processo — ex.: nota de produto
     * (NF-e) + nota de serviço (NFS-e) do mesmo pedido, que juntas formam uma
     * única obrigação/boleto. A PRIMEIRA nota da lista é a principal (seus
     * dados vão nas colunas normais de nf_processos, igual vincular()); as
     * demais entram em nf_processo_documentos (papel='anexo_fiscal').
     *
     * Valor do processo = soma de todas as notas, pré-preenchido — mas é um
     * valor normal, editável depois na edição de campos do processo como
     * qualquer outro (decisão do usuário: já é assim hoje, manualmente, pro
     * boleto que cobre as duas notas).
     *
     * @param list<string> $notaIds
     */
    public static function vincularMultiplas(
        array $notaIds,
        string $templateId,
        array $camposFormulario,
        string $criadoPor,
        ?string $usuarioAcao = null,
        ?string $regraId = null
    ): array {
        $notaIds = array_values(array_unique($notaIds));
        if (count($notaIds) < 2) {
            throw new RuntimeException('Selecione ao menos 2 notas para combinar em um processo.');
        }

        $notas = [];
        foreach ($notaIds as $id) {
            $n = self::findById($id);
            if (!$n || !in_array($n['status'], ['capturada', 'sugerida'], true)) {
                throw new RuntimeException('Uma das notas selecionadas não foi encontrada ou já foi resolvida.');
            }
            $notas[] = $n;
        }

        require_once SRC . '/Models/Process.php';

        // Anti-duplicidade — cada nota, individualmente, contra nf_processos
        // (nota principal de outro processo) e contra nf_processo_documentos
        // (já anexada/remessa de outro processo).
        foreach ($notas as $n) {
            $dup = Process::notaDuplicada(
                (string) $n['numero_nf'], null, (string) $n['fornecedor_cnpj'], (string) $n['chave_nf']
            );
            if ($dup) {
                throw new RuntimeException(sprintf(
                    'A nota %s (%s) já está lançada no processo %s (status: %s) — vínculo bloqueado.',
                    $n['numero_nf'], $n['fornecedor_nome'], $dup['codigo'], $dup['status']
                ));
            }
            $dupDoc = self::notaJaAnexada((string) $n['numero_nf'], (string) $n['fornecedor_cnpj'], (string) $n['chave_nf']);
            if ($dupDoc) {
                throw new RuntimeException(sprintf(
                    'A nota %s (%s) já está anexada ao processo %s — vínculo bloqueado.',
                    $n['numero_nf'], $n['fornecedor_nome'], $dupDoc['codigo']
                ));
            }
        }

        $principal  = $notas[0];
        $extras     = array_slice($notas, 1);
        $valorTotal = array_sum(array_map(static fn(array $n): float => (float) $n['valor'], $notas));

        $stmt = Database::getInstance()->prepare(
            "SELECT v.id AS versao_id, v.etapas, v.tem_financeiro, v.tipos_anexo
             FROM nf_templates t
             JOIN nf_template_versoes v ON v.id = t.versao_publicada_id
             WHERE t.id = ? LIMIT 1"
        );
        $stmt->execute([$templateId]);
        $tpl = $stmt->fetch();
        if (!$tpl) {
            throw new RuntimeException('Template sem versão publicada.');
        }

        $fornecedorId = self::garantirFornecedor($principal['fornecedor_cnpj'], $principal['fornecedor_nome']);
        $parcelas     = (bool) $tpl['tem_financeiro'] ? self::parcelasDaNota($principal) : [];

        $resultado = Process::criar([
            'template_versao_id' => $tpl['versao_id'],
            'fornecedor_id'      => $fornecedorId,
            'fornecedor_nome'    => $principal['fornecedor_nome'],
            'fornecedor_doc'     => $principal['fornecedor_cnpj'],
            'numero_nf'          => $principal['numero_nf'],
            'valor_nf'           => $valorTotal,
            'data_nf'            => $principal['data_emissao'],
            'chave_nf'           => $principal['chave_nf'],
            'filial_id'          => $principal['filial_id'],
            'filial_doc'         => $principal['destinatario_cnpj'],
            'dados_formulario'   => $camposFormulario,
            'etapas_template'    => json_decode($tpl['etapas'], true) ?: [],
            'tem_financeiro'     => (bool) $tpl['tem_financeiro'],
            'parcelas'           => $parcelas,
        ], $criadoPor);

        $pdo      = Database::getInstance();
        $stmtNota = $pdo->prepare(
            "UPDATE nf_notas_capturadas
             SET status = 'vinculada', ambigua_regras = NULL,
                 processo_id = ?, regra_id = ?, vinculada_por = ?, vinculada_em = NOW()
             WHERE id = ?"
        );
        $stmtNota->execute([$resultado['id'], $regraId, $usuarioAcao, $principal['id']]);
        self::anexarDocumentosFiscais($resultado['id'], $principal, $criadoPor, $tpl['tipos_anexo']);

        $stmtDoc = $pdo->prepare(
            "INSERT INTO nf_processo_documentos
                (id, processo_id, papel, tipo_documento, numero_nf, chave_nf, valor_nf,
                 data_nf, fornecedor_nome, fornecedor_doc, nota_capturada_id, criado_por)
             VALUES (?, ?, 'anexo_fiscal', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($extras as $n) {
            $stmtDoc->execute([
                self::uuid(), $resultado['id'], $n['tipo_documento'], $n['numero_nf'], $n['chave_nf'],
                (float) $n['valor'], $n['data_emissao'], $n['fornecedor_nome'], $n['fornecedor_cnpj'],
                $n['id'], $criadoPor,
            ]);
            $stmtNota->execute([$resultado['id'], $regraId, $usuarioAcao, $n['id']]);
            self::anexarDocumentosFiscais($resultado['id'], $n, $criadoPor, $tpl['tipos_anexo']);
        }

        return $resultado;
    }

    /**
     * Status em que um processo ainda aceita receber nota. Cancelado (ou a
     * caminho de ser) e encerrado no Apollo já fecharam a conta — juntar nota
     * neles só esconderia a nota de quem precisa lançá-la.
     */
    private const STATUS_ACEITA_VINCULO = ['aberto', 'devolvido', 'aprovado'];

    /**
     * Localiza o processo digitado no modal "Vincular a processo existente".
     * Aceita o código completo (PROC-2026-0323) ou só o número (323 / 0323) —
     * neste caso vale o processo mais recente com esse final, e o modal mostra
     * o código inteiro para o operador conferir antes de confirmar.
     *
     * @return array<string,mixed>|null resumo para a prévia, ou null se não achar
     */
    public static function buscarProcessoParaVinculo(string $digitado): ?array
    {
        $digitado = strtoupper(trim($digitado));
        if ($digitado === '') return null;

        $pdo = Database::getInstance();
        $sql = "SELECT p.id, p.codigo, p.status, p.valor_nf, p.numero_nf, p.data_nf,
                       p.fornecedor_nome, p.fornecedor_doc, p.filial_doc,
                       f.nome AS filial_nome, t.nome AS template_nome,
                       eta.nome_etapa AS etapa_nome
                FROM nf_processos p
                LEFT JOIN filiais f ON f.id = p.filial_id
                LEFT JOIN nf_processo_etapas eta ON eta.processo_id = p.id AND eta.numero_etapa = p.etapa_atual
                LEFT JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
                LEFT JOIN nf_templates t ON t.id = tv.template_id
                WHERE p.deletado_em IS NULL AND ";

        if (preg_match('/^\d{1,6}$/', $digitado)) {
            $stmt = $pdo->prepare($sql . "p.codigo LIKE ? ORDER BY p.criado_em DESC LIMIT 1");
            $stmt->execute(['PROC-%-' . str_pad(ltrim($digitado, '0') ?: '0', 4, '0', STR_PAD_LEFT)]);
        } else {
            $stmt = $pdo->prepare($sql . "p.codigo = ? LIMIT 1");
            $stmt->execute([$digitado]);
        }
        $p = $stmt->fetch();
        if (!$p) return null;

        $p['aceita_vinculo'] = in_array($p['status'], self::STATUS_ACEITA_VINCULO, true);
        return $p;
    }

    /**
     * Junta a nota capturada a um processo que JÁ EXISTE — o caso da NFS-e de
     * mão de obra que chega dias depois da NF-e de peças do mesmo atendimento,
     * quando a NF-e já virou processo. Antes não havia caminho: a NFS-e abria
     * um processo próprio, que depois era cancelado.
     *
     * Mesmo resultado do vincularMultiplas() para a nota extra: entra em
     * nf_processo_documentos (papel='anexo_fiscal'), DANFSe + XML vão para os
     * anexos e o valor do processo passa a ser a soma — opcional aqui, porque
     * o processo pode já ter parcelas lançadas pelo financeiro. As parcelas
     * nunca são tocadas.
     *
     * @return array{id: string, codigo: string}
     */
    public static function vincularExistente(
        string $notaId,
        string $processoId,
        string $usuarioId,
        string $usuarioNome,
        bool $somarValor
    ): array {
        $nota = self::findById($notaId);
        if (!$nota || !in_array($nota['status'], ['capturada', 'sugerida'], true)) {
            throw new RuntimeException('Nota não encontrada ou já resolvida.');
        }

        require_once SRC . '/Models/Process.php';

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            // Reserva a nota primeiro: o UPDATE trava a linha e só passa se ela
            // ainda estiver na fila — dois operadores vinculando a mesma nota ao
            // mesmo tempo, o segundo cai aqui e nada dele é gravado.
            $stmt = $pdo->prepare(
                "UPDATE nf_notas_capturadas
                 SET status = 'vinculada', ambigua_regras = NULL,
                     processo_id = ?, vinculada_por = ?, vinculada_em = NOW()
                 WHERE id = ? AND status IN ('capturada', 'sugerida')"
            );
            $stmt->execute([$processoId, $usuarioId, $notaId]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Nota não encontrada ou já resolvida.');
            }

            $stmt = $pdo->prepare(
                "SELECT p.id, p.codigo, p.status, p.etapa_atual, tv.tipos_anexo
                 FROM nf_processos p
                 LEFT JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
                 WHERE p.id = ? AND p.deletado_em IS NULL
                 FOR UPDATE"
            );
            $stmt->execute([$processoId]);
            $proc = $stmt->fetch();
            if (!$proc) {
                throw new RuntimeException('Processo não encontrado.');
            }
            if (!in_array($proc['status'], self::STATUS_ACEITA_VINCULO, true)) {
                throw new RuntimeException(sprintf(
                    'O processo %s está com status "%s" e não aceita novas notas.',
                    $proc['codigo'], $proc['status']
                ));
            }

            // Anti-duplicidade: a nota não pode ser principal nem anexo de
            // nenhum processo — inclusive deste.
            $dup = Process::notaDuplicada(
                (string) $nota['numero_nf'], null, (string) $nota['fornecedor_cnpj'], (string) $nota['chave_nf']
            );
            if ($dup) {
                throw new RuntimeException(sprintf(
                    'Esta nota já está lançada no processo %s (status: %s) — vínculo bloqueado.',
                    $dup['codigo'], $dup['status']
                ));
            }
            $dupDoc = self::notaJaAnexada((string) $nota['numero_nf'], (string) $nota['fornecedor_cnpj'], (string) $nota['chave_nf']);
            if ($dupDoc) {
                throw new RuntimeException(sprintf(
                    'Esta nota já está anexada ao processo %s — vínculo bloqueado.', $dupDoc['codigo']
                ));
            }

            $pdo->prepare(
                "INSERT INTO nf_processo_documentos
                    (id, processo_id, papel, tipo_documento, numero_nf, chave_nf, valor_nf,
                     data_nf, fornecedor_nome, fornecedor_doc, nota_capturada_id, criado_por)
                 VALUES (?, ?, 'anexo_fiscal', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                self::uuid(), $proc['id'], $nota['tipo_documento'], $nota['numero_nf'], $nota['chave_nf'],
                (float) $nota['valor'], $nota['data_emissao'], $nota['fornecedor_nome'], $nota['fornecedor_cnpj'],
                $nota['id'], $usuarioId,
            ]);

            if ($somarValor) {
                $pdo->prepare("UPDATE nf_processos SET valor_nf = COALESCE(valor_nf, 0) + ? WHERE id = ?")
                    ->execute([(float) $nota['valor'], $proc['id']]);
            }

            $valorFmt = 'R$ ' . number_format((float) $nota['valor'], 2, ',', '.');
            Process::registrarHistorico(
                $proc['id'], 'nota_vinculada', $usuarioId, $usuarioNome,
                sprintf('%s %s vinculada (%s · %s)%s',
                    $nota['tipo_documento'] === 'NFSe' ? 'NFS-e' : $nota['tipo_documento'],
                    $nota['numero_nf'], $nota['fornecedor_nome'], $valorFmt,
                    $somarValor ? ' — valor somado ao processo' : ''),
                (int) $proc['etapa_atual']
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        // Arquivos fora da transação: falha aqui só vai para o log (mesmo
        // contrato do vincular()), a nota já está no processo.
        self::anexarDocumentosFiscais($proc['id'], $nota, $usuarioId, $proc['tipos_anexo']);

        return ['id' => (string) $proc['id'], 'codigo' => (string) $proc['codigo']];
    }

    /** Nota já entrou em algum processo como documento adicional (anexo_fiscal/remessa)? */
    private static function notaJaAnexada(string $numeroNf, string $fornecedorDoc, ?string $chaveNf): ?array
    {
        $pdo      = Database::getInstance();
        $docLimpo = preg_replace('/\D+/', '', $fornecedorDoc) ?? '';
        $chave    = $chaveNf !== null ? preg_replace('/\D+/', '', $chaveNf) : '';

        if (!empty($chave)) {
            $stmt = $pdo->prepare(
                "SELECT p.codigo, p.status FROM nf_processo_documentos d
                 JOIN nf_processos p ON p.id = d.processo_id
                 WHERE d.chave_nf = ? AND d.deletado_em IS NULL
                   AND p.status <> 'cancelado' AND p.deletado_em IS NULL
                 LIMIT 1"
            );
            $stmt->execute([$chave]);
            $row = $stmt->fetch();
            if ($row) return $row;
        }

        if ($numeroNf === '' || $docLimpo === '') return null;

        $stmt = $pdo->prepare(
            "SELECT p.codigo, p.status FROM nf_processo_documentos d
             JOIN nf_processos p ON p.id = d.processo_id
             WHERE d.fornecedor_doc = ? AND d.deletado_em IS NULL
               AND p.status <> 'cancelado' AND p.deletado_em IS NULL
               AND (d.numero_nf = ? OR CAST(d.numero_nf AS UNSIGNED) = CAST(? AS UNSIGNED))
             LIMIT 1"
        );
        $stmt->execute([$docLimpo, $numeroNf, $numeroNf]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Extrai o número da Ordem de Serviço do texto livre do XML — "O.S.:
     * 139404" nas Informações Complementares da NF-e (Linx DMS) ou nos Dados
     * Complementares da NFS-e municipal. É o vínculo REAL entre a nota de
     * peça e a nota de mão de obra do mesmo atendimento (confirmado com
     * exemplo real: mesma O.S. 139404 nas duas, DANFE e DANFSe de Cidade A).
     *
     * Regex direto no XML crú — não em um campo já estruturado — porque a
     * posição da tag varia entre NF-e (infAdic/infCpl) e as várias prefeituras
     * de NFS-e ABRASF; o texto "O.S.: NNNN" aparece em qualquer um dos casos
     * como texto puro, então procurar nele é mais robusto que mapear tag.
     */
    private static function extrairOS(array $nota): ?string
    {
        $xml = self::xmlReal($nota);
        if ($xml === null) return null;
        if (preg_match('/O\.\s*S\.?\s*:?\s*(\d{3,})/i', $xml, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Extrai a placa do veículo do texto livre do XML — "PLACA.....:
     * ABC1D23#" no infAdProd da NF-e de venda de veículo (Linx DMS). Mesmo
     * princípio do extrairOS(): a nota de veículo e a nota de serviço do
     * mesmo atendimento citam a mesma placa, mesmo sem O.S. em comum.
     *
     * Exige o rótulo "PLACA" antes do valor (não casa placa solta no texto)
     * para não juntar notas por coincidência. Aceita formato antigo (3
     * letras + 4 dígitos) e Mercosul (3 letras + 1 dígito + 1 letra + 2
     * dígitos).
     */
    private static function extrairPlaca(array $nota): ?string
    {
        $xml = self::xmlReal($nota);
        if ($xml === null) return null;
        if (preg_match('/PLACA\.*\s*:?\s*([A-Z]{3}\s?\d[A-Z0-9]\d{2})\b/i', $xml, $m)) {
            return strtoupper(preg_replace('/\s+/', '', $m[1]));
        }
        return null;
    }

    /**
     * Extrai o chassi (VIN) do texto livre do XML — "CHASSI....:
     * 9BD358ATFRYM89292#" no infAdProd da NF-e de venda de veículo (Linx
     * DMS). Mesmo princípio do extrairOS()/extrairPlaca(): identifica o
     * mesmo veículo entre a nota de venda e a nota de serviço relacionada.
     *
     * Exige o rótulo "CHASSI" antes do valor. VIN tem 17 caracteres
     * alfanuméricos.
     */
    private static function extrairChassi(array $nota): ?string
    {
        $xml = self::xmlReal($nota);
        if ($xml === null) return null;
        if (preg_match('/CHASSI\.*\s*:?\s*([A-Z0-9]{17})\b/i', $xml, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    /**
     * Anexa ao processo recém-criado os documentos fiscais da nota capturada:
     * o DANFE/DACTE (HTML standalone, via DfeHtml) SEMPRE primeiro e o XML
     * em seguida. Falha aqui não desfaz a vinculação — apenas vai para o log.
     */
    private static function anexarDocumentosFiscais(
        string $processoId,
        array $nota,
        string $enviadoPor,
        ?string $tiposAnexoJson = null
    ): void {
        try {
            require_once SRC . '/Models/Process.php';
            require_once SRC . '/Models/Template.php';
            require_once SRC . '/Helpers/XmlDfeParser.php';
            require_once SRC . '/Helpers/DfeHtml.php';

            $base = defined('STORAGE') ? STORAGE : dirname(__DIR__, 2) . '/storage';
            $dir  = $base . '/processos/' . $processoId;
            if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
                throw new RuntimeException('Falha ao criar diretório de anexos do processo.');
            }

            $isCte = in_array($nota['tipo_documento'] ?? '', ['CTe', 'CTOs'], true);
            $sigla = $isCte ? 'DACTE' : 'DANFE';

            // O rótulo salvo (tipo_anexo_nome) precisa bater com o nome EXATO que
            // o template deu ao slot da nota fiscal na checklist de obrigatórios
            // — senão o processo fica com o documento anexado E "Nota Fiscal"
            // pendente ao mesmo tempo (mesmo bug já corrigido na importação de
            // PDF, ver Template::tipoAnexoDaNota). $sigla continua no NOME DO
            // ARQUIVO (cosmético/técnico) — só a classificação muda.
            $tipoAnexo = Template::tipoAnexoDaNota($tiposAnexoJson);

            $xml = self::xmlReal($nota);

            // 1) DANFE/DACTE — sempre o primeiro anexo do processo
            $det  = $xml !== null ? XmlDfeParser::detalhes($xml, (string) $nota['tipo_documento']) : null;
            $html = DfeHtml::gerar($nota, $det);
            self::gravarAnexo($processoId, $dir, $html,
                $sigla . '_' . $nota['numero_nf'] . '.html', 'text/html', $tipoAnexo, $enviadoPor);

            // 2) XML — autêntico do storage; preview gerado só como fallback
            $conteudoXml = $xml ?? self::gerarXmlPreview($nota);
            self::gravarAnexo($processoId, $dir, $conteudoXml,
                $nota['tipo_documento'] . '_' . $nota['numero_nf'] . '_' . $nota['chave_nf'] . '.xml',
                'application/xml', 'XML', $enviadoPor);
        } catch (Throwable $e) {
            error_log('Captura: falha ao anexar documentos fiscais ao processo ' . $processoId . ': ' . $e->getMessage());
        }
    }

    /** Grava conteúdo gerado pelo servidor no storage do processo e registra o anexo. */
    private static function gravarAnexo(
        string $processoId,
        string $dir,
        string $conteudo,
        string $nomeOriginal,
        string $mime,
        string $tipoNome,
        string $enviadoPor
    ): void {
        $nomeOriginal   = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nomeOriginal);
        $ext            = pathinfo($nomeOriginal, PATHINFO_EXTENSION);
        $nomeArmazenado = self::uuid() . '.' . $ext;
        $caminho        = $dir . '/' . $nomeArmazenado;

        if (file_put_contents($caminho, $conteudo) === false) {
            throw new RuntimeException("Falha ao gravar {$tipoNome} no storage do processo.");
        }

        Process::salvarAnexo($processoId, [
            'nome_original'   => $nomeOriginal,
            'nome_armazenado' => $nomeArmazenado,
            'caminho'         => $caminho,
            'mime_type'       => $mime,
            'tamanho_bytes'   => strlen($conteudo),
        ], $tipoNome, $enviadoPor);
    }

    /**
     * Busca fornecedor pelo CNPJ; se não existir, cadastra a partir
     * dos dados da nota (auto-cadastro da captura).
     */
    public static function garantirFornecedor(string $cnpj, string $nome): string
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id FROM fornecedores WHERE cnpj = ? LIMIT 1");
        $stmt->execute([$cnpj]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (string) $id;
        }

        $novoId = self::uuid();
        $stmt   = $pdo->prepare(
            "INSERT INTO fornecedores (id, razao_social, cnpj, ativo, para_compras)
             VALUES (?, ?, ?, 1, 1)"
        );
        $stmt->execute([$novoId, $nome, $cnpj]);
        return $novoId;
    }

    // ─────────────────────────────────────────
    // Motor de regras (pré-fluxo)
    // ─────────────────────────────────────────

    /**
     * Aplica as regras ativas sobre as notas pendentes.
     *
     * modo 'sugerir'    → marca a nota como sugerida (regra_id preenchida);
     *                     o operador confirma com um clique.
     * modo 'automatico' → cria o processo com o payload pré-configurado
     *                     e vincula a nota (criado_por = dono da regra).
     * modo 'arquivar'   → tira a nota da fila sem abrir processo; a nota
     *                     permanece pesquisável (status ignorada + motivo).
     *
     * Regra com precisa_revisao=1 não dispara.
     * Nota que casa com MAIS DE UMA regra fica no manual (ambiguidade
     * nunca decide sozinha).
     *
     * $soRegraId: aplica apenas essa regra (botão "Aplicar" da lista de
     * pré-fluxo). O match continua rodando contra TODAS as regras ativas —
     * nota ambígua segue no manual mesmo no modo regra única.
     *
     * 'ambiguidades' detalha cada colisão (nota + regras que casaram juntas)
     * para a tela exibir e o usuário ajustar as regras.
     *
     * @return array{sugeridas: int, vinculadas: int, ambiguas: int, arquivadas: int,
     *               ambiguidades: array<int, array{nota_id: string, numero_nf: string,
     *               tipo_documento: string, fornecedor_nome: string, valor: float,
     *               regras: array<int, array{id: string, nome: string}>}>}
     */
    public static function aplicarRegras(?string $soRegraId = null): array
    {
        $pdo    = Database::getInstance();
        $regras = $pdo->query(
            "SELECT * FROM nf_captura_regras WHERE ativo = 1 AND precisa_revisao = 0"
        )->fetchAll();

        $notas = $pdo->query(
            "SELECT * FROM nf_notas_capturadas WHERE status = 'capturada'"
        )->fetchAll();

        $resultado = ['sugeridas' => 0, 'vinculadas' => 0, 'ambiguas' => 0, 'arquivadas' => 0,
                      'duplicadas' => 0, 'combinadas' => 0, 'ambiguidades' => []];

        // Notas já resolvidas nesta mesma execução por terem sido puxadas pra
        // dentro do grupo de O.S. de outra nota (ver bloco 'automatico' abaixo)
        // — não processar de novo quando o loop chegar nelas.
        $jaTratadas = [];

        foreach ($notas as &$nota) {
            if (isset($jaTratadas[$nota['id']])) {
                continue;
            }

            // Regra implícita (não configurável): NF-e/NFS-e emitida antes do
            // go-live (01/08/2026) é lixo de teste/pré-produção — nunca abre
            // processo. CT-e/CT-Os ficam de fora de propósito, só o pedido
            // original (18/09/2026): "qualquer NF-e ou NFS-e".
            if (in_array($nota['tipo_documento'], ['NFe', 'NFSe'], true)
                && (string) $nota['data_emissao'] < '2026-08-01') {
                $stmt = $pdo->prepare(
                    "UPDATE nf_notas_capturadas
                     SET status = 'ignorada', vinculada_em = NOW(), motivo_ignorada = ?
                     WHERE id = ? AND status = 'capturada'"
                );
                $stmt->execute(['Emitida antes de 01/08/2026 — fora do período de produção.', $nota['id']]);
                $resultado['arquivadas'] += $stmt->rowCount();
                continue;
            }

            // Regra implícita (não configurável): mesmo fornecedor + mesma filial
            // + mesma data + mesmo número já tem processo aberto manualmente antes
            // da captura chegar (ex.: operador digitou a nota antes do cron trazer
            // o mesmo documento via ADN/SEFAZ/Brobot). Não há coluna de série em
            // nenhuma das duas tabelas — na prática esses quatro campos juntos já
            // identificam o documento sem ambiguidade real.
            $duplicado = self::processoJaExistente($pdo, $nota);
            if ($duplicado !== null) {
                $stmt = $pdo->prepare(
                    "UPDATE nf_notas_capturadas
                     SET status = 'ignorada', vinculada_em = NOW(), motivo_ignorada = ?
                     WHERE id = ? AND status = 'capturada'"
                );
                $stmt->execute([
                    mb_substr('Duplicada do processo ' . $duplicado['codigo'] . ' (já registrado manualmente)', 0, 255),
                    $nota['id'],
                ]);
                $resultado['duplicadas'] += $stmt->rowCount();
                continue;
            }

            $matches = [];
            foreach ($regras as $regra) {
                // Fornecedor vazio = qualquer fornecedor (ex.: arquivar todo CTe de terceiros)
                if (!empty($regra['fornecedor_cnpj'])
                    && $regra['fornecedor_cnpj'] !== $nota['fornecedor_cnpj'])  continue;
                if ($regra['destinatario_cnpj'] !== $nota['destinatario_cnpj']) continue;
                if ($regra['cfop'] !== null && $regra['cfop'] !== ''
                    && $regra['cfop'] !== ($nota['cfop'] ?? ''))                continue;
                // Tipo de documento vazio = qualquer tipo (NFe/CTe/NFSe/CTOs)
                if (!empty($regra['tipo_documento'])
                    && $regra['tipo_documento'] !== $nota['tipo_documento'])    continue;
                if (!self::tomadorCasa($regra['tomador'] ?? null, $nota))       continue;
                // Natureza da operação: critério CONTÉM (sem acento/caixa).
                // Nota sem natOp não casa com regra que exige o critério.
                if (!empty($regra['natureza_operacao'])) {
                    $natNota = self::normalizarTexto((string) ($nota['natureza_operacao'] ?? ''));
                    if ($natNota === ''
                        || !str_contains($natNota, self::normalizarTexto($regra['natureza_operacao']))) continue;
                }
                // Valor da nota: faixa opcional (só compara o lado preenchido)
                if (($regra['valor_min'] ?? null) !== null
                    && (float) $nota['valor'] < (float) $regra['valor_min'])   continue;
                if (($regra['valor_max'] ?? null) !== null
                    && (float) $nota['valor'] > (float) $regra['valor_max'])   continue;
                // Código dos itens (NF-e): prefixo do cProd — ex.: VN/VU = veículo
                if (!empty($regra['item_condicao'])
                    && !self::itemCriterioCasa((string) $regra['item_condicao'],
                                               (string) ($regra['item_prefixo'] ?? ''), $nota)) continue;
                $matches[] = $regra;
            }

            // Marca (ou desmarca) a nota como ambígua na própria linha, para a
            // inbox conseguir mostrar QUAIS são e com que regras colidiram — o
            // retorno abaixo só alimenta o resumo do job, que morre no log.
            self::sincronizarAmbiguidade($pdo, $nota, count($matches) > 1 ? $matches : []);

            if (count($matches) === 0) {
                continue;
            }
            if (count($matches) > 1) {
                // Ambiguidade → decisão humana. No modo regra única, só conta
                // quando a regra pedida está entre as que colidiram.
                if ($soRegraId === null
                    || in_array($soRegraId, array_column($matches, 'id'), true)) {
                    $resultado['ambiguas']++;
                    $resultado['ambiguidades'][] = [
                        'nota_id'         => (string) $nota['id'],
                        'numero_nf'       => (string) $nota['numero_nf'],
                        'tipo_documento'  => (string) ($nota['tipo_documento'] ?? ''),
                        'fornecedor_nome' => (string) ($nota['fornecedor_nome'] ?? ''),
                        'valor'           => (float) $nota['valor'],
                        'regras'          => array_map(
                            static fn(array $m) => ['id' => (string) $m['id'], 'nome' => (string) $m['nome']],
                            $matches
                        ),
                    ];
                }
                continue;
            }

            $regra = $matches[0];
            if ($soRegraId !== null && $regra['id'] !== $soRegraId) {
                continue; // nota casou com outra regra — fora do escopo do botão
            }
            $campos = json_decode($regra['campos_json'], true) ?: [];

            if ($regra['modo'] === 'arquivar') {
                // Guarda a nota (XML permanece no storage) e tira da fila —
                // sem abrir processo. Continua aparecendo nas pesquisas.
                $stmt = $pdo->prepare(
                    "UPDATE nf_notas_capturadas
                     SET status = 'ignorada', regra_id = ?, vinculada_em = NOW(), motivo_ignorada = ?
                     WHERE id = ? AND status = 'capturada'"
                );
                $stmt->execute([
                    $regra['id'],
                    mb_substr('Arquivada pela regra: ' . $regra['nome'], 0, 255),
                    $nota['id'],
                ]);
                $resultado['arquivadas'] += $stmt->rowCount();
            } elseif ($regra['modo'] === 'automatico') {
                // Vínculo automático entre notas do mesmo atendimento/venda:
                // se outra nota AINDA capturada, do mesmo fornecedor, mesma
                // filial e mesmo dia de emissão, compartilha a Ordem de
                // Serviço, a placa ou o chassi no texto livre do XML (Linx
                // DMS grava "O.S.: NNNN" / "PLACA....: NNNNNNN" / "CHASSI...:
                // NNNNNNNNNNNNNNNNN" tanto na NF-e quanto na NFS-e do mesmo
                // atendimento — confirmado com exemplo real), as duas (ou
                // mais) entram juntas no MESMO processo em vez de duas
                // aprovações separadas pro mesmo boleto. Qualquer um dos três
                // identificadores bater já é suficiente — não precisa dos
                // três juntos. Só NF-e/NFS-e — CT-e/CT-Os ficam de fora de
                // propósito (mesmo critério da regra implícita de corte por
                // data, acima).
                $grupoVinculo = [];
                $tiposAgrupaveis = ['NFe', 'NFSe'];
                if (in_array($nota['tipo_documento'], $tiposAgrupaveis, true)) {
                    $os     = self::extrairOS($nota);
                    $placa  = self::extrairPlaca($nota);
                    $chassi = self::extrairChassi($nota);
                    if ($os !== null || $placa !== null || $chassi !== null) {
                        foreach ($notas as $candidata) {
                            if ($candidata['id'] === $nota['id'])                          continue;
                            if (isset($jaTratadas[$candidata['id']]))                      continue;
                            if ($candidata['status'] !== 'capturada')                      continue;
                            if (!in_array($candidata['tipo_documento'], $tiposAgrupaveis, true)) continue;
                            if ($candidata['fornecedor_cnpj'] !== $nota['fornecedor_cnpj']) continue;
                            if ($candidata['destinatario_cnpj'] !== $nota['destinatario_cnpj']) continue;
                            if ((string) $candidata['data_emissao'] !== (string) $nota['data_emissao']) continue;
                            $mesmoOS      = $os !== null && self::extrairOS($candidata) === $os;
                            $mesmaPlaca   = $placa !== null && self::extrairPlaca($candidata) === $placa;
                            $mesmoChassi  = $chassi !== null && self::extrairChassi($candidata) === $chassi;
                            if (!$mesmoOS && !$mesmaPlaca && !$mesmoChassi)               continue;
                            $grupoVinculo[] = $candidata;
                        }
                    }
                }

                try {
                    if (!empty($grupoVinculo)) {
                        $idsGrupo = array_merge([(string) $nota['id']], array_column($grupoVinculo, 'id'));
                        self::vincularMultiplas($idsGrupo, $regra['template_id'], $campos, $regra['criado_por'], null, $regra['id']);
                        foreach ($idsGrupo as $idTratada) {
                            $jaTratadas[$idTratada] = true;
                        }
                        $resultado['vinculadas']  += count($idsGrupo);
                        $resultado['combinadas']  += count($idsGrupo);
                    } else {
                        self::vincular(
                            $nota['id'],
                            $regra['template_id'],
                            $campos,
                            $regra['criado_por'],   // processo atribuído ao dono da regra
                            null,                   // vinculada_por NULL = automação
                            $regra['id']
                        );
                        $resultado['vinculadas']++;
                    }
                } catch (Throwable $e) {
                    error_log('Pré-fluxo: falha ao vincular nota ' . $nota['id'] . ': ' . $e->getMessage());
                    continue;
                }
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE nf_notas_capturadas SET status = 'sugerida', regra_id = ? WHERE id = ? AND status = 'capturada'"
                );
                $stmt->execute([$regra['id'], $nota['id']]);
                $resultado['sugeridas'] += $stmt->rowCount();
            }

            $pdo->prepare(
                "UPDATE nf_captura_regras SET total_disparos = total_disparos + 1, ultima_execucao = NOW() WHERE id = ?"
            )->execute([$regra['id']]);
        }
        unset($nota);

        return $resultado;
    }

    /**
     * Busca processo já registrado (manual ou não) que corresponda à nota
     * capturada por fornecedor + filial de destino + data + número — a
     * regra implícita de deduplicação do aplicarRegras().
     *
     * numero_nf é varchar (digitação manual pode ter zero à esquerda ou não),
     * por isso compara tanto por igualdade de string quanto numericamente.
     */
    private static function processoJaExistente(PDO $pdo, array $nota): ?array
    {
        if (empty($nota['fornecedor_cnpj']) || empty($nota['numero_nf']) || empty($nota['data_emissao'])) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT id, codigo FROM nf_processos
             WHERE fornecedor_doc = ?
               AND filial_doc = ?
               AND data_nf = ?
               AND (numero_nf = ? OR CAST(numero_nf AS UNSIGNED) = CAST(? AS UNSIGNED))
               AND status <> 'cancelado'
               AND deletado_em IS NULL
             LIMIT 1"
        );
        $stmt->execute([
            $nota['fornecedor_cnpj'],
            $nota['destinatario_cnpj'],
            $nota['data_emissao'],
            $nota['numero_nf'],
            $nota['numero_nf'],
        ]);
        $row = $stmt->fetch();
        if ($row) return $row;

        // Também casa com nota já anexada como documento adicional (produto+
        // serviço combinados, ou remessa) de um processo — não só a principal.
        $stmt = $pdo->prepare(
            "SELECT p.id, p.codigo FROM nf_processo_documentos d
             JOIN nf_processos p ON p.id = d.processo_id
             WHERE d.fornecedor_doc = ?
               AND p.filial_doc = ?
               AND d.data_nf = ?
               AND (d.numero_nf = ? OR CAST(d.numero_nf AS UNSIGNED) = CAST(? AS UNSIGNED))
               AND d.deletado_em IS NULL
               AND p.status <> 'cancelado'
               AND p.deletado_em IS NULL
             LIMIT 1"
        );
        $stmt->execute([
            $nota['fornecedor_cnpj'],
            $nota['destinatario_cnpj'],
            $nota['data_emissao'],
            $nota['numero_nf'],
            $nota['numero_nf'],
        ]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Critério de itens: compara o prefixo do cProd de cada item da NF-e.
     *
     * $prefixosCsv aceita múltiplos prefixos separados por vírgula (ex.: "VN,VU").
     *   todos_iniciam → TODOS os itens iniciam com algum dos prefixos (veículos)
     *   nenhum_inicia → NENHUM item inicia com os prefixos (peças)
     *
     * Só se aplica a NF-e; nota sem itens (ou sem XML) não casa — ambiguidade
     * volta para a decisão humana em vez de classificar errado.
     */
    private static function itemCriterioCasa(string $condicao, string $prefixosCsv, array &$nota): bool
    {
        if (($nota['tipo_documento'] ?? '') !== 'NFe') {
            return false;
        }
        $prefixos = array_values(array_filter(array_map('trim', explode(',', strtoupper($prefixosCsv)))));
        if (empty($prefixos)) {
            return false;
        }
        $codigos = self::itensCodigosDaNota($nota);
        if (empty($codigos)) {
            return false;
        }
        foreach ($codigos as $codigo) {
            $codigoUp = strtoupper(trim((string) $codigo));
            $inicia   = false;
            foreach ($prefixos as $p) {
                if (str_starts_with($codigoUp, $p)) { $inicia = true; break; }
            }
            if ($condicao === 'todos_iniciam' && !$inicia) return false;
            if ($condicao === 'nenhum_inicia' && $inicia)  return false;
        }
        return true;
    }

    /**
     * Lista de cProd dos itens da nota (NF-e), com cache em itens_codigos.
     * Nota antiga sem cache: lê o XML do storage, extrai e grava o cache.
     * Retorna null quando o XML não está disponível/legível.
     */
    private static function itensCodigosDaNota(array &$nota): ?array
    {
        if (!empty($nota['itens_codigos'])) {
            $lista = json_decode((string) $nota['itens_codigos'], true);
            // "[]" = cache gravado por sync antigo que não extraía itens
            // (parse() sem a chave 'itens') — ignora e relê o XML do storage.
            if (is_array($lista) && $lista !== []) {
                return $lista;
            }
        }

        $chave = (string) ($nota['chave_nf'] ?? '');
        $base  = defined('STORAGE') ? STORAGE : ROOT . '/storage';
        $path  = $base . '/nfe_xml/' . $chave . '.xml';
        if ($chave === '' || !is_file($path)) {
            return null;
        }
        $xml = (string) @file_get_contents($path);
        if (trim($xml) === '') {
            return null;
        }

        require_once SRC . '/Helpers/XmlDfeParser.php';
        try {
            $parsed = XmlDfeParser::parse($xml, 'NFe');
        } catch (\Throwable $e) {
            error_log('Pré-fluxo: falha ao parsear XML da nota ' . $nota['id'] . ': ' . $e->getMessage());
            return null;
        }

        $codigos = [];
        foreach (($parsed['itens'] ?? []) as $item) {
            $c = trim((string) ($item['codigo'] ?? ''));
            if ($c !== '') {
                $codigos[] = $c;
            }
        }

        $json = json_encode($codigos, JSON_UNESCAPED_UNICODE);
        Database::getInstance()
            ->prepare("UPDATE nf_notas_capturadas SET itens_codigos = ? WHERE id = ?")
            ->execute([$json, $nota['id']]);
        $nota['itens_codigos'] = $json;

        return $codigos;
    }

    // ─────────────────────────────────────────
    // Cobrança do XML → parcelas do processo
    // ─────────────────────────────────────────

    /**
     * tPag da NF-e → nome do item da lista "Formas de pagamento".
     *
     * Só os códigos que casam sem ambiguidade com a lista do sistema. Cartão de
     * débito (04), crédito loja (05), vale (10..14) e "outros" (99) ficam de
     * fora de propósito: chutar a forma errada faz o financeiro pagar pelo meio
     * errado. Sem match, a parcela nasce sem forma e quem trata escolhe.
     */
    private const TPAG_FORMA = [
        '01' => 'À Vista',
        '02' => 'Cheque',
        '03' => 'Cartão de Crédito',
        '15' => 'Boleto',
        '17' => 'PIX',
        '20' => 'PIX',
    ];

    /**
     * Cobrança da nota (fatura + duplicatas + forma de pagamento), com cache
     * em nf_notas_capturadas.cobranca — mesmo padrão de itens_codigos.
     *
     * cobranca NULL = XML ainda não lido (nota anterior à migration 036): lê do
     * storage, extrai e grava. JSON com duplicatas vazias é resposta legítima
     * (nota sem <cobr>) e NÃO faz reler o XML na próxima vez.
     *
     * @return array|null null quando o XML não está disponível/legível
     */
    public static function cobrancaDaNota(array &$nota): ?array
    {
        if (($nota['tipo_documento'] ?? '') !== 'NFe') {
            return null; // só a NF-e tem bloco <cobr>
        }
        require_once SRC . '/Helpers/XmlDfeParser.php';

        if (!empty($nota['cobranca'])) {
            $cache = json_decode((string) $nota['cobranca'], true);
            if (is_array($cache) && array_key_exists('duplicatas', $cache)) {
                return $cache + XmlDfeParser::COBRANCA_VAZIA;
            }
        }

        $xml = self::xmlReal($nota);
        if ($xml === null) {
            return null;
        }

        try {
            $parsed = XmlDfeParser::parse($xml, 'NFe');
        } catch (\Throwable $e) {
            error_log('Captura: falha ao ler a cobrança do XML da nota ' . $nota['id'] . ': ' . $e->getMessage());
            return null;
        }

        $cobranca = ($parsed['cobranca'] ?? []) + XmlDfeParser::COBRANCA_VAZIA;
        $numero   = $cobranca['fatura']['numero'] ?? null;
        $json     = json_encode($cobranca, JSON_UNESCAPED_UNICODE);

        Database::getInstance()
            ->prepare("UPDATE nf_notas_capturadas SET cobranca = ?, numero_fatura = ? WHERE id = ?")
            ->execute([$json, $numero !== null ? mb_substr($numero, 0, 60) : null, $nota['id']]);

        $nota['cobranca']      = $json;
        $nota['numero_fatura'] = $numero;

        return $cobranca;
    }

    /**
     * Parcelas do processo derivadas das duplicatas do XML — no formato que
     * Process::criar() espera.
     *
     * Sem duplicata utilizável o retorno é vazio e o processo nasce sem
     * parcela, como antes: quem trata o financeiro lança na mão.
     *
     * @return list<array{vencimento:string, valor:float, forma_pagamento_id:?string,
     *                    forma_pagamento_nome:?string, origem_duplicata:?string}>
     */
    public static function parcelasDaNota(array &$nota): array
    {
        $cobranca = self::cobrancaDaNota($nota);
        if ($cobranca === null || empty($cobranca['duplicatas'])) {
            return [];
        }

        ['id' => $formaId, 'nome' => $formaNome] = self::formaPagamento($cobranca['forma_pagamento_cod'] ?? null);
        $parcelas = [];
        foreach ($cobranca['duplicatas'] as $dup) {
            $venc  = (string) ($dup['vencimento'] ?? '');
            $valor = (float) ($dup['valor'] ?? 0);
            // Duplicata sem vencimento ou zerada não vira parcela — é a mesma
            // regra que Process::regravarParcelasEditaveis() aplica na edição.
            if ($venc === '' || $valor <= 0) {
                continue;
            }
            $parcelas[] = [
                'vencimento'           => $venc,
                'valor'                => $valor,
                'forma_pagamento_id'   => $formaId,
                'forma_pagamento_nome' => $formaNome,
                'origem_duplicata'     => $dup['numero'] ?? null,
            ];
        }

        usort($parcelas, static fn(array $a, array $b) => $a['vencimento'] <=> $b['vencimento']);
        return $parcelas;
    }

    /**
     * Resolve o tPag do XML para o item ativo da lista "Formas de pagamento".
     * Volta id e nome nulos quando o código não tem correspondente confiável
     * (ver TPAG_FORMA) ou quando o item foi desativado na lista.
     *
     * @return array{id:?string, nome:?string}
     */
    private static function formaPagamento(?string $tPag): array
    {
        $nome = self::TPAG_FORMA[str_pad((string) $tPag, 2, '0', STR_PAD_LEFT)] ?? null;
        if ($nome === null) {
            return ['id' => null, 'nome' => null];
        }

        static $cache = [];
        if (!array_key_exists($nome, $cache)) {
            $stmt = Database::getInstance()->prepare(
                "SELECT i.id
                   FROM nf_lista_itens i
                   JOIN nf_listas l ON l.id = i.lista_id
                  WHERE l.slug = 'formas-pagamento' AND i.ativo = 1 AND i.valor = ?
                  LIMIT 1"
            );
            $stmt->execute([$nome]);
            $cache[$nome] = $stmt->fetchColumn() ?: null;
        }

        return $cache[$nome] === null
            ? ['id' => null, 'nome' => null]
            : ['id' => $cache[$nome], 'nome' => $nome];
    }

    /**
     * Sincroniza a marca de ambiguidade da nota com o resultado do motor.
     *
     * Guarda as REGRAS que colidiram, não um booleano: saber que a nota é
     * ambígua sem saber entre o quê não ajuda quem vai resolver. Vazio limpa a
     * marca — regra editada, desativada ou excluída desfaz a colisão, e a nota
     * não pode continuar carimbada.
     *
     * Só grava quando o valor muda: o motor varre a fila inteira a cada 30
     * minutos e não faz sentido reescrever milhares de linhas iguais.
     *
     * @param array<int, array> $matches regras que casaram (2+ = ambígua)
     */
    private static function sincronizarAmbiguidade(\PDO $pdo, array $nota, array $matches): void
    {
        $novo = $matches === [] ? null : json_encode(
            array_map(
                static fn(array $r) => ['id' => (string) $r['id'], 'nome' => (string) $r['nome']],
                array_values($matches)
            ),
            JSON_UNESCAPED_UNICODE
        );

        if (($nota['ambigua_regras'] ?? null) === $novo) {
            return;
        }

        $pdo->prepare("UPDATE nf_notas_capturadas SET ambigua_regras = ? WHERE id = ?")
            ->execute([$novo, $nota['id']]);
    }

    /**
     * Volta as notas em 'sugerida' para 'capturada' (limpando a regra),
     * para o motor reavaliar tudo com as regras atuais. Usado pelo botão
     * "Aplicar regras agora" — sugestões antigas não ficam presas a regras
     * que mudaram ou foram excluídas.
     *
     * $regraId: limpa só as sugestões daquela regra (botão "Aplicar" da
     * lista de pré-fluxo) — sugestões de outras regras ficam intactas.
     *
     * @return int quantidade de sugestões limpas
     */
    public static function resetarSugestoes(?string $regraId = null): int
    {
        $pdo = Database::getInstance();
        $sql = "UPDATE nf_notas_capturadas SET status = 'capturada', regra_id = NULL WHERE status = 'sugerida'";
        $params = [];
        if ($regraId !== null) {
            $sql     .= " AND regra_id = ?";
            $params[] = $regraId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Normaliza texto para comparação: maiúsculas, sem acentos, espaços únicos.
     * "Devolução de  Venda" → "DEVOLUCAO DE VENDA".
     */
    public static function normalizarTexto(string $texto): string
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

    /**
     * Critério de tomador do frete (CTe): 'filial' = a filial destinatária
     * paga o frete; 'terceiro' = outro CNPJ paga. NULL/'' = indiferente.
     * Nota sem tomador identificado não casa com regra que exige o critério
     * (fica para decisão manual).
     */
    private static function tomadorCasa(?string $criterio, array $nota): bool
    {
        if ($criterio === null || $criterio === '') return true;
        $tomador = (string) ($nota['tomador_cnpj'] ?? '');
        if ($tomador === '') return false;
        $ehFilial = $tomador === (string) ($nota['destinatario_cnpj'] ?? '');
        return $criterio === 'filial' ? $ehFilial : !$ehFilial;
    }

    // ─────────────────────────────────────────
    // Documentos (placeholder até a Brobot entregar os arquivos)
    // ─────────────────────────────────────────

    /** tipo_documento → pasta de XML no storage (mesmo mapa do BrobotSync). */
    private const XML_PASTAS = [
        'NFe'  => 'nfe_xml',
        'CTe'  => 'cte_xml',
        'NFSe' => 'nfse_xml',
        'CTOs' => 'ctos_xml',
    ];

    /**
     * Lê o XML autêntico da nota gravado pelo sync em storage/{tipo}_xml/{chave}.xml.
     * Retorna null quando o arquivo não existe (nota antiga / sync sem XML).
     */
    public static function xmlReal(array $nota): ?string
    {
        $pasta = self::XML_PASTAS[$nota['tipo_documento'] ?? ''] ?? null;
        $chave = preg_replace('/\D+/', '', (string) ($nota['chave_nf'] ?? ''));
        if ($pasta === null || $chave === '') return null;

        $base = defined('STORAGE') ? STORAGE : dirname(__DIR__, 2) . '/storage';
        $path = $base . '/' . $pasta . '/' . $chave . '.xml';
        if (!is_file($path)) return null;

        $xml = file_get_contents($path);
        return ($xml === false || trim($xml) === '') ? null : $xml;
    }

    /** Gera representação XML da nota a partir dos dados capturados (fallback). */
    public static function gerarXmlPreview(array $nota): string
    {
        $valor = number_format((float) $nota['valor'], 2, '.', '');
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!-- Preview gerado a partir dos dados capturados — o XML autêntico virá da integração Brobot -->
<nfeProc versao="4.00" xmlns="http://www.portalfiscal.inf.br/nfe">
  <NFe>
    <infNFe Id="NFe{$nota['chave_nf']}" versao="4.00">
      <ide>
        <nNF>{$nota['numero_nf']}</nNF>
        <dhEmi>{$nota['data_emissao']}T00:00:00-03:00</dhEmi>
        <tpNF>1</tpNF>
      </ide>
      <emit>
        <CNPJ>{$nota['fornecedor_cnpj']}</CNPJ>
        <xNome>{$nota['fornecedor_nome']}</xNome>
      </emit>
      <dest>
        <CNPJ>{$nota['destinatario_cnpj']}</CNPJ>
        <xNome>{$nota['filial_nome']}</xNome>
      </dest>
      <total>
        <ICMSTot>
          <vNF>{$valor}</vNF>
        </ICMSTot>
      </total>
    </infNFe>
  </NFe>
  <protNFe versao="4.00">
    <infProt>
      <chNFe>{$nota['chave_nf']}</chNFe>
      <cStat>100</cStat>
      <xMotivo>Autorizado o uso da NF-e</xMotivo>
    </infProt>
  </protNFe>
</nfeProc>
XML;
    }

    private static function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}
