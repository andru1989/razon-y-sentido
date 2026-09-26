#!/usr/bin/env bash
# Compila el sitio y lo sirve con PHP en http://127.0.0.1:8080, igual que en Hostinger.
# Uso: npm run local
set -euo pipefail
cd "$(dirname "$0")/.."

PHP_VERSION="8.4.23"
PHP_BIN=".tools/php"
PUERTO="${PUERTO:-8080}"

# PHP estático (static-php.dev) dentro del proyecto; no toca el sistema.
if [ ! -x "$PHP_BIN" ] && command -v php >/dev/null 2>&1; then
  PHP_BIN="$(command -v php)"
fi
if [ ! -x "$PHP_BIN" ]; then
  case "$(uname -s)-$(uname -m)" in
    Darwin-arm64)  PLATAFORMA="macos-aarch64" ;;
    Darwin-x86_64) PLATAFORMA="macos-x86_64" ;;
    Linux-x86_64)  PLATAFORMA="linux-x86_64" ;;
    Linux-aarch64) PLATAFORMA="linux-aarch64" ;;
    *) echo "Plataforma no soportada; instala PHP 8.4 con pdo_sqlite y curl." >&2; exit 1 ;;
  esac
  echo "Descargando PHP $PHP_VERSION ($PLATAFORMA)…"
  mkdir -p .tools
  curl -fsSL "https://dl.static-php.dev/static-php-cli/common/php-$PHP_VERSION-cli-$PLATAFORMA.tar.gz" | tar -xz -C .tools
fi

# Directorio privado local (config de sandbox + EPUB de prueba)
if [ ! -f private/config.php ]; then
  mkdir -p private/logs
  sed "s#'url_sitio' => 'https://tiempo.razonysentido.com'#'url_sitio' => 'http://127.0.0.1:$PUERTO'#" docs/config.ejemplo.php > private/config.php
  echo "Creado private/config.php: pon ahí tus credenciales de prueba."
fi
if [ ! -f private/libro.epub ]; then
  printf 'PK\003\004EPUB DE PRUEBA LOCAL\n' > private/libro.epub
  echo "Creado private/libro.epub de prueba (reemplázalo por el libro real para probar la descarga)."
fi

npm run build --silent

# Libera el puerto si quedó ocupado por una ejecución anterior
if command -v lsof >/dev/null 2>&1; then
  lsof -ti:"$PUERTO" | xargs kill 2>/dev/null || true
fi

echo ""
echo "  Landing:  http://127.0.0.1:$PUERTO"
echo "  Pedidos:  http://127.0.0.1:$PUERTO/api/pedidos-admin.php?clave=<admin.clave de private/config.php>"
echo "  Logs:     private/logs/"
echo "  (Ctrl+C para detener)"
echo ""
RYS_PRIVATE_DIR="$PWD/private" exec "$PHP_BIN" -S "127.0.0.1:$PUERTO" -t dist
