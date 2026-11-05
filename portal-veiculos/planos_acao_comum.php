<?php
/**
 * planos_acao_comum.php — regras compartilhadas do módulo Planos de Ação.
 * Uso: require_once __DIR__ . '/planos_acao_comum.php'; pa_guard(); (páginas)
 *      pa_guard(true);                                     (AJAX/JSON)
 */
require_once __DIR__ . '/config_database.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$mysqli->set_charset('utf8mb4');

const PA_DIAS_SEM_ATUALIZACAO = 30;
if (empty($_SESSION['pa_csrf'])) $_SESSION['pa_csrf'] = bin2hex(random_bytes(16));

function pa_guard(bool $ajax = false): void
{
    if (!empty($_SESSION['logado']) && ($_SESSION['is_admin'] || (int)(pa_perm('menu_planos_acao') ?? 1) === 1)) return;
    if ($ajax) {
        http_response_code(empty($_SESSION['logado']) ? 401 : 403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'msg' => 'Sem acesso aos Planos de Ação.']);
    } else {
        header('Location: /Veiculos/index.php');
    }
    exit;
}

/** Valor de uma coluna de usuarios_permissoes para o usuário logado (cache em sessão). */
function pa_perm(string $col): ?int
{
    global $mysqli;
    $perms = $_SESSION['perms'] ?? [];
    if (isset($perms[$col])) return (int)$perms[$col];
    if (!preg_match('/^[a-z_]+$/', $col)) return null;
    $uid = pa_uid();
    $r = $mysqli->query("SELECT `$col` v FROM usuarios_permissoes WHERE usuario_id = $uid");
    $row = $r ? $r->fetch_assoc() : null;
    return $row ? (int)$row['v'] : null;
}

function pa_uid(): int      { return (int)($_SESSION['usuario_id'] ?? 0); }
/** Admin do módulo = permissão própria (ser admin do Veículos não basta: senão vê planos de todos). */
function pa_eh_admin(): bool { return pa_perm('planos_acao_admin') === 1; }
/** Valida conclusão/encerramento dos planos: SÓ quem tem a permissão planos_acao_diretoria (nem admin entra sozinho). */
function pa_eh_diretoria(): bool { return pa_perm('planos_acao_diretoria') === 1; }

/** Status em que o plano ainda está "aberto" (conta prazo e gera avisos). */
const PA_ABERTOS = ['nao_iniciado', 'em_andamento'];

/** Setores do usuário: [setor_id => 'membro'|'gestor']. Admin não precisa (vê tudo). */
function pa_setores_usuario(): array
{
    global $mysqli;
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $uid = pa_uid();
    $r = $mysqli->query("SELECT setor_id, papel FROM pa_usuario_setor WHERE usuario_id = $uid");
    while ($r && $row = $r->fetch_assoc()) $cache[(int)$row['setor_id']] = $row['papel'];
    return $cache;
}

function pa_eh_gestor(int $setorId): bool
{
    return pa_eh_admin() || (pa_setores_usuario()[$setorId] ?? '') === 'gestor';
}

/**
 * Condição SQL de visibilidade sobre pa_planos (alias p).
 * Admin/Diretoria: tudo. Gestor: todos os planos dos setores que gerencia.
 * Membro: só os planos em que é responsável, atribuído ou tem alguma ação.
 */
function pa_sql_visibilidade(string $p = 'p'): string
{
    if (pa_eh_admin() || pa_eh_diretoria()) return '1=1';   // Diretoria enxerga tudo para validar
    $uid = pa_uid();
    $ids = array_keys(array_filter(pa_setores_usuario(), fn($papel) => $papel === 'gestor'));
    $porSetor = $ids ? "$p.setor_id IN (" . implode(',', array_map('intval', $ids)) . ") OR " : '';
    return "($porSetor $p.responsavel_id = $uid OR $p.atribuido_id = $uid OR EXISTS (SELECT 1 FROM pa_acoes a WHERE a.plano_id = $p.id AND a.responsavel_id = $uid))";
}

/** Pode editar o plano? admin, gestor do setor ou responsável. */
function pa_pode_editar(array $plano): bool
{
    return pa_eh_gestor((int)$plano['setor_id']) || (int)$plano['responsavel_id'] === pa_uid()
        || (int)($plano['atribuido_id'] ?? 0) === pa_uid();
}

/** Calcula "Para" a partir da base e da meta. Base 0 + percentual não gera alvo (exige valor). */
function pa_calcular_alvo(string $tipo, string $sentido, float $base, float $meta): ?float
{
    if ($tipo === 'percentual' && $base == 0.0) return null;
    $delta = $tipo === 'percentual' ? $base * $meta / 100 : $meta;
    return $sentido === 'aumento' ? $base + $delta : $base - $delta;
}

/** Enriquecimento: progresso, ações e avisos. $p já traz ultimo_valor/ultima_data/qtd_acoes/acoes_ok. */
function pa_enriquecer(array $p): array
{
    $hoje = new DateTimeImmutable('today');
    $p['progresso'] = null;
    $p['meta_atingida'] = false;

    if ((int)$p['tem_meta'] === 1 && $p['ultimo_valor'] !== null) {
        $base = (float)$p['valor_base']; $alvo = (float)$p['valor_alvo']; $real = (float)$p['ultimo_valor'];
        if ($alvo != $base) $p['progresso'] = round(($real - $base) / ($alvo - $base) * 100, 1);
        $p['meta_atingida'] = $p['sentido'] === 'aumento' ? $real >= $alvo : $real <= $alvo;
    }
    // Sem meta: progresso = ações concluídas / total
    if ((int)$p['tem_meta'] === 0 && (int)$p['qtd_acoes'] > 0) {
        $p['progresso'] = round((int)$p['acoes_ok'] / (int)$p['qtd_acoes'] * 100, 1);
    }

    $avisos = [];
    $p['atrasado'] = false;
    if ($p['status'] === 'aguardando_validacao') {
        $avisos[] = ['tipo' => 'validar', 'msg' => 'Aguardando validação ' . (['encerrado' => 'do encerramento', 'cancelado' => 'do cancelamento'][$p['validacao_destino'] ?? ''] ?? 'da conclusão') . '.'];
    }
    if ($p['validacao'] === 'devolvida' && in_array($p['status'], PA_ABERTOS, true)) {
        $avisos[] = ['tipo' => 'devolvido', 'msg' => 'Devolvido pela Diretoria' . ($p['parecer'] ? ': ' . $p['parecer'] : '.')];
    }
    if (in_array($p['status'], PA_ABERTOS, true)) {
        // Sem prazo definido: não gera avisos de vencimento
        $diasPrazo = $p['prazo'] ? (int)$hoje->diff(new DateTimeImmutable($p['prazo']))->format('%r%a') : null;
        if ($diasPrazo !== null && $diasPrazo < 0) { $p['atrasado'] = true; $avisos[] = ['tipo' => 'vencido', 'msg' => 'VENCIDO há ' . abs($diasPrazo) . ' dia(s) — conclua o plano.']; }
        elseif ($diasPrazo !== null && $diasPrazo <= 2)  $avisos[] = ['tipo' => 'critico', 'msg' => "CRÍTICO — prazo em $diasPrazo dia(s)."];
        elseif ($diasPrazo !== null && $diasPrazo <= 5)  $avisos[] = ['tipo' => 'alerta',  'msg' => "ALERTA — prazo em $diasPrazo dias."];
        elseif ($diasPrazo !== null && $diasPrazo <= 10) $avisos[] = ['tipo' => 'aviso',   'msg' => "AVISO — prazo em $diasPrazo dias."];

        // Desatualização: usa a data de referência do último lançamento (e a última ação mexida), não a de gravação
        $ref = max($p['ultima_data'] ?? '', substr($p['ultima_acao_em'] ?? '', 0, 10));
        if ((int)$p['tem_meta'] === 1) {
            if ($ref === '') $avisos[] = ['tipo' => 'atualizar', 'msg' => 'Nenhum acompanhamento lançado.'];
            elseif (($d = (int)$hoje->diff(new DateTimeImmutable($ref))->format('%a')) > PA_DIAS_SEM_ATUALIZACAO)
                $avisos[] = ['tipo' => 'atualizar', 'msg' => "Sem atualização há $d dias."];
        }
        if ((int)$p['acoes_atrasadas'] > 0) $avisos[] = ['tipo' => 'atraso_acao', 'msg' => (int)$p['acoes_atrasadas'] . ' ação(ões) atrasada(s).'];
        if ($p['meta_atingida'])           $avisos[] = ['tipo' => 'atingida', 'msg' => 'Meta atingida! Valide e conclua.'];
        if ((int)$p['qtd_acoes'] > 0 && (int)$p['acoes_ok'] === (int)$p['qtd_acoes'] && (int)$p['tem_meta'] === 0)
            $avisos[] = ['tipo' => 'atingida', 'msg' => 'Todas as ações concluídas — conclua o plano.'];
    }
    $p['avisos'] = $avisos;
    return $p;
}

/** Base do SELECT de planos com agregados (aplicar WHERE/ORDER por fora). */
function pa_sql_base(): string
{
    return "SELECT p.*, s.nome AS setor_nome, COALESCE(u.nome, p.responsavel_texto) AS responsavel_nome, COALESCE(ua.nome, p.atribuido_texto) AS atribuido_nome,
        l.nome AS loja_nome, uv.nome AS validador_nome,
        (SELECT a.valor    FROM pa_acompanhamentos a WHERE a.plano_id = p.id ORDER BY a.data_ref DESC LIMIT 1) AS ultimo_valor,
        (SELECT a.data_ref FROM pa_acompanhamentos a WHERE a.plano_id = p.id ORDER BY a.data_ref DESC LIMIT 1) AS ultima_data,
        (SELECT MAX(x.atualizado_em) FROM pa_acoes x WHERE x.plano_id = p.id) AS ultima_acao_em,
        (SELECT COUNT(*) FROM pa_acoes x WHERE x.plano_id = p.id AND x.status <> 'cancelada') AS qtd_acoes,
        (SELECT COUNT(*) FROM pa_acoes x WHERE x.plano_id = p.id AND x.status = 'concluida')  AS acoes_ok,
        (SELECT COUNT(*) FROM pa_acoes x WHERE x.plano_id = p.id AND x.status IN ('pendente','em_andamento') AND x.prazo < CURDATE()) AS acoes_atrasadas
      FROM pa_planos p
      JOIN pa_setores s ON s.id = p.setor_id
      LEFT JOIN usuarios u ON u.id = p.responsavel_id
      LEFT JOIN usuarios ua ON ua.id = p.atribuido_id
      LEFT JOIN usuarios uv ON uv.id = p.validado_por
      LEFT JOIN lojas l ON l.id = p.loja_id";
}

function pa_log(int $planoId, string $evento, ?string $detalhe = null, ?int $acaoId = null): void
{
    global $mysqli;
    $uid = pa_uid();
    $st = $mysqli->prepare('INSERT INTO pa_historico (plano_id, acao_id, usuario_id, evento, detalhe) VALUES (?,?,?,?,?)');
    $st->bind_param('iiiss', $planoId, $acaoId, $uid, $evento, $detalhe);
    $st->execute();
}

function pa_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
