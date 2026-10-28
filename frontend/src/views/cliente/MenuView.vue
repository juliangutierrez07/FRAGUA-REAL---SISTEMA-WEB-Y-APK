<script setup lang="ts">
// M04 — catálogo público (también vía QR de mesa, M02). Calcado de Menu V2
// del Figma (nodo 63:225): tabs de categoría, grilla de productos y un panel
// de personalización a la derecha. Datos reales desde GET /categorias y
// GET /productos (CatalogoController), que ya vienen filtrados a
// activo+disponible para Cliente (RN-M04-08).
//
// Revisión por rol (13/10/2026, RF-M04-020/031): pestaña "Combos" con GET /combos (solo los
// que hoy se pueden pedir). Antes el Cliente no podía pedir combos ni en la web ni por QR.
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Plus, X } from 'lucide-vue-next'
import CarritoFlotante from '@/components/common/CarritoFlotante.vue'
import { volarAlCarrito } from '@/utils/volarAlCarrito'
import { catalogoService } from '@/services/catalogo.service'
import { extrasDe } from '@/utils/extras'
import { MAX_UNIDADES_POR_LINEA, useCarritoStore } from '@/stores/carrito'
import { iconoPorNombre as iconoPara } from '@/utils/iconoCategoria'
import { etiquetaPromocion, precioConPromocion } from '@/utils/promociones'
import { componentesCombo } from '@/utils/combos'
import type { Categoria, Combo, Extra, Producto, Variante } from '@/types/models'

// Pestaña sintética: no viene del backend.
const CATEGORIA_COMBOS = -1

const categorias = ref<Categoria[]>([])
const productos = ref<Producto[]>([])
const combos = ref<Combo[]>([])
const cargando = ref(true)
const error = ref('')

const categoriaActivaId = ref<number | null>(null)
const productoSeleccionado = ref<Producto | null>(null)
const comboSeleccionado = ref<Combo | null>(null)
const varianteSeleccionada = ref<Variante | null>(null)
const extrasSeleccionados = ref<Extra[]>([])
const cantidad = ref(1)
const notas = ref('')

// RESPONSIVO: en teléfono/tablet (< lg) el configurador vive como un panel
// deslizable desde abajo ("bottom sheet"), no siempre visible — antes quedaba
// pegado en el flujo normal, debajo de TODA la grilla de productos, así que
// elegir el primer producto de una categoría larga obligaba a bajar hasta el
// final de la página para encontrar el botón "Agregar". Solo se abre cuando
// el cliente toca una tarjeta a propósito (elegirProducto), no con la
// preselección automática de seleccionarPrimeroDeCategoria — si no, cambiar
// de categoría abriría el panel solo. En lg+ sigue siendo el panel fijo de
// siempre (comportamiento sin cambios).
const mostrarConfiguradorMovil = ref(false)

const carrito = useCarritoStore()
const router = useRouter()
const route = useRoute()

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const [resCategorias, resProductos, resCombos] = await Promise.all([
      catalogoService.categorias(),
      catalogoService.productos(),
      // Si los combos no cargan, el resto del menú sigue funcionando.
      catalogoService.combos().catch(() => ({ data: [] as Combo[] })),
    ])
    categorias.value = resCategorias.data
    productos.value = resProductos.data
    combos.value = resCombos.data

    // Permite llegar con una categoría preseleccionada, p. ej. desde los
    // accesos rápidos del Home (/menu?categoria=hamburguesas). Coincidencia
    // parcial e insensible a mayúsculas; si no hay match, cae a la primera.
    const categoriaQuery = typeof route.query.categoria === 'string' ? route.query.categoria.toLowerCase() : ''
    const categoriaPreseleccionada = categoriaQuery
      ? categorias.value.find((c) => c.nombre.toLowerCase().includes(categoriaQuery))
      : null
    const pideCombos = categoriaQuery.startsWith('combo') && combos.value.length > 0
    categoriaActivaId.value = pideCombos
      ? CATEGORIA_COMBOS
      : (categoriaPreseleccionada?.id ?? categorias.value[0]?.id ?? (combos.value.length ? CATEGORIA_COMBOS : null))
    seleccionarPrimeroDeCategoria()
  } catch {
    error.value = 'No se pudo cargar el menú. Intenta de nuevo.'
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

const enCombos = computed(() => categoriaActivaId.value === CATEGORIA_COMBOS)
// Mejora (26/10/2026, RF-M04-056): a una bebida no se le pregunta qué ingrediente quitar (interruptor de la categoría).
const permiteQuitarIngredientes = computed(() => {
  const producto = productoSeleccionado.value
  if (!producto) return true
  return categorias.value.find((c) => c.id === producto.categoria_id)?.permite_quitar_ingredientes !== false
})

const productosDeCategoria = computed(() =>
  productos.value.filter((p) => p.categoria_id === categoriaActivaId.value),
)

const tituloCategoria = computed(() =>
  enCombos.value ? 'Combos' : categorias.value.find((c) => c.id === categoriaActivaId.value)?.nombre,
)

const haySeleccion = computed(() => Boolean(productoSeleccionado.value || comboSeleccionado.value))

function seleccionarCategoria(id: number) {
  categoriaActivaId.value = id
  mostrarConfiguradorMovil.value = false
  seleccionarPrimeroDeCategoria()
}

function seleccionarPrimeroDeCategoria() {
  if (enCombos.value) {
    seleccionarCombo(combos.value[0] ?? null)
    return
  }
  const primero = productosDeCategoria.value[0] ?? null
  seleccionarProducto(primero)
}

function seleccionarCombo(combo: Combo | null) {
  comboSeleccionado.value = combo
  productoSeleccionado.value = null
  varianteSeleccionada.value = null
  extrasSeleccionados.value = []
  cantidad.value = 1
  notas.value = ''
}

function elegirCombo(combo: Combo) {
  seleccionarCombo(combo)
  mostrarConfiguradorMovil.value = true
}

function seleccionarProducto(producto: Producto | null) {
  comboSeleccionado.value = null
  productoSeleccionado.value = producto
  varianteSeleccionada.value = producto?.variantes?.find((v) => v.activa) ?? null
  extrasSeleccionados.value = []
  cantidad.value = 1
  notas.value = ''
}

// Tocar una tarjeta a propósito (a diferencia de la preselección automática
// de arriba): además de elegir el producto, abre el panel en móvil/tablet.
function elegirProducto(producto: Producto) {
  seleccionarProducto(producto)
  mostrarConfiguradorMovil.value = true
}

function cerrarConfiguradorMovil() {
  mostrarConfiguradorMovil.value = false
}

function alternarExtra(extra: Extra) {
  const idx = extrasSeleccionados.value.findIndex((e) => e.id === extra.id)
  if (idx >= 0) {
    extrasSeleccionados.value.splice(idx, 1)
  } else {
    extrasSeleccionados.value.push(extra)
  }
}

// Mejora (16/10/2026): el precio del botón "Agregar" ya trae la oferta de la presentación elegida
// (antes se veía el precio normal aunque el producto estuviera en promoción).
const precioSeleccion = computed(() => {
  if (comboSeleccionado.value) return Number(comboSeleccionado.value.precio)
  const producto = productoSeleccionado.value
  if (!producto) return 0
  const variante = varianteSeleccionada.value
  const base = Number(variante?.precio ?? producto.precio_base)
  const extras = extrasSeleccionados.value.reduce((acc, e) => acc + Number(e.precio), 0)
  return precioConPromocion(producto, base, variante?.id ?? null) + extras
})

// Mejora (16/10/2026): al elegir una presentación con foto propia ("Familiar"), se ve esa foto.
const fotoSeleccion = computed(() => varianteSeleccionada.value?.imagen_url || productoSeleccionado.value?.imagen_url || '')

function agregarAlPedido(evento?: MouseEvent) {
  if (comboSeleccionado.value) {
    carrito.agregar({
      producto: null,
      combo: comboSeleccionado.value,
      variante: null,
      extras: [],
      cantidad: cantidad.value,
      notas: notas.value.trim() || undefined,
    })
  } else if (productoSeleccionado.value) {
    carrito.agregar({
      producto: productoSeleccionado.value,
      variante: varianteSeleccionada.value,
      extras: [...extrasSeleccionados.value],
      cantidad: cantidad.value,
      notas: notas.value.trim() || undefined,
    })
  } else {
    return
  }
  // Mejora de diseño (16/10/2026): aviso breve de lo que se agregó (lo muestra CarritoFlotante).
  const nombre = comboSeleccionado.value?.nombre ?? productoSeleccionado.value?.nombre ?? ''
  const presentacion = varianteSeleccionada.value && !comboSeleccionado.value ? ` (${varianteSeleccionada.value.nombre})` : ''
  carrito.avisarAgregado(`Agregado: ${cantidad.value > 1 ? `${cantidad.value} × ` : ''}${nombre}${presentacion}`)
  volarAlCarrito(evento?.currentTarget instanceof Element ? evento.currentTarget : null)
  cantidad.value = 1
  extrasSeleccionados.value = []
  notas.value = ''
  mostrarConfiguradorMovil.value = false
}


function formatoPrecio(valor: number) {
  return valor.toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 pb-32 pt-10 sm:px-6">
    <div class="mb-8 flex items-center gap-3">
      <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
      <span class="text-[11px] font-medium tracking-[2px] text-brand-700">NUESTRA CARTA</span>
    </div>
    <h1 class="font-display text-4xl font-medium tracking-tight text-ink sm:text-5xl">Tu próximo antojo.</h1>
    <p class="mt-3 text-[15px] text-[#6c655c]">Elige tu favorito, personalízalo y agrégalo a tu pedido.</p>

    <p v-if="error" class="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>
    <!-- CORREGIDO (16/10/2026, producción): mientras cargaba solo había una línea de texto, la página
         quedaba casi vacía y el pie de página subía hasta la mitad de la pantalla. Ahora se reserva el
         espacio del menú con la misma forma que tendrá (categorías y tarjetas). -->
    <div v-else-if="cargando" class="min-h-[70dvh]" role="status" aria-live="polite">
      <span class="sr-only">Cargando el menú…</span>
      <div class="mt-8 flex gap-2 overflow-hidden border-b border-[#e2dbcf] pb-3" aria-hidden="true">
        <span v-for="n in 5" :key="n" class="h-10 w-32 shrink-0 animate-pulse rounded-full bg-[#ece5d9]" />
      </div>
      <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_360px]" aria-hidden="true">
        <div>
          <span class="mb-4 block h-7 w-40 animate-pulse rounded-lg bg-[#ece5d9]" />
          <div class="grid grid-cols-2 gap-3 sm:gap-4">
            <div v-for="n in 4" :key="n" class="rounded-2xl border border-[#e9e3d8] bg-white p-2.5 sm:p-4">
              <span class="block aspect-[4/3] animate-pulse rounded-xl bg-[#efe8db]" />
              <span class="mt-4 block h-5 w-2/3 animate-pulse rounded bg-[#ece5d9]" />
              <span class="mt-2 block h-3 w-full animate-pulse rounded bg-[#f1ece3]" />
              <span class="mt-4 block h-4 w-1/3 animate-pulse rounded bg-[#ece5d9]" />
            </div>
          </div>
        </div>
        <div class="hidden h-96 animate-pulse rounded-2xl bg-[#f3eee5] lg:block" />
      </div>
    </div>

    <template v-else>
      <nav class="mt-8 flex gap-2 overflow-x-auto border-b border-[#e2dbcf] pb-3">
        <button
          v-for="categoria in categorias"
          :key="categoria.id"
          type="button"
          class="flex shrink-0 items-center gap-2 rounded-full border px-5 py-2.5 text-sm font-medium transition"
          :class="
            categoria.id === categoriaActivaId
              ? 'border-ink bg-ink text-white'
              : 'border-[#dfd8cd] bg-white text-[#59534c] hover:border-brand-500'
          "
          @click="seleccionarCategoria(categoria.id)"
        >
          <component :is="iconoPara(categoria.nombre)" :size="15" />
          {{ categoria.nombre }}
        </button>
        <button
          v-if="combos.length"
          type="button"
          class="flex shrink-0 items-center gap-2 rounded-full border px-5 py-2.5 text-sm font-medium transition"
          :class="
            enCombos
              ? 'border-ink bg-ink text-white'
              : 'border-[#dfd8cd] bg-white text-[#59534c] hover:border-brand-500'
          "
          @click="seleccionarCategoria(CATEGORIA_COMBOS)"
        >
          <component :is="iconoPara('combo')" :size="15" />
          Combos
        </button>
      </nav>

      <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_360px]">
        <section>
          <div class="mb-4 flex items-baseline justify-between">
            <h2 class="font-display text-2xl text-ink">{{ tituloCategoria }}</h2>
            <span class="text-xs text-[#797269]">
              {{ enCombos ? `${combos.length} ${combos.length === 1 ? 'combo' : 'combos'}` : `${productosDeCategoria.length} productos` }}
            </span>
          </div>

          <!-- Mejora (28/10/2026): dos tarjetas por fila también en el teléfono (una sola se veía muy grande). -->
          <div v-if="enCombos" class="grid grid-cols-2 gap-3 sm:gap-4">
            <button
              v-for="combo in combos"
              :key="combo.id"
              type="button"
              class="flex flex-col rounded-2xl border bg-white p-2.5 text-left transition sm:p-4"
              :class="
                comboSeleccionado?.id === combo.id
                  ? 'border-brand-500 shadow-[0_0_0_2px_rgba(214,164,58,0.14)]'
                  : 'border-[#e2dbcf] hover:border-brand-300'
              "
              @click="elegirCombo(combo)"
            >
              <div
                class="mb-2.5 flex aspect-[4/3] items-center justify-center rounded-xl bg-gradient-to-br sm:mb-3 from-[#efe8db] to-[#e0d6c2] text-brand-700/60"
              >
                <component :is="iconoPara(combo.nombre)" :size="30" :stroke-width="1.5" />
              </div>
              <p class="font-display text-[15px] leading-snug text-ink sm:text-lg">{{ combo.nombre }}</p>
              <p v-if="componentesCombo(combo)" class="mt-1 line-clamp-2 text-[11px] text-[#6f685f] sm:text-xs">
                Incluye {{ componentesCombo(combo) }}
              </p>
              <div class="mt-auto flex flex-wrap items-baseline justify-between gap-x-2 pt-2.5 sm:pt-3">
                <p class="text-sm text-ink sm:text-base">{{ formatoPrecio(Number(combo.precio)) }}</p>
                <span class="hidden border-b border-brand-700 pb-0.5 text-xs font-medium text-brand-700 sm:inline">Elegir</span>
                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-500 text-ink sm:hidden" aria-hidden="true"><Plus :size="15" /></span>
              </div>
            </button>
          </div>

          <p v-else-if="productosDeCategoria.length === 0" class="text-sm text-[#6c655c]">
            No hay productos disponibles en esta categoría por ahora.
          </p>

          <div v-if="!enCombos" class="grid grid-cols-2 gap-3 sm:gap-4">
            <button
              v-for="producto in productosDeCategoria"
              :key="producto.id"
              type="button"
              class="flex flex-col rounded-2xl border bg-white p-2.5 text-left transition sm:p-4"
              :class="
                productoSeleccionado?.id === producto.id
                  ? 'border-brand-500 shadow-[0_0_0_2px_rgba(214,164,58,0.14)]'
                  : 'border-[#e2dbcf] hover:border-brand-300'
              "
              @click="elegirProducto(producto)"
            >
              <div
                class="mb-2.5 flex aspect-[4/3] items-center justify-center overflow-hidden rounded-xl sm:mb-3 bg-gradient-to-br from-[#efe8db] to-[#e0d6c2] text-brand-700/60"
              >
                <!-- Fase 7, tarea 3 (RF-M04-004): la foto del producto si la tiene. -->
                <img v-if="producto.imagen_url" :src="producto.imagen_url" :alt="producto.nombre" loading="lazy" class="size-full object-cover" />
                <component :is="iconoPara(producto.nombre)" v-else :size="30" :stroke-width="1.5" />
              </div>
              <p class="font-display text-[15px] leading-snug text-ink sm:text-lg">
                {{ producto.nombre }}
                <!-- Fase 2, tarea 1 (RF-M04-035): promoción vigente del producto o su categoría. -->
                <span v-if="etiquetaPromocion(producto)" class="ml-1 align-middle rounded-full bg-emerald-50 px-2 py-0.5 font-sans text-[11px] font-semibold text-emerald-700">{{ etiquetaPromocion(producto) }}</span>
              </p>
              <p v-if="producto.descripcion" class="mt-1 line-clamp-2 text-[11px] text-[#6f685f] sm:text-xs">
                {{ producto.descripcion }}
              </p>
              <div class="mt-auto flex items-end justify-between gap-2 pt-2.5 sm:pt-3">
                <div class="min-w-0">
                  <p class="text-[10px] text-[#8a8379]">Desde</p>
                  <p class="text-sm text-ink sm:text-base">
                    <template v-if="precioConPromocion(producto, Number(producto.precio_base)) < Number(producto.precio_base)">
                      <span class="mr-1 text-xs text-[#a59d90] line-through">{{ formatoPrecio(Number(producto.precio_base)) }}</span>{{ formatoPrecio(precioConPromocion(producto, Number(producto.precio_base))) }}
                    </template>
                    <template v-else>{{ formatoPrecio(Number(producto.precio_base)) }}</template>
                  </p>
                </div>
                <span class="hidden border-b border-brand-700 pb-0.5 text-xs font-medium text-brand-700 sm:inline">Elegir</span>
                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-500 text-ink sm:hidden" aria-hidden="true"><Plus :size="15" /></span>
              </div>
            </button>
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
            v-if="haySeleccion && mostrarConfiguradorMovil"
            class="fixed inset-0 z-30 bg-black/40 lg:hidden"
            @click="cerrarConfiguradorMovil"
          />
        </Transition>

        <aside
          v-if="haySeleccion"
          class="fixed inset-x-0 bottom-0 z-40 flex max-h-[85vh] flex-col rounded-t-3xl border-t border-[#ded7cc] bg-[#fffdf9] shadow-[0_-18px_40px_rgba(53,42,20,0.18)] transition-transform duration-300 ease-out lg:sticky lg:top-24 lg:inset-x-auto lg:bottom-auto lg:z-auto lg:h-fit lg:max-h-none lg:translate-y-0 lg:rounded-2xl lg:border lg:shadow-[0_18px_22.5px_rgba(53,42,20,0.08)]"
          :class="mostrarConfiguradorMovil ? 'translate-y-0' : 'translate-y-full'"
        >
          <!-- Encabezado: fijo (no se desplaza con el contenido), para que el botón de
               cerrar y el drag handle siempre queden a la mano en móvil. -->
          <div class="shrink-0 px-5 pt-5 lg:p-5 lg:pb-0">
            <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-[#e5ded2] lg:hidden" aria-hidden="true" />
            <div class="flex items-center justify-between gap-3">
              <span class="text-[10px] font-medium tracking-[1.8px] text-brand-700">TU ELECCIÓN</span>
              <span class="hidden text-[11px] text-[#7d756b] lg:inline">Personaliza aquí</span>
              <button
                type="button"
                class="flex size-8 shrink-0 items-center justify-center rounded-full text-[#7d756b] hover:bg-black/5 lg:hidden"
                aria-label="Cerrar"
                @click="cerrarConfiguradorMovil"
              >
                <X :size="18" />
              </button>
            </div>
          </div>

          <!-- Cuerpo: lo único que se desplaza en móvil cuando el producto tiene
               muchas variantes/extras — el botón "Agregar" de abajo nunca se mueve. -->
          <div class="min-h-0 flex-1 overflow-y-auto px-5 lg:overflow-visible">
            <div
              class="flex aspect-[4/3] items-center justify-center overflow-hidden rounded-xl bg-gradient-to-br from-[#efe8db] to-[#e0d6c2] text-brand-700/60"
            >
              <img
                v-if="productoSeleccionado && fotoSeleccion"
                :key="fotoSeleccion"
                :src="fotoSeleccion"
                :alt="varianteSeleccionada ? `${productoSeleccionado.nombre} ${varianteSeleccionada.nombre}` : productoSeleccionado.nombre"
                class="size-full object-cover"
              />
              <component :is="iconoPara(comboSeleccionado?.nombre ?? productoSeleccionado?.nombre ?? '')" v-else :size="56" :stroke-width="1.5" />
            </div>
            <p class="mt-4 font-display text-2xl text-ink">{{ comboSeleccionado?.nombre ?? productoSeleccionado?.nombre }}</p>
            <p v-if="productoSeleccionado?.descripcion" class="mt-2 text-[13px] leading-relaxed text-[#696158]">
              {{ productoSeleccionado.descripcion }}
            </p>
            <template v-if="comboSeleccionado">
              <p class="mt-5 text-xs text-ink">Incluye</p>
              <ul class="mt-2 space-y-1 text-[13px] text-[#696158]">
                <li v-for="p in comboSeleccionado.productos ?? []" :key="p.id">{{ p.pivot?.cantidad ?? 1 }} × {{ p.nombre }}</li>
              </ul>
            </template>

            <template v-if="productoSeleccionado?.variantes?.length">
              <p class="mt-5 text-xs text-ink">Elige la presentación</p>
              <div class="mt-2 space-y-2">
                <label
                  v-for="variante in (productoSeleccionado.variantes ?? []).filter((v) => v.activa)"
                  :key="variante.id"
                  class="flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-xs"
                  :class="
                    varianteSeleccionada?.id === variante.id
                      ? 'border-brand-500 bg-[#fff7e5]'
                      : 'border-[#dfd8cd] bg-white'
                  "
                >
                  <span class="flex items-center gap-3">
                    <input
                      type="radio"
                      :name="'variante-' + productoSeleccionado.id"
                      class="accent-brand-600"
                      :checked="varianteSeleccionada?.id === variante.id"
                      @change="varianteSeleccionada = variante"
                    />
                    <span>
                      <span class="block">{{ variante.nombre }}</span>
                      <!-- Mejora (16/10/2026): qué trae cada presentación. -->
                      <span v-if="variante.descripcion" class="mt-0.5 block text-[11px] leading-snug text-[#7d756b]">{{ variante.descripcion }}</span>
                    </span>
                  </span>
                  <span class="shrink-0 text-right text-brand-800">
                    <template v-if="precioConPromocion(productoSeleccionado, Number(variante.precio), variante.id) < Number(variante.precio)">
                      <span class="block text-[10px] text-[#a59d90] line-through">{{ formatoPrecio(Number(variante.precio)) }}</span>
                      <span class="font-semibold text-emerald-700">{{ formatoPrecio(precioConPromocion(productoSeleccionado, Number(variante.precio), variante.id)) }}</span>
                    </template>
                    <template v-else>{{ formatoPrecio(Number(variante.precio)) }}</template>
                  </span>
                </label>
              </div>
            </template>

            <template v-if="productoSeleccionado && extrasDe(productoSeleccionado).length">
              <p class="mt-5 text-xs text-ink">Extras</p>
              <div class="mt-2 space-y-2">
                <label
                  v-for="extra in extrasDe(productoSeleccionado)"
                  :key="extra.id"
                  class="flex items-center justify-between gap-3 rounded-xl border border-[#dfd8cd] bg-white px-4 py-3 text-xs"
                >
                  <span class="flex items-center gap-3">
                    <input
                      type="checkbox"
                      class="accent-brand-600"
                      :checked="extrasSeleccionados.some((e) => e.id === extra.id)"
                      @change="alternarExtra(extra)"
                    />
                    {{ extra.nombre }}
                  </span>
                  <span class="text-brand-800">+{{ formatoPrecio(Number(extra.precio)) }}</span>
                </label>
              </div>
            </template>

            <label v-if="permiteQuitarIngredientes" class="mt-5 block pb-5 text-xs text-ink">
              ¿Quieres quitar algún ingrediente?
              <input v-formato="'texto'" maxlength="500"
                v-model="notas"
                type="text"
                placeholder="Indicación opcional para cocina"
                class="mt-2 w-full rounded-lg border border-[#dfd8cd] px-3 py-2 text-xs focus:border-brand-500 focus:outline-none"
              />
            </label>
            <div v-else class="pb-5" />
          </div>

          <!-- Pie: fijo, siempre visible — nunca hace falta bajar para encontrar "Agregar". -->
          <div class="flex shrink-0 items-center gap-3 border-t border-[#e5ded2] px-5 py-5">
            <div class="flex items-center gap-3 rounded-xl border border-[#ddd5c9] px-3 py-3">
              <button
                type="button"
                class="text-lg text-ink disabled:opacity-30"
                :disabled="cantidad <= 1"
                @click="cantidad--"
              >
                −
              </button>
              <span class="w-4 text-center text-sm">{{ cantidad }}</span>
              <button type="button" class="text-lg text-ink disabled:opacity-30" :disabled="cantidad >= MAX_UNIDADES_POR_LINEA" @click="cantidad++">+</button>
            </div>
            <button
              type="button"
              class="flex flex-1 items-center justify-between rounded-xl bg-brand-500 px-4 py-3 text-xs font-medium text-ink transition hover:bg-brand-400"
              @click="agregarAlPedido"
            >
              <span>Agregar a mi pedido</span>
              <span>{{ formatoPrecio(precioSeleccion * cantidad) }}</span>
            </button>
          </div>
        </aside>
      </div>
    </template>

    <!-- Mejora de diseño (16/10/2026): botón flotante del pedido (antes, una barra fija a todo lo ancho). -->
    <CarritoFlotante
      :cantidad="carrito.totalProductos"
      :aviso="carrito.ultimoAgregado"
      :oculto="haySeleccion && mostrarConfiguradorMovil"
      solo-movil
      @abrir="router.push('/carrito')"
    />
  </div>
</template>
