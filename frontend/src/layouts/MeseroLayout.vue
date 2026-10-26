<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppSidebar from '@/components/common/AppSidebar.vue'
import ComandasPendientes from '@/components/pedidos/ComandasPendientes.vue'
import { useEquipoImpresionStore } from '@/stores/equipoImpresion'

const route = useRoute()
// M12 (24/10/2026): si este equipo imprime, manda su señal a Impresoras cada minuto.
useEquipoImpresionStore().iniciar()

// Pantallas "a pantalla completa" (meta.pantallaCompleta, p. ej. Nuevo pedido) traen su propio
// encabezado y su propia barra inferior (el carrito) — sin márgenes del layout ni barra de
// pestañas debajo, para aprovechar cada píxel del teléfono.
const pantallaCompleta = computed(() => !!route.meta.pantallaCompleta)
</script>

<template>
  <!-- h-dvh (no h-screen): en móvil "vh" incluye la barra de direcciones del navegador y el
       final de la página quedaba tapado. md+ : barra lateral/riel a la izquierda. -->
  <div class="flex h-dvh flex-col overflow-hidden bg-cream md:flex-row">
    <AppSidebar variant="mesero" />
    <main class="min-h-0 min-w-0 flex-1 overflow-y-auto [scrollbar-gutter:stable]">
      <div v-if="pantallaCompleta" class="min-h-full">
        <router-view />
      </div>
      <!-- pb-nav-m: deja libre el alto de la barra de pestañas inferior (solo teléfono) para que
           el último elemento de cada lista nunca quede escondido debajo de ella. -->
      <div v-else class="mx-auto w-full max-w-6xl px-4 pb-nav-m pt-5 sm:px-6 sm:pt-7 md:pb-8 lg:px-10 lg:pt-9">
        <!-- Fase 6, tarea 2 (RNF-M02-011): las comandas por imprimir se ven en todas las pantallas. -->
        <ComandasPendientes />
        <router-view />
      </div>
    </main>
  </div>
</template>
