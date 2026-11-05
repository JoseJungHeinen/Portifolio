<?php
// @versao Cap_15 V_04_17/09/2026
declare(strict_types=1);

/**
 * Cliente do ADN (Ambiente de Dados Nacional) — distribuição de NFSe Nacional
 * por NSU, direto do governo, sem passar pela Brobot.
 *
 * Auth: mTLS — o certificado .pfx É a credencial (não tem token em header).
 * Rota: GET /contribuintes/DFe/{nsu}[?cnpjConsulta=...].
 *
 * Um único certificado da MATRIZ cobre qualquer filial da mesma raiz de CNPJ
 * (confirmado em 16/09/2026) — o cliente é instanciado uma vez com esse
 * certificado, e cada chamada informa `cnpjConsulta` pra dizer de qual filial
 * quer o histórico. NSU continua sendo por CNPJ consultado, não por
 * certificado — por isso o cursor (AdnSync) é por filial.
 *
 * Validado em produção restrita (homologação) em 16/09/2026: NSU 0 devolveu
 * 9 documentos reais da filial 09327318000156 (Fiat Cidade A), decodificados
 * e coerentes (ver docs/... se formalizado depois).
 *
 * Achado em 17/09/2026: o default de $homologacao era `true` e o único
 * chamador (AdnSync) nunca passava o argumento — a captura da B7 rodou contra
 * a produção restrita (homologação) desde o início, sem valor fiscal. Default
 * trocado para produção (mesmo padrão do NfeDistClient) pra esse erro não se
 * repetir por omissão.
 *
 * Nunca lança para erro de rede/HTTP — devolve ['ok'=>false, ...] para o
 * poller registrar no log e seguir.
 */
class AdnClient
{
    private const URL_HOMOLOGACAO = 'https://adn.producaorestrita.nfse.gov.br';
    private const URL_PRODUCAO    = 'https://adn.nfse.gov.br';

    private string $baseUrl;
    private string $pfxPath;
    private string $senha;
    private int $timeout;

    public function __construct(string $pfxPath, string $senha, bool $homologacao = false, int $timeout = 30)
    {
        $this->baseUrl = $homologacao ? self::URL_HOMOLOGACAO : self::URL_PRODUCAO;
        $this->pfxPath = $pfxPath;
        $this->senha   = $senha;
        $this->timeout = max(5, $timeout);
    }

    public function certificadoValido(): bool
    {
        return $this->pfxPath !== '' && is_file($this->pfxPath) && $this->senha !== '';
    }

    /**
     * GET /contribuintes/DFe/{nsu} — devolve o lote de documentos após o NSU
     * informado (0 = desde o início da retenção do ADN).
     *
     * $cnpjConsulta é opcional e SEPARADO do certificado da conexão mTLS: é
     * o parâmetro `cnpjConsulta` da query string, que permite consultar em
     * nome de outra filial da MESMA raiz de CNPJ do certificado (confirmado
     * no SDK oficial nfse-nacional/nfse-php). Sem ele, o ADN devolve o
     * histórico do CNPJ do próprio certificado.
     *
     * Cada doc já vem com o XML descomprimido (gzip+base64 → texto).
     *
     * @return array{ok:bool, semNovidade:bool, status:int,
     *               docs:list<array{nsu:int, chave:string, tipo:string, xml:string}>, erro:?string}
     */
    public function baixarLote(int $nsu, ?string $cnpjConsulta = null): array
    {
        $vazio = ['ok' => false, 'semNovidade' => false, 'status' => 0, 'docs' => [], 'erro' => null];

        if (!$this->certificadoValido()) {
            return ['erro' => 'Certificado não configurado ou arquivo .pfx não encontrado.'] + $vazio;
        }

        $url = "{$this->baseUrl}/contribuintes/DFe/{$nsu}";
        if ($cnpjConsulta !== null && $cnpjConsulta !== '') {
            $url .= '?' . http_build_query(['cnpjConsulta' => $cnpjConsulta]);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_SSLCERTTYPE    => 'P12',
            CURLOPT_SSLCERT        => $this->pfxPath,
            CURLOPT_SSLCERTPASSWD  => $this->senha,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($body === false || $err !== null) {
            return ['status' => $status, 'erro' => $err ?? 'sem resposta'] + $vazio;
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            return ['status' => $status, 'erro' => 'resposta não-JSON (HTTP ' . $status . ')'] + $vazio;
        }

        // "Não tem nada novo" é uma resposta válida, não um erro — o ADN devolve
        // 404 + E2220 quando o NSU informado já está em dia. Sem esse caso
        // especial, o poller trataria "sem novidade" como falha a cada run.
        $codigoErro = $json['Erros'][0]['Codigo'] ?? null;
        if ($status === 404 && $codigoErro === 'E2220') {
            return ['ok' => true, 'semNovidade' => true, 'status' => $status, 'docs' => [], 'erro' => null];
        }

        if ($status < 200 || $status >= 300) {
            $desc = $json['Erros'][0]['Descricao'] ?? ($json['StatusProcessamento'] ?? 'erro desconhecido');
            return ['status' => $status, 'erro' => (string) $desc] + $vazio;
        }

        $docs = [];
        foreach (($json['LoteDFe'] ?? []) as $item) {
            $xmlComprimido = base64_decode((string) ($item['ArquivoXml'] ?? ''), true);
            $xml = $xmlComprimido !== false ? @gzdecode($xmlComprimido) : false;
            if ($xml === false) {
                $xml = $xmlComprimido !== false ? @gzinflate($xmlComprimido) : false;
            }
            if ($xml === false || $xml === null) {
                continue; // documento ilegível — o dedup por chave protege se vier de novo depois
            }
            $docs[] = [
                'nsu'   => (int) ($item['NSU'] ?? 0),
                'chave' => (string) ($item['ChaveAcesso'] ?? ''),
                'tipo'  => (string) ($item['TipoDocumento'] ?? ''),
                'xml'   => $xml,
            ];
        }

        return ['ok' => true, 'semNovidade' => empty($docs), 'status' => $status, 'docs' => $docs, 'erro' => null];
    }
}
