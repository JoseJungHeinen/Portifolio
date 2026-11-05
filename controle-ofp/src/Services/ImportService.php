<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Models\Causal;
use App\Models\Ofp;
use App\Models\OfpEvento;
use App\Models\Unidade;
use App\Support\RegraException;
use Generator;
use PDOException;
use RuntimeException;

/**
 * Migração das planilhas (spec, seção 10). Idempotente: upsert pelo nº da OFP,
 * sem sobrescrever OFP criada ou mexida por usuário no sistema.
 */
class ImportService
{
    /** Cabeçalho normalizado da aba "Eurovale" → chave interna. */
    private const CABECALHO_V4 = [
        'unidade' => 'unidade', 'desenho' => 'desenho', 'peca' => 'peca', 'nfe' => 'nfe', 'volume' => 'volume',
        'coluna' => 'quantidade', 'vlr peca' => 'vlr_peca', 'motivo' => 'motivo', 'n bloqueio' => 'bloqueio',
        'n ofp' => 'ofp', 'dt coleta' => 'dt_coleta', 'status' => 'status', 'cod rastreamento' => 'rastreio',
        'dt inicio processo' => 'dt_inicio', 'nfe devolucao' => 'nf_devolucao', 'dt emissao devol' => 'dt_nf_devolucao',
        'nfe icms st' => 'nf_icms_st', 'dt emissao icms st' => 'dt_nf_icms_st', 'vlr devol' => 'vlr_devol',
        'vlr icms st' => 'vlr_icms_st', 'reanalise' => 'reanalise', 'coleta' => 'coleta', 'contato' => 'contato',
        'dt rcbto nfe devolucao' => 'dt_rcbto_devol', 'dt rcbto nfe icms st' => 'dt_rcbto_st',
    ];

    /** Aba "Planilha1" de "OFP - STATUS". */
    private const CABECALHO_STATUS = ['ofp' => 'ofp', 'status' => 'status', 'reanalise' => 'anotacao', 'protocolo' => 'protocolo'];

    /** Prioridade do status mapeado quando o nº da OFP se repete na v4: vale a linha de maior rank. */
    private const RANK_STATUS_V4 = [
        'estornada' => 10, 'creditada' => 9, 'aguardando_credito' => 8, 'aguardando_coleta' => 7, 'aprovada' => 6,
        'em_reanalise' => 5, 'rejeitada' => 4, 'em_analise' => 3, 'rejeitada_final' => 2, 'peca_vendida' => 2, 'cancelada' => 1,
    ];

    private array $unidades = [];
    private array $causais = [];
    private array $rejeitados = [];
    /** nº da OFP → [id, origem_importacao]; carregado no início de cada importação. */
    private array $mapa = [];
    private Ofp $ofps;
    private OfpEvento $eventos;

    public function __construct()
    {
        $this->ofps = new Ofp();
        $this->eventos = new OfpEvento();
        foreach ((new Unidade())->todas() as $u) {
            $this->unidades[$u['nome']] = $u['id'];
        }
        foreach ((new Causal())->todos() as $c) {
            $this->causais[$c['codigo']] = $c;
        }
    }

    /** @return array{inseridas: int, atualizadas: int, ignoradas: int, rejeitadas: int} */
    public function importarV4(string $arquivo): array
    {
        $stats = ['inseridas' => 0, 'atualizadas' => 0, 'ignoradas' => 0, 'rejeitadas' => 0];
        $this->mapa = $this->ofps->mapaNumeros();
        $db = Database::getInstance();

        // Passada 1: valida cada linha e agrupa as válidas por nº da OFP.
        $grupos = [];
        foreach ($this->linhas($arquivo, self::CABECALHO_V4) as $n => $l) {
            try {
                $numero = $this->numeroCampo($l['ofp'], 20, 'Nº OFP');
            } catch (RegraException $e) {
                $this->rejeitar('v4', $n, $e->getMessage(), $l, $stats);
                continue;
            }
            if ($numero === null) {
                if (!$this->vazia($l)) {
                    $this->rejeitar('v4', $n, 'Sem nº de OFP', $l, $stats);
                }
                continue;
            }
            $unidade = ImportNormalizer::unidade($l['unidade']);
            if ($unidade === null || !isset($this->unidades[$unidade])) {
                $this->rejeitar('v4', $n, 'Unidade não reconhecida: ' . $l['unidade'], $l, $stats);
                continue;
            }
            $codigo = ImportNormalizer::codigoCausal($l['motivo']);
            if ($codigo === null || !isset($this->causais[$codigo])) {
                $this->rejeitar('v4', $n, 'Causal não reconhecido: ' . $l['motivo'], $l, $stats);
                continue;
            }
            try {
                $this->montarV4($l, $numero, $this->unidades[$unidade], $this->causais[$codigo], 'em_analise', $n); // só valida tamanhos/faixas
            } catch (RegraException $e) {
                $this->rejeitar('v4', $n, $e->getMessage(), $l, $stats);
                continue;
            }
            $rank = self::RANK_STATUS_V4[ImportNormalizer::statusV4($l['status']) ?? 'em_analise'];
            $grupos[$numero][] = ['n' => $n, 'l' => $l, 'rank' => $rank, 'unidade' => $this->unidades[$unidade], 'causal' => $this->causais[$codigo]];
        }

        // Passada 2: por nº de OFP vale a linha de status mais avançado (empate: a última); as outras são rejeitadas.
        foreach ($grupos as $numero => $candidatas) {
            $escolhida = $candidatas[0];
            foreach ($candidatas as $c) {
                if ($c['rank'] >= $escolhida['rank']) {
                    $escolhida = $c;
                }
            }
            foreach ($candidatas as $c) {
                if ($c['n'] !== $escolhida['n']) {
                    $this->rejeitar('v4', $c['n'], "OFP duplicada — mantida a linha {$escolhida['n']} (status mais avançado)", $c['l'], $stats);
                }
            }
            $n = $escolhida['n'];
            $l = $escolhida['l'];
            // Cada linha na sua transação: uma linha ruim não derruba a importação.
            $db->beginTransaction();
            try {
                [$tipo, $id] = $this->gravarV4($l, (string)$numero, $escolhida['unidade'], $escolhida['causal'], $n);
                $db->commit();
            } catch (RegraException | PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $this->rejeitar('v4', $n, $e instanceof PDOException ? 'Erro de banco: ' . $e->getMessage() : $e->getMessage(), $l, $stats);
                continue;
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            if ($tipo === 'inseridas') {
                $this->mapa[$numero] = ['id' => $id, 'origem_importacao' => 'planilha_v4:linha ' . $n];
            }
            $stats[$tipo]++;
        }
        return $stats;
    }

    /** @return array{0: string, 1: ?string} [tipo de resultado, id da OFP] */
    private function gravarV4(array $l, string $numero, string $unidadeId, array $causal, int $linha): array
    {
        $status = ImportNormalizer::statusV4($l['status']) ?? 'em_analise';
        $existente = $this->mapa[$numero] ?? null;
        if ($existente) {
            // OFP criada ou mexida no sistema nunca é sobrescrita pela planilha.
            if ($existente['origem_importacao'] === null || $this->eventos->tocadaPorUsuario($existente['id'])) {
                return ['ignoradas', $existente['id']];
            }
            // Se o OFP-STATUS já mudou o status, a reimportação da v4 não o desfaz.
            if ($this->eventos->statusMudadoPelaPlanilha($existente['id'])) {
                $status = $this->ofps->find($existente['id'])['status'];
            }
        }
        $dados = $this->montarV4($l, $numero, $unidadeId, $causal, $status, $linha);
        if ($existente) {
            $this->ofps->update($existente['id'], $dados);
            return ['atualizadas', $existente['id']];
        }
        $id = $this->ofps->create($dados);
        $this->eventos->registrar($id, null, null, $status, 'Importado da planilha v4 (status original: ' . (trim($l['status']) ?: 'vazio') . ').');
        return ['inseridas', $id];
    }

    /** Nº de OFP/NF: null se vazio/não numérico; RegraException se maior que a coluna. */
    private function numeroCampo(string $valor, int $max, string $campo): ?string
    {
        $n = ImportNormalizer::numero($valor);
        if ($n !== null && ImportNormalizer::numero($valor, $max) === null) {
            throw new RegraException("{$campo} excede {$max} dígitos: {$n}");
        }
        return $n;
    }

    /** Valor decimal: null se vazio; RegraException se negativo ou maior que a coluna. */
    private function decimalCampo(string $valor, string $campo, float $limite = 1.0E10): ?float
    {
        $d = ImportNormalizer::decimal($valor);
        if ($d !== null && ($d < 0 || $d >= $limite)) {
            throw new RegraException("{$campo} fora do intervalo aceito: {$d}");
        }
        return $d;
    }

    private function montarV4(array $l, string $numero, string $unidadeId, array $causal, string $status, int $linha): array
    {
        $vlrDevol = $this->decimalCampo($l['vlr_devol'], 'VLR DEVOL');
        $vlrPeca = $this->decimalCampo($l['vlr_peca'], 'VLR PEÇA');
        $base = ($vlrDevol !== null && $vlrDevol > 0) ? $vlrDevol : $vlrPeca; // VLR DEVOL vazio ou zerado → VLR PEÇA
        $nfDevolucao = $this->numeroCampo($l['nf_devolucao'], 20, 'NFE DEVOLUÇÃO');
        $icms = $this->decimalCampo($l['vlr_icms_st'], 'VLR ICMS ST');
        $quantidade = $this->decimalCampo($l['quantidade'], 'COLUNA (quantidade)', 1.0E8);
        $dataNf = ImportNormalizer::data($l['dt_nf_devolucao']);
        $dataRecebimento = ImportNormalizer::data($l['dt_rcbto_devol']);
        $desenho = mb_strtoupper(trim($l['desenho']));

        $d = [
            'unidade_id'         => $unidadeId,
            'causal_id'          => $causal['id'],
            'numero_ofp'         => $numero,
            'desenho'            => $desenho !== '' ? mb_substr($desenho, 0, 30) : 'SEM DESENHO',
            'descricao_peca'     => mb_substr(trim($l['peca']), 0, 150) ?: null,
            'quantidade'         => $quantidade ?: 1,
            'valor_reclamado'    => $base ?? 0,
            'nf_origem'          => $this->numeroCampo($l['nfe'], 20, 'NFE'),
            'numero_volume'      => $this->numeroCampo($l['volume'], 30, 'VOLUME'),
            'status'             => $status,
            'data_abertura'      => ImportNormalizer::data($l['dt_inicio']),
            'data_coletada'      => ImportNormalizer::data($l['dt_coleta']),
            'codigo_rastreio'    => mb_substr(trim($l['rastreio']), 0, 40) ?: null,
            'nf_devolucao'       => $nfDevolucao,
            'data_nf_devolucao'  => $dataNf,
            'valor_nf_devolucao' => $nfDevolucao !== null ? $base : null,
            'nf_icms_st'         => $this->numeroCampo($l['nf_icms_st'], 20, 'NFE ICMS ST'),
            'data_nf_icms_st'    => ImportNormalizer::data($l['dt_nf_icms_st']),
            'valor_icms_st'      => $icms,
            'observacao'         => $this->observacaoV4($l),
            'origem_importacao'  => 'planilha_v4:linha ' . $linha,
            'data_credito'         => null,
            'valor_creditado_peca' => null,
            'valor_creditado_st'   => null,
        ];
        if ($status === 'creditada') {
            $d['data_credito'] = $dataRecebimento ?? ImportNormalizer::data($l['dt_rcbto_st']) ?? $dataNf;
            $d['valor_creditado_peca'] = $d['valor_nf_devolucao'] ?? $d['valor_reclamado'];
            $d['valor_creditado_st'] = $icms;
        } elseif ($status === 'aguardando_credito' && $dataRecebimento !== null
            && in_array(ImportNormalizer::texto($l['status']), ['recebto pendente tributo', 'recebimento tributo'], true)) {
            // "Recebto Pendente - Tributo": a peça já foi paga, falta o ICMS ST.
            $d['data_credito'] = $dataRecebimento;
            $d['valor_creditado_peca'] = $d['valor_nf_devolucao'] ?? $d['valor_reclamado'];
        }
        $prazoCausal = $causal['prazo_abertura_dias'] !== null ? (int)$causal['prazo_abertura_dias'] : null;
        return $d + PrazoService::padrao()->colunas($d, $prazoCausal);
    }

    private function observacaoV4(array $l): string
    {
        $partes = ['Importado da planilha Controle OFP v4'];
        foreach (['status' => 'Status original', 'reanalise' => 'Reanálise', 'coleta' => 'Coleta', 'contato' => 'Contato', 'bloqueio' => 'Nº bloqueio'] as $chave => $rotulo) {
            if (trim($l[$chave]) !== '') {
                $partes[] = $rotulo . ': ' . trim($l[$chave]);
            }
        }
        return implode(' | ', $partes);
    }

    /** @return array{atualizadas: int, anotadas: int, rejeitadas: int} */
    public function importarStatus(string $arquivo): array
    {
        $stats = ['atualizadas' => 0, 'anotadas' => 0, 'rejeitadas' => 0];
        $this->mapa = $this->ofps->mapaNumeros();
        $db = Database::getInstance();
        foreach ($this->linhas($arquivo, self::CABECALHO_STATUS) as $n => $l) {
            try {
                $numero = $this->numeroCampo($l['ofp'], 20, 'Nº OFP');
            } catch (RegraException $e) {
                $this->rejeitar('status', $n, $e->getMessage(), $l, $stats);
                continue;
            }
            if ($numero === null) {
                if (!$this->vazia($l)) {
                    $this->rejeitar('status', $n, 'Sem nº de OFP', $l, $stats);
                }
                continue;
            }
            $ref = $this->mapa[$numero] ?? null;
            if (!$ref) {
                $this->rejeitar('status', $n, 'OFP não encontrada na planilha v4 — cadastrar manualmente', $l, $stats);
                continue;
            }
            if ($ref['origem_importacao'] === null) {
                $this->rejeitar('status', $n, 'OFP criada no sistema — atualizar manualmente', $l, $stats);
                continue;
            }
            if (preg_match('/\d{31,}/', $l['protocolo'])) {
                $this->rejeitar('status', $n, 'Protocolo excede 30 dígitos', $l, $stats);
                continue;
            }
            $db->beginTransaction();
            try {
                $tipo = $this->gravarStatus($ref['id'], $l);
                $db->commit();
            } catch (RegraException | PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $this->rejeitar('status', $n, $e instanceof PDOException ? 'Erro de banco: ' . $e->getMessage() : $e->getMessage(), $l, $stats);
                continue;
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            if ($tipo !== null) {
                $stats[$tipo]++;
            }
        }
        return $stats;
    }

    /** @return ?string 'atualizadas', 'anotadas' ou null (anotação já existente) */
    private function gravarStatus(string $id, array $l): ?string
    {
        $ofp = $this->ofps->findComCausal($id);
        $observacao = 'Planilha OFP-STATUS: ' . implode(' | ', array_filter(array_map('trim', [$l['status'], $l['anotacao'], $l['protocolo']])));
        if ($this->eventos->existe($id, $observacao)) {
            return null;
        }
        $dados = preg_match('/(\d{6,})/', $l['protocolo'], $m) ? ['protocolo_grow' => $m[1]] : [];
        // OFP já mexida por usuário ou encerrada: só anota (e preenche o protocolo se estiver vazio).
        $somenteAnotar = $this->eventos->tocadaPorUsuario($id) || !StatusService::ativo($ofp['status']);
        if ($somenteAnotar && !empty($ofp['protocolo_grow'])) {
            $dados = [];
        }
        $novo = ImportNormalizer::statusOperacional($l['status']);
        if (!$somenteAnotar && $novo !== null && $novo !== $ofp['status']) {
            (new StatusService())->aplicar($ofp, $novo, $dados, null, $observacao);
            return 'atualizadas';
        }
        $this->ofps->update($id, $dados);
        $this->eventos->registrar($id, null, $ofp['status'], $ofp['status'], $observacao);
        return 'anotadas';
    }

    public function salvarRejeitados(string $arquivo): int
    {
        $h = fopen($arquivo, 'w');
        if (!$h) {
            throw new RuntimeException("Não foi possível gravar {$arquivo}");
        }
        fwrite($h, "\xEF\xBB\xBF"); // BOM para o Excel abrir em UTF-8
        fputcsv($h, ['planilha', 'linha', 'motivo', 'ofp', 'unidade', 'causal', 'status'], ';', '"', '');
        // Valores que o Excel leria como fórmula ganham um apóstrofo na frente.
        $seguro = fn($c) => is_string($c) && $c !== '' && str_contains("=+-@\t\r", $c[0]) ? "'" . $c : $c;
        foreach ($this->rejeitados as $r) {
            fputcsv($h, array_map($seguro, $r), ';', '"', '');
        }
        fclose($h);
        return count($this->rejeitados);
    }

    private function rejeitar(string $planilha, int $linha, string $motivo, array $l, array &$stats): void
    {
        $this->rejeitados[] = [$planilha, $linha, $motivo, $l['ofp'] ?? '', $l['unidade'] ?? '', $l['motivo'] ?? '', $l['status'] ?? ''];
        $stats['rejeitadas']++;
    }

    private function vazia(array $l): bool
    {
        return implode('', array_map('trim', $l)) === '';
    }

    /** Lê o CSV (UTF-8, vírgula) e entrega [nº da linha => valores pelas chaves internas]. */
    private function linhas(string $arquivo, array $mapa): Generator
    {
        $h = @fopen($arquivo, 'r');
        if (!$h) {
            throw new RuntimeException("Não foi possível abrir {$arquivo}");
        }
        $cabecalho = fgetcsv($h, 0, ',', '"', '') ?: [];
        $indices = [];
        foreach ($cabecalho as $i => $nome) {
            $chave = ImportNormalizer::texto(preg_replace('/^\xEF\xBB\xBF/', '', (string)$nome));
            if (isset($mapa[$chave]) && !isset($indices[$mapa[$chave]])) {
                $indices[$mapa[$chave]] = $i;
            }
        }
        $faltando = array_diff(array_values($mapa), array_keys($indices));
        if ($faltando) {
            fclose($h);
            throw new RuntimeException('Colunas não encontradas em ' . basename($arquivo) . ': ' . implode(', ', $faltando));
        }
        $n = 1;
        while (($linha = fgetcsv($h, 0, ',', '"', '')) !== false) {
            $n++;
            $valores = [];
            foreach ($indices as $chave => $i) {
                $valores[$chave] = (string)($linha[$i] ?? '');
            }
            yield $n => $valores;
        }
        fclose($h);
    }
}
