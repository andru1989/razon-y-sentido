/* ═══════════════════════════════════════════════════════════════════
   COMPONENTES ALPINE: selección de país + compra directa, reseñas,
   y la página de gracias (confirmación de pago y descarga).
   ═══════════════════════════════════════════════════════════════════ */
import type { Alpine as AlpineType } from 'alpinejs';
import { emitir, registrarPrecios, trackInicioCheckout, trackCompra } from './analytics';

interface CompraDirecta { activo: boolean; proveedor: 'mercadopago' | 'paypal'; moneda: string; precio: number; precio_texto: string; medios: string }
interface Tienda { nombre: string; evento: string; formato: string; logo: string; desc: string; url: string }
interface Pais { nombre: string; bandera: string; directo: CompraDirecta; tiendas: Tienda[] }
type Catalogo = Record<string, Pais>;

const NOMBRE_PROVEEDOR: Record<string, string> = { mercadopago: 'Mercado Pago', paypal: 'PayPal' };
const REGEX_CORREO = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

function leerJson<T>(id: string, porDefecto: T): T {
  try {
    const el = document.getElementById(id);
    return el ? (JSON.parse(el.textContent || '') as T) : porDefecto;
  } catch {
    return porDefecto;
  }
}

// País por IP, resuelto por el servidor (/api/pais.php). Devuelve '' si no se puede saber.
async function paisPorIp(): Promise<string> {
  try {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 3000);
    const res = await fetch('/api/pais.php', { headers: { Accept: 'application/json' }, signal: ctrl.signal });
    clearTimeout(t);
    if (!res.ok) return '';
    const data = (await res.json()) as { pais?: string | null };
    return data.pais || '';
  } catch {
    return '';
  }
}

// Elección explícita del visitante, recordada entre visitas.
const CLAVE_PAIS = 'rys_pais';
function leerPaisGuardado(): string {
  try { return localStorage.getItem(CLAVE_PAIS) || ''; } catch { return ''; }
}
function guardarPais(codigo: string) {
  try { codigo ? localStorage.setItem(CLAVE_PAIS, codigo) : localStorage.removeItem(CLAVE_PAIS); } catch { /* sin almacenamiento */ }
}

// País por zona horaria del dispositivo: fiable incluso sin red y en local.
const ZONAS: Record<string, string> = {
  'America/Bogota': 'co',
  'Europe/Madrid': 'es', 'Africa/Ceuta': 'es', 'Atlantic/Canary': 'es',
  'America/Mexico_City': 'mx', 'America/Cancun': 'mx', 'America/Merida': 'mx', 'America/Monterrey': 'mx',
  'America/Matamoros': 'mx', 'America/Chihuahua': 'mx', 'America/Ciudad_Juarez': 'mx', 'America/Ojinaga': 'mx',
  'America/Hermosillo': 'mx', 'America/Mazatlan': 'mx', 'America/Bahia_Banderas': 'mx', 'America/Tijuana': 'mx',
  'America/Buenos_Aires': 'ar', 'America/Cordoba': 'ar', 'America/Mendoza': 'ar',
};
function paisPorZonaHoraria(catalogo: Catalogo): string {
  try {
    const zona = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    const codigo = zona.startsWith('America/Argentina/') ? 'ar' : ZONAS[zona] || '';
    return catalogo[codigo] ? codigo : '';
  } catch {
    return '';
  }
}

// País sugerido por el idioma del navegador (es-CO → co).
function paisPorIdioma(catalogo: Catalogo): string {
  const idiomas = (navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language]) || [];
  for (const idioma of idiomas) {
    const region = (idioma || '').split('-')[1];
    if (!region) continue;
    const codigo = region.toLowerCase();
    if (catalogo[codigo]) return codigo;
  }
  return '';
}

export function registrarComponentes(Alpine: AlpineType) {
  // ── Componente: selección de país + compra directa + librerías ──
  Alpine.data('tiendas', () => ({
    pais: '',
    paises: {} as Catalogo,
    email: '',
    sitio: '', // honeypot
    error: '',
    aviso: '',
    enviando: false,
    detectando: true,

    init() {
      this.paises = leerJson<Catalogo>('catalogo-paises', {});
      registrarPrecios(this.paises);

      const q = new URLSearchParams(window.location.search);
      if (q.get('pago') === 'cancelado') {
        this.aviso = 'Tu pago no se completó y no se hizo ningún cargo. Puedes intentarlo de nuevo cuando quieras.';
      }

      // Prioridad: 1) ?pais= de la URL (campañas) · 2) elección previa guardada ·
      // 3) IP (/api/pais.php) · 4) zona horaria · 5) idioma · 6) país por defecto.
      // El selector solo aparece si el visitante pulsa "Cambiar país".
      const deUrl = (q.get('pais') || '').toLowerCase();
      if (this.paises[deUrl]) {
        this.fijarPais(deUrl, 'url');
        return;
      }
      const guardado = leerPaisGuardado();
      if (guardado && this.paises[guardado]) {
        this.fijarPais(guardado, 'guardado');
        return;
      }
      void this.detectarPais();
    },

    async detectarPais() {
      this.detectando = true;
      const porIp = await paisPorIp();
      if (this.pais) { this.detectando = false; return; } // el visitante ya eligió mientras tanto
      const candidatos: Array<[string, string]> = [
        [porIp, 'ip'],
        [paisPorZonaHoraria(this.paises), 'zona_horaria'],
        [paisPorIdioma(this.paises), 'idioma'],
        [(this.$el as HTMLElement).dataset.paisDefecto || '', 'por_defecto'],
      ];
      const elegido = candidatos.find(([c]) => c && this.paises[c]);
      if (elegido) this.fijarPais(elegido[0], elegido[1]);
      this.detectando = false;
    },

    fijarPais(codigo: string, origen: string) {
      this.pais = codigo;
      this.detectando = false;
      emitir('SeleccionPais', 'trackCustom', 'seleccion_pais', { pais: codigo, origen });
    },

    get paisActual(): Pais {
      return this.pais && this.paises[this.pais] ? this.paises[this.pais] : { nombre: '', bandera: '', directo: { activo: false } as CompraDirecta, tiendas: [] };
    },
    get directo(): CompraDirecta | null {
      return this.paisActual.directo || null;
    },
    get nombreProveedor(): string {
      return this.directo ? NOMBRE_PROVEEDOR[this.directo.proveedor] || this.directo.proveedor : '';
    },
    // "$25.000 COP" → ["$25.000", "COP"] (la moneda en pequeño); "US$7.99" y "6 €" se quedan enteros.
    get precioPartes(): [string, string] {
      const texto = this.directo ? this.directo.precio_texto : '';
      const m = texto.match(/^(.*\S)\s+([A-Z]{3})$/);
      return m ? [m[1], m[2]] : [texto, ''];
    },

    elegir(codigo: string) {
      this.pais = codigo;
      this.error = '';
      guardarPais(codigo);
    },
    cambiarPais() {
      this.pais = '';
      this.detectando = false;
      this.error = '';
      this.aviso = '';
      guardarPais('');
    },

    async comprar() {
      this.error = '';
      const email = this.email.trim().toLowerCase();
      if (!REGEX_CORREO.test(email)) {
        this.error = 'Escribe un correo válido: ahí te enviaremos el libro.';
        return;
      }
      if (this.sitio) return; // bot
      const d = this.directo;
      if (!d || !d.activo) return;

      this.enviando = true;
      trackInicioCheckout({ pais: this.pais, proveedor: d.proveedor, value: d.precio, currency: d.moneda });

      try {
        const res = await fetch('/api/checkout.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ pais: this.pais, email, sitio: this.sitio, origen: window.location.href }),
        });
        const data = (await res.json().catch(() => ({}))) as { url?: string; error?: string };
        if (!res.ok || !data.url) throw new Error(data.error || 'No pudimos iniciar el pago.');
        window.location.assign(data.url);
      } catch (e) {
        const msg = e instanceof Error ? e.message : 'No pudimos iniciar el pago.';
        this.error = msg + ' Inténtalo de nuevo en un momento o escríbenos por WhatsApp.';
        this.enviando = false;
      }
    },
  }));

  // ── Componente: reseñas dinámicas (reviews.json con respaldo local) ──
  const fallbackReviews = {
    updatedAt: '2026-07-09',
    reviews: [
      { platformLabel: 'Buscalibre', author: 'John Esteban Bedoya', rating: 5, text: 'Muy buen libro, me abrió la mente para entender el tiempo, no como una fecha específica, sino como lo que permite que la materia y el espacio puedan conjugarse. Ahora me enfocaré más en el presente dejando de lado la ansiedad por lo que el futuro, algo que no existe, pueda traer.', date: '2026-06-08', sourceUrl: 'https://www.buscalibre.com.co/libro-el-tiempo-como-entidad-ontologica/9791387717483/p/67219232' },
      { platformLabel: 'Buscalibre', author: 'Jose Daniel Obando', rating: 5, text: 'Excelente libro, permite replantearse lo que pensamos sobre el tiempo y si injerencia en la vida.', date: '2026-06-26', sourceUrl: 'https://www.buscalibre.com.co/libro-el-tiempo-como-entidad-ontologica/9791387717483/p/67219232' },
      { platformLabel: 'Buscalibre', author: 'Andres Vizcaino', rating: 5, text: 'Excelente libro, 100% recomendado.', date: '2026-05-15', sourceUrl: 'https://www.buscalibre.com.co/libro-el-tiempo-como-entidad-ontologica/9791387717483/p/67219232' },
      { platformLabel: 'Agapea', author: 'Cliente Agapea', rating: 5, text: 'Excelente libro, lo recomiendo al 100%, explica de una manera muy clara el concepto del tiempo.', date: '2026-05-08', sourceUrl: 'https://www.agapea.com/Luis-Amin-Velasco-Cobos/El-tiempo-como-entidad-ontologica-9791387717483-i.htm#opiniones' },
    ],
  };
  type Review = (typeof fallbackReviews.reviews)[number];

  Alpine.data('reviewsWidget', () => ({
    reviews: fallbackReviews.reviews as Review[],
    updatedAt: fallbackReviews.updatedAt,
    filter: 'todas',
    platforms: [...new Set(fallbackReviews.reviews.map((r) => r.platformLabel))],
    async load() {
      try {
        const res = await fetch('/reviews.json', { cache: 'no-store' });
        if (!res.ok) return;
        const data = (await res.json()) as { reviews?: Review[]; updatedAt?: string };
        if (!data.reviews || !data.reviews.length) return;
        this.reviews = data.reviews;
        this.updatedAt = data.updatedAt || '';
        this.platforms = [...new Set(this.reviews.map((r) => r.platformLabel))];
      } catch {
        // reviews.json no disponible: se usan las reseñas incluidas en el bundle
      }
    },
    get filteredReviews(): Review[] {
      return this.filter === 'todas' ? this.reviews : this.reviews.filter((r) => r.platformLabel === this.filter);
    },
    get average(): number {
      if (!this.reviews.length) return 0;
      return this.reviews.reduce((sum, r) => sum + r.rating, 0) / this.reviews.length;
    },
    starsHtml(count: number): string {
      const full = '<svg width="14" height="14" viewBox="0 0 24 24" fill="#E8A33D" stroke="#E8A33D" stroke-width="1"><polygon points="12 2 15 9 22 9.3 16.7 14 18.3 21 12 17.3 5.7 21 7.3 14 2 9.3 9 9"/></svg>';
      const empty = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#D8D2C4" stroke-width="1.5"><polygon points="12 2 15 9 22 9.3 16.7 14 18.3 21 12 17.3 5.7 21 7.3 14 2 9.3 9 9"/></svg>';
      return Array.from({ length: 5 }, (_, i) => (i < count ? full : empty)).join('');
    },
  }));

  // ── Componente: página de gracias (confirmación + descarga) ──
  interface Pedido {
    id: string; id_corto: string; estado: 'pendiente' | 'pagado' | 'fallido' | 'reembolsado';
    pais: string; proveedor: string; moneda: string; monto: number; email: string;
    descarga_url?: string; dias_validez?: number; descargas_max?: number; correo_enviado?: boolean;
  }

  Alpine.data('gracias', () => ({
    estado: 'cargando' as 'cargando' | 'pagado' | 'pendiente' | 'fallido' | 'error',
    pedido: null as Pedido | null,
    titulo: '',
    intentos: 0,
    temporizador: 0 as number | ReturnType<typeof setTimeout>,

    init() {
      this.titulo = leerJson<{ titulo: string }>('datos-gracias', { titulo: '' }).titulo;
      const q = new URLSearchParams(window.location.search);
      const id = q.get('pedido');
      if (!id) { this.estado = 'error'; return; }
      // Estado que devuelve Mercado Pago en la URL (approved | pending | in_process | rejected)
      const estadoMP = (q.get('status') || q.get('collection_status') || '').toLowerCase();
      if (estadoMP === 'rejected' || q.get('pago') === 'cancelado') this.estado = 'fallido';
      this.consultar(id);
    },

    async consultar(id: string) {
      this.intentos++;
      const q = new URLSearchParams(window.location.search);
      const params = new URLSearchParams({ id });
      // Pistas de la pasarela para que el servidor concilie de inmediato
      ['payment_id', 'collection_id', 'status', 'token', 'PayerID'].forEach((k) => { const v = q.get(k); if (v) params.set(k, v); });

      try {
        const res = await fetch('/api/pedido.php?' + params.toString(), { cache: 'no-store', headers: { Accept: 'application/json' } });
        if (res.status === 404) { this.estado = 'error'; return; }
        const data = (await res.json()) as Pedido & { error?: string };
        if (!res.ok || data.error) throw new Error(data.error || 'error');
        this.pedido = data;

        if (data.estado === 'pagado') {
          this.estado = 'pagado';
          trackCompra({ id: data.id, pais: data.pais, proveedor: data.proveedor, monto: data.monto, moneda: data.moneda });
          return;
        }
        if (data.estado === 'fallido' || data.estado === 'reembolsado') { this.estado = 'fallido'; return; }

        // Pendiente: reintenta cada 3 s durante ~1 minuto, luego cada 15 s
        if (this.estado !== 'fallido') this.estado = this.intentos > 20 ? 'pendiente' : 'cargando';
        const espera = this.intentos <= 20 ? 3000 : 15000;
        this.temporizador = setTimeout(() => this.consultar(id), espera);
      } catch {
        if (this.intentos < 5) {
          this.temporizador = setTimeout(() => this.consultar(id), 4000);
        } else {
          this.estado = 'error';
        }
      }
    },

    reenvio: '' as '' | 'enviando' | 'ok',
    reenvioError: '',

    async reenviar() {
      if (!this.pedido || this.reenvio === 'enviando') return;
      this.reenvio = 'enviando';
      this.reenvioError = '';
      try {
        const res = await fetch('/api/reenviar.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ id: this.pedido.id }),
        });
        const data = (await res.json().catch(() => ({}))) as { ok?: boolean; error?: string };
        if (!res.ok || !data.ok) throw new Error(data.error || 'No pudimos reenviar el correo.');
        this.reenvio = 'ok';
        this.pedido.correo_enviado = true;
      } catch (e) {
        this.reenvio = '';
        this.reenvioError = e instanceof Error ? e.message : 'No pudimos reenviar el correo.';
      }
    },

    reintentar() {
      clearTimeout(this.temporizador as number);
      this.estado = 'cargando';
      this.intentos = 0;
      const id = new URLSearchParams(window.location.search).get('pedido') || '';
      this.consultar(id);
    },
  }));
}
