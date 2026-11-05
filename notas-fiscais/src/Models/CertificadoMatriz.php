<?php
// @versao Ger_09 V_01_18/09/2026
declare(strict_types=1);

/**
 * Certificado digital por raiz de CNPJ (matriz) — captura direta NF-e/NFS-e.
 *
 * Um grupo pode ter mais de uma raiz de CNPJ (empresas juridicamente
 * separadas). O certificado único antigo (nf_configuracoes, grupo 'dist')
 * só cobre uma raiz — esta tabela permite cadastrar um por raiz, mantendo o
 * antigo como reserva pra raiz ainda sem certificado próprio.
 */
class CertificadoMatriz
{
    /** Lista todos, com as filiais ativas que casam por raiz (pra contexto na tela). */
    public static function listar(): array
    {
        $pdo = Database::getInstance();
        $certs = $pdo->query(
            "SELECT * FROM nf_certificados_matriz ORDER BY cnpj_raiz"
        )->fetchAll();

        $filiais = $pdo->query(
            "SELECT LEFT(cnpj, 8) AS raiz, nome FROM filiais WHERE ativo = 1 ORDER BY nome"
        )->fetchAll();

        foreach ($certs as &$c) {
            $c['filiais'] = array_values(array_map(
                fn($f) => $f['nome'],
                array_filter($filiais, fn($f) => $f['raiz'] === $c['cnpj_raiz'])
            ));
        }
        unset($c);

        return $certs;
    }

    public static function porRaiz(string $raiz): ?array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT * FROM nf_certificados_matriz WHERE cnpj_raiz = ? LIMIT 1"
        );
        $stmt->execute([$raiz]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Resolve qual certificado usar pra uma filial, nesta ordem:
     *   1. Certificado próprio da filial (filiais.dist_cert_path) — raiz
     *      diferente de qualquer matriz cadastrada, ou caso pontual.
     *   2. Certificado da matriz (raiz de CNPJ da filial) cadastrado aqui.
     *   3. Certificado padrão (nf_configuracoes, grupo 'dist') — reserva,
     *      só usado se a raiz da filial ainda não tem certificado próprio.
     *
     * @param array{cnpj:string, dist_cert_path?:?string, dist_cert_senha?:?string} $filial
     * @return array{0:string,1:string} [certPath, certSenha]
     */
    public static function resolverParaFilial(array $filial, string $padraoPath, string $padraoSenha): array
    {
        if (!empty($filial['dist_cert_path'])) {
            return [(string) $filial['dist_cert_path'], (string) ($filial['dist_cert_senha'] ?? '')];
        }

        $raiz = substr((string) ($filial['cnpj'] ?? ''), 0, 8);
        if (strlen($raiz) === 8) {
            $daMatriz = self::porRaiz($raiz);
            if ($daMatriz !== null && !empty($daMatriz['cert_path'])) {
                return [(string) $daMatriz['cert_path'], (string) ($daMatriz['cert_senha'] ?? '')];
            }
        }

        return [$padraoPath, $padraoSenha];
    }

    public static function findById(string $id): ?array
    {
        $stmt = Database::getInstance()->prepare("SELECT * FROM nf_certificados_matriz WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Cria ou atualiza (por raiz — UNIQUE KEY) num INSERT ... ON DUPLICATE
     * KEY, igual ao padrão já usado em Settings::salvar(). Arquivo/senha em
     * branco mantêm o valor atual (mesma regra do certificado único e do
     * certificado por filial) — só quem manda os dois de propósito troca.
     */
    public static function salvar(string $raiz, ?string $nome, ?string $certPath, ?string $certSenha, string $usuarioId): void
    {
        $pdo = Database::getInstance();
        $existente = self::porRaiz($raiz);

        if ($existente === null) {
            $pdo->prepare(
                "INSERT INTO nf_certificados_matriz (id, cnpj_raiz, nome, cert_path, cert_senha, atualizado_por)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([self::uuid(), $raiz, $nome, $certPath, $certSenha, $usuarioId]);
            return;
        }

        $sets = ['nome = ?'];
        $vals = [$nome ?: $existente['nome']];
        if ($certPath !== null) {
            $sets[] = 'cert_path = ?';
            $vals[] = $certPath;
        }
        if ($certSenha !== null) {
            $sets[] = 'cert_senha = ?';
            $vals[] = $certSenha;
        }
        $sets[] = 'atualizado_por = ?';
        $vals[] = $usuarioId;
        $vals[] = $existente['id'];

        $pdo->prepare('UPDATE nf_certificados_matriz SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($vals);
    }

    public static function excluir(string $id): bool
    {
        $stmt = Database::getInstance()->prepare("DELETE FROM nf_certificados_matriz WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
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
