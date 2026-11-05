<?php
// @versao Ver.002.301026

namespace App\Services;

use App\Models\Fornecedor;

/**
 * Lista de fornecedores (etapa 3, protótipo do Compras: vForn/filtraForn/ffOpts).
 * Funções puras sobre as linhas de Fornecedor::listaClassificacao(): filtros
 * combináveis com contagem, busca, ordenação e agrupamento matriz + filiais.
 */
class FornecedorListaService
{
    /** Valor de filtro "(não informado)". */
    public const VAZIO = '__vazio__';

    public const ORDENS = [
        'nome'    => 'Nome',
        'cidade'  => 'Cidade',
        'oc'      => 'Maior valor em OC (12 meses)',
        'recente' => 'Cadastrados por último',
    ];

    /** Grupos do filtro "Pendência / grupo" além das pendências do cadastro. */
    public const GRUPOS = [
        'filial' => 'Filiais',
        'com_oc' => 'Com OC nos últimos 12 meses',
    ];

    /** Filtros de campo: chave => [rótulo, getter]. */
    public static function campos(): array
    {
        return [
            'situacao' => ['Situação', fn(array $f) => $f['situacao'] ?? 'pendente', Fornecedor::SITUACOES],
            'regime'   => ['Regime tributário', fn(array $f) => $f['regime_tributario'] ?? null, Fornecedor::REGIMES],
            'cnd'      => ['CND Federal', fn(array $f) => $f['cnd_federal_situacao'] ?? null, Fornecedor::CND_SITUACOES],
            'uf'       => ['UF', fn(array $f) => $f['estado'] ?? null, null],
            'cidade'   => ['Cidade', fn(array $f) => $f['cidade'] ?? null, null],
        ];
    }

    public static function nome(array $f): string
    {
        return trim((string)($f['nome_fantasia'] ?? '')) !== '' ? (string)$f['nome_fantasia'] : (string)($f['razao_social'] ?? '');
    }

    /** minúsculas e sem acento, para a busca. */
    public static function normalizar(?string $s): string
    {
        $s = mb_strtolower((string)$s, 'UTF-8');
        return strtr($s, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    /** O fornecedor passa nos filtros? $ignora = filtro a desconsiderar (para contar as opções dele). */
    public static function passa(array $f, array $filtros, ?string $hoje = null, ?string $ignora = null): bool
    {
        foreach (self::campos() as $k => [, $get]) {
            $sel = (string)($filtros[$k] ?? '');
            if ($k === $ignora || $sel === '') continue;
            $v = (string)($get($f) ?? '');
            if ($sel === self::VAZIO ? $v !== '' : $v !== $sel) return false;
        }

        $pend = (string)($filtros['pend'] ?? '');
        if ($pend !== '' && $ignora !== 'pend' && !self::temPendencia($f, $pend, $hoje)) return false;

        $q = trim((string)($filtros['q'] ?? ''));
        if ($q !== '' && $ignora !== 'q') {
            $alvo = self::normalizar(implode(' ', [
                $f['razao_social'] ?? '', $f['nome_fantasia'] ?? '', $f['cnpj'] ?? '', $f['cidade'] ?? '',
                $f['estado'] ?? '', $f['email'] ?? '', $f['contato_fiscal'] ?? '',
            ]));
            $qn  = self::normalizar($q);
            $dig = preg_replace('/\D/', '', $q);
            $porDigitos = $dig !== '' && strlen($dig) >= 4 && preg_match('/^[\d.\/\-\s]+$/', $q)
                && str_contains((string)($f['cnpj'] ?? ''), $dig);
            if (!$porDigitos && !str_contains($alvo, $qn)) return false;
        }

        return true;
    }

    /** Pendência do cadastro ou grupo (filial, com OC). */
    public static function temPendencia(array $f, string $chave, ?string $hoje = null): bool
    {
        return match ($chave) {
            'filial' => !Fornecedor::ehMatriz($f['cnpj'] ?? '') && Fornecedor::cnpjRaiz($f['cnpj'] ?? '') !== '',
            'com_oc' => (float)($f['oc_12m'] ?? 0) > 0,
            default  => isset(Fornecedor::pendencias($f, $hoje)[$chave]),
        };
    }

    public static function filtrar(array $rows, array $filtros, ?string $hoje = null): array
    {
        return array_values(array_filter($rows, fn($f) => self::passa($f, $filtros, $hoje)));
    }

    /**
     * Opções de um filtro com contagem, considerando os outros filtros ativos.
     *
     * @return array<string, int> valor (ou VAZIO) => quantidade
     */
    public static function opcoes(array $rows, array $filtros, string $chave, callable $get, ?string $hoje = null): array
    {
        $cnt = [];
        foreach ($rows as $f) {
            if (!self::passa($f, $filtros, $hoje, $chave)) continue;
            $v = (string)($get($f) ?? '');
            $v = $v === '' ? self::VAZIO : $v;
            $cnt[$v] = ($cnt[$v] ?? 0) + 1;
        }
        uksort($cnt, fn($a, $b) => ($a === self::VAZIO) <=> ($b === self::VAZIO) ?: strcoll((string)$a, (string)$b));
        return $cnt;
    }

    /** Contagem de cada pendência/grupo, considerando os outros filtros. */
    public static function contagemPendencias(array $rows, array $filtros, ?string $hoje = null): array
    {
        $out = array_fill_keys(array_keys(Fornecedor::PENDENCIAS + self::GRUPOS), 0);
        foreach ($rows as $f) {
            if (!self::passa($f, $filtros, $hoje, 'pend')) continue;
            foreach (Fornecedor::pendencias($f, $hoje) as $k => $_) $out[$k]++;
            foreach (self::GRUPOS as $k => $_) if (self::temPendencia($f, $k, $hoje)) $out[$k]++;
        }
        return $out;
    }

    /**
     * Ordena e agrupa matriz + filiais (mesma raiz de CNPJ): o grupo fica na posição
     * do primeiro da ordenação; matriz primeiro, filiais abaixo.
     */
    public static function ordenarAgrupar(array $rows, string $ordem = 'nome'): array
    {
        $porNome = fn($a, $b) => strcoll(self::normalizar(self::nome($a)), self::normalizar(self::nome($b)));
        $cmp = match ($ordem) {
            'cidade'  => fn($a, $b) => strcoll(self::normalizar($a['cidade'] ?: '~'), self::normalizar($b['cidade'] ?: '~')) ?: $porNome($a, $b),
            'oc'      => fn($a, $b) => (float)($b['oc_12m'] ?? 0) <=> (float)($a['oc_12m'] ?? 0) ?: $porNome($a, $b),
            'recente' => fn($a, $b) => strcmp((string)($b['criado_em'] ?? ''), (string)($a['criado_em'] ?? '')) ?: $porNome($a, $b),
            default   => $porNome,
        };
        usort($rows, $cmp);

        $grupos = [];
        foreach ($rows as $i => $f) {
            $k = Fornecedor::cnpjRaiz($f['cnpj'] ?? '') ?: 'sem-cnpj:' . $i;
            $grupos[$k][] = $f;
        }
        $out = [];
        foreach ($grupos as $g) {
            usort($g, fn($a, $b) => Fornecedor::ehMatriz($b['cnpj'] ?? '') <=> Fornecedor::ehMatriz($a['cnpj'] ?? '')
                                    ?: strcmp((string)($a['cnpj'] ?? ''), (string)($b['cnpj'] ?? '')));
            array_push($out, ...$g);
        }
        return $out;
    }

    /** Linhas do CSV (cabeçalho incluso). */
    public static function linhasCsv(array $rows, ?string $hoje = null): array
    {
        $out = [['Razão social', 'Nome fantasia', 'CNPJ', 'Cidade', 'UF', 'E-mail', 'Telefone', 'Contato fiscal',
                 'Regime tributário', 'Regime confirmado em', 'CND Federal', 'Validade CND Federal', 'Situação',
                 'Pendências', 'OCs 12 meses (R$)', 'Última nota', 'Período']];
        foreach ($rows as $f) {
            $out[] = [
                $f['razao_social'] ?? '', $f['nome_fantasia'] ?? '', formatCnpj((string)($f['cnpj'] ?? '')),
                $f['cidade'] ?? '', $f['estado'] ?? '', $f['email'] ?? '', $f['telefone'] ?? '', $f['contato_fiscal'] ?? '',
                Fornecedor::REGIMES[$f['regime_tributario'] ?? ''] ?? '',
                !empty($f['regime_confirmado_em']) ? date_br($f['regime_confirmado_em']) : '',
                Fornecedor::CND_SITUACOES[$f['cnd_federal_situacao'] ?? ''] ?? '',
                !empty($f['cnd_federal_validade']) ? date_br($f['cnd_federal_validade']) : '',
                Fornecedor::SITUACOES[$f['situacao'] ?? 'pendente'] ?? '',
                implode('; ', Fornecedor::pendencias($f, $hoje)),
                number_format((float)($f['oc_12m'] ?? 0), 2, ',', '.'),
                isset($f['ultima_nota']) && $f['ultima_nota'] !== null ? Fornecedor::formatarNota((float)$f['ultima_nota']) : '',
                $f['ultimo_periodo'] ?? '',
            ];
        }
        return $out;
    }
}
