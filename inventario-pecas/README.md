# Inventário de peças

> Versão para portfólio. Nomes, hosts, bancos e credenciais são **fictícios** (`exemplo.local`, `app_user`, `TROQUE_NO_SERVIDOR`). Nenhum dado real foi incluído; configure o seu `.env` antes de rodar.

Contagem de estoque de peças com coletor, segunda contagem por dupla e tela de tomada de decisão para divergências.

- Busca de itens no ERP via Oracle (OCI8), com MySQL como reserva.
- Conferência, recontagem, ajustes e relatórios (inventário, descarte, transferências).
- Geração de etiquetas (EPL2 / Fingerprint) e QR Code.
- Testes em `tests/`.

## Configuração

- MySQL: ajuste `conexao_inventario.php` e aplique `sql/` em ordem.
- Oracle (opcional): ajuste `conexao_oracle.php` (usuário, serviço, host); precisa da extensão `oci8`.
- Servidor: `php -S localhost:8000`.

