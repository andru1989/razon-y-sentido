<?php
/**
 * Cliente HTTP mínimo sobre curl para hablar con las pasarelas.
 * Devuelve siempre ['status' => int, 'json' => array|null, 'raw' => string].
 */
declare(strict_types=1);

function http_json(string $metodo, string $url, ?array $cuerpo = null, array $cabeceras = [], ?string $basicAuth = null, ?string $cuerpoCrudo = null): array
{
    $ch = curl_init($url);
    $cab = array_merge(['Accept: application/json'], $cabeceras);
    $opciones = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'RazonYSentido-Landing/2.0 (+https://tiempo.razonysentido.com)',
    ];
    if ($cuerpoCrudo !== null) {
        $opciones[CURLOPT_POSTFIELDS] = $cuerpoCrudo;
    } elseif ($cuerpo !== null) {
        $opciones[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cab[] = 'Content-Type: application/json';
    }
    if ($basicAuth !== null) {
        $opciones[CURLOPT_USERPWD] = $basicAuth;
    }
    $opciones[CURLOPT_HTTPHEADER] = $cab;
    curl_setopt_array($ch, $opciones);

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $errno) {
        registrar('http', "curl error $errno: $error", ['url' => preg_replace('/\?.*/', '', $url)]);
        return ['status' => 0, 'json' => null, 'raw' => '', 'error' => $error];
    }
    $json = json_decode((string) $raw, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'raw' => (string) $raw];
}

/** Cabeceras de la petición actual, en minúsculas. */
function cabeceras_entrada(): array
{
    $salida = [];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $k => $v) {
            $salida[strtolower((string) $k)] = (string) $v;
        }
    }
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_')) {
            $salida[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
        }
    }
    return $salida;
}
