<?php
// @versao Cap_22 V_03_21/09/2026
declare(strict_types=1);

/**
 * Cliente do evento de Manifestação do Destinatário — webservice nacional
 * NFeRecepcaoEvento4, mesmo host da distribuição (NfeDistClient). Hoje só
 * implementa 210210 (Ciência da Operação): é o evento mínimo que libera a
 * SEFAZ a entregar o procNFe completo no NSU seguinte, disparado
 * automaticamente assim que a distribuição entrega um resNFe (ver
 * NfeDistSync::processarDoc / enviarCienciaDaOperacao).
 *
 * cOrgao é sempre "91" (Ambiente Nacional) pra eventos de manifestação,
 * diferente do cUFAutor usado em nfeDistDFeInteresse — não é a UF da filial.
 *
 * O XML do evento precisa vir ASSINADO (XmlAssinatura) além do mTLS da
 * conexão — a distribuição só exigia o mTLS, a manifestação exige as duas
 * coisas porque o evento fica gravado como declaração do destinatário.
 *
 * Mesma filosofia da NfeDistClient: nunca lança pra erro de rede/HTTP/SOAP,
 * devolve ['ok'=>false, ...] pro chamador registrar e seguir.
 */
class NfeEventoClient
{
    private const URL_HOMOLOGACAO = 'https://hom1.nfe.fazenda.gov.br/NFeRecepcaoEvento4/NFeRecepcaoEvento4.asmx';
    private const URL_PRODUCAO    = 'https://www1.nfe.fazenda.gov.br/NFeRecepcaoEvento4/NFeRecepcaoEvento4.asmx';

    private const TP_EVENTO_CIENCIA = '210210';
    private const DESC_CIENCIA      = 'Ciencia da Operacao';

    // cStat aceitos como sucesso: 135 = registrado e vinculado à NF-e,
    // 136 = registrado mas ainda não vinculado (SEFAZ processa em lote,
    // acontece), 573 = duplicidade — já manifestada antes (por fora deste
    // sistema, ex. e-CAC) — não é erro, é o mesmo resultado que queríamos.
    private const CSTAT_SUCESSO = ['135', '136', '573'];

    private string $url;
    private string $pfxPath;
    private string $senha;
    private string $cnpj;
    private int $tpAmb;
    private ?string $ultimaRespostaBruta = null;

    public function __construct(string $pfxPath, string $senha, string $cnpj, bool $homologacao = false)
    {
        $this->url     = $homologacao ? self::URL_HOMOLOGACAO : self::URL_PRODUCAO;
        $this->pfxPath = $pfxPath;
        $this->senha   = $senha;
        $this->cnpj    = preg_replace('/\D+/', '', $cnpj) ?? '';
        $this->tpAmb   = $homologacao ? 2 : 1;
    }

    /** SOAP bruto da última chamada — só pra depuração manual (cli/testar_ciencia_operacao.php). */
    public function ultimaRespostaBruta(): ?string
    {
        return $this->ultimaRespostaBruta;
    }

    public function certificadoValido(): bool
    {
        return $this->pfxPath !== '' && is_file($this->pfxPath) && $this->senha !== '' && $this->cnpj !== '';
    }

    /**
     * @return array{ok:bool, cStat:?string, xMotivo:?string, nProt:?string, erro:?string}
     */
    public function cienciaDaOperacao(string $chaveNfe): array
    {
        $vazio = ['ok' => false, 'cStat' => null, 'xMotivo' => null, 'nProt' => null, 'erro' => null];

        if (!$this->certificadoValido()) {
            return ['erro' => 'Certificado não configurado ou arquivo .pfx não encontrado.'] + $vazio;
        }

        $chave = preg_replace('/\D+/', '', $chaveNfe) ?? '';
        if (strlen($chave) !== 44) {
            return ['erro' => 'Chave de NF-e inválida (esperado 44 dígitos).'] + $vazio;
        }

        $dhEvento = date('Y-m-d\TH:i:sP');
        $id       = 'ID' . self::TP_EVENTO_CIENCIA . $chave . '01';

        $eventoXml = '<evento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">'
            . '<infEvento Id="' . $id . '">'
            . '<cOrgao>91</cOrgao>'
            . '<tpAmb>' . $this->tpAmb . '</tpAmb>'
            . '<CNPJ>' . $this->cnpj . '</CNPJ>'
            . '<chNFe>' . $chave . '</chNFe>'
            . '<dhEvento>' . $dhEvento . '</dhEvento>'
            . '<tpEvento>' . self::TP_EVENTO_CIENCIA . '</tpEvento>'
            . '<nSeqEvento>1</nSeqEvento>'
            . '<verEvento>1.00</verEvento>'
            . '<detEvento versao="1.00">'
            . '<descEvento>' . self::DESC_CIENCIA . '</descEvento>'
            . '</detEvento>'
            . '</infEvento>'
            . '</evento>';

        require_once __DIR__ . '/XmlAssinatura.php';
        $assinado = XmlAssinatura::assinarEvento($eventoXml, $id, $this->pfxPath, $this->senha);
        if (!$assinado['ok']) {
            return ['erro' => $assinado['erro']] + $vazio;
        }

        $envEvento = '<envEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">'
            . '<idLote>1</idLote>'
            . $assinado['xml']
            . '</envEvento>';

        // WSDL deste serviço (conferido em 21/09/2026 direto no ?wsdl) usa
        // estilo "bare": o corpo do SOAP tem o <nfeDadosMsg> como filho DIRETO
        // do Body, sem um elemento "<nfeRecepcaoEventoNF>" envolvendo — ao
        // contrário do NFeDistribuicaoDFe (NfeDistClient), que embrulha em
        // "<nfeDistDFeInteresse>". Confundir os dois estilos dá SOAP Fault
        // "action was not recognized" (é o que acontecia antes desta versão).
        // Nome da operação também é "nfeRecepcaoEventoNF" (com o "NF" no
        // final), não "nfeRecepcaoEvento".
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
            . 'xmlns:xsd="http://www.w3.org/2001/XMLSchema" '
            . 'xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">'
            . '<soap12:Body>'
            . '<nfeDadosMsg xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeRecepcaoEvento4">'
            . $envEvento
            . '</nfeDadosMsg>'
            . '</soap12:Body>'
            . '</soap12:Envelope>';

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_SSLCERTTYPE    => 'P12',
            CURLOPT_SSLCERT        => $this->pfxPath,
            CURLOPT_SSLCERTPASSWD  => $this->senha,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/soap+xml; charset=utf-8; '
                . 'action="http://www.portalfiscal.inf.br/nfe/wsdl/NFeRecepcaoEvento4/nfeRecepcaoEventoNF"',
            ],
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        $this->ultimaRespostaBruta = $body !== false ? (string) $body : null;

        if ($body === false || $err !== null) {
            return ['erro' => $err ?? ('sem resposta (HTTP ' . $status . ')')] + $vazio;
        }

        $ret = self::extrairRetorno((string) $body);
        if ($ret === null) {
            return ['erro' => 'resposta SOAP não reconhecida (HTTP ' . $status . ')'] + $vazio;
        }

        $ok = in_array($ret['cStat'], self::CSTAT_SUCESSO, true);

        return [
            'ok'      => $ok,
            'cStat'   => $ret['cStat'],
            'xMotivo' => $ret['xMotivo'],
            'nProt'   => $ret['nProt'],
            'erro'    => $ok ? null : ('cStat ' . $ret['cStat'] . ': ' . ($ret['xMotivo'] ?? '')),
        ];
    }

    /** @return array{cStat:?string, xMotivo:?string, nProt:?string}|null */
    private static function extrairRetorno(string $soapBody): ?array
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($soapBody, LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return null;
        }
        $xp = new DOMXPath($doc);
        $ln = static fn(string $tag) => '*[local-name()="' . $tag . '"]';
        $first = static function (string $q) use ($xp) {
            $n = $xp->query($q);
            return ($n !== false && $n->length > 0) ? trim((string) $n->item(0)->nodeValue) : null;
        };

        // cStat pode vir no retEvento (evento processado individualmente) ou
        // só no retEnvEvento (rejeição do lote inteiro, evento nem chega a
        // ser avaliado).
        $cStat = $first('//' . $ln('retEvento') . '/' . $ln('infEvento') . '/' . $ln('cStat'))
              ?? $first('//' . $ln('retEnvEvento') . '/' . $ln('cStat'));
        if ($cStat === null) {
            return null;
        }

        return [
            'cStat'   => $cStat,
            'xMotivo' => $first('//' . $ln('retEvento') . '/' . $ln('infEvento') . '/' . $ln('xMotivo'))
                      ?? $first('//' . $ln('retEnvEvento') . '/' . $ln('xMotivo')),
            'nProt'   => $first('//' . $ln('retEvento') . '/' . $ln('infEvento') . '/' . $ln('nProt')),
        ];
    }
}
