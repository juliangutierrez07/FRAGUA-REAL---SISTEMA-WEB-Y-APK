<script setup lang="ts">
// Fase 1, tarea 1 — Editar un pedido (Mesero y Administrador).
// PENDIENTE: agregar, cambiar cantidad u observaciones y retirar líneas (RN-PED-07,
// RF-M02-L-004/005). CONFIRMADO: lo mismo, pero solo con las líneas que la cocina no ha
// preparado (RN-PED-08, RN-PED-10). Cada cambio se guarda al momento, el backend recalcula el
// total y lo deja en el historial (RN-PED-12). Lo hecho con el pedido confirmado queda marcado
// para la comanda de actualización (RN-PED-09) cuando se conecte la impresora.
import { computed, reactive, ref, watch } from 'vue'
import { Minus, Plus, Search, Trash2 } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import { extrasDe } from '@/utils/extras'
import BaseModal from '@/components/common/BaseModal.vue'
import { catalogoService } from '@/services/catalogo.service'
import { pedidoService, type LineaPedidoPayload } from '@/services/pedido.service'
import { mensajeDeError } from '@/services/errors'
import type { Combo, Extra, Pedido, PedidoDetalle, Producto, Variante } from '@/types/models'

const props = defineProps<{ pedido: Pedido | null }>()
const emit = defineEmits<{
  (e: 'cerrar'): void
  (e: 'actualizado', pedido: Pedido): void
}>()

const error = ref('')
const guardando = ref<string | null>(null) // qué acción está en curso, para desactivar solo ese botón

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function precioLinea(detalle: PedidoDetalle, cantidad = detalle.cantidad) {
  const extras = (detalle.extras ?? []).reduce((acc, e) => acc + Number(e.pivot.precio_congelado), 0)
  return (Number(detalle.precio_unitario_congelado) + extras) * cantidad
}

function nombreLinea(detalle: PedidoDetalle) {
  return detalle.combo ? `Combo ${detalle.combo.nombre}` : (detalle.producto?.nombre ?? 'Producto')
}

// Borrador por línea: la cantidad y las observaciones se ajustan aquí y se guardan con un
// solo toque, así el historial muestra "cantidad 2 → 4" y no cuatro cambios de a uno.
const borradores = reactive<Record<number, { cantidad: number; observaciones: string }>>({})

function reiniciarBorradores(pedido: Pedido | null) {
  for (const k of Object.keys(borradores)) delete borradores[Number(k)]
  for (const d of pedido?.detalles ?? []) {
    borradores[d.id] = { cantidad: d.cantidad, observaciones: d.observaciones ?? '' }
  }
}

watch(
  () => props.pedido,
  (pedido, anterior) => {
    reiniciarBorradores(pedido)
    if (pedido && pedido.id !== anterior?.id) {
      error.value = ''
      cerrarSelector()
    }
  },
  { immediate: true },
)

function cambioPendiente(detalle: PedidoDetalle) {
  const b = borradores[detalle.id]
  return !!b && (b.cantidad !== detalle.cantidad || b.observaciones.trim() !== (detalle.observaciones ?? ''))
}

async function ejecutar(clave: string, accion: () => Promise<{ data: Pedido }>, mensajeFallo: string) {
  if (!props.pedido) return
  guardando.value = clave
  error.value = ''
  try {
    const { data } = await accion()
    emit('actualizado', data)
  } catch (e) {
    error.value = mensajeDeError(e, mensajeFallo)
    reiniciarBorradores(props.pedido) // vuelve a mostrar lo que de verdad quedó guardado
  } finally {
    guardando.value = null
  }
}

function guardarLinea(detalle: PedidoDetalle) {
  const b = borradores[detalle.id]
  const id = props.pedido!.id
  ejecutar(
    `linea-${detalle.id}`,
    () => pedidoService.actualizarLinea(id, detalle.id, { cantidad: b.cantidad, observaciones: b.observaciones.trim() || null }),
    'No pudimos guardar el cambio.',
  )
}

const lineaARetirar = ref<PedidoDetalle | null>(null)

function retirarLinea() {
  const detalle = lineaARetirar.value
  if (!detalle) return
  const id = props.pedido!.id
  lineaARetirar.value = null
  ejecutar(`retirar-${detalle.id}`, () => pedidoService.retirarLinea(id, detalle.id), 'No pudimos retirar el producto.')
}

// ---- Agregar un producto o combo ----
const selectorAbierto = ref(false)
const cargandoCatalogo = ref(false)
const productos = ref<Producto[]>([])
const combos = ref<Combo[]>([])
const busqueda = ref('')

const productoElegido = ref<Producto | null>(null)
const comboElegido = ref<Combo | null>(null)
const varianteElegida = ref<Variante | null>(null)
const extrasElegidos = ref<Extra[]>([])
const cantidadNueva = ref(1)
const observacionesNuevas = ref('')

async function abrirSelector() {
  selectorAbierto.value = true
  if (productos.value.length || combos.value.length) return
  cargandoCatalogo.value = true
  try {
    const [{ data: listaProductos }, { data: listaCombos }] = await Promise.all([
      catalogoService.productos(),
      catalogoService.combos(),
    ])
    productos.value = listaProductos.filter((p) => p.activo && p.disponible)
    combos.value = listaCombos
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar el catálogo.')
  } finally {
    cargandoCatalogo.value = false
  }
}

function cerrarSelector() {
  selectorAbierto.value = false
  productoElegido.value = null
  comboElegido.value = null
  busqueda.value = ''
}

const sinTildes = (t: string) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

const opciones = computed(() => {
  const q = sinTildes(busqueda.value.trim())
  const coincide = (nombre: string) => !q || sinTildes(nombre).includes(q)
  return [
    ...combos.value.filter((c) => coincide(c.nombre)).map((c) => ({ clave: `c${c.id}`, nombre: `Combo ${c.nombre}`, precio: Number(c.precio), combo: c, producto: null })),
    ...productos.value.filter((p) => coincide(p.nombre)).map((p) => ({ clave: `p${p.id}`, nombre: p.nombre, precio: Number(p.precio_base), combo: null, producto: p })),
  ].slice(0, 40)
})

function elegir(opcion: (typeof opciones.value)[number]) {
  productoElegido.value = opcion.producto
  comboElegido.value = opcion.combo
  varianteElegida.value = opcion.producto?.variantes?.find((v) => v.activa && v.disponible !== false) ?? null
  extrasElegidos.value = []
  cantidadNueva.value = 1
  observacionesNuevas.value = ''
}

function alternarExtra(extra: Extra) {
  const i = extrasElegidos.value.findIndex((e) => e.id === extra.id)
  if (i === -1) extrasElegidos.value.push(extra)
  else extrasElegidos.value.splice(i, 1)
}

const precioNuevaLinea = computed(() => {
  if (comboElegido.value) return Number(comboElegido.value.precio) * cantidadNueva.value
  if (!productoElegido.value) return 0
  const base = varianteElegida.value ? Number(varianteElegida.value.precio) : Number(productoElegido.value.precio_base)
  return (base + extrasElegidos.value.reduce((acc, e) => acc + Number(e.precio), 0)) * cantidadNueva.value
})

async function agregar() {
  if (!props.pedido || (!productoElegido.value && !comboElegido.value)) return
  const linea: LineaPedidoPayload = comboElegido.value
    ? { combo_id: comboElegido.value.id, cantidad: cantidadNueva.value }
    : {
        producto_id: productoElegido.value!.id,
        variante_id: varianteElegida.value?.id ?? null,
        cantidad: cantidadNueva.value,
        extras_ids: extrasElegidos.value.map((e) => e.id),
      }
  if (observacionesNuevas.value.trim()) linea.observaciones = observacionesNuevas.value.trim()
  const id = props.pedido.id
  await ejecutar('agregar', () => pedidoService.agregarLinea(id, linea), 'No pudimos agregar el producto.')
  if (!error.value) cerrarSelector()
}

const lineasPreparadas = computed(() => (props.pedido?.detalles ?? []).filter((d) => d.preparado).length)
</script>

<template>
  <BaseModal
    :model-value="!!pedido"
    :title="pedido ? `Editar pedido #${pedido.id}` : 'Editar pedido'"
    @update:model-value="emit('cerrar')"
  >
    <template v-if="pedido">
      <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">
        <template v-if="pedido.estado === 'confirmado'">
          El pedido ya está confirmado: solo cambias lo que la cocina no ha preparado.
          <span v-if="lineasPreparadas">Las líneas marcadas como preparadas no se tocan.</span>
        </template>
        <template v-else>Mientras esté pendiente puedes agregar, cambiar o quitar productos.</template>
        Cada cambio queda en el historial del pedido.
      </p>

      <p v-if="error" class="mt-3 alert-error">{{ error }}</p>

      <ul class="mt-4 divide-y divide-gray-200/70">
        <li v-for="detalle in pedido.detalles" :key="detalle.id" class="py-3 first:pt-0">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 text-sm" :class="detalle.preparado ? 'text-gray-400' : 'text-ink'">
              <p class="font-semibold">
                {{ nombreLinea(detalle) }}
                <span v-if="detalle.variante" class="text-xs font-normal text-gray-500">({{ detalle.variante.nombre }})</span>
              </p>
              <p v-if="detalle.extras?.length" class="text-xs text-gray-500">
                + {{ detalle.extras.map((e) => e.nombre).join(', ') }}
              </p>
            </div>
            <span class="shrink-0 text-sm font-medium text-gray-600">
              {{ formatoPrecio(precioLinea(detalle, borradores[detalle.id]?.cantidad ?? detalle.cantidad)) }}
            </span>
          </div>

          <p v-if="detalle.preparado" class="mt-1.5 text-xs text-gray-500">
            <span class="badge bg-emerald-50 text-emerald-700 ring-emerald-600/20">Preparado</span>
            {{ detalle.cantidad }} × — ya no se puede cambiar ni retirar.
          </p>

          <div v-else-if="borradores[detalle.id]" class="mt-2 space-y-2">
            <div class="flex flex-wrap items-center gap-2">
              <div class="inline-flex items-center rounded-xl border border-gray-200 bg-white">
                <button
                  type="button"
                  class="flex size-10 items-center justify-center text-gray-600 disabled:opacity-40"
                  :disabled="borradores[detalle.id].cantidad <= 1"
                  aria-label="Menos"
                  @click="borradores[detalle.id].cantidad--"
                >
                  <Minus :size="16" />
                </button>
                <span class="w-8 text-center text-sm font-semibold">{{ borradores[detalle.id].cantidad }}</span>
                <button
                  type="button"
                  class="flex size-10 items-center justify-center text-gray-600 disabled:opacity-40"
                  :disabled="borradores[detalle.id].cantidad >= 99"
                  aria-label="Más"
                  @click="borradores[detalle.id].cantidad++"
                >
                  <Plus :size="16" />
                </button>
              </div>
              <BaseButton
                v-if="cambioPendiente(detalle)"
                size="sm"
                :disabled="guardando !== null"
                @click="guardarLinea(detalle)"
              >
                {{ guardando === `linea-${detalle.id}` ? 'Guardando…' : 'Guardar cambio' }}
              </BaseButton>
              <button
                type="button"
                class="ml-auto inline-flex min-h-10 items-center gap-1.5 rounded-xl px-3 text-xs font-semibold text-red-700 hover:bg-red-50 disabled:opacity-40"
                :disabled="guardando !== null"
                @click="lineaARetirar = detalle"
              >
                <Trash2 :size="15" /> Quitar
              </button>
            </div>
            <input v-formato="'texto'"
              v-model="borradores[detalle.id].observaciones"
              type="text"
              maxlength="500"
              class="field text-sm"
              placeholder="Observaciones (ej: sin cebolla)"
            />
          </div>
        </li>
      </ul>

      <!-- Agregar producto -->
      <div class="mt-4 border-t border-gray-200 pt-4">
        <BaseButton v-if="!selectorAbierto" variant="secondary" class="w-full" @click="abrirSelector">
          <Plus :size="16" /> Agregar producto
        </BaseButton>

        <div v-else class="space-y-3">
          <template v-if="!productoElegido && !comboElegido">
            <label class="relative block">
              <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
              <input v-formato="'busqueda'" v-model="busqueda" type="search" class="field pl-9 text-sm" placeholder="Buscar producto o combo" />
            </label>
            <p v-if="cargandoCatalogo" class="text-xs text-gray-500">Cargando catálogo…</p>
            <ul v-else class="max-h-56 divide-y divide-gray-100 overflow-y-auto rounded-xl border border-gray-200">
              <li v-for="opcion in opciones" :key="opcion.clave">
                <button
                  type="button"
                  class="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left text-sm hover:bg-brand-50"
                  @click="elegir(opcion)"
                >
                  <span class="text-ink">{{ opcion.nombre }}</span>
                  <span class="shrink-0 text-gray-500">{{ formatoPrecio(opcion.precio) }}</span>
                </button>
              </li>
              <li v-if="!opciones.length" class="px-3 py-3 text-xs text-gray-500">Nada coincide con la búsqueda.</li>
            </ul>
            <BaseButton variant="ghost" class="w-full" @click="cerrarSelector">Cancelar</BaseButton>
          </template>

          <template v-else>
            <p class="text-sm font-semibold text-ink">
              {{ comboElegido ? `Combo ${comboElegido.nombre}` : productoElegido?.nombre }}
            </p>

            <div v-if="productoElegido?.variantes?.some((v) => v.activa && v.disponible !== false)" class="flex flex-wrap gap-2">
              <button
                v-for="variante in (productoElegido.variantes ?? []).filter((v) => v.activa && v.disponible !== false)"
                :key="variante.id"
                type="button"
                class="rounded-xl border px-3 py-2 text-xs font-semibold"
                :class="varianteElegida?.id === variante.id ? 'border-brand-500 bg-brand-50 text-ink' : 'border-gray-200 text-gray-600'"
                @click="varianteElegida = variante"
              >
                {{ variante.nombre }} · {{ formatoPrecio(variante.precio) }}
              </button>
            </div>

            <div v-if="extrasDe(productoElegido).length" class="space-y-1.5">
              <label
                v-for="extra in extrasDe(productoElegido)"
                :key="extra.id"
                class="flex items-center justify-between gap-3 text-sm"
              >
                <span class="flex items-center gap-2">
                  <input type="checkbox" :checked="extrasElegidos.some((e) => e.id === extra.id)" @change="alternarExtra(extra)" />
                  {{ extra.nombre }}
                </span>
                <span class="text-gray-600">+{{ formatoPrecio(extra.precio) }}</span>
              </label>
            </div>

            <div class="flex items-center gap-3">
              <div class="inline-flex items-center rounded-xl border border-gray-200 bg-white">
                <button type="button" class="flex size-10 items-center justify-center disabled:opacity-40" :disabled="cantidadNueva <= 1" aria-label="Menos" @click="cantidadNueva--">
                  <Minus :size="16" />
                </button>
                <span class="w-8 text-center text-sm font-semibold">{{ cantidadNueva }}</span>
                <button type="button" class="flex size-10 items-center justify-center disabled:opacity-40" :disabled="cantidadNueva >= 99" aria-label="Más" @click="cantidadNueva++">
                  <Plus :size="16" />
                </button>
              </div>
              <span class="ml-auto text-sm font-semibold text-ink">{{ formatoPrecio(precioNuevaLinea) }}</span>
            </div>

            <input v-formato="'texto'" v-if="productoElegido" v-model="observacionesNuevas" type="text" maxlength="500" class="field text-sm" placeholder="Observaciones (opcional)" />

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <BaseButton variant="ghost" @click="productoElegido = null; comboElegido = null">Elegir otro</BaseButton>
              <BaseButton :disabled="guardando !== null" @click="agregar">
                {{ guardando === 'agregar' ? 'Agregando…' : 'Agregar al pedido' }}
              </BaseButton>
            </div>
          </template>
        </div>
      </div>

      <div class="mt-5 flex items-center justify-between border-t border-gray-200 pt-3">
        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-gray-500">Total del pedido</span>
        <span class="font-sans text-xl font-semibold text-ink">{{ formatoPrecio(pedido.total) }}</span>
      </div>
      <BaseButton variant="ghost" class="mt-3 w-full" @click="emit('cerrar')">Listo</BaseButton>
    </template>
  </BaseModal>

  <!-- Confirmación para quitar una línea -->
  <BaseModal :model-value="!!lineaARetirar" title="Quitar producto" @update:model-value="lineaARetirar = null">
    <p class="text-sm text-gray-600">
      ¿Quitar <strong class="text-ink">{{ lineaARetirar?.cantidad }} × {{ lineaARetirar ? nombreLinea(lineaARetirar) : '' }}</strong>
      del pedido? Quedará registrado en el historial.
    </p>
    <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
      <BaseButton variant="ghost" @click="lineaARetirar = null">Volver</BaseButton>
      <BaseButton variant="danger" @click="retirarLinea">Quitar producto</BaseButton>
    </div>
  </BaseModal>
</template>
