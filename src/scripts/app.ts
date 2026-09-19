// Punto de entrada del cliente. Un solo bundle para todas las páginas:
// 1) analítica, 2) comportamiento de página, 3) Alpine con los componentes
// registrados ANTES de start(), que es lo que exige Alpine.
import Alpine from 'alpinejs';
import { iniciarAnalitica } from './analytics';
import { iniciarPagina } from './pagina';
import { registrarComponentes } from './componentes';

declare global {
  interface Window { Alpine: typeof Alpine }
}

iniciarAnalitica();
iniciarPagina();
registrarComponentes(Alpine);
window.Alpine = Alpine;
Alpine.start();
