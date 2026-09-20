// Datos del libro y catálogo de tiendas por país.
// Los precios y la pasarela de cada país viven en public/api/catalogo.json,
// que también lee el backend PHP: así la landing nunca muestra un precio
// distinto del que se cobra.
import catalogo from '../../public/api/catalogo.json';

export type CodigoPais = 'co' | 'mx' | 'es' | 'ar' | 'otros';
export type Proveedor = 'mercadopago' | 'paypal';

export interface CompraDirecta {
  activo: boolean;
  proveedor: Proveedor;
  moneda: string;
  precio: number;
  precio_texto: string;
  medios: string;
}

export interface Tienda {
  nombre: string;
  evento: string;
  formato: 'fisico' | 'digital' | 'ambos';
  logo: string;
  desc: string;
  url: string;
}

export interface Pais {
  nombre: string;
  bandera: string;
  directo: CompraDirecta;
  tiendas: Tienda[];
}

export const LIBRO = catalogo.libro;
export const DESCARGA = catalogo.descarga;
export const PAIS_POR_DEFECTO = catalogo.pais_por_defecto as CodigoPais;

export const SITIO = {
  url: 'https://tiempo.razonysentido.com',
  whatsapp: 'https://wa.me/573235109187',
  whatsappTexto: '+57 323 510 9187',
  metaPixelId: '824486448991877',
  ga4Id: 'G-VQ2XYTVYJZ',
};

// Librerías externas (libro físico y, en algunos países, también digital).
const TIENDAS: Record<CodigoPais, Tienda[]> = {
  co: [
    { nombre: 'Buscalibre',     evento: 'ClickBuscalibre',   formato: 'fisico',  logo: 'buscalibre.png',   desc: 'Envío dentro de Colombia',           url: 'https://www.buscalibre.com.co/libro-el-tiempo-como-entidad-ontologica/9791387717483/p/67219232' },
    { nombre: 'Mercado Libre',  evento: 'ClickMercadoLibre', formato: 'fisico',  logo: 'mercadolibre.png', desc: 'Compra nacional · envío rápido',     url: 'https://www.mercadolibre.com.co/el-tiempo-como-entidad-ontol243gica-libro-original/up/MCOU4008025326' },
    { nombre: 'Casa del Libro', evento: 'ClickCasaDelLibro', formato: 'digital', logo: 'casadellibro.svg', desc: 'Versión eBook · descarga inmediata', url: 'https://www.casadellibro.com.co/ebook-el-tiempo-como-entidad-ontologica-ebook/9791387716752/18223277' },
  ],
  mx: [
    { nombre: 'Gandhi',     evento: 'ClickGandhi',     formato: 'digital', logo: 'gandhi.png',     desc: 'Versión eBook',          url: 'https://www.gandhi.com.mx/el-tiempo-como-entidad-ontologica-9791387716752/p' },
    { nombre: 'Buscalibre', evento: 'ClickBuscalibre', formato: 'fisico',  logo: 'buscalibre.png', desc: 'Envío dentro de México', url: 'https://www.buscalibre.com.mx/libro-el-tiempo-como-entidad-ontologica/9791387717483/p/67219232' },
  ],
  es: [
    { nombre: 'Libros.cc', evento: 'ClickLibrosCC', formato: 'ambos',  logo: 'libroscc.png', desc: 'Físico y digital',                           url: 'https://libros.cc/El-tiempo-como-entidad-ontologica.htm?isbn=9791387717483' },
    { nombre: 'Agapea',    evento: 'ClickAgapea',   formato: 'fisico', logo: 'agapea.svg',   desc: 'Librería española · envío gratis desde 18€', url: 'https://www.agapea.com/Luis-Amin-Velasco-Cobos/El-tiempo-como-entidad-ontologica-9791387717483-i.htm' },
  ],
  ar: [
    { nombre: 'Buscalibre', evento: 'ClickBuscalibre', formato: 'fisico',  logo: 'buscalibre.png', desc: 'Envío dentro de Argentina', url: 'https://www.buscalibre.com.ar/libro-el-tiempo-como-entidad-ontologica/9791387717483/p/67219232' },
    { nombre: 'Kobo',       evento: 'ClickKobo',       formato: 'digital', logo: 'kobo.svg',       desc: 'Versión eBook',             url: 'https://www.kobo.com/ar/es/ebook/el-tiempo-como-entidad-ontologica?sId=8d039879-b1a1-4685-a441-b3a8006ab2b4&ssId=atlyPQy7-ZeII0L4iFuf_&cPos=1' },
  ],
  otros: [
    { nombre: 'Amazon',             evento: 'ClickAmazon',     formato: 'ambos',   logo: 'amazon.svg',     desc: 'Físico y Kindle · envío internacional', url: 'https://www.amazon.com/-/es/Luis-Amin-Velasco-Cobos/dp/B0GZ7R363W' },
    { nombre: 'Google Play Libros', evento: 'ClickGooglePlay', formato: 'digital', logo: 'googleplay.svg', desc: 'Versión digital',                       url: 'https://play.google.com/store/books/details/Luis_Amin_Velasco_Cobos_El_tiempo_como_entidad_ont?id=MhXbEQAAQBAJ&hl=es_CO' },
  ],
};

export const PAISES: Record<CodigoPais, Pais> = Object.fromEntries(
  (Object.keys(catalogo.paises) as CodigoPais[]).map((codigo) => [
    codigo,
    { ...catalogo.paises[codigo], tiendas: TIENDAS[codigo] } as Pais,
  ]),
) as Record<CodigoPais, Pais>;

// Ofertas para el JSON-LD (schema.org/Book): compra directa + librerías.
export function ofertasSchema() {
  const directas = (Object.keys(PAISES) as CodigoPais[])
    .filter((c) => PAISES[c].directo.activo)
    .map((c) => ({
      '@type': 'Offer',
      url: `${SITIO.url}/#consigue`,
      price: PAISES[c].directo.precio,
      priceCurrency: PAISES[c].directo.moneda,
      availability: 'https://schema.org/InStock',
      itemOffered: { '@type': 'Book', bookFormat: 'https://schema.org/EBook', isbn: LIBRO.isbn_ebook },
      ...(c !== 'otros' ? { areaServed: c.toUpperCase() } : {}),
    }));
  const externas = (Object.keys(PAISES) as CodigoPais[]).flatMap((c) =>
    PAISES[c].tiendas.map((t) => ({
      '@type': 'Offer',
      url: t.url,
      availability: 'https://schema.org/InStock',
      ...(c !== 'otros' ? { areaServed: c.toUpperCase() } : {}),
    })),
  );
  return [...directas, ...externas];
}
