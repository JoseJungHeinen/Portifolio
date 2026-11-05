<?php
// @versao Ger_06 V_02_01/10/2026
declare(strict_types=1);

/**
 * Acesso às configurações gerais (tabela nf_configuracoes).
 *
 *   Settings::get('email.host', 'localhost')
 *   Settings::getInt('seguranca.session_timeout_min', 30)
 *   Settings::salvar(['email.host' => '...', ...], $usuarioId)
 *
 * Cache por request. Se a tabela não existir ainda (migration não aplicada),
 * retorna sempre o default — o sistema continua funcionando.
 */
class Settings
{
    private static ?array $cache = null;

    private static function carregar(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            $pdo  = Database::getInstance();
            $rows = $pdo->query("SELECT chave, valor FROM nf_configuracoes")->fetchAll(PDO::FETCH_KEY_PAIR);
            self::$cache = $rows ?: [];
        } catch (Throwable $e) {
            self::$cache = [];
        }
        return self::$cache;
    }

    /**
     * Abas da tela Geral que o admin pode ligar/desligar (chave => rótulo).
     * 'geral' fica de fora de propósito: é onde mora o controle.
     */
    public const ABAS_OPCIONAIS = [
        'email' => 'E-mail', 'seguranca' => 'Segurança', 'notificacoes' => 'Notificações',
        'api' => 'API', 'filiais' => 'Filiais', 'brobot' => 'Brobot', 'nfe_nfse' => 'NF-e / NFS-e',
        'apollo' => 'Apollo', 'faturamento' => 'Faturamento', 'horarios' => 'Horários de Acesso',
        'paginas' => 'Páginas e Arquivos',
    ];

    /** Aba ligada? Sem registro = ligada (padrão); só some quando gravada como '0'. */
    public static function abaAtiva(string $aba): bool
    {
        return !isset(self::ABAS_OPCIONAIS[$aba]) || self::get('modulos.' . $aba, '1') !== '0';
    }

    public static function get(string $chave, $default = null)
    {
        $todas = self::carregar();
        return array_key_exists($chave, $todas) ? $todas[$chave] : $default;
    }

    public static function getInt(string $chave, int $default = 0): int
    {
        $v = self::get($chave, null);
        return $v === null || $v === '' ? $default : (int) $v;
    }

    /** Todas as chaves de um grupo (email, seguranca, notificacoes, geral). */
    public static function grupo(string $grupo): array
    {
        try {
            $pdo  = Database::getInstance();
            $stmt = $pdo->prepare("SELECT chave, valor FROM nf_configuracoes WHERE grupo = ?");
            $stmt->execute([$grupo]);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @param array<string,string> $pares chave => valor */
    public static function salvar(array $pares, string $usuarioId): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "INSERT INTO nf_configuracoes (chave, valor, atualizado_por)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_por = VALUES(atualizado_por)"
        );
        foreach ($pares as $chave => $valor) {
            $stmt->execute([$chave, (string) $valor, $usuarioId]);
        }
        self::$cache = null; // invalida cache
    }
}
