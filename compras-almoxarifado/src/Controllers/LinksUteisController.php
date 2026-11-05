<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Config\Database;

class LinksUteisController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $db   = Database::getInstance();
        $stmt = $db->query(
            "SELECT * FROM links_uteis WHERE ativo = 1 ORDER BY grupo ASC, ordem ASC, id ASC"
        );
        $todos  = $stmt->fetchAll();
        $grupos = [];
        foreach ($todos as $l) {
            $grupos[$l['grupo']][] = $l;
        }

        $this->view('links-uteis/index', compact('grupos'));
    }
}
