// M12 — Impresoras (24/10/2026): este navegador como equipo de impresión. Si está registrado, manda
// su señal cada minuto (para que Impresoras sepa si está en línea) y recibe sus ajustes, que pasan a
// mandar sobre las preferencias locales de la franja de comandas («al confirmar» y «abrir solas»).
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { AxiosError } from 'axios'
import { impresionService, type EquipoImpresion } from '@/services/impresion.service'
import { useAuthStore } from '@/stores/auth'
import { claveEquipo, guardarClaveEquipo, olvidarClaveEquipo } from '@/utils/equipoImpresion'
import { imprimirAlConfirmar, imprimirAutomatico } from '@/utils/comandas'

const ROLES_CON_EQUIPO = ['superadmin', 'administrador', 'mesero']

export const useEquipoImpresionStore = defineStore('equipoImpresion', () => {
  const clave = ref<string | null>(claveEquipo())
  const equipo = ref<EquipoImpresion | null>(null)
  const registrado = computed(() => !!clave.value)
  let temporizador: ReturnType<typeof setInterval> | null = null

  function aplicar(datos: EquipoImpresion) {
    equipo.value = datos
    imprimirAlConfirmar.set(datos.imprime_comandas && datos.al_confirmar)
    imprimirAutomatico.set(datos.imprime_comandas && datos.automatico)
  }

  async function senal() {
    const auth = useAuthStore()
    if (!clave.value || !auth.estaAutenticado || !ROLES_CON_EQUIPO.includes(auth.rol ?? '') || document.hidden) return
    try {
      const { data } = await impresionService.senal()
      aplicar(data)
    } catch (e) {
      // El Administrador lo quitó de Impresoras: este navegador deja de ser un equipo.
      if (e instanceof AxiosError && e.response?.status === 404) olvidar()
    }
  }

  function iniciar() {
    if (temporizador) return
    void senal()
    temporizador = setInterval(senal, 60_000)
  }

  function adoptar(nuevaClave: string, datos: EquipoImpresion) {
    guardarClaveEquipo(nuevaClave)
    clave.value = nuevaClave
    aplicar(datos)
  }

  async function vincular(codigo: string) {
    const { data } = await impresionService.vincular(codigo.trim())
    adoptar(data.clave, data.equipo)
  }

  function olvidar() {
    olvidarClaveEquipo()
    clave.value = null
    equipo.value = null
  }

  return { clave, equipo, registrado, senal, iniciar, adoptar, vincular, olvidar, aplicar }
})
