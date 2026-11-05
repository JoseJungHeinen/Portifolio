<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Models\Causal;
use App\Models\Ofp;
use App\Models\OfpEvento;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Support\Alteracoes;
use App\Support\RegraException;
use App\Support\ValidacaoException;
use DateTimeImmutable;
use Throwable;

class OfpService
{
    /** Campos editáveis no formulário de cadastro/edição. */
    public const CAMPOS = [
        'unidade_id', 'causal_id', 'numero_ofp', 'desenho', 'descricao_peca', 'quantidade', 'valor_reclamado',
        'nf_origem', 'serie_origem', 'numero_volume', 'transportadora', 'data_recebimento', 'responsavel_id', 'observacao',
    ];

    /** Campos extras editáveis apenas enquanto a OFP está em "aguardando_coleta". */
    public const CAMPOS_COLETA = ['data_coleta_solicitada', 'codigo_rastreio'];

    /** Rótulos dos campos do cadastro (histórico de alterações). */
    public const ROTULOS = [
        'unidade_id' => 'Unidade', 'causal_id' => 'Causal', 'numero_ofp' => 'Nº OFP', 'desenho' => 'Desenho',
        'descricao_peca' => 'Descrição da peça', 'quantidade' => 'Quantidade', 'valor_reclamado' => 'Valor reclamado',
        'nf_origem' => 'NF de origem', 'serie_origem' => 'Série', 'numero_volume' => 'Nº volume',
        'transportadora' => 'Transportadora', 'data_recebimento' => 'Recebimento do CTe', 'responsavel_id' => 'Responsável',
        'observacao' => 'Observação',
    ];

    /** @return array{id: string, avisos: string[]} */
    public function registrar(array $entrada, array $usuario): array
    {
        $d = $this->limpar($entrada);
        [$causal, $avisos] = $this->validar($d, null);

        $d['status'] = $d['numero_ofp'] ? 'em_analise' : 'registrada';
        $d['data_abertura'] = $d['numero_ofp'] ? date('Y-m-d') : null;
        $d['responsavel_id'] = $d['responsavel_id'] ?: $usuario['id'];
        $d['created_by'] = $usuario['id'];
        $d += PrazoService::padrao()->colunas($d, self::prazoCausal($causal));

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $id = (new Ofp())->create($d);
            $obs = 'OFP registrada.' . ($avisos ? ' Avisos: ' . implode(' ', $avisos) : '');
            (new OfpEvento())->registrar($id, $usuario['id'], null, $d['status'], $obs);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return ['id' => $id, 'avisos' => $avisos];
    }

    /** @return string[] avisos */
    public function atualizar(string $id, array $entrada, array $usuario): array
    {
        $atual = (new Ofp())->findComCausal($id);
        if (!$atual || !unidade_permitida($atual['unidade_id'])) {
            throw new RegraException('OFP não encontrada.');
        }
        if (!StatusService::ativo($atual['status'])) {
            throw new RegraException('OFP encerrada não pode ser editada.');
        }
        $d = $this->limpar($entrada);
        if (self::unidadeCausalTravados($atual)
            && ((string)$d['unidade_id'] !== (string)$atual['unidade_id'] || (string)$d['causal_id'] !== (string)$atual['causal_id'])) {
            throw new RegraException('Unidade e causal não podem mudar depois da aprovação; cancele e abra outra OFP.');
        }
        $d['numero_ofp'] = $atual['numero_ofp']; // o nº só entra pelo cadastro ou pela transição "Em análise"
        $coleta = $atual['status'] === 'aguardando_coleta' ? $this->limparColeta($entrada, $atual) : ['dados' => [], 'erros' => []];
        try {
            [$causal, $avisos] = $this->validar($d, $atual);
        } catch (ValidacaoException $e) {
            throw new ValidacaoException($e->erros + $coleta['erros']);
        }
        if ($coleta['erros']) {
            throw new ValidacaoException($coleta['erros']);
        }
        $d = array_merge($d, $coleta['dados']);
        $d['responsavel_id'] = $d['responsavel_id'] ?: $atual['responsavel_id'];
        $d += PrazoService::padrao()->colunas(array_merge($atual, $d), self::prazoCausal($causal));

        $d['perda_sugerida'] = PerdaService::sugerir(array_merge($atual, $d), new \DateTimeImmutable('today'));
        $campos = array_values(array_diff(array_keys($d), ['prazo_tipo', 'prazo_data', 'numero_ofp', 'perda_sugerida']));
        $alteracoes = $this->nomearIds(Alteracoes::calcular($atual, $d, $campos));

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            // Condicional ao status lido: se outro usuário mudou a OFP no meio, não grava.
            if ((new Ofp())->updateOnde($id, $d, ['status' => $atual['status']]) === 0) {
                throw new RegraException('Esta OFP foi alterada por outro usuário. Recarregue a página e tente novamente.');
            }
            (new OfpEvento())->registrar($id, $usuario['id'], $atual['status'], $atual['status'], 'Dados da OFP alterados.', null, $alteracoes);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return $avisos;
    }

    /** Depois da aprovação (ou com NF de devolução lançada) unidade e causal não mudam: a NF é única por unidade e o causal define o tipo de devolução. */
    public static function unidadeCausalTravados(array $ofp): bool
    {
        return !empty($ofp['nf_devolucao'])
            || (CorrecaoService::ETAPAS[$ofp['status']] ?? 0) > CorrecaoService::ETAPAS['aprovada'];
    }

    /** @return array{0: ?array, 1: string[]} causal e avisos */
    private function validar(array $d, ?array $atual): array
    {
        $causal = $d['causal_id'] ? (new Causal())->find($d['causal_id']) : null;
        $r = OfpValidator::validarNova($d, $causal, new DateTimeImmutable('today'));
        $erros = $r['erros'];

        if ($atual) {
            // Edição de OFP importada: causal legado mantido e recebimento desconhecido são aceitos.
            if (isset($erros['causal_id']) && $causal && $causal['id'] === $atual['causal_id']) {
                unset($erros['causal_id']);
            }
            if (isset($erros['data_recebimento']) && $atual['data_recebimento'] === null && $d['data_recebimento'] === null) {
                unset($erros['data_recebimento']);
            }
        }
        if ($d['unidade_id'] && !unidade_permitida((string)$d['unidade_id'])) {
            $erros['unidade_id'] = 'Unidade não permitida para o seu usuário.';
        } elseif ($d['unidade_id'] && !isset($erros['unidade_id']) && !(new Unidade())->find((string)$d['unidade_id'])) {
            $erros['unidade_id'] = 'Unidade inválida.';
        }
        if ($d['responsavel_id'] && (string)$d['responsavel_id'] !== (string)($atual['responsavel_id'] ?? '') && !in_array((string)$d['responsavel_id'], array_column((new Usuario())->responsaveis(), 'id'), true)) {
            $erros['responsavel_id'] = 'Responsável inválido.';
        }
        if ($d['numero_ofp'] && !isset($erros['numero_ofp'])) {
            $existente = (new Ofp())->findByNumero($d['numero_ofp']);
            if ($existente && $existente['id'] !== ($atual['id'] ?? null)) {
                $erros['numero_ofp'] = 'Já existe uma OFP com este número.';
            }
        }
        if ($erros) {
            throw new ValidacaoException($erros);
        }
        return [$causal, $r['avisos']];
    }

    /** @return array{dados: array, erros: array} data da solicitação de coleta e rastreio (só preenchidos entram). */
    private function limparColeta(array $entrada, array $atual): array
    {
        $dados = [];
        $erros = [];
        $data = is_string($entrada['data_coleta_solicitada'] ?? null) ? trim($entrada['data_coleta_solicitada']) : '';
        if ($data !== '') {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
            if (!$dt || $dt->format('Y-m-d') !== $data) {
                $erros['data_coleta_solicitada'] = 'Data da solicitação de coleta inválida.';
            } elseif ($data > date('Y-m-d')) {
                $erros['data_coleta_solicitada'] = 'Data da solicitação de coleta não pode ser futura.';
            } elseif (!empty($atual['data_nf_devolucao']) && $data < substr((string)$atual['data_nf_devolucao'], 0, 10)) {
                $erros['data_coleta_solicitada'] = 'Data da solicitação de coleta não pode ser anterior à data da NF de devolução.';
            } else {
                $dados['data_coleta_solicitada'] = $data;
            }
        }
        $rastreio = is_string($entrada['codigo_rastreio'] ?? null) ? trim(preg_replace('/[\r\n]+/', ' ', $entrada['codigo_rastreio'])) : '';
        if ($rastreio !== '') {
            if (mb_strlen($rastreio) > 40) {
                $erros['codigo_rastreio'] = 'Máximo de 40 caracteres.';
            } else {
                $dados['codigo_rastreio'] = $rastreio;
            }
        }
        return ['dados' => $dados, 'erros' => $erros];
    }

    private function limpar(array $entrada): array
    {
        $d = [];
        foreach (self::CAMPOS as $campo) {
            $valor = $entrada[$campo] ?? null;
            $valor = is_string($valor) ? trim($valor) : (is_scalar($valor) ? $valor : null);
            if (is_string($valor) && $campo !== 'observacao') {
                $valor = trim(preg_replace('/[\r\n]+/', ' ', $valor));
            }
            $d[$campo] = $valor === '' ? null : $valor;
        }
        $d['quantidade'] = $d['quantidade'] !== null ? parse_decimal($d['quantidade']) : 1.0;
        $d['valor_reclamado'] = $d['valor_reclamado'] !== null ? parse_decimal($d['valor_reclamado']) : 0.0;
        $d['desenho'] = $d['desenho'] !== null ? mb_strtoupper((string)$d['desenho']) : null;
        $d['numero_ofp'] = $d['numero_ofp'] !== null ? preg_replace('/\s+/', '', (string)$d['numero_ofp']) : null;
        if (!in_array($d['transportadora'], ['azul', 'jat', 'outra'], true)) {
            $d['transportadora'] = null;
        }
        return $d;
    }

    private static function prazoCausal(?array $causal): ?int
    {
        return isset($causal['prazo_abertura_dias']) ? (int)$causal['prazo_abertura_dias'] : null;
    }

    /** Troca ids por nomes legíveis no histórico (unidade, causal, responsável). */
    private function nomearIds(array $alteracoes): array
    {
        $nome = function (string $campo, ?string $id): ?string {
            if ($id === null) {
                return null;
            }
            return match ($campo) {
                'unidade_id'     => (new Unidade())->find($id)['nome'] ?? $id,
                'causal_id'      => (new Causal())->find($id)['codigo'] ?? $id,
                'responsavel_id' => (new Usuario())->find($id)['nome'] ?? $id,
                default          => $id,
            };
        };
        foreach ($alteracoes as &$a) {
            $a['de'] = $nome($a['campo'], $a['de']);
            $a['para'] = $nome($a['campo'], $a['para']);
        }
        return $alteracoes;
    }
}
