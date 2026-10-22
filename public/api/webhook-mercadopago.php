<?php
/**
 * Avisos de Mercado Pago (tema "payment"). Llegan de dos formas:
 *   - Webhooks del panel (?data.id=…&type=payment, con cabecera x-signature): se verifica la firma
 *     con mercadopago.webhook_secret y, si no cuadra, se rechazan (401).
 *   - IPN de la notification_url de cada preferencia (?topic=payment&id=…): Mercado Pago NO los firma.
 *     Se aceptan porque el aviso solo dice "mira el pago X": el estado SIEMPRE se lee de la API con
 *     nuestro Access Token, así que un aviso falso no puede marcar nada como pagado.
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

$firmado = isset(cabeceras_entrada()['x-signature']);
if ($firmado && !mp_firma_valida($dataId)) {
    registrar('mercadopago', 'firma inválida', ['data_id' => $dataId]);
    http_response_code(401);
    exit('firma inválida');
}
if (!$firmado) {
    registrar('mercadopago', 'aviso IPN sin firma: se verifica consultando la API', ['data_id' => $dataId]);
}
if (!ctype_digit($dataId)) {
    http_response_code(200);
    exit('ignorado');
}

$pago = mp_obtener_pago($dataId);
if (!$pago) {
    // 200 para que Mercado Pago no reintente un pago que no existe; el fallo queda en el registro.
    registrar('mercadopago', "no se pudo leer el pago $dataId");
    http_response_code(200);
    exit('pago no disponible');
}

// Deduplicación por (pago + estado real): el mismo pago se procesa otra vez cuando CAMBIA de estado
// (pendiente → aprobado → reembolsado), pero no por cada reintento del mismo aviso.
$eventoId = $dataId . ':' . (string) ($pago['status'] ?? '') . ':' . (string) ($pago['status_detail'] ?? '');
if (!webhook_registrar('mercadopago', $eventoId, $tipo, (string) ($_GET['pedido'] ?? ''), $crudo)) {
    http_response_code(200);
    exit('duplicado');
}

mp_aplicar_pago($pago);
http_response_code(200);
exit('ok');
