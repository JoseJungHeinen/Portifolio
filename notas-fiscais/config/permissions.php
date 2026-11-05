<?php
// @versao Sys_24 V_09_30/09/2026
declare(strict_types=1);

// O módulo `relatorios` tem uma ação por relatório — a lista mora em
// ReportAccess para o catálogo do Controle de Acesso e a tela não saírem
// do sincronismo. require_once: em contexto web o helper já veio do
// public/index.php, mas este arquivo também é lido fora dele.
require_once __DIR__ . '/../src/Helpers/ReportAccess.php';

/**
 * Mapa RBAC (Role Based Access Control) do módulo Workflow NF.
 *
 * Estrutura: $permissoes[$perfil][$modulo][$acao] = bool
 *
 * Perfis disponíveis (espelham os perfis da tabela `usuarios` do sistema maior):
 *   super_admin  — acesso completo (bypass total em Auth::can)
 *   admin        — SÓ cadastros: funcionários, grupos e templates.
 *                  NÃO acessa processos, capturas, financeiro, relatórios
 *                  nem dashboard — o bypass de admin acabou na migration 030.
 *   gestor       — aprovador + visualização gerencial
 *   diretor      — aprovador + visualização gerencial
 *   comprador    — abre e acompanha processos
 *   almoxarife   — visualiza tarefas e processos
 *   solicitante  — abre processos básicos
 *
 * Negação por padrão: qualquer perfil/módulo/ação não listado = false.
 */

return [

    // ─────────────────────────────────────────
    // SUPER ADMIN — acesso completo
    // (na prática nem consulta este mapa: Auth::can dá bypass total; fica aqui
    //  para a tela de perfis conseguir exibir o que ele pode)
    // ─────────────────────────────────────────
    'super_admin' => [
        'dashboard'   => ['ver_visao_geral' => true, 'ver_meus_processos' => true, 'ver_processos' => true, 'ver_aprovacoes' => true, 'ver_financeiro' => true, 'ver_desempenho' => true],
        'capturas'    => ['ver' => true, 'vincular' => true],
        'prefluxo'    => ['ver' => true, 'editar' => true],
        'processos'   => ['ver' => true, 'ver_todos' => true, 'criar' => true, 'editar' => true, 'aprovar' => true, 'devolver' => true, 'cancelar' => true, 'cancelar_abertura' => true, 'alterar_fluxo' => true, 'editar_dados' => true, 'assumir' => true, 'solicitar_cancelamento' => true, 'gerenciar_anexos' => true, 'descartar_teste' => true, 'ver_teste' => true],
        'tarefas'     => ['ver' => true, 'aprovar' => true, 'devolver' => true, 'reatribuir' => true, 'reatribuir_admin' => true],
        'financeiro'  => ['ver' => true, 'agendar' => true, 'registrar_pagamento' => true, 'cancelar' => true, 'exportar' => true],
        'fiscal'      => ['ver' => true, 'conferir' => true, 'atualizar' => true],
        'pagamentos'  => ['ver' => true, 'registrar_pagamento' => true, 'exportar' => true],
        'faturamento' => ['ver' => true],
        'templates'   => ['ver' => true, 'criar' => true, 'editar' => true, 'publicar' => true, 'arquivar' => true, 'testar' => true],
        'grupos'      => ['ver' => true, 'criar' => true, 'editar' => true, 'excluir' => true, 'gerenciar_membros' => true],
        'tabelas'     => ['ver' => true, 'criar' => true, 'editar' => true, 'excluir' => true, 'gerenciar_itens' => true],
        'relatorios'  => ReportAccess::todasLiberadas(),
        'arquivos'    => ['download' => true],
        'notificacoes'=> ['ver' => true, 'marcar_lida' => true],
    ],

    // ─────────────────────────────────────────
    // ADMIN — só cadastros (funcionários, grupos, templates).
    // Sem processos, capturas, financeiro, relatórios e dashboard: quem
    // administra o sistema não precisa enxergar o conteúdo dos processos.
    // O acesso à tela de Controle de Acesso (funcionários) é dado por
    // Permissions::isAdminCadastros(), não por este mapa.
    // ─────────────────────────────────────────
    'admin' => [
        'templates'   => ['ver' => true, 'criar' => true, 'editar' => true, 'publicar' => true, 'arquivar' => true, 'testar' => false],
        'grupos'      => ['ver' => true, 'criar' => true, 'editar' => true, 'excluir' => true, 'gerenciar_membros' => true],
        'tabelas'     => ['ver' => true, 'criar' => true, 'editar' => true, 'excluir' => true, 'gerenciar_itens' => true],
        'notificacoes'=> ['ver' => true, 'marcar_lida' => true],
    ],

    // ─────────────────────────────────────────
    // GESTOR — aprovador + visão gerencial
    // ─────────────────────────────────────────
    'gestor' => [
        'dashboard'   => ['ver_visao_geral' => true, 'ver_meus_processos' => true, 'ver_processos' => true, 'ver_aprovacoes' => true, 'ver_financeiro' => true, 'ver_desempenho' => true],
        'capturas'    => ['ver' => true, 'vincular' => true],
        'prefluxo'    => ['ver' => false, 'editar' => false],
        'processos'   => ['ver' => true, 'ver_todos' => false, 'criar' => true, 'editar' => true, 'aprovar' => true, 'devolver' => true, 'cancelar' => true, 'cancelar_abertura' => true, 'alterar_fluxo' => false, 'editar_dados' => false, 'assumir' => false, 'solicitar_cancelamento' => false, 'gerenciar_anexos' => false, 'descartar_teste' => false, 'ver_teste' => false],
        'tarefas'     => ['ver' => true, 'aprovar' => true, 'devolver' => true, 'reatribuir' => true, 'reatribuir_admin' => false],
        'financeiro'  => ['ver' => false, 'agendar' => false, 'registrar_pagamento' => false, 'cancelar' => false, 'exportar' => false],
        'fiscal'      => ['ver' => false, 'conferir' => false, 'atualizar' => false],
        'pagamentos'  => ['ver' => false, 'registrar_pagamento' => false, 'exportar' => false],
        'faturamento' => ['ver' => false],
        'templates'   => ['ver' => false, 'criar' => false, 'editar' => false, 'publicar' => false, 'arquivar' => false, 'testar' => false],
        'grupos'      => ['ver' => false, 'criar' => false, 'editar' => false, 'excluir' => false, 'gerenciar_membros' => false],
        'tabelas'     => ['ver' => false, 'criar' => false, 'editar' => false, 'excluir' => false, 'gerenciar_itens' => false],
        'relatorios'  => ReportAccess::todasLiberadas(),
        'arquivos'    => ['download' => true],
        'notificacoes'=> ['ver' => true, 'marcar_lida' => true],
    ],

    // ─────────────────────────────────────────
    // DIRETOR — mesmo que gestor (nível acima, mesmas permissões)
    // ─────────────────────────────────────────
    'diretor' => [
        'dashboard'   => ['ver_visao_geral' => true, 'ver_meus_processos' => true, 'ver_processos' => true, 'ver_aprovacoes' => true, 'ver_financeiro' => true, 'ver_desempenho' => true],
        'processos'   => ['ver' => true, 'ver_todos' => false, 'criar' => true, 'editar' => true, 'aprovar' => true, 'devolver' => true, 'cancelar' => true, 'cancelar_abertura' => true, 'alterar_fluxo' => false, 'editar_dados' => false, 'assumir' => false, 'solicitar_cancelamento' => false, 'gerenciar_anexos' => false, 'descartar_teste' => false, 'ver_teste' => false],
        'tarefas'     => ['ver' => true, 'aprovar' => true, 'devolver' => true, 'reatribuir' => true, 'reatribuir_admin' => false],
        'financeiro'  => ['ver' => false, 'agendar' => false, 'registrar_pagamento' => false, 'cancelar' => false, 'exportar' => false],
        'fiscal'      => ['ver' => false, 'conferir' => false, 'atualizar' => false],
        'pagamentos'  => ['ver' => false, 'registrar_pagamento' => false, 'exportar' => false],
        'faturamento' => ['ver' => false],
        'templates'   => ['ver' => false, 'criar' => false, 'editar' => false, 'publicar' => false, 'arquivar' => false, 'testar' => false],
        'grupos'      => ['ver' => false, 'criar' => false, 'editar' => false, 'excluir' => false, 'gerenciar_membros' => false],
        'tabelas'     => ['ver' => false, 'criar' => false, 'editar' => false, 'excluir' => false, 'gerenciar_itens' => false],
        'relatorios'  => ReportAccess::todasLiberadas(),
        'arquivos'    => ['download' => true],
        'notificacoes'=> ['ver' => true, 'marcar_lida' => true],
    ],

    // ─────────────────────────────────────────
    // COMPRADOR — cria e acompanha processos
    // ─────────────────────────────────────────
    'comprador' => [
        'dashboard'   => ['ver_visao_geral' => false, 'ver_meus_processos' => true, 'ver_processos' => false, 'ver_aprovacoes' => false, 'ver_financeiro' => false, 'ver_desempenho' => false],
        'processos'   => ['ver' => true, 'ver_todos' => false, 'criar' => true, 'editar' => true, 'aprovar' => false, 'devolver' => false, 'cancelar' => false, 'cancelar_abertura' => false, 'editar_dados' => false, 'assumir' => false, 'solicitar_cancelamento' => false, 'gerenciar_anexos' => false, 'descartar_teste' => false, 'ver_teste' => false],
        'tarefas'     => ['ver' => true, 'aprovar' => true, 'devolver' => true, 'reatribuir' => true, 'reatribuir_admin' => false],
        'financeiro'  => ['ver' => false, 'agendar' => false, 'registrar_pagamento' => false, 'cancelar' => false, 'exportar' => false],
        'fiscal'      => ['ver' => false, 'conferir' => false, 'atualizar' => false],
        'pagamentos'  => ['ver' => false, 'registrar_pagamento' => false, 'exportar' => false],
        'faturamento' => ['ver' => false],
        'templates'   => ['ver' => false, 'criar' => false, 'editar' => false, 'publicar' => false, 'arquivar' => false, 'testar' => false],
        'grupos'      => ['ver' => false, 'criar' => false, 'editar' => false, 'excluir' => false, 'gerenciar_membros' => false],
        'tabelas'     => ['ver' => false, 'criar' => false, 'editar' => false, 'excluir' => false, 'gerenciar_itens' => false],
        'relatorios'  => ['ver' => false],
        'arquivos'    => ['download' => true],
        'notificacoes'=> ['ver' => true, 'marcar_lida' => true],
    ],

    // ─────────────────────────────────────────
    // ALMOXARIFE — visualização de tarefas e processos
    // ─────────────────────────────────────────
    'almoxarife' => [
        'dashboard'   => ['ver_visao_geral' => false, 'ver_meus_processos' => true, 'ver_processos' => false, 'ver_aprovacoes' => false, 'ver_financeiro' => false, 'ver_desempenho' => false],
        'processos'   => ['ver' => true, 'ver_todos' => false, 'criar' => false, 'editar' => false, 'aprovar' => false, 'devolver' => false, 'cancelar' => false, 'cancelar_abertura' => false, 'editar_dados' => false, 'assumir' => false, 'solicitar_cancelamento' => false, 'gerenciar_anexos' => false, 'descartar_teste' => false, 'ver_teste' => false],
        'tarefas'     => ['ver' => true, 'aprovar' => false, 'devolver' => false, 'reatribuir' => false, 'reatribuir_admin' => false],
        'financeiro'  => ['ver' => false, 'agendar' => false, 'registrar_pagamento' => false, 'cancelar' => false, 'exportar' => false],
        'fiscal'      => ['ver' => false, 'conferir' => false, 'atualizar' => false],
        'pagamentos'  => ['ver' => false, 'registrar_pagamento' => false, 'exportar' => false],
        'faturamento' => ['ver' => false],
        'templates'   => ['ver' => false, 'criar' => false, 'editar' => false, 'publicar' => false, 'arquivar' => false, 'testar' => false],
        'grupos'      => ['ver' => false, 'criar' => false, 'editar' => false, 'excluir' => false, 'gerenciar_membros' => false],
        'tabelas'     => ['ver' => false, 'criar' => false, 'editar' => false, 'excluir' => false, 'gerenciar_itens' => false],
        'relatorios'  => ['ver' => false],
        'arquivos'    => ['download' => true],
        'notificacoes'=> ['ver' => true, 'marcar_lida' => true],
    ],

    // ─────────────────────────────────────────
    // SOLICITANTE — abre processos básicos
    // ─────────────────────────────────────────
    'solicitante' => [
        'dashboard'   => ['ver_visao_geral' => false, 'ver_meus_processos' => true, 'ver_processos' => false, 'ver_aprovacoes' => false, 'ver_financeiro' => false, 'ver_desempenho' => false],
        'processos'   => ['ver' => true, 'ver_todos' => false, 'criar' => true, 'editar' => false, 'aprovar' => false, 'devolver' => false, 'cancelar' => false, 'cancelar_abertura' => false, 'editar_dados' => false, 'assumir' => false, 'solicitar_cancelamento' => false, 'gerenciar_anexos' => false, 'descartar_teste' => false, 'ver_teste' => false],
        'tarefas'     => ['ver' => true, 'aprovar' => false, 'devolver' => false, 'reatribuir' => false, 'reatribuir_admin' => false],
        'financeiro'  => ['ver' => false, 'agendar' => false, 'registrar_pagamento' => false, 'cancelar' => false, 'exportar' => false],
        'fiscal'      => ['ver' => false, 'conferir' => false, 'atualizar' => false],
        'pagamentos'  => ['ver' => false, 'registrar_pagamento' => false, 'exportar' => false],
        'faturamento' => ['ver' => false],
        'templates'   => ['ver' => false, 'criar' => false, 'editar' => false, 'publicar' => false, 'arquivar' => false, 'testar' => false],
        'grupos'      => ['ver' => false, 'criar' => false, 'editar' => false, 'excluir' => false, 'gerenciar_membros' => false],
        'tabelas'     => ['ver' => false, 'criar' => false, 'editar' => false, 'excluir' => false, 'gerenciar_itens' => false],
        'relatorios'  => ['ver' => false],
        'arquivos'    => ['download' => true],
        'notificacoes'=> ['ver' => true, 'marcar_lida' => true],
    ],
];
