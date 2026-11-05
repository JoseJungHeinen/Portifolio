<?php

namespace App\Http\Controllers;

use App\Models\TipoEmplacamento;
use App\Models\Orcamento;
use Illuminate\Http\Request;
use Mpdf\Mpdf;

class EmplacamentoController extends Controller
{
    public function index()
    {
        $tipos = TipoEmplacamento::where('ativo', 1)->orderBy('nome')->get();
        return view('emplacamento.index', compact('tipos'));
    }

    public function calcular(Request $request)
    {
        $data = $request->validate([
            'tipo_id' => 'required|exists:tipos_emplacamento,id',
            'data_nf' => 'required|date',
            'valor_nf' => 'required|numeric|min:0',
            'escolha_placa' => 'nullable|boolean',
            'sequencial' => 'nullable|string|max:20',
        ]);

        $tipo = TipoEmplacamento::findOrFail($data['tipo_id']);

        $valorBase = (float) $data['valor_nf'];
        $aliquota = (float) $tipo->ipva_percentual;
        $ipva = $valorBase * $aliquota;
        $taxas = (float) $tipo->taxas;
        $valorPlaca = (float) $tipo->valor_placa;
        $margem = (float) $tipo->margem;
        $escolhaPlaca = $request->boolean('escolha_placa') ? (float) $tipo->valor_escolha_placa : 0;

        $total = $ipva + $taxas + $valorPlaca + $escolhaPlaca + $margem;

        $texto = "Emplacamento: {$tipo->nome}.\n";
        $texto .= "Valor NF: R$ " . number_format($valorBase, 2, ',', '.') . ".\n";
        $texto .= "IPVA (" . ($aliquota * 100) . "%): R$ " . number_format($ipva, 2, ',', '.') . ".\n";
        $texto .= "Taxas: R$ " . number_format($taxas, 2, ',', '.') . ".\n";
        $texto .= "Placa: R$ " . number_format($valorPlaca, 2, ',', '.') . ".\n";
        if ($escolhaPlaca > 0) {
            $texto .= "Escolha de placa: R$ " . number_format($escolhaPlaca, 2, ',', '.') . ".\n";
        }
        $texto .= "Margem: R$ " . number_format($margem, 2, ',', '.') . ".\n";
        $texto .= "Total estimado: R$ " . number_format($total, 2, ',', '.') . ".";

        $orcamento = Orcamento::create([
            'tipo' => 'emplacamento',
            'subtipo_id' => $tipo->id,
            'texto_detalhe' => $texto,
            'total' => $total,
            'dados_entrada' => $data,
            'criado_em' => now(),
            'deletar_em' => now()->addDays(365),
        ]);

        return view('emplacamento.resultado', compact('tipo', 'total', 'texto', 'orcamento'));
    }

    public function pdf($id)
    {
        $orc = Orcamento::where('tipo', 'emplacamento')->findOrFail($id);
        $tipo = TipoEmplacamento::find($orc->subtipo_id);

        $dadosView = [
            'tipo' => $tipo ? $tipo->nome : 'Emplacamento',
            'detalhe' => $orc->texto_detalhe,
            'total' => $orc->total,
            'dataHoje' => now(),
            'logoPath' => public_path('img/logo.svg'),
        ];

        $html = view('pdf.emplacamento', $dadosView)->render();
        $mpdf = new Mpdf(['format' => 'A4']);
        $mpdf->WriteHTML($html);
        return $mpdf->Output("Emplacamento_{$id}.pdf", 'I');
    }
}
