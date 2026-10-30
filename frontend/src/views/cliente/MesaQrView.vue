<script setup lang="ts">
// M02/M05 — Pedido por QR (RF-M02-L-025 a 028, RN-PED-29, HU-M02-021): el Cliente
// escanea el QR de su mesa (ver MesaController::porQrCode / QrMesaModal.vue en Admin),
// ve el menú, arma su pedido y lo envía SIN cuenta ni datos de contacto — el pedido se
// identifica solo por la mesa (tipo="local", origen="qr") y queda "pendiente" hasta que
// el Mesero lo confirma (PedidosView.vue del Mesero).
//
// Vista independiente (no usa ClienteLayout): el Cliente llegó aquí escaneando un papel
// en la mesa, no navegando el sitio — nav, login y footer solo le añadirían ruido. Pedido
// explícito del Administrador: "trata que la página sea más intuitiva porque el cliente
// no sabe mucho" — un solo flujo vertical (menú → carrito → confirmación), sin pasos de
// pago (el Mesero cobra después, igual que Recogida) ni campos que no sean necesarios.
import FotoCompleta from '@/components/common/FotoCompleta.vue'
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { AlertTriangle, ArrowLeft, CheckCircle2, Minus, Plus, Trash2 } from 'lucide-vue-next'
import BaseModal from '@/components/common/BaseModal.vue'
import CarritoFlotante from '@/components/common/CarritoFlotante.vue'
import LogoNegocio from '@/components/common/LogoNegocio.vue'
import { extrasDe } from '@/utils/extras'
import { mesaService } from '@/services/mesa.service'
import { catalogoService } from '@/services/catalogo.service'
import { pedidoService } from '@/services/pedido.service'
import { MAX_UNIDADES_POR_LINEA, useCarritoStore } from '@/stores/carrito'
import { componentesCombo } from '@/utils/combos'
import { useCotizacion } from '@/composables/useCotizacion'
import { mensajeDeError } from '@/services/errors'
import { iconoPorNombre as iconoPara } from '@/utils/iconoCategoria'
import { etiquetaPromocion, precioConPromocion } from '@/utils/promociones'
import type { Categoria, Combo, Extra, Mesa, Pedido, Producto, Variante } from '@/types/models'

const route = useRoute()
const carrito = useCarritoStore()

// Fase 2, tarea 1 (RF-M04-035): promociones vigentes aplicadas al pedido de la mesa.
const { cotizacion } = useCotizacion(() => 'local', () => carrito.aLineasPedido())
const promociones = computed(() => cotizacion.value?.promociones ?? [])
const totalConPromociones = computed(() => carrito.total - (cotizacion.value?.descuento ?? 0))

// ---- Paso 1: resolver la mesa a partir del QR ----
const cargandoMesa = ref(true)
const mesa = ref<Mesa | null>(null)
const errorMesa = ref('')

// ---- Menú ----
// Revisión por rol (13/10/2026, RF-M04-020/031): pestaña "Combos" (antes no se ofrecían por QR).
const CATEGORIA_COMBOS = -1
const categorias = ref<Categoria[]>([])
const productos = ref<Producto[]>([])
const combos = ref<Combo[]>([])
const cargandoMenu = ref(true)
const errorMenu = ref('')
const categoriaActivaId = ref<number | null>(null)
const enCombos = computed(() => categoriaActivaId.value === CATEGORIA_COMBOS)

const productosDeCategoria = computed(() => productos.value.filter((p) => p.categoria_id === categoriaActivaId.value))

// ---- Personalizar producto (hoja/modal, no un panel aparte — más simple en celular) ----
const productoAbierto = ref<Producto | null>(null)
const comboAbierto = ref<Combo | null>(null)
const modalAbierto = computed(() => Boolean(productoAbierto.value || comboAbierto.value))
const varianteSeleccionada = ref<Variante | null>(null)
const extrasSeleccionados = ref<Extra[]>([])
const cantidadSeleccionada = ref(1)
const notasSeleccionadas = ref('')

function abrirCombo(combo: Combo) {
  comboAbierto.value = combo
  productoAbierto.value = null
  varianteSeleccionada.value = null
  extrasSeleccionados.value = []
  cantidadSeleccionada.value = 1
  notasSeleccionadas.value = ''
}

function cerrarModal() {
  productoAbierto.value = null
  comboAbierto.value = null
}

function abrirProducto(producto: Producto) {
  comboAbierto.value = null
  productoAbierto.value = producto
  varianteSeleccionada.value = producto.variantes?.find((v) => v.activa) ?? null
  extrasSeleccionados.value = []
  cantidadSeleccionada.value = 1
  notasSeleccionadas.value = ''
}

function alternarExtra(extra: Extra) {
  const idx = extrasSeleccionados.value.findIndex((e) => e.id === extra.id)
  if (idx >= 0) extrasSeleccionados.value.splice(idx, 1)
  else extrasSeleccionados.value.push(extra)
}

// Mejora (16/10/2026): el precio del botón ya trae la oferta de la presentación elegida.
const precioSeleccion = computed(() => {
  if (comboAbierto.value) return Number(comboAbierto.value.precio)
  const producto = productoAbierto.value
  if (!producto) return 0
  const variante = varianteSeleccionada.value
  const base = Number(variante?.precio ?? producto.precio_base)
  const extras = extrasSeleccionados.value.reduce((acc, e) => acc + Number(e.precio), 0)
  return precioConPromocion(producto, base, variante?.id ?? null) + extras
})

const fotoSeleccion = computed(() => varianteSeleccionada.value?.imagen_url || productoAbierto.value?.imagen_url || '')

function confirmarAgregar() {
  if (comboAbierto.value) {
    carrito.agregar({
      producto: null,
      combo: comboAbierto.value,
      variante: null,
      extras: [],
      cantidad: cantidadSeleccionada.value,
      notas: notasSeleccionadas.value.trim() || undefined,
    })
  } else if (productoAbierto.value) {
    carrito.agregar({
      producto: productoAbierto.value,
      variante: varianteSeleccionada.value,
      extras: [...extrasSeleccionados.value],
      cantidad: cantidadSeleccionada.value,
      notas: notasSeleccionadas.value.trim() || undefined,
    })
  } else {
    return
  }
  // Mejora de diseño (16/10/2026): aviso breve de lo que se agregó (lo muestra CarritoFlotante).
  const nombre = comboAbierto.value?.nombre ?? productoAbierto.value?.nombre ?? ''
  const presentacion = varianteSeleccionada.value && !comboAbierto.value ? ` (${varianteSeleccionada.value.nombre})` : ''
  const unidades = cantidadSeleccionada.value > 1 ? `${cantidadSeleccionada.value} × ` : ''
  avisoAgregado.value = { id: Date.now(), texto: `Agregado: ${unidades}${nombre}${presentacion}` }
  cerrarModal()
}

const avisoAgregado = ref<{ id: number; texto: string } | null>(null)

// ---- Pasos de la página: menú → carrito → confirmación ----
const paso = ref<'menu' | 'carrito' | 'confirmado'>('menu')
const enviando = ref(false)
const errorEnvio = ref('')
const pedidoCreado = ref<Pedido | null>(null)

async function enviarPedido() {
  if (!mesa.value || carrito.lineas.length === 0) return
  enviando.value = true
  errorEnvio.value = ''
  try {
    const { data } = await pedidoService.crear({
      tipo: 'local',
      origen: 'qr',
      mesa_id: mesa.value.id,
      lineas: carrito.aLineasPedido(),
    })
    pedidoCreado.value = data
    carrito.vaciar()
    paso.value = 'confirmado'
  } catch (e) {
    errorEnvio.value = mensajeDeError(e, 'No pudimos enviar tu pedido. Intenta de nuevo.')
  } finally {
    enviando.value = false
  }
}

function pedirAlgoMas() {
  pedidoCreado.value = null
  paso.value = 'menu'
}

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

onMounted(async () => {
  // Pedido nuevo cada vez que se escanea un QR: si el celular traía algo en el carrito
  // de una sesión de Domicilio/Recogida sin terminar, no se mezcla con este pedido local.
  // Corrección (16/10/2026): el carrito ahora se guarda en el navegador; si es de esta misma mesa
  // (se recargó la página) se conserva y se actualiza con el catálogo de hoy.
  const qrCode = String(route.params.qr_code)
  carrito.usarOrigen(`mesa:${qrCode}`)
  carrito.actualizarConCatalogo()
  try {
    const { data } = await mesaService.porQrCode(qrCode)
    mesa.value = data
  } catch (e) {
    errorMesa.value = mensajeDeError(e, 'Este código QR ya no es válido. Pide ayuda a un mesero.')
    cargandoMesa.value = false
    return
  }
  cargandoMesa.value = false

  cargandoMenu.value = true
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
    categoriaActivaId.value = categorias.value[0]?.id ?? (combos.value.length ? CATEGORIA_COMBOS : null)
  } catch {
    errorMenu.value = 'No pudimos cargar el menú. Desliza hacia abajo para intentar de nuevo.'
  } finally {
    cargandoMenu.value = false
  }
})
</script>

<template>
  <div class="min-h-screen bg-cream pb-28">
    <!-- Encabezado mínimo: sin nav, sin login, sin footer — el Cliente llegó por el QR,
         no navegando el sitio (pedido explícito: la página debe ser simple). -->
    <header class="sticky top-0 z-20 border-b border-[#e9e3d8] bg-white px-4 py-3.5 sm:px-6">
      <div class="mx-auto flex max-w-2xl items-center justify-between">
        <div class="flex items-center gap-2.5">
          <LogoNegocio tamano="size-9" texto="text-sm" />
          <span class="font-display text-base font-medium text-ink">Fragua Real</span>
        </div>
        <span
          v-if="mesa"
          class="rounded-full bg-brand-500/15 px-3.5 py-1.5 text-xs font-medium text-brand-800"
        >
          {{ mesa.nombre }}
        </span>
      </div>
    </header>

    <!-- Cargando mesa -->
    <div v-if="cargandoMesa" class="mx-auto max-w-2xl px-4 py-16 text-center text-sm text-[#6c655c]">
      Verificando tu mesa…
    </div>

    <!-- QR inválido/mesa inactiva -->
    <div v-else-if="errorMesa" class="mx-auto max-w-md px-4 py-16 text-center">
      <AlertTriangle :size="40" class="mx-auto text-amber-600" />
      <h1 class="mt-4 font-display text-xl text-ink">No pudimos abrir tu mesa</h1>
      <p class="mt-2 text-sm text-[#6c655c]">{{ errorMesa }}</p>
    </div>

    <template v-else>
      <!-- Confirmación -->
      <div v-if="paso === 'confirmado'" class="mx-auto max-w-md px-4 py-16 text-center">
        <CheckCircle2 :size="52" class="mx-auto text-brand-600" />
        <h1 class="mt-4 font-display text-2xl text-ink">¡Pedido enviado!</h1>
        <p class="mt-2 text-sm text-[#6c655c]">
          Pedido #{{ pedidoCreado?.id }} para <strong>{{ mesa?.nombre }}</strong>.
        </p>
        <p class="mt-4 rounded-xl bg-white px-4 py-3 text-sm text-[#6c655c]">
          Un mesero va a confirmarlo en un momento y pronto empezamos a prepararlo. Pagas al final, cuando termines.
        </p>
        <button
          type="button"
          class="mt-8 inline-flex items-center justify-center rounded-xl bg-brand-500 px-6 py-3 text-sm font-medium text-ink shadow-[0_8px_20px_rgba(214,164,58,0.35)] transition-all duration-150 hover:-translate-y-0.5 hover:bg-brand-400"
          @click="pedirAlgoMas"
        >
          Pedir algo más
        </button>
      </div>

      <!-- Carrito -->
      <div v-else-if="paso === 'carrito'" class="mx-auto max-w-2xl px-4 py-8 sm:px-6">
        <button type="button" class="flex items-center gap-1.5 text-sm text-[#6c655c] hover:text-ink" @click="paso = 'menu'">
          <ArrowLeft :size="16" /> Seguir viendo el menú
        </button>

        <h1 class="mt-4 font-display text-3xl text-ink">Tu pedido</h1>

        <div v-if="carrito.lineas.length === 0" class="mt-10 text-center text-sm text-[#6c655c]">
          Todavía no has agregado nada.
        </div>

        <ul v-else class="mt-6 space-y-3">
          <li
            v-for="linea in carrito.lineas"
            :key="linea.id"
            class="flex items-start gap-4 rounded-2xl border border-[#e2dbcf] bg-white p-4"
          >
            <div class="min-w-0 flex-1">
              <p class="font-display text-base text-ink">{{ carrito.nombreLinea(linea) }}</p>
              <p v-if="linea.combo && componentesCombo(linea.combo)" class="mt-0.5 text-xs text-[#6c655c]">
                Incluye {{ componentesCombo(linea.combo) }}
              </p>
              <p v-if="linea.variante" class="mt-0.5 text-xs text-[#6c655c]">{{ linea.variante.nombre }}</p>
              <p v-if="linea.extras.length" class="mt-0.5 text-xs text-[#6c655c]">
                + {{ linea.extras.map((e) => e.nombre).join(', ') }}
              </p>
              <p v-if="linea.notas" class="mt-0.5 text-xs italic text-[#8a8379]">"{{ linea.notas }}"</p>

              <div class="mt-3 flex items-center gap-3">
                <div class="flex items-center gap-3 rounded-lg border border-[#ddd5c9] px-2 py-1">
                  <button
                    type="button"
                    class="text-ink disabled:opacity-30"
                    :disabled="linea.cantidad <= 1"
                    @click="carrito.actualizarCantidad(linea.id, linea.cantidad - 1)"
                  >
                    <Minus :size="14" />
                  </button>
                  <span class="w-4 text-center text-sm">{{ linea.cantidad }}</span>
                  <button type="button" class="text-ink" @click="carrito.actualizarCantidad(linea.id, linea.cantidad + 1)">
                    <Plus :size="14" />
                  </button>
                </div>
                <button
                  type="button"
                  class="flex items-center gap-1 text-xs text-[#a35b4a] hover:text-red-600"
                  @click="carrito.quitar(linea.id)"
                >
                  <Trash2 :size="14" />
                  Quitar
                </button>
              </div>
            </div>

            <p class="shrink-0 text-sm text-ink">{{ formatoPrecio(carrito.precioUnitario(linea) * linea.cantidad) }}</p>
          </li>
        </ul>

        <div v-if="carrito.lineas.length" class="mt-6 border-t border-[#e2dbcf] pt-5">
          <div v-for="(p, i) in promociones" :key="i" class="mb-2 flex justify-between gap-3 text-sm text-emerald-700">
            <span>{{ p.nombre }}</span>
            <span class="shrink-0">−{{ formatoPrecio(p.monto) }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-sm text-[#6c655c]">Total</span>
            <span class="font-sans font-semibold text-2xl text-ink">{{ formatoPrecio(totalConPromociones) }}</span>
          </div>
        </div>

        <p v-if="errorEnvio" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorEnvio }}</p>

        <button
          v-if="carrito.lineas.length"
          type="button"
          :disabled="enviando"
          class="mt-5 flex w-full items-center justify-center rounded-xl bg-brand-500 px-6 py-3.5 text-sm font-medium text-ink shadow-[0_8px_20px_rgba(214,164,58,0.35)] transition-all duration-150 hover:-translate-y-0.5 hover:bg-brand-400 disabled:opacity-60"
          @click="enviarPedido"
        >
          {{ enviando ? 'Enviando…' : 'Enviar pedido a la cocina' }}
        </button>
      </div>

      <!-- Menú -->
      <div v-else class="mx-auto max-w-2xl px-4 py-8 sm:px-6">
        <h1 class="font-display text-3xl font-medium tracking-tight text-ink">¿Qué te provoca?</h1>
        <p class="mt-2 text-sm text-[#6c655c]">Toca un producto para personalizarlo y agregarlo a tu pedido.</p>

        <p v-if="errorMenu" class="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMenu }}</p>
        <p v-else-if="cargandoMenu" class="mt-10 text-sm text-[#6c655c]">Cargando el menú…</p>

        <template v-else>
          <nav class="mt-6 flex gap-2 overflow-x-auto pb-2">
            <button
              v-for="categoria in categorias"
              :key="categoria.id"
              type="button"
              class="flex shrink-0 items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium transition"
              :class="
                categoria.id === categoriaActivaId
                  ? 'border-ink bg-ink text-white'
                  : 'border-[#dfd8cd] bg-white text-[#59534c]'
              "
              @click="categoriaActivaId = categoria.id"
            >
              <component :is="iconoPara(categoria.nombre)" :size="15" />
              {{ categoria.nombre }}
            </button>
            <button
              v-if="combos.length"
              type="button"
              class="flex shrink-0 items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium transition"
              :class="enCombos ? 'border-ink bg-ink text-white' : 'border-[#dfd8cd] bg-white text-[#59534c]'"
              @click="categoriaActivaId = CATEGORIA_COMBOS"
            >
              <component :is="iconoPara('combo')" :size="15" />
              Combos
            </button>
          </nav>

          <ul v-if="enCombos" class="mt-5 space-y-3">
            <li
              v-for="combo in combos"
              :key="combo.id"
              class="flex items-center gap-4 rounded-2xl border border-[#e2dbcf] bg-white p-3"
            >
              <div
                class="flex size-16 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-[#efe8db] to-[#e0d6c2] text-brand-700/60"
              >
                <component :is="iconoPara(combo.nombre)" :size="28" :stroke-width="1.5" />
              </div>
              <div class="min-w-0 flex-1">
                <p class="font-display text-base text-ink">{{ combo.nombre }}</p>
                <p v-if="componentesCombo(combo)" class="mt-0.5 line-clamp-2 text-xs text-[#6f685f]">
                  Incluye {{ componentesCombo(combo) }}
                </p>
                <p class="mt-1 text-sm text-ink">{{ formatoPrecio(Number(combo.precio)) }}</p>
              </div>
              <button
                type="button"
                class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-500 text-ink transition hover:bg-brand-400"
                :aria-label="`Agregar ${combo.nombre}`"
                @click="abrirCombo(combo)"
              >
                <Plus :size="18" />
              </button>
            </li>
          </ul>

          <p v-else-if="productosDeCategoria.length === 0" class="mt-8 text-sm text-[#6c655c]">
            No hay productos disponibles en esta categoría por ahora.
          </p>

          <ul v-if="!enCombos" class="mt-5 space-y-3">
            <li
              v-for="producto in productosDeCategoria"
              :key="producto.id"
              class="flex items-center gap-4 rounded-2xl border border-[#e2dbcf] bg-white p-3"
            >
              <div
                class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gradient-to-br from-[#efe8db] to-[#e0d6c2] text-brand-700/60"
              >
                <!-- Fase 7, tarea 3 (RF-M04-004): la foto del producto si la tiene. -->
                <FotoCompleta v-if="producto.imagen_url" :src="producto.imagen_url" :alt="producto.nombre" />
                <component :is="iconoPara(producto.nombre)" v-else :size="28" :stroke-width="1.5" />
              </div>
              <div class="min-w-0 flex-1">
                <p class="font-display text-base text-ink">{{ producto.nombre }}</p>
                <p v-if="producto.descripcion" class="mt-0.5 line-clamp-1 text-xs text-[#6f685f]">
                  {{ producto.descripcion }}
                </p>
                <p class="mt-1 text-sm text-ink">
                  <template v-if="etiquetaPromocion(producto)">
                    <span
                      v-if="precioConPromocion(producto, Number(producto.precio_base)) < Number(producto.precio_base)"
                      class="mr-1 text-xs text-[#a59d90] line-through"
                    >{{ formatoPrecio(Number(producto.precio_base)) }}</span>{{ formatoPrecio(precioConPromocion(producto, Number(producto.precio_base))) }}
                    <span class="ml-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">{{ etiquetaPromocion(producto) }}</span>
                  </template>
                  <template v-else>{{ formatoPrecio(Number(producto.precio_base)) }}</template>
                </p>
              </div>
              <button
                type="button"
                class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-500 text-ink transition hover:bg-brand-400"
                @click="abrirProducto(producto)"
              >
                <Plus :size="18" />
              </button>
            </li>
          </ul>
        </template>
      </div>

      <!-- Mejora de diseño (16/10/2026): botón flotante del pedido (antes, una barra fija a todo lo
           ancho). Aquí se ve también en escritorio: esta pantalla no tiene «Mi pedido» en el encabezado. -->
      <CarritoFlotante
        v-if="paso === 'menu'"
        :cantidad="carrito.totalProductos"
        :aviso="avisoAgregado"
        :oculto="modalAbierto"
        @abrir="paso = 'carrito'"
      />
    </template>

    <!-- Personalizar producto -->
    <BaseModal
      :model-value="modalAbierto"
      :title="comboAbierto?.nombre ?? productoAbierto?.nombre ?? ''"
      @update:model-value="cerrarModal"
    >
      <div v-if="modalAbierto" class="space-y-5">
        <!-- Mejora (16/10/2026): con presentación elegida, su foto propia si la tiene. -->
        <div v-if="productoAbierto && fotoSeleccion" class="aspect-[4/3] w-full overflow-hidden rounded-xl">
          <FotoCompleta
            :key="fotoSeleccion"
            :src="fotoSeleccion"
            :alt="varianteSeleccionada ? `${productoAbierto.nombre} ${varianteSeleccionada.nombre}` : productoAbierto.nombre"
            carga="eager"
          />
        </div>
        <p v-if="productoAbierto?.descripcion" class="text-sm text-[#6c655c]">{{ productoAbierto.descripcion }}</p>

        <div v-if="comboAbierto">
          <p class="mb-2 text-xs font-medium text-gray-700">Incluye</p>
          <ul class="space-y-1 text-sm text-[#6c655c]">
            <li v-for="p in comboAbierto.productos ?? []" :key="p.id">{{ p.pivot?.cantidad ?? 1 }} × {{ p.nombre }}</li>
          </ul>
        </div>

        <div v-if="productoAbierto?.variantes?.filter((v) => v.activa).length">
          <p class="mb-2 text-xs font-medium text-gray-700">Elige la presentación</p>
          <div class="space-y-2">
            <label
              v-for="variante in productoAbierto!.variantes!.filter((v) => v.activa)"
              :key="variante.id"
              class="flex items-center justify-between gap-3 rounded-xl border px-4 py-2.5 text-sm"
              :class="varianteSeleccionada?.id === variante.id ? 'border-brand-500 bg-[#fff7e5]' : 'border-gray-200'"
            >
              <span class="flex items-center gap-3">
                <input
                  type="radio"
                  :checked="varianteSeleccionada?.id === variante.id"
                  @change="varianteSeleccionada = variante"
                />
                <span>
                  <span class="block">{{ variante.nombre }}</span>
                  <span v-if="variante.descripcion" class="mt-0.5 block text-xs leading-snug text-[#7d756b]">{{ variante.descripcion }}</span>
                </span>
              </span>
              <span class="shrink-0 text-right text-brand-800">
                <template v-if="precioConPromocion(productoAbierto!, Number(variante.precio), variante.id) < Number(variante.precio)">
                  <span class="block text-[11px] text-[#a59d90] line-through">{{ formatoPrecio(Number(variante.precio)) }}</span>
                  <span class="font-semibold text-emerald-700">{{ formatoPrecio(precioConPromocion(productoAbierto!, Number(variante.precio), variante.id)) }}</span>
                </template>
                <template v-else>{{ formatoPrecio(Number(variante.precio)) }}</template>
              </span>
            </label>
          </div>
        </div>

        <div v-if="productoAbierto && extrasDe(productoAbierto).length">
          <p class="mb-2 text-xs font-medium text-gray-700">Extras</p>
          <div class="space-y-2">
            <label
              v-for="extra in extrasDe(productoAbierto)"
              :key="extra.id"
              class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-2.5 text-sm"
            >
              <span class="flex items-center gap-3">
                <input
                  type="checkbox"
                  :checked="extrasSeleccionados.some((e) => e.id === extra.id)"
                  @change="alternarExtra(extra)"
                />
                {{ extra.nombre }}
              </span>
              <span class="text-brand-800">+{{ formatoPrecio(Number(extra.precio)) }}</span>
            </label>
          </div>
        </div>

        <label class="block text-xs font-medium text-gray-700">
          ¿Algo que quitar o pedir especial?
          <input v-formato="'texto'" maxlength="500"
            v-model="notasSeleccionadas"
            type="text"
            placeholder="Opcional"
            class="mt-1.5 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
          />
        </label>

        <div class="flex items-center gap-3 border-t border-gray-100 pt-4">
          <div class="flex items-center gap-3 rounded-xl border border-gray-200 px-3 py-2.5">
            <button
              type="button"
              class="text-lg text-ink disabled:opacity-30"
              :disabled="cantidadSeleccionada <= 1"
              @click="cantidadSeleccionada--"
            >
              −
            </button>
            <span class="w-4 text-center text-sm">{{ cantidadSeleccionada }}</span>
            <button type="button" class="text-lg text-ink disabled:opacity-30" :disabled="cantidadSeleccionada >= MAX_UNIDADES_POR_LINEA" @click="cantidadSeleccionada++">+</button>
          </div>
          <button
            type="button"
            class="flex flex-1 items-center justify-between rounded-xl bg-brand-500 px-4 py-3 text-sm font-medium text-ink transition hover:bg-brand-400"
            @click="confirmarAgregar"
          >
            <span>Agregar</span>
            <span>{{ formatoPrecio(precioSeleccion * cantidadSeleccionada) }}</span>
          </button>
        </div>
      </div>
    </BaseModal>
  </div>
</template>
