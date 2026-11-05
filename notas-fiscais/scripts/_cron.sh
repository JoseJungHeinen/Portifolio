#!/bin/bash
set -u
LINHA1='0,30 7-20 * * * php /var/www/exemplo/notas/cli/assinafy_sync.php >> /var/www/exemplo/notas/storage/logs/assinafy_cron.log 2>&1'
LINHA2='0 21 * * * php /var/www/exemplo/notas/cli/assinafy_sync.php >> /var/www/exemplo/notas/storage/logs/assinafy_cron.log 2>&1'

echo "=== crontab atual do www-data"
sudo -n crontab -u www-data -l 2>/dev/null || echo "  (vazio)"

# Preserva o que já existe; só acrescenta se ainda não houver.
TMP=$(mktemp)
sudo -n crontab -u www-data -l 2>/dev/null > "$TMP" || true
if grep -q "assinafy_sync.php" "$TMP"; then
  echo
  echo "=== ja existe entrada do assinafy_sync — nada a fazer"
else
  {
    echo ""
    echo "# Assinatura eletronica (Assinafy) - janela 07:00-21:00, de 30 em 30 min"
    echo "$LINHA1"
    echo "$LINHA2"
  } >> "$TMP"
  sudo -n crontab -u www-data "$TMP"
  echo
  echo "=== instalado"
fi
rm -f "$TMP"

echo
echo "=== crontab do www-data agora"
sudo -n crontab -u www-data -l

echo
echo "=== o log e gravavel pelo www-data?"
sudo -n -u www-data touch /var/www/exemplo/notas/storage/logs/assinafy_cron.log && echo "  sim" || echo "  NAO — corrigir permissao"
ls -l /var/www/exemplo/notas/storage/logs/assinafy_cron.log 2>/dev/null
