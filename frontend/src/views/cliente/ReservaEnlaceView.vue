<script setup lang="ts">
// Fase 4, tarea 4 — Mi reserva por enlace (RF-M05-018, RN-M05-09, HU-M05-011). Quien reservó
// (también sin cuenta) entra con el enlace de sus correos (/reserva/<token>): ve su reserva y,
// mientras no haya llegado la hora, la cancela. Si el depósito se pagó en línea, se le devuelve
// completo (RN-M05-17); si se pagó en efectivo en el local, el backend le indica pasar por allá.
// Pago directo (22/10/2026, RF-M06-030 a 033): sin Wompi, paga el depósito con la captura de la
// transferencia; mientras se revisa lo dice, y si se rechaza ve el motivo y la envía de nuevo.
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { CalendarClock, CheckCircle2, XCircle } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import AvisoDestacado from '@/components/common/AvisoDestacado.vue'
import PagoEnLinea from '@/components/pagos/PagoEnLinea.vue'
import { usePagosEnLinea } from '@/composables/usePagosEnLinea'
import { reservaService } from '@/services/reserva.service'
import type { PagoEnLineaPayload } from '@/services/pedido.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { irAlCheckout } from '@/utils/pasarela'
import type { EstadoReserva, ReservaPublica } from '@/types/models'

const route = useRoute()
const token = String(route.params.token ?? '')

const reserva = ref<ReservaPublica | null>(null)
const cargando = ref(true)
const noEncontrada = ref(false)
const error = ref('')
const confirmando = ref(false)
const cancelando = ref(false)
const cancelada = ref(false)

const ESTADOS: Record<EstadoReserva, string> = {
  pendiente_pago: 'Pendiente de pago del depósito',
  confirmada: 'Confirmada',
  cancelada: 'Cancelada',
  no_show: 'No asististe',
  completada: 'Completada',
}

async function cargar() {
  cargando.value = true
  try {
    const { data } = await reservaService.verPorEnlace(token)
    reserva.value = data
  } catch (e) {
    if ((e as { response?: { status?: number } }).response?.status === 404) noEncontrada.value = true
    else error.value = mensajeDeError(e, 'No pudimos cargar tu reserva.')
  } finally {
    cargando.value = false
  }
}

async function cancelar() {
  cancelando.value = true
  error.value = ''
  try {
    const { data } = await reservaService.cancelarPorEnlace(token)
    reserva.value = data
    cancelada.value = true
    confirmando.value = false
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cancelar tu reserva.')
  } finally {
    cancelando.value = false
  }
}

// ---- Fase 6, tarea 1 (RF-M05-030, RF-M06-008): pagar o reintentar el depósito desde aquí ----
const { pagos: configPagos, disponible: enLineaDisponible } = usePagosEnLinea()
const pagando = ref(false)
const errorPago = ref('')
const recienEnviado = ref(false)
// Ya mandó el pago (o la captura) y el personal aún no lo revisa.
const depositoEnRevision = computed(
  () =>
    recienEnviado.value ||
    (reserva.value?.estado === 'pendiente_pago' && reserva.value.deposito_estado === 'pendiente' && !reserva.value.puede_pagar),
)

async function pagarDeposito(datosPago: PagoEnLineaPayload) {
  pagando.value = true
  errorPago.value = ''
  try {
    const { data } = await reservaService.pagarDepositoPorEnlace(token, datosPago)
    if (irAlCheckout(data.checkout)) return // vuelve por /pago/retorno
    reserva.value = data
    recienEnviado.value = true // modo manual: lo aprueba el personal
    pagando.value = false
  } catch (e) {
    errorPago.value = Object.values(erroresDeValidacion(e))[0] ?? mensajeDeError(e, 'No pudimos iniciar el pago del depósito.')
    pagando.value = false
  }
}

const fechaTexto = computed(() =>
  reserva.value
    ? new Date(reserva.value.fecha_hora).toLocaleString('es-CO', {
        timeZone: 'America/Bogota',
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        hour: 'numeric',
        minute: '2-digit',
      })
    : '',
)

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

onMounted(cargar)
</script>

<template>
  <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
    <p v-if="cargando" class="text-sm text-[#6c655c]">Cargando tu reserva…</p>

    <div v-else-if="noEncontrada" class="rounded-2xl border border-[#e2dbcf] bg-white p-8 text-center">
      <XCircle :size="40" class="mx-auto text-[#c8c1b2]" />
      <h1 class="mt-4 font-display text-2xl text-ink">No encontramos esa reserva</h1>
      <p class="mt-2 text-sm text-[#6c655c]">Revisa que el enlace esté completo, o comunícate con el local.</p>
    </div>

    <template v-else-if="reserva">
      <div class="flex items-center gap-3">
        <span class="flex size-11 items-center justify-center rounded-xl bg-brand-500/10 text-brand-700">
          <CalendarClock :size="20" />
        </span>
        <div>
          <p class="text-[11px] font-medium tracking-[2px] text-brand-700">TU RESERVA</p>
          <h1 class="font-display text-3xl font-medium tracking-tight text-ink">{{ reserva.cliente_nombre }}</h1>
        </div>
      </div>

      <section class="mt-6 space-y-2 rounded-2xl border border-[#e2dbcf] bg-white p-5 text-sm text-ink">
        <p><span class="text-[#6c655c]">Estado:</span> <strong>{{ ESTADOS[reserva.estado] }}</strong></p>
        <p class="first-letter:uppercase"><span class="text-[#6c655c]">Fecha:</span> {{ fechaTexto }}</p>
        <p v-if="reserva.mesa"><span class="text-[#6c655c]">Mesa:</span> {{ reserva.mesa }}</p>
        <p v-if="reserva.descripcion" class="whitespace-pre-line"><span class="text-[#6c655c]">Tu descripción:</span> {{ reserva.descripcion }}</p>
        <p>
          <span class="text-[#6c655c]">Depósito:</span> {{ formatoPrecio(reserva.deposito_valor) }}
          <template v-if="reserva.deposito_reembolsado"> — se te devuelve completo</template>
          <template v-else-if="reserva.estado === 'no_show' && reserva.deposito_pagado"> — retenido por no asistir</template>
          <template v-else-if="reserva.deposito_pagado"> — pagado; se descuenta de tu cuenta el día de tu visita</template>
          <template v-else> — sin pagar</template>
        </p>
        <p v-if="reserva.estado === 'confirmada'" class="text-xs text-[#6c655c]">
          Te esperamos hasta {{ reserva.tolerancia_minutos }} minutos después de la hora; si no llegas, la reserva se
          libera y el depósito no se devuelve.
        </p>
      </section>

      <p v-if="cancelada" class="mt-4 flex items-center gap-2 rounded-xl bg-green-50 px-4 py-3 text-sm text-green-700">
        <CheckCircle2 :size="16" />
        Tu reserva quedó cancelada. Te enviamos la confirmación por correo.
      </p>
      <p v-if="error" class="mt-4 alert-error">{{ error }}</p>

      <!-- Fase 6, tarea 1: el depósito se paga (o se reintenta) desde el enlace. -->
      <section
        v-if="reserva.puede_pagar && !depositoEnRevision && enLineaDisponible"
        class="mt-6 rounded-2xl border border-[#e2dbcf] bg-white p-5"
      >
        <h2 class="font-display text-lg text-ink">Pagar el depósito</h2>
        <p v-if="reserva.deposito_estado === 'rechazado'" class="mt-2 alert-error">
          El pago anterior no se aprobó<template v-if="reserva.deposito_rechazo_motivo">: {{ reserva.deposito_rechazo_motivo }}</template>.
          Revisa los datos y vuelve a intentarlo.
        </p>
        <AvisoDestacado class="mt-2">
          <template v-if="reserva.deposito_estado === 'pendiente'">Si no terminaste el pago anterior, puedes intentarlo de nuevo. </template>
          Tu reserva se confirma en cuanto el pago quede aprobado.
        </AvisoDestacado>
        <PagoEnLinea
          class="mt-4"
          :config="configPagos"
          :monto="Number(reserva.deposito_valor)"
          :enviando="pagando"
          :error="errorPago"
          :medio-inicial="reserva.deposito_pasarela"
          concepto="el depósito de"
          @pagar="pagarDeposito"
        />
      </section>
      <p v-else-if="depositoEnRevision" class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <template v-if="reserva.deposito_tiene_comprobante">Recibimos tu comprobante.</template>
        <template v-else>Registramos tu pago.</template>
        En cuanto lo verifiquemos, confirmamos tu reserva y te avisamos por correo.
      </p>

      <div v-if="reserva.puede_cancelar" class="mt-6">
        <BaseButton v-if="!confirmando" variant="danger-soft" @click="confirmando = true">Cancelar mi reserva</BaseButton>
        <div v-else class="rounded-2xl border border-red-200 bg-red-50 p-4">
          <p class="text-sm text-red-800">
            ¿Seguro que quieres cancelar tu reserva?
            <template v-if="reserva.deposito_pagado">Te devolvemos el depósito completo.</template>
          </p>
          <div class="mt-3 flex flex-col-reverse gap-2 sm:flex-row">
            <BaseButton variant="ghost" :disabled="cancelando" @click="confirmando = false">No, mantenerla</BaseButton>
            <BaseButton variant="danger" :disabled="cancelando" @click="cancelar">
              {{ cancelando ? 'Cancelando…' : 'Sí, cancelar' }}
            </BaseButton>
          </div>
        </div>
      </div>
      <p v-else-if="reserva.motivo_no_cancela && !cancelada && ['pendiente_pago', 'confirmada'].includes(reserva.estado)" class="mt-4 text-sm text-[#6c655c]">
        {{ reserva.motivo_no_cancela }}
      </p>
    </template>

    <p v-else-if="error" class="alert-error">{{ error }}</p>
  </div>
</template>
