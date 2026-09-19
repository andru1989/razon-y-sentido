<?php
/**
 * Webhook de Mercado Pago (tema "payment").
 * Configurar en el panel de desarrolladores de Mercado Pago:
 *   URL:     https://tiempo.razonysentido.com/api/webhook-mercadopago.php
 *   Eventos: Pagos
 *   Clave secreta → private/config.php (mercadopago.webhook_secret)
 * Siempre re-consulta el pago a la API: el cuerpo del aviso no se usa para decidir nada.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/mercadopago.php';

$crudo = (string) file_get_contents('php://input');
$cuerpo = json_decode($crudo, true) ?: [];

// PHP convierte "data.id" en "data_id"; se aceptan todas las variantes.
$tipo = (string) ($_GET['type'] ?? $_GET['topic'] ?? $cuerpo['type'] ?? '');
$dataId = (string) ($_GET['data.id'] ?? $_GET['data_id'] ?? $cuerpo['data']['id'] ?? $_GET['id'] ?? '');
$accion = (string) ($cuerpo['action'] ?? '');

registrar('mercadopago', 'webhook', ['tipo' => $tipo, 'accion' => $accion, 'data_id' => $dataId, 'pedido' => $_GET['pedido'] ?? null]);

// Otros temas (merchant_order, plan, etc.): se confirman y se ignoran.
if ($tipo !== 'payment' || $dataId === '') {
    http_response_code(200);
    exit('ignorado');
}

if (!mp_firma_valida($dataId)) {
    registrar('mercadopago', 'firma inválida', ['data_id' => $dataId]);
    http_response_code(401);
    exit('firma inválida');
}

// Deduplicación: misma notificación (id de pago + acción) solo se procesa una vez.
$eventoId = $dataId . ':' . ($accion ?: 'payment') . ':' . (string) ($cuerpo['id'] ?? '');
if (!webhook_registrar('mercadopago', $eventoId, $tipo, (string) ($_GET['pedido'] ?? ''), $crudo)) {
    http_response_code(200);
    exit('duplicado');
}

$pago = mp_obtener_pago($dataId);
if (!$pago) {
    // 200 para que Mercado Pago no reintente un pago que no existe; el fallo queda en el registro.
    registrar('mercadopago', "no se pudo leer el pago $dataId");
    http_response_code(200);
    exit('pago no disponible');
}

mp_aplicar_pago($pago);
http_response_code(200);
exit('ok');
