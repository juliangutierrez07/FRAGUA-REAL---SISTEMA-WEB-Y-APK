<script setup lang="ts">
// M02 — Nuevo pedido Local del Mesero: pantalla completa (no modal), pensada para
// teléfono/tablet, el dispositivo real que usa el Mesero en el piso. Reemplaza el
// modal anterior — mismo patrón que ya funciona bien en el Menú del Cliente
// (tarjetas + panel de personalización, sin overlay), adaptado a la paleta neutra
// del panel operativo y con un paso previo de selección de mesa.
//
// Fase 1, tarea 2 (11/10/2026): el mismo flujo registra domicilios y recogidas que llegan por
// teléfono o WhatsApp (RF-M07-001, RF-M07-R-001). En vez de la mesa, el primer paso pide los
// datos del cliente: nombre, teléfono y, en domicilio, dirección, barrio y referencia
// (RN-M07-01), más las observaciones de entrega (RF-M07-010). Se cobra contra entrega o al
// recoger, como cualquier pedido remoto (Mesero > Cobrar).
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Armchair, Bike, ChevronUp, Minus, Plus, ReceiptText, Search, ShoppingBag, ShoppingBasket, SplitSquareHorizontal, X } from 'lucide-vue-next'
import BaseModal from '@/components/common/BaseModal.vue'
import { catalogoService } from '@/services/catalogo.service'
import { extrasDe } from '@/utils/extras'
import { componentesCombo } from '@/utils/combos'
import { mesaService } from '@/services/mesa.service'
import { pedidoService, type LineaPedidoPayload } from '@/services/pedido.service'
import { configuracionService } from '@/services/configuracion.service'
import { useCotizacion } from '@/composables/useCotizacion'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { comandaService } from '@/services/comanda.service'
import { abrirPdf } from '@/utils/documentos'
import { avisarComandas, imprimirAlConfirmar, porImprimir } from '@/utils/comandas'
import { iconoPorNombre } from '@/utils/iconoCategoria'
import { etiquetaPromocion, precioConPromocion } from '@/utils/promociones'
import type { Categoria, Combo, Extra, Mesa, Pedido, Producto, Variante } from '@/types/models'

const route = useRoute()
const router = useRouter()

// ---- Canal: mesa del salón, o domicilio / recogida pedidos por teléfono ----
type Canal = 'local' | 'domicilio' | 'recogida'
const CANALES: { v: Canal; t: string; icono: typeof Armchair }[] = [
  { v: 'local', t: 'Mesa', icono: Armchair },
  { v: 'domicilio', t: 'Domicilio', icono: Bike },
  { v: 'recogida', t: 'Recogida', icono: ShoppingBag },
]
const canalInicial = route.query.canal
const canal = ref<Canal>(canalInicial === 'domicilio' || canalInicial === 'recogida' ? canalInicial : 'local')

// ---- Paso 1 (domicilio/recogida): datos del cliente ----
const datosCliente = reactive({
  nombre: '',
  telefono: '',
  // Fase 4, tarea 5 (RF-M07-R-012): opcional, para avisarle que la recogida está lista.
  correo: '',
  direccion: '',
  barrio: '',
  referencia: '',
  observaciones: '',
})
const datosListos = ref(false)
const erroresDatos = ref<Record<string, string>>({})
const valorDomicilio = ref(0)

// Mismo criterio que StorePedidoRequest::FORMATO_TELEFONO: de 7 a 15 dígitos.
function telefonoValido(texto: string) {
  const digitos = texto.replace(/\D/g, '').length
  return /^\+?[0-9 ()-]+$/.test(texto.trim()) && digitos >= 7 && digitos <= 15
}

function continuarConDatos() {
  const errores: Record<string, string> = {}
  if (!datosCliente.nombre.trim()) errores.nombre = 'Escribe el nombre del cliente.'
  if (!telefonoValido(datosCliente.telefono)) errores.telefono = 'Escribe un teléfono válido (de 7 a 15 dígitos).'
  if (canal.value === 'recogida' && datosCliente.correo.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(datosCliente.correo.trim())) {
    errores.correo = 'Escribe un correo válido o déjalo vacío.'
  }
  if (canal.value === 'domicilio') {
    if (!datosCliente.direccion.trim()) errores.direccion = 'Escribe la dirección.'
    if (!datosCliente.barrio.trim()) errores.barrio = 'Escribe el barrio.'
    if (!datosCliente.referencia.trim()) errores.referencia = 'Escribe un punto de referencia.'
  }
  erroresDatos.value = errores
  datosListos.value = Object.keys(errores).length === 0
}

function elegirCanal(nuevo: Canal) {
  canal.value = nuevo
  datosListos.value = false
  mesaSeleccionada.value = null
  erroresDatos.value = {}
}

const destinoListo = computed(() => (canal.value === 'local' ? !!mesaSeleccionada.value : datosListos.value))

const tituloPantalla = computed(
  () => ({ local: 'Nuevo pedido local', domicilio: 'Nuevo domicilio', recogida: 'Nueva recogida' })[canal.value],
)

const nombreDestino = computed(() => {
  if (canal.value === 'local') {
    if (!mesaSeleccionada.value) return 'Elige la mesa'
    return mesaSeleccionada.value.nombre + (cuentaSeleccionada.value ? ` · Cuenta ${cuentaSeleccionada.value}` : '')
  }
  return datosListos.value ? datosCliente.nombre.trim() : 'Datos del cliente'
})

function cambiarDestino() {
  if (canal.value === 'local') cambiarMesa()
  else datosListos.value = false
}

async function cargarValorDomicilio() {
  try {
    const { data } = await configuracionService.obtener()
    valorDomicilio.value = Number(data.valor_domicilio)
  } catch {
    // Solo es para mostrar el total estimado: el servidor fija el valor real.
  }
}

// ---- Paso 1: mesa ----
const mesas = ref<Mesa[]>([])
const mesaSeleccionada = ref<Mesa | null>(null)
const cargandoMesas = ref(true)
const errorMesas = ref('')

async function cargarMesas() {
  cargandoMesas.value = true
  errorMesas.value = ''
  try {
    const { data: todas } = await mesaService.index()
    // Corrección (19/10/2026): una mesa dada de baja no recibe pedidos.
    const data = todas.filter((m) => m.activa !== false)
    mesas.value = data
    const mesaQuery = Number(route.query.mesa)
    const mesa = mesaQuery ? data.find((m) => m.id === mesaQuery) : undefined
    if (mesa) elegirMesa(mesa)
  } catch (e) {
    errorMesas.value = mensajeDeError(e, 'No pudimos cargar las mesas.')
  } finally {
    cargandoMesas.value = false
  }
}

// Mejora (26/10/2026, RF-M02-L-033): cuentas separadas. En una mesa ocupada se elige si el pedido sigue en una de
// sus cuentas o abre una cuenta nueva, que se cobra aparte.
const cuentaSeleccionada = ref<number | null>(null)
const mesaPorElegirCuenta = ref<Mesa | null>(null)
const siguienteCuenta = computed(() => Math.max(0, ...(mesaPorElegirCuenta.value?.cuentas ?? []).map((c) => c.numero)) + 1)

function elegirMesa(mesa: Mesa) {
  if (mesa.estado === 'ocupada' && mesa.cuentas?.length) {
    mesaPorElegirCuenta.value = mesa
    return
  }
  mesaSeleccionada.value = mesa
  cuentaSeleccionada.value = null
}

function elegirCuenta(numero: number) {
  mesaSeleccionada.value = mesaPorElegirCuenta.value
  cuentaSeleccionada.value = numero
  mesaPorElegirCuenta.value = null
}

function cambiarMesa() {
  mesaSeleccionada.value = null
  cuentaSeleccionada.value = null
}

// ---- Paso 2: catálogo ----
const categorias = ref<Categoria[]>([])
const productos = ref<Producto[]>([])
const combos = ref<Combo[]>([])
const cargandoCatalogo = ref(true)
const errorCatalogo = ref('')

const CATEGORIA_COMBOS = -1 // pestaña sintética, no viene del backend
const categoriaActivaId = ref<number>(CATEGORIA_COMBOS)

async function cargarCatalogo() {
  cargandoCatalogo.value = true
  errorCatalogo.value = ''
  try {
    const [{ data: listaCategorias }, { data: listaProductos }, { data: listaCombos }] = await Promise.all([
      catalogoService.categorias(),
      catalogoService.productos(),
      catalogoService.combos(),
    ])
    categorias.value = listaCategorias
    // Fase 7, tarea 1 (RN-M04-04): un producto con presentaciones necesita al menos una vendible.
    productos.value = listaProductos.filter(
      (p) => p.activo && p.disponible && (!(p.variantes?.length) || p.variantes.some((v) => v.activa && v.disponible !== false)),
    )
    combos.value = listaCombos
    categoriaActivaId.value = listaCombos.length ? CATEGORIA_COMBOS : (listaCategorias[0]?.id ?? CATEGORIA_COMBOS)
  } catch (e) {
    errorCatalogo.value = mensajeDeError(e, 'No pudimos cargar el catálogo.')
  } finally {
    cargandoCatalogo.value = false
  }
}

// Fase 1, tarea 5 (RF-M04-025, HU-M04-013): con algo escrito en el buscador se buscan productos
// por nombre en todas las categorías; sin búsqueda, se ve la categoría elegida.
const busqueda = ref('')
const sinTildes = (t: string) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
const productosDeCategoria = computed(() => {
  const q = sinTildes(busqueda.value.trim())
  if (q) return productos.value.filter((p) => sinTildes(p.nombre).includes(q))
  return productos.value.filter((p) => p.categoria_id === categoriaActivaId.value)
})

// Un tamaño agotado (RF-M04-018) no se ofrece; el backend también lo rechaza.
const variantesVendibles = (p: Producto) => (p.variantes ?? []).filter((v) => v.activa && v.disponible !== false)

// ---- Configurador del producto/combo tocado (panel lateral en tablet/desktop,
// debajo de la grilla en teléfono — mismo grid que ya usa el Menú del Cliente) ----
const productoActivo = ref<Producto | null>(null)
const comboActivo = ref<Combo | null>(null)
const varianteActiva = ref<Variante | null>(null)
const extrasActivos = ref<Extra[]>([])
const cantidadActiva = ref(1)
const observacionesActivas = ref('')

// RESPONSIVO: en teléfono/tablet (< lg) el configurador vive como un panel
// deslizable desde abajo ("bottom sheet") — antes quedaba pegado en el flujo
// normal, debajo de TODA la grilla de productos/combos, así que elegir el
// primero de una categoría larga obligaba a bajar hasta el final de la
// página para encontrar "Agregar" (mismo problema que ya se corrigió en
// MenuView.vue del Cliente). En lg+ sigue siendo el panel fijo de siempre.
const mostrarConfiguradorMovil = ref(false)

function tocarProducto(producto: Producto) {
  productoActivo.value = producto
  comboActivo.value = null
  varianteActiva.value = variantesVendibles(producto)[0] ?? null
  extrasActivos.value = []
  cantidadActiva.value = 1
  observacionesActivas.value = ''
  mostrarConfiguradorMovil.value = true
}

function tocarCombo(combo: Combo) {
  comboActivo.value = combo
  productoActivo.value = null
  cantidadActiva.value = 1
  observacionesActivas.value = ''
  mostrarConfiguradorMovil.value = true
}

function cerrarConfiguradorMovil() {
  mostrarConfiguradorMovil.value = false
}

function cambiarCategoria(id: number) {
  categoriaActivaId.value = id
  mostrarConfiguradorMovil.value = false
}

function alternarExtraActivo(extra: Extra) {
  const i = extrasActivos.value.findIndex((e) => e.id === extra.id)
  if (i === -1) extrasActivos.value.push(extra)
  else extrasActivos.value.splice(i, 1)
}

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

const precioUnitarioActivo = computed(() => {
  if (comboActivo.value) return Number(comboActivo.value.precio)
  if (!productoActivo.value) return 0
  const base = varianteActiva.value ? Number(varianteActiva.value.precio) : Number(productoActivo.value.precio_base)
  const extras = extrasActivos.value.reduce((acc, e) => acc + Number(e.precio), 0)
  return base + extras
})

// ---- Carrito (líneas del pedido en construcción) ----
interface LineaCarrito {
  key: string
  nombre: string
  detalle: string | null
  producto_id?: number
  combo_id?: number
  variante_id?: number
  extras_ids: number[]
  precioUnitario: number
  cantidad: number
  observaciones?: string
}

const carrito = ref<LineaCarrito[]>([])
const carritoAbierto = ref(false)

const subtotalCarrito = computed(() => carrito.value.reduce((acc, l) => acc + l.precioUnitario * l.cantidad, 0))
const costoDomicilio = computed(() => (canal.value === 'domicilio' ? valorDomicilio.value : 0))
// Fase 2, tarea 1 (RF-M04-035): las promociones vigentes se aplican solas; aquí se ven antes de crear.
function lineasDelCarrito(): LineaPedidoPayload[] {
  return carrito.value.map((l) => ({
    producto_id: l.producto_id,
    combo_id: l.combo_id,
    variante_id: l.variante_id,
    cantidad: l.cantidad,
    extras_ids: l.extras_ids.length ? l.extras_ids : undefined,
    observaciones: l.observaciones,
  }))
}
const { cotizacion } = useCotizacion(() => canal.value, lineasDelCarrito)
const promocionesCarrito = computed(() => cotizacion.value?.promociones ?? [])
const totalCarrito = computed(() => subtotalCarrito.value - (cotizacion.value?.descuento ?? 0) + costoDomicilio.value)
const cantidadCarrito = computed(() => carrito.value.reduce((acc, l) => acc + l.cantidad, 0))

function agregarAlCarrito() {
  if (!productoActivo.value && !comboActivo.value) return

  const extrasIds = [...extrasActivos.value.map((e) => e.id)].sort((a, b) => a - b)
  const nueva: LineaCarrito = comboActivo.value
    ? {
        key: `combo-${comboActivo.value.id}`,
        nombre: comboActivo.value.nombre,
        // Revisión por rol (13/10/2026): lo que trae el combo y su nota para cocina.
        detalle: componentesCombo(comboActivo.value) || null,
        combo_id: comboActivo.value.id,
        extras_ids: [],
        precioUnitario: precioUnitarioActivo.value,
        cantidad: cantidadActiva.value,
        observaciones: observacionesActivas.value.trim() || undefined,
      }
    : {
        key: `producto-${productoActivo.value!.id}-${varianteActiva.value?.id ?? 0}-${extrasIds.join('.')}`,
        nombre: productoActivo.value!.nombre,
        detalle: varianteActiva.value?.nombre ?? null,
        producto_id: productoActivo.value!.id,
        variante_id: varianteActiva.value?.id,
        extras_ids: extrasIds,
        precioUnitario: precioUnitarioActivo.value,
        cantidad: cantidadActiva.value,
        observaciones: observacionesActivas.value || undefined,
      }

  // Misma configuración exacta (producto+variante+extras) ya en el carrito: se suma
  // la cantidad en vez de duplicar la línea — igual que hace el carrito del Cliente.
  const existente = carrito.value.find((l) => l.key === nueva.key && l.observaciones === nueva.observaciones)
  if (existente) {
    existente.cantidad += nueva.cantidad
  } else {
    carrito.value.push(nueva)
  }

  productoActivo.value = null
  comboActivo.value = null
  mostrarConfiguradorMovil.value = false
}

function cambiarCantidadLinea(linea: LineaCarrito, delta: number) {
  linea.cantidad = Math.max(1, linea.cantidad + delta)
}

function quitarLinea(linea: LineaCarrito) {
  carrito.value = carrito.value.filter((l) => l.key !== linea.key)
}

// ---- Crear pedido ----
const creando = ref(false)
const errorCrear = ref('')

// Campos del backend que pertenecen al paso de datos del cliente.
const CAMPOS_DATOS: Record<string, keyof typeof datosCliente> = {
  cliente_invitado_nombre: 'nombre',
  cliente_invitado_telefono: 'telefono',
  cliente_invitado_correo: 'correo',
  direccion_entrega: 'direccion',
  barrio: 'barrio',
  referencia: 'referencia',
  observaciones_entrega: 'observaciones',
}

// Mejora (27/10/2026, RF-M02-L-036): el pedido que crea el Mesero ya nace confirmado y su comanda queda
// lista para la impresora de la cocina. Si este equipo abre la comanda al confirmar (Impresoras), se abre
// aquí mismo, como antes al tocar «Confirmar pedido»; la pestaña se abre en el clic para que el navegador
// no la bloquee.
async function crearPedido() {
  if (!destinoListo.value || !carrito.value.length) return
  errorCrear.value = ''
  creando.value = true

  const lineas = lineasDelCarrito()
  const ventana = imprimirAlConfirmar.get() ? window.open('', '_blank') : null
  let creado: Pedido

  try {
    if (canal.value === 'local') {
      ;({ data: creado } = await pedidoService.crear({
        tipo: 'local',
        origen: 'mesero',
        mesa_id: mesaSeleccionada.value!.id,
        cuenta: cuentaSeleccionada.value ?? undefined,
        lineas,
      }))
    } else {
      ;({ data: creado } = await pedidoService.crear({
        tipo: canal.value,
        origen: 'mesero',
        cliente_invitado_nombre: datosCliente.nombre.trim(),
        cliente_invitado_telefono: datosCliente.telefono.trim(),
        cliente_invitado_correo: canal.value === 'recogida' ? datosCliente.correo.trim() || undefined : undefined,
        ...(canal.value === 'domicilio'
          ? {
              direccion_entrega: datosCliente.direccion.trim(),
              barrio: datosCliente.barrio.trim(),
              referencia: datosCliente.referencia.trim(),
            }
          : {}),
        observaciones_entrega: datosCliente.observaciones.trim() || undefined,
        lineas,
      }))
    }
    const comanda = (creado.comandas ?? []).find(porImprimir)
    if (ventana && comanda) {
      await abrirPdf(() => comandaService.ticket(comanda.id), '', ventana).catch(() => undefined) // si falla, sigue en «por imprimir»
    } else {
      ventana?.close()
    }
    avisarComandas()
    const aviso = creado.estado === 'confirmado'
      ? `Pedido #${creado.id} creado: la comanda ya va para la cocina.`
      : `Pedido #${creado.id} creado: confírmalo para enviarlo a la cocina.`
    router.push({ name: 'mesero-pedidos', state: { aviso } })
  } catch (e) {
    ventana?.close()
    // Si el servidor rechaza un dato del cliente, se vuelve a ese paso con el error en su campo.
    const errores = erroresDeValidacion(e)
    const deDatos = Object.entries(errores).filter(([campo]) => campo in CAMPOS_DATOS)
    if (canal.value !== 'local' && deDatos.length) {
      erroresDatos.value = Object.fromEntries(deDatos.map(([campo, msg]) => [CAMPOS_DATOS[campo], msg]))
      datosListos.value = false
      carritoAbierto.value = false
    } else {
      errorCrear.value = mensajeDeError(e, 'No pudimos crear el pedido.')
      carritoAbierto.value = true
    }
  } finally {
    creando.value = false
  }
}

onMounted(() => {
  cargarMesas()
  cargarCatalogo()
  cargarValorDomicilio()
})
</script>

<template>
  <div class="flex min-h-full flex-col bg-cream pb-28 lg:pb-8">
    <!-- Encabezado propio de la pantalla (no el de la app): mesa + volver + categorías —
         todo en un mismo bloque sticky, sin espacio entre la barra de la mesa y las
         categorías, y las categorías quedan fijas al hacer scroll del catálogo. -->
    <header class="sticky top-0 z-10 border-b border-gray-200 bg-white/95 px-4 backdrop-blur sm:px-8">
      <div class="mx-auto flex max-w-6xl items-center gap-3 py-4">
        <button
          type="button"
          class="flex size-10 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-white text-ink transition hover:border-brand-500 hover:text-brand-700 active:scale-95"
          @click="router.push({ name: 'mesero-pedidos' })"
        >
          <ArrowLeft :size="17" />
        </button>
        <div class="min-w-0 flex-1">
          <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-700">{{ tituloPantalla }}</p>
          <p class="truncate font-display text-xl font-medium leading-tight text-ink">
            {{ nombreDestino }}
          </p>
        </div>
        <button
          v-if="destinoListo"
          type="button"
          class="shrink-0 rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-xs font-semibold text-ink transition hover:border-brand-500 hover:text-brand-700 active:scale-95"
          @click="cambiarDestino"
        >
          {{ canal === 'local' ? 'Cambiar' : 'Editar datos' }}
        </button>
      </div>

      <label v-if="destinoListo && !cargandoCatalogo && !errorCatalogo" class="relative mx-auto mb-2.5 block max-w-6xl">
        <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
        <input v-formato="'busqueda'"
          v-model="busqueda"
          type="search"
          class="field pl-9"
          placeholder="Buscar producto en todo el menú"
          aria-label="Buscar producto en todo el menú"
        />
      </label>
      <nav
        v-if="destinoListo && !cargandoCatalogo && !errorCatalogo && !busqueda.trim() && (combos.length || categorias.length)"
        class="no-scrollbar mx-auto flex max-w-6xl gap-2 overflow-x-auto pb-3"
      >
        <button
          v-if="combos.length"
          type="button"
          class="flex shrink-0 items-center gap-2 rounded-full border px-4 py-2.5 text-sm font-semibold transition active:scale-95"
          :class="
            categoriaActivaId === CATEGORIA_COMBOS
              ? 'border-ink bg-ink text-white shadow-card'
              : 'border-gray-200 bg-white text-gray-600 hover:border-brand-400'
          "
          @click="cambiarCategoria(CATEGORIA_COMBOS)"
        >
          Combos
        </button>
        <button
          v-for="categoria in categorias"
          :key="categoria.id"
          type="button"
          class="flex shrink-0 items-center gap-2 rounded-full border px-4 py-2.5 text-sm font-semibold transition active:scale-95"
          :class="
            categoriaActivaId === categoria.id
              ? 'border-ink bg-ink text-white shadow-card'
              : 'border-gray-200 bg-white text-gray-600 hover:border-brand-400'
          "
          @click="cambiarCategoria(categoria.id)"
        >
          <component :is="iconoPorNombre(categoria.nombre)" :size="14" />
          {{ categoria.nombre }}
        </button>
      </nav>
    </header>

    <!-- Paso 1: canal y luego mesa (tarjetas grandes, táctiles) o datos del cliente -->
    <div v-if="!destinoListo" class="mx-auto w-full max-w-6xl px-4 pb-6 pt-6 sm:px-8 sm:pt-8">
      <div class="mb-6 grid grid-cols-3 gap-2 sm:max-w-md">
        <button
          v-for="opcion in CANALES"
          :key="opcion.v"
          type="button"
          class="flex min-h-12 flex-col items-center justify-center gap-1 rounded-xl border px-2 py-2.5 text-xs font-semibold transition active:scale-95 sm:flex-row sm:gap-2 sm:text-sm"
          :class="canal === opcion.v ? 'border-ink bg-ink text-white shadow-card' : 'border-gray-200 bg-white text-gray-600 hover:border-brand-400'"
          :aria-pressed="canal === opcion.v"
          @click="elegirCanal(opcion.v)"
        >
          <component :is="opcion.icono" :size="17" />
          {{ opcion.t }}
        </button>
      </div>

      <!-- Domicilio o recogida por teléfono: datos del cliente -->
      <form v-if="canal !== 'local'" class="card max-w-2xl space-y-4 p-4 sm:p-6" novalidate @submit.prevent="continuarConDatos">
        <p class="text-sm text-gray-600">
          {{
            canal === 'domicilio'
              ? 'Domicilio pedido por teléfono o WhatsApp. Se cobra contra entrega.'
              : 'Pedido para recoger en el local, pedido por teléfono o WhatsApp. Se cobra al recoger.'
          }}
        </p>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="mb-1.5 block text-xs font-semibold text-gray-600">Nombre del cliente</span>
            <input v-formato="'persona'" v-model="datosCliente.nombre" type="text" maxlength="255" autocomplete="off" class="field" :class="{ '!border-red-400': erroresDatos.nombre }" />
            <span v-if="erroresDatos.nombre" class="mt-1 block text-xs text-red-600">{{ erroresDatos.nombre }}</span>
          </label>
          <label class="block">
            <span class="mb-1.5 block text-xs font-semibold text-gray-600">Teléfono</span>
            <input v-formato="'celular'" v-model="datosCliente.telefono" type="tel" inputmode="tel" maxlength="30" autocomplete="off" class="field" :class="{ '!border-red-400': erroresDatos.telefono }" />
            <span v-if="erroresDatos.telefono" class="mt-1 block text-xs text-red-600">{{ erroresDatos.telefono }}</span>
          </label>
        </div>
        <label v-if="canal === 'recogida'" class="block">
          <span class="mb-1.5 block text-xs font-semibold text-gray-600">
            Correo <span class="font-normal text-gray-400">(opcional: le avisamos cuando esté listo)</span>
          </span>
          <input v-formato="'correo'" v-model="datosCliente.correo" type="email" inputmode="email" maxlength="255" autocomplete="off" class="field" :class="{ '!border-red-400': erroresDatos.correo }" />
          <span v-if="erroresDatos.correo" class="mt-1 block text-xs text-red-600">{{ erroresDatos.correo }}</span>
        </label>
        <template v-if="canal === 'domicilio'">
          <label class="block">
            <span class="mb-1.5 block text-xs font-semibold text-gray-600">Dirección</span>
            <input v-formato="'direccion'" v-model="datosCliente.direccion" type="text" maxlength="255" autocomplete="off" placeholder="Calle, carrera, número" class="field" :class="{ '!border-red-400': erroresDatos.direccion }" />
            <span v-if="erroresDatos.direccion" class="mt-1 block text-xs text-red-600">{{ erroresDatos.direccion }}</span>
          </label>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1.5 block text-xs font-semibold text-gray-600">Barrio</span>
              <input v-formato="'nombre'" v-model="datosCliente.barrio" type="text" maxlength="255" autocomplete="off" class="field" :class="{ '!border-red-400': erroresDatos.barrio }" />
              <span v-if="erroresDatos.barrio" class="mt-1 block text-xs text-red-600">{{ erroresDatos.barrio }}</span>
            </label>
            <label class="block">
              <span class="mb-1.5 block text-xs font-semibold text-gray-600">Punto de referencia</span>
              <input v-formato="'nombre'" v-model="datosCliente.referencia" type="text" maxlength="255" autocomplete="off" placeholder="Ej. casa azul frente al parque" class="field" :class="{ '!border-red-400': erroresDatos.referencia }" />
              <span v-if="erroresDatos.referencia" class="mt-1 block text-xs text-red-600">{{ erroresDatos.referencia }}</span>
            </label>
          </div>
        </template>
        <label class="block">
          <span class="mb-1.5 block text-xs font-semibold text-gray-600">
            {{ canal === 'domicilio' ? 'Indicaciones para la entrega' : 'Indicaciones' }} <span class="font-normal text-gray-400">(opcional)</span>
          </span>
          <textarea v-formato="'texto'" v-model="datosCliente.observaciones" rows="2" maxlength="500" class="field" :placeholder="canal === 'domicilio' ? 'Ej. apartamento 302, llamar al llegar' : 'Ej. pasa a las 7:30 p. m.'" />
          <span v-if="erroresDatos.observaciones" class="mt-1 block text-xs text-red-600">{{ erroresDatos.observaciones }}</span>
        </label>
        <button
          type="submit"
          class="min-h-12 w-full rounded-xl bg-brand-500 py-3 text-sm font-semibold text-ink shadow-gold transition hover:bg-brand-400 active:scale-[0.99] sm:w-auto sm:px-8"
        >
          Continuar con los productos
        </button>
      </form>

      <template v-else>
      <p v-if="errorMesas" class="alert-error">{{ errorMesas }}</p>
      <div v-if="cargandoMesas" class="loading-state">Cargando mesas…</div>
      <!-- Mejora (28/10/2026): sin mesas creadas el paso quedaba vacío. -->
      <div v-else-if="!mesas.length && !errorMesas" class="empty-state">
        <Armchair :size="32" class="mx-auto text-gray-300" />
        <p class="mt-3 text-sm font-medium text-ink">Todavía no hay mesas creadas</p>
        <p class="mt-1 text-xs text-gray-500">Las crea el Administrador en «Mesas y Reservas». Puedes tomar un domicilio o una recogida.</p>
      </div>
      <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
        <button
          v-for="mesa in mesas"
          :key="mesa.id"
          type="button"
          class="card flex flex-col items-center gap-2 border-t-4 px-4 py-6 text-center transition active:scale-[0.98]"
          :class="[
            mesa.estado === 'libre' ? 'border-t-emerald-400' : mesa.estado === 'ocupada' ? 'border-t-amber-400' : 'border-t-sky-400',
            mesa.estado === 'reservada' ? 'opacity-50' : 'hover:shadow-card-hover',
          ]"
          :disabled="mesa.estado === 'reservada'"
          @click="elegirMesa(mesa)"
        >
          <Armchair :size="26" class="text-gray-400" />
          <span class="font-display text-lg font-medium text-ink">{{ mesa.nombre }}</span>
          <span v-if="(mesa.cuentas?.length ?? 0) > 1" class="text-[11px] font-semibold text-amber-700">{{ mesa.cuentas!.length }} cuentas</span>
          <span
            class="badge capitalize"
            :class="{
              'bg-emerald-50 text-emerald-700 ring-emerald-600/20': mesa.estado === 'libre',
              'bg-amber-50 text-amber-700 ring-amber-600/20': mesa.estado === 'ocupada',
              'bg-sky-50 text-sky-700 ring-sky-600/20': mesa.estado === 'reservada',
            }"
          >
            {{ mesa.estado }}
          </span>
        </button>
      </div>
      </template>
    </div>

    <!-- Paso 2: catálogo + configurador -->
    <div v-else class="mx-auto w-full max-w-6xl flex-1 px-4 pb-6 pt-8 sm:px-8 sm:pt-10">
      <p v-if="errorCatalogo" class="alert-error">{{ errorCatalogo }}</p>
      <div v-if="cargandoCatalogo" class="loading-state">Cargando el catálogo…</div>

      <template v-else>
        <div class="grid gap-6 lg:grid-cols-[1fr_340px]">
          <section>
            <!-- RESPONSIVO: en teléfono (< sm) cada producto es una fila compacta (ícono pequeño a
                 la izquierda) — caben 5-6 por pantalla y el Mesero toma el pedido sin scroll
                 interminable; desde sm vuelve la rejilla de 2 columnas con imagen grande. -->
            <!-- Combos -->
            <div v-if="categoriaActivaId === CATEGORIA_COMBOS && !busqueda.trim()" class="grid gap-2.5 sm:grid-cols-2 sm:gap-3">
              <button
                v-for="combo in combos"
                :key="combo.id"
                type="button"
                class="card group flex items-center gap-3 p-3 text-left transition duration-200 active:scale-[0.99] sm:block sm:p-4"
                :class="
                  comboActivo?.id === combo.id
                    ? '!border-brand-500 shadow-[0_0_0_3px_rgba(214,164,58,0.18)]'
                    : 'hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-card-hover'
                "
                @click="tocarCombo(combo)"
              >
                <div
                  class="flex size-16 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-gray-100 to-gray-200/70 text-brand-700/60 sm:mb-3 sm:aspect-[4/3] sm:size-auto sm:w-full"
                >
                  <component :is="iconoPorNombre(combo.nombre)" :size="28" :stroke-width="1.5" />
                </div>
                <div class="min-w-0 flex-1 sm:flex-none">
                  <p class="font-display text-[17px] font-medium leading-snug text-ink sm:text-lg">{{ combo.nombre }}</p>
                  <p v-if="componentesCombo(combo)" class="mt-0.5 line-clamp-2 text-xs text-gray-500">{{ componentesCombo(combo) }}</p>
                  <div class="mt-1 flex items-center justify-between gap-2 sm:mt-3 sm:items-baseline">
                    <p class="text-base font-semibold text-ink">{{ formatoPrecio(combo.precio) }}</p>
                    <span
                      class="rounded-full bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-700 transition group-hover:bg-brand-500 group-hover:text-ink"
                      >Elegir</span
                    >
                  </div>
                </div>
              </button>
              <p v-if="!combos.length" class="text-sm text-gray-500">No hay combos disponibles hoy.</p>
            </div>

            <!-- Productos -->
            <div v-else class="grid gap-2.5 sm:grid-cols-2 sm:gap-3">
              <button
                v-for="producto in productosDeCategoria"
                :key="producto.id"
                type="button"
                class="card group flex items-center gap-3 p-3 text-left transition duration-200 active:scale-[0.99] sm:block sm:p-4"
                :class="
                  productoActivo?.id === producto.id
                    ? '!border-brand-500 shadow-[0_0_0_3px_rgba(214,164,58,0.18)]'
                    : 'hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-card-hover'
                "
                @click="tocarProducto(producto)"
              >
                <div
                  class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gradient-to-br from-gray-100 to-gray-200/70 text-brand-700/60 sm:mb-3 sm:aspect-[4/3] sm:size-auto sm:w-full"
                >
                  <!-- Fase 7, tarea 3 (RF-M04-004): la foto del producto si la tiene. -->
                  <img v-if="producto.imagen_url" :src="producto.imagen_url" :alt="producto.nombre" loading="lazy" class="size-full object-cover" />
                  <component :is="iconoPorNombre(producto.nombre)" v-else :size="28" :stroke-width="1.5" />
                </div>
                <div class="min-w-0 flex-1 sm:flex-none">
                  <p class="font-display text-[17px] font-medium leading-snug text-ink sm:text-lg">
                    {{ producto.nombre }}
                    <span v-if="etiquetaPromocion(producto)" class="ml-1 align-middle rounded-full bg-emerald-50 px-2 py-0.5 font-sans text-[11px] font-semibold text-emerald-700">{{ etiquetaPromocion(producto) }}</span>
                  </p>
                  <div class="mt-1 flex items-center justify-between gap-2 sm:mt-3 sm:items-baseline">
                    <p class="text-base font-semibold text-ink">
                      <span class="mr-1 text-[10px] font-medium uppercase tracking-wide text-gray-400">Desde</span
                      >{{ formatoPrecio(producto.precio_base) }}
                    </p>
                    <span
                      class="rounded-full bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-700 transition group-hover:bg-brand-500 group-hover:text-ink"
                      >Elegir</span
                    >
                  </div>
                </div>
              </button>
              <p v-if="!productosDeCategoria.length" class="text-sm text-gray-500">
                {{ busqueda.trim() ? 'Ningún producto disponible coincide con la búsqueda.' : 'No hay productos disponibles en esta categoría.' }}
              </p>
            </div>
          </section>

          <!-- Fondo oscuro del panel deslizable (solo móvil/tablet, solo si está abierto) -->
          <Transition
            enter-active-class="transition-opacity duration-200"
            leave-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
          >
            <div
              v-if="(productoActivo || comboActivo) && mostrarConfiguradorMovil"
              class="fixed inset-0 z-30 bg-ink/50 backdrop-blur-[2px] lg:hidden"
              @click="cerrarConfiguradorMovil"
            />
          </Transition>

          <!-- Configurador: hoja deslizable desde abajo en teléfono/tablet (< lg), panel
               fijo de siempre en escritorio (lg+) — ver mismo patrón en MenuView.vue. -->
          <aside
            v-if="productoActivo || comboActivo"
            class="fixed inset-x-0 bottom-0 z-40 flex max-h-[85vh] flex-col rounded-t-3xl border-t border-gray-200 bg-white shadow-pop transition-transform duration-300 ease-out lg:sticky lg:top-24 lg:inset-x-auto lg:bottom-auto lg:z-auto lg:h-fit lg:max-h-none lg:translate-y-0 lg:rounded-2xl lg:border lg:shadow-card"
            :class="mostrarConfiguradorMovil ? 'translate-y-0' : 'translate-y-full'"
          >
            <!-- Encabezado: fijo, para que el cierre y el drag handle siempre queden a la mano. -->
            <div class="shrink-0 px-5 pt-5 lg:p-5 lg:pb-0">
              <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-gray-200 lg:hidden" aria-hidden="true" />
              <div class="flex items-center justify-between gap-3">
                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-700">
                  {{ comboActivo ? 'Combo' : 'Personaliza' }}
                </p>
                <button
                  type="button"
                  class="flex size-8 shrink-0 items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 lg:hidden"
                  aria-label="Cerrar"
                  @click="cerrarConfiguradorMovil"
                >
                  <X :size="18" />
                </button>
              </div>
            </div>

            <!-- Cuerpo: lo único que se desplaza en móvil — "Agregar" nunca se mueve. -->
            <div class="min-h-0 flex-1 overflow-y-auto px-5 pb-5 lg:overflow-visible">
              <p class="font-display text-2xl font-medium leading-tight text-ink">{{ comboActivo?.nombre ?? productoActivo?.nombre }}</p>
              <p v-if="productoActivo?.descripcion" class="mt-2 text-sm text-gray-500">{{ productoActivo.descripcion }}</p>
              <template v-if="comboActivo && comboActivo.productos?.length">
                <p class="field-label mt-5">Incluye</p>
                <ul class="mt-2 space-y-1 text-sm text-gray-600">
                  <li v-for="p in comboActivo.productos" :key="p.id">{{ p.pivot?.cantidad ?? 1 }} × {{ p.nombre }}</li>
                </ul>
              </template>

              <template v-if="productoActivo && variantesVendibles(productoActivo).length">
                <p class="field-label mt-5">Presentación</p>
                <div class="mt-2 space-y-2">
                  <label
                    v-for="variante in variantesVendibles(productoActivo)"
                    :key="variante.id"
                    class="flex cursor-pointer items-center justify-between gap-3 rounded-xl border px-4 py-3.5 text-sm transition"
                    :class="varianteActiva?.id === variante.id ? 'border-brand-500 bg-brand-50' : 'border-gray-200 hover:border-brand-300'"
                  >
                    <span class="flex items-center gap-3">
                      <input
                        type="radio"
                        :name="'variante-' + productoActivo.id"
                        class="accent-brand-600"
                        :checked="varianteActiva?.id === variante.id"
                        @change="varianteActiva = variante"
                      />
                      <span>
                        <span class="block">{{ variante.nombre }}</span>
                        <!-- Mejora (16/10/2026): qué trae la presentación, para contárselo al cliente. -->
                        <span v-if="variante.descripcion" class="mt-0.5 block text-xs leading-snug text-gray-500">{{ variante.descripcion }}</span>
                      </span>
                    </span>
                    <span class="shrink-0 text-right text-gray-700">
                      <template v-if="precioConPromocion(productoActivo, Number(variante.precio), variante.id) < Number(variante.precio)">
                        <span class="block text-[11px] text-gray-400 line-through">{{ formatoPrecio(variante.precio) }}</span>
                        <span class="font-semibold text-emerald-700">{{ formatoPrecio(precioConPromocion(productoActivo, Number(variante.precio), variante.id)) }}</span>
                      </template>
                      <template v-else>{{ formatoPrecio(variante.precio) }}</template>
                    </span>
                  </label>
                </div>
              </template>

              <template v-if="extrasDe(productoActivo).length">
                <p class="field-label mt-5">Extras</p>
                <div class="mt-2 space-y-2">
                  <label
                    v-for="extra in extrasDe(productoActivo)"
                    :key="extra.id"
                    class="flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-3.5 text-sm transition hover:border-brand-300"
                  >
                    <span class="flex items-center gap-3">
                      <input
                        type="checkbox"
                        class="accent-brand-600"
                        :checked="extrasActivos.some((e) => e.id === extra.id)"
                        @change="alternarExtraActivo(extra)"
                      />
                      {{ extra.nombre }}
                    </span>
                    <span class="text-gray-700">+{{ formatoPrecio(extra.precio) }}</span>
                  </label>
                </div>
              </template>

              <label class="mt-5 block">
                <span class="field-label">Observaciones para cocina</span>
                <input v-formato="'texto'" maxlength="500"
                  v-model="observacionesActivas"
                  type="text"
                  placeholder="Opcional — ej: sin cebolla"
                  class="mt-2 field"
                />
              </label>
            </div>

            <!-- Pie: fijo, siempre visible — nunca hace falta bajar para encontrar "Agregar". -->
            <div class="flex shrink-0 items-center gap-3 border-t border-gray-200 px-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] pt-4">
              <div class="flex items-center gap-1 rounded-xl border border-gray-200 bg-white p-1">
                <button
                  type="button"
                  class="flex size-10 items-center justify-center rounded-lg text-gray-600 transition active:bg-gray-100 disabled:opacity-30"
                  :disabled="cantidadActiva <= 1"
                  @click="cantidadActiva--"
                >
                  <Minus :size="15" />
                </button>
                <span class="w-6 text-center text-base font-semibold">{{ cantidadActiva }}</span>
                <button type="button" class="flex size-10 items-center justify-center rounded-lg text-gray-600 transition active:bg-gray-100" @click="cantidadActiva++">
                  <Plus :size="15" />
                </button>
              </div>
              <button
                type="button"
                class="flex min-h-12 flex-1 items-center justify-between rounded-xl bg-brand-500 px-4 py-3 text-sm font-semibold text-ink shadow-gold transition hover:bg-brand-400 active:scale-[0.98]"
                @click="agregarAlCarrito"
              >
                <span>Agregar</span>
                <span>{{ formatoPrecio(precioUnitarioActivo * cantidadActiva) }}</span>
              </button>
            </div>
          </aside>
        </div>
      </template>
    </div>

    <!-- Carrito: barra inferior fija + panel deslizable — nunca un modal centrado. -->
    <div v-if="carrito.length" class="fixed inset-x-0 bottom-0 z-20 lg:bottom-6 lg:left-auto lg:right-6 lg:w-96">
      <div
        v-if="carritoAbierto"
        class="max-h-[65dvh] overflow-y-auto rounded-t-3xl border border-gray-200 bg-white px-4 pt-4 shadow-pop lg:rounded-2xl"
      >
        <div class="flex items-center justify-between pb-3">
          <p class="font-display text-lg font-medium text-ink">Pedido — {{ nombreDestino }}</p>
          <button type="button" class="flex size-9 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-ink" @click="carritoAbierto = false">
            <X :size="18" />
          </button>
        </div>

        <ul class="space-y-3 border-t border-gray-100 pt-3">
          <li v-for="linea in carrito" :key="linea.key" class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-ink">{{ linea.nombre }}</p>
              <p v-if="linea.detalle" class="text-xs text-gray-500">{{ linea.detalle }}</p>
              <p class="text-xs text-gray-500">{{ formatoPrecio(linea.precioUnitario) }} c/u</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
              <button
                type="button"
                class="flex size-8 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-600 transition active:scale-90 active:bg-gray-100"
                @click="cambiarCantidadLinea(linea, -1)"
              >
                <Minus :size="12" />
              </button>
              <span class="w-5 text-center text-sm font-semibold">{{ linea.cantidad }}</span>
              <button
                type="button"
                class="flex size-8 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-600 transition active:scale-90 active:bg-gray-100"
                @click="cambiarCantidadLinea(linea, 1)"
              >
                <Plus :size="12" />
              </button>
              <button type="button" class="ml-0.5 flex size-8 items-center justify-center rounded-full text-gray-300 transition hover:bg-red-50 hover:text-red-600" aria-label="Quitar" @click="quitarLinea(linea)">
                <X :size="15" />
              </button>
            </div>
          </li>
        </ul>

        <p v-if="errorCrear" class="mt-3 alert-error">{{ errorCrear }}</p>

        <div class="sticky bottom-0 -mx-4 mt-4 border-t border-gray-200 bg-white px-4 py-4">
          <div v-for="(p, i) in promocionesCarrito" :key="i" class="mb-1.5 flex items-center justify-between gap-3 text-sm text-emerald-700">
            <span>{{ p.nombre }}</span>
            <span class="shrink-0">−{{ formatoPrecio(p.monto) }}</span>
          </div>
          <div v-if="canal === 'domicilio'" class="mb-1.5 flex items-center justify-between text-sm text-gray-600">
            <span>Domicilio</span>
            <span>{{ formatoPrecio(costoDomicilio) }}</span>
          </div>
          <div class="mb-3 flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-gray-500">Total</span>
            <span class="font-display text-2xl font-medium text-ink">{{ formatoPrecio(totalCarrito) }}</span>
          </div>
          <button
            type="button"
            class="min-h-12 w-full rounded-xl bg-brand-500 py-3 text-sm font-semibold text-ink shadow-gold transition hover:bg-brand-400 active:scale-[0.99] disabled:opacity-60"
            :disabled="creando"
            @click="crearPedido"
          >
            {{ creando ? 'Creando pedido…' : 'Crear pedido' }}
          </button>
        </div>
      </div>

      <button
        type="button"
        class="flex w-full items-center justify-between gap-4 border-t border-white/10 bg-ink-900 px-5 pb-[max(1rem,env(safe-area-inset-bottom))] pt-4 text-left shadow-nav lg:rounded-2xl lg:border lg:pb-4"
        @click="carritoAbierto = !carritoAbierto"
      >
        <span class="flex items-center gap-3 text-white">
          <ShoppingBasket :size="19" class="text-brand-400" />
          <span class="text-sm font-medium">{{ cantidadCarrito }} producto{{ cantidadCarrito === 1 ? '' : 's' }}</span>
        </span>
        <span class="flex items-center gap-2 text-white">
          <span class="font-display text-lg font-medium text-brand-400">{{ formatoPrecio(totalCarrito) }}</span>
          <ChevronUp :size="16" class="transition" :class="{ 'rotate-180': carritoAbierto }" />
        </span>
      </button>
    </div>

    <!-- Mejora (26/10/2026, RF-M02-L-033): mesa ocupada → seguir una cuenta o abrir una cuenta separada. -->
    <BaseModal
      :model-value="!!mesaPorElegirCuenta"
      :title="mesaPorElegirCuenta ? `${mesaPorElegirCuenta.nombre} está ocupada` : ''"
      @update:model-value="mesaPorElegirCuenta = null"
    >
      <template v-if="mesaPorElegirCuenta">
        <p class="text-sm text-gray-600">¿A qué cuenta va este pedido?</p>
        <div class="mt-4 space-y-2.5">
          <button
            v-for="c in mesaPorElegirCuenta.cuentas"
            :key="c.numero"
            type="button"
            class="flex min-h-14 w-full items-center gap-3 rounded-2xl border border-gray-200 px-4 py-3 text-left transition hover:border-brand-400 hover:bg-brand-50/50 active:scale-[0.99]"
            @click="elegirCuenta(c.numero)"
          >
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700"><ReceiptText :size="19" /></span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-ink">Continuar la cuenta {{ c.numero }}</span>
              <span class="block text-xs text-gray-500">
                {{ c.pedidos.length }} pedido{{ c.pedidos.length === 1 ? '' : 's' }} · {{ formatoPrecio(c.total) }}
                <template v-if="c.saldo > 0"> · debe {{ formatoPrecio(c.saldo) }}</template>
                <template v-else> · pagada</template>
              </span>
            </span>
          </button>
          <button
            type="button"
            class="flex min-h-14 w-full items-center gap-3 rounded-2xl border-2 border-dashed border-brand-400 bg-brand-50/60 px-4 py-3 text-left transition hover:bg-brand-100/60 active:scale-[0.99]"
            @click="elegirCuenta(siguienteCuenta)"
          >
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-ink"><SplitSquareHorizontal :size="19" /></span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-ink">Nueva cuenta separada (cuenta {{ siguienteCuenta }})</span>
              <span class="block text-xs text-gray-600">Para quien paga aparte: se cobra sola, sin mezclarse con las demás.</span>
            </span>
          </button>
        </div>
      </template>
    </BaseModal>
  </div>
</template>
