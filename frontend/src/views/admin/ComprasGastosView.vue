<script setup lang="ts">
// M03 — Compras, Gastos y Cierre Diario (exclusivo Administrador). Ver CompraGastoController
// y CierreDiarioService en el backend; RN-M03-01 a 15 en el documento oficial del módulo.
// Fase 2, tarea 4 (12/10/2026): categoría obligatoria por tipo, cantidad y unidad informativas,
// observaciones, soporte JPG/PNG/PDF subido como archivo y visible solo con sesión (RF-M03-07,
// RNF-M03-06/11), filtros del historial con sus totales (RF-M03-13) y quién corrigió o anuló.
import { computed, onMounted, ref } from 'vue'
import { Ban, Paperclip, Pencil, Plus, Receipt, ShoppingCart, SlidersHorizontal, Wallet } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import CampoPesos from '@/components/common/CampoPesos.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import StatCard from '@/components/common/StatCard.vue'
import CierreDiarioPanel from '@/components/compras/CierreDiarioPanel.vue'
import { compraGastoService, requiereAutorizacion, type CompraGastoPayload, type FiltrosCompraGasto } from '@/services/compra-gasto.service'
import { reporteService } from '@/services/reporte.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import type { CompraGasto } from '@/types/models'

function hoy() {
  // Fecha local (Bogotá en la práctica) en formato Y-m-d — `fecha` es una columna DATE
  // simple en el backend (RN-M03-14: puede diferir de cuándo se registra), así que no hace
  // falta convertir huso horario, a diferencia de un datetime como Reserva.fecha_hora.
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// ---- Listado ----
const registros = ref<CompraGasto[]>([])
const total = ref(0)
const cargando = ref(true)
const error = ref('')
const filtroTipo = ref<'todos' | 'compra' | 'gasto'>('todos')
// RF-M03-13 (Fase 2, tarea 4): más filtros, paginación y totales de lo filtrado.
const filtros = ref<Omit<FiltrosCompraGasto, 'tipo' | 'page'>>({})
const verFiltros = ref(false)
const pagina = ref(1)
const ultimaPagina = ref(1)
const totalesFiltro = ref<{ total_compras: number; total_gastos: number } | null>(null)
const categorias = ref<Record<'compra' | 'gasto', string[]>>({ compra: [], gasto: [] })
const categoriasFiltro = computed(() =>
  filtroTipo.value === 'todos' ? [...new Set([...categorias.value.compra, ...categorias.value.gasto])] : categorias.value[filtroTipo.value],
)
const hayFiltros = computed(() => Object.values(filtros.value).some((v) => v))

async function cargarCategorias() {
  try {
    const { data } = await compraGastoService.categorias()
    categorias.value = data
  } catch {
    // Sin la lista, el formulario lo dirá al guardar.
  }
}

function aplicarFiltros() {
  pagina.value = 1
  cargar()
}

function limpiarFiltros() {
  filtros.value = {}
  aplicarFiltros()
}

const OPCIONES_TIPO: { v: typeof filtroTipo.value; t: string }[] = [
  { v: 'todos', t: 'Todos' },
  { v: 'compra', t: 'Compras' },
  { v: 'gasto', t: 'Gastos' },
]

const METODOS_PAGO: Record<CompraGasto['metodo_pago'], string> = {
  efectivo: 'Efectivo',
  transferencia: 'Transferencia',
  tarjeta: 'Tarjeta',
  otro: 'Otro',
}

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const limpios = Object.fromEntries(Object.entries(filtros.value).filter(([, v]) => v))
    const { data } = await compraGastoService.index({
      ...limpios,
      tipo: filtroTipo.value === 'todos' ? undefined : filtroTipo.value,
      page: pagina.value,
    })
    registros.value = data.data
    total.value = data.total
    ultimaPagina.value = data.last_page
    totalesFiltro.value = data.totales ?? null
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar las compras y gastos.')
  } finally {
    cargando.value = false
  }
}

function reemplazar(actualizado: CompraGasto) {
  const i = registros.value.findIndex((r) => r.id === actualizado.id)
  if (i !== -1) registros.value[i] = actualizado
}

// ---- Resumen del día (reutiliza el reporte de M08 — RN de agregación ya vive ahí) ----
const resumenDia = ref<{ total_compras: number; total_gastos: number } | null>(null)
async function cargarResumenDia() {
  try {
    const { data } = await reporteService.comprasYGastos({ desde: hoy(), hasta: hoy() })
    resumenDia.value = data.reporte.resumen
  } catch {
    resumenDia.value = null // el resumen es informativo; si falla, no bloquea la pantalla
  }
}

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

// CORREGIDO: aunque `fecha` es una columna DATE (RN-M03-14, sin hora), Eloquent la
// serializa igual como datetime ISO completo (p. ej. "2026-10-01T00:00:00.000000Z") — por
// eso NO se le debe concatenar "T00:00:00" (quedaba "...ZT00:00:00", Invalid Date).
function formatoFecha(valor: string) {
  return new Date(valor).toLocaleDateString('es-CO', { dateStyle: 'medium', timeZone: 'UTC' })
}

// ---- Crear / editar ----
const modalFormAbierto = ref(false)
const editando = ref<CompraGasto | null>(null)
function formVacio(): CompraGastoPayload {
  return {
    tipo: 'compra',
    categoria: '',
    descripcion: '',
    cantidad: null,
    unidad: '',
    valor: 0,
    metodo_pago: 'efectivo',
    proveedor: '',
    numero_factura: '',
    observaciones: '',
    fecha: hoy(),
  }
}
const form = ref<CompraGastoPayload>(formVacio())
// RF-M03-07: archivo elegido (JPG, PNG o PDF); se valida aquí y otra vez en el servidor.
const soporte = ref<File | null>(null)
const UNIDADES = ['unidades', 'kilos', 'gramos', 'litros', 'bolsas', 'paquetes', 'cajas', 'botellas']

function elegirSoporte(evento: Event) {
  const archivo = (evento.target as HTMLInputElement).files?.[0] ?? null
  erroresForm.value = { ...erroresForm.value, soporte: '' }
  if (archivo && !['image/jpeg', 'image/png', 'application/pdf'].includes(archivo.type)) {
    erroresForm.value = { ...erroresForm.value, soporte: 'El soporte debe ser una imagen JPG o PNG, o un PDF.' }
    soporte.value = null
    ;(evento.target as HTMLInputElement).value = ''
    return
  }
  soporte.value = archivo
}

function cambiarTipo(tipo: CompraGastoPayload['tipo']) {
  form.value.tipo = tipo
  if (!categorias.value[tipo].includes(form.value.categoria)) form.value.categoria = ''
}
const erroresForm = ref<Record<string, string>>({})
const errorForm = ref('')
const guardando = ref(false)
// Fase 2, tarea 5 (RN-M03-08, RF-M03-20): si la fecha ya tiene cierre, se pide la contraseña del
// Administrador y el motivo, y se reintenta. El cierre no se reescribe: queda como corrección.
const pideAutorizacion = ref(false)
const autorizacion = ref({ password: '', motivo: '' })

function abrirCrear() {
  editando.value = null
  form.value = formVacio()
  soporte.value = null
  pideAutorizacion.value = false
  autorizacion.value = { password: '', motivo: '' }
  erroresForm.value = {}
  errorForm.value = ''
  modalFormAbierto.value = true
}

function abrirEditar(registro: CompraGasto) {
  editando.value = registro
  form.value = {
    tipo: registro.tipo,
    categoria: registro.categoria ?? '',
    descripcion: registro.descripcion,
    cantidad: registro.cantidad != null ? Number(registro.cantidad) : null,
    unidad: registro.unidad ?? '',
    valor: Number(registro.valor),
    metodo_pago: registro.metodo_pago,
    proveedor: registro.proveedor ?? '',
    numero_factura: registro.numero_factura ?? '',
    observaciones: registro.observaciones ?? '',
    fecha: registro.fecha.slice(0, 10),
  }
  soporte.value = null
  pideAutorizacion.value = false
  autorizacion.value = { password: '', motivo: '' }
  erroresForm.value = {}
  errorForm.value = ''
  modalFormAbierto.value = true
}

async function confirmarGuardar() {
  guardando.value = true
  erroresForm.value = {}
  errorForm.value = ''

  const payload: CompraGastoPayload = {
    ...form.value,
    cantidad: form.value.cantidad ? Number(form.value.cantidad) : null,
    unidad: form.value.unidad || null,
    proveedor: form.value.proveedor || null,
    numero_factura: form.value.numero_factura || null,
    observaciones: form.value.observaciones || null,
    ...(pideAutorizacion.value ? { password_confirmacion: autorizacion.value.password, motivo_correccion: autorizacion.value.motivo.trim() } : {}),
  }

  try {
    if (editando.value) {
      let { data } = await compraGastoService.editar(editando.value.id, payload)
      if (soporte.value) ({ data } = await compraGastoService.adjuntarSoporte(editando.value.id, soporte.value))
      reemplazar(data)
    } else {
      await compraGastoService.crear(payload, soporte.value)
      await cargar()
    }
    await cargarResumenDia()
    await panelCierre.value?.recargar()
    modalFormAbierto.value = false
  } catch (e) {
    if (requiereAutorizacion(e)) {
      pideAutorizacion.value = true
      errorForm.value = mensajeDeError(e, 'Ese día ya tiene cierre.')
      return
    }
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) {
      erroresForm.value = campos
    } else {
      errorForm.value = mensajeDeError(e, 'No pudimos guardar el registro.')
    }
  } finally {
    guardando.value = false
  }
}

// ---- Ver soporte (RNF-M03-11: se pide con la sesión, no hay enlace público) ----
const abriendoSoporte = ref<number | null>(null)
async function verSoporte(registro: CompraGasto) {
  abriendoSoporte.value = registro.id
  error.value = ''
  // La pestaña se abre antes de pedir el archivo para que el navegador no la bloquee.
  const ventana = window.open('', '_blank')
  try {
    const { data } = await compraGastoService.verSoporte(registro.id)
    const url = URL.createObjectURL(data)
    if (ventana) ventana.location.href = url
    else window.open(url, '_blank')
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch (e) {
    ventana?.close()
    error.value = mensajeDeError(e, 'No pudimos abrir el soporte.')
  } finally {
    abriendoSoporte.value = null
  }
}

function formatoFechaHora(valor?: string | null) {
  return valor ? new Date(valor).toLocaleString('es-CO', { timeZone: 'America/Bogota', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : ''
}

// ---- Anular ----
const registroAAnular = ref<CompraGasto | null>(null)
const motivoAnular = ref('')
const passwordAnular = ref('')
const anularPideAutorizacion = ref(false)
const errorAnular = ref('')
const anulando = ref(false)

function abrirAnular(registro: CompraGasto) {
  registroAAnular.value = registro
  motivoAnular.value = ''
  passwordAnular.value = ''
  anularPideAutorizacion.value = false
  errorAnular.value = ''
}

async function confirmarAnular() {
  if (!registroAAnular.value) return
  if (!motivoAnular.value.trim()) {
    errorAnular.value = 'El motivo es obligatorio.'
    return
  }

  anulando.value = true
  errorAnular.value = ''
  try {
    const { data } = await compraGastoService.anular(registroAAnular.value.id, motivoAnular.value.trim(), passwordAnular.value)
    reemplazar(data)
    await cargarResumenDia()
    await panelCierre.value?.recargar()
    registroAAnular.value = null
  } catch (e) {
    if (requiereAutorizacion(e)) anularPideAutorizacion.value = true
    errorAnular.value = mensajeDeError(e, 'No pudimos anular el registro.')
  } finally {
    anulando.value = false
  }
}

// ---- Cierre diario: vive en CierreDiarioPanel (Fase 2, tarea 5). Se recalcula cuando cambian
// las compras y gastos. ----
const panelCierre = ref<{ recargar: () => Promise<unknown> } | null>(null)

const contadorTexto = computed(() => (total.value === 1 ? '1 registro' : `${total.value} registros`))

onMounted(() => {
  cargar()
  cargarResumenDia()
  cargarCategorias()
})
</script>

<template>
  <div>
    <PageHeader
      eyebrow="FINANZAS"
      titulo="Compras y gastos"
      descripcion="Registro de compras, otros gastos y cierre diario (M03)."
    >
      <template #actions>
        <BaseButton v-escritura variant="primary" @click="abrirCrear">
          <Plus :size="17" />
          Registrar compra o gasto
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Resumen del día — RN-M03-06/07: totalizar compras y gastos activos del día. -->
    <div class="mt-7 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
      <StatCard etiqueta="Compras de hoy" :valor="formatoPrecio(resumenDia?.total_compras ?? 0)" :icono="ShoppingCart" />
      <StatCard etiqueta="Gastos de hoy" :valor="formatoPrecio(resumenDia?.total_gastos ?? 0)" :icono="Receipt" />
      <StatCard
        class="col-span-2 sm:col-span-1"
        destacada
        etiqueta="Total pagado hoy"
        :valor="formatoPrecio((resumenDia?.total_compras ?? 0) + (resumenDia?.total_gastos ?? 0))"
        :icono="Wallet"
      />
    </div>

    <!-- Cierre diario completo (Fase 2, tarea 5): consulta previa, desglose, cierre formal,
         historial y correcciones posteriores. -->
    <CierreDiarioPanel ref="panelCierre" :fecha-inicial="hoy()" />

    <!-- Listado -->
    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between print:hidden">
      <div class="segmented">
        <button
          v-for="opcion in OPCIONES_TIPO"
          :key="opcion.v"
          type="button"
          class="segmented-item"
          :class="filtroTipo === opcion.v ? 'is-active' : ''"
          @click="filtroTipo = opcion.v; aplicarFiltros()"
        >
          {{ opcion.t }}
        </button>
      </div>
      <BaseButton variant="secondary" size="sm" @click="verFiltros = !verFiltros">
        <SlidersHorizontal :size="15" /> Filtros<template v-if="hayFiltros"> (activos)</template>
      </BaseButton>
    </div>

    <!-- RF-M03-13, HU-M03-03: filtros por fecha, categoría, forma de pago, proveedor y estado. -->
    <form v-if="verFiltros" class="card mt-3 grid gap-3 p-4 sm:grid-cols-3 print:hidden" @submit.prevent="aplicarFiltros">
      <label class="block">
        <span class="field-label">Desde</span>
        <input v-model="filtros.desde" type="date" class="field" />
      </label>
      <label class="block">
        <span class="field-label">Hasta</span>
        <input v-model="filtros.hasta" type="date" class="field" />
      </label>
      <label class="block">
        <span class="field-label">Categoría</span>
        <select v-model="filtros.categoria" class="field">
          <option :value="undefined">Todas</option>
          <option v-for="c in categoriasFiltro" :key="c" :value="c">{{ c }}</option>
        </select>
      </label>
      <label class="block">
        <span class="field-label">Forma de pago</span>
        <select v-model="filtros.metodo_pago" class="field">
          <option :value="undefined">Todas</option>
          <option v-for="(texto, valor) in METODOS_PAGO" :key="valor" :value="valor">{{ texto }}</option>
        </select>
      </label>
      <label class="block">
        <span class="field-label">Proveedor</span>
        <input v-formato="'busqueda'" v-model="filtros.proveedor" type="search" class="field" placeholder="Nombre o parte" />
      </label>
      <label class="block">
        <span class="field-label">Estado</span>
        <select v-model="filtros.estado" class="field">
          <option :value="undefined">Activos y anulados</option>
          <option value="activo">Solo activos</option>
          <option value="anulado">Solo anulados</option>
        </select>
      </label>
      <div class="flex gap-2 sm:col-span-3 sm:justify-end">
        <BaseButton variant="ghost" type="button" @click="limpiarFiltros">Limpiar</BaseButton>
        <BaseButton type="submit">Aplicar filtros</BaseButton>
      </div>
    </form>

    <p v-if="error" class="mt-4 alert-error print:hidden">{{ error }}</p>

    <div v-if="cargando" class="loading-state print:hidden">Cargando registros…</div>

    <div v-else-if="!registros.length" class="mt-10 empty-state print:hidden">
      <Receipt :size="32" class="mx-auto text-gray-300" />
      <p class="mt-3 text-sm text-gray-500">No hay compras ni gastos en este filtro.</p>
    </div>

    <template v-else>
      <p class="mt-4 text-xs text-gray-500 print:hidden">
        {{ contadorTexto }}
        <template v-if="totalesFiltro">
          · activos en este filtro: compras {{ formatoPrecio(totalesFiltro.total_compras) }}, gastos {{ formatoPrecio(totalesFiltro.total_gastos) }}
        </template>
      </p>
      <ul class="mt-2 space-y-3 print:hidden">
        <li
          v-for="registro in registros"
          :key="registro.id"
          class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-ink">
              {{ registro.descripcion }}
              <span
                class="badge"
                :class="registro.tipo === 'compra' ? 'bg-sky-50 text-sky-700 ring-sky-600/20' : 'bg-amber-50 text-amber-700 ring-amber-600/20'"
              >
                {{ registro.tipo === 'compra' ? 'Compra' : 'Gasto' }}
              </span>
              <span
                class="badge"
                :class="registro.estado === 'activo' ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'"
              >
                {{ registro.estado === 'activo' ? 'Activo' : 'Anulado' }}
              </span>
            </p>
            <p class="mt-1 text-xs text-gray-500">
              {{ formatoFecha(registro.fecha) }} · {{ METODOS_PAGO[registro.metodo_pago] }}
              <template v-if="registro.categoria"> · {{ registro.categoria }}</template>
              <template v-if="registro.cantidad"> · {{ Number(registro.cantidad) }} {{ registro.unidad ?? '' }}</template>
              <template v-if="registro.proveedor"> · {{ registro.proveedor }}</template>
              <template v-if="registro.numero_factura"> · factura {{ registro.numero_factura }}</template>
              <template v-if="registro.registrado_por_usuario"> · registrado por {{ registro.registrado_por_usuario.nombre }}</template>
              <template v-if="registro.editado_at"> · corregido por {{ registro.editado_por_usuario?.nombre ?? '—' }} el {{ formatoFechaHora(registro.editado_at) }}</template>
            </p>
            <p v-if="registro.observaciones" class="mt-1 text-xs text-gray-500">{{ registro.observaciones }}</p>
            <button
              v-if="registro.tiene_soporte"
              type="button"
              class="mt-1.5 inline-flex min-h-9 items-center gap-1.5 text-xs font-semibold text-brand-700 hover:underline"
              :disabled="abriendoSoporte === registro.id"
              @click="verSoporte(registro)"
            >
              <Paperclip :size="14" /> {{ abriendoSoporte === registro.id ? 'Abriendo…' : `Ver soporte (${registro.soporte_nombre ?? 'archivo'})` }}
            </button>
            <a v-else-if="registro.soporte_url" :href="registro.soporte_url" target="_blank" rel="noopener" class="mt-1.5 inline-flex items-center gap-1.5 text-xs font-semibold text-brand-700 hover:underline">
              <Paperclip :size="14" /> Ver enlace del soporte
            </a>
            <p v-if="registro.estado === 'anulado' && registro.anulado_motivo" class="mt-1 text-xs italic text-red-600">
              Anulado: {{ registro.anulado_motivo }}
              <template v-if="registro.anulado_at"> ({{ registro.anulado_por_usuario?.nombre ?? '—' }}, {{ formatoFechaHora(registro.anulado_at) }})</template>
            </p>
          </div>

          <div class="flex shrink-0 items-center justify-between gap-3 border-t border-gray-100 pt-3 sm:justify-end sm:border-0 sm:pt-0">
            <span class="font-sans text-xl font-semibold text-ink">{{ formatoPrecio(registro.valor) }}</span>
            <div v-if="registro.estado === 'activo'" class="flex gap-1.5">
              <button v-escritura type="button" class="flex size-10 items-center justify-center rounded-xl border border-gray-200 text-gray-500 transition hover:border-brand-400 hover:bg-brand-50 hover:text-ink" title="Editar" aria-label="Editar" @click="abrirEditar(registro)">
                <Pencil :size="16" />
              </button>
              <button v-escritura type="button" class="flex size-10 items-center justify-center rounded-xl border border-gray-200 text-red-600 transition hover:border-red-300 hover:bg-red-50" title="Anular" aria-label="Anular" @click="abrirAnular(registro)">
                <Ban :size="16" />
              </button>
            </div>
          </div>
        </li>
      </ul>

      <div v-if="ultimaPagina > 1" class="mt-6 flex items-center justify-center gap-3 print:hidden">
        <BaseButton variant="ghost" :disabled="pagina <= 1 || cargando" @click="pagina--; cargar()">Anterior</BaseButton>
        <span class="text-xs text-gray-500">{{ pagina }} / {{ ultimaPagina }}</span>
        <BaseButton variant="ghost" :disabled="pagina >= ultimaPagina || cargando" @click="pagina++; cargar()">Siguiente</BaseButton>
      </div>
    </template>

    <!-- Crear/editar -->
    <BaseModal v-model="modalFormAbierto" :title="editando ? 'Editar registro' : 'Registrar compra o gasto'">
      <form class="space-y-4" @submit.prevent="confirmarGuardar">
        <div>
          <p class="field-label">Tipo</p>
          <div class="grid grid-cols-2 gap-2">
            <button
              type="button"
              class="rounded-xl border px-3 py-3 text-sm font-semibold transition active:scale-[0.98]"
              :class="form.tipo === 'compra' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-brand-300'"
              @click="cambiarTipo('compra')"
            >
              Compra
            </button>
            <button
              type="button"
              class="rounded-xl border px-3 py-3 text-sm font-semibold transition active:scale-[0.98]"
              :class="form.tipo === 'gasto' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-brand-300'"
              @click="cambiarTipo('gasto')"
            >
              Gasto
            </button>
          </div>
        </div>

        <label class="block">
          <span class="field-label">Descripción</span>
          <input v-formato="'texto'" maxlength="255" v-model="form.descripcion" type="text" class="field" />
          <span v-if="erroresForm.descripcion" class="mt-1 block text-xs text-red-600">{{ erroresForm.descripcion }}</span>
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Valor</span>
            <CampoPesos v-model="form.valor" class="field" />
            <span v-if="erroresForm.valor" class="mt-1 block text-xs text-red-600">{{ erroresForm.valor }}</span>
          </label>
          <label class="block">
            <span class="field-label">Fecha</span>
            <input v-model="form.fecha" type="date" class="field" />
            <span v-if="erroresForm.fecha" class="mt-1 block text-xs text-red-600">{{ erroresForm.fecha }}</span>
          </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Categoría</span>
            <select v-model="form.categoria" class="field">
              <option value="" disabled>Elige una</option>
              <option v-for="c in categorias[form.tipo]" :key="c" :value="c">{{ c }}</option>
              <!-- Un registro viejo con otra categoría la conserva mientras no se cambie. -->
              <option v-if="form.categoria && !categorias[form.tipo].includes(form.categoria)" :value="form.categoria">{{ form.categoria }}</option>
            </select>
            <span v-if="erroresForm.categoria" class="mt-1 block text-xs text-red-600">{{ erroresForm.categoria }}</span>
          </label>
          <label class="block">
            <span class="field-label">Forma de pago</span>
            <select v-model="form.metodo_pago" class="field">
              <option value="efectivo">Efectivo</option>
              <option value="transferencia">Transferencia</option>
              <option value="tarjeta">Tarjeta</option>
              <option value="otro">Otro</option>
            </select>
          </label>
        </div>

        <!-- RF-M03-03/04, RN-M03-02: solo informativas, no llevan inventario. -->
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Cantidad (opcional)</span>
            <input v-formato="'cantidad'" v-model.number="form.cantidad" type="text" min="0" class="field" />
            <span v-if="erroresForm.cantidad" class="mt-1 block text-xs text-red-600">{{ erroresForm.cantidad }}</span>
          </label>
          <label class="block">
            <span class="field-label">Unidad (opcional)</span>
            <input v-formato="'nombre'" v-model="form.unidad" type="text" list="unidades-compra" maxlength="50" class="field" placeholder="kilos, bolsas, cajas…" />
            <datalist id="unidades-compra">
              <option v-for="u in UNIDADES" :key="u" :value="u" />
            </datalist>
          </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Proveedor (opcional)</span>
            <input v-formato="'nombre'" v-model="form.proveedor" type="text" class="field" />
          </label>
          <label class="block">
            <span class="field-label">N.º de factura (opcional)</span>
            <input v-formato="'referencia'" v-model="form.numero_factura" type="text" class="field" />
          </label>
        </div>

        <label class="block">
          <span class="field-label">Observaciones (opcional)</span>
          <textarea v-formato="'texto'" v-model="form.observaciones" rows="2" maxlength="1000" class="field" />
        </label>

        <!-- RF-M03-07, HU-M03-02: foto o PDF de la factura o el recibo. -->
        <label class="block">
          <span class="field-label">Soporte (opcional)</span>
          <input type="file" accept="image/jpeg,image/png,application/pdf" class="field file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-700" @change="elegirSoporte" />
          <span class="mt-1 block text-xs text-gray-400">
            JPG, PNG o PDF de hasta 5 MB.
            <template v-if="editando?.tiene_soporte"> Ya tiene uno ({{ editando.soporte_nombre }}); si eliges otro, lo reemplaza y el anterior queda guardado.</template>
          </span>
          <span v-if="erroresForm.soporte" class="mt-1 block text-xs text-red-600">{{ erroresForm.soporte }}</span>
        </label>

        <p v-if="errorForm" class="alert-error">{{ errorForm }}</p>

        <!-- RN-M03-08: el día ya está cerrado — corrección autorizada. -->
        <div v-if="pideAutorizacion" class="space-y-3 rounded-xl border border-amber-200 bg-amber-50/70 p-3">
          <label class="block">
            <span class="field-label">Tu contraseña de Administrador</span>
            <input v-model="autorizacion.password" type="password" autocomplete="current-password" class="field" />
          </label>
          <label class="block">
            <span class="field-label">Motivo de la corrección</span>
            <input v-formato="'texto'" v-model="autorizacion.motivo" type="text" maxlength="255" class="field" placeholder="Ej.: se olvidó registrar, error de digitación" />
          </label>
        </div>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="modalFormAbierto = false">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="guardando">
            {{ guardando ? 'Guardando…' : editando ? 'Guardar cambios' : 'Registrar' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>

    <!-- Anular -->
    <BaseModal :model-value="!!registroAAnular" :title="`Anular registro #${registroAAnular?.id ?? ''}`" @update:model-value="registroAAnular = null">
      <div class="space-y-4">
        <p class="text-sm text-gray-600">
          El registro queda marcado como ANULADO y se excluye de los totales — no se elimina.
        </p>
        <label class="block">
          <span class="field-label">Motivo</span>
          <textarea v-formato="'texto'" maxlength="255"
            v-model="motivoAnular"
            rows="3"
            class="field"
            placeholder="Motivo de la anulación"
          />
        </label>
        <label v-if="anularPideAutorizacion" class="block">
          <span class="field-label">Tu contraseña de Administrador (el día ya tiene cierre)</span>
          <input v-model="passwordAnular" type="password" autocomplete="current-password" class="field" />
        </label>
        <p v-if="errorAnular" class="alert-error">{{ errorAnular }}</p>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="registroAAnular = null">Volver</BaseButton>
          <BaseButton variant="danger" type="button" :disabled="anulando" @click="confirmarAnular">
            {{ anulando ? 'Anulando…' : 'Sí, anular' }}
          </BaseButton>
        </div>
      </div>
    </BaseModal>
  </div>
</template>
