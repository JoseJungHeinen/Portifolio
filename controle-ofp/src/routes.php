<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CadastroController;
use App\Controllers\FinanceiroController;
use App\Controllers\OfpController;
use App\Controllers\PainelController;
use App\Controllers\PerdaController;
use App\Controllers\UsuarioController;

/** @var App\Core\Router $router */

$operacao = ['estoque', 'comprador', 'gerente', 'admin'];
$financeiro = ['financeiro', 'gerente', 'diretor', 'admin'];
$baixa = ['financeiro', 'gerente', 'admin'];
$admin = ['admin'];

// Autenticação
$router->get('/login', [AuthController::class, 'showLogin'], publico: true);
$router->post('/login', [AuthController::class, 'doLogin'], publico: true);
$router->post('/logout', [AuthController::class, 'logout']);

// Início e painel
$router->get('/', [PainelController::class, 'inicio']);
$router->get('/painel', [PainelController::class, 'index']);

// OFPs (rotas fixas antes de /ofps/{id})
$router->get('/pendencias', [OfpController::class, 'pendencias'], $operacao);
$router->get('/ofps', [OfpController::class, 'index']);
$router->get('/ofps/exportar', [OfpController::class, 'exportar']);
$router->get('/ofps/nova', [OfpController::class, 'nova'], $operacao);
$router->post('/ofps', [OfpController::class, 'store'], $operacao);
$router->get('/ofps/{id}', [OfpController::class, 'show']);
$router->get('/ofps/{id}/editar', [OfpController::class, 'editar'], $operacao);
$router->post('/ofps/{id}', [OfpController::class, 'atualizar'], $operacao);
$router->post('/ofps/{id}/transicao', [OfpController::class, 'transicao']);
$router->post('/ofps/{id}/corrigir', [OfpController::class, 'corrigir'], ['comprador', 'financeiro', 'gerente', 'admin']);
$router->post('/ofps/{id}/anexos', [OfpController::class, 'anexar'], $operacao);
$router->get('/anexos/{id}', [OfpController::class, 'baixarAnexo']);

// Financeiro
$router->get('/financeiro', [FinanceiroController::class, 'index'], $financeiro);
$router->get('/financeiro/conciliacao', [FinanceiroController::class, 'conciliacao'], $financeiro);
$router->post('/financeiro/lote', [FinanceiroController::class, 'lote'], $baixa);
$router->post('/financeiro/{id}/baixa', [FinanceiroController::class, 'baixa'], $baixa);

// Perdas sugeridas
$router->get('/perdas', [PerdaController::class, 'index'], ['gerente', 'admin']);
$router->post('/perdas/confirmar', [PerdaController::class, 'confirmar'], ['gerente', 'admin']);

// Cadastros
$router->get('/cadastros/causais', [CadastroController::class, 'causais'], $admin);
$router->get('/cadastros/causais/novo', [CadastroController::class, 'novoCausal'], $admin);
$router->post('/cadastros/causais', [CadastroController::class, 'criarCausal'], $admin);
$router->get('/cadastros/causais/{id}', [CadastroController::class, 'editarCausal'], $admin);
$router->post('/cadastros/causais/{id}', [CadastroController::class, 'salvarCausal'], $admin);
$router->get('/cadastros/motivos', [CadastroController::class, 'motivos'], $admin);
$router->get('/cadastros/motivos/novo', [CadastroController::class, 'novoMotivo'], $admin);
$router->post('/cadastros/motivos', [CadastroController::class, 'criarMotivo'], $admin);
$router->get('/cadastros/motivos/{id}', [CadastroController::class, 'editarMotivo'], $admin);
$router->post('/cadastros/motivos/{id}', [CadastroController::class, 'salvarMotivo'], $admin);
$router->get('/cadastros/unidades', [CadastroController::class, 'unidades'], $admin);
$router->get('/cadastros/unidades/{id}', [CadastroController::class, 'editarUnidade'], $admin);
$router->post('/cadastros/unidades/{id}', [CadastroController::class, 'salvarUnidade'], $admin);
$router->get('/usuarios', [UsuarioController::class, 'index'], $admin);
$router->get('/usuarios/novo', [UsuarioController::class, 'novo'], $admin);
$router->post('/usuarios', [UsuarioController::class, 'criar'], $admin);
$router->get('/usuarios/{id}/editar', [UsuarioController::class, 'editar'], $admin);
$router->post('/usuarios/{id}', [UsuarioController::class, 'salvar'], $admin);
