// M06 — Caja del Administrador: abrir/cerrar turno y ver sus cifras es EXCLUSIVO
// del Administrador (RN-M06-01, CajaTurnoPolicy) — el Mesero solo cobra (ver
// pedido.service.ts `cobrar`). Espeja CajaController: GET /caja/turnos,
// GET /caja/turnos/{turno}, GET /caja/turno-actual, POST /caja/abrir,
// POST /caja/turnos/{turno}/cerrar.
import api from './api'
import type { CajaTurno } from '@/types/models'

export interface CajaTurnosPaginados {
  data: CajaTurno[]
  current_page: number
  last_page: number
  total: number
}

// Fase 7, tarea 4 (RF-M06-023, HU-M06-009): estado de la caja de hoy, para el Mesero y el Administrador.
export interface EstadoCaja {
  fecha: string
  estado: 'sin_abrir' | 'abierta' | 'cerrada'
  abierta_at: string | null
  abierta_por: string | null
  cerrada_at: string | null
  cerrada_por: string | null
  efectivo_esperado: number | null
}

export const cajaService = {
  estado() {
    return api.get<EstadoCaja>('/caja/estado')
  },
  index(page = 1) {
    return api.get<CajaTurnosPaginados>('/caja/turnos', { params: { page } })
  },
  show(turnoId: number) {
    return api.get<CajaTurno>(`/caja/turnos/${turnoId}`)
  },
  turnoActual() {
    return api.get<CajaTurno | null>('/caja/turno-actual')
  },
  abrir(payload: { base_inicial: number }) {
    return api.post<CajaTurno>('/caja/abrir', payload)
  },
  cerrar(turnoId: number, payload: { efectivo_contado: number; justificacion_descuadre?: string }) {
    return api.post<CajaTurno>(`/caja/turnos/${turnoId}/cerrar`, payload)
  },
  // 27/10/2026 (RF-M06-039): el conteo de un turno que se cerró solo.
  registrarConteo(turnoId: number, payload: { efectivo_contado: number; justificacion_descuadre?: string }) {
    return api.post<CajaTurno>(`/caja/turnos/${turnoId}/conteo`, payload)
  },
}
