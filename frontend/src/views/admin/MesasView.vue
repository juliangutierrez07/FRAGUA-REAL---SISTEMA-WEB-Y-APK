<script setup lang="ts">
// M05 — Vista Administrador > Mesas: alta de mesa, baja lógica (y su reverso, para no
// perder la mesa para siempre) y liberación forzada con motivo (RF-M05-004/009/010/011).
// Espeja MesaController.php / mesa.service.ts.
// Eliminar (24/10/2026; definitivo desde el 27/10/2026): una mesa sin pedidos ni reservas se borra; con
// historial sale de todo y su fila se guarda para los reportes (con contraseña, EliminarModal).
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Armchair, Ban, CalendarClock, LayoutGrid, Pencil, Plus, QrCode, RotateCcw, ShieldAlert, Trash2, Unlock } from 'lucide-vue-next'
import AccionesFila, { type AccionFila } from '@/components/common/AccionesFila.vue'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import EliminarModal from '@/components/common/EliminarModal.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import PestanasSalon from '@/components/common/PestanasSalon.vue'
import QrMesaModal from '@/components/common/QrMesaModal.vue'
import { mesaService } from '@/services/mesa.service'
import type { ResultadoEliminacion } from '@/services/eliminacion.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import type { Mesa } from '@/types/models'

const router = useRouter()

const mesas = ref<Mesa[]>([])
const cargando = ref(true)
const error = ref('')
const procesando = ref<number | null>(null)
const exito = ref('')

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await mesaService.index()
    mesas.value = data
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar las mesas.')
  } finally {
    cargando.value = false
  }
}

function reemplazar(actualizada: Mesa) {
  const i = mesas.value.findIndex((m) => m.id === actualizada.id)
  if (i !== -1) mesas.value[i] = actualizada
}

const ESTADOS: Record<Mesa['estado'], { texto: string; clase: string; icono: string }> = {
  libre: { texto: 'Libre', clase: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', icono: 'bg-emerald-50 text-emerald-600' },
  ocupada: { texto: 'Ocupada', clase: 'bg-amber-50 text-amber-700 ring-amber-600/20', icono: 'bg-amber-50 text-amber-600' },
  reservada: { texto: 'Reservada', clase: 'bg-sky-50 text-sky-700 ring-sky-600/20', icono: 'bg-sky-50 text-sky-600' },
}

// ---- Liberar (normal) ----
async function liberar(mesa: Mesa) {
  procesando.value = mesa.id
  error.value = ''
  try {
    const { data } = await mesaService.liberar(mesa.id)
    reemplazar(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos liberar la mesa.')
  } finally {
    procesando.value = null
  }
}

// ---- Dar de baja / reactivar ----
async function alternarActiva(mesa: Mesa) {
  procesando.value = mesa.id
  error.value = ''
  try {
    const { data } = mesa.activa
      ? await mesaService.desactivar(mesa.id)
      : await mesaService.activar(mesa.id)
    reemplazar(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos actualizar la mesa.')
  } finally {
    procesando.value = null
  }
}

// ---- Editar nombre (Fase 1, tarea 6 — RF-M05-002, HU-M05-002) ----
// El QR no cambia: el código impreso sigue llevando a la misma mesa.
const mesaEditando = ref<Mesa | null>(null)
const nombreEditado = ref('')
const errorEditar = ref('')
const guardandoNombre = ref(false)

function abrirEditar(mesa: Mesa) {
  mesaEditando.value = mesa
  nombreEditado.value = mesa.nombre
  errorEditar.value = ''
}

async function guardarNombre() {
  if (!mesaEditando.value) return
  if (!nombreEditado.value.trim()) {
    errorEditar.value = 'Escribe el nombre de la mesa.'
    return
  }
  guardandoNombre.value = true
  errorEditar.value = ''
  try {
    const { data } = await mesaService.actualizar(mesaEditando.value.id, nombreEditado.value.trim())
    reemplazar(data)
    mesaEditando.value = null
  } catch (e) {
    errorEditar.value = erroresDeValidacion(e).nombre || mensajeDeError(e, 'No pudimos cambiar el nombre.')
  } finally {
    guardandoNombre.value = false
  }
}

// ---- Nueva mesa ----
const modalNuevaAbierto = ref(false)
const nombreNueva = ref('')
const erroresNueva = ref<Record<string, string>>({})
const errorNueva = ref('')
const creando = ref(false)

function abrirNueva() {
  nombreNueva.value = ''
  erroresNueva.value = {}
  errorNueva.value = ''
  modalNuevaAbierto.value = true
}

async function confirmarNueva() {
  creando.value = true
  erroresNueva.value = {}
  errorNueva.value = ''
  try {
    await mesaService.crear({ nombre: nombreNueva.value })
    modalNuevaAbierto.value = false
    await cargar()
  } catch (e) {
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) {
      erroresNueva.value = campos
    } else {
      errorNueva.value = mensajeDeError(e, 'No pudimos crear la mesa.')
    }
  } finally {
    creando.value = false
  }
}

// ---- Liberación forzada (RF-M05-011, con motivo) ----
const mesaForzada = ref<Mesa | null>(null)
const motivoForzada = ref('')
const errorForzada = ref('')
const liberandoForzada = ref(false)

function abrirForzada(mesa: Mesa) {
  mesaForzada.value = mesa
  motivoForzada.value = ''
  errorForzada.value = ''
}

async function confirmarForzada() {
  if (!mesaForzada.value) return
  liberandoForzada.value = true
  errorForzada.value = ''
  try {
    const { data } = await mesaService.liberarForzada(mesaForzada.value.id, motivoForzada.value)
    reemplazar(data)
    mesaForzada.value = null
  } catch (e) {
    const campos = erroresDeValidacion(e)
    errorForzada.value = campos.motivo || mensajeDeError(e, 'No pudimos liberar la mesa.')
  } finally {
    liberandoForzada.value = false
  }
}

// ---- Eliminar (24/10/2026) ----
const mesaAEliminar = ref<Mesa | null>(null)

async function alEliminar(resultado: ResultadoEliminacion) {
  mesaAEliminar.value = null
  exito.value = resultado.mensaje
  await cargar()
}

const contadorTexto = computed(() =>
  mesas.value.length === 1 ? '1 mesa' : `${mesas.value.length} mesas`,
)

// ---- QR de mesa: ver/descargar/imprimir/regenerar (RF-M05-005/006) ----
const mesaQr = ref<Mesa | null>(null)

// Mejora del panel (30/10/2026): las acciones de cada mesa, en botones desde tableta y en una hoja en el teléfono.
function accionesDe(mesa: Mesa): AccionFila[] {
  const ocupado = procesando.value === mesa.id
  const acciones: AccionFila[] = [
    { texto: 'Ver reservas', icono: CalendarClock, soloConsulta: true, alTocar: () => router.push({ name: 'admin-reservas', query: { mesa_id: mesa.id, mesa_nombre: mesa.nombre } }) },
    { texto: 'Ver QR', icono: QrCode, soloConsulta: true, alTocar: () => (mesaQr.value = mesa) },
    { texto: 'Cambiar nombre', icono: Pencil, ancha: true, alTocar: () => abrirEditar(mesa) },
  ]
  if (mesa.activa) {
    if (mesa.estado !== 'libre') {
      acciones.push(
        { texto: 'Liberar', icono: Unlock, deshabilitada: ocupado, alTocar: () => liberar(mesa) },
        { texto: 'Liberar forzada', icono: ShieldAlert, deshabilitada: ocupado, alTocar: () => abrirForzada(mesa) },
      )
    }
    acciones.push({ texto: 'Dar de baja', icono: Ban, ancha: true, peligro: true, deshabilitada: ocupado, alTocar: () => alternarActiva(mesa) })
  } else {
    acciones.push({ texto: 'Reactivar', icono: RotateCcw, ancha: true, destacada: true, deshabilitada: ocupado, alTocar: () => alternarActiva(mesa) })
  }
  acciones.push({
    texto: 'Eliminar mesa',
    icono: Trash2,
    ancha: true,
    peligro: true,
    deshabilitada: ocupado,
    alTocar: () => {
      mesaAEliminar.value = mesa
      exito.value = ''
    },
  })
  return acciones
}

function alCerrarOActualizarQr(actualizada: Mesa | null) {
  if (actualizada) reemplazar(actualizada)
  mesaQr.value = actualizada
}

onMounted(cargar)
</script>

<template>
  <div>
    <PageHeader eyebrow="SALÓN" titulo="Mesas y reservas" descripcion="Alta de mesas, baja lógica y liberación forzada.">
      <template #actions>
        <BaseButton v-escritura variant="primary" @click="abrirNueva">
          <Plus :size="17" />
          Nueva mesa
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Corrección (16/10/2026): acceso directo a todas las reservas. -->
    <PestanasSalon />

    <p v-if="error" class="mt-4 alert-error">{{ error }}</p>
    <p v-if="exito" class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ exito }}</p>

    <div v-if="cargando" class="loading-state">Cargando mesas…</div>

    <div v-else-if="!mesas.length" class="mt-10 empty-state">
      <LayoutGrid :size="32" class="mx-auto text-gray-300" />
      <p class="mt-3 text-sm text-gray-500">Todavía no hay mesas creadas.</p>
    </div>

    <template v-else>
      <p class="mt-4 text-xs text-gray-500">{{ contadorTexto }}</p>
      <!-- Mejora del panel (30/10/2026): en el teléfono, dos tarjetas por fila como las del menú; las acciones
           están en el botón «⋯» (AccionesFila). -->
      <ul class="mt-2 grid grid-cols-2 gap-3 lg:grid-cols-3">
        <li
          v-for="mesa in mesas"
          :key="mesa.id"
          class="card relative flex flex-col gap-2.5 p-3 sm:gap-4 sm:p-5"
          :class="{ 'opacity-60': !mesa.activa }"
        >
          <div class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:gap-3.5">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl sm:size-12 sm:rounded-2xl" :class="ESTADOS[mesa.estado].icono">
              <Armchair :size="20" />
            </span>
            <div class="w-full min-w-0">
              <p class="truncate font-display text-[15px] font-medium leading-tight text-ink sm:text-lg">{{ mesa.nombre }}</p>
              <p class="mt-1.5 flex flex-wrap items-center gap-1.5">
                <span class="badge" :class="ESTADOS[mesa.estado].clase">
                  {{ ESTADOS[mesa.estado].texto }}
                </span>
                <span v-if="!mesa.activa" class="badge bg-red-50 text-red-700 ring-red-600/20">Inactiva</span>
              </p>
            </div>
          </div>
          <p v-if="mesa.qr_code" class="-mt-1 hidden truncate text-xs text-gray-400 sm:block" :title="mesa.qr_code">
            QR: {{ mesa.qr_code }}
          </p>

          <AccionesFila class="absolute right-2 top-2 sm:static sm:mt-auto" rejilla :titulo="mesa.nombre" :acciones="accionesDe(mesa)" />
        </li>
      </ul>
    </template>

    <EliminarModal tipo="mesas" articulo="la mesa" :registro="mesaAEliminar" @cerrar="mesaAEliminar = null" @eliminado="alEliminar" />

    <!-- Cambiar nombre (RF-M05-002) -->
    <BaseModal :model-value="!!mesaEditando" title="Cambiar nombre de la mesa" @update:model-value="mesaEditando = null">
      <form class="space-y-4" @submit.prevent="guardarNombre">
        <label class="block">
          <span class="field-label">Nombre</span>
          <input v-formato="'nombre'" v-model="nombreEditado" type="text" maxlength="255" class="field" />
        </label>
        <p class="text-xs text-gray-500">El código QR no cambia: el que está impreso sigue funcionando.</p>
        <p v-if="errorEditar" class="alert-error">{{ errorEditar }}</p>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="mesaEditando = null">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="guardandoNombre">
            {{ guardandoNombre ? 'Guardando…' : 'Guardar' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>

    <!-- Nueva mesa -->
    <BaseModal v-model="modalNuevaAbierto" title="Nueva mesa">
      <form class="space-y-4" @submit.prevent="confirmarNueva">
        <label class="block">
          <span class="field-label">Nombre</span>
          <input v-formato="'nombre'" maxlength="60"
            v-model="nombreNueva"
            type="text"
            placeholder="Ej. Mesa 7, Terraza 2…"
            class="field"
          />
          <span v-if="erroresNueva.nombre" class="mt-1 block text-xs text-red-600">{{ erroresNueva.nombre }}</span>
        </label>

        <p v-if="errorNueva" class="alert-error">{{ errorNueva }}</p>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="modalNuevaAbierto = false">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="creando">
            {{ creando ? 'Creando…' : 'Crear mesa' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>

    <!-- Liberación forzada (RF-M05-011): con motivo obligatorio, queda en auditoría -->
    <BaseModal :model-value="!!mesaForzada" title="Liberación forzada" @update:model-value="mesaForzada = null">
      <form class="space-y-4" @submit.prevent="confirmarForzada">
        <p class="text-sm text-gray-600">
          Vas a liberar <strong>{{ mesaForzada?.nombre }}</strong> aunque tenga pedidos activos. Esta acción queda
          registrada en la auditoría.
        </p>
        <label class="block">
          <span class="field-label">Motivo</span>
          <textarea v-formato="'texto'" maxlength="255"
            v-model="motivoForzada"
            rows="3"
            placeholder="Ej. El cliente se fue sin avisar y dejó el pedido sin cerrar."
            class="field"
          />
        </label>

        <p v-if="errorForzada" class="alert-error">{{ errorForzada }}</p>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="mesaForzada = null">Cancelar</BaseButton>
          <BaseButton variant="danger" type="submit" :disabled="liberandoForzada">
            {{ liberandoForzada ? 'Liberando…' : 'Liberar de todas formas' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>

    <!-- QR de mesa: ver/descargar/imprimir/regenerar (RF-M05-005/006) -->
    <QrMesaModal :mesa="mesaQr" @update:mesa="alCerrarOActualizarQr" />
  </div>
</template>
