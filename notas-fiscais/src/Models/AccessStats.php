<?php
// @versao Ace_07 V_02_11/08/2026
declare(strict_types=1);

/**
 * Estatísticas do Controle de Acesso — visão de cadastro e de LOGIN.
 *
 * Responde a pergunta que a tela de permissões não respondia: as pessoas estão
 * conseguindo entrar? Só LEITURA — nada aqui grava.
 *
 * Fontes (todas já existentes, nenhuma tabela nova):
 *   • usuarios          — ultimo_acesso, tentativas_login, bloqueado_ate, ativo
 *   • logs_auditoria    — nf_usuario.login_sucesso / login_falha /
 *                         senha_redefinida (com ip_address e criado_em)
 *   • nf_grupos / nf_grupo_membros
 *
 * Limite conhecido: `ultimo_acesso` é gravado no LOGIN, não a cada requisição —
 * por isso não existe "usuários online agora", e sim "logaram nas últimas 24h".
 * Falha de login com e-mail INEXISTENTE não é registrada hoje (AuthController
 * só audita quando o usuário existe), então o Top 5 de erro cobre senha errada,
 * não e-mail errado.
 */
class AccessStats
{
    /** Janela padrão das métricas de período, em dias. */
    private const JANELA_DIAS = 30;

    /** Dias plotados na curva de logins. */
    private const CURVA_DIAS = 14;

    /** Quantas linhas em cada ranking. */
    private const TOP = 5;

    private const ACAO_SUCESSO = 'nf_usuario.login_sucesso';
    private const ACAO_FALHA   = 'nf_usuario.login_falha';
    private const ACAO_RESET   = 'nf_usuario.senha_redefinida';

    /**
     * Todos os números do painel, em uma chamada.
     *
     * @return array{
     *   cadastro:array, acesso:array, uso:array, problemas:array,
     *   top_ativos:array, top_falhas:array, travados:array,
     *   curva:array, perfis:array, janela_dias:int
     * }
     */
    public static function painel(): array
    {
        $pdo = Database::getInstance();

        return [
            'janela_dias' => self::JANELA_DIAS,
            'cadastro'    => self::cadastro($pdo),
            'acesso'      => self::acesso($pdo),
            'uso'         => self::uso($pdo),
            'problemas'   => self::problemas($pdo),
            'top_ativos'  => self::topAtivos($pdo),
            'top_falhas'  => self::topFalhas($pdo),
            'travados'    => self::travados($pdo),
            'curva'       => self::curva($pdo),
            'perfis'      => self::perfis($pdo),
        ];
    }

    // ─────────────────────────────────────────
    // Blocos
    // ─────────────────────────────────────────

    /** Quem existe no cadastro. */
    private static function cadastro(\PDO $pdo): array
    {
        $sql = "SELECT
                  (SELECT COUNT(*) FROM usuarios)                                     AS total,
                  (SELECT COUNT(*) FROM usuarios WHERE ativo = 1)                     AS ativos,
                  (SELECT COUNT(*) FROM usuarios WHERE ativo = 0)                     AS inativos,
                  (SELECT COUNT(*) FROM usuarios
                    WHERE criado_em >= NOW() - INTERVAL ? DAY)                        AS novos,
                  (SELECT COUNT(*) FROM nf_grupos)                                    AS grupos,
                  (SELECT COUNT(*) FROM nf_grupos g
                    WHERE NOT EXISTS (SELECT 1 FROM nf_grupo_membros gm
                                       WHERE gm.grupo_id = g.id))                     AS grupos_vazios,
                  (SELECT COUNT(*) FROM usuarios u
                    WHERE u.ativo = 1
                      AND NOT EXISTS (SELECT 1 FROM nf_grupo_membros gm
                                       WHERE gm.usuario_id = u.id))                   AS sem_grupo";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([self::JANELA_DIAS]);

        return self::inteiros($stmt->fetch(\PDO::FETCH_ASSOC) ?: []);
    }

    /** A pergunta central: estão conseguindo entrar? */
    private static function acesso(\PDO $pdo): array
    {
        $sql = "SELECT
                  (SELECT COUNT(*) FROM usuarios
                    WHERE ativo = 1 AND ultimo_acesso IS NULL)                        AS nunca_logaram,
                  (SELECT COUNT(*) FROM usuarios
                    WHERE ativo = 1
                      AND (ultimo_acesso IS NULL
                           OR ultimo_acesso < NOW() - INTERVAL ? DAY))                AS sem_acesso_janela,
                  (SELECT COUNT(*) FROM usuarios WHERE bloqueado_ate > NOW())         AS bloqueados,
                  (SELECT COUNT(*) FROM usuarios WHERE tentativas_login > 0)          AS com_tentativa,
                  (SELECT COUNT(*) FROM logs_auditoria
                    WHERE acao = ? AND criado_em >= NOW() - INTERVAL ? DAY)           AS resets";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([self::JANELA_DIAS, self::ACAO_RESET, self::JANELA_DIAS]);
        $r = self::inteiros($stmt->fetch(\PDO::FETCH_ASSOC) ?: []);

        // Taxa de sucesso: sucessos / (sucessos + falhas) na janela
        $stmt = $pdo->prepare(
            "SELECT
               SUM(acao = ?) AS sucessos,
               SUM(acao = ?) AS falhas
             FROM logs_auditoria
             WHERE acao IN (?, ?) AND criado_em >= NOW() - INTERVAL ? DAY"
        );
        $stmt->execute([self::ACAO_SUCESSO, self::ACAO_FALHA, self::ACAO_SUCESSO, self::ACAO_FALHA, self::JANELA_DIAS]);
        $t = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $sucessos = (int) ($t['sucessos'] ?? 0);
        $falhas   = (int) ($t['falhas'] ?? 0);
        $tentativas = $sucessos + $falhas;

        $r['sucessos']   = $sucessos;
        $r['falhas']     = $falhas;
        $r['taxa_ok']    = $tentativas > 0 ? (int) round($sucessos * 100 / $tentativas) : null;

        return $r;
    }

    /** Quem está usando de fato. */
    private static function uso(\PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            "SELECT
               (SELECT COUNT(*) FROM usuarios
                 WHERE ultimo_acesso >= NOW() - INTERVAL 24 HOUR)                     AS logaram_24h,
               (SELECT COUNT(DISTINCT usuario_id) FROM logs_auditoria
                 WHERE acao = ? AND criado_em >= NOW() - INTERVAL 7 DAY)              AS distintos_7d,
               (SELECT COUNT(*) FROM logs_auditoria
                 WHERE acao = ? AND criado_em >= CURDATE())                           AS logins_hoje"
        );
        $stmt->execute([self::ACAO_SUCESSO, self::ACAO_SUCESSO]);

        return self::inteiros($stmt->fetch(\PDO::FETCH_ASSOC) ?: []);
    }

    /** Sinais de fricção. */
    private static function problemas(\PDO $pdo): array
    {
        // Hora de pico dos logins — diz se o uso bate com o expediente
        $stmt = $pdo->prepare(
            "SELECT HOUR(criado_em) AS hora, COUNT(*) AS qtd
               FROM logs_auditoria
              WHERE acao = ? AND criado_em >= NOW() - INTERVAL ? DAY
              GROUP BY hora ORDER BY qtd DESC LIMIT 1"
        );
        $stmt->execute([self::ACAO_SUCESSO, self::JANELA_DIAS]);
        $pico = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        // Logins fora do horário comercial (antes das 7h ou a partir das 20h)
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM logs_auditoria
              WHERE acao = ? AND criado_em >= NOW() - INTERVAL ? DAY
                AND (HOUR(criado_em) < 7 OR HOUR(criado_em) >= 20)"
        );
        $stmt->execute([self::ACAO_SUCESSO, self::JANELA_DIAS]);

        return [
            'hora_pico'      => isset($pico['hora']) ? (int) $pico['hora'] : null,
            'hora_pico_qtd'  => (int) ($pico['qtd'] ?? 0),
            'fora_horario'   => (int) $stmt->fetchColumn(),
        ];
    }

    // ─────────────────────────────────────────
    // Listas
    // ─────────────────────────────────────────

    /** Quem mais entra no sistema. */
    private static function topAtivos(\PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            "SELECT u.nome, u.email, COUNT(*) AS qtd, MAX(l.criado_em) AS ultimo
               FROM logs_auditoria l
               JOIN usuarios u ON u.id = l.usuario_id
              WHERE l.acao = ? AND l.criado_em >= NOW() - INTERVAL ? DAY
              GROUP BY l.usuario_id, u.nome, u.email
              ORDER BY qtd DESC, ultimo DESC
              LIMIT " . self::TOP
        );
        $stmt->execute([self::ACAO_SUCESSO, self::JANELA_DIAS]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Quem mais erra a senha. */
    private static function topFalhas(\PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            "SELECT u.nome, u.email, COUNT(*) AS qtd, MAX(l.criado_em) AS ultimo
               FROM logs_auditoria l
               JOIN usuarios u ON u.id = l.usuario_id
              WHERE l.acao = ? AND l.criado_em >= NOW() - INTERVAL ? DAY
              GROUP BY l.usuario_id, u.nome, u.email
              ORDER BY qtd DESC, ultimo DESC
              LIMIT " . self::TOP
        );
        $stmt->execute([self::ACAO_FALHA, self::JANELA_DIAS]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Os travados de verdade: tentaram, erraram e NUNCA entraram.
     * Errar a senha e entrar em seguida é normal; nunca ter entrado não é.
     */
    private static function travados(\PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            "SELECT u.nome, u.email, COUNT(*) AS qtd, MAX(l.criado_em) AS ultimo
               FROM logs_auditoria l
               JOIN usuarios u ON u.id = l.usuario_id
              WHERE l.acao = ? AND u.ativo = 1 AND u.ultimo_acesso IS NULL
              GROUP BY l.usuario_id, u.nome, u.email
              ORDER BY qtd DESC, ultimo DESC
              LIMIT " . self::TOP
        );
        $stmt->execute([self::ACAO_FALHA]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Logins por dia — série pronta para a barrinha, sem buracos. */
    private static function curva(\PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            "SELECT DATE(criado_em) AS dia, COUNT(*) AS qtd
               FROM logs_auditoria
              WHERE acao = ? AND criado_em >= CURDATE() - INTERVAL ? DAY
              GROUP BY dia"
        );
        $stmt->execute([self::ACAO_SUCESSO, self::CURVA_DIAS - 1]);

        $porDia = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $porDia[(string) $row['dia']] = (int) $row['qtd'];
        }

        // Dias sem login somem do GROUP BY — o gráfico precisa deles como zero
        $serie = [];
        for ($i = self::CURVA_DIAS - 1; $i >= 0; $i--) {
            $dia = date('Y-m-d', strtotime("-{$i} days"));
            $serie[] = ['dia' => $dia, 'qtd' => $porDia[$dia] ?? 0];
        }

        return $serie;
    }

    /**
     * Distribuição por perfil (quem é o quê).
     *
     * `super_admin` é marca reservada e não aparece na tela: some dentro de
     * `admin`, como já faz UserPermission::perfilVisivel no resto do módulo —
     * um contador separado entregaria a existência do perfil.
     */
    private static function perfis(\PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT CASE WHEN perfil = 'super_admin' THEN 'admin' ELSE perfil END AS perfil,
                    COUNT(*) AS qtd
               FROM usuarios
              WHERE ativo = 1
              GROUP BY 1
              ORDER BY qtd DESC"
        );

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // ─────────────────────────────────────────

    /** COUNT do MySQL volta string no PDO; a view espera número. */
    private static function inteiros(array $linha): array
    {
        return array_map(static fn($v) => $v === null ? null : (int) $v, $linha);
    }
}
