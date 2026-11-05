@extends('layouts.app')

@section('content')
<div class="container">
    <h1 class="mb-4">Simulador de Transferência</h1>

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
            <form method="POST" action="{{ url('/transferencia/calcular') }}">
                @csrf

                <div class="mb-3">
                    <label class="form-label">Tipo de transferência</label>
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
                    <label class="form-label">Ano do veículo</label>
                    <input type="number" name="ano_veiculo" class="form-control"
                           value="{{ old('ano_veiculo') }}" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Mês atual (1 a 12)</label>
                    <input type="number" name="mes_atual" class="form-control"
                           value="{{ old('mes_atual', date('n')) }}" required>
                </div>

                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="1"
                           id="ipva_pago" name="ipva_pago" {{ old('ipva_pago') ? 'checked' : '' }}>
                    <label class="form-check-label" for="ipva_pago">
                        IPVA já está pago
                    </label>
                </div>

                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" value="1"
                           id="intermunicipal" name="intermunicipal" {{ old('intermunicipal') ? 'checked' : '' }}>
                    <label class="form-check-label" for="intermunicipal">
                        Transferência intermunicipal
                    </label>
                </div>

                <button type="submit" class="btn btn-primary">
                    Calcular
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
