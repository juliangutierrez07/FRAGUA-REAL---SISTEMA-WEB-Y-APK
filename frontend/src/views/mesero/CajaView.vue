<script setup lang="ts">
// M06 — Cobrar del Mesero: RF-M06-003/004, RNF-M06-004 — el Mesero registra todo cobro
// presencial (efectivo/tarjeta/transferencia) sin importar el canal (local, domicilio
// contra entrega o recogida al recoger). Abrir/cerrar el turno de caja y ver sus cifras
// es EXCLUSIVO del Administrador (RN-M06-01, CajaTurnoPolicy) — esta vista no lo toca;
// si no hay turno abierto, el backend rechaza el cobro y acá solo se muestra el motivo.
//
// Fase 1, tarea 3: los pedidos locales se cobran por mesa (RF-M02-L-019/020, HU-M02-006). Cada
// mesa con saldo es una tarjeta; "Cobrar mesa" abre su cuenta completa y reparte el pago entre
// sus pedidos. Los domicilios y las recogidas se siguen cobrando pedido por pedido.
import { computed, onMounted, ref, watch } from 'vue'
import { Banknote, CircleDollarSign, CreditCard, Landmark, Smartphone, Wallet } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import CampoPesos from '@/components/common/CampoPesos.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import BotonComprobante from '@/components/pedidos/BotonComprobante.vue'
import CuentaMesaModal from '@/components/pedidos/CuentaMesaModal.vue'
import RevisarComprobanteModal from '@/components/pagos/RevisarComprobanteModal.vue'
import FotoComprobante from '@/components/pagos/FotoComprobante.vue'
import EfectivoRecibido from '@/components/pagos/EfectivoRecibido.vue'
import ClienteDelCobro from '@/components/pagos/ClienteDelCobro.vue'
import { pedidoService } from '@/services/pedido.service'
import { cajaService, type EstadoCaja } from '@/services/caja.service'
import { pagoService } from '@/services/pago.service'
import { mensajeDeError } from '@/services/errors'
import { metodoPasarela, nombreMedio } from '@/utils/pasarela'
import { TIPOS_PEDIDO_UI, faltaParaFinalizar, formatoHoraPedido, saldoPendiente, titularPedido, totalPagado } from '@/utils/pedidoUi'
import type { Pago, Pedido } from '@/types/models'

const pedidos = ref<Pedido[]>([])
const cargando = ref(true)
const error = ref('')

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

// totalPagado()/saldoPendiente() viven en utils/pedidoUi.ts (réplica del backend: solo
// pagos aprobados y no anulados cuentan, RF-M06-011).

// Pendientes de cobro = activos con saldo. CORREGIDO (Fase 0, 09/10/2026): antes bastaba con
// "no finalizado", porque el pago completo finalizaba el pedido. Ahora un pedido pagado puede
// seguir activo esperando preparación, entrega o recogida (RN-PED-32); ese ya no se cobra.
const pedidosPendientes = computed(() =>
  pedidos.value
    .filter((p) => !['finalizado', 'cancelado'].includes(p.estado) && saldoPendiente(p) > 0)
    .sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime()),
)

// Domicilios y recogidas: uno por uno. Los locales van agrupados por mesa (abajo).
const pedidosRemotosPendientes = computed(() => pedidosPendientes.value.filter((p) => p.tipo !== 'local' || !p.mesa_id))

const mesasPorCobrar = computed(() => {
  const porMesa = new Map<number, { id: number; nombre: string; saldo: number; pedidos: number; desde: string }>()
  for (const p of pedidosPendientes.value) {
    if (p.tipo !== 'local' || !p.mesa_id) continue
    const actual = porMesa.get(p.mesa_id) ?? { id: p.mesa_id, nombre: p.mesa?.nombre ?? `Mesa ${p.mesa_id}`, saldo: 0, pedidos: 0, desde: p.created_at }
    actual.saldo += saldoPendiente(p)
    actual.pedidos += 1
    porMesa.set(p.mesa_id, actual)
  }
  return [...porMesa.values()].sort((a, b) => a.nombre.localeCompare(b.nombre, 'es', { numeric: true }))
})

const cuentaDe = ref<number | null>(null)

// CORREGIDO (29/09/2026): un pago en_linea (Nequi/Bancolombia) nace en estado "pendiente" y
// se queda así para siempre si nadie lo aprueba/rechaza — PedidoService::confirmar() bloquea
// confirmar un pedido de domicilio/recogida mientras tenga uno sin aprobar (RN-M06-05). Sin
// esta sección no había ninguna pantalla desde donde el Mesero pudiera hacerlo (antes solo
// era posible llamando la API directo). Se arma aquí mismo, del mismo listado de pedidos ya
// cargado — no hace falta un endpoint nuevo para esto.
const pagosEnLineaPendientes = computed(() =>
  pedidos.value.flatMap((pedido) =>
    (pedido.pagos ?? [])
      .filter((pago) => pago.metodo === 'en_linea' && pago.estado === 'pendiente' && !pago.anulado)
      .map((pago) => ({ pago, pedido })),
  ),
)

// ---- Fase 7, tarea 4 (RF-M06-023, HU-M06-009): estado de la caja de hoy ----
// El Mesero ve si la caja está abierta y el efectivo que debería haber. Sin caja abierta no se
// puede cobrar (RN-M06-02): los botones de cobro se apagan y se explica a quién acudir.
const caja = ref<EstadoCaja | null>(null)
const cajaAbierta = computed(() => caja.value === null || caja.value.estado === 'abierta')

async function cargarCaja() {
  try {
    const { data } = await cajaService.estado()
    caja.value = data
  } catch {
    caja.value = null // si no se puede consultar, el servidor igual valida al cobrar
  }
}

function horaCorta(valor: string | null) {
  return valor ? new Date(valor).toLocaleTimeString('es-CO', { timeZone: 'America/Bogota', hour: 'numeric', minute: '2-digit' }) : ''
}

async function cargar() {
  void cargarCaja()
  cargando.value = true
  error.value = ''
  try {
    // Solo activos (los pendientes de cobro): sin esto, la lista paginada de 20 podía dejar
    // fuera pedidos por cobrar cuando había muchos pedidos recientes ya finalizados.
    const { data } = await pedidoService.index({ activos: true, per_page: 100 })
    pedidos.value = data.data
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar los pedidos.')
  } finally {
    cargando.value = false
  }
}

// RF-M06-004, RN-M06-06: el domicilio contra entrega se cobra después de confirmar la entrega.
function esperaEntrega(pedido: Pedido): boolean {
  return pedido.tipo === 'domicilio' && !pedido.entregado_at
}

const aviso = ref('')
// Fase 5, tarea 1 (RF-M06-009/010): el pedido cuyo cobro completó el pago, para imprimir su comprobante.
const recienPagado = ref<Pedido | null>(null)
const entregando = ref<number | null>(null)

async function confirmarEntrega(pedido: Pedido) {
  entregando.value = pedido.id
  error.value = ''
  aviso.value = ''
  try {
    const { data } = await pedidoService.confirmarEntrega(pedido.id)
    reemplazarPedido(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos confirmar la entrega.')
  } finally {
    entregando.value = null
  }
}

function reemplazarPedido(actualizado: Pedido) {
  const i = pedidos.value.findIndex((p) => p.id === actualizado.id)
  if (i !== -1) pedidos.value[i] = actualizado
}

// ---- Registrar cobro (RF-M06-003/004) ----
const pedidoACobrar = ref<Pedido | null>(null)
const metodo = ref<'efectivo' | 'tarjeta' | 'transferencia'>('efectivo')
const monto = ref(0)
const errorCobro = ref('')
const cobrando = ref(false)
// Mejora (26/10/2026, RF-M06-037): foto del comprobante (obligatoria en transferencia, opcional en tarjeta).
const fotoCobro = ref<File | null>(null)
// Mejora (26/10/2026, RF-M06-036): en efectivo, con cuánto paga el cliente, para mostrar el cambio.
const recibido = ref<number | null>(null)
const cambioCobro = computed(() => (metodo.value === 'efectivo' && recibido.value ? recibido.value - (monto.value || 0) : null))
watch([metodo, monto, recibido, fotoCobro], () => (errorCobro.value = ''))

const METODOS: { v: 'efectivo' | 'tarjeta' | 'transferencia'; t: string; icono: typeof Banknote }[] = [
  { v: 'efectivo', t: 'Efectivo', icono: Banknote },
  { v: 'tarjeta', t: 'Tarjeta', icono: CreditCard },
  { v: 'transferencia', t: 'Transferencia', icono: Landmark },
]

function abrirCobro(pedido: Pedido) {
  pedidoACobrar.value = pedido
  metodo.value = 'efectivo'
  monto.value = saldoPendiente(pedido)
  errorCobro.value = ''
  fotoCobro.value = null
  recibido.value = null
}

function cerrarCobro() {
  pedidoACobrar.value = null
}

// Fidelización (27/10/2026, RF-M06-040): el pedido quedó en el historial del cliente.
function clienteAsignado(cliente: { id: number; nombre: string }) {
  if (!pedidoACobrar.value) return
  const actualizado = { ...pedidoACobrar.value, cliente_id: cliente.id, cliente: { ...(pedidoACobrar.value.cliente ?? {}), ...cliente } as Pedido['cliente'] }
  pedidoACobrar.value = actualizado
  reemplazarPedido(actualizado)
}

async function confirmarCobro() {
  if (!pedidoACobrar.value) return
  if (!(monto.value > 0)) {
    errorCobro.value = 'Ingresa un monto mayor a 0.'
    return
  }
  if (metodo.value === 'transferencia' && !fotoCobro.value) {
    errorCobro.value = 'Toma la foto del comprobante de la transferencia.'
    return
  }
  if (cambioCobro.value !== null && cambioCobro.value < 0) {
    errorCobro.value = `Lo que entrega el cliente no alcanza: faltan ${formatoPrecio(-cambioCobro.value)}.`
    return
  }
  const cambio = cambioCobro.value
  cobrando.value = true
  errorCobro.value = ''
  try {
    const { data } = await pedidoService.cobrar(pedidoACobrar.value.id, {
      metodo: metodo.value,
      monto: monto.value,
      comprobante: metodo.value === 'efectivo' ? null : fotoCobro.value,
    })
    reemplazarPedido(data)
    pedidoACobrar.value = null
    recienPagado.value = data.comprobante ? data : null
    // RN-PED-32: un cobro completo no siempre finaliza; se le dice al Mesero qué sigue.
    const falta = faltaParaFinalizar(data)
    if (data.estado === 'finalizado') aviso.value = `Cobro registrado. ${titularPedido(data)} quedó finalizado.`
    else if (saldoPendiente(data) === 0 && falta) aviso.value = `Pago completo. ${falta} Hazlo en Pedidos.`
    else aviso.value = 'Cobro registrado.'
    if (cambio) aviso.value += ` Entrega ${formatoPrecio(cambio)} de cambio.`
    void cargarCaja()
  } catch (e) {
    // Si no hay turno de caja abierto, el backend responde con ese motivo exacto
    // (RN-M06-03) — el Mesero no puede abrirlo, así que solo puede avisarle al
    // Administrador; no hay una acción que esta vista pueda ofrecer para resolverlo.
    errorCobro.value = mensajeDeError(e, 'No pudimos registrar el cobro.')
  } finally {
    cobrando.value = false
  }
}

// ---- Aprobar/rechazar un pago en línea (RN-M06-05) ----
const procesandoPagoId = ref<number | null>(null)
const errorPago = ref('')

async function aprobarPago(pago: Pago) {
  procesandoPagoId.value = pago.id
  errorPago.value = ''
  try {
    await pagoService.confirmarEnLinea(pago.id)
    // Aprobar puede finalizar el pedido solo (si esto lo deja pagoCompleto()) — se
    // recarga todo en vez de parchar el pedido local, para que ambas listas (esta y
    // "pendientes de cobro") reflejen el nuevo estado real.
    await cargar()
  } catch (e) {
    errorPago.value = mensajeDeError(e, 'No pudimos aprobar el pago.')
  } finally {
    procesandoPagoId.value = null
  }
}

// Pago directo (22/10/2026, RF-M06-032/033): con captura, se revisa en una ventana y se aprueba o se
// rechaza con motivo (el Cliente lo ve); sin captura, «Rechazar» abre la misma ventana para el motivo.
const revision = ref<{ pago: Pago; pedido: Pedido; rechazar: boolean } | null>(null)
const errorRevision = ref('')

function abrirRevision(pago: Pago, pedido: Pedido, rechazar = false) {
  errorRevision.value = ''
  revision.value = { pago, pedido, rechazar }
}

async function aprobarRevisado() {
  if (!revision.value) return
  const { pago } = revision.value
  procesandoPagoId.value = pago.id
  errorRevision.value = ''
  try {
    await pagoService.confirmarEnLinea(pago.id)
    revision.value = null
    await cargar()
  } catch (e) {
    errorRevision.value = mensajeDeError(e, 'No pudimos aprobar el pago.')
  } finally {
    procesandoPagoId.value = null
  }
}

async function rechazarPago(motivo: string) {
  if (!revision.value) return
  const { pago } = revision.value
  procesandoPagoId.value = pago.id
  errorRevision.value = ''
  try {
    await pagoService.rechazarEnLinea(pago.id, motivo)
    revision.value = null
    await cargar()
  } catch (e) {
    errorRevision.value = mensajeDeError(e, 'No pudimos rechazar el pago.')
  } finally {
    procesandoPagoId.value = null
  }
}

onMounted(cargar)
</script>

<template>
  <div>
    <PageHeader
      eyebrow="COBRO"
      titulo="Cobrar"
      descripcion="Pedidos pendientes de pago — local, domicilio y recogida. El cobro se registra siempre acá, sin importar el canal."
    />

    <!-- Fase 7, tarea 4 (RF-M06-023, HU-M06-009): estado de la caja de hoy. -->
    <div
      v-if="caja"
      class="mt-4 flex flex-col gap-1 rounded-xl px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between"
      :class="caja.estado === 'abierta' ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900'"
      role="status"
    >
      <p v-if="caja.estado === 'abierta'">
        <span class="font-semibold">Caja abierta</span>
        <template v-if="caja.abierta_at"> desde las {{ horaCorta(caja.abierta_at) }}</template>
        <template v-if="caja.abierta_por"> · la abrió {{ caja.abierta_por }}</template>
      </p>
      <p v-else-if="caja.estado === 'sin_abrir'">
        <span class="font-semibold">La caja de hoy no está abierta.</span> No se puede cobrar hasta que el Administrador
        la abra.
      </p>
      <p v-else>
        <span class="font-semibold">La caja de hoy ya se cerró</span>
        <template v-if="caja.cerrada_at"> a las {{ horaCorta(caja.cerrada_at) }}</template>.
        Un cobro nuevo ya no entra a la caja: habla con el Administrador.
      </p>
      <p v-if="caja.estado === 'abierta' && caja.efectivo_esperado !== null" class="font-semibold">
        Efectivo esperado: {{ formatoPrecio(caja.efectivo_esperado) }}
      </p>
    </div>

    <p v-if="error" class="mt-4 alert-error">{{ error }}</p>
    <div v-if="aviso" class="mt-4 flex flex-col gap-2 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800 sm:flex-row sm:items-center sm:justify-between">
      <p>{{ aviso }}</p>
      <BotonComprobante v-if="recienPagado" :pedido="recienPagado" personal size="sm" />
    </div>

    <div v-if="cargando" class="loading-state">Cargando pedidos…</div>

    <template v-else>
      <!-- Pagos en línea por revisar: el Cliente ya intentó pagar por Nequi/Bancolombia,
           falta que el Mesero apruebe o rechace ese pago antes de que el pedido pueda
           seguir (RN-M06-05) — ver comentario de pagosEnLineaPendientes arriba. -->
      <section v-if="pagosEnLineaPendientes.length" class="mt-7">
        <h2 class="flex items-center gap-2 text-sm font-semibold text-ink">
          <span class="flex size-7 items-center justify-center rounded-lg bg-sky-50 text-sky-600">
            <Smartphone :size="15" />
          </span>
          Pagos en línea por revisar
          <span class="badge bg-sky-50 text-sky-700 ring-sky-600/20">{{ pagosEnLineaPendientes.length }}</span>
        </h2>
        <p class="mt-1.5 text-xs text-gray-500">
          Si el Cliente subió la captura de la transferencia, revísala y confirma en la app del banco que el dinero
          llegó antes de aprobar. Los que pasan por la pasarela (Wompi) se confirman solos; apruébalos a mano solo como
          respaldo.
        </p>

        <p v-if="errorPago" class="mt-3 alert-error">{{ errorPago }}</p>

        <ul class="mt-3 space-y-3">
          <li
            v-for="{ pago, pedido } in pagosEnLineaPendientes"
            :key="pago.id"
            class="card flex flex-col gap-3 border-l-4 border-l-sky-400 p-4 sm:flex-row sm:items-center sm:justify-between"
          >
            <div class="min-w-0">
              <p class="truncate text-[15px] font-semibold text-ink">{{ titularPedido(pedido) }}</p>
              <p class="mt-0.5 text-xs text-gray-500">
                #{{ pedido.id }} · {{ TIPOS_PEDIDO_UI[pedido.tipo].texto }} ·
                {{ nombreMedio(pago.pasarela) }}
              </p>
              <p class="mt-1.5 font-sans text-xl font-semibold text-ink">{{ formatoPrecio(pago.monto) }}</p>
              <!-- Fase 6, tarea 1: el intento en la pasarela, si lo hubo. -->
              <p v-if="pago.transaccion_pasarela" class="mt-1 text-xs text-sky-700">
                En proceso en Wompi<template v-if="pago.transaccion_pasarela.metodo_pago"> · {{ metodoPasarela(pago.transaccion_pasarela.metodo_pago) }}</template>
                · se confirma solo
              </p>
              <p v-if="pago.tiene_comprobante" class="mt-1 text-xs font-medium text-sky-700">
                Captura adjunta<template v-if="pago.referencia_cliente"> · ref. <span class="font-mono">{{ pago.referencia_cliente }}</span></template>
              </p>
            </div>
            <div v-if="pago.tiene_comprobante" class="sm:shrink-0">
              <BaseButton class="w-full" :disabled="procesandoPagoId === pago.id" @click="abrirRevision(pago, pedido)">
                Revisar comprobante
              </BaseButton>
            </div>
            <div v-else class="grid grid-cols-2 gap-2 sm:flex sm:shrink-0">
              <BaseButton variant="ghost" :disabled="procesandoPagoId === pago.id" @click="abrirRevision(pago, pedido, true)">
                {{ pago.transaccion_pasarela ? 'Rechazar a mano' : 'Rechazar' }}
              </BaseButton>
              <BaseButton
                :variant="pago.transaccion_pasarela ? 'secondary' : 'primary'"
                :disabled="procesandoPagoId === pago.id"
                @click="aprobarPago(pago)"
              >
                {{ procesandoPagoId === pago.id ? 'Procesando…' : pago.transaccion_pasarela ? 'Aprobar a mano' : 'Aprobar pago' }}
              </BaseButton>
            </div>
          </li>
        </ul>
      </section>

      <!-- Mesas con saldo: se cobran completas desde su cuenta -->
      <section v-if="mesasPorCobrar.length" class="mt-7">
        <h2 class="text-sm font-semibold text-ink">Mesas por cobrar</h2>
        <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <li v-for="mesa in mesasPorCobrar" :key="mesa.id" class="card flex items-center justify-between gap-3 p-4">
            <div class="min-w-0">
              <p class="truncate text-[15px] font-semibold text-ink">{{ mesa.nombre }}</p>
              <p class="mt-0.5 text-xs text-gray-500">
                {{ mesa.pedidos }} {{ mesa.pedidos === 1 ? 'pedido' : 'pedidos' }} por pagar
              </p>
              <p class="mt-1 font-sans text-xl font-semibold text-ink">{{ formatoPrecio(mesa.saldo) }}</p>
            </div>
            <BaseButton variant="primary" class="shrink-0" :disabled="!cajaAbierta" @click="cuentaDe = mesa.id">Cobrar mesa</BaseButton>
          </li>
        </ul>
      </section>

      <div v-if="!pedidosPendientes.length" class="mt-8 empty-state">
        <Wallet :size="32" class="mx-auto text-gray-300" />
        <p class="mt-3 text-sm font-medium text-ink">No hay pedidos pendientes de cobro</p>
        <p class="mt-1 text-xs text-gray-500">Todo al día por ahora.</p>
      </div>

      <template v-else>
        <h2
          v-if="pedidosRemotosPendientes.length && (pagosEnLineaPendientes.length || mesasPorCobrar.length)"
          class="mt-8 text-sm font-semibold text-ink"
        >
          Domicilios y recogidas por cobrar
        </h2>
        <ul class="space-y-3" :class="pagosEnLineaPendientes.length || mesasPorCobrar.length ? 'mt-3' : 'mt-6'">
          <li
            v-for="pedido in pedidosRemotosPendientes"
            :key="pedido.id"
            class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
          >
            <div class="flex min-w-0 items-center gap-3">
              <span
                class="flex size-11 shrink-0 items-center justify-center rounded-xl"
                :class="TIPOS_PEDIDO_UI[pedido.tipo].clase"
              >
                <component :is="TIPOS_PEDIDO_UI[pedido.tipo].icono" :size="21" />
              </span>
              <div class="min-w-0">
                <p class="truncate text-[15px] font-semibold text-ink">{{ titularPedido(pedido) }}</p>
                <p class="mt-0.5 truncate text-xs text-gray-500">
                  #{{ pedido.id }} · {{ TIPOS_PEDIDO_UI[pedido.tipo].texto }} · {{ formatoHoraPedido(pedido.created_at) }}
                </p>
                <p class="mt-0.5 text-xs text-gray-500">
                  Total {{ formatoPrecio(pedido.total) }}
                  <span v-if="Number(pedido.descuento ?? 0) > 0" class="text-emerald-700">(con −{{ formatoPrecio(pedido.descuento ?? 0) }} de descuento)</span>
                  <template v-if="totalPagado(pedido) > 0">
                    · pagado {{ formatoPrecio(totalPagado(pedido)) }} · saldo
                    <span class="font-semibold text-ink">{{ formatoPrecio(saldoPendiente(pedido)) }}</span>
                  </template>
                </p>
              </div>
            </div>
            <!-- Domicilio contra entrega: primero se confirma la entrega (RF-M06-004). -->
            <BaseButton
              v-if="esperaEntrega(pedido) && pedido.estado === 'preparando'"
              variant="secondary"
              class="w-full sm:w-auto"
              :disabled="entregando === pedido.id"
              @click="confirmarEntrega(pedido)"
            >
              {{ entregando === pedido.id ? 'Confirmando…' : 'Confirmar entrega' }}
            </BaseButton>
            <p v-else-if="esperaEntrega(pedido)" class="text-xs text-gray-500 sm:max-w-44 sm:text-right">
              Se cobra al confirmar la entrega, cuando el pedido esté en preparación.
            </p>
            <BaseButton v-else variant="primary" class="w-full sm:w-auto" :disabled="!cajaAbierta" @click="abrirCobro(pedido)">
              <CircleDollarSign :size="17" />
              Cobrar {{ formatoPrecio(saldoPendiente(pedido)) }}
            </BaseButton>
          </li>
        </ul>
      </template>
    </template>

    <CuentaMesaModal :mesa-id="cuentaDe" :puede-cobrar="true" @cerrar="cuentaDe = null" @cobrado="cargar" />

    <!-- Registrar cobro: confirmación corta (método + monto) — no es un flujo de
         selección de productos, así que el modal acá sí es apropiado (mismo criterio
         que "Cancelar pedido" en PedidosView.vue). En teléfono es una hoja inferior. -->
    <BaseModal :model-value="!!pedidoACobrar" title="Registrar cobro" @update:model-value="cerrarCobro">
      <template v-if="pedidoACobrar">
        <div class="rounded-2xl bg-ink-900 px-5 py-4 text-white">
          <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-white/55">
            {{ titularPedido(pedidoACobrar) }} · #{{ pedidoACobrar.id }}
          </p>
          <p class="mt-1 text-xs text-white/55">Saldo pendiente</p>
          <p class="font-display text-3xl font-medium leading-tight text-brand-400">
            {{ formatoPrecio(saldoPendiente(pedidoACobrar)) }}
          </p>
        </div>

        <p class="field-label mt-5">Método de pago</p>
        <div class="grid grid-cols-3 gap-2">
          <button
            v-for="opcion in METODOS"
            :key="opcion.v"
            type="button"
            class="flex flex-col items-center gap-1.5 rounded-xl border px-2 py-3 text-xs font-semibold transition active:scale-[0.97]"
            :class="
              metodo === opcion.v
                ? 'border-brand-500 bg-brand-50 text-brand-700 shadow-[0_0_0_3px_rgba(214,164,58,0.15)]'
                : 'border-gray-200 text-gray-600 hover:border-brand-300'
            "
            @click="metodo = opcion.v"
          >
            <component :is="opcion.icono" :size="22" />
            {{ opcion.t }}
          </button>
        </div>

        <label class="mt-5 block">
          <span class="field-label">Monto a cobrar</span>
          <span class="relative block">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-gray-400">$</span>
            <CampoPesos
              v-model="monto"
              class="field pl-9 text-lg font-semibold sm:text-base"
            />
          </span>
        </label>
        <p class="mt-1.5 text-xs text-gray-400">
          Puedes cobrar menos del saldo si es un pago mixto (ej. parte en efectivo, parte en tarjeta).
        </p>
        <EfectivoRecibido v-if="metodo === 'efectivo'" v-model="recibido" :total="monto || 0" />
        <FotoComprobante v-else v-model="fotoCobro" :obligatoria="metodo === 'transferencia'" />
        <ClienteDelCobro :pedidos="[pedidoACobrar]" @asignado="clienteAsignado" />

        <p v-if="errorCobro" class="mt-3 alert-error">{{ errorCobro }}</p>

        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" @click="cerrarCobro">Volver</BaseButton>
          <BaseButton variant="primary" :disabled="cobrando" @click="confirmarCobro">
            {{ cobrando ? 'Registrando…' : 'Registrar cobro' }}
          </BaseButton>
        </div>
      </template>
    </BaseModal>

    <RevisarComprobanteModal
      :abierto="!!revision"
      :titulo="revision ? `Comprobante · pedido #${revision.pedido.id}` : ''"
      :monto="Number(revision?.pago.monto ?? 0)"
      :medio="revision?.pago.pasarela"
      :referencia="revision?.pago.referencia_cliente"
      :cargar="revision?.pago.tiene_comprobante ? () => pagoService.comprobante(revision!.pago.id) : null"
      :rechazar="revision?.rechazar"
      :procesando="!!revision && procesandoPagoId === revision.pago.id"
      :error="errorRevision"
      @cerrar="revision = null"
      @aprobar="aprobarRevisado"
      @rechazar="rechazarPago"
    />
  </div>
</template>
