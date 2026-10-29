<script setup lang="ts">
// Seguridad (28/10/2026, RF-M01-037, RN-USR-28): el Cliente confirma que el correo de su cuenta es suyo con el
// código de 6 dígitos que le llega (el primero sale al registrarse). Con el correo confirmado ve sus reservas y
// el mesero lo encuentra por correo al cobrar. Se muestra en Perfil y en «Mis pedidos y reservas».
import { ref } from 'vue'
import { MailCheck } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import { authService } from '@/services/auth.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { useAuthStore } from '@/stores/auth'

const props = withDefaults(defineProps<{ motivo?: string }>(), {
  motivo: 'Así sabemos que el correo es tuyo: con él ves tus reservas y el mesero te encuentra al pagar.',
})
const emit = defineEmits<{ (e: 'confirmado'): void }>()

const auth = useAuthStore()
const codigo = ref('')
const enviando = ref(false)
const verificando = ref(false)
const mensaje = ref('')
const error = ref('')

async function enviar() {
  enviando.value = true
  error.value = ''
  mensaje.value = ''
  try {
    const { data } = await authService.enviarCodigoCorreo()
    mensaje.value = data.message
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos enviar el código. Intenta en unos minutos.')
  } finally {
    enviando.value = false
  }
}

async function verificar() {
  verificando.value = true
  error.value = ''
  mensaje.value = ''
  try {
    const { data } = await authService.verificarCorreo(codigo.value.trim())
    auth.usuario = data.user
    emit('confirmado')
  } catch (e) {
    error.value = erroresDeValidacion(e).codigo ?? mensajeDeError(e, 'No pudimos confirmar el código.')
  } finally {
    verificando.value = false
  }
}
</script>

<template>
  <section class="rounded-2xl border border-brand-200 bg-brand-50/60 p-5 sm:p-6">
    <div class="flex items-start gap-3">
      <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-white text-brand-700 ring-1 ring-brand-200">
        <MailCheck :size="19" />
      </span>
      <div class="min-w-0">
        <h2 class="font-display text-lg text-ink">Confirma tu correo</h2>
        <p class="mt-1 text-sm text-[#5f584f]">
          Te enviamos un código de 6 dígitos a <strong class="break-all text-ink">{{ auth.usuario?.email }}</strong>. {{ props.motivo }}
        </p>
      </div>
    </div>

    <form class="mt-4 flex flex-col gap-2 sm:flex-row" @submit.prevent="verificar">
      <input
        v-model="codigo"
        inputmode="numeric"
        autocomplete="one-time-code"
        maxlength="6"
        placeholder="000000"
        aria-label="Código de 6 dígitos"
        class="field !mt-0 text-center font-mono text-lg tracking-[0.4em] sm:max-w-44"
        @input="codigo = codigo.replace(/\D/g, '')"
      />
      <BaseButton type="submit" variant="primary" :disabled="verificando || codigo.length !== 6">
        {{ verificando ? 'Confirmando…' : 'Confirmar' }}
      </BaseButton>
    </form>
    <p v-if="error" class="mt-2 text-sm text-red-700">{{ error }}</p>
    <p v-else-if="mensaje" class="mt-2 text-sm text-emerald-700">{{ mensaje }}</p>
    <button
      type="button"
      class="mt-3 text-xs font-semibold text-brand-700 underline disabled:opacity-50"
      :disabled="enviando"
      @click="enviar"
    >
      {{ enviando ? 'Enviando…' : '¿No te llegó? Enviar un código nuevo' }}
    </button>
  </section>
</template>
