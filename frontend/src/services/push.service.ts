// M11 — Avisos push del personal (28/10/2026, RF-M11-013): activar, apagar y probar los avisos en este equipo.
//  · estado()      → POST /push/estado (si el servidor tiene avisos push y si este equipo ya los tiene activos)
//  · suscribir()   → POST /push/suscripciones (la suscripción del navegador, unida a esta sesión)
//  · desuscribir() → DELETE /push/suscripciones
//  · probar()      → POST /push/prueba (un aviso de prueba solo a este equipo)
import api from './api'

export interface EstadoPush {
  disponible: boolean
  llave_publica: string | null
  activa: boolean
}

export const pushService = {
  estado(endpoint: string | null) {
    return api.post<EstadoPush>('/push/estado', { endpoint })
  },
  suscribir(suscripcion: PushSubscriptionJSON) {
    return api.post<{ activa: boolean }>('/push/suscripciones', suscripcion)
  },
  desuscribir(endpoint: string) {
    return api.delete<{ activa: boolean }>('/push/suscripciones', { data: { endpoint } })
  },
  probar(endpoint: string) {
    return api.post<{ enviado: boolean }>('/push/prueba', { endpoint })
  },
}
