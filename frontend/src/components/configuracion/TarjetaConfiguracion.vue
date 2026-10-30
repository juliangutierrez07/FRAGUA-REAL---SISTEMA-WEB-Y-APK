<script setup lang="ts">
// Mejora del panel en el celular (30/10/2026): la tarjeta de cada sección de Configuración. Antes todo el contenido
// iba al lado del ícono, y en el teléfono los campos y el bloque del logo quedaban apretados en una columna
// angosta. Ahora el ícono y el título son el encabezado y el contenido usa todo el ancho.
import type { Component } from 'vue'

defineProps<{ icono: Component; tono: string; titulo: string }>()
</script>

<template>
  <section class="card p-4 sm:p-5">
    <div class="flex items-start gap-3">
      <span class="flex size-10 shrink-0 items-center justify-center rounded-xl sm:size-11" :class="tono">
        <component :is="icono" :size="20" />
      </span>
      <div class="min-w-0 flex-1">
        <h2 class="text-[15px] font-semibold text-ink">{{ titulo }}</h2>
        <p v-if="$slots.descripcion" class="mt-0.5 text-xs text-gray-500"><slot name="descripcion" /></p>
      </div>
      <slot name="accion" />
    </div>
    <div v-if="$slots.default" class="mt-4 sm:pl-14"><slot /></div>
  </section>
</template>
