# Controle de Multas — sistema de teste

Substitui a planilha **CONTROLE DE MULTAS** e o "colar na aba Detran → filtrar novas → colar no
Controle Geral". PHP 8 + MySQL (XAMPP), sem bibliotecas externas. Depois vai para o sistema Locadora.

## Rodar

1. Ligar o MySQL do XAMPP.
2. Criar o banco (uma vez):
   ```
   C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS multas_teste CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   C:\xampp\mysql\bin\mysql.exe -u root multas_teste < sql\001_schema.sql
   ```
3. Importar o histórico da planilha (pode repetir):
   ```
   python -I scripts\exportar_planilhas.py --controle "...\CONTROLE DE MULTAS - Nova.xlsx" --captura "C:\RPA\Multas_Geral.xlsx" --saida data\import
   C:\xampp\php\php.exe cli\importar_historico.php
   C:\xampp\php\php.exe cli\validar.php
   ```
4. Subir o site: `C:\xampp\php\php.exe -S localhost:8090 -t public` → http://localhost:8090

Banco já existente? Aplicar também `sql\002_coletor_api.sql` (uma vez).

## Uso diário (seg a sex, 8h)

0. Ligar o sistema: duplo clique em `iniciar_sistema.bat` (liga o MySQL e abre http://localhost:8090).
1. Entrar no portal PCSDetran com o gov.br, abrir **Minhas Empresas** e clicar no favorito
   **Coletar multas DETRAN** (instalação na página **Coletor do portal**).
2. O sistema abre numa nova aba **já com a prévia** (novas / atualizadas / saíram) → **Aplicar captura**.
   Se o pop-up for bloqueado, o botão baixa `multas_detran_….json` → enviar em **Importar captura**.
   *Alternativa:* robô RPA (`C:\RPA\Multas_Geral.xlsx`) em **Importar captura**.
3. Trabalhar as multas pelo **Painel** e pela lista. O painel fica vermelho em dia útil sem captura.

*Opcional, não agendado:* `cli/coletar_detran.mjs` faz a coleta sem abrir o site (login automático com
o certificado A1). Requer a configuração de `cli/configurar_windows.md`; o login automático não foi testado.

### Coletor do portal (`public/assets/coletor.js`)

- Chama só `GET pcsdetran.procergs.com.br/pcsdetran/rest/infracoes/requerentes/{cpf}?grupo=5&cnpj={cnpj}`,
  a mesma consulta que a tela "Infrações" da empresa faz. Traz a vencer e vencidas, sem paginação.
- Usa a sessão aberta no navegador; o arquivo gerado não leva token nem CPF.
- Portal instável (504): tenta 4 vezes por CNPJ. Se algum CNPJ falhar, a importação não finaliza nenhuma multa.
- Loja da multa: cadastro do veículo → multas anteriores da mesma placa → CNPJ configurado.
- Bônus da API: `codInfracao` (código oficial) e `dataLimApresCond` (preenche o prazo de identificação).

## Como o status é calculado (`lib/status.php`)

Igual às colunas R/S da planilha: verifica em ordem e para no primeiro campo vazio.

| Falta | Etapa | Painel |
|---|---|---|
| não está mais na captura do DETRAN | Finalizado Detran | Finalizadas |
| prazo de identificar (ou motivo) | Falta Data | Novas |
| e-mail | Falta E-mail | Em Processo |
| condutor identificado | Falta Identificar | Em Processo |
| nº da fatura | Falta Faturar | A Faturar |
| pagamento, e DETRAN sem vencimento ("--") | Aguardando Vcto | Aguardando Vcto |
| pagamento | Falta Pagar | A Pagar |
| — (tudo preenchido, ainda no DETRAN) | Aguardando Baixar | Paga aguardando baixar |

Remulta (multa por não identificar condutor) entra com e-mail e identificação = "Não".
Uma captura só finaliza multas das lojas marcadas como percorridas por completo.

## Arquivos

- `lib/status.php` — regra de status · `lib/captura.php` — leitura dos cards do PCSDetran e filtro de novas
- `lib/captura_db.php` — aplica a captura no banco · `lib/xlsx.php` — leitor de .xlsx sem dependências
- `public/` — telas · `cli/` — importação e validação · `sql/` — schema
- `data/` — exportações e uploads (fora do git: contém dados de negócio)

Ambiente de teste: **sem login**. Credenciais do banco por variáveis `MULTAS_DB_*` (padrão: root local do XAMPP).
