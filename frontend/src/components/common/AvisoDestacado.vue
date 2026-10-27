<script setup lang="ts">
// Mejora (26/10/2026, RNF-TRANS-036): las notas que el Cliente tiene que leer («Pides como invitado…», «Guárdalo…»,
// «Revisamos la transferencia…», el horario de las reservas) eran letra pequeña gris y pasaban
// desapercibidas. Este aviso las resalta con color, borde e ícono, sin gritar.
import { computed } from 'vue'
import { AlertTriangle, CheckCircle2, Info } from 'lucide-vue-next'

const props = withDefaults(defineProps<{ tono?: 'info' | 'atencion' | 'exito' }>(), { tono: 'info' })

const estilo = computed(
  () =>
    ({
      info: { caja: 'border-brand-400 bg-brand-50 text-ink', icono: Info, colorIcono: 'text-brand-700' },
      atencion: { caja: 'border-amber-400 bg-amber-50 text-amber-950', icono: AlertTriangle, colorIcono: 'text-amber-600' },
      exito: { caja: 'border-emerald-400 bg-emerald-50 text-emerald-950', icono: CheckCircle2, colorIcono: 'text-emerald-600' },
    })[props.tono],
)
</script>

<template>
  <div class="flex items-start gap-2.5 rounded-xl border-l-4 px-3.5 py-2.5 text-[13px] font-medium leading-snug" :class="estilo.caja" role="note">
    <component :is="estilo.icono" :size="17" class="mt-px shrink-0" :class="estilo.colorIcono" />
    <div class="min-w-0"><slot /></div>
  </div>
</template>
