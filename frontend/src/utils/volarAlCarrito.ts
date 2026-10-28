// Mejora (28/10/2026): en el computador, al agregar un producto un punto dorado sale del botón «Agregar a mi
// pedido» y vuela hasta «Mi pedido» del encabezado (el elemento con data-destino-carrito). En el teléfono no
// hace falta: ahí el botón flotante salta y muestra su aviso. Sin animaciones si la persona las desactivó.
export function volarAlCarrito(origen: Element | null) {
  if (!origen || !window.matchMedia('(min-width: 1024px)').matches) return
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
  const destino = document.querySelector('[data-destino-carrito]')
  if (!destino || typeof document.body.animate !== 'function') return

  const desde = origen.getBoundingClientRect()
  const hasta = destino.getBoundingClientRect()
  const punto = document.createElement('span')
  punto.setAttribute('aria-hidden', 'true')
  Object.assign(punto.style, {
    position: 'fixed',
    left: `${desde.left + desde.width / 2 - 9}px`,
    top: `${desde.top + desde.height / 2 - 9}px`,
    width: '18px',
    height: '18px',
    borderRadius: '9999px',
    background: '#d6a43a',
    boxShadow: '0 6px 16px rgba(214,164,58,0.55)',
    zIndex: '60',
    pointerEvents: 'none',
  })
  document.body.appendChild(punto)

  const dx = hasta.left + hasta.width / 2 - (desde.left + desde.width / 2)
  const dy = hasta.top + hasta.height / 2 - (desde.top + desde.height / 2)
  // Sube un poco antes de bajar hacia el encabezado: se ve como un arco.
  const animacion = punto.animate(
    [
      { transform: 'translate(0, 0) scale(1)', opacity: 1 },
      { transform: `translate(${dx * 0.45}px, ${dy * 0.45 - 60}px) scale(1.15)`, opacity: 1, offset: 0.45 },
      { transform: `translate(${dx}px, ${dy}px) scale(0.45)`, opacity: 0.6 },
    ],
    { duration: 650, easing: 'cubic-bezier(0.45, 0, 0.25, 1)' },
  )
  animacion.onfinish = () => punto.remove()
  animacion.oncancel = () => punto.remove()
  // Si la pestaña queda en segundo plano la animación se detiene sin terminar: el punto se quita igual.
  setTimeout(() => punto.remove(), 800)
}
