/* ═══════════════════════════════════════════════════════════════════
   MÓDULO DE ANALÍTICA — Meta Pixel + Google Analytics 4
   Bloque ÚNICO y centralizado. Toda la medición del sitio vive aquí.
   ═══════════════════════════════════════════════════════════════════ */

declare global {
  interface Window {
    fbq?: (...args: unknown[]) => void;
    gtag?: (...args: unknown[]) => void;
    trackMeta?: (e: string, p?: Record<string, unknown>) => void;
    trackGA?: (e: string, p?: Record<string, unknown>) => void;
  }
}

type Datos = Record<string, unknown>;

// ── Datos base del libro ──
const LIBRO = {
  isbn: '9791387717483',
  nombre: 'El tiempo como entidad ontológica',
  categoria: 'Filosofía / Metafísica',
  autor: 'Luis Amin Velasco Cobos',
};

/* ── PRECIOS POR PAÍS ──
   Se rellenan desde el catálogo que la sección "Consigue" incrusta en la
   página (mismo JSON que usa el backend para cobrar). Mientras no haya
   precio para un país NO se envía `value` ni `currency`. */
let PRECIOS: Record<string, { moneda: string; valor: number | null }> = {};

export function registrarPrecios(paises: Record<string, { directo?: { moneda: string; precio: number; activo: boolean } }>) {
  PRECIOS = {};
  Object.keys(paises).forEach((codigo) => {
    const d = paises[codigo].directo;
    PRECIOS[codigo] = { moneda: d ? d.moneda : 'USD', valor: d && d.activo ? d.precio : null };
  });
}

// País indicado en la URL (?pais=co|mx|es|ar), antes de que el visitante elija
function paisDeURL(): string {
  const p = (new URLSearchParams(window.location.search).get('pais') || '').toLowerCase();
  return PRECIOS[p] ? p : '';
}

// Devuelve { currency, value } SOLO si hay precio configurado para ese país
export function precioDe(codigoPais: string): Datos {
  const cfg = PRECIOS[codigoPais];
  if (!cfg || cfg.valor === null || cfg.valor === undefined) return {};
  return { currency: cfg.moneda, value: cfg.valor };
}

// ── device_type: móvil vs escritorio ──
function tipoDispositivo() {
  return window.matchMedia('(max-width: 640px)').matches ? 'mobile' : 'desktop';
}

// ── Parámetros UTM (solo los presentes en la URL) ──
function paramsUTM(): Datos {
  const q = new URLSearchParams(window.location.search);
  const utm: Datos = {};
  ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach((k) => {
    const v = q.get(k);
    if (v) utm[k] = v;
  });
  return utm;
}

// ── Parámetros COMUNES enviados en TODOS los eventos ──
function paramsComunes(): Datos {
  return Object.assign(
    {
      page_location: window.location.href,
      page_title: document.title,
      content_name: LIBRO.nombre,
      content_category: LIBRO.categoria,
      device_type: tipoDispositivo(),
    },
    paramsUTM(),
  );
}

// ── Ficha del libro en formato GA4 (array `items`) ──
export function itemLibro(extra?: Datos): Datos {
  return Object.assign(
    {
      item_id: LIBRO.isbn,
      item_name: LIBRO.nombre,
      item_brand: LIBRO.autor,
      item_category: 'Libros',
      item_category2: LIBRO.categoria,
    },
    extra || {},
  );
}

/* ── Identificador único por evento ──
   El servidor reenvía el mismo eventID por Conversions API en la compra,
   y Meta deduplica en vez de contar la conversión dos veces. */
function idEvento(): string {
  if (window.crypto && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  return 'e-' + Date.now() + '-' + Math.random().toString(16).slice(2);
}

/* ── Emisor unificado: envía el mismo evento a Meta y a GA4 ──
   Cada plataforma recibe solo los parámetros que entiende:
   `content_ids` es un array y GA4 descarta arrays en parámetros
   personalizados; `items` es formato GA4 y Meta no lo usa. */
export function emitir(metaEvent: string | null, metaTipo: string | null, gaEvent: string | null, extra?: Datos, eventID?: string) {
  const extras = extra || {};

  if (metaEvent && typeof window.fbq === 'function') {
    const datos: Datos = Object.assign(paramsComunes(), { content_ids: [LIBRO.isbn], content_type: 'product' }, extras);
    delete datos.items;
    delete datos.transaction_id;
    window.fbq(metaTipo || 'trackCustom', metaEvent, datos, { eventID: eventID || idEvento() });
  }

  if (gaEvent && typeof window.gtag === 'function') {
    const datos: Datos = Object.assign(paramsComunes(), extras);
    delete datos.content_ids;
    window.gtag('event', gaEvent, datos);
  }
}

// ── Anti-duplicados: cada clave se dispara una sola vez por carga ──
const emitidos = new Set<string>();
function unaVez(clave: string, cb: () => void) {
  if (emitidos.has(clave)) return;
  emitidos.add(clave);
  cb();
}

/* ───────── COMPRA DIRECTA ─────────
   InitiateCheckout / begin_checkout al pulsar "Comprar EPUB", y
   Purchase / purchase en /gracias cuando el pedido queda pagado. */
export function trackInicioCheckout(datos: { pais: string; proveedor: string; value: number; currency: string }) {
  const item = [itemLibro({ item_variant: 'epub', price: datos.value })];
  emitir('InitiateCheckout', 'track', 'begin_checkout', {
    pais: datos.pais,
    plataforma: datos.proveedor,
    formato: 'epub',
    value: datos.value,
    currency: datos.currency,
    items: item,
  });
}

export function trackCompra(pedido: { id: string; pais: string; proveedor: string; monto: number; moneda: string }) {
  const clave = 'purchase_' + pedido.id;
  try {
    if (localStorage.getItem(clave)) return;
    localStorage.setItem(clave, '1');
  } catch {
    /* almacenamiento bloqueado: se dispara igual; GA4 deduplica por transaction_id */
  }
  const item = [itemLibro({ item_variant: 'epub', price: pedido.monto, quantity: 1 })];
  emitir(
    'Purchase',
    'track',
    'purchase',
    {
      pais: pedido.pais,
      plataforma: pedido.proveedor,
      formato: 'epub',
      value: pedido.monto,
      currency: pedido.moneda,
      transaction_id: pedido.id,
      items: item,
    },
    pedido.id, // eventID = id del pedido → deduplica con Conversions API
  );
}

/* ───────── ARRANQUE: listeners de la landing ───────── */
export function iniciarAnalitica() {
  // Retro-compatibilidad
  window.trackMeta = (e, p) => { if (typeof window.fbq === 'function') window.fbq('track', e, Object.assign(paramsComunes(), p || {})); };
  window.trackGA = (e, p) => { if (typeof window.gtag === 'function') window.gtag('event', e, Object.assign(paramsComunes(), p || {})); };

  /* ───────── MANEJADOR DELEGADO DE CLICS ─────────
     Un solo listener cubre: tarjetas de plataforma (creadas
     dinámicamente por país), botones de país y enlaces externos. */
  document.addEventListener('click', (e) => {
    const target = e.target as HTMLElement | null;
    if (!target) return;

    // 1) Clic en una tarjeta de plataforma de compra (librería externa)
    const card = target.closest<HTMLAnchorElement>('.platform-card');
    if (card) {
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      e.preventDefault();
      const plataforma = card.dataset.platform || 'desconocida';
      const formato = card.dataset.format || 'desconocido';
      const pais = card.dataset.pais || 'na';
      const evento = card.dataset.store || 'ClickPlataformaOtra';
      const datos = { plataforma, formato, pais };
      const ventana = window.open('', '_blank');

      /* Evento específico por tienda (ClickBuscalibre, ClickAmazon, …).
         Se mantiene en Meta, donde puede estar en uso en campañas. */
      emitir(evento, 'trackCustom', evento, datos);
      setTimeout(() => {
        if (ventana) {
          ventana.opener = null;
          ventana.location.href = card.href;
        } else {
          window.location.href = card.href;
        }
      }, 350);
      return;
    }

    // 2) Clic en un botón de país (selector "Elige tu país")
    const paisBtn = target.closest<HTMLElement>('.pais-btn');
    if (paisBtn) {
      emitir('SeleccionPais', 'trackCustom', 'seleccion_pais', { pais: paisBtn.dataset.pais || 'desconocido', origen: 'click' });
      return;
    }
  });

  /* ───────── VISIBILIDAD DE SECCIONES CLAVE ─────────
     Miden si el visitante llegó a VER cada bloque, no solo si scrolleó. */
  if ('IntersectionObserver' in window) {
    const secciones = [
      { id: 'libro', meta: 'LibroVisible', ga: 'libro_visible' },
      { id: 'resenas', meta: 'Reseñas', ga: 'resenas' },
      { id: 'faq', meta: 'Preguntas frecuentes', ga: 'preguntas_frecuentes' },
      { id: 'selector-pais', meta: 'SelectorPaisVisible', ga: 'selector_pais_visible' },
    ];
    secciones.forEach((s) => {
      const el = document.getElementById(s.id);
      if (!el) return; // si la sección no existe, se omite sin romper el módulo
      const obs = new IntersectionObserver((entradas) => {
        entradas.forEach((en) => {
          if (!en.isIntersecting) return;
          obs.disconnect();
          unaVez('visible_' + s.id, () => emitir(s.meta, 'trackCustom', s.ga, { seccion: s.id }));
        });
      }, { threshold: 0.35 });
      obs.observe(el);
    });
  }

  /* ───────── PROFUNDIDAD DE SCROLL (25/50/75/90 %) ───────── */
  const hitos = [25, 50, 75, 90];
  let ticking = false;
  function revisar() {
    ticking = false;
    const doc = document.documentElement;
    const total = doc.scrollHeight - doc.clientHeight;
    if (total <= 0) return;
    const pct = Math.round((window.scrollY / total) * 100);
    hitos.forEach((h) => {
      if (pct >= h) unaVez('scroll_' + h, () => emitir('Scroll', 'trackCustom', 'scroll', { percent_scrolled: h }));
    });
  }
  window.addEventListener('scroll', () => {
    if (!ticking) { ticking = true; requestAnimationFrame(revisar); }
  }, { passive: true });

  // Expone el país de la URL para quien lo necesite (p. ej. ViewContent futuro)
  void paisDeURL;
}
