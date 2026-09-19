<?php
/**
 * POST /api/checkout.php  { pais, email, sitio (honeypot), origen }
 * Crea el pedido y devuelve la URL de pago de la pasarela del país.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

solo_metodo('POST');
$in = leer_json_body();

// Bots que rellenan el campo oculto: respuesta inocua y sin pedido.
if (!empty($in['sitio'])) {
    json_out(['url' => url_sitio() . '/']);
}

$pais = strtolower(trim((string) ($in['pais'] ?? '')));
$email = strtolower(trim((string) ($in['email'] ?? '')));
$cat = catalogo();
$cfgPais = $cat['paises'][$pais] ?? null;
$directo = $cfgPais['directo'] ?? null;

if (!$cfgPais || !$directo || empty($directo['activo'])) {
    json_error('La compra directa no está disponible para ese país.', 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    json_error('Escribe un correo válido: ahí te enviaremos el libro.', 400);
}

// Freno básico: más de 8 intentos desde la misma IP en 10 minutos.
$ip = ip_cliente();
$st = db()->prepare("SELECT COUNT(*) FROM pedidos WHERE ip = ? AND creado_en > ?");
$st->execute([$ip, gmdate('Y-m-d\TH:i:s\Z', time() - 600)]);
if ((int) $st->fetchColumn() >= 8) {
    json_error('Demasiados intentos. Espera unos minutos e inténtalo de nuevo.', 429);
}

$pedido = pedido_crear([
    'pais' => $pais,
    'proveedor' => $directo['proveedor'],
    'moneda' => $directo['moneda'],
    'monto' => (float) $directo['precio'],
    'email' => $email,
    'ip' => $ip,
    'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'origen' => (string) ($in['origen'] ?? ''),
]);

try {
    if ($directo['proveedor'] === 'mercadopago') {
        require_once __DIR__ . '/lib/mercadopago.php';
        $res = mp_crear_preferencia($pedido);
    } elseif ($directo['proveedor'] === 'paypal') {
        require_once __DIR__ . '/lib/paypal.php';
        $res = pp_crear_orden($pedido);
    } else {
        throw new RuntimeException('Proveedor desconocido: ' . $directo['proveedor']);
    }
} catch (Throwable $e) {
    registrar('errores', 'checkout ' . $pedido['id'] . ': ' . $e->getMessage());
    pedido_marcar($pedido['id'], 'fallido', 'error_pasarela');
    json_error('La pasarela de pago no respondió. Inténtalo en un momento.', 502);
}

pedido_actualizar($pedido['id'], ['proveedor_ref' => $res['id']]);
registrar('pedidos', 'creado ' . $pedido['id'], ['pais' => $pais, 'proveedor' => $directo['proveedor'], 'ref' => $res['id']]);

json_out(['pedido' => $pedido['id'], 'url' => $res['url']]);
