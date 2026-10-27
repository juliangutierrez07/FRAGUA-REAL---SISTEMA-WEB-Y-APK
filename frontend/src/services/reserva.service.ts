// M05 — espeja ReservaController.php. crear()/registrarDepositoEnLinea() son públicas
// (Cliente web); el resto es de gestión (Administrador/Mesero) — ver routes/api.php.
// index()/depositosRetenidos() son exclusivas de la vista de Administrador (RF-M05-035,
// HU-M05-015): el filtro por estado se resuelve en el backend, no en el frontend, para
// no traer más páginas de las necesarias.
import api from './api'
import { formularioPagoEnLinea, type PagoEnLineaPayload } from './pedido.service'
import type { CheckoutPasarela, EstadoReserva, Mesa, Reserva, ReservaPublica } from '@/types/models'

export interface CrearReservaPayload {
  mesa_id: number
  cliente_nombre: string
  cliente_telefono: string
  cliente_correo: string
  fecha_hora: string
  descripcion?: string | null
}

export interface DepositoEnLineaPayload extends PagoEnLineaPayload {
  // Fase 6, tarea 1: el enlace secreto que devolvió crear() (sin él, 404).
  token: string
}

export interface DepositoManualPayload {
  metodo: 'efectivo' | 'tarjeta' | 'transferencia'
  recibido?: number | null
  comprobante?: File | null
}

export interface PaginadoReservas {
  data: Reserva[]
  total: number
  current_page: number
  last_page: number
}

export interface DepositosRetenidos {
  total_retenido: number
  reservas: Reserva[]
}

export const reservaService = {
  // proximas: true → pendientes y confirmadas de hoy en adelante, de la más cercana a la más lejana.
  index(params?: { estado?: EstadoReserva; mesa_id?: number; page?: number; proximas?: boolean }) {
    return api.get<PaginadoReservas>('/reservas', { params })
  },
  // "Mis pedidos y reservas" (Cliente): sus propias reservas, identificadas por correo de
  // contacto (ver ReservaController::misReservas() — Reserva no tiene cliente_id, RN-M05-12).
  misReservas(params?: { page?: number }) {
    return api.get<PaginadoReservas>('/mis-reservas', { params })
  },
  depositosRetenidos() {
    return api.get<DepositosRetenidos>('/reservas/depositos-retenidos')
  },
  // Con sesión de Mesero o Administrador queda como reserva manual a su nombre (RF-M05-013).
  crear(payload: CrearReservaPayload) {
    return api.post<Reserva>('/reservas', payload)
  },
  // Fase 1, tarea 4: mesas activas sin otra reserva que se cruce con esa fecha y hora (pública).
  mesasDisponibles(fecha_hora: string) {
    return api.get<Mesa[]>('/mesas/disponibles', { params: { fecha_hora } })
  },
  // Fase 6, tarea 1 (RF-M05-030): con la pasarela activa trae `checkout` con el enlace de Wompi.
  registrarDepositoEnLinea(id: number, payload: DepositoEnLineaPayload) {
    const datos = formularioPagoEnLinea(payload)
    datos.append('token', payload.token)
    return api.post<Reserva & { checkout: CheckoutPasarela | null }>(`/reservas/${id}/deposito/en-linea`, datos)
  },
  confirmarDepositoEnLinea(id: number) {
    return api.post<Reserva>(`/reservas/${id}/deposito/en-linea/confirmar`)
  },
  // Pago directo (22/10/2026): el motivo lo ve el Cliente en el enlace de su reserva.
  // Pago directo (22/10/2026, RF-M06-032): la captura del depósito, solo para el personal.
  comprobanteDeposito(id: number) {
    return api.get<Blob>(`/reservas/${id}/deposito/comprobante`, { responseType: 'blob' })
  },
  rechazarDepositoEnLinea(id: number, motivo?: string) {
    return api.post<Reserva>(`/reservas/${id}/deposito/en-linea/rechazar`, { motivo: motivo?.trim() || undefined })
  },
  // RN-M05-16: presencial (efectivo, tarjeta o transferencia).
  // Mejora (26/10/2026, RF-M05-045 y 046): en efectivo, con cuánto pagó el cliente; en transferencia (obligatoria) o
  // tarjeta (opcional), la foto del comprobante. Va como formulario por la foto.
  registrarDepositoManual(id: number, payload: DepositoManualPayload) {
    const datos = new FormData()
    datos.append('metodo', payload.metodo)
    if (payload.metodo === 'efectivo' && payload.recibido) datos.append('recibido', String(payload.recibido))
    if (payload.metodo !== 'efectivo' && payload.comprobante) datos.append('comprobante', payload.comprobante)
    return api.post<Reserva>(`/reservas/${id}/deposito/manual`, datos)
  },
  // Mejora (26/10/2026, RF-M05-044): el Cliente con sesión paga el depósito pendiente desde «Mis pedidos y reservas».
  pagarMiDeposito(id: number, payload: PagoEnLineaPayload) {
    return api.post<Reserva & { checkout: CheckoutPasarela | null }>(`/mis-reservas/${id}/deposito`, formularioPagoEnLinea(payload))
  },
  marcarNoShow(id: number) {
    return api.post<Reserva>(`/reservas/${id}/no-show`)
  },
  // Fase 4, tarea 4 (RF-M05-027): reenviar un aviso por correo que falló.
  reintentarAviso(id: number, avisoId: number) {
    return api.post<Reserva>(`/reservas/${id}/avisos/${avisoId}/reintentar`)
  },
  // Fase 4, tarea 4 (RF-M05-018, RN-M05-09): ver y cancelar con el enlace del correo, sin cuenta.
  verPorEnlace(token: string) {
    return api.get<ReservaPublica>(`/reservas/enlace/${encodeURIComponent(token)}`)
  },
  cancelarPorEnlace(token: string) {
    return api.post<ReservaPublica>(`/reservas/enlace/${encodeURIComponent(token)}/cancelar`)
  },
  // Fase 6, tarea 1 (RF-M05-030): pagar o reintentar el depósito con el enlace de la reserva.
  pagarDepositoPorEnlace(token: string, payload: PagoEnLineaPayload) {
    return api.post<ReservaPublica & { checkout: CheckoutPasarela | null }>(
      `/reservas/enlace/${encodeURIComponent(token)}/deposito`,
      formularioPagoEnLinea(payload),
    )
  },
  completar(id: number) {
    return api.post<Reserva>(`/reservas/${id}/completar`)
  },
  cancelar(id: number) {
    return api.post<Reserva>(`/reservas/${id}/cancelar`)
  },
}
