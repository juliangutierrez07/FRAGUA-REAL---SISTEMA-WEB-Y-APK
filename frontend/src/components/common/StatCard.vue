<script setup lang="ts">
import type { Component } from 'vue'

// Tarjeta de cifra clave (KPI) del Dashboard. "destacada" la pinta sobre el negro de marca con
// la cifra en dorado — la métrica principal del día salta a la vista sin competir con las demás.
withDefaults(
  defineProps<{
    etiqueta: string
    valor: string | number
    detalle?: string
    icono: Component
    destacada?: boolean
    // Mejora del panel (30/10/2026): en el teléfono, tres por fila con la cifra y el nombre corto.
    compacta?: boolean
    etiquetaCorta?: string
  }>(),
  { destacada: false, compacta: false, etiquetaCorta: undefined },
)
</script>

<template>
  <div
    class="relative overflow-hidden rounded-2xl sm:p-6"
    :class="[destacada ? 'bg-ink-900 text-white shadow-pop' : 'card', compacta ? 'p-3' : 'p-4']"
  >
    <div v-if="compacta" class="sm:hidden">
      <div class="flex items-center justify-between gap-2">
        <p class="font-sans text-xl font-semibold leading-none" :class="destacada ? 'text-brand-400' : 'text-ink'">{{ valor }}</p>
        <component :is="icono" :size="15" :class="destacada ? 'text-brand-400' : 'text-brand-700'" aria-hidden="true" />
      </div>
      <p class="mt-1.5 text-[11px] font-medium leading-tight" :class="destacada ? 'text-white/70' : 'text-gray-500'">{{ etiquetaCorta ?? etiqueta }}</p>
    </div>
    <!-- Resplandor dorado decorativo (solo la tarjeta destacada) -->
    <span
      v-if="destacada"
      class="pointer-events-none absolute -right-10 -top-10 size-40 rounded-full bg-brand-500/20 blur-3xl"
      aria-hidden="true"
    />
    <div class="relative items-center justify-between gap-3" :class="compacta ? 'hidden sm:flex' : 'flex'">
      <p class="text-[11px] font-semibold uppercase tracking-[0.14em]" :class="destacada ? 'text-white/55' : 'text-gray-500'">
        {{ etiqueta }}
      </p>
      <span
        class="flex size-9 shrink-0 items-center justify-center rounded-xl sm:size-10"
        :class="destacada ? 'bg-white/10 text-brand-400' : 'bg-brand-50 text-brand-700'"
      >
        <component :is="icono" :size="20" />
      </span>
    </div>
    <p
      class="relative mt-3 font-sans text-[1.75rem] font-semibold leading-none tracking-tight sm:mt-4 sm:block sm:text-[2rem]"
      :class="[destacada ? 'text-brand-400' : 'text-ink', compacta ? 'hidden' : '']"
    >
      {{ valor }}
    </p>
    <p v-if="detalle" class="relative mt-2.5 text-xs leading-relaxed sm:block" :class="[destacada ? 'text-white/55' : 'text-gray-500', compacta ? 'hidden' : '']">
      {{ detalle }}
    </p>
  </div>
</template>
