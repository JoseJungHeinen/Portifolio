<?php
// @versao Apo_03 V_01_25/07/2026
declare(strict_types=1);

require_once SRC . '/Helpers/ApolloClient.php';
require_once SRC . '/Models/Process.php';

/**
 * Orquestra a consulta em lote de chaves de processos no Apollo.
 *
 * Fluxo à prova de falha parcial:
 *   Fase 1 — lê o Apollo UMA vez (read-only, todas as chaves de uma vez, em
 *            blocos no bridge). Ou volta o conjunto todo, ou falha inteira.
 *   Fase 2 — grava no Notas processo a processo, cada um em transação própria
 *            (observação + encerramento opcional). Uma falha isolada marca o
 *            item como `erro` e o lote continua; permite retomar depois.
 *
 * Escopo: somente processos `status='aberto'` do template escolhido, com chave.
 * Nota CANCELADA no Apollo (DTA_CANCELAMENTO_NOTA preenchida) vira alerta e NÃO
 * encerra. Encerramento usa o status terminal próprio `encerrado_apollo`.
 */
class ApolloConsulta
{
    /**
     * Templates (tipos de processo) que têm processos abertos com chave —
     * fonte do dropdown da tela.
     *
     * @return list<array{id:string,nome:string,qtd:int}>
     */
    public static function templatesElegiveis(): array
    {
        $stmt = Database::getInstance()->query(
            "SELECT t.id, t.nome, COUNT(*) AS qtd
               FROM nf_processos p
               JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
               JOIN nf_templates t ON t.id = tv.template_id
              WHERE p.status = 'aberto'
                AND p.deletado_em IS NULL
                AND p.chave_nf IS NOT NULL AND p.chave_nf <> ''
              GROUP BY t.id, t.nome
              ORDER BY t.nome ASC"
        );
        return $stmt->fetchAll();
    }

    /**
     * Processos abertos, com chave, de um template.
     *
     * @return list<array{id:string,codigo:string,chave_nf:string,etapa_atual:int}>
     */
    public static function processosElegiveis(string $templateId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT p.id, p.codigo, p.chave_nf, p.etapa_atual
               FROM nf_processos p
               JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
              WHERE tv.template_id = ?
                AND p.status = 'aberto'
                AND p.deletado_em IS NULL
                AND p.chave_nf IS NOT NULL AND p.chave_nf <> ''
              ORDER BY p.criado_em ASC"
        );
        $stmt->execute([$templateId]);
        return $stmt->fetchAll();
    }

    /**
     * Executa a consulta em lote.
     *
     * @return array{
     *   consulta_id:string, total:int, encontradas:int, canceladas:int,
     *   nao_encontradas:int, erros:int, encerrados:int, status:string
     * }
     */
    public static function executar(
        string $templateId,
        string $templateNome,
        bool $encerrarAoAchar,
        string $usuarioId,
        string $usuarioNome
    ): array {
        $pdo = Database::getInstance();

        $processos = self::processosElegiveis($templateId);
        $consultaId = self::uuid();

        // Cabeçalho do lote
        $pdo->prepare(
            "INSERT INTO nf_apollo_consultas
                (id, template_id, template_nome, encerrar_ao_achar, total, status, executado_por)
             VALUES (?, ?, ?, ?, ?, 'rodando', ?)"
        )->execute([$consultaId, $templateId, $templateNome, $encerrarAoAchar ? 1 : 0, count($processos), $usuarioId]);

        $resumo = [
            'consulta_id'     => $consultaId,
            'total'           => count($processos),
            'encontradas'     => 0,
            'canceladas'      => 0,
            'nao_encontradas' => 0,
            'erros'           => 0,
            'encerrados'      => 0,
            'status'          => 'concluida',
        ];

        if (empty($processos)) {
            self::finalizar($consultaId, $resumo, 'concluida', null);
            return $resumo;
        }

        // ── Fase 1: ler o Apollo (uma vez) ────────────────────────────────
        $chaves = [];
        foreach ($processos as $p) {
            $limpa = preg_replace('/\D/', '', (string) $p['chave_nf']);
            if (strlen($limpa) === 44) {
                $chaves[$limpa] = true;
            }
        }
        try {
            $apollo = ApolloClient::consultarChaves(array_keys($chaves));
        } catch (Throwable $e) {
            // Falha global — nada foi gravado nos processos.
            $resumo['status'] = 'falha';
            self::finalizar($consultaId, $resumo, 'falha', substr($e->getMessage(), 0, 300));
            AuditLog::registrar('nf_apollo', $consultaId, 'nf_apollo.consulta_falha', null, ['erro' => $e->getMessage()]);
            $resumo['erro'] = $e->getMessage();
            return $resumo;
        }

        // ── Fase 2: gravar processo a processo (atômico por processo) ─────
        $hoje = date('d/m/Y');
        foreach ($processos as $p) {
            $limpa = preg_replace('/\D/', '', (string) $p['chave_nf']);
            $consta = $limpa !== '' && isset($apollo[$limpa]) ? $apollo[$limpa] : null;

            try {
                $pdo->beginTransaction();

                if ($consta === null) {
                    // Não consta — não mexe no processo.
                    $resultado = 'nao_encontrada';
                    $encerrado = false;
                    $detalhe   = null;
                } elseif (!empty($consta['cancelada'])) {
                    // Consta porém CANCELADA — alerta, não encerra.
                    $resultado = 'cancelada_apollo';
                    $encerrado = false;
                    $detalhe   = 'Consta no Apollo porém CANCELADA';
                    Process::registrarHistorico(
                        $p['id'], 'nf_apollo_consulta', $usuarioId, $usuarioNome,
                        "Consulta Apollo em {$hoje}: nota CONSTA porém está CANCELADA no Apollo — verificar. Processo NÃO encerrado.",
                        (int) $p['etapa_atual']
                    );
                } else {
                    // Consta e válida.
                    $resultado = 'encontrada';
                    $encerrado = false;
                    $valorTxt  = $consta['valor'] !== null ? ('R$ ' . number_format((float) $consta['valor'], 2, ',', '.')) : '—';
                    $dataTxt   = $consta['data'] ?? '—';
                    $detalhe   = "valor {$valorTxt}, data {$dataTxt}";

                    $msg = "Consulta Apollo em {$hoje}: nota localizada em FAT_MOVIMENTO_CAPA ({$detalhe}).";
                    if ($encerrarAoAchar) {
                        self::encerrarProcesso($pdo, (string) $p['id']);
                        $encerrado = true;
                        $msg .= ' Processo encerrado via Apollo.';
                        Process::registrarHistorico(
                            $p['id'], 'processo_encerrado_apollo', $usuarioId, $usuarioNome,
                            $msg, (int) $p['etapa_atual']
                        );
                    } else {
                        Process::registrarHistorico(
                            $p['id'], 'nf_apollo_consulta', $usuarioId, $usuarioNome,
                            $msg, (int) $p['etapa_atual']
                        );
                    }
                }

                self::inserirItem($pdo, $consultaId, (string) $p['id'], $limpa, $resultado, $encerrado, $detalhe);
                $pdo->commit();

                // Contadores
                if ($resultado === 'encontrada')      { $resumo['encontradas']++; if ($encerrado) $resumo['encerrados']++; }
                elseif ($resultado === 'cancelada_apollo') { $resumo['canceladas']++; }
                else { $resumo['nao_encontradas']++; }

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $resumo['erros']++;
                // Item de erro fora da transação que falhou.
                try {
                    self::inserirItem($pdo, $consultaId, (string) $p['id'], $limpa, 'erro', false, substr($e->getMessage(), 0, 300));
                } catch (Throwable $ignore) {
                    // se nem o item de erro grava, segue o lote mesmo assim
                }
            }
        }

        self::finalizar($consultaId, $resumo, 'concluida', null);
        AuditLog::registrar('nf_apollo', $consultaId, 'nf_apollo.consulta_concluida', null, $resumo);
        return $resumo;
    }

    /** Encerra o processo com status terminal próprio `encerrado_apollo`. */
    private static function encerrarProcesso(PDO $pdo, string $processoId): void
    {
        // Posiciona etapa_atual na última etapa do processo (visual coerente).
        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(numero_etapa), 0) FROM nf_processo_etapas WHERE processo_id = ?"
        );
        $stmt->execute([$processoId]);
        $ultima = (int) $stmt->fetchColumn();

        $upd = $pdo->prepare(
            "UPDATE nf_processos
                SET status = 'encerrado_apollo',
                    concluido_em = NOW(),
                    etapa_atual = GREATEST(etapa_atual, ?)
              WHERE id = ? AND status = 'aberto' AND deletado_em IS NULL"
        );
        $upd->execute([$ultima, $processoId]);
        if ($upd->rowCount() === 0) {
            throw new RuntimeException('Processo não pôde ser encerrado (não está mais aberto).');
        }
    }

    private static function inserirItem(
        PDO $pdo, string $consultaId, string $processoId, string $chave,
        string $resultado, bool $encerrado, ?string $detalhe
    ): void {
        $pdo->prepare(
            "INSERT INTO nf_apollo_consulta_itens
                (id, consulta_id, processo_id, chave_nf, resultado, encerrado, detalhe)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            self::uuid(), $consultaId, $processoId,
            $chave !== '' ? $chave : null,
            $resultado, $encerrado ? 1 : 0, $detalhe,
        ]);
    }

    /** Fecha o cabeçalho do lote com contadores e status final. */
    private static function finalizar(string $consultaId, array $resumo, string $status, ?string $detalhe): void
    {
        Database::getInstance()->prepare(
            "UPDATE nf_apollo_consultas
                SET encontradas = ?, canceladas = ?, nao_encontradas = ?, erros = ?,
                    status = ?, detalhe = ?, finalizado_em = NOW()
              WHERE id = ?"
        )->execute([
            $resumo['encontradas'], $resumo['canceladas'], $resumo['nao_encontradas'],
            $resumo['erros'], $status, $detalhe, $consultaId,
        ]);
    }

    private static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
