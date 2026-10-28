// Mejora (28/10/2026): una pantalla que se actualiza sola cada cierto tiempo, sin «Cargando…», mientras se
// está viendo (la pestaña visible). Al volver a la pestaña se actualiza enseguida. `pausada` evita cambiarle
// los datos a alguien en medio de una acción (una ventana abierta, un formulario a medio llenar).
import { onBeforeUnmount, onMounted } from 'vue'

export function useActualizacionAutomatica(actualizar: () => unknown, opciones: { ms?: number; pausada?: () => boolean } = {}) {
  const ms = opciones.ms ?? 15000
  let temporizador: ReturnType<typeof setInterval> | null = null
  let enCurso = false

  async function tic() {
    if (document.hidden || enCurso || opciones.pausada?.()) return
    enCurso = true
    try {
      await actualizar()
    } finally {
      enCurso = false
    }
  }
  function alVolver() {
    if (!document.hidden) void tic()
  }

  onMounted(() => {
    temporizador = setInterval(() => void tic(), ms)
    document.addEventListener('visibilitychange', alVolver)
  })
  onBeforeUnmount(() => {
    if (temporizador) clearInterval(temporizador)
    document.removeEventListener('visibilitychange', alVolver)
  })
}
