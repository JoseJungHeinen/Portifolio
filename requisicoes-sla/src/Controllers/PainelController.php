<?php
// @versao Ver.002.301026

namespace App\Controllers;

use App\Core\Controller;
use App\Services\PainelComprasService;

/** Painel de compras (fases 1 e 2). Specs: docs/2026-10-29-painel-compras-fase1.md e -fase2.md */
class PainelController extends Controller
{
    public const PERFIS = ['comprador', 'admin', 'diretor'];

    public function index(): void
    {
        $this->requirePerfil(self::PERFIS);
        $user = auth();

        $per = is_string($_GET['per'] ?? null) && isset(PainelComprasService::PERIODOS[$_GET['per']])
            ? $_GET['per'] : PainelComprasService::PERIODO_PADRAO;
        $inicio = PainelComprasService::periodoInicio($per, new \DateTimeImmutable());

        // Unidade: quem não vê todas as filiais fica preso à sua (mesmo escopo de Fiscal/Cotações).
        $filiais = (new \App\Models\Filial())->allAtivas();
        $todas   = ve_todas_filiais();
        if (!$todas) {
            $filiais = array_values(array_filter($filiais, fn($f) => $f['id'] === ($user['filial_id'] ?? null)));
        }
        $pedida   = is_string($_GET['filial'] ?? null) ? $_GET['filial'] : '';
        $filialId = in_array($pedida, array_column($filiais, 'id'), true) ? $pedida : null;
        if (!$todas) {
            $filialId = $user['filial_id'] ?? '';
        }

        $painel = (new PainelComprasService())->montar($inicio, $filialId);
        $title  = 'Painel de compras';

        $this->view('painel/index', compact('title', 'per', 'inicio', 'filiais', 'filialId', 'todas', 'painel'));
    }
}
