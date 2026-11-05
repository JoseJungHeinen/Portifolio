<?php
// @versao Cap_08 V_14_25/09/2026
declare(strict_types=1);

/**
 * Extrai do XML de um DF-e (NFe / CTe / NFSe / CTOs) os campos que a resposta
 * da Brobot NÃO traz no topo e que o motor de regras precisa:
 *   emitente CNPJ + nome · destinatário CNPJ · CFOP.
 *
 * Chave, número, valor, tipo e emissão vêm do JSON de topo da Brobot — aqui só
 * cobrimos o que exige o XML. Usa XPath por local-name() para ignorar namespaces
 * (SEFAZ usa namespaces distintos por modelo).
 *
 * Nunca lança: campos não encontrados voltam null; o chamador decide se a nota
 * é aproveitável (sem CNPJ de destinatário não dá para casar filial → descarta).
 */
class XmlDfeParser
{
    /** Formato da cobrança quando o documento não tem bloco <cobr>/<pag>. */
    public const COBRANCA_VAZIA = ['fatura' => null, 'duplicatas' => [], 'forma_pagamento_cod' => null];

    /**
     * @return array{emit_cnpj:?string, emit_nome:?string, dest_cnpj:?string,
     *               cfop:?string, numero:?string, valor:?float, emissao:?string,
     *               tomador_cnpj:?string, tomador_papel:?string, natureza_operacao:?string,
     *               itens:array<int, array{codigo:?string}>, cobranca:array}
     */
    public static function parse(string $xml, string $tipo): array
    {
        $vazio = ['emit_cnpj'=>null,'emit_nome'=>null,'dest_cnpj'=>null,
                  'cfop'=>null,'numero'=>null,'valor'=>null,'emissao'=>null,
                  'tomador_cnpj'=>null,'tomador_papel'=>null,'natureza_operacao'=>null,
                  'itens'=>[],'cobranca'=>self::COBRANCA_VAZIA];

        $xml = trim($xml);
        if ($xml === '') return $vazio;

        $doc = new DOMDocument();
        $ok  = @$doc->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$ok) return $vazio;
        $xp = new DOMXPath($doc);

        return match ($tipo) {
            'CTe', 'CTOs' => self::parseCte($xp) + $vazio,
            'NFSe'        => self::parseNfse($xp) + $vazio,
            'ResNFe'      => self::parseResNfe($xp) + $vazio,
            default       => self::parseNfe($xp) + $vazio, // NFe
        };
    }

    /**
     * resNFe — resumo de NF-e da distribuição (NfeDistribuicaoDFe), sem itens
     * nem protocolo. A SEFAZ entrega isso em vez do procNFe (nota completa)
     * quando o destinatário ainda não fez a manifestação. Layout achatado,
     * bem diferente da NF-e completa — não tem <emit>/<dest>, é tudo direto
     * embaixo de <resNFe>.
     */
    private static function parseResNfe(DOMXPath $xp): array
    {
        return [
            'emit_cnpj' => self::soDigitos(self::first($xp, '//*[local-name()="resNFe"]/*[local-name()="CNPJ"]')),
            'emit_nome' => self::first($xp, '//*[local-name()="resNFe"]/*[local-name()="xNome"]'),
            'numero'    => null, // resNFe não traz nNF — só a chave (extraída à parte, de chNFe)
            'emissao'   => self::data(self::first($xp, '//*[local-name()="resNFe"]/*[local-name()="dhEmi"]')),
            'valor'     => self::valor(self::first($xp, '//*[local-name()="resNFe"]/*[local-name()="vNF"]')),
        ];
    }

    private static function parseNfe(DOMXPath $xp): array
    {
        // cProd de cada det — alimenta itens_codigos (critério de prefixo do pré-fluxo)
        $itens = [];
        foreach ($xp->query('//*[local-name()="det"]/*[local-name()="prod"]/*[local-name()="cProd"]') ?: [] as $n) {
            $c = trim((string) $n->nodeValue);
            if ($c !== '') $itens[] = ['codigo' => $c];
        }

        return [
            'itens'     => $itens,
            'cobranca'  => self::cobrancaNfe($xp),
            'emit_cnpj' => self::soDigitos(self::first($xp, '//*[local-name()="emit"]/*[local-name()="CNPJ"]')),
            'emit_nome' => self::first($xp, '//*[local-name()="emit"]/*[local-name()="xNome"]'),
            'dest_cnpj' => self::soDigitos(
                self::first($xp, '//*[local-name()="dest"]/*[local-name()="CNPJ"]')
                ?? self::first($xp, '//*[local-name()="dest"]/*[local-name()="CPF"]')
            ),
            'cfop'      => self::first($xp, '(//*[local-name()="det"]/*[local-name()="prod"]/*[local-name()="CFOP"])[1]'),
            'natureza_operacao' => self::first($xp, '//*[local-name()="ide"]/*[local-name()="natOp"]'),
            'numero'    => self::first($xp, '//*[local-name()="ide"]/*[local-name()="nNF"]'),
            'emissao'   => self::data(self::first($xp, '//*[local-name()="ide"]/*[local-name()="dhEmi"]')
                                   ?? self::first($xp, '//*[local-name()="ide"]/*[local-name()="dEmi"]')),
            'valor'     => self::valor(self::first($xp, '//*[local-name()="ICMSTot"]/*[local-name()="vNF"]')),
        ];
    }

    /**
     * Bloco de cobrança da NF-e: a fatura (<cobr><fat>), as duplicatas
     * (<cobr><dup>) e a forma de pagamento declarada (<pag><detPag><tPag>).
     *
     * É o que alimenta as parcelas do processo na vinculação — cada duplicata
     * vira uma parcela com o vencimento e o valor que já vinham no XML, em vez
     * de o financeiro redigitar. Emitente que não usa duplicata (venda à vista,
     * cartão) simplesmente não manda <cobr>: duplicatas volta vazio e o
     * processo segue sem parcela, como antes.
     *
     * @return array{fatura:?array{numero:?string, valor_original:?float, desconto:?float, valor_liquido:?float},
     *               duplicatas:list<array{numero:?string, vencimento:?string, valor:?float}>,
     *               forma_pagamento_cod:?string}
     */
    private static function cobrancaNfe(DOMXPath $xp): array
    {
        $ln  = fn(string $tag) => '*[local-name()="' . $tag . '"]';
        $fat = '//' . $ln('cobr') . '/' . $ln('fat') . '/';

        $duplicatas = [];
        foreach ($xp->query('//' . $ln('cobr') . '/' . $ln('dup')) ?: [] as $dup) {
            $duplicatas[] = [
                'numero'     => self::first($xp, './' . $ln('nDup'), $dup),
                'vencimento' => self::data(self::first($xp, './' . $ln('dVenc'), $dup)),
                'valor'      => self::valor(self::first($xp, './' . $ln('vDup'), $dup)),
            ];
        }

        $nFat   = self::first($xp, $fat . $ln('nFat'));
        $fatura = $nFat === null ? null : [
            'numero'         => $nFat,
            'valor_original' => self::valor(self::first($xp, $fat . $ln('vOrig'))),
            'desconto'       => self::valor(self::first($xp, $fat . $ln('vDesc'))),
            'valor_liquido'  => self::valor(self::first($xp, $fat . $ln('vLiq'))),
        ];

        return [
            'fatura'     => $fatura,
            'duplicatas' => $duplicatas,
            // Só o primeiro detPag: nota com pagamento dividido em duas formas
            // não tem "a" forma de pagamento — quem vincula ajusta na parcela.
            'forma_pagamento_cod' => self::first(
                $xp, '(//' . $ln('pag') . '/' . $ln('detPag') . '/' . $ln('tPag') . ')[1]'
            ),
        ];
    }

    private static function parseCte(DOMXPath $xp): array
    {
        $tomador = self::tomadorCte($xp);

        return [
            'emit_cnpj' => self::soDigitos(self::first($xp, '//*[local-name()="emit"]/*[local-name()="CNPJ"]')),
            'emit_nome' => self::first($xp, '//*[local-name()="emit"]/*[local-name()="xNome"]'),
            // No CT-e o "destinatário" da nossa ótica é quem paga/recebe o serviço.
            'dest_cnpj' => self::soDigitos(
                self::first($xp, '//*[local-name()="dest"]/*[local-name()="CNPJ"]')
                ?? self::first($xp, '//*[local-name()="receb"]/*[local-name()="CNPJ"]')
                ?? self::first($xp, '//*[local-name()="rem"]/*[local-name()="CNPJ"]')
                ?? self::first($xp, '//*[local-name()="toma"]/*[local-name()="CNPJ"]')
            ),
            'cfop'      => self::first($xp, '//*[local-name()="ide"]/*[local-name()="CFOP"]'),
            'natureza_operacao' => self::first($xp, '//*[local-name()="ide"]/*[local-name()="natOp"]'),
            'numero'    => self::first($xp, '//*[local-name()="ide"]/*[local-name()="nCT"]'),
            'emissao'   => self::data(self::first($xp, '//*[local-name()="ide"]/*[local-name()="dhEmi"]')),
            'valor'     => self::valor(self::first($xp, '//*[local-name()="vPrest"]/*[local-name()="vTPrest"]')),
            'tomador_cnpj'  => $tomador['cnpj'],
            'tomador_papel' => $tomador['papel'],
        ];
    }

    /**
     * Tomador do frete no CT-e: quem paga a prestação.
     * <toma4> traz CNPJ próprio (papel 'outros'); <toma> 0..3 aponta para
     * remetente/expedidor/recebedor/destinatário — resolvemos para o CNPJ da parte.
     *
     * @return array{cnpj:?string, papel:?string}
     */
    private static function tomadorCte(DOMXPath $xp): array
    {
        $ln = fn(string $tag) => '*[local-name()="' . $tag . '"]';

        $cnpj4 = self::soDigitos(self::first($xp, '//' . $ln('toma4') . '/' . $ln('CNPJ')));
        if ($cnpj4 !== null) {
            return ['cnpj' => $cnpj4, 'papel' => 'outros'];
        }

        $toma = self::first($xp, '//' . $ln('toma3') . '/' . $ln('toma'))
             ?? self::first($xp, '//' . $ln('ide') . '/' . $ln('toma'));
        $mapa = [
            '0' => ['tag' => 'rem',   'papel' => 'remetente'],
            '1' => ['tag' => 'exped', 'papel' => 'expedidor'],
            '2' => ['tag' => 'receb', 'papel' => 'recebedor'],
            '3' => ['tag' => 'dest',  'papel' => 'destinatario'],
        ];
        $alvo = $mapa[$toma ?? ''] ?? null;
        if ($alvo === null) {
            return ['cnpj' => null, 'papel' => null];
        }

        $cnpj = self::soDigitos(
            self::first($xp, '//' . $ln($alvo['tag']) . '/' . $ln('CNPJ'))
            ?? self::first($xp, '//' . $ln($alvo['tag']) . '/' . $ln('CPF'))
        );
        return ['cnpj' => $cnpj, 'papel' => $alvo['papel']];
    }

    /**
     * NFS-e tem dois layouts em uso hoje:
     *  - Nacional (ADN/v3 Brobot): <emit><CNPJ>/<xNome> no topo (igual à NFe),
     *    <infDPS><toma><CNPJ|CPF>/<xNome> mais embaixo, valor em <vServPrest><vServ>.
     *  - ABRASF (municipal, pré-Nacional): não tem layout único — varia por
     *    prefeitura. Extração best-effort pelos nomes mais comuns.
     * Detecta pelo <emit> genérico (só existe no layout Nacional) e cai pro
     * ABRASF quando não encontra.
     */
    private static function parseNfse(DOMXPath $xp): array
    {
        $emitCnpjNac = self::first($xp, '//*[local-name()="emit"]/*[local-name()="CNPJ"]');
        if ($emitCnpjNac !== null) {
            return [
                'emit_cnpj' => self::soDigitos($emitCnpjNac),
                'emit_nome' => self::first($xp, '//*[local-name()="emit"]/*[local-name()="xNome"]'),
                'dest_cnpj' => self::soDigitos(
                    self::first($xp, '//*[local-name()="toma"]/*[local-name()="CNPJ"]')
                    ?? self::first($xp, '//*[local-name()="toma"]/*[local-name()="CPF"]')
                ),
                'cfop'      => null,
                'numero'    => self::first($xp, '//*[local-name()="nNFSe"]'),
                'emissao'   => self::data(self::first($xp, '//*[local-name()="dhProc"]')
                                       ?? self::first($xp, '//*[local-name()="dCompet"]')),
                'valor'     => self::valor(self::first($xp, '//*[local-name()="vServPrest"]/*[local-name()="vServ"]')),
            ];
        }

        $prestCnpj = self::first($xp, '//*[local-name()="Prestador"]//*[local-name()="Cnpj"]')
                  ?? self::first($xp, '//*[local-name()="PrestadorServico"]//*[local-name()="Cnpj"]');
        $tomaCnpj  = self::first($xp, '//*[local-name()="Tomador"]//*[local-name()="Cnpj"]')
                  ?? self::first($xp, '//*[local-name()="TomadorServico"]//*[local-name()="Cnpj"]');

        return [
            'emit_cnpj' => self::soDigitos($prestCnpj),
            'emit_nome' => self::first($xp, '//*[local-name()="Prestador"]//*[local-name()="RazaoSocial"]')
                        ?? self::first($xp, '//*[local-name()="PrestadorServico"]//*[local-name()="RazaoSocial"]'),
            'dest_cnpj' => self::soDigitos($tomaCnpj),
            'cfop'      => null,
            'numero'    => self::first($xp, '//*[local-name()="Numero"]'),
            'emissao'   => self::data(self::first($xp, '//*[local-name()="DataEmissao"]')),
            'valor'     => self::valor(self::first($xp, '//*[local-name()="ValorServicos"]')
                                    ?? self::first($xp, '//*[local-name()="ValorLiquidoNfse"]')),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Totais fiscais — alimenta o módulo Fiscal (IBS/CBS)
    // ─────────────────────────────────────────────────────────────

    /**
     * Lê de uma NF-e os totais que a tela Fiscal soma: produtos, valor da
     * nota e o grupo da reforma tributária (<IBSCBSTot>).
     *
     * O grupo é opcional no período de transição — em agosto/2026 cerca de
     * 12% dos XMLs recebidos ainda não o traziam. Quando falta, os valores
     * voltam ZERO (é assim que a tela mostra o fornecedor que ainda não se
     * adequou) e `tem_grupo` marca a diferença entre "mandou zerado" e "não
     * mandou o grupo" — sem isso não dá para explicar o zero depois.
     *
     * Fallback pelos itens: se o total não veio mas os itens têm
     * <gIBSCBS>, soma item a item em vez de reportar zero indevidamente.
     *
     * @return array{chave:?string, numero:?string, data_emissao:?string,
     *               emitente_cnpj:?string, emitente_nome:?string,
     *               destinatario_cnpj:?string, valor_produtos:float,
     *               valor_nota:float, base_ibs_cbs:float, valor_ibs_uf:float,
     *               valor_ibs_mun:float, valor_ibs:float, valor_cbs:float,
     *               tem_grupo:bool}|null  null = XML vazio ou ilegível
     */
    public static function fiscalNfe(string $xml): ?array
    {
        $xml = trim($xml);
        if ($xml === '') return null;

        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING)) return null;
        $xp = new DOMXPath($doc);
        $ln = fn(string $tag) => '*[local-name()="' . $tag . '"]';

        // Chave: vem do atributo Id de infNFe ("NFe" + 44 dígitos).
        $chave = self::soDigitos(
            self::first($xp, '//' . $ln('infNFe') . '/@Id')
        );
        if ($chave !== null && strlen($chave) !== 44) $chave = null;

        $emitNode = self::node($xp, '//' . $ln('emit'));
        $destNode = self::node($xp, '//' . $ln('dest'));
        $totNode  = self::node($xp, '//' . $ln('ICMSTot'));
        $ibsNode  = self::node($xp, '//' . $ln('IBSCBSTot'));

        $tot = $ibsNode !== null
            ? [
                'base'    => self::valor(self::first($xp, './' . $ln('vBCIBSCBS'), $ibsNode)) ?? 0.0,
                'ibs_uf'  => self::valor(self::first($xp, './/' . $ln('vIBSUF'),  $ibsNode)) ?? 0.0,
                'ibs_mun' => self::valor(self::first($xp, './/' . $ln('vIBSMun'), $ibsNode)) ?? 0.0,
                'ibs'     => self::valor(self::first($xp, './' . $ln('gIBS') . '/' . $ln('vIBS'), $ibsNode)),
                'cbs'     => self::valor(self::first($xp, './' . $ln('gCBS') . '/' . $ln('vCBS'), $ibsNode)) ?? 0.0,
              ]
            : self::ibsCbsPorItem($xp, $ln);

        // vIBS consolidado é opcional: quem não manda, manda UF e Mun.
        $ibs = $tot['ibs'] ?? null;
        if ($ibs === null || ($ibs == 0.0 && ($tot['ibs_uf'] + $tot['ibs_mun']) > 0)) {
            $ibs = $tot['ibs_uf'] + $tot['ibs_mun'];
        }

        return [
            'chave'             => $chave,
            'numero'            => self::first($xp, '//' . $ln('ide') . '/' . $ln('nNF')),
            'data_emissao'      => self::data(self::first($xp, '//' . $ln('ide') . '/' . $ln('dhEmi'))
                                           ?? self::first($xp, '//' . $ln('ide') . '/' . $ln('dEmi'))),
            'emitente_cnpj'     => self::soDigitos(self::first($xp, './' . $ln('CNPJ'), $emitNode)),
            'emitente_nome'     => self::first($xp, './' . $ln('xNome'), $emitNode),
            'destinatario_cnpj' => self::soDigitos(
                self::first($xp, './' . $ln('CNPJ'), $destNode)
                ?? self::first($xp, './' . $ln('CPF'), $destNode)
            ),
            'valor_produtos'    => self::valor(self::first($xp, './' . $ln('vProd'), $totNode)) ?? 0.0,
            'valor_nota'        => self::valor(self::first($xp, './' . $ln('vNF'),   $totNode)) ?? 0.0,
            'base_ibs_cbs'      => $tot['base'],
            'valor_ibs_uf'      => $tot['ibs_uf'],
            'valor_ibs_mun'     => $tot['ibs_mun'],
            'valor_ibs'         => $ibs,
            'valor_cbs'         => $tot['cbs'],
            'tem_grupo'         => $tot['tem_grupo'] ?? true,
        ];
    }

    /**
     * Soma IBS/CBS item a item — usado só quando a nota não tem o grupo
     * total. `tem_grupo` sai true se ao menos um item declarou <gIBSCBS>:
     * o emitente se adequou, apenas não consolidou no total.
     *
     * @return array{base:float, ibs_uf:float, ibs_mun:float, ibs:?float, cbs:float, tem_grupo:bool}
     */
    private static function ibsCbsPorItem(DOMXPath $xp, callable $ln): array
    {
        $det  = '//' . $ln('det') . '//' . $ln('gIBSCBS');
        $soma = fn(string $tag) => (float) $xp->evaluate('sum(' . $det . '//' . $ln($tag) . ')');

        $temGrupo = ($xp->query($det)->length ?? 0) > 0;

        return [
            'base'      => $temGrupo ? $soma('vBC') : 0.0,
            'ibs_uf'    => $temGrupo ? $soma('vIBSUF')  : 0.0,
            'ibs_mun'   => $temGrupo ? $soma('vIBSMun') : 0.0,
            'ibs'       => null,
            'cbs'       => $temGrupo ? $soma('vCBS') : 0.0,
            'tem_grupo' => $temGrupo,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Extração COMPLETA — alimenta DfeHtml (DANFE oficial / DACTE)
    // ─────────────────────────────────────────────────────────────

    /**
     * Extrai do XML todos os campos que o DANFE/DACTE (DfeHtml) exibe.
     * Retorna null quando o XML está vazio ou não parseia — o DfeHtml
     * cai no resumo montado a partir da linha capturada.
     */
    public static function detalhes(string $xml, string $tipo): ?array
    {
        $xml = trim($xml);
        if ($xml === '') return null;

        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING)) return null;
        $xp = new DOMXPath($doc);

        // resNFe (resumo da distribuição) não tem itens, número nem endereços:
        // devolver null faz o DfeHtml cair no resumo em vez de montar um DANFE
        // vazio (com emitente e destinatário iguais, lidos do mesmo nó).
        if (!in_array($tipo, ['CTe', 'CTOs', 'NFSe'], true)
            && self::node($xp, '//*[local-name()="infNFe"]') === null) {
            return null;
        }

        return match ($tipo) {
            'CTe', 'CTOs' => self::detalhesCte($xp),
            // Nacional e ABRASF (municipal) usam o mesmo DANFSe — só a
            // extração muda, ver detalhesNfseAbrasf().
            'NFSe'        => self::first($xp, '//*[local-name()="emit"]/*[local-name()="CNPJ"]') !== null
                              ? self::detalhesNfseNacional($xp) : self::detalhesNfseAbrasf($xp),
            default       => self::detalhesNfe($xp),
        };
    }

    /**
     * Grupo IBS/CBS (Reforma Tributária) em NFS-e. O schema de serviços ainda
     * não estabilizou esse grupo (ago/2026: quase nenhum prestador manda) —
     * busca pelos mesmos nomes de tag que a NF-e já usa (<gIBSCBS>/<vBC>/
     * <gIBS>/<vIBS>/<gCBS>/<vCBS>) em qualquer posição do documento. Nota sem
     * o grupo volta tudo null e o DANFSe simplesmente não mostra a seção.
     */
    private static function ibsCbsNfse(DOMXPath $xp, callable $ln): array
    {
        $grupo = self::node($xp, '//' . $ln('gIBSCBS'));
        if ($grupo === null) {
            return ['base' => null, 'ibs' => null, 'cbs' => null, 'tem_grupo' => false];
        }
        $f = fn(string $q) => self::valor(self::first($xp, $q, $grupo));
        return [
            'base'      => $f('.//' . $ln('vBC')),
            'ibs'       => $f('.//' . $ln('gIBS') . '/' . $ln('vIBS')),
            'cbs'       => $f('.//' . $ln('gCBS') . '/' . $ln('vCBS')),
            'tem_grupo' => true,
        ];
    }

    /**
     * Extração COMPLETA de uma NFS-e Nacional para o DANFSe (DfeHtml) — todos
     * os campos que o documento auxiliar mostra: cabeçalho (nº NFS-e, DPS,
     * competência), prestador com endereço e regime, tomador, serviço, valores,
     * retenções federais, IBS/CBS e informações adicionais.
     *
     * A NFS-e Nacional tem DOIS níveis e o dado calculado só existe no de fora:
     *   <infNFSe>            número, emit (com endereço), valores (vBC/vISSQN/
     *                        vLiq/xOutInf) e o grupo <IBSCBS> com os valores
     *   <infNFSe><DPS><infDPS> o que o prestador declarou: prest (regime),
     *                        toma, serv, valores/trib (tribMun/tribFed) e o
     *                        <IBSCBS> só com CST/cClassTrib/cIndOp
     * Por isso a base de cálculo, o valor do ISS e o valor líquido saem de
     * infNFSe/valores, com o infDPS como fallback.
     */
    private static function detalhesNfseNacional(DOMXPath $xp): array
    {
        $ln = fn(string $tag) => '*[local-name()="' . $tag . '"]';
        $f  = fn(string $q, ?DOMNode $ctx = null) => self::first($xp, $q, $ctx);
        $v  = fn(string $q, ?DOMNode $ctx = null) => self::valor(self::first($xp, $q, $ctx));

        $inf     = self::node($xp, '//' . $ln('infNFSe'));
        $dps     = self::node($xp, '//' . $ln('infDPS'));
        $emit    = self::node($xp, '//' . $ln('emit'));
        $prest   = $dps ? self::node($xp, './' . $ln('prest'), $dps) : null;
        $toma    = $dps ? self::node($xp, './' . $ln('toma'), $dps) : null;
        $end     = $toma ? self::node($xp, './' . $ln('end'), $toma) : null;
        $valNfse = $inf ? self::node($xp, './' . $ln('valores'), $inf) : null;   // valores CALCULADOS
        $valDps  = $dps ? self::node($xp, './' . $ln('valores'), $dps) : null;   // valores DECLARADOS

        $tribMun = $valDps ? self::node($xp, './' . $ln('trib') . '/' . $ln('tribMun'), $valDps) : null;
        $tribFed = $valDps ? self::node($xp, './' . $ln('trib') . '/' . $ln('tribFed'), $valDps) : null;
        $totTrib = $valDps ? self::node($xp, './' . $ln('trib') . '/' . $ln('totTrib'), $valDps) : null;
        $pisCof  = $tribFed ? self::node($xp, './' . $ln('piscofins'), $tribFed) : null;

        // tpRetISSQN: 1=Não retido, 2=Retido pelo tomador, 3=Retido pelo intermediário
        $tpRetIssqn = $f('./' . $ln('tpRetISSQN'), $tribMun);
        $vRetCP     = $v('./' . $ln('vRetCP'), $tribFed)   ?? 0.0;
        $vRetIrrf   = $v('./' . $ln('vRetIRRF'), $tribFed) ?? 0.0;
        $vRetCsll   = $v('./' . $ln('vRetCSLL'), $tribFed) ?? 0.0;
        $vPis       = $v('./' . $ln('vPis'), $pisCof)      ?? 0.0;
        $vCofins    = $v('./' . $ln('vCofins'), $pisCof)   ?? 0.0;

        // Nome do município a partir dos pares código/nome que o próprio XML
        // traz — sem tabela IBGE no sistema, o que não bate volta só o código.
        $cLocEmi = $f('./' . $ln('cLocEmi'), $dps);
        $cLocPre = $f('//' . $ln('locPrest') . '/' . $ln('cLocPrestacao'));
        $cLocInc = $f('./' . $ln('cLocIncid'), $inf);
        $nomesMun = [];
        foreach ([
            [$cLocEmi, $f('./' . $ln('xLocEmi'), $inf)],
            [$cLocPre, $f('./' . $ln('xLocPrestacao'), $inf)],
            [$cLocInc, $f('./' . $ln('xLocIncid'), $inf)],
        ] as [$cod, $nome]) {
            if ($cod !== null && $nome !== null) $nomesMun[$cod] = $nome;
        }
        $nomeMun = fn(?string $cod) => $cod !== null ? ($nomesMun[$cod] ?? null) : null;

        // O endNac do XML não traz UF: os 2 primeiros dígitos do código IBGE do
        // município são o código da UF.
        $ufPorCod = [
            '11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR', '15' => 'PA', '16' => 'AP', '17' => 'TO',
            '21' => 'MA', '22' => 'PI', '23' => 'CE', '24' => 'RN', '25' => 'PB', '26' => 'PE', '27' => 'AL',
            '28' => 'SE', '29' => 'BA', '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP', '41' => 'PR',
            '42' => 'SC', '43' => 'RS', '50' => 'MS', '51' => 'MT', '52' => 'GO', '53' => 'DF',
        ];
        $ufDoMun = fn(?string $cod) => $cod !== null ? ($ufPorCod[substr($cod, 0, 2)] ?? null) : null;

        // cTribNac (6 dígitos): 140101 → item 14.01 da LC 116, subitem 01
        $cTribNac = $f('//' . $ln('cServ') . '/' . $ln('cTribNac'));
        $itemLc   = ($cTribNac !== null && preg_match('/^(\d{2})(\d{2})/', $cTribNac, $m)) ? $m[1] . '.' . $m[2] : null;

        // Endereço do prestador: enderNac do <emit>; o município só tem o código
        $emitEnd  = $emit ? self::node($xp, './' . $ln('enderNac'), $emit) : null;
        $emitCMun = $emitEnd ? $f('./' . $ln('cMun'), $emitEnd) : null;

        $ibs = self::ibsCbsNfseNacional($xp, $ln, $inf, $dps, $nomeMun);

        $opSimp = $f('./' . $ln('regTrib') . '/' . $ln('opSimpNac'), $prest);
        $regEsp = $f('./' . $ln('regTrib') . '/' . $ln('regEspTrib'), $prest);

        return ['nfse' => [
            'fonte'    => 'nacional',
            'chave'    => self::soDigitos($f('./@Id', $inf)),
            'numero'   => $f('./' . $ln('nNFSe'), $inf),
            'dhProc'   => $f('./' . $ln('dhProc'), $inf),
            'dhEmi'    => $f('./' . $ln('dhEmi'), $dps),
            'dCompet'  => $f('./' . $ln('dCompet'), $dps),
            'dps_numero' => $f('./' . $ln('nDPS'), $dps),
            'dps_serie'  => $f('./' . $ln('serie'), $dps),
            'municipio_emissao'     => $f('./' . $ln('xLocEmi'), $inf),
            'municipio_emissao_cod' => $cLocEmi,
            'ambiente'  => $f('./' . $ln('tpAmb'), $dps),   // 1=produção, 2=homologação

            'prestador' => [
                'cnpj'      => self::soDigitos($f('./' . $ln('CNPJ'), $emit)),
                'nome'      => $f('./' . $ln('xNome'), $emit),
                'inscricao_municipal' => $f('./' . $ln('IM'), $emit) ?? $f('./' . $ln('IM'), $prest),
                'email'     => $f('./' . $ln('email'), $emit) ?? $f('./' . $ln('email'), $prest),
                'fone'      => $f('./' . $ln('fone'), $emit)  ?? $f('./' . $ln('fone'), $prest),
                'logradouro'  => $emitEnd ? $f('./' . $ln('xLgr'), $emitEnd) : null,
                'numero'      => $emitEnd ? $f('./' . $ln('nro'), $emitEnd) : null,
                'complemento' => $emitEnd ? $f('./' . $ln('xCpl'), $emitEnd) : null,
                'bairro'      => $emitEnd ? $f('./' . $ln('xBairro'), $emitEnd) : null,
                'municipio'   => $nomeMun($emitCMun),
                'uf'          => ($emitEnd ? $f('./' . $ln('UF'), $emitEnd) : null) ?? $ufDoMun($emitCMun),
                'cep'         => $emitEnd ? $f('./' . $ln('CEP'), $emitEnd) : null,
                // opSimpNac: 1=Não optante, 2=MEI, 3=ME/EPP
                'simples'     => $opSimp,
                'regime'      => match ($opSimp) {
                    '1' => 'Regime Normal', '2' => 'Simples Nacional - MEI',
                    '3' => 'Simples Nacional', default => null,
                },
                'reg_especial' => $regEsp,
            ],

            'tomador' => $toma ? [
                'cnpj'      => self::soDigitos($f('./' . $ln('CNPJ'), $toma)),
                'cpf'       => self::soDigitos($f('./' . $ln('CPF'), $toma)),
                'nome'      => $f('./' . $ln('xNome'), $toma),
                'fone'      => $f('./' . $ln('fone'), $toma),
                'email'     => $f('./' . $ln('email'), $toma),
                'inscricao_municipal' => $f('./' . $ln('IM'), $toma),
                'logradouro'  => $end ? $f('./' . $ln('xLgr'), $end) : null,
                'numero'      => $end ? $f('./' . $ln('nro'), $end) : null,
                'complemento' => $end ? $f('./' . $ln('xCpl'), $end) : null,
                'bairro'      => $end ? $f('./' . $ln('xBairro'), $end) : null,
                'municipio'   => $end ? $nomeMun($f('.//' . $ln('cMun'), $end)) : null,
                'municipio_cod' => $end ? $f('.//' . $ln('cMun'), $end) : null,
                'uf'          => ($end ? $f('.//' . $ln('UF'), $end) : null) ?? $ufDoMun($end ? $f('.//' . $ln('cMun'), $end) : null),
                'cep'         => $end ? $f('.//' . $ln('CEP'), $end) : null,
            ] : null,

            'servico' => [
                'discriminacao' => $f('//' . $ln('xDescServ')),
                'cTribNac'      => $cTribNac,
                'item_lc116'    => $itemLc,
                'xTribNac'      => $f('./' . $ln('xTribNac'), $inf),
                'xTribMun'      => $f('./' . $ln('xTribMun'), $inf),
                'cTribMun'      => $f('//' . $ln('cServ') . '/' . $ln('cTribMun')),
                'cNBS'          => $f('//' . $ln('cServ') . '/' . $ln('cNBS')),
                'xNBS'          => $f('./' . $ln('xNBS'), $inf),
                'municipio_prestacao'     => $f('./' . $ln('xLocPrestacao'), $inf),
                'municipio_prestacao_cod' => $cLocPre,
                'municipio_incidencia'    => $f('./' . $ln('xLocIncid'), $inf),
                'informacoes_complementares' => $f('//' . $ln('infoCompl') . '/' . $ln('xInfComp')),
            ],

            'valores' => [
                'servico'         => $v('//' . $ln('vServPrest') . '/' . $ln('vServ')),
                'liquido'         => $v('./' . $ln('vLiq'), $valNfse),
                'deducoes'        => $v('./' . $ln('vDedRed') . '/' . $ln('vDR'), $valDps)
                                  ?? $v('./' . $ln('vCalcDR'), $valNfse),
                'desconto_incond' => $v('//' . $ln('vDescCondIncond') . '/' . $ln('vDescIncond')),
                'desconto_cond'   => $v('//' . $ln('vDescCondIncond') . '/' . $ln('vDescCond')),
            ],

            'tributacao' => [
                // 1=Exigível, 2=Não incidência, 3=Isenção, 4=Exportação, 5=Imunidade,
                // 6/7=Exigibilidade suspensa (decisão judicial / processo administrativo)
                'issqn_situacao'  => $f('./' . $ln('tribISSQN'), $tribMun),
                'issqn_retido'    => $tpRetIssqn === '2' || $tpRetIssqn === '3',
                'issqn_retencao'  => $tpRetIssqn,
                'issqn_aliquota'  => $v('./' . $ln('pAliqAplic'), $valNfse) ?? $v('./' . $ln('pAliq'), $tribMun),
                'base_calculo'    => $v('./' . $ln('vBC'), $valNfse) ?? $v('./' . $ln('vBC'), $tribMun),
                'valor_iss'       => $v('./' . $ln('vISSQN'), $valNfse) ?? $v('./' . $ln('vISSQN'), $tribMun),
                'credito_iptu'    => null,
                // totTrib > vTotTrib > vTotTribFed/Est/Mun — uma camada a mais
                // que o nome do bloco pai sugere.
                'tot_trib_fed'    => $v('.//' . $ln('vTotTribFed'), $totTrib) ?? 0.0,
                'tot_trib_est'    => $v('.//' . $ln('vTotTribEst'), $totTrib) ?? 0.0,
                'tot_trib_mun'    => $v('.//' . $ln('vTotTribMun'), $totTrib) ?? 0.0,
                'ret_inss'        => $vRetCP,
                'ret_irrf'        => $vRetIrrf,
                'ret_csll'        => $vRetCsll,
                'ret_pis'         => $vPis,
                'ret_cofins'      => $vCofins,
                'tem_retencao'    => in_array($tpRetIssqn, ['2', '3'], true)
                                     || $vRetCP > 0 || $vRetIrrf > 0 || $vRetCsll > 0 || $vPis > 0 || $vCofins > 0,
                'ibs_cbs'         => $ibs,
            ],

            'outras_info' => $f('./' . $ln('xOutInf'), $valNfse),
        ]];
    }

    /**
     * IBS/CBS da NFS-e Nacional. Os valores ficam no <IBSCBS> de infNFSe
     * (município de incidência, base, totais IBS UF/Município e CBS); o
     * <IBSCBS> do infDPS só declara CST, cClassTrib e cIndOp.
     *
     * @return array{tem_grupo:bool, base:?float, ibs:?float, ibs_uf:?float,
     *               ibs_mun:?float, cbs:?float, municipio_incidencia:?string,
     *               cst:?string, cClassTrib:?string, cIndOp:?string}
     */
    private static function ibsCbsNfseNacional(DOMXPath $xp, callable $ln, ?DOMNode $inf, ?DOMNode $dps, callable $nomeMun): array
    {
        $f = fn(string $q, ?DOMNode $ctx = null) => self::first($xp, $q, $ctx);
        $v = fn(string $q, ?DOMNode $ctx = null) => self::valor(self::first($xp, $q, $ctx));

        $calc = $inf ? self::node($xp, './' . $ln('IBSCBS'), $inf) : null;   // valores calculados
        $decl = $dps ? self::node($xp, './' . $ln('IBSCBS'), $dps) : null;   // declarados (CST/cClassTrib)

        $cLoc = $f('./' . $ln('cLocalidadeIncid'), $calc);
        return [
            'tem_grupo' => $calc !== null || $decl !== null,
            'base'      => $v('./' . $ln('valores') . '/' . $ln('vBC'), $calc),
            'ibs'       => $v('./' . $ln('totCIBS') . '/' . $ln('gIBS') . '/' . $ln('vIBSTot'), $calc),
            'ibs_uf'    => $v('.//' . $ln('gIBSUFTot') . '/' . $ln('vIBSUF'), $calc),
            'ibs_mun'   => $v('.//' . $ln('gIBSMunTot') . '/' . $ln('vIBSMun'), $calc),
            'cbs'       => $v('./' . $ln('totCIBS') . '/' . $ln('gCBS') . '/' . $ln('vCBS'), $calc),
            'municipio_incidencia' => $f('./' . $ln('xLocalidadeIncid'), $calc) ?? $nomeMun($cLoc),
            'cst'        => $f('.//' . $ln('gIBSCBS') . '/' . $ln('CST'), $decl),
            'cClassTrib' => $f('.//' . $ln('gIBSCBS') . '/' . $ln('cClassTrib'), $decl),
            'cIndOp'     => $f('./' . $ln('cIndOp'), $decl),
        ];
    }

    /**
     * Extração best-effort de NFS-e ABRASF (municipal, pré-Nacional) para o
     * DANFSe. Cada prefeitura tem seu próprio XSD — não existe layout único.
     * Tenta os nomes de tag mais comuns entre os municípios que ainda usam
     * ABRASF v2.x; campo que não bater volta null e o DANFSe simplesmente
     * não mostra a linha (mesma filosofia da extração Nacional acima).
     */
    private static function detalhesNfseAbrasf(DOMXPath $xp): array
    {
        $ln = fn(string $tag) => '*[local-name()="' . $tag . '"]';
        $f  = fn(string $q, ?DOMNode $ctx = null) => self::first($xp, $q, $ctx);

        $prest = self::node($xp, '//' . $ln('Prestador')) ?? self::node($xp, '//' . $ln('PrestadorServico'));
        $toma  = self::node($xp, '//' . $ln('Tomador'))    ?? self::node($xp, '//' . $ln('TomadorServico'));

        $pessoa = function (?DOMNode $ctx) use ($xp, $ln, $f): array {
            return [
                'cnpj'      => self::soDigitos($f('.//' . $ln('Cnpj'), $ctx)),
                'cpf'       => self::soDigitos($f('.//' . $ln('Cpf'), $ctx)),
                'nome'      => $f('.//' . $ln('RazaoSocial'), $ctx) ?? $f('.//' . $ln('NomeFantasia'), $ctx),
                'inscricao_municipal' => $f('.//' . $ln('InscricaoMunicipal'), $ctx),
                'email'     => $f('.//' . $ln('Email'), $ctx),
                'logradouro'=> $f('.//' . $ln('Endereco'), $ctx),
                'numero'    => $f('.//' . $ln('Numero'), $ctx),
                'bairro'    => $f('.//' . $ln('Bairro'), $ctx),
                'municipio' => $f('.//' . $ln('xMunicipio'), $ctx) ?? $f('.//' . $ln('Municipio'), $ctx),
                'uf'        => $f('.//' . $ln('Uf'), $ctx),
                'cep'       => $f('.//' . $ln('Cep'), $ctx),
            ];
        };

        $issRetido = $f('//' . $ln('IssRetido')) === '1';
        $retInss   = self::valor($f('//' . $ln('ValorInss'))) ?? 0.0;
        $retIrrf   = self::valor($f('//' . $ln('ValorIr')))   ?? 0.0;
        $retCsll   = self::valor($f('//' . $ln('ValorCsll'))) ?? 0.0;

        return ['nfse' => [
            'fonte'    => 'abrasf',
            'chave'    => null,
            'codigo_verificacao' => $f('//' . $ln('CodigoVerificacao')),
            'numero'   => $f('//' . $ln('Numero')),
            'dhProc'   => $f('//' . $ln('DataEmissao')),
            'dCompet'  => null,

            'prestador' => $pessoa($prest),
            'tomador'   => $pessoa($toma),

            'servico' => [
                'discriminacao'       => $f('//' . $ln('Discriminacao')),
                'cTribNac'            => $f('//' . $ln('ItemListaServico')) ?? $f('//' . $ln('CodigoTributacaoMunicipio')),
                'cNBS'                => null,
            ],

            'valores' => [
                'servico'         => self::valor($f('//' . $ln('ValorServicos'))),
                'deducoes'        => self::valor($f('//' . $ln('ValorDeducoes'))),
                'desconto_incond' => self::valor($f('//' . $ln('DescontoIncondicionado'))),
                'desconto_cond'   => self::valor($f('//' . $ln('DescontoCondicionado'))),
            ],

            'tributacao' => [
                'issqn_situacao'  => null,
                'issqn_aliquota'  => self::valor($f('//' . $ln('Aliquota'))),
                'issqn_retido'    => $issRetido,
                'base_calculo'    => self::valor($f('//' . $ln('BaseCalculo'))),
                'valor_iss'       => self::valor($f('//' . $ln('ValorIss'))),
                'credito_iptu'    => self::valor($f('//' . $ln('ValorCreditoIptu'))) ?? self::valor($f('//' . $ln('CreditoIptu'))),
                'tot_trib_fed'    => 0.0,
                'tot_trib_est'    => 0.0,
                'tot_trib_mun'    => 0.0,
                'ret_inss'        => $retInss,
                'ret_irrf'        => $retIrrf,
                'ret_csll'        => $retCsll,
                'tem_retencao'    => $issRetido || $retInss > 0 || $retIrrf > 0 || $retCsll > 0,
                'ibs_cbs'         => self::ibsCbsNfse($xp, $ln),
            ],

            'outras_info' => $f('//' . $ln('OutrasInformacoes')) ?? $f('//' . $ln('Observacao')),
        ]];
    }

    private static function detalhesNfe(DOMXPath $xp): array
    {
        $ln = fn(string $tag) => '*[local-name()="' . $tag . '"]';

        $endereco = function (?DOMNode $ctx) use ($xp, $ln): array {
            return [
                'logradouro' => self::first($xp, './/' . $ln('xLgr'), $ctx),
                'numero'     => self::first($xp, './/' . $ln('nro'), $ctx),
                'bairro'     => self::first($xp, './/' . $ln('xBairro'), $ctx),
                'municipio'  => self::first($xp, './/' . $ln('xMun'), $ctx),
                'uf'         => self::first($xp, './/' . $ln('UF'), $ctx),
                'cep'        => self::first($xp, './/' . $ln('CEP'), $ctx),
                'fone'       => self::first($xp, './/' . $ln('fone'), $ctx),
            ];
        };

        $emitNode = self::node($xp, '//' . $ln('emit'));
        $destNode = self::node($xp, '//' . $ln('dest'));

        $emit = $endereco($emitNode) + [
            'cnpj' => self::soDigitos(self::first($xp, './' . $ln('CNPJ'), $emitNode)),
            'nome' => self::first($xp, './' . $ln('xNome'), $emitNode),
            'ie'   => self::first($xp, './' . $ln('IE'), $emitNode),
        ];
        $dest = $endereco($destNode) + [
            'cnpj' => self::soDigitos(
                self::first($xp, './' . $ln('CNPJ'), $destNode)
                ?? self::first($xp, './' . $ln('CPF'), $destNode)
            ),
            'nome' => self::first($xp, './' . $ln('xNome'), $destNode),
            'ie'   => self::first($xp, './' . $ln('IE'), $destNode),
        ];

        // Totais (ICMSTot)
        $totNode = self::node($xp, '//' . $ln('ICMSTot'));
        $totais  = [];
        foreach (['vBC','vICMS','vBCST','vST','vProd','vFrete','vSeg','vDesc','vOutro','vIPI','vTotTrib','vNF'] as $t) {
            $totais[$t] = self::valor(self::first($xp, './' . $ln($t), $totNode));
        }

        // Transportador / volumes
        $transpNode = self::node($xp, '//' . $ln('transp'));
        $transp = [
            'modFrete'  => self::first($xp, './' . $ln('modFrete'), $transpNode),
            'nome'      => self::first($xp, './/' . $ln('transporta') . '/' . $ln('xNome'), $transpNode),
            'cnpj'      => self::soDigitos(self::first($xp, './/' . $ln('transporta') . '/' . $ln('CNPJ'), $transpNode)),
            'ie'        => self::first($xp, './/' . $ln('transporta') . '/' . $ln('IE'), $transpNode),
            'endereco'  => self::first($xp, './/' . $ln('transporta') . '/' . $ln('xEnder'), $transpNode),
            'municipio' => self::first($xp, './/' . $ln('transporta') . '/' . $ln('xMun'), $transpNode),
            'uf'        => self::first($xp, './/' . $ln('transporta') . '/' . $ln('UF'), $transpNode),
            'placa'     => self::first($xp, './/' . $ln('veicTransp') . '/' . $ln('placa'), $transpNode),
            'placa_uf'  => self::first($xp, './/' . $ln('veicTransp') . '/' . $ln('UF'), $transpNode),
            'rntc'      => self::first($xp, './/' . $ln('veicTransp') . '/' . $ln('RNTC'), $transpNode),
            'qVol'      => self::first($xp, './/' . $ln('vol') . '/' . $ln('qVol'), $transpNode),
            'especie'   => self::first($xp, './/' . $ln('vol') . '/' . $ln('esp'), $transpNode),
            'marca'     => self::first($xp, './/' . $ln('vol') . '/' . $ln('marca'), $transpNode),
            'numeracao' => self::first($xp, './/' . $ln('vol') . '/' . $ln('nVol'), $transpNode),
            'pesoB'     => self::first($xp, './/' . $ln('vol') . '/' . $ln('pesoB'), $transpNode),
            'pesoL'     => self::first($xp, './/' . $ln('vol') . '/' . $ln('pesoL'), $transpNode),
        ];

        // Fatura + duplicatas — mesma leitura que alimenta as parcelas
        $cobranca   = self::cobrancaNfe($xp);
        $duplicatas = $cobranca['duplicatas'];

        // Itens
        $itens = [];
        foreach ($xp->query('//' . $ln('det')) ?: [] as $det) {
            $orig = self::first($xp, './/' . $ln('ICMS') . '//' . $ln('orig'), $det) ?? '';
            $cst  = self::first($xp, './/' . $ln('ICMS') . '//' . $ln('CST'), $det)
                 ?? self::first($xp, './/' . $ln('ICMS') . '//' . $ln('CSOSN'), $det) ?? '';
            $itens[] = [
                'codigo'    => self::first($xp, './' . $ln('prod') . '/' . $ln('cProd'), $det),
                'descricao' => self::first($xp, './' . $ln('prod') . '/' . $ln('xProd'), $det),
                'ncm'       => self::first($xp, './' . $ln('prod') . '/' . $ln('NCM'), $det),
                'cst'       => $orig . $cst,
                'cfop'      => self::first($xp, './' . $ln('prod') . '/' . $ln('CFOP'), $det),
                'unidade'   => self::first($xp, './' . $ln('prod') . '/' . $ln('uCom'), $det),
                'qtd'       => self::valor(self::first($xp, './' . $ln('prod') . '/' . $ln('qCom'), $det)),
                'vUnit'     => self::valor(self::first($xp, './' . $ln('prod') . '/' . $ln('vUnCom'), $det)),
                'vTotal'    => self::valor(self::first($xp, './' . $ln('prod') . '/' . $ln('vProd'), $det)),
                'vDesc'     => self::valor(self::first($xp, './' . $ln('prod') . '/' . $ln('vDesc'), $det)),
                'vBC'       => self::valor(self::first($xp, './/' . $ln('ICMS') . '//' . $ln('vBC'), $det)),
                'vICMS'     => self::valor(self::first($xp, './/' . $ln('ICMS') . '//' . $ln('vICMS'), $det)),
                'pICMS'     => self::valor(self::first($xp, './/' . $ln('ICMS') . '//' . $ln('pICMS'), $det)),
            ];
        }

        return ['nfe' => [
            'numero'     => self::first($xp, '//' . $ln('ide') . '/' . $ln('nNF')),
            'serie'      => self::first($xp, '//' . $ln('ide') . '/' . $ln('serie')),
            'tp_nf'      => self::first($xp, '//' . $ln('ide') . '/' . $ln('tpNF')),
            'nat_op'     => self::first($xp, '//' . $ln('ide') . '/' . $ln('natOp')),
            'emissao'    => self::first($xp, '//' . $ln('ide') . '/' . $ln('dhEmi'))
                         ?? self::first($xp, '//' . $ln('ide') . '/' . $ln('dEmi')),
            'saida'      => self::first($xp, '//' . $ln('ide') . '/' . $ln('dhSaiEnt')),
            'protocolo'  => self::first($xp, '//' . $ln('infProt') . '/' . $ln('nProt')),
            'prot_data'  => self::first($xp, '//' . $ln('infProt') . '/' . $ln('dhRecbto')),
            'info_compl' => self::first($xp, '//' . $ln('infAdic') . '/' . $ln('infCpl')),
            'info_fisco' => self::first($xp, '//' . $ln('infAdic') . '/' . $ln('infAdFisco')),
            'emit'       => $emit,
            'dest'       => $dest,
            'totais'     => $totais,
            'transp'     => $transp,
            'fatura'     => $cobranca['fatura'],
            'duplicatas' => $duplicatas,
            'itens'      => $itens,
        ]];
    }

    private static function detalhesCte(DOMXPath $xp): array
    {
        $ln = fn(string $tag) => '*[local-name()="' . $tag . '"]';

        // Partes envolvidas no transporte.
        // O DACTE exige o bloco INTEIRO de cada papel (endereço, IE, CEP, país,
        // fone), não só nome e CNPJ — por isso o endereço é lido do nó filho
        // específico de cada um (enderReme, enderDest, ...) e não de um .//xMun
        // solto, que pegaria o município do primeiro descendente qualquer.
        $papeis = [
            'rem'   => ['papel' => 'Remetente',    'ender' => 'enderReme'],
            'exped' => ['papel' => 'Expedidor',    'ender' => 'enderExped'],
            'receb' => ['papel' => 'Recebedor',    'ender' => 'enderReceb'],
            'dest'  => ['papel' => 'Destinatário', 'ender' => 'enderDest'],
        ];
        $partes = [];
        foreach ($papeis as $tag => $cfg) {
            $node = self::node($xp, '//' . $ln($tag));
            if (!$node) continue;
            $partes[] = self::parteCte($xp, $node, $cfg['papel'], $cfg['ender'], $ln);
        }

        // Tomador: <toma> 0..3 aponta para uma das partes; toma4 traz CNPJ próprio
        $tomadorCnpj = self::soDigitos(self::first($xp, '//' . $ln('toma4') . '/' . $ln('CNPJ')));
        if ($tomadorCnpj === null) {
            $toma  = self::first($xp, '//' . $ln('toma3') . '/' . $ln('toma'))
                  ?? self::first($xp, '//' . $ln('ide') . '/' . $ln('toma'));
            $mapa  = ['0' => 'rem', '1' => 'exped', '2' => 'receb', '3' => 'dest'];
            $chave = $mapa[$toma ?? ''] ?? null;
            if ($chave !== null) {
                // $papeis passou a guardar ['papel' => ..., 'ender' => ...];
                // comparar com o array inteiro nunca casaria.
                $papelAlvo = $papeis[$chave]['papel'];
                foreach ($partes as $p) {
                    if ($p['papel'] === $papelAlvo) { $tomadorCnpj = $p['cnpj']; break; }
                }
            }
        }

        // Chaves das NF-e transportadas
        $chaves = [];
        foreach ($xp->query('//' . $ln('infNFe') . '/' . $ln('chave')) ?: [] as $c) {
            $v = trim((string) $c->nodeValue);
            if ($v !== '') $chaves[] = $v;
        }

        // Veículos novos transportados
        $veiculos = [];
        foreach ($xp->query('//' . $ln('veicNovos')) ?: [] as $v) {
            $veiculos[] = [
                'chassi' => self::first($xp, './' . $ln('chassi'), $v),
                'modelo' => self::first($xp, './' . $ln('cMod'), $v),
                'cor'    => self::first($xp, './' . $ln('xCor'), $v)
                         ?? self::first($xp, './' . $ln('cCor'), $v),
                'valor'  => self::valor(self::first($xp, './' . $ln('vUnit'), $v)),
                'frete'  => self::valor(self::first($xp, './' . $ln('vFrete'), $v)),
            ];
        }

        // Composição do valor da prestação
        $componentes = [];
        foreach ($xp->query('//' . $ln('vPrest') . '/' . $ln('Comp')) ?: [] as $c) {
            $componentes[] = [
                'nome'  => self::first($xp, './' . $ln('xNome'), $c),
                'valor' => self::valor(self::first($xp, './' . $ln('vComp'), $c)),
            ];
        }

        $munOrigem  = trim(implode('/', array_filter([
            self::first($xp, '//' . $ln('ide') . '/' . $ln('xMunIni')),
            self::first($xp, '//' . $ln('ide') . '/' . $ln('UFIni')),
        ])));
        $munDestino = trim(implode('/', array_filter([
            self::first($xp, '//' . $ln('ide') . '/' . $ln('xMunFim')),
            self::first($xp, '//' . $ln('ide') . '/' . $ln('UFFim')),
        ])));

        // ── Blocos exigidos pelo DACTE ────────────────────────────
        $ide  = self::node($xp, '//' . $ln('infCte') . '/' . $ln('ide'));
        $emit = self::node($xp, '//' . $ln('emit'));

        // Documentos originários: a chave sozinha não basta — o DACTE mostra
        // série/número, que ficam embutidos na própria chave (pos. 22-25 e 26-34).
        $documentos = [];
        foreach ($chaves as $ch) {
            $documentos[] = [
                'tipo'   => 'NF-e',
                'chave'  => $ch,
                'serie'  => strlen($ch) === 44 ? substr($ch, 22, 3) : null,
                'numero' => strlen($ch) === 44 ? substr($ch, 25, 9) : null,
            ];
        }

        // Carga: infQ vem como lista de medidas (PESO REAL, CUBAGEM, VOLUMES...)
        $medidas = [];
        foreach ($xp->query('//' . $ln('infCarga') . '/' . $ln('infQ')) ?: [] as $q) {
            $tp = self::first($xp, './' . $ln('tpMed'), $q);
            if ($tp !== null) {
                $medidas[mb_strtoupper($tp)] = self::valor(self::first($xp, './' . $ln('qCarga'), $q));
            }
        }

        // CT-e complementado (tpCTe = 1): chave do documento que este complementa
        $complementados = [];
        foreach ($xp->query('//' . $ln('infCteComp')) ?: [] as $c) {
            $chc = self::soDigitos(self::first($xp, './' . $ln('chCTe'), $c));
            if ($chc !== null) {
                $complementados[] = ['chave' => $chc];
            }
        }

        // Observações do campo livre + os pares xCampo/xTexto do ObsCont
        $obs = [];
        $xObs = self::first($xp, '//' . $ln('compl') . '/' . $ln('xObs'));
        if ($xObs !== null) $obs[] = $xObs;
        foreach ($xp->query('//' . $ln('ObsCont')) ?: [] as $o) {
            $campo = self::first($xp, './' . $ln('xCampo'), $o);
            $texto = self::first($xp, './' . $ln('xTexto'), $o);
            if ($campo !== null && $texto !== null) $obs[] = "{$campo}: {$texto}";
        }

        return [
            'partes'     => $partes,
            'tomador'    => ['cnpj' => $tomadorCnpj] + (self::tomadorBlocoCte($xp, $tomadorCnpj, $partes, $ln) ?: []),
            'trajeto'    => ($munOrigem !== '' || $munDestino !== '')
                          ? ['origem' => $munOrigem ?: '—', 'destino' => $munDestino ?: '—'] : null,
            'nfe_chaves' => $chaves,
            'veiculos'   => $veiculos,
            'prestacao'  => [
                'total'       => self::valor(self::first($xp, '//' . $ln('vPrest') . '/' . $ln('vTPrest'))),
                'receber'     => self::valor(self::first($xp, '//' . $ln('vPrest') . '/' . $ln('vRec'))),
                'componentes' => $componentes,
            ],
            'cte' => [
                'emitente' => $emit ? [
                    'nome'      => self::first($xp, './' . $ln('xNome'), $emit),
                    'fantasia'  => self::first($xp, './' . $ln('xFant'), $emit),
                    'cnpj'      => self::soDigitos(self::first($xp, './' . $ln('CNPJ'), $emit)
                                ?? self::first($xp, './' . $ln('CPF'), $emit)),
                    'ie'        => self::first($xp, './' . $ln('IE'), $emit),
                    'fone'      => self::first($xp, './/' . $ln('fone'), $emit),
                    'logradouro'=> self::first($xp, './/' . $ln('xLgr'), $emit),
                    'numero'    => self::first($xp, './/' . $ln('nro'), $emit),
                    'bairro'    => self::first($xp, './/' . $ln('xBairro'), $emit),
                    'municipio' => self::first($xp, './/' . $ln('xMun'), $emit),
                    'uf'        => self::first($xp, './/' . $ln('UF'), $emit),
                    'cep'       => self::soDigitos(self::first($xp, './/' . $ln('CEP'), $emit)),
                ] : null,
                'modelo'      => $ide ? self::first($xp, './' . $ln('mod'), $ide) : null,
                'serie'       => $ide ? self::first($xp, './' . $ln('serie'), $ide) : null,
                'numero'      => $ide ? self::first($xp, './' . $ln('nCT'), $ide) : null,
                'emissao'     => $ide ? self::first($xp, './' . $ln('dhEmi'), $ide) : null,
                'cfop'        => $ide ? self::first($xp, './' . $ln('CFOP'), $ide) : null,
                'natureza'    => $ide ? self::first($xp, './' . $ln('natOp'), $ide) : null,
                'modal'       => $ide ? self::first($xp, './' . $ln('modal'), $ide) : null,
                'tipo_cte'    => $ide ? self::first($xp, './' . $ln('tpCTe'), $ide) : null,
                'tipo_servico'=> $ide ? self::first($xp, './' . $ln('tpServ'), $ide) : null,
                'globalizado' => self::first($xp, '//' . $ln('indGlobalizado')),
                'protocolo'   => self::first($xp, '//' . $ln('infProt') . '/' . $ln('nProt')),
                'prot_data'   => self::first($xp, '//' . $ln('infProt') . '/' . $ln('dhRecbto')),
                'imposto'     => self::impostoCte($xp, $ln),
                'carga'       => [
                    'produto'  => self::first($xp, '//' . $ln('infCarga') . '/' . $ln('proPred')),
                    'outras'   => self::first($xp, '//' . $ln('infCarga') . '/' . $ln('xOutCat')),
                    'valor'    => self::valor(self::first($xp, '//' . $ln('infCarga') . '/' . $ln('vCarga'))),
                    'medidas'  => $medidas,
                ],
                'documentos'     => $documentos,
                'complementados' => $complementados,
                'observacoes'    => $obs,
                'rntrc'          => self::first($xp, '//' . $ln('rodo') . '/' . $ln('RNTRC')),
            ],
        ];
    }

    /** Bloco completo de uma parte do CT-e (remetente, destinatário, ...). */
    private static function parteCte(DOMXPath $xp, DOMNode $node, string $papel, string $tagEnder, callable $ln): array
    {
        $end = self::node($xp, './' . $ln($tagEnder), $node);
        return [
            'papel'      => $papel,
            'nome'       => self::first($xp, './' . $ln('xNome'), $node),
            'cnpj'       => self::soDigitos(self::first($xp, './' . $ln('CNPJ'), $node)
                         ?? self::first($xp, './' . $ln('CPF'), $node)),
            'ie'         => self::first($xp, './' . $ln('IE'), $node),
            'fone'       => self::first($xp, './' . $ln('fone'), $node),
            'logradouro' => $end ? self::first($xp, './' . $ln('xLgr'), $end) : null,
            'numero'     => $end ? self::first($xp, './' . $ln('nro'), $end) : null,
            'complemento'=> $end ? self::first($xp, './' . $ln('xCpl'), $end) : null,
            'bairro'     => $end ? self::first($xp, './' . $ln('xBairro'), $end) : null,
            'municipio'  => $end ? self::first($xp, './' . $ln('xMun'), $end) : null,
            'uf'         => $end ? self::first($xp, './' . $ln('UF'), $end) : null,
            'cep'        => $end ? self::soDigitos(self::first($xp, './' . $ln('CEP'), $end)) : null,
            'pais'       => $end ? self::first($xp, './' . $ln('xPais'), $end) : null,
        ];
    }

    /**
     * Bloco do tomador. Quando <toma4> existe ele traz dados próprios; senão o
     * tomador é uma das partes já lidas, e o DACTE repete os dados dela.
     */
    private static function tomadorBlocoCte(DOMXPath $xp, ?string $tomadorCnpj, array $partes, callable $ln): ?array
    {
        $toma4 = self::node($xp, '//' . $ln('toma4'));
        if ($toma4) {
            return self::parteCte($xp, $toma4, 'Tomador', 'enderToma', $ln);
        }
        if ($tomadorCnpj !== null) {
            foreach ($partes as $p) {
                if (($p['cnpj'] ?? null) === $tomadorCnpj) {
                    return ['papel' => 'Tomador'] + $p;
                }
            }
        }
        return null;
    }

    /**
     * ICMS do CT-e. O grupo varia conforme a tributação (ICMS00, ICMS20,
     * ICMS45, ICMS60, ICMS90, ICMSOutraUF, ICMSSN) — em vez de listar todos,
     * lê o primeiro filho de <ICMS> e tira dele os campos que existirem.
     */
    private static function impostoCte(DOMXPath $xp, callable $ln): array
    {
        $grupo = self::node($xp, '//' . $ln('imp') . '/' . $ln('ICMS') . '/*');
        if (!$grupo) {
            return ['cst' => null, 'bc' => null, 'aliquota' => null, 'valor' => null, 'reducao' => null, 'st' => null];
        }
        return [
            'cst'      => self::first($xp, './' . $ln('CST'), $grupo),
            'bc'       => self::valor(self::first($xp, './' . $ln('vBC'), $grupo)),
            'aliquota' => self::valor(self::first($xp, './' . $ln('pICMS'), $grupo)),
            'valor'    => self::valor(self::first($xp, './' . $ln('vICMS'), $grupo)),
            'reducao'  => self::valor(self::first($xp, './' . $ln('pRedBC'), $grupo)),
            'st'       => self::valor(self::first($xp, './' . $ln('vICMSST'), $grupo)),
        ];
    }

    // ── util ──────────────────────────────────────────────────────

    private static function node(DOMXPath $xp, string $query, ?DOMNode $ctx = null): ?DOMNode
    {
        $nodes = $ctx ? $xp->query($query, $ctx) : $xp->query($query);
        return ($nodes !== false && $nodes->length > 0) ? $nodes->item(0) : null;
    }

    private static function first(DOMXPath $xp, string $query, ?DOMNode $ctx = null): ?string
    {
        $nodes = $ctx ? $xp->query($query, $ctx) : $xp->query($query);
        if ($nodes === false || $nodes->length === 0) return null;
        $v = trim((string) $nodes->item(0)->nodeValue);
        return $v === '' ? null : $v;
    }

    private static function soDigitos(?string $v): ?string
    {
        if ($v === null) return null;
        $d = preg_replace('/\D+/', '', $v);
        return $d === '' ? null : $d;
    }

    /** '2026-07-10T13:20:00-03:00' → '2026-07-10' */
    private static function data(?string $v): ?string
    {
        if ($v === null) return null;
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $v, $m)) return $m[1];
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private static function valor(?string $v): ?float
    {
        if ($v === null) return null;
        return (float) str_replace(',', '.', $v);
    }
}
