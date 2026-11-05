<?php
// @versao Proc_08 V_51_05/10/2026
declare(strict_types=1);

/**
 * Model do módulo Processos de NF.
 *
 * Gerencia nf_processos, nf_processo_etapas, nf_processo_historico,
 * nf_processo_anexos e nf_parcelas no banco `compras`.
 * Sem ORM — apenas PDO com prepared statements.
 */
class Process
{
    /**
     * Teto de saltos de decisão em uma única aprovação.
     *
     * Decisões podem apontar para trás, então um template mal montado consegue
     * fechar um ciclo (decide → volta → decide de novo com o mesmo valor).
     * Sem teto, a transação giraria até o timeout do PHP segurando as linhas
     * travadas; com teto, a aprovação falha com mensagem e nada é gravado.
     */
    private const MAX_SALTOS_DECISAO = 50;

    /**
     * Responsável dinâmico do template: "quem abriu o processo".
     *
     * Vale SÓ dentro de nf_template_versoes.etapas. Nas etapas do processo
     * (nf_processo_etapas) ele já chega resolvido em 'usuario' + criado_por —
     * ver resolverAprovadorTemplate().
     */
    public const APROVADOR_SOLICITANTE = 'solicitante';

    /**
     * Traduz o responsável de uma etapa de TEMPLATE para o par gravado na
     * etapa do PROCESSO.
     *
     * Só 'solicitante' precisa de tradução: é o único responsável que o
     * template não sabe nomear — depende de quem abriu. Resolvemos aqui, no
     * momento em que as etapas são congeladas, de propósito:
     *
     *   - nf_processo_etapas continua guardando apenas 'usuario' | 'grupo' |
     *     'arquivo' | 'decisao', então Minhas Tarefas, dashboard, filtros de
     *     responsável e ProcessAccess seguem lendo exatamente o que já liam;
     *   - o processo em andamento não troca de dono se alguém editar o
     *     template depois — mesma garantia que nome_etapa e decisao_saltos já
     *     têm.
     *
     * @param  array  $etapa         linha de etapa do template
     * @param  string $solicitanteId UUID de quem abriu o processo
     * @return array{0:string,1:?string} [tipo_aprovador, aprovador_id]
     */
    /**
     * Barra a abertura de processo em fluxo desativado.
     *
     * Recebe a VERSÃO porque é o que todo caminho de abertura carrega; o
     * `ativo` mora no template (nf_templates), não na versão — desativar é
     * decisão sobre o fluxo inteiro, não sobre uma versão dele.
     *
     * @throws \RuntimeException com mensagem pronta para a tela
     */
    private static function exigirTemplateAtivo(string $templateVersaoId): void
    {
        if ($templateVersaoId === '') {
            return;   // sem versão não há o que validar — o INSERT reclama depois
        }

        $stmt = Database::getInstance()->prepare(
            "SELECT t.nome, t.ativo
               FROM nf_template_versoes tv
               JOIN nf_templates t ON t.id = tv.template_id
              WHERE tv.id = ? LIMIT 1"
        );
        $stmt->execute([$templateVersaoId]);
        $tpl = $stmt->fetch();

        if ($tpl && (int) $tpl['ativo'] === 0) {
            throw new \RuntimeException(
                'O fluxo "' . $tpl['nome'] . '" está desativado e não aceita novos processos. '
                . 'Escolha outro fluxo ou peça a um super admin para reativá-lo.'
            );
        }
    }

    /**
     * A faixa de valor do fluxo é um CONTROLE, não uma sugestão de tela.
     *
     * `valor_min`/`valor_max` existem em `nf_template_versoes` desde o início,
     * mas até aqui só filtravam a LISTA oferecida no step 1
     * (`buscarTemplatesCompatíveis`). Dois caminhos furavam esse filtro:
     *
     *   • tipo de nota marcado como "não exige valor": o filtro de faixa nem
     *     chega a ser aplicado na busca, e o valor real é digitado depois, no
     *     step 2, com o campo editável e sem conferência nenhuma;
     *   • toda abertura que não passa pelo assistente: captura/RPA, importação
     *     e a etapa "Abrir Fluxo".
     *
     * A checagem mora AQUI pelo mesmo motivo de exigirTemplateAtivo(): criar()
     * é por onde passa toda abertura. A tela ainda faz a sua, com mensagem
     * melhor; esta é a que não dá para contornar.
     *
     * Versão que não declara faixa (as duas colunas nulas) não restringe nada.
     * Isso é lacuna de cadastro, não liberação — `cli/verificar_faixas_valor.php`
     * lista quem está assim.
     */
    private static function exigirValorNaFaixa(string $templateVersaoId, array $dados): void
    {
        if ($templateVersaoId === '' || !empty($dados['sem_nota'])) {
            return;
        }

        $valor = (float) ($dados['valor_nf'] ?? 0);
        if ($valor <= 0) {
            return;   // sem valor informado não há o que comparar
        }

        $violacao = self::valorForaDaFaixa($templateVersaoId, $valor);
        if ($violacao === null) {
            return;
        }

        throw new \RuntimeException(
            'O fluxo "' . $violacao['nome'] . '" aceita ' . $violacao['limite']
            . ', e o valor informado é ' . self::formatarReais($valor) . '. '
            . 'Abra o processo no fluxo correspondente a essa faixa.'
        );
    }

    private static function resolverAprovadorTemplate(array $etapa, string $solicitanteId): array
    {
        $tipo = (string) ($etapa['tipo_aprovador'] ?? 'grupo');
        $id   = (string) ($etapa['aprovador_id']   ?? '');

        if ($tipo === self::APROVADOR_SOLICITANTE) {
            return ['usuario', $solicitanteId !== '' ? $solicitanteId : null];
        }

        return [$tipo, $id !== '' ? $id : null];
    }

    // ─────────────────────────────────────────
    // Utilitário interno
    // ─────────────────────────────────────────

    /**
     * Gera um UUID v4 sem dependências externas.
     */
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

    // ─────────────────────────────────────────
    // Geração de código sequencial
    // ─────────────────────────────────────────

    /**
     * Gera o próximo código de processo: PROC-{ANO}-{SEQUENCIA 4 DÍGITOS}.
     * Usa MAX no banco para garantir sequência mesmo em concorrência.
     */
    public static function gerarCodigo(): string
    {
        $pdo  = Database::getInstance();
        $ano  = date('Y');

        $stmt = $pdo->prepare(
            "SELECT MAX(CAST(SUBSTRING_INDEX(codigo, '-', -1) AS UNSIGNED)) AS ultimo
             FROM nf_processos
             WHERE codigo LIKE ?"
        );
        $stmt->execute(["PROC-{$ano}-%"]);
        $row    = $stmt->fetch();
        $proximo = ((int) ($row['ultimo'] ?? 0)) + 1;

        return sprintf('PROC-%s-%04d', $ano, $proximo);
    }

    // ─────────────────────────────────────────
    // Listagem
    // ─────────────────────────────────────────

    /**
     * Lista processos com filtros e paginação.
     *
     * @param array{status?: string, filial_id?: string, busca?: string, periodo_inicio?: string, periodo_fim?: string} $filtros
     */
    /**
     * Aplica ao WHERE os dois recortes de visibilidade do usuário logado:
     * sigilo por template (RH/Jurídico) e escopo por filial. Fora do contexto
     * web (CLI de sync/SLA) não muda nada — os jobs precisam varrer tudo.
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

        [$sigilo, $pSig] = TemplateSigilo::sqlExcluiProcessosSessao($alias);
        if ($sigilo !== '') {
            $where[] = $sigilo;
            $params  = array_merge($params, $pSig);
        }

        [$filial, $pFil] = FilialScope::sqlRestringeSessao("{$alias}.filial_doc");
        if ($filial !== '') {
            $where[] = $filial;
            $params  = array_merge($params, $pFil);
        }

        // Regra geral: processo só aparece para quem está no fluxo.
        $participa = ProcessAccess::sqlParticipaSessao($alias);
        if ($participa !== '') {
            $where[] = $participa;
        }
    }

    /**
     * Filtro de lojas escolhido na tela. Aceita um id só (links e telas antigas)
     * ou vários — a multi-seleção manda `filial_id[]`. Devolve ['', []] quando
     * nada foi marcado, que é "todas as lojas que o usuário já enxerga".
     *
     * Não é recorte de segurança: quem limita o que o usuário vê é
     * aplicarEscopoVisibilidade(). Aqui é só a escolha dele dentro disso.
     *
     * @return array{0:string,1:list<string>}
     */
    private static function filtroFiliais(string $coluna, mixed $valor): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($v): string => trim((string) $v), (array) $valor),
            static fn(string $v): bool => $v !== ''
        )));
        if ($ids === []) {
            return ['', []];
        }
        return [$coluna . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /**
     * Condição SQL da busca livre de listAll()/countAll() — extraído pra um
     * único lugar porque as duas cópias tinham que ser mantidas idênticas.
     *
     * $camposAtivos vem do botão de filtro da tela de pesquisa (nf/valor/
     * cnpj/placa) — vazio = busca em todos os campos (comportamento de
     * sempre). Código e fornecedor não são filtráveis por ali, ficam sempre
     * ativos (só nf/valor/cnpj/placa são o que a tela oferece direcionar).
     *
     * @param array<int,string> $camposAtivos
     * @param array<int,mixed>  &$params      acumulador de binds, na mesma ordem do SQL retornado
     */
    private static function condicaoBusca(string $busca, array $camposAtivos, array &$params): string
    {
        $todos = $camposAtivos === [];
        $like  = '%' . $busca . '%';

        $cond = ['p.codigo LIKE ?', 'p.fornecedor_nome LIKE ?'];
        $pBus = [$like, $like];

        if ($todos || in_array('nf', $camposAtivos, true)) {
            $cond[] = 'p.numero_nf LIKE ?';
            $pBus[] = $like;
        }

        // fornecedor_doc guarda CNPJ/CPF só com dígitos, então do termo cai
        // apenas a PONTUAÇÃO — nunca as letras. "11.222.333/0001-81" continua
        // achando o CNPJ, e a placa "ABC1D23" segue com as letras e não casa
        // com documento nenhum. Tirando as letras (como era antes) ela virava
        // "318" e trazia todo processo de fornecedor com 318 no CNPJ.
        if ($todos || in_array('cnpj', $camposAtivos, true)) {
            $termoDoc = preg_replace('/[^A-Za-z0-9]/', '', $busca);
            if ($termoDoc !== '') {
                $cond[] = 'p.fornecedor_doc LIKE ?';
                $pBus[] = '%' . $termoDoc . '%';
            }
        }

        // JSON_SEARCH varre só os VALORES de dados_formulario ('$.*'), nunca
        // os nomes dos campos — buscar "placa" não traz todo processo que
        // tem um campo Placa. Vale para qualquer campo do template, atual ou
        // futuro.
        //
        // A pontuação cai dos DOIS lados (termo e valor gravado): a placa
        // "ABC1D23" é achada por "ABC1D23" e vice-versa, sem depender de o
        // usuário digitar do mesmo jeito que o formulário guardou. Só saem
        // '-', '.', '/' e espaço — os delimitadores do JSON ({} "" : ,)
        // ficam, senão JSON_SEARCH deixaria de receber um JSON válido.
        if ($todos || in_array('placa', $camposAtivos, true)) {
            $termoJson = preg_replace('/[-.\/ ]/', '', $busca);
            if ($termoJson !== '') {
                $dadosNorm = "REPLACE(REPLACE(REPLACE(REPLACE(
                                  LOWER(p.dados_formulario), '-', ''), '.', ''), '/', ''), ' ', '')";
                $cond[] = "JSON_SEARCH($dadosNorm, 'one', LOWER(?), NULL, '$.*') IS NOT NULL";
                $pBus[] = '%' . $termoJson . '%';
            }
        }

        // Busca por valor (R$) — a tela formata o dígito puro em "1.450,00"
        // (máscara visível, sinaliza pro usuário que vai bater no valor da
        // nota) quando o filtro "Valor" está marcado. Exato de propósito:
        // valor é dado preciso, "parecido" só traria ruído.
        if ($todos || in_array('valor', $camposAtivos, true)) {
            $valor = self::parseValorBr($busca);
            if ($valor !== null) {
                $cond[] = 'p.valor_nf = ?';
                $pBus[] = $valor;
            }
        }

        $params = array_merge($params, $pBus);
        return '(' . implode(' OR ', $cond) . ')';
    }

    /** "1.450,00" / "450,00" → 1450.0 / 450.0. Formato errado → null (não filtra por valor). */
    private static function parseValorBr(string $busca): ?float
    {
        $busca = trim($busca);
        if (!preg_match('/^\d{1,3}(\.\d{3})*,\d{2}$/', $busca)) {
            return null;
        }
        return (float) str_replace(',', '.', str_replace('.', '', $busca));
    }

    public static function listAll(array $filtros = [], int $offset = 0, int $limit = 20): array
    {
        $pdo    = Database::getInstance();
        $where  = ['p.deletado_em IS NULL'];
        $params = [];

        // Sigilo por template (RH/Jurídico) e escopo por filial do usuário
        // logado — aplicados no backend, nunca só escondendo na tela.
        self::aplicarEscopoVisibilidade($where, $params, 'p');

        if (empty($filtros['testes'])) {
            $where[] = 'p.is_teste = 0';
        }

        // Processo finalizado (aprovado) ou encerrado sai da listagem por padrão —
        // inclusive em "Todos os status". Para vê-los, o usuário escolhe o status
        // no filtro. A pesquisa dedicada passa 'incluir_finalizados' para que um
        // processo já fechado continue localizável por código/NF/CNPJ.
        if (!empty($filtros['status']) && $filtros['status'] !== 'todos') {
            $where[]  = 'p.status = ?';
            $params[] = $filtros['status'];
        } elseif (empty($filtros['incluir_finalizados'])) {
            // "Andamento": só o que ainda depende de alguém. Estados terminais
            // (finalizado, cancelado, encerrado) só com o filtro escolhido.
            // 'cancelamento_solicitado' FICA — ainda aguarda confirmação.
            //
            // 'aprovado' NÃO é o fim da linha: todas as etapas passaram, mas o
            // financeiro ainda pode dever o pagamento. Enquanto sobrar parcela
            // em aberto o processo continua dependendo de alguém — e some da
            // tela justamente de quem o autorizou e quer acompanhar até o fim.
            $where[] = "(
                p.status NOT IN ('aprovado', 'cancelado', 'encerrado_apollo')
                OR (p.status = 'aprovado' AND EXISTS (
                        SELECT 1 FROM nf_parcelas pa_and
                         WHERE pa_and.processo_id = p.id
                           AND pa_and.deletado_em IS NULL
                           AND pa_and.status NOT IN ('pago', 'cancelado')))
            )";
        }

        [$condFil, $pFil] = self::filtroFiliais('p.filial_id', $filtros['filial_id'] ?? null);
        if ($condFil !== '') {
            $where[] = $condFil;
            $params  = array_merge($params, $pFil);
        }

        // Fluxo, departamento e setor não são colunas de nf_processos: vivem na
        // versão de template que o processo usa (tv.template_id / tv.setor_id →
        // setores.departamento_id). Por isso todo filtro aqui recai sobre
        // p.template_versao_id — assim listAll e countAll batem sem depender
        // dos JOINs do SELECT.
        if (!empty($filtros['fluxo_id'])) {
            $where[]  = 'p.template_versao_id IN (
                            SELECT tvf.id FROM nf_template_versoes tvf
                            WHERE tvf.template_id = ?)';
            $params[] = $filtros['fluxo_id'];
        }

        if (!empty($filtros['departamento_id'])) {
            $where[]  = 'p.template_versao_id IN (
                            SELECT tvd.id FROM nf_template_versoes tvd
                            JOIN setores sd ON sd.id = tvd.setor_id
                            WHERE sd.departamento_id = ?)';
            $params[] = $filtros['departamento_id'];
        }

        if (!empty($filtros['setor_id'])) {
            $where[]  = 'p.template_versao_id IN (
                            SELECT tvs.id FROM nf_template_versoes tvs
                            WHERE tvs.setor_id = ?)';
            $params[] = $filtros['setor_id'];
        }

        if (!empty($filtros['busca'])) {
            $busca        = trim((string) $filtros['busca']);
            $camposAtivos = is_array($filtros['busca_campos'] ?? null) ? $filtros['busca_campos'] : [];
            $where[]      = self::condicaoBusca($busca, $camposAtivos, $params);
        }

        if (!empty($filtros['periodo_inicio'])) {
            $where[]  = 'DATE(p.criado_em) >= ?';
            $params[] = $filtros['periodo_inicio'];
        }

        if (!empty($filtros['periodo_fim'])) {
            $where[]  = 'DATE(p.criado_em) <= ?';
            $params[] = $filtros['periodo_fim'];
        }

        // Responsável da etapa ATUAL (multi-select; itens ['tipo'=>..., 'id'=>uuid]).
        // Só processos que ESTÃO na mão de alguém — encerrado não tem responsável.
        if (!empty($filtros['resp_ids']) && is_array($filtros['resp_ids'])) {
            $conds = [];
            foreach ($filtros['resp_ids'] as $r) {
                $conds[]  = "(ea.tipo_aprovador = ? AND ea.aprovador_id = ?)";
                $params[] = $r['tipo'];
                $params[] = $r['id'];
            }
            $where[] = "p.status IN ('aberto','devolvido','cancelamento_solicitado')";
            $where[] = "EXISTS (SELECT 1 FROM nf_processo_etapas ea
                                WHERE ea.processo_id = p.id
                                  AND ea.numero_etapa = p.etapa_atual
                                  AND (" . implode(' OR ', $conds) . "))";
        }

        // Sem processos.ver_todos: só processos que o usuário participa
        // (criador ou aprovador direto/por grupo de alguma etapa) ou tem
        // liberação granular pontual — mesmos critérios de ProcessAccess::pode().
        if (!empty($filtros['restringir_usuario'])) {
            $where[] = ProcessAccess::sqlParticipa('p', (string) $filtros['restringir_usuario']);
        }

        $whereClause = implode(' AND ', $where);

        $sql = "
            SELECT
                p.id,
                p.codigo,
                p.fornecedor_nome,
                p.numero_nf,
                p.valor_nf,
                p.data_nf,
                p.sem_nota,
                p.filial_id,
                p.status,
                p.etapa_atual,
                p.is_teste,
                p.criado_em,
                p.concluido_em,
                f.nome AS filial_nome,
                t.nome AS template_nome,
                u.nome AS criado_por_nome,
                (SELECT COUNT(*) FROM nf_processo_etapas pe2 WHERE pe2.processo_id = p.id) AS total_etapas,
                eta.nome_etapa AS etapa_nome,
                eta.tipo_aprovador AS tipo_aprovador,
                CASE eta.tipo_aprovador
                  WHEN 'usuario' THEN (SELECT ua.nome FROM usuarios ua  WHERE ua.id = eta.aprovador_id LIMIT 1)
                  WHEN 'grupo'   THEN (SELECT g.nome  FROM nf_grupos g  WHERE g.id  = eta.aprovador_id LIMIT 1)
                  ELSE NULL
                END AS responsavel_nome
            FROM nf_processos p
            LEFT JOIN filiais f ON f.id = p.filial_id
            LEFT JOIN nf_processo_etapas eta ON eta.processo_id = p.id AND eta.numero_etapa = p.etapa_atual
            LEFT JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
            LEFT JOIN nf_templates t ON t.id = tv.template_id
            LEFT JOIN usuarios u ON u.id = p.criado_por
            WHERE {$whereClause}
            ORDER BY p.criado_em DESC
            LIMIT ? OFFSET ?
        ";

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Conta processos para paginação (mesmos filtros de listAll).
     */
    public static function countAll(array $filtros = []): int
    {
        $pdo    = Database::getInstance();
        $where  = ['p.deletado_em IS NULL'];
        $params = [];

        // Mesmo recorte de listAll — contagem tem que bater com a lista.
        self::aplicarEscopoVisibilidade($where, $params, 'p');

        if (empty($filtros['testes'])) {
            $where[] = 'p.is_teste = 0';
        }

        // Processo finalizado (aprovado) ou encerrado sai da listagem por padrão —
        // inclusive em "Todos os status". Para vê-los, o usuário escolhe o status
        // no filtro. A pesquisa dedicada passa 'incluir_finalizados' para que um
        // processo já fechado continue localizável por código/NF/CNPJ.
        if (!empty($filtros['status']) && $filtros['status'] !== 'todos') {
            $where[]  = 'p.status = ?';
            $params[] = $filtros['status'];
        } elseif (empty($filtros['incluir_finalizados'])) {
            // "Andamento": só o que ainda depende de alguém. Estados terminais
            // (finalizado, cancelado, encerrado) só com o filtro escolhido.
            // 'cancelamento_solicitado' FICA — ainda aguarda confirmação.
            //
            // 'aprovado' NÃO é o fim da linha: todas as etapas passaram, mas o
            // financeiro ainda pode dever o pagamento. Enquanto sobrar parcela
            // em aberto o processo continua dependendo de alguém — e some da
            // tela justamente de quem o autorizou e quer acompanhar até o fim.
            $where[] = "(
                p.status NOT IN ('aprovado', 'cancelado', 'encerrado_apollo')
                OR (p.status = 'aprovado' AND EXISTS (
                        SELECT 1 FROM nf_parcelas pa_and
                         WHERE pa_and.processo_id = p.id
                           AND pa_and.deletado_em IS NULL
                           AND pa_and.status NOT IN ('pago', 'cancelado')))
            )";
        }

        [$condFil, $pFil] = self::filtroFiliais('p.filial_id', $filtros['filial_id'] ?? null);
        if ($condFil !== '') {
            $where[] = $condFil;
            $params  = array_merge($params, $pFil);
        }

        // Fluxo, departamento e setor não são colunas de nf_processos: vivem na
        // versão de template que o processo usa (tv.template_id / tv.setor_id →
        // setores.departamento_id). Por isso todo filtro aqui recai sobre
        // p.template_versao_id — assim listAll e countAll batem sem depender
        // dos JOINs do SELECT.
        if (!empty($filtros['fluxo_id'])) {
            $where[]  = 'p.template_versao_id IN (
                            SELECT tvf.id FROM nf_template_versoes tvf
                            WHERE tvf.template_id = ?)';
            $params[] = $filtros['fluxo_id'];
        }

        if (!empty($filtros['departamento_id'])) {
            $where[]  = 'p.template_versao_id IN (
                            SELECT tvd.id FROM nf_template_versoes tvd
                            JOIN setores sd ON sd.id = tvd.setor_id
                            WHERE sd.departamento_id = ?)';
            $params[] = $filtros['departamento_id'];
        }

        if (!empty($filtros['setor_id'])) {
            $where[]  = 'p.template_versao_id IN (
                            SELECT tvs.id FROM nf_template_versoes tvs
                            WHERE tvs.setor_id = ?)';
            $params[] = $filtros['setor_id'];
        }

        if (!empty($filtros['busca'])) {
            $busca        = trim((string) $filtros['busca']);
            $camposAtivos = is_array($filtros['busca_campos'] ?? null) ? $filtros['busca_campos'] : [];
            $where[]      = self::condicaoBusca($busca, $camposAtivos, $params);
        }

        if (!empty($filtros['periodo_inicio'])) {
            $where[]  = 'DATE(p.criado_em) >= ?';
            $params[] = $filtros['periodo_inicio'];
        }

        if (!empty($filtros['periodo_fim'])) {
            $where[]  = 'DATE(p.criado_em) <= ?';
            $params[] = $filtros['periodo_fim'];
        }

        // Responsável da etapa ATUAL (multi-select; itens ['tipo'=>..., 'id'=>uuid]).
        // Só processos que ESTÃO na mão de alguém — encerrado não tem responsável.
        if (!empty($filtros['resp_ids']) && is_array($filtros['resp_ids'])) {
            $conds = [];
            foreach ($filtros['resp_ids'] as $r) {
                $conds[]  = "(ea.tipo_aprovador = ? AND ea.aprovador_id = ?)";
                $params[] = $r['tipo'];
                $params[] = $r['id'];
            }
            $where[] = "p.status IN ('aberto','devolvido','cancelamento_solicitado')";
            $where[] = "EXISTS (SELECT 1 FROM nf_processo_etapas ea
                                WHERE ea.processo_id = p.id
                                  AND ea.numero_etapa = p.etapa_atual
                                  AND (" . implode(' OR ', $conds) . "))";
        }

        // Sem processos.ver_todos: só processos que o usuário participa
        // (criador ou aprovador direto/por grupo de alguma etapa) ou tem
        // liberação granular pontual — mesmos critérios de ProcessAccess::pode().
        if (!empty($filtros['restringir_usuario'])) {
            $where[] = ProcessAccess::sqlParticipa('p', (string) $filtros['restringir_usuario']);
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM nf_processos p WHERE {$whereClause}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Retorna totais por status para os cards de resumo.
     *
     * @return array{total: int, abertos: int, devolvidos: int, aprovados: int, aprovados_mes: int, aguardando_minha_aprovacao: int}
     */
    public static function getTotais(string $usuarioId, array $gruposIds = [], bool $showTestes = false, bool $restringirUsuario = false): array
    {
        $pdo  = Database::getInstance();
        $testeFilter = $showTestes ? '' : ' AND is_teste = 0';

        // Sem processos.ver_todos, os cards de resumo têm que bater com a
        // lista filtrada abaixo — senão "Total: 36" ao lado de 3 linhas
        // visíveis tanto confunde quanto vaza contagem de dados alheios.
        $restrictSql = '';
        $params = [];
        if ($restringirUsuario) {
            $restrictSql = ' AND ' . ProcessAccess::sqlParticipa('nf_processos', $usuarioId);
        }

        // Sigilo/escopo do usuário logado — os cards não podem contar o que a
        // lista esconde (contagem também é informação).
        $escopoWhere = [];
        self::aplicarEscopoVisibilidade($escopoWhere, $params, 'nf_processos');
        $escopoSql = $escopoWhere ? ' AND ' . implode(' AND ', $escopoWhere) : '';

        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'aberto')    AS abertos,
                SUM(status = 'devolvido') AS devolvidos,
                SUM(status = 'aprovado')  AS aprovados,
                SUM(status = 'aprovado' AND MONTH(concluido_em) = MONTH(NOW()) AND YEAR(concluido_em) = YEAR(NOW())) AS aprovados_mes
             FROM nf_processos
             WHERE deletado_em IS NULL{$testeFilter}{$restrictSql}{$escopoSql}"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();

        $aguardando = self::contarAguardandoAprovacao($usuarioId, $gruposIds, $showTestes);

        return [
            'total'                       => (int) ($row['total'] ?? 0),
            'abertos'                     => (int) ($row['abertos'] ?? 0),
            'devolvidos'                  => (int) ($row['devolvidos'] ?? 0),
            'aprovados'                   => (int) ($row['aprovados'] ?? 0),
            'aprovados_mes'               => (int) ($row['aprovados_mes'] ?? 0),
            'aguardando_minha_aprovacao'  => $aguardando,
        ];
    }

    /**
     * Conta processos aguardando aprovação do usuário (direto ou via grupo).
     */
    private static function contarAguardandoAprovacao(string $usuarioId, array $gruposIds = [], bool $showTestes = false): int
    {
        $pdo    = Database::getInstance();
        $where  = ['pe.status = \'pendente\'', 'p.status = \'aberto\'', 'p.deletado_em IS NULL',
                   'pe.numero_etapa = p.etapa_atual'];
        if (!$showTestes) {
            $where[] = 'p.is_teste = 0';
        }
        $params = [];

        // Aprovador direto OU membro de grupo aprovador
        $condicoes = ['(pe.tipo_aprovador = \'usuario\' AND pe.aprovador_id = ?)'];
        $params[]  = $usuarioId;

        if (!empty($gruposIds)) {
            $placeholders = implode(',', array_fill(0, count($gruposIds), '?'));
            $condicoes[]  = "(pe.tipo_aprovador = 'grupo' AND pe.aprovador_id IN ({$placeholders}))";
            $params       = array_merge($params, $gruposIds);
        }

        $where[] = '(' . implode(' OR ', $condicoes) . ')';
        $whereClause = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT p.id)
             FROM nf_processo_etapas pe
             JOIN nf_processos p ON p.id = pe.processo_id
             WHERE {$whereClause}"
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // ─────────────────────────────────────────
    // Busca por ID
    // ─────────────────────────────────────────

    /**
     * Busca processo por ID com etapas, histórico e anexos.
     *
     * @return array|null  null se não encontrado ou deletado
     */
    public static function findById(string $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT
                p.*,
                f.nome  AS filial_nome,
                t.nome  AS template_nome,
                tv.campos AS template_campos,
                tv.etapas AS template_etapas,
                tv.tipos_anexo AS template_tipos_anexo,
                tv.tem_financeiro,
                u.nome  AS criado_por_nome,
                u.email AS criado_por_email
             FROM nf_processos p
             LEFT JOIN filiais f ON f.id = p.filial_id
             LEFT JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
             LEFT JOIN nf_templates t ON t.id = tv.template_id
             LEFT JOIN usuarios u ON u.id = p.criado_por
             WHERE p.id = ? AND p.deletado_em IS NULL
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $processo = $stmt->fetch();
        if (!$processo) {
            return null;
        }

        // Etapas
        $processo['etapas'] = self::getEtapas($id);

        // Histórico
        $processo['historico'] = self::getHistorico($id);

        // Anexos
        $processo['anexos'] = self::getAnexos($id);

        // Parcelas (se tiver)
        $processo['parcelas'] = self::getParcelas($id);

        // Decodificar JSON
        $processo['dados_formulario'] = json_decode($processo['dados_formulario'] ?? '{}', true) ?: [];

        return $processo;
    }

    /**
     * Opções de um campo de formulário do template.
     *
     * Um campo tipo lista/select pode apontar para uma tabela auxiliar
     * (`lista_id`), para as pseudo-listas `_filiais`/`_setores`, ou trazer as
     * opções inline em `opcoes`. Sempre devolve o par id/nome — o id é o que
     * vai gravado em dados_formulario.
     *
     * @return array<int,array{id:string,nome:string}>
     */
    public static function opcoesCampo(array $campo): array
    {
        if (!in_array($campo['tipo'] ?? '', ['lista', 'select'], true)) {
            return [];
        }

        $pdo     = Database::getInstance();
        $listaId = (string) ($campo['lista_id'] ?? '');

        if ($listaId === '_filiais') {
            return $pdo->query("SELECT id, nome FROM filiais WHERE ativo = 1 ORDER BY nome")->fetchAll();
        }
        if ($listaId === '_setores') {
            return $pdo->query("SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY nome")->fetchAll();
        }
        if ($listaId !== '') {
            $stmt = $pdo->prepare(
                "SELECT id, valor AS nome FROM nf_lista_itens WHERE lista_id = ? AND ativo = 1 ORDER BY valor"
            );
            $stmt->execute([$listaId]);
            return $stmt->fetchAll();
        }

        // Opções digitadas direto no template — o próprio texto é o valor.
        $inline = [];
        foreach ($campo['opcoes'] ?? [] as $op) {
            $v = is_array($op) ? (string) ($op['valor'] ?? '') : (string) $op;
            if ($v !== '') { $inline[] = ['id' => $v, 'nome' => $v]; }
        }
        return $inline;
    }

    /**
     * Traduz dados_formulario para exibição (label + texto legível).
     *
     * Campo de lista grava o ID do item (nf_lista_itens.id, filiais.id ou
     * setores.id), não o texto — sem esta tradução a tela mostra o UUID cru.
     * Também troca a chave interna do campo pelo label configurado no template.
     *
     * @param  array        $dadosForm  dados_formulario já decodificado
     * @param  string|array $camposTpl  nf_template_versoes.campos (JSON ou array)
     * @return array<int,array{label:string,valor:string}>
     */
    public static function rotularCampos(array $dadosForm, string|array $camposTpl): array
    {
        if (is_string($camposTpl)) {
            $camposTpl = json_decode($camposTpl, true) ?: [];
        }

        $config = [];
        foreach ($camposTpl as $c) {
            $nome = (string) ($c['nome'] ?? '');
            if ($nome !== '') { $config[$nome] = $c; }
        }

        // Junta os ids por origem para resolver tudo em uma consulta por tabela.
        $porOrigem = ['nf_lista_itens' => [], 'filiais' => [], 'setores' => []];
        foreach ($dadosForm as $nome => $valor) {
            $c = $config[$nome] ?? null;
            if (!$c || !in_array($c['tipo'] ?? '', ['lista', 'select'], true)) { continue; }
            $valor = is_array($valor) ? '' : (string) $valor;
            if ($valor === '') { continue; }

            $listaId = (string) ($c['lista_id'] ?? '');
            if ($listaId === '_filiais')     { $porOrigem['filiais'][$valor] = true; }
            elseif ($listaId === '_setores') { $porOrigem['setores'][$valor] = true; }
            elseif ($listaId !== '')         { $porOrigem['nf_lista_itens'][$valor] = true; }
        }

        $mapa    = [];
        $colunas = ['nf_lista_itens' => 'valor', 'filiais' => 'nome', 'setores' => 'nome'];
        foreach ($porOrigem as $tabela => $ids) {
            $ids = array_keys($ids);
            if (!$ids) { continue; }
            $ph   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = Database::getInstance()->prepare(
                "SELECT id, {$colunas[$tabela]} AS texto FROM {$tabela} WHERE id IN ({$ph})"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $r) { $mapa[$r['id']] = (string) $r['texto']; }
        }

        $saida = [];
        foreach ($dadosForm as $nome => $valor) {
            if ($valor === '' || $valor === null) { continue; }
            $c        = $config[$nome] ?? null;
            $valorStr = is_array($valor) ? implode(', ', $valor) : (string) $valor;
            $saida[]  = [
                'label' => (string) ($c['label'] ?? $nome),
                'valor' => $mapa[$valorStr] ?? $valorStr,
            ];
        }
        return $saida;
    }

    /**
     * Retorna etapas do processo com dados do aprovador.
     */
    public static function getEtapas(string $processoId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT
                pe.*,
                u.nome  AS atuado_por_nome,
                -- Distingue as duas causas de status 'pulado': etapa de
                -- observação (nasce pulada, dá visibilidade sem pedir ação) e
                -- etapa de ramo descartado por uma decisão. A tela mostra
                -- rótulos diferentes para cada uma.
                COALESCE(ec.somente_observacao, 0) AS somente_observacao,
                CASE
                    WHEN pe.tipo_aprovador = 'usuario' THEN ua.nome
                    WHEN pe.tipo_aprovador = 'grupo'   THEN g.nome
                    WHEN pe.tipo_aprovador = 'arquivo' THEN 'Arquivamento automático'
                    ELSE NULL
                END AS aprovador_nome
             FROM nf_processo_etapas pe
             LEFT JOIN usuarios u  ON u.id = pe.atuado_por
             LEFT JOIN usuarios ua ON (pe.tipo_aprovador = 'usuario' AND ua.id = pe.aprovador_id)
             LEFT JOIN nf_grupos g ON (pe.tipo_aprovador = 'grupo'   AND g.id  = pe.aprovador_id)
             LEFT JOIN nf_etapas ec ON ec.id = pe.etapa_catalogo_id
             WHERE pe.processo_id = ?
             ORDER BY pe.numero_etapa ASC"
        );
        $stmt->execute([$processoId]);
        return $stmt->fetchAll();
    }

    /**
     * Retorna histórico do processo (mais recente primeiro).
     */
    public static function getHistorico(string $processoId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT h.*, u.nome AS ator_nome_real
             FROM nf_processo_historico h
             LEFT JOIN usuarios u ON u.id = h.ator_id
             WHERE h.processo_id = ?
             ORDER BY h.criado_em ASC"
        );
        $stmt->execute([$processoId]);
        return $stmt->fetchAll();
    }

    /**
     * Retorna anexos não deletados do processo.
     */
    public static function getAnexos(string $processoId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT a.*, u.nome AS enviado_por_nome
             FROM nf_processo_anexos a
             LEFT JOIN usuarios u ON u.id = a.enviado_por
             WHERE a.processo_id = ? AND a.deletado_em IS NULL
             ORDER BY a.enviado_em ASC"
        );
        $stmt->execute([$processoId]);
        return $stmt->fetchAll();
    }

    /**
     * Retorna parcelas do processo.
     */
    public static function getParcelas(string $processoId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT par.*, li.valor AS forma_nome
             FROM nf_parcelas par
             LEFT JOIN nf_lista_itens li ON li.id = par.forma_pagamento_id
             WHERE par.processo_id = ? AND par.deletado_em IS NULL
             ORDER BY par.numero_parcela ASC"
        );
        $stmt->execute([$processoId]);
        return $stmt->fetchAll();
    }

    /** Rateios (centros de custo) do processo. */
    public static function getRateios(string $processoId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT id, setor_id, setor_codigo, setor_nome, percentual, valor
             FROM nf_processo_rateios WHERE processo_id = ? ORDER BY setor_nome ASC"
        );
        $stmt->execute([$processoId]);
        return $stmt->fetchAll();
    }

    /** Tipos de despesa (classificação do gasto) do processo. */
    public static function getTiposDespesa(string $processoId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT id, tipo_id, tipo_nome, percentual, valor
             FROM nf_processo_tipos_despesa WHERE processo_id = ? ORDER BY tipo_nome ASC"
        );
        $stmt->execute([$processoId]);
        return $stmt->fetchAll();
    }

    /**
     * Config da versão publicada do template (para reconstruir o formulário de
     * edição do processo): campos, etapas, tipos de anexo e flags financeiras.
     */
    public static function versaoConfig(string $versaoId): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT campos, etapas, tipos_anexo, tem_financeiro, tem_rateio,
                    tem_tipo_despesa, tipo_despesa_obrigatorio, tipo_nota_id,
                    permite_sem_nota
             FROM nf_template_versoes WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$versaoId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        return [
            'campos'                   => json_decode($row['campos'] ?? '[]', true) ?: [],
            'etapas'                   => json_decode($row['etapas'] ?? '[]', true) ?: [],
            'tipos_anexo'              => json_decode($row['tipos_anexo'] ?? '[]', true) ?: [],
            'tem_financeiro'           => (bool) $row['tem_financeiro'],
            'tem_rateio'               => (bool) $row['tem_rateio'],
            'tem_tipo_despesa'         => (bool) $row['tem_tipo_despesa'],
            'tipo_despesa_obrigatorio' => (bool) $row['tipo_despesa_obrigatorio'],
            'tipo_nota_id'             => $row['tipo_nota_id'] ?? null,
            'permite_sem_nota'         => (bool) ($row['permite_sem_nota'] ?? 0),
        ];
    }

    // ─────────────────────────────────────────
    // Busca de templates compatíveis
    // ─────────────────────────────────────────

    /**
     * Busca templates compatíveis com o contexto fornecido.
     * Inclui publicados + em teste (teste_interno, uat) para permitir processos de teste.
     * Publicados aparecem primeiro, depois os de teste.
     */
    /**
     * Fornecedor interno usado por processos que não têm fornecedor de verdade
     * (hoje: solicitação de acesso fora do horário). Criado sob demanda.
     *
     * É UM registro só, com CNPJ reservado, e não os funcionários: `fornecedor_id`
     * é NOT NULL e aponta para `fornecedores`, então usar o CPF de cada pessoa
     * criaria um "fornecedor" por funcionário — que apareceria no autocomplete
     * da abertura, nos relatórios por fornecedor e na checagem de nota duplicada.
     *
     * @return array{id: string, nome: string, doc: string}
     */
    public static function fornecedorInterno(): array
    {
        // 00000000000000 nunca é um CNPJ real — deixa o registro fácil de
        // reconhecer e de excluir de qualquer relatório.
        $doc  = '00000000000000';
        $nome = 'SOLICITACAO INTERNA (sem fornecedor)';

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT id, razao_social FROM fornecedores WHERE cnpj = ? LIMIT 1');
        $stmt->execute([$doc]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return ['id' => $row['id'], 'nome' => $row['razao_social'], 'doc' => $doc];
        }

        $id = self::uuid();
        $pdo->prepare(
            'INSERT INTO fornecedores (id, razao_social, nome_fantasia, cnpj, ativo)
             VALUES (?, ?, ?, ?, 1)'
        )->execute([$id, $nome, $nome, $doc]);

        return ['id' => $id, 'nome' => $nome, 'doc' => $doc];
    }

    public static function buscarTemplatesCompatíveis(
        string  $filialId,
        string  $setorId,
        string  $tipoNotaId,
        float   $valor,
        string  $departamentoId = ''
    ): array {
        $pdo = Database::getInstance();

        // Departamento é o PRIMEIRO recorte do funil (Filial → Departamento →
        // Setor → Template) e por isso é exato, não "IS NULL OR =". Template
        // sem departamento declarado não aparece na abertura: antes, ele casava
        // com todo departamento e poluía a escolha de todos os setores.
        $filtroDepartamento = '';
        $params = [$filialId];
        if ($departamentoId !== '') {
            $filtroDepartamento = 'AND tv.departamento_id = ?';
            $params[] = $departamentoId;
        }
        array_push($params, $setorId, $tipoNotaId);

        // Tipo de nota que não exige valor (Cancelamento, Devolução, Remessa)
        // não filtra pela faixa do template: sem valor informado, comparar com
        // valor_min/valor_max esconderia todo template que declara uma faixa.
        require_once SRC . '/Models/TableList.php';
        $filtroValor = '';
        if (TableList::tipoNotaExigeValor($tipoNotaId)) {
            $filtroValor = 'AND (tv.valor_min IS NULL OR ? >= tv.valor_min)
               AND (tv.valor_max IS NULL OR ? <= tv.valor_max)';
            array_push($params, $valor, $valor);
        }

        $stmt = $pdo->prepare(
            "SELECT
                tv.id,
                tv.status,
                tv.campos,
                tv.etapas,
                tv.tipos_anexo,
                tv.tem_financeiro,
                tv.permite_sem_nota,
                tv.tem_rateio,
                tv.tem_tipo_despesa,
                tv.tipo_despesa_obrigatorio,
                tv.libera_excecao_horario,
                tv.filial_id,
                tv.setor_id,
                tv.tipo_nota_id,
                tv.valor_min,
                tv.valor_max,
                t.nome
             FROM nf_template_versoes tv
             JOIN nf_templates t ON t.id = tv.template_id
             WHERE tv.status IN ('publicado', 'teste_interno', 'uat')
               AND t.ativo = 1
               AND (tv.filial_id IS NULL OR tv.filial_id = ?)
               {$filtroDepartamento}
               AND (tv.setor_id  IS NULL OR tv.setor_id  = ?)
               AND (tv.tipo_nota_id IS NULL OR tv.tipo_nota_id = ?)
               {$filtroValor}
             ORDER BY
               (tv.status = 'publicado') DESC,
               (tv.filial_id    IS NOT NULL) DESC,
               (tv.setor_id     IS NOT NULL) DESC,
               (tv.tipo_nota_id IS NOT NULL) DESC,
               t.nome ASC
             LIMIT 50"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Decodificar JSON de cada template
        foreach ($rows as &$row) {
            $row['campos']      = json_decode($row['campos']      ?? '[]', true) ?: [];
            $row['etapas']      = json_decode($row['etapas']      ?? '[]', true) ?: [];
            $row['tipos_anexo'] = json_decode($row['tipos_anexo'] ?? '[]', true) ?: [];
        }
        unset($row);

        return $rows;
    }

    // ─────────────────────────────────────────
    // Faixa de valor do fluxo (alçada)
    // ─────────────────────────────────────────

    /**
     * Faixa de valor declarada pela versão do template.
     *
     * @return array{min:?float,max:?float,nome:string}|null null = versão inexistente
     */
    public static function faixaDaVersao(string $templateVersaoId): ?array
    {
        if ($templateVersaoId === '') {
            return null;
        }

        $stmt = Database::getInstance()->prepare(
            "SELECT tv.valor_min, tv.valor_max, t.nome
               FROM nf_template_versoes tv
               JOIN nf_templates t ON t.id = tv.template_id
              WHERE tv.id = ? LIMIT 1"
        );
        $stmt->execute([$templateVersaoId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return [
            'min'  => $row['valor_min'] !== null ? (float) $row['valor_min'] : null,
            'max'  => $row['valor_max'] !== null ? (float) $row['valor_max'] : null,
            'nome' => (string) $row['nome'],
        ];
    }

    /**
     * Confere um valor contra a faixa da versão. Comparação pura, sem mensagem
     * — quem monta o texto é quem chama, porque a tela e o guard de criar()
     * falam de jeitos diferentes com o usuário.
     *
     * @return array{nome:string,limite:string,min:?float,max:?float}|null
     *         null = dentro da faixa, ou versão que não declara faixa nenhuma
     */
    public static function valorForaDaFaixa(string $templateVersaoId, float $valor): ?array
    {
        $faixa = self::faixaDaVersao($templateVersaoId);
        if ($faixa === null || ($faixa['min'] === null && $faixa['max'] === null)) {
            return null;
        }

        $abaixo = $faixa['min'] !== null && $valor < $faixa['min'];
        $acima  = $faixa['max'] !== null && $valor > $faixa['max'];
        if (!$abaixo && !$acima) {
            return null;
        }

        if ($faixa['min'] !== null && $faixa['max'] !== null) {
            $limite = 'de ' . self::formatarReais($faixa['min'])
                    . ' a ' . self::formatarReais($faixa['max']);
        } elseif ($abaixo) {
            $limite = 'a partir de ' . self::formatarReais($faixa['min']);
        } else {
            $limite = 'até ' . self::formatarReais($faixa['max']);
        }

        return [
            'nome'   => $faixa['nome'],
            'limite' => $limite,
            'min'    => $faixa['min'],
            'max'    => $faixa['max'],
        ];
    }

    /**
     * Nomes dos fluxos do mesmo recorte que DECLARAM faixa e aceitam este valor.
     *
     * Serve só para a mensagem de recusa apontar o caminho certo — a escolha do
     * fluxo continua sendo do step 1.
     *
     * Filtra a faixa aqui, e não pela query: `buscarTemplatesCompatíveis()` só
     * aplica valor_min/valor_max quando o tipo de nota exige valor, e é
     * justamente o caso contrário que traz o usuário até esta mensagem.
     *
     * Fluxo SEM faixa declarada não é sugerido: recomendá-lo seria mandar o
     * usuário para a mesma lacuna de cadastro que esta checagem existe para
     * fechar.
     *
     * @param array $contexto filial_id, setor_id, tipo_nota_id, departamento_id
     * @return list<string>
     */
    public static function sugerirTemplatesParaValor(
        float  $valor,
        array  $contexto,
        string $excluirVersaoId = ''
    ): array {
        foreach (['filial_id', 'setor_id', 'tipo_nota_id'] as $obrigatorio) {
            if (empty($contexto[$obrigatorio])) {
                return [];
            }
        }

        try {
            $compat = self::buscarTemplatesCompatíveis(
                (string) $contexto['filial_id'],
                (string) $contexto['setor_id'],
                (string) $contexto['tipo_nota_id'],
                $valor,
                (string) ($contexto['departamento_id'] ?? '')
            );
        } catch (\Throwable $e) {
            return [];   // sugestão é cortesia: nunca derruba a recusa
        }

        $nomes = [];
        foreach ($compat as $tpl) {
            if ($tpl['id'] === $excluirVersaoId) {
                continue;
            }
            $min = $tpl['valor_min'] !== null ? (float) $tpl['valor_min'] : null;
            $max = $tpl['valor_max'] !== null ? (float) $tpl['valor_max'] : null;
            if ($min === null && $max === null) {
                continue;
            }
            if ($min !== null && $valor < $min) {
                continue;
            }
            if ($max !== null && $valor > $max) {
                continue;
            }
            $nomes[(string) $tpl['nome']] = true;
            if (count($nomes) >= 3) {
                break;
            }
        }

        return array_keys($nomes);
    }

    /** Valor em reais no formato da tela — usado nas mensagens de faixa. */
    private static function formatarReais(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    // ─────────────────────────────────────────
    // Validação de nota fiscal duplicada
    // ─────────────────────────────────────────

    /**
     * Verifica se já existe um processo com a mesma nota fiscal.
     *
     * Uma NF é considerada duplicada quando:
     *   - possui a mesma chave de acesso (chave_nf — 44 dígitos em NF-e/CT-e,
     *     50 na NFS-e padrão nacional), OU
     *   - possui o mesmo número de NF para o mesmo fornecedor
     *     (identificado por fornecedor_id quando houver, ou pelo CNPJ/CPF).
     *
     * Processos cancelados são ignorados (a NF pode ser relançada).
     *
     * @return array{codigo:string,status:string}|null Dados do processo existente ou null
     */
    public static function notaDuplicada(
        string $numeroNf,
        ?string $fornecedorId = null,
        ?string $fornecedorDoc = null,
        ?string $chaveNf = null,
        ?string $ignorarProcessoId = null
    ): ?array {
        $pdo = Database::getInstance();

        $numeroNf = trim($numeroNf);
        $chaveNf  = $chaveNf !== null ? preg_replace('/\D/', '', $chaveNf) : '';
        $docLimpo = $fornecedorDoc !== null ? preg_replace('/\D/', '', $fornecedorDoc) : '';

        // Processo sem nota não tem o que duplicar: sem número e sem chave, a
        // regra "mesmo número para o mesmo fornecedor" casaria todos os
        // processos sem nota do mesmo fornecedor entre si.
        if ($numeroNf === '' && $chaveNf === '') {
            return null;
        }

        // 1. Chave de acesso é o identificador mais forte quando presente
        // 44 = NF-e/CT-e · 50 = NFS-e padrão nacional. Fora desses tamanhos a
        // chave está incompleta e não serve como identificador forte.
        if (in_array(strlen($chaveNf), [44, 50], true)) {
            $sql = "SELECT codigo, status FROM nf_processos
                    WHERE REPLACE(REPLACE(chave_nf, ' ', ''), '.', '') = ?
                      AND status <> 'cancelado'
                      AND deletado_em IS NULL";
            $params = [$chaveNf];
            if ($ignorarProcessoId !== null) { $sql .= " AND id <> ?"; $params[] = $ignorarProcessoId; }
            $sql .= " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            if ($row) return $row;
        }

        // 1b. A chave de 44 dígitos (NF-e/CT-e) carrega CNPJ do emitente (pos. 7–20)
        // e número da nota (pos. 26–34). Um processo lançado à mão traz só o número,
        // sem chave: sem este cruzamento a mesma nota entrava duas vezes.
        if (strlen($chaveNf) === 44) {
            $sql = "SELECT codigo, status FROM nf_processos
                    WHERE TRIM(LEADING '0' FROM numero_nf) = TRIM(LEADING '0' FROM ?)
                      AND REPLACE(REPLACE(REPLACE(fornecedor_doc, '.', ''), '/', ''), '-', '') = ?
                      AND status <> 'cancelado'
                      AND deletado_em IS NULL";
            $params = [substr($chaveNf, 25, 9), substr($chaveNf, 6, 14)];
            if ($ignorarProcessoId !== null) { $sql .= " AND id <> ?"; $params[] = $ignorarProcessoId; }
            $sql .= " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            if ($row) return $row;
        }

        // 2. Mesmo número de NF para o mesmo fornecedor
        // Comparação ignora zeros à esquerda ("01234" = "1234") — digitação
        // com padding não pode escapar da checagem de duplicidade.
        // Também casa processo que veio de captura só com a chave (número vazio):
        // o número dele está dentro da chave.
        if ($numeroNf === '') return null;

        $sql    = "SELECT codigo, status FROM nf_processos
                   WHERE (TRIM(LEADING '0' FROM numero_nf) = TRIM(LEADING '0' FROM ?)
                          OR (COALESCE(numero_nf, '') = '' AND LENGTH(chave_nf) = 44
                              AND TRIM(LEADING '0' FROM SUBSTRING(chave_nf, 26, 9)) = TRIM(LEADING '0' FROM ?)))
                     AND status <> 'cancelado'
                     AND deletado_em IS NULL";
        $params = [$numeroNf, $numeroNf];

        if (!empty($fornecedorId)) {
            $sql .= " AND fornecedor_id = ?";
            $params[] = $fornecedorId;
        } elseif ($docLimpo !== '') {
            $sql .= " AND REPLACE(REPLACE(REPLACE(fornecedor_doc, '.', ''), '/', ''), '-', '') = ?";
            $params[] = $docLimpo;
        } else {
            // Sem fornecedor identificável não há como afirmar duplicidade
            return null;
        }

        if ($ignorarProcessoId !== null) { $sql .= " AND id <> ?"; $params[] = $ignorarProcessoId; }
        $sql .= " LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    // ─────────────────────────────────────────
    // Criação de processo (transação atômica)
    // ─────────────────────────────────────────

    /**
     * Cria um processo completo em transação atômica.
     *
     * Inclui: nf_processos + nf_processo_etapas + nf_processo_historico
     * + nf_parcelas (se tem_financeiro=1) + notificação para aprovadores etapa 1.
     *
     * @param array{
     *   template_versao_id: string,
     *   fornecedor_id: string,
     *   fornecedor_nome: string,
     *   fornecedor_doc: string,
     *   numero_nf: string,
     *   valor_nf: float,
     *   data_nf: string,
     *   chave_nf?: string,
     *   filial_id: string,
     *   filial_doc?: string,
     *   dados_formulario: array,
     *   is_teste?: bool,
     *   etapas_template: array,
     *   tem_financeiro: bool,
     *   parcelas?: array
     * } $dados
     * @param string $criadoPor UUID do usuário criador
     * @return array{id: string, codigo: string} ID e código do processo criado
     * @throws \RuntimeException em caso de falha
     */
    public static function criar(array $dados, string $criadoPor): array
    {
        $pdo = Database::getInstance();

        // A etapa "Abrir Fluxo" chama criar() de DENTRO da transação de
        // aprovar(). MySQL não aninha transação, e um beginTransaction() aqui
        // estouraria com "There is already an active transaction".
        //
        // Quando já existe transação aberta, esta chamada entra nela: o
        // processo filho e o encerramento do pai passam a ser um ato só — ou os
        // dois acontecem, ou nenhum. Quem abriu a transação é quem a fecha.
        // Fluxo desativado não recebe processo NOVO. A checagem mora AQUI, e
        // não em cada tela, porque criar() é por onde passa toda abertura: o
        // assistente, a captura/RPA, a importação e a etapa "Abrir Fluxo". Os
        // seletores escondem o inativo, mas esconder não é impedir — um POST
        // com o id na mão ainda chegaria até aqui.
        //
        // Fora da transação de propósito: é validação de entrada, não tem o
        // que desfazer, e assim a etapa "Abrir Fluxo" (que chama criar() de
        // dentro da transação de aprovar()) recebe a exceção com a transação
        // ainda íntegra para o rollback de quem a abriu.
        self::exigirTemplateAtivo((string) ($dados['template_versao_id'] ?? ''));

        // Mesma razão, mesmo lugar: a faixa de valor do fluxo é alçada, e
        // alçada que só existe na tela não é alçada. Fora da transação como a
        // checagem acima — é validação de entrada, não tem o que desfazer.
        self::exigirValorNaFaixa((string) ($dados['template_versao_id'] ?? ''), $dados);

        $transacaoPropria = !$pdo->inTransaction();
        if ($transacaoPropria) {
            $pdo->beginTransaction();
        }

        try {
            $processoId = self::uuid();
            $codigo     = self::gerarCodigo();
            $isTeste    = (bool) ($dados['is_teste'] ?? false);
            $semNota    = (bool) ($dados['sem_nota'] ?? false);

            // ── 1. Inserir processo ──────────────────────
            $stmt = $pdo->prepare(
                "INSERT INTO nf_processos
                    (id, codigo, template_versao_id, fornecedor_id, fornecedor_nome, fornecedor_doc,
                     numero_nf, valor_nf, data_nf, chave_nf, sem_nota, filial_id, filial_doc,
                     dados_formulario, etapa_atual, status, is_teste, estagio_teste,
                     processo_pai_id, criado_por, criado_em)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'aberto', ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $processoId,
                $codigo,
                $dados['template_versao_id'],
                $dados['fornecedor_id']   ?: null,
                $dados['fornecedor_nome'],
                $dados['fornecedor_doc']  ?: null,
                // Processo sem nota grava NULL nos três — zero seria uma nota
                // de valor zero, NULL é a ausência de nota.
                $semNota ? null : $dados['numero_nf'],
                $semNota ? null : $dados['valor_nf'],
                $semNota ? null : $dados['data_nf'],
                $semNota ? null : ($dados['chave_nf'] ?: null),
                $semNota ? 1 : 0,
                $dados['filial_id'],
                $dados['filial_doc']      ?: null,
                json_encode($dados['dados_formulario'] ?: [], JSON_UNESCAPED_UNICODE),
                $isTeste ? 1 : 0,
                $dados['estagio_teste'] ?? null,
                // Preenchido só quando o processo nasce de uma etapa "Abrir
                // Fluxo" de outro processo.
                $dados['processo_pai_id'] ?? null,
                $criadoPor,
            ]);

            // ── 2. Inserir etapas a partir do template ───
            // Etapas do catálogo (nf_etapas) com "encerra automaticamente" viram
            // etapas de arquivamento: sem aprovador, concluídas sozinhas.
            require_once SRC . '/Models/Etapa.php';
            $etapasTemplate = $dados['etapas_template'] ?? [];
            $autoFlags      = [];
            $obsFlags       = [];

            // Etapa de decisão: o template referencia o destino pelo `uid` da
            // linha (estável sob drag-and-drop); o processo guarda o número da
            // etapa. Traduzir uid → posição exige o mapa pronto ANTES do laço.
            $posPorUid = [];
            foreach ($etapasTemplate as $i => $etapa) {
                if (!empty($etapa['uid'])) {
                    $posPorUid[(string) $etapa['uid']] = $i + 1;
                }
            }

            foreach ($etapasTemplate as $i => $etapa) {
                $etapaId    = self::uuid();
                $numEtapa   = $i + 1;
                $catalogo   = Etapa::resolver($etapa);
                $auto       = $catalogo !== null && (int) $catalogo['encerra_automaticamente'] === 1;
                // Observação: mantém o aprovador (é ele que dá a visibilidade em
                // isAprovadorDeAlgumaEtapa) e nasce 'pulado', para nunca virar
                // tarefa nem prender o fluxo.
                $obs        = $catalogo !== null && (int) ($catalogo['somente_observacao'] ?? 0) === 1;
                $autoFlags[$numEtapa] = $auto;
                $obsFlags[$numEtapa]  = $obs;

                // Decisão: congela campo lido e destinos já em número de etapa.
                // Destino que não existe mais no template é descartado aqui —
                // a validação da publicação é quem barra isso antes de chegar
                // em produção; aqui só não gravamos referência quebrada.
                $decisaoCampo  = null;
                $decisaoSaltos = null;
                $abreTemplate  = null;

                $ehDecisao = $catalogo !== null && !empty($catalogo['decisao_lista_id']);
                $ehSalto   = $catalogo !== null && !empty($catalogo['salto_incondicional']);

                if ($ehDecisao || $ehSalto) {
                    // O "Ir para" não lê campo nenhum — o destino é fixo, sob o
                    // índice 0. Só a decisão tem campo para avaliar.
                    $decisaoCampo = ($ehDecisao && ($etapa['decisao_campo'] ?? '') !== '')
                        ? (string) $etapa['decisao_campo']
                        : null;
                    $mapa = [];
                    foreach (($etapa['decisao_saltos'] ?? []) as $indice => $uid) {
                        if (isset($posPorUid[(string) $uid])) {
                            $mapa[(string) (int) $indice] = $posPorUid[(string) $uid];
                        }
                    }
                    $decisaoSaltos = $mapa !== [] ? json_encode($mapa) : null;
                }

                if ($catalogo !== null && !empty($catalogo['abre_fluxo'])) {
                    $abreTemplate = ($etapa['abre_template_id'] ?? '') !== ''
                        ? (string) $etapa['abre_template_id']
                        : null;
                }

                // Etapa marcada como "Solicitante" vira o usuário que abriu.
                [$tipoAprov, $idAprov] = self::resolverAprovadorTemplate($etapa, $criadoPor);

                $stmtEtapa  = $pdo->prepare(
                    "INSERT INTO nf_processo_etapas
                        (id, processo_id, numero_etapa, nome_etapa, etapa_catalogo_id,
                         decisao_campo, decisao_saltos, abre_template_id, tipo_aprovador, aprovador_id, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmtEtapa->execute([
                    $etapaId,
                    $processoId,
                    $numEtapa,
                    $etapa['nome']           ?? "Etapa {$numEtapa}",
                    $catalogo['id']          ?? null,
                    $decisaoCampo,
                    $decisaoSaltos,
                    $abreTemplate,
                    $auto ? 'arquivo' : $tipoAprov,
                    $auto ? null : $idAprov,
                    $obs ? 'pulado' : 'pendente',
                ]);
            }

            // ── 3. Inserir histórico inicial ─────────────
            $stmtNomeCriador = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ? LIMIT 1");
            $stmtNomeCriador->execute([$criadoPor]);
            $nomeCriador = $stmtNomeCriador->fetchColumn() ?: '—';
            self::inserirHistorico($pdo, $processoId, 'processo_aberto', 1, $criadoPor, $nomeCriador, 'Processo criado e enviado para aprovação.');

            // ── 3.5 Inserir rateio entre centros de custo ─
            if (!empty($dados['rateios'])) {
                $stmtRat = $pdo->prepare(
                    "INSERT INTO nf_processo_rateios
                        (id, processo_id, setor_id, setor_codigo, setor_nome, percentual, valor)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                foreach ($dados['rateios'] as $rateio) {
                    $stmtRat->execute([
                        self::uuid(),
                        $processoId,
                        $rateio['setor_id'],
                        $rateio['setor_codigo'] ?? null,
                        $rateio['setor_nome'],
                        $rateio['percentual'],
                        $rateio['valor'],
                    ]);
                }
            }

            // ── 3.6 Inserir tipos de despesa (classificação do gasto) ─
            if (!empty($dados['tipos_despesa'])) {
                $stmtTd = $pdo->prepare(
                    "INSERT INTO nf_processo_tipos_despesa
                        (id, processo_id, tipo_id, tipo_nome, percentual, valor)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                foreach ($dados['tipos_despesa'] as $td) {
                    $stmtTd->execute([
                        self::uuid(),
                        $processoId,
                        $td['tipo_id'],
                        $td['tipo_nome'],
                        $td['percentual'],
                        $td['valor'],
                    ]);
                }
            }

            // ── 4. Inserir parcelas (se tem_financeiro) ──
            if (!empty($dados['tem_financeiro']) && !empty($dados['parcelas'])) {
                $totalParcelas = count($dados['parcelas']);
                foreach ($dados['parcelas'] as $numParcela => $parcela) {
                    $parcelaId  = self::uuid();
                    $stmtParc   = $pdo->prepare(
                        "INSERT INTO nf_parcelas
                            (id, processo_id, numero_parcela, total_parcelas, vencimento, valor,
                             forma_pagamento_id, status, is_teste, criado_em)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'previsto', ?, NOW())"
                    );
                    $stmtParc->execute([
                        $parcelaId,
                        $processoId,
                        ((int) $numParcela) + 1,
                        $totalParcelas,
                        $parcela['vencimento'],
                        $parcela['valor'],
                        $parcela['forma_pagamento_id'] ?: null,
                        $isTeste ? 1 : 0,
                    ]);
                }
            }

            // ── 5. Concluir etapas de arquivamento iniciais e notificar
            //       os aprovadores da primeira etapa que exige ação ──────
            $totalEtapas = count($etapasTemplate);
            $etapaAtual  = 1;
            while ($etapaAtual <= $totalEtapas
                   && (($autoFlags[$etapaAtual] ?? false) || ($obsFlags[$etapaAtual] ?? false))) {
                // Observação já nasceu 'pulado' — só avança o ponteiro.
                if ($autoFlags[$etapaAtual] ?? false) {
                    self::concluirEtapaAutomatica(
                        $pdo, $processoId, $etapaAtual, $criadoPor, $nomeCriador,
                        (string) ($etapasTemplate[$etapaAtual - 1]['nome'] ?? ('Etapa ' . $etapaAtual))
                    );
                }
                $etapaAtual++;
            }

            if ($totalEtapas > 0 && $etapaAtual > $totalEtapas) {
                // Nenhuma etapa exigia ação (arquivamento e/ou observação) — o
                // processo já nasce concluído, registrando quem executou o vínculo.
                $pdo->prepare(
                    "UPDATE nf_processos SET etapa_atual = ?, status = 'aprovado', concluido_em = NOW() WHERE id = ?"
                )->execute([$totalEtapas, $processoId]);
                self::inserirHistorico($pdo, $processoId, 'processo_arquivado', $totalEtapas, $criadoPor, $nomeCriador,
                    "Processo concluído automaticamente na criação por {$nomeCriador} — nenhuma etapa do fluxo exige ação.");
            } else {
                if ($etapaAtual > 1) {
                    $pdo->prepare("UPDATE nf_processos SET etapa_atual = ? WHERE id = ?")
                        ->execute([$etapaAtual, $processoId]);
                }
                if (!empty($etapasTemplate)) {
                    [$tipoNotif, $idNotif] = self::resolverAprovadorTemplate(
                        $etapasTemplate[$etapaAtual - 1], $criadoPor
                    );
                    self::notificarAprovadores(
                        $pdo,
                        ['tipo_aprovador' => $tipoNotif, 'aprovador_id' => (string) $idNotif],
                        $processoId,
                        $codigo,
                        $criadoPor
                    );
                }
            }

            if ($transacaoPropria) {
                $pdo->commit();
            }
            return ['id' => $processoId, 'codigo' => $codigo];

        } catch (\Throwable $e) {
            // Só desfaz o que é nosso. Sendo transação de quem chamou, a
            // exceção sobe e o rollBack é lá — desfazer aqui abortaria também
            // o trabalho do chamador pelas costas dele.
            if ($transacaoPropria) {
                $pdo->rollBack();
            }
            throw new \RuntimeException("Falha ao criar processo: " . $e->getMessage(), 0, $e);
        }
    }

    // ─────────────────────────────────────────
    // Ações de workflow
    // ─────────────────────────────────────────

    /**
     * Aprova a etapa atual de um processo.
     * Se for a última etapa, marca o processo como 'aprovado'.
     * Se houver próxima, avança etapa_atual e notifica próximos aprovadores.
     */
    public static function aprovar(
        string $processoId,
        string $etapaId,
        string $usuarioId,
        string $usuarioNome
    ): bool {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            // Buscar processo e etapa atual
            $processo = self::buscarProcessoParaAcao($pdo, $processoId);
            if (!$processo) {
                throw new \RuntimeException("Processo não encontrado ou já encerrado.");
            }

            $etapaAtual = $processo['etapa_atual'];

            // Gate: anexos obrigatórios DESTA etapa precisam existir para aprovar
            $pendentes = self::anexosPendentesDaEtapa($processoId, $etapaAtual);
            if (!empty($pendentes)) {
                throw new \RuntimeException(
                    'Esta etapa exige o(s) anexo(s): "' . implode('", "', $pendentes)
                    . '". Anexe o(s) documento(s) na tela do processo antes de aprovar.'
                );
            }

            // Gate: campos que o template exige NESTA etapa (etapa > 1).
            // O dado pode não existir na abertura — o solicitante abre sem ele e
            // quem está na etapa preenche. A etapa não avança em branco porque,
            // em geral, é uma etapa seguinte que vai usar a informação.
            $camposFaltando = self::camposPendentesDaEtapa($processoId, (int) $etapaAtual);
            if (!empty($camposFaltando)) {
                throw new \RuntimeException(
                    'Esta etapa exige o preenchimento de: "' . implode('", "', $camposFaltando)
                    . '". Use "Preencher dados desta etapa" na tela do processo antes de aprovar.'
                );
            }

            // Gate de abertura: na etapa 1, os dados obrigatórios do template
            // (parcelas/forma de pagamento, tipo de despesa, campos e anexos)
            // precisam estar completos antes de o processo seguir no fluxo.
            if ((int) $etapaAtual === 1) {
                $faltando = self::dadosAberturaPendentes($processoId);
                if (!empty($faltando)) {
                    throw new \RuntimeException(
                        'Complete os dados do processo antes de avançar: '
                        . implode(' · ', $faltando)
                        . '. Use "Completar dados" na tela do processo.'
                    );
                }
            }

            // Marcar etapa como aprovada
            $stmt = $pdo->prepare(
                "UPDATE nf_processo_etapas
                 SET status = 'aprovado', atuado_por = ?, atuado_em = NOW()
                 WHERE id = ? AND processo_id = ? AND numero_etapa = ?"
            );
            $stmt->execute([$usuarioId, $etapaId, $processoId, $etapaAtual]);

            // Histórico — aprovação
            self::inserirHistorico($pdo, $processoId, 'etapa_aprovada', $etapaAtual, $usuarioId, $usuarioNome,
                "Etapa {$etapaAtual} aprovada por {$usuarioNome}."
            );

            // Verificar se há próxima etapa
            $stmtProxima = $pdo->prepare(
                "SELECT * FROM nf_processo_etapas
                 WHERE processo_id = ? AND numero_etapa = ?
                 LIMIT 1"
            );
            $stmtProxima->execute([$processoId, $etapaAtual + 1]);
            $proximaEtapa = $stmtProxima->fetch();

            // Etapas que não exigem ação seguem sozinhas (em cadeia): as de
            // arquivamento se concluem aqui; as de observação já estão 'pulado'
            // desde a criação e só precisam ser ultrapassadas; as de DECISÃO
            // leem o campo do formulário e saltam para a etapa daquele índice.
            $numAtual     = $etapaAtual;
            $arquivouAuto = false;
            $saltos       = 0;

            while ($proximaEtapa) {
                $numPula = (int) $proximaEtapa['numero_etapa'];

                // Decisão: resolve o salto e retoma a caminhada no destino.
                // Decisão já pulada (aninhada dentro de um ramo descartado) não
                // se resolve — o fluxo passa por cima dela como qualquer outra
                // etapa do ramo que não foi seguido.
                if (self::etapaEhDecisao($proximaEtapa) && $proximaEtapa['status'] !== 'pulado') {
                    // Um template com decisões apontando para trás pode fechar
                    // um ciclo; sem esta guarda a transação giraria até o
                    // timeout do PHP, segurando as linhas travadas.
                    if (++$saltos > self::MAX_SALTOS_DECISAO) {
                        throw new \RuntimeException(
                            'As etapas de decisão deste fluxo entraram em laço (mais de '
                            . self::MAX_SALTOS_DECISAO . ' saltos sem parar em nenhuma etapa). '
                            . 'Revise os destinos das decisões no template.'
                        );
                    }

                    $destino = self::resolverDecisao(
                        $pdo, $processoId, $proximaEtapa, $usuarioId, $usuarioNome
                    );
                    $numAtual = $numPula;
                    $stmtProxima->execute([$processoId, $destino]);
                    $proximaEtapa = $stmtProxima->fetch();
                    continue;
                }

                // "Abrir Fluxo": abre o processo filho e encerra este. Como o
                // pai se encerra aqui, o laço para — não existe "depois" para
                // caminhar. Etapa já pulada não dispara nada, pelo mesmo motivo
                // da decisão: ela pertence ao ramo que não foi seguido.
                if (!empty($proximaEtapa['abre_template_id']) && $proximaEtapa['status'] !== 'pulado') {
                    $pdo->prepare("UPDATE nf_processos SET etapa_atual = ? WHERE id = ?")
                        ->execute([$numPula, $processoId]);
                    self::abrirFluxoFilho($pdo, $processoId, $proximaEtapa, $usuarioId, $usuarioNome);
                    $pdo->prepare(
                        "UPDATE nf_processo_etapas
                            SET status = 'aprovado', atuado_por = ?, atuado_em = NOW()
                          WHERE processo_id = ? AND numero_etapa = ?"
                    )->execute([$usuarioId, $processoId, $numPula]);
                    $arquivouAuto = true;
                    $numAtual     = $numPula;
                    $proximaEtapa = null;
                    break;
                }

                // Etapa que exige ação: é aqui que o processo para.
                if (!self::etapaEhAutomatica($proximaEtapa) && !self::etapaEhObservacao($proximaEtapa)) {
                    break;
                }

                // Etapa de arquivamento DENTRO de um ramo descartado não pode
                // arquivar o processo: ela foi pulada pela decisão, não
                // atingida pelo fluxo. Sem esta condição de status, escolher um
                // ramo faria o processo se arquivar sozinho ao passar por cima
                // do "Fim" do ramo que não foi seguido.
                if (self::etapaEhAutomatica($proximaEtapa) && $proximaEtapa['status'] !== 'pulado') {
                    $pdo->prepare("UPDATE nf_processos SET etapa_atual = ? WHERE id = ?")
                        ->execute([$numPula, $processoId]);
                    self::concluirEtapaAutomatica(
                        $pdo, $processoId, $numPula, $usuarioId, $usuarioNome,
                        (string) ($proximaEtapa['nome_etapa'] ?? ('Etapa ' . $numPula))
                    );
                    $arquivouAuto = true;
                    $numAtual     = $numPula;

                    // A etapa de arquivamento ENCERRA o processo — nada depois
                    // dela roda. Enquanto Arquivo era sempre a última etapa isso
                    // acontecia sozinho (acabavam as etapas); com decisão, um
                    // ramo termina em Arquivo no MEIO da lista, e continuar a
                    // caminhada faria o processo seguir pelas etapas do ramo
                    // seguinte depois de já ter sido arquivado.
                    $proximaEtapa = null;
                    break;
                }
                $numAtual = $numPula;
                $stmtProxima->execute([$processoId, $numPula + 1]);
                $proximaEtapa = $stmtProxima->fetch();
            }

            // A próxima etapa é de assinatura eletrônica? Então ela é o ponto
            // de parada: registramos o pedido AQUI (dentro da transação, só
            // gravando) e o envio HTTP sai depois do commit. Chamada de rede
            // dentro da transação seguraria o banco pelo tempo do provedor e,
            // num rollback, deixaria o documento enviado e cobrado sem nada
            // gravado do nosso lado.
            $assinaturaId = null;
            require_once SRC . '/Models/Etapa.php';
            if ($proximaEtapa && Etapa::ehAssinatura($proximaEtapa)) {
                require_once __DIR__ . '/Assinatura.php';
                // Pode lançar (falta anexo, e-mail vazio) — e deve: aborta a
                // aprovação com a mensagem na tela, em vez de deixar o processo
                // avançar para uma etapa que nunca conseguiria enviar.
                $assinaturaId = Assinatura::registrarPendente(
                    $pdo, $processoId, (int) $proximaEtapa['numero_etapa'], $usuarioId
                );
            }

            if ($proximaEtapa) {
                // Resetar próxima etapa se estava devolvida
                if ($proximaEtapa['status'] === 'devolvido') {
                    // Só o status volta — quem devolveu e o motivo continuam
                    // registrados na etapa, para frente e para trás.
                    $stmtReset = $pdo->prepare(
                        "UPDATE nf_processo_etapas SET status = 'pendente'
                         WHERE id = ?"
                    );
                    $stmtReset->execute([$proximaEtapa['id']]);
                }

                // Avançar para próxima etapa e garantir status aberto
                $stmt = $pdo->prepare(
                    "UPDATE nf_processos SET etapa_atual = ?, status = 'aberto' WHERE id = ?"
                );
                $stmt->execute([(int) $proximaEtapa['numero_etapa'], $processoId]);

                $nomeProxEtapa = $proximaEtapa['nome_etapa'] ?? ('Etapa ' . $proximaEtapa['numero_etapa']);

                if ($assinaturaId !== null) {
                    // Etapa de assinatura: fica em estado próprio, e NÃO
                    // notifica aprovador. Quem a conclui é o retorno do
                    // provedor — mandar "você tem uma tarefa" para o
                    // responsável faria ele procurar um botão que não existe.
                    // Ele é avisado quando precisa agir (recusa, prazo vencido).
                    $pdo->prepare(
                        "UPDATE nf_processo_etapas SET status = 'aguardando_assinatura' WHERE id = ?"
                    )->execute([$proximaEtapa['id']]);

                    self::inserirHistorico($pdo, $processoId, 'etapa_avancada', (int) $proximaEtapa['numero_etapa'], $usuarioId, $usuarioNome,
                        "Encaminhado para {$nomeProxEtapa} — aguardando os signatários."
                    );
                } else {
                    // Notificar aprovadores da próxima etapa
                    self::notificarAprovadores($pdo, [
                        'tipo_aprovador' => $proximaEtapa['tipo_aprovador'],
                        'aprovador_id'   => $proximaEtapa['aprovador_id'],
                    ], $processoId, $processo['codigo'], $usuarioId);

                    self::inserirHistorico($pdo, $processoId, 'etapa_avancada', (int) $proximaEtapa['numero_etapa'], $usuarioId, $usuarioNome,
                        "Encaminhado para {$nomeProxEtapa}."
                    );
                }

            } else {
                // Última etapa aprovada (ou arquivamento em cadeia) — concluir processo
                $stmt = $pdo->prepare(
                    "UPDATE nf_processos
                     SET etapa_atual = ?, status = 'aprovado', concluido_em = NOW()
                     WHERE id = ?"
                );
                $stmt->execute([$numAtual, $processoId]);

                // Notificar solicitante
                self::criarNotificacao($pdo, $processo['criado_por'],
                    "Processo {$processo['codigo']} aprovado",
                    $arquivouAuto
                        ? "Seu processo foi concluído e arquivado automaticamente."
                        : "Seu processo foi aprovado por todos os aprovadores.",
                    $processoId,
                    'nf_processo_aprovado'
                );

                self::inserirHistorico($pdo, $processoId,
                    $arquivouAuto ? 'processo_arquivado' : 'processo_aprovado',
                    $numAtual, $usuarioId, $usuarioNome,
                    $arquivouAuto
                        ? 'Processo concluído e arquivado automaticamente (etapa de arquivamento).'
                        : 'Processo concluído — todas as etapas aprovadas.'
                );

                // Solicitação de acesso fora do horário (migration 043): grava a
                // liberação sozinha. Mesmo gancho de Task::aprovarEtapa —
                // aprovar pela tela do processo e aprovar pela fila de tarefas
                // são DOIS caminhos diferentes, e os dois têm de concluir igual.
                require_once __DIR__ . '/AccessSchedule.php';
                if (AccessSchedule::registrarDeProcesso($processoId) !== null) {
                    self::inserirHistorico($pdo, $processoId, 'acesso_liberado',
                        $numAtual, $usuarioId, $usuarioNome,
                        'Acesso fora do horário liberado automaticamente para o solicitante, conforme aprovado neste processo.'
                    );
                }
            }

            $pdo->commit();

            // ── Pós-commit: o que fala com o mundo lá fora ──────────────
            // Só aqui, com a transação fechada. Se o envio falhar, a linha
            // continua em 'a_enviar' e o worker (cli/assinafy_sync.php) tenta
            // de novo — a aprovação em si já está gravada e não se perde.
            if ($assinaturaId !== null) {
                try {
                    Assinatura::dispararPendentes(1, $assinaturaId);
                } catch (\Throwable $e) {
                    error_log('[assinatura] disparo pós-commit falhou: ' . $e->getMessage());
                }
            }

            return true;

        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new \RuntimeException("Falha ao aprovar: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Pessoas que já agiram no fluxo e podem receber a devolução.
     *
     * A devolução escolhe uma PESSOA, não uma etapa — quem devolve pensa em
     * "isso é com o Fulano", não em "isso é a etapa 3". O destino é traduzido
     * para o número da etapa aqui.
     *
     * Quando o aprovador da etapa é um grupo, quem entra na lista é a pessoa
     * que efetivamente agiu (`atuado_por`), nunca o grupo.
     *
     * @return array<int,array{usuario_id:string,nome:string,numero_etapa:int,origem:string}>
     */
    public static function pessoasParaDevolucao(string $processoId): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT p.etapa_atual, p.criado_por, u.nome AS criado_por_nome
               FROM nf_processos p
               LEFT JOIN usuarios u ON u.id = p.criado_por
              WHERE p.id = ? LIMIT 1"
        );
        $stmt->execute([$processoId]);
        $proc = $stmt->fetch();
        if (!$proc) {
            return [];
        }
        $etapaAtual   = (int) $proc['etapa_atual'];
        $solicitanteId = (string) ($proc['criado_por'] ?? '');

        // Quem já atuou em etapa anterior. `atuado_por` sobrevive à devolução —
        // o reset zera só o status —, então quem aprovou antes continua na lista
        // mesmo depois de o processo ter voltado por cima dele.
        $stmt = $pdo->prepare(
            "SELECT pe.atuado_por AS usuario_id, u.nome, MAX(pe.numero_etapa) AS numero_etapa
               FROM nf_processo_etapas pe
               JOIN usuarios u ON u.id = pe.atuado_por
              WHERE pe.processo_id = ? AND pe.numero_etapa < ? AND pe.status <> 'pulado'
              GROUP BY pe.atuado_por, u.nome"
        );
        $stmt->execute([$processoId, $etapaAtual]);

        $pessoas = [];
        foreach ($stmt->fetchAll() as $r) {
            // Atuou em mais de uma etapa: vale a mais recente — é de lá que a
            // correção retoma o caminho.
            $pessoas[$r['usuario_id']] = [
                'usuario_id'   => (string) $r['usuario_id'],
                'nome'         => (string) $r['nome'],
                'numero_etapa' => (int) $r['numero_etapa'],
                'origem'       => 'aprovador',
            ];
        }

        // O solicitante entra sempre: na etapa 1 ninguém aprovou nada ainda e é
        // ele quem corrige. Destino = a primeira etapa acionável em que ele
        // próprio é o aprovador; sem nenhuma, a primeira acionável do processo
        // (o mesmo alvo que a devolução sem escolha já usava).
        if ($solicitanteId !== '' && !isset($pessoas[$solicitanteId])) {
            $stmt = $pdo->prepare(
                "SELECT numero_etapa FROM nf_processo_etapas
                  WHERE processo_id = ? AND status <> 'pulado'
                    AND tipo_aprovador = 'usuario' AND aprovador_id = ?
                  ORDER BY numero_etapa ASC LIMIT 1"
            );
            $stmt->execute([$processoId, $solicitanteId]);
            $destino = $stmt->fetchColumn();

            if ($destino === false) {
                $stmt = $pdo->prepare(
                    "SELECT numero_etapa FROM nf_processo_etapas
                      WHERE processo_id = ? AND status <> 'pulado'
                      ORDER BY numero_etapa ASC LIMIT 1"
                );
                $stmt->execute([$processoId]);
                $destino = $stmt->fetchColumn() ?: 1;
            }

            $pessoas[$solicitanteId] = [
                'usuario_id'   => $solicitanteId,
                'nome'         => (string) ($proc['criado_por_nome'] ?: '—'),
                'numero_etapa' => (int) $destino,
                'origem'       => 'solicitante',
            ];
        }

        // Mais recente primeiro — o destino mais provável fica no topo.
        $lista = array_values($pessoas);
        usort($lista, fn($a, $b) => $b['numero_etapa'] <=> $a['numero_etapa']);
        return $lista;
    }

    /**
     * Traduz a pessoa escolhida na devolução para o número da etapa dela.
     *
     * A validação é feita contra pessoasParaDevolucao() — não basta o id ser de
     * um usuário existente, ele precisa ser de alguém que realmente agiu neste
     * fluxo. Assim o número da etapa nunca vem do formulário.
     */
    public static function etapaDaPessoa(string $processoId, string $usuarioDestino): int
    {
        foreach (self::pessoasParaDevolucao($processoId) as $p) {
            if ($p['usuario_id'] === $usuarioDestino) {
                return $p['numero_etapa'];
            }
        }
        throw new \RuntimeException('Pessoa de destino inválida para devolução.');
    }

    /**
     * Aponta a etapa de destino para a PESSOA escolhida.
     *
     * Devolver "para o Fulano" tem de chegar no Fulano. Se a etapa é de grupo,
     * cair na fila do grupo inteiro dilui a responsabilidade e qualquer membro
     * pode responder por uma correção que era de outro. A etapa passa a ser
     * dele — mesma mecânica da reatribuição, que já converte grupo → usuário.
     *
     * Não faz nada quando a etapa já é da própria pessoa.
     */
    public static function direcionarEtapaParaPessoa(
        \PDO $pdo,
        string $processoId,
        int $numeroEtapa,
        string $usuarioDestino,
        string $atorId,
        string $atorNome
    ): void {
        $stmt = $pdo->prepare(
            "SELECT id, tipo_aprovador, aprovador_id, nome_etapa
               FROM nf_processo_etapas
              WHERE processo_id = ? AND numero_etapa = ? LIMIT 1"
        );
        $stmt->execute([$processoId, $numeroEtapa]);
        $etapa = $stmt->fetch();
        if (!$etapa) {
            return;
        }
        if ($etapa['tipo_aprovador'] === 'usuario' && $etapa['aprovador_id'] === $usuarioDestino) {
            return;
        }

        $anterior = '—';
        if ($etapa['tipo_aprovador'] === 'grupo') {
            $s = $pdo->prepare("SELECT nome FROM nf_grupos WHERE id = ? LIMIT 1");
            $s->execute([$etapa['aprovador_id']]);
            $anterior = $s->fetchColumn() ?: '—';
        } else {
            $s = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ? LIMIT 1");
            $s->execute([$etapa['aprovador_id']]);
            $anterior = $s->fetchColumn() ?: '—';
        }

        $s = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ? LIMIT 1");
        $s->execute([$usuarioDestino]);
        $novoNome = $s->fetchColumn() ?: '—';

        $pdo->prepare(
            "UPDATE nf_processo_etapas SET tipo_aprovador = 'usuario', aprovador_id = ? WHERE id = ?"
        )->execute([$usuarioDestino, $etapa['id']]);

        self::inserirHistorico(
            $pdo, $processoId, 'responsavel_reatribuido', $numeroEtapa, $atorId, $atorNome,
            "Devolução direcionada na etapa {$etapa['nome_etapa']}: {$anterior} → {$novoNome}"
        );
    }

    /**
     * Devolve o processo para correção.
     * Decrementa etapa_atual (mínimo 1) e atualiza status para 'devolvido'.
     */
    public static function devolver(
        string $processoId,
        string $etapaId,
        string $usuarioId,
        string $usuarioNome,
        string $motivo,
        ?int $etapaDestino = null,
        ?string $usuarioDestino = null
    ): bool {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $processo = self::buscarProcessoParaAcao($pdo, $processoId);
            if (!$processo) {
                throw new \RuntimeException("Processo não encontrado ou já encerrado.");
            }

            $etapaAtual = $processo['etapa_atual'];

            // Marcar etapa como devolvida
            $stmt = $pdo->prepare(
                "UPDATE nf_processo_etapas
                 SET status = 'devolvido', atuado_por = ?, atuado_em = NOW(), motivo_devolucao = ?
                 WHERE id = ? AND processo_id = ? AND numero_etapa = ?"
            );
            $stmt->execute([$usuarioId, $motivo, $etapaId, $processoId, $etapaAtual]);

            // Destino: qualquer etapa anterior escolhida pelo aprovador
            // (padrão: a anterior que exige ação — etapa de observação não
            // recebe devolução, quem só observa não teria como devolver adiante)
            $novaEtapa = self::etapaAnteriorAcionavel($pdo, $processoId, $etapaAtual);

            // Destino por PESSOA tem precedência: o número da etapa é derivado
            // dela, nunca aceito direto do formulário.
            if ($usuarioDestino !== null && $usuarioDestino !== '') {
                $etapaDestino = self::etapaDaPessoa($processoId, $usuarioDestino);
            }

            if ($etapaDestino !== null) {
                // Devolver para a própria etapa atual só vale quando não existe
                // nenhuma anterior acionável — é o caso do processo na etapa 1,
                // em que a devolução volta para o solicitante corrigir.
                $limite = $novaEtapa === $etapaAtual ? $etapaAtual : $etapaAtual - 1;
                if ($etapaDestino < 1 || $etapaDestino > $limite) {
                    throw new \RuntimeException('Etapa de destino inválida para devolução.');
                }
                $stmtDest = $pdo->prepare(
                    "SELECT status FROM nf_processo_etapas
                      WHERE processo_id = ? AND numero_etapa = ? LIMIT 1"
                );
                $stmtDest->execute([$processoId, $etapaDestino]);
                if ($stmtDest->fetchColumn() === 'pulado') {
                    throw new \RuntimeException('Etapa de observação não recebe devolução — escolha uma etapa de aprovação.');
                }
                $novaEtapa = $etapaDestino;
            }

            $stmt = $pdo->prepare(
                "UPDATE nf_processos SET etapa_atual = ?, status = 'devolvido' WHERE id = ?"
            );
            $stmt->execute([$novaEtapa, $processoId]);

            // Resetar destino E todas as etapas intermediárias como pendentes —
            // um salto de volta exige reaprovação de quem estava no meio do
            // caminho. As de observação ('pulado') ficam como estão: reabri-las
            // transformaria um observador em aprovador e travaria o fluxo.
            // `atuado_por`/`atuado_em` NÃO são zerados: uma vez que alguém agiu,
            // o registro fica. Zerá-los apagava quem já tinha aprovado (some da
            // lista de destinos da próxima devolução e do acesso ao processo,
            // que é concedido por participação).
            $stmtReset = $pdo->prepare(
                "UPDATE nf_processo_etapas
                 SET status = 'pendente'
                 WHERE processo_id = ? AND numero_etapa >= ? AND numero_etapa < ?
                   AND status <> 'pulado'"
            );
            $stmtReset->execute([$processoId, $novaEtapa, $etapaAtual]);

            // Destino é a PRÓPRIA etapa atual (só acontece quando não existe
            // etapa anterior acionável — ex.: devolução na etapa 1 pra si
            // mesmo). A faixa do reset acima (`>= novaEtapa AND < etapaAtual`)
            // fica vazia nesse caso — sem isto a etapa ficava presa em
            // 'devolvido' pra sempre, nunca aparecendo em Minhas Tarefas
            // (que só lista pe.status='pendente'). Achado real, PROC-2026-13030,
            // 18/09/2026.
            if ((int) $novaEtapa === (int) $etapaAtual) {
                $stmtReabrir = $pdo->prepare(
                    "UPDATE nf_processo_etapas SET status = 'pendente' WHERE processo_id = ? AND numero_etapa = ?"
                );
                $stmtReabrir->execute([$processoId, $novaEtapa]);
            }

            // A etapa de destino passa a ser da pessoa escolhida (grupo → usuário)
            if ($usuarioDestino !== null && $usuarioDestino !== '') {
                self::direcionarEtapaParaPessoa($pdo, $processoId, $novaEtapa, $usuarioDestino, $usuarioId, $usuarioNome);
            }

            // Buscar dados da etapa de origem (que está devolvendo) e da etapa destino
            $stmtOrigem = $pdo->prepare(
                "SELECT nome_etapa FROM nf_processo_etapas
                 WHERE processo_id = ? AND numero_etapa = ? LIMIT 1"
            );
            $stmtOrigem->execute([$processoId, $etapaAtual]);
            $nomeEtapaOrigem = $stmtOrigem->fetchColumn() ?: ('Etapa ' . $etapaAtual);

            $stmtAprov = $pdo->prepare(
                "SELECT aprovador_id, tipo_aprovador, nome_etapa FROM nf_processo_etapas
                 WHERE processo_id = ? AND numero_etapa = ? LIMIT 1"
            );
            $stmtAprov->execute([$processoId, $novaEtapa]);
            $etapaAnterior = $stmtAprov->fetch();

            // Histórico — devolução (etapa de origem)
            self::inserirHistorico($pdo, $processoId, 'processo_devolvido', $etapaAtual, $usuarioId, $usuarioNome,
                "Processo devolvido por {$usuarioNome} na etapa {$nomeEtapaOrigem}. Motivo: {$motivo}"
            );

            // Histórico — retorno para etapa anterior (com referência à origem e motivo herdado)
            if ($etapaAnterior) {
                $nomeEtapaAnterior = $etapaAnterior['nome_etapa'] ?? ('Etapa ' . $novaEtapa);
                self::inserirHistorico($pdo, $processoId, 'etapa_reaberta', $novaEtapa, $usuarioId, $usuarioNome,
                    "Retornou para {$nomeEtapaAnterior} após devolução da etapa {$nomeEtapaOrigem}. Motivo: {$motivo}"
                );
            }

            if ($etapaAnterior && $etapaAnterior['tipo_aprovador'] === 'usuario') {
                self::criarNotificacao($pdo, $etapaAnterior['aprovador_id'],
                    "Processo {$processo['codigo']} devolvido para sua etapa",
                    "O processo foi devolvido para correção. Motivo: {$motivo}",
                    $processoId,
                    'nf_processo_devolvido'
                );
            }

            // Notificar criador também
            self::criarNotificacao($pdo, $processo['criado_por'],
                "Processo {$processo['codigo']} devolvido",
                "Seu processo foi devolvido. Motivo: {$motivo}",
                $processoId,
                'nf_processo_devolvido'
            );

            // Motivo também na aba Observações — é lá que a conversa do processo
            // acontece. Preso só no card da etapa e no histórico, o pedido de
            // correção passava despercebido por quem recebeu.
            $stmtObs = $pdo->prepare(
                "INSERT INTO nf_processo_observacoes (id, processo_id, autor_id, autor_nome, texto, criado_em)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $stmtObs->execute([
                self::uuid(), $processoId, $usuarioId, $usuarioNome,
                "Devolvido na etapa {$nomeEtapaOrigem}. Motivo: {$motivo}",
            ]);

            $pdo->commit();
            return true;

        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new \RuntimeException("Falha ao devolver: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Reenvia um processo devolvido de volta para aprovação.
     * Restaura a etapa devolvida como pendente e volta status para aberto.
     */
    public static function reenviar(
        string $processoId,
        string $usuarioId,
        string $usuarioNome
    ): bool {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "SELECT id, codigo, status, etapa_atual, criado_por
                 FROM nf_processos
                 WHERE id = ? AND deletado_em IS NULL AND status = 'devolvido'
                 LIMIT 1"
            );
            $stmt->execute([$processoId]);
            $processo = $stmt->fetch();

            if (!$processo) {
                throw new \RuntimeException("Processo não encontrado ou não está devolvido.");
            }

            if ($processo['criado_por'] !== $usuarioId) {
                throw new \RuntimeException("Apenas o solicitante pode reenviar o processo.");
            }

            // O reenvio retoma o fluxo de ONDE ELE PAROU — não recomeça do zero.
            // Num fluxo de 5 etapas em que a 4 devolveu para a 3, o processo está
            // na 3; reenviar o manda para a 4, que é quem pediu a correção e
            // precisa conferi-la. Quem já aprovou antes da 3 não aprova de novo.
            $etapaCorrigida = (int) $processo['etapa_atual'];

            // A etapa onde o processo parou foi cumprida pelo solicitante: é a
            // correção em si. Marcá-la como aprovada é o que destrava o avanço.
            $stmtAtual = $pdo->prepare(
                "UPDATE nf_processo_etapas
                    SET status = 'aprovado', atuado_por = ?, atuado_em = NOW(), motivo_devolucao = NULL
                  WHERE processo_id = ? AND numero_etapa = ? AND status <> 'pulado'"
            );
            $stmtAtual->execute([$usuarioId, $processoId, $etapaCorrigida]);

            // Próxima etapa que exige ação — a de observação é ultrapassada.
            // Quando a devolução saltou etapas (ex.: a 4 devolveu direto para a
            // 1), este avanço é de uma etapa só: as intermediárias voltaram a
            // ficar pendentes na devolução e serão percorridas uma a uma até
            // chegar de novo em quem devolveu.
            $stmtProx = $pdo->prepare(
                "SELECT id, numero_etapa, nome_etapa, tipo_aprovador, aprovador_id
                   FROM nf_processo_etapas
                  WHERE processo_id = ? AND numero_etapa > ? AND status <> 'pulado'
                  ORDER BY numero_etapa ASC LIMIT 1"
            );
            $stmtProx->execute([$processoId, $etapaCorrigida]);
            $proxima = $stmtProx->fetch();

            if ($proxima) {
                // Se a próxima é justamente a que devolveu, ela precisa voltar a
                // ser acionável — está com status 'devolvido'.
                $stmtReabre = $pdo->prepare(
                    "UPDATE nf_processo_etapas
                        SET status = 'pendente'
                      WHERE id = ? AND status <> 'pendente'"
                );
                $stmtReabre->execute([$proxima['id']]);

                $stmtProc = $pdo->prepare(
                    "UPDATE nf_processos SET status = 'aberto', etapa_atual = ? WHERE id = ?"
                );
                $stmtProc->execute([(int) $proxima['numero_etapa'], $processoId]);

                $nomeProx  = $proxima['nome_etapa'] ?? ('Etapa ' . $proxima['numero_etapa']);
                $numDestino = (int) $proxima['numero_etapa'];
            } else {
                // Sem etapa adiante: o processo estava na última. Só reabre.
                $stmtProc = $pdo->prepare(
                    "UPDATE nf_processos SET status = 'aberto' WHERE id = ?"
                );
                $stmtProc->execute([$processoId]);
                $nomeProx   = '';
                $numDestino = $etapaCorrigida;
            }

            self::inserirHistorico($pdo, $processoId, 'processo_reenviado',
                $numDestino, $usuarioId, $usuarioNome,
                "Processo corrigido e reenviado por {$usuarioNome}."
                . ($nomeProx !== '' ? " Segue para {$nomeProx}." : '')
            );

            if ($proxima) {
                self::notificarAprovadores($pdo, [
                    'tipo_aprovador' => $proxima['tipo_aprovador'] ?? 'grupo',
                    'aprovador_id'   => $proxima['aprovador_id']   ?? '',
                ], $processoId, (string) $processo['codigo'], $usuarioId);
            }

            self::criarNotificacao($pdo, $processo['criado_por'],
                "Processo {$processo['codigo']} reenviado",
                "O processo foi reenviado para aprovação.",
                $processoId,
                'nf_processo_aprovado'
            );

            $pdo->commit();
            return true;

        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new \RuntimeException("Falha ao reenviar: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Cancela um processo (soft — apenas atualiza status).
     */
    public static function cancelar(
        string $processoId,
        string $usuarioId,
        string $usuarioNome
    ): bool {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $processo = self::buscarProcessoParaAcao($pdo, $processoId);
            if (!$processo) {
                throw new \RuntimeException("Processo não encontrado ou já encerrado.");
            }

            $stmt = $pdo->prepare(
                "UPDATE nf_processos SET status = 'cancelado' WHERE id = ?"
            );
            $stmt->execute([$processoId]);

            self::inserirHistorico($pdo, $processoId, 'processo_cancelado', $processo['etapa_atual'],
                $usuarioId, $usuarioNome, "Processo cancelado por {$usuarioNome}."
            );

            $pdo->commit();
            return true;

        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new \RuntimeException("Falha ao cancelar: " . $e->getMessage(), 0, $e);
        }
    }

    // ─────────────────────────────────────────
    // Salvar anexo
    // ─────────────────────────────────────────

    /**
     * Registra metadados de um anexo no banco após upload físico.
     */
    public static function salvarAnexo(string $processoId, array $meta, string $tipoNome, string $enviadoPor, bool $isTeste = false): string
    {
        $pdo = Database::getInstance();
        $id  = self::uuid();

        $stmt = $pdo->prepare(
            "INSERT INTO nf_processo_anexos
                (id, processo_id, tipo_anexo_nome, nome_original, nome_armazenado,
                 caminho, mime_type, tamanho_bytes, is_teste, enviado_por, enviado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $id,
            $processoId,
            $tipoNome,
            $meta['nome_original'],
            $meta['nome_armazenado'],
            $meta['caminho'],
            $meta['mime_type'],
            $meta['tamanho_bytes'],
            $isTeste ? 1 : 0,
            $enviadoPor,
        ]);

        return $id;
    }

    /**
     * Busca um anexo por ID, verificando que pertence ao processo informado.
     */
    public static function findAnexo(string $anexoId, string $processoId): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT * FROM nf_processo_anexos
             WHERE id = ? AND processo_id = ? AND deletado_em IS NULL
             LIMIT 1"
        );
        $stmt->execute([$anexoId, $processoId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ─────────────────────────────────────────
    // Dados auxiliares para formulários
    // ─────────────────────────────────────────

    /**
     * Retorna filiais ativas.
     */
    public static function getFiliais(): array
    {
        $pdo    = Database::getInstance();
        $where  = ['ativo = 1'];
        $params = [];

        // Lista de lojas de TODA tela (filtro, wizard, edição): quem tem escopo
        // configurado só vê as suas. Sem isto o usuário restrito continuava
        // enxergando as outras lojas no select — e podia mandar um processo
        // para uma delas, que depois sumia da vista dele.
        if (class_exists('FilialScope')) {
            [$cond, $p] = FilialScope::sqlRestringeSessao(
                "REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','')"
            );
            if ($cond !== '') {
                $where[] = $cond;
                $params  = array_merge($params, $p);
            }
        }

        $stmt = $pdo->prepare(
            'SELECT id, nome, cnpj FROM filiais WHERE ' . implode(' AND ', $where) . ' ORDER BY nome ASC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Responsáveis (grupos e usuários) que são aprovadores da etapa ATUAL de
     * algum processo em andamento — opções do filtro "Responsável".
     * Valor codificado como "grupo:<uuid>" | "usuario:<uuid>".
     *
     * $restringirUsuario: aplica o mesmo escopo de listAll() para quem não
     * tem processos.ver_todos (só vê responsáveis dos processos que enxerga).
     */
    public static function getResponsaveis(?string $restringirUsuario = null, bool $is_teste = false): array
    {
        $pdo    = Database::getInstance();
        $where  = ["p.deletado_em IS NULL",
                   "p.status IN ('aberto','devolvido','cancelamento_solicitado')",
                   "ea.tipo_aprovador IN ('usuario','grupo')",
                   "ea.aprovador_id IS NOT NULL"];
        $params = [];

        if (!$is_teste) {
            $where[] = 'p.is_teste = 0';
        }
        if ($restringirUsuario !== null) {
            $where[] = ProcessAccess::sqlParticipa('p', $restringirUsuario);
        }

        $stmt = $pdo->prepare(
            "SELECT DISTINCT ea.tipo_aprovador AS tipo, ea.aprovador_id AS id,
                    CASE ea.tipo_aprovador
                      WHEN 'usuario' THEN (SELECT u.nome FROM usuarios u WHERE u.id = ea.aprovador_id LIMIT 1)
                      WHEN 'grupo'   THEN (SELECT g.nome FROM nf_grupos g WHERE g.id = ea.aprovador_id LIMIT 1)
                    END AS nome
             FROM nf_processo_etapas ea
             JOIN nf_processos p ON p.id = ea.processo_id AND p.etapa_atual = ea.numero_etapa
             WHERE " . implode(' AND ', $where) . "
             HAVING nome IS NOT NULL
             ORDER BY nome"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Fluxos (templates) que já possuem processos — opções do filtro "Fluxo".
     * Só devolve o que o usuário enxerga: aplica o mesmo recorte de sigilo,
     * filial e participação usado em listAll().
     *
     * $restringirUsuario: para quem não tem processos.ver_todos.
     */
    public static function getFluxos(?string $restringirUsuario = null, bool $is_teste = false): array
    {
        $pdo    = Database::getInstance();
        $where  = ['p.deletado_em IS NULL'];
        $params = [];

        self::aplicarEscopoVisibilidade($where, $params, 'p');

        if (!$is_teste) {
            $where[] = 'p.is_teste = 0';
        }
        if ($restringirUsuario !== null) {
            $where[] = ProcessAccess::sqlParticipa('p', $restringirUsuario);
        }

        $stmt = $pdo->prepare(
            "SELECT DISTINCT t.id, t.nome
             FROM nf_processos p
             JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
             JOIN nf_templates t ON t.id = tv.template_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY t.nome ASC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Retorna setores ativos.
     */
    public static function getSetores(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT s.id, s.nome, s.departamento_id, d.valor AS departamento_nome
             FROM setores s
             LEFT JOIN nf_lista_itens d ON d.id = s.departamento_id
             WHERE s.ativo = 1
             ORDER BY d.valor IS NULL, d.valor ASC, s.nome ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Retorna itens de uma lista pelo slug (ex: 'tipos-nota', 'formas-pagamento').
     */
    public static function getListaItens(string $slug): array
    {
        $pdo  = Database::getInstance();
        // exige_valor só é lido na lista tipos-nota (abertura do processo);
        // vem junto porque o custo é zero e evita uma segunda consulta na tela.
        $stmt = $pdo->prepare(
            "SELECT i.id, i.valor AS label, i.exige_valor
             FROM nf_lista_itens i
             JOIN nf_listas l ON l.id = i.lista_id
             WHERE l.slug = ? AND i.ativo = 1
             ORDER BY i.valor ASC"
        );
        $stmt->execute([$slug]);
        return $stmt->fetchAll();
    }

    /**
     * Busca fornecedores por nome ou CNPJ para autocomplete.
     */
    public static function buscarFornecedores(string $q): array
    {
        $pdo  = Database::getInstance();
        $cnpjLimpo = preg_replace('/\D/', '', $q);

        if ($cnpjLimpo === '') {
            return [];
        }

        $like = $cnpjLimpo . '%';
        // Rótulo "RAZÃO SOCIAL - Nome Fantasia"; sem fantasia (ou igual à
        // razão) fica só a razão social, sem o hífen solto.
        $stmt = $pdo->prepare(
            "SELECT id,
                    TRIM(CASE
                      WHEN nome_fantasia IS NULL OR nome_fantasia = '' OR nome_fantasia = razao_social
                        THEN razao_social
                      ELSE CONCAT(razao_social, ' - ', nome_fantasia)
                    END) AS nome,
                    razao_social, nome_fantasia, cnpj
             FROM fornecedores
             WHERE ativo = 1 AND cnpj LIKE ?
             ORDER BY razao_social ASC
             LIMIT 10"
        );
        $stmt->execute([$like]);
        return $stmt->fetchAll();
    }

    // ─────────────────────────────────────────
    // Verificação de permissão de aprovação
    // ─────────────────────────────────────────

    /**
     * Verifica se o usuário pode aprovar/devolver a etapa atual do processo.
     * Retorna a etapa (row) se sim, null se não.
     */
    public static function etapaParaUsuario(string $processoId, string $usuarioId, array $gruposIds = []): ?array
    {
        $pdo  = Database::getInstance();

        // Buscar etapa pendente atual (aberto ou devolvido)
        $stmt = $pdo->prepare(
            "SELECT pe.*
             FROM nf_processo_etapas pe
             JOIN nf_processos p ON p.id = pe.processo_id
             WHERE pe.processo_id = ?
               AND pe.status = 'pendente'
               AND pe.numero_etapa = p.etapa_atual
               AND p.status IN ('aberto', 'devolvido')
             LIMIT 1"
        );
        $stmt->execute([$processoId]);
        $etapa = $stmt->fetch();

        if (!$etapa) {
            return null;
        }

        // Verificar se o usuário é aprovador (direto ou por grupo)
        if ($etapa['tipo_aprovador'] === 'usuario' && $etapa['aprovador_id'] === $usuarioId) {
            return $etapa;
        }

        if ($etapa['tipo_aprovador'] === 'grupo' && in_array($etapa['aprovador_id'], $gruposIds, true)) {
            return $etapa;
        }

        return null;
    }

    /**
     * Retorna os grupos aos quais o usuário pertence.
     */
    public static function getGruposDoUsuario(string $usuarioId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT grupo_id FROM nf_grupo_membros WHERE usuario_id = ?"
        );
        $stmt->execute([$usuarioId]);
        return array_column($stmt->fetchAll(), 'grupo_id');
    }

    /**
     * Verifica se o usuário é aprovador de qualquer etapa do processo
     * (pendente ou já atuada) — usado para controle de acesso a downloads.
     */
    public static function isAprovadorDeAlgumaEtapa(string $processoId, string $usuarioId): bool
    {
        $pdo    = Database::getInstance();
        $grupos = self::getGruposDoUsuario($usuarioId);

        // Quem ATUOU numa etapa, ou registrou ação no histórico, participa —
        // mesmo que a etapa tenha sido reatribuída depois ou que ele tenha
        // saído do grupo aprovador. Sem isto a listagem mostraria o processo
        // (ver ProcessAccess::sqlParticipa) e a abertura devolveria 403.
        $stmt = $pdo->prepare(
            "SELECT 1 FROM nf_processo_etapas
              WHERE processo_id = ? AND atuado_por = ? LIMIT 1"
        );
        $stmt->execute([$processoId, $usuarioId]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        $stmt = $pdo->prepare(
            "SELECT 1 FROM nf_processo_historico
              WHERE processo_id = ? AND ator_id = ? LIMIT 1"
        );
        $stmt->execute([$processoId, $usuarioId]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        // Verificar aprovação direta por usuário
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_processo_etapas
             WHERE processo_id = ? AND tipo_aprovador = 'usuario' AND aprovador_id = ?"
        );
        $stmt->execute([$processoId, $usuarioId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return true;
        }

        // Verificar aprovação via grupo
        if (!empty($grupos)) {
            $placeholders = implode(',', array_fill(0, count($grupos), '?'));
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM nf_processo_etapas
                 WHERE processo_id = ? AND tipo_aprovador = 'grupo' AND aprovador_id IN ($placeholders)"
            );
            $stmt->execute(array_merge([$processoId], $grupos));
            if ((int) $stmt->fetchColumn() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Participação "de fato" no processo — criador OU aprovador (direto ou
     * por grupo) de qualquer etapa, passada ou pendente. Não confundir com
     * a liberação granular pontual (nf_template_acessos, por template) nem
     * com a permissão global processos.ver_todos — ver ProcessAccess::pode().
     */
    public static function usuarioParticipaDoProcesso(string $processoId, string $usuarioId): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT criado_por FROM nf_processos WHERE id = ? LIMIT 1");
        $stmt->execute([$processoId]);
        $criadoPor = $stmt->fetchColumn();

        if ($criadoPor !== false && $criadoPor === $usuarioId) {
            return true;
        }

        return self::isAprovadorDeAlgumaEtapa($processoId, $usuarioId);
    }

    /**
     * Liberação granular pontual ativa (nf_template_acessos, revogado_em IS NULL)
     * do usuário para o TEMPLATE deste processo — cobre todos os processos
     * daquele fluxo, não só a instância específica.
     */
    public static function temAcessoGranular(string $processoId, string $usuarioId): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM nf_processos pr
             JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             JOIN nf_template_acessos ta ON ta.template_id = tv.template_id
             WHERE pr.id = ? AND ta.usuario_id = ? AND ta.revogado_em IS NULL
             LIMIT 1"
        );
        $stmt->execute([$processoId, $usuarioId]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        // Liberação concedida ao GRUPO do usuário. A listagem sempre aceitou as
        // duas formas; aqui só a individual valia, então um processo liberado
        // por grupo aparecia na lista e devolvia 403 ao ser aberto.
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM nf_processos pr
             JOIN nf_template_versoes tv ON tv.id = pr.template_versao_id
             JOIN nf_template_acesso_grupos tg ON tg.template_id = tv.template_id
             JOIN nf_grupo_membros gm ON gm.grupo_id = tg.grupo_id
             WHERE pr.id = ? AND gm.usuario_id = ? AND tg.revogado_em IS NULL
             LIMIT 1"
        );
        $stmt->execute([$processoId, $usuarioId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Concede acesso granular pontual a TODOS os processos de um template —
     * reativa a liberação existente se já tiver havido uma (revogada) para o
     * mesmo par template+usuário, em vez de acumular linhas duplicadas.
     */
    public static function concederAcessoTemplate(string $templateId, string $usuarioId, string $concedidoPor, ?string $motivo = null): void
    {
        $pdo  = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT id FROM nf_template_acessos
             WHERE template_id = ? AND usuario_id = ? AND revogado_em IS NULL
             LIMIT 1"
        );
        $stmt->execute([$templateId, $usuarioId]);
        if ($stmt->fetchColumn()) {
            return; // já tem acesso ativo — nada a fazer
        }

        $stmtReativar = $pdo->prepare(
            "SELECT id FROM nf_template_acessos
             WHERE template_id = ? AND usuario_id = ? AND revogado_em IS NOT NULL
             ORDER BY revogado_em DESC LIMIT 1"
        );
        $stmtReativar->execute([$templateId, $usuarioId]);
        $existenteId = $stmtReativar->fetchColumn();

        if ($existenteId) {
            $upd = $pdo->prepare(
                "UPDATE nf_template_acessos
                 SET concedido_por = ?, concedido_em = NOW(), motivo = ?, revogado_por = NULL, revogado_em = NULL
                 WHERE id = ?"
            );
            $upd->execute([$concedidoPor, $motivo, $existenteId]);
            return;
        }

        $ins = $pdo->prepare(
            "INSERT INTO nf_template_acessos (id, template_id, usuario_id, concedido_por, motivo)
             VALUES (?, ?, ?, ?, ?)"
        );
        $ins->execute([self::uuid_v4(), $templateId, $usuarioId, $concedidoPor, $motivo]);
    }

    /** Revoga (soft) a liberação granular ativa do usuário para o template. */
    public static function revogarAcessoTemplate(string $templateId, string $usuarioId, string $revogadoPor): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            "UPDATE nf_template_acessos
             SET revogado_por = ?, revogado_em = NOW()
             WHERE template_id = ? AND usuario_id = ? AND revogado_em IS NULL"
        );
        $stmt->execute([$revogadoPor, $templateId, $usuarioId]);
    }

    /** Lista as liberações granulares ativas CONCEDIDAS A um usuário, com dados do template. */
    public static function listarAcessosPorUsuario(string $usuarioId): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT ta.template_id, t.nome AS template_nome,
                    ta.concedido_em, ta.motivo, cp.nome AS concedido_por_nome
             FROM nf_template_acessos ta
             JOIN nf_templates t ON t.id = ta.template_id
             LEFT JOIN usuarios cp ON cp.id = ta.concedido_por
             WHERE ta.usuario_id = ? AND ta.revogado_em IS NULL
             ORDER BY ta.concedido_em DESC"
        );
        $stmt->execute([$usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Busca leve de templates por nome, para o seletor de liberação de acesso. */
    public static function buscarTemplatesParaAcesso(string $q): array
    {
        $pdo  = Database::getInstance();
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare(
            "SELECT id, nome FROM nf_templates WHERE nome LIKE ? AND ativo = 1 ORDER BY nome LIMIT 20"
        );
        $stmt->execute([$like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────
    // Helpers internos
    // ─────────────────────────────────────────

    /**
     * Busca processo para ações (aprovar/devolver/cancelar).
     * Retorna null se não existir, já cancelado ou aprovado.
     */
    /**
     * Tipos de anexo obrigatórios de uma etapa que ainda não foram anexados.
     * Regra: tipo com obrigatorio=true e etapa_ordem=N bloqueia a aprovação
     * da etapa N enquanto não houver anexo daquele tipo no processo.
     *
     * @return string[] nomes dos tipos pendentes (vazio = liberado)
     */
    /**
     * Chave de comparação de tipo de anexo — espaço nas pontas e caixa fora.
     *
     * O nome do tipo vive em dois lugares: no template
     * (`nf_template_versoes.tipos_anexo`) e copiado em cada anexo enviado
     * (`nf_processo_anexos.tipo_anexo_nome`). Os dois PRECISAM casar, e casavam
     * por acidente até alguém cadastrar um tipo com espaço sobrando no fim
     * ("Consulta CPF "): o upload grava o nome já aparado, e a comparação em
     * SQL não se importa (utf8mb4_unicode_ci ignora espaço no fim e caixa) —
     * mas índice de array em PHP se importa, e o checklist passou a acusar
     * falta de um anexo que estava lá. Aconteceu no PROC-2026-9727, em
     * 20/08/2026: quatro cópias do mesmo arquivo anexadas, e o aviso na tela
     * sem sair.
     *
     * Normalizar aqui, num lugar só, mantém checklist e gate contando a mesma
     * história — que é o que a doc de checklistObrigatorios() promete.
     */
    private static function chaveTipoAnexo(string $nome): string
    {
        return mb_strtolower(trim($nome), 'UTF-8');
    }

    public static function anexosPendentesDaEtapa(string $processoId, int $numeroEtapa): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT tv.tipos_anexo
             FROM nf_processos p
             JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
             WHERE p.id = ? LIMIT 1"
        );
        $stmt->execute([$processoId]);
        $tipos = json_decode((string) $stmt->fetchColumn(), true) ?: [];

        $pendentes = [];
        foreach ($tipos as $tipo) {
            if (empty($tipo['obrigatorio'])) continue;
            if ((int) ($tipo['etapa_ordem'] ?? 0) !== $numeroEtapa) continue;

            // TRIM dos dois lados: o = do MySQL já ignora espaço no FIM, mas não
            // no começo. Aparar aqui deixa este gate exatamente igual ao
            // checklist (chaveTipoAnexo), que é a garantia de os dois nunca
            // divergirem. O filtro por processo_id continua indo pelo índice.
            $chk = $pdo->prepare(
                "SELECT COUNT(*) FROM nf_processo_anexos
                 WHERE processo_id = ? AND TRIM(tipo_anexo_nome) = TRIM(?) AND deletado_em IS NULL"
            );
            $chk->execute([$processoId, $tipo['nome'] ?? '']);
            if ((int) $chk->fetchColumn() === 0) {
                $pendentes[] = trim((string) ($tipo['nome'] ?? 'Anexo'));
            }
        }
        return $pendentes;
    }

    /**
     * Checklist de TUDO que o template exige do processo, com o estado atual de
     * cada exigência. Alimenta o aviso da tela do processo (o usuário vê o que
     * falta ANTES de tentar aprovar) e é a fonte única dos gates de aprovação —
     * checklist e gate não podem divergir.
     *
     * Cada item: [
     *   'origem' => 'dados'|'anexo',   // dados = abertura · anexo = documento
     *   'label'  => string,            // rótulo legível
     *   'etapa'  => int,               // etapa que exige (0 = sem etapa definida)
     *   'ok'     => bool,              // já atendido
     * ]
     *
     * @return array<int, array{origem:string,label:string,etapa:int,ok:bool}>
     */
    public static function checklistObrigatorios(string $processoId): array
    {
        $proc = self::findById($processoId);
        if (!$proc) return [];

        $cfg = self::versaoConfig((string) $proc['template_versao_id']);
        if ($cfg === null) return [];

        $itens = [];

        // Financeiro: ao menos 1 parcela completa (vencimento + valor + forma).
        // Remessa de entrega futura segue o fluxo (Fiscal) sem a obrigação do
        // Financeiro — quem paga é a nota de venda.
        require_once __DIR__ . '/EntregaFutura.php';
        if ($cfg['tem_financeiro'] && !EntregaFutura::ehRemessa($processoId)) {
            $ok = false;
            foreach (self::getParcelas($processoId) as $p) {
                if (!empty($p['vencimento']) && (float) $p['valor'] > 0 && !empty($p['forma_pagamento_id'])) {
                    $ok = true;
                    break;
                }
            }
            $itens[] = ['origem' => 'dados', 'label' => 'Parcelas e forma de pagamento', 'etapa' => 1, 'ok' => $ok];
        }

        // Tipo de despesa (quando obrigatório no template)
        if ($cfg['tem_tipo_despesa'] && $cfg['tipo_despesa_obrigatorio']) {
            $itens[] = [
                'origem' => 'dados',
                'label'  => 'Tipo de despesa',
                'etapa'  => 1,
                'ok'     => !empty(self::getTiposDespesa($processoId)),
            ];
        }

        // Campos adicionais obrigatórios do template — cada um na etapa em que
        // o template os exige. Sem `etapa_ordem` o campo é de abertura (etapa
        // 1), que é como todo template existente se comporta.
        $dados = is_array($proc['dados_formulario'] ?? null) ? $proc['dados_formulario'] : [];
        foreach ($cfg['campos'] as $campo) {
            if (empty($campo['obrigatorio'])) continue;
            $nome = $campo['nome'] ?? '';
            $itens[] = [
                'origem' => 'dados',
                'label'  => $campo['label'] ?? $nome ?: 'Campo obrigatório',
                'etapa'  => max(1, (int) ($campo['etapa_ordem'] ?? 1)),
                'ok'     => trim((string) ($dados[$nome] ?? '')) !== '',
            ];
        }

        // Anexos obrigatórios — cada um na etapa em que o template os exige
        $stmt = Database::getInstance()->prepare(
            "SELECT tipo_anexo_nome, COUNT(*) AS qtd
             FROM nf_processo_anexos
             WHERE processo_id = ? AND deletado_em IS NULL
             GROUP BY tipo_anexo_nome"
        );
        $stmt->execute([$processoId]);
        $qtdPorTipo = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Chave NORMALIZADA — ver chaveTipoAnexo(). Somar em vez de
            // sobrescrever: dois nomes que só diferem por espaço/caixa contam
            // para a mesma exigência, que é como o banco já os trata.
            $k = self::chaveTipoAnexo((string) $row['tipo_anexo_nome']);
            $qtdPorTipo[$k] = ($qtdPorTipo[$k] ?? 0) + (int) $row['qtd'];
        }

        foreach ($cfg['tipos_anexo'] as $tipo) {
            if (empty($tipo['obrigatorio'])) continue;
            $nome = trim((string) ($tipo['nome'] ?? 'Anexo'));
            $itens[] = [
                'origem' => 'anexo',
                'label'  => $nome,
                'etapa'  => (int) ($tipo['etapa_ordem'] ?? 0),
                'ok'     => ($qtdPorTipo[self::chaveTipoAnexo($nome)] ?? 0) > 0,
            ];
        }

        return $itens;
    }

    /**
     * Dados obrigatórios de ABERTURA que ainda faltam no processo, segundo a
     * config do template. Usado como gate na aprovação da etapa 1 e como aviso
     * na tela do processo. Retorna rótulos legíveis (vazio = nada pendente).
     */
    public static function dadosAberturaPendentes(string $processoId): array
    {
        $pendentes = [];
        foreach (self::checklistObrigatorios($processoId) as $item) {
            if ($item['ok'] || (int) $item['etapa'] !== 1) continue;
            $pendentes[] = $item['origem'] === 'anexo'
                ? 'Anexo: ' . $item['label']
                : $item['label'];
        }
        return $pendentes;
    }

    /**
     * Campos do formulário exigidos NESTA etapa que ainda estão em branco.
     *
     * O irmão de anexosPendentesDaEtapa() para os campos: o template pode pedir
     * um dado que não existia na abertura e que só aparece no meio do fluxo — a
     * etapa que o exige não avança sem ele, e a seguinte pode contar com o dado
     * preenchido.
     *
     * A etapa 1 fica de fora porque dadosAberturaPendentes() já a cobre (com
     * parcelas e anexos junto); incluí-la aqui repetiria a mesma cobrança em
     * duas mensagens.
     *
     * @return string[] rótulos dos campos pendentes (vazio = liberado)
     */
    public static function camposPendentesDaEtapa(string $processoId, int $numeroEtapa): array
    {
        if ($numeroEtapa <= 1) {
            return [];
        }

        $pendentes = [];
        foreach (self::checklistObrigatorios($processoId) as $item) {
            if ($item['ok'] || $item['origem'] !== 'dados') continue;
            if ((int) $item['etapa'] !== $numeroEtapa) continue;
            $pendentes[] = $item['label'];
        }
        return $pendentes;
    }

    /**
     * Campos do template que ESTA etapa pede — preenchidos ou não.
     *
     * Alimenta o formulário "Preencher dados desta etapa": quem está na etapa
     * precisa poder escrever nos campos que ela exige, senão não teria como
     * cumprir a exigência sem a permissão global de editar o processo.
     *
     * @return array<int,array> campos do template, na ordem em que aparecem
     */
    public static function camposDaEtapa(string $processoId, int $numeroEtapa): array
    {
        $proc = self::findById($processoId);
        if (!$proc) return [];

        $cfg = self::versaoConfig((string) $proc['template_versao_id']);
        if ($cfg === null) return [];

        $doEtapa = [];
        foreach ($cfg['campos'] as $campo) {
            if ((int) ($campo['etapa_ordem'] ?? 0) !== $numeroEtapa) continue;
            $campo['opcoes'] = self::opcoesCampo($campo);
            $doEtapa[] = $campo;
        }
        return $doEtapa;
    }

    /**
     * Grava os campos que ESTA etapa pede, sem tocar em mais nada do processo.
     *
     * Caminho de menor privilégio para o responsável da etapa: editarDados()
     * exige a permissão global `processos.editar_dados` e reescreve nota,
     * fornecedor e parcelas — quem só precisa preencher um campo do meio do
     * fluxo não deveria receber tudo isso junto. Aqui só entram os campos
     * marcados com `etapa_ordem` = etapa atual; qualquer outra chave do POST é
     * ignorada.
     *
     * @param array<string,string> $valores nome do campo => valor
     * @return list<string> descrição das alterações (vazio = nada mudou)
     */
    public static function salvarCamposDaEtapa(
        string $processoId,
        int    $numeroEtapa,
        array  $valores,
        string $usuarioId,
        string $usuarioNome
    ): array {
        $permitidos = self::camposDaEtapa($processoId, $numeroEtapa);
        if ($permitidos === []) {
            return [];
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "SELECT dados_formulario FROM nf_processos
                  WHERE id = ? AND deletado_em IS NULL LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$processoId]);
            $bruto = $stmt->fetchColumn();
            if ($bruto === false) {
                throw new \RuntimeException('Processo não encontrado.');
            }
            $dados = json_decode((string) $bruto, true) ?: [];

            $mudancas = [];
            foreach ($permitidos as $campo) {
                $nome = (string) ($campo['nome'] ?? '');
                if ($nome === '' || !array_key_exists($nome, $valores)) {
                    continue;
                }
                $novo  = trim(strip_tags((string) $valores[$nome]));
                $velho = trim((string) ($dados[$nome] ?? ''));
                if ($novo === $velho) {
                    continue;
                }

                $rotulo = (string) ($campo['label'] ?? $nome);
                // Campo de lista guarda o id do item — o histórico mostra o
                // rótulo, senão a linha vira um uuid ilegível.
                $mudancas[] = sprintf(
                    '%s: "%s" → "%s"',
                    $rotulo,
                    self::rotuloValorCampo($campo, $velho),
                    self::rotuloValorCampo($campo, $novo)
                );
                $dados[$nome] = $novo;
            }

            if ($mudancas === []) {
                $pdo->rollBack();
                return [];
            }

            $pdo->prepare("UPDATE nf_processos SET dados_formulario = ? WHERE id = ?")
                ->execute([json_encode($dados, JSON_UNESCAPED_UNICODE), $processoId]);

            self::inserirHistorico(
                $pdo, $processoId, 'processo_editado', $numeroEtapa, $usuarioId, $usuarioNome,
                "Dados preenchidos na etapa {$numeroEtapa} por {$usuarioNome}: " . implode(' · ', $mudancas)
            );

            $pdo->commit();
            return $mudancas;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new \RuntimeException('Falha ao salvar os dados da etapa: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Rótulo legível de um valor de campo (resolve id de item de lista). */
    private static function rotuloValorCampo(array $campo, string $valor): string
    {
        if ($valor === '') {
            return '—';
        }
        foreach (($campo['opcoes'] ?? []) as $opc) {
            if ((string) ($opc['id'] ?? '') === $valor) {
                return (string) ($opc['nome'] ?? $valor);
            }
        }
        return $valor;
    }

    /** Parcela nesses status já saiu do previsto — não pode ser reescrita pela edição. */
    private const PARCELA_TRAVADA = ['pago', 'agendado', 'cancelado'];

    /**
     * Edita os dados do processo em QUALQUER etapa (permissão processos.editar_dados).
     *
     * Diferente de atualizar(), que só vale na abertura, aqui o processo já pode
     * ter andado — então:
     *   • os dados da NOTA entram (fornecedor, número, data, valor, filial);
     *   • parcela já paga, agendada ou cancelada é INTOCÁVEL: apagar tudo e
     *     regravar, como faz atualizar(), reescreveria o que o financeiro já
     *     executou. Só as parcelas ainda previstas/vencidas são substituídas;
     *   • toda diferença vai para o histórico campo a campo — a rastreabilidade
     *     é o que justifica poder mexer num processo em andamento.
     *
     * @param array $dados fornecedor_nome, fornecedor_doc, numero_nf, valor_nf,
     *                     data_nf, chave_nf, filial_id, filial_doc,
     *                     dados_formulario, parcelas, tem_financeiro
     * @return list<string> descrição das alterações (vazio = nada mudou)
     */
    public static function editarDados(string $processoId, array $dados, string $usuarioId, string $usuarioNome): array
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT id, etapa_atual, status, fornecedor_nome, fornecedor_doc, numero_nf,
                    valor_nf, data_nf, chave_nf, filial_id, filial_doc, dados_formulario
               FROM nf_processos WHERE id = ? AND deletado_em IS NULL LIMIT 1"
        );
        $stmt->execute([$processoId]);
        $antes = $stmt->fetch();
        if (!$antes) {
            throw new \RuntimeException('Processo não encontrado.');
        }
        if (in_array($antes['status'], ['cancelado', 'encerrado_apollo'], true)) {
            throw new \RuntimeException('Processo cancelado ou encerrado não pode ser editado.');
        }

        $filialDoc = $dados['filial_doc'] ?? $antes['filial_doc'];
        $novo = [
            'fornecedor_nome' => trim((string) $dados['fornecedor_nome']),
            'fornecedor_doc'  => preg_replace('/\D/', '', (string) $dados['fornecedor_doc']),
            'numero_nf'       => trim((string) $dados['numero_nf']),
            'valor_nf'        => (float) $dados['valor_nf'],
            'data_nf'         => (string) $dados['data_nf'],
            'chave_nf'        => ($dados['chave_nf'] ?? '') !== '' ? preg_replace('/\D/', '', (string) $dados['chave_nf']) : null,
            'filial_id'       => (string) $dados['filial_id'],
            'filial_doc'      => (string) $filialDoc,
        ];

        $rotulos = [
            'fornecedor_nome' => 'Fornecedor', 'fornecedor_doc' => 'CNPJ/CPF do fornecedor',
            'numero_nf' => 'Número da NF', 'valor_nf' => 'Valor', 'data_nf' => 'Data de emissão',
            'chave_nf' => 'Chave de acesso', 'filial_id' => 'Filial',
        ];

        $mudancas = [];
        foreach ($rotulos as $campo => $rotulo) {
            $de   = $antes[$campo];
            $para = $novo[$campo];
            if ($campo === 'valor_nf') {
                if (abs((float) $de - (float) $para) < 0.005) continue;
                $de = number_format((float) $de, 2, ',', '.');
                $para = number_format((float) $para, 2, ',', '.');
            } elseif ((string) $de === (string) $para) {
                continue;
            }
            $mudancas[] = "{$rotulo}: " . ($de === null || $de === '' ? '(vazio)' : $de)
                        . ' → ' . ($para === null || $para === '' ? '(vazio)' : $para);
        }

        // Campos do fluxo (dados_formulario) — comparados chave a chave.
        $formAntes = json_decode((string) $antes['dados_formulario'], true) ?: [];
        $formNovo  = $dados['dados_formulario'] ?? [];
        foreach (array_unique(array_merge(array_keys($formAntes), array_keys($formNovo))) as $k) {
            $de   = (string) ($formAntes[$k] ?? '');
            $para = (string) ($formNovo[$k] ?? '');
            if ($de === $para) continue;
            $mudancas[] = "{$k}: " . ($de === '' ? '(vazio)' : $de) . ' → ' . ($para === '' ? '(vazio)' : $para);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "UPDATE nf_processos
                    SET fornecedor_nome = ?, fornecedor_doc = ?, numero_nf = ?, valor_nf = ?,
                        data_nf = ?, chave_nf = ?, filial_id = ?, filial_doc = ?, dados_formulario = ?
                  WHERE id = ?"
            )->execute([
                $novo['fornecedor_nome'], $novo['fornecedor_doc'], $novo['numero_nf'], $novo['valor_nf'],
                $novo['data_nf'], $novo['chave_nf'], $novo['filial_id'], $novo['filial_doc'],
                json_encode($formNovo, JSON_UNESCAPED_UNICODE), $processoId,
            ]);

            if (!empty($dados['tem_financeiro'])) {
                $mudancas = array_merge(
                    $mudancas,
                    self::regravarParcelasEditaveis($pdo, $processoId, $dados['parcelas'] ?? [])
                );
            }

            if ($mudancas) {
                self::inserirHistorico(
                    $pdo, $processoId, 'processo_dados_alterados',
                    (int) $antes['etapa_atual'], $usuarioId, $usuarioNome,
                    "Dados alterados por {$usuarioNome}: " . implode(' · ', $mudancas)
                );
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $mudancas;
    }

    /**
     * Substitui apenas as parcelas ainda editáveis, preservando as que o
     * financeiro já tratou. Renumera o conjunto inteiro no fim para que
     * numero_parcela/total_parcelas continuem coerentes.
     *
     * @return list<string> descrição das mudanças
     */
    private static function regravarParcelasEditaveis(\PDO $pdo, string $processoId, array $novas): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, numero_parcela, vencimento, valor, forma_pagamento_id, status
               FROM nf_parcelas WHERE processo_id = ? AND deletado_em IS NULL
              ORDER BY numero_parcela"
        );
        $stmt->execute([$processoId]);
        $atuais = $stmt->fetchAll();

        $travadas = array_values(array_filter(
            $atuais,
            static fn($p) => in_array($p['status'], self::PARCELA_TRAVADA, true)
        ));
        $editaveisAntes = count($atuais) - count($travadas);

        // Some as editáveis; as travadas seguem intactas.
        $pdo->prepare(
            "DELETE FROM nf_parcelas
              WHERE processo_id = ? AND deletado_em IS NULL
                AND status NOT IN ('pago', 'agendado', 'cancelado')"
        )->execute([$processoId]);

        $inseridas = 0;
        $stmtIns = $pdo->prepare(
            "INSERT INTO nf_parcelas
                (id, processo_id, numero_parcela, total_parcelas, vencimento, valor,
                 forma_pagamento_id, status, is_teste, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'previsto',
                     (SELECT is_teste FROM nf_processos WHERE id = ?), NOW())"
        );
        foreach ($novas as $p) {
            if (empty($p['vencimento']) || (float) $p['valor'] <= 0) continue;
            $stmtIns->execute([
                self::uuid(), $processoId, 0, 0,
                $p['vencimento'], (float) $p['valor'], $p['forma_pagamento_id'] ?: null, $processoId,
            ]);
            $inseridas++;
        }

        // Renumera tudo (travadas primeiro, por vencimento) para não ficar
        // parcela 3 de 2 depois de um desdobramento.
        $pdo->prepare(
            "UPDATE nf_parcelas SET total_parcelas = (
                 SELECT c FROM (SELECT COUNT(*) c FROM nf_parcelas
                                 WHERE processo_id = ? AND deletado_em IS NULL) t)
              WHERE processo_id = ? AND deletado_em IS NULL"
        )->execute([$processoId, $processoId]);

        $ordem = $pdo->prepare(
            "SELECT id FROM nf_parcelas WHERE processo_id = ? AND deletado_em IS NULL
              ORDER BY vencimento, criado_em"
        );
        $ordem->execute([$processoId]);
        $stmtNum = $pdo->prepare("UPDATE nf_parcelas SET numero_parcela = ? WHERE id = ?");
        foreach ($ordem->fetchAll(\PDO::FETCH_COLUMN) as $i => $id) {
            $stmtNum->execute([$i + 1, $id]);
        }

        if ($editaveisAntes === $inseridas && $editaveisAntes === 0) {
            return [];
        }
        $msg = "Parcelas: {$editaveisAntes} em aberto → {$inseridas}";
        if ($travadas) {
            $msg .= ' (' . count($travadas) . ' já paga(s)/agendada(s), mantida(s))';
        }
        return [$msg];
    }

    /**
     * Regrava os dados editáveis do processo enquanto ele está na abertura
     * (etapa 1, status aberto/devolvido). Substitui parcelas, rateios e tipos
     * de despesa por completo — seguro porque nada foi agendado/pago ainda.
     *
     * @param array $dados dados_formulario, parcelas, rateios, tipos_despesa,
     *                     tem_financeiro
     */
    public static function atualizar(string $processoId, array $dados, string $usuarioId, string $usuarioNome): void
    {
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            "SELECT etapa_atual, status, numero_nf FROM nf_processos
             WHERE id = ? AND deletado_em IS NULL LIMIT 1"
        );
        $stmt->execute([$processoId]);
        $proc = $stmt->fetch();
        if (!$proc) {
            throw new \RuntimeException('Processo não encontrado.');
        }
        if ((int) $proc['etapa_atual'] > 1 || !in_array($proc['status'], ['aberto', 'devolvido'], true)) {
            throw new \RuntimeException('O processo só pode ser editado na etapa de abertura (antes de avançar no fluxo).');
        }

        // Número da NF: corrigível ao completar dados (só quando informado).
        $novoNumero = isset($dados['numero_nf']) ? trim((string) $dados['numero_nf']) : '';
        $mudouNumero = $novoNumero !== '' && $novoNumero !== (string) $proc['numero_nf'];

        $pdo->beginTransaction();
        try {
            if ($mudouNumero) {
                $pdo->prepare("UPDATE nf_processos SET numero_nf = ? WHERE id = ?")
                    ->execute([$novoNumero, $processoId]);
                self::inserirHistorico($pdo, $processoId, 'processo_dados_alterados', 1, $usuarioId, $usuarioNome,
                    "Dados alterados por {$usuarioNome}: Número da NF: "
                    . ((string) $proc['numero_nf'] === '' ? '(vazio)' : $proc['numero_nf']) . ' → ' . $novoNumero);
            }

            // dados_formulario (campos do template)
            $pdo->prepare("UPDATE nf_processos SET dados_formulario = ? WHERE id = ?")
                ->execute([json_encode($dados['dados_formulario'] ?: [], JSON_UNESCAPED_UNICODE), $processoId]);

            // Rateios — substitui por completo
            $pdo->prepare("DELETE FROM nf_processo_rateios WHERE processo_id = ?")->execute([$processoId]);
            if (!empty($dados['rateios'])) {
                $stmtR = $pdo->prepare(
                    "INSERT INTO nf_processo_rateios
                        (id, processo_id, setor_id, setor_codigo, setor_nome, percentual, valor)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                foreach ($dados['rateios'] as $r) {
                    $stmtR->execute([
                        self::uuid(), $processoId, $r['setor_id'], $r['setor_codigo'] ?? null,
                        $r['setor_nome'], $r['percentual'], $r['valor'],
                    ]);
                }
            }

            // Tipos de despesa — substitui por completo
            $pdo->prepare("DELETE FROM nf_processo_tipos_despesa WHERE processo_id = ?")->execute([$processoId]);
            if (!empty($dados['tipos_despesa'])) {
                $stmtTd = $pdo->prepare(
                    "INSERT INTO nf_processo_tipos_despesa
                        (id, processo_id, tipo_id, tipo_nome, percentual, valor)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                foreach ($dados['tipos_despesa'] as $td) {
                    $stmtTd->execute([
                        self::uuid(), $processoId, $td['tipo_id'], $td['tipo_nome'],
                        $td['percentual'], $td['valor'],
                    ]);
                }
            }

            // Parcelas — substitui por completo (só se o template é financeiro)
            $pdo->prepare("DELETE FROM nf_parcelas WHERE processo_id = ?")->execute([$processoId]);
            if (!empty($dados['tem_financeiro']) && !empty($dados['parcelas'])) {
                $total = count($dados['parcelas']);
                $stmtP = $pdo->prepare(
                    "INSERT INTO nf_parcelas
                        (id, processo_id, numero_parcela, total_parcelas, vencimento, valor,
                         forma_pagamento_id, status, is_teste, criado_em)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'previsto',
                             (SELECT is_teste FROM nf_processos WHERE id = ?), NOW())"
                );
                foreach (array_values($dados['parcelas']) as $i => $parcela) {
                    $stmtP->execute([
                        self::uuid(), $processoId, $i + 1, $total,
                        $parcela['vencimento'], $parcela['valor'],
                        $parcela['forma_pagamento_id'] ?: null, $processoId,
                    ]);
                }
            }

            self::inserirHistorico($pdo, $processoId, 'processo_editado', 1, $usuarioId, $usuarioNome,
                "Dados do processo completados/editados por {$usuarioNome} na etapa de abertura.");

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new \RuntimeException('Falha ao salvar edição: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function buscarProcessoParaAcao(\PDO $pdo, string $processoId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT id, codigo, status, etapa_atual, criado_por
             FROM nf_processos
             WHERE id = ? AND deletado_em IS NULL AND status NOT IN ('cancelado')
             LIMIT 1"
        );
        $stmt->execute([$processoId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Insere um registro no histórico do processo.
     */
    /**
     * A etapa (linha de nf_processo_etapas) se conclui sozinha?
     * Verdadeiro para etapas gravadas como 'arquivo' e, em tempo real, para
     * etapas cujo item do catálogo passou a encerrar automaticamente.
     */
    public static function etapaEhAutomatica(array $etapaRow): bool
    {
        if (($etapaRow['tipo_aprovador'] ?? '') === 'arquivo') {
            return true;
        }
        if (!empty($etapaRow['etapa_catalogo_id'])) {
            require_once SRC . '/Models/Etapa.php';
            $cat = Etapa::findById((string) $etapaRow['etapa_catalogo_id']);
            return $cat !== null && (int) $cat['encerra_automaticamente'] === 1;
        }
        return false;
    }

    /**
     * Etapa de DECISÃO: não tem aprovador — quem a resolve é o sistema, lendo
     * um campo do formulário do processo. Reconhecida pelo mapa de saltos
     * congelado na criação; o catálogo é consultado só para as etapas antigas,
     * abertas antes da migration 045.
     */
    public static function etapaEhDecisao(array $etapaRow): bool
    {
        if (!empty($etapaRow['decisao_saltos'])) {
            return true;
        }
        if (($etapaRow['tipo_aprovador'] ?? '') === 'decisao') {
            return true;
        }
        return false;
    }

    /**
     * Resolve uma etapa de decisão e devolve o número da etapa de destino.
     *
     * O caminho: campo do formulário → id do item da lista → índice do item →
     * destino gravado para aquele índice. Tudo que fica ENTRE a decisão e o
     * destino é marcado 'pulado' (para frente) ou reaberto (para trás).
     *
     * Aborta com exceção — e não com um avanço torto — quando o campo está
     * vazio, o item não tem índice ou o índice não tem destino: a aprovação
     * falha com o motivo na tela e o processo fica onde está, no mesmo espírito
     * de Assinatura::registrarPendente().
     */
    private static function resolverDecisao(
        \PDO $pdo,
        string $processoId,
        array $etapaRow,
        string $usuarioId,
        string $usuarioNome
    ): int {
        $numDecisao = (int) $etapaRow['numero_etapa'];
        $nomeEtapa  = (string) ($etapaRow['nome_etapa'] ?? ('Etapa ' . $numDecisao));
        $campo      = (string) ($etapaRow['decisao_campo'] ?? '');
        $saltos     = json_decode((string) ($etapaRow['decisao_saltos'] ?? ''), true) ?: [];

        if ($saltos === []) {
            throw new \RuntimeException(
                "A etapa \"{$nomeEtapa}\" salta o fluxo mas está sem destino configurado. "
                . 'Corrija o template antes de continuar este processo.'
            );
        }

        // ── "Ir para": salto incondicional ───────────────────────────
        // Sem campo para avaliar — o destino está fixo sob o índice 0. Fecha
        // aqui, antes de qualquer consulta a lista.
        if ($campo === '' && isset($saltos['0'])) {
            $destino = (int) $saltos['0'];
            self::aplicarSalto($pdo, $processoId, $numDecisao, $destino, $usuarioId, $usuarioNome, $nomeEtapa, null);
            return $destino;
        }

        if ($campo === '') {
            throw new \RuntimeException(
                "A etapa \"{$nomeEtapa}\" é de decisão mas não diz por qual campo decide. "
                . 'Corrija o template antes de continuar este processo.'
            );
        }

        // 1. Valor escolhido no formulário — é o id do item da lista.
        $stmtDados = $pdo->prepare("SELECT dados_formulario FROM nf_processos WHERE id = ? LIMIT 1");
        $stmtDados->execute([$processoId]);
        $dados   = json_decode((string) $stmtDados->fetchColumn(), true) ?: [];
        $itemId  = trim((string) ($dados[$campo] ?? ''));

        if ($itemId === '') {
            throw new \RuntimeException(
                "A etapa \"{$nomeEtapa}\" decide pelo campo \"{$campo}\", que está vazio neste "
                . 'processo. Preencha o campo para que o fluxo saiba qual caminho seguir.'
            );
        }

        // 2. Índice do item escolhido.
        $stmtItem = $pdo->prepare("SELECT valor, indice FROM nf_lista_itens WHERE id = ? LIMIT 1");
        $stmtItem->execute([$itemId]);
        $item = $stmtItem->fetch();

        if (!$item || $item['indice'] === null) {
            $rotulo = $item['valor'] ?? $itemId;
            throw new \RuntimeException(
                "O item \"{$rotulo}\" não tem índice definido na lista, então a etapa "
                . "\"{$nomeEtapa}\" não sabe para onde mandar o processo. Defina o índice em Listas."
            );
        }

        $indice = (string) (int) $item['indice'];
        if (!isset($saltos[$indice])) {
            throw new \RuntimeException(
                "A etapa \"{$nomeEtapa}\" não tem etapa de destino para o índice {$indice} "
                . "(\"{$item['valor']}\"). Defina o destino desse índice no template."
            );
        }
        $destino = (int) $saltos[$indice];

        self::aplicarSalto(
            $pdo, $processoId, $numDecisao, $destino, $usuarioId, $usuarioNome, $nomeEtapa,
            "{$campo} = {$item['valor']} (índice {$indice})"
        );

        return $destino;
    }

    /**
     * Abre um processo NOVO a partir do template que a etapa indica, e liga os
     * dois. O processo atual é encerrado logo em seguida pelo caminho normal de
     * arquivamento — o filho segue sozinho.
     *
     * O que o filho herda (decidido em 19/08/2026): dados da nota, campos do
     * formulário que existirem NOS DOIS templates (casados por nome) e os
     * anexos. Parcelas não: o financeiro do filho é do filho.
     *
     * Solicitante do filho é quem aprovou a etapa que disparou — é ele que
     * acompanha o que abriu e recebe as notificações.
     *
     * @return string id do processo filho
     */
    private static function abrirFluxoFilho(
        \PDO   $pdo,
        string $processoId,
        array  $etapaRow,
        string $usuarioId,
        string $usuarioNome
    ): string {
        $numEtapa   = (int) $etapaRow['numero_etapa'];
        $nomeEtapa  = (string) ($etapaRow['nome_etapa'] ?? ('Etapa ' . $numEtapa));
        $templateId = (string) $etapaRow['abre_template_id'];

        // Versão publicada do template de destino. Sem ela não há o que abrir —
        // e abortar aqui é melhor que encerrar o pai sem gerar o filho.
        $stmtTpl = $pdo->prepare(
            "SELECT v.id, v.etapas, v.campos, v.tem_financeiro, t.nome
               FROM nf_templates t
               JOIN nf_template_versoes v ON v.id = t.versao_publicada_id
              WHERE t.id = ? LIMIT 1"
        );
        $stmtTpl->execute([$templateId]);
        $tpl = $stmtTpl->fetch();
        if (!$tpl) {
            throw new \RuntimeException(
                "A etapa \"{$nomeEtapa}\" abre um fluxo cujo template não tem versão publicada. "
                . 'Publique o fluxo de destino antes de continuar este processo.'
            );
        }

        // Laço: A abre B que abre A. O teto é a própria cadeia de pais — se o
        // template de destino já apareceu na linhagem deste processo, para.
        $ancestral = $processoId;
        $niveis    = 0;
        $stmtPai   = $pdo->prepare(
            "SELECT p.processo_pai_id, v.template_id
               FROM nf_processos p
               JOIN nf_template_versoes v ON v.id = p.template_versao_id
              WHERE p.id = ? LIMIT 1"
        );
        while ($ancestral !== null && $ancestral !== '' && ++$niveis <= self::MAX_SALTOS_DECISAO) {
            $stmtPai->execute([$ancestral]);
            $row = $stmtPai->fetch();
            if (!$row) break;
            if ((string) $row['template_id'] === $templateId) {
                throw new \RuntimeException(
                    "A etapa \"{$nomeEtapa}\" abriria o fluxo \"{$tpl['nome']}\", que já está nesta "
                    . 'cadeia de processos — seria um laço. Revise os templates encadeados.'
                );
            }
            $ancestral = $row['processo_pai_id'];
        }

        // Dados do pai.
        $stmtPai2 = $pdo->prepare(
            "SELECT fornecedor_id, fornecedor_nome, fornecedor_doc, numero_nf, valor_nf,
                    data_nf, chave_nf, sem_nota, filial_id, filial_doc, dados_formulario, codigo
               FROM nf_processos WHERE id = ? LIMIT 1"
        );
        $stmtPai2->execute([$processoId]);
        $pai = $stmtPai2->fetch();

        // Campos casados por NOME: o que existe nos dois templates vai
        // preenchido, o resto do filho nasce em branco. Casar por nome é o
        // único vínculo estável entre templates diferentes — não há id comum.
        $dadosPai   = json_decode((string) ($pai['dados_formulario'] ?? ''), true) ?: [];
        $camposTpl  = json_decode((string) $tpl['campos'], true) ?: [];
        $dadosFilho = [];
        foreach ($camposTpl as $c) {
            $nome = (string) ($c['nome'] ?? '');
            if ($nome !== '' && array_key_exists($nome, $dadosPai)) {
                $dadosFilho[$nome] = $dadosPai[$nome];
            }
        }

        $filhoId = self::criar([
            'template_versao_id' => (string) $tpl['id'],
            'fornecedor_id'      => $pai['fornecedor_id'],
            'fornecedor_nome'    => $pai['fornecedor_nome'],
            'fornecedor_doc'     => $pai['fornecedor_doc'],
            'numero_nf'          => $pai['numero_nf'],
            'valor_nf'           => $pai['valor_nf'],
            'data_nf'            => $pai['data_nf'],
            'chave_nf'           => $pai['chave_nf'],
            'sem_nota'           => (bool) $pai['sem_nota'],
            'filial_id'          => $pai['filial_id'],
            'filial_doc'         => $pai['filial_doc'],
            'dados_formulario'   => $dadosFilho,
            'etapas_template'    => json_decode((string) $tpl['etapas'], true) ?: [],
            'tem_financeiro'     => (bool) $tpl['tem_financeiro'],
            'processo_pai_id'    => $processoId,
        ], $usuarioId)['id'];

        // Anexos: a cópia é do REGISTRO, apontando para o mesmo arquivo físico.
        // Duplicar o arquivo em disco só gastaria espaço — os dois processos
        // referenciam o mesmo documento, que é justamente a intenção.
        $pdo->prepare(
            "INSERT INTO nf_processo_anexos
                (id, processo_id, tipo_anexo_nome, nome_original, nome_armazenado,
                 caminho, mime_type, tamanho_bytes, is_teste, enviado_por, enviado_em)
             SELECT UUID(), ?, tipo_anexo_nome, nome_original, nome_armazenado,
                    caminho, mime_type, tamanho_bytes, is_teste, ?, NOW()
               FROM nf_processo_anexos
              WHERE processo_id = ? AND deletado_em IS NULL"
        )->execute([$filhoId, $usuarioId, $processoId]);

        $stmtCod = $pdo->prepare("SELECT codigo FROM nf_processos WHERE id = ? LIMIT 1");
        $stmtCod->execute([$filhoId]);
        $codFilho = (string) ($stmtCod->fetchColumn() ?: '—');

        // Histórico dos DOIS lados: olhando qualquer um dos processos, dá para
        // chegar no outro.
        self::inserirHistorico(
            $pdo, $processoId, 'etapa_aprovada', $numEtapa, $usuarioId, $usuarioNome,
            "\"{$nomeEtapa}\": abriu o processo {$codFilho} no fluxo \"{$tpl['nome']}\". "
            . 'Este processo é encerrado aqui.'
        );
        self::inserirHistorico(
            $pdo, $filhoId, 'processo_aberto', 1, $usuarioId, $usuarioNome,
            "Aberto automaticamente pelo processo {$pai['codigo']}, na etapa \"{$nomeEtapa}\"."
        );

        return $filhoId;
    }

    /**
     * Executa o salto em si: marca a etapa de origem como resolvida, ajusta o
     * trecho que ficou no caminho e registra o histórico.
     *
     * Compartilhado pela decisão (que escolhe o destino lendo o formulário) e
     * pelo "Ir para" (que já nasce com o destino fixo) — o que muda entre as
     * duas é só COMO se chega ao número da etapa de destino; daqui para frente
     * o efeito é idêntico, e duplicar isso significaria dois lugares para
     * corrigir quando a regra do 'pulado' mudar.
     *
     * @param string|null $motivo texto do histórico explicando a escolha
     *                            (null = salto incondicional, não há escolha)
     */
    private static function aplicarSalto(
        \PDO    $pdo,
        string  $processoId,
        int     $numOrigem,
        int     $destino,
        string  $usuarioId,
        string  $usuarioNome,
        string  $nomeEtapa,
        ?string $motivo
    ): void {
        // A etapa que saltou fica registrada como resolvida pelo sistema.
        $pdo->prepare(
            "UPDATE nf_processo_etapas
                SET status = 'aprovado', atuado_por = ?, atuado_em = NOW()
              WHERE processo_id = ? AND numero_etapa = ?"
        )->execute([$usuarioId, $processoId, $numOrigem]);

        if ($destino > $numOrigem) {
            // Salto para frente: o trecho pulado nunca roda neste processo.
            // As etapas de observação já nascem 'pulado' e continuam assim.
            $stmtPula = $pdo->prepare(
                "UPDATE nf_processo_etapas
                    SET status = 'pulado'
                  WHERE processo_id = ? AND numero_etapa > ? AND numero_etapa < ?
                    AND status = 'pendente'"
            );
            $stmtPula->execute([$processoId, $numOrigem, $destino]);
            $afetadas = $stmtPula->rowCount();
        } else {
            // Salto para trás: o trecho volta a ser percorrido, então as etapas
            // já aprovadas precisam reabrir. As de observação ficam de fora —
            // reabri-las as transformaria em aprovadoras e travaria o fluxo.
            $stmtVolta = $pdo->prepare(
                "UPDATE nf_processo_etapas
                    SET status = 'pendente', atuado_por = NULL, atuado_em = NULL
                  WHERE processo_id = ? AND numero_etapa >= ? AND numero_etapa < ?
                    AND status IN ('aprovado', 'devolvido')
                    AND (decisao_saltos IS NULL OR decisao_saltos = '')"
            );
            $stmtVolta->execute([$processoId, $destino, $numOrigem]);
            $afetadas = $stmtVolta->rowCount();
        }

        $stmtNomeDestino = $pdo->prepare(
            "SELECT nome_etapa FROM nf_processo_etapas
              WHERE processo_id = ? AND numero_etapa = ? LIMIT 1"
        );
        $stmtNomeDestino->execute([$processoId, $destino]);
        $nomeDestino = (string) ($stmtNomeDestino->fetchColumn() ?: ('Etapa ' . $destino));

        $verbo = $destino > $numOrigem
            ? "{$afetadas} etapa(s) descartada(s)"
            : "{$afetadas} etapa(s) reaberta(s)";

        $cabeca = $motivo !== null
            ? "Decisão \"{$nomeEtapa}\": {$motivo}."
            : "\"{$nomeEtapa}\": salto direto.";

        self::inserirHistorico(
            $pdo, $processoId, 'etapa_aprovada', $numOrigem, $usuarioId, $usuarioNome,
            "{$cabeca} Fluxo segue na etapa {$destino} — {$nomeDestino}. {$verbo}."
        );
    }

    /**
     * Etapa de OBSERVAÇÃO: o responsável enxerga o processo mas nunca precisa
     * agir. Nasce com status 'pulado' na criação — este helper serve aos
     * caminhos que caminham pelas etapas por número (avanço em cadeia,
     * devolução) e precisam passar direto por ela sem parar o processo.
     *
     * O status 'pulado' basta para o caminho de aprovação do Task, que procura
     * a próxima etapa 'pendente'; aqui a checagem é pelo catálogo, porque a
     * etapa pode ter sido reaberta por engano em base antiga.
     */
    public static function etapaEhObservacao(array $etapaRow): bool
    {
        if (($etapaRow['status'] ?? '') === 'pulado') {
            return true;
        }
        if (!empty($etapaRow['etapa_catalogo_id'])) {
            require_once SRC . '/Models/Etapa.php';
            $cat = Etapa::findById((string) $etapaRow['etapa_catalogo_id']);
            return $cat !== null && (int) ($cat['somente_observacao'] ?? 0) === 1;
        }
        return false;
    }

    /**
     * Maior etapa ANTERIOR à atual que exige ação — as de observação ('pulado')
     * ficam de fora, senão a devolução transformaria um observador em aprovador.
     * Sem nenhuma anterior acionável, devolve a primeira acionável do processo
     * (mantém o comportamento histórico de "devolver na etapa 1 volta para o
     * solicitante corrigir").
     */
    private static function etapaAnteriorAcionavel(\PDO $pdo, string $processoId, int $etapaAtual): int
    {
        $stmt = $pdo->prepare(
            "SELECT numero_etapa FROM nf_processo_etapas
              WHERE processo_id = ? AND numero_etapa < ? AND status <> 'pulado'
              ORDER BY numero_etapa DESC LIMIT 1"
        );
        $stmt->execute([$processoId, $etapaAtual]);
        $anterior = $stmt->fetchColumn();
        if ($anterior !== false) {
            return (int) $anterior;
        }

        $stmt = $pdo->prepare(
            "SELECT numero_etapa FROM nf_processo_etapas
              WHERE processo_id = ? AND status <> 'pulado'
              ORDER BY numero_etapa ASC LIMIT 1"
        );
        $stmt->execute([$processoId]);
        return (int) ($stmt->fetchColumn() ?: 1);
    }

    /**
     * Conclui uma etapa de arquivamento: marca como aprovada registrando QUEM
     * executou a ação que a atingiu (vínculo da nota / aprovação anterior) e
     * grava o histórico — a rastreabilidade para pesquisa/questionamento.
     */
    private static function concluirEtapaAutomatica(
        \PDO   $pdo,
        string $processoId,
        int    $numeroEtapa,
        string $atorId,
        string $atorNome,
        string $nomeEtapa
    ): void {
        $pdo->prepare(
            "UPDATE nf_processo_etapas
             SET status = 'aprovado', atuado_por = ?, atuado_em = NOW()
             WHERE processo_id = ? AND numero_etapa = ?"
        )->execute([$atorId, $processoId, $numeroEtapa]);

        self::inserirHistorico($pdo, $processoId, 'etapa_arquivada', $numeroEtapa, $atorId, $atorNome,
            "Etapa {$numeroEtapa} ({$nomeEtapa}) concluída automaticamente — arquivamento executado por {$atorNome}."
        );
    }

    private static function inserirHistorico(
        \PDO   $pdo,
        string $processoId,
        string $acao,
        int    $numeroEtapa,
        string $atorId,
        string $atorNome,
        string $detalhe = ''
    ): void {
        $stmt = $pdo->prepare(
            "INSERT INTO nf_processo_historico
                (id, processo_id, acao, numero_etapa, ator_id, ator_nome, detalhe, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([self::uuid(), $processoId, $acao, $numeroEtapa, $atorId, $atorNome, $detalhe]);
    }

    /**
     * Registra evento no histórico (wrapper público de inserirHistorico).
     */
    public static function registrarHistorico(
        string $processoId,
        string $acao,
        string $atorId,
        string $atorNome,
        string $detalhe = '',
        int    $numeroEtapa = 0
    ): void {
        $pdo = Database::getInstance();
        self::inserirHistorico($pdo, $processoId, $acao, $numeroEtapa, $atorId, $atorNome, $detalhe);
    }

    /**
     * Gera UUID v4 (wrapper público).
     */
    public static function uuid_v4(): string
    {
        return self::uuid();
    }

    /**
     * Solicita cancelamento — muda status para 'cancelamento_solicitado'.
     * Usado quando o processo tem parcelas e precisa de confirmação do financeiro.
     */
    public static function solicitarCancelamento(
        string $processoId,
        string $usuarioId,
        string $usuarioNome,
        string $motivo = ''
    ): bool {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "SELECT etapa_atual, codigo FROM nf_processos
                 WHERE id = ? AND status IN ('aberto','devolvido') AND deletado_em IS NULL
                 LIMIT 1"
            );
            $stmt->execute([$processoId]);
            $proc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$proc) {
                $pdo->rollBack();
                return false;
            }

            $stmtUpd = $pdo->prepare(
                "UPDATE nf_processos SET status = 'cancelamento_solicitado' WHERE id = ?"
            );
            $stmtUpd->execute([$processoId]);

            $detalhe = $motivo
                ? "Cancelamento solicitado por {$usuarioNome}. Motivo: {$motivo}"
                : "Cancelamento solicitado por {$usuarioNome}.";
            self::inserirHistorico($pdo, $processoId, 'cancelamento_solicitado', (int)$proc['etapa_atual'],
                $usuarioId, $usuarioNome, $detalhe
            );

            $pdo->commit();
            return true;

        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[Process::solicitarCancelamento] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Desfaz solicitação de cancelamento — volta o processo para 'aberto'.
     */
    public static function desfazerCancelamento(
        string $processoId,
        string $usuarioId,
        string $usuarioNome
    ): bool {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "SELECT etapa_atual, codigo FROM nf_processos
                 WHERE id = ? AND status = 'cancelamento_solicitado' AND deletado_em IS NULL
                 LIMIT 1"
            );
            $stmt->execute([$processoId]);
            $proc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$proc) {
                $pdo->rollBack();
                return false;
            }

            $stmtUpd = $pdo->prepare(
                "UPDATE nf_processos SET status = 'aberto' WHERE id = ?"
            );
            $stmtUpd->execute([$processoId]);

            self::inserirHistorico($pdo, $processoId, 'cancelamento_desfeito', (int)$proc['etapa_atual'],
                $usuarioId, $usuarioNome, "Solicitação de cancelamento desfeita por {$usuarioNome}."
            );

            $pdo->commit();
            return true;

        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[Process::desfazerCancelamento] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retorna membros de um grupo de aprovação.
     * @return array<int, array{id: string, nome: string, email: string}>
     */
    public static function getMembrosGrupo(string $grupoId): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT u.id, u.nome, u.email
             FROM nf_grupo_membros gm
             JOIN usuarios u ON u.id = gm.usuario_id
             WHERE gm.grupo_id = ?
             ORDER BY u.nome ASC"
        );
        $stmt->execute([$grupoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Todos os usuários ativos do sistema — lista de candidatos para a
     * reatribuição administrativa quando a etapa é de um único usuário
     * (não há "membros do time" para restringir a escolha nesse caso).
     * @return array<int, array{id: string, nome: string}>
     */
    public static function getUsuariosAtivos(): array
    {
        $pdo = Database::getInstance();
        return $pdo->query(
            "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Etapa pendente de um processo, por id — sem exigir que o usuário
     * logado seja o dono. Usado pela reatribuição administrativa
     * (tarefas.reatribuir_admin), cuja checagem de posse é outra: quem
     * pode agir, não quem já é o responsável.
     */
    public static function getEtapaPendente(string $processoId, string $etapaId): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT pe.*, p.codigo, p.etapa_atual
             FROM nf_processo_etapas pe
             JOIN nf_processos p ON p.id = pe.processo_id
             WHERE pe.id = ? AND pe.processo_id = ? AND pe.status = 'pendente'
             LIMIT 1"
        );
        $stmt->execute([$etapaId, $processoId]);
        $etapa = $stmt->fetch(PDO::FETCH_ASSOC);
        return $etapa ?: null;
    }

    /**
     * Reatribui a etapa atual para outro aprovador.
     * Funciona para tipo_aprovador 'grupo' (muda para um membro específico → tipo_aprovador vira 'usuario')
     * ou 'usuario' (muda para outro usuário).
     */
    public static function reatribuir(
        string $processoId,
        string $etapaId,
        string $novoAprovadorId,
        string $atorId,
        string $atorNome
    ): bool {
        $pdo = Database::getInstance();

        try {
            $pdo->beginTransaction();

            // Buscar etapa e validar
            $stmt = $pdo->prepare(
                "SELECT pe.*, p.codigo, p.etapa_atual
                 FROM nf_processo_etapas pe
                 JOIN nf_processos p ON p.id = pe.processo_id
                 WHERE pe.id = ? AND pe.processo_id = ? AND pe.status = 'pendente'
                 LIMIT 1"
            );
            $stmt->execute([$etapaId, $processoId]);
            $etapa = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$etapa) {
                $pdo->rollBack();
                return false;
            }

            // Buscar nome do novo aprovador
            $stmtU = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ? LIMIT 1");
            $stmtU->execute([$novoAprovadorId]);
            $novoNome = $stmtU->fetchColumn() ?: '—';

            // Buscar nome do aprovador anterior
            $anteriorNome = '—';
            if ($etapa['tipo_aprovador'] === 'grupo') {
                $stmtG = $pdo->prepare("SELECT nome FROM nf_grupos WHERE id = ? LIMIT 1");
                $stmtG->execute([$etapa['aprovador_id']]);
                $anteriorNome = $stmtG->fetchColumn() ?: '—';
            } else {
                $stmtU2 = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ? LIMIT 1");
                $stmtU2->execute([$etapa['aprovador_id']]);
                $anteriorNome = $stmtU2->fetchColumn() ?: '—';
            }

            // Atualizar etapa
            $stmtUpd = $pdo->prepare(
                "UPDATE nf_processo_etapas
                 SET tipo_aprovador = 'usuario', aprovador_id = ?
                 WHERE id = ?"
            );
            $stmtUpd->execute([$novoAprovadorId, $etapaId]);

            // Registrar histórico
            $detalhe = $anteriorNome . ' → ' . $novoNome;
            self::inserirHistorico(
                $pdo, $processoId, 'responsavel_reatribuido',
                (int) $etapa['etapa_atual'], $atorId, $atorNome, $detalhe
            );

            $pdo->commit();

            // Notificar novo aprovador (fora da transação)
            self::criarNotificacao(
                $pdo, $novoAprovadorId,
                "Processo {$etapa['codigo']} reatribuído para você",
                "A etapa {$etapa['nome_etapa']} foi reatribuída para sua aprovação.",
                $processoId,
                'nf_tarefa_atribuida'
            );

            return true;

        } catch (\PDOException $e) {
            $pdo->rollBack();
            error_log('[Process::reatribuir] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cria notificação usando a tabela `notificacoes` existente no sistema.
     */
    private static function criarNotificacao(
        \PDO   $pdo,
        string $destinatarioId,
        string $titulo,
        string $mensagem,
        string $processoId,
        string $tipo = 'nf_tarefa_atribuida'
    ): void {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO notificacoes
                    (id, usuario_id, tipo, titulo, mensagem, entidade_tipo, entidade_id, lida, criado_em)
                 VALUES (?, ?, ?, ?, ?, 'nf_processo', ?, 0, NOW())"
            );
            $stmt->execute([self::uuid(), $destinatarioId, $tipo, $titulo, $mensagem, $processoId]);
        } catch (\PDOException) {
            // Silencioso — notificações não devem quebrar o fluxo principal
        }
    }

    /**
     * Notifica aprovadores de uma etapa de template.
     *
     * @param array{tipo_aprovador: string, aprovador_id: string} $etapa
     */
    /**
     * Altera o fluxo de um processo encaminhado para o template errado.
     *
     * O processo passa a usar a versão publicada do novo template e VOLTA PARA
     * O INÍCIO do fluxo: as etapas atuais são substituídas pelas do novo
     * template (etapa_atual = 1, status = 'aberto'). O histórico do processo
     * (nf_processo_historico) é imutável e permanece intacto — a troca é
     * registrada como evento 'fluxo_alterado', com o fluxo antigo, o novo e o
     * motivo. Etapas de arquivamento no início do novo fluxo são concluídas
     * automaticamente e os aprovadores da primeira etapa real são notificados.
     *
     * Bloqueado para processos concluídos, cancelados ou com cancelamento
     * solicitado. Requer a permissão processos.alterar_fluxo (controller).
     *
     * @param string $processoId  UUID do processo
     * @param string $templateId  UUID do NOVO template (nf_templates.id)
     * @param string $usuarioId   quem alterou
     * @param string $usuarioNome nome de quem alterou (histórico)
     * @param string $motivo      justificativa (obrigatória no controller)
     * @throws \RuntimeException  com mensagem amigável em caso de bloqueio
     */
    public static function alterarFluxo(
        string $processoId,
        string $templateId,
        string $usuarioId,
        string $usuarioNome,
        string $motivo
    ): void {
        $pdo = Database::getInstance();

        // Processo + nome do fluxo atual
        $stmt = $pdo->prepare(
            "SELECT p.id, p.codigo, p.status, p.template_versao_id, p.criado_por, t.nome AS template_nome
             FROM nf_processos p
             LEFT JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
             LEFT JOIN nf_templates t ON t.id = tv.template_id
             WHERE p.id = ? AND p.deletado_em IS NULL
             LIMIT 1"
        );
        $stmt->execute([$processoId]);
        $processo = $stmt->fetch();
        if (!$processo) {
            throw new \RuntimeException('Processo não encontrado.');
        }
        if (!in_array($processo['status'], ['aberto', 'devolvido'], true)) {
            throw new \RuntimeException('Só é possível alterar o fluxo de processos em andamento (aberto ou devolvido).');
        }

        // Versão publicada do novo template
        $stmt = $pdo->prepare(
            "SELECT tv.id AS versao_id, tv.etapas, t.nome, t.ativo
             FROM nf_templates t
             JOIN nf_template_versoes tv ON tv.id = t.versao_publicada_id
             WHERE t.id = ? AND tv.status = 'publicado'
             LIMIT 1"
        );
        $stmt->execute([$templateId]);
        $novoTemplate = $stmt->fetch();
        if (!$novoTemplate) {
            throw new \RuntimeException('O template escolhido não tem versão publicada.');
        }
        if ((int) $novoTemplate['ativo'] === 0) {
            throw new \RuntimeException(
                'O fluxo "' . $novoTemplate['nome'] . '" está desativado e não recebe processos.'
            );
        }
        if ($novoTemplate['versao_id'] === $processo['template_versao_id']) {
            throw new \RuntimeException('O processo já está neste fluxo.');
        }

        $etapasTemplate = json_decode($novoTemplate['etapas'] ?? '[]', true) ?: [];
        if (empty($etapasTemplate)) {
            throw new \RuntimeException('O template escolhido não possui etapas.');
        }

        $solicitanteId = (string) ($processo['criado_por'] ?? '');

        require_once SRC . '/Models/Etapa.php';

        $pdo->beginTransaction();
        try {
            // 1. Substitui as etapas: a rastreabilidade de quem aprovou o quê
            //    permanece no histórico imutável (nf_processo_historico).
            $pdo->prepare("DELETE FROM nf_processo_etapas WHERE processo_id = ?")
                ->execute([$processoId]);

            $autoFlags = [];
            $obsFlags  = [];
            $stmtEtapa = $pdo->prepare(
                "INSERT INTO nf_processo_etapas
                    (id, processo_id, numero_etapa, nome_etapa, etapa_catalogo_id, tipo_aprovador, aprovador_id, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($etapasTemplate as $i => $etapa) {
                $numEtapa = $i + 1;
                $catalogo = Etapa::resolver($etapa);
                $auto     = $catalogo !== null && (int) $catalogo['encerra_automaticamente'] === 1;
                $obs      = $catalogo !== null && (int) ($catalogo['somente_observacao'] ?? 0) === 1;
                $autoFlags[$numEtapa] = $auto;
                $obsFlags[$numEtapa]  = $obs;

                // O fluxo muda, o solicitante não: "Solicitante" continua sendo
                // quem abriu o processo, não quem mandou trocar de template.
                [$tipoAprov, $idAprov] = self::resolverAprovadorTemplate($etapa, $solicitanteId);

                $stmtEtapa->execute([
                    self::uuid(),
                    $processoId,
                    $numEtapa,
                    $etapa['nome']  ?? "Etapa {$numEtapa}",
                    $catalogo['id'] ?? null,
                    $auto ? 'arquivo' : $tipoAprov,
                    $auto ? null : $idAprov,
                    $obs ? 'pulado' : 'pendente',
                ]);
            }

            // 2. Reaponta o processo para o novo fluxo, voltando ao início
            $pdo->prepare(
                "UPDATE nf_processos
                 SET template_versao_id = ?, etapa_atual = 1, status = 'aberto'
                 WHERE id = ?"
            )->execute([$novoTemplate['versao_id'], $processoId]);

            // 3. Evento no histórico (linha do tempo preservada)
            $deAntigo = (string) ($processo['template_nome'] ?? '—');
            self::inserirHistorico($pdo, $processoId, 'fluxo_alterado', 1, $usuarioId, $usuarioNome,
                "Fluxo alterado de \"{$deAntigo}\" para \"{$novoTemplate['nome']}\" por {$usuarioNome}. " .
                "O processo retornou ao início do novo fluxo. Motivo: {$motivo}");

            // 4. Conclui etapas de arquivamento iniciais e notifica a
            //    primeira etapa que exige ação (mesma regra da criação)
            $totalEtapas = count($etapasTemplate);
            $etapaAtual  = 1;
            while ($etapaAtual <= $totalEtapas
                   && (($autoFlags[$etapaAtual] ?? false) || ($obsFlags[$etapaAtual] ?? false))) {
                if ($autoFlags[$etapaAtual] ?? false) {
                    self::concluirEtapaAutomatica(
                        $pdo, $processoId, $etapaAtual, $usuarioId, $usuarioNome,
                        (string) ($etapasTemplate[$etapaAtual - 1]['nome'] ?? ('Etapa ' . $etapaAtual))
                    );
                }
                $etapaAtual++;
            }

            if ($etapaAtual > $totalEtapas) {
                // Nenhuma etapa do novo fluxo exige ação (arquivamento/observação)
                $pdo->prepare(
                    "UPDATE nf_processos SET etapa_atual = ?, status = 'aprovado', concluido_em = NOW() WHERE id = ?"
                )->execute([$totalEtapas, $processoId]);
                self::inserirHistorico($pdo, $processoId, 'processo_arquivado', $totalEtapas, $usuarioId, $usuarioNome,
                    "Processo concluído automaticamente após alteração de fluxo por {$usuarioNome} — nenhuma etapa do novo fluxo exige ação.");
            } else {
                if ($etapaAtual > 1) {
                    $pdo->prepare("UPDATE nf_processos SET etapa_atual = ? WHERE id = ?")
                        ->execute([$etapaAtual, $processoId]);
                }
                [$tipoNotif, $idNotif] = self::resolverAprovadorTemplate(
                    $etapasTemplate[$etapaAtual - 1], $solicitanteId
                );
                self::notificarAprovadores(
                    $pdo,
                    ['tipo_aprovador' => $tipoNotif, 'aprovador_id' => (string) $idNotif],
                    $processoId,
                    (string) $processo['codigo'],
                    $usuarioId
                );
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new \RuntimeException('Falha ao alterar o fluxo: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function notificarAprovadores(
        \PDO   $pdo,
        array  $etapa,
        string $processoId,
        string $codigo,
        string $solicitanteId
    ): void {
        $tipo = $etapa['tipo_aprovador'] ?? 'grupo';
        $apId = $etapa['aprovador_id']  ?? '';

        if ($tipo === 'usuario') {
            // Quem acabou de agir não é avisado do próprio ato — mesma regra
            // que o ramo de grupo já aplicava. Passa a valer também aqui por
            // causa da etapa "Solicitante": se ela for a primeira do fluxo, o
            // aprovador é a própria pessoa que está abrindo o processo.
            if ($apId === '' || $apId === $solicitanteId) {
                return;
            }
            self::criarNotificacao($pdo, $apId,
                "Processo {$codigo} aguarda sua aprovação",
                "Um novo processo foi enviado para sua aprovação.",
                $processoId
            );
        } elseif ($tipo === 'grupo') {
            // Buscar membros do grupo e notificar cada um
            $stmt = $pdo->prepare(
                "SELECT usuario_id FROM nf_grupo_membros WHERE grupo_id = ?"
            );
            $stmt->execute([$apId]);
            $membros = $stmt->fetchAll();
            foreach ($membros as $membro) {
                if ($membro['usuario_id'] !== $solicitanteId) {
                    self::criarNotificacao($pdo, $membro['usuario_id'],
                        "Processo {$codigo} aguarda aprovação do seu grupo",
                        "Um novo processo foi enviado para aprovação do seu grupo.",
                        $processoId
                    );
                }
            }
        }
    }
}
