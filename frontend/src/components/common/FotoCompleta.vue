<script setup lang="ts">
// Mejora (30/10/2026): la foto de un producto se ve completa. Antes llenaba a la fuerza un marco horizontal y a las
// fotos verticales (las de celular) se les cortaba casi la mitad: el servidor las guarda enteras, el recorte era
// solo de la pantalla. Ahora la foto entra entera y lo que sobra del marco se rellena con ella misma, difuminada,
// para que no queden franjas vacías.
defineProps<{ src: string; alt: string; carga?: 'lazy' | 'eager' }>()
</script>

<template>
  <span class="relative block size-full overflow-hidden">
    <img :src="src" alt="" aria-hidden="true" class="absolute inset-0 size-full scale-110 object-cover opacity-60 blur-lg" :loading="carga ?? 'lazy'" />
    <img :src="src" :alt="alt" class="relative size-full object-contain" :loading="carga ?? 'lazy'" />
  </span>
</template>
