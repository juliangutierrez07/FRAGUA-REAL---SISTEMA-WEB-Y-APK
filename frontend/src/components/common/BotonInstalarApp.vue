<script setup lang="ts">
// Mejora (16/10/2026): «Instalar app» (PWA). Con el aviso del navegador (Android, computador) abre la
// instalación; en iPhone/iPad muestra los pasos de Safari. No aparece si la app ya está instalada.
// 26/10/2026: sin el aviso del navegador (Samsung Internet, Firefox…) muestra los pasos de su menú.
// `compacto`: solo el ícono, para la barra de arriba del Mesero en el teléfono.
import { ref } from 'vue'
import { Download, EllipsisVertical, PlusSquare, Share, X } from 'lucide-vue-next'
import { useInstalarApp } from '@/composables/useInstalarApp'

const props = defineProps<{ tono?: 'claro' | 'oscuro'; compacto?: boolean }>()
const { puedeInstalar, mostrarPasos, esIOS, instalar } = useInstalarApp()
const pasosAbiertos = ref(false)

const clases = () =>
  props.tono === 'oscuro'
    ? 'text-brand-400 hover:bg-white/5'
    : 'text-brand-800 hover:bg-brand-50'
</script>

<template>
  <button
    v-if="(puedeInstalar || mostrarPasos) && props.compacto"
    type="button"
    class="flex size-10 shrink-0 items-center justify-center rounded-xl text-brand-400 transition hover:bg-white/10 active:bg-white/15"
    aria-label="Instalar app"
    @click="puedeInstalar ? instalar() : (pasosAbiertos = true)"
  >
    <Download :size="19" />
  </button>
  <button
    v-else-if="puedeInstalar || mostrarPasos"
    type="button"
    class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition"
    :class="clases()"
    @click="puedeInstalar ? instalar() : (pasosAbiertos = true)"
  >
    <Download :size="18" />
    Instalar app
  </button>

  <!-- Pasos: Safari en iPhone y iPad; el menú del navegador en los demás -->
  <Teleport to="body">
    <div v-if="pasosAbiertos" class="fixed inset-0 z-[60] flex items-end justify-center bg-black/50 p-4 sm:items-center" @click.self="pasosAbiertos = false">
      <div class="w-full max-w-sm rounded-2xl bg-white p-5 text-ink shadow-pop" role="dialog" aria-label="Instalar la app">
        <div class="flex items-start justify-between gap-3">
          <p class="font-display text-xl">Instala Fragua Real</p>
          <button type="button" class="rounded-lg p-1 text-gray-400 hover:bg-gray-100" aria-label="Cerrar" @click="pasosAbiertos = false">
            <X :size="18" />
          </button>
        </div>
        <ol v-if="!esIOS" class="mt-4 space-y-3 text-sm text-gray-700">
          <li class="flex items-start gap-3">
            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-brand-400">1</span>
            <span>Abre el menú del navegador: <EllipsisVertical :size="15" class="mx-0.5 inline align-[-2px]" /> (arriba a la derecha) o ☰ (abajo).</span>
          </li>
          <li class="flex items-start gap-3">
            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-brand-400">2</span>
            <span>Elige <strong>Instalar app</strong> o <strong>Agregar a pantalla principal</strong> (según el navegador).</span>
          </li>
          <li class="flex items-start gap-3">
            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-brand-400">3</span>
            <span>Confirma. La app queda en tu pantalla de inicio con el ícono de Fragua Real.</span>
          </li>
        </ol>
        <ol v-else class="mt-4 space-y-3 text-sm text-gray-700">
          <li class="flex items-start gap-3">
            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-brand-400">1</span>
            <span>En Safari, toca <Share :size="15" class="mx-0.5 inline align-[-2px]" /> <strong>Compartir</strong> (abajo o arriba de la pantalla).</span>
          </li>
          <li class="flex items-start gap-3">
            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-brand-400">2</span>
            <span>Elige <PlusSquare :size="15" class="mx-0.5 inline align-[-2px]" /> <strong>Agregar a inicio</strong>.</span>
          </li>
          <li class="flex items-start gap-3">
            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-brand-400">3</span>
            <span>Toca <strong>Agregar</strong>. La app queda en tu pantalla de inicio con el ícono de Fragua Real.</span>
          </li>
        </ol>
        <button type="button" class="mt-5 w-full rounded-xl bg-brand-500 py-3 text-sm font-medium text-ink" @click="pasosAbiertos = false">Entendido</button>
      </div>
    </div>
  </Teleport>
</template>
