<?php
/**
 * Panel mínimo de pedidos: /api/pedidos-admin.php?clave=<admin.clave>
 * Lista los últimos pedidos y permite "Comprobar con la pasarela" y
 * "Reenviar correo". Sin clave configurada, responde 404.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

$claveCfg = (string) cfg('admin.clave', '');
$clave = (string) ($_POST['clave'] ?? $_GET['clave'] ?? '');
if ($claveCfg === '' || strlen($claveCfg) < 12 || !hash_equals($claveCfg, $clave)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No encontrado');
}
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

// Vista de un correo guardado por el transporte 'archivo' (pruebas locales).
$dirCorreos = RYS_PRIVATE_DIR . '/correos';
if (isset($_GET['correo'])) {
    $archivo = basename((string) $_GET['correo']);
    $ruta = "$dirCorreos/$archivo";
    if (!preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}\.(html|eml)$/', $archivo) || !is_file($ruta)) {
        http_response_code(404);
        exit('No encontrado');
    }
    if (str_ends_with($archivo, '.eml')) {
        header('Content-Type: message/rfc822');
        header('Content-Disposition: attachment; filename="' . $archivo . '"');
    } else {
        header('Content-Type: text/html; charset=utf-8');
    }
    readfile($ruta);
    exit;
}

$mensaje = '';
$errorManual = '';
// Venta por pago directo (transferencia, Nequi…): se registra, se entrega y se redirige
// (POST → GET) para que recargar la página no cree la venta dos veces.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['accion'] ?? '') === 'manual') {
    $moneda = (string) ($_POST['moneda'] ?? 'COP');
    $montoTxt = trim((string) ($_POST['monto'] ?? ''));
    // COP sin decimales ("25.000" o "25000"); USD/EUR con decimales ("7.99" o "7,99").
    $monto = $moneda === 'COP'
        ? (float) preg_replace('/\D/', '', $montoTxt)
        : (float) str_replace([',', ' '], ['.', ''], $montoTxt);
    $res = pedido_entrega_manual(
        (string) ($_POST['email'] ?? ''),
        (string) ($_POST['pais'] ?? 'co'),
        $monto,
        $moneda,
        (string) ($_POST['medio'] ?? ''),
        '',
        (string) ($_POST['whatsapp'] ?? '')
    );
    if (is_array($res)) {
        header('Location: ?clave=' . rawurlencode($clave) . '&entregado=' . rawurlencode($res['id']), true, 303);
        exit;
    }
    $errorManual = $res;
}
$entregado = null;
if (isset($_GET['entregado']) && es_uuid((string) $_GET['entregado'])) {
    $x = pedido_obtener((string) $_GET['entregado']);
    $entregado = ($x && $x['proveedor'] === 'manual') ? $x : null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $id = (string) ($_POST['id'] ?? '');
    $accion = (string) ($_POST['accion'] ?? '');
    $p = es_uuid($id) ? pedido_obtener($id) : null;
    if ($p && $accion === 'comprobar') {
        $p = pedido_conciliar($p);
        $mensaje = "Pedido " . strtoupper(substr($id, 0, 8)) . " comprobado: estado «{$p['estado']}»" . ($p['estado_detalle'] ? " ({$p['estado_detalle']})" : '') . '.';
    } elseif ($p && $accion === 'reenviar' && $p['estado'] === 'pagado') {
        require_once __DIR__ . '/lib/correo.php';
        $ok = correo_entrega($p);
        if ($ok) {
            pedido_actualizar($id, ['correo_enviado_en' => ahora()]);
        }
        $mensaje = $ok ? 'Correo reenviado a ' . $p['email'] . '.' : 'No se pudo enviar el correo; revisa private/logs/correo.log.';
    } elseif ($p && $accion === 'simular' && !en_produccion() && $p['estado'] !== 'pagado') {
        // Solo sandbox: marca pagado sin pasar por la pasarela para probar /gracias y el correo.
        pedido_marcar_pagado($id, ['pago_ref' => 'SIMULADO-' . strtoupper(substr(uuid4(), 0, 8)), 'estado_detalle' => 'simulado_sandbox']);
        $mensaje = 'Pago simulado. Abre la página de gracias del pedido para ver la descarga.';
    } elseif ($p && $accion === 'eliminar') {
        // Un pedido pendiente reciente puede pagarse todavía (PSE, efectivo): borrarlo dejaría
        // al comprador sin libro cuando llegue el pago. Se protege durante 48 horas.
        if ($p['estado'] === 'pendiente' && strtotime((string) $p['creado_en']) > time() - 48 * 3600) {
            $mensaje = 'Este pedido está pendiente y tiene menos de 48 horas: todavía puede pagarse. Podrás eliminarlo pasado ese tiempo.';
        } else {
            db()->prepare('DELETE FROM pedidos WHERE id = ?')->execute([$id]);
            registrar('pedidos', 'eliminado desde el panel ' . $id, ['estado' => $p['estado'], 'proveedor' => $p['proveedor'], 'monto' => $p['monto'], 'moneda' => $p['moneda'], 'email' => $p['email'], 'pago_ref' => $p['pago_ref']]);
            $mensaje = 'Registro ' . strtoupper(substr($id, 0, 8)) . ' eliminado.' . ($p['estado'] === 'pagado' ? ' Su enlace de descarga ya no funciona.' : '');
        }
    } elseif ($p && $accion === 'renovar' && $p['estado'] === 'pagado') {
        $vence = vencimiento_descarga($p);
        pedido_actualizar($id, ['descargas' => 0, 'expira_en' => $vence]);
        $mensaje = 'Enlace renovado: contador de descargas a 0 y válido hasta el ' . fecha_local($vence) . '.';
    }
}

$filas = db()->query('SELECT * FROM pedidos ORDER BY creado_en DESC LIMIT 200')->fetchAll();
// Un recuadro por estado; solo las ventas pagadas se separan por moneda (para sumar importes).
$totales = db()->query("SELECT estado, COUNT(*) n, SUM(monto) suma, CASE WHEN estado='pagado' THEN moneda ELSE '' END moneda FROM pedidos GROUP BY estado, CASE WHEN estado='pagado' THEN moneda ELSE '' END ORDER BY CASE estado WHEN 'pagado' THEN 0 WHEN 'pendiente' THEN 1 WHEN 'fallido' THEN 2 ELSE 3 END")->fetchAll();
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$claveEsc = $e($clave);
$modo = en_produccion() ? 'PRODUCCIÓN' : 'sandbox';
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pedidos · Razón y Sentido</title>
<style>
body{margin:0;font-family:'DM Sans',Helvetica,Arial,sans-serif;background:#F7F3EA;color:#0E0B16;padding:20px}
h1{font-size:20px;margin:0 0 4px}.sub{color:rgba(14,11,22,.55);font-size:13px;margin:0 0 18px}
.msg{background:#fff;border:1px solid rgba(46,124,153,.3);border-radius:10px;padding:10px 14px;font-size:14px;margin-bottom:14px}
.tot{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}.tot div{background:#fff;border-radius:10px;padding:10px 14px;font-size:13px;border:1px solid #EBEAEA}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;font-size:12.5px}
th,td{padding:9px 10px;border-bottom:1px solid #F0EDE6;text-align:left;vertical-align:top}th{background:#0E0B16;color:#fff;font-weight:600;font-size:11px;letter-spacing:.05em;text-transform:uppercase}
.est{display:inline-block;padding:2px 8px;border-radius:999px;font-weight:700;font-size:11px}
.pagado{background:rgba(46,124,153,.12);color:#2E7C99}.pendiente{background:rgba(232,163,61,.16);color:#C7822A}.fallido{background:rgba(217,83,79,.12);color:#B03A2E}.reembolsado{background:#EEE;color:#555}
form{display:inline}form.caja{display:block}button.borrar{background:#B03A2E}button{border:0;background:#0E0B16;color:#fff;border-radius:8px;padding:5px 9px;font-size:11px;cursor:pointer;margin:2px 2px 0 0}button.sec{background:#2E7C99}
.mono{font-family:ui-monospace,Menlo,monospace;font-size:11px}.wrap{overflow-x:auto}
.caja{background:#fff;border:1px solid rgba(232,163,61,.35);border-radius:14px;padding:16px;margin-bottom:16px;max-width:760px}
.caja h2{font-size:16px;margin:0 0 4px}.caja .sub{margin-bottom:12px}
.campos{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}
.campos label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:rgba(14,11,22,.7)}
.campos input,.campos select{font:inherit;font-size:14px;font-weight:400;padding:9px 10px;border:1.5px solid #E5E1D8;border-radius:9px;background:#FFFDF9;color:#0E0B16}
.btn{border:0;border-radius:10px;padding:11px 16px;font-size:14px;font-weight:700;cursor:pointer;color:#fff;background:linear-gradient(135deg,#E8A33D,#F5C983)}
.btn.wa{background:#25D366}.btn.sec{background:#2E7C99}
.listo{background:rgba(46,124,153,.07);border-color:rgba(46,124,153,.4)}
.enlace{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}.enlace input{flex:1;min-width:220px;font:inherit;font-size:13px;padding:9px 10px;border:1.5px solid #E5E1D8;border-radius:9px;background:#fff}
.err{color:#B03A2E;font-size:13px;font-weight:600;margin:0 0 10px}
</style></head><body>
<h1>Pedidos del EPUB <span style="font-size:12px;color:#C7822A;">· modo <?= $modo ?></span></h1>
<p class="sub">Últimos 200 pedidos. «Comprobar» vuelve a preguntar a la pasarela; «Reenviar» manda otra vez el correo de descarga; «Renovar» reinicia el enlace; «Eliminar» borra el registro.</p>
<?php if ($mensaje): ?><div class="msg"><?= $e($mensaje) ?></div><?php endif; ?>

<?php if ($entregado):
    $enlace = url_gracias($entregado);
    $textoWa = "¡Hola! Gracias por tu compra de «" . catalogo()['libro']['titulo'] . "». Ya confirmé tu pago. Aquí puedes descargar tu libro digital (EPUB):\n$enlace\n"
        . ($entregado['email'] !== '' ? "También te lo envié a tu correo " . $entregado['email'] . ".\n" : '')
        . "El enlace es personal y funciona hasta el " . fecha_local($entregado['expira_en']) . ". Cualquier duda, me escribes.";
    // wa.me/<número> abre directamente el chat de ese número (app en el celular, WhatsApp Web en el computador).
    $tel = whatsapp_de_pedido($entregado);
    $urlWa = 'https://wa.me/' . $tel . '?text=' . rawurlencode($textoWa); ?>
<div class="caja listo">
  <h2>✅ Venta registrada: enlace listo</h2>
  <?php if ($tel !== ''): ?>
  <p style="margin:6px 0 10px;"><a class="btn wa" href="<?= $e($urlWa) ?>" target="_blank" rel="noopener" style="display:inline-block;text-decoration:none;font-size:16px;padding:14px 20px;">Enviar enlace por WhatsApp a <?= $e(whatsapp_legible($tel)) ?></a></p>
  <p class="sub" style="margin-bottom:6px;">Se abre el chat de esa persona con el mensaje y el enlace listos: solo pulsa enviar. Si prefieres, copia el enlace:</p>
  <?php else: ?>
  <p class="sub" style="margin-bottom:6px;">Comparte este enlace con el comprador:</p>
  <?php endif; ?>
  <div class="enlace">
    <input id="enlace-entrega" type="text" readonly value="<?= $e($enlace) ?>" onclick="this.select()">
    <button class="btn sec" type="button" onclick="navigator.clipboard.writeText(document.getElementById('enlace-entrega').value).then(()=>{this.textContent='¡Copiado!'})">Copiar enlace</button>
  </div>
  <p class="sub" style="margin:0;">Pedido <span class="mono"><?= $e(strtoupper(substr($entregado['id'], 0, 8))) ?></span> · válido hasta el <strong><?= $e(fecha_local($entregado['expira_en'])) ?></strong> · hasta <?= (int) (catalogo()['descarga']['descargas_max'] ?? 5) ?> descargas.</p>
</div>
<?php endif; ?>

<form method="post" class="caja" autocomplete="off">
  <h2>Entrega por pago directo (QR / transferencia)</h2>
  <p class="sub"><strong>1.</strong> Verifica en tu app de Bancolombia que el dinero llegó (un pantallazo del comprobante no basta: pueden ser editados). <strong>2.</strong> Genera el enlace y envíalo por WhatsApp.</p>
  <?php if ($errorManual): ?><p class="err"><?= $e($errorManual) ?></p><?php endif; ?>
  <input type="hidden" name="clave" value="<?= $claveEsc ?>">
  <input type="hidden" name="accion" value="manual">
  <div class="campos">
    <label>WhatsApp del comprador *<input type="tel" name="whatsapp" required inputmode="tel" autofocus placeholder="300 123 4567" value="<?= $e($_POST['whatsapp'] ?? '') ?>"></label>
    <label>Medio de pago *<select name="medio" required><?php foreach (MEDIOS_MANUALES as $m): ?><option<?= ($_POST['medio'] ?? '') === $m ? ' selected' : '' ?>><?= $e($m) ?></option><?php endforeach; ?></select></label>
    <label>Importe recibido (COP) *<input type="text" name="monto" required inputmode="decimal" value="<?= $e($_POST['monto'] ?? '25.000') ?>"></label>
    <input type="hidden" name="moneda" value="COP">
    <input type="hidden" name="pais" value="co">
  </div>
  <p style="margin:12px 0 0;"><button class="btn" type="submit" onclick="return confirm('¿Verificaste en tu app de Bancolombia que el dinero llegó?')">Generar enlace de descarga</button></p>
</form>
<?php if (isset($_GET['chequeo'])):
    require_once __DIR__ . '/lib/chequeo.php';
    $puntos = chequeo_lanzamiento();
    $errores = count(array_filter($puntos, fn($p) => $p[0] === 'error'));
    $iconos = ['ok' => '✅', 'aviso' => '⚠️', 'error' => '❌']; ?>
<div class="msg" style="border-color:<?= $errores ? 'rgba(217,83,79,.45)' : 'rgba(46,124,153,.45)' ?>;">
<strong><?= $errores ? "Chequeo de lanzamiento: $errores problema(s) por resolver" : 'Chequeo de lanzamiento: listo para vender' ?></strong>
<ul style="list-style:none;margin:10px 0 0;padding:0;">
<?php foreach ($puntos as [$tipo, $titulo, $detalle]): ?>
<li style="margin:6px 0;"><?= $iconos[$tipo] ?> <strong><?= $e($titulo) ?></strong> <span style="color:rgba(14,11,22,.6);">— <?= $e($detalle) ?></span></li>
<?php endforeach; ?>
</ul></div>
<?php else: ?>
<p style="margin:0 0 14px;"><a href="?clave=<?= $claveEsc ?>&amp;chequeo=1" style="color:#2E7C99;font-weight:700;font-size:13px;">▶ Ejecutar chequeo de lanzamiento</a></p>
<?php endif; ?>
<div class="tot"><?php foreach ($totales as $t): ?><div><strong><?= $e($t['estado']) ?></strong>: <?= (int) $t['n'] ?><?php if ($t['estado'] === 'pagado'): ?> · <?= number_format((float) $t['suma'], $t['moneda'] === 'COP' ? 0 : 2, ',', '.') ?> <?= $e($t['moneda']) ?><?php endif; ?></div><?php endforeach; ?></div>
<div class="wrap"><table>
<tr><th>Fecha (UTC)</th><th>Pedido</th><th>Estado</th><th>País</th><th>Importe</th><th>Comprador</th><th>Pasarela / ref.</th><th>Descargas</th><th>Acciones</th></tr>
<?php foreach ($filas as $f): ?>
<tr>
<td><?= $e(str_replace('T', ' ', substr((string) $f['creado_en'], 0, 16))) ?></td>
<td class="mono" title="<?= $e($f['id']) ?>"><a href="<?= $e(url_sitio() . '/gracias?pedido=' . $f['id']) ?>" target="_blank" rel="noopener" style="color:#2E7C99;"><?= $e(strtoupper(substr($f['id'], 0, 8))) ?></a></td>
<td><span class="est <?= $e($f['estado']) ?>"><?= $e($f['estado']) ?></span><?php if ($f['estado_detalle']): ?><br><span class="mono"><?= $e($f['estado_detalle']) ?></span><?php endif; ?></td>
<td><?= $e(strtoupper($f['pais'])) ?></td>
<td><?= number_format((float) $f['monto'], $f['moneda'] === 'COP' ? 0 : 2, ',', '.') ?> <?= $e($f['moneda']) ?></td>
<td><?php $wa = whatsapp_de_pedido($f); ?><?php if ($f['email'] !== ''): ?><?= $e($f['email']) ?><?php if ($wa !== ''): ?><br><?php endif; ?><?php endif; ?><?php if ($wa !== ''): ?><a href="https://wa.me/<?= $e($wa) ?>" target="_blank" rel="noopener" style="color:#128C7E;font-weight:700;text-decoration:none;white-space:nowrap;">WhatsApp <?= $e(whatsapp_legible($wa)) ?></a><?php elseif ($f['email'] === ''): ?><span class="mono">(sin datos de contacto)</span><?php endif; ?><?php if ($f['email_pagador'] && $f['email_pagador'] !== $f['email']): ?><br><span class="mono">pagó: <?= $e($f['email_pagador']) ?></span><?php endif; ?></td>
<td><?= $e($f['proveedor']) ?><br><span class="mono"><?= $e(trim(preg_replace('/\s*·\s*WA \d+/', '', (string) ($f['pago_ref'] ?: $f['proveedor_ref'])) ?? '')) ?></span></td>
<td><?= (int) $f['descargas'] ?><?php if ($f['expira_en']): ?><br><span class="mono">hasta <?= $e(substr((string) $f['expira_en'], 0, 10)) ?></span><?php endif; ?></td>
<td>
<?php if ($f['estado'] !== 'pagado'): ?><form method="post"><input type="hidden" name="clave" value="<?= $claveEsc ?>"><input type="hidden" name="id" value="<?= $e($f['id']) ?>"><input type="hidden" name="accion" value="comprobar"><button>Comprobar</button></form><?php endif; ?>
<?php if ($f['estado'] !== 'pagado' && !en_produccion()): ?><form method="post"><input type="hidden" name="clave" value="<?= $claveEsc ?>"><input type="hidden" name="id" value="<?= $e($f['id']) ?>"><input type="hidden" name="accion" value="simular"><button style="background:#C7822A;">Simular pago aprobado</button></form><?php endif; ?>
<?php if ($f['estado'] === 'pagado'): ?>
<a class="mono" href="?clave=<?= $claveEsc ?>&amp;entregado=<?= $e($f['id']) ?>" style="color:#2E7C99;font-weight:700;"><?= $f['proveedor'] === 'manual' ? 'Ver enlace' : '' ?></a>
<?php if ($f['email'] !== ''): ?><form method="post"><input type="hidden" name="clave" value="<?= $claveEsc ?>"><input type="hidden" name="id" value="<?= $e($f['id']) ?>"><input type="hidden" name="accion" value="reenviar"><button class="sec">Reenviar correo</button></form><?php endif; ?>
<form method="post"><input type="hidden" name="clave" value="<?= $claveEsc ?>"><input type="hidden" name="id" value="<?= $e($f['id']) ?>"><input type="hidden" name="accion" value="renovar"><button class="sec">Renovar enlace</button></form>
<?php endif; ?>
<form method="post" onsubmit="return confirm(<?= $e(json_encode($f['estado'] === 'pagado'
    ? '¿Eliminar esta VENTA PAGADA? Desaparece del panel y de los totales, y el enlace de descarga del comprador deja de funcionar. No se puede deshacer.'
    : '¿Eliminar este registro? No se puede deshacer.', JSON_UNESCAPED_UNICODE)) ?>)"><input type="hidden" name="clave" value="<?= $claveEsc ?>"><input type="hidden" name="id" value="<?= $e($f['id']) ?>"><input type="hidden" name="accion" value="eliminar"><button class="borrar">Eliminar</button></form>
</td>
</tr>
<?php endforeach; ?>
<?php if (!$filas): ?><tr><td colspan="9" style="text-align:center;color:rgba(14,11,22,.5);padding:24px;">Todavía no hay pedidos.</td></tr><?php endif; ?>
</table></div>
<?php
$correos = is_dir($dirCorreos) ? array_slice(array_reverse(glob("$dirCorreos/*.html") ?: []), 0, 20) : [];
if ($correos): ?>
<h2 style="font-size:16px;margin:26px 0 8px;">Correos guardados <span style="font-size:12px;color:rgba(14,11,22,.5);font-weight:400;">· transporte «archivo», no se enviaron</span></h2>
<div class="wrap"><table>
<tr><th>Guardado (UTC)</th><th>Vista</th><th>Mensaje completo</th></tr>
<?php foreach ($correos as $c): $b = basename($c, '.html'); ?>
<tr>
<td class="mono"><?= $e(substr($b, 0, 4) . '-' . substr($b, 4, 2) . '-' . substr($b, 6, 2) . ' ' . substr($b, 9, 2) . ':' . substr($b, 11, 2)) ?></td>
<td><a href="?clave=<?= $claveEsc ?>&amp;correo=<?= $e($b) ?>.html" target="_blank" rel="noopener" style="color:#2E7C99;font-weight:600;">Ver correo</a></td>
<td><a href="?clave=<?= $claveEsc ?>&amp;correo=<?= $e($b) ?>.eml" style="color:#2E7C99;">Descargar .eml (con el EPUB adjunto)</a></td>
</tr>
<?php endforeach; ?>
</table></div>
<?php endif; ?>
</body></html>
