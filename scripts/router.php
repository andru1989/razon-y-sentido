<?php
/**
 * Router del servidor PHP integrado (npm run local / npm run tunel).
 * Replica las reglas de public/.htaccess que el servidor integrado no lee:
 * ni archivos ocultos ni la librería interna de /api/lib se sirven.
 */
$ruta = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$raiz = $_SERVER['DOCUMENT_ROOT'];
$archivo = $raiz . $ruta;
$existe = is_file($archivo) || is_file(rtrim($archivo, '/') . '/index.html');
// Sin esto el servidor integrado responde la portada ante cualquier ruta inexistente;
// Hostinger (y este router) responden 404, como debe ser.
if (preg_match('#(^|/)\.#', $ruta) || str_starts_with($ruta, '/api/lib/') || !$existe) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No encontrado';
    return true;
}
return false; // el servidor integrado sirve el archivo o ejecuta el .php
