<script setup lang="ts">
// Pago directo (22/10/2026, RF-M06-030 a 033): lo que ve el Cliente para pagar en línea, en el
// Checkout, el seguimiento, «Mis pedidos» y las reservas.
//  · Sin Wompi: elige Nequi, Bancolombia o Bre-B, copia los datos y el valor exacto, transfiere desde
//    su app y sube la captura (foto o PDF, hasta 5 MB). El personal la revisa y aprueba o rechaza.
//  · Con Wompi activo («automatico»): elige Nequi o Bancolombia y sale a la pasarela, como antes.
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Check, Copy, FileText, ImageUp, Landmark, Smartphone, X, Zap } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import AvisoDestacado from '@/components/common/AvisoDestacado.vue'
import type { PagosDirectosPublico } from '@/services/configuracion.service'
import type { PagoEnLineaPayload } from '@/services/pedido.service'
import type { MedioPagoEnLinea } from '@/types/models'

const props = withDefaults(
  defineProps<{
    config: PagosDirectosPublico | null
    monto: number
    enviando?: boolean
    error?: string
    medioInicial?: MedioPagoEnLinea | null
    /** Qué se paga, para el texto del botón con Wompi (p. ej. «el depósito»). */
    concepto?: string
  }>(),
  { enviando: false, error: '', medioInicial: null, concepto: '' },
)

const emit = defineEmits<{ (e: 'pagar', payload: PagoEnLineaPayload): void }>()

const MAX_BYTES = 5 * 1024 * 1024
const TIPOS = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
const ICONOS = { nequi: Smartphone, bancolombia: Landmark, breb: Zap } as const

const automatico = computed(() => !!props.config?.automatico)
const medios = computed(() =>
  automatico.value
    ? ([
        { medio: 'nequi', nombre: 'Nequi', campos: [] },
        { medio: 'bancolombia', nombre: 'Bancolombia', campos: [] },
      ] as const)
    : (props.config?.medios ?? []),
)

const medio = ref<MedioPagoEnLinea | null>(null)
watch(
  medios,
  (lista) => {
    if (lista.some((m) => m.medio === medio.value)) return
    medio.value = lista.find((m) => m.medio === props.medioInicial)?.medio ?? lista[0]?.medio ?? null
  },
  { immediate: true },
)
const seleccionado = computed(() => props.config?.medios.find((m) => m.medio === medio.value) ?? null)

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

// ---- Copiar ----
const copiado = ref('')
let temporizador: ReturnType<typeof setTimeout> | undefined
async function copiar(clave: string, texto: string) {
  try {
    await navigator.clipboard.writeText(texto)
  } catch {
    // Sin permiso del portapapeles (http o navegador viejo): se selecciona a mano.
    const campo = document.createElement('textarea')
    campo.value = texto
    document.body.appendChild(campo)
    campo.select()
    document.execCommand('copy')
    campo.remove()
  }
  copiado.value = clave
  clearTimeout(temporizador)
  temporizador = setTimeout(() => (copiado.value = ''), 1800)
}

// ---- Comprobante ----
const archivo = ref<File | null>(null)
const vista = ref('')
const errorArchivo = ref('')
const referencia = ref('')
const entrada = ref<HTMLInputElement | null>(null)

function elegir(evento: Event) {
  const elegido = (evento.target as HTMLInputElement).files?.[0] ?? null
  ;(evento.target as HTMLInputElement).value = ''
  if (!elegido) return
  if (!TIPOS.includes(elegido.type)) {
    errorArchivo.value = 'El comprobante debe ser una foto (JPG, PNG o WebP) o un PDF.'
    return
  }
  if (elegido.size > MAX_BYTES) {
    errorArchivo.value = 'El comprobante no puede pesar más de 5 MB.'
    return
  }
  errorArchivo.value = ''
  quitar()
  archivo.value = elegido
  if (elegido.type.startsWith('image/')) vista.value = URL.createObjectURL(elegido)
}

function quitar() {
  if (vista.value) URL.revokeObjectURL(vista.value)
  vista.value = ''
  archivo.value = null
}
onBeforeUnmount(() => {
  quitar()
  clearTimeout(temporizador)
})

function tamano(bytes: number) {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function enviar() {
  if (!medio.value) return
  if (automatico.value) {
    emit('pagar', { pasarela: medio.value })
    return
  }
  if (!archivo.value) {
    errorArchivo.value = 'Sube la captura o el PDF del comprobante de la transferencia.'
    return
  }
  emit('pagar', { pasarela: medio.value, comprobante: archivo.value, referencia: referencia.value })
}
</script>

<template>
  <div v-if="config && (automatico || config.activo)" class="space-y-4">
    <!-- Medio -->
    <div>
      <p class="mb-2 text-[11px] font-medium uppercase tracking-[0.08em] text-[#6c655c]">
        {{ automatico ? 'Paga con' : '1 · Transfiere a' }}
      </p>
      <div class="grid gap-2" :class="medios.length > 2 ? 'grid-cols-3' : 'grid-cols-2'">
        <button
          v-for="m in medios"
          :key="m.medio"
          type="button"
          class="flex min-h-[3rem] flex-col items-center justify-center gap-1 rounded-xl border px-2 py-2.5 text-xs font-medium transition sm:flex-row sm:gap-2 sm:px-3 sm:text-sm"
          :class="
            medio === m.medio
              ? 'border-brand-500 bg-[#fff7e5] text-ink shadow-[0_0_0_3px_rgba(214,164,58,0.15)]'
              : 'border-[#dfd8cd] bg-white text-[#59534c] hover:border-brand-400'
          "
          :aria-pressed="medio === m.medio"
          @click="medio = m.medio"
        >
          <component :is="ICONOS[m.medio]" :size="16" class="shrink-0" />
          {{ m.nombre }}
        </button>
      </div>
    </div>

    <template v-if="!automatico && seleccionado">
      <!-- Datos de la cuenta -->
      <div class="overflow-hidden rounded-2xl border border-[#e2dbcf] bg-[#fbfaf7]">
        <dl class="divide-y divide-[#ece6db]">
          <div v-for="c in seleccionado.campos" :key="c.campo" class="flex items-center gap-3 px-4 py-2.5">
            <div class="min-w-0 flex-1">
              <dt class="text-[11px] uppercase tracking-[0.06em] text-[#8a8378]">{{ c.etiqueta }}</dt>
              <dd class="break-all text-sm font-medium text-ink" :class="c.copiar ? 'font-mono tracking-wide' : ''">
                {{ c.valor }}
              </dd>
            </div>
            <button
              v-if="c.copiar"
              type="button"
              class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-[#dfd8cd] bg-white px-2.5 py-1.5 text-xs font-medium text-[#59534c] transition hover:border-brand-400"
              :aria-label="`Copiar ${c.etiqueta}`"
              @click="copiar(c.campo, c.valor.replace(/\s+/g, ''))"
            >
              <component :is="copiado === c.campo ? Check : Copy" :size="13" />
              {{ copiado === c.campo ? 'Copiado' : 'Copiar' }}
            </button>
          </div>
          <div class="flex items-center gap-3 bg-white px-4 py-3">
            <div class="min-w-0 flex-1">
              <dt class="text-[11px] uppercase tracking-[0.06em] text-[#8a8378]">Valor exacto</dt>
              <dd class="font-sans font-semibold text-xl text-ink">{{ formatoPrecio(monto) }}</dd>
            </div>
            <button
              type="button"
              class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-[#dfd8cd] bg-white px-2.5 py-1.5 text-xs font-medium text-[#59534c] transition hover:border-brand-400"
              aria-label="Copiar el valor"
              @click="copiar('monto', String(Math.round(Number(monto))))"
            >
              <component :is="copiado === 'monto' ? Check : Copy" :size="13" />
              {{ copiado === 'monto' ? 'Copiado' : 'Copiar' }}
            </button>
          </div>
        </dl>
      </div>

      <!-- Comprobante -->
      <div>
        <p class="mb-2 text-[11px] font-medium uppercase tracking-[0.08em] text-[#6c655c]">2 · Sube el comprobante</p>
        <input
          ref="entrada"
          type="file"
          accept="image/jpeg,image/png,image/webp,application/pdf"
          class="sr-only"
          @change="elegir"
        />
        <div v-if="archivo" class="flex items-center gap-3 rounded-2xl border border-brand-300 bg-white p-3">
          <img v-if="vista" :src="vista" alt="Vista previa del comprobante" class="h-16 w-16 shrink-0 rounded-lg object-cover" />
          <span v-else class="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg bg-[#f3eee5] text-[#8a8378]">
            <FileText :size="24" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-medium text-ink">{{ archivo.name }}</span>
            <span class="text-xs text-[#6c655c]">{{ tamano(archivo.size) }}</span>
            <button type="button" class="ml-2 text-xs font-medium text-brand-700 underline" @click="entrada?.click()">Cambiar</button>
          </span>
          <button
            type="button"
            class="shrink-0 rounded-lg p-1.5 text-[#8a8378] transition hover:bg-[#f3eee5] hover:text-ink"
            aria-label="Quitar el comprobante"
            @click="quitar"
          >
            <X :size="16" />
          </button>
        </div>
        <button
          v-else
          type="button"
          class="flex w-full flex-col items-center justify-center gap-1.5 rounded-2xl border-2 border-dashed border-[#dfd8cd] bg-white px-4 py-6 text-center transition hover:border-brand-400 hover:bg-[#fffbf2]"
          @click="entrada?.click()"
        >
          <ImageUp :size="26" class="text-brand-600" />
          <span class="text-sm font-medium text-ink">Toca para subir la captura</span>
          <span class="text-xs text-[#6c655c]">Foto o PDF de la transferencia · hasta 5 MB</span>
        </button>
        <p v-if="errorArchivo" class="mt-2 text-sm text-red-700">{{ errorArchivo }}</p>
      </div>

      <label class="block">
        <span class="mb-1.5 block text-[11px] font-medium uppercase tracking-[0.08em] text-[#6c655c]">
          Referencia o número de aprobación <span class="normal-case tracking-normal">(opcional)</span>
        </span>
        <input v-formato="'referencia'"
          v-model="referencia"
          type="text"
          maxlength="60"
          inputmode="text"
          placeholder="Ej. M1234567"
          class="block w-full rounded-xl border border-[#dfd8cd] bg-white px-4 py-3 text-sm text-ink placeholder:text-[#a39c8f] focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15"
        />
      </label>
    </template>

    <p v-if="error" class="alert-error">{{ error }}</p>

    <BaseButton class="w-full sm:w-auto" :disabled="enviando || !medio" @click="enviar">
      <template v-if="automatico">
        {{ enviando ? 'Abriendo el pago…' : `Pagar ${concepto ? concepto + ' ' : ''}${formatoPrecio(monto)}` }}
      </template>
      <template v-else>{{ enviando ? 'Enviando comprobante…' : 'Enviar comprobante' }}</template>
    </BaseButton>
    <AvisoDestacado v-if="!automatico">
      Revisamos la transferencia y te confirmamos. Si algo no cuadra, te decimos por qué y puedes volver a enviarla.
    </AvisoDestacado>
  </div>
</template>
