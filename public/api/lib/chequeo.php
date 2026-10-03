<?php
/**
 * Chequeo de lanzamiento: verifica que todo lo necesario para cobrar y entregar
 * esté en su sitio. Lo muestra el panel de pedidos con ?chequeo=1.
 * Cada punto: ['ok' | 'aviso' | 'error', título, detalle].
 */
declare(strict_types=1);

function chequeo_lanzamiento(): array
{
    $r = [];
    $cat = catalogo();
    $prod = en_produccion();

    // 1. Modo
    $r[] = $prod
        ? ['ok', 'Modo producción', 'Se cobra de verdad.']
        : ['aviso', 'Modo sandbox', "Cambia 'modo' => 'produccion' en private/config.php para cobrar de verdad."];

    // 2. URL pública
    $url = url_sitio();
    $r[] = str_starts_with($url, 'https://')
        ? ['ok', 'URL pública con HTTPS', $url]
        : ['error', 'URL sin HTTPS', "$url · Mercado Pago no redirige ni envía webhooks sin HTTPS."];

    // 3. Países y pasarelas activas
    $activos = [];
    foreach ($cat['paises'] ?? [] as $codigo => $p) {
        if (!empty($p['directo']['activo'])) {
            $activos[$p['directo']['proveedor']][] = strtoupper($codigo);
        }
    }
    $r[] = $activos
        ? ['ok', 'Venta directa activa', implode(' · ', array_map(fn($prov, $ps) => "$prov: " . implode(', ', $ps), array_keys($activos), $activos))]
        : ['aviso', 'Venta directa apagada', 'Ningún país tiene la compra directa activa en catalogo.json.'];

    // 4. Mercado Pago
    if (isset($activos['mercadopago'])) {
        $token = (string) cfg('mercadopago.access_token', '');
        if ($token === '' || str_contains($token, 'xxxx')) {
            $r[] = ['error', 'Mercado Pago sin Access Token', 'Falta mercadopago.access_token.'];
        } else {
            $yo = http_json('GET', 'https://api.mercadopago.com/users/me', null, ['Authorization: Bearer ' . $token]);
            if ($yo['status'] !== 200) {
                $r[] = ['error', 'Access Token de Mercado Pago inválido', 'La API respondió ' . $yo['status'] . '.'];
            } else {
                $esPrueba = in_array('test_user', (array) ($yo['json']['tags'] ?? []), true);
                $cuenta = ($yo['json']['nickname'] ?? '') . ' · ' . ($yo['json']['site_id'] ?? '');
                if ($prod && $esPrueba) {
                    $r[] = ['error', 'Token de Mercado Pago DE PRUEBA en producción', "Cuenta $cuenta. Los clientes reales verán un error. Usa las credenciales de producción."];
                } elseif (!$prod && !$esPrueba) {
                    $r[] = ['aviso', 'Token REAL de Mercado Pago en sandbox', "Cuenta $cuenta. Las compras de prueba cobrarían de verdad."];
                } else {
                    $r[] = ['ok', 'Mercado Pago conectado', 'Cuenta ' . $cuenta . ($esPrueba ? ' (prueba)' : ' (real)')];
                }
            }
        }
        $r[] = cfg('mercadopago.webhook_secret')
            ? ['ok', 'Webhook de Mercado Pago con clave secreta', 'Las notificaciones se verifican con firma.']
            : ['aviso', 'Webhook de Mercado Pago sin clave secreta', 'Funciona igual (siempre se re-consulta la API), pero conviene configurarla.'];
    }

    // 5. PayPal
    if (isset($activos['paypal'])) {
        $r[] = cfg('paypal.client_id') && cfg('paypal.secret')
            ? ['ok', 'PayPal con credenciales', en_produccion() ? 'Live' : 'Sandbox']
            : ['error', 'PayPal activo sin credenciales', 'Hay países con PayPal activo pero faltan client_id / secret.'];
    }

    // 6. EPUB
    $ruta = RYS_PRIVATE_DIR . '/' . basename((string) ($cat['libro']['archivo'] ?? 'libro.epub'));
    if (!is_file($ruta)) {
        $r[] = ['error', 'Falta el EPUB', "No existe $ruta."];
    } else {
        $cabecera = (string) @file_get_contents($ruta, false, null, 0, 64);
        $tam = filesize($ruta);
        $maxAdj = (float) cfg('correo.adjunto_max_mb', 10);
        $mb = round($tam / 1048576, 2);
        if (!str_starts_with($cabecera, 'PK') || !str_contains($cabecera, 'application/epub+zip')) {
            $r[] = ['error', 'El archivo no parece un EPUB válido', "$mb MB · debería empezar por el tipo application/epub+zip."];
        } elseif ($tam < 20000) {
            $r[] = ['error', 'El EPUB es demasiado pequeño', "$mb MB · parece un archivo de prueba, no el libro."];
        } else {
            $r[] = ['ok', 'EPUB listo', "$mb MB" . ($mb <= $maxAdj ? ' · va adjunto en el correo' : " · supera $maxAdj MB: se envía solo el enlace")];
        }
    }

    // 7. Correo
    $transporte = (string) cfg('correo.transporte', 'mail');
    if ($transporte === 'archivo') {
        $r[] = ['error', 'El correo no se envía', "correo.transporte = 'archivo' (solo para pruebas locales)."];
    } elseif ($transporte === 'smtp') {
        require_once __DIR__ . '/correo.php';
        $fallo = smtp_probar();
        $r[] = $fallo === ''
            ? ['ok', 'SMTP autenticado', cfg('correo.smtp.usuario') . ' @ ' . cfg('correo.smtp.host')]
            : ['error', 'SMTP no funciona', $fallo];
    } else {
        $r[] = ['aviso', "Correo por mail() de PHP", 'Si el remitente no es del servidor, puede llegar a spam. Recomendado: SMTP del buzón del dominio.'];
    }
    require_once __DIR__ . '/correo.php';
    $avisos = correo_destinatarios_aviso();
    $r[] = $avisos
        ? ['ok', 'Aviso de cada venta', 'Se envía a ' . implode(' y ', $avisos)]
        : ['aviso', 'Sin aviso de ventas', 'Pon uno o varios correos en correo.copia_a para enterarte de cada venta.'];

    return $r;
}
