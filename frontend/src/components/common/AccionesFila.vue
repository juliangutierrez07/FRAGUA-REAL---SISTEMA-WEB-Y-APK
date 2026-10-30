<script lang="ts">
import type { Component } from 'vue'

export interface AccionFila {
  texto: string
  icono?: Component
  alTocar: () => void
  peligro?: boolean
  destacada?: boolean // botón dorado (desde tableta)
  ancha?: boolean // ocupa las dos columnas de la rejilla (desde tableta)
  deshabilitada?: boolean
  soloConsulta?: boolean // no cambia nada: también la ve la cuenta de Soporte (M10)
}
</script>

<script setup lang="ts">
// Mejora del panel en el celular (30/10/2026): las acciones de una tarjeta (mesa, producto, categoría…). Antes cada
// botón ocupaba su renglón y una tarjeta llenaba casi toda la pantalla. En el teléfono, hasta dos acciones salen
// como botones de solo ícono; con más, un botón «⋯» abre una hoja inferior con todas, con su nombre. Desde
// tableta se ven los botones de siempre.
import { computed, ref } from 'vue'
import { MoreHorizontal } from 'lucide-vue-next'
import BaseButton from './BaseButton.vue'
import BaseModal from './BaseModal.vue'
import { useAuthStore } from '@/stores/auth'

const props = withDefaults(defineProps<{ titulo: string; acciones: AccionFila[]; rejilla?: boolean }>(), { rejilla: false })

const auth = useAuthStore()
const abierta = ref(false)
const visibles = computed(() => props.acciones.filter((a) => !auth.esSoporte || a.soloConsulta))

function tocar(accion: AccionFila) {
  abierta.value = false
  accion.alTocar()
}
</script>

<template>
  <div v-if="visibles.length">
    <!-- Teléfono -->
    <div class="flex gap-1.5 sm:hidden">
      <template v-if="visibles.length <= 2">
        <button
          v-for="accion in visibles"
          :key="accion.texto"
          type="button"
          class="flex size-10 items-center justify-center rounded-xl border bg-white transition active:scale-95 disabled:opacity-50"
          :class="accion.peligro ? 'border-red-200 text-red-700' : 'border-gray-200 text-gray-700'"
          :disabled="accion.deshabilitada"
          :aria-label="accion.texto"
          @click="tocar(accion)"
        >
          <component :is="accion.icono" :size="16" />
        </button>
      </template>
      <button
        v-else
        type="button"
        class="flex size-10 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-700 transition active:scale-95"
        :aria-label="`Acciones de ${titulo}`"
        @click="abierta = true"
      >
        <MoreHorizontal :size="18" />
      </button>
    </div>

    <!-- Desde tableta -->
    <div class="hidden" :class="rejilla ? 'sm:grid sm:grid-cols-2 sm:gap-2' : 'sm:flex sm:gap-2'">
      <BaseButton
        v-for="accion in visibles"
        :key="accion.texto"
        :variant="accion.destacada ? 'primary' : 'ghost'"
        size="sm"
        :class="[
          rejilla && accion.ancha ? 'col-span-2' : '',
          accion.peligro ? '!text-red-700 hover:!border-red-300 hover:!bg-red-50' : '',
        ]"
        :disabled="accion.deshabilitada"
        @click="tocar(accion)"
      >
        <component :is="accion.icono" v-if="accion.icono" :size="14" />
        {{ accion.texto }}
      </BaseButton>
    </div>

    <BaseModal v-model="abierta" :title="titulo">
      <ul class="-mx-1 space-y-1 pb-1">
        <li v-for="accion in visibles" :key="accion.texto">
          <button
            type="button"
            class="flex min-h-12 w-full items-center gap-3 rounded-xl px-3 text-left text-sm font-semibold transition active:bg-gray-100 disabled:opacity-50"
            :class="accion.peligro ? 'text-red-700' : 'text-ink'"
            :disabled="accion.deshabilitada"
            @click="tocar(accion)"
          >
            <span
              class="flex size-9 shrink-0 items-center justify-center rounded-xl"
              :class="accion.peligro ? 'bg-red-50' : 'bg-gray-100'"
              aria-hidden="true"
            >
              <component :is="accion.icono" v-if="accion.icono" :size="16" />
            </span>
            {{ accion.texto }}
          </button>
        </li>
      </ul>
    </BaseModal>
  </div>
</template>
