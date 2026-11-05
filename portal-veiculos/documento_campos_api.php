<?php
require_once __DIR__ . '/config_database.php';
session_start();

if (empty($_SESSION['logado'])) {
    http_response_code(403);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function carregarCamposDoTemplate(mysqli $mysqli, int $templateId): array {
    $stmt = $mysqli->prepare("
        SELECT id, nome_campo, rotulo, tipo, obrigatorio, origem_lista, config_lista
          FROM documento_campos
         WHERE template_id=?
      ORDER BY ordem ASC, id ASC
    ");
    if (!$stmt) return [];

    $stmt->bind_param('i', $templateId);
    $stmt->execute();
    $res = $stmt->get_result();

    $campos = [];
    while ($row = $res->fetch_assoc()) {
        $campo = [
            'id'           => (int)$row['id'],
            'nome_campo'   => (string)$row['nome_campo'],
            'rotulo'       => (string)$row['rotulo'],
            'tipo'         => (string)$row['tipo'],
            'obrigatorio'  => (int)$row['obrigatorio'],
            'origem_lista' => $row['origem_lista'] ?? null,
            'config_lista' => $row['config_lista'] ?? null,
            'opcoes'       => []
        ];

        // somente tipos de lista
        if (in_array($row['tipo'], ['select','enum','radio','checkbox','booleano'], true)) {

            // 1) lojas do sistema
            if ($row['origem_lista'] === 'tabela_lojas') {
                $sqlLojas = "SELECT id, nome FROM lojas ORDER BY nome ASC";
                if ($resL = $mysqli->query($sqlLojas)) {
                    while ($loja = $resL->fetch_assoc()) {
                        $campo['opcoes'][] = [
                            'valor' => (string)$loja['id'],
                            'label' => (string)$loja['nome']
                        ];
                    }
                    $resL->free();
                }
            }

            // 2) lista manual
            if ($row['origem_lista'] === 'lista_manual' && !empty($row['config_lista'])) {
                $cfg = json_decode($row['config_lista'], true);
                if (json_last_error() === JSON_ERROR_NONE &&
                    is_array($cfg) && !empty($cfg['opcoes']) && is_array($cfg['opcoes'])) {
                    foreach ($cfg['opcoes'] as $opt) {
                        if (!isset($opt['valor']) || !isset($opt['label'])) continue;
                        $campo['opcoes'][] = [
                            'valor' => (string)$opt['valor'],
                            'label' => (string)$opt['label']
                        ];
                    }
                }
            }

            // 3) booleano sem origem_lista: cria padrão Sim/Não
            if ($row['tipo'] === 'booleano' && $row['origem_lista'] === null) {
                if (empty($campo['opcoes'])) {
                    $campo['opcoes'][] = ['valor' => '1', 'label' => 'Sim'];
                    $campo['opcoes'][] = ['valor' => '0', 'label' => 'Não'];
                }
            }
        }

        $campos[] = $campo;
    }
    $stmt->close();

    return $campos;
}

function carregarGlobaisDosTemplates(mysqli $mysqli, array $templateIds): array {
    if (empty($templateIds)) return [];

    // monta IN seguro
    $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
    $types = str_repeat('i', count($templateIds));

    // tenta ler obrigatoriedade efetiva (se existir obrigatorio_override/obrigatorio no vínculo)
    // fallback: se não existir, usamos só g.obrigatorio
    $sql = "
        SELECT
            g.id,
            g.chave,
            g.rotulo,
            g.tipo,
            g.obrigatorio AS obrigatorio_padrao,
            tg.template_id,
            tg.global_id,
            tg.obrigatorio AS obrigatorio_no_template,
            tg.obrigatorio_override AS obrigatorio_override
        FROM documento_template_globais tg
        JOIN documento_variaveis_globais g ON g.id = tg.global_id
        WHERE tg.template_id IN ($placeholders)
        ORDER BY g.rotulo ASC
    ";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        // fallback simples (sem colunas do vínculo)
        $sql2 = "
            SELECT
                g.id, g.chave, g.rotulo, g.tipo, g.obrigatorio AS obrigatorio_padrao
            FROM documento_template_globais tg
            JOIN documento_variaveis_globais g ON g.id = tg.global_id
            WHERE tg.template_id IN ($placeholders)
            ORDER BY g.rotulo ASC
        ";
        $stmt2 = $mysqli->prepare($sql2);
        if (!$stmt2) return [];

        $stmt2->bind_param($types, ...$templateIds);
        $stmt2->execute();
        $r2 = $stmt2->get_result();

        $uni = [];
        while ($row = $r2->fetch_assoc()) {
            $gid = (int)$row['id'];
            if (!isset($uni[$gid])) {
                $uni[$gid] = [
                    'id' => $gid,
                    'chave' => (string)$row['chave'],
                    'rotulo' => (string)$row['rotulo'],
                    'tipo' => (string)$row['tipo'],
                    'obrigatorio' => !empty($row['obrigatorio_padrao']) ? 1 : 0,
                ];
            }
        }
        $stmt2->close();

        return array_values($uni);
    }

    $stmt->bind_param($types, ...$templateIds);
    $stmt->execute();
    $r = $stmt->get_result();

    // une por global_id (e marca obrigatório se QUALQUER template exigir)
    $uni = [];
    while ($row = $r->fetch_assoc()) {
        $gid = (int)$row['global_id'];
        $padrao = !empty($row['obrigatorio_padrao']) ? 1 : 0;

        // efetivo por template (prioriza override, senão obrigatorio, senão padrão)
        $efetivo = $padrao;
        if (array_key_exists('obrigatorio_override', $row) && $row['obrigatorio_override'] !== null) {
            $efetivo = !empty($row['obrigatorio_override']) ? 1 : 0;
        } elseif (array_key_exists('obrigatorio_no_template', $row) && $row['obrigatorio_no_template'] !== null) {
            $efetivo = !empty($row['obrigatorio_no_template']) ? 1 : 0;
        }

        if (!isset($uni[$gid])) {
            $uni[$gid] = [
                'id' => $gid,
                'chave' => (string)$row['chave'],
                'rotulo' => (string)$row['rotulo'],
                'tipo' => (string)$row['tipo'],
                'obrigatorio' => $efetivo,
            ];
        } else {
            // se algum template exige, a global vira obrigatória no lote
            if ($efetivo === 1) $uni[$gid]['obrigatorio'] = 1;
        }
    }

    $stmt->close();
    return array_values($uni);
}

/**
 * MODO 1 (compatível): ?template_id=10  -> retorna ARRAY de campos (como antes)
 * MODO 2 (novo): ?template_ids=1,2,3   -> retorna { globais:[], templates:[] }
 */
$templateId = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;

// Novo: template_ids (csv) ou template_ids[]
$templateIds = [];
if (!empty($_GET['template_ids'])) {
    if (is_array($_GET['template_ids'])) {
        foreach ($_GET['template_ids'] as $id) {
            $id = (int)$id;
            if ($id > 0) $templateIds[$id] = true;
        }
    } else {
        $parts = explode(',', (string)$_GET['template_ids']);
        foreach ($parts as $p) {
            $id = (int)trim($p);
            if ($id > 0) $templateIds[$id] = true;
        }
    }
}
$templateIds = array_keys($templateIds);

// Se pediu multi, usa MODO 2
if (!empty($templateIds)) {
    $saida = [
        'globais' => carregarGlobaisDosTemplates($mysqli, $templateIds),
        'templates' => [],
    ];

    // nome do template + campos exclusivos
    $stmtT = $mysqli->prepare("SELECT id, nome FROM documento_templates WHERE id IN (" . implode(',', array_fill(0, count($templateIds), '?')) . ")");
    if ($stmtT) {
        $stmtT->bind_param(str_repeat('i', count($templateIds)), ...$templateIds);
        $stmtT->execute();
        $rt = $stmtT->get_result();

        $nomes = [];
        while ($t = $rt->fetch_assoc()) $nomes[(int)$t['id']] = (string)$t['nome'];
        $stmtT->close();

        foreach ($templateIds as $tid) {
            $saida['templates'][] = [
                'id' => (int)$tid,
                'nome' => $nomes[(int)$tid] ?? ('Template #' . (int)$tid),
                'campos' => carregarCamposDoTemplate($mysqli, (int)$tid),
            ];
        }
    }

    echo json_encode($saida);
    exit;
}

// MODO 1 (antigo)
if ($templateId <= 0) {
    echo json_encode([]);
    exit;
}

echo json_encode(carregarCamposDoTemplate($mysqli, $templateId));
