<?php
// @versao Cap_16 V_06_18/09/2026
declare(strict_types=1);

/**
 * Motor de sincronização direta com o ADN (NFSe Nacional) — sem Brobot.
 *
 * Um único certificado (matriz, `nf_configuracoes` grupo 'dist') cobre todas
 * as filiais da mesma raiz de CNPJ — confirmado em 16/09/2026. Cada filial
 * continua com seu próprio cursor de NSU (`nf_adn_nsu_cursor`), porque o NSU
 * é por CNPJ consultado (`cnpjConsulta`), não por certificado.
 *
 * Por execução, para cada filial com `adn_nfse_ativo = 1` dentro da janela:
 *   1. lê o cursor (nf_adn_nsu_cursor.ultimo_nsu, 0 no primeiro run);
 *   2. chama GET /contribuintes/DFe/{nsu}?cnpjConsulta={cnpj_da_filial} em
 *      loop, avançando pelo maior NSU do lote, até "sem novidade";
 *   3. para cada documento: só importa se o TOMADOR da NFSe for a própria
 *      filial (documento em que a filial é emitente — nota própria — é
 *      ignorado, não é isso que a staging de compras representa);
 *   4. deduplica por chave, grava em nf_notas_capturadas (origem='adn'),
 *      avança o cursor e registra o run em nf_adn_log.
 *
 * Requer: AdnClient, XmlDfeParser (helpers) já incluídos pelo chamador.
 */
class AdnSync
{
    private const MAX_LOTES = 40; // teto de chamadas por filial por run (evita loop infinito)

    /**
     * Executa o poller para todas as filiais habilitadas.
     *
     * @return array{status:string, importadas:int, ignoradas:int, detalhe:string}
     */
    public static function run(): array
    {
        $certPadraoPath  = (string) Settings::get('dist.cert_path', '');
        $certPadraoSenha = (string) Settings::get('dist.cert_senha', '');

        // Fora da janela de busca (filiais.dist_horario_inicio/fim) nem entra na
        // lista — evita concentrar todas as filiais no mesmo horário contra o
        // Portal Nacional. Janela com fim < início cruza a meia-noite.
        $filiais = Database::getInstance()->query(
            "SELECT id, nome, cnpj, dist_cert_path, dist_cert_senha, dist_intervalo_min
             FROM filiais
             WHERE ativo = 1 AND adn_nfse_ativo = 1
               AND (
                     (dist_horario_inicio <= dist_horario_fim
                        AND CURTIME() >= dist_horario_inicio AND CURTIME() < dist_horario_fim)
                  OR (dist_horario_inicio > dist_horario_fim
                        AND (CURTIME() >= dist_horario_inicio OR CURTIME() < dist_horario_fim))
                   )"
        )->fetchAll();

        if (empty($filiais)) {
            return ['status' => 'skip', 'importadas' => 0, 'ignoradas' => 0,
                    'detalhe' => 'Nenhuma filial ativa e dentro da janela de busca agora.'];
        }

        $totalImportadas = 0; $totalIgnoradas = 0; $detalhes = [];
        $status = 'ok';

        foreach ($filiais as $filial) {
            // Intervalo mínimo (opcional) — a janela sozinha só cobre "uma vez
            // por dia, num horário fixo"; pra "de hora em hora" (janela larga
            // + intervalo de 60 min), o cron continua batendo a cada 5 min mas
            // a filial só processa quando já passou intervalo suficiente desde
            // o último run — evita martelar a SEFAZ/ADN a cada tick do cron.
            if (!empty($filial['dist_intervalo_min']) && self::ultimoRunRecente($filial['id'], (int) $filial['dist_intervalo_min'])) {
                continue;
            }

            // Resolução: certificado próprio da filial → certificado da
            // matriz (raiz de CNPJ) → padrão de reserva.
            [$certPath, $certSenha] = CertificadoMatriz::resolverParaFilial($filial, $certPadraoPath, $certPadraoSenha);
            if ($certPath === '' || !is_file($certPath) || $certSenha === '') {
                $r = ['status' => 'erro', 'importadas' => 0, 'ignoradas' => 0,
                      'detalhe' => 'Sem certificado válido (nem próprio, nem o padrão da matriz).'];
                self::registrarLog($filial['id'], date('Y-m-d H:i:s'), $r, null);
                $detalhes[] = "{$filial['nome']}: {$r['detalhe']}";
                $status = 'parcial';
                continue;
            }

            // false = produção. AdnClient::__construct tem default $homologacao
            // = true (achado em 17/09/2026: rodava contra a produção restrita/
            // homologação do governo desde o início — todo NFSe capturado até
            // aqui é "SEM VALOR FISCAL", confirmado pelo próprio texto que a
            // ADN grava na discriminação do serviço).
            $cliente = new AdnClient($certPath, $certSenha, false);
            $r = self::processarFilial($cliente, $filial);
            $totalImportadas += $r['importadas'];
            $totalIgnoradas  += $r['ignoradas'];
            $detalhes[] = "{$filial['nome']}: {$r['detalhe']}";
            if ($r['status'] === 'erro') $status = 'parcial';
        }

        return [
            'status'     => $status,
            'importadas' => $totalImportadas,
            'ignoradas'  => $totalIgnoradas,
            'detalhe'    => implode(' | ', $detalhes),
        ];
    }

    /**
     * Processa uma filial: avança o NSU até esgotar novidade.
     *
     * @param array{id:string, nome:string, cnpj:string} $filial
     * @return array{status:string, importadas:int, ignoradas:int, detalhe:string}
     */
    public static function processarFilial(AdnClient $cliente, array $filial): array
    {
        $inicio = date('Y-m-d H:i:s');

        $filialCnpj = self::soDigitos($filial['cnpj']);
        $nsu        = self::cursorAtual($filial['id']);
        $importadas = 0; $ignoradas = 0; $erro = null; $nsuFinal = $nsu;

        for ($i = 0; $i < self::MAX_LOTES; $i++) {
            $lote = $cliente->baixarLote($nsuFinal, $filialCnpj);

            if (!$lote['ok']) {
                $erro = $lote['erro'] ?? 'falha desconhecida';
                break;
            }
            if ($lote['semNovidade']) {
                break; // em dia — nada mais a fazer neste run
            }

            foreach ($lote['docs'] as $doc) {
                $res = self::processarDoc($doc, $filial['id'], $filial['nome'], $filialCnpj);
                if ($res === 'importada') $importadas++;
                else                       $ignoradas++;

                if ($doc['nsu'] > $nsuFinal) $nsuFinal = $doc['nsu'];
            }
        }

        if ($nsuFinal !== $nsu) {
            self::salvarCursor($filial['id'], $nsuFinal);
        }

        $status  = $erro !== null ? ($importadas > 0 ? 'parcial' : 'erro') : 'ok';
        $detalhe = $erro !== null
            ? "Falha após {$importadas} importada(s): {$erro}"
            : "{$importadas} importada(s), {$ignoradas} fora de filtro/duplicada(s). NSU: {$nsu} → {$nsuFinal}.";

        $r = ['status' => $status, 'importadas' => $importadas, 'ignoradas' => $ignoradas, 'detalhe' => $detalhe];
        self::registrarLog($filial['id'], $inicio, $r, $nsuFinal !== $nsu ? $nsuFinal : null);
        return $r;
    }

    /**
     * @param array{nsu:int, chave:string, tipo:string, xml:string} $doc
     * @return 'importada'|'ignorada'
     */
    private static function processarDoc(array $doc, string $filialId, string $filialNome, string $filialCnpj): string
    {
        $chave = self::soDigitos($doc['chave']);
        if ($chave === '' || strlen($chave) < 20) {
            return 'ignorada';
        }

        $pdo = Database::getInstance();
        $ja  = $pdo->prepare("SELECT 1 FROM nf_notas_capturadas WHERE chave_nf = ? LIMIT 1");
        $ja->execute([$chave]);
        if ($ja->fetchColumn()) {
            return 'ignorada'; // já capturada (por este caminho ou outro)
        }

        $parsed = XmlDfeParser::parse($doc['xml'], 'NFSe');

        // Só nos interessa quando a filial é a TOMADORA (nota de compra de
        // serviço). Documento em que a filial aparece como emitente é uma
        // nota própria (receita) — fora do escopo desta staging.
        if ($parsed['dest_cnpj'] === null || $parsed['dest_cnpj'] !== $filialCnpj) {
            return 'ignorada';
        }

        $fornCnpj = $parsed['emit_cnpj'];
        $fornNome = $parsed['emit_nome'];
        if ($fornCnpj === null || $fornNome === null) {
            error_log("AdnSync: NFSe {$chave} sem emitente reconhecível no XML — descartada.");
            return 'ignorada';
        }

        $numero  = (string) ($parsed['numero'] ?? '');
        $valor   = (float) ($parsed['valor'] ?? 0);
        $emissao = $parsed['emissao'] ?? date('Y-m-d');

        $stmt = $pdo->prepare(
            "INSERT INTO nf_notas_capturadas
                (id, chave_nf, tipo_documento, numero_nf, fornecedor_nome, fornecedor_cnpj,
                 destinatario_cnpj, filial_id, filial_nome, valor, data_emissao,
                 origem, brobot_id, xml_disponivel, pdf_disponivel, status, capturada_em)
             VALUES (?, ?, 'NFSe', ?, ?, ?, ?, ?, ?, ?, ?, 'adn', ?, 1, 0, 'capturada', NOW())"
        );
        $stmt->execute([
            self::uuid(), $chave, $numero, $fornNome, $fornCnpj,
            $filialCnpj, $filialId, $filialNome, $valor, $emissao,
            (string) $doc['nsu'],
        ]);

        self::salvarXml($chave, $doc['xml']);
        return 'importada';
    }

    // ─────────────────────────────────────────────────────────────
    // Cursor / log
    // ─────────────────────────────────────────────────────────────

    /** true se o último run (log, qualquer status) desta filial foi há menos de $minutos. */
    private static function ultimoRunRecente(string $filialId, int $minutos): bool
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT TIMESTAMPDIFF(MINUTE, iniciado_em, NOW()) FROM nf_adn_log
             WHERE filial_id = ? ORDER BY iniciado_em DESC LIMIT 1"
        );
        $stmt->execute([$filialId]);
        $minutosDesde = $stmt->fetchColumn();
        return $minutosDesde !== false && (int) $minutosDesde < $minutos;
    }

    private static function cursorAtual(string $filialId): int
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT ultimo_nsu FROM nf_adn_nsu_cursor WHERE filial_id = ?"
        );
        $stmt->execute([$filialId]);
        $v = $stmt->fetchColumn();
        return $v !== false ? (int) $v : 0;
    }

    private static function salvarCursor(string $filialId, int $nsu): void
    {
        Database::getInstance()->prepare(
            "INSERT INTO nf_adn_nsu_cursor (filial_id, ultimo_nsu, atualizado_em)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE ultimo_nsu = VALUES(ultimo_nsu), atualizado_em = VALUES(atualizado_em)"
        )->execute([$filialId, $nsu]);
    }

    public static function registrarLog(string $filialId, string $inicio, array $r, ?int $nsuAte): void
    {
        try {
            $status = in_array($r['status'], ['ok', 'parcial', 'erro'], true) ? $r['status'] : 'ok';
            Database::getInstance()->prepare(
                "INSERT INTO nf_adn_log
                    (id, filial_id, status, iniciado_em, finalizado_em, docs_importadas, docs_ignoradas, detalhe, nsu_ate)
                 VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?)"
            )->execute([
                self::uuid(), $filialId, $status, $inicio,
                (int) $r['importadas'], (int) $r['ignoradas'],
                mb_substr($r['detalhe'], 0, 500), $nsuAte,
            ]);
        } catch (Throwable $e) {
            error_log('AdnSync: falha ao registrar log: ' . $e->getMessage());
        }
    }

    /** Último run de uma filial — alimenta os carimbos da tela, quando existir. */
    public static function ultimoStatus(string $filialId): ?array
    {
        try {
            $stmt = Database::getInstance()->prepare(
                "SELECT * FROM nf_adn_log WHERE filial_id = ? ORDER BY iniciado_em DESC LIMIT 1"
            );
            $stmt->execute([$filialId]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Auxiliares
    // ─────────────────────────────────────────────────────────────

    private static function salvarXml(string $chave, string $xml): void
    {
        if (trim($xml) === '') return;
        $base = (defined('STORAGE') ? STORAGE : ROOT . '/storage') . '/nfse_xml';
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        @file_put_contents($base . '/' . $chave . '.xml', $xml);
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
