<?php
// @versao Tab_05 V_11_30/09/2026
declare(strict_types=1);

/**
 * Model para listas de referência do módulo NF.
 * Estrutura simples: nf_listas (definição) + nf_lista_itens (valor texto).
 */
class TableList
{
    /**
     * Listas de sistema — apontam para tabelas reais do sistema maior
     * (filiais, setores) e aparecem no módulo Listas como listas gerenciáveis
     * (adicionar / editar / ativar / desativar itens). A lista em si não pode
     * ser renomeada, desativada nem excluída.
     *
     * IDs fixos em hex válido (Sanitize::uuid exige hex).
     */
    public const ID_FILIAIS = '00000000-0000-4000-8000-0000000000f1';
    public const ID_SETORES = '00000000-0000-4000-8000-0000000000e5';
    public const ID_ETAPAS  = '00000000-0000-4000-8000-0000000000e7';

    /** ID da lista de referência "Departamentos" (nf_listas) — alvo do vínculo setor→departamento */
    public const ID_LISTA_DEPARTAMENTOS = 'de9a47a0-0000-4000-8000-000000000001';

    /** ID da lista "Tipos de nota" — a que aceita marcação por departamento. */
    public const ID_LISTA_TIPOS_NOTA = 'a1b2c3d4-0001-4000-8000-000000000001';

    public const SYSTEM_LISTS = [
        // Filiais saiu de Listas em 16/09/2026 — gerenciada em Geral > Filiais,
        // com todos os campos (endereço, integrações, certificado). A constante
        // ID_FILIAIS continua existindo (branches de compat em TableController/
        // items.php), só não aparece mais nesta listagem.
        '00000000-0000-4000-8000-0000000000e5' => [
            'tabela'    => 'setores',
            'nome'      => 'Setores',
            'descricao' => 'Setores internos — usados em grupos de aprovação, templates e cadastro de usuários',
        ],
        '00000000-0000-4000-8000-0000000000e7' => [
            'tabela'    => 'nf_etapas',
            'nome'      => 'Etapas',
            'descricao' => 'Etapas padronizadas dos fluxos — crie aqui antes de usar nos templates; os atributos (arquivamento automático, SLA, tipo de aprovador, visualização restrita) valem para todos os fluxos que usam a etapa',
        ],
    ];

    public static function isSystem(string $id): bool
    {
        return isset(self::SYSTEM_LISTS[$id]);
    }

    /** Nome da tabela real de uma lista de sistema (whitelist — nunca interpolar input). */
    private static function systemTable(string $listaId): string
    {
        return self::SYSTEM_LISTS[$listaId]['tabela'];
    }

    public static function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // ─────────────────────────────────────────
    // LISTAS
    // ─────────────────────────────────────────

    public static function list(array $filters = []): array
    {
        $pdo    = Database::getInstance();
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = $filters['status'] === 'ativo' ? 'l.ativo = 1' : 'l.ativo = 0';
        }
        if (!empty($filters['q'])) {
            $where[]  = 'l.nome LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        $whereStr = implode(' AND ', $where);
        $page   = max(1, (int)($filters['page'] ?? 1));
        $limit  = ITEMS_PER_PAGE;
        $offset = ($page - 1) * $limit;

        $stmt = $pdo->prepare(
            "SELECT l.*, COUNT(i.id) AS total_itens
             FROM nf_listas l
             LEFT JOIN nf_lista_itens i ON i.lista_id = l.id
             WHERE {$whereStr}
             GROUP BY l.id
             ORDER BY l.nome ASC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $listas = $stmt->fetchAll();

        // Listas de sistema entram no topo (página 1, sempre ativas)
        if ($page === 1 && ($filters['status'] ?? '') !== 'inativo') {
            $sistema = [];
            foreach (self::SYSTEM_LISTS as $id => $meta) {
                if (!empty($filters['q'])
                    && mb_stripos($meta['nome'], $filters['q']) === false) {
                    continue;
                }
                $count = (int) $pdo->query("SELECT COUNT(*) FROM {$meta['tabela']}")->fetchColumn();
                $sistema[] = [
                    'id'          => $id,
                    'nome'        => $meta['nome'],
                    'slug'        => $meta['tabela'],
                    'tipo'        => 'sistema',
                    'descricao'   => $meta['descricao'],
                    'ativo'       => 1,
                    'total_itens' => $count,
                ];
            }
            // Fornecedores: cadastro próprio (CNPJ, dados da Receita), mas
            // aparece na listagem como as demais — o "Ver itens" leva à tela
            // dedicada em vez do gerenciador genérico de itens.
            if (empty($filters['q']) || mb_stripos('Fornecedores', (string)$filters['q']) !== false) {
                $sistema[] = [
                    'id'          => null,
                    'nome'        => 'Fornecedores',
                    'slug'        => 'fornecedores',
                    'tipo'        => 'sistema',
                    'descricao'   => 'Cadastro de fornecedores — razão social, CNPJ/CPF, contato e dados da Receita',
                    'ativo'       => 1,
                    'total_itens' => (int) $pdo->query("SELECT COUNT(*) FROM fornecedores")->fetchColumn(),
                    'url'         => '?module=tabelas&action=fornecedores',
                ];
            }

            // Modelos de Veículos: lista com tela própria (MVS, ano, combustível, preço) —
            // alimenta o campo Modelo do pedido de Faturamento.
            if (empty($filters['q']) || mb_stripos('Modelos de Veículos', (string)$filters['q']) !== false) {
                $sistema[] = [
                    'id'          => null,
                    'nome'        => 'Modelos de Veículos',
                    'slug'        => 'modelos-veiculos',
                    'tipo'        => 'sistema',
                    'descricao'   => 'Modelos vendidos no Faturamento — MVS, ano-modelo, combustível e preço sugerido; com opção ativo / inativo',
                    'ativo'       => 1,
                    'total_itens' => (int) $pdo->query("SELECT COUNT(*) FROM nf_fat_modelos")->fetchColumn(),
                    'url'         => '?module=tabelas&action=modelos',
                ];
            }

            $listas = array_merge($sistema, $listas);
        }

        return $listas;
    }

    public static function count(array $filters = []): int
    {
        $pdo    = Database::getInstance();
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = $filters['status'] === 'ativo' ? 'ativo = 1' : 'ativo = 0';
        }
        if (!empty($filters['q'])) {
            $where[]  = 'nome LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        $whereStr = implode(' AND ', $where);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM nf_listas WHERE {$whereStr}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public static function findById(string $id): ?array
    {
        $pdo = Database::getInstance();

        if (self::isSystem($id)) {
            $meta  = self::SYSTEM_LISTS[$id];
            $count = (int) $pdo->query("SELECT COUNT(*) FROM {$meta['tabela']}")->fetchColumn();
            return [
                'id'          => $id,
                'nome'        => $meta['nome'],
                'slug'        => $meta['tabela'],
                'tipo'        => 'sistema',
                'descricao'   => $meta['descricao'],
                'ativo'       => 1,
                'total_itens' => $count,
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT l.*, COUNT(i.id) AS total_itens
             FROM nf_listas l
             LEFT JOIN nf_lista_itens i ON i.lista_id = l.id
             WHERE l.id = ?
             GROUP BY l.id
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data): string
    {
        $pdo  = Database::getInstance();
        $id   = self::generateUuid();
        $slug = self::generateSlug($data['nome']);

        $stmt = $pdo->prepare(
            "INSERT INTO nf_listas (id, nome, slug, tipo, descricao, ativo)
             VALUES (?, ?, ?, 'personalizada', ?, 1)"
        );
        $stmt->execute([$id, $data['nome'], $slug, $data['descricao'] ?? null]);
        return $id;
    }

    public static function update(string $id, array $data): bool
    {
        $pdo  = Database::getInstance();
        $sets = [];
        $params = [];

        if (isset($data['nome'])) {
            $sets[]   = 'nome = ?';
            $params[] = $data['nome'];
        }
        if (array_key_exists('descricao', $data)) {
            $sets[]   = 'descricao = ?';
            $params[] = $data['descricao'];
        }

        if (empty($sets)) return false;

        $params[] = $id;
        $stmt = $pdo->prepare("UPDATE nf_listas SET " . implode(', ', $sets) . " WHERE id = ?");
        return $stmt->execute($params) !== false;
    }

    public static function toggleStatus(string $id): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE nf_listas SET ativo = IF(ativo = 1, 0, 1) WHERE id = ?");
        return $stmt->execute([$id]) !== false;
    }

    // ─────────────────────────────────────────
    // ITENS (valor simples)
    // ─────────────────────────────────────────

    public static function getItens(string $listaId, array $filters = []): array
    {
        $pdo    = Database::getInstance();
        $page   = max(1, (int)($filters['page'] ?? 1));
        $limit  = ITEMS_PER_PAGE;
        $offset = ($page - 1) * $limit;

        if (self::isSystem($listaId)) {
            $tabela = self::systemTable($listaId);
            $where  = ['1=1'];
            $params = [];
            if (!empty($filters['status'])) {
                $where[] = $filters['status'] === 'ativo' ? 't.ativo = 1' : 't.ativo = 0';
            }
            if (!empty($filters['q'])) {
                $where[]  = 't.nome LIKE ?';
                $params[] = '%' . $filters['q'] . '%';
            }
            $whereStr = implode(' AND ', $where);

            // Setores carregam o vínculo com o departamento; filiais carregam o CNPJ;
            // etapas carregam os atributos de fluxo
            $extraSelect = '';
            $extraJoin   = '';
            if ($listaId === self::ID_SETORES) {
                $extraSelect = ', t.codigo, t.departamento_id, d.valor AS departamento_nome';
                $extraJoin   = 'LEFT JOIN nf_lista_itens d ON d.id = t.departamento_id';
            } elseif ($listaId === self::ID_FILIAIS) {
                $extraSelect = ', t.cnpj';
            } elseif ($listaId === self::ID_ETAPAS) {
                $extraSelect = ', t.descricao, t.encerra_automaticamente, t.sla_horas, t.tipo_aprovador, t.visualizacao_restrita, t.somente_observacao';
            }

            $stmt = $pdo->prepare(
                "SELECT t.id, t.nome AS valor, t.ativo{$extraSelect}
                 FROM {$tabela} t
                 {$extraJoin}
                 WHERE {$whereStr}
                 ORDER BY t.nome ASC
                 LIMIT {$limit} OFFSET {$offset}"
            );
            $stmt->execute($params);
            return $stmt->fetchAll();
        }

        $where  = ['lista_id = ?'];
        $params = [$listaId];

        if (!empty($filters['status'])) {
            $where[] = $filters['status'] === 'ativo' ? 'ativo = 1' : 'ativo = 0';
        }
        if (!empty($filters['q'])) {
            $where[]  = 'valor LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT * FROM nf_lista_itens
             WHERE {$whereStr}
             ORDER BY valor ASC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function countItens(string $listaId, array $filters = []): int
    {
        $pdo = Database::getInstance();

        if (self::isSystem($listaId)) {
            $tabela = self::systemTable($listaId);
            $where  = ['1=1'];
            $params = [];
            if (!empty($filters['status'])) {
                $where[] = $filters['status'] === 'ativo' ? 'ativo = 1' : 'ativo = 0';
            }
            if (!empty($filters['q'])) {
                $where[]  = 'nome LIKE ?';
                $params[] = '%' . $filters['q'] . '%';
            }
            $whereStr = implode(' AND ', $where);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tabela} WHERE {$whereStr}");
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        }

        $where  = ['lista_id = ?'];
        $params = [$listaId];

        if (!empty($filters['status'])) {
            $where[] = $filters['status'] === 'ativo' ? 'ativo = 1' : 'ativo = 0';
        }
        if (!empty($filters['q'])) {
            $where[]  = 'valor LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        $whereStr = implode(' AND ', $where);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM nf_lista_itens WHERE {$whereStr}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public static function createItem(string $listaId, string $valor, array $extra = []): string
    {
        $pdo = Database::getInstance();
        $id  = self::generateUuid();

        if (self::isSystem($listaId)) {
            $tabela = self::systemTable($listaId);
            if ($listaId === self::ID_SETORES) {
                $stmt = $pdo->prepare("INSERT INTO {$tabela} (id, nome, codigo, departamento_id, ativo) VALUES (?, ?, ?, ?, 1)");
                $stmt->execute([$id, $valor, $extra['codigo'] ?? null, $extra['departamento_id'] ?? null]);
            } elseif ($listaId === self::ID_FILIAIS) {
                $stmt = $pdo->prepare("INSERT INTO {$tabela} (id, nome, cnpj, ativo) VALUES (?, ?, ?, 1)");
                $stmt->execute([$id, $valor, $extra['cnpj'] ?? null]);
            } elseif ($listaId === self::ID_ETAPAS) {
                $stmt = $pdo->prepare(
                    "INSERT INTO {$tabela}
                        (id, nome, descricao, encerra_automaticamente, sla_horas, tipo_aprovador, visualizacao_restrita, somente_observacao, decisao_lista_id, salto_incondicional, abre_fluxo, ativo, criado_por)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)"
                );
                $stmt->execute([
                    $id, $valor,
                    $extra['descricao'] ?? null,
                    (int) ($extra['encerra_automaticamente'] ?? 0),
                    $extra['sla_horas'] ?? null,
                    $extra['tipo_aprovador'] ?? 'ambos',
                    (int) ($extra['visualizacao_restrita'] ?? 0),
                    (int) ($extra['somente_observacao'] ?? 0),
                    $extra['decisao_lista_id'] ?? null,
                    (int) ($extra['salto_incondicional'] ?? 0),
                    (int) ($extra['abre_fluxo'] ?? 0),
                    $extra['criado_por'] ?? null,
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO {$tabela} (id, nome, ativo) VALUES (?, ?, 1)");
                $stmt->execute([$id, $valor]);
            }
            return $id;
        }

        // exige_valor só tem significado na lista Tipos de nota; nas demais
        // fica no default 1 e ninguém lê.
        // indice vale para qualquer lista: é a classificação usada por etapa de
        // decisão. NULL enquanto ninguém classificar — dado inerte até uma
        // etapa de decisão apontar para a lista.
        $stmt = $pdo->prepare(
            "INSERT INTO nf_lista_itens (id, lista_id, valor, indice, exige_valor, ativo) VALUES (?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([
            $id, $listaId, $valor,
            $extra['indice'] ?? null,
            $listaId === self::ID_LISTA_TIPOS_NOTA ? (int) ($extra['exige_valor'] ?? 1) : 1,
        ]);
        return $id;
    }

    public static function updateItem(string $itemId, string $valor, string $listaId = '', array $extra = []): bool
    {
        $pdo = Database::getInstance();

        if ($listaId !== '' && self::isSystem($listaId)) {
            $tabela = self::systemTable($listaId);
            if ($listaId === self::ID_SETORES && array_key_exists('departamento_id', $extra)) {
                $stmt = $pdo->prepare("UPDATE {$tabela} SET nome = ?, codigo = ?, departamento_id = ? WHERE id = ?");
                return $stmt->execute([$valor, $extra['codigo'] ?? null, $extra['departamento_id'], $itemId]) !== false;
            }
            if ($listaId === self::ID_FILIAIS && array_key_exists('cnpj', $extra)) {
                $stmt = $pdo->prepare("UPDATE {$tabela} SET nome = ?, cnpj = ? WHERE id = ?");
                return $stmt->execute([$valor, $extra['cnpj'], $itemId]) !== false;
            }
            if ($listaId === self::ID_ETAPAS) {
                $stmt = $pdo->prepare(
                    "UPDATE {$tabela}
                     SET nome = ?, descricao = ?, encerra_automaticamente = ?,
                         sla_horas = ?, tipo_aprovador = ?, visualizacao_restrita = ?,
                         somente_observacao = ?, decisao_lista_id = ?,
                         salto_incondicional = ?, abre_fluxo = ?
                     WHERE id = ?"
                );
                return $stmt->execute([
                    $valor,
                    $extra['descricao'] ?? null,
                    (int) ($extra['encerra_automaticamente'] ?? 0),
                    $extra['sla_horas'] ?? null,
                    $extra['tipo_aprovador'] ?? 'ambos',
                    (int) ($extra['visualizacao_restrita'] ?? 0),
                    (int) ($extra['somente_observacao'] ?? 0),
                    $extra['decisao_lista_id'] ?? null,
                    (int) ($extra['salto_incondicional'] ?? 0),
                    (int) ($extra['abre_fluxo'] ?? 0),
                    $itemId,
                ]) !== false;
            }
            $stmt = $pdo->prepare("UPDATE {$tabela} SET nome = ? WHERE id = ?");
            return $stmt->execute([$valor, $itemId]) !== false;
        }

        if ($listaId === self::ID_LISTA_TIPOS_NOTA) {
            $stmt = $pdo->prepare("UPDATE nf_lista_itens SET valor = ?, indice = ?, exige_valor = ? WHERE id = ?");
            return $stmt->execute([$valor, $extra['indice'] ?? null, (int) ($extra['exige_valor'] ?? 1), $itemId]) !== false;
        }

        $stmt = $pdo->prepare("UPDATE nf_lista_itens SET valor = ?, indice = ? WHERE id = ?");
        return $stmt->execute([$valor, $extra['indice'] ?? null, $itemId]) !== false;
    }

    /**
     * O tipo de nota exige o "Valor Total" na abertura do processo?
     *
     * Tipo sem valor a informar (Cancelamento, Devolução, Remessa) é marcado
     * com exige_valor = 0 em Tabelas → Tipos de nota. Além de soltar a
     * obrigatoriedade do campo, isso tira a faixa valor_min/valor_max da busca
     * de template — sem valor informado, filtrar por faixa esconderia todo
     * template que declara uma.
     *
     * Tipo inexistente ou id vazio devolve true: o padrão continua sendo exigir.
     */
    public static function tipoNotaExigeValor(?string $tipoNotaId): bool
    {
        if ($tipoNotaId === null || $tipoNotaId === '') {
            return true;
        }

        static $cache = [];
        if (!array_key_exists($tipoNotaId, $cache)) {
            $stmt = Database::getInstance()->prepare(
                "SELECT exige_valor FROM nf_lista_itens WHERE id = ? AND lista_id = ? LIMIT 1"
            );
            $stmt->execute([$tipoNotaId, self::ID_LISTA_TIPOS_NOTA]);
            $v = $stmt->fetchColumn();
            $cache[$tipoNotaId] = $v === false || (int) $v === 1;
        }

        return $cache[$tipoNotaId];
    }

    public static function findItemById(string $itemId): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM nf_lista_itens WHERE id = ? LIMIT 1");
        $stmt->execute([$itemId]);
        return $stmt->fetch() ?: null;
    }

    public static function toggleItemStatus(string $itemId, string $listaId = ''): bool
    {
        $pdo = Database::getInstance();

        if ($listaId !== '' && self::isSystem($listaId)) {
            $tabela = self::systemTable($listaId);
            $stmt = $pdo->prepare("UPDATE {$tabela} SET ativo = IF(ativo = 1, 0, 1) WHERE id = ?");
            return $stmt->execute([$itemId]) !== false;
        }

        $stmt = $pdo->prepare("UPDATE nf_lista_itens SET ativo = IF(ativo = 1, 0, 1) WHERE id = ?");
        return $stmt->execute([$itemId]) !== false;
    }

    /**
     * Verifica se a lista está em uso por algum template (tipo_nota_id ou campo tipo select com lista_id).
     */
    public static function emUso(string $listaId): bool
    {
        $pdo = Database::getInstance();

        // Usada como tipo_nota em templates
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_template_versoes WHERE tipo_nota_id IN (SELECT id FROM nf_lista_itens WHERE lista_id = ?)"
        );
        $stmt->execute([$listaId]);
        if ((int)$stmt->fetchColumn() > 0) return true;

        // Usada como forma_pagamento em parcelas
        $stmt2 = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_parcelas WHERE forma_pagamento_id IN (SELECT id FROM nf_lista_itens WHERE lista_id = ?) AND deletado_em IS NULL"
        );
        $stmt2->execute([$listaId]);
        if ((int)$stmt2->fetchColumn() > 0) return true;

        // Usada como lista_id em campos de templates (JSON etapas/campos contém lista_id)
        $stmt3 = $pdo->prepare(
            "SELECT COUNT(*) FROM nf_template_versoes WHERE campos LIKE ?"
        );
        $stmt3->execute(['%' . $listaId . '%']);
        if ((int)$stmt3->fetchColumn() > 0) return true;

        return false;
    }

    /**
     * Exclui uma lista e seus itens. Só funciona se não estiver em uso.
     */
    public static function delete(string $listaId): bool
    {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM nf_lista_itens WHERE lista_id = ?")->execute([$listaId]);
            $pdo->prepare("DELETE FROM nf_listas WHERE id = ?")->execute([$listaId]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return false;
        }
    }

    /**
     * Conta referências a um item de lista de sistema (setor/filial) em todo
     * o banco, descobrindo dinamicamente as tabelas que têm a coluna
     * setor_id/filial_id — as FKs são suaves, então a checagem é manual.
     *
     * @return array{total: int, tabelas: string[]}
     */
    public static function systemItemUso(string $listaId, string $itemId): array
    {
        $pdo = Database::getInstance();

        // Etapas: uso = templates que referenciam a etapa (JSON) + etapas de processos
        if ($listaId === self::ID_ETAPAS) {
            $total  = 0;
            $usadas = [];

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM nf_template_versoes WHERE etapas LIKE ?");
            $stmt->execute(['%' . $itemId . '%']);
            $n = (int) $stmt->fetchColumn();
            if ($n > 0) { $total += $n; $usadas[] = "nf_template_versoes ({$n})"; }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM nf_processo_etapas WHERE etapa_catalogo_id = ?");
            $stmt->execute([$itemId]);
            $n = (int) $stmt->fetchColumn();
            if ($n > 0) { $total += $n; $usadas[] = "nf_processo_etapas ({$n})"; }

            return ['total' => $total, 'tabelas' => $usadas];
        }

        $coluna = self::systemTable($listaId) === 'setores' ? 'setor_id' : 'filial_id';

        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?"
        );
        $stmt->execute([$coluna]);
        $tabelas = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $total  = 0;
        $usadas = [];
        foreach ($tabelas as $tabela) {
            // Nome de tabela vem do information_schema — seguro para interpolar
            $c = $pdo->prepare("SELECT COUNT(*) FROM `{$tabela}` WHERE `{$coluna}` = ?");
            $c->execute([$itemId]);
            $n = (int) $c->fetchColumn();
            if ($n > 0) {
                $total   += $n;
                $usadas[] = "{$tabela} ({$n})";
            }
        }

        return ['total' => $total, 'tabelas' => $usadas];
    }

    /**
     * Exclui um item. Listas de sistema: só se não houver referências
     * (checar com systemItemUso antes). Pré-configuradas: nunca.
     */
    public static function deleteItem(string $itemId, string $listaId): bool
    {
        $pdo = Database::getInstance();

        if (self::isSystem($listaId)) {
            $tabela = self::systemTable($listaId);
            $stmt = $pdo->prepare("DELETE FROM {$tabela} WHERE id = ?");
            $stmt->execute([$itemId]);
            return $stmt->rowCount() > 0;
        }

        // A marcação por departamento morre com o item — sem isso sobram linhas
        // órfãs apontando para um item que não existe mais.
        $pdo->prepare("DELETE FROM nf_lista_item_departamentos WHERE item_id = ?")->execute([$itemId]);

        $stmt = $pdo->prepare("DELETE FROM nf_lista_itens WHERE id = ? AND lista_id = ?");
        $stmt->execute([$itemId, $listaId]);
        return $stmt->rowCount() > 0;
    }

    // ─────────────────────────────────────────
    // Visibilidade de item de lista por departamento
    //
    // Item SEM nenhum departamento marcado vale para TODOS — é o estado de
    // quem nunca foi configurado, e o que mantém o comportamento antigo.
    // ─────────────────────────────────────────

    /** @return list<string> ids dos departamentos marcados para o item */
    public static function getDepartamentosDoItem(string $itemId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT departamento_id FROM nf_lista_item_departamentos WHERE item_id = ?"
        );
        $stmt->execute([$itemId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Regrava a marcação do item. Lista vazia = visível para todos.
     *
     * @param list<string> $departamentoIds
     */
    public static function setDepartamentosDoItem(string $itemId, array $departamentoIds): void
    {
        $pdo = Database::getInstance();
        $pdo->prepare("DELETE FROM nf_lista_item_departamentos WHERE item_id = ?")->execute([$itemId]);

        if (!$departamentoIds) {
            return;
        }
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO nf_lista_item_departamentos (item_id, departamento_id) VALUES (?, ?)"
        );
        foreach (array_unique($departamentoIds) as $dep) {
            if ($dep !== '') {
                $stmt->execute([$itemId, $dep]);
            }
        }
    }

    /**
     * Mapa item → departamentos de uma lista inteira, para a tela montar o
     * filtro sem uma consulta por item.
     *
     * @return array<string,list<string>>
     */
    public static function mapaDepartamentosDaLista(string $listaId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT d.item_id, d.departamento_id
               FROM nf_lista_item_departamentos d
               JOIN nf_lista_itens i ON i.id = d.item_id
              WHERE i.lista_id = ?"
        );
        $stmt->execute([$listaId]);

        $mapa = [];
        foreach ($stmt->fetchAll() as $r) {
            $mapa[(string) $r['item_id']][] = (string) $r['departamento_id'];
        }
        return $mapa;
    }

    /** Itens ativos da lista Departamentos — opções do vínculo setor→departamento. */
    /**
     * Listas que podem governar uma ETAPA DE DECISÃO.
     *
     * Só as personalizadas: as de sistema (setores, filiais, etapas) vivem em
     * tabelas próprias, sem a coluna `indice`, então não classificam nada.
     *
     * Traz junto quantos índices distintos a lista já tem — é esse número que
     * o editor de template vai usar para saber quantos destinos pedir, e o
     * cadastro de etapa mostra para o usuário entender o que está escolhendo.
     *
     * @return array<int,array{id:string,nome:string,indices:int,sem_indice:int}>
     */
    public static function listasClassificaveis(): array
    {
        return Database::getInstance()->query(
            "SELECT l.id, l.nome,
                    COUNT(DISTINCT i.indice)                    AS indices,
                    SUM(CASE WHEN i.indice IS NULL THEN 1 ELSE 0 END) AS sem_indice
               FROM nf_listas l
               JOIN nf_lista_itens i ON i.lista_id = l.id AND i.ativo = 1
              WHERE l.ativo = 1
              GROUP BY l.id, l.nome
              ORDER BY l.nome"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Índices de uma lista, com o rótulo dos itens que caem em cada um.
     *
     * É o que a etapa de decisão pergunta: "quantos caminhos esta lista abre, e
     * como cada um se chama?". Dois itens podem compartilhar o mesmo índice
     * (ex.: "MEI" e "EIRELI" ambos no índice 2) — daí os rótulos virem
     * agrupados, e não um por linha.
     *
     * Itens ativos sem índice ficam de fora: não classificam caminho nenhum.
     * Quem precisa avisar o usuário sobre eles usa listasClassificaveis(),
     * que devolve a contagem em `sem_indice`.
     *
     * @return array<int,array{indice:int,rotulo:string}> ordenado por índice
     */
    public static function indicesDaLista(string $listaId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT i.indice, GROUP_CONCAT(i.valor ORDER BY i.valor SEPARATOR ' / ') AS rotulo
               FROM nf_lista_itens i
              WHERE i.lista_id = ? AND i.ativo = 1 AND i.indice IS NOT NULL
              GROUP BY i.indice
              ORDER BY i.indice"
        );
        $stmt->execute([$listaId]);

        return array_map(
            fn($r) => ['indice' => (int) $r['indice'], 'rotulo' => (string) $r['rotulo']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public static function getDepartamentosAtivos(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT id, valor FROM nf_lista_itens WHERE lista_id = ? AND ativo = 1 ORDER BY valor"
        );
        $stmt->execute([self::ID_LISTA_DEPARTAMENTOS]);
        return $stmt->fetchAll();
    }

    public static function hasItens(string $listaId): bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM nf_lista_itens WHERE lista_id = ?");
        $stmt->execute([$listaId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function generateSlug(string $nome): string
    {
        $pdo  = Database::getInstance();
        $slug = mb_strtolower(trim($nome));
        $slug = preg_replace('/[àáâãä]/u', 'a', $slug);
        $slug = preg_replace('/[èéêë]/u', 'e', $slug);
        $slug = preg_replace('/[ìíîï]/u', 'i', $slug);
        $slug = preg_replace('/[òóôõö]/u', 'o', $slug);
        $slug = preg_replace('/[ùúûü]/u', 'u', $slug);
        $slug = preg_replace('/[ç]/u', 'c', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        $base = $slug;
        $i = 2;
        while (true) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM nf_listas WHERE slug = ?");
            $check->execute([$slug]);
            if ((int)$check->fetchColumn() === 0) break;
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }
}
