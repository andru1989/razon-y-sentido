<?php
/**
 * Plantilla de private/config.php.
 *
 * Copiar a:  ~/domains/tiempo.razonysentido.com/private/config.php   (servidor)
 *            ./private/config.php                                     (pruebas locales)
 * Ese directorio está FUERA de public_html y nunca se versiona en git.
 */
return [
    // 'sandbox' usa las credenciales de prueba y api-m.sandbox.paypal.com; 'produccion' cobra de verdad.
    'modo' => 'sandbox',

    // URL pública, sin barra final. En local: 'http://localhost:8080'.
    'url_sitio' => 'https://tiempo.razonysentido.com',

    'mercadopago' => [
        // Panel de desarrolladores → Tu aplicación → Credenciales (de prueba o de producción según `modo`).
        'access_token' => 'APP_USR-xxxxxxxxxxxxxxxx-xxxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-xxxxxxxxx',
        // Tu aplicación → Webhooks → Clave secreta (opcional pero recomendado).
        'webhook_secret' => '',
        // Solo sandbox: correo del usuario comprador de prueba (test_user_…@testuser.com).
        // Así el checkout no te ofrece pagar con tu cuenta real. En producción se ignora.
        'email_comprador_prueba' => '',
    ],

    'paypal' => [
        // developer.paypal.com → Apps & Credentials → tu app (Sandbox o Live según `modo`).
        'client_id' => '',
        'secret' => '',
        // Webhooks → el Webhook ID que aparece al crearlo (opcional; sin él no se verifica la firma).
        'webhook_id' => '',
    ],

    'correo' => [
        'remitente' => 'libro@razonysentido.com',
        'nombre' => 'Razón y Sentido',
        'responder_a' => 'libro@razonysentido.com',
        // Aviso de cada venta al vendedor (vacío = no enviar).
        'copia_a' => '',
        // 'mail' (sendmail de Hostinger, sin configurar nada), 'smtp' (buzón del dominio; mejor entregabilidad)
        // o 'archivo' (pruebas locales: no envía, guarda el correo en private/correos/ y se ve en el panel).
        'transporte' => 'mail',
        // El EPUB va adjunto si pesa hasta estos MB (Gmail admite ~25 MB; base64 añade un 33 %). 0 = solo enlace.
        'adjunto_max_mb' => 10,
        'smtp' => [
            'host' => 'smtp.hostinger.com',
            'puerto' => 465,
            'usuario' => 'libro@razonysentido.com',
            'clave' => '',
        ],
    ],

    'meta' => [
        // Conversions API (opcional): Administrador de eventos → tu píxel → Configuración → Generar token.
        'pixel_id' => '824486448991877',
        'capi_token' => '',
        'test_event_code' => '',
    ],

    'admin' => [
        // Clave (≥ 12 caracteres) para /api/pedidos-admin.php?clave=… Vacía = panel desactivado.
        'clave' => '',
    ],
];
