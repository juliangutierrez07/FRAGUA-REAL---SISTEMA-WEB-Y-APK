<script setup lang="ts">
// Mejora (16/10/2026): resumen de un turno de caja para el Administrador (CajaService::resumen).
// Ingresos del día por método, de dónde sale el efectivo esperado, depósitos de reserva (también
// los pagados en línea) y anulados aparte. Se usa en la tarjeta «Turno de hoy» y en el detalle de
// cada turno del historial.
import { computed } from 'vue'
import { Banknote, CreditCard, Landmark, Smartphone } from 'lucide-vue-next'
import type { MontoPorMetodo, ResumenCaja } from '@/types/models'

const props = defineProps<{ resumen: ResumenCaja; compacto?: boolean }>()

const METODOS: Record<MontoPorMetodo['metodo'], { texto: string; icono: typeof Banknote; nota: string }> = {
  efectivo: { texto: 'Efectivo', icono: Banknote, nota: 'En el cajón' },
  tarjeta: { texto: 'Tarjeta', icono: CreditCard, nota: 'Compárala con el datáfono' },
  transferencia: { texto: 'Transferencia', icono: Landmark, nota: 'Compárala con el banco' },
  en_linea: { texto: 'En línea', icono: Smartphone, nota: 'Wompi / Nequi' },
}

function pesos(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function cantidadTexto(n: number, singular: string, plural: string) {
  return `${n} ${n === 1 ? singular : plural}`
}

// Las líneas del efectivo esperado; las que están en cero no se muestran (salvo la base).
const lineasEfectivo = computed(() => {
  const e = props.resumen.efectivo
  return [
    { texto: 'Base inicial', valor: e.base_inicial, signo: '', siempre: true },
    { texto: 'Cobros en efectivo', valor: e.cobros, signo: '+', siempre: true },
    { texto: 'Depósitos de reserva en efectivo', valor: e.depositos, signo: '+', siempre: false },
    { texto: 'Compras y gastos pagados en efectivo', valor: e.gastos, signo: '−', siempre: false },
    { texto: 'Depósitos devueltos en efectivo', valor: e.devoluciones, signo: '−', siempre: false },
  ].filter((l) => l.siempre || l.valor > 0)
})
</script>

<template>
  <div class="space-y-5">
    <!-- Ingresos del día por método -->
    <div>
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-700">Ingresos del día</h3>
        <p class="text-sm text-gray-800">
          <span class="font-semibold text-ink">{{ pesos(resumen.ingresos.total) }}</span>
          · {{ cantidadTexto(resumen.ingresos.cantidad, 'cobro', 'cobros') }}
        </p>
      </div>
      <div class="mt-2 grid grid-cols-2 gap-2" :class="compacto ? '' : 'lg:grid-cols-4'">
        <div
          v-for="m in resumen.ingresos.por_metodo"
          :key="m.metodo"
          class="rounded-xl border border-gray-200 bg-white p-3"
        >
          <p class="flex items-center gap-1.5 text-xs font-semibold text-gray-800">
            <component :is="METODOS[m.metodo].icono" :size="14" />
            {{ METODOS[m.metodo].texto }}
          </p>
          <p class="mt-1 font-sans text-lg font-semibold text-ink">{{ pesos(m.monto) }}</p>
          <p class="text-[11px] text-gray-600">
            {{ cantidadTexto(m.cantidad, m.metodo === 'en_linea' ? 'pago' : 'cobro', m.metodo === 'en_linea' ? 'pagos' : 'cobros') }}
            · {{ METODOS[m.metodo].nota }}
          </p>
        </div>
      </div>
    </div>

    <div class="grid gap-4" :class="compacto ? '' : 'lg:grid-cols-2'">
      <!-- De dónde sale el efectivo esperado (RN-M06-08) -->
      <div>
        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-700">Efectivo esperado en caja</h3>
        <dl class="mt-2 space-y-1 rounded-xl bg-gray-50 p-3 text-sm">
          <div v-for="l in lineasEfectivo" :key="l.texto" class="flex justify-between gap-3 text-gray-800">
            <dt>{{ l.texto }}</dt>
            <dd class="shrink-0 tabular-nums">{{ l.signo }} {{ pesos(l.valor) }}</dd>
          </div>
          <div class="flex justify-between gap-3 border-t border-gray-200 pt-1.5 font-semibold text-ink">
            <dt>Esperado</dt>
            <dd class="shrink-0 tabular-nums">{{ pesos(resumen.efectivo.esperado) }}</dd>
          </div>
        </dl>
        <p class="mt-1 text-[11px] text-gray-600">Tarjeta, transferencia y en línea no están en el cajón: no cuentan aquí.</p>
      </div>

      <div class="space-y-4">
        <!-- Depósitos de reserva del día (anticipos), incluidos los pagados en línea -->
        <div>
          <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-700">Depósitos de reserva del día</h3>
          <p v-if="!resumen.depositos.cantidad && !resumen.depositos.devueltos.cantidad" class="mt-2 text-sm text-gray-800">
            No se recibieron depósitos.
          </p>
          <ul v-else class="mt-2 space-y-1 text-sm text-gray-800">
            <li v-for="d in resumen.depositos.por_metodo" :key="d.metodo" class="flex justify-between gap-3">
              <span>{{ METODOS[d.metodo]?.texto ?? d.metodo }} ({{ d.cantidad }})</span>
              <span class="tabular-nums">{{ pesos(d.monto) }}</span>
            </li>
            <li v-if="resumen.depositos.devueltos.cantidad" class="flex justify-between gap-3 text-amber-700">
              <span>Devueltos ({{ resumen.depositos.devueltos.cantidad }})</span>
              <span class="tabular-nums">− {{ pesos(resumen.depositos.devueltos.monto) }}</span>
            </li>
          </ul>
          <p class="mt-1 text-[11px] text-gray-600">Son anticipos: se vuelven venta cuando el cliente llega y se abonan a su cuenta.</p>
        </div>

        <!-- Anulados, aparte de lo cobrado -->
        <div v-if="resumen.anulados.cantidad" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">
          <p class="font-medium">
            {{ cantidadTexto(resumen.anulados.cantidad, 'pago anulado o reembolsado', 'pagos anulados o reembolsados') }}:
            {{ pesos(resumen.anulados.total) }}
          </p>
          <p class="mt-0.5 text-xs">
            <template v-for="(a, i) in resumen.anulados.por_metodo" :key="a.metodo">
              <template v-if="i"> · </template>{{ METODOS[a.metodo]?.texto ?? a.metodo }} {{ pesos(a.monto) }}
            </template>
            . No suman en los ingresos del día.
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
