<script setup lang="ts">
// Mejora (28/10/2026, RF-M06-041): los pagos del pedido en su detalle (Pedidos del Mesero y del Administrador),
// con «Ver foto» en los que tienen comprobante: la foto que tomó el Mesero al cobrar por transferencia o
// tarjeta, o la captura que subió el Cliente. Antes la foto solo se veía en el detalle del turno de Caja.
// La foto llega por la API con la sesión (GET /pagos/{id}/comprobante), nunca por un enlace público.
import { computed, onBeforeUnmount, ref } from 'vue'
import { Camera, ExternalLink } from 'lucide-vue-next'
import BaseModal from '@/components/common/BaseModal.vue'
import { pagoService } from '@/services/pago.service'
import { mensajeDeBlob } from '@/utils/documentos'
import { nombreMedio } from '@/utils/pasarela'
import type { Pago, Pedido } from '@/types/models'

const props = defineProps<{ pedido: Pick<Pedido, 'id' | 'pagos'> }>()

const METODOS: Record<NonNullable<Pago['metodo']>, string> = {
  efectivo: 'Efectivo',
  tarjeta: 'Tarjeta',
  transferencia: 'Transferencia',
  en_linea: 'En línea',
}

// Los cobros del pedido (no los abonos del depósito de una reserva), del más viejo al más nuevo.
const pagos = computed(() => (props.pedido.pagos ?? []).filter((p) => p.tipo !== 'deposito'))

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}
function hora(valor: string) {
  return new Date(valor).toLocaleString('es-CO', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
}
function medio(pago: Pago) {
  const base = pago.metodo ? METODOS[pago.metodo] : 'Pago'
  return pago.metodo === 'en_linea' && pago.pasarela ? `${base} · ${nombreMedio(pago.pasarela)}` : base
}
function estado(pago: Pago): { texto: string; clase: string } {
  if (pago.anulado) return { texto: 'Anulado', clase: 'bg-gray-100 text-gray-600 ring-gray-500/20' }
  if (pago.estado === 'pendiente') return { texto: 'Por revisar', clase: 'bg-amber-50 text-amber-700 ring-amber-600/20' }
  if (pago.estado === 'rechazado') return { texto: 'Rechazado', clase: 'bg-red-50 text-red-700 ring-red-600/20' }
  return { texto: 'Aprobado', clase: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' }
}

const foto = ref<{ pago: Pago; url: string; esPdf: boolean } | null>(null)
const cargandoId = ref<number | null>(null)
const error = ref('')

async function verFoto(pago: Pago) {
  cargandoId.value = pago.id
  error.value = ''
  try {
    const { data } = await pagoService.comprobante(pago.id)
    cerrar()
    foto.value = { pago, url: URL.createObjectURL(data), esPdf: data.type === 'application/pdf' }
  } catch (e) {
    error.value = await mensajeDeBlob(e, 'No pudimos abrir la foto del comprobante.')
  } finally {
    cargandoId.value = null
  }
}
function cerrar() {
  if (foto.value) URL.revokeObjectURL(foto.value.url)
  foto.value = null
}
onBeforeUnmount(cerrar)
</script>

<template>
  <div v-if="pagos.length" class="mt-4 border-t border-gray-200 pt-3">
    <p class="text-[11px] font-semibold uppercase tracking-[0.1em] text-gray-500">Pagos</p>
    <ul class="mt-2 space-y-2">
      <li
        v-for="pago in pagos"
        :key="pago.id"
        class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5 rounded-xl bg-white px-3 py-2.5 ring-1 ring-gray-200"
      >
        <div class="min-w-0">
          <p class="text-sm font-medium text-ink" :class="pago.anulado ? 'line-through decoration-gray-400' : ''">
            {{ medio(pago) }} · {{ formatoPrecio(pago.monto) }}
          </p>
          <p class="text-xs text-gray-500">{{ hora(pago.created_at) }}</p>
        </div>
        <div class="flex items-center gap-2">
          <span class="badge" :class="estado(pago).clase">{{ estado(pago).texto }}</span>
          <button
            v-if="pago.tiene_comprobante"
            type="button"
            class="inline-flex min-h-9 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold text-brand-700 ring-1 ring-brand-200 transition hover:bg-brand-50 disabled:opacity-60"
            :disabled="cargandoId === pago.id"
            @click="verFoto(pago)"
          >
            <Camera :size="14" />
            {{ cargandoId === pago.id ? 'Abriendo…' : 'Ver foto' }}
          </button>
        </div>
      </li>
    </ul>
    <p v-if="error" class="mt-2 text-xs text-red-600">{{ error }}</p>

    <BaseModal
      :model-value="!!foto"
      :title="foto ? `Comprobante · pedido #${pedido.id}` : ''"
      @update:model-value="cerrar"
    >
      <template v-if="foto">
        <p class="mb-3 text-sm text-gray-600">{{ medio(foto.pago) }} · {{ formatoPrecio(foto.pago.monto) }} · {{ hora(foto.pago.created_at) }}</p>
        <a
          v-if="foto.esPdf"
          :href="foto.url"
          target="_blank"
          rel="noopener"
          class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 underline"
        >
          <ExternalLink :size="15" /> Abrir el comprobante (PDF)
        </a>
        <img v-else :src="foto.url" alt="Foto del comprobante" class="mx-auto max-h-[70vh] rounded-xl object-contain" />
      </template>
    </BaseModal>
  </div>
</template>
