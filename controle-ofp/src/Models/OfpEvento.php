<?php
declare(strict_types=1);

namespace App\Models;

class OfpEvento extends BaseModel
{
    protected string $table = 'ofp_eventos';

    /** @param list<array{campo: string, de: ?string, para: ?string}> $alteracoes */
    public function registrar(string $ofpId, ?string $usuarioId, ?string $de, ?string $para, ?string $observacao, ?string $motivoId = null, array $alteracoes = []): string
    {
        return $this->create([
            'ofp_id'      => $ofpId,
            'usuario_id'  => $usuarioId,
            'status_de'   => $de,
            'status_para' => $para,
            'motivo_id'   => $motivoId,
            'observacao'  => $observacao,
            'alteracoes'  => $alteracoes ? json_encode($alteracoes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) : null,
        ]);
    }

    public function doOfp(string $ofpId): array
    {
        return $this->query(
            'SELECT e.*, u.nome AS usuario_nome, m.descricao AS motivo_descricao
             FROM ofp_eventos e LEFT JOIN usuarios u ON u.id = e.usuario_id LEFT JOIN motivos m ON m.id = e.motivo_id
             WHERE e.ofp_id = ? ORDER BY e.created_at DESC',
            [$ofpId]
        );
    }

    public function existe(string $ofpId, string $observacao): bool
    {
        return (bool)$this->queryScalar('SELECT 1 FROM ofp_eventos WHERE ofp_id = ? AND observacao = ? LIMIT 1', [$ofpId, $observacao]);
    }

    /** A OFP já foi mexida por um usuário no sistema (evento com autor)? */
    public function tocadaPorUsuario(string $ofpId): bool
    {
        return (bool)$this->queryScalar('SELECT 1 FROM ofp_eventos WHERE ofp_id = ? AND usuario_id IS NOT NULL LIMIT 1', [$ofpId]);
    }

    /** A importação do OFP-STATUS já mudou o status desta OFP? */
    public function statusMudadoPelaPlanilha(string $ofpId): bool
    {
        return (bool)$this->queryScalar(
            "SELECT 1 FROM ofp_eventos WHERE ofp_id = ? AND usuario_id IS NULL AND observacao LIKE 'Planilha OFP-STATUS%'
             AND status_de IS NOT NULL AND status_de <> status_para LIMIT 1",
            [$ofpId]
        );
    }
}
