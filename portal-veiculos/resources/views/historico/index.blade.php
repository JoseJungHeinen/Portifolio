@extends('layouts.app')

@section('content')
<div class="container">
    <h1 class="mb-4">Histórico de Orçamentos (últimos 365 dias)</h1>

    <form method="GET" class="row g-3 mb-3">
        <div class="col-md-3">
            <label class="form-label">Tipo</label>
            <select name="tipo" class="form-select">
                <option value="">Todos</option>
                <option value="transferencia" {{ request('tipo') == 'transferencia' ? 'selected' : '' }}>Transferência</option>
                <option value="emplacamento" {{ request('tipo') == 'emplacamento' ? 'selected' : '' }}>Emplacamento</option>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label">Data inicial</label>
            <input type="date" name="data_inicial" class="form-control" value="{{ request('data_inicial') }}">
        </div>

        <div class="col-md-3">
            <label class="form-label">Data final</label>
            <input type="date" name="data_final" class="form-control" value="{{ request('data_final') }}">
        </div>

        <div class="col-md-3 d-flex align-items-end">
            <button class="btn btn-primary w-100">Filtrar</button>
        </div>
    </form>

    <div class="card">
        <div class="card-body table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tipo</th>
                        <th>Subtipo</th>
                        <th>Total (R$)</th>
                        <th>Criado em</th>
                        <th>Detalhes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orcamentos as $orc)
                        <tr>
                            <td>{{ $orc->id }}</td>
                            <td>{{ ucfirst($orc->tipo) }}</td>
                            <td>{{ $orc->subtipo_id }}</td>
                            <td>{{ number_format($orc->total, 2, ',', '.') }}</td>
                            <td>{{ optional($orc->criado_em)->format('d/m/Y H:i') }}</td>
                            <td>
                                <button class="btn btn-outline-secondary btn-sm" type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#detalhe-{{ $orc->id }}">
                                    Ver texto
                                </button>
                            </td>
                        </tr>
                        <tr class="collapse" id="detalhe-{{ $orc->id }}">
                            <td colspan="6">
                                <pre class="mb-0">{{ $orc->texto_detalhe }}</pre>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">Nenhum orçamento encontrado.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            {{ $orcamentos->withQueryString()->links() }}
        </div>
    </div>
</div>
@endsection
