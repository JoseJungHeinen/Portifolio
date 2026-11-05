<?php
// @versao Ger_10 V_02_07/10/2026
declare(strict_types=1);

/**
 * Leitura/validação de certificado .pfx/.p12 — abre o arquivo com a senha
 * informada (openssl_pkcs12_read) pra confirmar que a senha cadastrada
 * realmente é a certa, e aproveita pra extrair a validade sem precisar
 * instalar o certificado em lugar nenhum.
 */
class CertificadoPfx
{
    /**
     * Reexporta um .pfx com criptografia legada (RC2-40/3DES) no formato
     * padrão do OpenSSL 3 (AES-256). Usa o binário `openssl` com -legacy só
     * para LER; a senha vai por variável de ambiente (não aparece no ps) e a
     * chave privada nunca toca o disco em claro. Sobrescreve $path em caso de
     * sucesso. Devolve false se não deu (sem binário, senha errada, etc.).
     */
    private static function converterLegado(string $path, string $conteudo, string $senha): bool
    {
        if (!function_exists('proc_open') || !function_exists('openssl_pkcs12_export')) {
            return false;
        }

        $bins = ['openssl'];
        foreach (['C:\\xampp\\apache\\bin\\openssl.exe', '/usr/bin/openssl', '/usr/local/bin/openssl'] as $b) {
            if (is_file($b)) $bins[] = $b;
        }

        $pem = null;
        foreach ($bins as $bin) {
            foreach (['-legacy ', ''] as $flag) {
                $cmd = escapeshellarg($bin) . ' pkcs12 ' . $flag . '-nodes -passin env:PFX_SENHA';
                $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
                    null, ['PFX_SENHA' => $senha] + (getenv() ?: []));
                if (!is_resource($proc)) continue 2;
                fwrite($pipes[0], $conteudo);
                fclose($pipes[0]);
                $out = stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $rc = proc_close($proc);
                if ($rc === 0 && is_string($out) && strpos($out, 'PRIVATE KEY') !== false) {
                    $pem = $out;
                    break 2;
                }
            }
        }
        if ($pem === null) return false;

        if (!preg_match('/-----BEGIN (?:ENCRYPTED |RSA |EC )?PRIVATE KEY-----.*?-----END (?:ENCRYPTED |RSA |EC )?PRIVATE KEY-----/s', $pem, $mk)) {
            return false;
        }
        $pkey = openssl_pkey_get_private($mk[0]);
        if ($pkey === false) return false;

        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $mc);
        $leaf = null;
        $extras = [];
        foreach ($mc[0] as $c) {
            if ($leaf === null && openssl_x509_check_private_key($c, $pkey)) {
                $leaf = $c;
            } else {
                $extras[] = $c;
            }
        }
        if ($leaf === null) return false;

        $novo = '';
        if (!openssl_pkcs12_export($leaf, $novo, $pkey, $senha, $extras ? ['extracerts' => $extras] : [])) {
            return false;
        }

        // Só troca o arquivo se o novo realmente abre com o OpenSSL do PHP.
        $teste = [];
        if (!openssl_pkcs12_read($novo, $teste, $senha)) return false;

        return @file_put_contents($path, $novo) !== false;
    }

    /**
     * @return array{ok:bool, erro:?string, validade:?string, cn:?string}
     *   validade em 'Y-m-d H:i:s' (horário do certificado, como o OpenSSL entrega).
     */
    public static function inspecionar(string $path, string $senha): array
    {
        $vazio = ['ok' => false, 'erro' => null, 'validade' => null, 'cn' => null];

        if (!function_exists('openssl_pkcs12_read')) {
            return ['ok' => false, 'erro' => 'Extensão OpenSSL do PHP não está habilitada.'] + $vazio;
        }
        if (!is_file($path)) {
            return ['ok' => false, 'erro' => 'Arquivo do certificado não encontrado no servidor.'] + $vazio;
        }

        $conteudo = @file_get_contents($path);
        if ($conteudo === false || $conteudo === '') {
            return ['ok' => false, 'erro' => 'Falha ao ler o arquivo do certificado.'] + $vazio;
        }

        while (openssl_error_string() !== false) {
            // limpa a fila de erros do OpenSSL pra não confundir com erro de outra chamada
        }

        $certs = [];
        if (!openssl_pkcs12_read($conteudo, $certs, $senha)) {
            $detalhes = [];
            while (($e = openssl_error_string()) !== false) {
                $detalhes[] = $e;
            }
            $detalhe = implode(' | ', $detalhes);

            // OpenSSL 3 desabilita RC2-40/3DES por padrão: o .pfx antigo "não abre"
            // mesmo com a senha certa. Não é senha errada.
            if (stripos($detalhe, 'unsupported') !== false || stripos($detalhe, '0308010C') !== false) {
                // Tenta converter sozinho (CLI openssl -legacy) e reabrir.
                if (self::converterLegado($path, $conteudo, $senha)) {
                    return self::inspecionar($path, $senha);
                }
                $msg = 'O .pfx usa criptografia legada (RC2/3DES) não suportada pelo OpenSSL 3 deste servidor — a senha pode estar certa. Reexporte o certificado com AES-256 ou converta com "openssl pkcs12 -legacy".';
            } else {
                $msg = 'Senha do certificado incorreta (ou arquivo .pfx inválido/corrompido).';
            }
            if ($detalhe !== '') {
                $msg .= ' [OpenSSL: ' . $detalhe . ']';
            }
            return ['ok' => false, 'erro' => $msg] + $vazio;
        }

        $info = openssl_x509_parse($certs['cert'] ?? '');
        if ($info === false) {
            return ['ok' => true, 'erro' => null, 'validade' => null, 'cn' => null];
        }

        return [
            'ok'       => true,
            'erro'     => null,
            'validade' => isset($info['validTo_time_t']) ? date('Y-m-d H:i:s', $info['validTo_time_t']) : null,
            'cn'       => $info['subject']['CN'] ?? null,
        ];
    }
}
