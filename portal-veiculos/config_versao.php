<?php
// @versao Ver.001.020926
/**
 * Arquivo central de versões do sistema
 * Inclua em TODAS as telas PHP com:
 *   require_once __DIR__ . '/config_versao.php';
 */

// ======================================================================
// Versão geral do sistema
// ======================================================================

define('APP_VERSAO', 'Ver.001.170126');


// ======================================================================
// Telas raiz / acesso
// ======================================================================

// index.php (login)
define('VERSAO_INDEX',             'Ver.001.170126');

// menu.php (menu principal)
define('VERSAO_MENU',              'Ver.001.220126');

// logout.php
define('VERSAO_LOGOUT',            'Ver.001.170126');

// troca_senha.php
define('VERSAO_TROCA_SENHA',       'Ver.001.170126');

// troca_senha_obrigatoria.php
define('VERSAO_TROCA_SENHA_OBRIG', 'Ver.001.170126');

// alterar_senha.php
define('VERSAO_ALTERAR_SENHA',     'Ver.001.170126');


// ======================================================================
// Módulo Orçamentos / Cartão / Emplacamento / Transferência
// ======================================================================

// orcamento_form.php
define('VERSAO_ORCAMENTO_FORM',        'Ver.001.170126');

// ver_orcamento.php
define('VERSAO_VER_ORCAMENTO',         'Ver.001.220126');

// ver_cartao.php
define('VERSAO_VER_CARTAO',            'Ver.001.170126');

// ver_emplacamento.php
define('VERSAO_VER_EMPLACAMENTO',      'Ver.001.220126');

// ver_transferencia.php
define('VERSAO_VER_TRANSFERENCIA',     'Ver.001.170126');

// config_cartao.php
define('VERSAO_CONFIG_CARTAO',         'Ver.001.170126');

// config_emplacamento.php
define('VERSAO_CONFIG_EMPLACAMENTO',   'Ver.001.220126');

// config_transferencia.php
define('VERSAO_CONFIG_TRANSFERENCIA',  'Ver.001.170126');

// conteudo_transferencia.php
define('VERSAO_CONTEUDO_TRANSFERENCIA','Ver.001.170126');

// gerar_pdf.php
define('VERSAO_GERAR_PDF',             'Ver.001.170126');


// ======================================================================
// Configurações de menus / links / banners / ramais / texto legal
// ======================================================================

// config_menu.php
define('VERSAO_CONFIG_MENU',           'Ver.001.170126');

// reorder_menu.php
define('VERSAO_REORDER_MENU',          'Ver.001.170126');

// salvar_ordem_menu.php
define('VERSAO_SALVAR_ORDEM_MENU',     'Ver.001.170126');

// config_links.php
define('VERSAO_CONFIG_LINKS',          'Ver.001.170126');

// links_uteis.php
define('VERSAO_LINKS_UTEIS',           'Ver.001.170126');

// links_uteis_servidor.php
define('VERSAO_LINKS_UTEIS_SERVIDOR',  'Ver.001.170126');

// config_banners.php
define('VERSAO_CONFIG_BANNERS',        'Ver.001.170126');

// config_texto_legal.php
define('VERSAO_CONFIG_TEXTO_LEGAL',    'Ver.001.170126');

// ramais_uteis.php
define('VERSAO_RAMAIS_UTEIS',          'Ver.001.170126');

// ramais_uteis_servidor.php
define('VERSAO_RAMAIS_UTEIS_SERVIDOR', 'Ver.001.170126');

// ramais_uteis_impressao.php
define('VERSAO_RAMAIS_UTEIS_IMPRESSAO','Ver.001.020926');

// config_ramais.php
define('VERSAO_CONFIG_RAMAIS',         'Ver.001.170126');


// ======================================================================
// Usuários / Treinamentos
// ======================================================================

// gerenciar_usuarios.php
define('VERSAO_GERENCIAR_USUARIOS',    'Ver.001.220126');

// cadastro_usuario.php
define('VERSAO_CADASTRO_USUARIO',      'Ver.001.220126');

// treinamento.php
define('VERSAO_TREINAMENTO',           'Ver.001.170126');

// treinamentos_servidor.php
define('VERSAO_TREINAMENTO_SERVIDOR',  'Ver.001.170126');

// config_treinamento.php
define('VERSAO_CONFIG_TREINAMENTO',    'Ver.001.170126');


// ======================================================================
// Outros utilitários / técnicos
// ======================================================================

// teste_conexao.php
define('VERSAO_TESTE_CONEXAO',         'Ver.001.170126');

// teste_popup.php
define('VERSAO_TESTE_POPUP',           'Ver.001.170126');

// api_ordem_menu.php
define('VERSAO_API_ORDEM_MENU',        'Ver.001.170126');

// buscar_servidor.php
define('VERSAO_BUSCAR_SERVIDOR',       'Ver.001.170126');

// configuracoes.php
define('VERSAO_CONFIGURACOES',         'Ver.001.170126');

// criar_tabelas.sql não é PHP, então não entra aqui
// arquivos .blade.php e .html também não precisam de constante neste arquivo

// ======================================================================
// FIM DO ARQUIVO
// ======================================================================
