<script setup lang="ts">
// Fase 2, tarea 1 — Totales de un pedido con sus rebajas (RF-M04-035 a 038): subtotal, lo que
// rebajó cada promoción (o el descuento manual, con su motivo), domicilio y total. Se usa en las
// vistas del personal y en las del cliente, para que todos vean la misma cuenta.
import { computed } from 'vue'
import type { Pedido } from '@/types/models'

const props = withDefaults(
  defineProps<{ pedido: Pick<Pedido, 'subtotal' | 'total' | 'valor_domicilio' | 'descuento' | 'promociones_aplicadas' | 'descuento_manual' | 'descuento_manual_motivo' | 'descuento_manual_por'>; tono?: 'personal' | 'cliente'; mostrarTotal?: boolean }>(),
  { tono: 'personal', mostrarTotal: true },
)

const tenue = computed(() => (props.tono === 'cliente' ? 'text-[#6c655c]' : 'text-gray-500'))
const hayDescuento = computed(() => Number(props.pedido.descuento ?? 0) > 0)
const aplicadas = computed(() => props.pedido.promociones_aplicadas ?? [])

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}
</script>

<template>
  <dl class="space-y-1 text-sm">
    <div v-if="hayDescuento || pedido.valor_domicilio" class="flex justify-between" :class="tenue">
      <dt>Subtotal</dt>
      <dd>{{ formatoPrecio(pedido.subtotal) }}</dd>
    </div>
    <div v-for="(p, i) in aplicadas" :key="i" class="flex justify-between gap-3 text-emerald-700">
      <dt class="min-w-0">{{ p.nombre }}</dt>
      <dd class="shrink-0">−{{ formatoPrecio(p.monto) }}</dd>
    </div>
    <p v-if="pedido.descuento_manual && pedido.descuento_manual_motivo && tono === 'personal'" class="text-xs" :class="tenue">
      Motivo: {{ pedido.descuento_manual_motivo }}<template v-if="pedido.descuento_manual_por"> · {{ pedido.descuento_manual_por.nombre }}</template>
    </p>
    <div v-if="pedido.valor_domicilio" class="flex justify-between" :class="tenue">
      <dt>Domicilio</dt>
      <dd>{{ formatoPrecio(pedido.valor_domicilio) }}</dd>
    </div>
    <div v-if="mostrarTotal" class="flex items-center justify-between pt-1">
      <dt class="text-xs font-semibold uppercase tracking-[0.1em]" :class="tenue">Total</dt>
      <dd class="font-sans text-xl font-semibold text-ink">{{ formatoPrecio(pedido.total) }}</dd>
    </div>
  </dl>
</template>
