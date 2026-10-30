<script setup lang="ts">
// Pago directo (22/10/2026, RF-M06-032/033, RN-M06-17/18): el personal mira la captura que subió el
// Cliente (pago de un pedido o depósito de una reserva) y lo aprueba o lo rechaza con un motivo,
// que el Cliente ve al seguir su pedido o en el enlace de su reserva. Lo usan Cobrar (Mesero),
// Pedidos y Reservas (Administrador). La captura llega por la API con la sesión, nunca por un enlace.
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { ExternalLink, FileText, ImageOff, ShieldAlert } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import { mensajeDeBlob } from '@/utils/documentos'
import { nombreMedio } from '@/utils/pasarela'

const props = withDefaults(
  defineProps<{
    abierto: boolean
    titulo: string
    monto: number
    medio?: string | null
    referencia?: string | null
    /** Pide la captura; null si no hay (pago sin comprobante o de la pasarela). */
    cargar?: (() => Promise<{ data: Blob }>) | null
    procesando?: boolean
    error?: string
    /** Abrir directo en «Rechazar» (desde el botón Rechazar de la lista). */
    rechazar?: boolean
    textoAprobar?: string
  }>(),
  { medio: null, referencia: null, cargar: null, procesando: false, error: '', rechazar: false, textoAprobar: 'Aprobar pago' },
)

const emit = defineEmits<{
  (e: 'cerrar'): void
  (e: 'aprobar'): void
  (e: 'rechazar', motivo: string): void
}>()

const MOTIVOS = [
  'No nos ha llegado el dinero',
  'El valor no coincide',
  'La captura no se ve bien',
  'Es de otra transferencia',
]

const url = ref('')
const tipo = ref('')
const cargando = ref(false)
const errorArchivo = ref('')
const modo = ref<'revisar' | 'rechazar'>('revisar')
const motivo = ref('')
const avisoMotivo = ref('')

function liberar() {
  if (url.value) URL.revokeObjectURL(url.value)
  url.value = ''
  tipo.value = ''
}

watch(
  () => props.abierto,
  async (abierto) => {
    liberar()
    errorArchivo.value = ''
    motivo.value = ''
    avisoMotivo.value = ''
    modo.value = props.rechazar ? 'rechazar' : 'revisar'
    if (!abierto || !props.cargar) return
    cargando.value = true
    try {
      const { data } = await props.cargar()
      tipo.value = data.type
      url.value = URL.createObjectURL(data)
    } catch (e) {
      errorArchivo.value = await mensajeDeBlob(e, 'No pudimos abrir el comprobante.')
    } finally {
      cargando.value = false
    }
  },
  { immediate: true },
)
onBeforeUnmount(liberar)

const esPdf = computed(() => tipo.value === 'application/pdf')

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function confirmarRechazo() {
  if (!motivo.value.trim()) {
    avisoMotivo.value = 'Escribe el motivo: el Cliente lo ve para saber qué corregir.'
    return
  }
  emit('rechazar', motivo.value.trim())
}

function volver() {
  if (props.rechazar || !props.cargar) emit('cerrar')
  else modo.value = 'revisar'
}
</script>

<template>
  <BaseModal :model-value="abierto" :title="modo === 'rechazar' ? 'Rechazar pago' : titulo" @update:model-value="emit('cerrar')">
    <dl class="grid grid-cols-2 gap-3 rounded-2xl bg-gray-50 p-3.5 text-sm sm:grid-cols-3">
      <div>
        <dt class="text-[11px] uppercase tracking-[0.06em] text-gray-500">Valor</dt>
        <dd class="font-sans text-lg font-semibold text-ink">{{ formatoPrecio(monto) }}</dd>
      </div>
      <div>
        <dt class="text-[11px] uppercase tracking-[0.06em] text-gray-500">Medio</dt>
        <dd class="font-medium text-ink">{{ nombreMedio(medio) || '—' }}</dd>
      </div>
      <div class="col-span-2 sm:col-span-1">
        <dt class="text-[11px] uppercase tracking-[0.06em] text-gray-500">Referencia</dt>
        <dd class="break-all font-mono text-ink">{{ referencia || '—' }}</dd>
      </div>
    </dl>

    <template v-if="modo === 'revisar'">
      <div v-if="cargar" class="mt-4">
        <div v-if="cargando" class="h-64 animate-pulse rounded-2xl bg-gray-100" />
        <div v-else-if="errorArchivo" class="flex flex-col items-center gap-2 rounded-2xl bg-gray-50 px-4 py-10 text-center text-sm text-gray-500">
          <ImageOff :size="28" />
          {{ errorArchivo }}
        </div>
        <template v-else-if="url">
          <iframe v-if="esPdf" :src="url" title="Comprobante en PDF" class="h-[55dvh] w-full rounded-2xl border border-gray-200" />
          <a v-else :href="url" target="_blank" rel="noopener" class="block overflow-hidden rounded-2xl border border-gray-200 bg-gray-50">
            <img :src="url" alt="Comprobante de la transferencia" class="mx-auto max-h-[55dvh] w-auto object-contain" />
          </a>
          <a
            :href="url"
            target="_blank"
            rel="noopener"
            class="mt-2 inline-flex min-h-10 items-center gap-1.5 text-xs font-semibold text-gray-600 hover:text-ink"
          >
            <component :is="esPdf ? FileText : ExternalLink" :size="14" /> Abrir en otra pestaña
          </a>
        </template>
      </div>

      <p class="mt-3 flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-xs text-amber-900">
        <ShieldAlert :size="16" class="mt-px shrink-0" />
        Antes de aprobar, revisa en la app del banco que el dinero llegó a la cuenta del negocio: una captura sola se
        puede editar.
      </p>

      <p v-if="error" class="mt-3 alert-error">{{ error }}</p>

      <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <BaseButton v-escritura variant="danger-soft" :disabled="procesando" @click="modo = 'rechazar'">Rechazar</BaseButton>
        <BaseButton v-escritura variant="primary" :disabled="procesando" @click="emit('aprobar')">
          {{ procesando ? 'Procesando…' : textoAprobar }}
        </BaseButton>
      </div>
    </template>

    <template v-else>
      <p class="mt-4 text-sm text-gray-600">¿Por qué no se aprueba? El Cliente lo verá y podrá enviar el pago de nuevo.</p>
      <div class="mt-3 flex flex-wrap gap-2">
        <button
          v-for="m in MOTIVOS"
          :key="m"
          type="button"
          class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
          :class="motivo === m ? 'border-red-300 bg-red-50 text-red-700' : 'border-gray-200 text-gray-600 hover:border-gray-300'"
          @click="(motivo = m), (avisoMotivo = '')"
        >
          {{ m }}
        </button>
      </div>
      <textarea v-formato="'texto'" v-model="motivo" rows="3" maxlength="255" class="field mt-3" placeholder="O escribe el motivo" />
      <p v-if="avisoMotivo" class="mt-1 text-xs text-red-600">{{ avisoMotivo }}</p>
      <p v-if="error" class="mt-3 alert-error">{{ error }}</p>
      <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <BaseButton variant="ghost" :disabled="procesando" @click="volver">Volver</BaseButton>
        <BaseButton v-escritura variant="danger" :disabled="procesando" @click="confirmarRechazo">
          {{ procesando ? 'Rechazando…' : 'Rechazar pago' }}
        </BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
