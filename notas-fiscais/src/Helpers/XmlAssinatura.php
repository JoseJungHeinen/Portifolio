<?php
// @versao Cap_21 V_01_21/09/2026
declare(strict_types=1);

/**
 * Assinatura digital XML (XMLDSig) no padrão exigido pela SEFAZ para eventos
 * de NF-e — enveloped signature, C14N canônico (sem comentários), SHA-1 +
 * RSA. Mesma receita usada por qualquer assinador de NFe/CTe/evento sem
 * biblioteca externa: canonicaliza o nó referenciado, assina o SignedInfo
 * canonicalizado, e anexa <Signature> como irmão seguinte do nó assinado.
 *
 * Só existe pra suportar NfeEventoClient — não é usada na distribuição
 * (nfeDistDFeInteresse), que só consulta e não exige XML assinado, apenas
 * mTLS na conexão.
 */
class XmlAssinatura
{
    private const XMLNS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';
    private const C14N_ALGO  = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    /**
     * @return array{ok:bool, xml:?string, erro:?string}
     */
    public static function assinarEvento(string $xmlSemAssinatura, string $id, string $pfxPath, string $pfxSenha): array
    {
        $vazio = ['ok' => false, 'xml' => null, 'erro' => null];

        if (!function_exists('openssl_pkcs12_read')) {
            return ['erro' => 'Extensão OpenSSL do PHP não está habilitada.'] + $vazio;
        }

        $pfxConteudo = @file_get_contents($pfxPath);
        if ($pfxConteudo === false || $pfxConteudo === '') {
            return ['erro' => 'Falha ao ler o certificado.'] + $vazio;
        }

        $certs = [];
        if (!openssl_pkcs12_read($pfxConteudo, $certs, $pfxSenha)) {
            return ['erro' => 'Senha do certificado incorreta ou .pfx inválido.'] + $vazio;
        }

        $chavePrivada = openssl_pkey_get_private($certs['pkey'] ?? '');
        if ($chavePrivada === false) {
            return ['erro' => 'Falha ao carregar a chave privada do certificado.'] + $vazio;
        }

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        if (!@$doc->loadXML($xmlSemAssinatura)) {
            return ['erro' => 'XML do evento malformado antes de assinar.'] + $vazio;
        }

        $alvo = self::buscarPorId($doc, $id);
        if ($alvo === null) {
            return ['erro' => "Elemento com Id={$id} não encontrado para assinar."] + $vazio;
        }

        // Digest do nó referenciado — C14N padrão (não exclusivo), sem
        // comentários. Precisa ser calculado ANTES de inserir a assinatura na
        // árvore (é exatamente o estado que o transform "enveloped-signature"
        // espera reconstruir na validação).
        $c14nAlvo = $alvo->C14N(false, false);
        $digest   = base64_encode(sha1($c14nAlvo, true));

        $signedInfoXml = '<SignedInfo xmlns="' . self::XMLNS_DSIG . '">'
            . '<CanonicalizationMethod Algorithm="' . self::C14N_ALGO . '"/>'
            . '<SignatureMethod Algorithm="' . self::XMLNS_DSIG . 'rsa-sha1"/>'
            . '<Reference URI="#' . $id . '">'
            . '<Transforms>'
            . '<Transform Algorithm="' . self::XMLNS_DSIG . 'enveloped-signature"/>'
            . '<Transform Algorithm="' . self::C14N_ALGO . '"/>'
            . '</Transforms>'
            . '<DigestMethod Algorithm="' . self::XMLNS_DSIG . 'sha1"/>'
            . '<DigestValue>' . $digest . '</DigestValue>'
            . '</Reference>'
            . '</SignedInfo>';

        $signedInfoDoc = new DOMDocument();
        if (!@$signedInfoDoc->loadXML($signedInfoXml)) {
            return ['erro' => 'Falha ao montar SignedInfo.'] + $vazio;
        }
        $c14nSignedInfo = $signedInfoDoc->documentElement->C14N(false, false);

        $assinatura = '';
        if (!openssl_sign($c14nSignedInfo, $assinatura, $chavePrivada, OPENSSL_ALGO_SHA1)) {
            return ['erro' => 'Falha ao assinar o evento (openssl_sign).'] + $vazio;
        }
        $signatureValue = base64_encode($assinatura);

        $certLimpo = trim((string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\r|\n/', '', (string) ($certs['cert'] ?? '')));
        if ($certLimpo === '') {
            return ['erro' => 'Certificado público não encontrado no .pfx.'] + $vazio;
        }

        $signatureXml = '<Signature xmlns="' . self::XMLNS_DSIG . '">'
            . $signedInfoXml
            . '<SignatureValue>' . $signatureValue . '</SignatureValue>'
            . '<KeyInfo><X509Data><X509Certificate>' . $certLimpo . '</X509Certificate></X509Data></KeyInfo>'
            . '</Signature>';

        $fragmento = $doc->createDocumentFragment();
        if (!@$fragmento->appendXML($signatureXml)) {
            return ['erro' => 'Falha ao anexar a assinatura no documento.'] + $vazio;
        }
        $alvo->parentNode->appendChild($fragmento);

        return ['ok' => true, 'xml' => $doc->saveXML($doc->documentElement), 'erro' => null];
    }

    private static function buscarPorId(DOMDocument $doc, string $id): ?DOMElement
    {
        foreach ($doc->getElementsByTagName('*') as $el) {
            /** @var DOMElement $el */
            if ($el->getAttribute('Id') === $id) {
                return $el;
            }
        }
        return null;
    }
}
