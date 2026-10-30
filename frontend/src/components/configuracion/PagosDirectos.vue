<script setup lang="ts">
// Pago directo (22/10/2026, RF-M06-030/034, RN-M06-16) — Configuración > Cuentas para pagos en línea.
// Mientras no haya cuenta de Wompi, el Cliente transfiere a estas cuentas y sube la captura. Solo el
// SuperAdmin las cambia (es a donde llega el dinero) y cada cambio queda en la Auditoría; el
// Administrador las ve. Un medio sin sus datos obligatorios no se le ofrece al Cliente; si ninguno
// está completo, el pago en línea no aparece y se paga al recibir.
import TarjetaConfiguracion from '@/components/configuracion/TarjetaConfiguracion.vue'
import { computed, onMounted, ref } from 'vue'
import { Landmark, ShieldCheck, Smartphone, Zap } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseInput from '@/components/common/BaseInput.vue'
import { configuracionService, type DatosPagosDirectos, type PagosDirectosPublico } from '@/services/configuracion.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const puedeEditar = computed(() => auth.esSuperAdmin)

const VACIO: DatosPagosDirectos = {
  nequi: { numero: '', titular: '' },
  bancolombia: { tipo: '', numero: '', titular: '', documento: '' },
  breb: { llave: '', titular: '' },
}
const copiar = (d: DatosPagosDirectos): DatosPagosDirectos => JSON.parse(JSON.stringify(d))

const datos = ref<DatosPagosDirectos>(copiar(VACIO))
const guardados = ref<DatosPagosDirectos>(copiar(VACIO))
const publico = ref<PagosDirectosPublico | null>(null)
const cargando = ref(true)
const guardando = ref(false)
const errores = ref<Record<string, string>>({})
const error = ref('')
const exito = ref('')

const hayCambios = computed(() => JSON.stringify(datos.value) !== JSON.stringify(guardados.value))
const listo = (medio: string) => publico.value?.medios.some((m) => m.medio === medio) ?? false

function tomar(respuesta: { datos: DatosPagosDirectos; publico: PagosDirectosPublico }) {
  datos.value = copiar({ ...VACIO, ...respuesta.datos })
  guardados.value = copiar(datos.value)
  publico.value = respuesta.publico
}

onMounted(async () => {
  try {
    const { data } = await configuracionService.pagosDirectos()
    tomar(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar las cuentas de pago.')
  } finally {
    cargando.value = false
  }
})

async function guardar() {
  errores.value = {}
  error.value = ''
  exito.value = ''
  guardando.value = true
  try {
    const { data } = await configuracionService.actualizarPagosDirectos(datos.value)
    tomar(data)
    exito.value = data.publico.medios.length
      ? 'Cuentas guardadas. El Cliente ya las ve al pagar en línea.'
      : 'Guardado. Sin ninguna cuenta completa, el pago en línea no se ofrece.'
  } catch (e) {
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) errores.value = campos
    else error.value = mensajeDeError(e, 'No pudimos guardar las cuentas.')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <form novalidate @submit.prevent="guardar">
    <TarjetaConfiguracion :icono="Landmark" tono="bg-sky-50 text-sky-700" titulo="Cuentas para pagos en línea">
      <template #descripcion>
        El Cliente transfiere a estas cuentas desde su app y sube la captura; el personal la revisa en Cobrar,
        Pedidos o Reservas. Deja vacío un medio para no ofrecerlo.
      </template>
        <p
          v-if="publico?.automatico"
          class="mb-4 rounded-xl bg-emerald-50 px-3 py-2 text-xs text-emerald-800"
        >
          Wompi está activo: los pagos en línea se hacen en la pasarela y estas cuentas no se muestran.
        </p>
        <p v-else-if="!cargando && !puedeEditar" class="mb-4 flex items-start gap-2 rounded-xl bg-gray-50 px-3 py-2 text-xs text-gray-600">
          <ShieldCheck :size="15" class="mt-px shrink-0" />
          Solo el SuperAdmin cambia las cuentas donde se recibe el dinero.
        </p>

        <div v-if="cargando" class="h-40 animate-pulse rounded-xl bg-gray-100" />
        <div v-else class="space-y-4">
          <!-- Nequi -->
          <fieldset class="rounded-xl border border-[#ece6db] p-3.5">
            <legend class="flex items-center gap-2 px-1 text-sm font-semibold text-ink">
              <Smartphone :size="15" /> Nequi
              <span class="badge" :class="listo('nequi') ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-gray-100 text-gray-500 ring-gray-500/20'">
                {{ listo('nequi') ? 'Se ofrece' : 'No se ofrece' }}
              </span>
            </legend>
            <div class="grid gap-3 sm:grid-cols-2">
              <BaseInput formato="celular" v-model="datos.nequi.numero" label="Número de Nequi" placeholder="300 123 4567" :disabled="!puedeEditar" :error="errores['nequi.numero']" />
              <BaseInput formato="persona" v-model="datos.nequi.titular" label="A nombre de" placeholder="Fragua Real" :disabled="!puedeEditar" :error="errores['nequi.titular']" />
            </div>
          </fieldset>

          <!-- Bancolombia -->
          <fieldset class="rounded-xl border border-[#ece6db] p-3.5">
            <legend class="flex items-center gap-2 px-1 text-sm font-semibold text-ink">
              <Landmark :size="15" /> Cuenta Bancolombia
              <span class="badge" :class="listo('bancolombia') ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-gray-100 text-gray-500 ring-gray-500/20'">
                {{ listo('bancolombia') ? 'Se ofrece' : 'No se ofrece' }}
              </span>
            </legend>
            <div class="grid gap-3 sm:grid-cols-2">
              <label class="block">
                <span class="mb-1.5 block text-[11px] font-medium uppercase tracking-[0.08em] text-[#6c655c]">Tipo de cuenta</span>
                <select v-model="datos.bancolombia.tipo" class="field" :disabled="!puedeEditar">
                  <option value="">Sin cuenta</option>
                  <option value="ahorros">Ahorros</option>
                  <option value="corriente">Corriente</option>
                </select>
                <span v-if="errores['bancolombia.tipo']" class="mt-1 block text-xs text-red-600">{{ errores['bancolombia.tipo'] }}</span>
              </label>
              <BaseInput formato="cuenta" v-model="datos.bancolombia.numero" label="Número de cuenta" placeholder="123-456789-01" :disabled="!puedeEditar" :error="errores['bancolombia.numero']" />
              <BaseInput formato="persona" v-model="datos.bancolombia.titular" label="A nombre de" placeholder="Fragua Real" :disabled="!puedeEditar" :error="errores['bancolombia.titular']" />
              <BaseInput formato="documento" v-model="datos.bancolombia.documento" label="Cédula o NIT (opcional)" placeholder="900123456-7" :disabled="!puedeEditar" :error="errores['bancolombia.documento']" />
            </div>
          </fieldset>

          <!-- Bre-B -->
          <fieldset class="rounded-xl border border-[#ece6db] p-3.5">
            <legend class="flex items-center gap-2 px-1 text-sm font-semibold text-ink">
              <Zap :size="15" /> Llave Bre-B
              <span class="badge" :class="listo('breb') ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-gray-100 text-gray-500 ring-gray-500/20'">
                {{ listo('breb') ? 'Se ofrece' : 'No se ofrece' }}
              </span>
            </legend>
            <div class="grid gap-3 sm:grid-cols-2">
              <BaseInput formato="llave" v-model="datos.breb.llave" label="Llave" placeholder="@fraguareal, celular o correo" :disabled="!puedeEditar" :error="errores['breb.llave']" />
              <BaseInput formato="persona" v-model="datos.breb.titular" label="A nombre de" placeholder="Fragua Real" :disabled="!puedeEditar" :error="errores['breb.titular']" />
            </div>
          </fieldset>
        </div>

        <p v-if="error" class="mt-3 alert-error">{{ error }}</p>
        <p v-if="exito" class="mt-3 rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ exito }}</p>

        <div v-if="puedeEditar" class="mt-4 flex justify-end">
          <BaseButton v-escritura type="submit" variant="primary" class="w-full sm:w-auto" :disabled="guardando || !hayCambios || cargando">
            {{ guardando ? 'Guardando…' : 'Guardar cuentas' }}
          </BaseButton>
        </div>
    </TarjetaConfiguracion>
  </form>
</template>
