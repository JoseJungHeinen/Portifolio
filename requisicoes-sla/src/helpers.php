<?php
// @versao Ver.011.301026

if (!function_exists('uuid')) {
    function uuid(): string {
        // random_int() usa CSPRNG (estado não recuperável), ao contrário de
        // mt_rand(). UUIDs/tokens passam a ser imprevisíveis.
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

if (!function_exists('money')) {
    function money(float|int|string $value): string {
        return 'R$ ' . number_format((float)$value, 2, ',', '.');
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): void {
        $base = rtrim($_ENV['APP_URL'] ?? '', '/');
        header('Location: ' . $base . $path);
        exit;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string {
        // ASSET_URL: o Requisições serve CSS/JS da pasta do Compras (/compras/public/...)
        $base = rtrim(($_ENV['ASSET_URL'] ?? '') ?: ($_ENV['APP_URL'] ?? ''), '/');
        return $base . '/public/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string {
        $base = rtrim($_ENV['APP_URL'] ?? '', '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('session_get')) {
    function session_get(string $key, mixed $default = null): mixed {
        return $_SESSION[$key] ?? $default;
    }
}

if (!function_exists('session_set')) {
    function session_set(string $key, mixed $value): void {
        $_SESSION[$key] = $value;
    }
}

if (!function_exists('session_flash')) {
    function session_flash(string $key, mixed $value): void {
        $_SESSION['_flash'][$key] = $value;
    }
}

if (!function_exists('flash')) {
    function flash(string $key): mixed {
        $val = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $val;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(): void {
        $token = $_POST['_csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';
        if (!hash_equals(csrf_token(), $token)) {
            http_response_code(403); // 419 não é padrão: o Apache devolvia 500
            die('<h2>Sessão expirada ou token inválido.</h2><p>Recarregue a página e tente novamente.</p>');
        }
    }
}

if (!function_exists('upload_mime_valido')) {
    /**
     * Valida o MIME REAL de um arquivo enviado (via finfo/magic bytes) contra a
     * extensão declarada. Não confia na extensão nem no Content-Type do cliente.
     * Para imagens/PDF a checagem é estrita; formatos Office (ZIP/CDF) toleram
     * os MIMEs genéricos que o finfo costuma reportar, evitando falsos negativos.
     */
    function upload_mime_valido(string $tmpPath, string $ext): bool {
        $mapa = [
            'pdf'  => ['application/pdf'],
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
            'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage', 'application/octet-stream'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        ];
        if (!isset($mapa[$ext]) || !is_file($tmpPath)) return false;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: '';
        if (in_array($mime, $mapa[$ext], true)) return true;

        // Fallback para imagens: getimagesize lê o cabeçalho real da imagem e
        // é mais tolerante que a libmagic (que varia entre servidores). Um
        // executável/PHP renomeado não passa por aqui, então a proteção se mantém.
        $imgTypes = [
            'jpg'  => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG,
            'png'  => IMAGETYPE_PNG,  'gif'  => IMAGETYPE_GIF,
            'webp' => IMAGETYPE_WEBP,
        ];
        if (isset($imgTypes[$ext])) {
            $info = @getimagesize($tmpPath);
            if ($info !== false && ($info[2] ?? null) === $imgTypes[$ext]) return true;
        }
        return false;
    }
}

if (!function_exists('auth')) {
    function auth(): ?array {
        return $_SESSION['usuario'] ?? null;
    }
}

if (!function_exists('can')) {
    function can(string|array $perfis): bool {
        $user = auth();
        if (!$user) return false;
        $perfis = (array)$perfis;
        return in_array($user['perfil'], $perfis, true);
    }
}

if (!function_exists('sistema')) {
    /**
     * Qual porta de entrada está servindo a requisição: 'requisicoes' quando o .env
     * do Exemplo Requisições (APP_SISTEMA=requisicoes) foi carregado, senão 'compras'.
     * Spec: docs/superpowers/specs/2026-10-28-sistema-requisicoes-design.md
     */
    function sistema(): string {
        return ($_ENV['APP_SISTEMA'] ?? '') === 'requisicoes' ? 'requisicoes' : 'compras';
    }
}

if (!function_exists('sistema_marca')) {
    /** Assinatura bicolor ("Exemplo" + nome), subtítulo do login e ícone da marca. */
    function sistema_marca(): array {
        return sistema() === 'requisicoes'
            ? ['nome' => 'requisições', 'sub' => 'Requisições e Aprovações de Compra', 'icone' => 'bi-clipboard-check-fill']
            : ['nome' => 'compras',     'sub' => 'Gestão Integrada de Suprimentos',    'icone' => 'bi-bag-check-fill'];
    }
}

if (!function_exists('rota_permitida_no_sistema')) {
    /**
     * No Requisições só o fluxo de compras responde; o resto é 404 (Router::dispatch).
     * No Compras tudo é permitido. Lista única — conferir aqui ao criar rota nova do fluxo.
     */
    function rota_permitida_no_sistema(string $uri): bool {
        if (sistema() !== 'requisicoes') return true;
        // S4 (spec 2026-10-30): sem estoque no Requisições — saldo de estoque e
        // comparativo NF × estoque ficam fora, mesmo dentro de prefixos liberados.
        $path = (string)parse_url($uri, PHP_URL_PATH);
        if ($path === '/api/estoque' || str_starts_with($path, '/api/estoque/')
            || preg_match('#^/fiscal/[^/]+/comparativo-estoque$#', $path)) {
            return false;
        }
        $prefixos = [
            // fluxo
            '/solicitacoes', '/requisicoes', '/aprovacoes', '/compras/cotacoes', '/compras/ordens',
            '/fiscal', '/fornecedores', '/orcamentos/solicitacoes', '/painel',
            // cadastros de apoio ao fluxo (só admin — conferido no ConfiguracaoController);
            // perfis, grupos, parâmetros, integrações e /usuarios continuam fora
            '/configuracoes/filiais', '/configuracoes/setores', '/configuracoes/gestores', '/configuracoes/alcadas',
            // pública: link de cotação enviado ao fornecedor
            '/cotacao/responder',
            // base
            '/login', '/logout', '/senha', '/anexos', '/api', '/dashboard/notificacoes',
        ];
        foreach ($prefixos as $p) {
            if ($uri === $p || str_starts_with($uri, $p . '/')) return true;
        }
        return false;
    }
}

if (!function_exists('rota_atual')) {
    /**
     * REQUEST_URI sem o caminho do APP_URL (ex.: /requisicoes/aprovacoes?x=1 → /aprovacoes?x=1).
     * Para o menu marcar o item ativo igual nas duas portas de entrada.
     */
    function rota_atual(): string {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $base = rtrim((string)parse_url($_ENV['APP_URL'] ?? '', PHP_URL_PATH), '/');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        return '/' . ltrim($uri, '/');
    }
}

if (!function_exists('rota_inicial')) {
    /**
     * Rota de entrada por perfil. Solicitante e almoxarife não têm acesso
     * ao Dashboard Executivo (dados financeiros/gerenciais) e caem direto
     * no módulo que efetivamente usam no dia a dia. O Requisições não tem
     * Dashboard: cada perfil entra na sua etapa do fluxo; comprador e admin
     * entram no Painel de compras.
     */
    function rota_inicial(string $perfil): string {
        if (sistema() === 'requisicoes') {
            return match ($perfil) {
                'solicitante'        => '/solicitacoes',
                'gestor', 'diretor'  => '/aprovacoes',
                'comprador', 'admin' => '/painel',
                default             => '/requisicoes',
            };
        }
        return match ($perfil) {
            'solicitante' => '/requisicoes',
            'almoxarife'  => '/estoque',
            default       => '/dashboard',
        };
    }
}

if (!function_exists('is_matriz_user')) {
    /**
     * True se o usuário logado pertence à filial matriz (filiais.is_matriz = 1).
     * Admin é sempre tratado como matriz. Usado no módulo de Brindes para separar
     * quem gerencia (matriz) de quem só dá baixa (lojas).
     */
    function is_matriz_user(): bool {
        $user = auth();
        if (!$user) return false;
        if (($user['perfil'] ?? '') === 'admin') return true;
        if (empty($user['filial_id'])) return false;
        try {
            $db = \App\Config\Database::getInstance();
            $stmt = $db->prepare("SELECT is_matriz FROM filiais WHERE id = ? LIMIT 1");
            $stmt->execute([$user['filial_id']]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('podeModulo')) {
    /**
     * Verifica se o usuário atual pode acessar um módulo com nível mínimo.
     * Override por usuário tem prioridade sobre o padrão do perfil.
     * Níveis (crescente): nenhum < ver < criar < aprovar < total
     * Admin sempre tem acesso total.
     */
    function podeModulo(string $modulo, string $nivelMin = 'ver'): bool {
        $user = auth();
        if (!$user) return false;
        if ($user['perfil'] === 'admin') return true;

        static $cacheUser   = [];
        static $cachePerfil = [];

        $uid    = $user['id'];
        $perfil = $user['perfil'];

        try {
            $db = \App\Config\Database::getInstance();

            // Permissões específicas do usuário
            if (!array_key_exists($uid, $cacheUser)) {
                $cacheUser[$uid] = [];
                $stmt = $db->prepare("SELECT modulo, acesso FROM permissoes_usuario WHERE usuario_id = ?");
                $stmt->execute([$uid]);
                foreach ($stmt->fetchAll() as $r) {
                    $cacheUser[$uid][$r['modulo']] = $r['acesso'];
                }
            }

            // Permissões padrão do perfil
            if (!array_key_exists($perfil, $cachePerfil)) {
                $cachePerfil[$perfil] = [];
                $stmt = $db->prepare("SELECT modulo, acesso FROM permissoes_perfil WHERE perfil = ?");
                $stmt->execute([$perfil]);
                foreach ($stmt->fetchAll() as $r) {
                    $cachePerfil[$perfil][$r['modulo']] = $r['acesso'];
                }
            }
        } catch (\Throwable $e) { return false; }

        // Override do usuário tem prioridade; fallback para o perfil
        $acesso = $cacheUser[$uid][$modulo] ?? $cachePerfil[$perfil][$modulo] ?? 'nenhum';

        $h = ['nenhum' => 0, 'ver' => 1, 'criar' => 2, 'aprovar' => 3, 'total' => 4];
        return ($h[$acesso] ?? 0) >= ($h[$nivelMin] ?? 1);
    }
}

if (!function_exists('permissoes_catalogo')) {
    /** Catálogo de permissões (src/Config/permissoes.php): grupos → chaves. */
    function permissoes_catalogo(): array {
        static $catalogo = null;
        return $catalogo ??= require ROOT . '/src/Config/permissoes.php';
    }
}

if (!function_exists('permissoes_chaves')) {
    /** Todas as chaves do catálogo, na ordem da tela. */
    function permissoes_chaves(): array {
        $chaves = [];
        foreach (permissoes_catalogo() as $grupo) {
            foreach (array_keys($grupo['itens']) as $chave) {
                $chaves[] = $chave;
            }
        }
        return $chaves;
    }
}

if (!function_exists('pode')) {
    /**
     * Permissão sim/não do catálogo para o usuário logado
     * (exceção do usuário > padrão do cargo; admin sempre pode).
     */
    function pode(string $chave): bool {
        return podeModulo($chave, 'ver');
    }
}

if (!function_exists('ve_todas_filiais')) {
    /**
     * O usuário logado vê dados de todas as filiais? Diretor/admin sempre; os
     * demais quando o cadastro tem "Acesso a todas as filiais" (usuarios.todas_filiais,
     * migration 067). Lido do banco — e não da sessão — para valer sem novo login.
     * Só visualização: agir em nome de outra filial continua com diretor/admin.
     */
    function ve_todas_filiais(): bool {
        if (can(['diretor', 'admin'])) return true;
        $user = auth();
        if (!$user) return false;

        static $cache = [];
        if (!array_key_exists($user['id'], $cache)) {
            try {
                $stmt = \App\Config\Database::getInstance()->prepare("SELECT todas_filiais FROM usuarios WHERE id = ?");
                $stmt->execute([$user['id']]);
                $cache[$user['id']] = (bool)$stmt->fetchColumn();
            } catch (\Throwable $e) {
                $cache[$user['id']] = false; // coluna ainda não criada (migration 067)
            }
        }
        return $cache[$user['id']];
    }
}

if (!function_exists('tiposRequisicaoPermitidos')) {
    /**
     * Tipos de requisição que o usuário logado pode criar, na ordem dos botões
     * do formulário — o primeiro é o tipo padrão de uma requisição nova.
     *
     * Cada tipo é uma sub-chave do módulo requisicoes (migration 052). Esta é a
     * única fonte da verdade sobre tipo: substitui o antigo can(['comprador',
     * 'admin']) que restringia só a Compra.
     *
     * S4 (spec 2026-10-30): o Requisições não tem estoque — interna e
     * transferência saíram; só Compra e Ordem de Compra.
     *
     * @return string[] subconjunto ordenado de ['compra','ordem_compra']
     */
    function tiposRequisicaoPermitidos(): array {
        return array_values(array_filter(
            \App\Models\Requisicao::TIPOS_COMPRA,
            fn(string $t) => podeModulo("requisicoes.$t")
        ));
    }
}

if (!function_exists('date_br')) {
    function date_br(?string $date): string {
        if (!$date) return '—';
        try {
            return (new DateTime($date))->format('d/m/Y');
        } catch (Exception) {
            return '—';
        }
    }
}

if (!function_exists('datetime_br')) {
    function datetime_br(?string $date): string {
        if (!$date) return '—';
        try {
            return (new DateTime($date))->format('d/m/Y H:i');
        } catch (Exception) {
            return '—';
        }
    }
}

if (!function_exists('percent_bar_class')) {
    function percent_bar_class(float $pct): string {
        if ($pct >= 90) return 'bg-danger';
        if ($pct >= 75) return 'bg-warning';
        return 'bg-success';
    }
}

if (!function_exists('parse_decimal')) {
    /**
     * Normaliza valor monetário brasileiro para float.
     * Aceita: "1.500,00", "1500,00", "1500.00", "1500"
     */
    function parse_decimal(mixed $value): float {
        if (is_float($value) || is_int($value)) return (float)$value;
        $v = trim((string)$value);
        if ($v === '' || $v === null) return 0.0;
        // Se tem ponto E vírgula: formato BR (1.500,00)
        if (str_contains($v, '.') && str_contains($v, ',')) {
            $v = str_replace('.', '', $v);  // remove separador de milhar
            $v = str_replace(',', '.', $v); // vírgula → ponto decimal
        } elseif (str_contains($v, ',')) {
            // Só vírgula: pode ser decimal BR (1500,00) ou milhar US
            // Assume decimal BR
            $v = str_replace(',', '.', $v);
        }
        return (float)$v;
    }
}

if (!function_exists('status_badge')) {
    function status_badge(string $status): string {
        $map = [
            'pendente'          => 'warning',
            'em_aprovacao'      => 'info',
            'aprovado'          => 'success',
            'rejeitado'         => 'danger',
            'cancelado'         => 'secondary',
            'cancelada'         => 'secondary',
            'ativo'             => 'success',
            'vencido'           => 'danger',
            'esgotado'          => 'danger',
            'recebido_total'    => 'success',
            'recebido_parcial'  => 'warning',
            'divergente'        => 'danger',
            'aberta'            => 'success',
            'aguardando_respostas' => 'warning',
            'encerrada'         => 'secondary',
        ];
        $color = $map[$status] ?? 'secondary';
        $label = ucfirst(str_replace('_', ' ', $status));
        return "<span class=\"badge bg-{$color}\">{$label}</span>";
    }
}

if (!function_exists('formatar_numero_requisicao')) {
    function formatar_numero_requisicao(?string $tipo, string $criadoEm, int $numero): string {
        $prefixo = $tipo === 'transferencia' ? 'TRF' : 'REQ';
        return $prefixo . '-' . date('Y', strtotime($criadoEm)) . '-' . str_pad((string)$numero, 5, '0', STR_PAD_LEFT);
    }
}
/**
 * Formata CNPJ
 */
function formatCnpj(string $cnpj): string
{
    $cnpj = preg_replace('/\D/', '', $cnpj);
    return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpj);
}

if (!function_exists('sql_escopo_in')) {
    /**
     * Monta a cláusula de escopo de uma coluna a partir de uma lista de ids.
     *
     *   null  → sem restrição (ex.: diretor/admin veem tudo)
     *   []    → escopo vazio: AND 1=0 (nunca "IN ()", que é erro de sintaxe)
     *   [ids] → AND coluna IN (?, ?, ...)
     *
     * @param  array<int, string>|null $ids
     * @return array{0: string, 1: array<int, string>} [cláusula, parâmetros]
     */
    function sql_escopo_in(string $coluna, ?array $ids): array {
        if ($ids === null)  return ['', []];
        if (count($ids) === 0) return [' AND 1=0', []];

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        return [" AND {$coluna} IN ({$placeholders})", array_values($ids)];
    }
}

if (!function_exists('sql_escopo_pares')) {
    /**
     * Igual a sql_escopo_in(), mas para escopo de PAR unidade+setor: monta um OR
     * de "(filial = ? AND setor = ?)". Usado onde a tabela tem as duas colunas
     * (requisicoes) — quem só tem filial_id continua no sql_escopo_in().
     *
     *   null    → sem restrição
     *   []      → escopo vazio: AND 1=0
     *   [pares] → AND ((f = ? AND s = ?) OR (f = ? AND s = ?) ...)
     *
     * @param  array<int, array{filial_id: string, setor_id: string}>|null $pares
     * @return array{0: string, 1: array<int, string>} [cláusula, parâmetros]
     */
    function sql_escopo_pares(string $colFilial, string $colSetor, ?array $pares): array {
        if ($pares === null)      return ['', []];
        if (count($pares) === 0)  return [' AND 1=0', []];

        $ors    = [];
        $params = [];
        foreach ($pares as $par) {
            $ors[]    = "({$colFilial} = ? AND {$colSetor} = ?)";
            $params[] = $par['filial_id'];
            $params[] = $par['setor_id'];
        }

        return [' AND (' . implode(' OR ', $ors) . ')', $params];
    }
}

