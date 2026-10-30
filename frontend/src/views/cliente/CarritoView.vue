<script setup lang="ts">
// M02 — armar el pedido antes de confirmar. Lee directo del store de
// carrito (variante + extras + notas por línea, ver stores/carrito.ts);
// "Continuar" pasa a Checkout (M02/M07).
//
// Mejora de diseño (16/10/2026): cada línea muestra su foto (la de la presentación si tiene), la
// presentación, los extras, la nota para cocina, el precio por unidad y la rebaja de las promociones;
// al lado, un resumen con subtotal, promociones y total. Antes solo se veía el nombre y la cantidad.
import { ArrowLeft, Minus, NotebookPen, Plus, ShoppingBag, Tag, Trash2 } from 'lucide-vue-next'
import mascota from '@/assets/img/mascota-fragua-real.png'
import { computed } from 'vue'
import { useCarritoStore, type LineaCarrito } from '@/stores/carrito'
import { componentesCombo } from '@/utils/combos'
import { iconoPorNombre } from '@/utils/iconoCategoria'
import { useCotizacion } from '@/composables/useCotizacion'

const carrito = useCarritoStore()

// Fase 2, tarea 1 (RF-M04-035): promociones vigentes del carrito (el domicilio se suma en el checkout).
const { cotizacion } = useCotizacion(() => 'recogida', () => carrito.aLineasPedido())
const promociones = computed(() => cotizacion.value?.promociones ?? [])
// El subtotal sale del mismo cálculo local que el total, para que no se desfasen mientras llega la cotización.
const subtotal = computed(() => carrito.total)
const totalConPromociones = computed(() => carrito.total - (cotizacion.value?.descuento ?? 0))

// La cotización devuelve sus líneas en el mismo orden que el carrito.
function rebajaDeLinea(indice: number): number {
  return Number(cotizacion.value?.lineas?.[indice]?.descuento ?? 0)
}

function foto(linea: LineaCarrito): string {
  return linea.variante?.imagen_url || linea.producto?.imagen_url || ''
}

function formatoPrecio(valor: number) {
  return valor.toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}
</script>

<template>
  <div class="mx-auto min-h-[calc(100dvh-5rem)] max-w-6xl px-4 pb-16 pt-10 sm:px-6">
    <div class="mb-8 flex items-center gap-3">
      <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
      <span class="text-[11px] font-medium tracking-[2px] text-brand-700">MI PEDIDO</span>
    </div>
    <div class="flex flex-wrap items-end justify-between gap-3">
      <h1 class="font-display text-4xl font-medium tracking-tight text-ink sm:text-5xl">Tu carrito</h1>
      <router-link
        v-if="carrito.lineas.length"
        to="/menu"
        class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-700 hover:text-brand-800"
      >
        <ArrowLeft :size="16" />
        Seguir pidiendo
      </router-link>
    </div>

    <!-- Corrección (16/10/2026): si al volver algo cambió en el catálogo, se avisa qué se ajustó. -->
    <div v-if="carrito.avisosActualizacion.length" class="mt-6 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
      <p class="font-medium">Actualizamos tu carrito con el menú de hoy:</p>
      <ul class="mt-1 list-disc space-y-0.5 pl-5">
        <li v-for="aviso in carrito.avisosActualizacion" :key="aviso">{{ aviso }}</li>
      </ul>
    </div>

    <div v-if="carrito.lineas.length === 0" class="mt-16 text-center">
      <img
        :src="mascota"
        alt="Mascota de Fragua Real Burger invitando a pedir"
        class="mx-auto w-44 drop-shadow-[0_20px_30px_rgba(0,0,0,0.12)]"
      />
      <p class="mt-6 font-display text-xl text-ink">Aún no has agregado nada</p>
      <p class="mt-2 text-sm text-[#6c655c]">Explora el menú y arma tu próximo antojo.</p>
      <router-link
        to="/menu"
        class="mt-6 inline-flex items-center justify-center rounded-xl bg-brand-500 px-6 py-3 text-sm font-medium text-ink shadow-[0_8px_20px_rgba(214,164,58,0.35)] transition-all duration-150 hover:-translate-y-0.5 hover:bg-brand-400"
      >
        Ver el menú
      </router-link>
    </div>

    <div v-else class="mt-8 grid items-start gap-6 lg:grid-cols-[1fr_360px]">
      <ul class="space-y-3">
        <li
          v-for="(linea, i) in carrito.lineas"
          :key="linea.id"
          class="flex gap-4 rounded-2xl border border-[#e2dbcf] bg-white p-3 sm:p-4"
        >
          <!-- Foto: la de la presentación, la del producto o un ícono. -->
          <div
            class="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gradient-to-br from-[#efe8db] to-[#e0d6c2] text-brand-700/60 sm:size-24"
          >
            <img v-if="foto(linea)" :src="foto(linea)" :alt="carrito.nombreLinea(linea)" loading="lazy" class="size-full object-cover" />
            <component :is="iconoPorNombre(linea.combo ? 'combo' : carrito.nombreLinea(linea))" v-else :size="30" :stroke-width="1.5" />
          </div>

          <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="font-display text-lg leading-snug text-ink">{{ carrito.nombreLinea(linea) }}</p>
                <div class="mt-1 flex flex-wrap gap-1.5">
                  <span v-if="linea.combo" class="rounded-full bg-ink px-2 py-0.5 text-[11px] font-medium text-brand-400">Combo</span>
                  <span v-if="linea.variante" class="rounded-full bg-[#f3eee5] px-2 py-0.5 text-[11px] font-medium text-[#59534c]">
                    {{ linea.variante.nombre }}
                  </span>
                  <span
                    v-for="extra in linea.extras"
                    :key="extra.id"
                    class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-800"
                  >
                    + {{ extra.nombre }}
                  </span>
                </div>
              </div>

              <!-- Total de la línea, con la rebaja de las promociones si la hay. -->
              <div class="shrink-0 text-right">
                <template v-if="rebajaDeLinea(i) > 0">
                  <p class="text-xs text-[#a59d90] line-through">{{ formatoPrecio(carrito.precioUnitario(linea) * linea.cantidad) }}</p>
                  <p class="font-semibold text-emerald-700">
                    {{ formatoPrecio(carrito.precioUnitario(linea) * linea.cantidad - rebajaDeLinea(i)) }}
                  </p>
                </template>
                <p v-else class="font-semibold text-ink">{{ formatoPrecio(carrito.precioUnitario(linea) * linea.cantidad) }}</p>
                <p class="mt-0.5 text-[11px] text-[#7d756b]">{{ formatoPrecio(carrito.precioUnitario(linea)) }} c/u</p>
              </div>
            </div>

            <p v-if="linea.combo && componentesCombo(linea.combo)" class="mt-1.5 text-xs text-[#6c655c]">
              Incluye {{ componentesCombo(linea.combo) }}
            </p>
            <p v-if="linea.notas" class="mt-1.5 flex items-start gap-1.5 text-xs text-[#6c655c]">
              <NotebookPen :size="13" class="mt-0.5 shrink-0" />
              <span class="italic">"{{ linea.notas }}"</span>
            </p>

            <div class="mt-3 flex items-center gap-3">
              <div class="flex items-center rounded-xl border border-[#ddd5c9]">
                <button
                  type="button"
                  class="flex size-9 items-center justify-center text-ink disabled:opacity-30"
                  :disabled="linea.cantidad <= 1"
                  :aria-label="`Quitar una unidad de ${carrito.nombreLinea(linea)}`"
                  @click="carrito.actualizarCantidad(linea.id, linea.cantidad - 1)"
                >
                  <Minus :size="14" />
                </button>
                <span class="w-7 text-center text-sm font-medium tabular-nums">{{ linea.cantidad }}</span>
                <button
                  type="button"
                  class="flex size-9 items-center justify-center text-ink"
                  :aria-label="`Agregar una unidad de ${carrito.nombreLinea(linea)}`"
                  @click="carrito.actualizarCantidad(linea.id, linea.cantidad + 1)"
                >
                  <Plus :size="14" />
                </button>
              </div>
              <button
                type="button"
                class="flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-[#a35b4a] hover:bg-red-50 hover:text-red-700"
                @click="carrito.quitar(linea.id)"
              >
                <Trash2 :size="14" />
                Quitar
              </button>
            </div>
          </div>
        </li>
      </ul>

      <!-- Resumen -->
      <aside class="rounded-2xl border border-[#e2dbcf] bg-white p-5 sm:p-6 lg:sticky lg:top-24">
        <h2 class="flex items-center gap-2 font-display text-xl text-ink">
          <ShoppingBag :size="18" class="text-brand-700" />
          Resumen
        </h2>
        <dl class="mt-4 space-y-2 text-sm">
          <div class="flex justify-between gap-3 text-[#59534c]">
            <dt>Subtotal ({{ carrito.totalProductos }} {{ carrito.totalProductos === 1 ? 'producto' : 'productos' }})</dt>
            <dd class="tabular-nums">{{ formatoPrecio(subtotal) }}</dd>
          </div>
          <div v-for="(p, i) in promociones" :key="i" class="flex justify-between gap-3 text-emerald-700">
            <dt class="flex items-center gap-1.5"><Tag :size="13" />{{ p.nombre }}</dt>
            <dd class="shrink-0 tabular-nums">−{{ formatoPrecio(p.monto) }}</dd>
          </div>
          <div class="flex items-baseline justify-between gap-3 border-t border-[#e9e3d8] pt-3">
            <dt class="font-medium text-ink">Total</dt>
            <dd class="font-sans font-semibold text-3xl text-ink">{{ formatoPrecio(totalConPromociones) }}</dd>
          </div>
        </dl>
        <p class="mt-2 text-xs text-[#7d756b]">Si eliges domicilio, el valor del envío se suma en el siguiente paso.</p>

        <router-link
          to="/checkout"
          class="mt-5 flex w-full items-center justify-center rounded-xl bg-brand-500 px-6 py-3.5 text-sm font-medium text-ink shadow-[0_8px_20px_rgba(214,164,58,0.35)] transition-all duration-150 hover:-translate-y-0.5 hover:bg-brand-400"
        >
          Continuar
        </router-link>
        <router-link to="/menu" class="mt-3 block text-center text-sm font-medium text-brand-700 hover:text-brand-800">
          Seguir pidiendo
        </router-link>
      </aside>
    </div>
  </div>
</template>
