<?php
declare(strict_types=1);

/**
 * Configuração de e-mail do módulo Workflow NF.
 *
 * modo:
 *   'log'  — NÃO envia; grava o e-mail em storage/logs/emails.log (desenvolvimento)
 *   'smtp' — envia via SMTP autenticado (preencher credenciais abaixo)
 *
 * Para ativar em produção: modo => 'smtp' + dados do servidor de e-mail do grupo.
 */

return [
    'modo' => 'log',

    'smtp' => [
        'host'       => 'exemplo.com',   // ajustar
        'porta'      => 587,
        'seguranca'  => 'tls',                    // 'tls' | 'ssl' | ''
        'usuario'    => 'workflow@exemplo.com',
        'senha'      => 'TROQUE_NO_SERVIDOR',
        'timeout'    => 15,
    ],

    'remetente' => [
        'email' => 'workflow@exemplo.com',
        'nome'  => 'Exemplo financeiro — Workflow NF',
    ],

    /** URL base usada nos links dos e-mails */
    'base_url' => 'http://localhost:8090',
];
