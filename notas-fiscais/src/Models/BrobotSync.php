<?php
// @versao Cap_06 V_07_05/08/2026
declare(strict_types=1);

/**
 * Motor de sincronização com a Brobot (poller).
 *
 * A cada execução (agendada a cada 5 min):
 *   1. lê o cursor (última janela) — ou retroage `backfill_dias` no 1º run;
 *   2. pagina GET /dfe/fiscal_documents a partir do cursor (order=asc);
 *   3. para cada documento: identifica o tipo, extrai emit/dest/CFOP do XML,
 *      filtra por filial cadastrada + flag do tipo, deduplica por chave,
 *      grava a nota em `nf_notas_capturadas` e o XML em storage/{tipo}_xml/;
 *   4. avança o cursor e registra o run em `nf_brobot_log`.
 *
 * Requer: BrobotClient, XmlDfeParser (helpers) já incluídos pelo chamador.
 */
class BrobotSync
{
    private const MAX_PAGINAS   = 40;   // teto por run (evita loop infinito / runaway)
    private const PER_PAGE      = 50;

    /** fiscal_document_type (Brobot) → tipo interno + pasta de XML + coluna de flag */
    private const TIPOS = [
        'nfe'   => ['tipo' => 'NFe',  'pasta' => 'nfe_xml',  'flag' => 'brobot_nfe'],
        'cte'   => ['tipo' => 'CTe',  'pasta' => 'cte_xml',  'flag' => 'brobot_cte'],
        'nfse'  => ['tipo' => 'NFSe', 'pasta' => 'nfse_xml', 'flag' => 'brobot_nfse'],
        'cteos' => ['tipo' => 'CTOs', 'pasta' => 'ctos_xml', 'flag' => 'brobot_ctos'],
        'ctos'  => ['tipo' => 'CTOs', 'pasta' => 'ctos_xml', 'flag' => 'brobot_ctos'],
    ];

    /**
     * Executa o poller.
     *
     * @return array{status:string, importadas:int, ignoradas:int, detalhe:string}
     */
    public static function run(): array
    {
        $inicio = date('Y-m-d H:i:s');

        if (Settings::get('brobot.ativo', '0') !== '1') {
            return ['status' => 'skip', 'importadas' => 0, 'ignoradas' => 0,
                    'detalhe' => 'Integração desativada (brobot.ativo = 0).'];
        }

        $client = BrobotClient::fromSettings();
        if (!$client->temToken()) {
            $r = ['status' => 'erro', 'importadas' => 0, 'ignoradas' => 0, 'detalhe' => 'Token não configurado.'];
            self::registrarLog('sync', $inicio, $r, null);
            return $r;
        }

        $filiais   = self::mapaFiliais();
        $startDate = self::cursorAtual();

        $importadas = 0; $ignoradas = 0; $erro = null; $cursorNovo = null;

        for ($page = 1; $page <= self::MAX_PAGINAS; $page++) {
            $resp = $client->listarDocumentos([
                'per_page' => self::PER_PAGE,
                'page'     => $page,
                'order'    => 'asc',
                'q'        => ['start_date' => $startDate],
            ]);

            if (!$resp['ok']) {
                $erro = $resp['erro'] ?? 'falha desconhecida';
                break;
            }

            foreach ($resp['data'] as $doc) {
                $res = self::processarDoc($doc, $filiais);
                if ($res === 'importada')      $importadas++;
                else                            $ignoradas++;

                // Cursor avança por created_at — MESMO campo que a API usa em
                // q[start_date] e order[]. Usar issued_at aqui travava o poller:
                // docs chegam na Brobot muito depois da emissão, então o maior
                // issued_at de um lote (limitado a MAX_PAGINAS×PER_PAGE) pode
                // nunca passar do cursor → o run relia o mesmo lote para sempre.
                $criado = $doc['created_at'] ?? ($doc['issued_at'] ?? null);
                if ($criado && (!$cursorNovo || strtotime($criado) > strtotime($cursorNovo))) {
                    $cursorNovo = $criado;
                }
            }

            $meta        = $resp['meta'];
            $paginaAtual = (int) ($meta['current_page'] ?? $page);
            $totalPags   = (int) ($meta['total_pages'] ?? $paginaAtual);
            if ($paginaAtual >= $totalPags || empty($resp['data'])) {
                break;
            }
        }

        if ($cursorNovo !== null) {
            self::salvarCursor($cursorNovo);
        }

        $status  = $erro !== null ? ($importadas > 0 ? 'parcial' : 'erro') : 'ok';
        $detalhe = $erro !== null
            ? "Falha após {$importadas} importada(s): {$erro}"
            : "{$importadas} importada(s), {$ignoradas} fora de filtro/duplicada(s).";

        $r = ['status' => $status, 'importadas' => $importadas, 'ignoradas' => $ignoradas, 'detalhe' => $detalhe];
        self::registrarLog('sync', $inicio, $r, $cursorNovo);
        return $r;
    }

    /**
     * Classifica e importa um documento do Brobot.
     *
     * Público e com filtro opcional por CNPJ para ser reaproveitado pelo
     * backfill (BrobotBackfill): quando $soCnpjs é informado (lista de destinos
     * em backfill), só importa a nota se o destinatário estiver nessa lista.
     *
     * @param ?array $soCnpjs destinos aceitos (14 dígitos cada) ou null p/ todos
     * @return 'importada'|'ignorada'
     */
    public static function processarDoc(array $doc, array $filiais, ?array $soCnpjs = null): string
    {
        $chave = self::soDigitos((string) ($doc['key'] ?? ''));
        if ($chave === '' || strlen($chave) < 20) {
            return 'ignorada';
        }

        $meta = self::mapearTipo((string) ($doc['fiscal_document_type'] ?? ''));
        if ($meta === null) {
            error_log('BrobotSync: tipo de documento desconhecido: ' . ($doc['fiscal_document_type'] ?? '(vazio)'));
            return 'ignorada';
        }

        $pdo = Database::getInstance();

        // Dedup por chave (a UNIQUE também protege, mas evitamos parse/IO à toa)
        $ja = $pdo->prepare("SELECT 1 FROM nf_notas_capturadas WHERE chave_nf = ? LIMIT 1");
        $ja->execute([$chave]);
        if ($ja->fetchColumn()) {
            return 'ignorada';
        }

        $xml    = (string) ($doc['xml_brobot'] ?? ($doc['xml'] ?? ''));
        $parsed = XmlDfeParser::parse($xml, $meta['tipo']);

        $destCnpj = $parsed['dest_cnpj'];
        if ($soCnpjs !== null && ($destCnpj === null || !in_array($destCnpj, $soCnpjs, true))) {
            return 'ignorada'; // fora do conjunto em backfill
        }
        if ($destCnpj === null || !isset($filiais[$destCnpj])) {
            return 'ignorada'; // destinatário não é filial nossa
        }
        $filial = $filiais[$destCnpj];
        if ((int) ($filial[$meta['flag']] ?? 0) !== 1) {
            return 'ignorada'; // filial não integra este tipo
        }

        $fornCnpj = $parsed['emit_cnpj'];
        $fornNome = $parsed['emit_nome'];
        if ($fornCnpj === null || $fornNome === null) {
            error_log("BrobotSync: nota {$chave} sem emitente no XML — descartada.");
            return 'ignorada';
        }

        $numero  = (string) ($doc['doc_number'] ?? ($parsed['numero'] ?? ''));
        $valor   = isset($doc['total_value']) ? (float) $doc['total_value'] : (float) ($parsed['valor'] ?? 0);
        $emissao = !empty($doc['issued_at']) ? date('Y-m-d', strtotime($doc['issued_at'])) : ($parsed['emissao'] ?? date('Y-m-d'));

        // Cache dos cProd dos itens (NF-e) — critério de prefixo do pré-fluxo
        $itensCodigos = null;
        // Cobrança (NF-e): fatura + duplicatas + tPag. Gravada já na captura
        // para a vinculação montar as parcelas sem reler o XML do storage.
        $numeroFatura = null;
        $cobrancaJson = null;
        if ($meta['tipo'] === 'NFe') {
            $codigos = [];
            foreach (($parsed['itens'] ?? []) as $item) {
                $c = trim((string) ($item['codigo'] ?? ''));
                if ($c !== '') $codigos[] = $c;
            }
            $itensCodigos = json_encode($codigos, JSON_UNESCAPED_UNICODE);

            $cobranca     = $parsed['cobranca'] ?? XmlDfeParser::COBRANCA_VAZIA;
            $numeroFatura = $cobranca['fatura']['numero'] ?? null;
            $cobrancaJson = json_encode($cobranca, JSON_UNESCAPED_UNICODE);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO nf_notas_capturadas
                (id, chave_nf, tipo_documento, numero_nf, fornecedor_nome, fornecedor_cnpj,
                 destinatario_cnpj, filial_id, filial_nome, valor, data_emissao, cfop, itens_codigos,
                 natureza_operacao, numero_fatura, cobranca, tomador_cnpj, tomador_papel,
                 origem, brobot_id, xml_disponivel, pdf_disponivel, status, capturada_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'brobot', ?, 1, 0, 'capturada', NOW())"
        );
        $stmt->execute([
            self::uuid(), $chave, $meta['tipo'], $numero, $fornNome, $fornCnpj,
            $destCnpj, $filial['id'], $filial['nome'], $valor, $emissao, $parsed['cfop'], $itensCodigos,
            $parsed['natureza_operacao'] !== null ? mb_substr($parsed['natureza_operacao'], 0, 120) : null,
            $numeroFatura !== null ? mb_substr($numeroFatura, 0, 60) : null, $cobrancaJson,
            $parsed['tomador_cnpj'], $parsed['tomador_papel'],
            (string) ($doc['id'] ?? ''),
        ]);

        self::salvarXml($meta['pasta'], $chave, $xml);
        return 'importada';
    }

    // ─────────────────────────────────────────────────────────────
    // Estado / carimbos (nf_brobot_log)
    // ─────────────────────────────────────────────────────────────

    /**
     * Registra um run. Usado pelo poller ('sync') e pela classificação ('classificacao').
     *
     * @param array{status:string, importadas:int, ignoradas:int, detalhe:string} $r
     */
    public static function registrarLog(string $tipo, string $inicio, array $r, ?string $cursorAte): void
    {
        try {
            $status = in_array($r['status'], ['ok', 'parcial', 'erro'], true) ? $r['status'] : 'ok';
            Database::getInstance()->prepare(
                "INSERT INTO nf_brobot_log
                    (id, tipo, status, iniciado_em, finalizado_em, docs_importadas, docs_ignoradas, detalhe, cursor_ate)
                 VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?)"
            )->execute([
                self::uuid(), $tipo, $status, $inicio,
                (int) $r['importadas'], (int) $r['ignoradas'],
                mb_substr($r['detalhe'], 0, 500),
                $cursorAte ? date('Y-m-d H:i:s', strtotime($cursorAte)) : null,
            ]);
        } catch (Throwable $e) {
            error_log('BrobotSync: falha ao registrar log: ' . $e->getMessage());
        }
    }

    /** Último run de um tipo — alimenta os carimbos do topo da tela. */
    public static function ultimoStatus(string $tipo): ?array
    {
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT * FROM nf_brobot_log WHERE tipo = ? ORDER BY iniciado_em DESC LIMIT 1"
            );
            $stmt->execute([$tipo]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Auxiliares
    // ─────────────────────────────────────────────────────────────

    /** @return array<string,array> cnpj(14) => linha da filial */
    public static function mapaFiliais(): array
    {
        $rows = Database::getInstance()->query(
            "SELECT id, nome, cnpj, brobot_nfe, brobot_cte, brobot_nfse, brobot_ctos
             FROM filiais WHERE ativo = 1"
        )->fetchAll();

        $mapa = [];
        foreach ($rows as $f) {
            $cnpj = self::soDigitos((string) $f['cnpj']);
            if ($cnpj !== '') $mapa[$cnpj] = $f;
        }
        return $mapa;
    }

    private static function cursorAtual(): string
    {
        $cursor = (string) Settings::get('brobot.cursor_issued_at', '');
        if ($cursor !== '') {
            return gmdate('Y-m-d\TH:i:s\Z', strtotime($cursor));
        }
        $dias = max(1, Settings::getInt('brobot.backfill_dias', 7));
        return gmdate('Y-m-d\TH:i:s\Z', time() - $dias * 86400);
    }

    private static function salvarCursor(string $issued): void
    {
        // Grava direto (sem Settings::salvar) — CLI não tem usuário de sessão.
        Database::getInstance()->prepare(
            "UPDATE nf_configuracoes SET valor = ? WHERE chave = 'brobot.cursor_issued_at'"
        )->execute([date('Y-m-d H:i:s', strtotime($issued))]);
    }

    private static function salvarXml(string $pasta, string $chave, string $xml): void
    {
        if (trim($xml) === '') return;
        $base = (defined('STORAGE') ? STORAGE : ROOT . '/storage') . '/' . $pasta;
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        @file_put_contents($base . '/' . $chave . '.xml', $xml);
    }

    private static function mapearTipo(string $bruto): ?array
    {
        $k = preg_replace('/[^a-z]/', '', strtolower($bruto));
        return self::TIPOS[$k] ?? null;
    }

    private static function soDigitos(string $v): string
    {
        return preg_replace('/\D+/', '', $v) ?? '';
    }

    private static function uuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff));
    }
}
