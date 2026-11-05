#!/bin/bash
set -u
echo "hostname: $(hostname)"
echo "target:   /var/www/exemplo/notas"
echo
echo "--- versoes publicadas hoje ---"
for f in src/Models/Process.php src/Controllers/ProcessController.php cli/verificar_faixas_valor.php; do
  p="/var/www/exemplo/notas/$f"
  if [ -f "$p" ]; then
    printf "%-45s %s\n" "$f" "$(sed -n '2p' "$p" | grep -o '@versao.*' || echo '(sem marca)')"
  else
    printf "%-45s %s\n" "$f" "(NAO EXISTE - arquivo novo)"
  fi
done
echo
echo "--- verificar_versao existe no servidor? ---"
[ -f /var/www/exemplo/notas/cli/verificar_versao.php ] && echo "sim" || echo "NAO"
echo
echo "--- espaco em disco ---"
df -h /var/www | tail -1
