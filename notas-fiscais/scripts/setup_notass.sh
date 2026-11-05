#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# setup_notass.sh — Server_novo (portal / portal.exemplo.local)
#
# Cria o caminho http://portal.exemplo.local/notass servindo a aplicação Notas DESTE
# servidor (/var/www/html/Notas/public, banco `Notas` local).
#
# http://portal.exemplo.local/Notas continua com o redirect 301 para
# https://app.exemplo.local/ — nada muda ali.
#
# Roda como root:  sudo bash setup_notass.sh
# Idempotente: rodar duas vezes não duplica nada.
# ─────────────────────────────────────────────────────────────────────────────
set -e

CONF=/etc/apache2/sites-available/000-default.conf
APP=/var/www/html/Notas
STAMP=$(date +%Y%m%d_%H%M%S)

[ "$(id -u)" -eq 0 ] || { echo "ERRO: rode com sudo."; exit 1; }
[ -f "$CONF" ]       || { echo "ERRO: $CONF não encontrado."; exit 1; }
[ -d "$APP/public" ] || { echo "ERRO: $APP/public não encontrado."; exit 1; }

if grep -q 'Alias /notass' "$CONF"; then
    echo "Já configurado — nada a fazer."
    exit 0
fi

cp -p "$CONF" "$CONF.bak_$STAMP"
echo "Backup: $CONF.bak_$STAMP"

# Bloco novo, inserido antes do </VirtualHost>.
BLOCO=$(cat <<'APACHE'

    # ── Notas — acesso direto ao sistema DESTE servidor, em /notass ─────────
    # /Notas segue redirecionando para exemplo.com (.htaccess da pasta).
    # /notass entra pela pasta public/ e roda a aplicação local.
    Alias /notass/public/assets /var/www/html/Notas/public/assets
    Alias /notass               /var/www/html/Notas/public

    <Directory /var/www/html/Notas/public>
        Options -Indexes +FollowSymLinks
        # AllowOverride None + regras aqui: o .htaccess de /var/www/html/Notas
        # (o do redirect) não pode valer para este caminho, senão /notass também
        # sairia para o exemplo.com.
        AllowOverride None
        Require all granted

        RewriteEngine On
        RewriteBase /notass/
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.php [QSA,L]

        # Prefixo que a aplicação usa para montar links e assets (BASE_URL).
        SetEnv APP_BASE_URL /notass
    </Directory>
APACHE
)

python3 - "$CONF" "$BLOCO" <<'PY'
import sys
conf, bloco = sys.argv[1], sys.argv[2]
texto = open(conf, encoding='utf-8').read()
marca = '</VirtualHost>'
if marca not in texto:
    sys.exit('ERRO: </VirtualHost> não encontrado em ' + conf)
i = texto.rindex(marca)
open(conf, 'w', encoding='utf-8').write(texto[:i] + bloco.rstrip('\n') + '\n\n' + texto[i:])
print('Bloco inserido em', conf)
PY

echo "── configtest ──"
apache2ctl configtest

echo "── reload ──"
systemctl reload apache2

echo "── teste local ──"
curl -s -o /dev/null -w "  /notass/  -> %{http_code}\n" http://127.0.0.1/notass/ || true
curl -s -o /dev/null -w "  /Notas/   -> %{http_code} (esperado 301)\n" http://127.0.0.1/Notas/ || true

echo "FIM OK"
