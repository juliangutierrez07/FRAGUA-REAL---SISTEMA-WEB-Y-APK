<script setup lang="ts">
// Fase 1, tarea 2 — Datos de contacto y entrega de un domicilio o una recogida, para el
// Mesero y el Administrador (RF-M07-003 a 007, RF-M07-010, RF-M07-R-003). El teléfono es un
// enlace para llamar directo desde el celular. Un pedido local no muestra nada.
import { computed } from 'vue'
import { Banknote, Mail, MapPin, MessageSquareText, Navigation, Phone, User, Wallet } from 'lucide-vue-next'
import { nombreMedio } from '@/utils/pasarela'
import { enlaceMapa } from '@/utils/pedidoUi'
import type { Pedido } from '@/types/models'

const props = defineProps<{ pedido: Pedido }>()

const nombre = computed(() => props.pedido.cliente_invitado_nombre ?? props.pedido.cliente?.nombre ?? null)
const telefono = computed(
  () => props.pedido.telefono_contacto ?? props.pedido.cliente_invitado_telefono ?? props.pedido.cliente?.telefono ?? null,
)
// RF-M07-013/015: lo que el cliente dijo que iba a usar para pagar.
const formaDePago = computed(() => {
  const p = props.pedido
  if (p.metodo_pago_preferido === 'en_linea') {
    return `En línea${p.pasarela_preferida ? ` (${nombreMedio(p.pasarela_preferida)})` : ''}`
  }
  if (p.metodo_pago_preferido === 'contra_entrega') return p.tipo === 'domicilio' ? 'Contra entrega' : 'Al recoger'
  return null
})
// Fase 4, tarea 5: a dónde llega el aviso «listo para recoger» (el del invitado o el de la cuenta).
const correo = computed(() => props.pedido.cliente_invitado_correo ?? props.pedido.cliente?.email ?? null)
const enlaceTelefono = computed(() => (telefono.value ? `tel:${telefono.value.replace(/[^\d+]/g, '')}` : ''))

// Mejora (26/10/2026): la ubicación que compartió el Cliente y el cambio que debe llevar el domiciliario.
const mapa = computed(() =>
  props.pedido.ubicacion_lat != null && props.pedido.ubicacion_lng != null
    ? enlaceMapa(Number(props.pedido.ubicacion_lat), Number(props.pedido.ubicacion_lng))
    : '',
)
const cambio = computed(() => {
  const p = props.pedido
  if (p.tipo !== 'domicilio' || p.metodo_pago_preferido !== 'contra_entrega') return null
  if (p.paga_con == null) return { texto: 'Paga con el valor exacto', falta: false }
  const diferencia = Number(p.paga_con) - Number(p.total)
  if (diferencia < 0) return { texto: `Dijo que paga con ${pesos(p.paga_con)}, pero ya no alcanza para el total`, falta: true }
  return { texto: `Paga con ${pesos(p.paga_con)}: lleva ${pesos(diferencia)} de cambio`, falta: false }
})
function pesos(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}
</script>

<template>
  <div v-if="pedido.tipo !== 'local'" class="mb-4 space-y-1.5 rounded-xl border border-gray-200 bg-white px-3.5 py-3 text-sm">
    <p v-if="nombre" class="flex items-center gap-2 text-ink">
      <User :size="15" class="shrink-0 text-gray-400" />
      <span class="font-semibold">{{ nombre }}</span>
    </p>
    <p v-if="telefono" class="flex items-center gap-2">
      <Phone :size="15" class="shrink-0 text-gray-400" />
      <a :href="enlaceTelefono" class="font-medium text-brand-700 underline-offset-2 hover:underline">{{ telefono }}</a>
    </p>
    <p v-if="correo" class="flex items-center gap-2 text-gray-700">
      <Mail :size="15" class="shrink-0 text-gray-400" />
      <span class="truncate">{{ correo }}</span>
    </p>
    <p v-if="pedido.tipo === 'domicilio'" class="flex items-start gap-2 text-ink">
      <MapPin :size="15" class="mt-0.5 shrink-0 text-gray-400" />
      <span>
        {{ pedido.direccion_entrega }}<template v-if="pedido.barrio"> · {{ pedido.barrio }}</template>
        <span v-if="pedido.referencia" class="block text-xs text-gray-500">{{ pedido.referencia }}</span>
      </span>
    </p>
    <p v-if="mapa" class="flex items-center gap-2">
      <Navigation :size="15" class="shrink-0 text-gray-400" />
      <a :href="mapa" target="_blank" rel="noopener" class="font-medium text-brand-700 underline-offset-2 hover:underline">Ver la ubicación en el mapa</a>
    </p>
    <p v-if="formaDePago" class="flex items-center gap-2 text-gray-700">
      <Wallet :size="15" class="shrink-0 text-gray-400" />
      <span>Paga: {{ formaDePago }}</span>
    </p>
    <p v-if="cambio" class="flex items-center gap-2 font-medium" :class="cambio.falta ? 'text-red-700' : 'text-emerald-800'">
      <Banknote :size="15" class="shrink-0" :class="cambio.falta ? 'text-red-500' : 'text-emerald-600'" />
      <span>{{ cambio.texto }}</span>
    </p>
    <p v-if="pedido.observaciones_entrega" class="flex items-start gap-2 text-gray-700">
      <MessageSquareText :size="15" class="mt-0.5 shrink-0 text-gray-400" />
      <span class="italic">“{{ pedido.observaciones_entrega }}”</span>
    </p>
  </div>
</template>
