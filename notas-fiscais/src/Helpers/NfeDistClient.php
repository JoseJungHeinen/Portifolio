<?php
// @versao Cap_18 V_03_16/09/2026
declare(strict_types=1);

/**
 * Cliente da distribuição de NF-e (SEFAZ/AN) — webservice nacional
 * NFeDistribuicaoDFe, método nfeDistDFeInteresse. Mesmo certificado do ADN
 * (mTLS), protocolo diferente: SOAP 1.2, não REST.
 *
 * Achado real em produção (16/09/2026): a SEFAZ guarda o NSU esperado do lado
 * dela. Mandar um ultNSU diferente do que ela espera — inclusive 0, se já
 * houver histórico — devolve cStat 656 "Consumo Indevido" com bloqueio de 1h,
 * e o bloqueio NÃO libera nem corrigindo o NSU na tentativa seguinte (testado
 * na prática, duas tentativas seguidas com o valor certo, mesmo bloqueio).
 * Por isso: nunca chamar de novo dentro do mesmo run após um 656, e sempre
 * persistir o `ultNSU` que a própria SEFAZ devolve — mesmo dentro de uma
 * rejeição — como cursor pro próximo run (AdnSync::processarFilial faz isso).
 *
 * Nunca lança para erro de rede/HTTP/SOAP — devolve ['ok'=>false, ...] pro
 * poller registrar e seguir.
 */
class NfeDistClient
{
    private const URL_HOMOLOGACAO = 'https://hom1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx';
    private const URL_PRODUCAO    = 'https://www1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx';

    private string $url;
    private string $pfxPath;
    private string $senha;
    private string $cnpj;
    private int $tpAmb;
    private string $cUfAutor;
    private int $timeout;

    public function __construct(
        string $pfxPath,
        string $senha,
        string $cnpj,
        string $cUfAutor,     // código IBGE-UF (2 dígitos) da filial, ex.: '43' = RS
        bool $homologacao = false,
        int $timeout = 30
    ) {
        $this->url      = $homologacao ? self::URL_HOMOLOGACAO : self::URL_PRODUCAO;
        $this->pfxPath  = $pfxPath;
        $this->senha    = $senha;
        $this->cnpj     = preg_replace('/\D+/', '', $cnpj) ?? '';
        $this->cUfAutor = $cUfAutor;
        $this->tpAmb    = $homologacao ? 2 : 1;
        $this->timeout  = max(5, $timeout);
    }

    public function certificadoValido(): bool
    {
        return $this->pfxPath !== '' && is_file($this->pfxPath) && $this->senha !== '' && $this->cnpj !== '';
    }

    /**
     * nfeDistDFeInteresse — distribuição por NSU.
     *
     * Nos retornos de erro, sempre `[...overrides] + $vazio`, nunca o
     * inverso: no operador `+` de array do PHP, o lado ESQUERDO vence pra
     * chaves repetidas. `$vazio + [...]` (como estava antes) descartava
     * silenciosamente `status`/`erro`/`cStat` reais, sempre voltando os
     * defaults — foi assim que "falha desconhecida" apareceu em vez do
     * motivo de verdade num teste em produção (16/09/2026).
     *
     * @return array{ok:bool, semNovidade:bool, bloqueado:bool, status:int,
     *               cStat:?string, ultNSU:?int, docs:list<array{nsu:int, chave:?string, xml:string}>, erro:?string}
     */
    public function baixarLote(int $ultNsu): array
    {
        $vazio = ['ok' => false, 'semNovidade' => false, 'bloqueado' => false, 'status' => 0,
                  'cStat' => null, 'ultNSU' => null, 'docs' => [], 'erro' => null];

        if (!$this->certificadoValido()) {
            return ['erro' => 'Certificado não configurado ou arquivo .pfx não encontrado.'] + $vazio;
        }

        $ultNsuFmt = str_pad((string) $ultNsu, 15, '0', STR_PAD_LEFT);
        $distDFeInt = '<distDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            . '<tpAmb>' . $this->tpAmb . '</tpAmb>'
            . '<cUFAutor>' . $this->cUfAutor . '</cUFAutor>'
            . '<CNPJ>' . $this->cnpj . '</CNPJ>'
            . '<distNSU><ultNSU>' . $ultNsuFmt . '</ultNSU></distNSU>'
            . '</distDFeInt>';

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
            . 'xmlns:xsd="http://www.w3.org/2001/XMLSchema" '
            . 'xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">'
            . '<soap12:Body>'
            . '<nfeDistDFeInteresse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">'
            . '<nfeDadosMsg>' . $distDFeInt . '</nfeDadosMsg>'
            . '</nfeDistDFeInteresse>'
            . '</soap12:Body>'
            . '</soap12:Envelope>';

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_SSLCERTTYPE    => 'P12',
            CURLOPT_SSLCERT        => $this->pfxPath,
            CURLOPT_SSLCERTPASSWD  => $this->senha,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/soap+xml; charset=utf-8; '
                . 'action="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe/nfeDistDFeInteresse"',
            ],
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($body === false || $err !== null) {
            return ['status' => $status, 'erro' => $err ?? 'sem resposta'] + $vazio;
        }

        $ret = $this->extrairRetorno((string) $body);
        if ($ret === null) {
            return ['status' => $status, 'erro' => 'resposta SOAP não reconhecida (HTTP ' . $status . ')'] + $vazio;
        }

        $cStat  = $ret['cStat'];
        $ultRet = $ret['ultNSU'] !== null ? (int) $ret['ultNSU'] : null;

        if ($cStat === '656') {
            return ['ok' => false, 'semNovidade' => false, 'bloqueado' => true, 'status' => $status,
                     'cStat' => $cStat, 'ultNSU' => $ultRet, 'docs' => [],
                     'erro' => 'Consumo indevido — SEFAZ pede espera de 1h antes de tentar de novo.'];
        }
        if ($cStat === '137') {
            return ['ok' => true, 'semNovidade' => true, 'bloqueado' => false, 'status' => $status,
                     'cStat' => $cStat, 'ultNSU' => $ultRet, 'docs' => [], 'erro' => null];
        }
        if ($cStat !== '138') {
            return ['status' => $status, 'cStat' => $cStat,
                     'erro' => 'cStat ' . $cStat . ': ' . ($ret['xMotivo'] ?? '')] + $vazio;
        }

        $docs = [];
        foreach ($ret['docZips'] as $item) {
            $comprimido = base64_decode($item['conteudo'], true);
            $xml = $comprimido !== false ? @gzdecode($comprimido) : false;
            if ($xml === false) {
                $xml = $comprimido !== false ? @gzinflate($comprimido) : false;
            }
            if ($xml === false || $xml === null) continue;

            $docs[] = ['nsu' => $item['nsu'], 'chave' => $item['chave'], 'tipo' => $item['tipo'], 'xml' => $xml];
        }

        return ['ok' => true, 'semNovidade' => empty($docs), 'bloqueado' => false, 'status' => $status,
                 'cStat' => $cStat, 'ultNSU' => $ultRet, 'docs' => $docs, 'erro' => null];
    }

    /**
     * @return array{cStat:?string, xMotivo:?string, ultNSU:?string,
     *               docZips:list<array{nsu:int, chave:?string, tipo:string, conteudo:string}>}|null
     */
    private function extrairRetorno(string $soapBody): ?array
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($soapBody, LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return null;
        }
        $xp = new DOMXPath($doc);
        $ln = fn(string $tag) => '*[local-name()="' . $tag . '"]';
        $first = function (string $q) use ($xp) {
            $n = $xp->query($q);
            return ($n && $n->length > 0) ? trim((string) $n->item(0)->nodeValue) : null;
        };

        $cStat = $first('//' . $ln('retDistDFeInt') . '/' . $ln('cStat'));
        if ($cStat === null) return null;

        $docZips = [];
        foreach ($xp->query('//' . $ln('docZip')) ?: [] as $node) {
            /** @var DOMElement $node */
            // "schema" vem tipo "procEventoNFe_v1.00.xsd" — o prefixo alfabético
            // antes do "_v..." identifica o tipo: procNFe (nota completa+protocolo),
            // resNFe (resumo, sem itens), procEventoNFe/resEvento (evento — não é
            // nota). Cada um tem estrutura de XML diferente, precisa de parser
            // diferente (ou de ser ignorado, no caso de evento).
            $schema = $node->getAttribute('schema');
            $tipo   = preg_match('/^([A-Za-z]+)/', $schema, $m) ? $m[1] : 'desconhecido';
            $docZips[] = [
                'nsu'      => (int) $node->getAttribute('NSU'),
                'chave'    => null, // resolvido depois do parse do XML (chave difere por tipo)
                'tipo'     => $tipo,
                'conteudo' => trim((string) $node->nodeValue),
            ];
        }

        return [
            'cStat'   => $cStat,
            'xMotivo' => $first('//' . $ln('retDistDFeInt') . '/' . $ln('xMotivo')),
            'ultNSU'  => $first('//' . $ln('retDistDFeInt') . '/' . $ln('ultNSU')),
            'docZips' => $docZips,
        ];
    }
}
