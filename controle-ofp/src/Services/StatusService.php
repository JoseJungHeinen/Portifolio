<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use App\Config\Database;
use App\Models\Motivo;
use App\Models\Ofp;
use App\Models\OfpEvento;
use App\Support\Alteracoes;
use App\Support\ValidacaoException;
use App\Support\RegraException;
use Throwable;

/**
 * Máquina de estados da OFP (spec, seção 5).
 * Métodos estáticos = regras puras; métodos de instância (Task 6) gravam no banco.
 */
class StatusService
{
    public const LABELS = [
        'registrada'         => 'Ocorrência registrada',
        'em_analise'         => 'Em análise',
        'rejeitada'          => 'Rejeitada',
        'em_reanalise'       => 'Em reanálise',
        'rejeitada_final'    => 'Rejeitada final',
        'aprovada'           => 'Aprovada',
        'aguardando_tp41'    => 'Aguardando TP41',
        'nf_emitida'         => 'NF devolução emitida',
        'aguardando_coleta'  => 'Aguardando coleta',
        'coletada'           => 'Coletada',
        'aguardando_credito' => 'Aguardando crédito',
        'creditada'          => 'Creditada',
        'perdida_prazo'      => 'Perdida no prazo',
        'encerrada'          => 'Encerrada',
        'cancelada'          => 'Cancelada',
        'peca_vendida'       => 'Peça vendida/desistência',
        'estornada'          => 'Estornada',
    ];

    public const CORES = [
        'registrada' => 'secondary', 'em_analise' => 'info', 'rejeitada' => 'danger', 'em_reanalise' => 'warning',
        'rejeitada_final' => 'dark', 'aprovada' => 'primary', 'aguardando_tp41' => 'warning', 'nf_emitida' => 'primary',
        'aguardando_coleta' => 'warning', 'coletada' => 'info', 'aguardando_credito' => 'warning', 'creditada' => 'success',
        'perdida_prazo' => 'dark', 'encerrada' => 'dark', 'cancelada' => 'secondary', 'peca_vendida' => 'secondary',
        'estornada' => 'dark',
    ];

    /** Status que não contam como valor em aberto nem geram prazo. */
    public const INATIVOS = ['creditada', 'rejeitada_final', 'perdida_prazo', 'encerrada', 'cancelada', 'peca_vendida', 'estornada'];

    /** Perfis que podem executar qualquer transição prevista e ainda cancelar/marcar venda. */
    public const SUPERVISORES = ['gerente', 'admin'];

    /** status atual => [destino => perfis que podem executar] */
    private const FLUXO = [
        'registrada'         => ['em_analise' => ['estoque', 'comprador'], 'perdida_prazo' => ['estoque', 'comprador'], 'cancelada' => ['estoque', 'comprador']],
        'em_analise'         => ['aprovada' => ['comprador'], 'rejeitada' => ['comprador'], 'cancelada' => ['comprador']],
        'rejeitada'          => ['em_reanalise' => ['comprador'], 'rejeitada_final' => ['comprador']],
        'em_reanalise'       => ['em_analise' => ['comprador'], 'aprovada' => ['comprador'], 'rejeitada_final' => ['comprador']],
        'aprovada'           => ['aguardando_tp41' => ['comprador'], 'nf_emitida' => ['comprador'], 'encerrada' => ['comprador'], 'peca_vendida' => ['comprador']],
        'aguardando_tp41'    => ['nf_emitida' => ['comprador'], 'encerrada' => ['comprador']],
        'nf_emitida'         => ['aguardando_coleta' => ['comprador'], 'aguardando_credito' => ['comprador']],
        'aguardando_coleta'  => ['coletada' => ['comprador', 'estoque']],
        'coletada'           => ['aguardando_credito' => ['estoque']],
        'aguardando_credito' => ['creditada' => ['financeiro'], 'estornada' => ['financeiro']],
        'creditada'          => ['estornada' => ['financeiro']],
        'estornada'          => ['aguardando_credito' => ['financeiro']],
    ];

    /** Metadados dos campos que as transições podem gravar. */
    public const CAMPOS = [
        'numero_ofp'             => ['label' => 'Nº OFP (CSPS)', 'tipo' => 'text', 'max' => 20],
        'data_abertura'          => ['label' => 'Data de abertura no CSPS', 'tipo' => 'date'],
        'data_decisao'           => ['label' => 'Data da decisão da Fabricante', 'tipo' => 'date'],
        'protocolo_grow'         => ['label' => 'Protocolo GROW', 'tipo' => 'text', 'max' => 30],
        'data_reanalise'         => ['label' => 'Data de abertura do caso GROW', 'tipo' => 'date'],
        'nf_devolucao'           => ['label' => 'Nº NF de devolução', 'tipo' => 'text', 'max' => 20],
        'data_nf_devolucao'      => ['label' => 'Data de emissão da NF de devolução', 'tipo' => 'date'],
        'valor_nf_devolucao'     => ['label' => 'Valor da NF de devolução', 'tipo' => 'money'],
        'nf_compartilhada'       => ['label' => 'Esta NF cobre mais de uma OFP (justifique na observação)', 'tipo' => 'checkbox'],
        'nf_icms_st'             => ['label' => 'Nº NF de ICMS ST', 'tipo' => 'text', 'max' => 20],
        'data_nf_icms_st'        => ['label' => 'Data da NF de ICMS ST', 'tipo' => 'date'],
        'valor_icms_st'          => ['label' => 'Valor do ICMS ST', 'tipo' => 'money'],
        'data_coleta_solicitada' => ['label' => 'Data da solicitação de coleta', 'tipo' => 'date'],
        'data_coletada'          => ['label' => 'Data da coleta', 'tipo' => 'date'],
        'codigo_rastreio'        => ['label' => 'Código de rastreio', 'tipo' => 'text', 'max' => 40],
        'titulo_apollo'          => ['label' => 'Título Apollo', 'tipo' => 'text', 'max' => 30],
        'data_credito'           => ['label' => 'Data do crédito', 'tipo' => 'date'],
        'data_reabertura_credito' => ['label' => 'Reaberto para crédito em', 'tipo' => 'date'],
        'valor_creditado_peca'   => ['label' => 'Valor creditado — peça (total acumulado)', 'tipo' => 'money'],
        'valor_creditado_st'     => ['label' => 'Valor creditado — ICMS ST (total acumulado)', 'tipo' => 'money'],
        'observacao'             => ['label' => 'Observação', 'tipo' => 'textarea', 'max' => 2000],
    ];

    /** Campos que precisam vir informados na própria transição (não vale valor antigo). */
    private const FRESCOS = ['observacao', 'data_decisao', 'data_reanalise'];

    private const OBRIGATORIOS = [
        'em_analise'        => ['numero_ofp', 'data_abertura'],
        'aprovada'          => ['data_decisao'],
        'rejeitada'         => ['data_decisao'],
        'em_reanalise'      => ['protocolo_grow', 'data_reanalise'],
        'nf_emitida'        => ['nf_devolucao', 'data_nf_devolucao', 'valor_nf_devolucao'],
        'aguardando_coleta' => ['data_coleta_solicitada'],
        'coletada'          => ['data_coletada'],
        'creditada'         => ['titulo_apollo', 'data_credito', 'valor_creditado_peca'],
    ];

    private const OPCIONAIS = [
        'nf_emitida' => ['nf_icms_st', 'data_nf_icms_st', 'valor_icms_st', 'nf_compartilhada'],
        'coletada'   => ['codigo_rastreio'],
        'creditada'  => ['valor_creditado_st'],
    ];

    /** Transições que exigem um motivo da lista (Cadastros → Motivos). */
    public const EXIGEM_MOTIVO = ['rejeitada', 'rejeitada_final', 'cancelada', 'perdida_prazo', 'encerrada', 'peca_vendida', 'estornada'];

    /** Cada data não pode ser anterior à anterior preenchida nesta lista. */
    private const ORDEM_DATAS = ['data_recebimento', 'data_abertura', 'data_decisao', 'data_nf_devolucao', 'data_coleta_solicitada', 'data_coletada'];

    public static function ativo(string $status): bool
    {
        return !in_array($status, self::INATIVOS, true);
    }

    public static function exigeMotivo(string $para): bool
    {
        return in_array($para, self::EXIGEM_MOTIVO, true);
    }

    /** @param array|null $motivo linha da tabela motivos @return array<string, string> */
    public static function validarMotivo(?array $motivo, string $para, ?string $observacao): array
    {
        if (!self::exigeMotivo($para)) {
            return [];
        }
        if (!$motivo) {
            return ['motivo_id' => 'Informe o motivo.'];
        }
        if (!(int)$motivo['ativo']) {
            return ['motivo_id' => 'Motivo inativo.'];
        }
        $aplica = array_map('trim', explode(',', (string)$motivo['aplica_a']));
        if (!in_array($para, $aplica, true)) {
            return ['motivo_id' => 'Este motivo não se aplica a "' . self::LABELS[$para] . '".'];
        }
        if ((int)$motivo['exige_observacao'] && trim((string)$observacao) === '') {
            return ['observacao' => 'Descreva o motivo na observação.'];
        }
        return [];
    }

    /** @return string[] destinos possíveis para o perfil */
    public static function transicoesPermitidas(string $status, string $perfil, string $tipoDevolucao, bool $exigeTp41 = true): array
    {
        $supervisor = in_array($perfil, self::SUPERVISORES, true);
        $destinos = [];
        foreach (self::FLUXO[$status] ?? [] as $para => $perfis) {
            if ($supervisor || in_array($perfil, $perfis, true)) {
                $destinos[] = $para;
            }
        }
        if ($supervisor && self::ativo($status)) {
            foreach (['cancelada', 'peca_vendida'] as $extra) {
                if (!in_array($extra, $destinos, true)) {
                    $destinos[] = $extra;
                }
            }
        }
        if ($status === 'nf_emitida') {
            $remover = $tipoDevolucao === 'simbolica' ? 'aguardando_coleta' : 'aguardando_credito';
            $destinos = array_values(array_diff($destinos, [$remover]));
        }
        if (!$exigeTp41) {
            $destinos = array_values(array_diff($destinos, ['aguardando_tp41']));
        }
        return $destinos;
    }

    /** @return string[] status em que o perfil tem alguma ação (base de "Minhas pendências") */
    public static function statusOndeAtua(string $perfil): array
    {
        $supervisor = in_array($perfil, self::SUPERVISORES, true);
        $lista = [];
        foreach (self::FLUXO as $status => $destinos) {
            if (!self::ativo($status)) {
                continue;
            }
            if ($supervisor) {
                $lista[] = $status;
                continue;
            }
            foreach ($destinos as $perfis) {
                if (in_array($perfil, $perfis, true)) {
                    $lista[] = $status;
                    break;
                }
            }
        }
        return $lista;
    }

    /** @return string[] perfis (não supervisores) com alguma ação no status, em ordem alfabética */
    public static function perfisQueAgem(string $status): array
    {
        if (!self::ativo($status)) {
            return [];
        }
        $perfis = array_unique(array_merge(...array_values(self::FLUXO[$status] ?? [[]])));
        sort($perfis);
        return array_values($perfis);
    }

    public static function camposObrigatorios(string $para): array
    {
        return self::OBRIGATORIOS[$para] ?? [];
    }

    public static function camposOpcionais(string $para): array
    {
        $extras = in_array('observacao', self::camposObrigatorios($para), true) ? [] : ['observacao'];
        return array_merge(self::OPCIONAIS[$para] ?? [], $extras);
    }

    /** Mantém só campos conhecidos; '' vira null; dinheiro BR vira float. */
    public static function normalizarDados(array $dados): array
    {
        $saida = [];
        foreach ($dados as $campo => $valor) {
            if (!isset(self::CAMPOS[$campo])) {
                continue;
            }
            if (self::CAMPOS[$campo]['tipo'] === 'checkbox') {
                $saida[$campo] = is_scalar($valor) && in_array((string)$valor, ['1', 'on', 'true'], true) ? 1 : 0;
                continue;
            }
            $valor = is_string($valor) ? trim($valor) : $valor;
            if ($valor === '' || $valor === null || !is_scalar($valor)) {
                $saida[$campo] = null;
                continue;
            }
            $saida[$campo] = match (self::CAMPOS[$campo]['tipo']) {
                'money'    => self::numero($valor),
                default    => $valor,
            };
        }
        return $saida;
    }

    /** @return array<string, string> erros por campo */
    public static function validar(array $ofp, string $para, array $dados): array
    {
        $erros = [];
        $final = array_merge($ofp, array_filter($dados, fn($v) => $v !== null));
        foreach (self::camposObrigatorios($para) as $campo) {
            // Campos "frescos" precisam vir nesta transição; o resto pode já estar gravado na OFP.
            $valor = in_array($campo, self::FRESCOS, true) ? ($dados[$campo] ?? null) : ($final[$campo] ?? null);
            if ($valor === null || $valor === '') {
                $erros[$campo] = self::rotulo($campo) . ' é obrigatório.';
            }
        }
        return $erros + self::validarCampos($ofp, $dados);
    }

    /** Tipo, tamanho, faixa e ordem das datas dos campos informados. @return array<string, string> */
    public static function validarCampos(array $ofp, array $dados): array
    {
        $erros = [];
        foreach ($dados as $campo => $valor) {
            if ($valor === null) {
                continue;
            }
            $tipo = self::CAMPOS[$campo]['tipo'] ?? '';
            if ($tipo === 'date' && !self::dataValida((string)$valor)) {
                $erros[$campo] = self::rotulo($campo) . ' inválida.';
            }
            if ($tipo === 'money') {
                if (!is_float($valor) && !is_int($valor)) {
                    $erros[$campo] = self::rotulo($campo) . ' inválido.';
                } elseif ($valor < 0) {
                    $erros[$campo] = self::rotulo($campo) . ' não pode ser negativo.';
                } elseif ($valor >= 1e10) {
                    $erros[$campo] = self::rotulo($campo) . ' acima do limite.';
                }
            }
            $max = self::CAMPOS[$campo]['max'] ?? null;
            if ($max !== null && !isset($erros[$campo]) && mb_strlen((string)$valor) > $max) {
                $erros[$campo] = "Máximo de {$max} caracteres.";
            }
            if (in_array($campo, ['nf_devolucao', 'nf_icms_st'], true) && !isset($erros[$campo]) && !preg_match('/^\d{1,20}$/', (string)$valor)) {
                $erros[$campo] = 'Nº da NF deve ter só números.';
            }
            if ($campo === 'numero_ofp' && !isset($erros[$campo]) && !preg_match('/^\d{4,12}$/', (string)$valor)) {
                $erros[$campo] = self::rotulo($campo) . ' deve ter de 4 a 12 dígitos.';
            }
        }
        if ($erros) {
            return $erros;
        }

        $final = array_merge($ofp, $dados);
        $anteriorCampo = null;
        $anteriorData = null;
        foreach (self::ORDEM_DATAS as $campo) {
            $data = !empty($final[$campo]) ? substr((string)$final[$campo], 0, 10) : null;
            if ($data === null) {
                continue;
            }
            // Só checa o par se uma das datas está sendo informada agora (legado pode estar fora de ordem).
            $informada = isset($dados[$campo]) || ($anteriorCampo !== null && isset($dados[$anteriorCampo]));
            if ($informada && $anteriorData !== null && $data < $anteriorData) {
                $erros[$campo] = self::rotulo($campo) . ' não pode ser anterior a ' . mb_strtolower(self::rotulo($anteriorCampo)) . '.';
            }
            $anteriorCampo = $campo;
            $anteriorData = $data;
        }
        $rejeicao = !empty($final['data_rejeicao']) ? $final['data_rejeicao'] : ($final['data_decisao'] ?? null);
        if (isset($dados['data_reanalise']) && !empty($rejeicao)
            && substr((string)$dados['data_reanalise'], 0, 10) < substr((string)$rejeicao, 0, 10)) {
            $erros['data_reanalise'] = 'Data de abertura do caso GROW não pode ser anterior à rejeição.';
        }
        if (isset($dados['data_credito']) && !empty($final['data_nf_devolucao'])
            && substr((string)$dados['data_credito'], 0, 10) < substr((string)$final['data_nf_devolucao'], 0, 10)) {
            $erros['data_credito'] = self::rotulo('data_credito') . ' não pode ser anterior à data da NF de devolução.';
        }
        return $erros;
    }

    /** Campos obrigatórios de qualquer etapa (não podem ser apagados depois de preenchidos). */
    public static function todosObrigatorios(): array
    {
        return array_values(array_unique(array_diff(array_merge(...array_values(self::OBRIGATORIOS)), ['observacao'])));
    }

    /** Uma NF de devolução por OFP (manual cap. 12), salvo NF compartilhada justificada. */
    public function garantirNfUnica(array $ofp, array $dados, ?string $observacao): array
    {
        $nf = $dados['nf_devolucao'] ?? null;
        if ($nf === null || $nf === '') {
            unset($dados['nf_compartilhada']);
            return $dados;
        }
        $outra = (new Ofp())->outraComNf((string)$nf, $ofp['unidade_id'], $ofp['id']);
        $marcada = !empty($dados['nf_compartilhada']);
        if ($outra && !$marcada) {
            throw new ValidacaoException(['nf_devolucao' => "NF {$nf} já está na OFP " . ($outra['numero_ofp'] ?? 'sem nº')
                . '. O manual pede uma NF por OFP; se esta NF cobre mais de uma OFP, marque a opção e justifique na observação.']);
        }
        if ($marcada && trim((string)$observacao) === '') {
            throw new ValidacaoException(['observacao' => 'Justifique por que a NF cobre mais de uma OFP.']);
        }
        // A marca só vale quando existe de fato outra OFP com a mesma NF.
        $dados['nf_compartilhada'] = $outra ? 1 : 0;
        return $dados;
    }

    /** Converte número BR/US para float; texto que não parece número fica como veio (validar() recusa). */
    private static function numero(mixed $valor): mixed
    {
        if (is_int($valor) || is_float($valor)) {
            return (float)$valor;
        }
        $v = trim((string)$valor);
        if (preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$|^-?\d+([.,]\d+)?$/', $v)) {
            return parse_decimal($v);
        }
        return $v;
    }

    private static function rotulo(string $campo): string
    {
        return self::CAMPOS[$campo]['label'] ?? ($campo === 'data_recebimento' ? 'Data de recebimento' : $campo);
    }

    private static function dataValida(string $data): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        return $d !== false && $d->format('Y-m-d') === $data;
    }

    /** Transição pedida por um usuário: valida perfil, escopo, motivo e dados, depois grava. */
    public function transitar(string $ofpId, string $para, array $dadosBrutos, array $usuario): void
    {
        $ofp = (new Ofp())->findComCausal($ofpId);
        if (!$ofp || !unidade_permitida($ofp['unidade_id'])) {
            throw new RegraException('OFP não encontrada.');
        }
        if (!in_array($para, self::transicoesPermitidas($ofp['status'], $usuario['perfil'], $ofp['tipo_devolucao'], (bool)$ofp['exige_tp41']), true)) {
            throw new RegraException('Mudança de "' . status_label($ofp['status']) . '" para "' . status_label($para) . '" não é permitida para o seu perfil.');
        }
        $permitidos = array_merge(self::camposObrigatorios($para), self::camposOpcionais($para));
        $dados = array_intersect_key(self::normalizarDados($dadosBrutos), array_flip($permitidos));
        // Campo em branco na transição = "não informado"; apagar valor gravado só pela correção.
        $dados = array_filter($dados, fn($v) => $v !== null);

        $motivoId = is_string($dadosBrutos['motivo_id'] ?? null) ? trim($dadosBrutos['motivo_id']) : '';
        $motivo = $motivoId !== '' ? (new Motivo())->find($motivoId) : null;
        $erros = self::validar($ofp, $para, $dados) + self::validarMotivo($motivo, $para, $dados['observacao'] ?? null);
        if ($ofp['status'] === 'estornada' && $para === 'aguardando_credito' && trim((string)($dados['observacao'] ?? '')) === '') {
            $erros['observacao'] = 'Explique por que a OFP volta a aguardar crédito.';
        }
        if ($erros) {
            throw new ValidacaoException($erros);
        }
        if (!empty($dados['numero_ofp'])) {
            $outra = (new Ofp())->findByNumero((string)$dados['numero_ofp']);
            if ($outra && $outra['id'] !== $ofp['id']) {
                throw new ValidacaoException(['numero_ofp' => 'Nº OFP já cadastrado em outra OFP.']);
            }
        }
        $dados = $this->garantirNfUnica($ofp, $dados, $dados['observacao'] ?? null);
        if ($para === 'estornada') {
            // O dinheiro voltou para a Fabricante: o creditado, o título e a data do crédito zeram (ficam no histórico).
            foreach (['valor_creditado_peca', 'valor_creditado_st'] as $campo) {
                if ((float)($ofp[$campo] ?? 0) != 0.0) {
                    $dados[$campo] = 0.0;
                }
            }
            $dados['titulo_apollo'] = null;
            $dados['data_credito'] = null;
        }
        if ($ofp['status'] === 'estornada' && $para === 'aguardando_credito') {
            $dados['data_reabertura_credito'] = date('Y-m-d'); // o prazo do crédito recomeça
        }
        $observacao = $dados['observacao'] ?? null;
        unset($dados['observacao']);
        $this->aplicar($ofp, $para, $dados, $usuario['id'] ?? null, $observacao, self::exigeMotivo($para) ? $motivo['id'] : null);
    }

    /**
     * Grava status + campos + próximo prazo + evento numa transação, sem checar permissão.
     * $ofp deve vir de Ofp::findComCausal(). Usado por transitar(), baixa financeira e importação.
     */
    public function aplicar(array $ofp, string $para, array $dados, ?string $usuarioId, ?string $observacao, ?string $motivoId = null): void
    {
        $dados = array_intersect_key($dados, self::CAMPOS);
        $alteracoes = Alteracoes::calcular($ofp, $dados, array_keys($dados));
        $dados += self::datasDecisao($para, $dados['data_decisao'] ?? null);
        if ($motivoId !== null) {
            $dados['motivo_id'] = $motivoId;
        } elseif ($para !== $ofp['status'] && !self::exigeMotivo($para)) {
            $dados['motivo_id'] = null; // o motivo da perda/rejeição deixa de ser o atual; fica no histórico
        }
        $novo =array_merge($ofp, $dados, ['status' => $para]);
        $prazoCausal = $ofp['prazo_abertura_dias'] !== null ? (int)$ofp['prazo_abertura_dias'] : null;
        $dados = array_merge($dados, ['status' => $para], PrazoService::padrao()->colunas($novo, $prazoCausal));
        // A sugestão de perda acompanha a nova etapa e o novo prazo (ex.: aprovada com decisão antiga já nasce "encerrada").
        $dados['perda_sugerida'] = PerdaService::sugerir(array_merge($novo, $dados), new \DateTimeImmutable('today'));

        $db = Database::getInstance();
        $propria = !$db->inTransaction();
        if ($propria) {
            $db->beginTransaction();
        }
        try {
            // Condicional ao status lido: se outro usuário mudou a OFP no meio, não grava.
            if ((new Ofp())->updateOnde($ofp['id'], $dados, ['status' => $ofp['status']]) === 0) {
                throw new RegraException('Esta OFP foi alterada por outro usuário. Recarregue a página e tente novamente.');
            }
            (new OfpEvento())->registrar($ofp['id'], $usuarioId, $ofp['status'], $para, $observacao, $motivoId, $alteracoes);
            if ($propria) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($propria && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Rejeição e aprovação guardam a data em campos próprios (data_decisao continua sendo a última). */
    public static function datasDecisao(string $para, ?string $data): array
    {
        if ($data === null) {
            return [];
        }
        return match ($para) {
            'rejeitada' => ['data_rejeicao' => $data],
            'aprovada'  => ['data_aprovacao' => $data],
            default     => [],
        };
    }
}
