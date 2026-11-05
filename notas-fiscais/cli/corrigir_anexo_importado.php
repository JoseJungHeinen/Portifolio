<?php
// @versao Imp_04 V_01_10/08/2026
declare(strict_types=1);

/**
 * Corrige o tipo dos anexos gravados pela importação de PDFs — CLI (pontual).
 *
 * Até a correção do ImportController, todo PDF importado entrava com o rótulo
 * fixo "Documento importado (PDF)". Como a checagem de obrigatórios compara
 * nome com nome, o processo ficava com a nota anexada E com "Nota Fiscal"
 * pendente no checklist — foi o que apareceu no PROC-2026-8208.
 *
 * Este script relê cada anexo com o rótulo antigo, descobre no template do
 * processo qual é o tipo da nota (mesma regra do controller, via
 * `Template::tipoAnexoDaNota`) e renomeia o `tipo_anexo_nome`. O ARQUIVO não é
 * tocado: muda só a classificação.
 *
 * O que ele NÃO faz:
 *   · não mexe em anexo de processo cujo template não tem tipo de nota — sem
 *     destino, renomear seria inventar um slot que o template não pede;
 *   · não apaga nem substitui anexo nenhum. Se o processo já tiver um anexo no
 *     tipo de destino (alguém subiu o mesmo PDF de novo para matar a
 *     pendência), o script renomeia e AVISA — a limpeza da cópia é decisão de
 *     quem olha o processo, não do backfill.
 *
 * Uso:
 *   php cli/corrigir_anexo_importado.php                  simula e mostra o que faria
 *   php cli/corrigir_anexo_importado.php --aplicar        grava
 *   php cli/corrigir_anexo_importado.php --processo=PROC-2026-8208
 *   php cli/corrigir_anexo_importado.php --aplicar --processo=PROC-2026-8208
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
         WHERE a.tipo_anexo_nome = ?
           AND a.deletado_em IS NULL
           AND p.deletado_em IS NULL";
$params = [Template::ANEXO_SEM_TIPO];
if ($processo !== '') {
    $sql .= " AND p.codigo = ?";
    $params[] = $processo;
}
$sql .= " ORDER BY p.codigo";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$anexos = $stmt->fetchAll();

$log(sprintf('%d anexo(s) com o rótulo "%s".', count($anexos), Template::ANEXO_SEM_TIPO));

$stmtUpdate = $pdo->prepare("UPDATE nf_processo_anexos SET tipo_anexo_nome = ? WHERE id = ?");
$stmtJaTem  = $pdo->prepare(
    "SELECT COUNT(*) FROM nf_processo_anexos
      WHERE processo_id = ? AND tipo_anexo_nome = ? AND id <> ? AND deletado_em IS NULL"
);

$corrigidos = 0;
$semDestino = [];
$duplicados = [];

foreach ($anexos as $anx) {
    $destino = Template::tipoAnexoDaNota($anx['tipos_anexo']);

    if ($destino === Template::ANEXO_SEM_TIPO) {
        $semDestino[] = (string) $anx['codigo'];
        continue;
    }

    $stmtJaTem->execute([$anx['processo_id'], $destino, $anx['id']]);
    $jaTem = (int) $stmtJaTem->fetchColumn() > 0;

    $stmtUpdate->execute([$destino, $anx['id']]);

    $detalhe = sprintf(
        'Anexo "%s" reclassificado de "%s" para "%s" — o PDF da importação é a nota do processo.',
        $anx['nome_original'],
        Template::ANEXO_SEM_TIPO,
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

$log(sprintf('Corrigidos: %d · sem tipo de nota no template: %d', $corrigidos, count($semDestino)));

if ($semDestino) {
    $log('Sem destino — template não tem tipo de nota; anexo mantido como está:');
    foreach (array_unique($semDestino) as $c) $log('  · ' . $c);
}
if ($duplicados) {
    $log('Conferir — processo ficou com mais de um anexo no tipo da nota (o importado e outro que já existia):');
    foreach (array_unique($duplicados) as $c) $log('  · ' . $c);
}

if ($aplicar) {
    $pdo->commit();
    $log('Alterações gravadas.');
} else {
    $pdo->rollBack();
    $log('Simulação encerrada — nada foi gravado. Rode com --aplicar para valer.');
}
