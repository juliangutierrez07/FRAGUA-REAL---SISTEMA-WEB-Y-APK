<script setup lang="ts">
// M02/M07 — Domicilio o Recogida (canal Local se arma desde el QR de mesa, no
// desde este Checkout web, ver ADR-001 y StorePedidoRequest). El Cliente con sesión
// y el invitado pueden pagar en línea (Nequi/Bancolombia, RF-M06-005): el Cliente por
// su sesión y el invitado con el token del pedido. Quien prefiera efectivo o tarjeta
// paga contra entrega o al recoger — el Mesero lo cobra después (RF-M06-003/004).
//
// CORREGIDO (Fase 0, tarea 4, 09/10/2026): el pedido se crea UNA vez. Si el pago en
// línea falla, el Cliente reintenta solo el pago sobre el mismo pedido (RF-M06-008);
// antes reintentar volvía a crear el pedido completo.
//
// Pago directo (22/10/2026, RF-M06-030): sin Wompi, el pedido se crea primero y en la confirmación
// el Cliente ve las cuentas del negocio, transfiere el total exacto y sube la captura.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Banknote, Bike, Check, CheckCircle2, Landmark, LocateFixed, Map as IconoMapa, MapPin, Radar, ShoppingBag, Smartphone, X, Zap } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseInput from '@/components/common/BaseInput.vue'
import AvisoDestacado from '@/components/common/AvisoDestacado.vue'
import CampoPesos from '@/components/common/CampoPesos.vue'
import MapaUbicacion from '@/components/common/MapaUbicacion.vue'
import { useNegocioStore } from '@/stores/negocio'
import { MENSAJE_ERROR_UBICACION, obtenerUbicacion, type ErrorUbicacion, type Ubicacion } from '@/utils/ubicacion'
import PagoEnLinea from '@/components/pagos/PagoEnLinea.vue'
import EnlaceSeguimiento from '@/components/pedidos/EnlaceSeguimiento.vue'
import { usePagosEnLinea } from '@/composables/usePagosEnLinea'
import { useAuthStore } from '@/stores/auth'
import { useCarritoStore } from '@/stores/carrito'
import { useCotizacion } from '@/composables/useCotizacion'
import { configuracionService } from '@/services/configuracion.service'
import { pedidoService, type PagoEnLineaPayload } from '@/services/pedido.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { irAlCheckout, nombreMedio } from '@/utils/pasarela'
import type { MedioPagoEnLinea, Pedido, TipoPedido } from '@/types/models'

const router = useRouter()
const auth = useAuthStore()
const carrito = useCarritoStore()

// CORREGIDO (30/09/2026): esta vista usaba "auth.estaAutenticado" (cualquier sesión)
// para decidir si pedir datos de invitado y habilitar pago en línea — pero el backend
// (StorePedidoRequest::prepareForValidation, PedidoPolicy::pagarEnLinea) solo trata al
// usuario como Cliente cuando esCliente() es true. Si un Mesero/Administrador entra a
// este Checkout con su propia sesión activa (mismo navegador), quedaba "autenticado"
// pero sin cliente_id ni campos de invitado — el backend rechazaba el pedido con 422 y,
// como la sección de invitado estaba oculta, ese error nunca tenía dónde mostrarse (el
// botón "Confirmar pedido" no hacía nada visible). esCliente evita ambos problemas.
const esCliente = computed(() => auth.rol === 'cliente')

if (carrito.lineas.length === 0) {
  router.replace({ name: 'menu' })
}

const tipo = ref<Extract<TipoPedido, 'domicilio' | 'recogida'>>('domicilio')
const metodoPago = ref<'en_linea' | 'contra_entrega'>('contra_entrega')
const pasarela = ref<MedioPagoEnLinea>('nequi')

// Pago directo: qué medios se ofrecen (con Wompi, Nequi y Bancolombia; sin Wompi, los configurados).
const { pagos, disponible: enLineaDisponible, directo } = usePagosEnLinea()
const opcionesMedio = computed<MedioPagoEnLinea[]>(() =>
  directo.value ? (pagos.value?.medios ?? []).map((m) => m.medio) : ['nequi', 'bancolombia'],
)
watch(opcionesMedio, (lista) => {
  if (!lista.includes(pasarela.value) && lista[0]) pasarela.value = lista[0]
})
watch(enLineaDisponible, (si) => {
  if (!si && metodoPago.value === 'en_linea') metodoPago.value = 'contra_entrega'
})
const textoMedios = computed(() => {
  const nombres = opcionesMedio.value.map(nombreMedio)
  return nombres.length > 1 ? `${nombres.slice(0, -1).join(', ')} o ${nombres[nombres.length - 1]}` : (nombres[0] ?? '')
})

// RN-M07-01, RF-M07-003 a 007 y 010 (Fase 1, tarea 2): nombre, teléfono, dirección, barrio y
// referencia son obligatorios en un domicilio; el Cliente con cuenta confirma su teléfono para
// este pedido (viene de su perfil si lo tiene).
const form = reactive({
  direccion_entrega: '',
  barrio: '',
  referencia: '',
  observaciones_entrega: '',
  nombre_invitado: '',
  telefono_invitado: '',
  // Fase 4, tarea 5 (RF-M07-R-012): opcional, para avisarle cuando su pedido esté listo.
  correo_invitado: '',
  telefono_contacto: auth.usuario?.telefono ?? '',
})

// Al recargar la página el perfil llega después de montar la vista: se completa al llegar.
watch(
  () => auth.usuario?.telefono,
  (telefono) => {
    if (telefono && !form.telefono_contacto) form.telefono_contacto = telefono
  },
)

const valorDomicilio = ref(2000)
const enviando = ref(false)
const errores = ref<Record<string, string>>({})
const errorGeneral = ref('')
const pedidoCreado = ref<Pedido | null>(null)
const pagoRegistrado = ref(false)
const errorPago = ref('')
const pagando = ref(false)
const redirigiendo = ref(false) // Fase 6: saliendo hacia el checkout de la pasarela

onMounted(async () => {
  try {
    const { data } = await configuracionService.obtener()
    valorDomicilio.value = data.valor_domicilio
  } catch {
    // Si falla, se queda con el valor por defecto — el backend fija el real de todas formas.
  }
})

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

const subtotal = computed(() => carrito.total)
const costoDomicilio = computed(() => (tipo.value === 'domicilio' ? valorDomicilio.value : 0))
// Fase 2, tarea 1 (RF-M04-035): el backend dice cuánto rebajan las promociones vigentes.
const { cotizacion } = useCotizacion(() => tipo.value, () => carrito.aLineasPedido())
const promociones = computed(() => cotizacion.value?.promociones ?? [])
const descuento = computed(() => cotizacion.value?.descuento ?? 0)
const totalEstimado = computed(() => subtotal.value - descuento.value + costoDomicilio.value)

// ---- Mejora (26/10/2026, RF-M07-033): ubicación actual del Cliente (domicilio) ----
// CORREGIDO (26/10/2026, RF-M07-033): la ubicación del navegador puede caer lejos (en un computador o sin GPS sale de
// la red). Se toma la lectura más precisa de unos segundos (utils/ubicacion.ts) y el Cliente la confirma
// en el mapa: tocar el mapa o arrastrar el pin la corrige. También puede marcarla sin GPS.
const ubicacion = ref<Ubicacion | null>(null)
const mostrarMapa = ref(false)
const buscandoUbicacion = ref(false)
const errorUbicacion = ref('')
const negocio = useNegocioStore()
void negocio.cargar() // el punto del local, para abrir el mapa ahí
const centroLocal = computed<[number, number] | null>(() =>
  negocio.datos.latitud != null && negocio.datos.longitud != null ? [negocio.datos.latitud, negocio.datos.longitud] : null,
)
// Más de 100 m de error: casi seguro no es la puerta; se pide mover el pin.
const ubicacionAproximada = computed(() => !!ubicacion.value && ubicacion.value.precision > 100)

async function usarMiUbicacion() {
  errorUbicacion.value = ''
  buscandoUbicacion.value = true
  try {
    ubicacion.value = await obtenerUbicacion()
    mostrarMapa.value = true
  } catch (e) {
    errorUbicacion.value = MENSAJE_ERROR_UBICACION[e as ErrorUbicacion] ?? MENSAJE_ERROR_UBICACION.fallo
  } finally {
    buscandoUbicacion.value = false
  }
}
function marcarEnMapa(lat: number, lng: number) {
  ubicacion.value = { lat, lng, precision: 0 }
  errorUbicacion.value = ''
}
function quitarUbicacion() {
  ubicacion.value = null
  mostrarMapa.value = false
}

// ---- Mejora (26/10/2026, RF-M07-034): con qué billete paga contra entrega (para llevar el cambio) ----
const pagaCon = ref<number | null>(null) // null = valor exacto
const otroValor = ref(false)
const valorOtro = ref(0)
const pideBillete = computed(() => tipo.value === 'domicilio' && metodoPago.value === 'contra_entrega')
const opcionesBillete = computed(() => {
  const total = totalEstimado.value
  if (!(total > 0)) return []
  const redondear = (paso: number) => Math.ceil(total / paso) * paso
  return [...new Set([10000, 20000, 50000, 100000].map(redondear))].filter((v) => v > total).sort((a, b) => a - b).slice(0, 4)
})
const valorPagaCon = computed(() => (otroValor.value ? valorOtro.value || null : pagaCon.value))
const cambioEstimado = computed(() => (valorPagaCon.value ? valorPagaCon.value - totalEstimado.value : 0))
function elegirBillete(valor: number | null) {
  otroValor.value = false
  pagaCon.value = valor
}
// Si el total cambia y el billete ya no alcanza, vuelve a «Pago exacto».
watch(totalEstimado, (total) => {
  if (pagaCon.value !== null && pagaCon.value < total) pagaCon.value = null
})

// Íconos de los medios de pago en línea (no hay logos de las marcas).
const ICONO_MEDIO: Record<MedioPagoEnLinea, typeof Smartphone> = { nequi: Smartphone, bancolombia: Landmark, breb: Zap }

async function confirmarPedido() {
  errores.value = {}
  errorGeneral.value = ''
  enviando.value = true

  try {
    if (pideBillete.value && otroValor.value && valorOtro.value > 0 && valorOtro.value < totalEstimado.value) {
      errores.value = { paga_con: `Ese valor no alcanza para el total (${formatoPrecio(totalEstimado.value)}).` }
      return
    }
    const payload = {
      tipo: tipo.value,
      origen: 'cliente_web' as const,
      lineas: carrito.aLineasPedido(),
      ...(tipo.value === 'domicilio'
        ? {
            direccion_entrega: form.direccion_entrega,
            barrio: form.barrio,
            referencia: form.referencia,
            ubicacion_lat: ubicacion.value?.lat,
            ubicacion_lng: ubicacion.value?.lng,
            paga_con: pideBillete.value ? (valorPagaCon.value ?? undefined) : undefined,
          }
        : {}),
      observaciones_entrega: form.observaciones_entrega.trim() || undefined,
      // RF-M07-013/015: el pedido guarda cómo vas a pagar, aunque el pago llegue después.
      metodo_pago_preferido: metodoPago.value,
      pasarela_preferida: metodoPago.value === 'en_linea' ? pasarela.value : undefined,
      ...(!esCliente.value
        ? {
            cliente_invitado_nombre: form.nombre_invitado,
            cliente_invitado_telefono: form.telefono_invitado,
            cliente_invitado_correo: form.correo_invitado.trim() || undefined,
          }
        : { telefono_contacto: form.telefono_contacto.trim() || undefined }),
    }

    const { data: pedido } = await pedidoService.crear(payload)

    // El pedido ya existe: el carrito se vacía aunque el pago en línea falle, para que
    // un segundo clic no cree otro pedido.
    carrito.vaciar()
    pedidoCreado.value = pedido

    // Con Wompi se sale a pagar de una vez; sin Wompi el Cliente transfiere y sube la captura aquí.
    if (metodoPago.value === 'en_linea' && !directo.value) {
      await pagarEnLinea({ pasarela: pasarela.value })
    }
  } catch (error) {
    const camposInvalidos = erroresDeValidacion(error)
    if (Object.keys(camposInvalidos).length) {
      errores.value = camposInvalidos
    } else {
      errorGeneral.value = mensajeDeError(error, 'No pudimos confirmar tu pedido. Intenta de nuevo.')
    }
  } finally {
    enviando.value = false
  }
}

// RF-M06-005/008: registra el pago en línea del pedido ya creado. Si falla, el pedido sigue
// pendiente y el botón "Reintentar pago" vuelve a llamar solo a esta función.
async function pagarEnLinea(datosPago: PagoEnLineaPayload) {
  const pedido = pedidoCreado.value
  if (!pedido) return
  pasarela.value = datosPago.pasarela
  pagando.value = true
  errorPago.value = ''
  try {
    let respuesta
    if (esCliente.value) {
      respuesta = await pedidoService.pagarEnLinea(pedido.id, datosPago)
    } else if (pedido.token_publico) {
      respuesta = await pedidoService.pagarEnLineaConToken(pedido.token_publico, datosPago)
    } else {
      throw new Error('sin token')
    }
    // Fase 6, tarea 1 (RF-M06-006): con la pasarela activa, se paga en Wompi y se vuelve a
    // /pago/retorno; el botón queda deshabilitado mientras el navegador sale.
    if (irAlCheckout(respuesta.data.checkout)) {
      redirigiendo.value = true
      return
    }
    pagoRegistrado.value = true
  } catch (error) {
    const campos = erroresDeValidacion(error)
    errorPago.value = Object.values(campos)[0] ?? mensajeDeError(error, 'No pudimos registrar tu pago en línea.')
  } finally {
    if (!redirigiendo.value) pagando.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6">
    <!-- Confirmación -->
    <div v-if="pedidoCreado" class="mx-auto max-w-lg rounded-2xl border border-[#e2dbcf] bg-white p-8 text-center">
      <CheckCircle2 :size="48" class="mx-auto text-brand-600" />
      <h1 class="mt-4 font-display text-2xl text-ink">¡Tu pedido quedó registrado!</h1>
      <p class="mt-2 text-sm text-[#6c655c]">
        Pedido #{{ pedidoCreado.id }} · {{ pedidoCreado.tipo === 'domicilio' ? 'Domicilio' : 'Recogida en el local' }}
        · {{ formatoPrecio(pedidoCreado.total) }}
      </p>
      <p class="mt-4 text-sm text-[#6c655c]">
        <template v-if="metodoPago === 'en_linea' && redirigiendo">
          Te estamos llevando a la pasarela de pago (Wompi) para que pagues con {{ nombreMedio(pasarela) }}.
          Al terminar vuelves aquí y confirmamos tu pedido solos.
        </template>
        <template v-else-if="metodoPago === 'en_linea' && pagoRegistrado && directo">
          Recibimos tu comprobante. En cuanto verifiquemos la transferencia confirmamos tu pedido y empezamos a
          prepararlo.
        </template>
        <template v-else-if="metodoPago === 'en_linea' && pagoRegistrado">
          Ya registramos tu pago. En breve lo verificamos, confirmamos tu pedido y empezamos a prepararlo.
        </template>
        <template v-else-if="metodoPago === 'en_linea' && directo && !errorPago">
          Ahora transfiere el valor exacto a una de nuestras cuentas y sube la captura aquí abajo. Si prefieres,
          paga {{ pedidoCreado.tipo === 'domicilio' ? 'contra entrega' : 'al recoger' }} sin hacer nada más.
        </template>
        <template v-else-if="metodoPago === 'en_linea'">
          Tu pedido quedó guardado, pero el pago en línea no se pudo registrar. Puedes intentarlo de nuevo
          o pagar {{ pedidoCreado.tipo === 'domicilio' ? 'contra entrega' : 'al recoger' }} sin hacer nada más.
        </template>
        <template v-else>
          Pagas {{ pedidoCreado.tipo === 'domicilio' ? 'contra entrega' : 'al recoger' }}. Te avisamos cuando esté
          listo.
        </template>
      </p>
      <!-- Fase 1 (RF-M07-027): el invitado sigue su pedido con este enlace privado. 28/10/2026: con botones en vez de
           la dirección completa. -->
      <EnlaceSeguimiento v-if="!esCliente && pedidoCreado.token_publico" class="mt-5" :token="pedidoCreado.token_publico" :pedido-id="pedidoCreado.id" />
      <!-- Pagar (sin Wompi) o reintentar solo el pago, sobre el mismo pedido (RF-M06-008). -->
      <div v-if="metodoPago === 'en_linea' && !pagoRegistrado && !redirigiendo" class="mt-6 text-left">
        <PagoEnLinea
          :config="pagos"
          :monto="Number(pedidoCreado.total)"
          :enviando="pagando"
          :error="errorPago"
          :medio-inicial="pasarela"
          @pagar="pagarEnLinea"
        />
      </div>

      <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
        <!-- 28/10/2026 (RF-M07-037): abre el pedido en «Mis pedidos», con su seguimiento. -->
        <router-link
          v-if="esCliente"
          :to="{ name: 'cliente-pedidos', query: { pedido: pedidoCreado.id } }"
          class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-500 px-6 py-3 text-sm font-medium text-ink shadow-[0_8px_20px_rgba(214,164,58,0.35)] transition-all duration-150 hover:-translate-y-0.5 hover:bg-brand-400"
        >
          <Radar :size="16" />
          Seguir mi pedido
        </router-link>
        <router-link
          :to="{ name: 'home' }"
          class="inline-flex items-center justify-center rounded-xl border border-[#e3ded4] px-6 py-3 text-sm font-medium text-ink transition hover:bg-[#fbfaf7]"
        >
          Volver al inicio
        </router-link>
      </div>
    </div>

    <!-- Formulario -->
    <template v-else>
      <div class="mb-8 flex items-center gap-3">
        <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
        <span class="text-[11px] font-medium tracking-[2px] text-brand-700">ÚLTIMO PASO</span>
      </div>
      <h1 class="font-display text-4xl font-medium tracking-tight text-ink">Confirmar pedido</h1>

      <form class="mt-8 grid gap-8 lg:grid-cols-[1fr_360px]" @submit.prevent="confirmarPedido">
        <div class="space-y-8">
          <!-- Tipo de entrega -->
          <section>
            <h2 class="font-display text-lg text-ink">¿Cómo lo quieres?</h2>
            <div class="mt-3 grid grid-cols-2 gap-3">
              <button
                type="button"
                class="flex flex-col items-center gap-2 rounded-2xl border p-4 text-sm transition"
                :class="
                  tipo === 'domicilio'
                    ? 'border-brand-500 bg-brand-500/10 text-ink'
                    : 'border-[#e3ded4] text-[#6c655c] hover:border-brand-300'
                "
                @click="tipo = 'domicilio'"
              >
                <Bike :size="22" />
                Domicilio
              </button>
              <button
                type="button"
                class="flex flex-col items-center gap-2 rounded-2xl border p-4 text-sm transition"
                :class="
                  tipo === 'recogida'
                    ? 'border-brand-500 bg-brand-500/10 text-ink'
                    : 'border-[#e3ded4] text-[#6c655c] hover:border-brand-300'
                "
                @click="tipo = 'recogida'"
              >
                <ShoppingBag :size="22" />
                Recogida en el local
              </button>
            </div>
          </section>

          <!-- Dirección (solo domicilio) -->
          <section v-if="tipo === 'domicilio'" class="space-y-4">
            <h2 class="font-display text-lg text-ink">Dirección de entrega</h2>
            <BaseInput formato="direccion"
              v-model="form.direccion_entrega"
              label="Dirección"
              placeholder="Calle, carrera, número"
              :icon="MapPin"
              :error="errores.direccion_entrega"
            />
            <!-- Mejora (26/10/2026, RF-M07-033): compartir la ubicación para que el domiciliario llegue directo; el Cliente
                 la confirma o la corrige en el mapa. -->
            <div class="space-y-2.5">
              <div class="flex flex-col gap-2 sm:flex-row">
                <button
                  type="button"
                  class="flex min-h-11 items-center justify-center gap-2 rounded-xl border border-brand-400 bg-white px-4 py-2.5 text-sm font-semibold text-brand-800 transition hover:bg-brand-50 active:scale-[0.99] disabled:opacity-60"
                  :disabled="buscandoUbicacion"
                  @click="usarMiUbicacion"
                >
                  <LocateFixed :size="17" />
                  {{ buscandoUbicacion ? 'Buscando tu ubicación… (unos segundos)' : ubicacion ? 'Volver a buscar mi ubicación' : 'Usar mi ubicación actual' }}
                </button>
                <button
                  v-if="!mostrarMapa"
                  type="button"
                  class="flex min-h-11 items-center justify-center gap-2 rounded-xl border border-[#e3ded4] bg-white px-4 py-2.5 text-sm font-semibold text-[#4a443c] transition hover:border-brand-300 active:scale-[0.99]"
                  @click="mostrarMapa = true"
                >
                  <IconoMapa :size="17" />
                  Marcar en el mapa
                </button>
              </div>
              <p v-if="errorUbicacion" class="text-xs text-red-600">{{ errorUbicacion }}</p>
              <p v-else-if="!mostrarMapa" class="text-xs text-[#6c655c]">Opcional: el domiciliario la abre en el mapa. Igual escribe la dirección.</p>

              <template v-if="mostrarMapa">
                <AvisoDestacado v-if="ubicacionAproximada" tono="atencion">
                  Tu ubicación es aproximada (unos {{ ubicacion!.precision }} m): sin GPS, el navegador la calcula por la
                  red. Toca el mapa o arrastra el pin hasta tu puerta.
                </AvisoDestacado>
                <AvisoDestacado v-else-if="!ubicacion">Toca el mapa en el lugar de la entrega.</AvisoDestacado>
                <div v-else class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-emerald-900">
                  <CheckCircle2 :size="17" class="shrink-0 text-emerald-600" />
                  <span class="font-medium">{{ ubicacion.precision === 0 ? 'Punto marcado por ti.' : `Ubicación encontrada (unos ${ubicacion.precision} m).` }}</span>
                  <span class="text-xs text-[#6c655c]">Si no es tu puerta, toca el mapa o arrastra el pin.</span>
                </div>
                <MapaUbicacion
                  class="h-64 sm:h-72"
                  editable
                  etiqueta="Lugar de la entrega"
                  :latitud="ubicacion?.lat ?? null"
                  :longitud="ubicacion?.lng ?? null"
                  :centro-inicial="centroLocal"
                  @elegir="marcarEnMapa"
                />
                <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#6c655c] underline-offset-2 hover:underline" @click="quitarUbicacion">
                  <X :size="14" /> No enviar ubicación
                </button>
              </template>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <BaseInput formato="nombre" :maxlength="80" v-model="form.barrio" label="Barrio" placeholder="Tu barrio" :error="errores.barrio" />
              <BaseInput formato="nombre" :maxlength="160"
                v-model="form.referencia"
                label="Punto de referencia"
                placeholder="Ej. casa azul frente al parque"
                :error="errores.referencia"
              />
            </div>
          </section>

          <!-- Teléfono del Cliente con cuenta (el invitado lo deja en sus datos de contacto) -->
          <section v-if="esCliente" class="space-y-4">
            <h2 class="font-display text-lg text-ink">Teléfono de contacto</h2>
            <BaseInput formato="celular"
              v-model="form.telefono_contacto"
              label="Teléfono"
              type="tel"
              placeholder="Para avisarte sobre tu pedido"
              :error="errores.telefono_contacto"
            />
          </section>

          <!-- Datos de contacto (solo invitado) -->
          <section v-if="!esCliente" class="space-y-4">
            <h2 class="font-display text-lg text-ink">Tus datos de contacto</h2>
            <AvisoDestacado>
              Pides como invitado.
              <router-link :to="{ name: 'login', query: { redirect: '/checkout' } }" class="font-semibold text-brand-800 underline"
                >Inicia sesión</router-link
              >
              si prefieres guardar tu historial de pedidos.
            </AvisoDestacado>
            <div class="grid gap-4 sm:grid-cols-2">
              <BaseInput formato="persona" v-model="form.nombre_invitado" label="Nombre" placeholder="Tu nombre" :error="errores.cliente_invitado_nombre" />
              <BaseInput formato="celular"
                v-model="form.telefono_invitado"
                label="Teléfono"
                type="tel"
                placeholder="Tu número de contacto"
                :error="errores.cliente_invitado_telefono"
              />
              <div class="sm:col-span-2">
                <BaseInput formato="correo"
                  v-model="form.correo_invitado"
                  label="Correo (opcional)"
                  type="email"
                  autocomplete="email"
                  placeholder="tucorreo@ejemplo.com"
                  :error="errores.cliente_invitado_correo"
                />
                <p v-if="!errores.cliente_invitado_correo" class="mt-1 text-xs text-[#6c655c]">
                  {{ tipo === 'recogida' ? 'Te avisamos por correo cuando tu pedido esté listo para recoger.' : 'Lo usamos solo para avisarte sobre tu pedido.' }}
                </p>
              </div>
            </div>
          </section>

          <!-- RF-M07-010: observaciones para quien entrega o atiende -->
          <section class="space-y-2">
            <label for="observaciones-entrega" class="font-display text-lg text-ink">
              {{ tipo === 'domicilio' ? 'Indicaciones para la entrega' : 'Indicaciones para la recogida' }}
              <span class="text-xs font-sans text-[#6c655c]">(opcional)</span>
            </label>
            <textarea v-formato="'texto'"
              id="observaciones-entrega"
              v-model="form.observaciones_entrega"
              rows="2"
              maxlength="500"
              class="field"
              :placeholder="tipo === 'domicilio' ? 'Ej. apartamento 302, timbre dañado, llamar al llegar' : 'Ej. paso a las 7:30 p. m.'"
            />
            <p v-if="errores.observaciones_entrega" class="text-xs text-red-600">{{ errores.observaciones_entrega }}</p>
          </section>

          <!-- Método de pago -->
          <section class="space-y-3">
            <h2 class="font-display text-lg text-ink">¿Cómo vas a pagar?</h2>

            <label
              class="flex cursor-pointer items-start gap-3 rounded-2xl border p-4 transition"
              :class="
                metodoPago === 'contra_entrega'
                  ? 'border-brand-500 bg-brand-500/10'
                  : 'border-[#e3ded4] hover:border-brand-300'
              "
            >
              <input v-model="metodoPago" type="radio" value="contra_entrega" class="mt-1" />
              <span>
                <span class="flex items-center gap-2 text-sm font-medium text-ink">
                  <Banknote :size="16" />
                  {{ tipo === 'domicilio' ? 'Pago contra entrega' : 'Pago al recoger' }}
                </span>
                <span class="mt-0.5 block text-xs text-[#6c655c]">
                  Efectivo, tarjeta o transferencia — lo recibe quien te {{ tipo === 'domicilio' ? 'entrega' : 'atiende' }}.
                </span>
              </span>
            </label>

            <!-- Mejora (26/10/2026, RF-M07-034): con qué billete paga, para que el domiciliario lleve el cambio completo. -->
            <div v-if="pideBillete" class="rounded-2xl border border-[#e3ded4] bg-white p-4">
              <p class="text-sm font-medium text-ink">Si pagas en efectivo, ¿con cuánto vas a pagar?</p>
              <p class="mt-0.5 text-xs text-[#6c655c]">Así el domiciliario lleva el cambio completo.</p>
              <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                <button
                  type="button"
                  class="min-h-11 rounded-xl border px-3 py-2 text-sm font-semibold transition active:scale-[0.98]"
                  :class="!otroValor && pagaCon === null ? 'border-brand-500 bg-brand-500/15 text-ink' : 'border-[#e3ded4] text-[#4a443c] hover:border-brand-300'"
                  @click="elegirBillete(null)"
                >
                  Pago exacto
                </button>
                <button
                  v-for="valor in opcionesBillete"
                  :key="valor"
                  type="button"
                  class="min-h-11 rounded-xl border px-3 py-2 text-sm font-semibold transition active:scale-[0.98]"
                  :class="!otroValor && pagaCon === valor ? 'border-brand-500 bg-brand-500/15 text-ink' : 'border-[#e3ded4] text-[#4a443c] hover:border-brand-300'"
                  @click="elegirBillete(valor)"
                >
                  {{ formatoPrecio(valor) }}
                </button>
                <button
                  type="button"
                  class="min-h-11 rounded-xl border px-3 py-2 text-sm font-semibold transition active:scale-[0.98]"
                  :class="otroValor ? 'border-brand-500 bg-brand-500/15 text-ink' : 'border-[#e3ded4] text-[#4a443c] hover:border-brand-300'"
                  @click="otroValor = true"
                >
                  Otro valor
                </button>
              </div>
              <label v-if="otroValor" class="mt-3 block">
                <span class="mb-1 block text-xs font-medium text-ink">¿Con cuánto pagas?</span>
                <span class="relative block">
                  <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-[#9b9389]">$</span>
                  <CampoPesos v-model="valorOtro" class="field pl-9" />
                </span>
              </label>
              <p v-if="errores.paga_con" class="mt-2 text-xs text-red-600">{{ errores.paga_con }}</p>
              <p v-else-if="valorPagaCon && cambioEstimado >= 0" class="mt-3 text-sm font-medium text-emerald-800">
                Te llevamos {{ formatoPrecio(cambioEstimado) }} de cambio.
              </p>
            </div>

            <label
              v-if="enLineaDisponible"
              class="flex cursor-pointer items-start gap-3 rounded-2xl border p-4 transition"
              :class="[
                metodoPago === 'en_linea'
                  ? 'border-brand-500 bg-brand-500/10'
                  : 'border-[#e3ded4] hover:border-brand-300',
              ]"
            >
              <input
                v-model="metodoPago"
                type="radio"
                value="en_linea"
                class="mt-1"
              />
              <span>
                <span class="flex items-center gap-2 text-sm font-medium text-ink">
                  <Smartphone :size="16" />
                  Pago en línea ({{ textoMedios }})
                </span>
                <span class="mt-0.5 block text-xs text-[#6c655c]">
                  <template v-if="directo">
                    Al confirmar te mostramos nuestras cuentas: transfieres desde tu app y subes la captura.
                    Confirmamos tu pedido cuando verifiquemos el pago.
                  </template>
                  <template v-else>Pagas ahora mismo, en línea. Confirmamos tu pedido cuando verifiquemos el pago.</template>
                </span>
              </span>
            </label>

            <!-- Mejora de diseño (26/10/2026): el medio en línea se elige con tarjetas, no con radios sueltos. -->
            <div v-if="enLineaDisponible && metodoPago === 'en_linea'" role="radiogroup" aria-label="Medio de pago en línea" class="grid gap-2.5 sm:grid-cols-2">
              <button
                v-for="m in opcionesMedio"
                :key="m"
                type="button"
                role="radio"
                :aria-checked="pasarela === m"
                class="flex min-h-14 items-center gap-3 rounded-2xl border bg-white px-4 py-3 text-left transition active:scale-[0.99]"
                :class="pasarela === m ? 'border-brand-500 shadow-[0_0_0_3px_rgba(214,164,58,0.18)]' : 'border-[#e3ded4] hover:border-brand-300'"
                @click="pasarela = m"
              >
                <span
                  class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                  :class="pasarela === m ? 'bg-brand-500 text-ink' : 'bg-[#f3efe7] text-[#6c655c]'"
                >
                  <component :is="ICONO_MEDIO[m]" :size="19" />
                </span>
                <span class="flex-1 text-sm font-semibold text-ink">{{ nombreMedio(m) }}</span>
                <span
                  class="flex size-6 shrink-0 items-center justify-center rounded-full border"
                  :class="pasarela === m ? 'border-brand-500 bg-brand-500 text-ink' : 'border-[#d6cfc3]'"
                >
                  <Check v-if="pasarela === m" :size="14" :stroke-width="3" />
                </span>
              </button>
            </div>
          </section>

          <p v-if="errorGeneral" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorGeneral }}</p>
        </div>

        <!-- Resumen -->
        <aside class="h-fit rounded-2xl border border-[#e2dbcf] bg-white p-5">
          <h2 class="font-display text-lg text-ink">Resumen</h2>
          <ul class="mt-4 space-y-2 text-sm">
            <li v-for="linea in carrito.lineas" :key="linea.id" class="flex justify-between gap-3 text-[#6c655c]">
              <span>{{ linea.cantidad }}× {{ carrito.nombreLinea(linea) }}</span>
              <span class="shrink-0 text-ink">{{ formatoPrecio(carrito.precioUnitario(linea) * linea.cantidad) }}</span>
            </li>
          </ul>
          <div class="mt-4 space-y-1.5 border-t border-[#e2dbcf] pt-4 text-sm">
            <div class="flex justify-between text-[#6c655c]">
              <span>Subtotal</span>
              <span>{{ formatoPrecio(subtotal) }}</span>
            </div>
            <div v-for="(p, i) in promociones" :key="i" class="flex justify-between gap-3 text-emerald-700">
              <span>{{ p.nombre }}</span>
              <span class="shrink-0">−{{ formatoPrecio(p.monto) }}</span>
            </div>
            <div v-if="tipo === 'domicilio'" class="flex justify-between text-[#6c655c]">
              <span>Domicilio</span>
              <span>{{ formatoPrecio(costoDomicilio) }}</span>
            </div>
            <div class="flex justify-between pt-1 font-display text-lg text-ink">
              <span>Total</span>
              <span>{{ formatoPrecio(totalEstimado) }}</span>
            </div>
          </div>

          <BaseButton type="submit" :disabled="enviando" class="mt-5 w-full">
            {{ enviando ? 'Confirmando…' : 'Confirmar pedido' }}
          </BaseButton>
        </aside>
      </form>
    </template>
  </div>
</template>
