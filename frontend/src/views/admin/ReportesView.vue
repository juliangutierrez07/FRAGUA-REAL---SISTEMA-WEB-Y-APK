<script setup lang="ts">
// M08 — Reportes. Solo lectura, exclusivo del Administrador (RN-M08-01/02/07,
// RF-M08-013): cada cifra se agrega en vivo sobre lo que M02/M07 (pedidos), M04
// (catálogo) y M06 (pagos) ya registraron — esta vista no crea ni edita nada.
//
// Parte 1 del cronograma (ya construida): selector de periodo (RF-M08-001, RN-M08-03:
// semana actual por defecto) + ventas totales (RF-M08-002), ventas por producto y
// categoría con más/menos vendidos (RF-M08-003/004), y ventas por tipo de venta y método
// de pago (RF-M08-005/006).
// Parte 2 (ya construida): compras y gastos (RF-M08-007), resultado operativo (RF-M08-008,
// RN-M08-06) y uso de mesas (RF-M08-009), sobre el mismo periodo elegido arriba.
// Parte 3 (esta entrega, cierra M08): comparar contra el periodo anterior (RF-M08-010,
// RN-M08-04) con un checkbox que agrega variación ▲/▼ a las tarjetas clave, y exportar a
// PDF/Excel con auditoría (RF-M08-011/012/014).
import { computed, onMounted, ref } from 'vue'
import BaseButton from '@/components/common/BaseButton.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import {
  reporteService,
  type Comparacion,
  type ComprasYGastosDelPeriodoReporte,
  type PeriodoElegido,
  type PeriodoReporte,
  type ReporteExportable,
  type ReporteExportacion,
  type ResultadoOperativoReporte,
  type UsoDeMesasPorEstado,
  type UsoDeMesasReporte,
  type VariacionMetrica,
  type VentaPorMetodo,
  type VentaPorTipo,
  type VentasPorProductoReporte,
  type VentasPorTipoYMetodoReporte,
  type VentasTotalesReporte,
} from '@/services/reporte.service'
import { mensajeDeError } from '@/services/errors'
import { guardarArchivo, mensajeDeBlob } from '@/utils/documentos'

function hoy() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function formatoMoneda(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

// dd/mm/aaaa en hora de Colombia — 'desde'/'hasta' del periodo ya vienen como fecha de
// calendario (Y-m-d) desde el backend (ver PeriodoReporte::$desdeFecha/$hastaFecha), sin
// hora, así que no hace falta convertir huso horario aquí.
function formatoFecha(valor: string) {
  return new Date(`${valor}T00:00:00`).toLocaleDateString('es-CO', { dateStyle: 'medium' })
}

const periodoElegido = ref<PeriodoElegido>('semana') // RN-M08-03: semana actual por defecto
const desdePersonalizado = ref(hoy())
const hastaPersonalizado = ref(hoy())

const cargando = ref(false)
const error = ref('')

const periodo = ref<PeriodoReporte | null>(null)
const ventasTotales = ref<VentasTotalesReporte['reporte'] | null>(null)
const ventasPorProducto = ref<VentasPorProductoReporte['reporte'] | null>(null)
const ventasPorTipoYMetodo = ref<VentasPorTipoYMetodoReporte['reporte'] | null>(null)
const comprasYGastos = ref<ComprasYGastosDelPeriodoReporte['reporte'] | null>(null)
const resultadoOperativo = ref<ResultadoOperativoReporte['reporte'] | null>(null)
const usoDeMesas = ref<UsoDeMesasReporte['reporte'] | null>(null)

// RF-M08-010, RN-M08-04: comparar contra el periodo equivalente inmediatamente anterior —
// un solo checkbox controla los 6 reportes a la vez, misma forma de respuesta en todos.
const comparar = ref(false)
const comparacionVentasTotales = ref<Comparacion | null>(null)
const comparacionComprasYGastos = ref<Comparacion | null>(null)
const comparacionResultadoOperativo = ref<Comparacion | null>(null)
const comparacionUsoDeMesas = ref<Comparacion | null>(null)

// RF-M08-011/012/014: exportar el reporte que está en pantalla a PDF o Excel.
const reporteAExportar = ref<ReporteExportable>('ventas-totales')
const exportando = ref(false)
const opcionesExportar: { v: ReporteExportable; etiqueta: string }[] = [
  { v: 'ventas-totales', etiqueta: 'Ventas totales' },
  { v: 'ventas-por-producto', etiqueta: 'Ventas por producto' },
  { v: 'ventas-por-tipo-y-metodo', etiqueta: 'Ventas por tipo y método' },
  { v: 'compras-y-gastos', etiqueta: 'Compras y gastos' },
  { v: 'resultado-operativo', etiqueta: 'Resultado operativo' },
  { v: 'uso-de-mesas', etiqueta: 'Uso de mesas' },
]

const opcionesPeriodo: { v: PeriodoElegido; etiqueta: string }[] = [
  { v: 'semana', etiqueta: 'Semana actual' },
  { v: 'mes', etiqueta: 'Mes actual' },
  { v: 'personalizado', etiqueta: 'Rango personalizado' },
]

async function cargar() {
  if (periodoElegido.value === 'personalizado' && desdePersonalizado.value > hastaPersonalizado.value) {
    error.value = 'La fecha "desde" no puede ser posterior a la fecha "hasta".'
    return
  }

  cargando.value = true
  error.value = ''

  try {
    const desde = periodoElegido.value === 'personalizado' ? desdePersonalizado.value : undefined
    const hasta = periodoElegido.value === 'personalizado' ? hastaPersonalizado.value : undefined

    const [totales, porProducto, porTipoYMetodo, gastos, operativo, mesas] = await Promise.all([
      reporteService.ventasTotales(periodoElegido.value, desde, hasta, comparar.value),
      reporteService.ventasPorProducto(periodoElegido.value, desde, hasta, 5, comparar.value),
      reporteService.ventasPorTipoYMetodo(periodoElegido.value, desde, hasta, comparar.value),
      reporteService.comprasYGastosDelPeriodo(periodoElegido.value, desde, hasta, comparar.value),
      reporteService.resultadoOperativo(periodoElegido.value, desde, hasta, comparar.value),
      reporteService.usoDeMesas(periodoElegido.value, desde, hasta, comparar.value),
    ])

    periodo.value = totales.data.periodo
    ventasTotales.value = totales.data.reporte
    ventasPorProducto.value = porProducto.data.reporte
    ventasPorTipoYMetodo.value = porTipoYMetodo.data.reporte
    comprasYGastos.value = gastos.data.reporte
    resultadoOperativo.value = operativo.data.reporte
    usoDeMesas.value = mesas.data.reporte

    comparacionVentasTotales.value = totales.data.comparacion ?? null
    comparacionComprasYGastos.value = gastos.data.comparacion ?? null
    comparacionResultadoOperativo.value = operativo.data.comparacion ?? null
    comparacionUsoDeMesas.value = mesas.data.comparacion ?? null
  } catch (e) {
    error.value = mensajeDeError(e, 'No se pudo cargar el reporte.')
  } finally {
    cargando.value = false
  }
}

// RF-M08-010: ▲ verde cuando la métrica subió contra el periodo anterior, ▼ rojo cuando
// bajó — sin inferir si subir es "bueno" o "malo" para esa métrica en particular (eso
// depende del contexto de cada tarjeta, no del dato).
function badgeVariacion(v: VariacionMetrica | undefined) {
  if (!v) return null
  if (v.variacion_porcentual === null) return { simbolo: '', texto: 'sin dato anterior', clase: 'text-gray-400' }
  const positivo = v.variacion_porcentual >= 0
  return {
    simbolo: positivo ? '▲' : '▼',
    texto: `${Math.abs(v.variacion_porcentual).toFixed(1)}%`,
    clase: positivo ? 'text-emerald-500' : 'text-red-500',
  }
}

// RF-M08-011/012/014: exporta el reporte elegido, en el periodo ya elegido arriba, a PDF o
// Excel — el backend deja auditoría de cada exportación. 'limite' en ventas-por-producto
// calza con el 5 que se pide en cargar() (CA-M08-009/010: el archivo refleja lo mostrado).
async function exportar(formato: 'pdf' | 'excel') {
  exportando.value = true
  error.value = ''
  try {
    const desde = periodoElegido.value === 'personalizado' ? desdePersonalizado.value : undefined
    const hasta = periodoElegido.value === 'personalizado' ? hastaPersonalizado.value : undefined
    const limite = reporteAExportar.value === 'ventas-por-producto' ? 5 : undefined

    const respuesta = await reporteService.exportar(reporteAExportar.value, formato, periodoElegido.value, desde, hasta, limite, comparar.value)

    const disposicion = (respuesta.headers?.['content-disposition'] as string | undefined) ?? ''
    const coincidencia = disposicion.match(/filename="?([^"]+)"?/)
    const nombreArchivo = coincidencia?.[1] ?? `${reporteAExportar.value}.${formato === 'pdf' ? 'pdf' : 'xlsx'}`
    guardarArchivo(respuesta.data, nombreArchivo)
    await cargarExportaciones()
  } catch (e) {
    error.value = await mensajeDeBlob(e, 'No se pudo exportar el reporte.')
  } finally {
    exportando.value = false
  }
}

// ---- Fase 5, tarea 3 (RNF-M08-006): exportaciones guardadas, se descargan tal cual se generaron ----
const exportaciones = ref<ReporteExportacion[]>([])
const descargando = ref<number | null>(null)
const errorExportaciones = ref('')
const NOMBRE_REPORTE = Object.fromEntries(opcionesExportar.map((o) => [o.v, o.etiqueta])) as Record<ReporteExportable, string>

async function cargarExportaciones() {
  try {
    exportaciones.value = (await reporteService.exportaciones()).data.data
  } catch {
    exportaciones.value = []
  }
}

async function descargarGuardada(exportacion: ReporteExportacion) {
  descargando.value = exportacion.id
  errorExportaciones.value = ''
  try {
    const { data } = await reporteService.descargarExportacion(exportacion.id)
    guardarArchivo(data, exportacion.nombre_archivo)
    exportacion.descargas++
  } catch (e) {
    errorExportaciones.value = await mensajeDeBlob(e, 'No se pudo descargar el archivo.')
  } finally {
    descargando.value = null
  }
}

function fechaHora(valor: string) {
  return new Date(valor).toLocaleString('es-CO', { timeZone: 'America/Bogota', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
}

function elegirPeriodo(v: PeriodoElegido) {
  periodoElegido.value = v
  if (v !== 'personalizado') cargar()
}

onMounted(() => {
  cargar()
  cargarExportaciones()
})

// RF-M08-004: más y menos vendidos ya vienen separados del backend, sobre el mismo
// listado de productos — aquí solo se normaliza el ancho de la barra contra el máximo de
// cada listado (son dos magnitudes independientes, cada una con su propio eje).
function anchoBarra(valor: number, maximo: number) {
  if (maximo <= 0) return '0%'
  return `${Math.max(4, Math.round((valor / maximo) * 100))}%`
}

const maxMasVendidos = computed(() =>
  Math.max(1, ...(ventasPorProducto.value?.detalle.mas_vendidos.map((p) => p.monto_vendido) ?? [0]))
)
const maxMenosVendidos = computed(() =>
  Math.max(1, ...(ventasPorProducto.value?.detalle.menos_vendidos.map((p) => p.monto_vendido) ?? [0]))
)

// Identidad fija por categoría (RF-M08-005/006) — el color sigue al tipo/método, nunca a
// su posición en la lista, para que no se repinten si un periodo trae menos categorías.
// Paleta categórica validada (skill de dataviz): azul/naranja/aqua/amarillo, en ese orden.
const colorTipo: Record<VentaPorTipo['tipo_venta'], string> = {
  bebida: '#2a78d6',
  alimento: '#eb6834',
  otro: '#1baf7a',
}
const etiquetaTipo: Record<VentaPorTipo['tipo_venta'], string> = {
  bebida: 'Bebida',
  alimento: 'Alimento',
  otro: 'Otro',
}

const colorMetodo: Record<VentaPorMetodo['metodo'], string> = {
  efectivo: '#2a78d6',
  tarjeta: '#eb6834',
  transferencia: '#1baf7a',
  en_linea: '#eda100',
  deposito_reserva: '#8c6bd6',
}
const etiquetaMetodo: Record<VentaPorMetodo['metodo'], string> = {
  efectivo: 'Efectivo',
  tarjeta: 'Tarjeta',
  transferencia: 'Transferencia',
  en_linea: 'En línea',
  // Fase 2, tarea 2: el depósito de una reserva abonado a la cuenta de la visita.
  deposito_reserva: 'Depósito de reserva',
}

const maxPorTipo = computed(() =>
  Math.max(1, ...(ventasPorTipoYMetodo.value?.detalle.por_tipo.map((t) => t.monto_vendido) ?? [0]))
)
const maxPorMetodo = computed(() =>
  Math.max(1, ...(ventasPorTipoYMetodo.value?.detalle.por_metodo.map((m) => m.monto_total) ?? [0]))
)

// RF-M08-009: estados de reserva de M05 — identidad fija, mismo criterio que
// colorTipo/colorMetodo arriba (nunca por posición en la lista).
const colorEstadoReserva: Record<UsoDeMesasPorEstado['estado'], string> = {
  pendiente_pago: '#eda100',
  confirmada: '#2a78d6',
  completada: '#1baf7a',
  cancelada: '#eb6834',
  no_show: '#e34948',
}
const etiquetaEstadoReserva: Record<UsoDeMesasPorEstado['estado'], string> = {
  pendiente_pago: 'Pendiente de pago',
  confirmada: 'Confirmada',
  completada: 'Completada',
  cancelada: 'Cancelada',
  no_show: 'No-show',
}

const maxPorEstadoReserva = computed(() =>
  Math.max(1, ...(usoDeMesas.value?.detalle.por_estado.map((e) => e.cantidad) ?? [0]))
)
const maxOcupacionPorMesa = computed(() =>
  Math.max(1, ...(usoDeMesas.value?.detalle.ocupacion_por_mesa.map((m) => m.visitas + m.reservas_completadas) ?? [0]))
)
</script>

<template>
  <div class="pb-10">
    <PageHeader
      eyebrow="ANÁLISIS"
      titulo="Reportes"
      descripcion="Solo lectura — agrega lo que ya registraron Pedidos, Catálogo y Caja. No modifica ningún registro."
    />

    <!-- RF-M08-001: selector de periodo -->
    <div class="card mt-7 p-4 sm:p-5">
      <div class="flex flex-wrap items-center gap-2.5">
        <div class="segmented">
          <button
            v-for="opcion in opcionesPeriodo"
            :key="opcion.v"
            type="button"
            class="segmented-item"
            :class="periodoElegido === opcion.v ? 'is-active' : ''"
            @click="elegirPeriodo(opcion.v)"
          >
            {{ opcion.etiqueta }}
          </button>
        </div>

        <template v-if="periodoElegido === 'personalizado'">
          <input v-model="desdePersonalizado" type="date" class="field !w-auto" />
          <span class="text-sm text-gray-400">a</span>
          <input v-model="hastaPersonalizado" type="date" class="field !w-auto" />
          <BaseButton variant="primary" @click="cargar">Aplicar</BaseButton>
        </template>

        <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-600">
          <input
            v-model="comparar"
            type="checkbox"
            class="size-4 accent-brand-600"
            @change="cargar"
          />
          Comparar con el periodo anterior
        </label>

        <span v-if="periodo" class="flex items-center gap-2 text-sm text-gray-500 sm:ml-auto">
          {{ formatoFecha(periodo.desde) }} – {{ formatoFecha(periodo.hasta) }}
          <!-- RF-M08-015/RN-M08-05: el periodo en curso no es un periodo ya cerrado -->
          <span v-if="periodo.parcial" class="badge bg-amber-50 text-amber-700 ring-amber-600/20">
            Periodo en curso
          </span>
        </span>
      </div>

      <!-- RF-M08-010: rango del periodo anterior contra el que se está comparando -->
      <p v-if="comparar && comparacionVentasTotales" class="mt-2 text-xs text-gray-400">
        Comparando contra {{ formatoFecha(comparacionVentasTotales.periodo_anterior.desde) }} –
        {{ formatoFecha(comparacionVentasTotales.periodo_anterior.hasta) }}
      </p>

      <p v-if="error" class="alert-error mt-3">{{ error }}</p>

      <!-- RF-M08-011/012/014: exportar el reporte en pantalla a PDF o Excel -->
      <div class="mt-4 flex flex-col gap-2.5 border-t border-gray-200 pt-4 sm:flex-row sm:flex-wrap sm:items-center">
        <label class="field-label !mb-0" for="reporte-a-exportar">Exportar</label>
        <select id="reporte-a-exportar" v-model="reporteAExportar" class="field sm:!w-auto">
          <option v-for="opcion in opcionesExportar" :key="opcion.v" :value="opcion.v">{{ opcion.etiqueta }}</option>
        </select>
        <div class="grid grid-cols-2 gap-2 sm:flex">
          <BaseButton variant="ghost" :disabled="exportando" @click="exportar('pdf')">
            {{ exportando ? 'Exportando…' : 'Exportar PDF' }}
          </BaseButton>
          <BaseButton variant="ghost" :disabled="exportando" @click="exportar('excel')">
            {{ exportando ? 'Exportando…' : 'Exportar Excel' }}
          </BaseButton>
        </div>
      </div>
    </div>

    <div v-if="cargando" class="loading-state">Cargando reportes…</div>

    <template
      v-else-if="ventasTotales && ventasPorProducto && ventasPorTipoYMetodo && comprasYGastos && resultadoOperativo && usoDeMesas"
    >
      <!-- RF-M08-002: ventas totales -->
      <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="relative overflow-hidden rounded-2xl bg-ink-900 p-5 text-white shadow-pop sm:p-6">
          <span class="pointer-events-none absolute -right-10 -top-10 size-40 rounded-full bg-brand-500/20 blur-3xl" aria-hidden="true" />
          <p class="relative text-[11px] font-semibold uppercase tracking-[0.14em] text-white/55">Monto vendido en el periodo</p>
          <p class="relative mt-3 font-sans text-4xl font-semibold leading-none text-brand-400">{{ formatoMoneda(ventasTotales.resumen.monto_total) }}</p>
          <p
            v-if="badgeVariacion(comparacionVentasTotales?.variacion.monto_total)"
            class="mt-1 text-xs font-semibold"
            :class="badgeVariacion(comparacionVentasTotales?.variacion.monto_total)!.clase"
          >
            {{ badgeVariacion(comparacionVentasTotales?.variacion.monto_total)!.simbolo }}
            {{ badgeVariacion(comparacionVentasTotales?.variacion.monto_total)!.texto }}
            <span class="font-normal text-gray-400">vs. periodo anterior</span>
          </p>
        </div>
        <div class="card p-4 sm:p-5">
          <p class="text-xs font-medium text-gray-500">Pedidos finalizados</p>
          <p class="mt-1 font-sans text-3xl font-semibold text-ink">{{ ventasTotales.resumen.cantidad_pedidos }}</p>
          <p
            v-if="badgeVariacion(comparacionVentasTotales?.variacion.cantidad_pedidos)"
            class="mt-1 text-xs font-semibold"
            :class="badgeVariacion(comparacionVentasTotales?.variacion.cantidad_pedidos)!.clase"
          >
            {{ badgeVariacion(comparacionVentasTotales?.variacion.cantidad_pedidos)!.simbolo }}
            {{ badgeVariacion(comparacionVentasTotales?.variacion.cantidad_pedidos)!.texto }}
            <span class="font-normal text-gray-400">vs. periodo anterior</span>
          </p>
        </div>
      </div>

      <!-- RF-M08-003/004: ventas por producto y categoría -->
      <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="card p-4 sm:p-5">
          <h2 class="section-title">Productos más vendidos</h2>
          <p v-if="ventasPorProducto.detalle.mas_vendidos.length === 0" class="mt-3 text-sm text-gray-400">
            Sin ventas en este periodo.
          </p>
          <ul v-else class="mt-3 space-y-2.5">
            <li v-for="p in ventasPorProducto.detalle.mas_vendidos" :key="p.clave">
              <div class="flex items-baseline justify-between text-sm">
                <span class="font-medium text-ink">
                  {{ p.nombre }}
                  <span v-if="p.es_combo" class="ml-1 text-xs font-normal text-gray-400">combo</span>
                </span>
                <span class="text-gray-500">{{ p.unidades_vendidas }} uds. · {{ formatoMoneda(p.monto_vendido) }}</span>
              </div>
              <div class="mt-1 h-2 rounded-full bg-gray-100">
                <div
                  class="h-2 rounded-full bg-brand-500"
                  :style="{ width: anchoBarra(p.monto_vendido, maxMasVendidos) }"
                />
              </div>
            </li>
          </ul>
        </div>

        <div class="card p-4 sm:p-5">
          <h2 class="section-title">Productos menos vendidos</h2>
          <p v-if="ventasPorProducto.detalle.menos_vendidos.length === 0" class="mt-3 text-sm text-gray-400">
            Sin ventas en este periodo.
          </p>
          <ul v-else class="mt-3 space-y-2.5">
            <li v-for="p in ventasPorProducto.detalle.menos_vendidos" :key="p.clave">
              <div class="flex items-baseline justify-between text-sm">
                <span class="font-medium text-ink">
                  {{ p.nombre }}
                  <span v-if="p.es_combo" class="ml-1 text-xs font-normal text-gray-400">combo</span>
                </span>
                <span class="text-gray-500">{{ p.unidades_vendidas }} uds. · {{ formatoMoneda(p.monto_vendido) }}</span>
              </div>
              <div class="mt-1 h-2 rounded-full bg-gray-100">
                <div
                  class="h-2 rounded-full bg-gray-400"
                  :style="{ width: anchoBarra(p.monto_vendido, maxMenosVendidos) }"
                />
              </div>
            </li>
          </ul>
        </div>
      </div>

      <div v-if="ventasPorProducto.detalle.por_categoria.length" class="card mt-4 p-4 sm:p-5">
        <h2 class="section-title">Ventas por categoría</h2>
        <ul class="mt-3 divide-y divide-gray-100">
          <li
            v-for="c in ventasPorProducto.detalle.por_categoria"
            :key="c.id"
            class="flex items-center justify-between py-2 text-sm"
          >
            <span class="text-gray-700">{{ c.nombre }}</span>
            <span class="text-gray-500">{{ c.unidades_vendidas }} uds. · {{ formatoMoneda(c.monto_vendido) }}</span>
          </li>
        </ul>
      </div>

      <!-- RF-M08-005/006: ventas por tipo de venta y por método de pago -->
      <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="card p-4 sm:p-5">
          <h2 class="section-title">Por tipo de venta</h2>
          <p v-if="ventasPorTipoYMetodo.detalle.por_tipo.length === 0" class="mt-3 text-sm text-gray-400">
            Sin ventas en este periodo.
          </p>
          <ul v-else class="mt-3 space-y-2.5">
            <li v-for="t in ventasPorTipoYMetodo.detalle.por_tipo" :key="t.tipo_venta">
              <div class="flex items-baseline justify-between text-sm">
                <span class="flex items-center gap-1.5 font-medium text-gray-700">
                  <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: colorTipo[t.tipo_venta] }" />
                  {{ etiquetaTipo[t.tipo_venta] }}
                </span>
                <span class="text-gray-500">{{ t.unidades_vendidas }} uds. · {{ formatoMoneda(t.monto_vendido) }}</span>
              </div>
              <div class="mt-1 h-2 rounded-full bg-gray-100">
                <div
                  class="h-2 rounded-full"
                  :style="{ width: anchoBarra(t.monto_vendido, maxPorTipo), backgroundColor: colorTipo[t.tipo_venta] }"
                />
              </div>
            </li>
          </ul>
        </div>

        <div class="card p-4 sm:p-5">
          <h2 class="section-title">Por método de pago</h2>
          <p class="text-xs text-gray-400">Pagos aprobados y no anulados (M06).</p>
          <p v-if="ventasPorTipoYMetodo.detalle.por_metodo.length === 0" class="mt-3 text-sm text-gray-400">
            Sin pagos en este periodo.
          </p>
          <ul v-else class="mt-3 space-y-2.5">
            <li v-for="m in ventasPorTipoYMetodo.detalle.por_metodo" :key="m.metodo">
              <div class="flex items-baseline justify-between text-sm">
                <span class="flex items-center gap-1.5 font-medium text-gray-700">
                  <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: colorMetodo[m.metodo] }" />
                  {{ etiquetaMetodo[m.metodo] }}
                </span>
                <span class="text-gray-500">{{ m.cantidad }} pagos · {{ formatoMoneda(m.monto_total) }}</span>
              </div>
              <div class="mt-1 h-2 rounded-full bg-gray-100">
                <div
                  class="h-2 rounded-full"
                  :style="{ width: anchoBarra(m.monto_total, maxPorMetodo), backgroundColor: colorMetodo[m.metodo] }"
                />
              </div>
            </li>
          </ul>
        </div>
      </div>

      <!-- RF-M08-007: compras y gastos del periodo (M03) -->
      <div class="card mt-4 p-4 sm:p-5">
        <h2 class="section-title">Compras y gastos</h2>
        <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div class="rounded-xl bg-gray-50 p-3.5">
            <p class="text-xs font-medium text-gray-500">Total compras</p>
            <p class="mt-1 font-sans text-2xl font-semibold text-ink">{{ formatoMoneda(comprasYGastos.resumen.total_compras) }}</p>
            <p
              v-if="badgeVariacion(comparacionComprasYGastos?.variacion.total_compras)"
              class="mt-1 text-xs font-semibold"
              :class="badgeVariacion(comparacionComprasYGastos?.variacion.total_compras)!.clase"
            >
              {{ badgeVariacion(comparacionComprasYGastos?.variacion.total_compras)!.simbolo }}
              {{ badgeVariacion(comparacionComprasYGastos?.variacion.total_compras)!.texto }}
            </p>
          </div>
          <div class="rounded-xl bg-gray-50 p-3.5">
            <p class="text-xs font-medium text-gray-500">Total gastos</p>
            <p class="mt-1 font-sans text-2xl font-semibold text-ink">{{ formatoMoneda(comprasYGastos.resumen.total_gastos) }}</p>
            <p
              v-if="badgeVariacion(comparacionComprasYGastos?.variacion.total_gastos)"
              class="mt-1 text-xs font-semibold"
              :class="badgeVariacion(comparacionComprasYGastos?.variacion.total_gastos)!.clase"
            >
              {{ badgeVariacion(comparacionComprasYGastos?.variacion.total_gastos)!.simbolo }}
              {{ badgeVariacion(comparacionComprasYGastos?.variacion.total_gastos)!.texto }}
            </p>
          </div>
        </div>
        <p v-if="comprasYGastos.detalle.por_categoria.length === 0" class="mt-3 text-sm text-gray-400">
          Sin compras ni gastos en este periodo.
        </p>
        <ul v-else class="mt-3 divide-y divide-gray-100">
          <li
            v-for="(c, i) in comprasYGastos.detalle.por_categoria"
            :key="`${c.tipo}-${c.categoria}-${i}`"
            class="flex items-center justify-between py-2 text-sm"
          >
            <span class="flex items-center gap-2 text-gray-700">
              <span
                class="badge"
                :class="c.tipo === 'compra' ? 'bg-sky-50 text-sky-700 ring-sky-600/20' : 'bg-amber-50 text-amber-700 ring-amber-600/20'"
              >
                {{ c.tipo === 'compra' ? 'Compra' : 'Gasto' }}
              </span>
              {{ c.categoria ?? 'Sin categoría' }}
            </span>
            <span class="text-gray-500">{{ c.cantidad }} registros · {{ formatoMoneda(c.total) }}</span>
          </li>
        </ul>
      </div>

      <!-- RF-M08-008, RN-M08-06: resultado operativo del periodo -->
      <div class="card mt-4 p-4 sm:p-5">
        <h2 class="section-title">Resultado operativo</h2>
        <p class="text-xs text-gray-400">
          Ventas + depósitos retenidos por no-show − compras − gastos. No es un arqueo de caja ni la utilidad contable real.
        </p>
        <div class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-5">
          <div>
            <p class="text-xs font-medium text-gray-500">Ventas</p>
            <p class="mt-1 font-sans text-xl font-semibold text-ink">{{ formatoMoneda(resultadoOperativo.resumen.total_ventas) }}</p>
            <p
              v-if="badgeVariacion(comparacionResultadoOperativo?.variacion.total_ventas)"
              class="mt-1 text-xs font-semibold"
              :class="badgeVariacion(comparacionResultadoOperativo?.variacion.total_ventas)!.clase"
            >
              {{ badgeVariacion(comparacionResultadoOperativo?.variacion.total_ventas)!.simbolo }}
              {{ badgeVariacion(comparacionResultadoOperativo?.variacion.total_ventas)!.texto }}
            </p>
          </div>
          <!-- Mejora (16/10/2026): el depósito que el negocio retuvo cuando el cliente no llegó. -->
          <div>
            <p class="text-xs font-medium text-gray-500">Depósitos retenidos (no-show)</p>
            <p class="mt-1 font-sans text-xl font-semibold text-ink">{{ formatoMoneda(resultadoOperativo.resumen.otros_ingresos ?? 0) }}</p>
            <p
              v-if="badgeVariacion(comparacionResultadoOperativo?.variacion.otros_ingresos)"
              class="mt-1 text-xs font-semibold"
              :class="badgeVariacion(comparacionResultadoOperativo?.variacion.otros_ingresos)!.clase"
            >
              {{ badgeVariacion(comparacionResultadoOperativo?.variacion.otros_ingresos)!.simbolo }}
              {{ badgeVariacion(comparacionResultadoOperativo?.variacion.otros_ingresos)!.texto }}
            </p>
          </div>
          <div>
            <p class="text-xs font-medium text-gray-500">Compras</p>
            <p class="mt-1 font-sans text-xl font-semibold text-ink">{{ formatoMoneda(resultadoOperativo.resumen.total_compras) }}</p>
            <p
              v-if="badgeVariacion(comparacionResultadoOperativo?.variacion.total_compras)"
              class="mt-1 text-xs font-semibold"
              :class="badgeVariacion(comparacionResultadoOperativo?.variacion.total_compras)!.clase"
            >
              {{ badgeVariacion(comparacionResultadoOperativo?.variacion.total_compras)!.simbolo }}
              {{ badgeVariacion(comparacionResultadoOperativo?.variacion.total_compras)!.texto }}
            </p>
          </div>
          <div>
            <p class="text-xs font-medium text-gray-500">Gastos</p>
            <p class="mt-1 font-sans text-xl font-semibold text-ink">{{ formatoMoneda(resultadoOperativo.resumen.total_gastos) }}</p>
            <p
              v-if="badgeVariacion(comparacionResultadoOperativo?.variacion.total_gastos)"
              class="mt-1 text-xs font-semibold"
              :class="badgeVariacion(comparacionResultadoOperativo?.variacion.total_gastos)!.clase"
            >
              {{ badgeVariacion(comparacionResultadoOperativo?.variacion.total_gastos)!.simbolo }}
              {{ badgeVariacion(comparacionResultadoOperativo?.variacion.total_gastos)!.texto }}
            </p>
          </div>
          <div>
            <p class="text-xs font-medium text-gray-500">Resultado operativo</p>
            <p
              class="mt-1 font-display text-xl font-medium"
              :class="resultadoOperativo.resumen.resultado_operativo >= 0 ? 'text-emerald-700' : 'text-red-700'"
            >
              {{ formatoMoneda(resultadoOperativo.resumen.resultado_operativo) }}
            </p>
            <p
              v-if="badgeVariacion(comparacionResultadoOperativo?.variacion.resultado_operativo)"
              class="mt-1 text-xs font-semibold"
              :class="badgeVariacion(comparacionResultadoOperativo?.variacion.resultado_operativo)!.clase"
            >
              {{ badgeVariacion(comparacionResultadoOperativo?.variacion.resultado_operativo)!.simbolo }}
              {{ badgeVariacion(comparacionResultadoOperativo?.variacion.resultado_operativo)!.texto }}
            </p>
          </div>
        </div>
      </div>

      <!-- RF-M08-009: uso de mesas del periodo (M05) -->
      <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="card p-4 sm:p-5">
          <h2 class="section-title">Reservas por estado</h2>
          <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div>
              <p class="text-xs font-medium text-gray-500">Creadas</p>
              <p class="mt-1 font-display text-xl font-medium text-ink">{{ usoDeMesas.resumen.reservas_creadas }}</p>
              <p
                v-if="badgeVariacion(comparacionUsoDeMesas?.variacion.reservas_creadas)"
                class="mt-1 text-xs font-semibold"
                :class="badgeVariacion(comparacionUsoDeMesas?.variacion.reservas_creadas)!.clase"
              >
                {{ badgeVariacion(comparacionUsoDeMesas?.variacion.reservas_creadas)!.simbolo }}
                {{ badgeVariacion(comparacionUsoDeMesas?.variacion.reservas_creadas)!.texto }}
              </p>
            </div>
            <div>
              <p class="text-xs font-medium text-gray-500">Canceladas</p>
              <p class="mt-1 font-display text-xl font-medium text-ink">{{ usoDeMesas.resumen.reservas_canceladas }}</p>
              <p
                v-if="badgeVariacion(comparacionUsoDeMesas?.variacion.reservas_canceladas)"
                class="mt-1 text-xs font-semibold"
                :class="badgeVariacion(comparacionUsoDeMesas?.variacion.reservas_canceladas)!.clase"
              >
                {{ badgeVariacion(comparacionUsoDeMesas?.variacion.reservas_canceladas)!.simbolo }}
                {{ badgeVariacion(comparacionUsoDeMesas?.variacion.reservas_canceladas)!.texto }}
              </p>
            </div>
            <div>
              <p class="text-xs font-medium text-gray-500">No-show</p>
              <p class="mt-1 font-display text-xl font-medium text-ink">{{ usoDeMesas.resumen.reservas_no_show }}</p>
              <p
                v-if="badgeVariacion(comparacionUsoDeMesas?.variacion.reservas_no_show)"
                class="mt-1 text-xs font-semibold"
                :class="badgeVariacion(comparacionUsoDeMesas?.variacion.reservas_no_show)!.clase"
              >
                {{ badgeVariacion(comparacionUsoDeMesas?.variacion.reservas_no_show)!.simbolo }}
                {{ badgeVariacion(comparacionUsoDeMesas?.variacion.reservas_no_show)!.texto }}
              </p>
            </div>
            <div>
              <p class="text-xs font-medium text-gray-500">Mesas con uso</p>
              <p class="mt-1 font-display text-xl font-medium text-ink">{{ usoDeMesas.resumen.mesas_con_uso }}</p>
              <p
                v-if="badgeVariacion(comparacionUsoDeMesas?.variacion.mesas_con_uso)"
                class="mt-1 text-xs font-semibold"
                :class="badgeVariacion(comparacionUsoDeMesas?.variacion.mesas_con_uso)!.clase"
              >
                {{ badgeVariacion(comparacionUsoDeMesas?.variacion.mesas_con_uso)!.simbolo }}
                {{ badgeVariacion(comparacionUsoDeMesas?.variacion.mesas_con_uso)!.texto }}
              </p>
            </div>
          </div>

          <p v-if="usoDeMesas.detalle.por_estado.length === 0" class="mt-4 text-sm text-gray-400">
            Sin reservas en este periodo.
          </p>
          <ul v-else class="mt-4 space-y-2.5">
            <li v-for="e in usoDeMesas.detalle.por_estado" :key="e.estado">
              <div class="flex items-baseline justify-between text-sm">
                <span class="flex items-center gap-1.5 font-medium text-gray-700">
                  <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: colorEstadoReserva[e.estado] }" />
                  {{ etiquetaEstadoReserva[e.estado] }}
                </span>
                <span class="text-gray-500">{{ e.cantidad }}</span>
              </div>
              <div class="mt-1 h-2 rounded-full bg-gray-100">
                <div
                  class="h-2 rounded-full"
                  :style="{
                    width: anchoBarra(e.cantidad, maxPorEstadoReserva),
                    backgroundColor: colorEstadoReserva[e.estado],
                  }"
                />
              </div>
            </li>
          </ul>
        </div>

        <div class="card p-4 sm:p-5">
          <h2 class="section-title">Ocupación por mesa</h2>
          <!-- Fase 5, tarea 4 (RF-M08-009): visitas con pedidos en el local, además de las reservas. -->
          <p class="text-xs text-gray-400">
            {{ usoDeMesas.resumen.visitas }} visitas con {{ usoDeMesas.resumen.pedidos_locales }} pedidos en el local. Una visita
            empieza con el primer pedido de la mesa libre y termina cuando se cierra el último.
          </p>
          <p v-if="usoDeMesas.detalle.ocupacion_por_mesa.length === 0" class="mt-3 text-sm text-gray-400">
            No hay mesas activas.
          </p>
          <ul v-else class="mt-3 space-y-2.5">
            <li v-for="m in usoDeMesas.detalle.ocupacion_por_mesa" :key="m.id">
              <div class="flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
                <span class="font-medium text-ink">{{ m.nombre }}</span>
                <span class="text-xs text-gray-500">
                  <template v-if="m.visitas || m.reservas_completadas">
                    {{ m.visitas }} {{ m.visitas === 1 ? 'visita' : 'visitas' }} · {{ formatoMoneda(m.ventas) }}
                    <template v-if="m.minutos_promedio !== null"> · {{ m.minutos_promedio }} min en promedio</template>
                    <template v-if="m.reservas_completadas"> · {{ m.reservas_completadas }} {{ m.reservas_completadas === 1 ? 'reserva' : 'reservas' }}</template>
                  </template>
                  <template v-else>Sin uso en el periodo</template>
                </span>
              </div>
              <div class="mt-1 h-2 rounded-full bg-gray-100">
                <div
                  v-if="m.visitas || m.reservas_completadas"
                  class="h-2 rounded-full bg-brand-500"
                  :style="{ width: anchoBarra(m.visitas + m.reservas_completadas, maxOcupacionPorMesa) }"
                />
              </div>
            </li>
          </ul>
        </div>
      </div>
    </template>

    <!-- Fase 5, tarea 3 (RNF-M08-006): lo exportado queda guardado y se vuelve a descargar tal cual. -->
    <section v-if="exportaciones.length" class="card mt-6 p-4 sm:p-5">
      <p class="section-title">Exportaciones guardadas</p>
      <p class="mt-0.5 text-xs text-gray-500">
        Se descargan tal como se generaron, con las cifras de ese momento (el archivo dice cuándo y quién lo generó).
        Cada descarga queda en la auditoría.
      </p>
      <p v-if="errorExportaciones" class="alert-error mt-3">{{ errorExportaciones }}</p>
      <ul class="mt-3 divide-y divide-gray-100">
        <li v-for="x in exportaciones" :key="x.id" class="flex flex-col gap-2 py-2.5 sm:flex-row sm:items-center sm:justify-between">
          <div class="min-w-0 text-sm">
            <p class="font-semibold text-ink">
              {{ NOMBRE_REPORTE[x.reporte] ?? x.reporte }}
              <span class="ml-1 badge bg-gray-100 text-gray-700 ring-gray-500/20">{{ x.formato === 'excel' ? 'Excel' : 'PDF' }}</span>
              <span v-if="x.comparar" class="ml-1 badge bg-sky-50 text-sky-700 ring-sky-600/20">con comparación</span>
              <span v-if="x.parcial" class="ml-1 badge bg-amber-50 text-amber-700 ring-amber-600/20">parcial</span>
            </p>
            <p class="mt-0.5 text-xs text-gray-500">
              {{ formatoFecha(x.desde) }} – {{ formatoFecha(x.hasta) }} · generado {{ fechaHora(x.created_at) }}
              por {{ x.generado_por_usuario?.nombre ?? '—' }}<template v-if="x.descargas"> · descargado {{ x.descargas === 1 ? '1 vez' : `${x.descargas} veces` }} más</template>
            </p>
          </div>
          <BaseButton variant="secondary" size="sm" :disabled="descargando === x.id" @click="descargarGuardada(x)">
            {{ descargando === x.id ? 'Descargando…' : 'Descargar' }}
          </BaseButton>
        </li>
      </ul>
    </section>
  </div>
</template>
