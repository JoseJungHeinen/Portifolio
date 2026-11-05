<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Models\Ofp;
use App\Support\RegraException;
use App\Support\ValidacaoException;

/** Baixa de crédito (Conta Movimento / título Apollo) e conciliação — substitui a aba "Conferência". */
class ConciliacaoService
{
    public const PERFIS_BAIXA = ['financeiro', 'gerente', 'admin'];

    /** @return array{peca: float, st: float} valores ainda não creditados */
    public static function emAberto(array $o): array
    {
        $devido = (float)($o['valor_nf_devolucao'] ?? $o['valor_reclamado'] ?? 0);
        return [
            'peca' => round(max(0.0, $devido - (float)($o['valor_creditado_peca'] ?? 0)), 2),
            'st'   => round(max(0.0, (float)($o['valor_icms_st'] ?? 0) - (float)($o['valor_creditado_st'] ?? 0)), 2),
        ];
    }

    /**
     * Registra o total creditado até agora. Quitou peça e ICMS ST → "creditada"; senão continua aguardando.
     * @return string 'creditada' | 'parcial'
     */
    public function baixar(string $ofpId, array $entrada, array $usuario): string
    {
        if (!in_array($usuario['perfil'], self::PERFIS_BAIXA, true)) {
            throw new RegraException('Somente o financeiro pode dar baixa de crédito.');
        }
        $ofp = (new Ofp())->findComCausal($ofpId);
        if (!$ofp || !unidade_permitida($ofp['unidade_id'])) {
            throw new RegraException('OFP não encontrada.');
        }
        if ($ofp['status'] !== 'aguardando_credito') {
            throw new RegraException('Só é possível dar baixa em OFP aguardando crédito.');
        }
        $campos = ['titulo_apollo', 'data_credito', 'valor_creditado_peca', 'valor_creditado_st', 'observacao'];
        $dados = StatusService::normalizarDados(array_intersect_key($entrada, array_flip($campos)));
        // Campo em branco mantém o total acumulado já registrado.
        $dados['valor_creditado_peca'] = $dados['valor_creditado_peca'] ?? (float)($ofp['valor_creditado_peca'] ?? 0);
        $dados['valor_creditado_st'] = $dados['valor_creditado_st'] ?? (float)($ofp['valor_creditado_st'] ?? 0);
        $titulo = $dados['titulo_apollo'] ?? null;
        $observacao = $dados['observacao'] ?? null;
        unset($dados['observacao']);

        $erros = StatusService::validar($ofp, 'creditada', $dados);
        foreach (['valor_creditado_peca', 'valor_creditado_st'] as $campo) {
            $gravado = (float)($ofp[$campo] ?? 0);
            if (!isset($erros[$campo]) && $dados[$campo] < $gravado - 0.005) {
                $erros[$campo] = 'Total creditado não pode ser menor que o já registrado (' . money($gravado) . ').';
            }
        }
        if ($erros) {
            throw new ValidacaoException($erros);
        }

        $resta = self::emAberto(array_merge($ofp, $dados));
        $status = new StatusService();
        $prefixo = $titulo ? "Título {$titulo}: " : '';
        if ($resta['peca'] < 0.01 && $resta['st'] < 0.01) {
            $status->aplicar($ofp, 'creditada', $dados, $usuario['id'], $prefixo . ($observacao ?: 'Crédito integral recebido.'));
            return 'creditada';
        }
        $status->aplicar($ofp, 'aguardando_credito', $dados, $usuario['id'],
            $prefixo . 'Crédito parcial — falta ' . money($resta['peca']) . ' de peça e ' . money($resta['st']) . ' de ICMS ST.' . ($observacao ? ' ' . $observacao : ''));
        return 'parcial';
    }

    /** Baixa várias OFPs pelo valor integral devido. @return array{ok: int, erros: string[]} */
    public function baixarLote(array $ids, string $titulo, string $data, array $usuario): array
    {
        if (count($ids) > 200) {
            throw new ValidacaoException(['ids' => 'Selecione no máximo 200 OFPs por vez.']);
        }
        $ok = 0;
        $erros = [];
        foreach ($ids as $id) {
            $ofp = (new Ofp())->find((string)$id);
            if (!$ofp || !unidade_permitida($ofp['unidade_id'])) {
                $erros[] = 'OFP não encontrada.';
                continue;
            }
            $rotulo = 'OFP ' . ($ofp['numero_ofp'] ?? 'sem nº') . ': ';
            try {
                $this->baixar($ofp['id'], [
                    'titulo_apollo'        => $titulo,
                    'data_credito'         => $data,
                    'valor_creditado_peca' => (float)($ofp['valor_nf_devolucao'] ?? $ofp['valor_reclamado']),
                    'valor_creditado_st'   => (float)($ofp['valor_icms_st'] ?? 0),
                ], $usuario);
                $ok++;
            } catch (ValidacaoException $e) {
                $erros[] = $rotulo . implode(' ', $e->erros);
            } catch (RegraException $e) {
                $erros[] = $rotulo . $e->getMessage();
            }
        }
        return ['ok' => $ok, 'erros' => $erros];
    }

    /** Devoluções por unidade e ano da NF: devido × creditado. */
    public function conciliacao(): array
    {
        [$w, $p] = sql_unidades('o.unidade_id');
        $st = Database::getInstance()->prepare(
            "SELECT u.nome AS unidade, YEAR(o.data_nf_devolucao) AS ano, COUNT(*) AS qtd,
                    SUM(COALESCE(o.valor_nf_devolucao, 0)) AS devolucao, SUM(COALESCE(o.valor_icms_st, 0)) AS icms_st,
                    SUM(COALESCE(o.valor_creditado_peca, 0)) AS creditado_peca, SUM(COALESCE(o.valor_creditado_st, 0)) AS creditado_st
             FROM ofps o JOIN unidades u ON u.id = o.unidade_id
             WHERE {$w} AND o.data_nf_devolucao IS NOT NULL AND o.status NOT IN ('cancelada', 'estornada')
             GROUP BY u.id, u.nome, YEAR(o.data_nf_devolucao) ORDER BY ano DESC, u.nome"
        );
        $st->execute($p);
        return array_map(fn(array $r) => $r + [
            'diferenca' => round((float)$r['devolucao'] + (float)$r['icms_st'] - (float)$r['creditado_peca'] - (float)$r['creditado_st'], 2),
        ], $st->fetchAll());
    }
}
