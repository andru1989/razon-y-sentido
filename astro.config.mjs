// @ts-check
import { defineConfig } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';

// Sitio 100 % estático: las páginas se prerenderizan y los endpoints de pago
// viven en public/api/*.php, que Astro copia tal cual a dist/ (Hostinger, PHP 8.4).
export default defineConfig({
  site: 'https://tiempo.razonysentido.com',
  output: 'static',
  trailingSlash: 'ignore',
  compressHTML: false,
  vite: {
    plugins: [tailwindcss()],
  },
});
