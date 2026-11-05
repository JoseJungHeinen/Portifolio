<?php
declare(strict_types=1);

use App\Services\PrazoService;
use App\Services\StatusService;

function uuid(): string
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

function money(float|int|string|null $valor): string
{
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

/** Troca UTF-8 inválido por "?" na entrada (evita erro 500 em preg/json); campos de senha nunca são alterados. */
function limpar_utf8(array $entrada): array
{
    foreach ($entrada as $chave => $valor) {
        if (is_string($chave) && preg_match('/senha|password/i', $chave)) {
            continue;
        }
        if (is_array($valor)) {
            $entrada[$chave] = limpar_utf8($valor);
        } elseif (is_string($valor) && !preg_match('//u', $valor)) {
            $entrada[$chave] = function_exists('mb_scrub') ? mb_scrub($valor, 'UTF-8') : (string)iconv('UTF-8', 'UTF-8//IGNORE', $valor);
        }
    }
    return $entrada;
}

function e(mixed $valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function url(string $caminho = ''): string
{
    return rtrim($_ENV['APP_URL'] ?? '', '/') . '/' . ltrim($caminho, '/');
}

function asset(string $caminho): string
{
    return url('public/assets/' . ltrim($caminho, '/'));
}

function redirect(string $caminho): never
{
    header('Location: ' . url($caminho));
    exit;
}

/** Caminho da requisição atual sem o prefixo de APP_URL (ex.: "/ofps/nova"). */
function rota_atual(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = rtrim((string)parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH), '/');
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    return '/' . ltrim($uri, '/');
}

function session_flash(string $chave, mixed $valor): void
{
    $_SESSION['_flash'][$chave] = $valor;
}

function flash(string $chave): mixed
{
    $valor = $_SESSION['_flash'][$chave] ?? null;
    unset($_SESSION['_flash'][$chave]);
    return $valor;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
}

function csrf_verify(): void
{
    $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), (string)$token)) {
        http_response_code(419);
        exit('<h2>Sessão expirada ou token inválido.</h2><p>Recarregue a página e tente novamente.</p>');
    }
}

function bcrypt_cost(): int
{
    return max(10, min(14, (int)($_ENV['BCRYPT_COST'] ?? 12)));
}

function auth(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

function can(string|array $perfis): bool
{
    $usuario = auth();
    return $usuario !== null && in_array($usuario['perfil'], (array)$perfis, true);
}

/** null = todas as unidades; [] = nenhuma; lista = ids permitidos. */
function unidades_permitidas(): ?array
{
    return array_key_exists('unidades', $_SESSION ?? []) ? $_SESSION['unidades'] : [];
}

function unidade_permitida(string $unidadeId): bool
{
    $ids = unidades_permitidas();
    return $ids === null || in_array($unidadeId, $ids, true);
}

/** @return array{0: string, 1: array} fragmento WHERE e parâmetros do escopo de unidades */
function sql_unidades(string $coluna): array
{
    $ids = unidades_permitidas();
    if ($ids === null) {
        return ['1=1', []];
    }
    if (!$ids) {
        return ['1=0', []];
    }
    return [$coluna . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_values($ids)];
}

function rota_inicial(string $perfil): string
{
    return in_array($perfil, ['estoque', 'comprador'], true) ? '/pendencias' : '/painel';
}

function date_br(?string $data): string
{
    if (!$data) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($data))->format('d/m/Y');
    } catch (Exception) {
        return '—';
    }
}

function datetime_br(?string $data): string
{
    if (!$data) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($data))->format('d/m/Y H:i');
    } catch (Exception) {
        return '—';
    }
}

/** Normaliza valor monetário BR ("1.500,00", "1500,00", "1500.00", "1500") para float. */
function parse_decimal(mixed $valor): float
{
    if (is_float($valor) || is_int($valor)) {
        return (float)$valor;
    }
    $v = trim((string)$valor);
    if ($v === '') {
        return 0.0;
    }
    if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $v)) {
        return (float)str_replace('.', '', $v);
    }
    if (str_contains($v, '.') && str_contains($v, ',')) {
        $v = str_replace(['.', ','], ['', '.'], $v);
    } elseif (str_contains($v, ',')) {
        $v = str_replace(',', '.', $v);
    }
    return (float)$v;
}

function status_label(string $status): string
{
    return StatusService::LABELS[$status] ?? $status;
}

function status_badge(string $status): string
{
    $cor = StatusService::CORES[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . $cor . '">' . e(status_label($status)) . '</span>';
}

function semaforo_badge(?string $prazoData): string
{
    $hoje = new DateTimeImmutable('today');
    $cor = PrazoService::semaforo($prazoData, $hoje);
    if ($cor === null) {
        return '<span class="text-muted">—</span>';
    }
    $dias = PrazoService::diasRestantes((string)$prazoData, $hoje);
    $texto = $dias < 0 ? abs($dias) . 'd atrasado' : ($dias === 0 ? 'vence hoje' : "{$dias}d");
    return '<span class="semaforo semaforo--' . $cor . '" title="Prazo: ' . e(date_br($prazoData)) . '">' . $texto . '</span>';
}

function rotulo_campo(string $campo): string
{
    $derivados = ['data_rejeicao' => 'Data da rejeição', 'data_aprovacao' => 'Data da aprovação', 'prazo_data' => 'Prazo'];
    return e(StatusService::CAMPOS[$campo]['label'] ?? (\App\Services\OfpService::ROTULOS[$campo] ?? ($derivados[$campo] ?? $campo)));
}

/** Valor do histórico formatado para tela (dinheiro, data, sim/não). */
function valor_campo(string $campo, ?string $valor): string
{
    if ($valor === null || $valor === '') {
        return '—';
    }
    $tipo = StatusService::CAMPOS[$campo]['tipo']
        ?? ($campo === 'valor_reclamado' ? 'money' : (str_starts_with($campo, 'data_') || $campo === 'prazo_data' ? 'date' : 'text'));
    return match ($tipo) {
        'money'    => money($valor),
        'date'     => date_br($valor),
        'checkbox' => $valor === '0' || $valor === '0.00' ? 'não' : 'sim',
        default    => e($valor),
    };
}
