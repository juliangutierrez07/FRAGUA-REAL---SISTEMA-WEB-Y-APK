<script setup lang="ts">
// M01 — RF-M01-014: autorregistro, exclusivo para Cliente. RN-USR-04: correo
// único (la validación del lado servidor es la que manda; aquí solo se
// refleja el error 422 que devuelva).
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, AtSign, IdCard, Lock, Mail, Phone, User } from 'lucide-vue-next'
import BaseInput from '@/components/common/BaseInput.vue'
import BaseButton from '@/components/common/BaseButton.vue'
import RequisitosContrasena from '@/components/common/RequisitosContrasena.vue'
import { useAuth } from '@/composables/useAuth'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { destinoInterno } from '@/utils/redireccion'

const router = useRouter()
const route = useRoute()
const { registrar } = useAuth()

const nombre = ref('')
const email = ref('')
const telefono = ref('')
// 27/10/2026 (RF-M01-035): con él, el Mesero encuentra al cliente al cobrar y le deja el pedido en su historial.
const documento = ref('')
const username = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const cargando = ref(false)
const errorGeneral = ref('')
const erroresCampo = ref<Record<string, string>>({})

async function onSubmit() {
  errorGeneral.value = ''
  erroresCampo.value = {}
  cargando.value = true
  try {
    await registrar({
      nombre: nombre.value,
      email: email.value,
      username: username.value.trim() || undefined,
      telefono: telefono.value || undefined,
      documento: documento.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    // Revisión por rol (13/10/2026): quien llegó desde el Checkout vuelve a terminar su pedido.
    router.push(destinoInterno(route.query.redirect) ?? '/')
  } catch (e) {
    erroresCampo.value = erroresDeValidacion(e)
    if (Object.keys(erroresCampo.value).length === 0) {
      errorGeneral.value = mensajeDeError(e, 'No se pudo crear la cuenta.')
    }
  } finally {
    cargando.value = false
  }
}
</script>

<template>
  <div class="mb-6">
    <span class="text-[11px] font-medium tracking-[2px] text-brand-700">EMPIEZA AQUÍ</span>
    <h1 class="mt-2 font-display text-3xl font-medium tracking-tight text-ink">Crea tu cuenta</h1>
    <p class="mt-2 text-sm text-[#6c655c]">Unos pocos datos y todo listo para pedir.</p>
  </div>
  <form class="space-y-4" @submit.prevent="onSubmit">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <BaseInput formato="persona"
        v-model="nombre"
        label="Nombre completo"
        placeholder="Tu nombre y apellido"
        :icon="User"
        :error="erroresCampo.nombre"
      />
      <BaseInput formato="celular"
        v-model="telefono"
        type="tel"
        label="Teléfono (opcional)"
        placeholder="Tu número de celular"
        :icon="Phone"
        :error="erroresCampo.telefono"
      />
    </div>
    <div>
      <BaseInput formato="identificacion"
        v-model="documento"
        label="Número de documento"
        placeholder="Cédula, cédula de extranjería o pasaporte"
        :icon="IdCard"
        :error="erroresCampo.documento"
      />
      <p v-if="!erroresCampo.documento" class="mt-1.5 text-xs text-[#6c655c]">
        Al pagar en el local, dale tu documento (o tu correo o celular) al mesero y el pedido queda en tu historial.
      </p>
    </div>
    <BaseInput formato="correo"
      v-model="email"
      type="email"
      label="Correo electrónico"
      placeholder="tu@correo.com"
      :icon="Mail"
      autocomplete="email"
      :error="erroresCampo.email"
    />
    <!-- Fase 3, tarea 3 (RF-M01-007/011): opcional; con él también se puede iniciar sesión. -->
    <BaseInput formato="usuario"
      v-model="username"
      label="Nombre de usuario (opcional)"
      placeholder="Ej. carla.p — para entrar sin escribir el correo"
      autocomplete="username"
      :icon="AtSign"
      :error="erroresCampo.username"
    />
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <BaseInput
        v-model="password"
        type="password"
        label="Contraseña"
        placeholder="Ej. Hamburguesa2026"
        :icon="Lock"
        :error="erroresCampo.password"
        autocomplete="new-password"
      />
      <BaseInput
        v-model="passwordConfirmation"
        type="password"
        label="Confirmar contraseña"
        placeholder="••••••••"
        :icon="Lock"
        autocomplete="new-password"
      />
    </div>
    <RequisitosContrasena :clave="password" :confirmacion="passwordConfirmation" />
    <p v-if="errorGeneral" class="text-sm text-red-600">{{ errorGeneral }}</p>
    <BaseButton type="submit" :disabled="cargando" class="w-full">
      <span class="flex w-full items-center justify-between">
        {{ cargando ? 'Creando cuenta…' : 'Crear cuenta' }}
        <ArrowRight :size="16" />
      </span>
    </BaseButton>
    <p class="text-center text-sm text-[#6c655c]">
      ¿Ya tienes cuenta?
      <router-link :to="{ path: '/login', query: destinoInterno(route.query.redirect) ? { redirect: destinoInterno(route.query.redirect) } : {} }" class="font-medium text-brand-700 hover:text-brand-800">Inicia sesión</router-link>
    </p>

    <div class="flex items-center gap-3 pt-1">
      <span class="h-px flex-1 bg-[#e9e3d8]" aria-hidden="true" />
      <span class="text-xs text-[#a39c8f]">o</span>
      <span class="h-px flex-1 bg-[#e9e3d8]" aria-hidden="true" />
    </div>
    <router-link
      to="/"
      class="flex items-center justify-center gap-2 text-sm font-medium text-ink transition hover:text-brand-700"
    >
      Continuar como invitado
      <ArrowRight :size="15" />
    </router-link>
  </form>
</template>
