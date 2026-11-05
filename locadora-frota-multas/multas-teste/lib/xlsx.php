<?php
/**
 * Leitor mínimo de .xlsx (só valores), sem bibliotecas externas:
 * usa ZipArchive + XMLReader, que já vêm no PHP do XAMPP.
 * Lê os valores calculados pelo Excel (o <v> de células com fórmula).
 */

function xlsx_abas(string $arquivo): array
{
    $zip = new ZipArchive();
    if ($zip->open($arquivo) !== true) throw new RuntimeException('Não foi possível abrir o arquivo .xlsx');
    $wb = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
    $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
    $alvo = [];
    foreach ($rels->Relationship as $r) $alvo[(string)$r['Id']] = (string)$r['Target'];
    $abas = [];
    foreach ($wb->sheets->sheet as $s) {
        $rid = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $t = ltrim($alvo[$rid] ?? '', '/');
        $abas[(string)$s['name']] = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
    }
    $zip->close();
    return $abas;
}

/**
 * @return array<int, array<int, string|null>> linhas (a partir da 1) → colunas (0 = A)
 */
function xlsx_ler_aba(string $arquivo, string $aba, int $maxColunas = 30): array
{
    $abas = xlsx_abas($arquivo);
    if (!isset($abas[$aba])) throw new RuntimeException("Aba \"$aba\" não encontrada. Abas: " . implode(', ', array_keys($abas)));

    $zip = new ZipArchive();
    $zip->open($arquivo);
    $compart = [];
    if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $x = new XMLReader();
        $x->XML($ss);
        // Avança até o 1º <si> e depois pula de irmão em irmão com next().
        while ($x->read() && !($x->nodeType === XMLReader::ELEMENT && $x->localName === 'si'));
        while ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'si') {
            $si = simplexml_load_string($x->readOuterXml());
            $txt = '';
            foreach ($si->xpath('.//*[local-name()="t"]') as $t) $txt .= (string)$t;
            $compart[] = $txt;
            if (!$x->next('si')) break;
        }
    }
    $xml = $zip->getFromName($abas[$aba]);
    $zip->close();

    $linhas = [];
    $x = new XMLReader();
    $x->XML($xml);
    while ($x->read() && !($x->nodeType === XMLReader::ELEMENT && $x->localName === 'row'));
    while ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'row') {
        $row = simplexml_load_string($x->readOuterXml());
        $n = (int)$row['r'];
        $cols = [];
        foreach ($row->c as $c) {
            $col = xlsx_coluna((string)$c['r']);
            if ($col >= $maxColunas) continue;
            $tipo = (string)$c['t'];
            if ($tipo === 's') $v = $compart[(int)$c->v] ?? null;
            elseif ($tipo === 'inlineStr') $v = (string)$c->is->t;
            else $v = isset($c->v) ? (string)$c->v : null;
            $cols[$col] = $v;
        }
        $linhas[$n] = $cols;
        if (!$x->next('row')) break;
    }
    return $linhas;
}

function xlsx_coluna(string $ref): int
{
    preg_match('~^([A-Z]+)~', $ref, $m);
    $n = 0;
    foreach (str_split($m[1]) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}
