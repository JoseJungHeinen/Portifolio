<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Motivo;
use App\Models\Ofp;
use App\Services\PerdaService;

class PerdaController extends Controller
{
    private const LIMITE = 500;

    public function index(): void
    {
        $ofps = new Ofp();
        $this->view('perdas/index', [
            'title'   => 'Perdas sugeridas',
            'grupos'  => [
                'perdida_prazo' => $ofps->buscar(['status' => 'ativos', 'sugestao' => 'perdida_prazo'], self::LIMITE),
                'encerrada'     => $ofps->buscar(['status' => 'ativos', 'sugestao' => 'encerrada'], self::LIMITE),
            ],
            'totais'  => [
                'perdida_prazo' => $ofps->contar(['status' => 'ativos', 'sugestao' => 'perdida_prazo']),
                'encerrada'     => $ofps->contar(['status' => 'ativos', 'sugestao' => 'encerrada']),
            ],
            'limite'  => self::LIMITE,
            'motivos' => (new Motivo())->ativosPorStatus(),
        ]);
    }

    public function confirmar(): void
    {
        $ids = array_values(array_filter((array)($_POST['ids'] ?? []), 'is_string'));
        $motivo = is_string($_POST['motivo_id'] ?? null) ? $_POST['motivo_id'] : '';
        $observacao = is_string($_POST['observacao'] ?? null) ? trim($_POST['observacao']) : '';
        if (!$ids || $motivo === '') {
            session_flash('error', 'Selecione as OFPs e o motivo.');
            redirect('/perdas');
        }
        $r = (new PerdaService())->confirmar($ids, $motivo, $observacao, auth());
        if ($r['ok'] > 0) {
            session_flash('success', "{$r['ok']} OFP(s) baixada(s) como perda.");
        }
        if ($r['erros']) {
            session_flash('warning', implode(' ', array_unique($r['erros'])));
        }
        redirect('/perdas');
    }
}
