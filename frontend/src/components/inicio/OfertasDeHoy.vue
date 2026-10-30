<script setup lang="ts">
// Mejora del Inicio (21/10/2026): «Ofertas y combos de hoy». Sale sola de lo que el Administrador ya
// tiene en Catálogo: los productos con una promoción vigente (GET /productos, promociones_vigentes)
// y los combos que hoy se pueden pedir (GET /combos ya filtra vigencia, franja, día y componentes
// disponibles, RN-M04-11/16). Sin ofertas ni combos, la sección no aparece.
// Un producto sencillo o un combo se agregan al pedido con un toque; uno con presentaciones lleva al
// Menú para elegirla. El precio es para mostrar: el total real lo calcula el servidor (RF-M04-035).
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Check, ChevronRight, Plus, Sparkles } from 'lucide-vue-next'
import { useCarritoStore } from '@/stores/carrito'
import { componentesCombo } from '@/utils/combos'
import { iconoPorNombre } from '@/utils/iconoCategoria'
import { etiquetaPromocion, precioConPromocion } from '@/utils/promociones'
import type { Combo, Producto } from '@/types/models'

const props = defineProps<{ productos: Producto[]; combos: Combo[] }>()
const emit = defineEmits<{ (e: 'agregado', texto: string): void }>()

const carrito = useCarritoStore()
const router = useRouter()
const recienAgregado = ref<string | null>(null)
let temporizador: ReturnType<typeof setTimeout> | undefined

const MAXIMO = 8

type Oferta =
  | { clave: string; tipo: 'producto'; nombre: string; detalle: string; foto: string | null; etiqueta: string; antes: number | null; precio: number; directo: boolean; producto: Producto }
  | { clave: string; tipo: 'combo'; nombre: string; detalle: string; foto: string | null; etiqueta: string; antes: number | null; precio: number; directo: true; combo: Combo }

const ofertas = computed<Oferta[]>(() => {
  const productos = props.productos
    .filter((p) => p.disponible !== false && (p.promociones_vigentes ?? []).length)
    .map<Oferta>((p) => {
      // Con presentaciones, el precio «desde» es el de la más barata ya con su oferta.
      const variantes = (p.variantes ?? []).filter((v) => v.disponible !== false)
      const conVariantes = variantes.length > 0
      const opciones = conVariantes
        ? variantes.map((v) => ({ base: Number(v.precio), rebajado: precioConPromocion(p, Number(v.precio), v.id) }))
        : [{ base: Number(p.precio_base), rebajado: precioConPromocion(p, Number(p.precio_base)) }]
      const { base, rebajado } = opciones.reduce((min, o) => (o.rebajado < min.rebajado ? o : min))
      return {
        clave: `p-${p.id}`,
        tipo: 'producto',
        nombre: p.nombre,
        detalle: p.descripcion ?? p.categoria?.nombre ?? '',
        foto: p.imagen_url ?? null,
        etiqueta: etiquetaPromocion(p) ?? 'Oferta',
        antes: rebajado < base ? base : null,
        precio: rebajado,
        // Con presentaciones, o con una oferta que es solo de una de ellas, se elige en el Menú.
        directo: !conVariantes,
        producto: p,
      }
    })
  const combos = props.combos.map<Oferta>((c) => ({
    clave: `c-${c.id}`,
    tipo: 'combo',
    nombre: c.nombre,
    detalle: componentesCombo(c),
    foto: c.productos?.find((p) => p.imagen_url)?.imagen_url ?? null,
    etiqueta: 'Combo',
    antes: null,
    precio: Number(c.precio),
    directo: true,
    combo: c,
  }))
  return [...productos, ...combos].slice(0, MAXIMO)
})

function formatoPrecio(valor: number) {
  return valor.toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function agregar(o: Oferta) {
  if (!o.directo && o.tipo === 'producto') {
    router.push({ name: 'menu', query: { categoria: o.producto.categoria?.nombre } })
    return
  }
  if (o.tipo === 'combo') {
    carrito.agregar({ producto: null, combo: o.combo, variante: null, extras: [], cantidad: 1 })
  } else {
    carrito.agregar({ producto: o.producto, variante: null, extras: [], cantidad: 1 })
  }
  recienAgregado.value = o.clave
  clearTimeout(temporizador)
  temporizador = setTimeout(() => (recienAgregado.value = null), 1800)
  emit('agregado', `Agregado: ${o.nombre}`)
}
</script>

<template>
  <section v-if="ofertas.length" class="mx-auto max-w-6xl px-4 pb-6 sm:px-6" aria-labelledby="titulo-ofertas">
    <div class="mb-6 flex items-end justify-between gap-4">
      <div>
        <div class="mb-3 flex items-center gap-3">
          <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
          <span class="flex items-center gap-1.5 text-[11px] font-medium tracking-[2px] text-brand-700">
            <Sparkles :size="13" aria-hidden="true" />
            OFERTAS Y COMBOS DE HOY
          </span>
        </div>
        <h2 id="titulo-ofertas" class="font-display text-3xl font-medium tracking-tight text-ink">Aprovecha hoy.</h2>
      </div>
      <router-link
        :to="{ name: 'menu', query: { categoria: 'combos' } }"
        class="hidden shrink-0 text-sm font-medium text-brand-700 hover:text-brand-800 sm:inline"
      >
        Ver todo en el menú →
      </router-link>
    </div>

    <!-- En el teléfono se desliza de lado (con imán en cada tarjeta); desde tableta, cuadrícula.
         29/10/2026: en el teléfono, del tamaño de las tarjetas del menú (antes una ocupaba casi toda la pantalla);
         se ven dos y un pedazo de la siguiente, y «Agregar» es el botón redondo «+». -->
    <div class="-mx-4 flex snap-x snap-mandatory scroll-px-4 gap-3 overflow-x-auto px-4 pb-2 [scrollbar-width:none] sm:mx-0 sm:grid sm:grid-cols-2 sm:gap-4 sm:overflow-visible sm:px-0 sm:pb-0 lg:grid-cols-4 [&::-webkit-scrollbar]:hidden">
      <article
        v-for="o in ofertas"
        :key="o.clave"
        class="flex w-[44%] shrink-0 snap-start flex-col overflow-hidden rounded-2xl border border-[#e3ded4] bg-white transition hover:-translate-y-0.5 hover:shadow-[0_14px_28px_rgba(47,38,20,0.10)] sm:w-auto"
      >
        <div class="relative flex aspect-[4/3] items-center justify-center bg-[#fbf0d9]">
          <img v-if="o.foto" :src="o.foto" :alt="o.nombre" class="size-full object-cover" loading="lazy" />
          <component v-else :is="iconoPorNombre(o.nombre)" :size="30" :stroke-width="1.5" class="text-brand-500" aria-hidden="true" />
          <span
            class="absolute left-2 top-2 rounded-full px-2 py-0.5 text-[10px] font-semibold shadow-sm sm:left-3 sm:top-3 sm:px-2.5 sm:py-1 sm:text-[11px]"
            :class="o.tipo === 'combo' ? 'bg-ink text-brand-300' : 'bg-red-600 text-white'"
          >
            {{ o.etiqueta }}
          </span>
        </div>
        <div class="flex flex-1 flex-col p-2.5 sm:p-4">
          <p class="font-display text-[15px] leading-snug text-ink sm:text-lg">{{ o.nombre }}</p>
          <p v-if="o.detalle" class="mt-1 line-clamp-2 text-[11px] text-[#6c655c] sm:text-xs">{{ o.detalle }}</p>
          <div class="mt-auto flex items-end justify-between gap-2 pt-2.5 sm:gap-3 sm:pt-4">
            <p class="min-w-0 leading-tight">
              <span v-if="o.antes" class="block text-[11px] text-[#a59d90] line-through sm:text-xs">{{ formatoPrecio(o.antes) }}</span>
              <span class="whitespace-nowrap text-sm font-semibold text-ink sm:text-lg">
                <span v-if="o.tipo === 'producto' && !o.directo" class="text-[11px] font-medium text-[#6c655c] sm:text-xs">Desde </span>{{ formatoPrecio(o.precio) }}
              </span>
            </p>
            <button
              type="button"
              class="inline-flex size-8 shrink-0 items-center justify-center gap-1.5 rounded-full text-sm font-semibold transition sm:size-auto sm:min-h-10 sm:rounded-xl sm:px-3.5"
              :class="recienAgregado === o.clave ? 'bg-emerald-600 text-white' : 'bg-brand-500 text-ink hover:bg-brand-400'"
              :aria-label="o.directo ? `Agregar ${o.nombre} al pedido` : `Elegir presentación de ${o.nombre}`"
              @click="agregar(o)"
            >
              <Check v-if="recienAgregado === o.clave" :size="16" />
              <Plus v-else-if="o.directo" :size="16" />
              <ChevronRight v-else :size="16" class="sm:hidden" />
              <span class="hidden sm:inline">{{ recienAgregado === o.clave ? 'Listo' : o.directo ? 'Agregar' : 'Elegir' }}</span>
            </button>
          </div>
        </div>
      </article>
    </div>
  </section>
</template>
