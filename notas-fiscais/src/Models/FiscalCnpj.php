<?php
// @versao Fis_07 V_01_26/08/2026
declare(strict_types=1);

/**
 * Situação na Receita dos emitentes que aparecem na tela Fiscal.
 *
 * Duas fontes, nesta ordem:
 *   1. `fornecedores` — o cadastro do sistema (compartilhado com o compras).
 *      Tem precedência: é o dado que o pessoal mantém.
 *   2. `nf_fiscal_cnpj` — cache só para emitente que NÃO está no cadastro.
 *
 * A consulta ao cnpj.ws grava de volta na fonte certa: quem já está em
 * `fornecedores` tem as colunas preenchidas lá (é o mesmo que o botão
 * "Buscar dados na Receita" da tela de Fornecedores faz); quem não está vai
 * para o cache, para não criar fornecedor fantasma na tabela compartilhada.
 */
class FiscalCnpj
{
    /**
     * Situação de cada CNPJ pedido.
     *
     * @param list<string> $cnpjs
     * @return array<string, array{razao_social:?string, situacao:?string,
     *                             simples:?int, mei:?int, ativo_cadastro:?int,
     *                             origem:string, erro:?string}>
     *         origem: 'cadastro' | 'cache' | 'ausente'
     */
    public static function mapa(array $cnpjs): array
    {
        $cnpjs = array_values(array_unique(array_filter(
            array_map(fn($c) => (string) preg_replace('/\D/', '', (string) $c), $cnpjs),
            fn($c) => $c !== ''
        )));
        if ($cnpjs === []) return [];

        $pdo = Database::getInstance();
        $ph  = implode(',', array_fill(0, count($cnpjs), '?'));
        $out = [];

        // 2ª fonte primeiro: o cadastro sobrescreve depois, garantindo a ordem
        // de precedência sem um IF por linha.
        $stmt = $pdo->prepare(
            "SELECT cnpj, razao_social, situacao_cadastral, optante_simples, optante_mei, erro
               FROM nf_fiscal_cnpj WHERE cnpj IN ({$ph})"
        );
        $stmt->execute($cnpjs);
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['cnpj']] = [
                'razao_social'   => $r['razao_social'],
                'situacao'       => $r['situacao_cadastral'],
                'simples'        => $r['optante_simples'] === null ? null : (int) $r['optante_simples'],
                'mei'            => $r['optante_mei'] === null ? null : (int) $r['optante_mei'],
                'ativo_cadastro' => null,
                'origem'         => 'cache',
                'erro'           => $r['erro'],
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT cnpj, razao_social, situacao_cadastral, optante_simples, optante_mei, ativo
               FROM fornecedores WHERE cnpj IN ({$ph})"
        );
        $stmt->execute($cnpjs);
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['cnpj']] = [
                'razao_social'   => $r['razao_social'],
                'situacao'       => $r['situacao_cadastral'],
                'simples'        => $r['optante_simples'] === null ? null : (int) $r['optante_simples'],
                'mei'            => $r['optante_mei'] === null ? null : (int) $r['optante_mei'],
                'ativo_cadastro' => (int) $r['ativo'],
                'origem'         => 'cadastro',
                'erro'           => null,
            ];
        }

        return $out;
    }

    /**
     * CNPJ ainda sem situação — o que a consulta precisa percorrer.
     *
     * Inclui tanto quem não tem linha em lugar nenhum quanto quem está no
     * cadastro com `situacao_cadastral` vazia (a maioria, hoje). Fica de
     * fora o que já falhou na consulta: com 20s por chamada, insistir num
     * CNPJ que a Receita não devolve custa caro. `$comErro = true` traz os
     * falhados de volta, para uma nova rodada deliberada.
     *
     * @param list<string> $cnpjs universo a checar (os do período filtrado)
     * @return list<string>
     */
    public static function pendentes(array $cnpjs, bool $comErro = false): array
    {
        $mapa      = self::mapa($cnpjs);
        $pendentes = [];

        foreach ($cnpjs as $cnpj) {
            $cnpj = (string) preg_replace('/\D/', '', (string) $cnpj);
            // Pessoa física (CPF no lugar do CNPJ) não tem consulta na API.
            if (strlen($cnpj) !== 14) continue;

            $info = $mapa[$cnpj] ?? null;
            if ($info === null) { $pendentes[] = $cnpj; continue; }
            if (($info['situacao'] ?? '') !== '') continue;
            if (!$comErro && ($info['erro'] ?? null) !== null) continue;

            $pendentes[] = $cnpj;
        }

        return array_values(array_unique($pendentes));
    }

    /**
     * Grava o resultado da consulta na fonte certa.
     * Devolve 'cadastro' ou 'cache', conforme onde foi parar.
     */
    public static function gravar(string $cnpj, array $d): string
    {
        $cnpj = (string) preg_replace('/\D/', '', $cnpj);
        $pdo  = Database::getInstance();

        $stmt = $pdo->prepare('SELECT id FROM fornecedores WHERE cnpj = ? LIMIT 1');
        $stmt->execute([$cnpj]);
        $fornecedorId = $stmt->fetchColumn();

        if ($fornecedorId !== false) {
            // Está no cadastro: completa as colunas que já existem lá. Razão
            // social NÃO é tocada — quem cadastrou pode ter ajustado o nome
            // de propósito, e não é isso que a tela Fiscal veio resolver.
            $up = $pdo->prepare(
                'UPDATE fornecedores
                    SET situacao_cadastral = ?, optante_simples = ?, optante_mei = ?
                  WHERE id = ?'
            );
            $up->execute([
                $d['situacao_cadastral'] ?: null,
                $d['optante_simples'], $d['optante_mei'], $fornecedorId,
            ]);
            return 'cadastro';
        }

        $ins = $pdo->prepare(
            'INSERT INTO nf_fiscal_cnpj
               (cnpj, razao_social, situacao_cadastral, optante_simples, optante_mei, consultado_em, erro)
             VALUES (?, ?, ?, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE
               razao_social = VALUES(razao_social),
               situacao_cadastral = VALUES(situacao_cadastral),
               optante_simples = VALUES(optante_simples),
               optante_mei = VALUES(optante_mei),
               consultado_em = VALUES(consultado_em),
               erro = NULL'
        );
        $ins->execute([
            $cnpj,
            $d['razao_social'] ?: null,
            $d['situacao_cadastral'] ?: null,
            $d['optante_simples'], $d['optante_mei'],
            date('Y-m-d H:i:s'),
        ]);
        return 'cache';
    }

    /**
     * Carimba a falha para o CNPJ sair da fila de pendentes.
     * Sempre no cache, mesmo que o CNPJ exista no cadastro: `fornecedores`
     * é tabela do compras e não tem onde guardar erro de consulta.
     */
    public static function registrarErro(string $cnpj, string $erro): void
    {
        $cnpj = (string) preg_replace('/\D/', '', $cnpj);
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO nf_fiscal_cnpj (cnpj, consultado_em, erro)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE consultado_em = VALUES(consultado_em), erro = VALUES(erro)'
        );
        $stmt->execute([$cnpj, date('Y-m-d H:i:s'), mb_substr($erro, 0, 160)]);
    }

    /**
     * Rótulo de tela a partir da linha do mapa.
     *
     * @return array{texto:string, bg:string, fg:string, detalhe:string, titulo:string}
     */
    public static function badge(?array $info): array
    {
        if ($info === null || ($info['situacao'] ?? '') === '') {
            $titulo = $info['erro'] ?? 'Situação ainda não consultada na Receita';
            return ['texto' => '—', 'bg' => 'transparent', 'fg' => '#888888',
                    'detalhe' => '', 'titulo' => $titulo];
        }

        $situacao = (string) $info['situacao'];
        // A API devolve "Ativa", "Baixada", "Suspensa", "Inapta", "Nula".
        [$bg, $fg] = match (mb_strtolower($situacao)) {
            'ativa'            => ['#D8F5E5', '#00A960'],
            'baixada', 'nula'  => ['#FFE8EE', '#B3261E'],
            'suspensa', 'inapta' => ['#FFF3E0', '#FF8A2D'],
            default            => ['#EAEAEA', '#888888'],
        };

        $regimes = [];
        if (($info['mei'] ?? null) === 1)     $regimes[] = 'MEI';
        if (($info['simples'] ?? null) === 1) $regimes[] = 'Simples';

        // MEI é sempre Simples: mostrar os dois é ruído. Fica só o mais específico.
        if (in_array('MEI', $regimes, true)) {
            $regimes = ['Simples', 'MEI'];
        }

        return [
            'texto'   => mb_strtoupper($situacao),
            'bg'      => $bg,
            'fg'      => $fg,
            'detalhe' => implode(' · ', $regimes),
            'titulo'  => 'Fonte: ' . (($info['origem'] ?? '') === 'cadastro'
                          ? 'cadastro de fornecedores' : 'consulta cnpj.ws'),
        ];
    }
}
