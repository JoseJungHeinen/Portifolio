<?php

namespace App\Http\Controllers;

use App\Models\TipoTransferencia;
use App\Models\Orcamento;
use Illuminate\Http\Request;
use Mpdf\Mpdf;

class TransferenciaController extends Controller
{
    public function index()
    {
        $tipos = TipoTransferencia::where('ativo', 1)->orderBy('nome')->get();
        return view('transferencia.index', compact('tipos'));
    }

    public function calcular(Request $request)
    {
        $data = $request->validate([
            'tipo_id' => 'required|exists:tipos_transferencia,id',
            'ano_veiculo' => 'required|integer|min:1900|max:' . date('Y'),
            'ipva_pago' => 'required|boolean',
            'mes_atual' => 'required|integer|min:1|max:12',
            'intermunicipal' => 'nullable|boolean',
        ]);

        $tipo = TipoTransferencia::findOrFail($data['tipo_id']);

        $valorPlaca = $tipo->valor_placa;
        $valorIntermunicipal = $request->boolean('intermunicipal') ? $tipo->valor_intermunicipal : 0;
        $valorTransferencia = $tipo->valor_transferencia;
        $valorIpva = $data['ipva_pago'] ? 0 : 350.00;

        $total = $valorPlaca + $valorIntermunicipal + $valorTransferencia + $valorIpva;

        $texto = "Simulação de transferência para {$tipo->nome}.\n";
        $texto .= "Ano do veículo: {$data['ano_veiculo']}.\n";
        $texto .= "IPVA " . ($data['ipva_pago'] ? "pago" : "não pago") . ".\n";
        if ($valorIntermunicipal > 0) {
            $texto .= "Inclui transferência intermunicipal.\n";
        }
        $texto .= "Total estimado: R$ " . number_format($total, 2, ',', '.') . ".";

        $orcamento = Orcamento::create([
            'tipo' => 'transferencia',
            'subtipo_id' => $tipo->id,
            'texto_detalhe' => $texto,
            'total' => $total,
            'dados_entrada' => $data,
            'criado_em' => now(),
            'deletar_em' => now()->addDays(365),
        ]);

        return view('transferencia.resultado', compact('tipo', 'total', 'texto', 'orcamento'));
    }

    public function pdf($id)
    {
        $orc = Orcamento::where('tipo', 'transferencia')->findOrFail($id);
        $tipo = TipoTransferencia::find($orc->subtipo_id);

        $dadosView = [
            'tipo' => $tipo ? $tipo->nome : 'Transferência',
            'detalhe' => $orc->texto_detalhe,
            'total' => $orc->total,
            'dataHoje' => now(),
            'logoPath' => public_path('img/logo.svg'),
        ];

        $html = view('pdf.transferencia', $dadosView)->render();
        $mpdf = new Mpdf(['format' => 'A4']);
        $mpdf->WriteHTML($html);
        return $mpdf->Output("Transferencia_{$id}.pdf", 'I');
    }
}
