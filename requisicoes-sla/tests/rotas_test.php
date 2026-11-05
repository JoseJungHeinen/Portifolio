<?php
// Checagem dos helpers do Exemplo Requisições (sem suíte no projeto).
// Uso: C:\xampp\php\php.exe C:\Projetos\Requisicoes\tests\rotas_test.php
declare(strict_types=1);

require dirname(__DIR__) . '/src/helpers.php';

$falhas = 0;
function confere(string $nome, bool $ok): void {
    global $falhas;
    echo ($ok ? 'ok    ' : 'FALHA ') . $nome . PHP_EOL;
    if (!$ok) $falhas++;
}

// ── Compras (sem APP_SISTEMA): tudo como hoje ──
unset($_ENV['APP_SISTEMA'], $_ENV['ASSET_URL']);
$_ENV['APP_URL'] = 'http://compras.test';
confere('compras: sistema()',                sistema() === 'compras');
confere('compras: /estoque liberado',        rota_permitida_no_sistema('/estoque'));
confere('compras: rota inicial gestor',      rota_inicial('gestor') === '/dashboard');
confere('compras: rota inicial almoxarife',  rota_inicial('almoxarife') === '/estoque');
confere('compras: marca',                    sistema_marca()['nome'] === 'compras');
confere('compras: asset pelo APP_URL',       asset('css/app.css') === 'http://compras.test/public/assets/css/app.css');
$_SERVER['REQUEST_URI'] = '/requisicoes/nova';
confere('compras: rota_atual sem base',      rota_atual() === '/requisicoes/nova');

// ── Requisições ──
$_ENV['APP_SISTEMA'] = 'requisicoes';
$_ENV['APP_URL']     = 'http://localhost/requisicoes';
$_ENV['ASSET_URL']   = '/compras';
confere('req: sistema()',                    sistema() === 'requisicoes');
confere('req: marca',                        sistema_marca()['nome'] === 'requisições');
confere('req: asset pelo ASSET_URL',         asset('css/app.css') === '/compras/public/assets/css/app.css');

foreach (['/solicitacoes', '/solicitacoes/abc-1', '/requisicoes/nova', '/aprovacoes',
          '/compras/cotacoes', '/compras/ordens/x/pdf', '/cotacao/responder/abc', '/fiscal/1/conferir',
          '/fornecedores/novo', '/orcamentos/solicitacoes', '/login', '/logout', '/senha/alterar',
          '/anexos/notas/1', '/api/itens/1/media-preco', '/dashboard/notificacoes',
          '/dashboard/notificacoes/1/lida', '/painel',
          // cadastros liberados ao admin (o perfil é conferido no controller)
          '/configuracoes/filiais', '/configuracoes/filiais/abc-1', '/configuracoes/filiais/abc-1/toggle',
          '/configuracoes/setores', '/configuracoes/setores/abc-1/toggle', '/configuracoes/setores/abc-1/excluir',
          '/configuracoes/gestores', '/configuracoes/gestores/abc-1/toggle', '/configuracoes/alcadas'] as $uri) {
    confere("req: libera $uri", rota_permitida_no_sistema($uri));
}
foreach (['/estoque', '/brindes', '/itens', '/relatorios', '/usuarios', '/usuarios/novo',
          '/configuracoes', '/configuracoes/perfis', '/configuracoes/grupos', '/configuracoes/parametros',
          '/configuracoes/integracoes', '/configuracoes/filiaisx', '/configuracoes/alcadasx',
          '/contratos', '/orcamentos', '/transferencias/recebimento', '/auditoria-estoque',
          '/ramais', '/links-uteis', '/dashboard', '/requisicoesx', '/compras', '/painelx',
          // S4: sem estoque no Requisições
          '/api/estoque', '/api/estoque/item/1', '/api/estoque/filial/1', '/fiscal/1/comparativo-estoque'] as $uri) {
    confere("req: bloqueia $uri", !rota_permitida_no_sistema($uri));
}

$_SERVER['REQUEST_URI'] = '/requisicoes/aprovacoes?x=1';
confere('req: rota_atual tira o base',       rota_atual() === '/aprovacoes?x=1');
$_SERVER['REQUEST_URI'] = '/requisicoes/';
confere('req: rota_atual raiz',              rota_atual() === '/');

confere('req: rota inicial solicitante',     rota_inicial('solicitante') === '/solicitacoes');
confere('req: rota inicial gestor',          rota_inicial('gestor') === '/aprovacoes');
confere('req: rota inicial diretor',         rota_inicial('diretor') === '/aprovacoes');
confere('req: rota inicial comprador',       rota_inicial('comprador') === '/painel');
confere('req: rota inicial admin',           rota_inicial('admin') === '/painel');
confere('req: rota inicial almoxarife',      rota_inicial('almoxarife') === '/requisicoes');
confere('req: rota inicial sem perfil',      rota_inicial('') === '/requisicoes');

echo PHP_EOL . ($falhas ? "$falhas falha(s)" : 'tudo ok') . PHP_EOL;
exit($falhas ? 1 : 0);
