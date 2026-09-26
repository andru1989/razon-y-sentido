<?php
/**
 * Correo de entrega. Tres transportes, elegidos en config.php (correo.transporte):
 *   'mail'    → mail() de PHP (Hostinger lo enruta por su sendmail; funciona sin configurar nada)
 *   'smtp'    → SMTP autenticado con un buzón del dominio (mejor entregabilidad)
 *   'archivo' → no envía: guarda el correo en private/correos/ para revisarlo (pruebas locales)
 */
declare(strict_types=1);

/**
 * @param array<int, array{nombre: string, ruta: string, tipo: string}> $adjuntos
 */
function correo_enviar(string $para, string $asunto, string $html, string $texto, ?string $responderA = null, array $adjuntos = []): bool
{
    $remitente = (string) cfg('correo.remitente', 'no-reply@tiempo.razonysentido.com');
    $nombre = (string) cfg('correo.nombre', 'Razón y Sentido');
    $responderA = $responderA ?: (string) cfg('correo.responder_a', $remitente);

    // multipart/mixed ( multipart/alternative (texto + html) + adjuntos )
    $alt = 'rys-alt-' . bin2hex(random_bytes(8));
    $alternativa = "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto))
        . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
        . "--$alt--\r\n";

    $cabeceras = [
        'From: ' . mb_encode_mimeheader($nombre, 'UTF-8') . " <$remitente>",
        "Reply-To: $responderA",
        'MIME-Version: 1.0',
        'X-Mailer: RazonYSentido-Landing',
    ];
    if ($adjuntos) {
        $mix = 'rys-mix-' . bin2hex(random_bytes(8));
        $cabeceras[] = "Content-Type: multipart/mixed; boundary=\"$mix\"";
        $cuerpo = "--$mix\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n$alternativa";
        foreach ($adjuntos as $a) {
            $datos = @file_get_contents($a['ruta']);
            if ($datos === false) {
                registrar('correo', 'no se pudo leer el adjunto ' . basename($a['ruta']));
                continue;
            }
            $nombreAscii = preg_replace('/[^A-Za-z0-9._-]/', '_', $a['nombre']) ?: 'adjunto';
            $cuerpo .= "--$mix\r\nContent-Type: {$a['tipo']}; name=\"$nombreAscii\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"$nombreAscii\"\r\n\r\n" . chunk_split(base64_encode($datos));
        }
        $cuerpo .= "--$mix--\r\n";
    } else {
        $cabeceras[] = "Content-Type: multipart/alternative; boundary=\"$alt\"";
        $cuerpo = $alternativa;
    }

    $transporte = cfg('correo.transporte', 'mail');
    if ($transporte === 'archivo') {
        return correo_guardar_archivo($para, $asunto, $cabeceras, $cuerpo, $html, $adjuntos);
    }
    if ($transporte === 'smtp') {
        return smtp_enviar($remitente, $para, $asunto, $cabeceras, $cuerpo);
    }
    $ok = @mail($para, mb_encode_mimeheader($asunto, 'UTF-8'), $cuerpo, implode("\r\n", $cabeceras));
    if (!$ok) {
        registrar('correo', "mail() devolvió false para $para");
    }
    return $ok;
}

/** Transporte de pruebas: guarda el .eml completo (se abre con Mail/Outlook) y una vista .html. */
function correo_guardar_archivo(string $para, string $asunto, array $cabeceras, string $cuerpo, string $html, array $adjuntos): bool
{
    $dir = RYS_PRIVATE_DIR . '/correos';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    $base = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    $eml = 'To: ' . $para . "\r\nSubject: " . mb_encode_mimeheader($asunto, 'UTF-8') . "\r\nDate: " . date('r') . "\r\n"
        . implode("\r\n", $cabeceras) . "\r\n\r\n" . $cuerpo;
    $lista = implode(', ', array_map(fn($a) => $a['nombre'] . ' (' . round(((int) @filesize($a['ruta'])) / 1024) . ' KB)', $adjuntos));
    $meta = '<div style="font:13px/1.5 monospace;background:#0E0B16;color:#F5C983;padding:12px 16px;">'
        . 'Para: ' . htmlspecialchars($para) . '<br>Asunto: ' . htmlspecialchars($asunto)
        . '<br>Adjuntos: ' . htmlspecialchars($lista ?: 'ninguno') . '</div>';
    $ok = @file_put_contents("$dir/$base.eml", $eml) !== false
        && @file_put_contents("$dir/$base.html", $meta . $html) !== false;
    registrar('correo', "guardado en archivo $base para $para", ['asunto' => $asunto, 'adjuntos' => count($adjuntos)]);
    return $ok;
}

/** Cliente SMTP mínimo (SSL implícito en 465 o STARTTLS en 587) con AUTH LOGIN. */
function smtp_enviar(string $de, string $para, string $asunto, array $cabeceras, string $cuerpo): bool
{
    $host = (string) cfg('correo.smtp.host', 'smtp.hostinger.com');
    $puerto = (int) cfg('correo.smtp.puerto', 465);
    $usuario = (string) cfg('correo.smtp.usuario', '');
    $clave = (string) cfg('correo.smtp.clave', '');
    $ssl = $puerto === 465;

    $sock = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . "$host:$puerto", $errno, $errstr, 15);
    if (!$sock) {
        registrar('correo', "SMTP no conecta: $errstr ($errno)");
        return false;
    }
    stream_set_timeout($sock, 15);
    $leer = function () use ($sock): string {
        $resp = '';
        while (($linea = fgets($sock, 515)) !== false) {
            $resp .= $linea;
            if (strlen($linea) < 4 || $linea[3] !== '-') {
                break;
            }
        }
        return $resp;
    };
    $enviar = function (string $cmd, string $esperado) use ($sock, $leer): void {
        fwrite($sock, $cmd . "\r\n");
        $resp = $leer();
        if (!str_starts_with($resp, $esperado)) {
            throw new RuntimeException('SMTP ' . substr($cmd, 0, 8) . ' → ' . trim($resp));
        }
    };
    try {
        if (!str_starts_with($leer(), '220')) {
            throw new RuntimeException('SMTP sin saludo 220');
        }
        $enviar('EHLO tiempo.razonysentido.com', '250');
        if (!$ssl) {
            $enviar('STARTTLS', '220');
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS falló');
            }
            $enviar('EHLO tiempo.razonysentido.com', '250');
        }
        $enviar('AUTH LOGIN', '334');
        $enviar(base64_encode($usuario), '334');
        $enviar(base64_encode($clave), '235');
        $enviar("MAIL FROM:<$de>", '250');
        $enviar("RCPT TO:<$para>", '250');
        $enviar('DATA', '354');
        $mensaje = 'To: ' . $para . "\r\nSubject: " . mb_encode_mimeheader($asunto, 'UTF-8') . "\r\nDate: " . date('r') . "\r\n"
            . implode("\r\n", $cabeceras) . "\r\n\r\n" . preg_replace('/^\./m', '..', $cuerpo);
        $enviar($mensaje . "\r\n.", '250');
        fwrite($sock, "QUIT\r\n");
        fclose($sock);
        return true;
    } catch (Throwable $e) {
        registrar('correo', 'SMTP: ' . $e->getMessage());
        fclose($sock);
        return false;
    }
}

function correo_plantilla(string $titulo, string $contenidoHtml): string
{
    $sitio = url_sitio();
    return <<<HTML
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>$titulo</title></head>
<body style="margin:0;padding:0;background:#F7F3EA;font-family:'DM Sans',Helvetica,Arial,sans-serif;color:#0E0B16;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F7F3EA;padding:28px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 12px 36px rgba(232,163,61,0.16);">
  <tr><td style="background:#0E0B16;padding:22px 28px;text-align:center;">
    <span style="display:inline-block;width:22px;height:22px;border-radius:50%;vertical-align:middle;background:radial-gradient(circle at 50% 45%,#F5C983 0%,#E8A33D 35%,#2E7C99 75%,#0E0B16 100%);"></span>
    <span style="color:#fff;font-weight:800;font-size:18px;vertical-align:middle;margin-left:8px;">Razón y <span style="color:#E8A33D;">Sentido</span></span>
  </td></tr>
  <tr><td style="padding:30px 28px;">$contenidoHtml</td></tr>
  <tr><td style="padding:18px 28px;background:#FBF8F1;color:rgba(14,11,22,0.45);font-size:11px;text-align:center;">
    Luis Amin Velasco Cobos · <a href="$sitio" style="color:#2E7C99;text-decoration:none;">tiempo.razonysentido.com</a><br>
    Este correo se envió porque compraste el libro digital. El archivo es para uso personal.
  </td></tr>
</table>
</td></tr></table>
</body></html>
HTML;
}

/** Adjunto del EPUB si existe y no supera correo.adjunto_max_mb (por defecto 10 MB). */
function correo_adjunto_libro(): array
{
    $cat = catalogo();
    $ruta = RYS_PRIVATE_DIR . '/' . basename((string) ($cat['libro']['archivo'] ?? 'libro.epub'));
    $maxBytes = (int) round(((float) cfg('correo.adjunto_max_mb', 10)) * 1024 * 1024);
    if (!is_file($ruta) || $maxBytes <= 0 || filesize($ruta) > $maxBytes) {
        return [];
    }
    return [[
        'nombre' => (string) ($cat['libro']['nombre_descarga'] ?? 'libro.epub'),
        'ruta' => $ruta,
        'tipo' => 'application/epub+zip',
    ]];
}

/** Correo al comprador: EPUB adjunto + enlace de descarga de respaldo + instrucciones de lectura. */
function correo_entrega(array $p): bool
{
    $cat = catalogo();
    $libro = $cat['libro'];
    $url = url_descarga($p);
    $dias = (int) ($cat['descarga']['dias_validez'] ?? 30);
    $max = (int) ($cat['descarga']['descargas_max'] ?? 5);
    $pedidoCorto = strtoupper(substr($p['id'], 0, 8));
    $wa = 'https://wa.me/573235109187';
    $tituloEsc = htmlspecialchars($libro['titulo'], ENT_QUOTES, 'UTF-8');
    $adjuntos = correo_adjunto_libro();
    $intro = $adjuntos
        ? "Te adjuntamos <strong>«{$tituloEsc}»</strong> en formato EPUB. También puedes descargarlo con este botón:"
        : "Aquí tienes <strong>«{$tituloEsc}»</strong> en formato EPUB.";

    $html = <<<HTML
<h1 style="margin:0 0 8px;font-size:22px;line-height:1.25;">¡Gracias por tu compra!</h1>
<p style="margin:0 0 22px;color:rgba(14,11,22,0.65);font-size:15px;">$intro</p>
<p style="margin:0 0 24px;text-align:center;">
  <a href="$url" style="display:inline-block;background:linear-gradient(135deg,#E8A33D,#F5C983);color:#fff;font-weight:800;font-size:16px;padding:14px 28px;border-radius:12px;text-decoration:none;">Descargar el libro (EPUB)</a>
</p>
<p style="margin:0 0 20px;font-size:12px;color:rgba(14,11,22,0.5);text-align:center;">Enlace válido $dias días · hasta $max descargas · Pedido $pedidoCorto</p>
<h2 style="margin:24px 0 10px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#C7822A;">Cómo leerlo</h2>
<ol style="margin:0;padding-left:20px;font-size:14px;line-height:1.6;color:rgba(14,11,22,0.75);">
  <li><strong>Celular o tablet:</strong> abre el archivo con Apple Libros (iPhone/iPad) o Google Play Libros (Android).</li>
  <li><strong>Kindle:</strong> entra a <a href="https://www.amazon.com/sendtokindle" style="color:#2E7C99;">amazon.com/sendtokindle</a> y sube el EPUB.</li>
  <li><strong>Computador:</strong> ábrelo con Calibre, Thorium Reader o el navegador Microsoft Edge.</li>
</ol>
<p style="margin:24px 0 0;padding:14px 16px;background:rgba(232,163,61,0.08);border:1px solid rgba(232,163,61,0.25);border-radius:12px;font-size:13px;color:rgba(14,11,22,0.75);">
  ¿Algún problema con el archivo? Escríbenos por <a href="$wa" style="color:#C7822A;font-weight:700;">WhatsApp +57 323 510 9187</a> indicando el pedido $pedidoCorto y lo resolvemos.
</p>
HTML;

    $texto = "¡Gracias por tu compra!\n\n" . ($adjuntos ? "Te adjuntamos" : "Aquí tienes") . " «{$libro['titulo']}» en formato EPUB.\n\nDescarga: $url\n(válido $dias días, hasta $max descargas · pedido $pedidoCorto)\n\n"
        . "Cómo leerlo:\n1) Celular o tablet: Apple Libros o Google Play Libros.\n2) Kindle: amazon.com/sendtokindle y sube el EPUB.\n3) Computador: Calibre, Thorium Reader o Microsoft Edge.\n\n"
        . "¿Problemas? WhatsApp +57 323 510 9187 indicando el pedido $pedidoCorto.\n";

    return correo_enviar((string) $p['email'], 'Tu libro: ' . $libro['titulo'] . ' (EPUB)', correo_plantilla('Tu libro', $html), $texto, null, $adjuntos);
}

/** Aviso al vendedor de cada venta (correo.copia_a). */
function correo_aviso_venta(array $p): bool
{
    $copia = (string) cfg('correo.copia_a', '');
    if ($copia === '') {
        return false;
    }
    $monto = number_format((float) $p['monto'], $p['moneda'] === 'COP' ? 0 : 2, ',', '.');
    $email = htmlspecialchars((string) $p['email'], ENT_QUOTES, 'UTF-8');
    $html = "<h1 style=\"margin:0 0 12px;font-size:20px;\">Nueva venta del EPUB</h1>
<table style=\"font-size:14px;line-height:1.7;\">
<tr><td style=\"color:rgba(14,11,22,0.5);padding-right:16px;\">Importe</td><td><strong>$monto {$p['moneda']}</strong></td></tr>
<tr><td style=\"color:rgba(14,11,22,0.5);padding-right:16px;\">País</td><td>" . strtoupper((string) $p['pais']) . "</td></tr>
<tr><td style=\"color:rgba(14,11,22,0.5);padding-right:16px;\">Pasarela</td><td>{$p['proveedor']} · ref {$p['pago_ref']}</td></tr>
<tr><td style=\"color:rgba(14,11,22,0.5);padding-right:16px;\">Comprador</td><td>$email</td></tr>
<tr><td style=\"color:rgba(14,11,22,0.5);padding-right:16px;\">Pedido</td><td style=\"font-family:monospace;\">{$p['id']}</td></tr>
</table>";
    $texto = "Nueva venta del EPUB\nImporte: $monto {$p['moneda']}\nPaís: {$p['pais']}\nPasarela: {$p['proveedor']} ({$p['pago_ref']})\nComprador: {$p['email']}\nPedido: {$p['id']}\n";
    return correo_enviar($copia, "Venta EPUB · $monto {$p['moneda']} · " . strtoupper((string) $p['pais']), correo_plantilla('Nueva venta', $html), $texto);
}
