<script setup lang="ts">
// M05 — Mesas del Mesero: ver estado de las mesas, abrirlas/liberarlas manualmente
// (RF-M05-009/010, sin pedido de por medio) y saltar directo a tomar un pedido Local
// en una mesa concreta (reutiliza el modal de PedidosView.vue vía ?mesa=<id>).
// La liberación forzada (RF-M05-011, HU-M05-007) también es del Mesero: exige motivo y deja
// registro en auditoría. Las reservas se gestionan en Mesero > Reservas (Fase 1, tarea 4).
// Fase 1, tarea 3: cada mesa ocupada muestra el total de su cuenta y lo que falta pagar, y
// "Ver cuenta" abre el detalle con el cobro por mesa (RF-M02-L-019/020, HU-M02-006).
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Armchair, Receipt, RotateCcw } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import CuentaMesaModal from '@/components/pedidos/CuentaMesaModal.vue'
import { mesaService } from '@/services/mesa.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import type { Mesa } from '@/types/models'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
// M09 (20/10/2026): sin el menú Cobrar o Reservas, sus botones no se muestran (el servidor también lo bloquea).
const auth = useAuthStore()

const mesas = ref<Mesa[]>([])
const cargando = ref(true)
const error = ref('')
const procesando = ref<number | null>(null)

// borde = franja superior de la tarjeta; icono = fondo del ícono de la mesa; badge = etiqueta.
const ESTADOS: Record<Mesa['estado'], { texto: string; borde: string; icono: string; badge: string; punto: string }> = {
  libre: {
    texto: 'Libre',
    borde: 'border-t-emerald-400',
    icono: 'bg-emerald-50 text-emerald-600',
    badge: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    punto: 'bg-emerald-500',
  },
  ocupada: {
    texto: 'Ocupada',
    borde: 'border-t-amber-400',
    icono: 'bg-amber-50 text-amber-600',
    badge: 'bg-amber-50 text-amber-700 ring-amber-600/20',
    punto: 'bg-amber-500',
  },
  reservada: {
    texto: 'Reservada',
    borde: 'border-t-sky-400',
    icono: 'bg-sky-50 text-sky-600',
    badge: 'bg-sky-50 text-sky-700 ring-sky-600/20',
    punto: 'bg-sky-500',
  },
}

const resumen = computed(() => ({
  libres: mesas.value.filter((m) => m.estado === 'libre').length,
  ocupadas: mesas.value.filter((m) => m.estado === 'ocupada').length,
  reservadas: mesas.value.filter((m) => m.estado === 'reservada').length,
}))

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await mesaService.index()
    // Corrección (19/10/2026): una mesa dada de baja no se opera (también si entra el Administrador).
    mesas.value = data
      .filter((m) => m.activa !== false)
      .sort((a, b) => a.nombre.localeCompare(b.nombre, 'es', { numeric: true }))
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

async function abrir(mesa: Mesa) {
  procesando.value = mesa.id
  error.value = ''
  try {
    const { data } = await mesaService.abrir(mesa.id)
    reemplazar(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos abrir la mesa.')
  } finally {
    procesando.value = null
  }
}

async function liberar(mesa: Mesa) {
  procesando.value = mesa.id
  error.value = ''
  try {
    const { data } = await mesaService.liberar(mesa.id)
    reemplazar(data)
  } catch (e) {
    // El backend responde 422 con el motivo real (p. ej. "tiene pedidos activos") —
    // se muestra tal cual en vez de un mensaje genérico.
    error.value = mensajeDeError(e, 'No pudimos liberar la mesa.')
  } finally {
    procesando.value = null
  }
}

// ---- Liberación forzada (RF-M05-011): con motivo obligatorio, queda en auditoría ----
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

// ---- Cuenta de la mesa (Fase 1, tarea 3) ----
const cuentaDe = ref<number | null>(null)

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function tomarPedido(mesa: Mesa) {
  router.push({ name: 'mesero-nuevo-pedido', query: { mesa: mesa.id } })
}

onMounted(cargar)
</script>

<template>
  <div>
    <PageHeader
      eyebrow="SALÓN"
      titulo="Mesas"
      descripcion="Abre y libera mesas manualmente, o toma un pedido directo en una de ellas."
    />

    <!-- Resumen del salón de un vistazo -->
    <div v-if="!cargando && mesas.length" class="mt-5 flex flex-wrap gap-2">
      <span class="badge bg-emerald-50 text-emerald-700 ring-emerald-600/20">
        <span class="size-1.5 rounded-full bg-emerald-500" />{{ resumen.libres }} libres
      </span>
      <span class="badge bg-amber-50 text-amber-700 ring-amber-600/20">
        <span class="size-1.5 rounded-full bg-amber-500" />{{ resumen.ocupadas }} ocupadas
      </span>
      <span v-if="resumen.reservadas" class="badge bg-sky-50 text-sky-700 ring-sky-600/20">
        <span class="size-1.5 rounded-full bg-sky-500" />{{ resumen.reservadas }} reservadas
      </span>
    </div>

    <p v-if="error" class="mt-4 alert-error">{{ error }}</p>

    <div v-if="cargando" class="loading-state">Cargando mesas…</div>

    <!-- Mejora (28/10/2026): sin mesas creadas la pantalla quedaba en blanco. -->
    <div v-else-if="!mesas.length && !error" class="mt-8 empty-state">
      <Armchair :size="32" class="mx-auto text-gray-300" />
      <p class="mt-3 text-sm font-medium text-ink">Todavía no hay mesas creadas</p>
      <p class="mt-1 text-xs text-gray-500">
        Las crea el Administrador en «Mesas y Reservas». Mientras tanto puedes tomar pedidos de domicilio o recogida.
      </p>
    </div>

    <div v-else class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
      <div
        v-for="mesa in mesas"
        :key="mesa.id"
        class="card flex flex-col items-center border-t-4 p-4 text-center sm:p-5"
        :class="ESTADOS[mesa.estado].borde"
      >
        <span
          class="flex size-14 items-center justify-center rounded-2xl"
          :class="ESTADOS[mesa.estado].icono"
        >
          <Armchair :size="26" />
        </span>
        <p class="mt-3 font-display text-lg font-medium leading-tight text-ink">{{ mesa.nombre }}</p>
        <span class="badge mt-2" :class="ESTADOS[mesa.estado].badge">
          <span class="size-1.5 rounded-full" :class="ESTADOS[mesa.estado].punto" />
          {{ ESTADOS[mesa.estado].texto }}
        </span>
        <p v-if="mesa.estado === 'ocupada' && mesa.cuenta_pedidos" class="mt-2 text-xs text-gray-600">
          Cuenta {{ formatoPrecio(mesa.cuenta_total ?? 0) }}
          <span v-if="(mesa.cuenta_saldo ?? 0) > 0" class="block font-semibold text-ink">
            Debe {{ formatoPrecio(mesa.cuenta_saldo ?? 0) }}
          </span>
          <span v-else class="block font-semibold text-emerald-700">Pagada</span>
        </p>

        <div class="mt-4 flex w-full flex-col gap-2">
          <BaseButton
            v-if="mesa.estado === 'libre' || mesa.estado === 'ocupada'"
            variant="primary"
            size="sm"
            class="w-full"
            :disabled="procesando === mesa.id"
            @click="tomarPedido(mesa)"
          >
            Tomar pedido
          </BaseButton>

          <BaseButton
            v-if="mesa.estado === 'ocupada'"
            variant="secondary"
            size="sm"
            class="w-full"
            :disabled="procesando === mesa.id"
            @click="cuentaDe = mesa.id"
          >
            <Receipt :size="13" />
            Ver cuenta
          </BaseButton>

          <BaseButton
            v-if="mesa.estado === 'libre'"
            variant="ghost"
            size="sm"
            class="w-full"
            :disabled="procesando === mesa.id"
            @click="abrir(mesa)"
          >
            Abrir mesa
          </BaseButton>

          <BaseButton
            v-if="mesa.estado === 'ocupada'"
            variant="ghost"
            size="sm"
            class="w-full hover:!border-red-300 hover:!bg-red-50 hover:!text-red-700"
            :disabled="procesando === mesa.id"
            @click="liberar(mesa)"
          >
            <RotateCcw :size="13" />
            Liberar mesa
          </BaseButton>

          <!-- Si "Liberar" falla porque hay pedidos activos, esta es la salida excepcional. -->
          <button
            v-if="mesa.estado === 'ocupada'"
            type="button"
            class="text-xs font-medium text-gray-500 underline-offset-2 transition hover:text-red-700 hover:underline disabled:opacity-50"
            :disabled="procesando === mesa.id"
            @click="abrirForzada(mesa)"
          >
            Forzar liberación…
          </button>

          <router-link
            v-if="mesa.estado === 'reservada' && auth.tieneMenu('reservas')"
            :to="{ name: 'mesero-reservas', query: { mesa_id: mesa.id, mesa_nombre: mesa.nombre } }"
            class="text-xs font-medium text-brand-700 underline-offset-2 hover:underline"
          >
            Ver reservas · confirmar llegada
          </router-link>
        </div>
      </div>
    </div>

    <CuentaMesaModal :mesa-id="cuentaDe" :puede-cobrar="auth.tieneMenu('cobrar')" @cerrar="cuentaDe = null" @cobrado="cargar" />

    <!-- Liberación forzada (RF-M05-011) -->
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
  </div>
</template>
