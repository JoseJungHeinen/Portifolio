<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11pt;
            color: #333333;
        }
        .header {
            border-bottom: 3px solid #2347C8;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .logo {
            height: 40px;
        }
        .titulo {
            color: #2347C8;
            font-size: 18pt;
            font-weight: bold;
        }
        .box {
            border: 1px solid #CCCCCC;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 10px;
        }
        .total {
            background-color: #7A4FD0;
            padding: 8px;
            font-size: 14pt;
            font-weight: bold;
            border-radius: 6px;
            text-align: right;
        }
        .rodape {
            font-size: 9pt;
            color: #777777;
            margin-top: 20px;
            border-top: 1px solid #CCCCCC;
            padding-top: 5px;
            text-align: right;
        }
    </style>
</head>
<body>

<div class="header">
    <table width="100%">
        <tr>
            <td>
                <img src="{{ $logoPath }}" class="logo">
            </td>
            <td align="right" class="titulo">
                Simulação de Emplacamento
            </td>
        </tr>
    </table>
</div>

<div class="box">
    {!! nl2br(e($detalhe)) !!}
</div>

<div class="total">
    Total estimado: R$ {{ number_format($total, 2, ',', '.') }}
</div>

<div class="rodape">
    Gerado em {{ $dataHoje->format('d/m/Y H:i') }}
</div>

</body>
</html>
