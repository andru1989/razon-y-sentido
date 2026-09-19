/* ═══════════════════════════════════════════════════════════════════
   FUNCIONALIDAD DE LA PÁGINA (no analítica): revelado al hacer scroll,
   barra flotante, año del pie y modales legales.
   ═══════════════════════════════════════════════════════════════════ */

export function iniciarPagina() {
  // ── Revelado progresivo de las preguntas y bloques al hacer scroll ──
  const revealObserver = new IntersectionObserver((entries) => {
    entries.forEach((en) => {
      if (en.isIntersecting) { en.target.classList.add('visible'); revealObserver.unobserve(en.target); }
    });
  }, { threshold: 0.25 });
  document.querySelectorAll('.reveal').forEach((el) => revealObserver.observe(el));

  // ── Barra flotante (sticky CTA): aparece solo tras pasar el reveal del libro ──
  const stickyCTA = document.getElementById('sticky-cta');
  const libro = document.getElementById('libro');
  if (stickyCTA && libro) {
    new IntersectionObserver(([entry]) => {
      // visible cuando la sección del libro ya quedó por encima del viewport
      stickyCTA.classList.toggle('visible', entry.boundingClientRect.top < 0);
    }, { threshold: 0 }).observe(libro);
  }

  // ── Año dinámico en el pie de página ──
  const anio = document.getElementById('footer-year');
  if (anio) anio.textContent = String(new Date().getFullYear());

  // ── Modales legales (Términos / Privacidad) ──
  function abrirLegal(tipo: string) {
    const el = document.getElementById('modal-' + tipo);
    if (!el) return;
    el.hidden = false;
    el.classList.add('flex');
    document.body.style.overflow = 'hidden';
    const body = el.querySelector<HTMLElement>('.legal-body');
    if (body) body.scrollTop = 0;
  }
  function cerrarLegal(tipo: string) {
    const el = document.getElementById('modal-' + tipo);
    if (!el || el.hidden) return;
    el.hidden = true;
    el.classList.remove('flex');
    document.body.style.overflow = '';
  }
  document.addEventListener('click', (e) => {
    const target = e.target as HTMLElement | null;
    if (!target) return;
    const abrir = target.closest<HTMLElement>('[data-legal-open]');
    if (abrir) { abrirLegal(abrir.dataset.legalOpen!); return; }
    const cerrar = target.closest<HTMLElement>('[data-legal-close]');
    if (cerrar) { cerrarLegal(cerrar.dataset.legalClose!); return; }
    // clic en el fondo oscuro
    if (target.classList.contains('legal-overlay')) cerrarLegal(target.id.replace('modal-', ''));
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { cerrarLegal('terminos'); cerrarLegal('privacidad'); }
  });
}
