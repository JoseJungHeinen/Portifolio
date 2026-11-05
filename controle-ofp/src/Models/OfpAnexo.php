<?php
declare(strict_types=1);

namespace App\Models;

class OfpAnexo extends BaseModel
{
    protected string $table = 'ofp_anexos';

    public function doOfp(string $ofpId): array
    {
        return $this->query(
            'SELECT a.*, u.nome AS usuario_nome FROM ofp_anexos a LEFT JOIN usuarios u ON u.id = a.usuario_id
             WHERE a.ofp_id = ? ORDER BY a.created_at DESC',
            [$ofpId]
        );
    }
}
