#!/bin/bash
# Deploy 27 -> Server-APP. Uma conexao so: o pacote ja esta em ~/deploy27.tar.gz.
# Cada portao aborta antes de tocar em /var/www/exemplo/notas.
set -u

ALVO=/var/www/exemplo/notas
PKG=~/deploy27.tar.gz
STG=~/deploy
ARQUIVOS="src/Models/Process.php src/Controllers/ProcessController.php cli/verificar_faixas_valor.php"

falhar() { echo; echo "!!! ABORTADO: $*"; echo "!!! Nada foi publicado."; exit 1; }

echo "===== 0. ALVO ====="
H=$(hostname)
echo "hostname: $H"
[ "$H" = "exemplo-app" ] || falhar "servidor errado (esperado exemplo-app, veio $H)"
[ -d "$ALVO" ]           || falhar "$ALVO nao existe"
[ -f "$PKG" ]            || falhar "pacote $PKG nao chegou"
echo "alvo ok: $ALVO"

echo
echo "===== 1. BACKUP ====="
BKP=~/notas_backup_$(date +%Y%m%d_%H%M%S).tar.gz
sudo -n tar czf "$BKP" -C /var/www/exemplo notas || falhar "backup falhou"
echo "backup: $BKP  ($(du -h "$BKP" | cut -f1))"

echo
echo "===== 2. STAGING ====="
rm -rf "$STG"; mkdir -p "$STG"
tar xzf "$PKG" -C "$STG" || falhar "extracao do pacote falhou"
find "$STG" -type f | sed "s|$STG/|  |"

echo
echo "===== 3. php -l NO SERVIDOR ====="
for f in $ARQUIVOS; do
  php -l "$STG/$f" || falhar "erro de sintaxe em $f"
done

echo
echo "===== 4. verificar_versao --comparar ====="
php "$ALVO/cli/verificar_versao.php" --comparar "$STG" "$ALVO"
RC=$?
echo "(exit=$RC)"
[ "$RC" -eq 0 ] || falhar "verificar_versao bloqueou o deploy"

echo
echo "===== 5. PUBLICANDO ====="
for f in $ARQUIVOS; do
  d=$(dirname "$ALVO/$f")
  sudo -n mkdir -p "$d"                       || falhar "mkdir $d"
  sudo -n cp "$STG/$f" "$ALVO/$f"             || falhar "cp $f"
  sudo -n chown www-data:www-data "$ALVO/$f"  || falhar "chown $f"
  sudo -n chmod 644 "$ALVO/$f"                || falhar "chmod $f"
  echo "  publicado: $f"
done

echo
echo "===== 6. MD5 E VERSAO PUBLICADA ====="
for f in $ARQUIVOS; do
  printf "  %-45s %s  %s\n" "$f" "$(md5sum "$ALVO/$f" | cut -d' ' -f1)" "$(sed -n '2p' "$ALVO/$f" | grep -o '@versao.*')"
done

echo
echo "===== 7. PERMISSOES ====="
for f in $ARQUIVOS; do
  ls -l "$ALVO/$f" | awk '{printf "  %s %s:%s %s\n", $1, $3, $4, $9}'
done

echo
echo "===== 8. SMOKE TEST ====="
echo "  raiz     (NAO serve de teste, devolve 200 de outra pagina): $(curl -s -o /dev/null -w '%{http_code}' --max-time 20 https://app.exemplo.local/)"
echo "  /Notas/  : $(curl -s -o /dev/null -w '%{http_code}' --max-time 20 https://app.exemplo.local/)"
echo "  asset    : $(curl -s -o /dev/null -w '%{http_code}' --max-time 20 https://app.exemplo.local/)"

echo
echo "===== 9. TESTE FUNCIONAL: o relatorio novo roda? ====="
cd "$ALVO" && php cli/verificar_faixas_valor.php
echo "(exit=$? — 1 aqui e esperado: ha 3 fluxos pendentes de decisao)"

echo
echo "===== 10. LIMPEZA ====="
rm -rf "$STG" "$PKG"
echo "staging e pacote removidos"

echo
echo "===== DEPLOY 27 CONCLUIDO ====="
echo "backup para rollback: $BKP"
