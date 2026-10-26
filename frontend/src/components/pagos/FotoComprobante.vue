<script setup lang="ts">
// Mejora (26/10/2026): al cobrar por transferencia o tarjeta, el Mesero toma la foto del comprobante con
// la cámara del teléfono (capture="environment" abre la cámara trasera; en un computador abre el
// selector de archivos). En transferencia es obligatoria; en tarjeta, opcional (el datáfono deja recibo).
import { onBeforeUnmount, ref, watch } from 'vue'
import { Camera, RefreshCw, X } from 'lucide-vue-next'
import { comprimirFoto } from '@/utils/imagen'

const props = defineProps<{ obligatoria?: boolean }>()
const foto = defineModel<File | null>({ default: null })

const entrada = ref<HTMLInputElement | null>(null)
const vista = ref('')
const preparando = ref(false)

watch(foto, (nueva) => {
  if (vista.value) URL.revokeObjectURL(vista.value)
  vista.value = nueva ? URL.createObjectURL(nueva) : ''
})
onBeforeUnmount(() => vista.value && URL.revokeObjectURL(vista.value))

async function elegida(evento: Event) {
  const archivo = (evento.target as HTMLInputElement).files?.[0]
  ;(evento.target as HTMLInputElement).value = ''
  if (!archivo) return
  preparando.value = true
  try {
    foto.value = await comprimirFoto(archivo)
  } finally {
    preparando.value = false
  }
}
</script>

<template>
  <div class="mt-4">
    <p class="field-label">
      Foto del comprobante
      <span class="font-normal" :class="props.obligatoria ? 'text-red-600' : 'text-gray-400'">
        {{ props.obligatoria ? '(obligatoria)' : '(opcional)' }}
      </span>
    </p>
    <input ref="entrada" type="file" accept="image/*" capture="environment" class="hidden" @change="elegida" />

    <div v-if="foto && vista" class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-2.5">
      <img :src="vista" alt="Foto del comprobante" class="size-16 shrink-0 rounded-lg object-cover ring-1 ring-black/5" />
      <div class="min-w-0 flex-1 text-xs text-emerald-800">
        <p class="font-semibold">Foto lista</p>
        <p class="text-emerald-700/80">Se guarda con el cobro.</p>
      </div>
      <button type="button" class="flex size-10 items-center justify-center rounded-lg text-gray-500 hover:bg-white" aria-label="Tomar otra foto" @click="entrada?.click()">
        <RefreshCw :size="17" />
      </button>
      <button type="button" class="flex size-10 items-center justify-center rounded-lg text-gray-500 hover:bg-white" aria-label="Quitar la foto" @click="foto = null">
        <X :size="18" />
      </button>
    </div>
    <button
      v-else
      type="button"
      class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-3 text-sm font-semibold transition active:scale-[0.99]"
      :class="props.obligatoria ? 'border-brand-400 bg-brand-50 text-brand-800 hover:bg-brand-100' : 'border-gray-300 text-gray-600 hover:border-brand-300'"
      :disabled="preparando"
      @click="entrada?.click()"
    >
      <Camera :size="19" />
      {{ preparando ? 'Preparando la foto…' : 'Tomar foto del comprobante' }}
    </button>
  </div>
</template>
