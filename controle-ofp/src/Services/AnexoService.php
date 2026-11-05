<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Ofp;
use App\Models\OfpAnexo;
use App\Support\RegraException;
use finfo;
use Throwable;

class AnexoService
{
    public const TIPOS = ['foto' => 'Foto', 'cte' => 'CTe ressalvado', 'nf_pdf' => 'NF (PDF)', 'nf_xml' => 'NF (XML)', 'outro' => 'Outro'];

    /** Extensão => MIMEs reais aceitos (checados por magic bytes, não pelo navegador). */
    private const PERMITIDOS = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'pdf'  => ['application/pdf'],
        'xml'  => ['text/xml', 'application/xml', 'text/plain'],
    ];
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** Converte $_FILES['campo'] (simples ou múltiplo) numa lista de arquivos. */
    public static function lista(?array $files): array
    {
        if (!$files || !isset($files['name'])) {
            return [];
        }
        if (!is_array($files['name'])) {
            return [$files];
        }
        $lista = [];
        foreach ($files['name'] as $i => $nome) {
            $lista[] = [
                'name' => $nome, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i], 'size' => $files['size'][$i],
            ];
        }
        return $lista;
    }

    /** @return string[] mensagens de arquivos recusados */
    public function salvar(string $ofpId, array $arquivos, string $tipo, ?string $usuarioId): array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/', $ofpId)) {
            throw new RegraException('OFP não encontrada.');
        }
        $ofp = (new Ofp())->find($ofpId);
        if (!$ofp || !unidade_permitida($ofp['unidade_id'])) {
            throw new RegraException('OFP não encontrada.');
        }
        $tipo = isset(self::TIPOS[$tipo]) ? $tipo : 'outro';
        $erros = [];
        foreach ($arquivos as $f) {
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $nome = basename((string)$f['name']);
            $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
            if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
                $erros[] = "{$nome}: maior que 10 MB.";
                continue;
            }
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $erros[] = "{$nome}: falha no envio.";
                continue;
            }
            if ($f['size'] > self::MAX_BYTES) {
                $erros[] = "{$nome}: maior que 10 MB.";
                continue;
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            if (!isset(self::PERMITIDOS[$ext]) || !in_array($mime, self::PERMITIDOS[$ext], true)) {
                $erros[] = "{$nome}: tipo não permitido (use JPG, PNG, PDF ou XML).";
                continue;
            }
            $dir = ROOT . '/storage/anexos/' . $ofpId;
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RegraException('Não foi possível criar a pasta de anexos.');
            }
            $arquivo = uuid() . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $arquivo)) {
                $erros[] = "{$nome}: não foi possível gravar.";
                continue;
            }
            try {
                (new OfpAnexo())->create([
                    'ofp_id' => $ofpId, 'tipo' => $tipo, 'caminho' => $ofpId . '/' . $arquivo,
                    'nome_original' => mb_substr($nome, 0, 200), 'usuario_id' => $usuarioId,
                ]);
            } catch (Throwable $e) {
                @unlink($dir . '/' . $arquivo);
                throw $e;
            }
        }
        return $erros;
    }
}
