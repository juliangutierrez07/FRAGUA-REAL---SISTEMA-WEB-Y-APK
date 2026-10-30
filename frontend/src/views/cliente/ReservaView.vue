<script setup lang="ts">
// M05 — Reservar mesa con depósito obligatorio (RN-M05-14 a 19). Dos pasos:
// 1) crear la reserva (queda "pendiente_pago"); 2) pagar el depósito en línea
// (Nequi/Bancolombia). El pago en línea hoy lo confirma el Mesero/Administrador
// manualmente (no hay webhook real todavía, ver README-BACKEND.md) — por eso el
// paso 2 termina en "en revisión", no en "confirmada" al instante.
// Fase 1, tarea 4: al elegir fecha y hora, la lista de mesas se reduce a las que no tienen otra
// reserva a esa hora (GET /mesas/disponibles); el servidor vuelve a validarlo al crear.
// Pago directo (22/10/2026, RF-M06-030): sin Wompi, el depósito se paga transfiriendo a las cuentas
// del negocio y subiendo la captura; el personal la revisa y confirma la reserva.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { CalendarClock, CheckCircle2, Clock, Mail, MessageCircle, Phone, ReceiptText, RotateCcw, User, Users } from 'lucide-vue-next'
import { HORARIO_POR_DEFECTO, fechaMinimaReserva, horasDelDia, textoHorario, type HorarioReservas } from '@/utils/horarioReservas'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseInput from '@/components/common/BaseInput.vue'
import AvisoDestacado from '@/components/common/AvisoDestacado.vue'
import PagoEnLinea from '@/components/pagos/PagoEnLinea.vue'
import { usePagosEnLinea } from '@/composables/usePagosEnLinea'
import { useAuthStore } from '@/stores/auth'
import { useNegocioStore } from '@/stores/negocio'
import { mesaService } from '@/services/mesa.service'
import { configuracionService } from '@/services/configuracion.service'
import { reservaService } from '@/services/reserva.service'
import type { PagoEnLineaPayload } from '@/services/pedido.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { irAlCheckout } from '@/utils/pasarela'
import type { Mesa, Reserva } from '@/types/models'

const auth = useAuthStore()

const mesas = ref<Mesa[]>([])
const depositoValor = ref(20000)
// Fase 4, tarea 2 (RN-M05-07/08/18): minutos de espera antes de liberar la reserva.
const toleranciaMinutos = ref(15)
const cargandoMesas = ref(true)

const form = reactive({
  mesa_id: '' as number | '',
  fecha: '',
  hora: '',
  cliente_nombre: auth.usuario?.nombre ?? '',
  cliente_telefono: auth.usuario?.telefono ?? '',
  cliente_correo: auth.usuario?.email ?? '',
  descripcion: '',
})
const MAX_DESCRIPCION = 500

const { pagos: configPagos, cargado: pagosCargados, disponible: enLineaDisponible, directo } = usePagosEnLinea()

const enviando = ref(false)
const errores = ref<Record<string, string>>({})
const errorGeneral = ref('')

const reservaCreada = ref<Reserva | null>(null)
const pagandoDeposito = ref(false)
const depositoRegistrado = ref(false)
const redirigiendo = ref(false) // Fase 6: saliendo hacia el checkout de la pasarela
const errorDeposito = ref('')

// Corrección (19/10/2026): solo de 4:00 p. m. a 11:00 p. m. y con al menos 3 horas de anticipación
// (los valores llegan del servidor, que vuelve a validarlo al crear). Si hoy ya no queda ninguna
// hora, el primer día que se ofrece es mañana.
const horario = ref<HorarioReservas>({ ...HORARIO_POR_DEFECTO })
const reloj = ref(Date.now())
const fechaMinima = computed(() => fechaMinimaReserva(horario.value, reloj.value))
const horas = computed(() => horasDelDia(form.fecha, horario.value, reloj.value))
watch(horas, (lista) => {
  // Si la hora elegida deja de servir (cambió el día o pasó el tiempo), se quita.
  if (form.hora && !lista.some((h) => h.valor === form.hora && h.disponible)) form.hora = ''
})

onMounted(async () => {
  try {
    const [{ data: listaMesas }, { data: config }] = await Promise.all([
      mesaService.index(),
      configuracionService.obtener(),
    ])
    mesas.value = listaMesas
    depositoValor.value = config.deposito_reserva
    toleranciaMinutos.value = config.reserva_tolerancia_minutos ?? 15
    horario.value = {
      inicio: config.reserva_hora_inicio ?? HORARIO_POR_DEFECTO.inicio,
      fin: config.reserva_hora_fin ?? HORARIO_POR_DEFECTO.fin,
      anticipacionMinutos: config.reserva_anticipacion_minutos ?? HORARIO_POR_DEFECTO.anticipacionMinutos,
    }
  } catch {
    errorGeneral.value = 'No pudimos cargar las mesas disponibles. Recarga la página.'
  } finally {
    cargandoMesas.value = false
  }
})

// Con fecha y hora elegidas, solo las mesas sin otra reserva que se cruce.
const filtradasPorHora = ref(false)
watch(
  () => [form.fecha, form.hora],
  async ([fecha, hora]) => {
    if (!fecha || !hora) return
    cargandoMesas.value = true
    try {
      const { data } = await reservaService.mesasDisponibles(`${fecha} ${hora}`)
      mesas.value = data
      filtradasPorHora.value = true
      if (form.mesa_id !== '' && !data.some((m) => m.id === form.mesa_id)) form.mesa_id = ''
    } catch {
      // Si falla, queda la lista anterior; el servidor rechaza la reserva si la mesa ya está tomada.
    } finally {
      cargandoMesas.value = false
    }
  },
)

// Mejora de diseño (16/10/2026): la mesa se elige con botones y el resumen usa su nombre.
const mesaElegida = computed(() => mesas.value.find((m) => m.id === form.mesa_id) ?? null)
// En orden natural: Mesa 1, Mesa 2… Mesa 10 (el servidor no las manda ordenadas).
const mesasOrdenadas = computed(() =>
  [...mesas.value].sort((x, y) => x.nombre.localeCompare(y.nombre, 'es', { numeric: true })),
)
const fechaHoraLegible = computed(() => {
  if (!form.fecha || !form.hora) return ''
  const texto = new Date(`${form.fecha}T${form.hora}`).toLocaleString('es-CO', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    hour: 'numeric',
    minute: '2-digit',
  })
  return texto.charAt(0).toUpperCase() + texto.slice(1)
})
// Mejora (19/10/2026): el WhatsApp del negocio sale de Configuración.
const negocio = useNegocioStore()
negocio.cargar()
const whatsappUrl = computed(() => negocio.whatsappUrl)

function elegirMesa(id: number) {
  form.mesa_id = id
  if (errores.value.mesa_id) errores.value = { ...errores.value, mesa_id: '' }
}

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

async function confirmarReserva() {
  errores.value = {}
  errorGeneral.value = ''
  reloj.value = Date.now()
  if (!form.hora) {
    errores.value = { fecha_hora: 'Elige una hora.' }
    return
  }
  // Con botones ya no hay un <select required>: la mesa se valida aquí antes de enviar.
  if (form.mesa_id === '') {
    errores.value = { mesa_id: 'Elige una mesa.' }
    return
  }
  enviando.value = true

  try {
    const { data } = await reservaService.crear({
      mesa_id: Number(form.mesa_id),
      cliente_nombre: form.cliente_nombre,
      cliente_telefono: form.cliente_telefono,
      cliente_correo: form.cliente_correo,
      fecha_hora: `${form.fecha}T${form.hora}`,
      descripcion: form.descripcion.trim() || null,
    })
    reservaCreada.value = data
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) {
      errores.value = campos
    } else {
      errorGeneral.value = mensajeDeError(error, 'No pudimos crear la reserva. Intenta de nuevo.')
    }
  } finally {
    enviando.value = false
  }
}

async function pagarDeposito(datosPago: PagoEnLineaPayload) {
  if (!reservaCreada.value) return
  errorDeposito.value = ''
  pagandoDeposito.value = true
  try {
    // Fase 6, tarea 1 (RF-M05-030): con el enlace secreto que devolvió la creación de la reserva.
    const { data } = await reservaService.registrarDepositoEnLinea(reservaCreada.value.id, {
      ...datosPago,
      token: reservaCreada.value.token_publico ?? '',
    })
    // Con la pasarela activa se paga en Wompi y se vuelve por /pago/retorno.
    if (irAlCheckout(data.checkout)) {
      redirigiendo.value = true
      return
    }
    depositoRegistrado.value = true
  } catch (error) {
    errorDeposito.value =
      Object.values(erroresDeValidacion(error))[0] ?? mensajeDeError(error, 'No pudimos registrar el pago del depósito. Intenta de nuevo.')
  } finally {
    if (!redirigiendo.value) pagandoDeposito.value = false
  }
}
</script>

<template>
  <!-- Mejora de diseño (16/10/2026): pasos numerados (cuándo, mesa, datos), mesas como botones, un
       panel con el depósito y las condiciones, y altura mínima para que el pie de página no suba
       mientras cargan las mesas. -->
  <div class="mx-auto min-h-[75dvh] max-w-6xl px-4 pb-16 pt-10 sm:px-6">
    <div class="mb-8 flex items-center gap-3">
      <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
      <span class="text-[11px] font-medium tracking-[2px] text-brand-700">RESERVA TU MESA</span>
    </div>
    <h1 class="font-display text-4xl font-medium tracking-tight text-ink sm:text-5xl">Reservar mesa</h1>
    <p class="mt-3 max-w-2xl text-[15px] text-[#6c655c]">
      Elige el día, la hora y tu mesa. Te la guardamos con un depósito que se descuenta de tu cuenta el día de tu visita.
    </p>

    <div class="mt-8 grid items-start gap-6 lg:grid-cols-[1fr_340px]">
      <!-- Confirmación / pago del depósito -->
      <div v-if="reservaCreada" class="rounded-2xl border border-[#e2dbcf] bg-white p-6 sm:p-8">
        <template v-if="!depositoRegistrado">
          <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
            <CalendarClock :size="24" />
          </span>
          <h2 class="mt-4 font-display text-2xl text-ink">Reserva #{{ reservaCreada.id }} creada</h2>
          <p class="mt-1 text-sm text-[#6c655c]">Falta un paso: paga el depósito para confirmarla.</p>

          <dl class="mt-5 grid gap-3 rounded-xl bg-[#fbf8f2] p-4 text-sm sm:grid-cols-3">
            <div>
              <dt class="text-[11px] uppercase tracking-[0.08em] text-[#7d756b]">Mesa</dt>
              <dd class="mt-0.5 font-medium text-ink">{{ mesaElegida?.nombre ?? '—' }}</dd>
            </div>
            <div class="sm:col-span-2">
              <dt class="text-[11px] uppercase tracking-[0.08em] text-[#7d756b]">Cuándo</dt>
              <dd class="mt-0.5 font-medium text-ink">{{ fechaHoraLegible }}</dd>
            </div>
            <div v-if="reservaCreada.descripcion" class="sm:col-span-3">
              <dt class="text-[11px] uppercase tracking-[0.08em] text-[#7d756b]">Tu descripción</dt>
              <dd class="mt-0.5 whitespace-pre-line text-ink">{{ reservaCreada.descripcion }}</dd>
            </div>
          </dl>

          <PagoEnLinea
            v-if="enLineaDisponible"
            class="mt-6"
            :config="configPagos"
            :monto="Number(reservaCreada.deposito_valor)"
            :enviando="pagandoDeposito || redirigiendo"
            :error="errorDeposito"
            concepto="el depósito de"
            @pagar="pagarDeposito"
          />
          <p v-else-if="pagosCargados" class="mt-6 rounded-xl bg-[#fff7e5] px-4 py-3 text-sm text-[#7a5a12]">
            Por ahora el depósito no se puede pagar en línea. Escríbenos por WhatsApp y te decimos cómo pagarlo; con
            el enlace que te llegó al correo puedes volver a esta reserva.
          </p>
        </template>

        <template v-else>
          <span class="flex size-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700">
            <CheckCircle2 :size="26" />
          </span>
          <h2 class="mt-4 font-display text-2xl text-ink">
            {{ directo ? '¡Ya casi! Recibimos tu comprobante' : '¡Ya casi! Tu depósito quedó registrado' }}
          </h2>
          <p class="mt-2 text-sm text-[#6c655c]">
            {{ directo ? 'En cuanto verifiquemos la transferencia' : 'En breve confirmamos el pago y' }} tu reserva pasa a
            «Confirmada». Te llega un correo con el enlace para verla o cancelarla.
          </p>
          <div class="mt-6 flex flex-col gap-3 sm:flex-row">
            <router-link
              :to="{ name: 'home' }"
              class="inline-flex items-center justify-center rounded-xl border border-[#e3ded4] px-6 py-3 text-sm font-medium text-ink transition hover:bg-[#fbfaf7]"
            >
              Volver al inicio
            </router-link>
            <a
              :href="whatsappUrl"
              target="_blank"
              rel="noopener"
              class="inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-medium text-brand-700 transition hover:bg-brand-50"
            >
              <MessageCircle :size="16" />
              Escríbenos por WhatsApp
            </a>
          </div>
        </template>
      </div>

      <!-- Formulario -->
      <form v-else class="space-y-5" @submit.prevent="confirmarReserva">
        <!-- 1. Cuándo -->
        <section class="rounded-2xl border border-[#e2dbcf] bg-white p-5 sm:p-6">
          <h2 class="flex items-center gap-3 font-display text-xl text-ink">
            <span class="flex size-7 items-center justify-center rounded-full bg-ink font-sans text-xs font-semibold text-brand-400">1</span>
            ¿Cuándo vienes?
          </h2>
          <div class="mt-4 grid grid-cols-2 gap-3">
            <label class="block">
              <span class="mb-1.5 block text-[11px] font-medium uppercase tracking-[0.08em] text-[#6c655c]">Fecha</span>
              <input
                v-model="form.fecha"
                type="date"
                required
                :min="fechaMinima"
                class="w-full rounded-xl border border-[#e3ded4] bg-[#fbfaf7] px-4 py-3 text-sm text-ink transition focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-brand-500/15"
                :class="{ '!border-red-400 !ring-red-100': errores.fecha_hora }"
              />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-[11px] font-medium uppercase tracking-[0.08em] text-[#6c655c]">Hora</span>
              <select
                v-model="form.hora"
                required
                :disabled="!form.fecha"
                class="w-full rounded-xl border border-[#e3ded4] bg-[#fbfaf7] px-4 py-3 text-sm text-ink transition focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-brand-500/15 disabled:cursor-not-allowed disabled:opacity-60"
                :class="{ '!border-red-400 !ring-red-100': errores.fecha_hora }"
              >
                <option value="" disabled>{{ form.fecha ? 'Elige la hora' : 'Primero la fecha' }}</option>
                <option v-for="h in horas" :key="h.valor" :value="h.valor" :disabled="!h.disponible">
                  {{ h.texto }}{{ h.disponible ? '' : ' · no disponible' }}
                </option>
              </select>
            </label>
          </div>
          <span v-if="errores.fecha_hora" class="mt-1.5 block text-xs text-red-600">{{ errores.fecha_hora }}</span>
          <p v-else-if="fechaHoraLegible" class="mt-2 text-xs text-[#6c655c]">{{ fechaHoraLegible }}</p>
          <AvisoDestacado class="mt-3">{{ textoHorario(horario) }}</AvisoDestacado>
        </section>

        <!-- 2. Mesa -->
        <section class="rounded-2xl border border-[#e2dbcf] bg-white p-5 sm:p-6">
          <h2 class="flex items-center gap-3 font-display text-xl text-ink">
            <span class="flex size-7 items-center justify-center rounded-full bg-ink font-sans text-xs font-semibold text-brand-400">2</span>
            Elige tu mesa
          </h2>
          <p class="mt-1 text-xs text-[#6c655c]">
            {{ filtradasPorHora ? 'Solo se muestran las mesas libres a la hora que elegiste.' : 'Elige primero la fecha y la hora para ver solo las mesas libres.' }}
          </p>

          <div v-if="cargandoMesas" class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4" aria-hidden="true">
            <span v-for="n in 8" :key="n" class="h-12 animate-pulse rounded-xl bg-[#ece5d9]" />
          </div>
          <p v-else-if="!mesas.length" class="mt-4 rounded-xl bg-[#fbf8f2] px-4 py-3 text-sm text-[#6c655c]">
            {{ filtradasPorHora ? 'No hay mesas libres a esa hora. Prueba con otra hora u otro día.' : 'No hay mesas disponibles para reservar.' }}
          </p>
          <div v-else class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4" role="radiogroup" aria-label="Mesa">
            <button
              v-for="mesa in mesasOrdenadas"
              :key="mesa.id"
              type="button"
              role="radio"
              :aria-checked="form.mesa_id === mesa.id"
              class="rounded-xl border px-3 py-3 text-sm font-medium transition"
              :class="
                form.mesa_id === mesa.id
                  ? 'border-ink bg-ink text-white'
                  : 'border-[#dfd8cd] bg-white text-[#3f3b35] hover:border-brand-500'
              "
              @click="elegirMesa(mesa.id)"
            >
              {{ mesa.nombre }}
            </button>
          </div>
          <span v-if="errores.mesa_id" class="mt-2 block text-xs text-red-600">{{ errores.mesa_id }}</span>
        </section>

        <!-- 3. Datos -->
        <section class="space-y-4 rounded-2xl border border-[#e2dbcf] bg-white p-5 sm:p-6">
          <h2 class="flex items-center gap-3 font-display text-xl text-ink">
            <span class="flex size-7 items-center justify-center rounded-full bg-ink font-sans text-xs font-semibold text-brand-400">3</span>
            Tus datos
          </h2>
          <BaseInput formato="persona" v-model="form.cliente_nombre" label="Nombre" :icon="User" :error="errores.cliente_nombre" />
          <div class="grid gap-4 sm:grid-cols-2">
            <BaseInput formato="celular" v-model="form.cliente_telefono" label="Teléfono" :icon="Phone" :error="errores.cliente_telefono" />
            <BaseInput formato="correo" v-model="form.cliente_correo" label="Correo" :icon="Mail" :error="errores.cliente_correo" />
          </div>
          <label class="block">
            <span class="mb-1.5 block text-[11px] font-medium uppercase tracking-[0.08em] text-[#6c655c]">Descripción (opcional)</span>
            <textarea v-formato="'texto'"
              v-model="form.descripcion"
              rows="3"
              :maxlength="MAX_DESCRIPCION"
              placeholder="Ej.: es un cumpleaños, necesitamos una silla para bebé o preferimos cerca de la ventana."
              class="w-full resize-none rounded-xl border border-[#e3ded4] bg-[#fbfaf7] px-4 py-3 text-sm text-ink transition placeholder:text-[#a39b8f] focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-brand-500/15"
              :class="{ '!border-red-400 !ring-red-100': errores.descripcion }"
            />
            <span class="mt-1 flex justify-between text-xs">
              <span class="text-red-600">{{ errores.descripcion }}</span>
              <span class="text-[#a39b8f]">{{ form.descripcion.length }}/{{ MAX_DESCRIPCION }}</span>
            </span>
          </label>
          <AvisoDestacado>Te enviamos al correo la confirmación y un enlace para ver o cancelar la reserva.</AvisoDestacado>
        </section>

        <p v-if="errorGeneral" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorGeneral }}</p>

        <BaseButton type="submit" :disabled="enviando || cargandoMesas" class="w-full">
          <Users :size="16" />
          {{ enviando ? 'Creando reserva…' : `Continuar y pagar el depósito de ${formatoPrecio(depositoValor)}` }}
        </BaseButton>
      </form>

      <!-- Depósito y condiciones -->
      <aside class="rounded-2xl bg-ink p-6 text-white lg:sticky lg:top-24">
        <p class="text-[11px] font-medium uppercase tracking-[0.14em] text-white/60">Depósito de la reserva</p>
        <p class="mt-1 font-sans font-semibold text-4xl text-brand-400">{{ formatoPrecio(depositoValor) }}</p>
        <ul class="mt-5 space-y-4 text-sm text-white/85">
          <li class="flex gap-3">
            <ReceiptText :size="18" class="mt-0.5 shrink-0 text-brand-400" />
            <span>Se descuenta de tu cuenta el día de tu visita: no es un cobro adicional.</span>
          </li>
          <li class="flex gap-3">
            <RotateCcw :size="18" class="mt-0.5 shrink-0 text-brand-400" />
            <span>Si cancelas antes de la hora, te lo devolvemos completo.</span>
          </li>
          <li class="flex gap-3">
            <Clock :size="18" class="mt-0.5 shrink-0 text-brand-400" />
            <span>Te esperamos hasta {{ toleranciaMinutos }} minutos después de la hora. Si no llegas, la mesa se libera y el depósito no se devuelve.</span>
          </li>
        </ul>
        <a
          :href="whatsappUrl"
          target="_blank"
          rel="noopener"
          class="mt-6 inline-flex items-center gap-2 text-sm font-medium text-brand-400 hover:text-brand-300"
        >
          <MessageCircle :size="16" />
          ¿Dudas? Escríbenos por WhatsApp
        </a>
      </aside>
    </div>
  </div>
</template>
