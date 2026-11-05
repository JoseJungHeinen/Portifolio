<?php
declare(strict_types=1);

if (!function_exists('doc_slug_chave_global')) {
    function doc_slug_chave_global(string $rotulo): string {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT', $rotulo);
        $s = preg_replace('/[^A-Za-z0-9]+/', '_', (string)$s);
        $s = preg_replace('/_+/', '_', (string)$s);
        $s = trim((string)$s, '_');
        $s = strtoupper((string)$s);
        if ($s === '') $s = 'CAMPO';
        return $s;
    }
}

if (!function_exists('doc_validar_chave_global')) {
    function doc_validar_chave_global(string $chave): bool {
        return (bool)preg_match('/^[A-Z0-9_]{2,120}$/', $chave);
    }
}
