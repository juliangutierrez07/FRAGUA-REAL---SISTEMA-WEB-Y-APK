import { Armchair, Bike, ShoppingBag } from 'lucide-vue-next'
import type { Pedido } from '@/types/models'

// Cada canal de pedido tiene su ícono y su tono — de un vistazo (sin leer) el Mesero distingue
// qué pedidos son del piso y cuáles son de domicilio/recogida. Compartido por las pantallas
// de Pedidos y Cobrar del Mesero.
export const TIPOS_PEDIDO_UI: Record<Pedido['tipo'], { texto: string; icono: typeof Armchair; clase: string }> = {
  local: { texto: 'Local', icono: Armchair, clase: 'bg-brand-50 text-brand-700' },
  domicilio: { texto: 'Domicilio', icono: Bike, clase: 'bg-sky-50 text-sky-700' },
  recogida: { texto: 'Recogida', icono: ShoppingBag, clase: 'bg-violet-50 text-violet-700' },
}

// Hoy: solo la hora ("9:23 p. m."); otro día: "28 sept, 9:23 p. m." — más corto que la fecha completa.
export function formatoHoraPedido(valor: string): string {
  const fecha = new Date(valor)
  const hora = fecha.toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit' })
  if (fecha.toDateString() === new Date().toDateString()) return hora
  const dia = fecha.toLocaleDateString('es-CO', { day: 'numeric', month: 'short' }).replace(' de ', ' ').replace('.', '')
  return `${dia}, ${hora}`
}

// Quién/dónde: la mesa para el canal Local, o el cliente (invitado o con cuenta) para
// domicilio/recogida; "Pedido #id" solo si no hay ninguno.
export function titularPedido(pedido: Pedido): string {
  // Mejora (26/10/2026, RF-M02-L-034): con cuentas separadas, la mesa dice a qué cuenta va el pedido.
  if (pedido.mesa) return pedido.mesa.nombre + ((pedido.cuenta ?? 1) > 1 ? ` · Cuenta ${pedido.cuenta}` : '')
  return pedido.cliente_invitado_nombre ?? pedido.cliente?.nombre ?? `Pedido #${pedido.id}`
}

// Mejora (26/10/2026, RF-M07-035): enlace para abrir en el mapa la ubicación que compartió el Cliente.
export function enlaceMapa(lat: number, lng: number): string {
  return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`
}

// Réplica de Pedido::totalPagado() del backend: solo pagos aprobados y no anulados.
export function totalPagado(pedido: Pedido): number {
  return (pedido.pagos ?? [])
    .filter((p) => p.estado === 'aprobado' && !p.anulado)
    .reduce((acc, p) => acc + Number(p.monto), 0)
}

export function saldoPendiente(pedido: Pedido): number {
  return Math.max(0, Number(pedido.total) - totalPagado(pedido))
}

// Réplica de Pedido::faltaParaFinalizar() (RN-PED-32): null si el pedido ya puede finalizarse.
// El backend vuelve a validar; esto solo decide qué botón y qué aviso mostrar.
export function faltaParaFinalizar(pedido: Pedido): string | null {
  if (pedido.estado !== 'preparando') return 'Solo un pedido en preparación puede finalizarse.'
  if (saldoPendiente(pedido) > 0) return 'Falta completar el pago.'
  if (pedido.tipo === 'domicilio' && !pedido.entregado_at) return 'Falta confirmar la entrega.'
  if (pedido.tipo === 'recogida' && !pedido.recogido_at) return 'Falta confirmar que el cliente lo recogió.'
  return null
}
