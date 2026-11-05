<?php
// @versao Cap_24 V_01_28/09/2026
declare(strict_types=1);

/**
 * Corrige o tipo dos anexos gravados pela CAPTURA automática (Brobot/SEFAZ) —
 * CLI (pontual, backfill). Irmão do `cli/corrigir_anexo_importado.php`
 * (10/08/2026), que já corrigiu o mesmo problema no fluxo de importação
 * manual de PDF — este cobre o outro caminho que ficou de fora na época.
 *
 * Até a correção de `CapturedNote::anexarDocumentosFiscais`, todo DANFE/DACTE
 * anexado pela captura entrava com o rótulo fixo "DANFE"/"DACTE", ignorando o
 * nome que o template deu ao slot da nota fiscal na checklist. Como a checagem
 * de obrigatórios compara nome com nome, o processo ficava com a nota
 * anexada E "Nota Fiscal" (ou o nome que o template usa) pendente ao mesmo
 * tempo — pendência de mentira. Achado no PROC-2026-14487 (28/09/2026).
 *
 * Este script relê cada anexo DANFE/DACTE, descobre no template do processo
 * qual é o tipo da nota (`Template::tipoAnexoDaNota`) e renomeia o
 * `tipo_anexo_nome`. O ARQUIVO não é tocado: muda só a classificação.
 * XML não é mexido — nenhum template usa esse nome de slot, e a captura já
 * está correta pra ele (feature nova mantém "XML" como está).
 *
 * O que ele NÃO faz:
 *   · não mexe em anexo de processo cujo template não tem tipo de nota — sem
 *     destino, renomear seria inventar um slot que o template não pede;
 *   · não apaga nem substitui anexo nenhum. Se o processo já tiver um anexo no
 *     tipo de destino (alguém subiu de novo o mesmo documento pra matar a
 *     pendência manualmente), o script renomeia e AVISA — a limpeza da cópia é
 *     decisão de quem olha o processo, não do backfill.
 *
 * Uso:
 *   php cli/corrigir_anexo_captura.php                  simula e mostra o que faria
 *   php cli/corrigir_anexo_captura.php --aplicar         grava
 *   php cli/corrigir_anexo_captura.php --processo=PROC-2026-14487
 *   php cli/corrigir_anexo_captura.php --aplicar --processo=PROC-2026-14487
 *
 * Sem --aplicar tudo roda dentro de uma transação que termina em ROLLBACK:
 * o relatório é o mesmo, o banco fica intacto.
 */

define('ROOT', dirname(__DIR__));
define('SRC', ROOT . '/src');
define('STORAGE', ROOT . '/storage');
date_default_timezone_set('America/Sao_Paulo');

require ROOT . '/config/database.php';
require ROOT . '/src/Models/Template.php';
require ROOT . '/src/Models/Process.php';

$args     = array_slice($argv, 1);
$aplicar  = in_array('--aplicar', $args, true);
$processo = '';
foreach ($args as $a) {
    if (preg_match('/^--processo=(.+)$/', $a, $m)) $processo = trim($m[1]);
}

$log = static function (string $msg): void {
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
};

$pdo = Database::getInstance();
$pdo->beginTransaction();

$log($aplicar ? 'Modo APLICAR — as alterações serão gravadas.' : 'Modo SIMULAÇÃO — nada será gravado (use --aplicar).');
if ($processo !== '') $log('Recorte: apenas ' . $processo);

$sql = "SELECT a.id, a.nome_original, a.tipo_anexo_nome,
               p.id AS processo_id, p.codigo, p.status, p.criado_por, p.etapa_atual,
               v.tipos_anexo
          FROM nf_processo_anexos a
          JOIN nf_processos p        ON p.id = a.processo_id
          JOIN nf_template_versoes v ON v.id = p.template_versao_id
         WHERE a.tipo_anexo_nome IN ('DANFE', 'DACTE')
           AND a.deletado_em IS NULL
           AND p.deletado_em IS NULL";
$params = [];
if ($processo !== '') {
    $sql .= " AND p.codigo = ?";
    $params[] = $processo;
}
$sql .= " ORDER BY p.codigo";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$anexos = $stmt->fetchAll();

$log(sprintf('%d anexo(s) com rótulo "DANFE"/"DACTE".', count($anexos)));

$stmtUpdate = $pdo->prepare("UPDATE nf_processo_anexos SET tipo_anexo_nome = ? WHERE id = ?");
$stmtJaTem  = $pdo->prepare(
    "SELECT COUNT(*) FROM nf_processo_anexos
      WHERE processo_id = ? AND tipo_anexo_nome = ? AND id <> ? AND deletado_em IS NULL"
);

$corrigidos = 0;
$semDestino = [];
$jaCorreto  = 0;
$duplicados = [];

foreach ($anexos as $anx) {
    $destino = Template::tipoAnexoDaNota($anx['tipos_anexo']);

    if ($destino === Template::ANEXO_SEM_TIPO) {
        $semDestino[] = (string) $anx['codigo'];
        continue;
    }
    if ($destino === $anx['tipo_anexo_nome']) {
        // Template chama o slot de "DANFE"/"DACTE" mesmo — já está correto.
        $jaCorreto++;
        continue;
    }

    $stmtJaTem->execute([$anx['processo_id'], $destino, $anx['id']]);
    $jaTem = (int) $stmtJaTem->fetchColumn() > 0;

    $stmtUpdate->execute([$destino, $anx['id']]);

    $detalhe = sprintf(
        'Anexo "%s" reclassificado de "%s" para "%s" — correção de rótulo da captura automática, o documento já estava anexado.',
        $anx['nome_original'],
        $anx['tipo_anexo_nome'],
        $destino
    );

    Process::registrarHistorico(
        (string) $anx['processo_id'],
        'processo_dados_alterados',
        (string) $anx['criado_por'],
        'Correção automática',
        $detalhe,
        (int) $anx['etapa_atual']
    );

    $corrigidos++;
    $log(sprintf('  %s → %s%s', $anx['codigo'], $destino, $jaTem ? '   [já tinha anexo neste tipo]' : ''));

    if ($jaTem) $duplicados[] = (string) $anx['codigo'];
}

$log(sprintf(
    'Corrigidos: %d · já corretos: %d · sem tipo de nota no template: %d',
    $corrigidos, $jaCorreto, count($semDestino)
));

if ($duplicados) {
    $log('Conferir — processo ficou com mais de um anexo no tipo da nota (o da captura e outro que já existia):');
    foreach (array_unique($duplicados) as $c) $log('  · ' . $c);
}

if ($aplicar) {
    $pdo->commit();
    $log('Alterações gravadas.');
} else {
    $pdo->rollBack();
    $log('Simulação encerrada — nada foi gravado. Rode com --aplicar para valer.');
}
