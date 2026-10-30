// Mejora del Inicio (30/10/2026): una fila que se desliza con el dedo avanza sola, de a una tarjeta. Solo en el
// teléfono (desde tableta la fila no se desliza). Para que no estorbe: se detiene mientras la persona la toca y
// un rato después, y no se mueve si está fuera de la pantalla o la pestaña está oculta. Quien pide menos movimiento
// en su dispositivo también la ve avanzar, pero de una tarjeta a otra, sin el deslizamiento suave. Al llegar al
// final vuelve al inicio.
import { onBeforeUnmount, onMounted, type Ref } from 'vue'

export function useCarruselAutomatico(fila: Ref<HTMLElement | null>, opciones: { ms?: number; pausaAlTocar?: number } = {}) {
  const ms = opciones.ms ?? 3000
  const pausaAlTocar = opciones.pausaAlTocar ?? 6000
  let temporizador: ReturnType<typeof setInterval> | null = null
  let quietoHasta = 0

  function pausar() {
    quietoHasta = Date.now() + pausaAlTocar
  }

  function seVe(el: HTMLElement) {
    const caja = el.getBoundingClientRect()
    return caja.bottom > 0 && caja.top < window.innerHeight
  }

  function avanzar() {
    const el = fila.value
    if (!el || document.hidden || Date.now() < quietoHasta || !seVe(el)) return
    if (el.scrollWidth <= el.clientWidth + 1) return // no hay nada que deslizar (tableta y computador)

    const [primera, segunda] = el.children
    if (!(primera instanceof HTMLElement) || !(segunda instanceof HTMLElement)) return
    const paso = segunda.offsetLeft - primera.offsetLeft
    const alFinal = el.scrollLeft + el.clientWidth >= el.scrollWidth - 2
    const reducido = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    el.scrollTo({ left: alFinal ? 0 : el.scrollLeft + paso, behavior: reducido ? 'auto' : 'smooth' })
  }

  // La fila aparece cuando carga el catálogo, por eso se escucha en el documento y no en ella.
  function alTocar(evento: Event) {
    if (evento.target instanceof Node && fila.value?.contains(evento.target)) pausar()
  }

  onMounted(() => {
    temporizador = setInterval(avanzar, ms)
    document.addEventListener('pointerdown', alTocar, { passive: true })
    document.addEventListener('touchmove', alTocar, { passive: true })
    document.addEventListener('wheel', alTocar, { passive: true })
  })

  onBeforeUnmount(() => {
    if (temporizador) clearInterval(temporizador)
    document.removeEventListener('pointerdown', alTocar)
    document.removeEventListener('touchmove', alTocar)
    document.removeEventListener('wheel', alTocar)
  })
}
