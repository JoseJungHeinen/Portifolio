<?php
// @versao Ver.003.301026

namespace App\Services;

/**
 * Leitura da planilha de fornecedores (.xlsx ou .csv) para a importação
 * (spec 2026-10-30, base: lerPlanilha/impData/impRegime/impCnd/impStatus do protótipo).
 *
 * Sem biblioteca: o .xlsx é lido com ZipArchive + SimpleXML, só as partes
 * necessárias (workbook, rels, sharedStrings e as abas), com limite de tamanho
 * descompactado por parte (zip bomb), sem rede e sem DOCTYPE/entidades no XML.
 */
class PlanilhaReader
{
    /** Tamanho máximo do arquivo enviado (limite de upload de produção). */
    public const MAX_ARQUIVO = 2 * 1024 * 1024;
    /** Tamanho máximo descompactado de cada parte lida do .xlsx. */
    public const MAX_ENTRADA = 8 * 1024 * 1024;
    /** Total descompactado lido de um .xlsx (todas as partes). */
    public const MAX_TOTAL = 32 * 1024 * 1024;
    public const MAX_LINHAS = 5000;
    /** O cabeçalho é procurado nas primeiras linhas de cada aba. */
    public const LINHAS_CABECALHO = 20;

    /** Campo => [rótulo do modelo, sinônimos aceitos no cabeçalho]. Ordem = ordem do modelo. */
    public const COLUNAS = [
        'razao'                => ['Razão Social', ['razao social', 'razao', 'nome empresarial']],
        'fantasia'             => ['Nome Fantasia', ['nome fantasia', 'fantasia']],
        'cnpj'                 => ['CNPJ', ['cnpj', 'cnpj/cpf']],
        'cnpj_matriz'          => ['CNPJ Matriz', ['cnpj matriz', 'cnpj da matriz']],
        'cidade'               => ['Cidade', ['cidade', 'municipio']],
        'uf'                   => ['UF', ['uf', 'estado']],
        'email'                => ['E-mail contato', ['e-mail contato', 'e-mail', 'email', 'email contato']],
        'fone'                 => ['Fone Fixo', ['fone fixo', 'telefone', 'fone']],
        'celular'              => ['Celular', ['celular', 'whatsapp']],
        'status'               => ['Status', ['status']],
        'regime'               => ['Regime Tributário', ['regime tributario', 'regime']],
        'cnd_federal'          => ['CND Federal', ['cnd federal']],
        'cnd_federal_validade' => ['Validade CND Federal', ['validade cnd federal', 'cnd federal validade', 'validade da cnd federal',
                                                           'vencimento cnd federal', 'cnd federal valida ate']],
        'setor'                => ['Departamento / Setor', ['departamento / setor', 'departamento', 'setor']],
        'obs'                  => ['Observação', ['observacao', 'observacoes', 'obs']],
        'classificacao'        => ['Classificação do Fornecedor', ['classificacao do fornecedor', 'classificacao fornecedor', 'classificacao']],
    ];

    private const ACENTOS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ];

    // ── Funções puras ─────────────────────────────────────────────────────

    /** Minúsculas, sem acento, espaços simples (e " / " padronizado). */
    public static function norm(?string $s): string
    {
        $s = strtr(mb_strtolower(trim((string)$s), 'UTF-8'), self::ACENTOS);
        $s = preg_replace('/\s*\/\s*/u', ' / ', $s);
        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }

    public static function cnpjValido(?string $cnpj): bool
    {
        $d = preg_replace('/\D/', '', (string)$cnpj);
        if (strlen($d) !== 14 || preg_match('/^(\d)\1{13}$/', $d)) {
            return false;
        }
        $calc = function (string $base): int {
            $pesos = strlen($base) === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $s = 0;
            foreach (str_split($base) as $i => $c) {
                $s += (int)$c * $pesos[$i];
            }
            $r = $s % 11;
            return $r < 2 ? 0 : 11 - $r;
        };
        return $calc(substr($d, 0, 12)) === (int)$d[12] && $calc(substr($d, 0, 13)) === (int)$d[13];
    }

    /**
     * Data da planilha: número serial do Excel, dd/mm/aaaa, dd/mm/aa ou aaaa-mm-dd.
     * '' = vazia; null = não reconhecida.
     */
    public static function data(?string $v): ?string
    {
        $t = trim((string)$v);
        if ($t === '') return '';
        if (preg_match('/^\d+(\.\d+)?$/', $t)) {
            $n = (float)$t;
            if ($n > 20000 && $n < 80000) {
                return (new \DateTimeImmutable('1899-12-30'))->modify('+' . (int)floor($n) . ' days')->format('Y-m-d');
            }
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:$|[T ])/', $t, $m)) {
            [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})$#', $t, $m)) {
            [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            if ($y < 100) $y += 2000;
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** Regime da planilha → chave de Fornecedor::REGIMES. '' = vazio; null = sem equivalente. */
    public static function regime(?string $v): ?string
    {
        $n = self::norm($v);
        if ($n === '') return '';
        if (str_contains($n, 'hibrid') || str_contains($n, 'ibs')) return null;
        if ($n === 'mei' || preg_match('/\bmei\b/', $n) || str_contains($n, 'microempreendedor')) return 'mei';
        if (str_contains($n, 'simples')) return 'simples_nacional';
        if (str_contains($n, 'real')) return 'lucro_real';
        if (str_contains($n, 'presumido')) return 'lucro_presumido';
        return null;
    }

    /** CND Federal → chave de Fornecedor::CND_SITUACOES. '' = vazio; null = não reconhecida. */
    public static function cnd(?string $v): ?string
    {
        $n = self::norm($v);
        if ($n === '') return '';
        if (str_contains($n, 'efeito')) return 'positiva_efeitos_negativa';
        if (str_starts_with($n, 'positiva')) return 'positiva';
        if (str_starts_with($n, 'negativa')) return 'negativa';
        return null;
    }

    /** Status + Classificação → situação. '' = não informada. */
    public static function situacao(?string $status, ?string $classificacao): string
    {
        $s = self::norm($status);
        $c = self::norm($classificacao);
        if ($s === 'inativo' || str_starts_with($c, 'nao autoriz')) return 'bloqueado';
        if (str_starts_with($c, 'autoriz')) return 'homologado';
        if (str_starts_with($c, 'ressalv')) return 'pendente';
        return '';
    }

    /** @return list<string> cabeçalho do modelo para download */
    public static function cabecalhoModelo(): array
    {
        return array_values(array_map(fn($c) => $c[0], self::COLUNAS));
    }

    // ── Leitura ──────────────────────────────────────────────────────────

    /**
     * @return array{aba: string, colunas: array<string, int>, linhas: list<array<string, mixed>>}
     * @throws \RuntimeException com mensagem pronta para a tela
     */
    public function ler(string $caminho, string $nomeOriginal): array
    {
        $ext = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
        if ($ext === 'xls') {
            throw new \RuntimeException('Arquivo .xls (Excel 97-2003) não é suportado: abra no Excel e use "Salvar como" → Pasta de Trabalho do Excel (.xlsx).');
        }
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            throw new \RuntimeException('Envie a planilha em .xlsx ou .csv.');
        }
        if (!is_file($caminho)) {
            throw new \RuntimeException('Arquivo não recebido.');
        }
        if (filesize($caminho) > self::MAX_ARQUIVO) {
            throw new \RuntimeException('Arquivo maior que 2 MB.');
        }

        $abas = $ext === 'xlsx' ? $this->abasXlsx($caminho) : ['CSV' => $this->linhasCsv($caminho)];
        foreach ($abas as $nome => $linhas) {
            $r = $this->extrair($linhas instanceof \Closure ? $linhas() : $linhas);
            if ($r !== null) {
                return ['aba' => (string)$nome] + $r;
            }
        }
        throw new \RuntimeException('Não encontrei uma aba com as colunas "Razão Social" e "CNPJ" no cabeçalho. Use o modelo (botão "Baixar modelo").');
    }

    /**
     * Acha o cabeçalho e monta as linhas por campo.
     *
     * @param array<int, array<int, string>> $linhas número da linha => [coluna => valor]
     * @return array{colunas: array<string, int>, linhas: list<array<string, mixed>>}|null
     */
    private function extrair(array $linhas): ?array
    {
        $hi = null;
        foreach ($linhas as $num => $cels) {
            if ($num > self::LINHAS_CABECALHO) break;
            $n = array_map(self::norm(...), $cels);
            if (array_intersect($n, ['cnpj', 'cnpj / cpf']) && array_filter($n, fn($c) => str_contains($c, 'razao'))) {
                $hi = $num;
                break;
            }
        }
        if ($hi === null) return null;

        $head = array_map(self::norm(...), $linhas[$hi]);
        $col  = [];
        foreach (self::COLUNAS as $k => [, $sin]) {
            foreach ($sin as $a) {
                $j = array_search(self::norm($a), array_diff_key($head, array_flip($col)), true);
                if ($j !== false) { $col[$k] = $j; break; }
            }
        }
        $faltam = array_diff(['razao', 'cnpj'], array_keys($col));
        if ($faltam) {
            throw new \RuntimeException('Coluna obrigatória não encontrada no cabeçalho: '
                . implode(', ', array_map(fn($k) => self::COLUNAS[$k][0], $faltam)) . '.');
        }

        $out = [];
        foreach ($linhas as $num => $cels) {
            if ($num <= $hi) continue;
            if (!array_filter($cels, fn($c) => trim($c) !== '')) continue;
            $l = ['linha' => $num];
            foreach (array_keys(self::COLUNAS) as $k) {
                $l[$k] = isset($col[$k]) ? trim((string)($cels[$col[$k]] ?? '')) : '';
            }
            if (!array_filter(array_diff_key($l, ['linha' => 1]), fn($v) => $v !== '')) continue;
            $out[] = $l;
            if (count($out) > self::MAX_LINHAS) {
                throw new \RuntimeException('A planilha passa de ' . self::MAX_LINHAS . ' linhas: divida em arquivos menores.');
            }
        }
        return ['colunas' => $col, 'linhas' => $out];
    }

    // ── CSV ──────────────────────────────────────────────────────────────

    /** @return array<int, array<int, string>> */
    private function linhasCsv(string $caminho): array
    {
        $txt = (string)file_get_contents($caminho);
        if (str_starts_with($txt, "\xEF\xBB\xBF")) {
            $txt = substr($txt, 3);
        } elseif (!mb_check_encoding($txt, 'UTF-8')) {
            $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
        }
        if (str_contains($txt, "\0")) {
            throw new \RuntimeException('O arquivo .csv não é texto.');
        }

        // Separador: o mais frequente na linha do cabeçalho (a que tem "CNPJ").
        $amostra = '';
        foreach (array_slice(preg_split('/\r\n|\n|\r/', $txt, self::LINHAS_CABECALHO + 1), 0, self::LINHAS_CABECALHO) as $ln) {
            if (stripos($ln, 'cnpj') !== false) { $amostra = $ln; break; }
        }
        $sep = ';';
        $max = substr_count($amostra, ';');
        foreach ([',', "\t"] as $s) {
            if (substr_count($amostra, $s) > $max) { $sep = $s; $max = substr_count($amostra, $s); }
        }

        $h = fopen('php://temp', 'r+');
        fwrite($h, $txt);
        rewind($h);
        $linhas = [];
        $num = 0;
        while (($cels = fgetcsv($h, null, $sep, '"', '')) !== false) {
            $num++;
            $linhas[$num] = $cels === [null] ? [] : array_map(fn($c) => (string)$c, $cels);
            if ($num > self::MAX_LINHAS + self::LINHAS_CABECALHO + 1000) break;
        }
        fclose($h);
        return $linhas;
    }

    // ── XLSX ─────────────────────────────────────────────────────────────

    /**
     * Abas na ordem do arquivo; cada valor é uma closure que lê a aba sob demanda.
     *
     * @return array<string, \Closure>
     */
    private function abasXlsx(string $caminho): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($caminho, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('O arquivo .xlsx está corrompido ou não é uma planilha do Excel.');
        }
        if ($zip->numFiles > 2000) {
            $zip->close();
            throw new \RuntimeException('O arquivo .xlsx tem partes demais para uma planilha.');
        }
        $lidos = 0;
        $parte = function (string $nome, bool $obrigatoria = true) use ($zip, &$lidos): ?\SimpleXMLElement {
            $st = $zip->statName($nome);
            if ($st === false) {
                if ($obrigatoria) throw new \RuntimeException('O arquivo .xlsx não é uma planilha do Excel válida.');
                return null;
            }
            if ($st['size'] > self::MAX_ENTRADA) {
                throw new \RuntimeException('Planilha grande demais para importar.');
            }
            // Lê no máximo MAX_ENTRADA+1 bytes, mesmo que o cabeçalho do zip minta o tamanho.
            $xml = $zip->getFromName($nome, self::MAX_ENTRADA + 1);
            if ($xml === false) throw new \RuntimeException('Não consegui ler o arquivo .xlsx.');
            $lidos += strlen($xml);
            if (strlen($xml) > self::MAX_ENTRADA || $lidos > self::MAX_TOTAL) {
                throw new \RuntimeException('Planilha grande demais para importar.');
            }
            return self::xml($xml);
        };

        $wb   = $parte('xl/workbook.xml');
        $rels = $parte('xl/_rels/workbook.xml.rels');
        $alvo = [];
        foreach ($rels->Relationship ?? [] as $r) {
            $alvo[(string)$r['Id']] = (string)$r['Target'];
        }
        $sst = [];
        if ($ss = $parte('xl/sharedStrings.xml', false)) {
            foreach ($ss->si as $si) {
                $sst[] = self::texto($si);
            }
        }

        $relNs = '';
        foreach ($wb->getDocNamespaces(true) as $uri) {
            if (str_contains($uri, 'relationships')) { $relNs = $uri; break; }
        }
        $abas = [];
        foreach ($wb->sheets->sheet ?? [] as $sh) {
            $rid = $relNs !== '' ? (string)($sh->attributes($relNs)['id'] ?? '') : '';
            $t   = ltrim(str_replace('\\', '/', $alvo[$rid] ?? ''), '/');
            $t   = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
            if (!preg_match('#^xl/worksheets/[A-Za-z0-9_]+\.xml$#', $t)) continue;
            $abas[(string)$sh['name']] = fn() => $this->celulasXlsx($parte($t), $sst);
            if (count($abas) >= 50) break;
        }
        return $abas;
    }

    /** @return array<int, array<int, string>> */
    private function celulasXlsx(\SimpleXMLElement $ws, array $sst): array
    {
        $linhas = [];
        $seq = 0;
        foreach ($ws->sheetData->row ?? [] as $row) {
            $num = (int)($row['r'] ?? 0) ?: $seq + 1;
            $seq = $num;
            $cels = [];
            $ci = -1;
            foreach ($row->c as $c) {
                $ci = preg_match('/^([A-Z]+)\d*$/', (string)$c['r'], $m) ? self::indiceColuna($m[1]) : $ci + 1;
                if ($ci > 200) continue;
                $t = (string)$c['t'];
                $v = match ($t) {
                    's'         => $sst[(int)$c->v] ?? '',
                    'inlineStr' => self::texto($c->is),
                    'str', 'e'  => (string)$c->v,
                    'b'         => (string)$c->v === '1' ? 'VERDADEIRO' : 'FALSO',
                    default     => self::numero((string)$c->v),
                };
                $cels[$ci] = $v;
            }
            if ($cels) {
                $max = max(array_keys($cels));
                $linhas[$num] = array_replace(array_fill(0, $max + 1, ''), $cels);
            }
            if (count($linhas) > self::MAX_LINHAS + self::LINHAS_CABECALHO + 1000) break;
        }
        return $linhas;
    }

    /** XML sem rede, sem DOCTYPE (bloqueia entidades externas/expansão). */
    private static function xml(string $xml): \SimpleXMLElement
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \RuntimeException('O arquivo .xlsx tem conteúdo não permitido.');
        }
        $ant = libxml_use_internal_errors(true);
        $x   = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($ant);
        if ($x === false) {
            throw new \RuntimeException('O arquivo .xlsx está corrompido.');
        }
        return $x;
    }

    /** Texto de <si>/<is>: <t> simples ou runs <r><t>. */
    private static function texto(?\SimpleXMLElement $n): string
    {
        if ($n === null) return '';
        $s = isset($n->t) ? (string)$n->t : '';
        // Sem runs de formatação $n->r vem null (planilhas do openpyxl/Excel com texto simples).
        foreach ($n->r ?? [] as $r) {
            $s .= (string)$r->t;
        }
        return $s;
    }

    /** Número do Excel como texto: 1.1222333000181E+13 → 11222333000181; 5.0 → 5. */
    private static function numero(string $v): string
    {
        $v = trim($v);
        if ($v === '' || !is_numeric($v)) return $v;
        $f = (float)$v;
        if (floor($f) === $f && abs($f) < 1e15) {
            return sprintf('%.0f', $f);
        }
        return $v;
    }

    private static function indiceColuna(string $letras): int
    {
        $n = 0;
        foreach (str_split($letras) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }
}
