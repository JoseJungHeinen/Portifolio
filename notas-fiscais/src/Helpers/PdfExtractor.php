<?php
// @versao Imp_02 V_03_11/08/2026
declare(strict_types=1);

/**
 * Leitura de PDF fiscal — extrai os campos que abrem um processo.
 *
 * Usa o `pdftotext` (poppler-utils) para tirar o texto preservando o layout, e
 * aplica regras por TIPO DE DOCUMENTO, não por fornecedor:
 *
 *   • DANFSe (NFS-e padrão nacional) — layout padronizado em todo o país, então
 *     a mesma regra vale para qualquer município. Atenção à inversão de papéis:
 *     o EMITENTE/prestador é o fornecedor e o TOMADOR é a nossa filial.
 *     A v2.0 (a da reforma, com IBS/CBS) renomeou os blocos para
 *     "PRESTADOR / FORNECEDOR" e "TOMADOR / ADQUIRENTE" e passou os rótulos a
 *     MAIÚSCULAS — por isso todo rótulo aqui é comparado sem diferenciar caixa.
 *   • DANFE (NF-e) — reconhecido, mas o valor total costuma cair fora do
 *     alcance do rótulo por causa da grade; o operador confere na tela.
 *   • Genérico (faturas) — sem padrão nacional: pega CNPJs na ordem, datas e
 *     valores por rótulos comuns. É onde a conferência humana mais importa.
 *
 * NADA aqui decide sozinho: o resultado vai para a tela de conferência, onde
 * cada campo é editável antes de virar processo.
 *
 * PDF só de imagem (escaneado) não tem texto — devolve erro explícito em vez de
 * inventar. Reconhecimento óptico ficaria caro e impreciso justamente no campo
 * que mais importa, o valor.
 */
class PdfExtractor
{
    /** Caminhos prováveis do binário, na ordem de preferência. */
    private const BINARIOS = ['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', 'pdftotext'];

    /** Mínimo de caracteres para considerar que o PDF tem texto de verdade. */
    private const MIN_TEXTO = 80;

    /**
     * @return array{
     *   ok:bool, erro:?string, tipo:string, texto:string,
     *   fornecedor_cnpj:?string, fornecedor_nome:?string,
     *   filial_cnpj:?string, numero:?string, data:?string,
     *   valor:?float, chave:?string, vencimento:?string
     * }
     */
    public static function extrair(string $caminho): array
    {
        $base = [
            'ok' => false, 'erro' => null, 'tipo' => 'desconhecido', 'texto' => '',
            'fornecedor_cnpj' => null, 'fornecedor_nome' => null, 'filial_cnpj' => null,
            'numero' => null, 'data' => null, 'valor' => null, 'chave' => null, 'vencimento' => null,
        ];

        if (!is_file($caminho)) {
            return ['erro' => 'Arquivo não encontrado.'] + $base;
        }

        $texto = self::texto($caminho);
        if ($texto === null) {
            return ['erro' => 'Não foi possível ler o PDF (pdftotext indisponível ou arquivo corrompido).'] + $base;
        }
        if (mb_strlen(trim($texto)) < self::MIN_TEXTO) {
            return ['erro' => 'PDF sem texto — provavelmente escaneado (imagem). Preencha os campos manualmente.'] + $base;
        }

        $r = self::regras($texto);
        $r['texto'] = $texto;
        $r['ok']    = true;
        $r['erro']  = null;
        return $r + $base;
    }

    /** O binário está disponível? (para avisar na tela em vez de falhar calado) */
    public static function disponivel(): bool
    {
        return self::binario() !== null;
    }

    private static function binario(): ?string
    {
        static $cache = false;
        if ($cache !== false) {
            return $cache;
        }
        foreach (self::BINARIOS as $bin) {
            // Confere pelo CÓDIGO DE SAÍDA, não pelo texto: quando o binário não
            // existe o próprio shell responde "sh: 1: /usr/bin/pdftotext: not
            // found" — que contém a palavra "pdftotext" e passava como se
            // estivesse instalado, deixando o erro aparecer só arquivo a arquivo.
            $saida = [];
            $rc    = 1;
            @exec(escapeshellarg($bin) . ' -v 2>&1', $saida, $rc);
            $primeira = $saida[0] ?? '';
            // `pdftotext -v` sai com 0 e imprime "pdftotext version X.Y.Z".
            if ($rc === 0 && stripos($primeira, 'pdftotext version') === 0) {
                return $cache = $bin;
            }
        }
        return $cache = null;
    }

    private static function texto(string $caminho): ?string
    {
        $bin = self::binario();
        if ($bin === null) {
            return null;
        }
        $cmd = escapeshellarg($bin) . ' -layout -enc UTF-8 -q ' . escapeshellarg($caminho) . ' -';
        $out = @shell_exec($cmd);
        return is_string($out) ? $out : null;
    }

    // ─────────────────────────────────────────
    // Regras por tipo de documento
    // ─────────────────────────────────────────

    private static function regras(string $t): array
    {
        $tipo = self::tipo($t);
        $r    = ['tipo' => $tipo];

        if ($tipo === 'NFSe') {
            // Cada papel é lido dentro do SEU bloco: no DANFSe os dois usam o
            // mesmo rótulo ("CNPJ / CPF / NIF", "Nome / Nome Empresarial"), e sem
            // recortar o bloco o tomador herdaria os dados do prestador.
            $prestador = self::bloco($t, 'PRESTADOR / FORNECEDOR', [
                'TOMADOR / ADQUIRENTE', 'DESTINAT[ÁA]RIO DA OPERA[ÇC][ÃA]O',
            ]);
            $tomador = self::bloco($t, 'TOMADOR / ADQUIRENTE', [
                'DESTINAT[ÁA]RIO DA OPERA[ÇC][ÃA]O', 'INTERMEDI[ÁA]RIO DA OPERA[ÇC][ÃA]O',
                'SERVI[ÇC]O PRESTADO',
            ]);
            $cnpjs = self::cnpjs($t);

            $r['fornecedor_cnpj'] = self::celulaDoc($prestador, 'CNPJ / CPF / NIF')
                                 ?? self::cnpjApos($t, 'EMITENTE DA NFS-e')
                                 ?? ($cnpjs[0] ?? null);
            $r['fornecedor_nome'] = self::celulaTexto($prestador, 'Nome / Nome Empresarial')
                                 ?? self::nomeApos($t, 'EMITENTE DA NFS-e');
            $r['filial_cnpj']     = self::celulaDoc($tomador, 'CNPJ / CPF / NIF')
                                 ?? self::cnpjApos($t, 'TOMADOR DO SERVI[ÇC]O')
                                 ?? ($cnpjs[1] ?? null);
            $r['numero']          = self::celulaNumero($t, 'N[ÚU]MERO DA NFS-e')
                                 ?? (preg_match('~N[ÚU]MERO DA NFS-e.{0,200}?\n\s*(\d+)~isu', $t, $m) ? $m[1] : null);
            $r['data']            = self::celulaData($t, 'DATA E HORA DA EMISS[ÃA]O DA NFS-e')
                                 ?? self::dataApos($t, 'DATA E HORA DA EMISS[ÃA]O DA NFS-e')
                                 ?? self::celulaData($t, 'COMPET[ÊE]NCIA DA NFS-e');
            // O líquido é o que a filial paga; o bruto só entra se o líquido não
            // vier. O lookahead evita cair na coluna "... + IBS/CBS", que na v2.0
            // fica ao lado e hoje vem zerada.
            $r['valor']           = self::celulaValor($t, 'VALOR L[ÍI]QUIDO DA NFS-e(?! \+)')
                                 ?? self::celulaValor($t, 'VALOR DA OPERA[ÇC][ÃA]O / SERVI[ÇC]O')
                                 ?? self::celulaValor($t, 'VALOR TOTAL DA NFS-e')
                                 ?? self::valorApos($t, 'VALOR L[ÍI]QUIDO DA NFS-e')
                                 ?? self::valorApos($t, 'VALOR DO SERVI[ÇC]O');
            $r['chave']           = self::chave($t, 50);
        } elseif ($tipo === 'NFe') {
            $cnpjs                = self::cnpjs($t);
            $r['fornecedor_cnpj'] = $cnpjs[0] ?? null;   // emitente vem primeiro no DANFE
            $r['filial_cnpj']     = $cnpjs[1] ?? null;   // destinatário
            $r['numero']          = preg_match('/N[ºo°]\s*([\d.]{5,})/u', $t, $m)
                                    ? (ltrim(preg_replace('/\D/', '', $m[1]), '0') ?: null) : null;
            $r['data']            = self::dataApos($t, 'DATA DA EMISS[ÃA]O');
            $r['valor']           = self::valorApos($t, 'VALOR TOTAL DA NOTA');
            $r['chave']           = self::chave($t, 44);
        } else {
            $cnpjs                = self::cnpjs($t);
            $r['fornecedor_cnpj'] = $cnpjs[0] ?? null;
            $r['filial_cnpj']     = $cnpjs[1] ?? null;
            $r['numero']          = preg_match('/(?:FATURA|N[ÚU]MERO|N[ºo°])\D{0,12}([\d.\/-]{3,})/iu', $t, $m)
                                    ? (preg_replace('/\D/', '', $m[1]) ?: null) : null;
            $r['data']            = self::dataApos($t, 'EMISS[ÃA]O')
                                 ?? (preg_match('/(\d{2}\/\d{2}\/\d{4})/', $t, $m) ? $m[1] : null);
            $r['valor']           = self::valorApos($t, 'TOTAL A PAGAR')
                                 ?? self::valorApos($t, 'VALOR DO DOCUMENTO')
                                 ?? self::valorApos($t, 'VALOR TOTAL')
                                 ?? self::maiorValor($t);
        }

        $r['vencimento'] = self::dataApos($t, 'VENCIMENTO');
        return $r;
    }

    private static function tipo(string $t): string
    {
        if (stripos($t, 'DANFSe') !== false || stripos($t, 'NFS-e') !== false) return 'NFSe';
        if (stripos($t, 'DANFE') !== false)                                    return 'NFe';
        return 'generico';
    }

    // ─────────────────────────────────────────
    // Leitura por bloco e por coluna
    //
    // O `-layout` mantém a grade: o rótulo fica numa linha e o valor na linha
    // seguinte, na MESMA coluna. Ler "o primeiro valor depois do rótulo" pega a
    // célula vizinha quando a nossa está vazia — daí recortar a coluna.
    // ─────────────────────────────────────────

    /** Folga à esquerda da coluna, para valores alinhados à direita. */
    private const PAD_COLUNA = 14;

    /** Trecho entre o rótulo de um bloco e o primeiro dos rótulos que o encerram. */
    private static function bloco(string $t, string $inicio, array $fins): ?string
    {
        if (!preg_match('~' . $inicio . '~iu', $t, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $resto = substr($t, $m[0][1]);
        $corte = strlen($resto);
        foreach ($fins as $fim) {
            if (preg_match('~' . $fim . '~iu', $resto, $mf, PREG_OFFSET_CAPTURE) && $mf[0][1] > 0) {
                $corte = min($corte, $mf[0][1]);
            }
        }
        return substr($resto, 0, $corte);
    }

    /**
     * Células candidatas do campo: o recorte da coluna do rótulo nas (até duas)
     * primeiras linhas não vazias abaixo dele, com e sem a folga à esquerda.
     * Quem chama filtra pelo formato esperado (data, valor, documento).
     *
     * @return list<string>
     */
    private static function celulas(?string $t, string $rotulo): array
    {
        if ($t === null || $t === '') {
            return [];
        }
        $linhas = preg_split('~\R~u', $t) ?: [];
        $total  = count($linhas);

        foreach ($linhas as $n => $linha) {
            if (!preg_match('~' . $rotulo . '~iu', $linha, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            // Offset do preg é em bytes; a coluna da grade é em caracteres.
            [$ini, $fim] = self::coluna($linha, mb_strlen(substr($linha, 0, $m[0][1])));
            $largura = $fim === null ? null : $fim - $ini;
            $pad     = min(self::PAD_COLUNA, $ini);

            $cands = [];
            $vistas = 0;
            for ($j = $n + 1; $j < $total && $vistas < 2; $j++) {
                if (trim($linhas[$j]) === '') {
                    continue;
                }
                $vistas++;
                $cands[] = trim(mb_substr($linhas[$j], $ini, $largura));
                if ($pad > 0) {
                    $cands[] = trim(mb_substr(
                        $linhas[$j],
                        $ini - $pad,
                        $largura === null ? null : $largura + $pad
                    ));
                }
            }
            // "-" é como o DANFSe escreve célula vazia.
            return array_values(array_filter($cands, fn($c) => $c !== '' && $c !== '-'));
        }
        return [];
    }

    /**
     * Limites da coluna que contém a posição $col: do início do seu rótulo até o
     * início do rótulo seguinte na mesma linha (colunas separadas por 2+ espaços).
     *
     * @return array{0:int,1:?int}
     */
    private static function coluna(string $linha, int $col): array
    {
        preg_match_all('~\S+(?: \S+)*~u', $linha, $m, PREG_OFFSET_CAPTURE);
        $ini = 0;
        $fim = null;
        foreach ($m[0] as $bloco) {
            $c = mb_strlen(substr($linha, 0, $bloco[1]));
            if ($c <= $col) {
                $ini = $c;
            } elseif ($fim === null) {
                $fim = $c;
            }
        }
        return [$ini, $fim];
    }

    /** CNPJ (14) ou CPF (11) da célula, só dígitos. */
    private static function celulaDoc(?string $t, string $rotulo): ?string
    {
        foreach (self::celulas($t, $rotulo) as $cel) {
            if (preg_match('~(\d{2}\.?\d{3}\.?\d{3}/?\d{4}-?\d{2}|\d{3}\.?\d{3}\.?\d{3}-?\d{2})~', $cel, $m)) {
                $d = preg_replace('/\D/', '', $m[1]);
                if (strlen($d) === 14 || strlen($d) === 11) {
                    return $d;
                }
            }
        }
        return null;
    }

    private static function celulaData(?string $t, string $rotulo): ?string
    {
        foreach (self::celulas($t, $rotulo) as $cel) {
            if (preg_match('~(\d{2}/\d{2}/\d{4})~', $cel, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private static function celulaValor(?string $t, string $rotulo): ?float
    {
        foreach (self::celulas($t, $rotulo) as $cel) {
            if (preg_match('~(?:R\$\s*)?([\d.]{1,12},\d{2})~u', $cel, $m)) {
                return self::paraFloat($m[1]);
            }
        }
        return null;
    }

    private static function celulaNumero(?string $t, string $rotulo): ?string
    {
        foreach (self::celulas($t, $rotulo) as $cel) {
            if (preg_match('~^(\d{1,15})\b~', $cel, $m)) {
                $n = ltrim($m[1], '0');
                if ($n !== '') {
                    return $n;
                }
            }
        }
        return null;
    }

    private static function celulaTexto(?string $t, string $rotulo): ?string
    {
        foreach (self::celulas($t, $rotulo) as $cel) {
            $cel = trim(preg_replace('/\s{2,}/u', ' ', $cel));
            if (mb_strlen($cel) >= 3) {
                return mb_substr($cel, 0, 120);
            }
        }
        return null;
    }

    /** CNPJs distintos, na ordem em que aparecem. @return list<string> */
    private static function cnpjs(string $t): array
    {
        preg_match_all('/\b(\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2})\b/', $t, $m);
        $out = [];
        foreach ($m[1] ?? [] as $c) {
            $d = preg_replace('/\D/', '', $c);
            if (strlen($d) === 14 && !in_array($d, $out, true)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /**
     * Primeiro CNPJ que aparece DEPOIS do rótulo. Resolve as grades em que o
     * rótulo está numa linha e o valor na seguinte, sem depender de coluna.
     */
    private static function cnpjApos(string $t, string $rotulo): ?string
    {
        $resto = self::apos($t, $rotulo);
        if ($resto === null) return null;
        return preg_match('/(\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2})/', $resto, $m)
            ? preg_replace('/\D/', '', $m[1]) : null;
    }

    /** Razão social logo abaixo do bloco do rótulo (linha não vazia sem rótulo). */
    private static function nomeApos(string $t, string $rotulo): ?string
    {
        $resto = self::apos($t, $rotulo);
        if ($resto === null) return null;
        if (preg_match('/Nome \/ Nome Empresarial.*?\n\s*([^\n]{3,120})/su', $resto, $m)) {
            $nome = trim(preg_replace('/\s{2,}.*$/', '', $m[1]));
            return $nome !== '' ? $nome : null;
        }
        return null;
    }

    private static function dataApos(string $t, string $rotulo): ?string
    {
        $resto = self::apos($t, $rotulo);
        if ($resto === null) return null;
        return preg_match('/(\d{2}\/\d{2}\/\d{4})/', $resto, $m) ? $m[1] : null;
    }

    private static function valorApos(string $t, string $rotulo): ?float
    {
        $resto = self::apos($t, $rotulo);
        if ($resto === null) return null;
        return preg_match('/(?:R\$\s*)?([\d.]{1,12},\d{2})/u', $resto, $m) ? self::paraFloat($m[1]) : null;
    }

    /** Último recurso para fatura sem rótulo conhecido: o maior valor do documento. */
    private static function maiorValor(string $t): ?float
    {
        preg_match_all('/\b([\d.]{1,12},\d{2})\b/', $t, $m);
        $vals = array_map([self::class, 'paraFloat'], $m[1] ?? []);
        return $vals ? max($vals) : null;
    }

    private static function chave(string $t, int $tamanho): ?string
    {
        // Contígua primeiro (é assim que o DANFSe imprime); só depois a versão
        // com separadores, que o DANFE usa em grupos de quatro.
        if (preg_match('/(?<!\d)(\d{' . $tamanho . '})(?!\d)/', $t, $m)) {
            return $m[1];
        }
        if (preg_match('/((?:\d[\s.]?){' . $tamanho . '})/', $t, $m)) {
            $d = preg_replace('/\D/', '', $m[1]);
            if (strlen($d) === $tamanho) return $d;
        }
        return null;
    }

    /**
     * Texto que vem depois da primeira ocorrência do rótulo. Sem diferenciar
     * caixa: o mesmo campo aparece como "Valor Líquido" na v1 do DANFSe e
     * "VALOR LÍQUIDO" na v2.
     */
    private static function apos(string $t, string $rotulo): ?string
    {
        if (!preg_match('~' . $rotulo . '~isu', $t, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        return substr($t, $m[0][1] + strlen($m[0][0]));
    }

    private static function paraFloat(string $v): float
    {
        return (float) str_replace(['.', ','], ['', '.'], $v);
    }
}
