<?php
// @versao Rel_07 V_02_07/08/2026
declare(strict_types=1);

/**
 * Catálogo e gate de acesso dos RELATÓRIOS, um a um.
 *
 * Cada relatório é uma ação do módulo `relatorios` no RBAC existente
 * (config/permissions.php → nf_perfil_permissoes → nf_usuario_permissoes),
 * então o Controle de Acesso já sabe editar isso sem tabela nova.
 *
 * Acesso é tudo ou nada por relatório: quem enxerga o relatório também
 * exporta (XLSX, CSV e PDF). Não existe permissão separada de exportação —
 * o dado que sai no arquivo é o mesmo que já está na tela.
 *
 * `relatorios.ver` continua sendo o gate do MÓDULO (link na sidebar e a
 * própria tela); as ações `rel_*` decidem quais relatórios aparecem dentro.
 */
class ReportAccess
{
    /**
     * chave do relatório => [label, aba, acao RBAC]
     *
     * A chave é a mesma usada em ?relatorio=... e nas exportações.
     */
    public const RELATORIOS = [
        'fluxo_caixa' => [
            'label' => 'Fluxo de caixa previsto',
            'aba'   => 'financeiro',
            'acao'  => 'rel_fluxo_caixa',
        ],
        'pagamentos' => [
            'label' => 'Pagamentos realizados',
            'aba'   => 'financeiro',
            'acao'  => 'rel_pagamentos',
        ],
        'contas_pagar' => [
            'label' => 'Contas a pagar',
            'aba'   => 'financeiro',
            'acao'  => 'rel_contas_pagar',
        ],
        'gastos_grupo' => [
            'label' => 'Gastos por grupo',
            'aba'   => 'financeiro',
            'acao'  => 'rel_gastos_grupo',
        ],
        'volume_processos' => [
            'label' => 'Volume de processos',
            'aba'   => 'operacional',
            'acao'  => 'rel_volume_processos',
        ],
        'sla' => [
            'label' => 'SLA e tempo de ciclo',
            'aba'   => 'operacional',
            'acao'  => 'rel_sla',
        ],
        'devolucoes' => [
            'label' => 'Devoluções por motivo',
            'aba'   => 'operacional',
            'acao'  => 'rel_devolucoes',
        ],
        'desempenho_usuarios' => [
            'label' => 'Desempenho por usuário',
            'aba'   => 'operacional',
            'acao'  => 'rel_desempenho_usuarios',
        ],
        // Os três abaixo listam processo a processo, em TODOS os status —
        // inclusive os que a tela de Processos esconde por padrão (finalizado
        // pago, cancelado e encerrado). São a única forma de ver o histórico
        // completo de um departamento, setor ou fluxo.
        'processos_departamento' => [
            'label' => 'Processos por departamento',
            'aba'   => 'operacional',
            'acao'  => 'rel_processos_departamento',
        ],
        'processos_setor' => [
            'label' => 'Processos por setor',
            'aba'   => 'operacional',
            'acao'  => 'rel_processos_setor',
        ],
        'processos_fluxo' => [
            'label' => 'Processos por fluxo',
            'aba'   => 'operacional',
            'acao'  => 'rel_processos_fluxo',
        ],
        'meus_processos' => [
            'label' => 'Meus processos',
            'aba'   => 'meus',
            'acao'  => 'rel_meus_processos',
        ],
    ];

    /** Abas da tela, na ordem em que aparecem. */
    public const ABAS = [
        'financeiro'  => 'Financeiro',
        'operacional' => 'Operacional',
        'meus'        => 'Meus processos',
    ];

    /** O usuário logado tem acesso a este relatório? */
    public static function pode(string $chave): bool
    {
        $acao = self::RELATORIOS[$chave]['acao'] ?? null;
        return $acao !== null && Permissions::can('relatorios', $acao);
    }

    /**
     * Exige acesso ao relatório — 403 e encerra, igual a Permissions::require().
     * Usado na exportação, que é uma URL direta e não passa pela tela.
     */
    public static function exigir(string $chave): void
    {
        if (!isset(self::RELATORIOS[$chave])) {
            http_response_code(404);
            include VIEWS . '/errors/404.php';
            exit;
        }
        Permissions::require('relatorios', self::RELATORIOS[$chave]['acao']);
    }

    /**
     * Chaves dos relatórios que o usuário logado acessa.
     *
     * @param string|null $aba filtra por aba quando informado
     * @return list<string>
     */
    public static function permitidos(?string $aba = null): array
    {
        $out = [];
        foreach (self::RELATORIOS as $chave => $meta) {
            if ($aba !== null && $meta['aba'] !== $aba) {
                continue;
            }
            if (self::pode($chave)) {
                $out[] = $chave;
            }
        }
        return $out;
    }

    /**
     * Abas que têm ao menos um relatório liberado para o usuário.
     *
     * @return array<string,string> chave => rótulo, na ordem de self::ABAS
     */
    public static function abasPermitidas(): array
    {
        $out = [];
        foreach (self::ABAS as $chave => $rotulo) {
            if (self::permitidos($chave) !== []) {
                $out[$chave] = $rotulo;
            }
        }
        return $out;
    }

    /**
     * Ações do módulo `relatorios` para o catálogo do Controle de Acesso.
     *
     * @return array<string,string> acao => descrição
     */
    public static function acoesCatalogo(): array
    {
        $acoes = ['ver' => 'Acessar o módulo de Relatórios (necessário para qualquer relatório abaixo)'];
        foreach (self::RELATORIOS as $meta) {
            $aba = self::ABAS[$meta['aba']] ?? $meta['aba'];
            $acoes[$meta['acao']] = $aba === $meta['label']
                ? $meta['label'] . ' — ver e exportar'
                : $meta['label'] . ' — ver e exportar (aba ' . $aba . ')';
        }
        return $acoes;
    }

    /** Mapa acao => true, para montar o perfil padrão em config/permissions.php. */
    public static function todasLiberadas(): array
    {
        $out = ['ver' => true];
        foreach (self::RELATORIOS as $meta) {
            $out[$meta['acao']] = true;
        }
        return $out;
    }
}
