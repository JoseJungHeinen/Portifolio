# Configuração do Windows para o coletor automático

Dois passos, feitos **uma vez**, pelo próprio usuário (mexem em configurações do Chrome e do Windows).
Rodar no **PowerShell** (não precisa ser administrador).

## 1. Chrome escolher o certificado A1 sozinho no gov.br

Sem isso, o Chrome invisível fica parado esperando alguém escolher o certificado.
O comando lê o nome do certificado da EXEMPLO instalado no Windows e cria a política
`AutoSelectCertificateForUrls` **só para os endereços do gov.br** (`[*.]acesso.gov.br`).

```powershell
$cert = Get-ChildItem Cert:\CurrentUser\My | Where-Object { $_.HasPrivateKey -and $_.NotAfter -gt (Get-Date) -and $_.Subject -like '*CN=EXEMPLO VEICULOS LTDA*' } | Select-Object -First 1
$cn = ($cert.Subject -replace '.*CN=([^,]+).*','$1')
$regra = @{ pattern = 'https://[*.]acesso.gov.br'; filter = @{ SUBJECT = @{ CN = $cn } } } | ConvertTo-Json -Compress -Depth 4
New-Item -Path 'HKCU:\Software\Policies\Google\Chrome\AutoSelectCertificateForUrls' -Force | Out-Null
Set-ItemProperty -Path 'HKCU:\Software\Policies\Google\Chrome\AutoSelectCertificateForUrls' -Name '1' -Value $regra
"Política criada para: $($cn -replace '\d','#')"
```

Efeito colateral: no seu Chrome normal, o login do gov.br **com certificado** também passa a usar
o da EXEMPLO sem perguntar. Para desfazer:

```powershell
Remove-Item -Path 'HKCU:\Software\Policies\Google\Chrome\AutoSelectCertificateForUrls' -Recurse
```

Conferir: abra `chrome://policy` no Chrome — deve aparecer `AutoSelectCertificateForUrls`.

## 2. Agendar de segunda a sexta às 7h

```powershell
schtasks /Create /TN "Coletor Multas DETRAN" /SC WEEKLY /D MON,TUE,WED,THU,FRI /ST 07:00 /TR "\"C:\Projetos\locadora\multas-teste\cli\coletar_agendado.bat\""
```

A tarefa roda com o seu usuário e **só quando ele estiver logado** no Windows (o certificado fica no
seu perfil). A máquina precisa estar ligada às 7h. Para rodar na hora: `schtasks /Run /TN "Coletor Multas DETRAN"`.
Para remover: `schtasks /Delete /TN "Coletor Multas DETRAN" /F`.

## Onde ver o resultado

- Painel do sistema: aviso "Coletor automático" no topo (verde = ok hoje, vermelho = falhou ou não rodou).
- `data\coletas\<data_hora>\`: `log.txt`, `resultado.json`, o `.json` coletado e prints das telas em caso de erro.
- `data\coletas\agendado.log`: saída de todas as execuções agendadas.
