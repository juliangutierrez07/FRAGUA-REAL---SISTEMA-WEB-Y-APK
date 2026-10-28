<script setup lang="ts">
// M11 (28/10/2026, RF-M11-013, HU-M11-008): arriba de la lista de la campana, los avisos push de ESTE equipo:
// activarlos, probarlos o apagarlos, y qué hacer si el navegador no puede (iPhone sin instalar, permiso negado).
import { onMounted } from 'vue'
import { BellRing, Smartphone } from 'lucide-vue-next'
import { useAvisosPush } from '@/composables/useAvisosPush'

const { estado, ocupado, mensaje, error, revisar, activar, apagar, probar } = useAvisosPush()

onMounted(revisar)
</script>

<template>
  <div v-if="estado !== 'revisando' && estado !== 'sin-servidor'" class="border-b border-[#efe9df] bg-[#fbfaf7] px-4 py-3">
    <div class="flex items-start gap-3">
      <span
        class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg"
        :class="estado === 'activo' ? 'bg-emerald-50 text-emerald-700' : 'bg-brand-50 text-brand-700'"
      >
        <BellRing v-if="estado === 'activo' || estado === 'inactivo'" :size="16" />
        <Smartphone v-else :size="16" />
      </span>
      <div class="min-w-0 flex-1">
        <p class="text-[13px] font-semibold text-ink">
          {{ estado === 'activo' ? 'Avisos activos en este equipo' : 'Avisos en este equipo' }}
        </p>
        <p class="mt-0.5 text-xs leading-snug text-gray-600">
          <template v-if="estado === 'activo'">Te llegan aunque la app esté cerrada o el teléfono bloqueado.</template>
          <template v-else-if="estado === 'inactivo'">Actívalos para que te lleguen aunque la app esté cerrada o el teléfono bloqueado.</template>
          <template v-else-if="estado === 'bloqueado'">
            Los avisos están bloqueados en este navegador. Permítelos en la configuración del sitio (el candado junto a la
            dirección) y vuelve a abrir la campana.
          </template>
          <template v-else-if="estado === 'iphone-sin-instalar'">
            En iPhone, instala primero la app: en Safari toca Compartir y «Agregar a inicio»; ábrela desde ahí y actívalos.
          </template>
          <template v-else>Este navegador no recibe avisos. Usa Chrome, Edge o Firefox, o la app instalada.</template>
        </p>

        <div v-if="estado === 'inactivo' || estado === 'activo'" class="mt-2 flex flex-wrap gap-2">
          <button
            v-if="estado === 'inactivo'"
            type="button"
            class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-ink transition hover:bg-brand-400 disabled:opacity-60"
            :disabled="ocupado"
            @click="activar"
          >
            {{ ocupado ? 'Activando…' : 'Activar avisos' }}
          </button>
          <template v-else>
            <button
              type="button"
              class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-ink transition hover:border-brand-300 disabled:opacity-60"
              :disabled="ocupado"
              @click="probar"
            >
              Enviar prueba
            </button>
            <button
              type="button"
              class="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-500 transition hover:bg-gray-100 hover:text-ink disabled:opacity-60"
              :disabled="ocupado"
              @click="apagar"
            >
              Apagar
            </button>
          </template>
        </div>
        <p v-if="mensaje" class="mt-2 text-xs text-emerald-700" role="status">{{ mensaje }}</p>
        <p v-if="error" class="mt-2 text-xs text-red-600" role="alert">{{ error }}</p>
      </div>
    </div>
  </div>
</template>
