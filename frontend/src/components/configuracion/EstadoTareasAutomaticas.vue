<script setup lang="ts">
// Fase 4, tarea 1 — Configuración > Tareas automáticas. Muestra si en el servidor están corriendo
// el programador de tareas (cron con `schedule:run`) y el trabajador de la cola (`queue:work`):
// sin ellos no salen los no-show automáticos, los recordatorios ni los avisos por correo, y
// nada más lo delataría. Cada proceso deja un "latido" cada minuto (EstadoSistemaService).
import { onMounted, ref } from 'vue'
import { Activity, CheckCircle2, Mail, RefreshCw, TriangleAlert } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import { configuracionService, type EstadoSistema } from '@/services/configuracion.service'
import { mensajeDeError } from '@/services/errors'

const estado = ref<EstadoSistema | null>(null)
const cargando = ref(false)
const error = ref('')

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await configuracionService.estadoSistema()
    estado.value = data
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos consultar el estado de las tareas automáticas.')
  } finally {
    cargando.value = false
  }
}

function hace(valor: string | null): string {
  if (!valor) return 'nunca'
  const minutos = Math.round((Date.now() - new Date(valor).getTime()) / 60000)
  if (minutos < 1) return 'hace menos de un minuto'
  if (minutos === 1) return 'hace 1 minuto'
  if (minutos < 60) return `hace ${minutos} minutos`
  return new Date(valor).toLocaleString('es-CO', { timeZone: 'America/Bogota', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
}

onMounted(cargar)
</script>

<template>
  <section class="card p-4 sm:p-5">
    <div class="flex items-start justify-between gap-3">
      <div class="flex items-start gap-3">
        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-violet-700 sm:size-11">
          <Activity :size="21" />
        </span>
        <div>
          <h2 class="text-[15px] font-semibold text-ink">Tareas automáticas</h2>
          <p class="mt-0.5 text-xs text-gray-500">
            Procesos del servidor que envían los avisos y hacen las tareas programadas. Se revisan solos cada minuto.
          </p>
        </div>
      </div>
      <BaseButton variant="ghost" size="sm" :disabled="cargando" aria-label="Volver a consultar" @click="cargar">
        <RefreshCw :size="14" :class="{ 'animate-spin': cargando }" />
      </BaseButton>
    </div>

    <p v-if="error" class="mt-3 alert-error">{{ error }}</p>

    <ul v-if="estado" class="mt-4 space-y-2.5 text-sm">
      <li class="flex items-start gap-2.5">
        <CheckCircle2 v-if="estado.programador.activo" :size="18" class="mt-0.5 shrink-0 text-emerald-600" />
        <TriangleAlert v-else :size="18" class="mt-0.5 shrink-0 text-amber-600" />
        <div>
          <p class="font-medium text-ink">Programador de tareas: {{ estado.programador.activo ? 'activo' : 'detenido' }}</p>
          <p class="text-xs text-gray-500">
            Último latido: {{ hace(estado.programador.ultimo_latido) }}.
            <template v-if="!estado.programador.activo">
              Falta el cron del servidor (ver README-ENTREGA, "Programador y cola en el VPS").
            </template>
          </p>
        </div>
      </li>
      <li class="flex items-start gap-2.5">
        <CheckCircle2 v-if="estado.cola.activa" :size="18" class="mt-0.5 shrink-0 text-emerald-600" />
        <TriangleAlert v-else :size="18" class="mt-0.5 shrink-0 text-amber-600" />
        <div>
          <p class="font-medium text-ink">
            Cola de avisos: {{ estado.cola.sincronica ? 'inmediata (sin trabajador)' : estado.cola.activa ? 'activa' : 'detenida' }}
          </p>
          <p class="text-xs text-gray-500">
            <template v-if="!estado.cola.sincronica">Último latido: {{ hace(estado.cola.ultimo_latido) }}. </template>
            <template v-if="estado.cola.pendientes !== null">Pendientes: {{ estado.cola.pendientes }}. </template>
            <template v-if="estado.cola.fallidos">
              <span class="font-semibold text-red-600">Fallidos: {{ estado.cola.fallidos }}.</span>
            </template>
            <template v-if="!estado.cola.activa">Falta el trabajador de la cola (Supervisor) en el servidor.</template>
          </p>
        </div>
      </li>
      <li class="flex items-start gap-2.5">
        <Mail :size="18" class="mt-0.5 shrink-0" :class="estado.correo.envia_de_verdad ? 'text-emerald-600' : 'text-amber-600'" />
        <div>
          <p class="font-medium text-ink">Correo: {{ estado.correo.envia_de_verdad ? 'se envía' : 'solo se registra (no sale)' }}</p>
          <p class="text-xs text-gray-500">Transporte configurado: {{ estado.correo.transporte }}.</p>
        </div>
      </li>
    </ul>
    <p v-else-if="cargando" class="mt-3 text-sm text-gray-500">Consultando…</p>
  </section>
</template>
