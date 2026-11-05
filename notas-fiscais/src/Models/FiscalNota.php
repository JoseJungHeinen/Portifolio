<?php
// @versao Fis_04 V_03_26/08/2026
declare(strict_types=1);

/**
 * Model do módulo Fiscal — IBS/CBS das notas já recebidas.
 *
 * A fonte é o acervo de XMLs em storage/nfe_xml, gravado pelo sync da
 * Brobot. Este model NÃO fala com a Brobot: o "Atualizar" da tela relê o
 * disco e materializa os totais em `nf_fiscal_totais`. Quem consulta a tela
 * lê só a tabela — parsear 18 mil XMLs a cada filtro não fecharia a conta.
 *
 * Conferência é manual e vive em `nf_fiscal_conferencia`: linha presente =
 * conferida. O status do fornecedor (total/parcial/nenhuma) é derivado na
 * consulta, dentro do período filtrado — nunca gravado, porque nota nova
 * caindo no período tem que rebaixar o fornecedor sozinha.
 */
class FiscalNota
{
    /** Quantos XMLs uma execução do botão "Atualizar" processa por vez. */
    public const LOTE_PADRAO = 3000;

    // ─────────────────────────────────────────────────────────────
    // Carga — lê os XMLs do disco e materializa os totais
    // ─────────────────────────────────────────────────────────────

    /**
     * Varre storage/nfe_xml e grava/atualiza os totais fiscais.
     *
     * Arquivo já lido e inalterado (mesmo mtime e tamanho) é pulado, o que
     * torna a 2ª execução barata: só entra o que a Brobot trouxe depois.
     *
     * @return array{arquivos:int, lidos:int, novos:int, atualizados:int,
     *                pulados:int, ignorados:int, restantes:int}
     */
    public static function carregar(int $limite = self::LOTE_PADRAO): array
    {
        require_once SRC . '/Helpers/XmlDfeParser.php';

        $pdo  = Database::getInstance();
        $dir  = (defined('STORAGE') ? STORAGE : ROOT . '/storage') . '/nfe_xml';
        $r    = ['arquivos' => 0, 'lidos' => 0, 'novos' => 0, 'atualizados' => 0,
                 'pulados'  => 0, 'ignorados' => 0, 'restantes' => 0];

        if (!is_dir($dir)) return $r;

        // Carimbo do que já está na tabela — uma consulta só, para não bater
        // no banco uma vez por arquivo.
        $jaLidos = [];
        foreach ($pdo->query('SELECT chave_nf, xml_mtime, xml_bytes FROM nf_fiscal_totais') as $row) {
            $jaLidos[$row['chave_nf']] = [(int) $row['xml_mtime'], (int) $row['xml_bytes']];
        }

        $filiais = self::mapaFiliais();
        $agora   = date('Y-m-d H:i:s');
        $stmt    = $pdo->prepare(self::SQL_UPSERT);

        foreach (new DirectoryIterator($dir) as $arq) {
            if ($arq->isDot() || !$arq->isFile()) continue;
            if (strtolower($arq->getExtension()) !== 'xml') continue;

            $r['arquivos']++;

            $chaveArq = $arq->getBasename('.xml');
            $mtime    = (int) $arq->getMTime();
            $bytes    = (int) $arq->getSize();

            if (isset($jaLidos[$chaveArq]) && $jaLidos[$chaveArq] === [$mtime, $bytes]) {
                $r['pulados']++;
                continue;
            }

            // Teto por execução: o resto fica para o próximo clique, com a
            // tela avisando quantos faltam.
            if ($r['lidos'] >= $limite) {
                $r['restantes']++;
                continue;
            }

            $xml = @file_get_contents($arq->getPathname());
            $d   = $xml === false ? null : XmlDfeParser::fiscalNfe($xml);
            $r['lidos']++;

            // Sem emitente ou sem data de emissão a nota não tem como entrar
            // no agrupamento — melhor ficar de fora do que somar em fornecedor
            // errado.
            if ($d === null || $d['emitente_cnpj'] === null || $d['data_emissao'] === null) {
                $r['ignorados']++;
                continue;
            }

            // A chave vem do Id do próprio XML; o nome do arquivo só entra
            // como último recurso.
            $chave = $d['chave'] ?? (strlen($chaveArq) === 44 ? $chaveArq : null);
            if ($chave === null) {
                $r['ignorados']++;
                continue;
            }

            // char(14) com sql_mode STRICT (produção) recusa string maior —
            // XML torto derrubaria a carga inteira. Corta em vez de estourar.
            $destCnpj = substr((string) ($d['destinatario_cnpj'] ?? ''), 0, 14);
            $emitCnpj = substr((string) $d['emitente_cnpj'], 0, 14);
            $filial   = $filiais[$destCnpj] ?? null;
            $novo     = !isset($jaLidos[$chave]);

            $stmt->execute([
                $chave,
                $d['numero'] !== null ? substr($d['numero'], 0, 20) : null,
                $d['data_emissao'],
                $emitCnpj,
                mb_substr((string) ($d['emitente_nome'] ?? 'SEM NOME'), 0, 200),
                $destCnpj,
                $filial['id'] ?? null,
                isset($filial['nome']) ? mb_substr((string) $filial['nome'], 0, 100) : null,
                $d['valor_produtos'], $d['valor_nota'],
                $d['base_ibs_cbs'], $d['valor_ibs_uf'], $d['valor_ibs_mun'],
                $d['valor_ibs'], $d['valor_cbs'],
                $d['tem_grupo'] ? 1 : 0,
                $mtime, $bytes, $agora,
            ]);

            $novo ? $r['novos']++ : $r['atualizados']++;
        }

        return $r;
    }

    private const SQL_UPSERT =
        'INSERT INTO nf_fiscal_totais
           (chave_nf, numero_nf, data_emissao, emitente_cnpj, emitente_nome,
            destinatario_cnpj, filial_id, filial_nome, valor_produtos, valor_nota,
            base_ibs_cbs, valor_ibs_uf, valor_ibs_mun, valor_ibs, valor_cbs,
            tem_grupo_ibs_cbs, xml_mtime, xml_bytes, lido_em)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            numero_nf = VALUES(numero_nf), data_emissao = VALUES(data_emissao),
            emitente_cnpj = VALUES(emitente_cnpj), emitente_nome = VALUES(emitente_nome),
            destinatario_cnpj = VALUES(destinatario_cnpj),
            filial_id = VALUES(filial_id), filial_nome = VALUES(filial_nome),
            valor_produtos = VALUES(valor_produtos), valor_nota = VALUES(valor_nota),
            base_ibs_cbs = VALUES(base_ibs_cbs), valor_ibs_uf = VALUES(valor_ibs_uf),
            valor_ibs_mun = VALUES(valor_ibs_mun), valor_ibs = VALUES(valor_ibs),
            valor_cbs = VALUES(valor_cbs), tem_grupo_ibs_cbs = VALUES(tem_grupo_ibs_cbs),
            xml_mtime = VALUES(xml_mtime), xml_bytes = VALUES(xml_bytes),
            lido_em = VALUES(lido_em)';

    /** CNPJ (só dígitos) → ['id' => ..., 'nome' => ...] das filiais. */
    private static function mapaFiliais(): array
    {
        $pdo  = Database::getInstance();
        $cnpj = "REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','')";
        $out  = [];
        foreach ($pdo->query("SELECT id, nome, {$cnpj} AS cnpj FROM filiais") as $f) {
            $out[$f['cnpj']] = ['id' => $f['id'], 'nome' => $f['nome']];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────
    // Consultas da tela
    // ─────────────────────────────────────────────────────────────

    /**
     * WHERE comum das consultas: período (obrigatório) + filiais escolhidas
     * + escopo de filial do usuário logado.
     *
     * @param array{data_ini:string, data_fim:string, filial_ids?:list<string>} $f
     * @return array{0:string, 1:list<mixed>}
     */
    private static function where(array $f): array
    {
        $where  = ['t.data_emissao BETWEEN ? AND ?'];
        $params = [$f['data_ini'], $f['data_fim']];

        $filiais = $f['filial_ids'] ?? [];
        if ($filiais !== []) {
            $where[] = 't.filial_id IN (' . implode(',', array_fill(0, count($filiais), '?')) . ')';
            $params  = array_merge($params, $filiais);
        }

        // Mesmo recorte por CNPJ de destino que as demais telas respeitam.
        if (class_exists('FilialScope')) {
            [$cond, $p] = FilialScope::sqlRestringeSessao('t.destinatario_cnpj');
            if ($cond !== '') {
                $where[] = $cond;
                $params  = array_merge($params, $p);
            }
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * O que conta como "destacou IBS/CBS": a nota trouxe o GRUPO da reforma
     * (`<IBSCBSTot>`, cujo primeiro campo é `vBCIBSCBS`).
     *
     * O critério é a presença do grupo, não o valor: base zerada pode ser
     * perfeitamente correta dependendo da operação — isenta, imune, fora do
     * campo de incidência. O emitente que mandou o grupo se adequou ao
     * layout; quem não mandou é que ficou de fora.
     */
    private const DESTACOU = 't.tem_grupo_ibs_cbs = 1';

    /** Colunas de soma reaproveitadas pelo resumo e pelo agrupamento. */
    private static function somas(): string
    {
        return 'COUNT(*) AS notas,
            COALESCE(SUM(t.valor_produtos), 0) AS valor_produtos,
            COALESCE(SUM(t.valor_nota), 0)     AS valor_nota,
            COALESCE(SUM(t.base_ibs_cbs), 0)   AS base_ibs_cbs,
            COALESCE(SUM(t.valor_ibs), 0)      AS valor_ibs,
            COALESCE(SUM(t.valor_cbs), 0)      AS valor_cbs,
            COALESCE(SUM(c.chave_nf IS NOT NULL), 0) AS conferidas,
            COALESCE(SUM(' . self::DESTACOU . '), 0) AS notas_destacaram';
    }

    /**
     * Resumo do topo — todas as notas e todos os fornecedores do período.
     *
     * @return array<string, float|int>
     */
    public static function resumo(array $f): array
    {
        [$w, $params] = self::where($f);

        $sql = 'SELECT ' . self::somas() . ',
                       COUNT(DISTINCT t.emitente_cnpj) AS fornecedores,
                       COUNT(DISTINCT IF(' . self::DESTACOU . ', t.emitente_cnpj, NULL))
                         AS fornecedores_destacaram
                  FROM nf_fiscal_totais t
                  LEFT JOIN nf_fiscal_conferencia c ON c.chave_nf = t.chave_nf
                 WHERE ' . $w;

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];

        return [
            'notas'                    => (int)   ($row['notas'] ?? 0),
            'fornecedores'             => (int)   ($row['fornecedores'] ?? 0),
            'valor_produtos'           => (float) ($row['valor_produtos'] ?? 0),
            'valor_nota'               => (float) ($row['valor_nota'] ?? 0),
            'base_ibs_cbs'             => (float) ($row['base_ibs_cbs'] ?? 0),
            'valor_ibs'                => (float) ($row['valor_ibs'] ?? 0),
            'valor_cbs'                => (float) ($row['valor_cbs'] ?? 0),
            'conferidas'               => (int)   ($row['conferidas'] ?? 0),
            'notas_destacaram'         => (int)   ($row['notas_destacaram'] ?? 0),
            'fornecedores_destacaram'  => (int)   ($row['fornecedores_destacaram'] ?? 0),
        ];
    }

    /**
     * Uma linha por CNPJ de fornecedor, com os valores somados no período e
     * o estágio de conferência derivado.
     *
     * `cbs` recorta por adesão à reforma, somando o período inteiro:
     *   'sem' → fornecedor que NÃO destacou CBS em nenhuma nota
     *   'com' → fornecedor que destacou CBS em ao menos uma
     * O recorte é HAVING, não WHERE: a pergunta é sobre o fornecedor no
     * período, não sobre a nota isolada.
     *
     * @return list<array<string,mixed>>  cada linha com 'status' = total|parcial|nenhuma
     */
    public static function porFornecedor(array $f): array
    {
        [$w, $params] = self::where($f);

        $having = match ($f['cbs'] ?? '') {
            'sem'   => ' HAVING SUM(' . self::DESTACOU . ') = 0',
            'com'   => ' HAVING SUM(' . self::DESTACOU . ') > 0',
            default => '',
        };

        $sql = 'SELECT t.emitente_cnpj,
                       MAX(t.emitente_nome) AS emitente_nome, ' . self::somas() . '
                  FROM nf_fiscal_totais t
                  LEFT JOIN nf_fiscal_conferencia c ON c.chave_nf = t.chave_nf
                 WHERE ' . $w . '
                 GROUP BY t.emitente_cnpj' . $having . '
                 ORDER BY valor_nota DESC';

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['status'] = self::estagio((int) $row['conferidas'], (int) $row['notas']);
            $out[] = $row;
        }
        return $out;
    }

    /** Notas de um fornecedor, dentro do mesmo recorte da listagem. */
    public static function notasDoFornecedor(string $emitenteCnpj, array $f): array
    {
        [$w, $params] = self::where($f);
        $params[] = $emitenteCnpj;

        $sql = 'SELECT t.*, (c.chave_nf IS NOT NULL) AS conferida,
                       c.conferido_em, u.nome AS conferido_por_nome
                  FROM nf_fiscal_totais t
                  LEFT JOIN nf_fiscal_conferencia c ON c.chave_nf = t.chave_nf
                  LEFT JOIN usuarios u              ON u.id = c.conferido_por
                 WHERE ' . $w . ' AND t.emitente_cnpj = ?
                 ORDER BY t.data_emissao, t.numero_nf';

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Cabeçalho da tela de detalhe: dados e somas do fornecedor no período.
     * Ignora o recorte de CBS de propósito — quem abriu um fornecedor quer
     * vê-lo, mesmo que o filtro da listagem deixasse de exibi-lo.
     */
    public static function fornecedor(string $emitenteCnpj, array $f): ?array
    {
        unset($f['cbs']);
        foreach (self::porFornecedor($f) as $linha) {
            if ($linha['emitente_cnpj'] === $emitenteCnpj) {
                return $linha;
            }
        }
        return null;
    }

    /** total = todas conferidas · parcial = alguma · nenhuma = zero. */
    private static function estagio(int $conferidas, int $notas): string
    {
        if ($notas > 0 && $conferidas >= $notas) return 'total';
        return $conferidas > 0 ? 'parcial' : 'nenhuma';
    }

    // ─────────────────────────────────────────────────────────────
    // Conferência manual
    // ─────────────────────────────────────────────────────────────

    /**
     * Marca ou desmarca notas como conferidas. Desmarcar apaga a linha —
     * "não conferida" é a ausência de registro, não um estado gravado.
     *
     * @param list<string> $chaves
     * @return int quantas notas mudaram de estado
     */
    public static function conferir(array $chaves, bool $conferido, string $usuarioId): int
    {
        $chaves = array_values(array_filter(
            $chaves,
            fn($c) => is_string($c) && strlen($c) === 44 && ctype_digit($c)
        ));
        if ($chaves === []) return 0;

        $pdo = Database::getInstance();
        $ph  = implode(',', array_fill(0, count($chaves), '?'));

        if (!$conferido) {
            $stmt = $pdo->prepare("DELETE FROM nf_fiscal_conferencia WHERE chave_nf IN ({$ph})");
            $stmt->execute($chaves);
            return $stmt->rowCount();
        }

        // Só confere o que existe na carga — chave solta no POST não cria linha órfã.
        $stmt = $pdo->prepare("SELECT chave_nf FROM nf_fiscal_totais WHERE chave_nf IN ({$ph})");
        $stmt->execute($chaves);
        $validas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($validas === []) return 0;

        $ins = $pdo->prepare(
            'INSERT INTO nf_fiscal_conferencia (chave_nf, conferido_por, conferido_em)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE conferido_por = VALUES(conferido_por),
                                     conferido_em  = VALUES(conferido_em)'
        );
        $agora = date('Y-m-d H:i:s');
        foreach ($validas as $chave) {
            $ins->execute([$chave, $usuarioId, $agora]);
        }
        return count($validas);
    }

    /** Filiais que o usuário pode escolher no filtro. */
    public static function filiaisDoFiltro(): array
    {
        $uid = (string) ($_SESSION['usuario_id'] ?? '');
        if ($uid !== '' && class_exists('FilialScope')) {
            return FilialScope::filiaisVisiveis($uid);
        }
        return Database::getInstance()
            ->query('SELECT id, nome FROM filiais WHERE ativo = 1 ORDER BY nome')
            ->fetchAll();
    }

    /** Carimbo da última carga — a tela mostra "atualizado em". */
    public static function ultimaCarga(): ?string
    {
        $v = Database::getInstance()->query('SELECT MAX(lido_em) FROM nf_fiscal_totais')->fetchColumn();
        return $v ?: null;
    }
}
