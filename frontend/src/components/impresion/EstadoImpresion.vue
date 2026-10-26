<script setup lang="ts">
// M12 — Impresoras (24/10/2026, RF-M12-009/010): lo que falta por imprimir (reintentar o descartar) y
// las últimas impresiones: qué, quién, cuándo y en qué equipo.
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { ChefHat, History, RotateCcw } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import { comandaService, type ComandaPendiente } from '@/services/comanda.service'
import { impresionService, type ImpresionHistorial } from '@/services/impresion.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { abrirPdf } from '@/utils/documentos'
import { TIPOS_COMANDA } from '@/utils/comandas'

const pendientes = ref<ComandaPendiente[]>([])
const historial = ref<ImpresionHistorial[]>([])
const cargando = ref(true)
const error = ref('')
const procesando = ref<number | null>(null)
let temporizador: ReturnType<typeof setInterval> | null = null

async function cargar() {
  try {
    const { data } = await impresionService.estado()
    pendientes.value = data.pendientes
    historial.value = data.historial
    error.value = ''
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar el estado de impresión.')
  } finally {
    cargando.value = false
  }
}
onMounted(() => {
  cargar()
  temporizador = setInterval(cargar, 20_000)
})
onBeforeUnmount(() => temporizador && clearInterval(temporizador))

function titulo(c: ComandaPendiente) {
  return `${TIPOS_COMANDA[c.tipo]} ${c.pedido_id}-${c.numero} · ${c.pedido.mesa ?? (c.pedido.tipo === 'domicilio' ? 'Domicilio' : 'Para recoger')}`
}

async function reintentar(c: ComandaPendiente) {
  procesando.value = c.id
  error.value = ''
  try {
    await abrirPdf(() => comandaService.ticket(c.id), 'No pudimos abrir la comanda.')
    await cargar()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No pudimos abrir la comanda.'
  } finally {
    procesando.value = null
  }
}

const aDescartar = ref<ComandaPendiente | null>(null)
const motivo = ref('')
const errorMotivo = ref('')
async function descartar() {
  if (!aDescartar.value) return
  errorMotivo.value = ''
  try {
    await impresionService.descartarComanda(aDescartar.value.id, motivo.value.trim())
    aDescartar.value = null
    motivo.value = ''
    await cargar()
  } catch (e) {
    errorMotivo.value = erroresDeValidacion(e).motivo || mensajeDeError(e, 'No pudimos descartarla.')
  }
}

const ACCIONES: Record<string, string> = {
  imprimir_comanda: 'Comanda',
  imprimir_comprobante: 'Comprobante',
  imprimir_cierre_diario: 'Cierre diario',
  comanda_no_impresa: 'Comanda que no salió',
  descartar_comanda: 'Comanda descartada',
  prueba_impresion: 'Impresión de prueba',
}

function describir(h: ImpresionHistorial) {
  const d = (h.detalles ?? {}) as Record<string, unknown>
  const partes = [ACCIONES[h.accion] ?? h.accion]
  if (d.comanda) partes[0] += ` ${h.pedido_id}-${d.comanda}`
  else if (h.pedido_id) partes.push(`pedido #${h.pedido_id}`)
  if (h.accion === 'prueba_impresion' && d.resultado) partes.push(String(d.resultado))
  if (d.reimpresion) partes.push(`reimpresión · copia ${d.vez}`)
  if (d.formato === 'ticket') partes.push('ticket 80 mm')
  if (d.motivo) partes.push(`«${d.motivo}»`)
  return partes.join(' · ')
}

function hora(valor: string) {
  return new Date(valor).toLocaleString('es-CO', { timeZone: 'America/Bogota', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
}
</script>

<template>
  <div class="space-y-6">
    <p v-if="error" class="alert-error">{{ error }}</p>

    <section>
      <h2 class="flex items-center gap-2 font-display text-lg font-medium text-ink"><ChefHat :size="18" /> Por imprimir</h2>
      <div v-if="cargando" class="loading-state">Cargando…</div>
      <p v-else-if="!pendientes.length" class="mt-2 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">Todo impreso: la cocina tiene todas las comandas.</p>
      <ul v-else class="mt-3 space-y-2">
        <li v-for="c in pendientes" :key="c.id" class="card flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between">
          <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-ink">{{ titulo(c) }}</p>
            <p class="text-xs" :class="c.estado_impresion === 'fallida' ? 'text-red-700' : 'text-gray-500'">
              {{ c.estado_impresion === 'fallida' ? `No salió: ${c.ultimo_error}` : 'Pendiente' }} · {{ c.lineas }} {{ c.lineas === 1 ? 'línea' : 'líneas' }}
            </p>
          </div>
          <div class="flex gap-2">
            <BaseButton v-escritura size="sm" :disabled="procesando === c.id" @click="reintentar(c)"><RotateCcw :size="14" /> Imprimir</BaseButton>
            <BaseButton v-escritura size="sm" variant="ghost" @click="(aDescartar = c), (motivo = ''), (errorMotivo = '')">Descartar</BaseButton>
          </div>
        </li>
      </ul>
    </section>

    <section>
      <h2 class="flex items-center gap-2 font-display text-lg font-medium text-ink"><History :size="18" /> Últimas impresiones</h2>
      <p v-if="!cargando && !historial.length" class="mt-2 text-sm text-gray-500">Todavía no hay impresiones.</p>
      <div v-else-if="historial.length" class="card mt-3 overflow-hidden">
        <ul class="divide-y divide-gray-100">
          <li v-for="h in historial" :key="h.id" class="flex flex-col gap-0.5 px-4 py-2.5 text-sm sm:flex-row sm:items-center sm:justify-between">
            <span class="min-w-0 text-ink">{{ describir(h) }}</span>
            <span class="shrink-0 text-xs text-gray-500">
              {{ hora(h.fecha) }} · {{ h.usuario ?? 'sistema' }}<template v-if="h.detalles?.equipo"> · en «{{ h.detalles.equipo }}»</template>
            </span>
          </li>
        </ul>
      </div>
    </section>

    <BaseModal :model-value="!!aDescartar" title="Descartar comanda" @update:model-value="aDescartar = null">
      <p class="text-sm text-gray-600">«{{ aDescartar ? titulo(aDescartar) : '' }}» sale de las pendientes y no se imprimirá. El pedido no cambia.</p>
      <textarea v-model="motivo" v-formato="'texto'" maxlength="255" rows="2" class="field mt-3" placeholder="Ej.: la cocina ya lo tiene anotado" />
      <p v-if="errorMotivo" class="mt-1 text-xs text-red-600">{{ errorMotivo }}</p>
      <div class="mt-5 flex justify-end gap-2">
        <BaseButton variant="ghost" @click="aDescartar = null">Volver</BaseButton>
        <BaseButton v-escritura variant="danger" :disabled="motivo.trim().length < 3" @click="descartar">Descartar</BaseButton>
      </div>
    </BaseModal>
  </div>
</template>
