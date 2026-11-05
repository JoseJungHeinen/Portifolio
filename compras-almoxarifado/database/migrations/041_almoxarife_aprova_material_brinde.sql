-- 041_almoxarife_aprova_material_brinde.sql
-- Requisicao Interna (movimentacao de material, sem impacto financeiro
-- externo) e requisicao de Brinde passam a poder ser aprovadas pelo proprio
-- almoxarife que as criou, sem depender de gestor/diretor/admin. Compra e
-- Solicitacao de Transferencia continuam exigindo aprovacao normal.
-- Esta migration so concede a visibilidade padrao do menu "Aprovacoes"
-- (nivel ver) para o perfil almoxarife, no mesmo padrao da 038. O escopo
-- real — so a propria requisicao, so tipo interna/brinde — e aplicado em
-- AprovacaoController::almoxarifePodeDecidir() e em
-- Aprovacao::pendentesParaUsuario()/totalPendentes()/historicoParaUsuario().
INSERT INTO permissoes_perfil (perfil, modulo, acesso) VALUES
  ('almoxarife', 'aprovacoes', 'ver')
ON DUPLICATE KEY UPDATE acesso = VALUES(acesso);
