<script setup lang="ts">
// M05/M07 — Administrador > Configuración: valor fijo de domicilio (RF-M07-030, RN-M07-02),
// valor fijo del depósito de reserva (RF-M05-028, RN-M05-14) y, desde la Fase 4, los tiempos de
// las reservas: tolerancia, recordatorio y ventana de mesa Reservada (RF-M05-017/026). El Administrador puede cambiarlos
// "en cualquier momento"; el nuevo valor aplica a los pedidos/reservas creados después del
// cambio y el histórico no se altera (precio congelado, ADR-002).
// Lee con GET /configuracion (incluye el valor de respaldo si la tabla aún no tiene la fila) y
// guarda con PUT /configuraciones. Desde el 27/10/2026 también la hora de cierre de la caja y la
// tolerancia antes de que se cierre sola (RF-M06-039).
import { computed, onMounted, ref } from 'vue'
import { Bike, CalendarClock, LockKeyhole, Timer } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseInput from '@/components/common/BaseInput.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import EstadoTareasAutomaticas from '@/components/configuracion/EstadoTareasAutomaticas.vue'
import RevisionProduccion from '@/components/configuracion/RevisionProduccion.vue'
import DatosNegocio from '@/components/configuracion/DatosNegocio.vue'
import PagosDirectos from '@/components/configuracion/PagosDirectos.vue'
import { configuracionService, RANGO_TOLERANCIA_CAJA, RANGOS_TIEMPOS_RESERVA } from '@/services/configuracion.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'

const domicilio = ref('')
const deposito = ref('')
// Fase 4, tarea 2 (RF-M05-017/026, D6): tiempos de las reservas, en minutos.
const tolerancia = ref('15')
const recordatorio = ref('120')
const ventana = ref('60')
// 27/10/2026 (RF-M06-039): cierre automático de la caja.
const horaCierre = ref('23:30')
const toleranciaCaja = ref('60')
// Valores guardados, para saber si hay cambios sin guardar.
const guardados = ref({ domicilio: '', deposito: '', tolerancia: '', recordatorio: '', ventana: '', horaCierre: '', toleranciaCaja: '' })

function actuales() {
  return {
    domicilio: domicilio.value, deposito: deposito.value, tolerancia: tolerancia.value, recordatorio: recordatorio.value,
    ventana: ventana.value, horaCierre: horaCierre.value, toleranciaCaja: toleranciaCaja.value,
  }
}

// "23:30" + 60 → "12:30 a. m." (la hora en que la caja se cierra sola).
const cierreSolaTexto = computed(() => {
  const coincide = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(horaCierre.value)
  const extra = Number(toleranciaCaja.value)
  if (!coincide || !/^\d+$/.test(toleranciaCaja.value)) return ''
  const total = (Number(coincide[1]) * 60 + Number(coincide[2]) + extra) % (24 * 60)
  const h = Math.floor(total / 60)
  const m = String(total % 60).padStart(2, '0')
  return `${h % 12 || 12}:${m} ${h < 12 ? 'a. m.' : 'p. m.'}`
})

// "120" → "2 horas"; "90" → "1 h 30 min"; "45" → "45 minutos".
function duracionTexto(texto: string): string {
  const minutos = Number(texto)
  if (!Number.isInteger(minutos) || minutos <= 0) return ''
  const horas = Math.floor(minutos / 60)
  const resto = minutos % 60
  if (horas === 0) return `${minutos} minutos`
  if (resto === 0) return horas === 1 ? '1 hora' : `${horas} horas`
  return `${horas} h ${resto} min`
}

const cargando = ref(true)
const guardando = ref(false)
const error = ref('')
const exito = ref('')
const errores = ref<Record<string, string>>({})

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function aNumero(texto: string): number {
  // Acepta "2000", "2.000" y "2 000"; descarta cualquier otro carácter.
  return Number(texto.replace(/[^\d]/g, ''))
}

const hayCambios = computed(() => JSON.stringify(actuales()) !== JSON.stringify(guardados.value))

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await configuracionService.obtener()
    domicilio.value = String(Math.round(Number(data.valor_domicilio)))
    deposito.value = String(Math.round(Number(data.deposito_reserva)))
    tolerancia.value = String(data.reserva_tolerancia_minutos ?? 15)
    recordatorio.value = String(data.reserva_recordatorio_minutos ?? 120)
    ventana.value = String(data.reserva_ventana_reservada_minutos ?? 60)
    horaCierre.value = data.caja_hora_cierre ?? '23:30'
    toleranciaCaja.value = String(data.caja_tolerancia_minutos ?? 60)
    guardados.value = actuales()
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar la configuración.')
  } finally {
    cargando.value = false
  }
}

function validar(): boolean {
  errores.value = {}
  for (const [campo, texto] of [
    ['valor_domicilio', domicilio.value],
    ['deposito_reserva', deposito.value],
  ] as const) {
    if (!texto.trim() || !/^\d[\d.\s]*$/.test(texto.trim())) {
      errores.value[campo] = 'Escribe un valor en pesos (solo números, 0 o más).'
    }
  }
  for (const [campo, texto] of [
    ['reserva_tolerancia_minutos', tolerancia.value],
    ['reserva_recordatorio_minutos', recordatorio.value],
    ['reserva_ventana_reservada_minutos', ventana.value],
  ] as const) {
    const [minimo, maximo] = RANGOS_TIEMPOS_RESERVA[campo]
    const minutos = Number(texto.trim())
    if (!/^\d+$/.test(texto.trim()) || minutos < minimo || minutos > maximo) {
      errores.value[campo] = `Escribe minutos enteros, entre ${minimo} y ${maximo}.`
    }
  }
  if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(horaCierre.value)) {
    errores.value.caja_hora_cierre = 'Escoge la hora de cierre.'
  }
  const [minimo, maximo] = RANGO_TOLERANCIA_CAJA
  const minutosCaja = Number(toleranciaCaja.value.trim())
  if (!/^\d+$/.test(toleranciaCaja.value.trim()) || minutosCaja < minimo || minutosCaja > maximo) {
    errores.value.caja_tolerancia_minutos = `Escribe minutos enteros, entre ${minimo} y ${maximo}.`
  }
  return Object.keys(errores.value).length === 0
}

async function guardar() {
  exito.value = ''
  error.value = ''
  if (!validar()) return
  guardando.value = true
  try {
    await configuracionService.actualizar({
      valor_domicilio: aNumero(domicilio.value),
      deposito_reserva: aNumero(deposito.value),
      reserva_tolerancia_minutos: Number(tolerancia.value),
      reserva_recordatorio_minutos: Number(recordatorio.value),
      reserva_ventana_reservada_minutos: Number(ventana.value),
      caja_hora_cierre: horaCierre.value,
      caja_tolerancia_minutos: Number(toleranciaCaja.value),
    })
    domicilio.value = String(aNumero(domicilio.value))
    deposito.value = String(aNumero(deposito.value))
    guardados.value = actuales()
    exito.value = 'Configuración guardada. Los valores en pesos aplican a los pedidos y reservas nuevos; los tiempos de reserva y de la caja, desde ya.'
  } catch (e) {
    errores.value = erroresDeValidacion(e)
    // Si el servidor señaló campos concretos, el mensaje va bajo cada campo; el general solo
    // aparece para otros errores (red, sesión vencida, 403…).
    if (Object.keys(errores.value).length === 0) error.value = mensajeDeError(e, 'No pudimos guardar la configuración.')
  } finally {
    guardando.value = false
  }
}

onMounted(cargar)
</script>

<template>
  <div>
    <PageHeader
      eyebrow="SISTEMA"
      titulo="Configuración"
      descripcion="Valores que el negocio puede cambiar en cualquier momento."
    />

    <div v-if="cargando" class="loading-state">Cargando configuración…</div>

    <!-- Mejora (19/10/2026): dirección, teléfono y logo del negocio (se guardan aparte). -->
    <div v-if="!cargando" class="mt-6 max-w-2xl">
      <DatosNegocio />
    </div>

    <!-- Pago directo (22/10/2026): cuentas de Nequi, Bancolombia y Bre-B (las cambia el SuperAdmin). -->
    <div v-if="!cargando" class="mt-4 max-w-2xl">
      <PagosDirectos />
    </div>

    <form v-if="!cargando" class="mt-4 max-w-2xl space-y-4" novalidate @submit.prevent="guardar">
      <div class="card p-4 sm:p-5">
        <div class="flex items-start gap-3">
          <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-700">
            <Bike :size="21" />
          </span>
          <div class="min-w-0 flex-1">
            <h2 class="text-[15px] font-semibold text-ink">Valor del domicilio</h2>
            <p class="mt-0.5 text-xs text-gray-500">
              Valor fijo igual para toda la ciudad, sin zonas ni tarifas por barrio. La recogida en el
              local no tiene costo.
            </p>
            <div class="mt-3">
              <BaseInput formato="pesos"
                v-model="domicilio"
                label="Valor en pesos (COP)"
                type="text"
                placeholder="2000"
                :error="errores.valor_domicilio"
              />
              <!-- El error ya lo muestra BaseInput debajo del campo. -->
              <p v-if="!errores.valor_domicilio && domicilio && !Number.isNaN(aNumero(domicilio))" class="mt-1 text-xs text-gray-500">
                Se cobrará {{ formatoPrecio(aNumero(domicilio)) }} por cada domicilio.
              </p>
            </div>
          </div>
        </div>
      </div>

      <div class="card p-4 sm:p-5">
        <div class="flex items-start gap-3">
          <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
            <CalendarClock :size="21" />
          </span>
          <div class="min-w-0 flex-1">
            <h2 class="text-[15px] font-semibold text-ink">Depósito de reserva</h2>
            <p class="mt-0.5 text-xs text-gray-500">
              Valor fijo obligatorio para toda reserva, sin importar el número de personas. Se reembolsa
              si se cancela a tiempo, se retiene si hay no-show y se abona al pedido si el cliente llega.
            </p>
            <div class="mt-3">
              <BaseInput formato="pesos"
                v-model="deposito"
                label="Valor en pesos (COP)"
                type="text"
                placeholder="20000"
                :error="errores.deposito_reserva"
              />
              <!-- El error ya lo muestra BaseInput debajo del campo. -->
              <p v-if="!errores.deposito_reserva && deposito && !Number.isNaN(aNumero(deposito))" class="mt-1 text-xs text-gray-500">
                Cada reserva exigirá {{ formatoPrecio(aNumero(deposito)) }} de depósito.
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Fase 4, tarea 2 (RF-M05-017/026, RN-M05-07/08/13, decisión D6) -->
      <div class="card p-4 sm:p-5">
        <div class="flex items-start gap-3">
          <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
            <Timer :size="21" />
          </span>
          <div class="min-w-0 flex-1">
            <h2 class="text-[15px] font-semibold text-ink">Tiempos de las reservas</h2>
            <p class="mt-0.5 text-xs text-gray-500">
              En minutos. Los usan el no-show automático, el recordatorio al cliente y el estado de la mesa.
            </p>
            <div class="mt-3 grid gap-4 sm:grid-cols-3">
              <div>
                <BaseInput formato="entero" :maxlength="4" v-model="tolerancia" label="Tolerancia (no-show)" type="text" placeholder="15" :error="errores.reserva_tolerancia_minutos" />
                <p v-if="!errores.reserva_tolerancia_minutos" class="mt-1 text-xs text-gray-500">
                  Si el cliente no llega {{ duracionTexto(tolerancia) || '…' }} después de la hora, la reserva se libera y el depósito se retiene.
                </p>
              </div>
              <div>
                <BaseInput formato="entero" :maxlength="4" v-model="recordatorio" label="Recordatorio" type="text" placeholder="120" :error="errores.reserva_recordatorio_minutos" />
                <p v-if="!errores.reserva_recordatorio_minutos" class="mt-1 text-xs text-gray-500">Se avisa al cliente {{ duracionTexto(recordatorio) || '…' }} antes.</p>
              </div>
              <div>
                <BaseInput formato="entero" :maxlength="4" v-model="ventana" label="Mesa Reservada" type="text" placeholder="60" :error="errores.reserva_ventana_reservada_minutos" />
                <p v-if="!errores.reserva_ventana_reservada_minutos" class="mt-1 text-xs text-gray-500">La mesa se ve Reservada desde {{ duracionTexto(ventana) || '…' }} antes.</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 27/10/2026 (RF-M06-039, RN-M06-22): la caja que nadie cerró se cierra sola. -->
      <div class="card p-4 sm:p-5">
        <div class="flex items-start gap-3">
          <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
            <LockKeyhole :size="21" />
          </span>
          <div class="min-w-0 flex-1">
            <h2 class="text-[15px] font-semibold text-ink">Cierre automático de la caja</h2>
            <p class="mt-0.5 text-xs text-gray-500">
              Si nadie cierra el turno, se cierra solo pasada la tolerancia, sin contar el efectivo. Después registras
              lo que contaste en Caja. Los pedidos que queden abiertos se avisan en la campana y se cobran con la caja
              del día siguiente.
            </p>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
              <div>
                <BaseInput v-model="horaCierre" label="Hora de cierre del negocio" type="time" :error="errores.caja_hora_cierre" />
                <p v-if="!errores.caja_hora_cierre" class="mt-1 text-xs text-gray-500">Antes de las 6:00 a. m. cuenta como la madrugada siguiente.</p>
              </div>
              <div>
                <BaseInput formato="entero" :maxlength="3" v-model="toleranciaCaja" label="Tolerancia (minutos)" type="text" placeholder="60" :error="errores.caja_tolerancia_minutos" />
                <p v-if="!errores.caja_tolerancia_minutos && cierreSolaTexto" class="mt-1 text-xs text-gray-500">
                  La caja se cierra sola a las {{ cierreSolaTexto }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <p v-if="error" class="alert-error">{{ error }}</p>
      <p v-if="exito" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ exito }}</p>

      <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-xs text-gray-500">
          Los pedidos y reservas ya creados conservan el valor con que se hicieron.
        </p>
        <BaseButton v-escritura type="submit" variant="primary" :disabled="guardando || !hayCambios">
          {{ guardando ? 'Guardando…' : 'Guardar cambios' }}
        </BaseButton>
      </div>
    </form>

    <!-- Fase 4, tarea 1: estado del programador y de la cola en el servidor. -->
    <div v-if="!cargando" class="mt-8 max-w-2xl">
      <EstadoTareasAutomaticas />
    </div>

    <!-- Endurecimiento (13/10/2026): revisión de seguridad antes de abrir al público. -->
    <div v-if="!cargando" class="mt-6 max-w-2xl">
      <RevisionProduccion />
    </div>
  </div>
</template>
