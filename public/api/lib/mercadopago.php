<?php
/**
 * Mercado Pago · Checkout Pro (Colombia, COP).
 * Solo se confía en lo que responde la API con nuestro Access Token; el
 * cuerpo del webhook es apenas un aviso de "ve a mirar el pago X".
 */
declare(strict_types=1);

const MP_API = 'https://api.mercadopago.com';

function mp_token(): string
{
    $t = (string) cfg('mercadopago.access_token', '');
    if ($t === '') {
        throw new RuntimeException('Falta mercadopago.access_token en private/config.php');
    }
    return $t;
}

function mp_cabeceras(array $extra = []): array
{
    return array_merge(['Authorization: Bearer ' . mp_token()], $extra);
}

/** Crea la preferencia de pago y devuelve ['id' => ..., 'url' => init_point]. */
function mp_crear_preferencia(array $pedido): array
{
    $cat = catalogo();
    $libro = $cat['libro'];
    $sitio = url_sitio();
    $id = $pedido['id'];

    $cuerpo = [
        'items' => [[
            'id' => $libro['isbn_ebook'],
            'title' => $libro['titulo'] . ' (EPUB)',
            'description' => 'Libro digital · ' . $libro['autor'],
            'picture_url' => $sitio . '/img/portada.jpeg',
            'category_id' => 'others',
            'quantity' => 1,
            'currency_id' => $pedido['moneda'],
            'unit_price' => (float) $pedido['monto'],
        ]],
        'external_reference' => $id,
        'back_urls' => [
            'success' => "$sitio/gracias?pedido=$id",
            'pending' => "$sitio/gracias?pedido=$id",
            'failure' => "$sitio/gracias?pedido=$id&pago=cancelado",
        ],
        'statement_descriptor' => 'RAZONYSENTIDO',
        'binary_mode' => false, // permite PSE / efectivo (quedan "pendiente" hasta que se aprueban)
        'metadata' => ['pedido_id' => $id, 'pais' => $pedido['pais']],
    ];
    // Pagador sugerido. En producción, el correo del comprador. En sandbox, NUNCA el correo real
    // (MP lo asocia a la cuenta real y ofrece pagar con ella): se usa el comprador de prueba si está
    // configurado; si no, no se envía y MP pide iniciar sesión.
    if (en_produccion()) {
        $cuerpo['payer'] = ['email' => $pedido['email']];
    } elseif ($prueba = (string) cfg('mercadopago.email_comprador_prueba', '')) {
        $cuerpo['payer'] = ['email' => $prueba];
    }

    // Mercado Pago rechaza auto_return y notification_url si el sitio no es HTTPS público
    // (p. ej. pruebas en http://127.0.0.1). En local el comprador vuelve con «Volver al sitio».
    if (str_starts_with($sitio, 'https://')) {
        $cuerpo['auto_return'] = 'approved';
        $cuerpo['notification_url'] = "$sitio/api/webhook-mercadopago.php?pedido=$id";
    }

    $r = http_json('POST', MP_API . '/checkout/preferences', $cuerpo, mp_cabeceras(['X-Idempotency-Key: ' . $id]));
    if ($r['status'] !== 201 || empty($r['json']['init_point'])) {
        registrar('mercadopago', 'error creando preferencia', ['status' => $r['status'], 'resp' => mb_substr($r['raw'], 0, 500)]);
        throw new RuntimeException('Mercado Pago no devolvió una preferencia válida');
    }
    return ['id' => (string) $r['json']['id'], 'url' => (string) $r['json']['init_point']];
}

function mp_obtener_pago(string $paymentId): ?array
{
    $r = http_json('GET', MP_API . '/v1/payments/' . rawurlencode($paymentId), null, mp_cabeceras());
    return $r['status'] === 200 && $r['json'] ? $r['json'] : null;
}

/** Pagos asociados a nuestro id de pedido (external_reference), más recientes primero. */
function mp_buscar_pagos(string $pedidoId): array
{
    $q = http_build_query(['external_reference' => $pedidoId, 'sort' => 'date_created', 'criteria' => 'desc']);
    $r = http_json('GET', MP_API . '/v1/payments/search?' . $q, null, mp_cabeceras());
    return $r['status'] === 200 && isset($r['json']['results']) && is_array($r['json']['results']) ? $r['json']['results'] : [];
}

/**
 * Verifica la cabecera x-signature (HMAC-SHA256 de "id:<data.id>;request-id:<x-request-id>;ts:<ts>;").
 * Si no hay clave secreta configurada devuelve true: la seguridad real está en
 * re-consultar el pago a la API, nunca en el cuerpo del aviso.
 */
function mp_firma_valida(string $dataId): bool
{
    $secreto = (string) cfg('mercadopago.webhook_secret', '');
    if ($secreto === '') {
        return true;
    }
    $cab = cabeceras_entrada();
    $firma = $cab['x-signature'] ?? '';
    $requestId = $cab['x-request-id'] ?? '';
    $ts = null;
    $hash = null;
    foreach (explode(',', $firma) as $parte) {
        $kv = explode('=', $parte, 2);
        if (count($kv) !== 2) {
            continue;
        }
        [$k, $v] = [trim($kv[0]), trim($kv[1])];
        if ($k === 'ts') {
            $ts = $v;
        } elseif ($k === 'v1') {
            $hash = $v;
        }
    }
    if ($ts === null || $hash === null) {
        return false;
    }
    $partes = [];
    if ($dataId !== '') {
        // Mercado Pago pide el id en minúsculas si es alfanumérico
        $partes[] = 'id:' . (ctype_digit($dataId) ? $dataId : strtolower($dataId));
    }
    if ($requestId !== '') {
        $partes[] = "request-id:$requestId";
    }
    $partes[] = "ts:$ts";
    $manifiesto = implode(';', $partes) . ';';
    return hash_equals(hash_hmac('sha256', $manifiesto, $secreto), $hash);
}

/**
 * Traduce un recurso "payment" de Mercado Pago a nuestro estado de pedido.
 * Devuelve el pedido actualizado, o null si el pago no es de esta tienda.
 */
function mp_aplicar_pago(array $pago): ?array
{
    $pedidoId = (string) ($pago['external_reference'] ?? '');
    if (!es_uuid($pedidoId)) {
        return null;
    }
    $p = pedido_obtener($pedidoId);
    if (!$p || $p['proveedor'] !== 'mercadopago') {
        return null;
    }

    $estado = (string) ($pago['status'] ?? '');
    $pagoId = (string) ($pago['id'] ?? '');
    $emailPagador = $pago['payer']['email'] ?? null;

    switch ($estado) {
        case 'approved':
            $montoOk = (float) ($pago['transaction_amount'] ?? 0) + 0.01 >= (float) $p['monto'];
            $monedaOk = ($pago['currency_id'] ?? '') === $p['moneda'];
            if (!$montoOk || !$monedaOk) {
                registrar('mercadopago', "pago $pagoId aprobado con monto/moneda distintos; NO se entrega", ['pedido' => $pedidoId, 'monto' => $pago['transaction_amount'] ?? null, 'moneda' => $pago['currency_id'] ?? null]);
                pedido_marcar($pedidoId, 'pendiente', 'revisar_monto', ['pago_ref' => $pagoId]);
                break;
            }
            return pedido_marcar_pagado($pedidoId, ['pago_ref' => $pagoId, 'email_pagador' => $emailPagador, 'estado_detalle' => 'approved']);
        case 'pending':
        case 'in_process':
        case 'in_mediation':
        case 'authorized':
            pedido_marcar($pedidoId, 'pendiente', $estado, ['pago_ref' => $pagoId]);
            break;
        case 'rejected':
        case 'cancelled':
            pedido_marcar($pedidoId, 'fallido', $estado . (isset($pago['status_detail']) ? ':' . $pago['status_detail'] : ''), ['pago_ref' => $pagoId]);
            break;
        case 'refunded':
        case 'charged_back':
            pedido_marcar($pedidoId, 'reembolsado', $estado, ['pago_ref' => $pagoId]);
            break;
    }
    return pedido_obtener($pedidoId);
}

/** Con o sin id de pago: consulta a Mercado Pago y aplica el resultado. */
function mp_conciliar(array $p, ?string $paymentId = null): array
{
    $pagos = [];
    if ($paymentId && ctype_digit($paymentId)) {
        $pago = mp_obtener_pago($paymentId);
        if ($pago) {
            $pagos[] = $pago;
        }
    }
    if (!$pagos) {
        $pagos = mp_buscar_pagos($p['id']);
    }
    // Si hay varios intentos (p. ej. tarjeta rechazada y luego PSE), gana el aprobado.
    usort($pagos, fn($a, $b) => (($b['status'] ?? '') === 'approved') <=> (($a['status'] ?? '') === 'approved'));
    foreach ($pagos as $pago) {
        $res = mp_aplicar_pago($pago);
        if ($res && $res['estado'] === 'pagado') {
            return $res;
        }
    }
    return pedido_obtener($p['id']) ?? $p;
}
