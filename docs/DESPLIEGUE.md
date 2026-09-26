# Puesta en marcha de la venta directa

## 1. Cómo funciona

```
Landing (#consigue)
  └─ elige país → tarjeta "Comprar libro digital" (precio y pasarela según public/api/catalogo.json)
       └─ POST /api/checkout.php { pais, email }
            ├─ crea el pedido (SQLite en private/pedidos.sqlite)
            ├─ Colombia → preferencia de Mercado Pago · resto → orden de PayPal
            └─ redirige a la página de pago de la pasarela
                 ├─ pasarela → /api/webhook-mercadopago.php | /api/webhook-paypal.php
                 │     verifica firma → RE-CONSULTA el pago en la API → marca pagado → entrega
                 └─ pasarela → /gracias?pedido=…
                       consulta /api/pedido.php (que también concilia con la API si aún no hay webhook)
                       muestra el botón de descarga y dispara Purchase (Meta) + purchase (GA4)

Entrega = enlace firmado /api/descarga.php?t=… (30 días, 5 descargas) + correo al comprador
          + aviso al vendedor + Purchase por Conversions API (si hay token)
```

Los dos caminos (webhook y retorno a /gracias) llevan al mismo sitio: `pedido_marcar_pagado()`, que es idempotente. Da igual cuál llegue primero o si llegan los dos.

## 2. Directorio privado en el servidor

En Hostinger, junto a `public_html/` del dominio:

```
~/domains/tiempo.razonysentido.com/
├── public_html/     ← lo que sube GitHub Actions (dist/)
└── private/         ← se crea UNA vez a mano; nunca lo toca el despliegue
    ├── config.php   ← copiar de docs/config.ejemplo.php y rellenar
    ├── libro.epub   ← el archivo que se vende
    ├── pedidos.sqlite  (se crea solo)
    ├── cache/          (se crea solo)
    └── logs/           (se crea solo)
```

Subir `config.php` y `libro.epub` por SSH (`scp -P 65002 …`) o con el Administrador de archivos de hPanel.
Permisos recomendados: `chmod 700 private && chmod 600 private/config.php private/libro.epub`.

## 3. Credenciales

### Mercado Pago (Colombia)
1. https://www.mercadopago.com.co/developers → **Tus integraciones** → crear aplicación (Checkout Pro).
2. **Credenciales de prueba** → `access_token` de prueba en `config.php` con `'modo' => 'sandbox'`.
3. **Webhooks** → URL `https://tiempo.razonysentido.com/api/webhook-mercadopago.php`, evento **Pagos**.
   Copiar la **clave secreta** en `mercadopago.webhook_secret`.
4. Para cobrar de verdad: **Credenciales de producción** (requiere cuenta verificada) y `'modo' => 'produccion'`.

Pruebas: crear un *usuario de prueba comprador* en el panel y pagar con las tarjetas de prueba de MP.

### PayPal (resto del mundo)
1. https://developer.paypal.com → **Apps & Credentials** → crear app (Sandbox primero).
2. `client_id` y `secret` en `config.php`.
3. **Webhooks** → URL `https://tiempo.razonysentido.com/api/webhook-paypal.php` con los eventos
   `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED`,
   `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.CAPTURE.REVERSED`. Copiar el **Webhook ID** en `paypal.webhook_id`.
4. Para cobrar de verdad: app **Live** (cuenta Business verificada), mismas tres claves, `'modo' => 'produccion'`.

Pruebas: cuentas *sandbox* de comprador en **Sandbox → Accounts**.

### Correo
- `transporte => 'mail'` funciona en Hostinger sin configurar nada, pero el remitente debería ser un correo del dominio.
- Mejor entregabilidad: crear un buzón en hPanel (p. ej. `libro@razonysentido.com`, si el dominio tiene correo en Hostinger) y usar `transporte => 'smtp'` con `smtp.hostinger.com:465`.
- `copia_a`: correo del vendedor para recibir el aviso de cada venta.

### Meta Conversions API (opcional, recomendado)
Administrador de eventos → píxel → **Configuración** → *Conversions API* → **Generar token de acceso** → `meta.capi_token`.
La compra llega al píxel con `event_id = id del pedido` desde el servidor y desde el navegador; Meta la cuenta una sola vez.

### Panel de pedidos
`admin.clave` (≥ 12 caracteres) → `https://tiempo.razonysentido.com/api/pedidos-admin.php?clave=…`
Muestra los pedidos y permite *Comprobar con la pasarela*, *Reenviar correo* y *Renovar enlace*.

## 4. Pruebas locales (sin subir nada)

Requiere PHP 8.4 con `pdo_sqlite` y `curl` (en macOS: `brew install php`, o el binario estático de https://static-php.dev).

```bash
npm run build
cp docs/config.ejemplo.php private/config.php      # url_sitio => http://localhost:8080
cp /ruta/al/libro.epub private/libro.epub
RYS_PRIVATE_DIR="$PWD/private" php -S localhost:8080 -t dist
```

- http://localhost:8080 → la landing con el flujo completo hasta la pasarela (sandbox).
- Los webhooks no llegan a localhost, pero `/gracias` concilia por sí sola consultando a la pasarela.
- `private/logs/*.log` registra cada paso.

## 5. Lanzamiento a producción

El despliegue es automático: **push a `main` → GitHub Actions compila Astro → sube `dist/` a `public_html` por FTPS** (secretos `HOSTINGER_FTP_*` del repositorio). No se usa la integración Git de Hostinger: copiaría el repo sin compilar.

El servidor ya tiene `~/domains/tiempo.razonysentido.com/private/config.php` en modo producción. Antes de fusionar a `main`:

1. **Mercado Pago (cuenta que recibe el dinero):** *Tus integraciones* → la aplicación → **Credenciales de producción** (hay que activarlas indicando sector y la web `https://tiempo.razonysentido.com`). El Access Token va en `mercadopago.access_token`.
2. **Webhook de producción:** *Webhooks* → modo productivo → URL `https://tiempo.razonysentido.com/api/webhook-mercadopago.php`, evento **Pagos** → la clave secreta va en `mercadopago.webhook_secret`.
3. **EPUB definitivo** → `private/libro.epub`.
4. **Correo:** el dominio tiene el correo en HostGator. Crear (o usar) un buzón, p. ej. `libro@razonysentido.com`, y poner usuario y contraseña en `correo.smtp` (host `mail.razonysentido.com`, puerto 465). `correo.copia_a` = correo que recibe el aviso de cada venta.
5. Panel → **Ejecutar chequeo de lanzamiento**: todo en ✅.
6. Fusionar `astro-pagos` en `main` y hacer push. En 1–2 minutos el sitio está publicado (pestaña *Actions* de GitHub).
7. Hacer **una compra real** de Colombia con importe normal y reembolsarla desde Mercado Pago si se desea.

Volver atrás: `git revert` del merge y push, o desplegar la etiqueta `pre-astro`.

## 6. Lista de comprobación antes de cobrar de verdad

- [ ] `private/libro.epub` es el archivo definitivo (abrirlo en un lector para confirmarlo).
- [ ] Compra de prueba en sandbox por **Colombia** (Mercado Pago) y por **otro país** (PayPal): llega el correo, descarga funciona, Purchase aparece en Meta y GA4.
- [ ] Webhooks configurados en ambos paneles y `webhook_secret` / `webhook_id` en `config.php`.
- [ ] `'modo' => 'produccion'` + credenciales de producción.
- [ ] Una compra real de cada pasarela con importe normal; después reembolsar desde el panel de la pasarela si se desea.
- [ ] Copia de seguridad periódica de `private/pedidos.sqlite` (basta copiar el archivo).

## 7. Operación diaria

- **¿Vendí algo?** Correo de aviso (`copia_a`) o panel de pedidos.
- **Comprador dice que no le llegó:** panel → *Reenviar correo*. O enviarle `https://tiempo.razonysentido.com/gracias?pedido=<id>`, que muestra el botón de descarga.
- **Pago PSE aprobado horas después:** el webhook lo marca pagado y envía el correo solo. Si no, panel → *Comprobar*.
- **Cambiar precio / desactivar un país:** `public/api/catalogo.json` → push a `main`.
- **Reembolso:** hacerlo en Mercado Pago / PayPal; el webhook marca el pedido como `reembolsado` y el enlace deja de funcionar.
