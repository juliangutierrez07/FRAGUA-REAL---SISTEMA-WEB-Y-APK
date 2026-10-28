<script setup lang="ts">
// M11 (21/10/2026): la lista de notificaciones que abre la campana. En el teléfono baja desde la
// barra de arriba; en tableta y computador sale al lado del menú lateral. Tocar una la marca como
// leída y lleva a su pantalla (el pedido, la reserva, la caja…).
import { onBeforeUnmount, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { AlertTriangle, BellOff, CalendarClock, CheckCheck, ClipboardList, Settings, Wallet, X } from 'lucide-vue-next'
import { useNotificacionesStore } from '@/stores/notificaciones'
import AvisosPushEquipo from '@/components/common/AvisosPushEquipo.vue'
import type { CategoriaNotificacion, Notificacion } from '@/services/notificacion.service'

const notificaciones = useNotificacionesStore()
const router = useRouter()

const ICONOS: Record<CategoriaNotificacion, { icono: unknown; clase: string }> = {
  pedidos: { icono: ClipboardList, clase: 'bg-brand-50 text-brand-700' },
  reservas: { icono: CalendarClock, clase: 'bg-sky-50 text-sky-700' },
  caja: { icono: Wallet, clase: 'bg-emerald-50 text-emerald-700' },
  operacion: { icono: AlertTriangle, clase: 'bg-amber-50 text-amber-700' },
  sistema: { icono: Settings, clase: 'bg-violet-50 text-violet-700' },
}

function hace(fecha: string) {
  const minutos = Math.max(0, Math.round((Date.now() - new Date(fecha).getTime()) / 60000))
  if (minutos < 1) return 'ahora'
  if (minutos < 60) return `hace ${minutos} min`
  const horas = Math.round(minutos / 60)
  if (horas < 24) return `hace ${horas} h`
  if (horas < 48) return 'ayer'
  return new Date(fecha).toLocaleDateString('es-CO', { timeZone: 'America/Bogota', day: 'numeric', month: 'short' })
}

async function abrir(n: Notificacion) {
  await notificaciones.marcarLeida(n)
  if (n.enlace) {
    notificaciones.abierto = false
    router.push(n.enlace)
  }
}

function alTeclado(e: KeyboardEvent) {
  if (e.key === 'Escape') notificaciones.abierto = false
}

onMounted(() => document.addEventListener('keydown', alTeclado))
onBeforeUnmount(() => document.removeEventListener('keydown', alTeclado))
</script>

<template>
  <Teleport to="body">
    <div v-if="notificaciones.abierto" class="fixed inset-0 z-[60]" @click="notificaciones.abierto = false" />
    <Transition
      enter-active-class="transition duration-150 ease-out"
      leave-active-class="transition duration-100 ease-in"
      enter-from-class="opacity-0 -translate-y-1"
      leave-to-class="opacity-0 -translate-y-1"
    >
      <section
        v-if="notificaciones.abierto"
        class="fixed inset-x-2 top-16 z-[61] flex max-h-[calc(100dvh-5rem)] flex-col overflow-hidden rounded-2xl border border-[#e3ded4] bg-white shadow-2xl md:inset-x-auto md:bottom-4 md:left-[6rem] md:top-auto md:max-h-[min(36rem,calc(100dvh-2rem))] md:w-96 lg:left-[16.75rem]"
        role="dialog"
        aria-label="Notificaciones"
      >
        <header class="flex items-center justify-between gap-2 border-b border-[#efe9df] px-4 py-3">
          <div>
            <h2 class="text-[15px] font-semibold text-ink">Notificaciones</h2>
            <p class="text-xs text-gray-500">
              {{ notificaciones.noLeidas ? `${notificaciones.noLeidas} sin leer` : 'Estás al día' }}
            </p>
          </div>
          <div class="flex items-center gap-1">
            <button
              v-if="notificaciones.noLeidas"
              type="button"
              class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-brand-700 transition hover:bg-brand-50"
              @click="notificaciones.marcarTodas()"
            >
              <CheckCheck :size="15" />
              Marcar todo como leído
            </button>
            <button
              type="button"
              class="flex size-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-ink"
              aria-label="Cerrar notificaciones"
              @click="notificaciones.abierto = false"
            >
              <X :size="17" />
            </button>
          </div>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
          <!-- M11 (28/10/2026, RF-M11-013): los avisos push de este equipo. -->
          <AvisosPushEquipo />
          <p v-if="notificaciones.cargando" class="px-4 py-10 text-center text-sm text-gray-500">Cargando…</p>
          <p v-else-if="notificaciones.error" class="m-4 alert-error">{{ notificaciones.error }}</p>
          <div v-else-if="!notificaciones.lista.length" class="px-4 py-12 text-center">
            <BellOff :size="28" class="mx-auto text-gray-300" />
            <p class="mt-3 text-sm text-gray-500">No tienes notificaciones.</p>
          </div>
          <ul v-else class="divide-y divide-[#f1ece3]">
            <li v-for="n in notificaciones.lista" :key="n.id">
              <button
                type="button"
                class="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-[#fbfaf7]"
                :class="n.leida_at ? '' : 'bg-brand-50/40'"
                @click="abrir(n)"
              >
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl" :class="ICONOS[n.categoria]?.clase">
                  <component :is="ICONOS[n.categoria]?.icono" :size="17" />
                </span>
                <span class="min-w-0 flex-1">
                  <span class="flex items-start justify-between gap-2">
                    <span class="text-sm text-ink" :class="n.leida_at ? 'font-medium' : 'font-semibold'">{{ n.titulo }}</span>
                    <span v-if="!n.leida_at" class="mt-1.5 size-2 shrink-0 rounded-full bg-brand-500" aria-label="Sin leer" />
                  </span>
                  <span class="mt-0.5 block text-xs leading-snug text-gray-600">{{ n.texto }}</span>
                  <span class="mt-1 block text-[11px] text-gray-400">{{ hace(n.created_at) }}</span>
                </span>
              </button>
            </li>
          </ul>
        </div>
      </section>
    </Transition>
  </Teleport>
</template>
