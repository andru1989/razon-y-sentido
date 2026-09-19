<?php
/**
 * GET /api/descarga.php?t=<token>
 * Entrega el EPUB desde el directorio privado. El token pertenece a un
 * pedido pagado, caduca a los N días y admite un máximo de descargas.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

function pagina_descarga(int $codigo, string $titulo, string $mensaje): never
{
    http_response_code($codigo);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $t = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8');
    $sitio = htmlspecialchars(url_sitio(), ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>$t</title>
<style>body{margin:0;font-family:'DM Sans',Helvetica,Arial,sans-serif;background:#F7F3EA;color:#0E0B16;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}
.c{background:#fff;border-radius:16px;padding:32px 28px;max-width:440px;text-align:center;box-shadow:0 18px 48px rgba(14,11,22,.10);border:1px solid rgba(232,163,61,.25)}
h1{font-size:22px;margin:0 0 10px}p{color:rgba(14,11,22,.65);font-size:15px;line-height:1.55;margin:0 0 20px}
a.b{display:inline-block;background:linear-gradient(135deg,#2E7C99,#5FA8C4);color:#fff;font-weight:800;padding:12px 22px;border-radius:12px;text-decoration:none}
a.s{display:block;margin-top:14px;color:#2E7C99;font-weight:600;font-size:14px;text-decoration:none}</style></head>
<body><div class="c"><h1>$t</h1><p>$m</p>
<a class="b" href="https://wa.me/573235109187" target="_blank" rel="noopener">Escribir por WhatsApp</a>
<a class="s" href="$sitio/">Volver a la página del libro</a></div></body></html>
HTML;
    exit;
}

$token = (string) ($_GET['t'] ?? '');
if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
    pagina_descarga(404, 'Enlace no válido', 'Este enlace de descarga no existe. Revisa el correo de tu compra o escríbenos.');
}
$p = pedido_por_token($token);
if (!$p || $p['estado'] !== 'pagado') {
    pagina_descarga(404, 'Enlace no válido', 'No encontramos una compra confirmada para este enlace. Si ya pagaste, escríbenos con tu correo y lo resolvemos.');
}
if ($p['expira_en'] && strtotime((string) $p['expira_en']) < time()) {
    pagina_descarga(410, 'Enlace vencido', 'Este enlace ya caducó. Escríbenos por WhatsApp con tu número de pedido ' . strtoupper(substr($p['id'], 0, 8)) . ' y te enviamos uno nuevo.');
}
$cat = catalogo();
$max = (int) ($cat['descarga']['descargas_max'] ?? 5);
if ((int) $p['descargas'] >= $max) {
    pagina_descarga(429, 'Límite de descargas alcanzado', 'Este enlace ya se usó ' . $max . ' veces. Si necesitas el archivo de nuevo, escríbenos con tu número de pedido ' . strtoupper(substr($p['id'], 0, 8)) . '.');
}

$archivo = RYS_PRIVATE_DIR . '/' . basename((string) ($cat['libro']['archivo'] ?? 'libro.epub'));
if (!is_file($archivo) || !is_readable($archivo)) {
    registrar('errores', 'FALTA EL EPUB en ' . $archivo);
    pagina_descarga(503, 'Archivo no disponible', 'El archivo no está disponible en este momento. Ya quedó registrado; escríbenos por WhatsApp y te lo enviamos directamente.');
}

// Cuenta la descarga antes de servir (una recarga a medias también cuenta; el límite es generoso).
db()->prepare('UPDATE pedidos SET descargas = descargas + 1, actualizado_en = ? WHERE id = ?')->execute([ahora(), $p['id']]);
registrar('descargas', 'descarga ' . $p['id'], ['n' => (int) $p['descargas'] + 1, 'ip' => ip_cliente()]);

$nombre = (string) ($cat['libro']['nombre_descarga'] ?? 'libro.epub');
$nombreAscii = preg_replace('/[^A-Za-z0-9._-]/', '_', $nombre) ?: 'libro.epub';

if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/epub+zip');
header('Content-Length: ' . (string) filesize($archivo));
header('Content-Disposition: attachment; filename="' . $nombreAscii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex');
header('X-Content-Type-Options: nosniff');

$fh = fopen($archivo, 'rb');
if ($fh) {
    while (!feof($fh)) {
        echo fread($fh, 1024 * 256);
        flush();
    }
    fclose($fh);
}
exit;
