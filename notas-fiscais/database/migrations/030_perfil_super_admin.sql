-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 030 — Dois níveis de administração
--
--   super_admin → acesso completo (é o que o perfil `admin` faz hoje)
--   admin       → SÓ cadastros: funcionários (Controle de Acesso), Grupos e
--                 Templates. NÃO enxerga processos, capturas, financeiro,
--                 relatórios nem dashboard.
--
-- O bypass total sai do `admin` e passa a valer só para `super_admin`
-- (Auth::can / Permissions::isSuperAdmin).
--
-- IMPORTANTE: super_admin NÃO é atribuível pela tela — o select de perfil do
-- Controle de Acesso não oferece a opção e o backend rejeita o valor. Promover
-- alguém é ato deliberado, feito por SQL como o desta migration. Hoje o único
-- super_admin é o gerente de TI (gerente.ti@exemplo.com), que já era o
-- único `admin` do sistema.
--
-- Banco alvo: passado na CLI (compras no local, Notas no servidor) — SEM `USE`,
-- seguindo a convenção das migrations 025–029.
-- Local:    mysql -u root --default-character-set=utf8mb4 compras < database/migrations/030_perfil_super_admin.sql
-- Servidor: mysql -u notas_app -p'…' Notas < ~/deploy_030.sql
-- ═══════════════════════════════════════════════════════════════════════════

-- ── 1) Novo valor no enum de perfis ───────────────────────────────────────
ALTER TABLE `usuarios`
  MODIFY COLUMN `perfil`
    enum('solicitante','almoxarife','comprador','gestor','diretor','admin','super_admin')
    NOT NULL;

-- ── 2) Promoção nominal — NÃO promover admins em bloco ───────────────────
-- Quem não estiver aqui e for `admin` fica com o admin restrito (cadastros).
UPDATE `usuarios`
   SET `perfil` = 'super_admin'
 WHERE `email` = 'gerente.ti@exemplo.com';

-- ── 3) Conferência ────────────────────────────────────────────────────────
-- SELECT perfil, COUNT(*) FROM usuarios WHERE ativo = 1 GROUP BY perfil;
