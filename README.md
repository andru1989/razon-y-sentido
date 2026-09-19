# razon-y-sentido

Landing y tienda del libro **«El tiempo como entidad ontológica»** (Luis Amin Velasco Cobos).
Sitio estático en **Astro 7** + venta directa del EPUB con **Mercado Pago** (Colombia) y **PayPal** (resto del mundo), alojado en Hostinger.

## Estructura

```
src/
  pages/index.astro        Landing
  pages/gracias.astro      Retorno tras el pago: confirma, descarga, dispara Purchase
  components/              Una sección por archivo (Apertura, Libro, Resenas, Faq, Consigue, Footer…)
  layouts/Base.astro       <head>: metadatos, GA4, Meta Pixel, fuentes
  scripts/                 analytics.ts (Meta + GA4), componentes.ts (Alpine), pagina.ts
  styles/global.css        Tokens de diseño + Tailwind 4
  data/libro.ts            Tiendas por país y datos del libro
public/
  api/catalogo.json        ★ Precios y pasarela por país (lo leen Astro y PHP)
  api/*.php                Backend de pagos (checkout, pedido, webhooks, descarga, panel)
  api/lib/                 Librería PHP (Mercado Pago, PayPal, correo, SQLite, CAPI)
  .htaccess                Reglas de LiteSpeed/Apache
docs/
  config.ejemplo.php       Plantilla de private/config.php
  DESPLIEGUE.md            Puesta en marcha paso a paso
  landing-anterior.html    La landing previa a Astro (referencia)
```

En el servidor, junto a `public_html/` existe `private/` (fuera de la web) con `config.php`, `libro.epub`, `pedidos.sqlite` y `logs/`.

## Desarrollo

```bash
nvm use            # Node 22
npm install
npm run dev        # http://localhost:4321 (solo frontend)
npm run build      # genera dist/
```

Para probar los pagos en local hace falta PHP 8.4 (ver `docs/DESPLIEGUE.md`, sección «Pruebas locales»).

## Cambiar precios o activar/desactivar un país

Edita `public/api/catalogo.json` y haz push a `main`. La landing y el cobro usan el mismo archivo.

## Despliegue

Cada push a `main` compila el sitio en GitHub Actions y sube `dist/` a Hostinger por FTPS
(`.github/workflows/deploy-hostinger.yml`). Secretos necesarios en el repositorio:
`HOSTINGER_FTP_HOST`, `HOSTINGER_FTP_USER`, `HOSTINGER_FTP_PASSWORD`, `HOSTINGER_FTP_DIR`.

La versión anterior del sitio está etiquetada como `pre-astro`.
