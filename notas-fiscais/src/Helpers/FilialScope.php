<?php
// @versao Ace_06 V_02_12/08/2026
declare(strict_types=1);

/**
 * Escopo de visibilidade por FILIAL (CNPJ de destino).
 *
 * Enquanto o usuário não tiver nenhuma linha em nf_usuario_filiais, nada muda:
 * ele continua enxergando todas as filiais (comportamento histórico). A partir
 * do primeiro vínculo, passa a ver SOMENTE as filiais vinculadas — regra que
 * vale inclusive para admin, porque o vínculo só existe se alguém o criou de
 * propósito na tela de Controle de Acesso.
 *
 * O recorte é feito pelo CNPJ (nf_notas_capturadas.destinatario_cnpj /
 * nf_processos.filial_doc), não por filial_id: o CNPJ é NOT NULL nas duas
 * tabelas, enquanto filial_id aceita NULL na captura — nota de CNPJ ainda não
 * cadastrado como filial escaparia de um filtro por id.
 */
class FilialScope
{
    /** Cache por request: usuario_id => lista de CNPJ, ou null (sem restrição). */
    private static array $cache = [];

    /**
     * CNPJs (só dígitos) que o usuário pode enxergar.
     * null = sem restrição configurada (vê tudo).
     *
     * @return list<string>|null
     */
    public static function cnpjsPermitidos(string $usuarioId): ?array
    {
        if ($usuarioId === '') {
            return null;
        }
        if (array_key_exists($usuarioId, self::$cache)) {
            return self::$cache[$usuarioId];
        }

        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT REPLACE(REPLACE(REPLACE(f.cnpj,'.',''),'/',''),'-','') AS cnpj
                   FROM nf_usuario_filiais uf
                   JOIN filiais f ON f.id = uf.filial_id
                  WHERE uf.usuario_id = ?"
            );
            $stmt->execute([$usuarioId]);
            $cnpjs = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            // Tabela ainda não migrada — sem restrição (estado atual do sistema).
            $cnpjs = [];
        }

        return self::$cache[$usuarioId] = $cnpjs ? array_map('strval', $cnpjs) : null;
    }

    /** IDs das filiais vinculadas (para marcar os checkboxes na tela). */
    public static function filiaisDoUsuario(string $usuarioId): array
    {
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT filial_id FROM nf_usuario_filiais WHERE usuario_id = ?"
            );
            $stmt->execute([$usuarioId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** O usuário tem restrição de filial configurada? */
    public static function temEscopo(string $usuarioId): bool
    {
        return self::cnpjsPermitidos($usuarioId) !== null;
    }

    /**
     * Condição SQL restringindo uma coluna de CNPJ ao escopo do usuário.
     * Devolve ['', []] quando não há restrição.
     *
     * @param string $coluna ex.: 'c.destinatario_cnpj' ou 'p.filial_doc'
     * @return array{0:string,1:list<string>} [condicao, params]
     */
    public static function sqlRestringe(string $coluna, string $usuarioId): array
    {
        $cnpjs = self::cnpjsPermitidos($usuarioId);
        if ($cnpjs === null) {
            return ['', []];
        }
        if ($cnpjs === []) {
            return ['1 = 0', []];   // vínculo com filial inexistente: não vê nada
        }

        // Sem placeholders de propósito — os CNPJs vêm da tabela `filiais`,
        // já escapados por PDO::quote(). Assim o recorte entra em qualquer
        // query legada sem alterar a ordem dos parâmetros do execute().
        $pdo   = Database::getInstance();
        $lista = implode(',', array_map(static fn(string $c): string => $pdo->quote($c), $cnpjs));

        return ["{$coluna} IN ({$lista})", []];
    }

    /**
     * Versão "de sessão" de sqlRestringe(): aplica o escopo ao usuário logado
     * e não faz nada fora do contexto web (CLI de sync/SLA precisa varrer tudo).
     *
     * @return array{0:string,1:list<string>}
     */
    public static function sqlRestringeSessao(string $coluna): array
    {
        $uid = (string) ($_SESSION['usuario_id'] ?? '');
        return $uid === '' ? ['', []] : self::sqlRestringe($coluna, $uid);
    }

    /**
     * Filiais que o usuário pode escolher no filtro de tela.
     * Sem escopo = todas as ativas.
     */
    public static function filiaisVisiveis(string $usuarioId): array
    {
        $pdo   = Database::getInstance();
        $cnpjs = self::cnpjsPermitidos($usuarioId);

        if ($cnpjs === null) {
            return $pdo->query("SELECT id, nome FROM filiais WHERE ativo = 1 ORDER BY nome")->fetchAll();
        }
        if ($cnpjs === []) {
            return [];
        }
        $ph   = implode(',', array_fill(0, count($cnpjs), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, nome FROM filiais
              WHERE REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','') IN ({$ph})
              ORDER BY nome"
        );
        $stmt->execute($cnpjs);
        return $stmt->fetchAll();
    }

    /**
     * IDs das filiais que o usuário enxerga — null = sem restrição (todas).
     * Contraparte de cnpjsPermitidos() para quem precisa comparar por id
     * (filtro de tela, que trabalha com filial_id).
     *
     * @return list<string>|null
     */
    public static function idsVisiveis(string $usuarioId): ?array
    {
        if (self::cnpjsPermitidos($usuarioId) === null) {
            return null;
        }
        return array_map('strval', array_column(self::filiaisVisiveis($usuarioId), 'id'));
    }

    /**
     * Lê o filtro de lojas da tela (multi-seleção: `filial_id[]`, ou o valor
     * único das telas/links antigos) e devolve os ids válidos DENTRO do escopo
     * do usuário logado.
     *
     * Recortar aqui é o que faz a multi-seleção "respeitar o acesso": marcar
     * uma loja que não é sua no HTML ou na URL não amplia nada — o id sai da
     * lista e o WHERE continua sendo o do escopo.
     *
     * @return list<string>
     */
    public static function idsDoFiltro(mixed $bruto): array
    {
        $ids = [];
        foreach ((array) $bruto as $v) {
            $v = trim((string) $v);
            if ($v !== '' && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $v)) {
                $ids[$v] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [];
        }

        $uid = (string) ($_SESSION['usuario_id'] ?? '');
        if ($uid === '') {
            return $ids;
        }
        $permitidas = self::idsVisiveis($uid);

        return $permitidas === null ? $ids : array_values(array_intersect($ids, $permitidas));
    }

    /**
     * Substitui o conjunto de filiais do usuário (lista vazia = remove o escopo,
     * voltando a enxergar tudo).
     *
     * @param list<string> $filialIds
     */
    public static function definirFiliais(string $usuarioId, array $filialIds, string $porUsuarioId): void
    {
        $pdo = Database::getInstance();
        $pdo->prepare("DELETE FROM nf_usuario_filiais WHERE usuario_id = ?")->execute([$usuarioId]);

        $stmt = $pdo->prepare(
            "INSERT INTO nf_usuario_filiais (id, usuario_id, filial_id, criado_por) VALUES (?, ?, ?, ?)"
        );
        foreach (array_unique($filialIds) as $fid) {
            if ($fid !== '') {
                $stmt->execute([self::uuid(), $usuarioId, $fid, $porUsuarioId]);
            }
        }

        unset(self::$cache[$usuarioId]);
        AuditLog::registrar('usuario', $usuarioId, 'usuario.escopo_filiais_alterado',
            null, ['filiais' => count($filialIds), 'por' => $porUsuarioId]);
    }

    /** Zera o cache por request — usado após alterar liberações/vínculos. */
    public static function limparCache(): void
    {
        self::$cache = [];
    }

    private static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
