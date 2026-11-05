<?php
// @versao Cap_07 V_02_04/08/2026
declare(strict_types=1);

/**
 * Cliente da API Brobot Platform V2.
 *
 * Base:  https://api.platform.brobot.com.br/api/v2
 * Auth:  header `token` (gerado em Definições > Usuários na plataforma Brobot)
 * Rota:  GET /dfe/fiscal_documents
 *
 * Uso:
 *   $c = BrobotClient::fromSettings();
 *   $r = $c->listarDocumentos(['per_page' => 50, 'page' => 1,
 *                              'q' => ['start_date' => '2026-07-01T00:00:00Z']]);
 *   if ($r['ok']) { foreach ($r['data'] as $doc) { ... } }
 *
 * Nunca lança para erro de rede/HTTP — devolve ['ok'=>false, 'erro'=>...] para
 * que o poller registre no log e siga (não derruba o agendador).
 */
class BrobotClient
{
    private string $baseUrl;
    private string $token;
    private int $timeout;
    private string $metodoXml;

    public function __construct(string $baseUrl, string $token, int $timeout = 30, string $metodoXml = 'xml_brobot')
    {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->token     = trim($token);
        $this->timeout   = max(5, $timeout);
        $this->metodoXml = $metodoXml ?: 'xml_brobot';
    }

    /** Constrói o cliente a partir das configs (grupo brobot). */
    public static function fromSettings(): self
    {
        return new self(
            (string) Settings::get('brobot.base_url', 'https://api.platform.brobot.com.br/api/v2'),
            (string) Settings::get('brobot.token', ''),
            Settings::getInt('brobot.timeout_seg', 30),
            (string) Settings::get('brobot.metodo_xml', 'xml_brobot')
        );
    }

    public function temToken(): bool
    {
        return $this->token !== '';
    }

    /**
     * GET /dfe/fiscal_documents
     *
     * @param array $opts {
     *   per_page?: int, page?: int, order?: 'asc'|'desc',
     *   q?: array{ start_date?:string, end_date?:string, key?:string|string[] }
     * }
     * @return array{ok:bool, status:int, data:array, meta:array, erro:?string}
     */
    public function listarDocumentos(array $opts = []): array
    {
        // A ordem é resolvida antes de entrar na lista: a versão anterior
        // validava `$opts['order'] ?? 'asc'` mas usava `$opts['order']` cru no
        // ramo verdadeiro — sem a chave, o valor virava null e o rawurlencode()
        // do get() estourava TypeError. Só não aparecia porque o poller e o
        // backfill sempre informam a ordem.
        $ordem = (string) ($opts['order'] ?? 'asc');
        if (!in_array($ordem, ['asc', 'desc'], true)) {
            $ordem = 'asc';
        }

        $params = [
            ['methods[]', $this->metodoXml],
            ['order[created_at]', $ordem],
            ['per_page', (string) (int) ($opts['per_page'] ?? 50)],
            ['page',     (string) (int) ($opts['page'] ?? 1)],
        ];

        $q = $opts['q'] ?? [];
        if (!empty($q['start_date'])) $params[] = ['q[start_date]', $q['start_date']];
        if (!empty($q['end_date']))   $params[] = ['q[end_date]',   $q['end_date']];
        if (!empty($q['key'])) {
            foreach ((array) $q['key'] as $k) {
                $params[] = ['q[key][]', $k];
            }
        }

        return $this->get('/dfe/fiscal_documents', $params);
    }

    /**
     * Testa a conexão: 1 documento apenas. Interpreta os códigos da Brobot.
     *
     * @return array{ok:bool, status:int, mensagem:string}
     */
    public function testarConexao(): array
    {
        if (!$this->temToken()) {
            return ['ok' => false, 'status' => 0, 'mensagem' => 'Token não configurado.'];
        }

        $r = $this->get('/dfe/fiscal_documents', [
            ['methods[]', $this->metodoXml],
            ['per_page', '1'],
            ['page', '1'],
        ]);

        if ($r['ok']) {
            $total = $r['meta']['total_count'] ?? '?';
            return ['ok' => true, 'status' => 200,
                    'mensagem' => "Conexão OK. Documentos disponíveis na conta: {$total}."];
        }

        $msg = match ($r['status']) {
            401     => 'Não autorizado (401): faça login/registre-se na Brobot ou revise a conta.',
            422     => 'Token inválido (422): "Acesso não autorizado!". Gere um novo token na Brobot.',
            0       => 'Falha de conexão: ' . ($r['erro'] ?? 'sem resposta') . '.',
            default => "Resposta inesperada (HTTP {$r['status']}): " . ($r['erro'] ?? ''),
        };
        return ['ok' => false, 'status' => $r['status'], 'mensagem' => $msg];
    }

    // ─────────────────────────────────────────────────────────────
    // HTTP interno
    // ─────────────────────────────────────────────────────────────

    /**
     * @param array<array{0:string,1:string}> $params pares [chave, valor] — permite chaves repetidas (methods[], q[key][])
     * @return array{ok:bool, status:int, data:array, meta:array, erro:?string}
     */
    private function get(string $path, array $params): array
    {
        $query = implode('&', array_map(
            fn($p) => rawurlencode($p[0]) . '=' . rawurlencode($p[1]),
            $params
        ));
        $url = $this->baseUrl . $path . ($query !== '' ? '?' . $query : '');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_HTTPHEADER     => [
                'token: ' . $this->token,
                'Accept: application/json',
            ],
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($body === false || $err !== null) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'meta' => [], 'erro' => $err ?? 'sem resposta'];
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'status' => $status, 'data' => [], 'meta' => [],
                    'erro' => 'resposta não-JSON (HTTP ' . $status . ')'];
        }

        if ($status < 200 || $status >= 300) {
            // Erros da Brobot: {errors:[...]} (401) ou {data:{errors:[...]}} (422)
            $erros = $json['errors'] ?? ($json['data']['errors'] ?? []);
            return ['ok' => false, 'status' => $status, 'data' => [], 'meta' => [],
                    'erro' => is_array($erros) ? implode('; ', $erros) : (string) $erros];
        }

        return [
            'ok'     => true,
            'status' => $status,
            'data'   => is_array($json['data'] ?? null) ? $json['data'] : [],
            'meta'   => is_array($json['meta'] ?? null) ? $json['meta'] : [],
            'erro'   => null,
        ];
    }
}
