<script setup lang="ts">
// M01 — el usuario autenticado (Cliente, Mesero o Administrador) edita sus propios
// datos y cambia su propia contraseña. Dos formularios independientes: cada uno
// envía y valida por separado (AuthController::actualizarPerfil / cambiarPassword).
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { AtSign, CheckCircle2, IdCard, KeyRound, Lock, Mail, Phone, User } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseInput from '@/components/common/BaseInput.vue'
import SesionesLista from '@/components/common/SesionesLista.vue'
import RequisitosContrasena from '@/components/common/RequisitosContrasena.vue'
import { authService } from '@/services/auth.service'
import type { PoliticaSesiones, SesionAbierta } from '@/types/models'
import { useAuthStore } from '@/stores/auth'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'

const auth = useAuthStore()

const ROLES: Record<string, string> = {
  cliente: 'Cliente',
  mesero: 'Mesero',
  administrador: 'Administrador',
}

// ---- Datos del perfil ----
const datos = reactive({ nombre: '', telefono: '', username: '', documento: '' })
// 27/10/2026 (RF-M01-035): el documento es del Cliente; quien se registró antes lo agrega aquí.
const esCliente = computed(() => auth.usuario?.role === 'cliente')

watch(
  () => auth.usuario,
  (usuario) => {
    if (usuario) {
      datos.nombre = usuario.nombre
      datos.telefono = usuario.telefono ?? ''
      datos.username = usuario.username ?? ''
      datos.documento = usuario.documento ?? ''
    }
  },
  { immediate: true },
)

const guardandoDatos = ref(false)
const erroresDatos = ref<Record<string, string>>({})
const errorDatos = ref('')
const exitoDatos = ref(false)

async function guardarDatos() {
  guardandoDatos.value = true
  erroresDatos.value = {}
  errorDatos.value = ''
  exitoDatos.value = false
  try {
    // username siempre se envía: vacío = quitarlo (Fase 3, tarea 3).
    await auth.actualizarPerfil({
      nombre: datos.nombre,
      telefono: datos.telefono || undefined,
      username: datos.username.trim(),
      ...(esCliente.value && datos.documento ? { documento: datos.documento } : {}),
    })
    exitoDatos.value = true
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) {
      erroresDatos.value = campos
    } else {
      errorDatos.value = mensajeDeError(error, 'No pudimos guardar tus datos. Intenta de nuevo.')
    }
  } finally {
    guardandoDatos.value = false
  }
}

// ---- Cambiar contraseña ----
const password = reactive({ actual: '', nueva: '', confirmacion: '' })
const guardandoPassword = ref(false)
const erroresPassword = ref<Record<string, string>>({})
const errorPassword = ref('')
const exitoPassword = ref(false)

async function cambiarPassword() {
  guardandoPassword.value = true
  erroresPassword.value = {}
  errorPassword.value = ''
  exitoPassword.value = false
  try {
    await auth.cambiarPassword({
      password_actual: password.actual,
      password: password.nueva,
      password_confirmation: password.confirmacion,
    })
    exitoPassword.value = true
    cargarSesiones() // el cambio cerró las demás sesiones
    password.actual = ''
    password.nueva = ''
    password.confirmacion = ''
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) {
      erroresPassword.value = campos
    } else {
      errorPassword.value = mensajeDeError(error, 'No pudimos cambiar tu contraseña. Intenta de nuevo.')
    }
  } finally {
    guardandoPassword.value = false
  }
}

// ---- Sesiones abiertas (Fase 3, tarea 4: RF-M01-010/024, HU-M01-010) ----
const sesiones = ref<SesionAbierta[]>([])
const politica = ref<PoliticaSesiones | null>(null)
const cargandoSesiones = ref(false)
const errorSesiones = ref('')
const mensajeSesiones = ref('')
const cerrandoSesion = ref<number | null>(null)
const cerrandoOtras = ref(false)

async function cargarSesiones() {
  cargandoSesiones.value = true
  errorSesiones.value = ''
  try {
    const { data } = await authService.sesiones()
    sesiones.value = data.sesiones
    politica.value = data.politica
  } catch (error) {
    errorSesiones.value = mensajeDeError(error, 'No pudimos cargar tus sesiones.')
  } finally {
    cargandoSesiones.value = false
  }
}

async function cerrarSesion(sesion: SesionAbierta) {
  cerrandoSesion.value = sesion.id
  errorSesiones.value = ''
  mensajeSesiones.value = ''
  try {
    await authService.cerrarSesion(sesion.id)
    sesiones.value = sesiones.value.filter((s) => s.id !== sesion.id)
    mensajeSesiones.value = `Se cerró la sesión de ${sesion.dispositivo}.`
  } catch (error) {
    errorSesiones.value = mensajeDeError(error, 'No pudimos cerrar esa sesión.')
    await cargarSesiones()
  } finally {
    cerrandoSesion.value = null
  }
}

async function cerrarOtras() {
  cerrandoOtras.value = true
  errorSesiones.value = ''
  mensajeSesiones.value = ''
  try {
    const { data } = await authService.cerrarOtrasSesiones()
    mensajeSesiones.value = data.cerradas === 1 ? 'Se cerró 1 sesión.' : `Se cerraron ${data.cerradas} sesiones.`
    await cargarSesiones()
  } catch (error) {
    errorSesiones.value = mensajeDeError(error, 'No pudimos cerrar tus demás sesiones.')
  } finally {
    cerrandoOtras.value = false
  }
}

onMounted(cargarSesiones)
</script>

<template>
  <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
    <div class="mb-8 flex items-center gap-3">
      <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
      <span class="text-[11px] font-medium tracking-[2px] text-brand-700">TU CUENTA</span>
    </div>
    <h1 class="font-display text-4xl font-medium tracking-tight text-ink">Tu perfil</h1>

    <div v-if="auth.usuario" class="mt-8 space-y-8">
      <!-- Datos -->
      <section class="rounded-2xl border border-[#e2dbcf] bg-white p-6">
        <div class="flex items-center justify-between">
          <h2 class="font-display text-lg text-ink">Tus datos</h2>
          <span class="rounded-full bg-brand-500/15 px-3 py-1 text-xs font-medium text-brand-700">
            {{ ROLES[auth.usuario.role] ?? auth.usuario.role }}
          </span>
        </div>

        <form class="mt-5 space-y-4" @submit.prevent="guardarDatos">
          <BaseInput formato="persona" v-model="datos.nombre" label="Nombre" :icon="User" :error="erroresDatos.nombre" />
          <BaseInput label="Correo electrónico" :icon="Mail" :model-value="auth.usuario.email" disabled />
          <div>
            <BaseInput formato="usuario"
              v-model="datos.username"
              label="Nombre de usuario (opcional)"
              placeholder="Ej. carla.p"
              autocomplete="username"
              :icon="AtSign"
              :error="erroresDatos.username"
            />
            <p v-if="!erroresDatos.username" class="mt-1.5 text-xs text-[#6c655c]">
              Puedes iniciar sesión con tu correo o con este nombre. De 3 a 30 caracteres: letras sin tilde, números, punto, guion o guion bajo.
            </p>
          </div>
          <BaseInput formato="celular" v-model="datos.telefono" label="Teléfono (opcional)" :icon="Phone" :error="erroresDatos.telefono" />
          <div v-if="esCliente">
            <p v-if="!auth.usuario.documento" class="mb-2 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-800">
              Agrega tu número de documento para que el mesero te encuentre al pagar y el pedido quede en tu historial.
            </p>
            <BaseInput formato="identificacion" v-model="datos.documento" label="Número de documento" placeholder="Cédula, cédula de extranjería o pasaporte" :icon="IdCard" :error="erroresDatos.documento" />
          </div>

          <p v-if="errorDatos" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorDatos }}</p>
          <p v-if="exitoDatos" class="flex items-center gap-2 rounded-xl bg-green-50 px-4 py-3 text-sm text-green-700">
            <CheckCircle2 :size="16" />
            Tus datos quedaron guardados.
          </p>

          <BaseButton type="submit" :disabled="guardandoDatos">
            {{ guardandoDatos ? 'Guardando…' : 'Guardar cambios' }}
          </BaseButton>
        </form>
      </section>

      <!-- Contraseña -->
      <section class="rounded-2xl border border-[#e2dbcf] bg-white p-6">
        <h2 class="font-display text-lg text-ink">Cambiar contraseña</h2>
        <p class="mt-1 text-xs text-[#6c655c]">Al cambiarla, cerramos tus demás sesiones activas por seguridad.</p>

        <form class="mt-5 space-y-4" @submit.prevent="cambiarPassword">
          <BaseInput
            v-model="password.actual"
            type="password"
            label="Contraseña actual"
            :icon="Lock"
            :error="erroresPassword.password_actual"
          />
          <BaseInput
            v-model="password.nueva"
            type="password"
            label="Nueva contraseña"
            :icon="KeyRound"
            :error="erroresPassword.password"
          />
          <BaseInput v-model="password.confirmacion" type="password" label="Confirmar nueva contraseña" :icon="KeyRound" autocomplete="new-password" />
          <RequisitosContrasena :clave="password.nueva" :confirmacion="password.confirmacion" />

          <p v-if="errorPassword" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorPassword }}</p>
          <p v-if="exitoPassword" class="flex items-center gap-2 rounded-xl bg-green-50 px-4 py-3 text-sm text-green-700">
            <CheckCircle2 :size="16" />
            Tu contraseña se actualizó correctamente.
          </p>

          <BaseButton type="submit" variant="secondary" :disabled="guardandoPassword">
            {{ guardandoPassword ? 'Actualizando…' : 'Cambiar contraseña' }}
          </BaseButton>
        </form>
      </section>

      <!-- Sesiones abiertas (Fase 3, tarea 4) -->
      <section class="rounded-2xl border border-[#e2dbcf] bg-white p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 class="font-display text-lg text-ink">Sesiones abiertas</h2>
            <p class="mt-1 text-xs text-[#6c655c]">Los dispositivos donde tu cuenta está abierta. Si no reconoces uno, ciérralo y cambia tu contraseña.</p>
          </div>
          <BaseButton
            v-if="sesiones.some((s) => !s.actual)"
            variant="secondary"
            size="sm"
            :disabled="cerrandoOtras"
            @click="cerrarOtras"
          >
            {{ cerrandoOtras ? 'Cerrando…' : 'Cerrar las demás' }}
          </BaseButton>
        </div>

        <p v-if="errorSesiones" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorSesiones }}</p>
        <p v-if="mensajeSesiones" class="mt-4 flex items-center gap-2 rounded-xl bg-green-50 px-4 py-3 text-sm text-green-700">
          <CheckCircle2 :size="16" />
          {{ mensajeSesiones }}
        </p>

        <p v-if="cargandoSesiones && !sesiones.length" class="mt-4 text-sm text-[#6c655c]">Cargando sesiones…</p>
        <SesionesLista
          v-else
          class="mt-3"
          :sesiones="sesiones"
          :politica="politica"
          :procesando="cerrandoSesion"
          @cerrar="cerrarSesion"
        />
      </section>
    </div>
  </div>
</template>
