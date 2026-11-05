<?php
// @versao Cap_12 V_02_18/07/2026
declare(strict_types=1);

/**
 * Backfill histórico do Brobot por filial.
 *
 * O poller (BrobotSync) só anda para frente a partir de um cursor global. Ao
 * LIGAR uma filial nova, as notas dela que já estavam no Brobot ficam atrás do
 * cursor e nunca seriam capturadas. Este módulo enfileira, ao ligar a filial,
 * uma varredura da janela [desde, ate] importando só as notas daquela filial
 * (dedup por chave protege contra duplicar). O cursor incremental segue
 * intacto cobrindo [ate, ∞).
 *
 * Fluxo:
 *   - enfileirar(): cria/reinicia um job 'pendente' para a filial (chamado ao
 *     salvar as flags em Geral > Brobot quando uma filial passa de 0 → 1).
 *   - run(): faz UMA varredura da janela unificada [min(desde), max(ate)] de
 *     TODOS os jobs pendentes/rodando ao mesmo tempo, importando as notas de
 *     qualquer uma das filiais da fila numa única passada (a API do Brobot não
 *     filtra por destinatário e devolve o XML inteiro — varrer uma vez para N
 *     filiais em vez de N vezes economiza tempo e carga proporcional a N).
 *     Avança um cursor compartilhado; ao alcançar o fim da janela marca os
 *     jobs como 'concluido'. Agendado no cron (cli/brobot_backfill.php) a cada
 *     5 min, em paralelo ao poller incremental.
 *
 * Requer (no contexto CLI): BrobotClient, XmlDfeParser, BrobotSync já incluídos.
 * enfileirar() depende apenas de Database (seguro chamar do controller web).
 */
class BrobotBackfill
{
    private const MAX_PAGINAS = 40;
    private const PER_PAGE    = 50;

    /**
     * Enfileira (ou reinicia) o backfill de uma filial. Mantém no máximo um job
     * ativo por filial: pendentes/rodando anteriores são descartados.
     *
     * @param int     $dias janela retroativa a varrer
     * @param ?string $ate  fim da janela (default: agora)
     */
    public static function enfileirar(string $filialId, string $cnpj, int $dias, ?string $ate = null): string
    {
        $pdo = Database::getInstance();
        $pdo->prepare(
            "DELETE FROM nf_brobot_backfill WHERE filial_id = ? AND status IN ('pendente','rodando')"
        )->execute([$filialId]);

        $id    = self::uuid();
        $desde = date('Y-m-d H:i:s', time() - max(1, $dias) * 86400);
        $ate   = $ate ?: date('Y-m-d H:i:s');
        $pdo->prepare(
            "INSERT INTO nf_brobot_backfill (id, filial_id, cnpj, desde, ate, status)
             VALUES (?, ?, ?, ?, ?, 'pendente')"
        )->execute([$id, $filialId, $cnpj, $desde, $ate]);

        return $id;
    }

    /**
     * Faz um lote da varredura unificada: uma passada no Brobot que atende
     * TODOS os jobs pendentes/rodando de uma vez (importa notas de qualquer
     * filial da fila). Avança um cursor compartilhado; ao terminar a janela
     * marca todos os jobs do lote como 'concluido'.
     *
     * @return array{status:string, filiais:int, importadas:int, varridos:int, detalhe:string}
     */
    public static function run(int $maxPaginas = self::MAX_PAGINAS): array
    {
        $pdo  = Database::getInstance();
        $jobs = $pdo->query(
            "SELECT b.*, f.nome AS filial_nome
             FROM nf_brobot_backfill b
             LEFT JOIN filiais f ON f.id = b.filial_id
             WHERE b.status IN ('pendente','rodando')
             ORDER BY b.criado_em ASC"
        )->fetchAll();

        if (!$jobs) {
            return ['status' => 'idle', 'filiais' => 0, 'importadas' => 0, 'varridos' => 0,
                    'detalhe' => 'Nenhum backfill pendente.'];
        }

        $ids = array_column($jobs, 'id');

        $client = BrobotClient::fromSettings();
        if (!$client->temToken()) {
            self::atualizarLote($pdo, $ids, 'erro', null, 0, 'Token Brobot não configurado.');
            return ['status' => 'erro', 'filiais' => count($jobs), 'importadas' => 0,
                    'varridos' => 0, 'detalhe' => 'Token não configurado.'];
        }

        // Marca o lote como rodando.
        self::marcarStatus($pdo, $ids, 'rodando');

        // Janela unificada + conjunto de CNPJs de todos os jobs do lote.
        // cursor_ate (progresso) tem prioridade sobre desde; jobs novos (sem
        // cursor) puxam o início de volta e a passada os cobre — dedup protege.
        $cnpjs = []; $desdeMin = null; $ateMax = null;
        foreach ($jobs as $j) {
            $cnpjs[$j['cnpj']] = true;
            $ini = $j['cursor_ate'] ?: $j['desde'];
            if ($desdeMin === null || strtotime($ini) < strtotime($desdeMin)) $desdeMin = $ini;
            if ($ateMax   === null || strtotime((string) $j['ate']) > strtotime($ateMax)) $ateMax = (string) $j['ate'];
        }
        $cnpjSet  = array_keys($cnpjs);
        $startIso = gmdate('Y-m-d\TH:i:s\Z', strtotime($desdeMin));
        $ateIso   = gmdate('Y-m-d\TH:i:s\Z', strtotime($ateMax));

        $filiais    = BrobotSync::mapaFiliais();
        $importadas = 0; $varridos = 0; $erro = null;
        $cursorNovo = $desdeMin;
        $concluido  = false;

        for ($page = 1; $page <= $maxPaginas; $page++) {
            $resp = $client->listarDocumentos([
                'per_page' => self::PER_PAGE,
                'page'     => $page,
                'order'    => 'asc',
                'q'        => ['start_date' => $startIso, 'end_date' => $ateIso],
            ]);

            if (!$resp['ok']) { $erro = $resp['erro'] ?? 'falha desconhecida'; break; }
            if (empty($resp['data'])) { $concluido = true; break; }

            foreach ($resp['data'] as $doc) {
                $varridos++;
                if (BrobotSync::processarDoc($doc, $filiais, $cnpjSet) === 'importada') {
                    $importadas++;
                }
                $criado = $doc['created_at'] ?? ($doc['issued_at'] ?? null);
                if ($criado && (!$cursorNovo || strtotime($criado) > strtotime($cursorNovo))) {
                    $cursorNovo = date('Y-m-d H:i:s', strtotime($criado));
                }
            }

            $meta = $resp['meta'];
            $cur  = (int) ($meta['current_page'] ?? $page);
            $tot  = (int) ($meta['total_pages']  ?? $cur);
            if ($cur >= $tot) { $concluido = true; break; }
        }

        $status  = $erro !== null ? 'erro' : ($concluido ? 'concluido' : 'rodando');
        $detalhe = $erro !== null
            ? "Erro: {$erro}"
            : ($concluido ? 'Backfill concluído.' : 'Lote processado — continua na próxima rodada.');

        // Atualiza todos os jobs do lote: mesmo cursor; importadas contadas por
        // filial direto da staging (notas dela capturadas após o job entrar).
        self::atualizarLote($pdo, $ids, $status, $cursorNovo, $varridos, $detalhe, $jobs);

        return ['status' => $status, 'filiais' => count($jobs), 'importadas' => $importadas,
                'varridos' => $varridos, 'detalhe' => $detalhe];
    }

    private static function marcarStatus(\PDO $pdo, array $ids, string $status): void
    {
        if (!$ids) return;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE nf_brobot_backfill SET status = ? WHERE id IN ($in)")
            ->execute(array_merge([$status], $ids));
    }

    /**
     * Grava o progresso do lote. docs_importadas de cada filial vem da staging
     * (notas do CNPJ capturadas a partir da criação do job) — assim o número
     * bate mesmo com a passada compartilhada, sem depender de contagem por doc.
     *
     * @param array<int,array>|null $jobs linhas dos jobs (p/ contar por CNPJ)
     */
    private static function atualizarLote(
        \PDO $pdo, array $ids, string $status, ?string $cursor, int $varridos, string $detalhe, ?array $jobs = null
    ): void {
        if (!$ids) return;
        $detalhe = mb_substr($detalhe, 0, 500);

        if ($jobs === null) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE nf_brobot_backfill SET status = ?, detalhe = ? WHERE id IN ($in)")
                ->execute(array_merge([$status, $detalhe], $ids));
            return;
        }

        $cnt = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_notas_capturadas WHERE destinatario_cnpj = ? AND capturada_em >= ?"
        );
        $upd = $pdo->prepare(
            "UPDATE nf_brobot_backfill
             SET status = ?, cursor_ate = ?, docs_importadas = ?, docs_varridos = docs_varridos + ?, detalhe = ?
             WHERE id = ?"
        );
        foreach ($jobs as $j) {
            $cnt->execute([$j['cnpj'], $j['criado_em']]);
            $importadasFilial = (int) $cnt->fetchColumn();
            $upd->execute([$status, $cursor, $importadasFilial, $varridos, $detalhe, $j['id']]);
        }
    }

    private static function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}
