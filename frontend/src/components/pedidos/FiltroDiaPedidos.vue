<script setup lang="ts">
// Mejora (28/10/2026, RF-M02-T-018): de qué día se ven los pedidos. Hoy por defecto; antes la lista traía los
// pedidos de todas las semanas. «Otra fecha» abre un calendario (hasta hoy). Las fechas son de Colombia.
import { computed } from 'vue'
import { CalendarDays } from 'lucide-vue-next'
import { fechaNegocio } from '@/utils/auditoria'

export type OpcionDia = 'hoy' | 'ayer' | 'semana' | 'fecha'
export interface RangoDia {
  opcion: OpcionDia
  fecha: string // solo para «Otra fecha»
}

const props = withDefaults(defineProps<{ conSemana?: boolean }>(), { conSemana: false })
const modelo = defineModel<RangoDia>({ required: true })

const hoy = fechaNegocio()
const opciones = computed(() => {
  const lista: { v: OpcionDia; t: string }[] = [
    { v: 'hoy', t: 'Hoy' },
    { v: 'ayer', t: 'Ayer' },
  ]
  if (props.conSemana) lista.push({ v: 'semana', t: 'Últimos 7 días' })
  lista.push({ v: 'fecha', t: 'Otra fecha' })
  return lista
})

function elegir(opcion: OpcionDia) {
  modelo.value = { opcion, fecha: opcion === 'fecha' ? modelo.value.fecha || hoy : modelo.value.fecha }
}
function cambiarFecha(evento: Event) {
  const valor = (evento.target as HTMLInputElement).value
  if (valor) modelo.value = { opcion: 'fecha', fecha: valor > hoy ? hoy : valor }
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <div class="segmented">
      <button
        v-for="opcion in opciones"
        :key="opcion.v"
        type="button"
        class="segmented-item"
        :class="modelo.opcion === opcion.v ? 'is-active' : ''"
        @click="elegir(opcion.v)"
      >
        <CalendarDays v-if="opcion.v === 'fecha'" :size="14" class="mr-1" />{{ opcion.t }}
      </button>
    </div>
    <input
      v-if="modelo.opcion === 'fecha'"
      type="date"
      class="field !mt-0 !w-auto min-h-10 py-1.5 text-sm"
      :max="hoy"
      :value="modelo.fecha"
      aria-label="Fecha de los pedidos"
      @change="cambiarFecha"
    />
  </div>
</template>
