<?php
/**
 * PayPal · Orders API v2 (México, España, Argentina y resto del mundo).
 * Flujo: crear orden → el comprador aprueba en PayPal → capturamos al volver
 * a /gracias (o desde el webhook si cerró la ventana) → pedido pagado.
 */
declare(strict_types=1);

function pp_base(): string
{
    return en_produccion() ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

function pp_credenciales(): array
{
    $id = (string) cfg('paypal.client_id', '');
    $secreto = (string) cfg('paypal.secret', '');
    if ($id === '' || $secreto === '') {
        throw new RuntimeException('Faltan paypal.client_id / paypal.secret en private/config.php');
    }
    return [$id, $secreto];
}

/** Token OAuth (client_credentials), cacheado en disco hasta 5 min antes de vencer. */
function pp_token(): string
{
    $cache = RYS_PRIVATE_DIR . '/cache/paypal_token_' . (en_produccion() ? 'live' : 'sandbox') . '.json';
    if (is_file($cache)) {
        $d = json_decode((string) file_get_contents($cache), true);
        if (is_array($d) && !empty($d['token']) && ($d['expira'] ?? 0) > time() + 300) {
            return (string) $d['token'];
        }
    }
    [$id, $secreto] = pp_credenciales();
    $r = http_json('POST', pp_base() . '/v1/oauth2/token', null,
        ['Content-Type: application/x-www-form-urlencoded'], "$id:$secreto", 'grant_type=client_credentials');
    if ($r['status'] !== 200 || empty($r['json']['access_token'])) {
        registrar('paypal', 'error obteniendo token', ['status' => $r['status'], 'resp' => mb_substr($r['raw'], 0, 300)]);
        throw new RuntimeException('PayPal no entregó un token de acceso');
    }
    @mkdir(dirname($cache), 0700, true);
    @file_put_contents($cache, json_encode(['token' => $r['json']['access_token'], 'expira' => time() + (int) ($r['json']['expires_in'] ?? 3600)]), LOCK_EX);
    @chmod($cache, 0600);
    return (string) $r['json']['access_token'];
}

function pp_cabeceras(array $extra = []): array
{
    return array_merge(['Authorization: Bearer ' . pp_token()], $extra);
}

/** Crea la orden y devuelve ['id' => ..., 'url' => enlace de aprobación]. */
function pp_crear_orden(array $pedido): array
{
    $cat = catalogo();
    $libro = $cat['libro'];
    $sitio = url_sitio();
    $id = $pedido['id'];
    $monto = number_format((float) $pedido['monto'], 2, '.', '');
    $importe = ['currency_code' => $pedido['moneda'], 'value' => $monto];

    $cuerpo = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => 'epub',
            'custom_id' => $id,
            'invoice_id' => $id,
            'description' => mb_substr($libro['titulo'] . ' (EPUB)', 0, 127),
            'amount' => $importe + ['breakdown' => ['item_total' => $importe]],
            'items' => [[
                'name' => mb_substr($libro['titulo'], 0, 127),
                'description' => 'Libro digital (EPUB) · ' . $libro['autor'],
                'sku' => $libro['isbn_ebook'],
                'unit_amount' => $importe,
                'quantity' => '1',
                'category' => 'DIGITAL_GOODS',
            ]],
        ]],
        'payment_source' => ['paypal' => ['experience_context' => [
            'brand_name' => 'Razón y Sentido',
            'locale' => 'es-ES',
            'landing_page' => 'NO_PREFERENCE',
            'shipping_preference' => 'NO_SHIPPING',
            'user_action' => 'PAY_NOW',
            'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
            'return_url' => "$sitio/gracias?pedido=$id",
            'cancel_url' => "$sitio/?pais={$pedido['pais']}&pago=cancelado#consigue",
        ]]],
    ];

    $r = http_json('POST', pp_base() . '/v2/checkout/orders', $cuerpo, pp_cabeceras(['PayPal-Request-Id: ' . $id, 'Prefer: return=representation']));
    if (!in_array($r['status'], [200, 201], true) || empty($r['json']['id'])) {
        registrar('paypal', 'error creando orden', ['status' => $r['status'], 'resp' => mb_substr($r['raw'], 0, 500)]);
        throw new RuntimeException('PayPal no devolvió una orden válida');
    }
    $url = '';
    foreach ($r['json']['links'] ?? [] as $l) {
        if (in_array($l['rel'] ?? '', ['payer-action', 'approve'], true)) {
            $url = (string) $l['href'];
            break;
        }
    }
    if ($url === '') {
        throw new RuntimeException('PayPal no devolvió el enlace de aprobación');
    }
    return ['id' => (string) $r['json']['id'], 'url' => $url];
}

function pp_obtener_orden(string $ordenId): ?array
{
    $r = http_json('GET', pp_base() . '/v2/checkout/orders/' . rawurlencode($ordenId), null, pp_cabeceras());
    return $r['status'] === 200 && $r['json'] ? $r['json'] : null;
}

/** Captura una orden aprobada. Si ya estaba capturada, devuelve la orden actual. */
function pp_capturar_orden(string $ordenId): ?array
{
    $r = http_json('POST', pp_base() . '/v2/checkout/orders/' . rawurlencode($ordenId) . '/capture', [],
        pp_cabeceras(['PayPal-Request-Id: cap-' . $ordenId, 'Prefer: return=representation']));
    if (in_array($r['status'], [200, 201], true) && $r['json']) {
        return $r['json'];
    }
    $issue = $r['json']['details'][0]['issue'] ?? '';
    if ($r['status'] === 422 && $issue === 'ORDER_ALREADY_CAPTURED') {
        return pp_obtener_orden($ordenId);
    }
    registrar('paypal', "captura de $ordenId no completada", ['status' => $r['status'], 'issue' => $issue, 'resp' => mb_substr($r['raw'], 0, 400)]);
    return null;
}

/** Verifica la firma del webhook con el endpoint oficial de PayPal. */
function pp_verificar_webhook(array $cab, string $cuerpoCrudo): bool
{
    $webhookId = (string) cfg('paypal.webhook_id', '');
    if ($webhookId === '') {
        // Sin id configurado no se puede verificar; el endpoint solo re-consulta la API, así que es inocuo.
        registrar('paypal', 'webhook recibido sin paypal.webhook_id configurado: no se verifica la firma');
        return true;
    }
    $datos = [
        'transmission_id' => $cab['paypal-transmission-id'] ?? '',
        'transmission_time' => $cab['paypal-transmission-time'] ?? '',
        'cert_url' => $cab['paypal-cert-url'] ?? '',
        'auth_algo' => $cab['paypal-auth-algo'] ?? '',
        'transmission_sig' => $cab['paypal-transmission-sig'] ?? '',
        'webhook_id' => $webhookId,
    ];
    // webhook_event debe ir EXACTAMENTE como se recibió: se inserta el JSON crudo sin re-serializar.
    $json = json_encode($datos, JSON_UNESCAPED_SLASHES);
    $json = substr($json, 0, -1) . ',"webhook_event":' . $cuerpoCrudo . '}';
    $r = http_json('POST', pp_base() . '/v1/notifications/verify-webhook-signature', null,
        pp_cabeceras(['Content-Type: application/json']), null, $json);
    return $r['status'] === 200 && (($r['json']['verification_status'] ?? '') === 'SUCCESS');
}

/** Aplica el estado de una orden (COMPLETED → pagado, APPROVED → captura, VOIDED → fallido). */
function pp_aplicar_orden(array $orden): ?array
{
    $pedidoId = (string) ($orden['purchase_units'][0]['custom_id'] ?? '');
    if (!es_uuid($pedidoId)) {
        return null;
    }
    $p = pedido_obtener($pedidoId);
    if (!$p || $p['proveedor'] !== 'paypal') {
        return null;
    }
    $estado = (string) ($orden['status'] ?? '');
    $ordenId = (string) ($orden['id'] ?? $p['proveedor_ref']);

    if ($estado === 'APPROVED') {
        $capturada = pp_capturar_orden($ordenId);
        if (!$capturada) {
            pedido_marcar($pedidoId, 'pendiente', 'APPROVED_SIN_CAPTURA');
            return pedido_obtener($pedidoId);
        }
        $orden = $capturada;
        $estado = (string) ($orden['status'] ?? '');
    }

    if ($estado === 'COMPLETED') {
        $captura = $orden['purchase_units'][0]['payments']['captures'][0] ?? [];
        $capEstado = (string) ($captura['status'] ?? 'COMPLETED');
        if ($capEstado === 'COMPLETED') {
            $montoOk = (float) ($captura['amount']['value'] ?? 0) + 0.01 >= (float) $p['monto'];
            $monedaOk = ($captura['amount']['currency_code'] ?? '') === $p['moneda'];
            if (!$montoOk || !$monedaOk) {
                registrar('paypal', "captura con monto/moneda distintos; NO se entrega", ['pedido' => $pedidoId, 'amount' => $captura['amount'] ?? null]);
                pedido_marcar($pedidoId, 'pendiente', 'revisar_monto', ['pago_ref' => (string) ($captura['id'] ?? '')]);
                return pedido_obtener($pedidoId);
            }
            $emailPagador = $orden['payer']['email_address'] ?? ($orden['payment_source']['paypal']['email_address'] ?? null);
            return pedido_marcar_pagado($pedidoId, ['pago_ref' => (string) ($captura['id'] ?? ''), 'email_pagador' => $emailPagador, 'estado_detalle' => 'COMPLETED']);
        }
        if ($capEstado === 'PENDING') {
            pedido_marcar($pedidoId, 'pendiente', 'CAPTURE_PENDING:' . ($captura['status_details']['reason'] ?? ''), ['pago_ref' => (string) ($captura['id'] ?? '')]);
        } elseif (in_array($capEstado, ['DECLINED', 'FAILED'], true)) {
            pedido_marcar($pedidoId, 'fallido', 'CAPTURE_' . $capEstado, ['pago_ref' => (string) ($captura['id'] ?? '')]);
        } elseif ($capEstado === 'REFUNDED') {
            pedido_marcar($pedidoId, 'reembolsado', 'CAPTURE_REFUNDED', ['pago_ref' => (string) ($captura['id'] ?? '')]);
        }
        return pedido_obtener($pedidoId);
    }

    if ($estado === 'VOIDED') {
        pedido_marcar($pedidoId, 'fallido', 'VOIDED');
    } elseif ($estado !== '') {
        // CREATED / SAVED / PAYER_ACTION_REQUIRED: el comprador aún no ha aprobado
        pedido_marcar($pedidoId, 'pendiente', $estado);
    }
    return pedido_obtener($pedidoId);
}

function pp_conciliar(array $p): array
{
    if (!$p['proveedor_ref']) {
        return $p;
    }
    $orden = pp_obtener_orden((string) $p['proveedor_ref']);
    if ($orden) {
        $res = pp_aplicar_orden($orden);
        if ($res) {
            return $res;
        }
    }
    return pedido_obtener($p['id']) ?? $p;
}
