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
            $dias = (int) (catalogo()['descarga']['dias_validez'] ?? 30);
            $campos = [
                'estado' => 'pagado',
                'estado_detalle' => $info['estado_detalle'] ?? 'approved',
                'pagado_en' => ahora(),
                'token_descarga' => $p['token_descarga'] ?: bin2hex(random_bytes(24)),
                'expira_en' => gmdate('Y-m-d\TH:i:s\Z', time() + $dias * 86400),
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

/** Correo al comprador, aviso al vendedor y Purchase por Conversions API (cada uno una sola vez). */
function pedido_entregar(array $p): void
{
    if ($p['estado'] !== 'pagado') {
        return;
    }
    require_once __DIR__ . '/correo.php';
    require_once __DIR__ . '/meta_capi.php';

    if (!$p['correo_enviado_en']) {
        try {
            if (correo_entrega($p)) {
                pedido_actualizar($p['id'], ['correo_enviado_en' => ahora()]);
            }
        } catch (Throwable $e) {
            registrar('correo', 'fallo entrega ' . $p['id'] . ': ' . $e->getMessage());
        }
    }
    if (!$p['aviso_enviado_en'] && cfg('correo.copia_a')) {
        try {
            if (correo_aviso_venta($p)) {
                pedido_actualizar($p['id'], ['aviso_enviado_en' => ahora()]);
            }
        } catch (Throwable $e) {
            registrar('correo', 'fallo aviso ' . $p['id'] . ': ' . $e->getMessage());
        }
    }
    if (!$p['capi_enviado_en']) {
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
        $salida['descarga_url'] = url_descarga($p);
        $salida['dias_validez'] = (int) ($cat['descarga']['dias_validez'] ?? 30);
        $salida['descargas_max'] = (int) ($cat['descarga']['descargas_max'] ?? 5);
    }
    return $salida;
}

/** Vuelve a preguntar a la pasarela por un pedido que no esté pagado. */
function pedido_conciliar(array $p, array $pistas = []): array
{
    if ($p['estado'] === 'pagado' || $p['estado'] === 'reembolsado') {
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
