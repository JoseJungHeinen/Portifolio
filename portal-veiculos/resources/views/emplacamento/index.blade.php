@extends('layouts.app')

@section('content')
<div class="container">
    <h1 class="mb-4">Simulador de Emplacamento</h1>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $erro)
                    <li>{{ $erro }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ url('/emplacamento/calcular') }}">
                @csrf

                <div class="mb-3">
                    <label class="form-label">Tipo de emplacamento</label>
                    <select name="tipo_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        @foreach ($tipos as $tipo)
                            <option value="{{ $tipo->id }}" {{ old('tipo_id') == $tipo->id ? 'selected' : '' }}>
                                {{ $tipo->nome }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Data da nota fiscal</label>
                    <input type="date" name="data_nf" class="form-control"
                           value="{{ old('data_nf', date('Y-m-d')) }}" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Valor da nota fiscal (R$)</label>
                    <input type="number" step="0.01" name="valor_nf" class="form-control"
                           value="{{ old('valor_nf') }}" required>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" value="1"
                           id="escolha_placa" name="escolha_placa" {{ old('escolha_placa') ? 'checked' : '' }}>
                    <label class="form-check-label" for="escolha_placa">
                        Deseja escolher a placa
                    </label>
                </div>

                <div class="mb-3">
                    <label class="form-label">Sequencial (opcional)</label>
                    <input type="text" name="sequencial" class="form-control"
                           value="{{ old('sequencial') }}">
                </div>

                <button type="submit" class="btn btn-primary">
                    Calcular
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
