<?php
// @versao Clk_01 V_01_01/10/2026
declare(strict_types=1);

/**
 * Cliente da API v3 (Envelope) da Clicksign — assinatura eletrônica.
 *
 * Porte do cliente Node da Locadora (api-bridge/lib/clicksignClient.js), que foi
 * exercitado de ponta a ponta em sandbox real em 24-25/09/2026.
 *
 * Base:  https://app.clicksign.com/api/v3 (produção) · https://sandbox.clicksign.com/api/v3
 * Auth:  header Authorization com o token CRU (sem "Bearer")
 * Corpo: JSON:API — Content-Type e Accept `application/vnd.api+json`
 *
 * Fluxo de envio (um documento, N signatários, assinam em paralelo):
 *   criarEnvelope → adicionarDocumento → adicionarSignatario (x N)
 *   → adicionarRequisitos (x N: `agree/sign` + `provide_evidence/<auth>`)
 *   → ativarEnvelope → notificar
 *
 * Status do envelope: draft | running | closed | canceled. A fonte de verdade é
 * SEMPRE a reconsulta autenticada (getEnvelope), nunca o corpo do webhook.
 * A doc não expõe status por signatário — a conclusão é `closed`.
 *
 * Armadilha vivida na Locadora: o campo "E-mail do usuário da API" da conta
 * Clicksign (Configurações → API) PRECISA ser um e-mail já cadastrado em
 * Usuários; um qualquer "salva" e depois toda chamada falha.
 *
 * NUNCA lança para erro de rede/HTTP — devolve ['ok'=>false, 'erro'=>...], como o
 * AssinafyClient: quem chama é o worker e o gancho pós-commit.
 */
class ClicksignClient
{
    /** Valores de `auth` aceitos — os mesmos 11 curados na Locadora. */
    public const AUTH = [
        'email', 'whatsapp', 'sms', 'selfie', 'liveness', 'official_document',
        'icp_brasil', 'handwritten', 'pix', 'facial_biometrics', 'documentscopy',
    ];

    private string $baseUrl;
    private string $token;
    private int    $timeout;

    public function __construct(string $baseUrl, string $token, int $timeout = 60)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token   = trim($token);
        $this->timeout = max(10, $timeout);
    }

    public static function fromSettings(): self
    {
        $base = (string) Settings::get('clicksign.ambiente', 'sandbox') === 'producao'
            ? 'https://app.clicksign.com/api/v3'
            : 'https://sandbox.clicksign.com/api/v3';

        return new self(
            $base,
            (string) Settings::get('clicksign.access_token', ''),
            Settings::getInt('clicksign.timeout_seg', 60)
        );
    }

    public function configurado(): bool
    {
        return $this->token !== '';
    }

    public function ativo(): bool
    {
        return (string) Settings::get('clicksign.ativo', '0') === '1' && $this->configurado();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Validações (a Clicksign recusa o signatário inteiro se vier errado)
    // ─────────────────────────────────────────────────────────────────────

    /** CPF com dígitos verificadores → '000.000.000-00'; senão null. */
    public static function formatarCpf(?string $valor): ?string
    {
        $d = preg_replace('/\D/', '', (string) $valor);
        if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) { return null; }
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) { $soma += (int) $d[$i] * ($t + 1 - $i); }
            $dig = ((10 * $soma) % 11) % 10;
            if ((int) $d[$t] !== $dig) { return null; }
        }
        return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9);
    }

    /** A Clicksign exige ao menos duas palavras no nome. */
    public static function nomeValido(?string $nome): bool
    {
        $partes = preg_split('/\s+/u', trim((string) $nome), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($partes) && count($partes) >= 2;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Envelope
    // ─────────────────────────────────────────────────────────────────────

    public function criarEnvelope(string $nome, ?string $deadlineIso = null): array
    {
        $attr = ['name' => mb_substr($nome, 0, 255, 'UTF-8')];
        if ($deadlineIso !== null) { $attr['deadline_at'] = $deadlineIso; }
        return $this->req('POST', '/envelopes', ['data' => ['type' => 'envelopes', 'attributes' => $attr]]);
    }

    /** O PDF vai em base64 dentro do JSON (a API não aceita multipart). */
    public function adicionarDocumento(string $envelopeId, string $filename, string $caminhoPdf): array
    {
        if (!is_file($caminhoPdf) || !is_readable($caminhoPdf)) {
            return $this->falha('Arquivo não encontrado: ' . basename($caminhoPdf));
        }
        $bin = file_get_contents($caminhoPdf);
        if ($bin === false || $bin === '') { return $this->falha('Arquivo vazio ou ilegível.'); }

        return $this->req('POST', '/envelopes/' . rawurlencode($envelopeId) . '/documents', ['data' => [
            'type' => 'documents',
            'attributes' => [
                'filename'       => $filename,
                'content_base64' => 'data:application/pdf;base64,' . base64_encode($bin),
            ],
        ]]);
    }

    /**
     * Regras (confirmadas na doc e no sandbox):
     *  - name com ao menos duas palavras; documentation = CPF formatado;
     *  - has_documentation=true exige CPF E data de nascimento — como não
     *    guardamos nascimento, sem CPF válido manda false;
     *  - phone_number só com 10/11 dígitos; sms/whatsapp usam o mesmo canal no
     *    convite, senão o signatário confirma por um canal que nunca chega;
     *  - signature_reminder só aceita none/email.
     */
    public function adicionarSignatario(string $envelopeId, array $s): array
    {
        $auth  = (string) ($s['tipo_autenticacao'] ?? 'email');
        $canal = in_array($auth, ['sms', 'whatsapp'], true) ? $auth : 'email';

        $attr = [
            'name'  => (string) $s['nome'],
            'email' => (string) $s['email'],
            'communicate_events' => [
                'signature_request'  => $canal,
                'signature_reminder' => 'email',
                'document_signed'    => $canal === 'whatsapp' ? 'whatsapp' : 'email',
            ],
        ];
        $cpf = self::formatarCpf($s['cpf'] ?? '');
        if ($cpf !== null) { $attr['documentation'] = $cpf; $attr['has_documentation'] = true; }
        else               { $attr['has_documentation'] = false; }

        $tel = preg_replace('/\D/', '', (string) ($s['telefone'] ?? ''));
        if (strlen($tel) === 10 || strlen($tel) === 11) { $attr['phone_number'] = $tel; }

        return $this->req('POST', '/envelopes/' . rawurlencode($envelopeId) . '/signers',
            ['data' => ['type' => 'signers', 'attributes' => $attr]]);
    }

    /** Dois requisitos por signatário e por documento: assinar + autenticar. */
    public function adicionarRequisitos(string $envelopeId, string $documentId, string $signerId, string $auth): array
    {
        if (!in_array($auth, self::AUTH, true)) { $auth = 'email'; }
        $rel = [
            'document' => ['data' => ['type' => 'documents', 'id' => $documentId]],
            'signer'   => ['data' => ['type' => 'signers',   'id' => $signerId]],
        ];
        $url = '/envelopes/' . rawurlencode($envelopeId) . '/requirements';

        $r = $this->req('POST', $url, ['data' => [
            'type' => 'requirements', 'attributes' => ['action' => 'agree', 'role' => 'sign'], 'relationships' => $rel,
        ]]);
        if (!$r['ok']) { return $r; }

        return $this->req('POST', $url, ['data' => [
            'type' => 'requirements', 'attributes' => ['action' => 'provide_evidence', 'auth' => $auth], 'relationships' => $rel,
        ]]);
    }

    public function ativarEnvelope(string $envelopeId): array
    {
        return $this->req('PATCH', '/envelopes/' . rawurlencode($envelopeId), ['data' => [
            'id' => $envelopeId, 'type' => 'envelopes', 'attributes' => ['status' => 'running'],
        ]]);
    }

    public function notificar(string $envelopeId): array
    {
        return $this->req('POST', '/envelopes/' . rawurlencode($envelopeId) . '/notifications',
            ['data' => ['type' => 'notifications', 'attributes' => (object) []]]);
    }

    /** Fonte de verdade do andamento: data.attributes.status. */
    public function getEnvelope(string $envelopeId): array
    {
        return $this->req('GET', '/envelopes/' . rawurlencode($envelopeId));
    }

    public function listarDocumentos(string $envelopeId): array
    {
        return $this->req('GET', '/envelopes/' . rawurlencode($envelopeId) . '/documents');
    }

    /**
     * Baixa o PDF assinado do primeiro documento. O link é pré-assinado (S3) e
     * vai SEM Authorization. `links.files.signed` leva alguns segundos (~11s
     * medidos) para existir depois que o envelope fecha — a ausência NÃO é
     * erro definitivo: devolve ok=false e o worker tenta de novo.
     */
    public function baixarAssinado(string $envelopeId): array
    {
        $docs = $this->listarDocumentos($envelopeId);
        if (!$docs['ok']) { return $docs + ['raw' => '']; }

        $url = $docs['data'][0]['links']['files']['signed'] ?? null;
        if (!is_string($url) || $url === '') {
            return $this->falha('A Clicksign ainda não disponibilizou o PDF assinado (links.files.signed ausente).') + ['raw' => ''];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) { return $this->falha('Falha de rede ao baixar o PDF assinado: ' . $err) + ['raw' => '']; }
        if ($http < 200 || $http >= 300) { return $this->falha('HTTP ' . $http . ' ao baixar o PDF assinado.') + ['raw' => '']; }
        return ['ok' => true, 'status' => $http, 'data' => null, 'raw' => (string) $raw, 'erro' => null];
    }

    /** Valida a credencial sem efeito colateral. */
    public function testarConexao(): array
    {
        return $this->req('GET', '/envelopes?page[size]=1');
    }

    // ─────────────────────────────────────────────────────────────────────
    // HTTP
    // ─────────────────────────────────────────────────────────────────────

    private function falha(string $erro, int $status = 0): array
    {
        return ['ok' => false, 'status' => $status, 'data' => null, 'erro' => $erro];
    }

    /** @return array{ok:bool, status:int, data:mixed, erro:?string} — data já é o nó `data` do JSON:API */
    private function req(string $metodo, string $caminho, ?array $json = null): array
    {
        if (!$this->configurado()) {
            return $this->falha('Clicksign sem Access Token configurado (Geral → API).');
        }

        $ch = curl_init($this->baseUrl . $caminho);
        $headers = [
            'Authorization: ' . $this->token,
            'Accept: application/vnd.api+json',
        ];
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/vnd.api+json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $erro = curl_error($ch);
            curl_close($ch);
            return $this->falha('Falha de rede: ' . $erro);
        }
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $corpo = json_decode((string) $resp, true);   // 202/204 chegam sem corpo
        if ($http < 200 || $http >= 300) {
            $e   = is_array($corpo) ? ($corpo['errors'][0] ?? null) : null;
            $det = is_array($e) ? (string) ($e['detail'] ?? ($e['title'] ?? '')) : '';
            return $this->falha($det !== '' ? 'Clicksign: ' . $det : 'Clicksign respondeu HTTP ' . $http . ' em ' . $caminho, $http);
        }

        return ['ok' => true, 'status' => $http, 'data' => is_array($corpo) ? ($corpo['data'] ?? null) : null, 'erro' => null];
    }
}
