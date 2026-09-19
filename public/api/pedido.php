<?php
/**
 * GET /api/pedido.php?id=<uuid>[&payment_id=…][&token=…]
 * Estado del pedido para la página /gracias. Si aún no está pagado,
 * pregunta a la pasarela antes de responder (así no dependemos del webhook).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

solo_metodo('GET');
$id = (string) ($_GET['id'] ?? '');
if (!es_uuid($id)) {
    json_error('Pedido no válido', 400);
}
$p = pedido_obtener($id);
if (!$p) {
    json_error('Pedido no encontrado', 404);
}

if ($p['estado'] !== 'pagado') {
    $pistas = [];
    $paymentId = (string) ($_GET['payment_id'] ?? $_GET['collection_id'] ?? '');
    if ($paymentId !== '') {
        $pistas['payment_id'] = $paymentId;
    }
    $p = pedido_conciliar($p, $pistas);
}

json_out(pedido_publico($p));
