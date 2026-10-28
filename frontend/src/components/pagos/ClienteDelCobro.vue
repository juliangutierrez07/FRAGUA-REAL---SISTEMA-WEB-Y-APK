<script setup lang="ts">
// Fidelización (27/10/2026, RF-M06-040, RN-M06-23, HU-M06-021): al cobrar un pedido (del QR o del Mesero), una
// cuenta o toda la mesa, el Mesero puede buscar al cliente por documento, correo o celular, confirmar su
// nombre con él y dejarle los pedidos en su historial («Mis pedidos»). Es opcional: nunca impide cobrar.
// Los pedidos que ya son de un cliente (p. ej. uno web con sesión) solo muestran su nombre.
import { computed, ref, watch } from 'vue'
import { Search, UserRound } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import { pedidoService } from '@/services/pedido.service'
import { mensajeDeError } from '@/services/errors'
import type { Pedido } from '@/types/models'

const props = defineProps<{ pedidos: Pedido[] }>()
const emit = defineEmits<{ (e: 'asignado', cliente: { id: number; nombre: string }, pedidoIds: number[]): void }>()

const POR: Record<string, string> = { documento: 'por documento', correo: 'por correo', celular: 'por celular' }

const conCliente = computed(() => props.pedidos.find((p) => p.cliente_id && p.cliente)?.cliente ?? null)
const sinCliente = computed(() => props.pedidos.filter((p) => !p.cliente_id && p.estado !== 'cancelado'))

const dato = ref('')
const buscando = ref(false)
const asignando = ref(false)
const hallado = ref<{ id: number; nombre: string; coincide: string } | null>(null)
const mensaje = ref('')
const error = ref('')

// Otro pedido u otra cuenta: se empieza de cero.
watch(
  () => props.pedidos.map((p) => p.id).join(','),
  () => {
    dato.value = ''
    hallado.value = null
    mensaje.value = ''
    error.value = ''
  },
)

async function buscar() {
  const texto = dato.value.trim()
  if (!texto) return
  buscando.value = true
  hallado.value = null
  mensaje.value = ''
  error.value = ''
  try {
    const { data } = await pedidoService.buscarCliente(texto)
    hallado.value = data
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos buscar al cliente.')
  } finally {
    buscando.value = false
  }
}

async function asignar() {
  if (!hallado.value || !sinCliente.value.length) return
  asignando.value = true
  error.value = ''
  try {
    const { data } = await pedidoService.asignarCliente(sinCliente.value.map((p) => p.id), hallado.value.id)
    mensaje.value = `Listo: ${data.pedido_ids.length === 1 ? 'el pedido queda' : 'los pedidos quedan'} en el historial de ${data.cliente.nombre}.`
    emit('asignado', data.cliente, data.pedido_ids)
    hallado.value = null
    dato.value = ''
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos asignar el pedido al cliente.')
  } finally {
    asignando.value = false
  }
}
</script>

<template>
  <div class="mt-5 rounded-2xl border border-gray-200 px-4 py-3.5">
    <p class="flex items-center gap-2 text-xs font-semibold text-gray-700">
      <UserRound :size="15" class="text-brand-600" />
      Cliente <span class="font-normal text-gray-400">(opcional, para su historial)</span>
    </p>

    <p v-if="conCliente" class="mt-2 text-sm text-ink">
      <strong>{{ conCliente.nombre }}</strong>
      <span class="text-xs text-gray-500">
        · {{ sinCliente.length ? 'parte de esta cuenta ya está en su historial' : 'queda en su historial' }}
      </span>
    </p>
    <p v-if="mensaje" class="mt-2 rounded-xl bg-emerald-50 px-3 py-2 text-xs text-emerald-800" role="status">{{ mensaje }}</p>

    <template v-if="sinCliente.length">
      <form class="mt-2.5 flex gap-2" @submit.prevent="buscar">
        <input
          v-model="dato"
          v-formato="'llave'"
          type="text"
          class="field min-w-0 flex-1"
          placeholder="Documento, correo o celular"
          aria-label="Documento, correo o celular del cliente"
          autocomplete="off"
        />
        <BaseButton type="submit" variant="secondary" :disabled="buscando || !dato.trim()" aria-label="Buscar cliente">
          <Search :size="16" />
          <span class="hidden sm:inline">{{ buscando ? 'Buscando…' : 'Buscar' }}</span>
        </BaseButton>
      </form>

      <div v-if="hallado" class="mt-2.5 flex flex-col gap-2 rounded-xl bg-brand-50 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-ink">
          <strong>{{ hallado.nombre }}</strong>
          <span class="block text-xs text-gray-500">Encontrado {{ POR[hallado.coincide] ?? '' }}. Confírmalo con el cliente.</span>
        </p>
        <div class="flex shrink-0 gap-2">
          <BaseButton variant="ghost" size="sm" @click="hallado = null">No es</BaseButton>
          <BaseButton v-escritura variant="primary" size="sm" :disabled="asignando" @click="asignar">
            {{ asignando ? 'Asignando…' : 'Asignar' }}
          </BaseButton>
        </div>
      </div>
      <p v-if="error" class="mt-2 text-xs text-red-600" role="alert">{{ error }}</p>
      <p v-else-if="!hallado && !mensaje" class="mt-1.5 text-[11px] text-gray-400">
        Si no tiene cuenta, se cobra igual: puede registrarse en la web y desde su próximo pedido queda guardado.
      </p>
    </template>
  </div>
</template>
