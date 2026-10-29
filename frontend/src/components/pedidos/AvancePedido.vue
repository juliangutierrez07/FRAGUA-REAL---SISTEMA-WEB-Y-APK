<script setup lang="ts">
// Mejora (28/10/2026, RF-M07-037): el avance del pedido (Recibido → Confirmado → En preparación → Entregado) con
// el mensaje de su estado. Antes vivía solo en la página de seguimiento del enlace; ahora también lo ve el
// Cliente con cuenta en «Mis pedidos», así tiene dos formas de seguir su pedido.
import { computed } from 'vue'
import { CheckCircle2 } from 'lucide-vue-next'
import type { Pedido } from '@/types/models'

const props = withDefaults(defineProps<{ pedido: Pedido; compacto?: boolean }>(), { compacto: false })

// Pasos que ve el cliente; el último depende del canal.
const pasos = computed(() => {
  const p = props.pedido
  return [
    { clave: 'pendiente', texto: 'Recibido' },
    { clave: 'confirmado', texto: 'Confirmado' },
    // Fase 4, tarea 5 (RF-M07-R-012): la recogida lista sigue en preparación, pero el cliente lo ve así.
    { clave: 'preparando', texto: p.tipo === 'recogida' && p.listo_at ? 'Listo para recoger' : 'En preparación' },
    { clave: 'finalizado', texto: p.tipo === 'domicilio' ? 'Entregado' : p.tipo === 'recogida' ? 'Recogido' : 'Finalizado' },
  ]
})
const indiceActual = computed(() => pasos.value.findIndex((s) => s.clave === props.pedido.estado))

const mensaje = computed(() => {
  const p = props.pedido
  switch (p.estado) {
    case 'pendiente':
      return 'Recibimos tu pedido. En cuanto lo revisemos lo confirmamos y empezamos a prepararlo.'
    case 'confirmado':
      return 'Tu pedido está confirmado y entra a cocina.'
    case 'preparando':
      if (p.tipo === 'domicilio') return p.entregado_at ? 'Tu pedido fue entregado.' : 'Estamos preparando tu pedido; te lo llevamos pronto.'
      if (p.tipo === 'local') return 'Estamos preparando tu pedido.'
      if (p.recogido_at) return 'Ya recogiste tu pedido.'
      return p.listo_at
        ? '¡Tu pedido está listo! Pasa a recogerlo en el local.'
        : 'Estamos preparando tu pedido. Te avisamos cuando esté listo para recoger.'
    case 'finalizado':
      return '¡Gracias! Tu pedido está completo.'
    default:
      return `Este pedido fue cancelado${p.cancelado_motivo ? `: ${p.cancelado_motivo}` : ''}.`
  }
})
</script>

<template>
  <div>
    <ol v-if="pedido.estado !== 'cancelado'" class="relative grid grid-cols-4 gap-2" aria-label="Avance del pedido">
      <!-- La línea que une los pasos, rellena hasta el paso actual. -->
      <span class="absolute left-[12.5%] right-[12.5%] h-0.5 bg-[#ece5d7]" :class="compacto ? 'top-3' : 'top-4'" aria-hidden="true" />
      <span
        class="absolute left-[12.5%] h-0.5 bg-brand-500 transition-all duration-500"
        :class="compacto ? 'top-3' : 'top-4'"
        :style="{ width: `${(Math.max(indiceActual, 0) / 3) * 75}%` }"
        aria-hidden="true"
      />
      <li v-for="(paso, i) in pasos" :key="paso.clave" class="relative text-center" :aria-current="i === indiceActual ? 'step' : undefined">
        <span
          class="mx-auto flex items-center justify-center rounded-full border-2 font-semibold"
          :class="[
            compacto ? 'size-6 text-[10px]' : 'size-8 text-xs',
            i <= indiceActual ? 'border-brand-500 bg-brand-500 text-ink' : 'border-[#e3ded4] bg-white text-[#a59d90]',
            i === indiceActual && pedido.estado !== 'finalizado' ? 'ring-4 ring-brand-500/20' : '',
          ]"
        >
          <CheckCircle2 v-if="i < indiceActual || pedido.estado === 'finalizado'" :size="compacto ? 13 : 16" />
          <template v-else>{{ i + 1 }}</template>
        </span>
        <span class="mt-1.5 block leading-tight" :class="[compacto ? 'text-[10px]' : 'text-[11px]', i <= indiceActual ? 'text-ink' : 'text-[#a59d90]']">
          {{ paso.texto }}
        </span>
      </li>
    </ol>
    <p class="text-sm text-ink" :class="{ 'mt-4': pedido.estado !== 'cancelado' }" role="status">{{ mensaje }}</p>
  </div>
</template>
