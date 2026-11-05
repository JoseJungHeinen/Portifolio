<?php

namespace App\Services;

use App\Models\Orcamento;
use App\Models\SolicitacaoOrcamento;
use App\Models\Notificacao;
use App\Config\Database;
use RuntimeException;

class OrcamentoService
{
    private Orcamento $model;

    public function __construct()
    {
        $this->model = new Orcamento();
    }

    /**
     * Verifica saldo do Setor antes de permitir envio de requisição.
     * Apenas o saldo do setor é considerado — filial e empresa não bloqueiam.
     */
    public function verificarSaldo(
        string $filialId,
        string $setorId,
        string $grupoProdutoId,
        float  $valor
    ): array {
        $mes = (int)date('n');
        $ano = (int)date('Y');

        $orc   = $this->model->findMelhorParaSetor($filialId, $setorId, $grupoProdutoId, $mes, $ano);
        $saldo = $this->model->saldoSetor($filialId, $setorId, $mes, $ano);
        $ok    = $saldo >= $valor;

        return [
            'aprovado'     => $ok,
            'orcamento_id' => $orc['id'] ?? null,
            'camada1' => [
                'label' => 'Setor',
                'saldo' => $saldo,
                'ok'    => $ok,
            ],
        ];
    }

    public function verificarSaldoPorGrupos(string $filialId, string $setorId, array $valoresPorGrupo): array
    {
        $total = 0.0;
        foreach ($valoresPorGrupo as $grupo) {
            $total += (float)($grupo['valor_total'] ?? 0);
        }

        $primeiroGrupo = $valoresPorGrupo[0]['grupo_produto_id'] ?? null;
        $check = $primeiroGrupo
            ? $this->verificarSaldo($filialId, $setorId, $primeiroGrupo, $total)
            : [
                'aprovado'     => false,
                'orcamento_id' => null,
                'camada1'      => ['label' => 'Setor', 'saldo' => 0, 'ok' => false],
            ];

        $mes = (int)date('n');
        $ano = (int)date('Y');
        $grupos = [];

        foreach ($valoresPorGrupo as $grupo) {
            $grupoId = $grupo['grupo_produto_id'];
            $valor   = (float)$grupo['valor_total'];
            $orc     = $this->model->findMelhorParaSetor($filialId, $setorId, $grupoId, $mes, $ano);

            $grupos[] = [
                'grupo_produto_id' => $grupoId,
                'grupo_nome'       => $grupo['grupo_nome'] ?? 'Grupo',
                'valor_total'      => $valor,
                'orcamento_id'     => $orc['id'] ?? null,
            ];
        }

        $check['grupos'] = $grupos;
        $check['valor_total'] = $total;

        return $check;
    }

    public function consumir(string $orcamentoId, float $valor): void
    {
        $this->model->consumir($orcamentoId, $valor);

        // Alerta se atingiu 80% do orçamento
        $orc = $this->model->find($orcamentoId);
        if ($orc) {
            $pct = (float)$orc['valor_aprovado'] > 0
                ? ($orc['valor_consumido'] / $orc['valor_aprovado']) * 100
                : 0;

            $alertaPct = 80.0; // poderia vir de parametros_sistema

            if ($pct >= $alertaPct) {
                (new NotificacaoService())->notificarSetorBudgetCritico($orc, $pct);
            }
        }
    }

    public function devolver(string $orcamentoId, float $valor): void
    {
        $this->model->devolver($orcamentoId, $valor);
    }

    public function solicitarAumento(
        ?string $orcamentoId,
        ?string $requisicaoId,
        string $filialId,
        string $setorId,
        string $grupoProdutoId,
        float  $valorNecessario,
        float  $valorSolicitado,
        string $justificativa,
        string $solicitadoPor
    ): string {
        $mes = (int)date('n');
        $ano = (int)date('Y');

        // Se não existe orçamento, usa o melhor match do setor ou cria um zerado como âncora
        if (!$orcamentoId) {
            $existente = $this->model->findMelhorParaSetor($filialId, $setorId, $grupoProdutoId, $mes, $ano);
            if ($existente) {
                $orcamentoId = $existente['id'];
            } else {
                $orcamentoId = $this->model->create([
                    'filial_id'        => $filialId,
                    'setor_id'         => $setorId,
                    'grupo_produto_id' => $grupoProdutoId,
                    'mes'              => $mes,
                    'ano'              => $ano,
                    'valor_aprovado'   => 0,
                    'valor_consumido'  => 0,
                    'criado_por'       => $solicitadoPor,
                ]);
            }
        }

        $orc   = $this->model->find($orcamentoId);
        $saldo = $orc ? (float)$orc['valor_aprovado'] - (float)$orc['valor_consumido'] : 0;

        $sol = new SolicitacaoOrcamento();
        $id  = $sol->create([
            'orcamento_id'      => $orcamentoId,
            'requisicao_id'     => $requisicaoId,
            'filial_id'         => $filialId,
            'setor_id'          => $setorId,
            'grupo_produto_id'  => $grupoProdutoId,
            'valor_saldo_atual' => $saldo,
            'valor_necessario'  => $valorNecessario,
            'valor_solicitado'  => $valorSolicitado,
            'justificativa'     => $justificativa,
            'solicitado_por'    => $solicitadoPor,
        ]);

        // Notifica todos os diretores
        (new NotificacaoService())->notificarDiretoresAumentoOrcamento($id, $setorId, $grupoProdutoId);

        (new AuditoriaService())->log(
            $solicitadoPor, 'solicitacao_orcamento', $id, 'solicitacao.criada',
            null, ['valor' => $valorSolicitado, 'motivo' => $justificativa]
        );

        return $id;
    }
}
