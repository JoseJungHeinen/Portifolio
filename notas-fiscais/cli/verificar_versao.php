<?php
// @versao Ger_05 V_01_16/07/2026
declare(strict_types=1);

/**
 * Verificador de versões para deploy — bloqueia regressão de arquivos.
 *
 * Autossuficiente (sem banco, sem includes) para rodar tanto na máquina local
 * quanto no servidor, inclusive contra pastas fora da raiz do sistema.
 *
 * Uso:
 *   php cli/verificar_versao.php --anotacao <pasta>
 *       Confere se TODO .php/.js/.css da pasta tem a marca @versao.
 *
 *   php cli/verificar_versao.php --comparar <pasta_nova> <pasta_atual>
 *       Compara cada arquivo da pasta nova com o equivalente na atual:
 *         BLOQUEIA se o novo não tem @versao;
 *         BLOQUEIA se a versão nova é MENOR que a atual;
 *         BLOQUEIA se a versão é IGUAL mas o conteúdo mudou (alterou sem subir versão);
 *         PERMITE  se o atual não tem versão (primeira adoção) ou se a nova é maior.
 *
 * Sai com código 0 (liberado) ou 1 (bloqueado) — use com && no deploy.
 */

const REGEX_VERSAO = '/@versao\s+([A-Za-z]+_\d+)\s+V_(\d+)_(\d{2}\/\d{2}\/\d{4})/';
const EXTS = ['php', 'js', 'css'];

function lerVersao(string $path): ?array
{
    $topo = @file_get_contents($path, false, null, 0, 2048);
    if ($topo === false || !preg_match(REGEX_VERSAO, $topo, $m)) return null;
    return ['codigo' => $m[1], 'versao' => (int) $m[2], 'data' => $m[3]];
}

/** @return list<string> caminhos relativos dos arquivos versionáveis */
function listarArquivos(string $raiz): array
{
    $raiz = rtrim($raiz, '/\\');
    $out  = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        $ext = strtolower($f->getExtension());
        if (!in_array($ext, EXTS, true)) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($raiz) + 1));
        if (str_contains('/' . $rel, '/vendor/')) continue;
        $out[] = $rel;
    }
    sort($out);
    return $out;
}

$modo = $argv[1] ?? '';

if ($modo === '--anotacao') {
    $pasta = rtrim($argv[2] ?? '', '/\\');
    if ($pasta === '' || !is_dir($pasta)) {
        fwrite(STDERR, "Uso: php cli/verificar_versao.php --anotacao <pasta>\n");
        exit(1);
    }
    $sem = [];
    foreach (listarArquivos($pasta) as $rel) {
        if (lerVersao($pasta . '/' . $rel) === null) $sem[] = $rel;
    }
    if ($sem) {
        echo "BLOQUEADO — arquivos SEM a marca @versao:\n";
        foreach ($sem as $s) echo "  ✗ $s\n";
        exit(1);
    }
    echo "LIBERADO — todos os arquivos têm @versao.\n";
    exit(0);
}

if ($modo === '--comparar') {
    $novo  = rtrim($argv[2] ?? '', '/\\');
    $atual = rtrim($argv[3] ?? '', '/\\');
    if ($novo === '' || $atual === '' || !is_dir($novo) || !is_dir($atual)) {
        fwrite(STDERR, "Uso: php cli/verificar_versao.php --comparar <pasta_nova> <pasta_atual>\n");
        exit(1);
    }

    $bloqueios = [];
    $liberados = 0;

    foreach (listarArquivos($novo) as $rel) {
        $vNovo = lerVersao($novo . '/' . $rel);
        if ($vNovo === null) {
            $bloqueios[] = "$rel — novo arquivo SEM @versao";
            continue;
        }

        $pathAtual = $atual . '/' . $rel;
        if (!is_file($pathAtual)) { $liberados++; continue; }          // arquivo inédito

        $vAtual = lerVersao($pathAtual);
        if ($vAtual === null)     { $liberados++; continue; }          // adoção: atual sem versão

        if ($vNovo['versao'] < $vAtual['versao']) {
            $bloqueios[] = sprintf('%s — REGRESSÃO: enviando V_%02d sobre V_%02d (%s)',
                $rel, $vNovo['versao'], $vAtual['versao'], $vAtual['codigo']);
            continue;
        }
        if ($vNovo['versao'] === $vAtual['versao']
            && md5_file($novo . '/' . $rel) !== md5_file($pathAtual)) {
            $bloqueios[] = sprintf('%s — conteúdo mudou mas a versão continua V_%02d: suba para V_%02d',
                $rel, $vNovo['versao'], $vNovo['versao'] + 1);
            continue;
        }
        $liberados++;
    }

    if ($bloqueios) {
        echo "BLOQUEADO — o deploy NÃO deve prosseguir:\n";
        foreach ($bloqueios as $b) echo "  ✗ $b\n";
        echo "Liberados: $liberados | Bloqueios: " . count($bloqueios) . "\n";
        exit(1);
    }
    echo "LIBERADO — $liberados arquivo(s) aptos a publicar (nenhuma regressão).\n";
    exit(0);
}

fwrite(STDERR, "Modo inválido. Use --anotacao <pasta> ou --comparar <pasta_nova> <pasta_atual>\n");
exit(1);
