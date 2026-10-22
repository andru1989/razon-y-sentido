<?php
/**
 * Pedidos: creación, consulta, transición a "pagado" y entrega.
 * La transición a pagado es idempotente: da igual que llegue por el webhook,
 * por la conciliación desde /gracias o por el panel; se entrega una sola vez.
 */
declare(strict_types=1);

function pedido_crear(array $d): array
{
    $id = uuid4();
    $ahora = ahora();
    $st = db()->prepare('INSERT INTO pedidos (id, creado_en, actualizado_en, pais, proveedor, moneda, monto, email, estado, ip, user_agent, origen)
                         VALUES (:id, :c, :a, :pais, :prov, :mon, :monto, :email, "pendiente", :ip, :ua, :origen)');
    $st->execute([
        ':id' => $id, ':c' => $ahora, ':a' => $ahora,
        ':pais' => $d['pais'], ':prov' => $d['proveedor'], ':mon' => $d['moneda'], ':monto' => $d['monto'],
        ':email' => $d['email'], ':ip' => $d['ip'] ?? null,
        ':ua' => isset($d['user_agent']) ? mb_substr($d['user_agent'], 0, 300) : null,
        ':origen' => isset($d['origen']) ? mb_substr($d['origen'], 0, 1000) : null,
    ]);
    return pedido_obtener($id);
}

function pedido_obtener(string $id): ?array
{
    $st = db()->prepare('SELECT * FROM pedidos WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    return $p ?: null;
}

function pedido_por_token(string $token): ?array
{
    $st = db()->prepare('SELECT * FROM pedidos WHERE token_descarga = ?');
    $st->execute([$token]);
    $p = $st->fetch();
    return $p ?: null;
}

function pedido_por_proveedor_ref(string $proveedor, string $ref): ?array
{
    $st = db()->prepare('SELECT * FROM pedidos WHERE proveedor = ? AND proveedor_ref = ?');
    $st->execute([$proveedor, $ref]);
    $p = $st->fetch();
    return $p ?: null;
}

function pedido_actualizar(string $id, array $campos): void
{
    if (!$campos) {
        return;
    }
    $permitidos = ['proveedor_ref', 'pago_ref', 'email_pagador', 'estado', 'estado_detalle', 'token_descarga', 'descargas',
        'expira_en', 'pagado_en', 'correo_enviado_en', 'aviso_enviado_en', 'capi_enviado_en'];
    $sets = ['actualizado_en = :actualizado'];
    $valores = [':actualizado' => ahora(), ':id' => $id];
    foreach ($campos as $k => $v) {
        if (!in_array($k, $permitidos, true)) {
            throw new InvalidArgumentException("Campo no permitido: $k");
        }
        $sets[] = "$k = :$k";
        $valores[":$k"] = $v;
    }
    db()->prepare('UPDATE pedidos SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($valores);
}

/** Cambia el estado (pendiente/fallido/reembolsado) sin tocar la entrega. */
function pedido_marcar(string $id, string $estado, ?string $detalle = null, array $extra = []): void
{
    $p = pedido_obtener($id);
    if (!$p) {
        return;
    }
    // Un pedido pagado nunca vuelve a pendiente/fallido por una notificación tardía.
    if ($p['estado'] === 'pagado' && $estado !== 'reembolsado') {
        return;
    }
    pedido_actualizar($id, array_merge(['estado' => $estado, 'estado_detalle' => $detalle], $extra));
}

/**
 * Marca el pedido como pagado y entrega el libro. Se puede llamar cuantas
 * veces haga falta: solo la primera genera token, correo y evento de compra.
 */
function pedido_marcar_pagado(string $id, array $info = []): ?array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM pedidos WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) {
            $pdo->rollBack();
            return null;
        }
        if ($p['estado'] !== 'pagado') {
            $campos = [
                'estado' => 'pagado',
                'estado_detalle' => $info['estado_detalle'] ?? 'approved',
                'pagado_en' => ahora(),
                'token_descarga' => $p['token_descarga'] ?: bin2hex(random_bytes(24)),
                'expira_en' => vencimiento_descarga($p),
            ];
            if (!empty($info['pago_ref'])) {
                $campos['pago_ref'] = (string) $info['pago_ref'];
            }
            if (!empty($info['email_pagador'])) {
                $campos['email_pagador'] = mb_substr((string) $info['email_pagador'], 0, 190);
            }
            pedido_actualizar($id, $campos);
            registrar('pedidos', "pagado $id", ['proveedor' => $p['proveedor'], 'pais' => $p['pais'], 'monto' => $p['monto'], 'moneda' => $p['moneda']]);
        } elseif (!empty($info['pago_ref']) && !$p['pago_ref']) {
            pedido_actualizar($id, ['pago_ref' => (string) $info['pago_ref']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $p = pedido_obtener($id);
    pedido_entregar($p);
    return pedido_obtener($id);
}

const ZONA_LOCAL = 'America/Bogota';

/** Último segundo del mes en curso, hora de Colombia, expresado en UTC («2026-11-01T04:59:59Z»). */
function fin_de_mes_utc(): string
{
    $fin = new DateTimeImmutable('last day of this month 23:59:59', new DateTimeZone(ZONA_LOCAL));
    return $fin->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

/**
 * Hasta cuándo funciona el enlace de descarga de un pedido recién pagado (o renovado):
 * ventas por pago directo (QR, transferencia) → hasta fin de mes; ventas en línea → N días.
 */
function vencimiento_descarga(array $p): string
{
    if (($p['proveedor'] ?? '') === 'manual') {
        return fin_de_mes_utc();
    }
    $dias = (int) (catalogo()['descarga']['dias_validez'] ?? 30);
    return gmdate('Y-m-d\TH:i:s\Z', time() + $dias * 86400);
}

/** Fecha UTC guardada → «31/10/2026» en hora de Colombia. */
function fecha_local(?string $utc): string
{
    if (!$utc) {
        return '';
    }
    return (new DateTimeImmutable($utc))->setTimezone(new DateTimeZone(ZONA_LOCAL))->format('d/m/Y');
}

/** Medios aceptados para las ventas registradas a mano desde el panel. */
const MEDIOS_MANUALES = ['QR Bancolombia', 'Transferencia Bancolombia', 'Nequi', 'Daviplata', 'Otra transferencia', 'Efectivo', 'Otro'];

/**
 * Venta cobrada fuera del sitio (QR Bancolombia, transferencia, Nequi…) que Luis registra desde
 * el panel tras verificar el pago en su banco: crea el pedido, lo marca pagado y genera el enlace
 * de descarga. El correo es opcional: si viene, también se envía el libro por correo.
 * Devuelve el pedido o un texto de error.
 */
function pedido_entrega_manual(string $email, string $pais, float $monto, string $moneda, string $medio, string $referencia, string $whatsapp = ''): array|string
{
    $email = strtolower(trim($email));
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190)) {
        return 'El correo del comprador no es válido (o déjalo vacío).';
    }
    $whatsapp = whatsapp_normalizar($whatsapp);
    if ($whatsapp === '') {
        return 'Escribe el WhatsApp del comprador (por ejemplo 300 123 4567, o con indicativo +52… si es de otro país).';
    }
    if (!isset(catalogo()['paises'][$pais])) {
        return 'País no válido.';
    }
    if (!in_array($moneda, ['COP', 'USD', 'EUR'], true) || $monto <= 0 || $monto > 10000000) {
        return 'Importe o moneda no válidos.';
    }
    if (!in_array($medio, MEDIOS_MANUALES, true)) {
        return 'Medio de pago no válido.';
    }
    $referencia = trim(preg_replace('/\s+/', ' ', $referencia) ?? '');
    $p = pedido_crear([
        'pais' => $pais, 'proveedor' => 'manual', 'moneda' => $moneda, 'monto' => $monto,
        'email' => $email, 'ip' => ip_cliente(), 'user_agent' => 'panel', 'origen' => 'panel',
    ]);
    $ref = mb_substr($medio . ($whatsapp !== '' ? ' · WA ' . $whatsapp : '') . ($referencia !== '' ? ' · ' . $referencia : ''), 0, 120);
    registrar('pedidos', 'venta manual ' . $p['id'], ['medio' => $medio, 'monto' => $monto, 'moneda' => $moneda]);
    return pedido_marcar_pagado($p['id'], ['pago_ref' => $ref, 'estado_detalle' => 'manual']) ?? 'No se pudo registrar la venta.';
}

/**
 * Número en formato internacional sin "+" (el que exige wa.me), o '' si no es válido.
 * Un número colombiano de 10 dígitos (300 123 4567) recibe el indicativo 57.
 */
function whatsapp_normalizar(string $numero): string
{
    $d = preg_replace('/\D/', '', $numero) ?? '';
    if (str_starts_with($d, '00')) {
        $d = substr($d, 2); // 0057… → 57…
    }
    if (strlen($d) === 10 && $d[0] === '3') {
        $d = '57' . $d;
    }
    return (strlen($d) >= 11 && strlen($d) <= 15) ? $d : '';
}

/** WhatsApp guardado en una venta manual (va en pago_ref como «WA 573001234567»). */
function whatsapp_de_pedido(array $p): string
{
    return preg_match('/\bWA (\d{11,15})\b/', (string) $p['pago_ref'], $m) ? $m[1] : '';
}

/** «573196787454» → «+57 319 678 7454» (otros países: «+52 5512345678»). */
function whatsapp_legible(string $numero): string
{
    if (strlen($numero) === 12 && str_starts_with($numero, '57')) {
        return '+57 ' . substr($numero, 2, 3) . ' ' . substr($numero, 5, 3) . ' ' . substr($numero, 8);
    }
    return '+' . substr($numero, 0, 2) . ' ' . substr($numero, 2);
}

/** URL personal de la página de gracias de un pedido (la que se comparte con el comprador). */
function url_gracias(array $p): string
{
    return url_sitio() . '/gracias?pedido=' . rawurlencode((string) $p['id']);
}

/** Correo al comprador, aviso al vendedor y Purchase por Conversions API (cada uno una sola vez). */
function pedido_entregar(array $p): void
{
    if ($p['estado'] !== 'pagado') {
        return;
    }
    require_once __DIR__ . '/correo.php';
    require_once __DIR__ . '/meta_capi.php';

    // Sin correo (venta manual entregada solo por enlace) no hay nada que enviar al comprador.
    if (!$p['correo_enviado_en'] && $p['email'] !== '') {
        try {
            if (correo_entrega($p)) {
                pedido_actualizar($p['id'], ['correo_enviado_en' => ahora()]);
            }
        } catch (Throwable $e) {
            registrar('correo', 'fallo entrega ' . $p['id'] . ': ' . $e->getMessage());
        }
    }
    if (!$p['aviso_enviado_en'] && cfg('correo.copia_a')) { // uno o varios correos (ver correo_destinatarios_aviso)
        try {
            if (correo_aviso_venta($p)) {
                pedido_actualizar($p['id'], ['aviso_enviado_en' => ahora()]);
            }
        } catch (Throwable $e) {
            registrar('correo', 'fallo aviso ' . $p['id'] . ': ' . $e->getMessage());
        }
    }
    // Las ventas manuales no vienen de un anuncio ni del checkout: no se reportan a Meta.
    if (!$p['capi_enviado_en'] && $p['proveedor'] !== 'manual') {
        try {
            if (capi_purchase($p)) {
                pedido_actualizar($p['id'], ['capi_enviado_en' => ahora()]);
            }
        } catch (Throwable $e) {
            registrar('meta', 'fallo CAPI ' . $p['id'] . ': ' . $e->getMessage());
        }
    }
}

function url_descarga(array $p): string
{
    return url_sitio() . '/api/descarga.php?t=' . rawurlencode((string) $p['token_descarga']);
}

/** Representación segura para el navegador (sin IP, sin refs internas). */
function pedido_publico(array $p): array
{
    $cat = catalogo();
    $salida = [
        'id' => $p['id'],
        'id_corto' => strtoupper(substr($p['id'], 0, 8)),
        'estado' => $p['estado'],
        'estado_detalle' => $p['estado_detalle'],
        'pais' => $p['pais'],
        'proveedor' => $p['proveedor'],
        'moneda' => $p['moneda'],
        'monto' => (float) $p['monto'],
        'email' => $p['email'],
    ];
    if ($p['estado'] === 'pagado' && $p['token_descarga']) {
        $salida['correo_enviado'] = (bool) $p['correo_enviado_en'];
        $salida['descarga_url'] = url_descarga($p);
        $salida['dias_validez'] = (int) ($cat['descarga']['dias_validez'] ?? 30);
        $salida['valido_hasta'] = fecha_local($p['expira_en']);
        $salida['descargas_max'] = (int) ($cat['descarga']['descargas_max'] ?? 5);
    }
    return $salida;
}

/** Vuelve a preguntar a la pasarela por un pedido que no esté pagado. */
function pedido_conciliar(array $p, array $pistas = []): array
{
    if ($p['estado'] === 'pagado' || $p['estado'] === 'reembolsado' || $p['proveedor'] === 'manual') {
        return $p;
    }
    try {
        if ($p['proveedor'] === 'mercadopago') {
            require_once __DIR__ . '/mercadopago.php';
            return mp_conciliar($p, $pistas['payment_id'] ?? null);
        }
        if ($p['proveedor'] === 'paypal') {
            require_once __DIR__ . '/paypal.php';
            return pp_conciliar($p);
        }
    } catch (Throwable $e) {
        registrar('errores', 'conciliar ' . $p['id'] . ': ' . $e->getMessage());
    }
    return pedido_obtener($p['id']) ?? $p;
}
