<?php
/**
 * Webhook de PayPal.
 * Configurar en developer.paypal.com → tu app → Webhooks:
 *   URL:     https://tiempo.razonysentido.com/api/webhook-paypal.php
 *   Eventos: CHECKOUT.ORDER.APPROVED, PAYMENT.CAPTURE.COMPLETED,
 *            PAYMENT.CAPTURE.DENIED, PAYMENT.CAPTURE.REFUNDED, PAYMENT.CAPTURE.REVERSED
 *   Webhook ID → private/config.php (paypal.webhook_id)
 * Cada evento se resuelve re-leyendo la orden en la API; el cuerpo solo indica qué mirar.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/paypal.php';

$crudo = (string) file_get_contents('php://input');
$evento = json_decode($crudo, true) ?: [];
$tipo = (string) ($evento['event_type'] ?? '');
$eventoId = (string) ($evento['id'] ?? '');
$recurso = $evento['resource'] ?? [];

registrar('paypal', 'webhook', ['tipo' => $tipo, 'evento' => $eventoId]);

if ($tipo === '' || $eventoId === '') {
    http_response_code(400);
    exit('evento inválido');
}
if (!pp_verificar_webhook(cabeceras_entrada(), $crudo)) {
    registrar('paypal', 'firma inválida', ['evento' => $eventoId]);
    http_response_code(401);
    exit('firma inválida');
}

// Id de orden y de pedido según el tipo de recurso (orden o captura).
$ordenId = (string) ($recurso['supplementary_data']['related_ids']['order_id'] ?? '');
$pedidoId = (string) ($recurso['custom_id'] ?? $recurso['purchase_units'][0]['custom_id'] ?? '');
if ($ordenId === '' && str_starts_with($tipo, 'CHECKOUT.ORDER.')) {
    $ordenId = (string) ($recurso['id'] ?? '');
}

if (!webhook_registrar('paypal', $eventoId, $tipo, $pedidoId ?: null, $crudo)) {
    http_response_code(200);
    exit('duplicado');
}

try {
    switch ($tipo) {
        case 'CHECKOUT.ORDER.APPROVED':
        case 'CHECKOUT.ORDER.COMPLETED':
        case 'PAYMENT.CAPTURE.COMPLETED':
        case 'PAYMENT.CAPTURE.PENDING':
        case 'PAYMENT.CAPTURE.DENIED':
            if ($ordenId === '' && es_uuid($pedidoId)) {
                $p = pedido_obtener($pedidoId);
                $ordenId = (string) ($p['proveedor_ref'] ?? '');
            }
            if ($ordenId !== '') {
                $orden = pp_obtener_orden($ordenId);
                if ($orden) {
                    pp_aplicar_orden($orden);
                } else {
                    registrar('paypal', "no se pudo leer la orden $ordenId");
                }
            }
            break;

        case 'PAYMENT.CAPTURE.REFUNDED':
        case 'PAYMENT.CAPTURE.REVERSED':
            if (es_uuid($pedidoId)) {
                pedido_marcar($pedidoId, 'reembolsado', $tipo, ['pago_ref' => (string) ($recurso['id'] ?? '')]);
            }
            break;

        default:
            // Evento no relevante para la tienda: se confirma y se ignora.
            break;
    }
} catch (Throwable $e) {
    // 500 → PayPal reintenta más tarde (p. ej. API caída o credenciales aún sin configurar).
    registrar('paypal', 'error procesando ' . $eventoId . ': ' . $e->getMessage());
    db()->prepare('DELETE FROM eventos_webhook WHERE proveedor = ? AND evento_id = ?')->execute(['paypal', $eventoId]);
    http_response_code(500);
    exit('error');
}

http_response_code(200);
exit('ok');
