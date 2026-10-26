// M05 — espeja MesaController.php: GET /mesas lista las mesas (activas para
// Cliente/invitado; también las inactivas para Administrador/Mesero, igual que
// categorias()/productos() en catalogo.service.ts — así el Administrador puede
// reactivarlas). abrir()/liberar() son de Administrador/Mesero (RF-M05-009/010), sin
// pedido de por medio. crear()/desactivar()/activar()/liberarForzada() son exclusivas
// de Administrador (RF-M05-004/011).
import api from './api'
import type { CuentaDeMesa, Mesa, Pedido } from '@/types/models'

// Fase 1, tarea 3 — espeja MesaCuentaService::resumen(): la cuenta de la visita en curso
// (RF-M02-L-019/020). "abierta" es false cuando la mesa ya no está ocupada.
export interface CuentaMesa {
  mesa: Mesa
  abierta: boolean
  ocupada_desde: string | null
  pedidos: Pedido[]
  total: number
  pagado: number
  saldo: number
  pedidos_activos: number
  // Mejora (26/10/2026): la cuenta separada por número de cuenta.
  cuentas: CuentaDeMesa[]
}

// Cobro con la foto del comprobante: va como formulario (multipart).
export function formularioCobro(payload: { metodo: string; monto: number; cuenta?: number | null; comprobante?: File | null }): FormData {
  const datos = new FormData()
  datos.append('metodo', payload.metodo)
  datos.append('monto', String(payload.monto))
  if (payload.cuenta) datos.append('cuenta', String(payload.cuenta))
  if (payload.comprobante) datos.append('comprobante', payload.comprobante)
  return datos
}

export interface CrearMesaPayload {
  nombre: string
  activa?: boolean
}

export const mesaService = {
  // Eliminar (24/10/2026): con `eliminadas`, solo las archivadas (para restaurarlas).
  index(params?: { eliminadas?: boolean }) {
    return api.get<Mesa[]>('/mesas', { params: params?.eliminadas ? { eliminadas: 1 } : undefined })
  },
  // RF-M02-L-025: resuelve la mesa escaneada por el Cliente (pedido por QR, sin cuenta) —
  // público, 404 si el QR no corresponde a ninguna mesa activa. Ver MesaQrView.vue.
  porQrCode(qr_code: string) {
    return api.get<Mesa>(`/mesas/qr/${qr_code}`)
  },
  crear(payload: CrearMesaPayload) {
    return api.post<Mesa>('/mesas', payload)
  },
  // Fase 1, tarea 6 (RF-M05-002): corregir el nombre; el QR sigue siendo el mismo.
  actualizar(id: number, nombre: string) {
    return api.put<Mesa>(`/mesas/${id}`, { nombre })
  },
  abrir(id: number) {
    return api.post<Mesa>(`/mesas/${id}/abrir`)
  },
  liberar(id: number) {
    return api.post<Mesa>(`/mesas/${id}/liberar`)
  },
  // RF-M05-011: salta la validación de pedidos activos de liberar() — exclusiva de
  // Administrador, exige un motivo que queda en la auditoría.
  liberarForzada(id: number, motivo: string) {
    return api.post<Mesa>(`/mesas/${id}/liberar-forzada`, { motivo })
  },
  desactivar(id: number) {
    return api.post<Mesa>(`/mesas/${id}/desactivar`)
  },
  activar(id: number) {
    return api.post<Mesa>(`/mesas/${id}/activar`)
  },
  cuenta(id: number) {
    return api.get<CuentaMesa>(`/mesas/${id}/cuenta`)
  },
  // Solo el Mesero (RF-M06-003). Reparte el monto entre los pedidos de la mesa con saldo, del
  // más antiguo al más nuevo; 422 si no hay caja abierta o el monto supera lo que se debe.
  // Mejora (26/10/2026): `cuenta` cobra solo esa cuenta de la mesa; `comprobante` es la foto que tomó
  // el Mesero (obligatoria en transferencia).
  cobrar(id: number, payload: { metodo: 'efectivo' | 'tarjeta' | 'transferencia'; monto: number; cuenta?: number | null; comprobante?: File | null }) {
    return api.post<CuentaMesa>(`/mesas/${id}/cobrar`, formularioCobro(payload))
  },
  // RF-M05-006: invalida el QR anterior y genera uno nuevo.
  regenerarQr(id: number) {
    return api.post<Mesa>(`/mesas/${id}/regenerar-qr`)
  },
}
