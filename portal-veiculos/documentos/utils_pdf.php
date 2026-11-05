<?php

use PhpOffice\PhpWord\Settings;

if (!function_exists('documentos_habilitar_escape_phpword')) {
    function documentos_habilitar_escape_phpword(): void
    {
        if (class_exists(Settings::class)) {
            Settings::setOutputEscapingEnabled(true);
        }
    }
}

if (!function_exists('documentos_localizar_libreoffice_windows')) {
    function documentos_localizar_libreoffice_windows(): ?string
    {
        $candidatos = [
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
        ];

        foreach ($candidatos as $caminho) {
            if (is_file($caminho)) {
                return $caminho;
            }
        }

        $saida = [];
        $ret = 0;
        @exec('where soffice 2>NUL', $saida, $ret);
        if ($ret === 0 && !empty($saida[0]) && is_file(trim($saida[0]))) {
            return trim($saida[0]);
        }

        return null;
    }
}

if (!function_exists('documentos_validar_xml_string')) {
    function documentos_validar_xml_string(string $xml, string $parte): void
    {
        $dom = new DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $carregado = $dom->loadXML($xml, LIBXML_NONET);
        $erros = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        if ($carregado) {
            return;
        }

        $mensagens = [];
        foreach ($erros as $erro) {
            $mensagens[] = trim($erro->message) . " (linha {$erro->line}, coluna {$erro->column})";
        }

        $detalhe = $mensagens ? implode('; ', $mensagens) : 'XML inválido.';
        throw new Exception("DOCX gerado inválido na parte {$parte}: {$detalhe}");
    }
}

if (!function_exists('documentos_validar_docx_gerado')) {
    function documentos_validar_docx_gerado(string $docxPath): void
    {
        if (!is_file($docxPath)) {
            throw new Exception("DOCX não encontrado para validação: {$docxPath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            throw new Exception("Não foi possível abrir o DOCX gerado para validação: {$docxPath}");
        }

        $partes = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat) || empty($stat['name'])) {
                continue;
            }

            $nome = (string)$stat['name'];
            if (!preg_match('#^word/(document|header\d+|footer\d+)\.xml$#', $nome)) {
                continue;
            }

            $partes[] = $nome;
        }

        if (empty($partes)) {
            $zip->close();
            throw new Exception('O DOCX gerado não contém as partes XML esperadas para validação.');
        }

        foreach ($partes as $parte) {
            $xml = $zip->getFromName($parte);
            if ($xml === false) {
                $zip->close();
                throw new Exception("Não foi possível ler {$parte} dentro do DOCX gerado.");
            }

            try {
                documentos_validar_xml_string($xml, $parte);
            } catch (Throwable $e) {
                $zip->close();
                throw $e;
            }
        }

        $zip->close();
    }
}
