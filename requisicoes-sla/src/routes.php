<?php
// @versao Ver.017.051126

use App\Controllers\{
    AuthController,

    DashboardController,
    UsuarioController,
    OrcamentoController,
    RequisicaoController,
    FornecedorController,
    ContratoController,
    EstoqueController,
    AprovacaoController,
    CompraController,
    FiscalController,
    RelatorioController,
    RelatorioConsumoFilialController,
    RelatorioConsumoController,
    RelatorioComprasController,
    RelatorioSaudeEstoqueController,
    ConfiguracaoController,
    ItemController,
    RamaisController,
    LinksUteisController,
};

// â”€â”€â”€ AutenticaÃ§Ã£o â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->get('/login',               [AuthController::class, 'showLogin']);
$router->post('/login',              [AuthController::class, 'doLogin']);
$router->post('/logout',             [AuthController::class, 'logout']);
$router->get('/senha/reset',         [AuthController::class, 'showReset']);
$router->post('/senha/reset',        [AuthController::class, 'doReset']);
$router->get('/senha/nova/{token}',  [AuthController::class, 'showNovaSenha']);
$router->post('/senha/nova/{token}', [AuthController::class, 'doNovaSenha']);
$router->get('/senha/alterar',       [AuthController::class, 'showAlterar']);
$router->post('/senha/alterar',      [AuthController::class, 'doAlterar']);

// Redireciona raiz para dashboard
$router->get('/', [DashboardController::class, 'index']);

// â”€â”€â”€ Dashboard â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->get('/dashboard',                         [DashboardController::class, 'index']);
$router->get('/dashboard/notificacoes',            [DashboardController::class, 'notificacoes']);
$router->post('/dashboard/notificacoes/{id}/lida', [DashboardController::class, 'marcarLida']);
$router->post('/dashboard/preferencias',           [DashboardController::class, 'savePrefs']);

// â”€â”€â”€ UsuÃ¡rios â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->get('/usuarios',              [UsuarioController::class, 'index']);
$router->get('/usuarios/novo',         [UsuarioController::class, 'form']);
$router->post('/usuarios',             [UsuarioController::class, 'store']);
$router->get('/usuarios/{id}/editar',  [UsuarioController::class, 'form']);
$router->post('/usuarios/{id}',        [UsuarioController::class, 'update']);
$router->post('/usuarios/{id}/delete', [UsuarioController::class, 'destroy']);

// â”€â”€â”€ OrÃ§amentos â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->get('/orcamentos',                          [OrcamentoController::class, 'index']);
$router->get('/orcamentos/novo',                     [OrcamentoController::class, 'form']);
$router->post('/orcamentos',                         [OrcamentoController::class, 'store']);
$router->post('/orcamentos/{id}',                    [OrcamentoController::class, 'update']);
$router->get('/orcamentos/solicitacoes',             [OrcamentoController::class, 'solicitacoes']);
$router->post('/orcamentos/solicitacoes',            [OrcamentoController::class, 'solicitar']);
$router->post('/orcamentos/solicitacoes/{id}/aprovar',  [OrcamentoController::class, 'aprovar']);
$router->post('/orcamentos/solicitacoes/{id}/rejeitar', [OrcamentoController::class, 'rejeitar']);

// â”€â”€â”€ RequisiÃ§Ãµes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->get('/requisicoes',                       [RequisicaoController::class, 'index']);
$router->get('/requisicoes/nova',                  [RequisicaoController::class, 'form']);
$router->post('/requisicoes',                      [RequisicaoController::class, 'store']);
$router->get('/requisicoes/{id}/imprimir',         [RequisicaoController::class, 'imprimir']);
$router->get('/requisicoes/{id}',                  [RequisicaoController::class, 'show']);
$router->get('/requisicoes/{id}/editar',           [RequisicaoController::class, 'form']);
$router->post('/requisicoes/{id}/atualizar',       [RequisicaoController::class, 'atualizar']);
$router->post('/requisicoes/{id}/enviar',          [RequisicaoController::class, 'enviar']);
$router->post('/requisicoes/{id}/cancelar',        [RequisicaoController::class, 'cancelar']);
$router->post('/requisicoes/{id}/excluir',         [RequisicaoController::class, 'excluir']);
$router->get('/requisicoes/{id}/bloqueio-orcamento', [RequisicaoController::class, 'bloqueioOrcamento']);

// â”€â”€â”€ AprovaÃ§Ãµes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->get('/aprovacoes',                        [AprovacaoController::class, 'index']);
$router->post('/aprovacoes/{id}/aprovar',          [AprovacaoController::class, 'aprovar']);
$router->post('/aprovacoes/{id}/rejeitar',         [AprovacaoController::class, 'rejeitar']);

// â”€â”€â”€ Itens â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->get('/itens',                 [ItemController::class, 'index']);
$router->get('/itens/novo',            [ItemController::class, 'form']);
$router->get('/itens/proximo-codigo',          [ItemController::class, 'proximoCodigo']);
$router->get('/itens/comparativo',             [ItemController::class, 'comparativo']);
$router->post('/itens',                [ItemController::class, 'store']);
$router->get('/itens/{id}/editar',          [ItemController::class, 'form']);
$router->get('/itens/{id}/relatorio',          [ItemController::class, 'relatorio']);
$router->post('/itens/{id}',                [ItemController::class, 'update']);
$router->post('/itens/{id}/toggle',            [ItemController::class, 'toggleAtivo']);
$router->post('/itens/{id}/excluir',           [ItemController::class, 'excluir']);

// Painel de compras (docs/2026-10-29-painel-compras-fase1.md)
use App\Controllers\PainelController;
$router->get('/painel',                             [PainelController::class, 'index']);

// Solicitações de compra
use App\Controllers\SolicitacaoCompraController;
$router->get('/solicitacoes',                       [SolicitacaoCompraController::class, 'index']);
$router->post('/solicitacoes',                      [SolicitacaoCompraController::class, 'store']);
$router->get('/solicitacoes/anexos/{anexoId}',      [SolicitacaoCompraController::class, 'verAnexo']);
$router->get('/solicitacoes/{id}',                  [SolicitacaoCompraController::class, 'show']);
$router->post('/solicitacoes/{id}/comentar',        [SolicitacaoCompraController::class, 'comentar']);
$router->post('/solicitacoes/{id}/aprovar',         [SolicitacaoCompraController::class, 'aprovar']);
$router->post('/solicitacoes/{id}/recusar',         [SolicitacaoCompraController::class, 'recusar']);
$router->post('/solicitacoes/{id}/cancelar',        [SolicitacaoCompraController::class, 'cancelar']);
$router->post('/solicitacoes/{id}/concluir',        [SolicitacaoCompraController::class, 'concluir']);
$router->post('/solicitacoes/{id}/confirmar-recebimento', [SolicitacaoCompraController::class, 'confirmarRecebimento']);
$router->post('/solicitacoes/{id}/urgencia',        [SolicitacaoCompraController::class, 'urgencia']);

// Brindes
use App\Controllers\BrindeController;
$router->get('/brindes',                        [BrindeController::class, 'index']);
$router->get('/brindes/novo',                   [BrindeController::class, 'form']);
$router->post('/brindes',                       [BrindeController::class, 'salvar']);
$router->get('/brindes/requisicoes',            [BrindeController::class, 'requisicoes']);
$router->get('/brindes/requisicoes/{id}',       [BrindeController::class, 'showRequisicao']);
$router->get('/brindes/requisicao/nova',        [BrindeController::class, 'novaRequisicao']);
$router->post('/brindes/requisicao',            [BrindeController::class, 'criarRequisicao']);
$router->get('/brindes/fotos/{fotoId}',         [BrindeController::class, 'verFoto']);
$router->post('/brindes/fotos/{fotoId}/excluir',[BrindeController::class, 'excluirFoto']);
$router->get('/brindes/{id}',                   [BrindeController::class, 'show']);
$router->get('/brindes/{id}/editar',            [BrindeController::class, 'form']);
$router->post('/brindes/{id}',                  [BrindeController::class, 'salvar']);
$router->post('/brindes/{id}/excluir',          [BrindeController::class, 'excluir']);
$router->post('/brindes/{id}/fotos',            [BrindeController::class, 'uploadFoto']);
$router->get('/brindes/{id}/entrada',           [BrindeController::class, 'entradaForm']);
$router->post('/brindes/{id}/entrada',          [BrindeController::class, 'entrada']);
$router->post('/estoque/{id}/ajuste-estoque', [EstoqueController::class, 'ajusteEstoque']);

// â”€â”€â”€ Estoque â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->get('/estoque',                           [EstoqueController::class, 'index']);
$router->get('/estoque/entrada',                   [EstoqueController::class, 'formEntrada']);
$router->post('/estoque/entrada',                  [EstoqueController::class, 'entrada']);
$router->post('/estoque/saida',                    [EstoqueController::class, 'saida']);
$router->get('/estoque/{id}/qrcode',               [EstoqueController::class, 'qrcode']);
$router->get('/estoque/{id}/historico',            [EstoqueController::class, 'historico']);
$router->get('/api/estoque/item/{id}',             [EstoqueController::class, 'saldoItem']);
$router->get('/api/estoque/filial/{id}',           [EstoqueController::class, 'saldoPorFilial']);

// Auditoria de Estoque
use App\Controllers\AuditoriaEstoqueController;
$router->get('/auditoria-estoque',                      [AuditoriaEstoqueController::class, 'index']);
$router->get('/auditoria-estoque/nova',                 [AuditoriaEstoqueController::class, 'nova']);
$router->post('/auditoria-estoque',                     [AuditoriaEstoqueController::class, 'criar']);
$router->get('/auditoria-estoque/{id}',                 [AuditoriaEstoqueController::class, 'show']);
$router->get('/auditoria-estoque/{id}/imprimir',        [AuditoriaEstoqueController::class, 'imprimir']);
$router->post('/auditoria-estoque/{id}/conferir',       [AuditoriaEstoqueController::class, 'conferir']);
$router->get('/auditoria-estoque/{id}/relatorio',       [AuditoriaEstoqueController::class, 'relatorio']);
$router->post('/auditoria-estoque/{id}/aplicar-ajustes', [AuditoriaEstoqueController::class, 'aplicarAjustes']);
$router->post('/auditoria-estoque/{id}/excluir',        [AuditoriaEstoqueController::class, 'excluir']);
$router->post('/auditoria-estoque/{id}/fotos',          [AuditoriaEstoqueController::class, 'uploadFoto']);
$router->get('/auditoria-estoque/fotos/{fotoId}',       [AuditoriaEstoqueController::class, 'verFoto']);
$router->post('/auditoria-estoque/fotos/{fotoId}/excluir', [AuditoriaEstoqueController::class, 'excluirFoto']);

use App\Controllers\TransferenciaController;
$router->get('/transferencias/recebimento',     [TransferenciaController::class, 'recebimento']);
$router->get('/transferencias/{id}',             [TransferenciaController::class, 'show']);
$router->post('/transferencias/{id}/confirmar',  [TransferenciaController::class, 'confirmar']);

$router->get('/api/itens/{id}/media-preco',        [ItemController::class,   'mediaPreco']);

// â”€â”€â”€ Compras / CotaÃ§Ãµes / OC â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->get('/compras/cotacoes',                  [CompraController::class, 'cotacoes']);
$router->post('/compras/cotacoes',                 [CompraController::class, 'criarCotacao']);
$router->post('/compras/cotacoes/email-enviado',      [CompraController::class, 'marcarEmailEnviado']);
$router->get('/compras/cotacoes/itens-para-resposta', [CompraController::class, 'itensPararespostaManual']);
$router->get('/api/cotacoes/{id}/itens',             [CompraController::class, 'itensDaCotacao']);
$router->get('/api/fornecedor/{id}/creditos',        [CompraController::class, 'creditosFornecedor']);
// O Router converte {cnpj} em [a-zA-Z0-9\-]+, então o JS precisa mandar só
// dígitos: CNPJ formatado (com . e /) não casaria com a rota.
$router->get('/api/cnpj/{cnpj}', [FornecedorController::class, 'consultarCnpj']);
$router->post('/compras/cotacoes/resposta-manual',    [CompraController::class, 'salvarRespostaManual']);
$router->get('/compras/cotacoes/{id}/editar',      [CompraController::class, 'editarCotacaoForm']);
$router->post('/compras/cotacoes/{id}/editar',     [CompraController::class, 'salvarCotacao']);
$router->post('/compras/cotacoes/{id}/refazer',    [CompraController::class, 'refazerCotacao']);
$router->post('/compras/cotacoes/{id}/cancelar',   [CompraController::class, 'cancelarCotacao']);
$router->get('/compras/cotacoes/{id}/comparativo', [CompraController::class, 'comparativo']);
$router->post('/compras/cotacoes/{id}/gerar-oc',   [CompraController::class, 'gerarOC']);
$router->get('/compras/ordens',                    [CompraController::class, 'ordens']);
$router->get('/compras/ordens/{id}',               [CompraController::class, 'showOC']);
$router->get('/compras/ordens/{id}/pdf',           [CompraController::class, 'pdfOC']);
$router->post('/compras/ordens/{id}/prazos',       [CompraController::class, 'salvarPrazosOC']);
$router->post('/compras/ordens/{id}/enviada',      [CompraController::class, 'marcarEnviadaOC']);
$router->post('/compras/ordens/{id}/transito',     [CompraController::class, 'marcarTransitoOC']);
$router->post('/compras/ordens/{id}/recebimento',  [CompraController::class, 'marcarRecebimentoOC']);
$router->get('/cotacao/responder/{token}',         [CompraController::class, 'responderCotacao']);
$router->post('/cotacao/responder/{token}',        [CompraController::class, 'salvarResposta']);

// â”€â”€â”€ CrÃ©ditos de Fornecedor â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->post('/fornecedores/{id}/creditos',         [FornecedorController::class, 'adicionarCredito']);
$router->post('/fornecedores/creditos/{id}/excluir', [FornecedorController::class, 'excluirCredito']);

// â”€â”€â”€ Fornecedores â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->get('/fornecedores',                      [FornecedorController::class, 'index']);
// Importação por planilha (antes de /fornecedores/{id}, senão "importar" vira id)
$router->get('/fornecedores/importar',             [FornecedorController::class, 'importarForm']);
$router->post('/fornecedores/importar',            [FornecedorController::class, 'importarPrevia']);
$router->post('/fornecedores/importar/confirmar',  [FornecedorController::class, 'importarConfirmar']);
$router->get('/fornecedores/importar/modelo',      [FornecedorController::class, 'modelo']);
$router->get('/fornecedores/novo',                 [FornecedorController::class, 'form']);
$router->post('/fornecedores',                     [FornecedorController::class, 'store']);
$router->post('/api/fornecedores/rapido',          [FornecedorController::class, 'rapido']);
$router->get('/fornecedores/{id}/editar',          [FornecedorController::class, 'form']);
$router->get('/fornecedores/{id}',                 [FornecedorController::class, 'show']);
$router->post('/fornecedores/{id}/toggle',         [FornecedorController::class, 'toggleAtivo']);
$router->post('/fornecedores/{id}/confirmar-regime', [FornecedorController::class, 'confirmarRegime']);
$router->post('/fornecedores/{id}/bloquear',        [FornecedorController::class, 'bloquear']);
$router->post('/fornecedores/{id}/excluir',         [FornecedorController::class, 'excluir']);
$router->post('/fornecedores/{id}',                [FornecedorController::class, 'update']);

// AvaliaÃ§Ãµes de Fornecedores
$router->post('/fornecedores/{id}/avaliar',        [FornecedorController::class, 'avaliar']);
$router->post('/fornecedores/{id}/remover-avaliacao', [FornecedorController::class, 'removerAvaliacao']);

// Documentos de Fornecedores
$router->post('/fornecedores/{id}/documentos',               [FornecedorController::class, 'uploadDocumento']);
$router->get('/fornecedores/documentos/{id}/download',       [FornecedorController::class, 'downloadDocumento']);
$router->post('/fornecedores/documentos/{id}/excluir',       [FornecedorController::class, 'excluirDocumento']);
// â”€â”€â”€ Contratos â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->get('/contratos',                         [ContratoController::class, 'index']);
$router->get('/contratos/novo',                    [ContratoController::class, 'form']);
$router->post('/contratos',                        [ContratoController::class, 'store']);
$router->get('/contratos/{id}/editar',             [ContratoController::class, 'form']);
$router->post('/contratos/{id}',                   [ContratoController::class, 'update']);
$router->get('/contratos/{id}/arquivo',            [ContratoController::class, 'downloadArquivo']);
$router->post('/contratos/{id}/documentos',                  [ContratoController::class, 'uploadDocumento']);
$router->get('/contratos/{id}/documentos',                   [ContratoController::class, 'listarDocumentos']);
$router->get('/contratos/documentos/{id}/download',          [ContratoController::class, 'downloadDocumento']);
$router->post('/contratos/documentos/{id}/excluir',          [ContratoController::class, 'excluirDocumento']);

// â”€â”€â”€ ConferÃªncia Fiscal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->get('/fiscal',                            [FiscalController::class, 'index']);
$router->post('/fiscal/upload',                    [FiscalController::class, 'upload']);
$router->get('/fiscal/{id}',                       [FiscalController::class, 'show']);
$router->post('/fiscal/{id}/aprovar',              [FiscalController::class, 'aprovar']);
$router->post('/fiscal/{id}/rejeitar',             [FiscalController::class, 'rejeitar']);
$router->post('/fiscal/{id}/conferir',             [FiscalController::class, 'confirmarConferencia']);
$router->get('/fiscal/{id}/comparativo-estoque',   [FiscalController::class, 'comparativoEstoque']);

// â”€â”€â”€ RelatÃ³rios â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->get('/relatorios',                        [RelatorioController::class, 'index']);
$router->get('/relatorios/compras',                [RelatorioComprasController::class, 'index']);
$router->get('/relatorios/filiais',                [RelatorioConsumoFilialController::class, 'index']);
$router->get('/relatorios/consumo',                [RelatorioConsumoController::class, 'index']);
$router->get('/relatorios/movimentacao-estoque',   [RelatorioController::class, 'movimentacaoEstoque']);
$router->get('/relatorios/saude-estoque',          [RelatorioSaudeEstoqueController::class, 'index']);
$router->get('/relatorios/saving',                 [RelatorioController::class, 'saving']);
$router->get('/relatorios/auditoria',              [RelatorioController::class, 'auditoria']);
$router->get('/relatorios/anomalias',              [RelatorioController::class, 'anomalias']);
$router->get('/relatorios/brindes',                [RelatorioController::class, 'brindes']);

// â”€â”€â”€ ConfiguraÃ§Ãµes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$router->get('/configuracoes/perfis',             [ConfiguracaoController::class, 'perfis']);
$router->post('/configuracoes/perfis',            [ConfiguracaoController::class, 'savePerfis']);
$router->get('/configuracoes/alcadas',             [ConfiguracaoController::class, 'alcadas']);
$router->post('/configuracoes/alcadas',            [ConfiguracaoController::class, 'saveAlcadas']);
$router->get('/configuracoes/setores',                    [ConfiguracaoController::class, 'setores']);
$router->post('/configuracoes/setores',                   [ConfiguracaoController::class, 'saveSetor']);
$router->post('/configuracoes/setores/{id}/toggle',  [ConfiguracaoController::class, 'toggleSetor']);
$router->post('/configuracoes/setores/{id}/excluir', [ConfiguracaoController::class, 'deleteSetor']);
$router->get('/configuracoes/grupos',              [ConfiguracaoController::class, 'grupos']);
$router->post('/configuracoes/grupos',             [ConfiguracaoController::class, 'saveGrupo']);
$router->get('/configuracoes/parametros',          [ConfiguracaoController::class, 'parametros']);
$router->post('/configuracoes/parametros',         [ConfiguracaoController::class, 'saveParametros']);
$router->get('/configuracoes/integracoes',  [ConfiguracaoController::class, 'integracoes']);
$router->post('/configuracoes/integracoes', [ConfiguracaoController::class, 'saveIntegracoes']);
$router->get('/configuracoes/filiais',              [ConfiguracaoController::class, 'filiais']);
$router->post('/configuracoes/filiais',             [ConfiguracaoController::class, 'filialStore']);
$router->post('/configuracoes/filiais/{id}',        [ConfiguracaoController::class, 'filialUpdate']);
$router->post('/configuracoes/filiais/{id}/toggle', [ConfiguracaoController::class, 'filialToggle']);
$router->get('/configuracoes/gestores',              [ConfiguracaoController::class, 'gestores']);
$router->post('/configuracoes/gestores',             [ConfiguracaoController::class, 'saveGestor']);
$router->post('/configuracoes/gestores/{id}/toggle', [ConfiguracaoController::class, 'toggleGestor']);
$router->post('/configuracoes/gestores/{id}/excluir',[ConfiguracaoController::class, 'deleteGestor']);
$router->post('/estoque/transferencia',             [EstoqueController::class,      'transferencia']);
$router->post('/estoque/definir-minimo',            [EstoqueController::class,      'definirMinimo']);

// --- Utilidades ---
$router->get('/ramais',      [RamaisController::class,     'index']);
$router->get('/ramais/imprimir', [RamaisController::class, 'imprimir']);
$router->get('/links-uteis', [LinksUteisController::class, 'index']);

// Anexos de uploads/ só com login (auditoria de segurança M2)
use App\Controllers\AnexoController;
$router->get('/anexos/{pasta}/{id}', [AnexoController::class, 'ver']);

