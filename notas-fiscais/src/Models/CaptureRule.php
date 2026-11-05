<?php
// @versao Pre_04 V_07_12/08/2026
declare(strict_types=1);

/**
 * Model das regras de pré-fluxo (configurador de vínculos).
 *
 * Regra = critérios de match (CNPJ emitente × CNPJ destino, CFOP opcional)
 *       + payload de campos pré-configurados do template
 *       + modo de operação (sugerir | automatico).
 */
class CaptureRule
{
    /**
     * Lista as regras, opcionalmente filtradas.
     *
     * @param array{origem?:string,destino?:string,template?:string,status?:string} $filtros
     *        origem   CNPJ do fornecedor (só dígitos) ou '__qualquer__' p/ regras sem fornecedor
     *        destino  CNPJ da filial (só dígitos)
     *        template ID do template ('__sem__' p/ regras de arquivamento, sem template)
     *        status   'ativas' | 'inativas' | 'todas' (vazio = todas)
     */
    public static function list(array $filtros = []): array
    {
        $pdo    = Database::getInstance();
        $where  = [];
        $params = [];

        if (($filtros['status'] ?? '') === 'ativas') {
            $where[] = "r.ativo = 1";
        } elseif (($filtros['status'] ?? '') === 'inativas') {
            $where[] = "r.ativo = 0";
        }

        if (($filtros['origem'] ?? '') !== '') {
            if ($filtros['origem'] === '__qualquer__') {
                $where[] = "(r.fornecedor_cnpj IS NULL OR r.fornecedor_cnpj = '')";
            } else {
                $where[]  = "r.fornecedor_cnpj = ?";
                $params[] = $filtros['origem'];
            }
        }
        if (($filtros['destino'] ?? '') !== '') {
            $where[]  = "r.destinatario_cnpj = ?";
            $params[] = $filtros['destino'];
        }
        if (($filtros['template'] ?? '') !== '') {
            if ($filtros['template'] === '__sem__') {
                $where[] = "(r.template_id IS NULL OR r.template_id = '')";
            } else {
                $where[]  = "r.template_id = ?";
                $params[] = $filtros['template'];
            }
        }

        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare(
            "SELECT r.*, t.nome AS template_nome,
                    u.nome AS criado_por_nome,
                    f.nome AS filial_nome,
                    fo.razao_social AS fornecedor_nome
             FROM nf_captura_regras r
             LEFT JOIN nf_templates t ON t.id = r.template_id
             LEFT JOIN usuarios u     ON u.id = r.criado_por
             LEFT JOIN filiais f      ON REPLACE(REPLACE(REPLACE(f.cnpj,'.',''),'/',''),'-','') = r.destinatario_cnpj
             LEFT JOIN fornecedores fo ON REPLACE(REPLACE(REPLACE(fo.cnpj,'.',''),'/',''),'-','') = r.fornecedor_cnpj
             {$sqlWhere}
             ORDER BY r.ativo DESC, r.nome ASC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Opções dos selects de filtro da lista — apenas valores que existem em
     * alguma regra, para não oferecer combinação que retorna vazio.
     *
     * @return array{origens:array,destinos:array,templates:array}
     */
    public static function getOpcoesFiltro(): array
    {
        $pdo = Database::getInstance();

        $origens = $pdo->query(
            "SELECT DISTINCT r.fornecedor_cnpj AS cnpj, fo.razao_social AS nome
             FROM nf_captura_regras r
             LEFT JOIN fornecedores fo ON REPLACE(REPLACE(REPLACE(fo.cnpj,'.',''),'/',''),'-','') = r.fornecedor_cnpj"
        )->fetchAll();

        $destinos = $pdo->query(
            "SELECT DISTINCT r.destinatario_cnpj AS cnpj, f.nome AS nome
             FROM nf_captura_regras r
             LEFT JOIN filiais f ON REPLACE(REPLACE(REPLACE(f.cnpj,'.',''),'/',''),'-','') = r.destinatario_cnpj"
        )->fetchAll();

        $templates = $pdo->query(
            "SELECT DISTINCT r.template_id AS id, t.nome AS nome
             FROM nf_captura_regras r
             LEFT JOIN nf_templates t ON t.id = r.template_id"
        )->fetchAll();

        return [
            'origens'   => self::ordenarOpcoes($origens, 'cnpj'),
            'destinos'  => self::ordenarOpcoes($destinos, 'cnpj'),
            'templates' => self::ordenarOpcoes($templates, 'id'),
        ];
    }

    /** Remove duplicatas pela chave e ordena por nome (sem nome vai para o fim). */
    private static function ordenarOpcoes(array $linhas, string $chave): array
    {
        $unicas = [];
        foreach ($linhas as $l) {
            $k = (string) ($l[$chave] ?? '');
            if (!isset($unicas[$k]) || (string) ($unicas[$k]['nome'] ?? '') === '') {
                $unicas[$k] = $l;
            }
        }
        $unicas = array_values($unicas);
        usort($unicas, static function (array $a, array $b) use ($chave): int {
            $na = (string) ($a['nome'] ?? '');
            $nb = (string) ($b['nome'] ?? '');
            if (($na === '') !== ($nb === '')) {
                return $na === '' ? 1 : -1;
            }
            return $na !== ''
                ? strcasecmp($na, $nb)
                : strcmp((string) ($a[$chave] ?? ''), (string) ($b[$chave] ?? ''));
        });
        return $unicas;
    }

    public static function findById(string $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM nf_captura_regras WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data, string $criadoPor): string
    {
        $pdo = Database::getInstance();
        $id  = self::uuid();

        $stmt = $pdo->prepare(
            "INSERT INTO nf_captura_regras
                (id, nome, fornecedor_cnpj, destinatario_cnpj, cfop, natureza_operacao, tipo_documento, tomador,
                 valor_min, valor_max, item_condicao, item_prefixo,
                 template_id, campos_json, modo, ativo, criado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)"
        );
        $stmt->execute([
            $id,
            $data['nome'],
            $data['fornecedor_cnpj'] ?: null,
            $data['destinatario_cnpj'],
            $data['cfop'] ?: null,
            $data['natureza_operacao'] ?: null,
            $data['tipo_documento'] ?: null,
            $data['tomador'] ?: null,
            $data['valor_min'],
            $data['valor_max'],
            $data['item_condicao'],
            $data['item_prefixo'],
            $data['template_id'] ?: null,
            json_encode($data['campos'] ?: new stdClass(), JSON_UNESCAPED_UNICODE),
            $data['modo'],
            $criadoPor,
        ]);

        return $id;
    }

    public static function update(string $id, array $data): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "UPDATE nf_captura_regras
             SET nome = ?, fornecedor_cnpj = ?, destinatario_cnpj = ?, cfop = ?,
                 natureza_operacao = ?, tipo_documento = ?, tomador = ?,
                 valor_min = ?, valor_max = ?, item_condicao = ?, item_prefixo = ?,
                 template_id = ?, campos_json = ?, modo = ?, precisa_revisao = 0
             WHERE id = ?"
        );
        $stmt->execute([
            $data['nome'],
            $data['fornecedor_cnpj'] ?: null,
            $data['destinatario_cnpj'],
            $data['cfop'] ?: null,
            $data['natureza_operacao'] ?: null,
            $data['tipo_documento'] ?: null,
            $data['tomador'] ?: null,
            $data['valor_min'],
            $data['valor_max'],
            $data['item_condicao'],
            $data['item_prefixo'],
            $data['template_id'] ?: null,
            json_encode($data['campos'] ?: new stdClass(), JSON_UNESCAPED_UNICODE),
            $data['modo'],
            $id,
        ]);
    }

    public static function toggleAtivo(string $id): ?bool
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE nf_captura_regras SET ativo = IF(ativo = 1, 0, 1) WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT ativo FROM nf_captura_regras WHERE id = ?");
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    public static function delete(string $id): bool
    {
        $pdo = Database::getInstance();
        // Limpa referências nas notas (mantém histórico de vinculadas)
        $pdo->prepare(
            "UPDATE nf_notas_capturadas SET status = 'capturada', regra_id = NULL
             WHERE regra_id = ? AND status = 'sugerida'"
        )->execute([$id]);

        $stmt = $pdo->prepare("DELETE FROM nf_captura_regras WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Templates com versão publicada + campos JSON — usados pelo formulário
     * da regra (renderiza os campos pré-configuráveis) e pelo modal de
     * vinculação manual.
     */
    public static function getTemplatesPublicados(): array
    {
        $pdo = Database::getInstance();
        return $pdo->query(
            "SELECT t.id, t.nome, v.campos, v.tem_financeiro,
                    s.departamento_id AS departamento_id,
                    d.valor           AS departamento_nome
             FROM nf_templates t
             JOIN nf_template_versoes v ON v.id = t.versao_publicada_id
             LEFT JOIN setores s        ON s.id = v.setor_id
             LEFT JOIN nf_lista_itens d ON d.id = s.departamento_id
             WHERE v.status = 'publicado'
             ORDER BY t.nome ASC"
        )->fetchAll();
    }

    /**
     * Filiais para o select de CNPJ destino — recortadas pelo escopo do usuário
     * logado (quem só enxerga duas lojas também só escolhe entre elas).
     */
    public static function getFiliais(): array
    {
        $pdo    = Database::getInstance();
        $cnpj   = "REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','')";
        $where  = ['ativo = 1'];
        $params = [];

        if (class_exists('FilialScope')) {
            [$cond, $p] = FilialScope::sqlRestringeSessao($cnpj);
            if ($cond !== '') {
                $where[] = $cond;
                $params  = array_merge($params, $p);
            }
        }

        $stmt = $pdo->prepare(
            "SELECT id, nome, {$cnpj} AS cnpj
             FROM filiais WHERE " . implode(' AND ', $where) . ' ORDER BY nome'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private static function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}
