#!/usr/bin/env bash
# Compila el sitio y lo sirve con PHP, igual que en Hostinger.
#   npm run local   → solo en este equipo: http://127.0.0.1:8080
#   npm run tunel   → además, una URL pública HTTPS (Cloudflare Tunnel) para que otra persona pruebe.
#                     La URL cambia cada vez que se arranca y funciona mientras este comando siga abierto.
set -euo pipefail
cd "$(dirname "$0")/.."

PHP_VERSION="8.4.23"
PHP_BIN=".tools/php"
CLOUDFLARED=".tools/cloudflared"
PUERTO="${PUERTO:-8080}"
TUNEL=0
[ "${1:-}" = "--tunel" ] && TUNEL=1

case "$(uname -s)-$(uname -m)" in
  Darwin-arm64)  PLAT_PHP="macos-aarch64"; PLAT_CF="darwin-arm64" ;;
  Darwin-x86_64) PLAT_PHP="macos-x86_64";  PLAT_CF="darwin-amd64" ;;
  Linux-x86_64)  PLAT_PHP="linux-x86_64";  PLAT_CF="linux-amd64" ;;
  Linux-aarch64) PLAT_PHP="linux-aarch64"; PLAT_CF="linux-arm64" ;;
  *) PLAT_PHP=""; PLAT_CF="" ;;
esac
mkdir -p .tools

# PHP estático (static-php.dev) dentro del proyecto; no toca el sistema.
if [ ! -x "$PHP_BIN" ] && command -v php >/dev/null 2>&1; then
  PHP_BIN="$(command -v php)"
fi
if [ ! -x "$PHP_BIN" ]; then
  [ -n "$PLAT_PHP" ] || { echo "Plataforma no soportada; instala PHP 8.4 con pdo_sqlite y curl." >&2; exit 1; }
  echo "Descargando PHP $PHP_VERSION ($PLAT_PHP)…"
  curl -fsSL "https://dl.static-php.dev/static-php-cli/common/php-$PHP_VERSION-cli-$PLAT_PHP.tar.gz" | tar -xz -C .tools
fi

# Directorio privado local (config de sandbox + EPUB de prueba)
if [ ! -f private/config.php ]; then
  mkdir -p private/logs
  CLAVE_ADMIN="$(LC_ALL=C tr -dc 'a-zA-Z0-9' </dev/urandom | head -c 24)"
  sed -e "s#'url_sitio' => 'https://tiempo.razonysentido.com'#'url_sitio' => 'http://127.0.0.1:$PUERTO'#" \
      -e "s#'transporte' => 'mail'#'transporte' => 'archivo'#" \
      -e "/'admin' => \[/,/\]/ s#'clave' => '',#'clave' => '$CLAVE_ADMIN',#" docs/config.ejemplo.php > private/config.php
  echo "Creado private/config.php: pon ahí tus credenciales de prueba."
fi
if [ ! -f private/libro.epub ]; then
  printf 'PK\003\004EPUB DE PRUEBA LOCAL\n' > private/libro.epub
  echo "Creado private/libro.epub de prueba (reemplázalo por el libro real para probar la descarga)."
fi
CLAVE_ADMIN="$(sed -n "/'admin' => \[/,/\]/ s/.*'clave' => '\([^']*\)'.*/\1/p" private/config.php)"

npm run build --silent

# Libera el puerto si quedó ocupado por una ejecución anterior
if command -v lsof >/dev/null 2>&1; then
  lsof -ti:"$PUERTO" | xargs kill 2>/dev/null || true
fi

URL_PUBLICA=""
if [ "$TUNEL" = 1 ]; then
  if [ ! -x "$CLOUDFLARED" ]; then
    [ -n "$PLAT_CF" ] || { echo "Plataforma no soportada para cloudflared." >&2; exit 1; }
    echo "Descargando cloudflared ($PLAT_CF)…"
    if [[ "$PLAT_CF" == darwin-* ]]; then
      curl -fsSL "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-$PLAT_CF.tgz" | tar -xz -C .tools
    else
      curl -fsSL -o "$CLOUDFLARED" "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-$PLAT_CF"
    fi
    chmod +x "$CLOUDFLARED"
  fi
  mkdir -p private/logs
  LOG_TUNEL="private/logs/tunel.log"
  : > "$LOG_TUNEL"
  "$CLOUDFLARED" tunnel --no-autoupdate --url "http://127.0.0.1:$PUERTO" > "$LOG_TUNEL" 2>&1 &
  PID_TUNEL=$!
  trap 'kill $PID_TUNEL 2>/dev/null || true' EXIT INT TERM
  for _ in $(seq 1 40); do
    URL_PUBLICA="$(grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' "$LOG_TUNEL" | head -1 || true)"
    [ -n "$URL_PUBLICA" ] && break
    sleep 1
  done
  [ -n "$URL_PUBLICA" ] || { echo "No se pudo abrir el túnel; revisa $LOG_TUNEL" >&2; exit 1; }
  # PHP usa la URL pública para las URLs de retorno, los webhooks y los enlaces de descarga.
  export RYS_URL_SITIO="$URL_PUBLICA" RYS_TRAS_CLOUDFLARE=1
fi

BASE="${URL_PUBLICA:-http://127.0.0.1:$PUERTO}"
echo ""
if [ -n "$URL_PUBLICA" ]; then
  echo "  URL pública (compártela):  $URL_PUBLICA/?pais=co"
fi
echo "  Landing en este equipo:    http://127.0.0.1:$PUERTO"
echo "  Panel de pedidos (privado): $BASE/api/pedidos-admin.php?clave=$CLAVE_ADMIN"
echo "  Logs:                      private/logs/"
echo "  (Ctrl+C para detener)"
echo ""

export RYS_PRIVATE_DIR="$PWD/private" PHP_CLI_SERVER_WORKERS=4
SERVIDOR=("$PHP_BIN" -S "127.0.0.1:$PUERTO" -t dist scripts/router.php)
# Con túnel, evita que el Mac se duerma mientras el servidor esté arriba.
if [ "$TUNEL" = 1 ] && command -v caffeinate >/dev/null 2>&1; then
  caffeinate -i "${SERVIDOR[@]}"
else
  "${SERVIDOR[@]}"
fi
