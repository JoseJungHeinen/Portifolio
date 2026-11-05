<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TransferenciaController;
use App\Http\Controllers\EmplacamentoController;
use App\Http\Controllers\HistoricoOrcamentosController;

Route::get('/', function () {
    return redirect('/transferencia');
});

Route::get('/transferencia', [TransferenciaController::class, 'index']);
Route::post('/transferencia/calcular', [TransferenciaController::class, 'calcular']);
Route::get('/transferencia/pdf/{id}', [TransferenciaController::class, 'pdf']);

Route::get('/emplacamento', [EmplacamentoController::class, 'index']);
Route::post('/emplacamento/calcular', [EmplacamentoController::class, 'calcular']);
Route::get('/emplacamento/pdf/{id}', [EmplacamentoController::class, 'pdf']);

Route::get('/historico-orcamentos', [HistoricoOrcamentosController::class, 'index']);
