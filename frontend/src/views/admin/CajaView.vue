<script setup lang="ts">
// M06 — Caja del Administrador. RN-M06-01: abrir/cerrar un turno y ver sus cifras es
// EXCLUSIVO del Administrador (el Mesero solo cobra, ver mesero/CajaView.vue). Sin un
// turno abierto el Mesero no puede registrar cobros (RN-M06-03: un solo turno por día),
// así que esta vista también cubre abrir/cerrar — si no, no habría ninguna forma de
// hacerlo desde la app a pesar de que CajaService ya lo soporta del todo.
import { computed, onMounted, ref } from 'vue'
import { Banknote, Camera, CreditCard, Landmark, RefreshCw, Smartphone, Wallet } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import CampoPesos from '@/components/common/CampoPesos.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import PagosPasarelaPorRevisar from '@/components/caja/PagosPasarelaPorRevisar.vue'
import ResumenTurno from '@/components/caja/ResumenTurno.vue'
import { cajaService } from '@/services/caja.service'
import { pagoService } from '@/services/pago.service'
import { mensajeDeError } from '@/services/errors'
import { ESTADO_REEMBOLSO, ESTADO_TRANSACCION, metodoPasarela, nombreMedio } from '@/utils/pasarela'
import type { CajaTurno, Pago } from '@/types/models'

function formatoPrecio(valor: number | null) {
  return Number(valor ?? 0).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

// Para contado/diferencia, que son null mientras el turno sigue abierto (solo se llenan
// al cerrar) — mostrar "$ 0" ahí sugeriría que ya se cerró en $0, así que se usa "—".
function formatoPrecioONulo(valor: number | null) {
  return valor === null ? '—' : formatoPrecio(valor)
}

function formatoFecha(valor: string) {
  // "fecha" llega como datetime ISO en UTC medianoche (cast 'date' de Laravel), p.ej.
  // "2026-09-30T00:00:00.000000Z". OJO: new Date(valor).toLocaleDateString() convierte a
  // la zona horaria LOCAL del navegador — en Colombia (UTC-5) esa medianoche UTC cae en
  // "29/09 7pm", así que el día se ve un día atrás (mismo tipo de bug de zona horaria que
  // CajaService::ZONA_HORARIA_NEGOCIO corrige en el backend). Se toman los primeros 10
  // caracteres ("YYYY-MM-DD") directo del string, sin pasar por Date(), para no arrastrar
  // ese corrimiento.
  const [anio, mes, dia] = valor.slice(0, 10).split('-')
  return `${dia}/${mes}/${anio}`
}

function formatoFechaHora(valor: string | null) {
  if (!valor) return '—'
  return new Date(valor).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' })
}

const METODOS: Record<string, { texto: string; icono: typeof Banknote }> = {
  efectivo: { texto: 'Efectivo', icono: Banknote },
  tarjeta: { texto: 'Tarjeta', icono: CreditCard },
  transferencia: { texto: 'Transferencia', icono: Landmark },
  en_linea: { texto: 'En línea', icono: Smartphone },
}

const ESTADOS_PAGO: Record<Pago['estado'], string> = {
  pendiente: 'bg-yellow-100 text-yellow-800',
  aprobado: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  rechazado: 'bg-red-50 text-red-700 ring-red-600/20',
}

// ---- Turno actual (abrir/cerrar) ----
const turnoActual = ref<CajaTurno | null>(null)
const cargandoActual = ref(true)
const errorActual = ref('')

async function cargarTurnoActual() {
  cargandoActual.value = true
  errorActual.value = ''
  try {
    const { data } = await cajaService.turnoActual()
    turnoActual.value = data
  } catch (e) {
    errorActual.value = mensajeDeError(e, 'No pudimos cargar el turno de hoy.')
  } finally {
    cargandoActual.value = false
  }
}

// Mejora (16/10/2026): «Actualizar» trae los cobros nuevos del Mesero sin recargar la página ni
// mostrar el turno como si se estuviera cargando de cero.
const actualizando = ref(false)
async function actualizarResumen() {
  actualizando.value = true
  errorActual.value = ''
  try {
    const { data } = await cajaService.turnoActual()
    turnoActual.value = data
  } catch (e) {
    errorActual.value = mensajeDeError(e, 'No pudimos actualizar el resumen.')
  } finally {
    actualizando.value = false
  }
}

function horaCorta(valor: string) {
  return new Date(valor).toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit' })
}

const modalAbrirOpen = ref(false)
const baseInicial = ref(0)
const errorAbrir = ref('')
const abriendo = ref(false)

function abrirModalAbrir() {
  baseInicial.value = 0
  errorAbrir.value = ''
  modalAbrirOpen.value = true
}

async function confirmarAbrir() {
  abriendo.value = true
  errorAbrir.value = ''
  try {
    const { data } = await cajaService.abrir({ base_inicial: baseInicial.value })
    turnoActual.value = data
    modalAbrirOpen.value = false
    await cargarHistorial()
  } catch (e) {
    errorAbrir.value = mensajeDeError(e, 'No pudimos abrir el turno.')
  } finally {
    abriendo.value = false
  }
}

const modalCerrarOpen = ref(false)
const efectivoContado = ref(0)
const justificacionDescuadre = ref('')
const errorCerrar = ref('')
const cerrando = ref(false)

async function abrirModalCerrar() {
  if (!turnoActual.value) return
  // Mejora (26/10/2026, RF-M06-038): se trae el turno al día para ver qué pedidos faltan por finalizar.
  await actualizarResumen()
  if (!turnoActual.value) return
  efectivoContado.value = Number(turnoActual.value.efectivo_esperado)
  justificacionDescuadre.value = ''
  errorCerrar.value = ''
  modalCerrarOpen.value = true
}

// Mejora (26/10/2026, RF-M06-038, RN-M06-20): la caja solo cierra con todos los pedidos finalizados o cancelados.
const pedidosSinFinalizar = computed(() => turnoActual.value?.pedidos_sin_finalizar ?? [])
const ESTADO_TEXTO: Record<string, string> = { pendiente: 'Pendiente', confirmado: 'Confirmado', preparando: 'En preparación' }

async function confirmarCerrar() {
  if (!turnoActual.value) return
  cerrando.value = true
  errorCerrar.value = ''
  try {
    await cajaService.cerrar(turnoActual.value.id, {
      efectivo_contado: efectivoContado.value,
      justificacion_descuadre: justificacionDescuadre.value.trim() || undefined,
    })
    turnoActual.value = null
    modalCerrarOpen.value = false
    await cargarHistorial()
  } catch (e) {
    // Si hay descuadre y no se dio justificación, el backend responde 422 (RF-M06-015/016)
    // — se muestra el motivo exacto para que el Administrador complete el campo.
    errorCerrar.value = mensajeDeError(e, 'No pudimos cerrar el turno.')
  } finally {
    cerrando.value = false
  }
}

// ---- Historial de turnos ----
const turnos = ref<CajaTurno[]>([])
const paginaActual = ref(1)
const ultimaPagina = ref(1)
const total = ref(0)
const cargandoHistorial = ref(true)
const errorHistorial = ref('')

async function cargarHistorial(page = 1) {
  cargandoHistorial.value = true
  errorHistorial.value = ''
  try {
    const { data } = await cajaService.index(page)
    turnos.value = data.data
    paginaActual.value = data.current_page
    ultimaPagina.value = data.last_page
    total.value = data.total
  } catch (e) {
    errorHistorial.value = mensajeDeError(e, 'No pudimos cargar el historial de turnos.')
  } finally {
    cargandoHistorial.value = false
  }
}

const contadorTexto = computed(() => (total.value === 1 ? '1 turno' : `${total.value} turnos`))

// Mejora (26/10/2026, RF-M06-037): la foto del comprobante que tomó el Mesero al cobrar.
const fotoPago = ref<{ pago: Pago; url: string } | null>(null)
const errorFoto = ref('')
async function verFoto(pago: Pago) {
  errorFoto.value = ''
  try {
    const { data } = await pagoService.comprobante(pago.id)
    fotoPago.value = { pago, url: URL.createObjectURL(data) }
  } catch (e) {
    errorFoto.value = mensajeDeError(e, 'No pudimos abrir la foto del comprobante.')
  }
}
function cerrarFoto() {
  if (fotoPago.value) URL.revokeObjectURL(fotoPago.value.url)
  fotoPago.value = null
}

// ---- Detalle de un turno (sus pagos) ----
const turnoDetalle = ref<CajaTurno | null>(null)
const cargandoDetalle = ref(false)
const errorDetalle = ref('')

async function verDetalle(turno: CajaTurno) {
  turnoDetalle.value = null
  cargandoDetalle.value = true
  errorDetalle.value = ''
  try {
    const { data } = await cajaService.show(turno.id)
    turnoDetalle.value = data
  } catch (e) {
    errorDetalle.value = mensajeDeError(e, 'No pudimos cargar el detalle del turno.')
  } finally {
    cargandoDetalle.value = false
  }
}

function cerrarDetalle() {
  avisoAnulacion.value = ''
  turnoDetalle.value = null
}

// ---- Anular un pago (control financiero exclusivo del Administrador) ----
const pagoAAnular = ref<Pago | null>(null)
const motivoAnular = ref('')
const tipoReverso = ref<'anulacion' | 'reembolso'>('anulacion')
const passwordConfirmacion = ref('')
const errorAnular = ref('')
const anulando = ref(false)
// 22/10/2026 (RN-M06-15): el pedido finalizado que quedó con saldo vuelve a cobro; se avisa aquí.
const avisoAnulacion = ref('')

// RF-M06-019: si la caja de ese día ya cerró, anular exige autorización adicional (la
// contraseña del Administrador) y queda como ajuste extraordinario.
const cajaDelPagoCerrada = computed(() => turnoDetalle.value?.estado === 'cerrada')

const TIPOS_REVERSO = [
  { v: 'anulacion', t: 'Anulación' },
  { v: 'reembolso', t: 'Reembolso' },
] as const

function abrirAnular(pago: Pago) {
  pagoAAnular.value = pago
  motivoAnular.value = ''
  tipoReverso.value = 'anulacion'
  passwordConfirmacion.value = ''
  errorAnular.value = ''
}

function cerrarAnular() {
  pagoAAnular.value = null
}

async function confirmarAnular() {
  if (!pagoAAnular.value) return
  if (!motivoAnular.value.trim()) {
    errorAnular.value = 'Ingresa el motivo de la anulación.'
    return
  }
  if (cajaDelPagoCerrada.value && !passwordConfirmacion.value) {
    errorAnular.value = 'La caja de ese día ya cerró: confirma tu contraseña para autorizar el ajuste.'
    return
  }
  anulando.value = true
  errorAnular.value = ''
  try {
    const { data: anulado } = await pagoService.anular(pagoAAnular.value.id, {
      motivo: motivoAnular.value.trim(),
      tipo: tipoReverso.value,
      password_confirmacion: cajaDelPagoCerrada.value ? passwordConfirmacion.value : undefined,
    })
    avisoAnulacion.value = anulado.pedido_reabierto
      ? `El pedido #${anulado.pedido_reabierto.pedido_id} volvió a cobro: le falta ${formatoPrecio(Number(anulado.pedido_reabierto.saldo))}. El Mesero lo cobra desde Pedidos.`
      : ''
    pagoAAnular.value = null
    // PagoController::anular responde el Pago "a secas" (sin pedido/registradoPorUsuario/
    // anuladoPorUsuario cargados) — parchar el array local con eso haría desaparecer "cobrado
    // por" de la fila. Se recarga el detalle completo (show(), que sí carga todo) en vez de
    // parchar, y de paso el historial para reflejar el efectivo_esperado recalculado
    // (CajaService::recalcularEfectivoEsperado, si el pago anulado era en efectivo).
    const turnoId = turnoDetalle.value?.id
    if (turnoId) {
      const { data } = await cajaService.show(turnoId)
      turnoDetalle.value = data
    }
    await cargarHistorial(paginaActual.value)
  } catch (e) {
    errorAnular.value = mensajeDeError(e, 'No pudimos anular el pago.')
  } finally {
    anulando.value = false
  }
}

onMounted(() => {
  cargarTurnoActual()
  cargarHistorial()
})
</script>

<template>
  <div>
    <PageHeader
      eyebrow="FINANZAS"
      titulo="Caja"
      descripcion="Historial de turnos, detalle de pagos y anulación de pagos."
    />

    <!-- Fase 6, tarea 1 (D4): estado de la pasarela y pagos en línea por revisar. -->
    <PagosPasarelaPorRevisar />

    <!-- Turno de hoy: abrir si no hay uno, cerrar si está abierto (RN-M06-01/03) -->
    <section
      class="mt-7 overflow-hidden rounded-2xl p-5 sm:p-6"
      :class="turnoActual ? 'bg-ink-900 text-white shadow-pop' : 'card'"
    >
      <p v-if="errorActual" class="alert-error mb-4">{{ errorActual }}</p>

      <div v-if="cargandoActual" class="flex items-center gap-3 text-sm" :class="turnoActual ? 'text-white/60' : 'text-gray-500'">
        <span class="size-4 animate-spin rounded-full border-2 border-gray-300 border-t-brand-500" />
        Cargando turno de hoy…
      </div>

      <div v-else-if="!turnoActual" class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3.5">
          <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-gray-100 text-gray-500">
            <Wallet :size="22" />
          </span>
          <div>
            <p class="text-[15px] font-semibold text-ink">No hay un turno de caja abierto hoy</p>
            <p class="mt-0.5 text-xs text-gray-500">El Mesero no puede registrar cobros hasta que se abra el turno.</p>
          </div>
        </div>
        <BaseButton v-escritura variant="primary" @click="abrirModalAbrir">Abrir turno</BaseButton>
      </div>

      <div v-else class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-white/55">
            Turno de hoy
            <span class="badge bg-emerald-500/15 text-emerald-300 ring-emerald-400/30">
              <span class="size-1.5 rounded-full bg-emerald-400" />Abierta
            </span>
          </p>
          <p class="mt-2 text-xs text-white/55">Efectivo esperado en caja</p>
          <p class="font-display text-3xl font-medium leading-tight text-brand-400">
            {{ formatoPrecio(turnoActual.efectivo_esperado) }}
          </p>
          <p class="mt-2 text-xs text-white/55">
            Base inicial {{ formatoPrecio(turnoActual.base_inicial) }} · abierto por
            {{ turnoActual.abierto_por_usuario?.nombre ?? '—' }}
          </p>
        </div>
        <BaseButton v-escritura variant="danger" @click="abrirModalCerrar">Cerrar turno</BaseButton>
      </div>
    </section>

    <!-- Mejora (16/10/2026): resumen del turno abierto: ingresos por método, de dónde sale el
         efectivo esperado, depósitos del día y anulados. -->
    <section v-if="!cargandoActual && turnoActual?.resumen" class="card mt-4 p-5 sm:p-6">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="section-title">Resumen del día</h2>
          <p class="text-xs text-gray-700">Actualizado a las {{ horaCorta(turnoActual.resumen.actualizado_at) }}</p>
        </div>
        <BaseButton variant="secondary" size="sm" :disabled="actualizando" @click="actualizarResumen">
          <RefreshCw :size="14" :class="actualizando ? 'animate-spin' : ''" />
          {{ actualizando ? 'Actualizando…' : 'Actualizar' }}
        </BaseButton>
      </div>
      <ResumenTurno :resumen="turnoActual.resumen" />
    </section>

    <!-- Historial de turnos -->
    <section class="mt-10">
      <h2 class="section-title">Historial de turnos</h2>

      <p v-if="errorHistorial" class="alert-error mt-3">{{ errorHistorial }}</p>

      <div v-if="cargandoHistorial" class="loading-state">Cargando turnos…</div>

      <div v-else-if="!turnos.length" class="mt-5 empty-state">
        <Wallet :size="32" class="mx-auto text-gray-300" />
        <p class="mt-3 text-sm text-gray-500">Todavía no hay turnos de caja registrados.</p>
      </div>

      <template v-else>
        <p class="mt-3 text-xs text-gray-500">{{ contadorTexto }}</p>
        <ul class="mt-2 space-y-3">
          <li
            v-for="turno in turnos"
            :key="turno.id"
            class="card-interactive flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
            @click="verDetalle(turno)"
          >
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-ink">
                {{ formatoFecha(turno.fecha) }}
                <span
                  class="badge"
                  :class="turno.estado === 'abierta' ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-gray-100 text-gray-500 ring-gray-500/20'"
                >
                  {{ turno.estado === 'abierta' ? 'Abierta' : 'Cerrada' }}
                </span>
                <span v-if="turno.diferencia !== null && turno.diferencia !== 0" class="badge bg-amber-50 text-amber-700 ring-amber-600/20">
                  Descuadre {{ formatoPrecio(turno.diferencia) }}
                </span>
              </p>
              <p class="mt-1.5 text-xs leading-relaxed text-gray-500">
                Base {{ formatoPrecio(turno.base_inicial) }} · esperado {{ formatoPrecio(turno.efectivo_esperado) }}
                <template v-if="turno.efectivo_contado !== null"> · contado {{ formatoPrecio(turno.efectivo_contado) }}</template>
                · abrió {{ turno.abierto_por_usuario?.nombre ?? '—' }}
                <template v-if="turno.cerrado_por_usuario"> · cerró {{ turno.cerrado_por_usuario.nombre }}</template>
              </p>
            </div>
            <BaseButton variant="ghost" size="sm" class="shrink-0" @click.stop="verDetalle(turno)">Ver detalle</BaseButton>
          </li>
        </ul>

        <div v-if="ultimaPagina > 1" class="mt-5 flex items-center justify-center gap-3">
          <BaseButton variant="ghost" size="sm" :disabled="paginaActual <= 1" @click="cargarHistorial(paginaActual - 1)">Anterior</BaseButton>
          <span class="text-xs text-gray-500">Página {{ paginaActual }} de {{ ultimaPagina }}</span>
          <BaseButton variant="ghost" size="sm" :disabled="paginaActual >= ultimaPagina" @click="cargarHistorial(paginaActual + 1)">Siguiente</BaseButton>
        </div>
      </template>
    </section>

    <!-- Abrir turno -->
    <BaseModal v-model="modalAbrirOpen" title="Abrir turno de caja">
      <label class="block text-xs font-medium text-gray-700">
        Base inicial
        <CampoPesos
          v-model="baseInicial"
          class="mt-2 field"
        />
      </label>
      <p class="mt-1 text-xs text-gray-400">El efectivo con el que arranca la caja hoy.</p>

      <p v-if="errorAbrir" class="mt-3 alert-error">{{ errorAbrir }}</p>

      <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <BaseButton variant="ghost" @click="modalAbrirOpen = false">Cancelar</BaseButton>
        <BaseButton variant="primary" :disabled="abriendo" @click="confirmarAbrir">
          {{ abriendo ? 'Abriendo…' : 'Abrir turno' }}
        </BaseButton>
      </div>
    </BaseModal>

    <!-- Cerrar turno -->
    <BaseModal v-model="modalCerrarOpen" title="Cerrar turno de caja">
      <div v-if="pedidosSinFinalizar.length" class="mb-4 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
        <p class="font-semibold">
          {{ pedidosSinFinalizar.length === 1 ? 'Falta finalizar 1 pedido' : `Faltan ${pedidosSinFinalizar.length} pedidos por finalizar` }}
        </p>
        <p class="mt-1 text-xs text-amber-800">La caja solo cierra cuando todos los pedidos están finalizados o cancelados.</p>
        <ul class="mt-3 max-h-48 space-y-1.5 overflow-y-auto">
          <li v-for="p in pedidosSinFinalizar" :key="p.id" class="flex items-center justify-between gap-3 rounded-lg bg-white/70 px-3 py-2 text-xs">
            <span class="min-w-0"><strong>#{{ p.id }}</strong> · {{ p.destino }} · {{ ESTADO_TEXTO[p.estado] ?? p.estado }}</span>
            <span class="shrink-0 font-semibold" :class="p.saldo > 0 ? 'text-red-700' : 'text-emerald-700'">
              {{ p.saldo > 0 ? `Debe ${formatoPrecio(p.saldo)}` : 'Pagado' }}
            </span>
          </li>
        </ul>
        <router-link to="/admin/pedidos" class="mt-3 inline-block text-xs font-semibold text-amber-900 underline">Ir a Pedidos</router-link>
      </div>
      <label class="block text-xs font-medium text-gray-700">
        Efectivo contado
        <CampoPesos
          v-model="efectivoContado"
          class="mt-2 field"
        />
      </label>
      <p v-if="turnoActual" class="mt-1 text-xs text-gray-400">
        Efectivo esperado: {{ formatoPrecio(turnoActual.efectivo_esperado) }}
      </p>

      <label class="mt-4 block text-xs font-medium text-gray-700">
        Justificación del descuadre (si aplica)
        <textarea v-formato="'texto'" maxlength="500"
          v-model="justificacionDescuadre"
          rows="2"
          class="mt-2 field"
        />
      </label>
      <p class="mt-1 text-xs text-gray-400">Obligatoria solo si el efectivo contado no coincide con el esperado.</p>

      <p v-if="errorCerrar" class="mt-3 alert-error">{{ errorCerrar }}</p>

      <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <BaseButton variant="ghost" @click="modalCerrarOpen = false">Cancelar</BaseButton>
        <BaseButton variant="danger" :disabled="cerrando || pedidosSinFinalizar.length > 0" @click="confirmarCerrar">
          {{ cerrando ? 'Cerrando…' : 'Cerrar turno' }}
        </BaseButton>
      </div>
    </BaseModal>

    <BaseModal :model-value="!!fotoPago" :title="fotoPago ? `Comprobante · pedido #${fotoPago.pago.pedido_id}` : ''" @update:model-value="cerrarFoto">
      <img v-if="fotoPago" :src="fotoPago.url" alt="Foto del comprobante" class="mx-auto max-h-[70vh] rounded-xl object-contain" />
    </BaseModal>

    <!-- Detalle de un turno: sus pagos, con anular por fila -->
    <BaseModal :model-value="!!turnoDetalle || cargandoDetalle" :title="turnoDetalle ? `Turno ${formatoFecha(turnoDetalle.fecha)}` : 'Detalle del turno'" @update:model-value="cerrarDetalle">
      <p v-if="errorDetalle" class="alert-error">{{ errorDetalle }}</p>
      <p v-if="avisoAnulacion" class="mb-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800" role="status">{{ avisoAnulacion }}</p>

      <div v-if="cargandoDetalle" class="loading-state">Cargando pagos…</div>

      <template v-else-if="turnoDetalle">
        <div class="grid grid-cols-2 gap-3 text-xs text-gray-600 sm:grid-cols-4">
          <div><p class="text-gray-400">Base</p><p class="font-medium text-gray-900">{{ formatoPrecio(turnoDetalle.base_inicial) }}</p></div>
          <div><p class="text-gray-400">Esperado</p><p class="font-medium text-gray-900">{{ formatoPrecio(turnoDetalle.efectivo_esperado) }}</p></div>
          <div><p class="text-gray-400">Contado</p><p class="font-medium text-gray-900">{{ formatoPrecioONulo(turnoDetalle.efectivo_contado) }}</p></div>
          <div><p class="text-gray-400">Diferencia</p><p class="font-medium text-gray-900">{{ formatoPrecioONulo(turnoDetalle.diferencia) }}</p></div>
        </div>
        <p v-if="turnoDetalle.justificacion_descuadre" class="mt-2 text-xs text-gray-500">
          Justificación: {{ turnoDetalle.justificacion_descuadre }}
        </p>
        <!-- Mejora (16/10/2026): el mismo resumen del turno de hoy (antes, notas sueltas sobre el esperado). -->
        <ResumenTurno v-if="turnoDetalle.resumen" :resumen="turnoDetalle.resumen" compacto class="mt-5" />

        <h3 class="mt-5 text-xs font-semibold uppercase tracking-wide text-gray-400">Pagos</h3>

        <p v-if="!turnoDetalle.pagos?.length" class="mt-3 text-sm text-gray-500">Este turno no tiene pagos registrados.</p>

        <ul v-else class="mt-2 max-h-96 space-y-2 overflow-y-auto">
          <li
            v-for="pago in turnoDetalle.pagos"
            :key="pago.id"
            class="flex flex-col gap-2 rounded-xl border border-gray-200 bg-gray-50/60 p-3 sm:flex-row sm:items-center sm:justify-between"
            :class="{ 'opacity-60': pago.anulado }"
          >
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-gray-900">
                Pedido #{{ pago.pedido?.id ?? pago.pedido_id }}
                <span class="badge" :class="ESTADOS_PAGO[pago.estado]">
                  {{ pago.estado }}
                </span>
                <span v-if="pago.anulado" class="badge bg-gray-100 text-gray-500 ring-gray-500/20">Anulado</span>
              </p>
              <p class="mt-0.5 text-xs text-gray-500">
                <component :is="METODOS[pago.metodo ?? '']?.icono ?? Banknote" :size="12" class="mr-1 inline-block" />
                {{ METODOS[pago.metodo ?? '']?.texto ?? pago.metodo }} · {{ formatoPrecio(pago.monto) }}
                <template v-if="pago.registrado_por_usuario"> · cobrado por {{ pago.registrado_por_usuario.nombre }}</template>
                <template v-if="pago.anulado">
                  · anulado por {{ pago.anulado_por_usuario?.nombre ?? '—' }} ({{ formatoFechaHora(pago.anulado_at) }})
                  <template v-if="pago.anulado_motivo">: "{{ pago.anulado_motivo }}"</template>
                </template>
              </p>
            </div>
            <div class="flex shrink-0 gap-2">
              <BaseButton v-if="pago.tiene_comprobante" variant="ghost" size="sm" @click="verFoto(pago)">
                <Camera :size="15" class="mr-1" /> Ver foto
              </BaseButton>
              <BaseButton v-escritura v-if="!pago.anulado" variant="danger-soft" size="sm" @click="abrirAnular(pago)">
                Anular
              </BaseButton>
            </div>
          </li>
        </ul>
        <p v-if="errorFoto" class="mt-2 alert-error">{{ errorFoto }}</p>

        <!-- Pagos en línea del día: no pasan por la caja física, pero también se anulan o
             reembolsan desde aquí (RF-M06-017). -->
        <template v-if="turnoDetalle.pagos_en_linea?.length">
          <h3 class="mt-5 text-xs font-semibold uppercase tracking-wide text-gray-400">Pagos en línea del día</h3>
          <ul class="mt-2 space-y-2">
            <li
              v-for="pago in turnoDetalle.pagos_en_linea"
              :key="pago.id"
              class="flex flex-col gap-2 rounded-xl border border-gray-200 bg-gray-50/60 p-3 sm:flex-row sm:items-center sm:justify-between"
              :class="{ 'opacity-60': pago.anulado }"
            >
              <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-gray-900">
                  Pedido #{{ pago.pedido?.id ?? pago.pedido_id }}
                  <span class="badge" :class="ESTADOS_PAGO[pago.estado]">{{ pago.estado }}</span>
                  <span v-if="pago.anulado" class="badge bg-gray-100 text-gray-500 ring-gray-500/20">
                    {{ pago.reverso_tipo === 'reembolso' ? 'Reembolsado' : 'Anulado' }}
                  </span>
                </p>
                <p class="mt-0.5 text-xs text-gray-500">
                  {{ nombreMedio(pago.pasarela) }} · {{ formatoPrecio(pago.monto) }}
                  <template v-if="pago.anulado && pago.anulado_motivo">· "{{ pago.anulado_motivo }}"</template>
                </p>
                <!-- Fase 6, tarea 1: cómo se aprobó y qué dijo la pasarela. -->
                <p v-if="pago.transaccion_pasarela || pago.confirmado_via" class="mt-0.5 text-xs text-gray-500">
                  <template v-if="pago.confirmado_via === 'pasarela'">Confirmado por Wompi</template>
                  <template v-else-if="pago.confirmado_via === 'manual'">
                    {{ pago.estado === 'rechazado' ? 'Rechazado' : 'Aprobado' }} a mano<template v-if="pago.confirmado_por_usuario"> por {{ pago.confirmado_por_usuario.nombre }}</template>
                  </template>
                  <template v-if="pago.transaccion_pasarela">
                    · {{ metodoPasarela(pago.transaccion_pasarela.metodo_pago) || ESTADO_TRANSACCION[pago.transaccion_pasarela.estado] }}
                    · ref. <span class="font-mono">{{ pago.transaccion_pasarela.referencia }}</span>
                  </template>
                </p>
                <p v-if="pago.transaccion_pasarela?.reembolso_estado" class="mt-0.5 text-xs" :class="pago.transaccion_pasarela.reembolso_estado === 'fallido' ? 'text-red-700' : 'text-amber-700'">
                  {{ ESTADO_REEMBOLSO[pago.transaccion_pasarela.reembolso_estado] }}<template v-if="pago.transaccion_pasarela.reembolso_mensaje">: {{ pago.transaccion_pasarela.reembolso_mensaje }}</template>
                </p>
              </div>
              <BaseButton v-escritura
                v-if="!pago.anulado && pago.estado === 'aprobado'"
                variant="danger-soft"
                size="sm"
                class="shrink-0"
                @click="abrirAnular(pago)"
              >
                Anular
              </BaseButton>
            </li>
          </ul>
        </template>

        <!-- Fase 2, tarea 2: depósitos de reserva recibidos o devueltos en este turno. Cuando el cliente
             llega, el depósito se abona a su cuenta sin volver a entrar a la caja. -->
        <template v-if="turnoDetalle.depositos_reserva?.length || turnoDetalle.depositos_devueltos?.length || turnoDetalle.depositos_en_linea?.length">
          <h3 class="mt-5 text-xs font-semibold uppercase tracking-wide text-gray-400">Depósitos de reserva</h3>
          <ul class="mt-2 space-y-1.5 text-xs text-gray-600">
            <li v-for="r in turnoDetalle.depositos_reserva" :key="`d${r.id}`" class="rounded-lg bg-gray-50 px-3 py-2">
              Recibido {{ formatoPrecio(Number(r.deposito_valor)) }} ({{ METODOS[r.deposito_metodo ?? '']?.texto ?? r.deposito_metodo }}) ·
              reserva #{{ r.id }} de {{ r.cliente_nombre }}<template v-if="r.mesa">, {{ r.mesa.nombre }}</template>
            </li>
            <!-- Mejora (16/10/2026): los pagados en línea ese día, que antes no salían aquí. -->
            <li v-for="r in turnoDetalle.depositos_en_linea" :key="`l${r.id}`" class="rounded-lg bg-gray-50 px-3 py-2">
              Recibido {{ formatoPrecio(Number(r.deposito_valor)) }} (en línea<template v-if="r.deposito_pasarela">, {{ nombreMedio(r.deposito_pasarela) }}</template>) ·
              reserva #{{ r.id }} de {{ r.cliente_nombre }}<template v-if="r.mesa">, {{ r.mesa.nombre }}</template>
            </li>
            <li v-for="r in turnoDetalle.depositos_devueltos" :key="`v${r.id}`" class="rounded-lg bg-amber-50 px-3 py-2">
              Devuelto {{ formatoPrecio(Number(r.deposito_valor)) }} ({{ METODOS[r.deposito_metodo ?? '']?.texto ?? r.deposito_metodo }}) ·
              reserva #{{ r.id }} de {{ r.cliente_nombre }} (cancelada)
            </li>
          </ul>
        </template>

        <!-- Ajustes extraordinarios: reversos hechos con la caja ya cerrada (RF-M06-019). -->
        <template v-if="turnoDetalle.ajustes?.length">
          <h3 class="mt-5 text-xs font-semibold uppercase tracking-wide text-gray-400">Ajustes extraordinarios</h3>
          <ul class="mt-2 space-y-1.5 text-xs text-gray-600">
            <li v-for="ajuste in turnoDetalle.ajustes" :key="ajuste.id" class="rounded-lg bg-amber-50 px-3 py-2">
              {{ ajuste.tipo === 'reembolso' ? 'Reembolso' : 'Anulación' }} de {{ formatoPrecio(Number(ajuste.monto)) }}
              ({{ METODOS[ajuste.metodo]?.texto ?? ajuste.metodo }}) del pago #{{ ajuste.pago_id }} ·
              autorizó {{ ajuste.autorizado_por_usuario?.nombre ?? '—' }} el {{ formatoFechaHora(ajuste.created_at) }}:
              "{{ ajuste.motivo }}"
            </li>
          </ul>
        </template>
      </template>

      <div class="mt-4 flex justify-end">
        <BaseButton variant="ghost" @click="cerrarDetalle">Cerrar</BaseButton>
      </div>
    </BaseModal>

    <!-- Anular un pago: motivo obligatorio (control financiero exclusivo del Administrador) -->
    <BaseModal :model-value="!!pagoAAnular" title="Anular pago" @update:model-value="cerrarAnular">
      <template v-if="pagoAAnular">
        <p class="text-sm text-gray-600">
          Vas a anular el pago de {{ formatoPrecio(pagoAAnular.monto) }} del pedido #{{ pagoAAnular.pedido_id }}.
          Esta acción no se puede deshacer.
        </p>

        <div class="mt-4 grid grid-cols-2 gap-2">
          <button
            v-for="opcion in TIPOS_REVERSO"
            :key="opcion.v"
            type="button"
            class="rounded-xl border px-3 py-2 text-sm font-semibold transition"
            :class="tipoReverso === opcion.v ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-brand-300'"
            @click="tipoReverso = opcion.v"
          >
            {{ opcion.t }}
          </button>
        </div>

        <p class="mt-3 text-xs text-gray-500">
          <template v-if="tipoReverso === 'anulacion'">
            Anulación: el pago se registró por error. Si el pedido ya estaba finalizado y queda sin pagar completo, vuelve a
            cobro para cobrar lo que falta.
          </template>
          <template v-else>Reembolso: se le devuelve el dinero al cliente; el pedido sigue como está.</template>
        </p>

        <p v-if="cajaDelPagoCerrada" class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
          La caja de ese día ya cerró. El reverso queda como ajuste extraordinario y el arqueo de ese día no cambia.
          Confirma tu contraseña para autorizarlo.
        </p>
        <label v-if="cajaDelPagoCerrada" class="mt-3 block text-xs font-medium text-gray-700">
          Tu contraseña
          <input v-model="passwordConfirmacion" type="password" autocomplete="current-password" class="mt-2 field" />
        </label>

        <label class="mt-4 block text-xs font-medium text-gray-700">
          Motivo
          <textarea v-formato="'texto'" maxlength="255"
            v-model="motivoAnular"
            rows="2"
            class="mt-2 field"
          />
        </label>

        <p v-if="errorAnular" class="mt-3 alert-error">{{ errorAnular }}</p>

        <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" @click="cerrarAnular">Cancelar</BaseButton>
          <BaseButton variant="danger" :disabled="anulando" @click="confirmarAnular">
            {{ anulando ? 'Anulando…' : 'Anular pago' }}
          </BaseButton>
        </div>
      </template>
    </BaseModal>
  </div>
</template>
