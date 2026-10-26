<script setup lang="ts">
// M12 — Impresoras (24/10/2026, RF-M12-007/008): el formato del comprobante y de la comanda de 80 mm,
// con vista previa en vivo (un ticket de muestra con lo que se está cambiando, sin guardarlo).
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ReceiptText } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import { impresionService, type FormatoImpresion } from '@/services/impresion.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { mensajeDeBlob } from '@/utils/documentos'

const formato = ref<FormatoImpresion | null>(null)
const guardado = ref('')
const tipo = ref<'comprobante' | 'comanda'>('comprobante')
const vista = ref('')
const cargandoVista = ref(false)
const errorVista = ref('')
const guardando = ref(false)
const errores = ref<Record<string, string>>({})
const error = ref('')
const exito = ref('')

const hayCambios = computed(() => !!formato.value && JSON.stringify(formato.value) !== guardado.value)

onMounted(async () => {
  try {
    const { data } = await impresionService.formato()
    formato.value = data
    guardado.value = JSON.stringify(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar el formato.')
  }
})

let espera: ReturnType<typeof setTimeout> | undefined
watch([formato, tipo], () => {
  clearTimeout(espera)
  espera = setTimeout(actualizarVista, 450)
}, { deep: true })

async function actualizarVista() {
  if (!formato.value) return
  cargandoVista.value = true
  errorVista.value = ''
  try {
    const { data } = await impresionService.vistaPrevia(tipo.value, formato.value)
    if (vista.value) URL.revokeObjectURL(vista.value)
    vista.value = URL.createObjectURL(new Blob([data], { type: 'application/pdf' })) + '#toolbar=0&navpanes=0&view=FitH'
  } catch (e) {
    errorVista.value = await mensajeDeBlob(e, 'No pudimos dibujar la vista previa.')
  } finally {
    cargandoVista.value = false
  }
}
onBeforeUnmount(() => vista.value && URL.revokeObjectURL(vista.value))

async function guardar() {
  if (!formato.value) return
  guardando.value = true
  errores.value = {}
  error.value = ''
  exito.value = ''
  try {
    const { data } = await impresionService.guardarFormato(formato.value)
    formato.value = data
    guardado.value = JSON.stringify(data)
    exito.value = 'Formato guardado: los próximos tickets ya salen así.'
  } catch (e) {
    errores.value = erroresDeValidacion(e)
    if (!Object.keys(errores.value).length) error.value = mensajeDeError(e, 'No pudimos guardar el formato.')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <section class="grid gap-5 lg:grid-cols-[1fr_330px]">
    <div class="card p-4 sm:p-5">
      <div class="flex items-start gap-3">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700"><ReceiptText :size="21" /></span>
        <div class="min-w-0 flex-1">
          <h2 class="text-[15px] font-semibold text-ink">Formato del ticket (80 mm)</h2>
          <p class="mt-0.5 text-xs text-gray-500">La vista previa se actualiza sola; los cambios se aplican al guardar.</p>
        </div>
      </div>

      <div v-if="!formato" class="loading-state">Cargando…</div>
      <div v-else class="mt-4 space-y-5">
        <fieldset class="space-y-2.5">
          <legend class="field-label">Comprobante de pago</legend>
          <label class="flex items-center gap-2 text-sm"><input v-model="formato.ticket_logo" v-escritura type="checkbox" /> Imprimir el logo del negocio</label>
          <div class="flex flex-wrap items-center gap-2 text-sm">
            Tamaño de letra:
            <label class="flex items-center gap-1.5"><input v-model="formato.ticket_letra" v-escritura type="radio" value="normal" /> Normal</label>
            <label class="flex items-center gap-1.5"><input v-model="formato.ticket_letra" v-escritura type="radio" value="grande" /> Grande</label>
          </div>
          <label class="block">
            <span class="text-sm">Mensaje al pie</span>
            <textarea
              v-model="formato.ticket_mensaje_pie"
              v-formato="'texto'"
              v-escritura
              maxlength="160"
              rows="2"
              class="field mt-1"
              placeholder="Ej.: ¡Gracias por tu visita! · @fraguareal · WiFi: fragua2026"
            />
            <span class="mt-0.5 block text-right text-[11px] text-gray-400">{{ formato.ticket_mensaje_pie?.length ?? 0 }}/160</span>
            <span v-if="errores.ticket_mensaje_pie" class="block text-xs text-red-600">{{ errores.ticket_mensaje_pie }}</span>
          </label>
          <label class="flex items-center gap-2 text-sm">
            Copias:
            <select v-model.number="formato.copias_comprobante" v-escritura class="field-sm w-20">
              <option v-for="n in 3" :key="n" :value="n">{{ n }}</option>
            </select>
          </label>
        </fieldset>

        <fieldset class="space-y-2.5 border-t border-gray-100 pt-4">
          <legend class="field-label">Comanda de cocina</legend>
          <label class="flex items-center gap-2 text-sm"><input v-model="formato.comanda_letra_grande" v-escritura type="checkbox" /> Letra grande (se lee mejor de lejos)</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="formato.comanda_mostrar_hora" v-escritura type="checkbox" /> Mostrar la fecha y la hora</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="formato.comanda_mostrar_mesero" v-escritura type="checkbox" /> Mostrar quién confirmó el pedido</label>
          <label class="flex items-center gap-2 text-sm">
            Copias:
            <select v-model.number="formato.copias_comanda" v-escritura class="field-sm w-20">
              <option v-for="n in 3" :key="n" :value="n">{{ n }}</option>
            </select>
          </label>
          <p class="text-xs text-gray-500">La comanda nunca lleva precios. Con más de una copia, cada una sale en una hoja.</p>
        </fieldset>

        <p v-if="error" class="alert-error">{{ error }}</p>
        <p v-if="exito" class="rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ exito }}</p>
        <div class="flex justify-end">
          <BaseButton v-escritura :disabled="guardando || !hayCambios" @click="guardar">{{ guardando ? 'Guardando…' : 'Guardar formato' }}</BaseButton>
        </div>
      </div>
    </div>

    <div class="card p-3 lg:sticky lg:top-4 lg:self-start">
      <div class="segmented mb-3 w-full">
        <button type="button" class="segmented-item flex-1" :class="tipo === 'comprobante' ? 'is-active' : ''" @click="tipo = 'comprobante'">Comprobante</button>
        <button type="button" class="segmented-item flex-1" :class="tipo === 'comanda' ? 'is-active' : ''" @click="tipo = 'comanda'">Comanda</button>
      </div>
      <div class="relative overflow-hidden rounded-xl border border-gray-200 bg-gray-100">
        <iframe v-if="vista" :src="vista" title="Vista previa del ticket" class="h-[560px] w-full bg-white" />
        <div v-else class="flex h-[560px] items-center justify-center text-sm text-gray-400">{{ errorVista || 'Preparando la vista previa…' }}</div>
        <span v-if="cargandoVista" class="absolute right-2 top-2 rounded-full bg-white/90 px-2 py-0.5 text-[11px] text-gray-500 shadow">Actualizando…</span>
      </div>
      <p class="mt-2 text-[11px] text-gray-500">Ticket de muestra (pedido #123). Así se ve en papel de 80 mm.</p>
    </div>
  </section>
</template>
