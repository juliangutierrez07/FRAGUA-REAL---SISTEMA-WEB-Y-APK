<script setup lang="ts">
// M12 — Impresoras (24/10/2026): imprime el ticket de prueba de 80 mm en este equipo y pregunta cómo
// salió. Lo usan «Este equipo» (Impresoras) y la franja de comandas del Mesero.
import { ref } from 'vue'
import { Printer } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import { impresionService, RESULTADOS_PRUEBA, type ResultadoPrueba } from '@/services/impresion.service'
import { mensajeDeError } from '@/services/errors'
import { useEquipoImpresionStore } from '@/stores/equipoImpresion'
import { abrirPdf } from '@/utils/documentos'

const props = withDefaults(defineProps<{ compacto?: boolean }>(), { compacto: false })
const emit = defineEmits<{ (e: 'resultado', resultado: ResultadoPrueba): void }>()

const store = useEquipoImpresionStore()
const preguntando = ref(false)
const enviando = ref(false)
const mensaje = ref('')
const error = ref('')

async function imprimir() {
  error.value = ''
  mensaje.value = ''
  try {
    await abrirPdf(() => impresionService.prueba(), 'No pudimos abrir la impresión de prueba.')
    preguntando.value = store.registrado
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No pudimos abrir la impresión de prueba.'
  }
}

async function responder(resultado: ResultadoPrueba) {
  enviando.value = true
  error.value = ''
  try {
    const { data } = await impresionService.resultadoPrueba(resultado)
    store.aplicar(data)
    preguntando.value = false
    mensaje.value =
      resultado === 'ok'
        ? 'Listo: la prueba quedó registrada como «Salió bien».'
        : resultado === 'cortado'
          ? 'Quedó registrado. Revisa en el cuadro de impresión que el papel sea de 80 mm y los márgenes estén en «Ninguno».'
          : 'Quedó registrado. Revisa que la impresora esté prendida, con papel y elegida como predeterminada (ver la guía).'
    emit('resultado', resultado)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos guardar el resultado.')
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <div :class="props.compacto ? 'space-y-2' : 'space-y-3'">
    <BaseButton v-escritura :size="props.compacto ? 'sm' : 'md'" variant="secondary" @click="imprimir">
      <Printer :size="15" />
      Imprimir prueba
    </BaseButton>
    <div v-if="preguntando" class="rounded-xl border border-gray-200 bg-white p-3">
      <p class="text-sm font-medium text-ink">¿Cómo salió la prueba?</p>
      <div class="mt-2 flex flex-wrap gap-2">
        <BaseButton v-for="(texto, clave) in RESULTADOS_PRUEBA" :key="clave" size="sm" :variant="clave === 'ok' ? 'primary' : 'ghost'" :disabled="enviando" @click="responder(clave)">
          {{ texto }}
        </BaseButton>
      </div>
    </div>
    <p v-if="mensaje" class="text-xs text-emerald-700">{{ mensaje }}</p>
    <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
  </div>
</template>
