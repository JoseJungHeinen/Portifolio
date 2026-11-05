<?php
// @versao Ger_04 V_07_17/09/2026
declare(strict_types=1);

/**
 * Leitor do cabeçalho de versão dos arquivos do sistema.
 *
 * Todo arquivo versionado carrega, nas primeiras linhas, a marca:
 *   @versao <Prefixo>_<Seq> V_<NN>_<dd/mm/aaaa>
 *   ex.: @versao Cap_01 V_01_16/07/2026
 *
 * Prefixo = módulo/tela · Seq 01 = tela principal, 02+ = arquivos internos
 * (controller, model, sync, css/js...). Usado pela aba "Páginas e Arquivos"
 * (Geral) e pelo verificador de deploy (cli/verificar_versao.php).
 */
class FileVersion
{
    /** Nomenclatura aberta: prefixo → nome da tela/módulo. */
    public const MODULOS = [
        'Cap'  => 'Notas Captura',
        'Pre'  => 'Pré-fluxo',
        'Proc' => 'Processos',
        'Dash' => 'Dashboard',
        'Tpl'  => 'Templates',
        'Fin'  => 'Financeiro',
        'Fis'  => 'Fiscal (IBS/CBS)',
        'Rel'  => 'Relatórios',
        'Imp'  => 'Importação de PDF',
        'Tab'  => 'Tabelas',
        'Tar'  => 'Tarefas',
        'Not'  => 'Notificações',
        'Ace'  => 'Acessos',
        'Log'  => 'Login / Autenticação',
        'Ini'  => 'Tela Inicial (boas-vindas)',
        'Grp'  => 'Grupos',
        'Cta'  => 'Conta / Perfil',
        'Ger'  => 'Configurações Gerais',
        'Apo'  => 'Apollo (consulta de chave)',
        'Asg'  => 'Assinatura eletrônica',
        'Fat'  => 'Faturamento (protótipo)',
        'Sys'  => 'Base do sistema (compartilhado)',
    ];

    /** Pastas varridas e extensões consideradas. */
    private const DIRS = ['views', 'src', 'cli', 'public'];
    private const EXTS = ['php', 'js', 'css'];
    private const IGNORAR = ['/vendor/', '/fonts/'];

    private const REGEX = '/@versao\s+([A-Za-z]+)_(\d+)\s+V_(\d+)_(\d{2}\/\d{2}\/\d{4})/';

    /**
     * Lê a marca de versão de um arquivo (primeiros 2 KB).
     *
     * @return array{codigo:string, prefixo:string, seq:int, versao:int, data:string}|null
     */
    public static function parseArquivo(string $path): ?array
    {
        $topo = @file_get_contents($path, false, null, 0, 2048);
        if ($topo === false || !preg_match(self::REGEX, $topo, $m)) {
            return null;
        }
        return [
            'codigo'  => $m[1] . '_' . $m[2],
            'prefixo' => $m[1],
            'seq'     => (int) $m[2],
            'versao'  => (int) $m[3],
            'data'    => $m[4],
        ];
    }

    /**
     * Varre a árvore do sistema e devolve todos os arquivos com (ou sem) versão.
     *
     * @return list<array{arquivo:string, tipo:string, codigo:?string, prefixo:?string,
     *                    seq:?int, versao:?int, data:?string, modulo:?string}>
     */
    public static function scan(?string $raiz = null): array
    {
        $raiz = rtrim($raiz ?? (defined('ROOT') ? ROOT : dirname(__DIR__, 2)), '/\\');
        $out  = [];

        foreach (self::DIRS as $dir) {
            $base = $raiz . '/' . $dir;
            if (!is_dir($base)) continue;

            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                /** @var SplFileInfo $f */
                $ext = strtolower($f->getExtension());
                if (!in_array($ext, self::EXTS, true)) continue;

                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($raiz) + 1));
                foreach (self::IGNORAR as $ig) {
                    if (str_contains('/' . $rel, $ig)) continue 2;
                }

                $v = self::parseArquivo($f->getPathname());
                if (str_starts_with($rel, 'views/'))  { $tipo = 'Tela'; }
                elseif ($ext === 'php')               { $tipo = 'Interno'; }
                else                                  { $tipo = 'Asset'; }
                $out[] = [
                    'arquivo' => $rel,
                    'tipo'    => $tipo,
                    'codigo'  => $v['codigo']  ?? null,
                    'prefixo' => $v['prefixo'] ?? null,
                    'seq'     => $v['seq']     ?? null,
                    'versao'  => $v['versao']  ?? null,
                    'data'    => $v['data']    ?? null,
                    'modulo'  => $v ? (self::MODULOS[$v['prefixo']] ?? $v['prefixo']) : null,
                ];
            }
        }

        // Ordena: módulos na ordem do mapa, depois sequência; sem versão no fim.
        $ordem = array_flip(array_keys(self::MODULOS));
        usort($out, function ($a, $b) use ($ordem) {
            $pa = $a['prefixo'] !== null ? ($ordem[$a['prefixo']] ?? 900) : 999;
            $pb = $b['prefixo'] !== null ? ($ordem[$b['prefixo']] ?? 900) : 999;
            return [$pa, $a['seq'] ?? 999, $a['arquivo']] <=> [$pb, $b['seq'] ?? 999, $b['arquivo']];
        });

        return $out;
    }
}
