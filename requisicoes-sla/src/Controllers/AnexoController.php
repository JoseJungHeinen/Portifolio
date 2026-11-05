<?php
// @versao Ver.002.281026

namespace App\Controllers;

use App\Config\Database;
use App\Core\Controller;

/**
 * Serve os anexos de uploads/ só para quem está logado (auditoria M2). Antes os
 * arquivos eram links diretos e abriam sem login para quem tivesse a URL.
 * Só serve arquivo que esteja registrado no banco — nunca um caminho arbitrário.
 */
class AnexoController extends Controller
{
    /** pasta da URL => [tabela, coluna onde o caminho é gravado] */
    private const PASTAS = [
        'requisicoes' => ['requisicao_itens', 'anexo'],
        'brindes'     => ['requisicoes_brinde', 'autorizacao_anexo'],
    ];

    private const MIME = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    ];

    public function ver(string $pasta, string $id): void
    {
        $this->requireAuth();
        if (!isset(self::PASTAS[$pasta]) || !preg_match('/^[a-f0-9-]{36}$/', $id)) {
            $this->naoEncontrado();
        }

        [$tabela, $coluna] = self::PASTAS[$pasta];
        $stmt = Database::getInstance()->prepare(
            "SELECT {$coluna} FROM {$tabela} WHERE {$coluna} LIKE ? LIMIT 1"
        );
        $stmt->execute(["uploads/{$pasta}/{$id}.%"]);
        $caminho = (string)$stmt->fetchColumn();

        $ext  = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
        $full = ROOT . '/uploads/' . $pasta . '/' . $id . '.' . $ext;
        if ($caminho === '' || !isset(self::MIME[$ext]) || !is_file($full)) {
            $this->naoEncontrado();
        }

        session_write_close();
        header('Content-Type: ' . self::MIME[$ext]);
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: inline; filename="' . $id . '.' . $ext . '"');
        header('Cache-Control: private, max-age=3600');
        readfile($full);
        exit;
    }

    /** Converte o caminho gravado (uploads/pasta/uuid.ext) na URL protegida. */
    public static function url(string $caminho): string
    {
        if (!preg_match('#^uploads/([a-z]+)/([a-f0-9-]{36})\.[a-z0-9]+$#', $caminho, $m)) {
            return url($caminho);
        }
        return url('anexos/' . $m[1] . '/' . $m[2]);
    }

    private function naoEncontrado(): never
    {
        http_response_code(404);
        echo 'Arquivo não encontrado.';
        exit;
    }
}
