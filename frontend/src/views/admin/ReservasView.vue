<script setup lang="ts">
// M05 — Vista Administrador > Reservas: listado con sus estados y depósitos retenidos
// por no-show (RF-M05-035, HU-M05-015, RN-M05-16 a 19). Espeja ReservaController.php /
// reserva.service.ts. El Cliente reserva desde ReservaView.vue (público); aquí se
// gestiona el ciclo de vida completo de esa reserva.
//
// Fase 1, tarea 4 (11/10/2026): la misma pantalla sirve al Mesero en /mesero/reservas
// (HU-M05-009/010). Los dos registran reservas por teléfono con "Nueva reserva" (RF-M05-013),
// registran el depósito presencial (RF-M05-031, RN-M05-16) y confirman la llegada del cliente
// (RF-M05-015). Los depósitos retenidos por no-show siguen siendo solo del Administrador
// (RF-M05-035). Por defecto se ven las reservas próximas, de hoy en adelante.
// Fase 2, tarea 2 (12/10/2026): al confirmar la llegada el depósito se abona a la cuenta de la mesa
// (RF-M05-034); el depósito manual exige la caja abierta y devolver uno en efectivo también.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CalendarClock, Camera, Plus, Wallet, X } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import PestanasSalon from '@/components/common/PestanasSalon.vue'
import RevisarComprobanteModal from '@/components/pagos/RevisarComprobanteModal.vue'
import EfectivoRecibido from '@/components/pagos/EfectivoRecibido.vue'
import FotoComprobante from '@/components/pagos/FotoComprobante.vue'
import { reservaService } from '@/services/reserva.service'
import { mesaService } from '@/services/mesa.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { ESTADO_REEMBOLSO, ESTADO_TRANSACCION, metodoPasarela, nombreMedio } from '@/utils/pasarela'
import { useAuthStore } from '@/stores/auth'
import { configuracionService } from '@/services/configuracion.service'
import { HORARIO_POR_DEFECTO, fechaMinimaReserva, horasDelDia, textoHorario, type HorarioReservas } from '@/utils/horarioReservas'
import type { EstadoReserva, Mesa, Reserva, ReservaAviso } from '@/types/models'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const esAdministrador = computed(() => auth.esAdministrador)

// Historial de reservas de UNA mesa — se puede llegar aquí con ?mesa_id=&mesa_nombre=
// (enlace "Ver reservas" en MesasView.vue) o eligiendo la mesa directamente en el
// selector de abajo. mesa_nombre es solo para mostrar el filtro activo sin tener que
// esperar la primera respuesta del backend.
const mesaIdFiltro = ref<number | null>(route.query.mesa_id ? Number(route.query.mesa_id) : null)
const mesaNombreFiltro = ref<string>(typeof route.query.mesa_nombre === 'string' ? route.query.mesa_nombre : '')

// Selector de mesa (filtro directo, sin tener que ir a Mesas y volver).
const mesas = ref<Mesa[]>([])

async function cargarMesas() {
  try {
    const { data } = await mesaService.index()
    mesas.value = data
  } catch {
    // El selector es una ayuda adicional; si falla la carga de mesas, el resto de la
    // vista (filtros por estado, listado) sigue funcionando con normalidad.
  }
}

function seleccionarMesa(valor: string) {
  const id = valor ? Number(valor) : null
  mesaIdFiltro.value = id
  mesaNombreFiltro.value = id ? mesas.value.find((m) => m.id === id)?.nombre ?? '' : ''
  paginaActual.value = 1
  router.replace({
    query: { ...route.query, mesa_id: id ?? undefined, mesa_nombre: mesaNombreFiltro.value || undefined },
  })
  cargar()
}

function quitarFiltroMesa() {
  seleccionarMesa('')
}

// Corrección (16/10/2026): la pestaña «Reservas» (PestanasSalon) lleva a /admin/reservas sin filtro;
// si se estaba viendo una sola mesa, la lista vuelve a mostrar todas.
watch(
  () => route.query.mesa_id,
  (valor) => {
    const id = valor ? Number(valor) : null
    if (id === mesaIdFiltro.value) return
    mesaIdFiltro.value = id
    mesaNombreFiltro.value = typeof route.query.mesa_nombre === 'string' ? route.query.mesa_nombre : ''
    paginaActual.value = 1
    cargar()
  },
)

const reservas = ref<Reserva[]>([])
const total = ref(0)
const paginaActual = ref(1)
const ultimaPagina = ref(1)
const cargando = ref(true)
const error = ref('')
const procesando = ref<number | null>(null)

// "proximas" y "todas" además de los 5 estados del modelo (EstadoReserva) — ver types/models.ts.
type FiltroEstado = 'proximas' | 'todas' | EstadoReserva
const filtro = ref<FiltroEstado>('proximas')

const OPCIONES_FILTRO: { v: FiltroEstado; t: string }[] = [
  { v: 'proximas', t: 'Próximas' },
  { v: 'todas', t: 'Todas' },
  { v: 'pendiente_pago', t: 'Pendientes de pago' },
  { v: 'confirmada', t: 'Confirmadas' },
  { v: 'no_show', t: 'No-show' },
  { v: 'completada', t: 'Completadas' },
  { v: 'cancelada', t: 'Canceladas' },
]

const ESTADOS: Record<EstadoReserva, { texto: string; clase: string }> = {
  pendiente_pago: { texto: 'Pendiente de pago', clase: 'bg-amber-50 text-amber-700 ring-amber-600/20' },
  confirmada: { texto: 'Confirmada', clase: 'bg-sky-50 text-sky-700 ring-sky-600/20' },
  no_show: { texto: 'No-show', clase: 'bg-red-50 text-red-700 ring-red-600/20' },
  completada: { texto: 'Completada', clase: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' },
  cancelada: { texto: 'Cancelada', clase: 'bg-gray-100 text-gray-600 ring-gray-500/20' },
}

function fechaTexto(v: string): string {
  return new Date(v).toLocaleString('es-CO', {
    weekday: 'short',
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const formatoPesos = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await reservaService.index({
      estado: filtro.value === 'todas' || filtro.value === 'proximas' ? undefined : filtro.value,
      proximas: filtro.value === 'proximas' || undefined,
      mesa_id: mesaIdFiltro.value ?? undefined,
      page: paginaActual.value,
    })
    reservas.value = data.data
    total.value = data.total
    ultimaPagina.value = data.last_page
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar las reservas.')
  } finally {
    cargando.value = false
  }
}

watch(filtro, () => {
  paginaActual.value = 1
  cargar()
})

function cambiarPagina(delta: number) {
  const destino = paginaActual.value + delta
  if (destino < 1 || destino > ultimaPagina.value) return
  paginaActual.value = destino
  cargar()
}

function reemplazar(actualizada: Reserva) {
  const i = reservas.value.findIndex((r) => r.id === actualizada.id)
  if (i !== -1) reservas.value[i] = actualizada
}

// ---- Acciones sobre el depósito en línea (RN-M05-16, hoy manuales hasta el webhook real) ----
async function confirmarDeposito(reserva: Reserva) {
  procesando.value = reserva.id
  error.value = ''
  try {
    const { data } = await reservaService.confirmarDepositoEnLinea(reserva.id)
    reemplazar(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos confirmar el depósito.')
  } finally {
    procesando.value = null
  }
}

// Pago directo (22/10/2026, RF-M06-032/033): la captura del depósito se revisa en una ventana; el
// rechazo lleva motivo, que el Cliente ve en el enlace de su reserva.
const revisionDeposito = ref<{ reserva: Reserva; rechazar: boolean } | null>(null)
const errorRevision = ref('')

function abrirRevisionDeposito(reserva: Reserva, rechazar = false) {
  errorRevision.value = ''
  revisionDeposito.value = { reserva, rechazar }
}

async function aprobarDepositoRevisado() {
  if (!revisionDeposito.value) return
  const { reserva } = revisionDeposito.value
  procesando.value = reserva.id
  errorRevision.value = ''
  try {
    const { data } = await reservaService.confirmarDepositoEnLinea(reserva.id)
    reemplazar(data)
    revisionDeposito.value = null
  } catch (e) {
    errorRevision.value = mensajeDeError(e, 'No pudimos confirmar el depósito.')
  } finally {
    procesando.value = null
  }
}

async function rechazarDeposito(motivo: string) {
  if (!revisionDeposito.value) return
  const { reserva } = revisionDeposito.value
  procesando.value = reserva.id
  errorRevision.value = ''
  try {
    const { data } = await reservaService.rechazarDepositoEnLinea(reserva.id, motivo)
    reemplazar(data)
    revisionDeposito.value = null
  } catch (e) {
    errorRevision.value = mensajeDeError(e, 'No pudimos rechazar el depósito.')
  } finally {
    procesando.value = null
  }
}

// ---- Depósito manual (RN-M05-16) ----
// Mejora (26/10/2026, RF-M05-045 y 046): en efectivo se indica con cuánto pagó el cliente (y se ve el cambio); en
// transferencia se toma la foto del comprobante (obligatoria; en tarjeta, opcional).
const reservaDeposito = ref<Reserva | null>(null)
const metodoDeposito = ref<'efectivo' | 'tarjeta' | 'transferencia'>('efectivo')
const errorDeposito = ref('')
const registrandoDeposito = ref(false)
const recibidoDeposito = ref<number | null>(null)
const fotoDeposito = ref<File | null>(null)
const avisoDeposito = ref('')
// Al corregir el método, el valor o la foto, el error anterior ya no aplica.
watch([metodoDeposito, recibidoDeposito, fotoDeposito], () => (errorDeposito.value = ''))

const METODOS: { v: typeof metodoDeposito.value; t: string }[] = [
  { v: 'efectivo', t: 'Efectivo' },
  { v: 'tarjeta', t: 'Tarjeta' },
  { v: 'transferencia', t: 'Transferencia' },
]

function abrirDeposito(reserva: Reserva) {
  reservaDeposito.value = reserva
  metodoDeposito.value = 'efectivo'
  errorDeposito.value = ''
  recibidoDeposito.value = null
  fotoDeposito.value = null
}

async function confirmarDepositoManual() {
  if (!reservaDeposito.value) return
  const valor = Number(reservaDeposito.value.deposito_valor)
  const recibido = recibidoDeposito.value
  if (metodoDeposito.value === 'efectivo') {
    if (!recibido) {
      errorDeposito.value = 'Indica con cuánto pagó el cliente (o toca «Exacto»).'
      return
    }
    if (recibido < valor) {
      errorDeposito.value = `Lo que entregó el cliente no alcanza: faltan ${formatoPesos.format(valor - recibido)}.`
      return
    }
  }
  if (metodoDeposito.value === 'transferencia' && !fotoDeposito.value) {
    errorDeposito.value = 'Toma la foto del comprobante de la transferencia.'
    return
  }
  registrandoDeposito.value = true
  errorDeposito.value = ''
  avisoDeposito.value = ''
  try {
    const { data } = await reservaService.registrarDepositoManual(reservaDeposito.value.id, {
      metodo: metodoDeposito.value,
      recibido,
      comprobante: fotoDeposito.value,
    })
    reemplazar(data)
    const cambio = metodoDeposito.value === 'efectivo' && recibido ? recibido - valor : 0
    avisoDeposito.value = `Depósito de ${data.cliente_nombre} registrado: la reserva quedó confirmada.${cambio > 0 ? ` Entrega ${formatoPesos.format(cambio)} de cambio.` : ''}`
    reservaDeposito.value = null
  } catch (e) {
    errorDeposito.value = Object.values(erroresDeValidacion(e))[0] ?? mensajeDeError(e, 'No pudimos registrar el depósito.')
  } finally {
    registrandoDeposito.value = false
  }
}

// Mejora (26/10/2026, RF-M05-046): la foto o captura del comprobante de un depósito ya pagado.
const fotoVista = ref<{ titulo: string; url: string; pdf: boolean } | null>(null)
async function verFotoDeposito(reserva: Reserva) {
  error.value = ''
  try {
    const { data } = await reservaService.comprobanteDeposito(reserva.id)
    fotoVista.value = { titulo: `Comprobante · reserva #${reserva.id}`, url: URL.createObjectURL(data), pdf: data.type === 'application/pdf' }
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos abrir el comprobante.')
  }
}
function cerrarFotoDeposito() {
  if (fotoVista.value) URL.revokeObjectURL(fotoVista.value.url)
  fotoVista.value = null
}

// ---- Nueva reserva por teléfono (RF-M05-013, RN-M05-12) ----
// Primero fecha y hora; con eso se piden las mesas que no tienen otra reserva a esa hora.
const nuevaAbierta = ref(false)
const nueva = ref({ fecha: '', hora: '', mesa_id: 0, nombre: '', telefono: '', correo: '', descripcion: '' })
const mesasLibres = ref<Mesa[]>([])
const buscandoMesas = ref(false)
const erroresNueva = ref<Record<string, string>>({})
const errorNueva = ref('')
const creandoNueva = ref(false)

const depositoVigente = ref(0)
// Corrección (19/10/2026): la reserva por teléfono sigue el mismo horario (4:00 p. m. a 11:00 p. m.)
// y la misma anticipación mínima (3 horas) que la web; el servidor lo valida igual.
const horario = ref<HorarioReservas>({ ...HORARIO_POR_DEFECTO })
const horasNueva = computed(() => horasDelDia(nueva.value.fecha, horario.value, ahora.value))
// Fase 4, tarea 3 (RN-M05-07/08): minutos de tolerancia; la reserva se libera sola a la hora + esto.
const toleranciaMinutos = ref(15)

async function cargarDepositoVigente() {
  try {
    const { data } = await configuracionService.obtener()
    depositoVigente.value = Number(data.deposito_reserva)
    toleranciaMinutos.value = data.reserva_tolerancia_minutos ?? 15
    horario.value = {
      inicio: data.reserva_hora_inicio ?? HORARIO_POR_DEFECTO.inicio,
      fin: data.reserva_hora_fin ?? HORARIO_POR_DEFECTO.fin,
      anticipacionMinutos: data.reserva_anticipacion_minutos ?? HORARIO_POR_DEFECTO.anticipacionMinutos,
    }
  } catch {
    // Solo es informativo: el servidor fija el valor real al crear la reserva.
  }
}

// Reloj de la pantalla (cada 30 s) para saber si ya venció la tolerancia de una reserva.
const ahora = ref(Date.now())
let reloj: ReturnType<typeof setInterval> | undefined

function horaDeLiberacion(reserva: Reserva): Date {
  return new Date(new Date(reserva.fecha_hora).getTime() + toleranciaMinutos.value * 60000)
}

function yaVencioTolerancia(reserva: Reserva): boolean {
  return ahora.value >= horaDeLiberacion(reserva).getTime()
}

function horaCorta(valor: Date | string): string {
  return new Date(valor).toLocaleTimeString('es-CO', { timeZone: 'America/Bogota', hour: 'numeric', minute: '2-digit' })
}

function abrirNueva() {
  if (!depositoVigente.value) cargarDepositoVigente()
  nueva.value = { fecha: fechaMinimaReserva(horario.value), hora: '', mesa_id: 0, nombre: '', telefono: '', correo: '', descripcion: '' }
  mesasLibres.value = []
  erroresNueva.value = {}
  errorNueva.value = ''
  nuevaAbierta.value = true
}

watch(
  () => [nueva.value.fecha, nueva.value.hora],
  async ([fecha, hora]) => {
    mesasLibres.value = []
    if (!fecha || !hora) return
    buscandoMesas.value = true
    try {
      const { data } = await reservaService.mesasDisponibles(`${fecha} ${hora}`)
      mesasLibres.value = data
      if (!data.some((m) => m.id === nueva.value.mesa_id)) nueva.value.mesa_id = 0
    } catch {
      mesasLibres.value = []
    } finally {
      buscandoMesas.value = false
    }
  },
)

async function crearNueva() {
  erroresNueva.value = {}
  errorNueva.value = ''
  const n = nueva.value
  if (!n.fecha || !n.hora) erroresNueva.value.fecha_hora = 'Elige la fecha y la hora.'
  if (!n.mesa_id) erroresNueva.value.mesa_id = 'Elige una mesa disponible.'
  if (!n.nombre.trim()) erroresNueva.value.cliente_nombre = 'Escribe el nombre.'
  if (!n.telefono.trim()) erroresNueva.value.cliente_telefono = 'Escribe el teléfono.'
  if (!n.correo.trim()) erroresNueva.value.cliente_correo = 'Escribe el correo.'
  if (Object.keys(erroresNueva.value).length) return

  creandoNueva.value = true
  try {
    const { data } = await reservaService.crear({
      mesa_id: n.mesa_id,
      cliente_nombre: n.nombre.trim(),
      cliente_telefono: n.telefono.trim(),
      cliente_correo: n.correo.trim(),
      fecha_hora: `${n.fecha} ${n.hora}`,
      descripcion: n.descripcion.trim() || null,
    })
    nuevaAbierta.value = false
    if (filtro.value === 'proximas') await cargar()
    else filtro.value = 'proximas' // el watch de filtro recarga
    // RN-M05-16: la reserva manual se confirma con el depósito presencial; se ofrece de una vez.
    abrirDeposito(data)
  } catch (e) {
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) erroresNueva.value = campos
    else errorNueva.value = mensajeDeError(e, 'No pudimos crear la reserva.')
  } finally {
    creandoNueva.value = false
  }
}

// ---- Avisos al cliente (Fase 4, tarea 4: RF-M05-023/024/025/027) ----
const TIPOS_AVISO: Record<ReservaAviso['tipo'], string> = {
  confirmacion: 'Confirmación',
  recordatorio: 'Recordatorio',
  cancelacion: 'Cancelación',
  no_show: 'No-show',
}

async function reintentarAviso(reserva: Reserva, aviso: ReservaAviso) {
  procesando.value = reserva.id
  error.value = ''
  try {
    const { data } = await reservaService.reintentarAviso(reserva.id, aviso.id)
    reemplazar(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos reintentar el aviso.')
  } finally {
    procesando.value = null
  }
}

// ---- No-show / completar / cancelar ----
async function marcarNoShow(reserva: Reserva) {
  procesando.value = reserva.id
  error.value = ''
  try {
    const { data } = await reservaService.marcarNoShow(reserva.id)
    reemplazar(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos marcar la reserva como no-show.')
  } finally {
    procesando.value = null
  }
}

async function completar(reserva: Reserva) {
  procesando.value = reserva.id
  error.value = ''
  try {
    const { data } = await reservaService.completar(reserva.id)
    reemplazar(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos completar la reserva.')
  } finally {
    procesando.value = null
  }
}

async function cancelar(reserva: Reserva) {
  procesando.value = reserva.id
  error.value = ''
  try {
    const { data } = await reservaService.cancelar(reserva.id)
    reemplazar(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cancelar la reserva.')
  } finally {
    procesando.value = null
  }
}

// ---- Depósitos retenidos por no-show (RF-M05-035, HU-M05-015) ----
const modalRetenidosAbierto = ref(false)
const cargandoRetenidos = ref(false)
const errorRetenidos = ref('')
const totalRetenido = ref(0)
const reservasRetenidas = ref<Reserva[]>([])

async function abrirRetenidos() {
  modalRetenidosAbierto.value = true
  cargandoRetenidos.value = true
  errorRetenidos.value = ''
  try {
    const { data } = await reservaService.depositosRetenidos()
    totalRetenido.value = data.total_retenido
    reservasRetenidas.value = data.reservas
  } catch (e) {
    errorRetenidos.value = mensajeDeError(e, 'No pudimos cargar los depósitos retenidos.')
  } finally {
    cargandoRetenidos.value = false
  }
}

const contadorTexto = computed(() => (total.value === 1 ? '1 reserva' : `${total.value} reservas`))

onMounted(() => {
  cargarMesas()
  cargar()
  cargarDepositoVigente()
  reloj = setInterval(() => (ahora.value = Date.now()), 30000)
})

onBeforeUnmount(() => clearInterval(reloj))
</script>

<template>
  <div>
    <PageHeader eyebrow="SALÓN" titulo="Reservas" descripcion="Reservas por teléfono, depósitos y llegada de los clientes.">
      <template #actions>
        <BaseButton v-escritura variant="primary" @click="abrirNueva">
          <Plus :size="16" />
          Nueva reserva
        </BaseButton>
        <BaseButton v-if="esAdministrador" variant="secondary" @click="abrirRetenidos">
          <Wallet :size="16" />
          Depósitos retenidos
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Corrección (16/10/2026): pestañas Mesas | Reservas, solo en la vista del Administrador (el Mesero
         tiene «Mesas» y «Reservas» como secciones aparte en su menú). -->
    <PestanasSalon v-if="route.name === 'admin-reservas'" />

    <div class="mt-5 flex flex-col gap-2 sm:flex-row sm:items-center">
      <label class="flex items-center gap-3 text-sm text-gray-600">
        <span class="shrink-0 text-[11px] font-semibold uppercase tracking-[0.08em] text-gray-500">Mesa</span>
        <select
          :value="mesaIdFiltro ?? ''"
          class="field sm:w-56"
          @change="seleccionarMesa(($event.target as HTMLSelectElement).value)"
        >
          <option value="">Todas las mesas</option>
          <option v-for="mesa in mesas" :key="mesa.id" :value="mesa.id">{{ mesa.nombre }}</option>
        </select>
      </label>
      <button
        v-if="mesaIdFiltro"
        type="button"
        class="flex items-center gap-1 self-start text-xs font-medium text-brand-700 hover:underline sm:self-auto"
        @click="quitarFiltroMesa"
      >
        <X :size="14" /> Quitar filtro
      </button>
    </div>

    <div v-if="mesaIdFiltro" class="mt-2 text-xs text-gray-500">
      Mostrando el historial de <strong class="text-gray-700">{{ mesaNombreFiltro || `Mesa #${mesaIdFiltro}` }}</strong>.
    </div>

    <div class="segmented mt-6 sm:w-fit">
      <button
        v-for="opcion in OPCIONES_FILTRO"
        :key="opcion.v"
        type="button"
        class="segmented-item"
        :class="filtro === opcion.v ? 'is-active' : ''"
        @click="filtro = opcion.v"
      >
        {{ opcion.t }}
      </button>
    </div>

    <p v-if="error" class="mt-4 alert-error">{{ error }}</p>
    <p v-if="avisoDeposito" class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ avisoDeposito }}</p>

    <div v-if="cargando" class="loading-state">Cargando reservas…</div>

    <div v-else-if="!reservas.length" class="mt-10 empty-state">
      <CalendarClock :size="32" class="mx-auto text-gray-300" />
      <p class="mt-3 text-sm text-gray-500">No hay reservas en este filtro.</p>
    </div>

    <template v-else>
      <p class="mt-4 text-xs text-gray-500">{{ contadorTexto }}</p>
      <ul class="mt-2 space-y-3">
        <li
          v-for="reserva in reservas"
          :key="reserva.id"
          class="card flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-5"
        >
          <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-ink">
              {{ reserva.cliente_nombre }}
              <span class="badge" :class="ESTADOS[reserva.estado].clase">
                {{ ESTADOS[reserva.estado].texto }}
              </span>
            </p>
            <p class="mt-1 text-xs text-gray-500">
              {{ reserva.mesa?.nombre ?? `Mesa #${reserva.mesa_id}` }} · {{ fechaTexto(reserva.fecha_hora) }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
              <a :href="`tel:${reserva.cliente_telefono.replace(/[^\d+]/g, '')}`" class="text-brand-700 hover:underline">{{ reserva.cliente_telefono }}</a>
              · {{ reserva.cliente_correo }}
            </p>
            <!-- Corrección (19/10/2026): la nota que dejó el cliente. -->
            <p v-if="reserva.descripcion" class="mt-2 whitespace-pre-line rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
              «{{ reserva.descripcion }}»
            </p>
            <p class="mt-1 text-xs text-gray-500">
              {{
                reserva.creado_por === 'cliente_web'
                  ? 'Hecha por el cliente en la web'
                  : `Registrada por ${reserva.registrada_por_usuario?.nombre ?? (reserva.creado_por === 'mesero' ? 'el Mesero' : 'el Administrador')}`
              }}
            </p>
            <!-- Fase 4, tarea 3 (RF-M05-016/024): liberación automática y recordatorio. -->
            <p v-if="reserva.estado === 'confirmada'" class="mt-1 text-xs text-gray-500">
              <template v-if="yaVencioTolerancia(reserva)">
                <span class="font-medium text-amber-700">Pasó la tolerancia: se libera como no-show en el próximo minuto.</span>
              </template>
              <template v-else>Si no llega, se libera sola a las {{ horaCorta(horaDeLiberacion(reserva)) }}.</template>
              <template v-if="reserva.recordatorio_enviado_at"> · Recordatorio enviado a las {{ horaCorta(reserva.recordatorio_enviado_at) }}.</template>
            </p>
            <p v-else-if="reserva.estado === 'no_show' && reserva.no_show_at" class="mt-1 text-xs text-gray-500">
              {{ reserva.no_show_automatico ? 'Liberada automáticamente' : 'Marcada no-show por el personal' }} a las {{ horaCorta(reserva.no_show_at) }}.
            </p>
            <!-- Fase 4, tarea 4 (RF-M05-027): avisos por correo al cliente y su resultado. -->
            <div v-if="reserva.avisos?.length" class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
              <span class="text-gray-500">Avisos:</span>
              <span
                v-for="aviso in reserva.avisos"
                :key="aviso.id"
                class="badge"
                :class="{
                  'bg-emerald-50 text-emerald-700 ring-emerald-600/20': aviso.estado === 'enviado',
                  'bg-red-50 text-red-700 ring-red-600/20': aviso.estado === 'fallido',
                  'bg-gray-100 text-gray-600 ring-gray-500/20': aviso.estado === 'pendiente',
                }"
                :title="aviso.error ?? `${aviso.destino}`"
              >
                {{ TIPOS_AVISO[aviso.tipo] }}:
                {{ aviso.estado === 'enviado' ? `enviado ${horaCorta(aviso.enviado_at ?? aviso.created_at)}` : aviso.estado === 'fallido' ? 'falló' : 'en cola' }}
              </span>
              <button v-escritura
                v-for="aviso in reserva.avisos.filter((a) => a.estado === 'fallido')"
                :key="`r-${aviso.id}`"
                type="button"
                class="min-h-8 font-semibold text-brand-700 underline disabled:opacity-50"
                :disabled="procesando === reserva.id"
                @click="reintentarAviso(reserva, aviso)"
              >
                Reintentar {{ TIPOS_AVISO[aviso.tipo].toLowerCase() }}
              </button>
            </div>
            <p class="mt-1 text-xs text-gray-500">
              Depósito: {{ formatoPesos.format(reserva.deposito_valor) }}
              <template v-if="reserva.deposito_pagado">
                — pagado ({{ reserva.deposito_metodo }})
                <button
                  v-if="reserva.deposito_tiene_comprobante"
                  type="button"
                  class="inline-flex min-h-8 items-center gap-1 font-semibold text-brand-700 underline-offset-2 hover:underline"
                  @click="verFotoDeposito(reserva)"
                >
                  <Camera :size="13" /> Ver comprobante
                </button>
                <span v-if="reserva.estado === 'no_show'" class="font-medium text-red-700">· retenido por no-show</span>
                <!-- Fase 2, tarea 2 (RF-M05-034): cuánto se abonó a la cuenta de la visita y cuánto queda. -->
                <span v-else-if="reserva.deposito_aplicado_abono" class="font-medium text-green-700">
                  · abonado {{ formatoPesos.format(Number(reserva.deposito_abonado ?? reserva.deposito_valor)) }} a la cuenta
                  <template v-if="Number(reserva.deposito_valor) - Number(reserva.deposito_abonado ?? reserva.deposito_valor) > 0">
                    (quedan {{ formatoPesos.format(Number(reserva.deposito_valor) - Number(reserva.deposito_abonado ?? 0)) }} para los pedidos de la visita)
                  </template>
                </span>
                <span v-else-if="reserva.estado === 'completada'" class="font-medium text-green-700">· se abona al primer pedido de la mesa</span>
                <span v-else-if="reserva.deposito_reembolsado" class="font-medium text-gray-700">· reembolsado</span>
              </template>
              <template v-else-if="reserva.deposito_estado === 'pendiente'">
                <template v-if="reserva.deposito_tiene_comprobante">
                  — <span class="font-medium text-sky-700">comprobante por revisar ({{ nombreMedio(reserva.deposito_pasarela) }})</span>
                </template>
                <template v-else>
                  — {{ reserva.transaccion_deposito ? 'pago en proceso en Wompi (se confirma solo)' : 'pago en línea pendiente de confirmar' }}
                </template>
              </template>
              <template v-else-if="reserva.deposito_estado === 'rechazado'">
                — pago en línea rechazado<template v-if="reserva.deposito_rechazo_motivo">: «{{ reserva.deposito_rechazo_motivo }}»</template>
              </template>
              <template v-else> — sin pagar</template>
            </p>
            <!-- Fase 6, tarea 1 (RF-M05-030): el último intento en la pasarela y su reembolso. -->
            <p v-if="reserva.transaccion_deposito" class="mt-0.5 text-xs text-gray-500">
              Wompi: {{ ESTADO_TRANSACCION[reserva.transaccion_deposito.estado] }}
              <template v-if="reserva.transaccion_deposito.metodo_pago"> · {{ metodoPasarela(reserva.transaccion_deposito.metodo_pago) }}</template>
              · ref. <span class="font-mono">{{ reserva.transaccion_deposito.referencia }}</span>
              <template v-if="reserva.deposito_confirmado_via === 'manual' && reserva.deposito_pagado"> · aprobado a mano</template>
              <template v-if="reserva.transaccion_deposito.reembolso_estado">
                · {{ ESTADO_REEMBOLSO[reserva.transaccion_deposito.reembolso_estado] }}
              </template>
            </p>
          </div>

          <div
            v-if="reserva.estado === 'pendiente_pago' || reserva.estado === 'confirmada'"
            class="grid shrink-0 grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:justify-end"
          >
            <template v-if="reserva.estado === 'pendiente_pago'">
              <BaseButton
                v-if="reserva.deposito_estado === 'pendiente' && reserva.deposito_tiene_comprobante"
                size="sm"
                variant="primary"
                :disabled="procesando === reserva.id"
                @click="abrirRevisionDeposito(reserva)"
              >
                Revisar comprobante
              </BaseButton>
              <template v-else-if="reserva.deposito_estado === 'pendiente'">
                <!-- Con Wompi se confirma solo; los botones quedan de respaldo (D4). -->
                <BaseButton v-escritura
                  size="sm"
                  :variant="reserva.transaccion_deposito ? 'secondary' : 'primary'"
                  :disabled="procesando === reserva.id"
                  @click="confirmarDeposito(reserva)"
                >
                  {{ reserva.transaccion_deposito ? 'Confirmar a mano' : 'Confirmar depósito' }}
                </BaseButton>
                <BaseButton v-escritura size="sm" variant="ghost" :disabled="procesando === reserva.id" @click="abrirRevisionDeposito(reserva, true)">
                  {{ reserva.transaccion_deposito ? 'Rechazar a mano' : 'Rechazar depósito' }}
                </BaseButton>
              </template>
              <BaseButton v-escritura v-else size="sm" variant="primary" :disabled="procesando === reserva.id" @click="abrirDeposito(reserva)">
                Registrar depósito manual
              </BaseButton>
              <BaseButton v-escritura size="sm" variant="danger-soft" :disabled="procesando === reserva.id" @click="cancelar(reserva)">
                Cancelar
              </BaseButton>
            </template>

            <template v-else-if="reserva.estado === 'confirmada'">
              <BaseButton v-escritura size="sm" variant="primary" :disabled="procesando === reserva.id" @click="completar(reserva)">
                Confirmar llegada
              </BaseButton>
              <!-- Fase 4, tarea 3 (RN-M05-07): el no-show solo cuando ya venció la tolerancia. -->
              <BaseButton v-escritura
                v-if="yaVencioTolerancia(reserva)"
                size="sm"
                variant="ghost"
                :disabled="procesando === reserva.id"
                @click="marcarNoShow(reserva)"
              >
                Marcar no-show
              </BaseButton>
              <BaseButton v-escritura size="sm" variant="danger-soft" :disabled="procesando === reserva.id" @click="cancelar(reserva)">
                Cancelar
              </BaseButton>
            </template>
          </div>
        </li>
      </ul>

      <div v-if="ultimaPagina > 1" class="mt-4 flex items-center justify-center gap-3 text-sm text-gray-500">
        <BaseButton variant="ghost" :disabled="paginaActual <= 1" @click="cambiarPagina(-1)">Anterior</BaseButton>
        <span>Página {{ paginaActual }} de {{ ultimaPagina }}</span>
        <BaseButton variant="ghost" :disabled="paginaActual >= ultimaPagina" @click="cambiarPagina(1)">Siguiente</BaseButton>
      </div>
    </template>

    <!-- Depósito manual (RN-M05-16) -->
    <BaseModal :model-value="!!reservaDeposito" title="Registrar depósito manual" @update:model-value="reservaDeposito = null">
      <form class="space-y-4" @submit.prevent="confirmarDepositoManual">
        <p class="text-sm text-gray-600">
          Reserva de <strong>{{ reservaDeposito?.cliente_nombre }}</strong> por
          {{ formatoPesos.format(reservaDeposito?.deposito_valor ?? 0) }}.
        </p>
        <div>
          <p class="field-label">Método de pago</p>
          <div class="grid grid-cols-3 gap-2">
            <button
              v-for="opcion in METODOS"
              :key="opcion.v"
              type="button"
              class="rounded-xl border px-3 py-3 text-sm font-semibold transition active:scale-[0.98]"
              :class="metodoDeposito === opcion.v ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-brand-300'"
              @click="metodoDeposito = opcion.v"
            >
              {{ opcion.t }}
            </button>
          </div>
        </div>

        <EfectivoRecibido
          v-if="metodoDeposito === 'efectivo'"
          v-model="recibidoDeposito"
          class="!mt-0"
          obligatorio
          :total="Number(reservaDeposito?.deposito_valor ?? 0)"
        />
        <FotoComprobante v-else v-model="fotoDeposito" class="!mt-0" :obligatoria="metodoDeposito === 'transferencia'" />

        <p v-if="errorDeposito" class="alert-error">{{ errorDeposito }}</p>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="reservaDeposito = null">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="registrandoDeposito">
            {{ registrandoDeposito ? 'Registrando…' : 'Registrar depósito' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>

    <BaseModal :model-value="!!fotoVista" :title="fotoVista?.titulo ?? ''" @update:model-value="cerrarFotoDeposito">
      <template v-if="fotoVista">
        <iframe v-if="fotoVista.pdf" :src="fotoVista.url" title="Comprobante en PDF" class="h-[70vh] w-full rounded-xl" />
        <img v-else :src="fotoVista.url" alt="Foto del comprobante" class="mx-auto max-h-[70vh] rounded-xl object-contain" />
      </template>
    </BaseModal>

    <!-- Nueva reserva por teléfono (RF-M05-013) -->
    <BaseModal :model-value="nuevaAbierta" title="Nueva reserva" @update:model-value="nuevaAbierta = false">
      <form class="space-y-4" novalidate @submit.prevent="crearNueva">
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="field-label">Fecha</span>
            <input v-model="nueva.fecha" type="date" :min="fechaMinimaReserva(horario, ahora)" class="field" @change="nueva.hora = ''" />
          </label>
          <label class="block">
            <span class="field-label">Hora</span>
            <select v-model="nueva.hora" class="field" :disabled="!nueva.fecha">
              <option value="" disabled>{{ nueva.fecha ? 'Elige la hora' : 'Primero la fecha' }}</option>
              <option v-for="h in horasNueva" :key="h.valor" :value="h.valor" :disabled="!h.disponible">
                {{ h.texto }}{{ h.disponible ? '' : ' · no disponible' }}
              </option>
            </select>
          </label>
        </div>
        <p class="-mt-2 text-xs text-gray-500">{{ textoHorario(horario) }}</p>
        <p v-if="erroresNueva.fecha_hora" class="-mt-2 text-xs text-red-600">{{ erroresNueva.fecha_hora }}</p>

        <label class="block">
          <span class="field-label">Mesa</span>
          <select v-model.number="nueva.mesa_id" class="field" :disabled="!nueva.fecha || !nueva.hora || buscandoMesas">
            <option :value="0">
              {{
                !nueva.fecha || !nueva.hora
                  ? 'Primero elige fecha y hora'
                  : buscandoMesas
                    ? 'Buscando mesas…'
                    : mesasLibres.length
                      ? 'Elige una mesa'
                      : 'No hay mesas libres a esa hora'
              }}
            </option>
            <option v-for="mesa in mesasLibres" :key="mesa.id" :value="mesa.id">{{ mesa.nombre }}</option>
          </select>
          <span v-if="erroresNueva.mesa_id" class="mt-1 block text-xs text-red-600">{{ erroresNueva.mesa_id }}</span>
        </label>

        <label class="block">
          <span class="field-label">Nombre del cliente</span>
          <input v-formato="'persona'" v-model="nueva.nombre" type="text" maxlength="255" class="field" />
          <span v-if="erroresNueva.cliente_nombre" class="mt-1 block text-xs text-red-600">{{ erroresNueva.cliente_nombre }}</span>
        </label>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Teléfono</span>
            <input v-formato="'celular'" v-model="nueva.telefono" type="tel" inputmode="tel" maxlength="30" class="field" />
            <span v-if="erroresNueva.cliente_telefono" class="mt-1 block text-xs text-red-600">{{ erroresNueva.cliente_telefono }}</span>
          </label>
          <label class="block">
            <span class="field-label">Correo</span>
            <input v-formato="'correo'" v-model="nueva.correo" type="email" maxlength="255" class="field" />
            <span v-if="erroresNueva.cliente_correo" class="mt-1 block text-xs text-red-600">{{ erroresNueva.cliente_correo }}</span>
          </label>
        </div>
        <label class="block">
          <span class="field-label">Descripción (opcional)</span>
          <textarea v-formato="'texto'" v-model="nueva.descripcion" rows="2" maxlength="500" class="field resize-none" placeholder="Cumpleaños, silla para bebé, cerca de la ventana…" />
          <span v-if="erroresNueva.descripcion" class="mt-1 block text-xs text-red-600">{{ erroresNueva.descripcion }}</span>
        </label>
        <p class="text-xs text-gray-500">
          La reserva queda pendiente hasta registrar el depósito<template v-if="depositoVigente">
            de {{ formatoPesos.format(depositoVigente) }}</template> en efectivo, tarjeta o transferencia.
        </p>

        <p v-if="errorNueva" class="alert-error">{{ errorNueva }}</p>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="nuevaAbierta = false">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="creandoNueva">
            {{ creandoNueva ? 'Guardando…' : 'Crear reserva' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>

    <!-- Depósitos retenidos por no-show (RF-M05-035, HU-M05-015) -->
    <BaseModal v-model="modalRetenidosAbierto" title="Depósitos retenidos por no-show">
      <div v-if="cargandoRetenidos" class="loading-state">Cargando…</div>
      <p v-else-if="errorRetenidos" class="alert-error">{{ errorRetenidos }}</p>
      <template v-else>
        <p class="alert-error">
          Total retenido: <strong>{{ formatoPesos.format(totalRetenido) }}</strong>
          ({{ reservasRetenidas.length === 1 ? '1 reserva' : `${reservasRetenidas.length} reservas` }})
        </p>
        <ul v-if="reservasRetenidas.length" class="mt-4 max-h-80 space-y-2 overflow-y-auto">
          <li v-for="reserva in reservasRetenidas" :key="reserva.id" class="rounded-xl border border-gray-200 bg-gray-50/60 p-3 text-sm">
            <p class="font-semibold text-ink">{{ reserva.cliente_nombre }}</p>
            <p class="text-xs text-gray-500">
              {{ reserva.mesa?.nombre ?? `Mesa #${reserva.mesa_id}` }} · {{ fechaTexto(reserva.fecha_hora) }} ·
              {{ formatoPesos.format(reserva.deposito_valor) }}
            </p>
          </li>
        </ul>
        <p v-else class="mt-4 text-sm text-gray-500">No hay depósitos retenidos actualmente.</p>
      </template>
    </BaseModal>

    <RevisarComprobanteModal
      :abierto="!!revisionDeposito"
      :titulo="revisionDeposito ? `Depósito · reserva #${revisionDeposito.reserva.id}` : ''"
      :monto="Number(revisionDeposito?.reserva.deposito_valor ?? 0)"
      :medio="revisionDeposito?.reserva.deposito_pasarela"
      :referencia="revisionDeposito?.reserva.deposito_referencia"
      :cargar="
        revisionDeposito?.reserva.deposito_tiene_comprobante
          ? () => reservaService.comprobanteDeposito(revisionDeposito!.reserva.id)
          : null
      "
      :rechazar="revisionDeposito?.rechazar"
      :procesando="!!revisionDeposito && procesando === revisionDeposito.reserva.id"
      :error="errorRevision"
      texto-aprobar="Confirmar depósito"
      @cerrar="revisionDeposito = null"
      @aprobar="aprobarDepositoRevisado"
      @rechazar="rechazarDeposito"
    />
  </div>
</template>
