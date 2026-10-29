<script setup lang="ts">
// Fase 1, tarea 6 — Seguimiento del pedido para el invitado (RF-M07-027, RF-M07-R-015, HU-M07-002/
// 011). El invitado no tiene cuenta: entra con el enlace privado que recibió al crear su pedido
// (/seguimiento/<token>). Ve el estado, los productos, el total y lo pagado; mientras el pedido
// está PENDIENTE puede pagarlo en línea o reintentar el pago (RF-M06-005/008) y cancelarlo
// (RF-M07-016, RF-M07-R-009). La página se actualiza sola cada 10 segundos (26/10/2026, RF-M07-036).
// Pago directo (22/10/2026, RF-M06-030 a 033): sin Wompi, paga transfiriendo y subiendo la captura;
// mientras se revisa lo dice, y si se rechaza ve el motivo y puede enviarla de nuevo.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { Bike, ShoppingBag, XCircle, CircleX } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import AvisoDestacado from '@/components/common/AvisoDestacado.vue'
import AvancePedido from '@/components/pedidos/AvancePedido.vue'
import BotonComprobante from '@/components/pedidos/BotonComprobante.vue'
import PagoEnLinea from '@/components/pagos/PagoEnLinea.vue'
import { usePagosEnLinea } from '@/composables/usePagosEnLinea'
import TotalesPedido from '@/components/pedidos/TotalesPedido.vue'
import { pedidoService, type PagoEnLineaPayload } from '@/services/pedido.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { irAlCheckout } from '@/utils/pasarela'
import type { Pedido } from '@/types/models'

const route = useRoute()
const token = String(route.params.token ?? '')

const pedido = ref<Pedido | null>(null)
const cargando = ref(true)
const noEncontrado = ref(false)
const error = ref('')

async function cargar(silencioso = false) {
  if (!silencioso) cargando.value = true
  try {
    const { data } = await pedidoService.seguimiento(token)
    pedido.value = data
    noEncontrado.value = false
  } catch (e) {
    if ((e as { response?: { status?: number } }).response?.status === 404) noEncontrado.value = true
    else if (!silencioso) error.value = mensajeDeError(e, 'No pudimos cargar tu pedido.')
  } finally {
    cargando.value = false
  }
}

// Mejora (26/10/2026, RF-M07-036): el estado tardaba hasta 30 s en cambiar. Ahora se pregunta cada 10 s mientras
// la página está a la vista, y de inmediato al volver a ella (el teléfono pausa las pestañas ocultas).
const CADA_MS = 10000
let temporizador: ReturnType<typeof setInterval> | undefined
const sigueActivo = () => !!pedido.value && !['finalizado', 'cancelado'].includes(pedido.value.estado)
function alVolver() {
  if (document.visibilityState === 'visible' && sigueActivo()) cargar(true)
}
onMounted(() => {
  cargar()
  temporizador = setInterval(() => {
    if (document.visibilityState === 'visible' && sigueActivo()) cargar(true)
  }, CADA_MS)
  document.addEventListener('visibilitychange', alVolver)
  window.addEventListener('focus', alVolver)
})
onBeforeUnmount(() => {
  clearInterval(temporizador)
  document.removeEventListener('visibilitychange', alVolver)
  window.removeEventListener('focus', alVolver)
})

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

const pagado = computed(() =>
  (pedido.value?.pagos ?? []).filter((x) => x.estado === 'aprobado' && !x.anulado).reduce((a, x) => a + Number(x.monto), 0),
)
const pagoEnRevision = computed(() =>
  (pedido.value?.pagos ?? []).some((x) => x.metodo === 'en_linea' && x.estado === 'pendiente' && !x.anulado),
)
// Fase 6, tarea 1: un pago que se fue a la pasarela y no se terminó se puede volver a intentar
// (el servidor pregunta antes por el anterior; si sigue en curso, lo dice). En modo manual, no.
const intentoEnPasarela = computed(() =>
  (pedido.value?.pagos ?? []).some((x) => x.metodo === 'en_linea' && x.estado === 'pendiente' && !x.anulado && x.transaccion_pasarela),
)
// Pago directo: el último pago en línea, para decir si se está revisando la captura o por qué se rechazó.
const ultimoEnLinea = computed(() =>
  (pedido.value?.pagos ?? []).filter((x) => x.metodo === 'en_linea' && !x.anulado).sort((a, b) => b.id - a.id)[0] ?? null,
)
const comprobanteEnRevision = computed(() => pagoEnRevision.value && !!ultimoEnLinea.value?.tiene_comprobante)
const rechazado = computed(() => (ultimoEnLinea.value?.estado === 'rechazado' ? ultimoEnLinea.value : null))
const { pagos: configPagos, disponible: enLineaDisponible } = usePagosEnLinea()

const puedePagar = computed(
  () =>
    enLineaDisponible.value &&
    pedido.value?.estado === 'pendiente' &&
    (!pagoEnRevision.value || intentoEnPasarela.value) &&
    Number(pedido.value.total) - pagado.value > 0,
)

// ---- Pago en línea (RF-M06-005/008) ----
const pagando = ref(false)
const errorPago = ref('')

async function pagar(datosPago: PagoEnLineaPayload) {
  if (!pedido.value) return
  pagando.value = true
  errorPago.value = ''
  try {
    const { data } = await pedidoService.pagarEnLineaConToken(token, datosPago)
    if (irAlCheckout(data.checkout)) return // vuelve por /pago/retorno
    await cargar(true)
  } catch (e) {
    errorPago.value = Object.values(erroresDeValidacion(e))[0] ?? mensajeDeError(e, 'No pudimos registrar tu pago.')
  } finally {
    pagando.value = false
  }
}

// ---- Cancelar mientras esté pendiente (RF-M07-016, RF-M07-R-009) ----
const cancelarAbierto = ref(false)
const motivo = ref('')
const cancelando = ref(false)
const errorCancelar = ref('')

async function cancelar() {
  if (!motivo.value.trim()) {
    errorCancelar.value = 'Cuéntanos por qué cancelas.'
    return
  }
  cancelando.value = true
  errorCancelar.value = ''
  try {
    const { data } = await pedidoService.cancelarConToken(token, motivo.value.trim())
    pedido.value = { ...pedido.value!, ...data }
    cancelarAbierto.value = false
  } catch (e) {
    errorCancelar.value = mensajeDeError(e, 'No pudimos cancelar el pedido.')
  } finally {
    cancelando.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
    <p v-if="cargando" class="text-sm text-[#6c655c]">Cargando tu pedido…</p>

    <div v-else-if="noEncontrado" class="rounded-2xl border border-[#e2dbcf] bg-white p-8 text-center">
      <XCircle :size="40" class="mx-auto text-[#c8c1b2]" />
      <h1 class="mt-4 font-display text-2xl text-ink">No encontramos ese pedido</h1>
      <p class="mt-2 text-sm text-[#6c655c]">Revisa que el enlace esté completo, o comunícate con el local.</p>
    </div>

    <p v-else-if="error" class="alert-error">{{ error }}</p>

    <template v-else-if="pedido">
      <div class="flex items-center gap-3">
        <span class="flex size-11 items-center justify-center rounded-xl bg-brand-500/10 text-brand-700">
          <Bike v-if="pedido.tipo === 'domicilio'" :size="20" />
          <ShoppingBag v-else :size="20" />
        </span>
        <div>
          <p class="text-[11px] font-medium tracking-[2px] text-brand-700">
            {{ pedido.tipo === 'domicilio' ? 'DOMICILIO' : 'RECOGIDA EN EL LOCAL' }}
          </p>
          <h1 class="font-display text-3xl font-medium tracking-tight text-ink">Pedido #{{ pedido.id }}</h1>
        </div>
      </div>

      <!-- Estado -->
      <section class="mt-6 rounded-2xl border border-[#e2dbcf] bg-white p-5">
        <AvancePedido :pedido="pedido" />
      </section>

      <!-- Productos y total -->
      <section class="mt-4 rounded-2xl border border-[#e2dbcf] bg-white p-5">
        <ul class="space-y-2 text-sm">
          <li v-for="d in pedido.detalles" :key="d.id" class="flex justify-between gap-3">
            <span class="text-ink">
              {{ d.cantidad }}× {{ d.combo ? `Combo ${d.combo.nombre}` : d.producto?.nombre }}
              <span v-if="d.variante" class="text-xs text-[#6c655c]">({{ d.variante.nombre }})</span>
            </span>
          </li>
        </ul>
        <!-- Fase 2, tarea 1: promociones o descuento aplicados al pedido. -->
        <TotalesPedido class="mt-4 border-t border-[#e2dbcf] pt-3" :pedido="pedido" tono="cliente" />
        <dl class="mt-1 space-y-1 text-sm">
          <div v-if="pagado > 0" class="flex justify-between text-[#6c655c]">
            <dt>Pagado</dt>
            <dd>{{ formatoPrecio(pagado) }}</dd>
          </div>
        </dl>
        <p v-if="pagoEnRevision && intentoEnPasarela" class="mt-3 text-xs text-[#6c655c]">
          Tu pago en línea está en proceso en la pasarela. Si pagas con Nequi, apruébalo en la app; en cuanto llegue, confirmamos tu pedido.
        </p>
        <p v-else-if="comprobanteEnRevision" class="mt-3 rounded-xl bg-[#fff7e5] px-3 py-2 text-xs text-[#7a5a12]">
          Recibimos tu comprobante. Estamos verificando la transferencia; en cuanto la confirmemos empezamos tu pedido.
        </p>
        <p v-else-if="pagoEnRevision" class="mt-3 text-xs text-[#6c655c]">Tu pago en línea está en revisión.</p>
        <p v-if="pedido.direccion_entrega" class="mt-3 text-xs text-[#6c655c]">
          Entrega en {{ pedido.direccion_entrega }}<template v-if="pedido.barrio">, {{ pedido.barrio }}</template>
        </p>
        <!-- Fase 5, tarea 1 (RF-M06-010): el comprobante en PDF, con el mismo enlace. -->
        <BotonComprobante v-if="pedido.comprobante" :pedido="pedido" :token="token" size="sm" class="mt-4" />
      </section>

      <!-- Pagar en línea -->
      <section v-if="puedePagar" class="mt-4 rounded-2xl border border-[#e2dbcf] bg-white p-5">
        <h2 class="font-display text-lg text-ink">Pagar en línea</h2>
        <p v-if="rechazado" class="mt-2 alert-error">
          Tu pago anterior no se aprobó<template v-if="rechazado.rechazo_motivo">: {{ rechazado.rechazo_motivo }}</template>.
          Revisa los datos y vuelve a enviar el comprobante.
        </p>
        <AvisoDestacado class="mt-2">
          <template v-if="intentoEnPasarela">¿No terminaste el pago? Puedes intentarlo de nuevo. </template>
          También puedes pagar {{ pedido.tipo === 'domicilio' ? 'contra entrega' : 'al recoger' }}.
        </AvisoDestacado>
        <PagoEnLinea
          class="mt-4"
          :config="configPagos"
          :monto="Number(pedido.total) - pagado"
          :enviando="pagando"
          :error="errorPago"
          :medio-inicial="pedido.pasarela_preferida"
          @pagar="pagar"
        />
      </section>

      <!-- Cancelar -->
      <!-- 21/10/2026: antes era un enlace rojo suelto; ahora una tarjeta como las demás, que dice hasta
           cuándo se puede cancelar (RN-M07-04: solo mientras el pedido sigue pendiente). -->
      <section v-if="pedido.estado === 'pendiente'" class="mt-4">
        <div
          v-if="!cancelarAbierto"
          class="flex flex-col gap-4 rounded-2xl border border-[#e2dbcf] bg-white p-5 sm:flex-row sm:items-center sm:justify-between"
        >
          <div class="flex items-start gap-3">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600" aria-hidden="true">
              <CircleX :size="20" />
            </span>
            <div>
              <p class="text-sm font-semibold text-ink">¿Cambiaste de opinión?</p>
              <p class="mt-0.5 text-xs text-[#6c655c]">
                Puedes cancelarlo mientras no lo hayamos confirmado.<template v-if="pagado > 0"> Si ya pagaste en línea, te devolvemos el dinero.</template>
              </p>
            </div>
          </div>
          <BaseButton variant="danger-soft" class="w-full shrink-0 sm:w-auto" @click="cancelarAbierto = true">
            <CircleX :size="16" />
            Cancelar mi pedido
          </BaseButton>
        </div>
        <div v-else class="rounded-2xl border border-red-200 bg-red-50/60 p-5">
          <label class="block">
            <span class="text-sm font-medium text-ink">¿Por qué cancelas?</span>
            <textarea v-formato="'texto'" v-model="motivo" rows="2" maxlength="255" class="field mt-2" />
          </label>
          <p v-if="errorCancelar" class="mt-2 text-xs text-red-600">{{ errorCancelar }}</p>
          <div class="mt-3 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <BaseButton variant="ghost" @click="cancelarAbierto = false">Volver</BaseButton>
            <BaseButton variant="danger" :disabled="cancelando" @click="cancelar">
              {{ cancelando ? 'Cancelando…' : 'Cancelar pedido' }}
            </BaseButton>
          </div>
        </div>
      </section>

      <AvisoDestacado tono="atencion" class="mt-8">Guarda este enlace: es la única forma de ver tu pedido sin cuenta.</AvisoDestacado>
    </template>
  </div>
</template>
