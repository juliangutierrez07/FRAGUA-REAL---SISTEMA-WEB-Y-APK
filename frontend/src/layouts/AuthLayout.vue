<script setup lang="ts">
// Layout para /login, /registro, /recuperar-password, /restablecer-password (M01).
// Diseño alineado a la referencia que compartió el negocio: página completa
// en crema (sin panel oscuro), header propio con "Volver al inicio", columna
// de texto + mascota a la izquierda y la tarjeta del formulario a la derecha.
// El texto de la izquierda cambia según la pantalla (login/registro/recuperar/
// restablecer), así que vive aquí mismo indexado por el nombre de la ruta.
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft } from 'lucide-vue-next'
import mascota from '@/assets/img/mascota-fragua-real.png'
import LogoNegocio from '@/components/common/LogoNegocio.vue'

const route = useRoute()

const contenidoPorRuta: Record<string, { eyebrow: string; lineas: [string, string]; subtitulo: string }> = {
  login: {
    eyebrow: 'HECHO PARA TUS ANTOJOS',
    lineas: ['Tu próximo antojo', 'empieza aquí.'],
    subtitulo: 'El sabor de Fragua Real, siempre cerca.',
  },
  registro: {
    eyebrow: 'ÚNETE A FRAGUA REAL',
    lineas: ['Tus antojos,', 'en un solo lugar.'],
    subtitulo: 'Pide, reserva y vuelve por tus favoritos.',
  },
  'recuperar-password': {
    eyebrow: 'ESTAMOS PARA AYUDARTE',
    lineas: ['Vuelve a disfrutar', 'de tus favoritos.'],
    subtitulo: 'Recupera tu acceso y sigue disfrutando.',
  },
  'restablecer-password': {
    eyebrow: 'ÚLTIMO PASO',
    lineas: ['Ya casi', 'está listo.'],
    subtitulo: 'Elige una nueva contraseña y vuelve a pedir.',
  },
}

const contenido = computed(() => contenidoPorRuta[String(route.name)] ?? contenidoPorRuta.login)
</script>

<template>
  <div class="min-h-screen bg-cream">
    <header class="border-b border-[#e9e3d8]">
      <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-5 sm:px-6">
        <router-link to="/" class="flex items-center gap-3">
          <LogoNegocio />
          <span class="flex flex-col leading-tight">
            <span class="font-display text-lg font-medium text-ink">Fragua Real</span>
            <span class="text-[10px] font-medium tracking-[2.6px] text-brand-700">BURGER</span>
          </span>
        </router-link>

        <router-link to="/" class="flex items-center gap-2 text-sm font-medium text-ink transition hover:text-brand-700">
          <ArrowLeft :size="16" />
          Volver al inicio
        </router-link>
      </div>
    </header>

    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:py-14">
      <div class="grid gap-12 lg:grid-cols-2 lg:items-start lg:gap-16">
        <!-- Texto + mascota: fija en pantalla al hacer scroll en formularios
             largos (Registro), para que no se quede "colgando" arriba con un
             hueco vacío debajo mientras se desplaza solo la tarjeta. -->
        <div class="lg:sticky lg:top-10">
          <span class="text-[11px] font-medium tracking-[2px] text-brand-700">{{ contenido.eyebrow }}</span>
          <h1 class="mt-3 font-display text-4xl font-medium leading-[1.1] tracking-tight text-ink sm:text-5xl">
            {{ contenido.lineas[0] }}<br />
            <span class="text-brand-500">{{ contenido.lineas[1] }}</span>
          </h1>
          <p class="mt-4 max-w-sm text-[15px] leading-relaxed text-[#696159]">{{ contenido.subtitulo }}</p>

          <img
            :src="mascota"
            alt="Mascota de Fragua Real Burger saludando con el pulgar arriba"
            class="mx-auto mt-8 w-56 drop-shadow-[0_25px_35px_rgba(47,38,20,0.18)] sm:w-64 lg:mx-0 lg:w-72 xl:w-80"
          />
        </div>

        <!-- Tarjeta del formulario -->
        <div class="rounded-3xl border border-[#e9e4d9] bg-white p-8 shadow-[0_18px_45px_rgba(47,38,20,0.09)] sm:p-10">
          <router-view />
        </div>
      </div>

      <p class="mt-10 text-center text-xs text-[#a39c8f]">Fragua Real Burger · Donde la vida sabe mejor.</p>
    </div>
  </div>
</template>
