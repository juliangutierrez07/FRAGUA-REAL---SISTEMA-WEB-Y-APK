<script setup lang="ts">
import { Eye } from 'lucide-vue-next'
import AppSidebar from '@/components/common/AppSidebar.vue'
import { useAuthStore } from '@/stores/auth'
import { useEquipoImpresionStore } from '@/stores/equipoImpresion'

// M10 (20/10/2026): la cuenta de Soporte ve todo, pero solo para consultar; la franja lo recuerda
// en cada pantalla.
const auth = useAuthStore()
// M12 (24/10/2026): si este equipo imprime, manda su señal a Impresoras cada minuto.
useEquipoImpresionStore().iniciar()
</script>

<template>
  <!-- h-dvh (no h-screen): en móvil "vh" incluye la barra de direcciones del navegador y el
       final de la página quedaba tapado. md+ : barra lateral/riel a la izquierda. -->
  <div class="flex h-dvh flex-col overflow-hidden bg-cream md:flex-row">
    <AppSidebar variant="admin" />
    <main class="min-h-0 min-w-0 flex-1 overflow-y-auto [scrollbar-gutter:stable]">
      <div
        v-if="auth.esSoporte"
        class="sticky top-0 z-20 flex items-center justify-center gap-2 border-b border-sky-200 bg-sky-50/95 px-4 py-2 text-center text-xs font-medium text-sky-800 backdrop-blur"
        role="status"
      >
        <Eye :size="15" class="shrink-0" />
        <span>Modo soporte: solo consulta. Puedes ver todo; los cambios del negocio están bloqueados.</span>
      </div>
      <div class="mx-auto w-full max-w-7xl px-4 pb-10 pt-5 sm:px-6 sm:pt-7 lg:px-10 lg:pb-12 lg:pt-9">
        <router-view />
      </div>
    </main>
  </div>
</template>
