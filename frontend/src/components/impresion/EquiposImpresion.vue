<script setup lang="ts">
// M12 — Impresoras (24/10/2026, RF-M12-001 a 006): «Este equipo» (registrarlo o vincularlo y su
// prueba) y la lista de equipos del local con su señal, su última prueba y sus ajustes.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { KeyRound, Monitor, Plus, Printer, Trash2, Wifi, WifiOff } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseInput from '@/components/common/BaseInput.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import PruebaImpresion from '@/components/impresion/PruebaImpresion.vue'
import { impresionService, RESULTADOS_PRUEBA, type EquipoImpresion } from '@/services/impresion.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { useEquipoImpresionStore } from '@/stores/equipoImpresion'
import { haceCuanto } from '@/utils/tiempoRelativo'

const store = useEquipoImpresionStore()
const equipos = ref<EquipoImpresion[]>([])
const cargando = ref(true)
const error = ref('')
const procesando = ref<number | null>(null)
let temporizador: ReturnType<typeof setInterval> | null = null

const esteEquipo = computed(() => equipos.value.find((e) => e.id === store.equipo?.id) ?? store.equipo)

async function cargar() {
  try {
    const { data } = await impresionService.equipos()
    equipos.value = data
    error.value = ''
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar los equipos.')
  } finally {
    cargando.value = false
  }
}

onMounted(() => {
  void store.senal().then(cargar)
  temporizador = setInterval(cargar, 30_000)
})
onBeforeUnmount(() => temporizador && clearInterval(temporizador))

// ---- Este equipo ----
const nombreAqui = ref('')
const errorAqui = ref('')
const registrando = ref(false)
const codigoAqui = ref('')

async function registrarAqui() {
  errorAqui.value = ''
  registrando.value = true
  try {
    const { data } = await impresionService.registrarAqui(nombreAqui.value.trim())
    store.adoptar(data.clave, data.equipo)
    nombreAqui.value = ''
    await cargar()
  } catch (e) {
    errorAqui.value = erroresDeValidacion(e).nombre || mensajeDeError(e, 'No pudimos registrar este equipo.')
  } finally {
    registrando.value = false
  }
}

async function vincularAqui() {
  errorAqui.value = ''
  registrando.value = true
  try {
    await store.vincular(codigoAqui.value)
    codigoAqui.value = ''
    await cargar()
  } catch (e) {
    errorAqui.value = erroresDeValidacion(e).codigo || mensajeDeError(e, 'No pudimos vincular este equipo.')
  } finally {
    registrando.value = false
  }
}

// ---- Ajustes de un equipo ----
async function cambiar(equipo: EquipoImpresion, campo: 'imprime_comandas' | 'imprime_comprobantes' | 'al_confirmar' | 'automatico') {
  procesando.value = equipo.id
  error.value = ''
  try {
    const { data } = await impresionService.actualizar(equipo.id, { [campo]: !equipo[campo] })
    Object.assign(equipo, data)
    if (equipo.id === store.equipo?.id) store.aplicar(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos guardar el cambio.')
  } finally {
    procesando.value = null
  }
}

// ---- Renombrar ----
const renombrando = ref<EquipoImpresion | null>(null)
const nuevoNombre = ref('')
const errorNombre = ref('')

function abrirRenombrar(equipo: EquipoImpresion) {
  renombrando.value = equipo
  nuevoNombre.value = equipo.nombre
  errorNombre.value = ''
}

async function guardarNombre() {
  if (!renombrando.value) return
  try {
    const { data } = await impresionService.actualizar(renombrando.value.id, { nombre: nuevoNombre.value.trim() })
    Object.assign(renombrando.value, data)
    if (data.id === store.equipo?.id) store.aplicar(data)
    renombrando.value = null
  } catch (e) {
    errorNombre.value = erroresDeValidacion(e).nombre || mensajeDeError(e, 'No pudimos cambiar el nombre.')
  }
}

// ---- Agregar con código / código nuevo ----
const agregarAbierto = ref(false)
const nombreNuevo = ref('')
const errorNuevo = ref('')
const codigoMostrado = ref<{ nombre: string; codigo: string } | null>(null)

async function crearConCodigo() {
  errorNuevo.value = ''
  try {
    const { data } = await impresionService.crearConCodigo(nombreNuevo.value.trim())
    agregarAbierto.value = false
    codigoMostrado.value = { nombre: data.equipo.nombre, codigo: data.codigo }
    nombreNuevo.value = ''
    await cargar()
  } catch (e) {
    errorNuevo.value = erroresDeValidacion(e).nombre || mensajeDeError(e, 'No pudimos agregar el equipo.')
  }
}

async function nuevoCodigo(equipo: EquipoImpresion) {
  procesando.value = equipo.id
  try {
    const { data } = await impresionService.nuevoCodigo(equipo.id)
    codigoMostrado.value = { nombre: equipo.nombre, codigo: data.codigo }
    await cargar()
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos crear el código.')
  } finally {
    procesando.value = null
  }
}

// ---- Quitar ----
const aQuitar = ref<EquipoImpresion | null>(null)
async function quitar() {
  if (!aQuitar.value) return
  const equipo = aQuitar.value
  try {
    await impresionService.quitar(equipo.id)
    if (equipo.id === store.equipo?.id) store.olvidar()
    aQuitar.value = null
    await cargar()
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos quitar el equipo.')
  }
}

const AJUSTES = [
  { campo: 'imprime_comandas', texto: 'Imprime comandas de cocina' },
  { campo: 'imprime_comprobantes', texto: 'Imprime comprobantes de pago' },
  { campo: 'al_confirmar', texto: 'Abre la comanda al confirmar un pedido' },
  { campo: 'automatico', texto: 'Abre solas las comandas nuevas' },
] as const
</script>

<template>
  <div class="space-y-6">
    <!-- Este equipo -->
    <section class="card p-4 sm:p-5">
      <div class="flex items-start gap-3">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700"><Monitor :size="21" /></span>
        <div class="min-w-0 flex-1">
          <h2 class="text-[15px] font-semibold text-ink">Este equipo</h2>
          <template v-if="store.registrado && esteEquipo">
            <p class="mt-0.5 text-sm text-gray-600">
              Registrado como <strong class="text-ink">«{{ esteEquipo.nombre }}»</strong> ·
              {{ esteEquipo.imprime_comandas && esteEquipo.imprime_comprobantes ? 'imprime comandas y comprobantes' : esteEquipo.imprime_comandas ? 'imprime comandas' : esteEquipo.imprime_comprobantes ? 'imprime comprobantes' : 'no imprime nada todavía' }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
              Última prueba:
              <template v-if="esteEquipo.ultima_prueba_resultado">
                <strong :class="esteEquipo.ultima_prueba_resultado === 'ok' ? 'text-emerald-700' : 'text-red-700'">{{ RESULTADOS_PRUEBA[esteEquipo.ultima_prueba_resultado] }}</strong>,
                {{ haceCuanto(esteEquipo.ultima_prueba_at) }}
              </template>
              <template v-else>todavía no se ha hecho.</template>
            </p>
            <div class="mt-3"><PruebaImpresion @resultado="cargar" /></div>
          </template>
          <template v-else-if="store.registrado">
            <p class="mt-1 text-sm text-gray-500">Revisando este equipo…</p>
          </template>
          <template v-else>
            <p class="mt-0.5 text-sm text-gray-600">
              Este navegador todavía no está registrado. Regístralo si desde aquí se imprimen comandas o comprobantes.
            </p>
            <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
              <BaseInput v-model="nombreAqui" label="Nombre de este equipo" formato="nombre" :maxlength="60" placeholder="Ej. Caja, Cocina" />
              <BaseButton v-escritura :disabled="registrando || nombreAqui.trim().length < 2" @click="registrarAqui">Registrar este equipo</BaseButton>
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
              <BaseInput v-model="codigoAqui" label="¿Tienes un código de vinculación?" formato="entero" :maxlength="6" placeholder="6 números" />
              <BaseButton v-escritura variant="secondary" :disabled="registrando || codigoAqui.length !== 6" @click="vincularAqui">Vincular</BaseButton>
            </div>
            <p v-if="errorAqui" class="mt-2 text-xs text-red-600">{{ errorAqui }}</p>
            <div class="mt-4 border-t border-gray-100 pt-3">
              <p class="mb-2 text-xs text-gray-500">También puedes probar la impresora sin registrar el equipo:</p>
              <PruebaImpresion compacto />
            </div>
          </template>
        </div>
      </div>
    </section>

    <!-- Equipos del local -->
    <section>
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 class="font-display text-lg font-medium text-ink">Equipos del local</h2>
          <p class="text-xs text-gray-500">La señal se actualiza sola. Los cambios le llegan a cada equipo en menos de un minuto.</p>
        </div>
        <BaseButton v-escritura variant="secondary" size="sm" @click="(agregarAbierto = true), (errorNuevo = '')">
          <Plus :size="15" /> Agregar equipo con código
        </BaseButton>
      </div>
      <p v-if="error" class="mt-3 alert-error">{{ error }}</p>
      <div v-if="cargando" class="loading-state">Cargando equipos…</div>
      <div v-else-if="!equipos.length" class="mt-4 empty-state">
        <Printer :size="30" class="mx-auto text-gray-300" />
        <p class="mt-3 text-sm text-gray-500">Todavía no hay equipos registrados.</p>
      </div>
      <ul v-else class="mt-3 grid gap-3 lg:grid-cols-2">
        <li v-for="equipo in equipos" :key="equipo.id" class="card flex flex-col gap-3 p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-ink">
                {{ equipo.nombre }}
                <span v-if="equipo.id === store.equipo?.id" class="text-xs font-normal text-gray-400">(este equipo)</span>
              </p>
              <p class="mt-1 flex items-center gap-1.5 text-xs" :class="equipo.en_linea ? 'text-emerald-700' : 'text-gray-500'">
                <component :is="equipo.en_linea ? Wifi : WifiOff" :size="13" />
                <template v-if="!equipo.vinculado">Esperando que lo vinculen con el código</template>
                <template v-else-if="equipo.en_linea">En línea · {{ haceCuanto(equipo.ultima_senal_at) }}</template>
                <template v-else>Desconectado · última señal {{ haceCuanto(equipo.ultima_senal_at) }}</template>
                <template v-if="equipo.senal_por_usuario"> · sesión de {{ equipo.senal_por_usuario.nombre }}</template>
              </p>
              <p class="mt-0.5 text-xs text-gray-500">
                Última prueba:
                <template v-if="equipo.ultima_prueba_resultado">
                  <span :class="equipo.ultima_prueba_resultado === 'ok' ? 'text-emerald-700' : 'text-red-700'">{{ RESULTADOS_PRUEBA[equipo.ultima_prueba_resultado] }}</span>
                  {{ haceCuanto(equipo.ultima_prueba_at) }}
                </template>
                <template v-else>nunca</template>
              </p>
              <p v-if="equipo.codigo" class="mt-1 text-xs text-sky-700">
                Código para vincular: <strong class="font-mono text-sm tracking-widest">{{ equipo.codigo }}</strong>
              </p>
            </div>
            <span class="size-2.5 shrink-0 rounded-full" :class="equipo.en_linea ? 'bg-emerald-500' : 'bg-gray-300'" aria-hidden="true" />
          </div>

          <div class="grid gap-1.5 rounded-xl bg-gray-50 p-3 text-sm">
            <label v-for="a in AJUSTES" :key="a.campo" class="flex items-center gap-2" :class="{ 'opacity-50': a.campo !== 'imprime_comandas' && a.campo !== 'imprime_comprobantes' && !equipo.imprime_comandas }">
              <input
                v-escritura
                type="checkbox"
                :checked="equipo[a.campo]"
                :disabled="procesando === equipo.id || (a.campo !== 'imprime_comandas' && a.campo !== 'imprime_comprobantes' && !equipo.imprime_comandas)"
                @change="cambiar(equipo, a.campo)"
              />
              {{ a.texto }}
            </label>
          </div>

          <div class="flex flex-wrap gap-2">
            <BaseButton v-escritura variant="ghost" size="sm" @click="abrirRenombrar(equipo)">Cambiar nombre</BaseButton>
            <BaseButton v-escritura variant="ghost" size="sm" :disabled="procesando === equipo.id" @click="nuevoCodigo(equipo)">
              <KeyRound :size="14" /> {{ equipo.vinculado ? 'Vincular otro navegador' : 'Código nuevo' }}
            </BaseButton>
            <BaseButton v-escritura variant="ghost" size="sm" class="!text-red-700 hover:!bg-red-50" @click="aQuitar = equipo">
              <Trash2 :size="14" /> Quitar
            </BaseButton>
          </div>
        </li>
      </ul>
    </section>

    <!-- Agregar con código -->
    <BaseModal v-model="agregarAbierto" title="Agregar equipo con código">
      <p class="text-sm text-gray-600">
        Para la tablet o el PC donde está abierta la sesión de un Mesero (por ejemplo, el de la cocina). Te damos un código de
        6 números para escribirlo en ese equipo.
      </p>
      <div class="mt-4">
        <BaseInput v-model="nombreNuevo" label="Nombre del equipo" formato="nombre" :maxlength="60" placeholder="Ej. Cocina" :error="errorNuevo" />
      </div>
      <div class="mt-5 flex justify-end gap-2">
        <BaseButton variant="ghost" @click="agregarAbierto = false">Volver</BaseButton>
        <BaseButton v-escritura :disabled="nombreNuevo.trim().length < 2" @click="crearConCodigo">Crear código</BaseButton>
      </div>
    </BaseModal>

    <BaseModal :model-value="!!codigoMostrado" title="Código para vincular" @update:model-value="codigoMostrado = null">
      <p class="text-sm text-gray-600">En el equipo <strong class="text-ink">«{{ codigoMostrado?.nombre }}»</strong>:</p>
      <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-gray-600">
        <li>Abre el sistema con cualquier cuenta del personal (también la de un Mesero).</li>
        <li>En <strong>Pedidos</strong>, abajo de las comandas, toca «Impresión de comandas en este equipo».</li>
        <li>Escribe este código en «Vincular con un código»:</li>
      </ol>
      <p class="my-4 text-center font-mono text-4xl font-semibold tracking-[0.3em] text-ink">{{ codigoMostrado?.codigo }}</p>
      <p class="text-xs text-gray-500">Vale 30 minutos y sirve una sola vez.</p>
      <div class="mt-5 flex justify-end"><BaseButton @click="codigoMostrado = null">Listo</BaseButton></div>
    </BaseModal>

    <BaseModal :model-value="!!renombrando" title="Cambiar nombre del equipo" @update:model-value="renombrando = null">
      <BaseInput v-model="nuevoNombre" label="Nombre" formato="nombre" :maxlength="60" :error="errorNombre" />
      <div class="mt-5 flex justify-end gap-2">
        <BaseButton variant="ghost" @click="renombrando = null">Volver</BaseButton>
        <BaseButton v-escritura :disabled="nuevoNombre.trim().length < 2" @click="guardarNombre">Guardar</BaseButton>
      </div>
    </BaseModal>

    <BaseModal :model-value="!!aQuitar" title="Quitar equipo" @update:model-value="aQuitar = null">
      <p class="text-sm text-gray-600">
        «{{ aQuitar?.nombre }}» deja de ser un equipo de impresión: no manda su señal ni recibe ajustes, y vuelve a usar
        las preferencias propias de la franja de comandas. Lo que ya imprimió sigue en el historial.
      </p>
      <div class="mt-5 flex justify-end gap-2">
        <BaseButton variant="ghost" @click="aQuitar = null">Volver</BaseButton>
        <BaseButton v-escritura variant="danger" @click="quitar">Quitar</BaseButton>
      </div>
    </BaseModal>
  </div>
</template>
