<?php
// @versao Sys_17 V_01_16/07/2026
declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * Envio de e-mail — SMTP nativo (sem dependências) ou modo log (dev).
 *
 * Uso:
 *   Mailer::enviar('pessoa@exemplo.com', 'Assunto', '<p>corpo html</p>');
 *
 * Config em variáveis MAIL_* do .env. No modo 'log', o e-mail é gravado em
 * storage/logs/emails.log em vez de ser enviado — comportamento padrão
 * até as credenciais SMTP serem preenchidas.
 */
class Mailer
{
    /** Configuração vinda do .env (MAIL_MODE=log grava em storage/logs/emails.log em vez de enviar). */
    private static function config(): array
    {
        return [
            'modo' => strtolower(trim((string)($_ENV['MAIL_MODE'] ?? 'log'))),
            'smtp' => [
                'host'      => $_ENV['MAIL_HOST'] ?? '',
                'porta'     => (int)($_ENV['MAIL_PORT'] ?? 587),
                'seguranca' => strtolower(trim((string)($_ENV['MAIL_SECURITY'] ?? 'tls'))),
                'usuario'   => $_ENV['MAIL_USERNAME'] ?? '',
                'senha'     => $_ENV['MAIL_PASSWORD'] ?? '',
                'timeout'   => 15,
            ],
            'remetente' => [
                'email' => $_ENV['MAIL_FROM_ADDRESS'] ?? '',
                'nome'  => $_ENV['MAIL_FROM_NAME'] ?? 'Exemplo OFP',
            ],
            'base_url' => $_ENV['APP_URL'] ?? '',
        ];
    }

    /** @return bool true se enviado (ou logado) com sucesso */
    public static function enviar(string $para, string $assunto, string $corpoHtml): bool
    {
        if (preg_match('/[\r\n]/', $para . $assunto)) {
            return false; // bloqueia injeção de cabeçalhos
        }
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
        return file_put_contents($dir . '/emails.log', $linha, FILE_APPEND | LOCK_EX) !== false;
    }

    private static function enviarSmtp(array $cfg, string $para, string $assunto, string $corpoHtml): bool
    {
        $s = $cfg['smtp'];
        if (!in_array($s['seguranca'], ['tls', 'ssl'], true)) {
            throw new RuntimeException('MAIL_SECURITY deve ser tls ou ssl (envio sem criptografia não é permitido)');
        }
        $host    = $s['seguranca'] === 'ssl' ? 'ssl://' . $s['host'] : $s['host'];
        $timeout = (int) ($s['timeout'] ?? 15);

        $fp = stream_socket_client("{$host}:{$s['porta']}", $errno, $errstr, $timeout);
        if (!$fp) {
            throw new RuntimeException("Conexão falhou: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, $timeout);

        try {
            $ler = function () use ($fp): string {
                $resp = '';
                while (($linha = fgets($fp)) !== false) {
                    $resp .= $linha;
                    if (strlen($linha) < 4 || $linha[3] !== '-') break;
                }
                return $resp;
            };
            $cmd = function (string $c, array $esperado) use ($fp, $ler): string {
                fwrite($fp, $c . "\r\n");
                $r = $ler();
                $codigo = (int) substr($r, 0, 3);
                if (!in_array($codigo, $esperado, true)) {
                    // não inclui o comando: pode conter usuário/senha em base64
                    throw new RuntimeException("SMTP → {$r}");
                }
                return $r;
            };

            if ((int) substr($ler(), 0, 3) !== 220) {
                throw new RuntimeException('SMTP: banner inesperado');
            }
            $cmd('EHLO ' . gethostname(), [250]);

            if ($s['seguranca'] === 'tls') {
                $cmd('STARTTLS', [220]);
                $metodo = 0;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $metodo |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $metodo |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if ($metodo === 0 || stream_socket_enable_crypto($fp, true, $metodo) !== true) {
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
            $assuntoM = preg_match('/[^\x20-\x7E]/', $assunto)
                ? mb_encode_mimeheader($assunto, 'UTF-8', 'B', "\r\n")
                : $assunto;
            $dominio  = substr(strrchr($de, '@') ?: '@localhost', 1);
            $corpo    = "From: {$nomeDe} <{$de}>\r\n"
                      . "To: <{$para}>\r\n"
                      . "Subject: {$assuntoM}\r\n"
                      . 'Date: ' . date('r') . "\r\n"
                      . 'Message-ID: <' . bin2hex(random_bytes(16)) . "@{$dominio}>\r\n"
                      . "MIME-Version: 1.0\r\n"
                      . "Content-Type: text/html; charset=UTF-8\r\n"
                      . "Content-Transfer-Encoding: base64\r\n"
                      . "\r\n"
                      . rtrim(chunk_split(base64_encode($corpoHtml), 76, "\r\n")) . "\r\n.";
            $cmd($corpo, [250]);
            return true;
        } finally {
            try {
                fwrite($fp, "QUIT\r\n");
            } catch (Throwable) {
            }
            fclose($fp);
        }
    }
}
