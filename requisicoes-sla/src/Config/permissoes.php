<?php
// @versao Ver.001.211026
/**
 * Catálogo de permissões — fonte única para as telas de Usuário e de Cargo,
 * para os padrões e para a validação do que é salvo.
 *
 * Cada grupo vira um cartão na tela; cada item é uma chave sim/não gravada em
 * permissoes_perfil (padrão do cargo) e permissoes_usuario (exceção da pessoa).
 *
 *   label  texto na tela
 *   sub    true = ação dentro do módulo, desenhada com "→"
 *   ativa  true = a chave já é verificada no código. false = "em breve": pode
 *          ser marcada, mas o módulo ainda decide pelo cargo. Cada etapa de
 *          conversão vira as chaves do seu módulo para true.
 *
 * Os nomes das chaves que já existiam (módulos, requisicoes.<tipo>,
 * relatorios.<relatório>) foram mantidos para não perder o que está gravado.
 */
return [
    'requisicoes' => [
        'label' => 'Requisições',
        'icon'  => 'bi-file-earmark-text',
        'itens' => [
            'requisicoes'               => ['label' => 'Requisições (ver)', 'ativa' => true],
            'requisicoes.compra'        => ['label' => 'Criar: Compra', 'sub' => true, 'ativa' => true],
            'requisicoes.interna'       => ['label' => 'Criar: Requisição Interna', 'sub' => true, 'ativa' => true],
            'requisicoes.transferencia' => ['label' => 'Criar: Solicitação de Transferência', 'sub' => true, 'ativa' => true],
            'requisicoes.ordem_compra'  => ['label' => 'Criar: Ordem de Compra', 'sub' => true, 'ativa' => true],
            'requisicoes.editar'        => ['label' => 'Editar', 'sub' => true, 'ativa' => false],
            'requisicoes.cancelar'      => ['label' => 'Cancelar', 'sub' => true, 'ativa' => false],
            'requisicoes.excluir'       => ['label' => 'Excluir', 'sub' => true, 'ativa' => false],
            'requisicoes.imprimir'      => ['label' => 'Imprimir', 'sub' => true, 'ativa' => false],
            'requisicoes.todas_filiais' => ['label' => 'Ver todas as filiais', 'sub' => true, 'ativa' => false],
        ],
    ],
    'aprovacoes' => [
        'label' => 'Aprovações',
        'icon'  => 'bi-check2-square',
        'itens' => [
            'aprovacoes'               => ['label' => 'Aprovações (ver)', 'ativa' => true],
            'aprovacoes.aprovar'       => ['label' => 'Aprovar', 'sub' => true, 'ativa' => false],
            'aprovacoes.rejeitar'      => ['label' => 'Rejeitar', 'sub' => true, 'ativa' => false],
            'aprovacoes.todas_filiais' => ['label' => 'Ver todas as filiais', 'sub' => true, 'ativa' => false],
        ],
    ],
    'cotacoes' => [
        'label' => 'Cotações e Ordens de Compra',
        'icon'  => 'bi-cart3',
        'itens' => [
            'cotacoes'                 => ['label' => 'Cotações (ver)', 'ativa' => true],
            'cotacoes.criar'           => ['label' => 'Criar cotação', 'sub' => true, 'ativa' => false],
            'cotacoes.editar'          => ['label' => 'Editar / refazer', 'sub' => true, 'ativa' => false],
            'cotacoes.cancelar'        => ['label' => 'Cancelar', 'sub' => true, 'ativa' => false],
            'cotacoes.resposta_manual' => ['label' => 'Lançar resposta manual', 'sub' => true, 'ativa' => false],
            'cotacoes.comparativo'     => ['label' => 'Comparativo', 'sub' => true, 'ativa' => false],
            'cotacoes.gerar_oc'        => ['label' => 'Gerar ordem de compra', 'sub' => true, 'ativa' => false],
            'cotacoes.todas_filiais'   => ['label' => 'Ver todas as filiais', 'sub' => true, 'ativa' => false],
            'ordens_compra'            => ['label' => 'Ordens de compra (ver)', 'ativa' => false],
            'ordens_compra.pdf'        => ['label' => 'PDF da ordem de compra', 'sub' => true, 'ativa' => false],
        ],
    ],
    'fornecedores' => [
        'label' => 'Fornecedores',
        'icon'  => 'bi-truck',
        'itens' => [
            'fornecedores'            => ['label' => 'Fornecedores (ver lista e detalhes)', 'ativa' => true],
            'fornecedores.cadastrar'  => ['label' => 'Cadastrar', 'sub' => true, 'ativa' => false],
            'fornecedores.editar'     => ['label' => 'Editar', 'sub' => true, 'ativa' => false],
            'fornecedores.ativar'     => ['label' => 'Ativar / inativar', 'sub' => true, 'ativa' => false],
            'fornecedores.avaliar'    => ['label' => 'Avaliar', 'sub' => true, 'ativa' => false],
            'fornecedores.documentos' => ['label' => 'Documentos e anexos', 'sub' => true, 'ativa' => false],
            'fornecedores.creditos'   => ['label' => 'Créditos', 'sub' => true, 'ativa' => false],
        ],
    ],
    'contratos' => [
        'label' => 'Contratos',
        'icon'  => 'bi-file-earmark-medical',
        'itens' => [
            'contratos'            => ['label' => 'Contratos (ver)', 'ativa' => true],
            'contratos.cadastrar'  => ['label' => 'Cadastrar', 'sub' => true, 'ativa' => false],
            'contratos.editar'     => ['label' => 'Editar', 'sub' => true, 'ativa' => false],
            'contratos.documentos' => ['label' => 'Documentos e anexos', 'sub' => true, 'ativa' => false],
        ],
    ],
    'fiscal' => [
        'label' => 'Fiscal',
        'icon'  => 'bi-receipt',
        'itens' => [
            'fiscal'               => ['label' => 'Fiscal (ver)', 'ativa' => true],
            'fiscal.enviar'        => ['label' => 'Enviar XML', 'sub' => true, 'ativa' => false],
            'fiscal.conferir'      => ['label' => 'Conferir', 'sub' => true, 'ativa' => false],
            'fiscal.aprovar'       => ['label' => 'Aprovar / rejeitar', 'sub' => true, 'ativa' => false],
            'fiscal.comparativo'   => ['label' => 'Comparativo com estoque', 'sub' => true, 'ativa' => false],
            'fiscal.todas_filiais' => ['label' => 'Ver todas as filiais', 'sub' => true, 'ativa' => false],
        ],
    ],
    'itens' => [
        'label' => 'Itens',
        'icon'  => 'bi-box-seam',
        'itens' => [
            'itens'             => ['label' => 'Itens (ver)', 'ativa' => true],
            'itens.cadastrar'   => ['label' => 'Cadastrar', 'sub' => true, 'ativa' => false],
            'itens.editar'      => ['label' => 'Editar', 'sub' => true, 'ativa' => false],
            'itens.ativar'      => ['label' => 'Ativar / inativar', 'sub' => true, 'ativa' => false],
            'itens.excluir'     => ['label' => 'Excluir', 'sub' => true, 'ativa' => false],
            'itens.relatorio'   => ['label' => 'Relatório do item', 'sub' => true, 'ativa' => false],
            'itens.comparativo' => ['label' => 'Comparativo de itens', 'sub' => true, 'ativa' => false],
        ],
    ],
    'estoque' => [
        'label' => 'Estoque',
        'icon'  => 'bi-boxes',
        'itens' => [
            'estoque'               => ['label' => 'Estoque (ver)', 'ativa' => true],
            'estoque.entrada'       => ['label' => 'Entrada', 'sub' => true, 'ativa' => false],
            'estoque.saida'         => ['label' => 'Saída (baixa)', 'sub' => true, 'ativa' => false],
            'estoque.ajuste'        => ['label' => 'Ajuste de saldo', 'sub' => true, 'ativa' => false],
            'estoque.transferencia' => ['label' => 'Transferência entre filiais', 'sub' => true, 'ativa' => false],
            'estoque.minimo'        => ['label' => 'Definir estoque mínimo', 'sub' => true, 'ativa' => false],
            'estoque.historico'     => ['label' => 'Histórico de movimentação', 'sub' => true, 'ativa' => false],
            'estoque.qrcode'        => ['label' => 'QR Code', 'sub' => true, 'ativa' => false],
            'estoque.todas_filiais' => ['label' => 'Ver todas as filiais', 'sub' => true, 'ativa' => false],
        ],
    ],
    'auditoria_estoque' => [
        'label' => 'Auditoria de Estoque',
        'icon'  => 'bi-clipboard2-check',
        'itens' => [
            'auditoria_estoque'          => ['label' => 'Auditoria (ver)', 'ativa' => true],
            'auditoria_estoque.criar'    => ['label' => 'Criar auditoria', 'sub' => true, 'ativa' => false],
            'auditoria_estoque.conferir' => ['label' => 'Conferir contagem', 'sub' => true, 'ativa' => false],
            'auditoria_estoque.aplicar'  => ['label' => 'Aplicar ajustes no estoque', 'sub' => true, 'ativa' => false],
            'auditoria_estoque.excluir'  => ['label' => 'Excluir auditoria', 'sub' => true, 'ativa' => false],
            'auditoria_estoque.fotos'    => ['label' => 'Fotos', 'sub' => true, 'ativa' => false],
        ],
    ],
    'recebimento' => [
        'label' => 'Recebimento',
        'icon'  => 'bi-box-arrow-in-down',
        'itens' => [
            'confirmacao_recebimento' => ['label' => 'Confirmar transferências recebidas', 'ativa' => true],
        ],
    ],
    'brindes' => [
        'label' => 'Brindes',
        'icon'  => 'bi-gift',
        'itens' => [
            'brindes'               => ['label' => 'Brindes (ver)', 'ativa' => true],
            'brindes.cadastrar'     => ['label' => 'Cadastrar / editar', 'sub' => true, 'ativa' => false],
            'brindes.excluir'       => ['label' => 'Excluir', 'sub' => true, 'ativa' => false],
            'brindes.entrada'       => ['label' => 'Entrada', 'sub' => true, 'ativa' => false],
            'brindes.fotos'         => ['label' => 'Fotos', 'sub' => true, 'ativa' => false],
            'brindes.requisitar'    => ['label' => 'Requisitar brinde', 'sub' => true, 'ativa' => false],
            'brindes.requisicoes'   => ['label' => 'Ver requisições de brindes', 'sub' => true, 'ativa' => false],
            'brindes.todas_filiais' => ['label' => 'Ver todas as filiais', 'sub' => true, 'ativa' => false],
        ],
    ],
    'orcamentos' => [
        'label' => 'Orçamentos',
        'icon'  => 'bi-piggy-bank',
        'itens' => [
            'orcamentos'           => ['label' => 'Orçamentos (ver)', 'ativa' => false],
            'orcamentos.cadastrar' => ['label' => 'Cadastrar / editar', 'sub' => true, 'ativa' => false],
            'orcamentos.solicitar' => ['label' => 'Solicitar orçamento extra', 'sub' => true, 'ativa' => false],
            'orcamentos.aprovar'   => ['label' => 'Aprovar / rejeitar solicitações', 'sub' => true, 'ativa' => false],
        ],
    ],
    'relatorios' => [
        'label' => 'Relatórios',
        'icon'  => 'bi-bar-chart-line',
        'itens' => [
            'relatorios'                      => ['label' => 'Relatórios (ver)', 'ativa' => true],
            'relatorios.compras'              => ['label' => 'Relatório de Compras', 'sub' => true, 'ativa' => true],
            'relatorios.filiais'              => ['label' => 'Consumo por Filial', 'sub' => true, 'ativa' => true],
            'relatorios.consumo'              => ['label' => 'Consumo por Período', 'sub' => true, 'ativa' => true],
            'relatorios.movimentacao_estoque' => ['label' => 'Movimentação de Estoque', 'sub' => true, 'ativa' => true],
            'relatorios.saude_estoque'        => ['label' => 'Saúde do Estoque', 'sub' => true, 'ativa' => true],
            'relatorios.saving'               => ['label' => 'Saving', 'sub' => true, 'ativa' => true],
            'relatorios.anomalias'            => ['label' => 'Anomalias', 'sub' => true, 'ativa' => true],
            'relatorios.brindes'              => ['label' => 'Brindes', 'sub' => true, 'ativa' => true],
            'relatorios.auditoria'            => ['label' => 'Log de Auditoria', 'sub' => true, 'ativa' => true],
            'relatorios.todas_filiais'        => ['label' => 'Ver todas as filiais', 'sub' => true, 'ativa' => false],
        ],
    ],
    'dashboard' => [
        'label' => 'Dashboard',
        'icon'  => 'bi-speedometer2',
        'itens' => [
            'dashboard' => ['label' => 'Dashboard executivo', 'ativa' => false],
        ],
    ],
    'geral' => [
        'label' => 'Geral',
        'icon'  => 'bi-house',
        'itens' => [
            'ramais'      => ['label' => 'Ramais', 'ativa' => true],
            'links_uteis' => ['label' => 'Links úteis', 'ativa' => true],
        ],
    ],
    'administracao' => [
        'label' => 'Administração',
        'icon'  => 'bi-gear',
        'itens' => [
            'usuarios'                  => ['label' => 'Usuários (ver)', 'ativa' => true],
            'usuarios.criar'            => ['label' => 'Criar usuário', 'sub' => true, 'ativa' => false],
            'usuarios.editar'           => ['label' => 'Editar usuário e permissões', 'sub' => true, 'ativa' => false],
            'usuarios.excluir'          => ['label' => 'Excluir usuário', 'sub' => true, 'ativa' => false],
            'configuracoes'             => ['label' => 'Configurações (ver)', 'ativa' => true],
            'configuracoes.alcadas'     => ['label' => 'Alçadas', 'sub' => true, 'ativa' => false],
            'configuracoes.setores'     => ['label' => 'Setores', 'sub' => true, 'ativa' => false],
            'configuracoes.grupos'      => ['label' => 'Grupos de produto', 'sub' => true, 'ativa' => false],
            'configuracoes.parametros'  => ['label' => 'Parâmetros', 'sub' => true, 'ativa' => false],
            'configuracoes.integracoes' => ['label' => 'Integrações', 'sub' => true, 'ativa' => false],
            'configuracoes.filiais'     => ['label' => 'Unidades', 'sub' => true, 'ativa' => false],
            'configuracoes.gestores'    => ['label' => 'Gestores', 'sub' => true, 'ativa' => false],
            'configuracoes.perfis'      => ['label' => 'Perfis e permissões dos cargos', 'sub' => true, 'ativa' => true],
        ],
    ],
];
