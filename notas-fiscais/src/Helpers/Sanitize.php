<?php
// @versao Sys_19 V_01_16/07/2026
declare(strict_types=1);

/**
 * Helper de sanitização e validação de entrada.
 *
 * Regras:
 * - Validação server-side obrigatória em 100% dos campos
 * - Whitelist por campo — cada método aceita apenas o formato esperado
 * - Dados inválidos lançam InvalidArgumentException — nunca são silenciados
 * - Nunca confiar em dado vindo do cliente
 */
class Sanitize
{
    /**
     * Sanitiza texto livre: remove tags HTML, aplica trim e limita o comprimento.
     *
     * @param string $val     Valor bruto
     * @param int    $maxLen  Comprimento máximo em caracteres (padrão 255)
     * @return string Texto limpo — pronto para armazenar no banco
     * @throws InvalidArgumentException se exceder o comprimento máximo
     */
    public static function text(string $val, int $maxLen = 255): string
    {
        $val = trim(strip_tags($val));
        if (mb_strlen($val) > $maxLen) {
            throw new InvalidArgumentException(
                "Campo excede o limite de {$maxLen} caracteres (recebido: " . mb_strlen($val) . ")."
            );
        }
        return $val;
    }

    /**
     * Valida e retorna um inteiro.
     *
     * @throws InvalidArgumentException se o valor não for um inteiro válido
     */
    public static function int(mixed $val): int
    {
        $v = filter_var($val, FILTER_VALIDATE_INT);
        if ($v === false) {
            throw new InvalidArgumentException("Valor inválido — esperado inteiro, recebido: " . print_r($val, true));
        }
        return $v;
    }

    /**
     * Valida e retorna um inteiro positivo (> 0).
     * Usado para validar IDs numéricos e quantidades.
     *
     * @throws InvalidArgumentException se o valor não for inteiro positivo
     */
    public static function positiveInt(mixed $val): int
    {
        $v = self::int($val);
        if ($v <= 0) {
            throw new InvalidArgumentException("Valor deve ser um inteiro positivo, recebido: {$v}.");
        }
        return $v;
    }

    /**
     * Valida e retorna um UUID (qualquer versão, char(36)).
     * Aceita UUIDs v1-v5 e IDs personalizados no formato UUID.
     * Usado para validar IDs primários (char(36)).
     *
     * @throws InvalidArgumentException se não for UUID válido
     */
    public static function uuid(string $val): string
    {
        $val = trim($val);
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        if (!preg_match($pattern, $val)) {
            throw new InvalidArgumentException("UUID inválido: {$val}");
        }
        return strtolower($val);
    }

    /**
     * Valida e retorna um endereço de e-mail.
     *
     * @throws InvalidArgumentException se o e-mail for inválido
     */
    public static function email(string $val): string
    {
        $val = trim($val);
        $filtered = filter_var($val, FILTER_VALIDATE_EMAIL);
        if ($filtered === false) {
            throw new InvalidArgumentException("E-mail inválido: {$val}");
        }
        return $filtered;
    }

    /**
     * Valida e retorna um valor decimal positivo (para valores monetários).
     *
     * @throws InvalidArgumentException se o valor não for um decimal positivo
     */
    public static function decimal(mixed $val): float
    {
        // Formato BR: ponto = milhar, vírgula = decimal → remover milhar, trocar decimal
        if (is_string($val)) {
            $val = str_replace('.', '', $val);
            $val = str_replace(',', '.', $val);
        }
        $v = filter_var($val, FILTER_VALIDATE_FLOAT);
        if ($v === false || $v < 0) {
            throw new InvalidArgumentException("Valor monetário inválido: " . print_r($val, true));
        }
        return round($v, 2);
    }

    /**
     * Valida e retorna uma data no formato Y-m-d.
     *
     * @throws InvalidArgumentException se a data for inválida ou no formato errado
     */
    public static function date(string $val): string
    {
        $val = trim($val);
        // Aceitar formato brasileiro d/m/Y e converter para Y-m-d
        if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $val)) {
            [$d, $m, $y] = explode('/', $val);
            $val = "{$y}-{$m}-{$d}";
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            throw new InvalidArgumentException("Formato de data inválido (esperado Y-m-d): {$val}");
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $val);
        if (!$dt || $dt->format('Y-m-d') !== $val) {
            throw new InvalidArgumentException("Data inválida no calendário: {$val}");
        }
        return $val;
    }

    /**
     * Valida que o valor pertence a uma lista de opções permitidas (whitelist).
     *
     * @param string   $val     Valor a validar
     * @param string[] $allowed Lista de valores aceitos
     * @throws InvalidArgumentException se o valor não estiver na lista
     */
    public static function enum(string $val, array $allowed): string
    {
        $val = trim($val);
        if (!in_array($val, $allowed, true)) {
            throw new InvalidArgumentException(
                "Valor não permitido: '{$val}'. Opções válidas: " . implode(', ', $allowed)
            );
        }
        return $val;
    }

    /**
     * Lê apenas as chaves permitidas do $_POST e retorna um array seguro.
     * Chaves ausentes em $_POST retornam string vazia por padrão.
     *
     * @param string[] $keys Lista de chaves permitidas
     * @return array<string, string>
     */
    public static function fromPost(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = isset($_POST[$key]) ? (string) $_POST[$key] : '';
        }
        return $result;
    }

    /**
     * Lê apenas as chaves permitidas do $_GET e retorna um array seguro.
     * Chaves ausentes em $_GET retornam string vazia por padrão.
     *
     * @param string[] $keys Lista de chaves permitidas
     * @return array<string, string>
     */
    public static function fromGet(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = isset($_GET[$key]) ? (string) $_GET[$key] : '';
        }
        return $result;
    }

    /**
     * Escapa uma string para saída segura em HTML.
     * Use sempre que exibir dados do usuário em views.
     *
     * @param mixed $val Valor a escapar
     */
    public static function html(mixed $val): string
    {
        return htmlspecialchars((string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Atalho global para escapar saída HTML nas views.
 * Equivalente a Sanitize::html() — uso: <?= h($var) ?>
 */
function h(mixed $val): string
{
    return htmlspecialchars((string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formatarDoc(string $doc): string
{
    $d = preg_replace('/\D/', '', $doc);
    if (strlen($d) === 14) {
        return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d);
    }
    if (strlen($d) === 11) {
        return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d);
    }
    return $doc;
}

/**
 * Monta o link para o detalhe do processo, carregando a página atual
 * (com todos os filtros aplicados) para o botão "Voltar" do detalhe
 * retornar exatamente para onde o usuário estava.
 *
 * @param string $processoId UUID do processo
 * @param string $origem     Código curto da tela de origem (ver resolverVoltarProcesso)
 */
function linkDetalheProcesso(string $processoId, string $origem): string
{
    $voltar = $_SERVER['REQUEST_URI'] ?? '';
    return '?module=processos&action=detalhe&id=' . urlencode($processoId)
        . '&origem=' . urlencode($origem)
        . '&voltar=' . urlencode($voltar);
}

/**
 * Resolve a URL e o rótulo do botão "Voltar" da tela de detalhe do processo.
 *
 * "voltar" só é aceito quando aponta para uma rota interna do próprio app
 * (nunca um destino externo) — caso contrário, cai no padrão da origem.
 *
 * @return array{url: string, label: string}
 */
function resolverVoltarProcesso(string $origem, string $voltar): array
{
    $origens = [
        'tarefas'    => ['url' => '?module=tarefas&action=index',    'label' => 'Minhas Tarefas'],
        'processos'  => ['url' => '?module=processos&action=index',  'label' => 'Processos'],
        'financeiro' => ['url' => '?module=financeiro&action=index', 'label' => 'Financeiro'],
        'dashboard'  => ['url' => '?module=dashboard&action=index',  'label' => 'Dashboard'],
        'relatorios' => ['url' => '?module=relatorios&action=index', 'label' => 'Relatórios'],
    ];

    $destino = $origens[$origem] ?? $origens['processos'];

    // Aceita apenas caminhos relativos internos (ex: "/index.php?module=...",
    // "/notas/index.php?module=..."). Nunca um destino absoluto ou
    // protocol-relative ("//evil.com", "https://evil.com") — isso impediria
    // um link malicioso de usar o botão "Voltar" como redirecionamento aberto.
    $seguro = $voltar !== ''
        && str_starts_with($voltar, '/')
        && !str_starts_with($voltar, '//')
        && !str_contains($voltar, '://')
        && str_contains($voltar, '?module=');

    if ($seguro) {
        $destino['url'] = $voltar;
    }

    return $destino;
}
