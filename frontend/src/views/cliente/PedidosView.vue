<script setup lang="ts">
// M02/M07/M05 — "Mis pedidos y reservas". GET /pedidos ya viene filtrado a "los míos"
// cuando el usuario autenticado es Cliente (PedidoController::index); GET /mis-reservas
// identifica las reservas propias por correo de contacto, ya que Reserva no tiene
// cliente_id (RN-M05-12 — ver ReservaController::misReservas()).
// Mejora (26/10/2026, RF-M05-044): una reserva pendiente de pago se paga desde aquí (antes solo con el enlace
// del correo); mientras el personal revisa el comprobante se ve «Pago en revisión».
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { CalendarClock, ChevronDown, PackageSearch, XCircle } from 'lucide-vue-next'
import mascota from '@/assets/img/mascota-fragua-real.png'
import AvancePedido from '@/components/pedidos/AvancePedido.vue'
import BotonComprobante from '@/components/pedidos/BotonComprobante.vue'
import TotalesPedido from '@/components/pedidos/TotalesPedido.vue'
import PagoEnLinea from '@/components/pagos/PagoEnLinea.vue'
import AvisoDestacado from '@/components/common/AvisoDestacado.vue'
import ConfirmarCorreo from '@/components/common/ConfirmarCorreo.vue'
import { AxiosError } from 'axios'
import { usePagosEnLinea } from '@/composables/usePagosEnLinea'
import { pedidoService, type PagoEnLineaPayload } from '@/services/pedido.service'
import { reservaService } from '@/services/reserva.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { irAlCheckout } from '@/utils/pasarela'
import type { EstadoReserva, Pedido, Reserva } from '@/types/models'

const tab = ref<'pedidos' | 'reservas'>('pedidos')

// ---- Pedidos ----
const pedidos = ref<Pedido[]>([])
const cargando = ref(true)
const error = ref('')
const abierto = ref<number | null>(null)
const detalleCache = ref<Record<number, Pedido>>({})
const cargandoDetalle = ref<number | null>(null)

const pedidoACancelar = ref<Pedido | null>(null)
const motivoCancelacion = ref('')
const cancelandoPedido = ref(false)
const errorCancelarPedido = ref('')

const ESTADOS: Record<Pedido['estado'], { texto: string; clase: string }> = {
  pendiente: { texto: 'Pendiente', clase: 'bg-amber-100 text-amber-800' },
  confirmado: { texto: 'Confirmado', clase: 'bg-blue-100 text-blue-800' },
  preparando: { texto: 'Preparando', clase: 'bg-orange-100 text-orange-800' },
  finalizado: { texto: 'Finalizado', clase: 'bg-green-100 text-green-800' },
  cancelado: { texto: 'Cancelado', clase: 'bg-red-100 text-red-800' },
}

// Fase 4, tarea 5 (RF-M07-R-012): una recogida lista sigue en preparación, pero se muestra así.
const LISTO = { texto: 'Listo para recoger', clase: 'bg-emerald-100 text-emerald-800' }
function estadoVisible(pedido: Pedido) {
  return pedido.tipo === 'recogida' && pedido.estado === 'preparando' && pedido.listo_at && !pedido.recogido_at
    ? LISTO
    : ESTADOS[pedido.estado]
}

const TIPOS: Record<Pedido['tipo'], string> = {
  local: 'En el local',
  domicilio: 'Domicilio',
  recogida: 'Recogida',
}

// RN-M07-04, RF-M07-016 y RF-M07-R-009 (Fase 1): el Cliente cancela su domicilio o su recogida
// mientras esté PENDIENTE; ver PedidoPolicy::cancelar().
function puedeCancelarPedido(pedido: Pedido) {
  return (pedido.tipo === 'domicilio' || pedido.tipo === 'recogida') && pedido.estado === 'pendiente'
}

// RF-M06-008 (Fase 0, tarea 4): si el último pago en línea fue rechazado y el pedido sigue
// pendiente, el Cliente puede reintentarlo aquí sobre el mismo pedido.
// Pago directo (22/10/2026): también si eligió pagar en línea y aún no envió el comprobante.
const { pagos: configPagos, disponible: enLineaDisponible } = usePagosEnLinea()

function pagosEnLinea(pedido: Pedido) {
  return (pedido.pagos ?? []).filter((p) => p.metodo === 'en_linea' && !p.anulado).sort((a, b) => b.id - a.id)
}

function puedeReintentarPago(pedido: Pedido) {
  const enLinea = pagosEnLinea(pedido)
  // Fase 6, tarea 1: también si el último quedó a medias en la pasarela (el servidor pregunta por
  // él antes de abrir otro; si sigue en curso, lo dice).
  const aMedias = enLinea.some((p) => p.estado === 'pendiente' && p.transaccion_pasarela)
  return (
    enLineaDisponible.value &&
    pedido.estado === 'pendiente' &&
    pedido.tipo !== 'local' &&
    (enLinea.length > 0 ? aMedias || enLinea.every((p) => p.estado === 'rechazado') : pedido.metodo_pago_preferido === 'en_linea')
  )
}

// Sin Wompi: la captura que mandó y aún se revisa.
function comprobanteEnRevision(pedido: Pedido) {
  const ultimo = pagosEnLinea(pedido)[0]
  return pedido.estado === 'pendiente' && ultimo?.estado === 'pendiente' && !!ultimo.tiene_comprobante
}

function saldoPedido(pedido: Pedido) {
  const pagado = (pedido.pagos ?? []).filter((p) => p.estado === 'aprobado' && !p.anulado).reduce((a, p) => a + Number(p.monto), 0)
  return Math.max(0, Number(pedido.total) - pagado)
}

const pagoAbierto = ref<number | null>(null)
const reintentando = ref<number | null>(null)
const errorReintento = ref('')

async function reintentarPago(pedido: Pedido, datosPago: PagoEnLineaPayload) {
  reintentando.value = pedido.id
  errorReintento.value = ''
  try {
    const { data } = await pedidoService.pagarEnLinea(pedido.id, datosPago)
    if (irAlCheckout(data.checkout)) return // Fase 6: se paga en la pasarela y se vuelve por /pago/retorno
    pagoAbierto.value = null
    await cargarPedidos()
  } catch (e) {
    errorReintento.value = Object.values(erroresDeValidacion(e))[0] ?? mensajeDeError(e, 'No pudimos registrar el pago.')
  } finally {
    reintentando.value = null
  }
}

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function formatoFecha(valor: string) {
  return new Date(valor).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' })
}

async function cargarPedidos(silencioso = false) {
  if (!silencioso) cargando.value = true
  error.value = ''
  try {
    const { data } = await pedidoService.index()
    pedidos.value = data.data
    // El detalle abierto se vuelve a pedir para que su estado y sus pagos también estén al día.
    const id = abierto.value
    if (silencioso && id) detalleCache.value[id] = (await pedidoService.show(id)).data
  } catch (e) {
    if (!silencioso) error.value = mensajeDeError(e, 'No pudimos cargar tus pedidos.')
  } finally {
    cargando.value = false
  }
}

// Mejora (26/10/2026, RF-M07-036): mientras haya un pedido en curso, la lista se pone al día cada 10 s (con la
// página a la vista) y al volver a ella.
let temporizador: ReturnType<typeof setInterval> | undefined
const hayEnCurso = () => pedidos.value.some((p) => ['pendiente', 'confirmado', 'preparando'].includes(p.estado))
function alVolver() {
  if (document.visibilityState === 'visible' && hayEnCurso()) void cargarPedidos(true)
}
onMounted(() => {
  temporizador = setInterval(alVolver, 10000)
  document.addEventListener('visibilitychange', alVolver)
})
onBeforeUnmount(() => {
  clearInterval(temporizador)
  document.removeEventListener('visibilitychange', alVolver)
})

async function alternar(pedido: Pedido) {
  if (abierto.value === pedido.id) {
    abierto.value = null
    return
  }
  abierto.value = pedido.id

  if (!detalleCache.value[pedido.id]) {
    cargandoDetalle.value = pedido.id
    try {
      const { data } = await pedidoService.show(pedido.id)
      detalleCache.value[pedido.id] = data
    } catch {
      // Si falla, se deja el resumen básico que ya se tiene en la lista.
    } finally {
      cargandoDetalle.value = null
    }
  }
}

function abrirCancelarPedido(pedido: Pedido) {
  pedidoACancelar.value = pedido
  motivoCancelacion.value = ''
  errorCancelarPedido.value = ''
}

function cerrarCancelarPedido() {
  pedidoACancelar.value = null
}

async function confirmarCancelarPedido() {
  if (!pedidoACancelar.value) return
  if (!motivoCancelacion.value.trim()) {
    errorCancelarPedido.value = 'Cuéntanos brevemente el motivo.'
    return
  }

  cancelandoPedido.value = true
  errorCancelarPedido.value = ''
  try {
    const { data } = await pedidoService.cancelar(pedidoACancelar.value.id, motivoCancelacion.value.trim())
    const idx = pedidos.value.findIndex((p) => p.id === data.id)
    if (idx !== -1) pedidos.value[idx] = data
    delete detalleCache.value[data.id]
    pedidoACancelar.value = null
  } catch (e) {
    errorCancelarPedido.value = mensajeDeError(e, 'No pudimos cancelar el pedido.')
  } finally {
    cancelandoPedido.value = false
  }
}

// ---- Reservas ----
const reservas = ref<Reserva[]>([])
const cargandoReservas = ref(false)
const reservasCargadas = ref(false)
const errorReservas = ref('')
// Seguridad (28/10/2026, RN-USR-28): las reservas se buscan por correo; sin confirmarlo, el servidor no las muestra.
const correoSinConfirmar = ref(false)

const reservaACancelar = ref<Reserva | null>(null)
const cancelandoReserva = ref(false)
const errorCancelarReserva = ref('')

const ESTADOS_RESERVA: Record<EstadoReserva, { texto: string; clase: string }> = {
  pendiente_pago: { texto: 'Pendiente de pago', clase: 'bg-amber-100 text-amber-800' },
  confirmada: { texto: 'Confirmada', clase: 'bg-blue-100 text-blue-800' },
  cancelada: { texto: 'Cancelada', clase: 'bg-red-100 text-red-800' },
  no_show: { texto: 'No asistió', clase: 'bg-neutral-200 text-neutral-700' },
  completada: { texto: 'Completada', clase: 'bg-green-100 text-green-800' },
}

// Ya mandó el comprobante (o el pago quedó en proceso) y el personal aún no lo revisa.
function depositoEnRevision(reserva: Reserva) {
  return reserva.estado === 'pendiente_pago' && reserva.deposito_estado === 'pendiente' && !reserva.puede_pagar
}
const EN_REVISION = { texto: 'Pago en revisión', clase: 'bg-sky-100 text-sky-800' }
function estadoReservaVisible(reserva: Reserva) {
  return depositoEnRevision(reserva) ? EN_REVISION : ESTADOS_RESERVA[reserva.estado]
}

const pagoReservaAbierto = ref<number | null>(null)
const pagandoReserva = ref<number | null>(null)
const errorPagoReserva = ref('')

function abrirPagoReserva(reserva: Reserva) {
  pagoReservaAbierto.value = reserva.id
  errorPagoReserva.value = ''
}

async function pagarReserva(reserva: Reserva, datosPago: PagoEnLineaPayload) {
  pagandoReserva.value = reserva.id
  errorPagoReserva.value = ''
  try {
    const { data } = await reservaService.pagarMiDeposito(reserva.id, datosPago)
    if (irAlCheckout(data.checkout)) return // con la pasarela se paga allá y se vuelve por /pago/retorno
    const { checkout: _checkout, ...actualizada } = data
    const idx = reservas.value.findIndex((r) => r.id === reserva.id)
    if (idx !== -1) reservas.value[idx] = { ...reservas.value[idx], ...actualizada }
    pagoReservaAbierto.value = null
  } catch (e) {
    errorPagoReserva.value = Object.values(erroresDeValidacion(e))[0] ?? mensajeDeError(e, 'No pudimos registrar el pago del depósito.')
  } finally {
    pagandoReserva.value = null
  }
}

// RN-M05-09: "Una reserva puede cancelarse antes de su hora por quien la creó, o por el
// Mesero/Administrador." (ver ReservaPolicy::cancelar() — la autorización fina real vive
// en el backend; esto solo decide si se muestra el botón).
function puedeCancelarReserva(reserva: Reserva) {
  return ['pendiente_pago', 'confirmada'].includes(reserva.estado) && new Date(reserva.fecha_hora) > new Date() && !depositoEnEfectivo(reserva)
}

// Revisión por rol (13/10/2026): un depósito pagado en efectivo se devuelve en el local (igual
// que en el enlace de la reserva); el servidor también lo rechaza.
function depositoEnEfectivo(reserva: Reserva) {
  return reserva.deposito_pagado && reserva.deposito_metodo === 'efectivo'
}

async function cargarReservas() {
  cargandoReservas.value = true
  errorReservas.value = ''
  try {
    const { data } = await reservaService.misReservas()
    reservas.value = data.data
    correoSinConfirmar.value = false
  } catch (e) {
    correoSinConfirmar.value = e instanceof AxiosError && e.response?.data?.correo_sin_confirmar === true
    if (!correoSinConfirmar.value) errorReservas.value = mensajeDeError(e, 'No pudimos cargar tus reservas.')
  } finally {
    cargandoReservas.value = false
    reservasCargadas.value = true
  }
}

function abrirCancelarReserva(reserva: Reserva) {
  reservaACancelar.value = reserva
  errorCancelarReserva.value = ''
}

function cerrarCancelarReserva() {
  reservaACancelar.value = null
}

async function confirmarCancelarReserva() {
  if (!reservaACancelar.value) return

  cancelandoReserva.value = true
  errorCancelarReserva.value = ''
  try {
    const { data } = await reservaService.cancelar(reservaACancelar.value.id)
    const idx = reservas.value.findIndex((r) => r.id === data.id)
    if (idx !== -1) reservas.value[idx] = data
    reservaACancelar.value = null
  } catch (e) {
    errorCancelarReserva.value = mensajeDeError(e, 'No pudimos cancelar la reserva.')
  } finally {
    cancelandoReserva.value = false
  }
}

function seleccionarTab(nuevo: 'pedidos' | 'reservas') {
  tab.value = nuevo
  if (nuevo === 'reservas' && !reservasCargadas.value) {
    cargarReservas()
  }
}

const sinNada = computed(() => tab.value === 'pedidos' ? pedidos.value.length === 0 : reservas.value.length === 0)

// Mejora (28/10/2026, RF-M07-037): «Seguir mi pedido» de la confirmación llega aquí con ?pedido=<id> y lo abre.
const route = useRoute()
onMounted(async () => {
  await cargarPedidos()
  const id = Number(route.query.pedido)
  const pedido = pedidos.value.find((p) => p.id === id)
  if (pedido) {
    await alternar(pedido)
    await nextTick()
    document.getElementById(`pedido-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }
})
</script>

<template>
  <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
    <div class="mb-8 flex items-center gap-3">
      <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
      <span class="text-[11px] font-medium tracking-[2px] text-brand-700">TU HISTORIAL</span>
    </div>
    <h1 class="font-display text-4xl font-medium tracking-tight text-ink">Mis pedidos y reservas</h1>

    <div class="mt-6 flex gap-2 border-b border-[#e2dbcf]">
      <button
        type="button"
        class="px-4 py-2 text-sm font-medium transition"
        :class="tab === 'pedidos' ? 'border-b-2 border-brand-500 text-ink' : 'text-[#6c655c]'"
        @click="seleccionarTab('pedidos')"
      >
        Pedidos
      </button>
      <button
        type="button"
        class="px-4 py-2 text-sm font-medium transition"
        :class="tab === 'reservas' ? 'border-b-2 border-brand-500 text-ink' : 'text-[#6c655c]'"
        @click="seleccionarTab('reservas')"
      >
        Reservas
      </button>
    </div>

    <!-- ---- Pedidos ---- -->
    <div v-if="tab === 'pedidos'" class="mt-8">
      <div v-if="cargando" class="mt-8 text-center text-sm text-[#6c655c]">Cargando tus pedidos…</div>

      <p v-else-if="error" class="mt-8 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

      <div v-else-if="sinNada" class="mt-8 text-center">
        <img :src="mascota" alt="Mascota de Fragua Real Burger" class="mx-auto w-40 drop-shadow-[0_20px_30px_rgba(0,0,0,0.12)]" />
        <p class="mt-6 font-display text-xl text-ink">Todavía no has pedido nada</p>
        <p class="mt-2 text-sm text-[#6c655c]">Cuando hagas tu primer pedido, lo vas a ver aquí.</p>
        <router-link
          to="/menu"
          class="mt-6 inline-flex items-center justify-center rounded-xl bg-brand-500 px-6 py-3 text-sm font-medium text-ink shadow-[0_8px_20px_rgba(214,164,58,0.35)] transition-all duration-150 hover:-translate-y-0.5 hover:bg-brand-400"
        >
          Ver el menú
        </router-link>
      </div>

      <ul v-else class="space-y-4">
        <li v-for="pedido in pedidos" :id="`pedido-${pedido.id}`" :key="pedido.id" class="scroll-mt-24 rounded-2xl border border-[#e2dbcf] bg-white">
          <button type="button" class="flex w-full items-center justify-between gap-4 p-5 text-left" @click="alternar(pedido)">
            <div class="flex items-center gap-3">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-500/15 text-brand-700">
                <PackageSearch :size="18" />
              </span>
              <div>
                <p class="font-display text-base text-ink">Pedido #{{ pedido.id }} · {{ TIPOS[pedido.tipo] }}<template v-if="pedido.tipo === 'local' && pedido.mesa"> · {{ pedido.mesa.nombre }}</template></p>
                <p class="mt-0.5 text-xs text-[#6c655c]">{{ formatoFecha(pedido.created_at) }}</p>
              </div>
            </div>
            <div class="flex items-center gap-3">
              <span class="rounded-full px-3 py-1 text-xs font-medium" :class="estadoVisible(pedido).clase">
                {{ estadoVisible(pedido).texto }}
              </span>
              <span class="hidden text-sm text-ink sm:inline">{{ formatoPrecio(pedido.total) }}</span>
              <ChevronDown :size="18" class="text-[#a39c8f] transition" :class="{ 'rotate-180': abierto === pedido.id }" />
            </div>
          </button>

          <div v-if="abierto === pedido.id" class="border-t border-[#e2dbcf] px-5 py-4">
            <!-- Mejora (28/10/2026, RF-M07-037): el seguimiento del pedido también desde aquí (se actualiza solo). -->
            <div class="mb-4 rounded-xl bg-[#fbfaf7] p-4">
              <p class="mb-3 text-[11px] font-medium tracking-[2px] text-brand-700">SEGUIMIENTO</p>
              <AvancePedido :pedido="pedido" compacto />
            </div>
            <p v-if="cargandoDetalle === pedido.id" class="text-sm text-[#6c655c]">Cargando detalle…</p>
            <template v-else-if="detalleCache[pedido.id]">
              <ul class="space-y-2 text-sm">
                <li
                  v-for="detalle in detalleCache[pedido.id].detalles"
                  :key="detalle.id"
                  class="flex justify-between gap-3 text-[#6c655c]"
                >
                  <span>
                    {{ detalle.cantidad }}× {{ detalle.producto?.nombre ?? detalle.combo?.nombre }}
                    <span v-if="detalle.variante" class="text-xs">({{ detalle.variante.nombre }})</span>
                    <span v-if="detalle.extras?.length" class="block text-xs italic">
                      + {{ detalle.extras.map((e) => e.nombre).join(', ') }}
                    </span>
                  </span>
                  <span class="shrink-0 text-ink">
                    {{ formatoPrecio((Number(detalle.precio_unitario_congelado) + (detalle.extras ?? []).reduce((a, e) => a + Number(e.pivot.precio_congelado), 0)) * detalle.cantidad) }}
                  </span>
                </li>
              </ul>
              <div class="mt-3 space-y-1 border-t border-[#e2dbcf] pt-3 text-sm">
                <!-- Fase 2, tarea 1: subtotal, promociones o descuento aplicados, domicilio y total. -->
                <TotalesPedido :pedido="detalleCache[pedido.id]" tono="cliente" />
                <div v-if="pedido.direccion_entrega" class="text-xs text-[#6c655c]">
                  {{ pedido.direccion_entrega }}<template v-if="pedido.barrio">, {{ pedido.barrio }}</template>
                </div>
              </div>
              <!-- Fase 5, tarea 1 (RF-M06-010): comprobante de pago en PDF cuando el pago está completo. -->
              <BotonComprobante v-if="detalleCache[pedido.id].comprobante" :pedido="detalleCache[pedido.id]" size="sm" class="mt-3" />
            </template>

            <p
              v-if="comprobanteEnRevision(pedido)"
              class="mt-4 rounded-xl bg-[#fff7e5] px-3 py-2 text-xs text-[#7a5a12]"
            >
              Recibimos tu comprobante. Estamos verificando la transferencia; en cuanto la confirmemos empezamos tu pedido.
            </p>
            <div v-if="puedeReintentarPago(pedido)" class="mt-4 border-t border-[#e2dbcf] pt-4">
              <p class="text-xs text-[#6c655c]">
                <template v-if="pagosEnLinea(pedido)[0]?.estado === 'rechazado'">
                  Tu pago en línea no se aprobó<template v-if="pagosEnLinea(pedido)[0]?.rechazo_motivo">:
                    <strong class="text-red-700">{{ pagosEnLinea(pedido)[0]?.rechazo_motivo }}</strong></template>. Puedes intentarlo de nuevo.
                </template>
                <template v-else-if="pagosEnLinea(pedido).length">
                  Tu pago en línea quedó en proceso. Si no lo terminaste, puedes intentarlo de nuevo.
                </template>
                <template v-else>Elegiste pagar en línea y aún no recibimos tu pago.</template>
              </p>
              <button
                v-if="pagoAbierto !== pedido.id"
                type="button"
                class="mt-2 rounded-lg border border-[#e3ded4] px-3 py-1.5 text-xs font-medium text-ink transition hover:bg-[#fbfaf7]"
                @click="(pagoAbierto = pedido.id), (errorReintento = '')"
              >
                Pagar {{ formatoPrecio(saldoPedido(pedido)) }} en línea
              </button>
              <PagoEnLinea
                v-else
                class="mt-3"
                :config="configPagos"
                :monto="saldoPedido(pedido)"
                :enviando="reintentando === pedido.id"
                :error="errorReintento"
                :medio-inicial="pedido.pasarela_preferida"
                @pagar="reintentarPago(pedido, $event)"
              />
            </div>

            <div v-if="puedeCancelarPedido(pedido)" class="mt-4 border-t border-[#e2dbcf] pt-4">
              <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-50"
                @click="abrirCancelarPedido(pedido)"
              >
                <XCircle :size="14" />
                Cancelar pedido
              </button>
            </div>
          </div>
        </li>
      </ul>
    </div>

    <!-- ---- Reservas ---- -->
    <div v-else class="mt-8">
      <div v-if="cargandoReservas" class="mt-8 text-center text-sm text-[#6c655c]">Cargando tus reservas…</div>

      <ConfirmarCorreo
        v-else-if="correoSinConfirmar"
        class="mt-8"
        motivo="Tus reservas se buscan por tu correo: confírmalo para verlas."
        @confirmado="cargarReservas"
      />

      <p v-else-if="errorReservas" class="mt-8 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorReservas }}</p>

      <div v-else-if="sinNada" class="mt-8 text-center">
        <img :src="mascota" alt="Mascota de Fragua Real Burger" class="mx-auto w-40 drop-shadow-[0_20px_30px_rgba(0,0,0,0.12)]" />
        <p class="mt-6 font-display text-xl text-ink">Todavía no tienes reservas</p>
        <p class="mt-2 text-sm text-[#6c655c]">Cuando reserves una mesa, la vas a ver aquí.</p>
        <router-link
          to="/reservar"
          class="mt-6 inline-flex items-center justify-center rounded-xl bg-brand-500 px-6 py-3 text-sm font-medium text-ink shadow-[0_8px_20px_rgba(214,164,58,0.35)] transition-all duration-150 hover:-translate-y-0.5 hover:bg-brand-400"
        >
          Reservar una mesa
        </router-link>
      </div>

      <ul v-else class="space-y-4">
        <li v-for="reserva in reservas" :key="reserva.id" class="rounded-2xl border border-[#e2dbcf] bg-white p-5">
          <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-500/15 text-brand-700">
                <CalendarClock :size="18" />
              </span>
              <div>
                <p class="font-display text-base text-ink">
                  Reserva #{{ reserva.id }}<template v-if="reserva.mesa"> · {{ reserva.mesa.nombre }}</template>
                </p>
                <p class="mt-0.5 text-xs text-[#6c655c]">{{ formatoFecha(reserva.fecha_hora) }}</p>
              </div>
            </div>
            <span class="shrink-0 rounded-full px-3 py-1 text-xs font-medium" :class="estadoReservaVisible(reserva).clase">
              {{ estadoReservaVisible(reserva).texto }}
            </span>
          </div>

          <div class="mt-3 flex items-center justify-between border-t border-[#e2dbcf] pt-3 text-sm text-[#6c655c]">
            <span>Depósito: {{ formatoPrecio(reserva.deposito_valor) }}<template v-if="reserva.deposito_reembolsado"> (reembolsado)</template></span>
            <button
              v-if="puedeCancelarReserva(reserva)"
              type="button"
              class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-50"
              @click="abrirCancelarReserva(reserva)"
            >
              <XCircle :size="14" />
              Cancelar reserva
            </button>
            <span
              v-else-if="depositoEnEfectivo(reserva) && ['pendiente_pago', 'confirmada'].includes(reserva.estado)"
              class="text-xs text-[#6c655c]"
            >
              Pagaste el depósito en el local: para cancelarla, llama o pasa por el restaurante.
            </span>
          </div>

          <!-- Mejora (26/10/2026, RF-M05-044): pagar el depósito pendiente sin buscar el correo. -->
          <p v-if="depositoEnRevision(reserva)" class="mt-3 rounded-xl bg-sky-50 px-3 py-2 text-xs text-sky-800">
            <template v-if="reserva.deposito_tiene_comprobante">Recibimos tu comprobante.</template>
            <template v-else>Tu pago quedó en proceso.</template>
            En cuanto lo verifiquemos, confirmamos tu reserva y te avisamos por correo.
          </p>
          <div v-else-if="reserva.puede_pagar" class="mt-3 border-t border-[#e2dbcf] pt-3">
            <p v-if="reserva.deposito_estado === 'rechazado'" class="mb-2 rounded-xl bg-red-50 px-3 py-2 text-xs text-red-700">
              Tu pago anterior no se aprobó<template v-if="reserva.deposito_rechazo_motivo">: <strong>{{ reserva.deposito_rechazo_motivo }}</strong></template>.
              Revisa los datos y vuelve a intentarlo.
            </p>
            <template v-if="enLineaDisponible">
              <button
                v-if="pagoReservaAbierto !== reserva.id"
                type="button"
                class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-brand-500 px-5 py-2.5 text-sm font-semibold text-ink shadow-[0_8px_20px_rgba(214,164,58,0.3)] transition hover:bg-brand-400 active:scale-[0.99] sm:w-auto"
                @click="abrirPagoReserva(reserva)"
              >
                Pagar el depósito de {{ formatoPrecio(reserva.deposito_valor) }}
              </button>
              <template v-else>
                <AvisoDestacado>Tu reserva se confirma en cuanto verifiquemos el pago del depósito.</AvisoDestacado>
                <PagoEnLinea
                  class="mt-3"
                  :config="configPagos"
                  :monto="Number(reserva.deposito_valor)"
                  :enviando="pagandoReserva === reserva.id"
                  :error="errorPagoReserva"
                  :medio-inicial="reserva.deposito_pasarela"
                  concepto="el depósito de"
                  @pagar="pagarReserva(reserva, $event)"
                />
                <button type="button" class="mt-2 text-xs font-medium text-[#6c655c] underline-offset-2 hover:underline" @click="pagoReservaAbierto = null">
                  Pagar después
                </button>
              </template>
            </template>
            <AvisoDestacado v-else tono="atencion">
              Por ahora el depósito no se puede pagar en línea. Escríbenos por WhatsApp y te decimos cómo pagarlo.
            </AvisoDestacado>
          </div>
        </li>
      </ul>
    </div>

    <!-- ---- Modal: cancelar pedido ---- -->
    <div v-if="pedidoACancelar" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
      <div class="w-full max-w-sm rounded-2xl bg-white p-6">
        <h2 class="font-display text-lg text-ink">Cancelar pedido #{{ pedidoACancelar.id }}</h2>
        <p class="mt-1 text-sm text-[#6c655c]">Cuéntanos brevemente por qué cancelas este pedido.</p>
        <textarea v-formato="'texto'" maxlength="255"
          v-model="motivoCancelacion"
          rows="3"
          class="mt-4 w-full rounded-xl border border-[#e2dbcf] px-3 py-2 text-sm focus:border-brand-500 focus:outline-none"
          placeholder="Motivo de la cancelación"
        />
        <p v-if="errorCancelarPedido" class="mt-2 text-xs text-red-700">{{ errorCancelarPedido }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="rounded-lg px-4 py-2 text-sm text-[#6c655c]" @click="cerrarCancelarPedido">
            Volver
          </button>
          <button
            type="button"
            class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition disabled:opacity-60"
            :disabled="cancelandoPedido"
            @click="confirmarCancelarPedido"
          >
            {{ cancelandoPedido ? 'Cancelando…' : 'Sí, cancelar' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ---- Modal: cancelar reserva ---- -->
    <div v-if="reservaACancelar" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
      <div class="w-full max-w-sm rounded-2xl bg-white p-6">
        <h2 class="font-display text-lg text-ink">Cancelar reserva #{{ reservaACancelar.id }}</h2>
        <p class="mt-1 text-sm text-[#6c655c]">
          ¿Confirmas que quieres cancelar esta reserva?
          <template v-if="reservaACancelar.deposito_pagado"> El depósito se reembolsa por completo.</template>
        </p>
        <p v-if="errorCancelarReserva" class="mt-2 text-xs text-red-700">{{ errorCancelarReserva }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="rounded-lg px-4 py-2 text-sm text-[#6c655c]" @click="cerrarCancelarReserva">
            Volver
          </button>
          <button
            type="button"
            class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition disabled:opacity-60"
            :disabled="cancelandoReserva"
            @click="confirmarCancelarReserva"
          >
            {{ cancelandoReserva ? 'Cancelando…' : 'Sí, cancelar' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
