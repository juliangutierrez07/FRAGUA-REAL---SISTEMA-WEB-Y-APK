<script setup lang="ts">
// M01 — RF-M01-007: iniciar sesión con el correo o el nombre de usuario (Fase 3, tarea 3) y la contraseña.
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, Lock, UserRound } from 'lucide-vue-next'
import BaseInput from '@/components/common/BaseInput.vue'
import BaseButton from '@/components/common/BaseButton.vue'
import { useAuth } from '@/composables/useAuth'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { destinoInterno } from '@/utils/redireccion'

const router = useRouter()
const route = useRoute()
const { login, rol, rolDeZona } = useAuth()

// CORREGIDO (28/09/2026): antes SIEMPRE mandaba a "/" (Home de Cliente) cuando no
// venía de un redirect por el guard — un Mesero/Administrador que entraba por
// /login directo (no por un enlace protegido) quedaba viendo el sitio de Cliente
// sin ninguna pista de cómo llegar a su panel (RF-M01-009: cada rol a su zona).
function rutaSegunRol(): string {
  if (rolDeZona.value === 'administrador') return '/admin'
  if (rol.value === 'mesero') return '/mesero'
  return '/'
}

const identificador = ref('')
const password = ref('')
const cargando = ref(false)
const errorGeneral = ref('')
const erroresCampo = ref<Record<string, string>>({})

async function onSubmit() {
  errorGeneral.value = ''
  erroresCampo.value = {}
  cargando.value = true
  try {
    await login({ login: identificador.value.trim(), password: password.value })
    router.push(destinoInterno(route.query.redirect) ?? rutaSegunRol())
  } catch (e) {
    erroresCampo.value = erroresDeValidacion(e)
    if (Object.keys(erroresCampo.value).length === 0) {
      errorGeneral.value = mensajeDeError(e, 'Usuario o contraseña incorrectos.')
    }
  } finally {
    cargando.value = false
  }
}
</script>

<template>
  <div class="mb-8">
    <span class="text-[11px] font-medium tracking-[2px] text-brand-700">BIENVENIDO DE VUELTA</span>
    <h1 class="mt-2 font-display text-3xl font-medium tracking-tight text-ink">Inicia sesión</h1>
    <p class="mt-2 text-sm text-[#6c655c]">Tus pedidos y reservas, en un solo lugar.</p>
  </div>
  <form class="space-y-5" @submit.prevent="onSubmit">
    <p v-if="route.query.restablecida" class="rounded-xl bg-green-50 px-4 py-3 text-sm text-green-700">
      Tu contraseña quedó actualizada. Ingresa con la nueva.
    </p>
    <!-- 28/10/2026 (RF-M01-038): llega aquí cuando su sesión se cerró mientras usaba la app. -->
    <p v-else-if="route.query.sesion === 'cerrada'" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
      Tu sesión se cerró (desde otro dispositivo o porque pasó mucho tiempo sin usarla). Vuelve a entrar.
    </p>
    <BaseInput formato="correo"
      v-model="identificador"
      type="text"
      label="Correo o nombre de usuario"
      placeholder="tu@correo.com o tu usuario"
      autocomplete="username"
      :icon="UserRound"
      :error="erroresCampo.login"
    />
    <div>
      <BaseInput
        v-model="password"
        type="password"
        label="Contraseña"
        placeholder="••••••••"
        autocomplete="current-password"
        :icon="Lock"
        :error="erroresCampo.password"
      />
      <router-link
        to="/recuperar-password"
        class="mt-2 block text-right text-sm text-[#6c655c] hover:text-brand-700"
      >
        ¿Olvidaste tu contraseña?
      </router-link>
    </div>
    <p v-if="errorGeneral" class="text-sm text-red-600">{{ errorGeneral }}</p>
    <BaseButton type="submit" :disabled="cargando" class="w-full">
      <span class="flex w-full items-center justify-between">
        {{ cargando ? 'Ingresando…' : 'Ingresar' }}
        <ArrowRight :size="16" />
      </span>
    </BaseButton>
    <p class="text-center text-sm text-[#6c655c]">
      ¿Aún no tienes cuenta?
      <router-link :to="{ path: '/registro', query: destinoInterno(route.query.redirect) ? { redirect: destinoInterno(route.query.redirect) } : {} }" class="font-medium text-brand-700 hover:text-brand-800">Crear cuenta</router-link>
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
