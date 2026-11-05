<?php
// @versao Asg_02 V_01_17/08/2026
declare(strict_types=1);

/**
 * Cliente da API Assinafy (assinatura eletrônica).
 *
 * Base:  https://api.assinafy.com.br/v1   (produção)
 *        https://sandbox.assinafy.com.br/v1 (testes)
 * Auth:  header `X-Api-Key`
 * Envelope: toda resposta é { status, message, data } — `status` espelha o HTTP.
 *
 * Levantado contra a API real em 17/08/2026 (ver
 * docs/IDEIA-ASSINATURA-ELETRONICA-ASSINAFY.md). Tempos medidos: upload ~4s,
 * processamento do PDF ~6s, demais chamadas 0,6–2,4s.
 *
 * Uso:
 *   $c = AssinafyClient::fromSettings();
 *   if (!$c->configurado()) { ... }
 *   $r = $c->subirDocumento('/caminho/doc.pdf');
 *   if ($r['ok']) { $id = $r['data']['id']; }
 *
 * NUNCA lança para erro de rede/HTTP — devolve ['ok'=>false, 'erro'=>...].
 * Quem chama é o worker e o gancho de aprovação: uma exceção aqui derrubaria a
 * fila inteira ou a transação do processo.
 */
class AssinafyClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $accountId;
    private int    $timeout;

    public function __construct(string $baseUrl, string $apiKey, string $accountId, int $timeout = 60)
    {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->apiKey    = trim($apiKey);
        $this->accountId = trim($accountId);
        $this->timeout   = max(10, $timeout);
    }

    /** Constrói a partir das configs (grupo `assinafy`, tela Geral → API). */
    public static function fromSettings(): self
    {
        $ambiente = (string) Settings::get('assinafy.ambiente', 'sandbox');
        $base     = $ambiente === 'producao'
            ? 'https://api.assinafy.com.br/v1'
            : 'https://sandbox.assinafy.com.br/v1';

        return new self(
            $base,
            (string) Settings::get('assinafy.api_key', ''),
            (string) Settings::get('assinafy.account_id', ''),
            Settings::getInt('assinafy.timeout_seg', 60)
        );
    }

    /** A integração está ligada e com credencial? */
    public function configurado(): bool
    {
        return $this->apiKey !== '' && $this->accountId !== '';
    }

    public function ativo(): bool
    {
        return (string) Settings::get('assinafy.ativo', '0') === '1' && $this->configurado();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Documentos
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST /accounts/{id}/documents — sobe o PDF (multipart, campo `file`).
     *
     * Devolve o documento em `uploaded`; o provedor normaliza para PDF e extrai
     * as páginas sozinho (≈6s). O `method: virtual` do assignment aceita o
     * documento ainda em processamento, então NÃO é preciso esperar.
     *
     * Atenção: o provedor remove acento do nome do arquivo (`Alvará.PDF` vira
     * `Alvara.PDF`). Nunca casar anexo por nome — usar sempre o id devolvido.
     */
    public function subirDocumento(string $caminho, ?string $nome = null): array
    {
        if (!is_file($caminho) || !is_readable($caminho)) {
            return ['ok' => false, 'status' => 0, 'erro' => 'Arquivo não encontrado: ' . basename($caminho)];
        }
        $mime = function_exists('mime_content_type') ? (mime_content_type($caminho) ?: 'application/pdf') : 'application/pdf';
        $post = ['file' => new CURLFile($caminho, $mime, $nome ?: basename($caminho))];

        return $this->req('POST', '/accounts/' . $this->accountId . '/documents', null, $post);
    }

    /** GET /documents/{id} — estado atual (com o assignment expandido). */
    public function documento(string $documentoId): array
    {
        return $this->req('GET', '/documents/' . rawurlencode($documentoId) . '?expand=assignment');
    }

    /**
     * GET /documents/{id}/activities — trilha de auditoria.
     *
     * Usa o MESMO vocabulário de eventos do webhook (`signer_signed_document`,
     * `document_ready`, …) e já vem com `message` em português. É o que permite
     * o worker reconstruir tudo por polling, sem depender do webhook — que não
     * tem HMAC e portanto não é confiável como fonte.
     */
    public function atividades(string $documentoId): array
    {
        return $this->req('GET', '/documents/' . rawurlencode($documentoId) . '/activities');
    }

    /** GET /documents/{id}/download/{artefato} — devolve os bytes crus. */
    public function baixarArtefato(string $documentoId, string $artefato = 'certificated'): array
    {
        $permitidos = ['original', 'certificated', 'certificate-page', 'bundle'];
        if (!in_array($artefato, $permitidos, true)) {
            return ['ok' => false, 'status' => 0, 'erro' => 'Artefato inválido: ' . $artefato];
        }
        return $this->req(
            'GET',
            '/documents/' . rawurlencode($documentoId) . '/download/' . $artefato,
            null, null, true
        );
    }

    /** DELETE /documents/{id} — cancela/remove (só em status deletável). */
    public function excluirDocumento(string $documentoId): array
    {
        return $this->req('DELETE', '/documents/' . rawurlencode($documentoId));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Signatários
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Acha por e-mail ou cria. Confirmado em 17/08: sem a busca prévia o
     * workspace enche de duplicados — cada processo recriaria o mesmo
     * fornecedor.
     *
     * @return array{ok:bool, status:int, data:array, erro:?string, reusado:bool}
     */
    public function acharOuCriarSignatario(string $nome, string $email): array
    {
        $email = trim($email);
        $busca = $this->req(
            'GET',
            '/accounts/' . $this->accountId . '/signers?search=' . rawurlencode($email)
        );
        if ($busca['ok']) {
            foreach (($busca['data'] ?: []) as $s) {
                if (strcasecmp((string) ($s['email'] ?? ''), $email) === 0) {
                    return ['ok' => true, 'status' => 200, 'data' => $s, 'erro' => null, 'reusado' => true];
                }
            }
        }
        $novo = $this->req('POST', '/accounts/' . $this->accountId . '/signers', [
            'full_name' => trim($nome),
            'email'     => $email,
        ]);
        $novo['reusado'] = false;
        return $novo;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Assignment (pedido de assinatura)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST /documents/{id}/assignments — dispara os convites.
     *
     * Todos os signatários vão com `step: 1` (paralelo, decidido em
     * 17/08/2026): são notificados de uma vez e assinam em qualquer ordem.
     *
     * @param array<int,string> $signerIds  quem assina
     * @param array<int,string> $copiaIds   quem só recebe cópia
     * @param int               $prazoDias  0 = sem expiração
     */
    public function pedirAssinaturas(
        string $documentoId,
        array $signerIds,
        array $copiaIds = [],
        string $mensagem = '',
        int $prazoDias = 0
    ): array {
        if (empty($signerIds)) {
            return ['ok' => false, 'status' => 0, 'erro' => 'Nenhum signatário informado.'];
        }

        $corpo = [
            'method'  => 'virtual',
            'signers' => array_map(
                static fn(string $id): array => ['id' => $id, 'step' => 1],
                array_values($signerIds)
            ),
        ];
        if ($mensagem !== '')      $corpo['message']        = mb_substr($mensagem, 0, 500, 'UTF-8');
        if (!empty($copiaIds))     $corpo['copy_receivers'] = array_values($copiaIds);
        if ($prazoDias > 0)        $corpo['expires_at']     = gmdate('Y-m-d\TH:i:s\Z', time() + $prazoDias * 86400);

        return $this->req('POST', '/documents/' . rawurlencode($documentoId) . '/assignments', $corpo);
    }

    /** Custo antes de enviar — evita estourar cota sem aviso. */
    public function estimarCusto(string $documentoId, array $signerIds): array
    {
        return $this->req('POST', '/documents/' . rawurlencode($documentoId) . '/assignments/estimate-cost', [
            'method'  => 'virtual',
            'signers' => array_map(static fn(string $id): array => ['id' => $id, 'step' => 1], array_values($signerIds)),
        ]);
    }

    /** Reenvia o convite a um signatário que ainda não assinou. */
    public function reenviarConvite(string $documentoId, string $assignmentId, string $signerId): array
    {
        return $this->req('PUT', '/documents/' . rawurlencode($documentoId)
            . '/assignments/' . rawurlencode($assignmentId)
            . '/signers/' . rawurlencode($signerId) . '/resend');
    }

    /** Renova o prazo de um assignment vencido/prestes a vencer. */
    public function renovarPrazo(string $documentoId, string $assignmentId): array
    {
        return $this->req('PUT', '/documents/' . rawurlencode($documentoId)
            . '/assignments/' . rawurlencode($assignmentId) . '/reset-expiration');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Webhook
    // ─────────────────────────────────────────────────────────────────────

    /** PUT /accounts/{id}/webhooks/subscriptions — assina os eventos. */
    public function configurarWebhook(array $eventos, string $url, string $email, bool $ativo = true): array
    {
        return $this->req('PUT', '/accounts/' . $this->accountId . '/webhooks/subscriptions', [
            'events'    => array_values($eventos),
            'is_active' => $ativo,
            'url'       => $url,
            'email'     => $email,
        ]);
    }

    public function webhookAtual(): array
    {
        return $this->req('GET', '/accounts/' . $this->accountId . '/webhooks/subscriptions');
    }

    /** Valida a credencial sem efeito colateral — usado pelo botão "Testar". */
    public function testarConexao(): array
    {
        return $this->req('GET', '/accounts/' . $this->accountId);
    }

    // ─────────────────────────────────────────────────────────────────────
    // HTTP
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array|null $json    corpo JSON
     * @param array|null $upload  multipart (CURLFile)
     * @param bool       $binario resposta é arquivo, não JSON
     * @return array{ok:bool, status:int, data:mixed, raw:string, erro:?string}
     */
    private function req(
        string $metodo,
        string $caminho,
        ?array $json = null,
        ?array $upload = null,
        bool $binario = false
    ): array {
        if (!$this->configurado()) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'raw' => '',
                    'erro' => 'Assinafy sem chave ou workspace configurado (Geral → API).'];
        }

        $ch      = curl_init();
        $headers = ['X-Api-Key: ' . $this->apiKey, 'Accept: application/json'];

        $opts = [
            CURLOPT_URL            => $this->baseUrl . $caminho,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($upload !== null) {
            // cURL monta o boundary do multipart sozinho — não definir
            // Content-Type aqui, senão o boundary não entra e o upload falha.
            $opts[CURLOPT_POSTFIELDS] = $upload;
        } elseif ($json !== null) {
            $headers[]                = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $resposta = curl_exec($ch);
        if ($resposta === false) {
            $erro = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'status' => 0, 'data' => null, 'raw' => '',
                    'erro' => 'Falha de rede: ' . $erro];
        }
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = $http >= 200 && $http < 300;

        if ($binario) {
            return ['ok' => $ok, 'status' => $http, 'data' => null, 'raw' => $resposta,
                    'erro' => $ok ? null : 'HTTP ' . $http . ' ao baixar artefato'];
        }

        $corpo = json_decode($resposta, true);
        if (!$ok) {
            $msg = is_array($corpo)
                ? (string) ($corpo['message'] ?? ($corpo['name'] ?? 'erro'))
                : 'resposta não-JSON';
            return ['ok' => false, 'status' => $http, 'data' => $corpo['data'] ?? null,
                    'raw' => $resposta, 'erro' => 'HTTP ' . $http . ': ' . $msg];
        }

        return ['ok' => true, 'status' => $http, 'data' => $corpo['data'] ?? null,
                'raw' => $resposta, 'erro' => null];
    }
}
