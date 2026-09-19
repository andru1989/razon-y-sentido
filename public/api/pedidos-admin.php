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

$mensaje = '';
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
    } elseif ($p && $accion === 'renovar' && $p['estado'] === 'pagado') {
        $dias = (int) (catalogo()['descarga']['dias_validez'] ?? 30);
        pedido_actualizar($id, ['descargas' => 0, 'expira_en' => gmdate('Y-m-d\TH:i:s\Z', time() + $dias * 86400)]);
        $mensaje = 'Enlace renovado: contador a 0 y ' . $dias . ' días más.';
    }
}

$filas = db()->query('SELECT * FROM pedidos ORDER BY creado_en DESC LIMIT 200')->fetchAll();
$totales = db()->query("SELECT estado, COUNT(*) n, SUM(CASE WHEN estado='pagado' THEN monto ELSE 0 END) suma, moneda FROM pedidos GROUP BY estado, moneda")->fetchAll();
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
form{display:inline}button{border:0;background:#0E0B16;color:#fff;border-radius:8px;padding:5px 9px;font-size:11px;cursor:pointer;margin:2px 2px 0 0}button.sec{background:#2E7C99}
.mono{font-family:ui-monospace,Menlo,monospace;font-size:11px}.wrap{overflow-x:auto}
</style></head><body>
<h1>Pedidos del EPUB <span style="font-size:12px;color:#C7822A;">· modo <?= $modo ?></span></h1>
<p class="sub">Últimos 200 pedidos. «Comprobar» vuelve a preguntar a la pasarela; «Reenviar» manda otra vez el correo de descarga; «Renovar» reinicia el enlace.</p>
<?php if ($mensaje): ?><div class="msg"><?= $e($mensaje) ?></div><?php endif; ?>
<div class="tot"><?php foreach ($totales as $t): ?><div><strong><?= $e($t['estado']) ?></strong>: <?= (int) $t['n'] ?><?php if ($t['estado'] === 'pagado'): ?> · <?= number_format((float) $t['suma'], $t['moneda'] === 'COP' ? 0 : 2, ',', '.') ?> <?= $e($t['moneda']) ?><?php endif; ?></div><?php endforeach; ?></div>
<div class="wrap"><table>
<tr><th>Fecha (UTC)</th><th>Pedido</th><th>Estado</th><th>País</th><th>Importe</th><th>Comprador</th><th>Pasarela / ref.</th><th>Descargas</th><th>Acciones</th></tr>
<?php foreach ($filas as $f): ?>
<tr>
<td><?= $e(str_replace('T', ' ', substr((string) $f['creado_en'], 0, 16))) ?></td>
<td class="mono" title="<?= $e($f['id']) ?>"><?= $e(strtoupper(substr($f['id'], 0, 8))) ?></td>
<td><span class="est <?= $e($f['estado']) ?>"><?= $e($f['estado']) ?></span><?php if ($f['estado_detalle']): ?><br><span class="mono"><?= $e($f['estado_detalle']) ?></span><?php endif; ?></td>
<td><?= $e(strtoupper($f['pais'])) ?></td>
<td><?= number_format((float) $f['monto'], $f['moneda'] === 'COP' ? 0 : 2, ',', '.') ?> <?= $e($f['moneda']) ?></td>
<td><?= $e($f['email']) ?><?php if ($f['email_pagador'] && $f['email_pagador'] !== $f['email']): ?><br><span class="mono">pagó: <?= $e($f['email_pagador']) ?></span><?php endif; ?></td>
<td><?= $e($f['proveedor']) ?><br><span class="mono"><?= $e($f['pago_ref'] ?: $f['proveedor_ref']) ?></span></td>
<td><?= (int) $f['descargas'] ?><?php if ($f['expira_en']): ?><br><span class="mono">hasta <?= $e(substr((string) $f['expira_en'], 0, 10)) ?></span><?php endif; ?></td>
<td>
<?php if ($f['estado'] !== 'pagado'): ?><form method="post"><input type="hidden" name="clave" value="<?= $claveEsc ?>"><input type="hidden" name="id" value="<?= $e($f['id']) ?>"><input type="hidden" name="accion" value="comprobar"><button>Comprobar</button></form><?php endif; ?>
<?php if ($f['estado'] === 'pagado'): ?>
<form method="post"><input type="hidden" name="clave" value="<?= $claveEsc ?>"><input type="hidden" name="id" value="<?= $e($f['id']) ?>"><input type="hidden" name="accion" value="reenviar"><button class="sec">Reenviar correo</button></form>
<form method="post"><input type="hidden" name="clave" value="<?= $claveEsc ?>"><input type="hidden" name="id" value="<?= $e($f['id']) ?>"><input type="hidden" name="accion" value="renovar"><button class="sec">Renovar enlace</button></form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php if (!$filas): ?><tr><td colspan="9" style="text-align:center;color:rgba(14,11,22,.5);padding:24px;">Todavía no hay pedidos.</td></tr><?php endif; ?>
</table></div>
</body></html>
