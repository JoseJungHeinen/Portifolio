<?php

namespace App\Http\Controllers;

use App\Models\Orcamento;
use Illuminate\Http\Request;

class HistoricoOrcamentosController extends Controller
{
    public function index(Request $request)
    {
        $query = Orcamento::where('criado_em', '>=', now()->subDays(365));

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        if ($request->filled('data_inicial')) {
            $query->where('criado_em', '>=', $request->data_inicial);
        }

        if ($request->filled('data_final')) {
            $query->where('criado_em', '<=', $request->data_final . ' 23:59:59');
        }

        $orcamentos = $query->orderBy('criado_em', 'desc')->paginate(20);

        return view('historico.index', compact('orcamentos'));
    }
}
