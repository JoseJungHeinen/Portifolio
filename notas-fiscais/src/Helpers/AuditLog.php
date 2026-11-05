<?php
// @versao Sys_14 V_01_16/07/2026
declare(strict_types=1);

/**
 * Helper para registro de auditoria.
 *
 * Insere eventos na tabela `logs_auditoria` do sistema maior.
 * Não cria tabela própria — reutiliza a infraestrutura existente.
 *
 * Estrutura da tabela `logs_auditoria` (existente no banco compras):
 *   id              char(36)        UUID gerado aqui
 *   usuario_id      char(36)        FK suave → usuarios.id
 *   entidade_tipo   varchar(50)     Ex: 'nf_processo', 'nf_parcela', 'nf_template'
 *   entidade_id     char(36)        ID do registro impactado
 *   acao            varchar(100)    Ex: 'nf_processo.aprovado', 'nf_parcela.paga'
 *   payload_antes   JSON            Estado anterior (null se criação)
 *   payload_depois  JSON            Estado novo (null se exclusão)
 *   ip_address      varchar(45)     IP do cliente (suporta IPv6)
 *   user_agent      varchar(300)    User-Agent do navegador
 *   criado_em       timestamp       Preenchido pelo banco
 */
class AuditLog
{
    /**
     * Registra um evento de auditoria.
     *
     * @param array{
     *   usuario_id:     string,
     *   entidade_tipo:  string,
     *   entidade_id:    string,
     *   acao:           string,
     *   payload_antes?: string|null,
     *   payload_depois?: string|null,
     * } $data Dados do evento
     */
    public static function record(array $data): void
    {
        // ip_address e user_agent são completados automaticamente
        $ipAddress  = self::resolverIp();
        $userAgent  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO logs_auditoria
                (id, usuario_id, entidade_tipo, entidade_id, acao,
                 payload_antes, payload_depois, ip_address, user_agent)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            self::gerarUuid(),
            $data['usuario_id'],
            $data['entidade_tipo'],
            $data['entidade_id'],
            $data['acao'],
            $data['payload_antes'] ?? null,
            $data['payload_depois'] ?? null,
            $ipAddress,
            $userAgent,
        ]);
    }

    /**
     * Atalho para registrar evento com o usuário autenticado atual.
     * Não exige passar usuario_id manualmente.
     *
     * @param string      $entidadeTipo  Ex: 'nf_processo'
     * @param string      $entidadeId    UUID do registro impactado
     * @param string      $acao          Ex: 'nf_processo.aprovado'
     * @param mixed       $antes         Estado anterior (será convertido para JSON)
     * @param mixed       $depois        Estado novo (será convertido para JSON)
     */
    public static function registrar(
        string $entidadeTipo,
        string $entidadeId,
        string $acao,
        mixed $antes = null,
        mixed $depois = null
    ): void {
        self::record([
            'usuario_id'     => Auth::id(),
            'entidade_tipo'  => $entidadeTipo,
            'entidade_id'    => $entidadeId,
            'acao'           => $acao,
            'payload_antes'  => $antes !== null ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
            'payload_depois' => $depois !== null ? json_encode($depois, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /**
     * Resolve o IP real do cliente, considerando proxies reversos confiáveis.
     */
    private static function resolverIp(): string
    {
        // Verificar headers de proxy em ordem de confiabilidade
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $header) {
            if (!empty($_SERVER[$header])) {
                // X-Forwarded-For pode conter múltiplos IPs — pegar o primeiro
                $ip = explode(',', $_SERVER[$header])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    /**
     * Gera um UUID v4 sem dependências externas.
     */
    private static function gerarUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // versão 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variante RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
