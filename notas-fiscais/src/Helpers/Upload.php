<?php
// @versao Sys_21 V_04_11/09/2026
declare(strict_types=1);

/**
 * Helper de upload seguro de arquivos.
 *
 * Valida MIME real via finfo (magic bytes), extensão e tamanho.
 * Salva em storage/processos/{processoId}/ ou storage/test/ (processos de teste).
 */
class Upload
{
    /**
     * Salva um arquivo enviado via upload.
     *
     * @param array  $file       Entrada de $_FILES['campo']
     * @param string $processoId UUID do processo
     * @param bool   $isTeste    Se true, salva em storage/test/
     * @return array{nome_original: string, nome_armazenado: string, caminho: string, mime_type: string, tamanho_bytes: int}
     * @throws \RuntimeException em caso de erro de validação ou IO
     */
    public static function salvar(array $file, string $processoId, bool $isTeste = false): array
    {
        // Verificar erro de upload
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $msgs = [
                UPLOAD_ERR_INI_SIZE   => 'Arquivo excede o tamanho máximo do servidor.',
                UPLOAD_ERR_FORM_SIZE  => 'Arquivo excede o tamanho máximo do formulário.',
                UPLOAD_ERR_PARTIAL    => 'Upload incompleto.',
                UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo enviado.',
                UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário ausente.',
                UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar arquivo temporário.',
                UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensão PHP.',
            ];
            $codigo = $file['error'] ?? -1;
            throw new \RuntimeException($msgs[$codigo] ?? "Erro de upload desconhecido (código {$codigo}).");
        }

        // Verificar que é upload real (não caminho fabricado)
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Arquivo não é um upload HTTP legítimo.');
        }

        $tamanho      = (int) $file['size'];
        $nomeOriginal = basename($file['name']);
        $extOriginal  = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));

        if ($tamanho <= 0) {
            throw new \RuntimeException('Arquivo vazio.');
        }

        // Validar MIME via magic bytes (finfo), não via extensão ou $_FILES['type']
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeReal = $finfo->file($file['tmp_name']);

        if ($mimeReal === false || !in_array($mimeReal, ALLOWED_MIME_TYPES, true)) {
            // O motivo da recusa não sobrevive à tela: sem registrar aqui, um
            // arquivo rejeitado não deixa rastro nenhum para diagnóstico.
            error_log(sprintf(
                'Upload recusado: arquivo=%s extensao=%s mime_detectado=%s tamanho=%d',
                $nomeOriginal,
                $extOriginal,
                $mimeReal === false ? '(indetectavel)' : $mimeReal,
                $tamanho
            ));
            throw new \RuntimeException(self::mensagemTipoInvalido((string) $file['name'], $mimeReal));
        }

        // Validar extensão do nome original
        if (!in_array($extOriginal, ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException(
                "Extensão não permitida: .{$extOriginal}. "
                . "Extensões aceitas: " . implode(', ', ALLOWED_EXTENSIONS)
            );
        }

        // Vídeo/áudio: conteúdo e extensão têm de concordar.
        //
        // As duas listas acima são conferidas separadamente — nada impedia um
        // .mp4 renomeado para .pdf de passar nas duas. Enquanto vídeo não era
        // aceito isso era inofensivo; com o teto MENOR para vídeo/áudio, virou a
        // rota óbvia para burlá-lo: renomeie e o arquivo ganha 10 MB. Aqui as
        // duas respostas precisam ser a mesma, nos dois sentidos. Áudio segue a
        // mesma regra do vídeo — mesma categoria de anexo ("Áudio / Vídeo"),
        // mesmo teto.
        $mimeEhVideo = in_array($mimeReal, VIDEO_MIME_TYPES, true);
        $extEhVideo  = in_array($extOriginal, VIDEO_EXTENSIONS, true);
        $mimeEhAudio = in_array($mimeReal, AUDIO_MIME_TYPES, true);
        $extEhAudio  = in_array($extOriginal, AUDIO_EXTENSIONS, true);

        if ($mimeEhVideo !== $extEhVideo) {
            error_log(sprintf(
                'Upload recusado (vídeo incoerente): arquivo=%s extensao=%s mime=%s',
                $nomeOriginal, $extOriginal, (string) $mimeReal
            ));
            throw new \RuntimeException($mimeEhVideo
                ? "O arquivo \"{$nomeOriginal}\" é um vídeo, mas está com a extensão .{$extOriginal}. "
                . "Renomeie para a extensão correta (.mp4, .webm ou .mov) e anexe num tipo de anexo de vídeo."
                : "O arquivo \"{$nomeOriginal}\" tem extensão de vídeo, mas o conteúdo é {$mimeReal}. "
                . "Confira se anexou o arquivo certo."
            );
        }

        if ($mimeEhAudio !== $extEhAudio) {
            error_log(sprintf(
                'Upload recusado (áudio incoerente): arquivo=%s extensao=%s mime=%s',
                $nomeOriginal, $extOriginal, (string) $mimeReal
            ));
            throw new \RuntimeException($mimeEhAudio
                ? "O arquivo \"{$nomeOriginal}\" é um áudio, mas está com a extensão .{$extOriginal}. "
                . "Renomeie para a extensão correta (.ogg, .mp3, .m4a ou .wav) e anexe num tipo de anexo de áudio/vídeo."
                : "O arquivo \"{$nomeOriginal}\" tem extensão de áudio, mas o conteúdo é {$mimeReal}. "
                . "Confira se anexou o arquivo certo."
            );
        }

        // Tamanho — o teto sai do MIME REAL, não do nome do arquivo. Vídeo e
        // áudio têm teto próprio (5 MB) porque vídeo é a única coisa aqui que
        // cresce o storage em ordem de grandeza; áudio (nota de voz) fica na
        // mesma categoria de anexo e segue o mesmo teto.
        $mimeEhMidia = $mimeEhVideo || $mimeEhAudio;
        $maximo = $mimeEhMidia ? MAX_UPLOAD_SIZE_VIDEO : MAX_UPLOAD_SIZE;

        if ($tamanho > $maximo) {
            $limite = number_format($maximo / 1_048_576, 0) . 'MB';
            throw new \RuntimeException(
                $mimeEhMidia
                    ? ($mimeEhVideo ? "Vídeo excede o tamanho máximo permitido ({$limite}). "
                        . "Corte o trecho que importa ou reduza a qualidade antes de anexar."
                        : "Áudio excede o tamanho máximo permitido ({$limite}).")
                    : "Arquivo excede o tamanho máximo permitido ({$limite})."
            );
        }

        // Gerar nome armazenado seguro (UUID + extensão original)
        $nomeArmazenado = self::uuid() . '.' . $extOriginal;

        // Definir diretório de destino
        if ($isTeste) {
            $diretorio = STORAGE . '/test';
        } else {
            $diretorio = STORAGE . '/processos/' . $processoId;
        }

        // Criar diretório se não existir
        if (!is_dir($diretorio)) {
            if (!mkdir($diretorio, 0750, true)) {
                throw new \RuntimeException("Falha ao criar diretório de armazenamento.");
            }
        }

        $caminho = $diretorio . '/' . $nomeArmazenado;

        // Mover arquivo para destino final
        if (!move_uploaded_file($file['tmp_name'], $caminho)) {
            throw new \RuntimeException("Falha ao mover arquivo para armazenamento.");
        }

        // Retornar metadados
        return [
            'nome_original'   => $nomeOriginal,
            'nome_armazenado' => $nomeArmazenado,
            'caminho'         => $caminho,
            'mime_type'       => $mimeReal,
            'tamanho_bytes'   => $tamanho,
        ];
    }

    /**
     * Explica a recusa em vez de despejar a lista de MIME types.
     *
     * A distinção que importa para quem está na tela: extensão aceita mas
     * conteúdo irreconhecível quase nunca é "formato proibido" — é arquivo
     * quebrado na ORIGEM. O caso real que motivou isto foi uma NF-e cujo
     * emissor colou o cabeçalho HTTP antes do `%PDF-` (29 bytes de
     * "Content-type: application/pdf"): o leitor de PDF abre, porque tolera
     * lixo no início, mas o arquivo não é um PDF válido. A mensagem antiga
     * dizia "Tipo não permitido: application/octet-stream", que manda a pessoa
     * procurar o problema no lugar errado.
     *
     * @param string|false $mimeReal veredito do finfo
     */
    private static function mensagemTipoInvalido(string $nomeArquivo, $mimeReal): string
    {
        $nome    = basename($nomeArquivo);
        $ext     = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        $extOk   = in_array($ext, ALLOWED_EXTENSIONS, true);
        $aceitas = '.' . implode(', .', ALLOWED_EXTENSIONS);

        if (!$extOk) {
            return $ext === ''
                ? "O arquivo \"{$nome}\" não tem extensão. Envie um arquivo {$aceitas}."
                : "Extensão .{$ext} não é aceita. Envie um arquivo {$aceitas}.";
        }

        // Contêiner genérico do Office: o arquivo provavelmente está ÍNTEGRO,
        // o detector é que não abre a casca para confirmar o formato interno.
        // Dizer "gerado com defeito" aqui seria mentira — é um .xls/.doc velho.
        if (in_array($mimeReal, ['application/x-ole-storage', 'application/CDFV2', 'application/zip'], true)) {
            return "O arquivo \"{$nome}\" está num formato antigo do Office que o servidor não consegue "
                 . "confirmar. Abra o arquivo e salve novamente como .xlsx ou .docx (ou exporte em PDF) "
                 . "antes de anexar.";
        }

        // Conteúdo que o detector não classifica de jeito nenhum: aí sim o
        // arquivo costuma estar quebrado na origem.
        if ($mimeReal === false || $mimeReal === 'application/octet-stream') {
            return "O arquivo \"{$nome}\" tem extensão .{$ext}, mas o conteúdo não corresponde a esse formato "
                 . "— ele provavelmente foi gerado com defeito por quem o emitiu (é comum abrir normalmente no "
                 . "leitor e ainda assim estar quebrado). Peça o arquivo novamente, ou abra e use "
                 . "Imprimir → Salvar como PDF antes de anexar.";
        }

        return "O conteúdo do arquivo \"{$nome}\" é do tipo {$mimeReal}, que não está entre os aceitos. "
             . "A extensão .{$ext} não corresponde ao conteúdo real do arquivo.";
    }

    /**
     * Remove um arquivo físico com segurança.
     * Ignora silenciosamente se o arquivo não existir.
     */
    public static function excluir(string $caminho): void
    {
        // Verificar que o caminho está dentro do diretório de storage
        $realStorage = realpath(STORAGE);
        $realCaminho = realpath($caminho);

        if ($realStorage === false || $realCaminho === false) {
            return; // Arquivo não existe — nada a fazer
        }

        // Proteção de path traversal
        if (!str_starts_with($realCaminho, $realStorage)) {
            throw new \RuntimeException("Caminho fora do diretório de armazenamento permitido.");
        }

        if (is_file($realCaminho)) {
            @unlink($realCaminho);
        }
    }

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
}
