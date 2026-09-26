<?php
/**
 * POST /api/reenviar.php  { id }
 * Reenvía el correo con el libro desde la página /gracias.
 * Solo pedidos pagados; como máximo 3 reenvíos por pedido y uno cada 60 s.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/correo.php';

solo_metodo('POST');
$in = leer_json_body();
$id = (string) ($in['id'] ?? '');
if (!es_uuid($id)) {
    json_error('Pedido no válido', 400);
}
$p = pedido_obtener($id);
if (!$p) {
    json_error('Pedido no encontrado', 404);
}
if ($p['estado'] !== 'pagado') {
    json_error('El pago aún no está confirmado.', 409);
}

$st = db()->prepare("SELECT COUNT(*) AS n, MAX(recibido_en) AS ultimo FROM eventos_webhook WHERE proveedor = 'reenvio' AND pedido_id = ?");
$st->execute([$id]);
$uso = $st->fetch() ?: ['n' => 0, 'ultimo' => null];
if ((int) $uso['n'] >= 3) {
    json_error('Ya reenviamos el correo varias veces. Revisa spam o escríbenos por WhatsApp.', 429);
}
if ($uso['ultimo'] && strtotime((string) $uso['ultimo']) > time() - 60) {
    json_error('Acabamos de enviarlo. Espera un minuto antes de pedirlo otra vez.', 429);
}

// Se registra el intento en la tabla de eventos (deduplicación ya indexada).
webhook_registrar('reenvio', $id . ':' . uuid4(), 'reenvio_correo', $id, '');

if (!correo_entrega($p)) {
    json_error('No pudimos enviar el correo ahora. Descarga el libro con el botón o escríbenos por WhatsApp.', 502);
}
pedido_actualizar($id, ['correo_enviado_en' => ahora()]);
registrar('correo', "reenvío a petición del comprador $id");
json_out(['ok' => true, 'email' => $p['email']]);
