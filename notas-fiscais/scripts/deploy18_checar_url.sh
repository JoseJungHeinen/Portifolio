#!/bin/bash
# Qual é o entry point real do Notas no Server-APP: com ou sem /public/?
for u in "https://app.exemplo.local/" \
         "https://app.exemplo.local/"; do
  echo "== $u"
  curl -s -o /dev/null -w '   1o salto: %{http_code} -> %{redirect_url}
' "$u"
  curl -sL -o /dev/null -w '   final:    %{http_code} %{url_effective}
' "$u"
  echo "   conteudo (primeiros 120 chars do body seguido):"
  curl -sL "$u" | tr -d '\n' | head -c 120
  echo
  echo
done

echo "== o que o Apache serve em /Notas/ =="
grep -rn "APP_BASE_URL\|DocumentRoot\|Alias" /etc/apache2/sites-enabled/ 2>/dev/null | head -10
echo "--- .htaccess da raiz do app ---"
head -20 /var/www/exemplo/notas/.htaccess 2>/dev/null || echo "(sem .htaccess na raiz)"
