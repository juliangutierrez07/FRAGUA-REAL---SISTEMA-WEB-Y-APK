<script setup lang="ts">
// Endurecimiento (13/10/2026) — Configuración > Revisión antes de producción. La misma lista que
// `php artisan fragua:revisar-produccion`: depuración apagada, HTTPS, cuentas de prueba, correo,
// cola, pasarela y orígenes permitidos. Solo lectura; nunca muestra llaves ni contraseñas.
import { computed, onMounted, ref } from 'vue'
import { CheckCircle2, RefreshCw, ShieldCheck, TriangleAlert, XCircle } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import { configuracionService, type ItemRevisionProduccion } from '@/services/configuracion.service'
import { mensajeDeError } from '@/services/errors'

const items = ref<ItemRevisionProduccion[]>([])
const listo = ref(false)
const cargando = ref(false)
const error = ref('')

const errores = computed(() => items.value.filter((i) => i.nivel === 'error').length)
const avisos = computed(() => items.value.filter((i) => i.nivel === 'aviso').length)
// Lo grave primero.
const ordenados = computed(() => {
  const peso = { error: 0, aviso: 1, ok: 2 } as const
  return [...items.value].sort((a, b) => peso[a.nivel] - peso[b.nivel])
})

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await configuracionService.revisionProduccion()
    items.value = data.items
    listo.value = data.listo
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos hacer la revisión.')
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)
</script>

<template>
  <section class="card p-4 sm:p-5">
    <div class="flex items-start justify-between gap-3">
      <div class="flex items-start gap-3">
        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 sm:size-11">
          <ShieldCheck :size="21" />
        </span>
        <div>
          <h2 class="text-[15px] font-semibold text-ink">Revisión antes de producción</h2>
          <p class="mt-0.5 text-xs text-gray-500">
            <template v-if="!items.length">Revisa la configuración de seguridad del servidor.</template>
            <template v-else-if="listo">Lista para abrir al público<template v-if="avisos"> ({{ avisos }} {{ avisos === 1 ? 'aviso' : 'avisos' }} por revisar)</template>.</template>
            <template v-else>{{ errores }} {{ errores === 1 ? 'punto por corregir' : 'puntos por corregir' }} antes de abrir al público.</template>
          </p>
        </div>
      </div>
      <BaseButton variant="ghost" size="sm" :disabled="cargando" aria-label="Volver a revisar" @click="cargar">
        <RefreshCw :size="14" :class="{ 'animate-spin': cargando }" />
      </BaseButton>
    </div>

    <p v-if="error" class="mt-3 alert-error">{{ error }}</p>

    <ul v-if="items.length" class="mt-4 space-y-2.5 text-sm">
      <li v-for="i in ordenados" :key="i.clave" class="flex items-start gap-2.5">
        <XCircle v-if="i.nivel === 'error'" :size="18" class="mt-0.5 shrink-0 text-red-600" />
        <TriangleAlert v-else-if="i.nivel === 'aviso'" :size="18" class="mt-0.5 shrink-0 text-amber-600" />
        <CheckCircle2 v-else :size="18" class="mt-0.5 shrink-0 text-emerald-600" />
        <div>
          <p class="font-medium text-ink">{{ i.titulo }}</p>
          <p class="text-xs text-gray-500">{{ i.detalle }}</p>
        </div>
      </li>
    </ul>
    <p class="mt-4 text-xs text-gray-500">
      En el servidor, lo mismo se revisa con <code>php artisan fragua:revisar-produccion</code>. En el equipo de
      desarrollo es normal que salgan errores (depuración prendida, sin HTTPS).
    </p>
  </section>
</template>
