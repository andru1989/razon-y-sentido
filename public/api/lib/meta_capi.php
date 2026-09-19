<?php
/**
 * Meta Conversions API: envía Purchase desde el servidor con el MISMO
 * event_id que usa el navegador en /gracias (el id del pedido), de modo
 * que Meta deduplica y la compra se cuenta aunque el comprador tenga
 * bloqueadores o nunca vuelva a la página. Solo actúa si hay token.
 */
declare(strict_types=1);

function capi_purchase(array $p): bool
{
    $pixel = (string) cfg('meta.pixel_id', '');
    $token = (string) cfg('meta.capi_token', '');
    if ($pixel === '' || $token === '') {
        return false;
    }
    $cat = catalogo();
    $evento = [
        'event_name' => 'Purchase',
        'event_time' => time(),
        'event_id' => $p['id'],
        'action_source' => 'website',
        'event_source_url' => $p['origen'] ?: url_sitio() . '/',
        'user_data' => array_filter([
            'em' => [hash('sha256', strtolower(trim((string) $p['email'])))],
            'client_ip_address' => $p['ip'] ?: null,
            'client_user_agent' => $p['user_agent'] ?: null,
            'country' => [hash('sha256', $p['pais'] === 'otros' ? '' : (string) $p['pais'])],
        ]),
        'custom_data' => [
            'currency' => $p['moneda'],
            'value' => (float) $p['monto'],
            'content_ids' => [$cat['libro']['isbn']],
            'content_type' => 'product',
            'content_name' => $cat['libro']['titulo'],
            'order_id' => $p['id'],
        ],
    ];
    $cuerpo = ['data' => [$evento]];
    if (!en_produccion() && cfg('meta.test_event_code')) {
        $cuerpo['test_event_code'] = cfg('meta.test_event_code');
    }
    $r = http_json('POST', "https://graph.facebook.com/v21.0/$pixel/events?access_token=" . rawurlencode($token), $cuerpo);
    if ($r['status'] !== 200) {
        registrar('meta', 'CAPI rechazó el evento', ['status' => $r['status'], 'resp' => mb_substr($r['raw'], 0, 300)]);
        return false;
    }
    return true;
}
