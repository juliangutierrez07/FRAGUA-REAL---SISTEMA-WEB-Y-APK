// M02/M07 — espeja StorePedidoRequest y RegistrarPagoEnLineaRequest del backend
// (routes/api.php: POST /pedidos, GET /pedidos, GET /pedidos/{id}, POST /pedidos/{id}/pagar-en-linea).
import api from './api'
import { formularioCobro } from './mesa.service'
import type { CheckoutPasarela, Comprobante, MedioPagoEnLinea, Pago, Pedido, PromocionAplicada, TipoPedido } from '@/types/models'

export interface LineaPedidoPayload {
  producto_id?: number
  combo_id?: number
  variante_id?: number | null
  cantidad: number
  extras_ids?: number[]
  observaciones?: string
}

// Espeja StorePedidoRequest completo (los 3 canales): el Cliente web solo usa
// domicilio/recogida (ver CheckoutView.vue), el Mesero usa "local" con mesa_id
// (ver views/mesero/PedidosView.vue) — el backend fija valor_domicilio, nunca el body.
export interface CrearPedidoPayload {
  tipo: TipoPedido
  origen: 'cliente_web' | 'mesero' | 'qr'
  mesa_id?: number
  // Mejora (26/10/2026, RF-M02-L-033): a qué cuenta de la mesa va (sin dato, la 1).
  cuenta?: number
  cliente_invitado_nombre?: string
  cliente_invitado_telefono?: string
  // Fase 4, tarea 5: opcional, para avisarle al invitado que su pedido está listo.
  cliente_invitado_correo?: string
  // Domicilio/recogida: el del invitado ya sirve; el Cliente con cuenta lo manda aquí.
  telefono_contacto?: string
  // Domicilio: dirección, barrio y referencia son obligatorios (RN-M07-01).
  direccion_entrega?: string
  barrio?: string
  referencia?: string
  // Mejora (26/10/2026, RF-M07-033 y 034): ubicación actual del Cliente y con qué billete paga contra entrega.
  ubicacion_lat?: number
  ubicacion_lng?: number
  paga_con?: number
  observaciones_entrega?: string
  // Sin dato, el backend asume contra entrega / al recoger.
  metodo_pago_preferido?: 'contra_entrega' | 'en_linea'
  pasarela_preferida?: MedioPagoEnLinea
  lineas: LineaPedidoPayload[]
}

// Fase 6, tarea 1 (RF-M06-006): el pago creado y, si la pasarela está activa, el enlace del checkout.
export type PagoEnLineaRespuesta = Pago & { checkout: CheckoutPasarela | null }

// Pago directo (22/10/2026): sin Wompi va la captura del comprobante (y una referencia opcional); el
// monto lo fija el servidor (el saldo del pedido). Se envía como formulario con archivo.
export interface PagoEnLineaPayload {
  pasarela: MedioPagoEnLinea
  comprobante?: File | null
  referencia?: string
}

export function formularioPagoEnLinea(payload: PagoEnLineaPayload): FormData {
  const datos = new FormData()
  datos.append('pasarela', payload.pasarela)
  if (payload.comprobante) datos.append('comprobante', payload.comprobante)
  if (payload.referencia?.trim()) datos.append('referencia', payload.referencia.trim())
  return datos
}

// Espeja RegistrarCobroRequest — cobro PRESENCIAL (M06), siempre hecho por el Mesero,
// sin importar el canal (RF-M06-003/004, RNF-M06-004). "en_linea" no es válido aquí.
export interface CobrarPayload {
  metodo: 'efectivo' | 'tarjeta' | 'transferencia'
  monto: number
  // Mejora (26/10/2026, RF-M06-037): la foto del comprobante (obligatoria en transferencia).
  comprobante?: File | null
}

// Fase 2, tarea 1 (RF-M04-035): POST /pedidos/cotizacion — total del carrito con las
// promociones vigentes, antes de crear el pedido (PedidoService::cotizar).
export interface Cotizacion {
  subtotal: number
  descuento: number
  promociones: PromocionAplicada[]
  valor_domicilio: number
  total: number
  lineas: { subtotal: number; descuento: number }[]
}

// Fase 2, tarea 1 (RF-M04-036, HU-M04-015): descuento manual. O porcentaje/valor fijo, o una
// promoción de tipo "manual" (promocion_id). El motivo siempre es obligatorio.
export interface DescuentoPayload {
  tipo_valor?: 'porcentaje' | 'fijo'
  valor?: number
  promocion_id?: number
  motivo: string
}

// Fase 1, tarea 1: un evento de GET /pedidos/{id}/historial (PedidoService::historial).
export interface EventoHistorialPedido {
  tipo: 'estado' | 'modificacion' | 'pago' | 'comanda'
  fecha: string
  quien: string | null
  descripcion: string
  total_anterior?: number
  total_nuevo?: number
  afecta_comanda?: boolean
}

export interface PedidosPaginados {
  data: Pedido[]
  current_page: number
  last_page: number
  total: number
}

export const pedidoService = {
  crear(payload: CrearPedidoPayload) {
    return api.post<Pedido>('/pedidos', payload)
  },
  // Sin filtros: el backend ya limita a "los míos" cuando el usuario es Cliente (PedidoController::index).
  // Con filtros (Administrador > Pedidos): tipo, estado y página — PedidoController::index
  // los acepta como query params y pagina de a 20 (per_page hasta 100); `activos` trae solo
  // pendiente/confirmado/preparando.
  // Mejora (28/10/2026, RF-M02-T-018): `desde`/`hasta` (YYYY-MM-DD, hora de Colombia) limitan a esos días.
  index(params?: { tipo?: TipoPedido; estado?: Pedido['estado']; activos?: boolean; page?: number; per_page?: number; desde?: string; hasta?: string }) {
    return api.get<PedidosPaginados>('/pedidos', { params })
  },
  show(id: number) {
    return api.get<Pedido>(`/pedidos/${id}`)
  },
  // Fase 6, tarea 1: con la pasarela activa trae `checkout` con el enlace de Wompi (ver utils/pasarela.ts).
  pagarEnLinea(id: number, payload: PagoEnLineaPayload) {
    return api.post<PagoEnLineaRespuesta>(`/pedidos/${id}/pagar-en-linea`, formularioPagoEnLinea(payload))
  },
  // RF-M06-005 (Fase 0, tarea 4): el invitado paga y consulta su pedido con el token que
  // recibió al crearlo — no tiene sesión.
  pagarEnLineaConToken(token: string, payload: PagoEnLineaPayload) {
    return api.post<PagoEnLineaRespuesta>(`/pedidos/seguimiento/${token}/pagar-en-linea`, formularioPagoEnLinea(payload))
  },
  seguimiento(token: string) {
    return api.get<Pedido>(`/pedidos/seguimiento/${token}`)
  },
  // ---- Mesero/Administrador (routes/api.php, grupo role:administrador,mesero) ----
  confirmar(id: number) {
    return api.post<Pedido>(`/pedidos/${id}/confirmar`)
  },
  marcarPreparando(id: number) {
    return api.post<Pedido>(`/pedidos/${id}/preparando`)
  },
  marcarLineaPreparada(pedidoId: number, detalleId: number) {
    return api.post(`/pedidos/${pedidoId}/detalles/${detalleId}/preparado`)
  },
  // RN-PED-32: un pedido finaliza desde PREPARANDO con el pago completo y, en domicilio y
  // recogida, la entrega o la recogida confirmada. Las tres acciones son del Mesero (403 al
  // Administrador); si algo falta, el backend responde 422 con el motivo.
  confirmarEntrega(id: number) {
    return api.post<Pedido>(`/pedidos/${id}/entregar`)
  },
  confirmarRecogida(id: number) {
    return api.post<Pedido>(`/pedidos/${id}/recoger`)
  },
  // Fase 5, tarea 1 (RF-M06-009/010): el comprobante vigente en PDF — "ticket" (80 mm, para
  // imprimir) o "pdf" (A5). Sin formato, el del canal: ticket en local, PDF en domicilio y recogida.
  comprobante(id: number, formato?: 'ticket' | 'pdf') {
    return api.get<Blob>(`/pedidos/${id}/comprobante`, { params: formato ? { formato } : undefined, responseType: 'blob' })
  },
  comprobantePorEnlace(token: string) {
    return api.get<Blob>(`/pedidos/seguimiento/${token}/comprobante`, { responseType: 'blob' })
  },
  // Reimpresión controlada (13/10/2026), solo Administrador: todos los comprobantes del pedido
  // (vigente y anulados) y abrir uno en particular (un anulado sale con su sello ANULADO).
  comprobantes(id: number) {
    return api.get<{ data: Comprobante[] }>(`/pedidos/${id}/comprobantes`)
  },
  comprobanteDelPedido(id: number, comprobanteId: number) {
    return api.get<Blob>(`/pedidos/${id}/comprobantes/${comprobanteId}`, { responseType: 'blob' })
  },
  // Fase 4, tarea 5 (RF-M07-R-012): la recogida está lista; el backend avisa al cliente por correo.
  marcarListo(id: number) {
    return api.post<Pedido>(`/pedidos/${id}/listo`)
  },
  reintentarAviso(id: number, avisoId: number) {
    return api.post<Pedido>(`/pedidos/${id}/avisos/${avisoId}/reintentar`)
  },
  finalizar(id: number) {
    return api.post<Pedido>(`/pedidos/${id}/finalizar`)
  },
  // ---- Edición (Fase 1, tarea 1): PENDIENTE libre; CONFIRMADO solo líneas no preparadas
  // (RN-PED-07/08/10). Cada llamada devuelve el pedido completo con el total recalculado y
  // deja el cambio en el historial (RN-PED-12). Errores de regla → 422 con el motivo.
  agregarLinea(id: number, linea: LineaPedidoPayload) {
    return api.post<Pedido>(`/pedidos/${id}/detalles`, linea)
  },
  actualizarLinea(id: number, detalleId: number, cambios: { cantidad?: number; observaciones?: string | null }) {
    return api.patch<Pedido>(`/pedidos/${id}/detalles/${detalleId}`, cambios)
  },
  retirarLinea(id: number, detalleId: number) {
    return api.delete<Pedido>(`/pedidos/${id}/detalles/${detalleId}`)
  },
  historial(id: number) {
    return api.get<{ data: EventoHistorialPedido[] }>(`/pedidos/${id}/historial`)
  },
  // Fase 2, tarea 1: cotización (pública) y descuento manual (Mesero y Administrador).
  cotizar(tipo: TipoPedido, lineas: LineaPedidoPayload[]) {
    return api.post<Cotizacion>('/pedidos/cotizacion', { tipo, lineas })
  },
  aplicarDescuento(id: number, payload: DescuentoPayload) {
    return api.post<Pedido>(`/pedidos/${id}/descuento`, payload)
  },
  quitarDescuento(id: number) {
    return api.delete<Pedido>(`/pedidos/${id}/descuento`)
  },
  // Fase 1, tarea 6: el Mesero cancela lo PENDIENTE; algo ya confirmado necesita la contraseña de
  // un Administrador (RN-M07-05). El Administrador no la necesita.
  cancelar(id: number, motivo: string, passwordAdministrador?: string) {
    return api.post<Pedido>(`/pedidos/${id}/cancelar`, { motivo, password_administrador: passwordAdministrador || undefined })
  },
  // RF-M07-016, RF-M07-R-009: el invitado cancela su pedido pendiente con su enlace.
  cancelarConToken(token: string, motivo: string) {
    return api.post<Pedido>(`/pedidos/seguimiento/${token}/cancelar`, { motivo })
  },
  // POST /pedidos/{id}/cobrar — cobro presencial (M06). El backend rechaza con 422 y
  // un mensaje claro si no hay turno de caja abierto (RN-M06-03) — el Mesero no puede
  // abrir turno, eso es exclusivo del Administrador (RN-M06-01).
  cobrar(id: number, payload: CobrarPayload) {
    return api.post<Pedido>(`/pedidos/${id}/cobrar`, formularioCobro(payload))
  },
  // Fidelización (27/10/2026, RF-M06-040): al cobrar, buscar al cliente y dejarle los pedidos en su historial.
  buscarCliente(dato: string) {
    return api.get<{ id: number; nombre: string; coincide: 'documento' | 'correo' | 'celular' }>('/clientes/buscar', { params: { dato } })
  },
  asignarCliente(pedidoIds: number[], clienteId: number) {
    return api.post<{ cliente: { id: number; nombre: string }; pedido_ids: number[] }>('/pedidos/asignar-cliente', {
      pedido_ids: pedidoIds,
      cliente_id: clienteId,
    })
  },
}
