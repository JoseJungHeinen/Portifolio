<?php
// Usado pelo coletor automático: imprime os CNPJs configurados (JSON).
require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/captura_db.php';
echo json_encode(cnpjs_coletor());
