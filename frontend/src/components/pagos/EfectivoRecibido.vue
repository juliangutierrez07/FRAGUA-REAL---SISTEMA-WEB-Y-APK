<script setup lang="ts">
// Mejora (26/10/2026, RF-M06-036, RF-M05-045): al cobrar en efectivo (un pedido, la cuenta de una mesa o el depósito de una
// reserva), el personal indica con cuánto paga el cliente y ve de una vez el cambio que debe entregar.
// Los botones sugieren el valor exacto y los billetes que alcanzan; también se puede escribir el valor.
import { computed } from 'vue'
import { Banknote } from 'lucide-vue-next'
import CampoPesos from '@/components/common/CampoPesos.vue'

const props = defineProps<{ total: number; obligatorio?: boolean }>()
const recibido = defineModel<number | null>({ default: null })

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

// Los billetes que alcanzan para el total, redondeando hacia arriba (p. ej. 37.000 → 40.000, 50.000).
const sugeridos = computed(() => {
  const total = props.total
  if (!(total > 0)) return []
  const arriba = (paso: number) => Math.ceil(total / paso) * paso
  return [...new Set([5000, 10000, 20000, 50000, 100000].map(arriba))]
    .filter((v) => v > total)
    .sort((a, b) => a - b)
    .slice(0, 3)
})

const valor = computed(() => (typeof recibido.value === 'number' && recibido.value > 0 ? recibido.value : null))
const cambio = computed(() => (valor.value !== null ? valor.value - props.total : null))

const campo = computed({
  get: () => recibido.value ?? '',
  set: (v: number | string) => (recibido.value = v === '' ? null : Number(v)),
})
</script>

<template>
  <div class="mt-4 rounded-2xl border border-gray-200 bg-gray-50/70 p-3.5">
    <p class="field-label flex flex-wrap items-center gap-x-1.5">
      <Banknote :size="15" class="text-gray-400" />
      ¿Con cuánto paga el cliente?
      <span v-if="obligatorio" class="font-normal normal-case tracking-normal text-red-600">(obligatorio)</span>
    </p>
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
      <button
        type="button"
        class="min-h-11 rounded-xl border px-2 py-2 text-sm font-semibold transition active:scale-[0.97]"
        :class="valor === total ? 'border-brand-500 bg-brand-50 text-brand-800' : 'border-gray-200 bg-white text-gray-700 hover:border-brand-300'"
        @click="recibido = total"
      >
        Exacto
      </button>
      <button
        v-for="billete in sugeridos"
        :key="billete"
        type="button"
        class="min-h-11 rounded-xl border px-2 py-2 text-sm font-semibold transition active:scale-[0.97]"
        :class="valor === billete ? 'border-brand-500 bg-brand-50 text-brand-800' : 'border-gray-200 bg-white text-gray-700 hover:border-brand-300'"
        @click="recibido = billete"
      >
        {{ formatoPrecio(billete) }}
      </button>
    </div>
    <label class="mt-2 block">
      <span class="sr-only">Valor que entrega el cliente</span>
      <span class="relative block">
        <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-gray-400">$</span>
        <CampoPesos v-model="campo" class="field bg-white pl-9" placeholder="Otro valor" />
      </span>
    </label>

    <dl v-if="valor !== null" class="mt-3 grid grid-cols-3 gap-2 text-center" aria-live="polite">
      <div class="rounded-xl bg-white px-2 py-2 ring-1 ring-gray-200">
        <dt class="text-[10px] font-semibold uppercase tracking-[0.1em] text-gray-400">A cobrar</dt>
        <dd class="mt-0.5 text-sm font-semibold text-ink">{{ formatoPrecio(total) }}</dd>
      </div>
      <div class="rounded-xl bg-white px-2 py-2 ring-1 ring-gray-200">
        <dt class="text-[10px] font-semibold uppercase tracking-[0.1em] text-gray-400">Recibe</dt>
        <dd class="mt-0.5 text-sm font-semibold text-ink">{{ formatoPrecio(valor) }}</dd>
      </div>
      <div class="rounded-xl px-2 py-2 ring-1" :class="cambio! >= 0 ? 'bg-emerald-50 ring-emerald-200' : 'bg-red-50 ring-red-200'">
        <dt class="text-[10px] font-semibold uppercase tracking-[0.1em]" :class="cambio! >= 0 ? 'text-emerald-700' : 'text-red-700'">
          {{ cambio! >= 0 ? 'Cambio' : 'Falta' }}
        </dt>
        <dd class="mt-0.5 font-display text-lg font-medium leading-tight" :class="cambio! >= 0 ? 'text-emerald-800' : 'text-red-700'">
          {{ formatoPrecio(Math.abs(cambio!)) }}
        </dd>
      </div>
    </dl>
    <p v-else class="mt-2 text-xs text-gray-500">Toca un billete o escribe el valor para ver el cambio.</p>
  </div>
</template>
