<script setup lang="ts">
// Encabezado común de las pantallas de Mesero/Administrador: línea dorada + sobretítulo (igual
// que las secciones del sitio del Cliente), título en la fuente de títulos (Fraunces) y descripción. Las acciones
// (slot "actions") se apilan debajo en teléfono y ocupan todo el ancho — fáciles de tocar —;
// desde sm se alinean a la derecha del título.
defineProps<{ titulo: string; descripcion?: string; eyebrow?: string }>()
</script>

<template>
  <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between sm:gap-6">
    <div class="min-w-0">
      <p v-if="eyebrow" class="page-eyebrow">{{ eyebrow }}</p>
      <h1 class="page-title" :class="{ 'mt-2.5': eyebrow }">{{ titulo }}</h1>
      <p v-if="descripcion" class="page-subtitle">{{ descripcion }}</p>
    </div>
    <div
      v-if="$slots.actions"
      class="flex shrink-0 flex-wrap items-center gap-2 [&>*]:flex-1 sm:[&>*]:flex-none"
    >
      <slot name="actions" />
    </div>
  </header>
</template>
