<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Causal;
use App\Models\Unidade;
use App\Services\PainelService;

class PainelController extends Controller
{
    public function inicio(): void
    {
        redirect(rota_inicial(auth()['perfil']));
    }

    public function index(): void
    {
        $f = [];
        foreach (['unidade_id', 'causal_id', 'ano'] as $chave) {
            $f[$chave] = trim((string)($_GET[$chave] ?? ''));
        }
        $painel = new PainelService();
        $this->view('painel/index', [
            'title'        => 'Painel',
            'filtros'      => $f,
            'cards'        => $painel->cards($f),
            'matriz'       => $painel->matriz($f),
            'aging'        => $painel->aging($f),
            'topCausais'   => $painel->topCausais($f),
            'evolucao'     => $painel->evolucao($f),
            'perdas'       => $painel->perdasPorMotivo($f),
            'anos'         => $painel->anos(),
            'unidades'     => (new Unidade())->permitidas(),
            'causais'      => (new Causal())->todos(),
        ]);
    }
}
