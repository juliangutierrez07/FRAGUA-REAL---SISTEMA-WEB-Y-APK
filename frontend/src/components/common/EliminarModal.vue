<script setup lang="ts">
// Eliminar (24/10/2026): la misma ventana para mesas, productos y usuarios (y, desde el 27/10/2026,
// categorías, combos, extras y promociones). Antes de pedir la contraseña dice qué va a pasar, o por qué
// no se puede ahora.
// CAMBIO (27/10/2026): eliminar es definitivo (ya no hay «Eliminados» ni restaurar) y el motivo solo se
// pide al eliminar una cuenta (`vista.pide_motivo`). Lo que tenía historial deja de verse, pero los pedidos
// y reportes de antes lo siguen nombrando.
import { ref, watch } from 'vue'
import { ShieldAlert, Trash2 } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseInput from '@/components/common/BaseInput.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import { eliminacionService, type Eliminable, type ResultadoEliminacion, type VistaEliminacion } from '@/services/eliminacion.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'

const props = defineProps<{
  tipo: Eliminable
  /** El registro a eliminar; null = ventana cerrada. */
  registro: { id: number; nombre: string } | null
  /** «la mesa», «el producto», «la cuenta». */
  articulo: string
}>()

const emit = defineEmits<{
  (e: 'cerrar'): void
  (e: 'eliminado', resultado: ResultadoEliminacion): void
}>()

const vista = ref<VistaEliminacion | null>(null)
const cargando = ref(false)
const motivo = ref('')
const password = ref('')
const errores = ref<Record<string, string>>({})
const error = ref('')
const enviando = ref(false)

watch(
  () => props.registro,
  async (registro) => {
    vista.value = null
    motivo.value = ''
    password.value = ''
    errores.value = {}
    error.value = ''
    if (!registro) return
    cargando.value = true
    try {
      const { data } = await eliminacionService.vista(props.tipo, registro.id)
      vista.value = data
    } catch (e) {
      error.value = mensajeDeError(e, 'No pudimos revisar si se puede eliminar.')
    } finally {
      cargando.value = false
    }
  },
)

async function confirmar() {
  if (!props.registro || !vista.value) return
  errores.value = {}
  error.value = ''
  if (vista.value.pide_motivo && motivo.value.trim().length < 3) errores.value.motivo = 'Escribe por qué eliminas la cuenta (al menos 3 letras).'
  if (!password.value) errores.value.password = 'Escribe tu contraseña para confirmar.'
  if (Object.keys(errores.value).length) return
  enviando.value = true
  try {
    const { data } = await eliminacionService.eliminar(props.tipo, props.registro.id, {
      password: password.value,
      motivo: vista.value.pide_motivo ? motivo.value.trim() : undefined,
    })
    emit('eliminado', data)
  } catch (e) {
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) errores.value = campos
    else error.value = mensajeDeError(e, 'No pudimos eliminarlo.')
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="!!registro" :title="`Eliminar ${articulo}`" @update:model-value="emit('cerrar')">
    <p class="text-sm text-gray-600">
      Vas a eliminar <strong class="text-ink">«{{ registro?.nombre }}»</strong>. No se puede deshacer.
    </p>

    <div v-if="cargando" class="mt-4 h-16 animate-pulse rounded-2xl bg-gray-100" />
    <template v-else-if="vista">
      <p v-if="vista.bloqueo" class="mt-4 flex items-start gap-2 rounded-2xl bg-red-50 px-3.5 py-3 text-sm text-red-800">
        <ShieldAlert :size="18" class="mt-px shrink-0" />
        {{ vista.bloqueo }}
      </p>
      <div v-else class="mt-4 flex items-start gap-3 rounded-2xl bg-red-50 px-3.5 py-3 text-sm text-red-800">
        <Trash2 :size="18" class="mt-px shrink-0" />
        <p>{{ vista.detalle }}</p>
      </div>
    </template>

    <template v-if="vista && !vista.bloqueo">
      <label v-if="vista.pide_motivo" class="mt-4 block">
        <span class="mb-1.5 block text-[11px] font-medium uppercase tracking-[0.08em] text-[#6c655c]">Motivo</span>
        <textarea
          v-model="motivo"
          v-formato="'texto'"
          maxlength="255"
          rows="2"
          class="field"
          placeholder="Ej.: ya no trabaja aquí"
        />
        <span v-if="errores.motivo" class="mt-1 block text-xs text-red-600">{{ errores.motivo }}</span>
      </label>
      <div class="mt-3">
        <BaseInput v-model="password" type="password" label="Tu contraseña" autocomplete="current-password" :error="errores.password" />
      </div>
    </template>

    <p v-if="error" class="mt-3 alert-error">{{ error }}</p>

    <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
      <BaseButton variant="ghost" :disabled="enviando" @click="emit('cerrar')">Volver</BaseButton>
      <BaseButton v-if="vista && !vista.bloqueo" v-escritura variant="danger" :disabled="enviando" @click="confirmar">
        {{ enviando ? 'Eliminando…' : 'Eliminar' }}
      </BaseButton>
    </div>
  </BaseModal>
</template>
