<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Models\Ofp;
use App\Models\OfpEvento;
use App\Support\Alteracoes;
use App\Support\RegraException;
use App\Support\ValidacaoException;
use Throwable;

/** Correção de dados já lançados (NF, datas, valores), com motivo e histórico antes → depois. */
class CorrecaoService
{
    public const TIPOS = [
        'digitacao'      => 'Erro de digitação',
        'carta_correcao' => 'Carta de correção',
        'reemissao'      => 'NF cancelada e reemitida',
        'fabricante'     => 'Ajuste pedido pela Fabricante',
    ];

    private const OPERACAO = [
        'numero_ofp', 'data_abertura', 'data_decisao', 'protocolo_grow', 'data_reanalise',
        'nf_devolucao', 'data_nf_devolucao', 'valor_nf_devolucao', 'nf_compartilhada', 'nf_icms_st', 'data_nf_icms_st', 'valor_icms_st',
        'data_coleta_solicitada', 'data_coletada', 'codigo_rastreio',
    ];
    private const FINANCEIRO = ['titulo_apollo', 'data_credito'];

    /** Só mudam pela baixa (crédito) ou pelo estorno. */
    private const CREDITADO = ['valor_creditado_peca', 'valor_creditado_st'];
    /** Em OFP creditada, valores só mudam por estorno. */
    private const MONETARIOS = ['valor_nf_devolucao', 'valor_icms_st', 'valor_reclamado', 'quantidade'];
    /** Só fazem sentido (e só se corrigem) depois do crédito. */
    private const POS_CREDITO = ['titulo_apollo', 'data_credito'];

    /** Ordem linear do fluxo principal: um campo vazio só é preenchido quando a OFP já chegou à etapa que o coleta. */
    public const ETAPAS = [
        'registrada' => 0, 'em_analise' => 10, 'rejeitada' => 15, 'em_reanalise' => 16, 'aprovada' => 20, 'aguardando_tp41' => 20,
        'nf_emitida' => 30, 'aguardando_coleta' => 40, 'coletada' => 50, 'aguardando_credito' => 60, 'creditada' => 70,
    ];

    /**
     * @param array|null $ofp OFP (precisa de 'status'); sem ela, só o limite do perfil
     * @return string[] campos que o perfil pode corrigir (nesta OFP, quando informada)
     */
    public static function camposPermitidos(string $perfil, ?array $ofp = null): array
    {
        $campos = match ($perfil) {
            'gerente', 'admin' => array_merge(self::OPERACAO, self::FINANCEIRO),
            'comprador'        => self::OPERACAO,
            'financeiro'       => self::FINANCEIRO,
            default            => [],
        };
        if ($ofp === null) {
            return $campos;
        }
        $status = (string)$ofp['status'];
        if (!self::corrigivel($status)) {
            return [];
        }
        if ($status !== 'creditada') {
            $campos = array_diff($campos, self::POS_CREDITO);
        } else {
            $campos = array_diff($campos, self::MONETARIOS);
        }
        return array_values($campos);
    }

    private static function corrigivel(string $status): bool
    {
        return $status === 'creditada' || StatusService::ativo($status);
    }

    /** Etapa mínima em que o campo é coletado (null = sem restrição). */
    private static function etapaDoCampo(string $campo): ?int
    {
        $etapa = null;
        foreach (self::ETAPAS as $status => $n) {
            if (in_array($campo, StatusService::camposObrigatorios($status), true) || in_array($campo, StatusService::camposOpcionais($status), true)) {
                $etapa = $etapa === null ? $n : min($etapa, $n);
            }
        }
        return $etapa;
    }

    /** @return int quantidade de campos alterados */
    public function corrigir(string $ofpId, array $entrada, array $usuario): int
    {
        if (!self::camposPermitidos($usuario['perfil'])) {
            throw new RegraException('Seu perfil não pode corrigir dados de OFP.');
        }
        $ofp = (new Ofp())->findComCausal($ofpId);
        if (!$ofp || !unidade_permitida($ofp['unidade_id'])) {
            throw new RegraException('OFP não encontrada.');
        }
        if (!self::corrigivel($ofp['status'])) {
            throw new RegraException('OFP encerrada não pode ser corrigida.');
        }
        $permitidos = self::camposPermitidos($usuario['perfil'], $ofp);
        if (!$permitidos) {
            throw new RegraException('Nenhum dado desta OFP pode ser corrigido pelo seu perfil nesta situação.');
        }

        $erros = [];
        $tipo = is_string($entrada['tipo_correcao'] ?? null) ? $entrada['tipo_correcao'] : '';
        if (!isset(self::TIPOS[$tipo])) {
            $erros['tipo_correcao'] = 'Informe o tipo da correção.';
        }
        $observacao = is_string($entrada['observacao'] ?? null) ? trim($entrada['observacao']) : '';
        if ($observacao === '') {
            $erros['observacao'] = 'Descreva o que foi corrigido e por quê.';
        }
        foreach (array_keys($entrada) as $campo) {
            if (in_array($campo, self::CREDITADO, true)) {
                $erros[$campo] = 'O valor creditado só muda pela baixa do crédito ou pelo estorno.';
            } elseif ($ofp['status'] === 'creditada' && in_array($campo, self::MONETARIOS, true)) {
                $erros[$campo] = 'OFP creditada: valores só mudam por estorno.';
            } elseif (isset(StatusService::CAMPOS[$campo]) && $campo !== 'observacao' && !in_array($campo, $permitidos, true)) {
                $erros[$campo] = 'Este dado não pode ser corrigido por você nesta situação.';
            }
        }
        if ($erros) {
            throw new ValidacaoException($erros);
        }

        $dados = array_intersect_key(StatusService::normalizarDados($entrada), array_flip($permitidos));
        $alteracoes = Alteracoes::calcular($ofp, $dados, array_keys($dados));
        if (!$alteracoes) {
            throw new ValidacaoException(['geral' => 'Nenhum valor foi alterado.']);
        }
        $mudou = array_intersect_key($dados, array_flip(array_column($alteracoes, 'campo')));

        $erros = StatusService::validarCampos($ofp, $mudou);
        $etapaAtual = self::ETAPAS[$ofp['status']] ?? 0;
        foreach ($mudou as $campo => $valor) {
            $vazioAntes = ($ofp[$campo] ?? null) === null || $ofp[$campo] === '';
            if ($valor === null && in_array($campo, StatusService::todosObrigatorios(), true)) {
                $erros[$campo] = 'Este campo não pode ficar vazio.';
            } elseif ($valor !== null && $vazioAntes && $campo !== 'nf_compartilhada') {
                $etapa = self::etapaDoCampo($campo);
                if ($campo === 'data_decisao') {
                    $erros[$campo] = 'A decisão entra pela mudança de status, não pela correção.';
                } elseif ($etapa !== null && $etapaAtual < $etapa) {
                    $erros[$campo] = 'Este dado ainda não é coletado na etapa atual da OFP.';
                } elseif (in_array($campo, ['protocolo_grow', 'data_reanalise'], true) && empty($ofp['data_rejeicao']) && !in_array($ofp['status'], ['rejeitada', 'em_reanalise'], true)) {
                    $erros[$campo] = 'Esta OFP não foi rejeitada; não há caso GROW.';
                } elseif (in_array($campo, ['data_coleta_solicitada', 'data_coletada', 'codigo_rastreio'], true) && $ofp['tipo_devolucao'] === 'simbolica') {
                    $erros[$campo] = 'Devolução simbólica não tem coleta.';
                }
            }
        }
        if (isset($mudou['data_nf_devolucao']) && !isset($erros['data_nf_devolucao']) && !empty($ofp['data_credito'])
            && substr((string)$mudou['data_nf_devolucao'], 0, 10) > substr((string)$ofp['data_credito'], 0, 10)) {
            $erros['data_nf_devolucao'] = 'A data da NF de devolução não pode ser posterior à data do crédito.';
        }
        if (array_key_exists('nf_icms_st', $mudou) || array_key_exists('valor_icms_st', $mudou)) {
            $final = array_merge($ofp, $mudou);
            $temNf = ($final['nf_icms_st'] ?? null) !== null && $final['nf_icms_st'] !== '';
            $temValor = ($final['valor_icms_st'] ?? null) !== null && $final['valor_icms_st'] !== '';
            if ($temNf !== $temValor) {
                $erros['valor_icms_st'] = 'Informe a NF e o valor do ICMS ST juntos (ou deixe os dois em branco).';
            }
        }
        if ($erros) {
            throw new ValidacaoException($erros);
        }
        if (!empty($mudou['numero_ofp'])) {
            $outra = (new Ofp())->findByNumero((string)$mudou['numero_ofp']);
            if ($outra && $outra['id'] !== $ofp['id']) {
                throw new ValidacaoException(['numero_ofp' => 'Nº OFP já cadastrado em outra OFP.']);
            }
        }

        // NF única por OFP: avalia também quando só a marca "compartilhada" mudou (com a NF já gravada).
        $marcaPedida = !empty($mudou['nf_compartilhada']);
        $paraNf = $mudou;
        $nfInjetada = false;
        if (array_key_exists('nf_compartilhada', $mudou) && !array_key_exists('nf_devolucao', $mudou) && !empty($ofp['nf_devolucao'])) {
            $paraNf['nf_devolucao'] = $ofp['nf_devolucao'];
            $nfInjetada = true;
        }
        $mudou = (new StatusService())->garantirNfUnica($ofp, $paraNf, $observacao);
        if ($nfInjetada) {
            unset($mudou['nf_devolucao']);
        }
        $alteracoes = Alteracoes::calcular($ofp, $mudou, array_keys($mudou));
        if (!$alteracoes) {
            if ($marcaPedida) {
                throw new ValidacaoException(['nf_compartilhada' => 'Nenhuma outra OFP usa esta NF; a marca não se aplica.']);
            }
            throw new ValidacaoException(['geral' => 'Nenhum valor foi alterado.']);
        }
        $mudou = array_intersect_key($mudou, array_flip(array_column($alteracoes, 'campo')));

        // data_decisao corrigida: acompanha a data de rejeição/aprovação que era igual a ela (e só essa).
        $extras = [];
        if (isset($mudou['data_decisao'])) {
            $antiga = substr((string)$ofp['data_decisao'], 0, 10);
            foreach (['data_rejeicao', 'data_aprovacao'] as $coluna) {
                if (!empty($ofp[$coluna]) && substr((string)$ofp[$coluna], 0, 10) === $antiga) {
                    $extras[$coluna] = $mudou['data_decisao'];
                }
            }
        }
        $prazoCausal = $ofp['prazo_abertura_dias'] !== null ? (int)$ofp['prazo_abertura_dias'] : null;
        $prazo = PrazoService::padrao()->colunas(array_merge($ofp, $mudou), $prazoCausal);
        $gravar = $mudou + $extras + $prazo;
        $gravar['perda_sugerida'] = PerdaService::sugerir(array_merge($ofp, $gravar), new \DateTimeImmutable('today'));
        $historico = array_merge(
            $alteracoes,
            Alteracoes::calcular($ofp, $extras + ['prazo_data' => $prazo['prazo_data'] ?? null], [...array_keys($extras), 'prazo_data'])
        );
        // Também as datas que alimentam o prazo: se mudaram no meio, o prazo calculado aqui estaria velho.
        $antigos = [];
        foreach (['data_recebimento', 'data_abertura', 'data_reanalise', 'data_decisao', 'data_coleta_solicitada', 'data_nf_devolucao', 'data_reabertura_credito'] as $coluna) {
            $antigos[$coluna] = $ofp[$coluna] ?? null;
        }
        foreach (array_keys($mudou + $extras) as $coluna) {
            $antigos[$coluna] = $ofp[$coluna] ?? null;
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            if ((new Ofp())->updateOndeIgual($ofp['id'], $gravar, ['status' => $ofp['status']], $antigos) === 0) {
                throw new RegraException('A OFP foi alterada por outra pessoa; recarregue e tente de novo.');
            }
            (new OfpEvento())->registrar($ofp['id'], $usuario['id'], $ofp['status'], $ofp['status'],
                'Correção — ' . self::TIPOS[$tipo] . ': ' . $observacao, null, $historico);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return count($alteracoes);
    }
}
