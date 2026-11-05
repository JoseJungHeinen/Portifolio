@extends('layouts.app')

@section('content')
<div class="container">
    <h1 class="mb-3">Resultado do Emplacamento</h1>

    <div class="card mb-3">
        <div class="card-body">
            <h3 class="h5 mb-3">{{ $tipo->nome }}</h3>
            <p>{!! nl2br(e($texto)) !!}</p>
            <h2 class="h4 mt-3">
                Total: R$ {{ number_format($total, 2, ',', '.') }}
            </h2>
        </div>
    </div>

    <a href="{{ url('/emplacamento') }}" class="btn btn-secondary me-2">
        Nova simulação
    </a>
    <a href="{{ url('/emplacamento/pdf/'.$orcamento->id) }}" class="btn btn-primary">
        Gerar PDF
    </a>
</div>
@endsection
