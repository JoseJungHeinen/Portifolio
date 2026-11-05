<?php
// @versao Ace_03 V_16_01/10/2026
declare(strict_types=1);

/**
 * Model de Permissões Granulares por Usuário.
 *
 * Gerencia `nf_usuario_permissoes` (banco compras) — sobrepõe, quando presente,
 * o mapa estático por perfil em config/permissions.php. Usuário sem override
 * continua regido pelo perfil normalmente (ver Auth::can()).
 */
class UserPermission
{
    private static function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Catálogo de módulos/ações disponíveis para configuração granular.
     * Espelha exatamente o universo de config/permissions.php.
     */
    public static function catalogo(): array
    {
        // Itens do menu lateral — um por link. Marcar = o item aparece no menu.
        require_once __DIR__ . '/../Helpers/Permissions.php';
        return [
            'menu' => [
                'label' => 'Menu', 'icone' => 'menu',
                'acoes' => array_map(static fn(string $r): string => 'Mostrar "' . $r . '" no menu lateral', Permissions::MENU_ITENS),
            ],
            'dashboard' => [
                'label' => 'Dashboard', 'icone' => 'layout-dashboard',
                'acoes' => [
                    'ver_visao_geral'    => 'Ver aba Visão Geral (todos os processos em andamento)',
                    'ver_meus_processos' => 'Ver aba Meus Processos (só os processos do próprio usuário)',
                    'ver_processos'      => 'Ver aba Processos (atrasados, parados e todos — visão agregada)',
                    'ver_aprovacoes'     => 'Ver aba Aprovações (filas por aprovador e por grupo)',
                    'ver_financeiro'     => 'Ver aba Financeiro (parcelas e volumes por loja/setor)',
                    'ver_desempenho'     => 'Ver aba Desempenho (ranking de usuários)',
                ],
                // Abas que exibem valores CONSOLIDADOS (somas em R$ de vários
                // processos/usuários), não só contagens — sinalizadas na tela
                // de Controle de Acesso pra quem for liberar acesso enxergar
                // que está expondo montante agregado da empresa, não dado
                // individual. "Meus Processos" e "Processos" ficam de fora:
                // mostram valor por linha (processo a processo), sem somatório.
                'valores_agregados' => ['ver_visao_geral', 'ver_aprovacoes', 'ver_financeiro', 'ver_desempenho'],
            ],
            'capturas' => [
                'label' => 'Notas Capturadas', 'icone' => 'inbox',
                'acoes' => [
                    'ver'      => 'Ver a inbox de notas capturadas (NF-e da Brobot)',
                    'vincular' => 'Vincular nota a processo, ignorar nota, aplicar regras e sincronizar',
                ],
            ],
            'prefluxo' => [
                'label' => 'Pré-Fluxo', 'icone' => 'git-branch',
                'acoes' => [
                    'ver'    => 'Visualizar itens do pré-fluxo',
                    'editar' => 'Editar itens do pré-fluxo',
                ],
            ],
            'processos' => [
                'label' => 'Processos', 'icone' => 'file-text',
                'acoes' => [
                    'ver'       => 'Visualizar processos (só os que participa)',
                    'ver_todos' => 'Visualizar TODOS os processos, mesmo sem participar (acesso global de consulta)',
                    'criar'     => 'Criar novo processo',
                    'importar_pdfs' => 'Importar PDFs em lote (fatura e NFS-e) — libera o item do menu e a tela',
                    'editar'    => 'Completar/editar dados do processo na etapa de abertura (parcelas, tipo de despesa, centro de custo)',
                    'editar_dados' => 'Alterar dados do processo em QUALQUER etapa (nota, campos do fluxo e parcelas) — toda alteração fica no histórico',
                    'aprovar'   => 'Aprovar etapa',
                    'devolver'  => 'Devolver etapa',
                    'assumir'   => 'Assumir tarefa de etapa de grupo (tira o processo da fila do grupo)',
                    // Duas liberações de cancelamento, propositalmente separadas:
                    // quem abre o processo costuma precisar desfazer o próprio
                    // lançamento errado, mas não derrubar um processo que já
                    // passou por aprovações.
                    'cancelar_abertura' => 'Cancelar processo SÓ na abertura (etapa 1, antes de qualquer aprovação)',
                    'cancelar'  => 'Cancelar processo em QUALQUER etapa do fluxo',
                    'solicitar_cancelamento' => 'Solicitar cancelamento de processo que já avançou no fluxo',
                    'gerenciar_anexos' => 'Mexer em anexos FORA da sua etapa ou com o processo encerrado. Quem participa já anexa pela regra da etapa, e a obrigatoriedade de cada anexo continua vindo do template.',
                    'descartar_teste'  => 'Descartar processo de teste',
                    'alterar_fluxo' => 'Alterar o fluxo do processo (reencaminha para outro template, voltando ao início)',
                    'ver_teste' => 'Ver processos de teste',
                ],
            ],
            'tarefas' => [
                'label' => 'Minhas Tarefas', 'icone' => 'check-square',
                'acoes' => [
                    'ver'        => 'Ver fila de tarefas',
                    'aprovar'    => 'Aprovar tarefa',
                    'devolver'   => 'Devolver tarefa',
                    'reatribuir' => 'Reatribuir tarefa para outro membro do time',
                    'reatribuir_admin' => 'Reatribuir QUALQUER tarefa ativa, mesmo sem ser o responsável atual (uso administrativo — cobrir férias/afastamento)',
                ],
            ],
            'financeiro' => [
                'label' => 'Controle Financeiro', 'icone' => 'credit-card',
                'acoes' => [
                    'ver'                 => 'Visualizar parcelas',
                    'agendar'             => 'Agendar pagamento',
                    'registrar_pagamento' => 'Registrar pagamento',
                    'cancelar'            => 'Cancelar parcela',
                    'exportar'            => 'Exportar (Excel)',
                ],
            ],
            'fiscal' => [
                'label' => 'Fiscal (IBS/CBS)', 'icone' => 'landmark',
                'acoes' => [
                    'ver'       => 'Ver o painel de IBS/CBS por fornecedor (valores somados do periodo)',
                    'conferir'  => 'Marcar e desmarcar notas como conferidas',
                    'atualizar' => 'Reler os XML do storage, recarregar os totais e consultar CNPJ na Receita',
                ],
                // A tela soma valores de TODAS as notas do periodo, nota a nota
                // e por fornecedor — quem libera precisa enxergar que esta
                // expondo o montante comprado no periodo, nao so o imposto.
                'valores_agregados' => ['ver'],
            ],
            'pagamentos' => [
                'label' => 'Pagamentos', 'icone' => 'wallet',
                'acoes' => [
                    'ver'                 => 'Acessar a tela de Pagamentos',
                    'registrar_pagamento' => 'Registrar pagamento',
                    'exportar'            => 'Exportar (Excel)',
                ],
            ],
            'faturamento' => [
                'label' => 'Faturamento', 'icone' => 'car',
                'acoes' => [
                    'ver' => 'Acessar o módulo de Faturamento de veículos (menu e telas)',
                ],
            ],
            'templates' => [
                'label' => 'Templates de Fluxo', 'icone' => 'copy',
                'acoes' => [
                    'ver'      => 'Visualizar templates',
                    'criar'    => 'Criar template',
                    'editar'   => 'Editar template',
                    'publicar' => 'Publicar versão',
                    'arquivar' => 'Arquivar template',
                    'testar'   => 'Enviar para teste (UAT)',
                ],
            ],
            'grupos' => [
                'label' => 'Grupos de Aprovação', 'icone' => 'users',
                'acoes' => [
                    'ver'               => 'Visualizar grupos',
                    'criar'             => 'Criar grupo',
                    'editar'            => 'Editar grupo',
                    'excluir'           => 'Excluir grupo',
                    'gerenciar_membros' => 'Gerenciar membros',
                ],
            ],
            'tabelas' => [
                'label' => 'Listas', 'icone' => 'list',
                'acoes' => [
                    'ver'             => 'Visualizar listas',
                    'criar'           => 'Criar lista',
                    'editar'          => 'Editar lista',
                    'excluir'         => 'Excluir lista',
                    'gerenciar_itens' => 'Gerenciar itens',
                ],
            ],
            'relatorios' => [
                'label' => 'Relatórios', 'icone' => 'bar-chart-2',
                // Um relatório por ação — quem enxerga o relatório também
                // exporta (XLSX, CSV, PDF). Ver ReportAccess.
                'acoes' => ReportAccess::acoesCatalogo(),
            ],
            'arquivos' => [
                'label' => 'Arquivos', 'icone' => 'paperclip',
                'acoes' => ['download' => 'Baixar anexos'],
            ],
            'notificacoes' => [
                'label' => 'Notificações', 'icone' => 'bell',
                'acoes' => [
                    'ver'         => 'Visualizar notificações',
                    'marcar_lida' => 'Marcar como lida',
                ],
            ],
        ];
    }

    /**
     * Lista usuários ativos do sistema maior, com indicação de override.
     */
    /**
     * Perfil como ele deve APARECER para quem está olhando.
     *
     * `super_admin` é uma marca reservada: só outro super admin enxerga. Para
     * qualquer outro observador (inclusive o admin de cadastros) o super admin
     * se apresenta como administrador comum — é o que mantém a marca invisível
     * sem precisar esconder o próprio usuário da lista.
     */
    public static function perfilVisivel(?string $perfil): string
    {
        $perfil = (string) $perfil;
        if ($perfil === 'super_admin' && !Permissions::isSuperAdmin()) {
            return 'admin';
        }
        return $perfil;
    }

    /** Aplica perfilVisivel() a uma lista de usuários. */
    public static function mascararPerfis(array $usuarios): array
    {
        foreach ($usuarios as &$u) {
            if (isset($u['perfil'])) {
                $u['perfil'] = self::perfilVisivel($u['perfil']);
            }
        }
        return $usuarios;
    }

    public static function listarUsuarios(string $busca = ''): array
    {
        $pdo = Database::getInstance();

        // Inclui inativos (senão seria impossível reativar um usuário pela tela)
        $sql = "SELECT u.id, u.nome, u.email, u.perfil, u.ativo,
                       (p.id IS NOT NULL) AS tem_override
                FROM usuarios u
                LEFT JOIN nf_usuario_permissoes p ON p.usuario_id = u.id
                WHERE 1=1";
        $params = [];

        if ($busca !== '') {
            $sql   .= " AND (u.nome LIKE ? OR u.email LIKE ?)";
            $like   = '%' . $busca . '%';
            $params = [$like, $like];
        }

        $sql .= " ORDER BY u.ativo DESC, u.nome ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Marca/desmarca "Vendedor Veículos" (opção de Vendedor no pedido de Faturamento). */
    public static function definirVendedorVeiculos(string $usuarioId, bool $valor): void
    {
        Database::getInstance()
            ->prepare('UPDATE usuarios SET vendedor_veiculos = ? WHERE id = ?')
            ->execute([(int) $valor, $usuarioId]);
    }

    public static function buscarUsuario(string $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT id, nome, email, perfil, filial_id, setor_id, ativo, force_change_senha, vendedor_veiculos
             FROM usuarios WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        return $usuario ?: null;
    }

    /**
     * Atualiza o cadastro completo do usuário (correção de erros).
     * Restrito a administradores — gate no AccessController.
     */
    public static function atualizarUsuario(string $id, array $dados): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "UPDATE usuarios
             SET nome = ?, email = ?, perfil = ?, filial_id = ?, setor_id = ?, ativo = ?
             WHERE id = ?"
        );
        return $stmt->execute([
            $dados['nome'],
            $dados['email'],
            $dados['perfil'],
            $dados['filial_id'],
            $dados['setor_id'],
            $dados['ativo'] ? 1 : 0,
            $id,
        ]) !== false;
    }

    /**
     * Override granular do usuário (decodificado), ou null se ele ainda
     * não tem nenhuma linha — nesse caso é regido pelo perfil.
     */
    public static function buscarOverride(string $usuarioId): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT permissoes FROM nf_usuario_permissoes WHERE usuario_id = ? LIMIT 1");
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $decoded = json_decode($row['permissoes'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Permissões a exibir no formulário: override do usuário se existir,
     * senão o padrão do perfil (config/permissions.php) — pré-preenche o
     * grid em vez de começar tudo desmarcado.
     */
    public static function permissoesParaExibicao(string $perfil, ?array $override): array
    {
        require_once __DIR__ . '/../Helpers/Permissions.php';
        if ($override !== null) {
            return Permissions::completarPadroes($override);
        }
        return Permissions::completarPadroes(self::permissoesDoPerfil($perfil));
    }

    // ─────────────────────────────────────────
    // Permissões padrão POR PERFIL (administráveis)
    // ─────────────────────────────────────────

    /**
     * Permissões efetivas de um perfil: customização do banco
     * (nf_perfil_permissoes) se existir, senão o padrão do arquivo.
     */
    public static function permissoesDoPerfil(string $perfil): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT permissoes FROM nf_perfil_permissoes WHERE perfil = ? LIMIT 1");
        $stmt->execute([$perfil]);
        $row = $stmt->fetchColumn();
        if ($row) {
            $decoded = json_decode($row, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $mapa = require CONFIG . '/permissions.php';
        return $mapa[$perfil] ?? [];
    }

    /** O perfil tem customização gravada no banco (difere do padrão do arquivo)? */
    public static function perfilCustomizado(string $perfil): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT 1 FROM nf_perfil_permissoes WHERE perfil = ? LIMIT 1");
        $stmt->execute([$perfil]);
        return (bool) $stmt->fetchColumn();
    }

    /** Grava a customização do perfil (admin nunca é customizável). */
    public static function salvarPerfil(string $perfil, array $permissoes, string $atualizadoPor): void
    {
        if ($perfil === 'super_admin') {
            throw new InvalidArgumentException('O perfil admin sempre tem acesso total.');
        }
        $pdo = Database::getInstance();
        $pdo->prepare(
            "INSERT INTO nf_perfil_permissoes (perfil, permissoes, atualizado_por)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE permissoes = VALUES(permissoes), atualizado_por = VALUES(atualizado_por)"
        )->execute([$perfil, json_encode($permissoes, JSON_UNESCAPED_UNICODE), $atualizadoPor]);
    }

    /** Remove a customização — perfil volta ao padrão do arquivo. */
    public static function reverterPerfil(string $perfil): void
    {
        $pdo = Database::getInstance();
        $pdo->prepare("DELETE FROM nf_perfil_permissoes WHERE perfil = ?")->execute([$perfil]);
    }

    /**
     * Cria ou atualiza o override granular do usuário.
     */
    public static function salvar(string $usuarioId, array $permissoes, string $atualizadoPor): void
    {
        $pdo  = Database::getInstance();
        $json = json_encode($permissoes, JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare("SELECT id FROM nf_usuario_permissoes WHERE usuario_id = ? LIMIT 1");
        $stmt->execute([$usuarioId]);
        $existente = $stmt->fetchColumn();

        if ($existente) {
            $upd = $pdo->prepare(
                "UPDATE nf_usuario_permissoes SET permissoes = ?, atualizado_por = ? WHERE usuario_id = ?"
            );
            $upd->execute([$json, $atualizadoPor, $usuarioId]);
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO nf_usuario_permissoes (id, usuario_id, permissoes, atualizado_por) VALUES (?, ?, ?, ?)"
            );
            $ins->execute([self::uuid(), $usuarioId, $json, $atualizadoPor]);
        }
    }

    /**
     * Remove o override — usuário volta a ser regido só pelo perfil.
     */
    public static function removerOverride(string $usuarioId): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("DELETE FROM nf_usuario_permissoes WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
    }

    // ─────────────────────────────────────────
    // Cadastro e senha (tabela usuarios — sistema maior)
    // ─────────────────────────────────────────

    public static function emailJaExiste(string $email, string $excetoUsuarioId = ''): bool
    {
        $pdo = Database::getInstance();
        if ($excetoUsuarioId !== '') {
            $stmt = $pdo->prepare("SELECT 1 FROM usuarios WHERE email = ? AND id <> ? LIMIT 1");
            $stmt->execute([$email, $excetoUsuarioId]);
        } else {
            $stmt = $pdo->prepare("SELECT 1 FROM usuarios WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
        }
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Gera uma senha temporária legível (8 caracteres, sem 0/O/1/l para
     * evitar confusão na hora de digitar).
     */
    private static function gerarSenhaTemporaria(): string
    {
        $alfabeto = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $senha = '';
        for ($i = 0; $i < 8; $i++) {
            $senha .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        return $senha;
    }

    /**
     * Cria um novo usuário em `usuarios` com senha temporária (o usuário é
     * obrigado a trocá-la no primeiro acesso — force_change_senha = 1).
     *
     * @return array{id: string, senha_temporaria: string}
     */
    public static function criarUsuario(
        string $nome,
        string $email,
        string $perfil,
        string $filialId,
        string $setorId,
        string $criadoPor,
        bool $vendedorVeiculos = false
    ): array {
        $pdo             = Database::getInstance();
        $id              = self::uuid();
        $senhaTemporaria = self::gerarSenhaTemporaria();
        $hash            = password_hash($senhaTemporaria, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            "INSERT INTO usuarios
                (id, nome, email, senha_hash, perfil, filial_id, setor_id, ativo, force_change_senha, criado_por, vendedor_veiculos)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?)"
        );
        $stmt->execute([$id, $nome, $email, $hash, $perfil, $filialId, $setorId, $criadoPor, (int) $vendedorVeiculos]);

        return ['id' => $id, 'senha_temporaria' => $senhaTemporaria];
    }

    /**
     * Define uma nova senha para um usuário existente (reset feito pelo
     * admin) — força troca no próximo acesso e limpa o bloqueio por
     * tentativas, já que o admin está intervindo manualmente.
     */
    public static function trocarSenha(string $usuarioId, string $novaSenha): void
    {
        $pdo  = Database::getInstance();
        $hash = password_hash($novaSenha, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare(
            "UPDATE usuarios
                SET senha_hash = ?, force_change_senha = 1,
                    tentativas_login = 0, bloqueado_ate = NULL
              WHERE id = ?"
        );
        $stmt->execute([$hash, $usuarioId]);
    }

    /**
     * Troca a própria senha (self-service, tela "Meu Perfil") — exige a
     * senha atual correta antes de aplicar a nova. Diferente de
     * trocarSenha(), não força troca no próximo acesso: foi o próprio
     * usuário que decidiu trocar, conscientemente.
     *
     * @return bool true se a senha atual era válida e a troca foi aplicada
     */
    public static function trocarSenhaPropria(string $usuarioId, string $senhaAtual, string $novaSenha): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT senha_hash FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$usuarioId]);
        $hashAtual = $stmt->fetchColumn();

        if (!$hashAtual || !password_verify($senhaAtual, (string) $hashAtual)) {
            return false;
        }

        $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?");
        $upd->execute([$novoHash, $usuarioId]);

        return true;
    }
}
