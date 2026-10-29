<script setup lang="ts">
// Mejora (28/10/2026, RF-M07-027): el enlace de seguimiento del invitado. Antes se mostraba la dirección completa
// subrayada (larga y difícil de leer en el celular); ahora es un botón para abrir el seguimiento y otro para
// guardarlo: en el celular abre «Compartir» (WhatsApp, notas…) y en el computador lo copia.
import { computed, onBeforeUnmount, ref } from 'vue'
import { Bookmark, Check, Copy, Radar, Share2 } from 'lucide-vue-next'

const props = defineProps<{ token: string; pedidoId: number }>()

const enlace = computed(() => `${window.location.origin}/seguimiento/${props.token}`)
const puedeCompartir = typeof navigator !== 'undefined' && typeof navigator.share === 'function'
const copiado = ref(false)
let temporizador: ReturnType<typeof setTimeout> | undefined

async function guardar() {
  if (puedeCompartir) {
    try {
      await navigator.share({ title: `Mi pedido #${props.pedidoId} — Fragua Real Burger`, text: 'Sigue mi pedido aquí:', url: enlace.value })
      return
    } catch (e) {
      // Si la persona cierra el menú no se hace nada; si el navegador no deja compartir, se copia.
      if ((e as DOMException)?.name === 'AbortError') return
    }
  }
  try {
    await navigator.clipboard.writeText(enlace.value)
  } catch {
    window.prompt('Copia este enlace:', enlace.value)
    return
  }
  copiado.value = true
  clearTimeout(temporizador)
  temporizador = setTimeout(() => (copiado.value = false), 2500)
}
onBeforeUnmount(() => clearTimeout(temporizador))
</script>

<template>
  <section class="rounded-2xl border border-[#e8dfcd] bg-[#fbfaf7] p-4 text-left sm:p-5">
    <div class="flex items-start gap-3">
      <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-500/15 text-brand-700" aria-hidden="true">
        <Radar :size="20" />
      </span>
      <div class="min-w-0">
        <p class="text-sm font-semibold text-ink">Sigue tu pedido en vivo</p>
        <p class="mt-0.5 text-xs leading-relaxed text-[#6c655c]">
          Mira cuándo lo confirmamos, lo preparamos y sale hacia ti. Mientras esté pendiente, también puedes cancelarlo.
        </p>
      </div>
    </div>

    <div class="mt-4 grid gap-2 sm:grid-cols-2">
      <router-link
        :to="{ name: 'seguimiento-pedido', params: { token } }"
        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-ink px-4 py-2.5 text-sm font-medium text-white transition hover:bg-black"
      >
        <Radar :size="16" />
        Seguir mi pedido
      </router-link>
      <button
        type="button"
        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-medium transition"
        :class="copiado ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-[#ddd7cd] bg-white text-ink hover:border-brand-500'"
        @click="guardar"
      >
        <Check v-if="copiado" :size="16" />
        <Share2 v-else-if="puedeCompartir" :size="16" />
        <Copy v-else :size="16" />
        {{ copiado ? '¡Enlace copiado!' : puedeCompartir ? 'Guardar o compartir' : 'Copiar enlace' }}
      </button>
    </div>

    <p class="mt-3 flex items-start gap-2 text-xs text-[#7a5a12]">
      <Bookmark :size="14" class="mt-px shrink-0" aria-hidden="true" />
      Guarda el enlace: sin cuenta es la única forma de volver a ver tu pedido.
    </p>
  </section>
</template>
