<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Simulador Exemplo - Empresa Exemplo</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --exemplo-red:   #2347C8;
            --exemplo-gray:  #606060;
            --exemplo-yellow:#7A4FD0;
            --exemplo-light: #EAEAEA;
        }
        body { background-color: var(--exemplo-light); color: var(--exemplo-gray); font-family: 'Roboto', 'Arial', sans-serif; }
        h1, h2, h3, .navbar-brand { font-family: 'Montserrat', 'Arial', sans-serif; font-weight: 700; color: var(--exemplo-red); }
        .btn-primary { background-color: var(--exemplo-red); border-color: var(--exemplo-red); }
        .btn-primary:hover { background-color: #cc0000; border-color: #cc0000; }
        .navbar { background-color: #ffffff; border-bottom: 3px solid var(--exemplo-red); }
        .nav-link { color: var(--exemplo-gray); font-weight: 500; }
        .nav-link.active { color: var(--exemplo-red) !important; }
        .card { border-radius: 8px; border-color: #ddd; }
        footer { margin-top: 40px; padding: 16px 0; background-color: #ffffff; border-top: 1px solid #ddd; font-size: 0.85rem; color: var(--exemplo-gray); }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg mb-4">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="{{ url('/') }}">
            <img src="{{ asset('img/logo.svg') }}" alt="Exemplo" style="height:40px;" class="me-2">
            <span>Simulador de Emplacamento</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link {{ request()->is('transferencia*') ? 'active' : '' }}" href="{{ url('/transferencia') }}">Transferência</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->is('emplacamento*') ? 'active' : '' }}" href="{{ url('/emplacamento') }}">Emplacamento</a></li>
                <li class="nav-item"><a class="nav-link {{ request()->is('historico-orcamentos*') ? 'active' : '' }}" href="{{ url('/historico-orcamentos') }}">Histórico</a></li>
            </ul>
        </div>
    </div>
</nav>

@yield('content')

<footer>
    <div class="container text-center">Empresa Exemplo &mdash; Simulador de Transferência e Emplacamento</div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
