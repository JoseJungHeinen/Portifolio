<?php
/**
 * Resolver de campos para geração de documentos (multi-template + globais).
 *
 * Retorna:
 * [
 *   'templates' => [ ['id'=>1,'nome'=>'...'], ... ],
 *   'globais' => [
 *      [
 *        'id'=>1,'chave'=>'NOME_CLIENTE','rotulo'=>'Nome do cliente','tipo'=>'texto_curto',
 *        'obrigatorio'=>1,'obrigatorio_em'=>[1,2],'ordem'=>0
 *      ], ...
 *   ],
 *   'campos_por_template' => [
 *      1 => [ ... ],
 *      2 => [ ... ],
 *   ]
 * ]
 */


// ============================================================
// COMPATIBILIDADE PHP 7.x
// - array_is_list() existe apenas no PHP 8+.
// - Polyfill para evitar Fatal Error em produção.
// ============================================================
if (!function_exists('array_is_list')) {
    function array_is_list(array $array): bool {
        $i = 0;
        foreach ($array as $k => $_) {
            if ($k !== $i) return false;
            $i++;
        }
        return true;
    }
}





// ============================================================
// NORMALIZAÇÃO GLOBAL DE TEXTO PARA GERAÇÃO (CAIXA ALTA)
// - Use documentos_upper_tudo($dados) para converter TODOS os valores
//   (incluindo acentos) para CAIXA ALTA antes de substituir no DOCX.
// ============================================================
if (!function_exists('documentos_upper_tudo')) {
    /**
     * Converte recursivamente qualquer valor para CAIXA ALTA (UTF-8).
     * - Mantém números como estão.
     * - Mantém estrutura de arrays.
     */
    function documentos_upper_tudo($v) {
        if (is_array($v)) {
            foreach ($v as $k => $item) {
                $v[$k] = documentos_upper_tudo($item);
            }
            return $v;
        }

        // Para objetos (caso venha algum DateTime etc.), tenta converter pra string.
        if (is_object($v)) {
            if (method_exists($v, '__toString')) {
                $v = (string)$v;
            } else {
                return $v; // não mexe
            }
        }

        $s = trim((string)$v);
        if ($s === '') return '';

        // UTF-8 com acentos
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($s, 'UTF-8');
        }

        // Fallback (sem mbstring) - pode não transformar corretamente acentos.
        return strtoupper($s);
    }
}

if (!function_exists('documentos_normalizar_chave_global')) {
    function documentos_normalizar_chave_global(string $chave): string {
        $chave = trim($chave);
        if ($chave === '') return '';
        $chave = preg_replace('/[^A-Za-z0-9_]+/', '_', $chave);
        $chave = trim(preg_replace('/_+/', '_', $chave), '_');
        return strtoupper($chave);
    }
}

if (!function_exists('documentos_lista_obter_opcoes')) {
    /**
     * Busca opções da lista (manual) via documento_listas.config_manual
     * Cache por lista_id para não consultar repetido.
     *
     * Retorna: [ ['valor'=>'x','label'=>'y'], ... ]
     */
    function documentos_lista_obter_opcoes(mysqli $mysqli, int $listaId, array &$cache): array {
        if ($listaId <= 0) return [];

        if (array_key_exists($listaId, $cache)) {
            return $cache[$listaId];
        }

        $cache[$listaId] = [];

        $stmt = $mysqli->prepare("SELECT id, tipo, config_manual FROM documento_listas WHERE id=? LIMIT 1");
        if (!$stmt) return $cache[$listaId];

        $stmt->bind_param('i', $listaId);
        $stmt->execute();
        $res = $stmt->get_result();
        $lista = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$lista) return $cache[$listaId];

        // Hoje você usa listas manuais -> config_manual
        $cfgRaw = (string)($lista['config_manual'] ?? '');
        if ($cfgRaw === '') return $cache[$listaId];

        $cfg = json_decode($cfgRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($cfg)) {
            return $cache[$listaId];
        }

        // Aceita formatos:
        // 1) { "opcoes": [ {"valor":"1","label":"A"}, ... ] }
        // 2) [ {"valor":"1","label":"A"}, ... ]
        $opcoes = [];
        if (isset($cfg['opcoes']) && is_array($cfg['opcoes'])) {
            $opcoes = $cfg['opcoes'];
        } elseif (array_is_list($cfg)) {
            $opcoes = $cfg;
        }

        foreach ($opcoes as $opt) {
            if (!is_array($opt)) continue;

            // tolera chaves diferentes (valor/value, label/text)
            $valor = $opt['valor'] ?? ($opt['value'] ?? null);
            $label = $opt['label'] ?? ($opt['text'] ?? null);

            if ($valor === null) continue;
            if ($label === null) $label = (string)$valor;

            $cache[$listaId][] = [
                'valor' => (string)$valor,
                'label' => (string)$label,
            ];
        }

        return $cache[$listaId];
    }
}

if (!function_exists('documentos_campo_com_opcoes')) {
    /**
     * Monta um campo do template (documento_campos) incluindo opcoes quando aplicável.
     * Agora usa lista_id (sem config_lista).
     */
    function documentos_campo_com_opcoes(mysqli $mysqli, array $row, array &$cacheListas): array {
        $tipo = (string)($row['tipo'] ?? 'texto_curto');

        $campo = [
            'id'          => (int)($row['id'] ?? 0),
            'nome_campo'  => (string)($row['nome_campo'] ?? ''),
            'rotulo'      => (string)($row['rotulo'] ?? ''),
            'tipo'        => $tipo,
            'obrigatorio' => (int)($row['obrigatorio'] ?? 0),
            'lista_id'    => isset($row['lista_id']) ? (int)$row['lista_id'] : null,
            'opcoes'      => [],
        ];

        // Tipos com opções
        $tiposComOpcoes = ['select','enum','radio','checkbox','booleano'];
        if (in_array($tipo, $tiposComOpcoes, true)) {
            $listaId = (int)($row['lista_id'] ?? 0);
            if ($listaId > 0) {
                $campo['opcoes'] = documentos_lista_obter_opcoes($mysqli, $listaId, $cacheListas);
            }

            // booleano sem opções: cria padrão Sim/Não
            if ($tipo === 'booleano' && empty($campo['opcoes'])) {
                $campo['opcoes'][] = ['valor' => '1', 'label' => 'Sim'];
                $campo['opcoes'][] = ['valor' => '0', 'label' => 'Não'];
            }
        }

        return $campo;
    }
}

if (!function_exists('documentos_resolver_campos')) {
    /**
     * @param int[] $templateIds
     */
    function documentos_resolver_campos(mysqli $mysqli, array $templateIds): array {
        $templateIds = array_values(array_unique(array_filter(array_map('intval', $templateIds), fn($v) => $v > 0)));

        if (empty($templateIds)) {
            return [
                'templates' => [],
                'globais' => [],
                'campos_por_template' => [],
            ];
        }

        // Templates válidos
        $in = implode(',', array_fill(0, count($templateIds), '?'));
        $types = str_repeat('i', count($templateIds));

        $sqlTpl = "SELECT id, nome FROM documento_templates WHERE ativo=1 AND id IN ($in) ORDER BY nome ASC";
        $stmt = $mysqli->prepare($sqlTpl);
        if (!$stmt) {
            return ['error' => 'Erro ao preparar consulta de templates'];
        }
        $stmt->bind_param($types, ...$templateIds);
        $stmt->execute();
        $resTpl = $stmt->get_result();

        $templates = [];
        while ($r = $resTpl->fetch_assoc()) {
            $templates[] = ['id' => (int)$r['id'], 'nome' => (string)$r['nome']];
        }
        $stmt->close();

        if (empty($templates)) {
            return [
                'templates' => [],
                'globais' => [],
                'campos_por_template' => [],
            ];
        }

        $templateIdsValidos = array_map(fn($t) => (int)$t['id'], $templates);

        // Ajusta IN para os válidos (pode ser menos ids)
        $in2 = implode(',', array_fill(0, count($templateIdsValidos), '?'));
        $types2 = str_repeat('i', count($templateIdsValidos));

        // Campos por template (sem config_lista; usa lista_id)
        $camposPorTemplate = [];
        $sqlCampos = "
            SELECT id, template_id, nome_campo, rotulo, tipo, obrigatorio, ordem, lista_id
              FROM documento_campos
             WHERE template_id IN ($in2)
          ORDER BY template_id ASC, ordem ASC, id ASC
        ";
        $stmt = $mysqli->prepare($sqlCampos);
        if (!$stmt) {
            return ['error' => 'Erro ao preparar consulta de campos'];
        }
        $stmt->bind_param($types2, ...$templateIdsValidos);
        $stmt->execute();
        $resC = $stmt->get_result();

        $cacheListas = []; // cache de opções por lista_id

        while ($row = $resC->fetch_assoc()) {
            $tid = (int)$row['template_id'];
            if (!isset($camposPorTemplate[$tid])) $camposPorTemplate[$tid] = [];
            $camposPorTemplate[$tid][] = documentos_campo_com_opcoes($mysqli, $row, $cacheListas);
        }
        $stmt->close();

        // Globais vinculados aos templates (obrigatorio efetivo = override se não-null, senão global.obrigatorio)
        $globaisPorChave = []; // chave => agregado

        $sqlGlobais = "
            SELECT
                g.id            AS global_id,
                g.chave         AS chave,
                g.rotulo        AS rotulo,
                g.tipo          AS tipo,
                g.lista_id      AS lista_id,
                g.obrigatorio   AS obrigatorio_global,
                dtg.template_id AS template_id,
                dtg.obrigatorio_override AS obrigatorio_override,
                dtg.ordem       AS ordem
            FROM documento_template_globais dtg
            JOIN documento_variaveis_globais g ON g.id = dtg.global_id
            WHERE dtg.template_id IN ($in2)
            ORDER BY dtg.ordem ASC, g.rotulo ASC, g.id ASC
        ";

        $stmt = $mysqli->prepare($sqlGlobais);
        if ($stmt) {
            $stmt->bind_param($types2, ...$templateIdsValidos);
            $stmt->execute();
            $resG = $stmt->get_result();

            while ($row = $resG->fetch_assoc()) {
                $chaveNorm = documentos_normalizar_chave_global((string)$row['chave']);
                if ($chaveNorm === '') continue;

                $tid = (int)$row['template_id'];

                $obEfetivo = ($row['obrigatorio_override'] === null)
                    ? (int)$row['obrigatorio_global']
                    : (int)$row['obrigatorio_override'];

                if (!isset($globaisPorChave[$chaveNorm])) {
                    $globaisPorChave[$chaveNorm] = [
                        'id' => (int)$row['global_id'],
                        'chave' => $chaveNorm,
                        'rotulo' => (string)$row['rotulo'],
                        'tipo' => (string)$row['tipo'],
                        'lista_id' => isset($row['lista_id']) ? (int)$row['lista_id'] : null,
                        'opcoes' => [],
                        'obrigatorio' => 0,
                        'obrigatorio_em' => [],
                        'ordem' => (int)$row['ordem'],
                    ];

                    // Se for um tipo com opções, já resolve as opções (apenas 1x por chave)
                    $tipoG = (string)$row['tipo'];
                    $tiposComOpcoes = ['select','enum','radio','checkbox','booleano'];
                    if (in_array($tipoG, $tiposComOpcoes, true)) {
                        $listaId = (int)($row['lista_id'] ?? 0);
                        if ($listaId > 0) {
                            $globaisPorChave[$chaveNorm]['opcoes'] = documentos_lista_obter_opcoes($mysqli, $listaId, $cacheListas);
                        }

                        // booleano sem opções: cria padrão Sim/Não
                        if ($tipoG === 'booleano' && empty($globaisPorChave[$chaveNorm]['opcoes'])) {
                            $globaisPorChave[$chaveNorm]['opcoes'][] = ['valor' => '1', 'label' => 'Sim'];
                            $globaisPorChave[$chaveNorm]['opcoes'][] = ['valor' => '0', 'label' => 'Não'];
                        }
                    }
                }

                if ($obEfetivo === 1) {
                    $globaisPorChave[$chaveNorm]['obrigatorio'] = 1;
                    $globaisPorChave[$chaveNorm]['obrigatorio_em'][] = $tid;
                }
            }
            $stmt->close();
        }

        // Ordena globais
        $globais = array_values($globaisPorChave);
        usort($globais, function ($a, $b) {
            $oa = (int)($a['ordem'] ?? 0);
            $ob = (int)($b['ordem'] ?? 0);
            if ($oa !== $ob) return $oa <=> $ob;
            return strcasecmp((string)$a['rotulo'], (string)$b['rotulo']);
        });

        foreach ($globais as &$g) {
            $g['obrigatorio_em'] = array_values(array_unique(array_map('intval', $g['obrigatorio_em'] ?? [])));
        }
        unset($g);

        return [
            'templates' => $templates,
            'globais' => $globais,
            'campos_por_template' => $camposPorTemplate,
        ];
    }
}
