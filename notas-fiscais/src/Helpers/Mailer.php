<?php
// @versao Sys_17 V_01_16/07/2026
declare(strict_types=1);

/**
 * Envio de e-mail — SMTP nativo (sem dependências) ou modo log (dev).
 *
 * Uso:
 *   Mailer::enviar('pessoa@exemplo.com', 'Assunto', '<p>corpo html</p>');
 *
 * Config em config/mail.php. No modo 'log', o e-mail é gravado em
 * storage/logs/emails.log em vez de ser enviado — comportamento padrão
 * até as credenciais SMTP serem preenchidas.
 */
class Mailer
{
    /**
     * Monta a config de e-mail: valores da tela Geral (nf_configuracoes)
     * têm precedência sobre config/mail.php (fallback para dev/instalação).
     */
    private static function config(): array
    {
        require_once __DIR__ . '/Settings.php';
        $arq = require dirname(__DIR__, 2) . '/config/mail.php';
        $s   = Settings::grupo('email');
        if (empty($s)) {
            return $arq; // tabela ainda não populada
        }
        return [
            'modo' => $s['email.modo'] ?? $arq['modo'],
            'smtp' => [
                'host'      => $s['email.host']      ?? $arq['smtp']['host'],
                'porta'     => (int) ($s['email.porta'] ?? $arq['smtp']['porta']),
                'seguranca' => $s['email.seguranca'] ?? $arq['smtp']['seguranca'],
                'usuario'   => $s['email.usuario']   ?? $arq['smtp']['usuario'],
                'senha'     => $s['email.senha']     ?? $arq['smtp']['senha'],
                'timeout'   => $arq['smtp']['timeout'] ?? 15,
            ],
            'remetente' => [
                'email' => $s['email.remetente_email'] ?? $arq['remetente']['email'],
                'nome'  => $s['email.remetente_nome']  ?? $arq['remetente']['nome'],
            ],
            'base_url' => $s['email.base_url'] ?? $arq['base_url'],
        ];
    }

    /** @return bool true se enviado (ou logado) com sucesso */
    public static function enviar(string $para, string $assunto, string $corpoHtml): bool
    {
        $cfg = self::config();

        if (($cfg['modo'] ?? 'log') !== 'smtp') {
            return self::logar($para, $assunto, $corpoHtml);
        }

        try {
            return self::enviarSmtp($cfg, $para, $assunto, $corpoHtml);
        } catch (Throwable $e) {
            error_log('[Mailer] Falha SMTP: ' . $e->getMessage());
            self::logar($para, $assunto, $corpoHtml, 'FALHA SMTP: ' . $e->getMessage());
            return false;
        }
    }

    private static function logar(string $para, string $assunto, string $corpo, string $obs = ''): bool
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $linha = sprintf(
            "[%s] PARA: %s | ASSUNTO: %s%s\n%s\n%s\n",
            date('Y-m-d H:i:s'), $para, $assunto,
            $obs !== '' ? " | {$obs}" : '',
            strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $corpo)),
            str_repeat('─', 70)
        );
        file_put_contents($dir . '/emails.log', $linha, FILE_APPEND | LOCK_EX);
        return true;
    }

    private static function enviarSmtp(array $cfg, string $para, string $assunto, string $corpoHtml): bool
    {
        $s = $cfg['smtp'];
        $host    = $s['seguranca'] === 'ssl' ? 'ssl://' . $s['host'] : $s['host'];
        $timeout = (int) ($s['timeout'] ?? 15);

        $fp = stream_socket_client("{$host}:{$s['porta']}", $errno, $errstr, $timeout);
        if (!$fp) {
            throw new RuntimeException("Conexão falhou: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, $timeout);

        $ler = function () use ($fp): string {
            $resp = '';
            while ($linha = fgets($fp, 515)) {
                $resp .= $linha;
                if (isset($linha[3]) && $linha[3] === ' ') break;
            }
            return $resp;
        };
        $cmd = function (string $c, array $esperado) use ($fp, $ler): string {
            fwrite($fp, $c . "\r\n");
            $r = $ler();
            $codigo = (int) substr($r, 0, 3);
            if (!in_array($codigo, $esperado, true)) {
                throw new RuntimeException("SMTP '{$c}' → {$r}");
            }
            return $r;
        };

        $ler(); // banner
        $cmd('EHLO ' . gethostname(), [250]);

        if ($s['seguranca'] === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Falha ao iniciar TLS');
            }
            $cmd('EHLO ' . gethostname(), [250]);
        }

        if (!empty($s['usuario'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($s['usuario']), [334]);
            $cmd(base64_encode($s['senha']), [235]);
        }

        $de = $cfg['remetente']['email'];
        $cmd("MAIL FROM:<{$de}>", [250]);
        $cmd("RCPT TO:<{$para}>", [250, 251]);
        $cmd('DATA', [354]);

        $nomeDe   = mb_encode_mimeheader($cfg['remetente']['nome'], 'UTF-8');
        $assuntoM = mb_encode_mimeheader($assunto, 'UTF-8');
        $corpo    = "From: {$nomeDe} <{$de}>\r\n"
                  . "To: <{$para}>\r\n"
                  . "Subject: {$assuntoM}\r\n"
                  . "MIME-Version: 1.0\r\n"
                  . "Content-Type: text/html; charset=UTF-8\r\n"
                  . "Content-Transfer-Encoding: 8bit\r\n"
                  . "\r\n"
                  . $corpoHtml . "\r\n.";
        $cmd($corpo, [250]);
        $cmd('QUIT', [221]);
        fclose($fp);
        return true;
    }
}
