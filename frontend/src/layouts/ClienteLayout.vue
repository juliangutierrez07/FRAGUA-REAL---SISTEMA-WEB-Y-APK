<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { LayoutDashboard, LogOut, MapPin, Menu, MessageCircle, PackageSearch, ShoppingBag, User, X } from 'lucide-vue-next'
import { useRoute, useRouter } from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import { useCarritoStore } from '@/stores/carrito'
import BotonInstalarApp from '@/components/common/BotonInstalarApp.vue'
import LogoNegocio from '@/components/common/LogoNegocio.vue'
import { useNegocioStore } from '@/stores/negocio'

// Layout público/Cliente: Home, Menú (M04), Carrito/Checkout (M02/M07),
// Reserva (M05), Mis pedidos y Perfil (M01). Header calcado del Figma
// "Fragua Real Diseños V1" (Home V2 / Menu V2): logo + nombre + nav +
// botón "Mi pedido" con el conteo de líneas del carrito.
//
// CORREGIDO (28/09/2026): Perfil (RF-M01) también vive en este layout para
// Mesero/Administrador (no solo Cliente) — pero antes, un Mesero/Admin que
// llegaba aquí (por ejemplo a /perfil) no tenía forma de volver a su panel
// ni de cerrar sesión, porque ese botón solo existía en AppSidebar
// (Admin/MeseroLayout). Se agrega "Mi panel" + "Cerrar sesión" aquí también.
//
// RESPONSIVO (28/09/2026): la nav de texto y los botones de cuenta solo
// vivían a partir de sm/md, pero no había ningún reemplazo para pantallas
// angostas — un Cliente en /carrito, /checkout, /perfil, etc. no tenía forma
// de llegar a Menú/Reservar desde el header (solo el logo o el footer, hasta
// abajo de la página). Se agrega un menú "drawer" (mismo patrón que
// AppSidebar) por debajo de md, y la nav + los botones de cuenta se juntan a
// partir de ese mismo breakpoint para no dejar un rango intermedio sin nada.
// AJUSTE (16/10/2026): ese breakpoint pasó de md (768 px) a lg (1024 px): en una tablet vertical el
// menú completo no cabía y el botón "Mi pedido" quedaba cortado; ahí se usa el menú hamburguesa.
const { estaAutenticado, rol, rolDeZona, logout } = useAuth()
const carrito = useCarritoStore()
const router = useRouter()

// Corrección (16/10/2026): el carrito se guarda en el navegador. Estas páginas usan el carrito "web"
// (si el guardado era de un pedido por QR de mesa, empieza vacío) y, al volver, se actualiza con el
// catálogo de hoy.
onMounted(() => {
  carrito.usarOrigen('web')
  carrito.actualizarConCatalogo()
})

const rutaPanel = computed(() => (rolDeZona.value === 'administrador' ? '/admin' : rol.value === 'mesero' ? '/mesero' : null))

const menuAbierto = ref(false)

function cerrarMenu() {
  menuAbierto.value = false
}

async function cerrarSesion() {
  menuAbierto.value = false
  await logout()
  router.push('/')
}

const enlaces = [
  { to: '/', texto: 'Inicio' },
  { to: '/menu', texto: 'Menú' },
  { to: '/reservar', texto: 'Reservar' },
  { to: { path: '/', hash: '#contacto' }, texto: 'Contacto' },
]

// CORREGIDO (28/10/2026): «Contacto» es una sección del Inicio (/#contacto) y el router marcaba los dos
// enlaces como activos. Ahora el activo se decide aquí: Contacto solo con #contacto, Inicio sin él.
// Mejora (28/10/2026): en el computador, al agregar algo «Mi pedido» da un salto y muestra debajo qué se
// agregó (en el teléfono lo hace el botón flotante del menú).
const saltoCarrito = ref(false)
const avisoCarrito = ref<string | null>(null)
let temporizadorSalto: ReturnType<typeof setTimeout> | undefined
let temporizadorAviso: ReturnType<typeof setTimeout> | undefined
watch(
  () => carrito.ultimoAgregado?.id,
  () => {
    if (!carrito.ultimoAgregado) return
    saltoCarrito.value = false
    clearTimeout(temporizadorSalto)
    // Espera a que el punto dorado llegue (volarAlCarrito) y deja que la animación vuelva a empezar.
    temporizadorSalto = setTimeout(() => {
      saltoCarrito.value = true
      temporizadorSalto = setTimeout(() => (saltoCarrito.value = false), 500)
    }, 600)
    avisoCarrito.value = carrito.ultimoAgregado.texto
    clearTimeout(temporizadorAviso)
    temporizadorAviso = setTimeout(() => (avisoCarrito.value = null), 3200)
  },
)
onBeforeUnmount(() => {
  clearTimeout(temporizadorSalto)
  clearTimeout(temporizadorAviso)
})

const route = useRoute()
function enlaceActivo(enlace: (typeof enlaces)[number]) {
  const conContacto = route.hash === '#contacto'
  if (enlace.texto === 'Contacto') return route.path === '/' && conContacto
  if (enlace.to === '/') return route.path === '/' && !conContacto
  return typeof enlace.to === 'string' && route.path.startsWith(enlace.to)
}

// Mejora (19/10/2026): dirección, teléfono y WhatsApp salen de Configuración (los cambia el
// Administrador); antes estaban escritos aquí y en el Home.
const negocio = useNegocioStore()
negocio.cargar()
const direccion = computed(() => negocio.datos.direccion)
const telefono = computed(() => negocio.datos.telefono)
const whatsappUrl = computed(() => negocio.whatsappUrl)
const mapaUrl = computed(() => negocio.mapaUrl)
const anioActual = new Date().getFullYear()
</script>

<template>
  <div class="flex min-h-screen flex-col bg-cream">
    <header class="sticky top-0 z-20 border-b border-[#e9e3d8] bg-white">
      <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:gap-6 sm:px-6">
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="flex size-9 shrink-0 items-center justify-center rounded-lg text-ink hover:bg-cream lg:hidden"
            aria-label="Abrir menú"
            @click="menuAbierto = true"
          >
            <Menu :size="20" />
          </button>

          <router-link to="/" class="flex items-center gap-3">
            <LogoNegocio />
            <span class="flex flex-col leading-tight">
              <span class="font-display text-lg font-medium text-ink">Fragua Real</span>
              <span class="text-[10px] font-medium tracking-[2.6px] text-brand-700">BURGER</span>
            </span>
          </router-link>
        </div>

        <nav class="hidden items-center gap-8 text-sm font-medium text-[#625e57] lg:flex">
          <router-link
            v-for="enlace in enlaces"
            :key="enlace.texto"
            :to="enlace.to"
            class="border-b-2 py-1 transition hover:text-ink"
            :class="enlaceActivo(enlace) ? 'border-brand-500 text-ink' : 'border-transparent'"
            active-class=""
            exact-active-class=""
          >
            {{ enlace.texto }}
          </router-link>
        </nav>

        <div class="flex items-center gap-3">
          <!-- Mesero/Administrador: volver a su panel — están viendo estas páginas
               porque Perfil (RF-M01) también vive en este layout, no porque este
               sea "su" sitio. -->
          <router-link
            v-if="rutaPanel"
            :to="rutaPanel"
            class="hidden rounded-full border border-[#ddd7cd] px-4 py-2.5 text-sm font-medium text-ink transition hover:border-brand-500 lg:block"
          >
            Mi panel
          </router-link>

          <!-- "Mis pedidos y reservas" (HU-M05/M07): único acceso persistente, antes solo
               se llegaba desde la pantalla de confirmación del checkout. -->
          <router-link
            v-if="rol === 'cliente'"
            :to="{ name: 'cliente-pedidos' }"
            class="hidden size-10 items-center justify-center rounded-full border border-[#ddd7cd] text-ink transition hover:bg-cream lg:flex"
            title="Mis pedidos y reservas"
          >
            <PackageSearch :size="18" />
          </router-link>

          <router-link
            :to="estaAutenticado ? '/perfil' : '/login'"
            class="hidden size-10 items-center justify-center rounded-full border border-[#ddd7cd] text-ink transition hover:bg-cream lg:flex"
            :title="estaAutenticado ? 'Mi perfil' : 'Ingresar'"
          >
            <User :size="18" />
          </router-link>

          <button
            v-if="estaAutenticado"
            type="button"
            class="hidden size-10 items-center justify-center rounded-full border border-[#ddd7cd] text-ink transition hover:border-red-300 hover:bg-red-50 hover:text-red-700 lg:flex"
            title="Cerrar sesión"
            @click="cerrarSesion"
          >
            <LogOut :size="18" />
          </button>
          <div class="relative">
            <router-link
              to="/carrito"
              data-destino-carrito
              class="flex items-center gap-2 rounded-full border bg-white px-4 py-2.5 text-sm font-medium text-ink transition hover:border-brand-500"
              :class="saltoCarrito ? 'carrito-salto border-brand-500' : 'border-[#ddd7cd]'"
            >
              <ShoppingBag :size="16" />
              <span class="hidden sm:inline">Mi pedido</span>
              <span
                class="flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-500 px-1 text-[11px] font-medium text-ink"
              >
                {{ carrito.totalProductos }}
              </span>
            </router-link>
            <Transition
              enter-active-class="transition duration-200 ease-out"
              enter-from-class="-translate-y-2 opacity-0"
              leave-active-class="transition duration-150 ease-in"
              leave-to-class="-translate-y-2 opacity-0"
            >
              <div
                v-if="avisoCarrito"
                class="absolute right-0 top-full z-30 mt-3 hidden w-80 items-center justify-between gap-3 rounded-2xl bg-ink px-4 py-3 text-sm text-white shadow-[0_12px_30px_rgba(23,21,16,0.35)] lg:flex"
                role="status"
                aria-live="polite"
              >
                <span class="absolute -top-1.5 right-8 size-3 rotate-45 bg-ink" aria-hidden="true" />
                <span class="min-w-0 truncate">{{ avisoCarrito }}</span>
                <router-link to="/carrito" class="shrink-0 font-medium text-brand-400 hover:text-brand-300" @click="avisoCarrito = null">
                  Ver pedido
                </router-link>
              </div>
            </Transition>
          </div>
        </div>
      </div>
    </header>

    <!-- Menú móvil: nav + cuenta, por debajo de md (mismo patrón "drawer" que AppSidebar). -->
    <Transition
      enter-active-class="transition-opacity duration-200"
      leave-active-class="transition-opacity duration-150"
      enter-from-class="opacity-0"
      leave-to-class="opacity-0"
    >
      <div v-if="menuAbierto" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="cerrarMenu" />
    </Transition>

    <!-- CORREGIDO (16/10/2026, producción): "Ingresar" quedaba al fondo del menú, debajo de los
         enlaces, y con h-screen (100vh incluye la barra del navegador del celular) se escondía bajo
         ella. Ahora la cuenta va arriba —para el invitado, un botón "Ingresar" bien visible— y el
         menú mide h-dvh, como AppSidebar. "Cerrar sesión" sigue abajo, respetando el área segura. -->
    <aside
      class="fixed inset-y-0 left-0 z-40 flex h-dvh w-72 max-w-[85vw] flex-col bg-white transition-transform duration-200 ease-out lg:hidden"
      :class="menuAbierto ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex items-center justify-between border-b border-[#e9e3d8] px-4 py-5">
        <router-link to="/" class="flex items-center gap-3" @click="cerrarMenu">
          <LogoNegocio tamano="size-10" texto="text-base" />
          <span class="flex flex-col leading-tight">
            <span class="font-display text-base font-medium text-ink">Fragua Real</span>
            <span class="text-[9px] font-medium tracking-[2.4px] text-brand-700">BURGER</span>
          </span>
        </router-link>
        <button
          type="button"
          class="flex size-8 shrink-0 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600"
          aria-label="Cerrar menú"
          @click="cerrarMenu"
        >
          <X :size="18" />
        </button>
      </div>

      <div v-if="!estaAutenticado" class="space-y-2 border-b border-[#e9e3d8] p-4">
        <router-link
          to="/login"
          class="flex items-center justify-center gap-2 rounded-full bg-brand-500 px-4 py-3 text-sm font-semibold text-ink transition hover:bg-brand-400"
          @click="cerrarMenu"
        >
          <User :size="18" />
          Ingresar
        </router-link>
        <router-link
          to="/registro"
          class="block text-center text-sm font-medium text-brand-700 hover:underline"
          @click="cerrarMenu"
        >
          Crear cuenta
        </router-link>
      </div>

      <div v-else class="space-y-1 border-b border-[#e9e3d8] p-2">
        <router-link
          v-if="rutaPanel"
          :to="rutaPanel"
          class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-[#3f3b35] hover:bg-gray-100"
          @click="cerrarMenu"
        >
          <LayoutDashboard :size="18" />
          Mi panel
        </router-link>
        <router-link
          v-if="rol === 'cliente'"
          :to="{ name: 'cliente-pedidos' }"
          class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-[#3f3b35] hover:bg-gray-100"
          @click="cerrarMenu"
        >
          <PackageSearch :size="18" />
          Mis pedidos y reservas
        </router-link>
        <router-link
          to="/perfil"
          class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-[#3f3b35] hover:bg-gray-100"
          @click="cerrarMenu"
        >
          <User :size="18" />
          Mi perfil
        </router-link>
      </div>

      <nav class="flex-1 space-y-1 overflow-y-auto px-2 py-3">
        <router-link
          v-for="enlace in enlaces"
          :key="enlace.texto"
          :to="enlace.to"
          class="flex items-center rounded-lg px-3 py-2.5 text-sm font-medium hover:bg-brand-50 hover:text-brand-700"
          :class="enlaceActivo(enlace) ? 'bg-brand-50 text-brand-700' : 'text-[#3f3b35]'"
          active-class=""
          exact-active-class=""
          @click="cerrarMenu"
        >
          {{ enlace.texto }}
        </router-link>
        <!-- Mejora (16/10/2026): instalar la app (PWA). -->
        <BotonInstalarApp />
      </nav>

      <div v-if="estaAutenticado" class="border-t border-[#e9e3d8] p-2 pb-[max(0.5rem,env(safe-area-inset-bottom))]">
        <button
          type="button"
          class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-[#3f3b35] hover:bg-red-50 hover:text-red-700"
          @click="cerrarSesion"
        >
          <LogOut :size="18" />
          Cerrar sesión
        </button>
      </div>
    </aside>

    <!-- CORREGIDO (16/10/2026): con poco contenido (o mientras carga) el pie de página subía hasta la
         mitad de la pantalla. El contenido ocupa al menos el alto visible menos el encabezado, así el
         pie siempre empieza abajo, en todas las páginas del cliente. -->
    <main class="min-h-[calc(100dvh-4.5rem)] flex-1">
      <router-view />
    </main>

    <!-- Mejora de diseño (16/10/2026): en el celular el pie iba centrado y en una sola columna, largo y
         con mucho espacio vacío. Ahora va alineado a la izquierda, con dos botones a la mano
         (WhatsApp y cómo llegar), los enlaces en dos columnas y el contacto en tarjetas que se tocan
         fácil. En tablet y escritorio se reparte en columnas. -->
    <footer class="border-t border-white/10 bg-ink text-white">
      <div class="mx-auto max-w-6xl px-4 pb-[max(1.5rem,env(safe-area-inset-bottom))] pt-10 sm:px-6 sm:pt-14">
        <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-[1.3fr_1fr_1.2fr] lg:gap-12">
          <!-- Marca y acciones -->
          <div class="sm:col-span-2 lg:col-span-1">
            <router-link to="/" class="inline-flex items-center gap-3">
              <LogoNegocio fondo="bg-white/10" />
              <span class="flex flex-col leading-tight">
                <span class="font-display text-lg font-medium text-white">Fragua Real</span>
                <span class="text-[10px] font-medium tracking-[2.6px] text-brand-500">BURGER</span>
              </span>
            </router-link>
            <p class="mt-4 max-w-sm text-sm leading-relaxed text-[#d4d0c9]">
              Hamburguesas, perros y mucho más, preparados al momento. Pide a domicilio, recoge en el local o
              reserva tu mesa.
            </p>
            <div class="mt-5 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:gap-3">
              <a
                :href="whatsappUrl"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-500 px-4 py-3 text-sm font-medium text-ink transition hover:bg-brand-400"
              >
                <MessageCircle :size="16" />
                WhatsApp
              </a>
              <a
                :href="mapaUrl"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/20 px-4 py-3 text-sm font-medium text-white transition hover:border-brand-500 hover:text-brand-400"
              >
                <MapPin :size="16" />
                Cómo llegar
              </a>
            </div>
          </div>

          <!-- Explorar -->
          <div>
            <p class="text-[11px] font-semibold tracking-[2px] text-brand-500">EXPLORAR</p>
            <nav class="mt-3 grid grid-cols-2 gap-x-4 text-sm text-[#d4d0c9] sm:grid-cols-1">
              <router-link
                v-for="enlace in enlaces"
                :key="enlace.texto"
                :to="enlace.to"
                class="py-2 transition hover:text-white"
              >
                {{ enlace.texto }}
              </router-link>
              <router-link to="/carrito" class="py-2 transition hover:text-white">Mi pedido</router-link>
            </nav>
          </div>

          <!-- Contacto -->
          <div>
            <p class="text-[11px] font-semibold tracking-[2px] text-brand-500">CONTACTO</p>
            <div class="mt-3 space-y-2">
              <a
                :href="mapaUrl"
                target="_blank"
                rel="noopener"
                class="flex items-start gap-3 rounded-xl bg-white/5 p-3 text-sm text-[#d4d0c9] transition hover:bg-white/10 hover:text-white"
              >
                <MapPin :size="18" class="mt-0.5 shrink-0 text-brand-500" />
                <span>
                  <span class="block text-[11px] uppercase tracking-[0.08em] text-[#8b8579]">Dirección</span>
                  {{ direccion }}
                </span>
              </a>
              <a
                :href="whatsappUrl"
                target="_blank"
                rel="noopener"
                class="flex items-start gap-3 rounded-xl bg-white/5 p-3 text-sm text-[#d4d0c9] transition hover:bg-white/10 hover:text-white"
              >
                <MessageCircle :size="18" class="mt-0.5 shrink-0 text-brand-500" />
                <span>
                  <span class="block text-[11px] uppercase tracking-[0.08em] text-[#8b8579]">WhatsApp</span>
                  {{ telefono }}
                </span>
              </a>
            </div>
          </div>
        </div>

        <div class="mt-10 flex flex-col gap-1.5 border-t border-white/10 pt-5 sm:flex-row sm:items-center sm:justify-between">
          <p class="font-display text-base text-brand-400">El antojo tiene corona.</p>
          <p class="text-xs text-[#8b8579]">© {{ anioActual }} Fragua Real Burger. Todos los derechos reservados.</p>
        </div>
      </div>
    </footer>
  </div>
</template>

<style scoped>
/* Mejora (28/10/2026): salto de «Mi pedido» al agregar un producto (computador). */
@keyframes carrito-salto {
  0%,
  100% {
    transform: scale(1);
  }
  35% {
    transform: scale(1.12);
  }
  65% {
    transform: scale(0.96);
  }
}
.carrito-salto {
  animation: carrito-salto 0.5s ease-out;
  box-shadow: 0 0 0 4px rgba(214, 164, 58, 0.18);
}
@media (prefers-reduced-motion: reduce) {
  .carrito-salto {
    animation: none;
  }
}
</style>
