<?php
/**
 * GET /api/pais.php → { "pais": "co" | "mx" | "es" | "ar" | "otros" | null, "iso": "CO" }
 * Detecta el país del visitante por su IP para preseleccionarlo en la landing.
 * Orden: cabecera de geolocalización del proxy (Cloudflare, etc.) → caché →
 * consulta a geojs.io. Nunca guarda la IP en claro: la caché usa un hash.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

solo_metodo('GET');

$ip = ip_cliente();
$iso = '';

// 1) Proxies que ya traen el país
$cabeceras = ['GEOIP_COUNTRY_CODE'];
if (tras_cloudflare()) {
    array_unshift($cabeceras, 'HTTP_CF_IPCOUNTRY');
}
foreach ($cabeceras as $clave) {
    $v = strtoupper(trim((string) ($_SERVER[$clave] ?? '')));
    if (preg_match('/^[A-Z]{2}$/', $v)) {
        $iso = $v;
        break;
    }
}

// 2) Caché (24 h) y 3) consulta externa; se omiten para IPs privadas o locales
$publica = $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
if ($iso === '' && $publica) {
    $hash = hash('sha256', 'pais|' . $ip);
    $pdo = db();
    $st = $pdo->prepare('SELECT iso FROM geo_cache WHERE ip_hash = ? AND consultado_en > ?');
    $st->execute([$hash, gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);
    $iso = (string) ($st->fetchColumn() ?: '');

    if ($iso === '') {
        $r = http_json('GET', 'https://get.geojs.io/v1/ip/country/' . rawurlencode($ip) . '.json');
        $v = strtoupper((string) ($r['json']['country'] ?? ''));
        if ($r['status'] === 200 && preg_match('/^[A-Z]{2}$/', $v)) {
            $iso = $v;
            $pdo->prepare('INSERT OR REPLACE INTO geo_cache (ip_hash, iso, consultado_en) VALUES (?,?,?)')->execute([$hash, $iso, ahora()]);
        }
    }
}

$paises = catalogo()['paises'] ?? [];
$codigo = strtolower($iso);
$pais = $iso === '' ? null : (isset($paises[$codigo]) ? $codigo : 'otros');

header('Cache-Control: private, max-age=3600');
json_out(['pais' => $pais, 'iso' => $iso ?: null]);
