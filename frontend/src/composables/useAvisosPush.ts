// M11 — Avisos push del personal (28/10/2026, RF-M11-013, RN-M11-09): en qué quedan los avisos en ESTE equipo y
// cómo activarlos. Los avisos son los mismos de la campana; llegan aunque la app esté cerrada o el teléfono
// bloqueado. Cada equipo se activa aparte y queda unido a la sesión: al cerrar sesión deja de recibirlos.
//
// En iPhone solo funcionan con la app instalada en la pantalla de inicio (iOS 16.4 o más nuevo).
import { ref } from 'vue'
import { pushService } from '@/services/push.service'
import { mensajeDeError } from '@/services/errors'

export type EstadoAvisos =
  | 'revisando'
  | 'no-soportado' // el navegador no tiene avisos push
  | 'iphone-sin-instalar' // iPhone/iPad: hay que instalar la app primero
  | 'sin-servidor' // el servidor no tiene llaves VAPID todavía
  | 'bloqueado' // la persona negó el permiso en este navegador
  | 'inactivo'
  | 'activo'

const esIphone = () =>
  /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
const instalada = () =>
  window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true
const soportado = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

function llaveDesdeB64(texto: string): Uint8Array<ArrayBuffer> {
  const base = (texto + '='.repeat((4 - (texto.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  const binario = atob(base)
  const bytes = new Uint8Array(new ArrayBuffer(binario.length))
  for (let i = 0; i < binario.length; i++) bytes[i] = binario.charCodeAt(i)
  return bytes
}

// El service worker (public/sw.js) recibe los avisos. En la versión compilada ya está registrado; en desarrollo
// se registra aquí, solo cuando alguien activa los avisos.
async function registro(): Promise<ServiceWorkerRegistration> {
  const actual = await navigator.serviceWorker.getRegistration('/')
  if (!actual) await navigator.serviceWorker.register('/sw.js')
  return navigator.serviceWorker.ready
}

async function suscripcionActual(): Promise<PushSubscription | null> {
  const reg = await navigator.serviceWorker.getRegistration('/')
  return reg ? reg.pushManager.getSubscription() : null
}

export function useAvisosPush() {
  const estado = ref<EstadoAvisos>('revisando')
  const ocupado = ref(false)
  const mensaje = ref('')
  const error = ref('')
  let llavePublica: string | null = null

  async function revisar() {
    error.value = ''
    if (!soportado()) {
      estado.value = esIphone() && !instalada() ? 'iphone-sin-instalar' : 'no-soportado'
      return
    }
    try {
      const actual = await suscripcionActual()
      const { data } = await pushService.estado(actual?.endpoint ?? null)
      llavePublica = data.llave_publica
      if (!data.disponible) estado.value = 'sin-servidor'
      else if (Notification.permission === 'denied') estado.value = 'bloqueado'
      else estado.value = data.activa && Notification.permission === 'granted' ? 'activo' : 'inactivo'
    } catch {
      estado.value = 'sin-servidor'
    }
  }

  async function activar() {
    if (!llavePublica) return
    ocupado.value = true
    error.value = ''
    mensaje.value = ''
    try {
      const permiso = await Notification.requestPermission()
      if (permiso !== 'granted') {
        estado.value = permiso === 'denied' ? 'bloqueado' : 'inactivo'
        return
      }
      const reg = await registro()
      const llave = llaveDesdeB64(llavePublica)
      let suscripcion = await reg.pushManager.getSubscription()
      // Si el equipo estaba suscrito con otras llaves del servidor, se vuelve a suscribir.
      const otraLlave = suscripcion?.options.applicationServerKey
        && new Uint8Array(suscripcion.options.applicationServerKey).join() !== llave.join()
      if (suscripcion && otraLlave) {
        await suscripcion.unsubscribe()
        suscripcion = null
      }
      suscripcion ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: llave })
      await pushService.suscribir(suscripcion.toJSON())
      estado.value = 'activo'
      mensaje.value = 'Listo: en este equipo te llegarán los avisos aunque cierres la app.'
    } catch (e) {
      error.value = mensajeDeError(e, 'No pudimos activar los avisos en este equipo.')
    } finally {
      ocupado.value = false
    }
  }

  async function apagar() {
    ocupado.value = true
    error.value = ''
    mensaje.value = ''
    try {
      const suscripcion = await suscripcionActual()
      if (suscripcion) {
        await pushService.desuscribir(suscripcion.endpoint)
        await suscripcion.unsubscribe()
      }
      estado.value = 'inactivo'
    } catch (e) {
      error.value = mensajeDeError(e, 'No pudimos apagar los avisos.')
    } finally {
      ocupado.value = false
    }
  }

  async function probar() {
    ocupado.value = true
    error.value = ''
    mensaje.value = ''
    try {
      const suscripcion = await suscripcionActual()
      if (!suscripcion) {
        estado.value = 'inactivo'
        return
      }
      await pushService.probar(suscripcion.endpoint)
      mensaje.value = 'Enviamos un aviso de prueba: debe aparecer en unos segundos.'
    } catch (e) {
      error.value = mensajeDeError(e, 'No pudimos enviar el aviso de prueba.')
    } finally {
      ocupado.value = false
    }
  }

  return { estado, ocupado, mensaje, error, revisar, activar, apagar, probar }
}
