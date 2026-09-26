<?php
/**
 * Arranque común de todos los endpoints de /api.
 *
 * Todo lo sensible vive FUERA de public_html, en el directorio privado:
 *   private/config.php      → credenciales (Mercado Pago, PayPal, correo, Meta)
 *   private/pedidos.sqlite  → pedidos y eventos de webhook
 *   private/libro.epub      → el archivo que se vende (nunca en el repo)
 *   private/logs/           → registros
 *
 * Ruta: public_html/api/lib → ../../../private. Se puede forzar con la
 * variable de entorno RYS_PRIVATE_DIR (útil para pruebas locales).
 */
declare(strict_types=1);

define('RYS_PRIVATE_DIR', rtrim(getenv('RYS_PRIVATE_DIR') ?: dirname(__DIR__, 3) . '/private', '/'));
define('RYS_API_DIR', dirname(__DIR__));

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(RYS_PRIVATE_DIR)) {
    if (!is_dir(RYS_PRIVATE_DIR . '/logs')) {
        @mkdir(RYS_PRIVATE_DIR . '/logs', 0700, true);
    }
    ini_set('error_log', RYS_PRIVATE_DIR . '/logs/php-error.log');
}
date_default_timezone_set('UTC');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/pedidos.php';

/** Lee un valor de private/config.php con notación de puntos: cfg('paypal.client_id'). */
function cfg(string $ruta, mixed $porDefecto = null): mixed
{
    static $config = null;
    if ($config === null) {
        $archivo = RYS_PRIVATE_DIR . '/config.php';
        $config = is_file($archivo) ? (require $archivo) : [];
        if (!is_array($config)) {
            $config = [];
        }
    }
    $nodo = $config;
    foreach (explode('.', $ruta) as $clave) {
        if (!is_array($nodo) || !array_key_exists($clave, $nodo)) {
            return $porDefecto;
        }
        $nodo = $nodo[$clave];
    }
    return $nodo;
}

/** ¿Estamos cobrando de verdad? Cualquier otro valor se trata como sandbox. */
function en_produccion(): bool
{
    return cfg('modo') === 'produccion';
}

/** Catálogo compartido con la landing (precios, países, datos del libro). */
function catalogo(): array
{
    static $catalogo = null;
    if ($catalogo === null) {
        $json = @file_get_contents(RYS_API_DIR . '/catalogo.json');
        $catalogo = $json ? (json_decode($json, true) ?: []) : [];
    }
    return $catalogo;
}

/** URL pública del sitio, sin barra final. */
function url_sitio(): string
{
    // El túnel de pruebas (npm run tunel) fija su URL pública, que cambia en cada arranque.
    $entorno = getenv('RYS_URL_SITIO');
    if (is_string($entorno) && $entorno !== '') {
        return rtrim($entorno, '/');
    }
    $configurada = cfg('url_sitio');
    if (is_string($configurada) && $configurada !== '') {
        return rtrim($configurada, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function json_out(mixed $datos, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $mensaje, int $codigo = 400): never
{
    json_out(['error' => $mensaje], $codigo);
}

function leer_json_body(): array
{
    $crudo = file_get_contents('php://input');
    $datos = json_decode($crudo ?: '', true);
    return is_array($datos) ? $datos : [];
}

function solo_metodo(string $metodo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $metodo) {
        header('Allow: ' . $metodo);
        json_error('Método no permitido', 405);
    }
}

/**
 * ¿El sitio está detrás de Cloudflare (túnel de pruebas o proxy)? Solo entonces se aceptan
 * sus cabeceras CF-*; sin proxy, cualquiera podría falsificarlas.
 */
function tras_cloudflare(): bool
{
    return getenv('RYS_TRAS_CLOUDFLARE') === '1' || cfg('tras_cloudflare') === true;
}

function ip_cliente(): string
{
    // Hostinger pasa la IP real en REMOTE_ADDR; detrás de Cloudflare viene en CF-Connecting-IP.
    $ip = (tras_cloudflare() ? ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? null) : null) ?? $_SERVER['REMOTE_ADDR'] ?? '';
    return substr((string) $ip, 0, 64);
}

/** Registro por canal en private/logs/<canal>.log (nunca contiene credenciales). */
function registrar(string $canal, string $mensaje, array $contexto = []): void
{
    $dir = RYS_PRIVATE_DIR . '/logs';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        error_log("[$canal] $mensaje " . json_encode($contexto, JSON_UNESCAPED_UNICODE));
        return;
    }
    $linea = gmdate('c') . " $mensaje";
    if ($contexto) {
        $linea .= ' ' . json_encode($contexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents("$dir/" . preg_replace('/[^a-z0-9_-]/i', '', $canal) . '.log', $linea . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function es_uuid(string $v): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v);
}

/** Errores fatales → JSON limpio y registro, nunca HTML de PHP al cliente. */
set_exception_handler(function (Throwable $e): void {
    registrar('errores', get_class($e) . ': ' . $e->getMessage(), ['archivo' => basename($e->getFile()), 'linea' => $e->getLine()]);
    if (!headers_sent()) {
        json_error('Error interno. Inténtalo de nuevo en un momento.', 500);
    }
});
