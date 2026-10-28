// M11 (21/10/2026): estado de la campana del personal. Pregunta el contador cada 20 segundos (y al
// volver a la pestaña); la lista se pide al abrir el panel o si llegó algo nuevo con el panel abierto.
// Lo inicia el menú del panel (AppSidebar) y lo detiene al salir de él o al cerrar sesión.
import { ref } from 'vue'
import { defineStore } from 'pinia'
import { notificacionService, type Notificacion } from '@/services/notificacion.service'

const INTERVALO_MS = 20_000

export const useNotificacionesStore = defineStore('notificaciones', () => {
  const lista = ref<Notificacion[]>([])
  const noLeidas = ref(0)
  const abierto = ref(false)
  const cargando = ref(false)
  const error = ref('')
  let ultimaId: number | null = null
  let temporizador: ReturnType<typeof setInterval> | null = null
  let usos = 0

  async function refrescar() {
    if (document.visibilityState === 'hidden') return
    try {
      const { data } = await notificacionService.contador()
      noLeidas.value = data.no_leidas
      const cambio = data.ultima_id !== ultimaId
      ultimaId = data.ultima_id
      if (cambio && abierto.value) await cargarLista()
    } catch {
      // Sin conexión la campana se queda como estaba; lo intenta otra vez en 20 s.
    }
  }

  async function cargarLista() {
    cargando.value = lista.value.length === 0
    error.value = ''
    try {
      const { data } = await notificacionService.listar()
      lista.value = data.data
      noLeidas.value = data.no_leidas
    } catch {
      error.value = 'No pudimos cargar las notificaciones.'
    } finally {
      cargando.value = false
    }
  }

  function alVolver() {
    if (document.visibilityState === 'visible') refrescar()
  }

  /** Empieza a preguntar (una sola vez aunque haya dos campanas en pantalla). */
  function iniciar() {
    usos++
    if (temporizador) return
    refrescar()
    temporizador = setInterval(refrescar, INTERVALO_MS)
    document.addEventListener('visibilitychange', alVolver)
  }

  function detener() {
    usos = Math.max(0, usos - 1)
    if (usos > 0 || !temporizador) return
    clearInterval(temporizador)
    temporizador = null
    document.removeEventListener('visibilitychange', alVolver)
    lista.value = []
    noLeidas.value = 0
    ultimaId = null
    abierto.value = false
  }

  async function alternar() {
    abierto.value = !abierto.value
    if (abierto.value) await cargarLista()
  }

  async function marcarLeida(n: Notificacion) {
    if (n.leida_at) return
    n.leida_at = new Date().toISOString()
    noLeidas.value = Math.max(0, noLeidas.value - 1)
    try {
      const { data } = await notificacionService.marcarLeida(n.id)
      noLeidas.value = data.no_leidas
    } catch {
      // Si falla, la próxima consulta deja el contador como esté en el servidor.
    }
  }

  async function marcarTodas() {
    const ahora = new Date().toISOString()
    lista.value.forEach((n) => (n.leida_at ??= ahora))
    noLeidas.value = 0
    try {
      await notificacionService.marcarTodas()
    } catch {
      await refrescar()
    }
  }

  return { lista, noLeidas, abierto, cargando, error, iniciar, detener, alternar, cargarLista, marcarLeida, marcarTodas, refrescar }
})
