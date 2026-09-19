<?php
/**
 * Base de datos: SQLite en el directorio privado. Sin credenciales, sin
 * panel que configurar; una copia del archivo es la copia de seguridad.
 * El esquema se crea solo la primera vez.
 */
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!is_dir(RYS_PRIVATE_DIR) && !@mkdir(RYS_PRIVATE_DIR, 0700, true)) {
        throw new RuntimeException('No existe el directorio privado: ' . RYS_PRIVATE_DIR);
    }
    $pdo = new PDO('sqlite:' . RYS_PRIVATE_DIR . '/pedidos.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('PRAGMA foreign_keys=ON');
    @chmod(RYS_PRIVATE_DIR . '/pedidos.sqlite', 0600);
    db_crear_esquema($pdo);
    return $pdo;
}

function db_crear_esquema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pedidos (
  id               TEXT PRIMARY KEY,           -- uuid v4; también es external_reference / custom_id en la pasarela
  creado_en        TEXT NOT NULL,
  actualizado_en   TEXT NOT NULL,
  pais             TEXT NOT NULL,              -- co | mx | es | ar | otros
  proveedor        TEXT NOT NULL,              -- mercadopago | paypal
  proveedor_ref    TEXT,                       -- id de preferencia (MP) / id de orden (PayPal)
  pago_ref         TEXT,                       -- id de pago (MP) / id de captura (PayPal)
  moneda           TEXT NOT NULL,
  monto            REAL NOT NULL,
  email            TEXT NOT NULL,              -- correo indicado por el comprador (entrega)
  email_pagador    TEXT,                       -- correo que reporta la pasarela
  estado           TEXT NOT NULL DEFAULT 'pendiente', -- pendiente | pagado | fallido | reembolsado
  estado_detalle   TEXT,                       -- estado literal de la pasarela (in_process, rejected, APPROVED…)
  token_descarga   TEXT UNIQUE,
  descargas        INTEGER NOT NULL DEFAULT 0,
  expira_en        TEXT,
  pagado_en        TEXT,
  correo_enviado_en TEXT,
  aviso_enviado_en  TEXT,
  capi_enviado_en   TEXT,
  ip               TEXT,
  user_agent       TEXT,
  origen           TEXT                        -- URL desde la que se inició la compra (conserva UTM)
);
CREATE INDEX IF NOT EXISTS idx_pedidos_estado        ON pedidos(estado);
CREATE INDEX IF NOT EXISTS idx_pedidos_proveedor_ref ON pedidos(proveedor, proveedor_ref);
CREATE INDEX IF NOT EXISTS idx_pedidos_creado        ON pedidos(creado_en);

CREATE TABLE IF NOT EXISTS eventos_webhook (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  recibido_en TEXT NOT NULL,
  proveedor   TEXT NOT NULL,
  evento_id   TEXT NOT NULL,                   -- id del evento en la pasarela (deduplicación)
  tipo        TEXT,
  pedido_id   TEXT,
  cuerpo      TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_evento_unico ON eventos_webhook(proveedor, evento_id);

CREATE TABLE IF NOT EXISTS geo_cache (
  ip_hash       TEXT PRIMARY KEY,               -- sha256 de la IP: nunca se guarda la IP en claro
  iso           TEXT NOT NULL,
  consultado_en TEXT NOT NULL
);
SQL);
}

function ahora(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/**
 * Registra un evento de webhook. Devuelve false si ya se había procesado
 * (mismo proveedor + id), para que el endpoint responda 200 sin repetir trabajo.
 */
function webhook_registrar(string $proveedor, string $eventoId, ?string $tipo, ?string $pedidoId, string $cuerpo): bool
{
    $st = db()->prepare('INSERT OR IGNORE INTO eventos_webhook (recibido_en, proveedor, evento_id, tipo, pedido_id, cuerpo) VALUES (?,?,?,?,?,?)');
    $st->execute([ahora(), $proveedor, $eventoId, $tipo, $pedidoId, mb_substr($cuerpo, 0, 20000)]);
    return $st->rowCount() > 0;
}
