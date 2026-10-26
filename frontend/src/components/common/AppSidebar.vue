<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  LayoutDashboard,
  Users,
  ClipboardList,
  Receipt,
  UtensilsCrossed,
  Armchair,
  CalendarClock,
  Wallet,
  BarChart3,
  Settings,
  ShieldCheck,
  Printer,
  LogOut,
  Menu,
  X,
} from 'lucide-vue-next'
import { useAuth } from '@/composables/useAuth'
import BotonInstalarApp from '@/components/common/BotonInstalarApp.vue'
import BotonNotificaciones from '@/components/common/BotonNotificaciones.vue'
import PanelNotificaciones from '@/components/common/PanelNotificaciones.vue'
import { useNotificacionesStore } from '@/stores/notificaciones'
import LogoNegocio from '@/components/common/LogoNegocio.vue'

// Navegación por rol — cada entrada referencia el módulo que implementa,
// igual que la tabla de README.md de esta carpeta.
const props = defineProps<{ variant: 'admin' | 'mesero' }>()

const { usuario, esSuperAdmin, esSoporte, logout } = useAuth()
// M11 (21/10/2026): la campana pregunta por notificaciones mientras el panel esté abierto.
const notificaciones = useNotificacionesStore()
onMounted(() => notificaciones.iniciar())
onBeforeUnmount(() => notificaciones.detener())
const router = useRouter()
const route = useRoute()

// CORREGIDO (29/09/2026): @click="logout" llamaba solo al store (limpia el token y el
// usuario en memoria) pero nunca navegaba a ningún lado — el layout de Admin/Mesero se
// queda montado y visible con toda la data ya cargada, como si la sesión siguiera activa,
// hasta que el usuario intente ir a otro lado (recién ahí el guard del router lo saca).
// ClienteLayout.vue ya hacía bien esto (cerrarSesion() + router.push) — mismo patrón acá.
async function cerrarSesion() {
  await logout()
  router.push('/login')
}

// RESPONSIVO (rediseño 02/10/2026) — tres modos según el ancho, porque el Mesero y el
// Administrador usan mayormente teléfono o tablet:
//   · < md  (teléfono): barra superior oscura. Mesero → barra de pestañas INFERIOR (el pulgar la
//           alcanza; hay solo 3 secciones). Administrador → menú lateral deslizable (10 secciones
//           no caben en una barra inferior).
//   · md–lg (tablet): "riel" angosto de solo íconos con su etiqueta corta — siempre visible, sin
//           tapar el contenido ni obligar a abrir un menú.
//   · lg+   (laptop/escritorio): barra lateral completa.
const abierto = ref(false)

function cerrar() {
  abierto.value = false
}

// Al navegar (o al cambiar de tamaño de pantalla a tablet) el menú deslizable se cierra solo.
watch(() => route.fullPath, cerrar)
// Con el menú abierto en el teléfono, la página de atrás no se desplaza.
watch(abierto, (si) => document.documentElement.classList.toggle('overflow-hidden', si))
onBeforeUnmount(() => document.documentElement.classList.remove('overflow-hidden'))

interface Enlace {
  to: string
  label: string
  corto: string
  icon: typeof LayoutDashboard
  // Rutas hijas que también cuentan como "estar en esta sección" (p. ej. Reservas vive
  // dentro de "Mesas y Reservas"; "Nuevo pedido" dentro de "Pedidos").
  tambien?: string[]
  // M09 (20/10/2026): la clave del permiso de menú (MenusPorRol del servidor).
  menu: string
}

const enlacesAdmin: Enlace[] = [
  { to: '/admin', label: 'Dashboard', corto: 'Inicio', icon: LayoutDashboard, menu: 'dashboard' },
  { to: '/admin/usuarios', label: 'Usuarios', corto: 'Usuarios', icon: Users, menu: 'usuarios' }, // M01
  { to: '/admin/pedidos', label: 'Pedidos', corto: 'Pedidos', icon: ClipboardList, menu: 'pedidos' }, // M02
  { to: '/admin/compras-gastos', label: 'Compras y Gastos', corto: 'Gastos', icon: Receipt, menu: 'compras_gastos' }, // M03
  { to: '/admin/catalogo', label: 'Catálogo', corto: 'Catálogo', icon: UtensilsCrossed, tambien: ['/admin/disponibilidad'], menu: 'catalogo' }, // M04
  { to: '/admin/mesas', label: 'Mesas y Reservas', corto: 'Mesas', icon: Armchair, tambien: ['/admin/reservas'], menu: 'mesas' }, // M05
  { to: '/admin/caja', label: 'Caja', corto: 'Caja', icon: Wallet, menu: 'caja' }, // M06
  { to: '/admin/reportes', label: 'Reportes', corto: 'Reportes', icon: BarChart3, menu: 'reportes' }, // M08
  { to: '/admin/auditoria', label: 'Auditoría', corto: 'Auditoría', icon: ShieldCheck, menu: 'auditoria' }, // M01 (Fase 3)
  { to: '/admin/configuracion', label: 'Configuración', corto: 'Ajustes', icon: Settings, menu: 'configuracion' }, // M05/M07
  { to: '/admin/impresoras', label: 'Impresoras', corto: 'Impresoras', icon: Printer, menu: 'impresoras' }, // M12
]

const enlacesMesero: Enlace[] = [
  { to: '/mesero', label: 'Pedidos', corto: 'Pedidos', icon: ClipboardList, tambien: ['/mesero/pedidos'], menu: 'pedidos' }, // M02
  { to: '/mesero/mesas', label: 'Mesas', corto: 'Mesas', icon: Armchair, menu: 'mesas' }, // M05
  { to: '/mesero/reservas', label: 'Reservas', corto: 'Reservas', icon: CalendarClock, menu: 'reservas' }, // M05 (Fase 1)
  { to: '/mesero/menu', label: 'Menú', corto: 'Menú', icon: UtensilsCrossed, menu: 'menu' }, // M04 (Fase 1)
  { to: '/mesero/caja', label: 'Cobrar', corto: 'Cobrar', icon: Wallet, menu: 'cobrar' }, // M06
]
// Fase 7, tarea 2 (RF-M03-32): solo el Mesero con permiso de compras y gastos.
const enlaceGastos: Enlace = { to: '/mesero/gastos', label: 'Gastos', corto: 'Gastos', icon: Receipt, menu: 'gastos' }

// M09 (20/10/2026): solo los menús que el SuperAdmin le habilitó a esta cuenta (el SuperAdmin ve todos).
function habilitados(lista: Enlace[]) {
  const permitidos = usuario.value?.menus
  if (esSuperAdmin.value || esSoporte.value || !permitidos) return lista
  return lista.filter((e) => permitidos.includes(e.menu))
}

const enlaces = computed(() => {
  if (props.variant === 'admin') return habilitados(enlacesAdmin)
  return habilitados(usuario.value?.puede_registrar_gastos ? [...enlacesMesero, enlaceGastos] : enlacesMesero)
})

// "/admin" y "/mesero" son el índice de cada zona pero también el PREFIJO de todas las demás
// ("/mesero/mesas" empieza con "/mesero") — por eso el índice solo se marca con coincidencia
// exacta, y el resto también por prefijo (sus subrutas).
function esActivo(item: Enlace) {
  const indice = item.to === '/admin' || item.to === '/mesero'
  const ruta = route.path
  if (ruta === item.to) return true
  if (!indice && ruta.startsWith(item.to + '/')) return true
  return !!item.tambien?.some((p) => ruta === p || ruta.startsWith(p + '/'))
}

// Pantallas que ocupan todo el alto (p. ej. "Nuevo pedido" del Mesero: su propio encabezado,
// categorías y barra del carrito) esconden la barra superior/inferior en teléfono.
const pantallaCompleta = computed(() => !!route.meta.pantallaCompleta)

// M10 (20/10/2026): la cuenta del desarrollador se ve como «Soporte».
const rolTexto = computed(() =>
  props.variant === 'admin' ? (esSuperAdmin.value ? 'SuperAdmin' : esSoporte.value ? 'Soporte' : 'Administrador') : 'Mesero',
)
const iniciales = computed(() =>
  (usuario.value?.nombre ?? rolTexto.value)
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join(''),
)
</script>

<template>
  <!-- Barra superior móvil (< md). Vive en el flujo normal del layout (empuja el contenido
       hacia abajo); el panel deslizable de abajo es lo único que queda "flotando". -->
  <header
    v-if="!pantallaCompleta"
    class="z-30 flex min-h-14 shrink-0 items-center gap-3 bg-ink-900 px-3 pt-[env(safe-area-inset-top)] text-white shadow-[0_1px_0_rgba(255,255,255,0.06)] md:hidden"
  >
    <button
      v-if="variant === 'admin'"
      type="button"
      class="flex size-10 shrink-0 items-center justify-center rounded-xl text-white/80 transition hover:bg-white/10 active:bg-white/15"
      aria-label="Abrir menú"
      @click="abierto = true"
    >
      <Menu :size="22" />
    </button>
    <LogoNegocio
      v-else
      class="ml-1"
      tamano="size-9"
      texto="text-base font-semibold"
      fondo="bg-gradient-to-br from-brand-300 to-brand-600"
      color="text-ink"
    />

    <div class="min-w-0 flex-1 leading-tight">
      <p class="truncate font-display text-lg font-medium text-white">Fragua Real</p>
      <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-brand-400">{{ rolTexto }}</p>
    </div>

    <BotonInstalarApp v-if="variant === 'mesero'" compacto />
    <BotonNotificaciones variante="barra" />
    <button
      type="button"
      class="flex size-10 shrink-0 items-center justify-center rounded-xl text-white/70 transition hover:bg-white/10 hover:text-white active:bg-white/15"
      aria-label="Cerrar sesión"
      @click="cerrarSesion"
    >
      <LogOut :size="19" />
    </button>
  </header>

  <!-- Fondo oscuro del menú deslizable (solo Administrador en teléfono, solo si está abierto) -->
  <Transition
    enter-active-class="transition-opacity duration-200"
    leave-active-class="transition-opacity duration-150"
    enter-from-class="opacity-0"
    leave-to-class="opacity-0"
  >
    <div
      v-if="abierto && variant === 'admin'"
      class="fixed inset-0 z-40 bg-ink/60 backdrop-blur-[2px] md:hidden"
      @click="cerrar"
    />
  </Transition>

  <aside
    class="z-50 flex-col bg-ink-900 text-white transition-transform duration-200 ease-out md:static md:z-auto md:flex md:h-full md:w-[5.5rem] md:shrink-0 md:translate-x-0 lg:w-64"
    :class="
      variant === 'admin'
        ? ['fixed inset-y-0 left-0 flex w-72 max-w-[85vw] pt-[env(safe-area-inset-top)]', abierto ? 'translate-x-0' : '-translate-x-full']
        : 'hidden'
    "
  >
    <div class="flex items-center justify-between gap-3 px-5 pb-4 pt-6 md:justify-center md:px-2 lg:justify-start lg:px-5">
      <div class="flex items-center gap-3">
        <LogoNegocio
          class="shadow-gold"
          tamano="size-10"
          texto="text-lg font-semibold"
          fondo="bg-gradient-to-br from-brand-300 to-brand-600"
          color="text-ink"
        />
        <div class="leading-tight md:hidden lg:block">
          <p class="font-display text-lg font-medium text-white">Fragua Real</p>
          <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-brand-400">{{ rolTexto }}</p>
        </div>
      </div>
      <button
        type="button"
        class="flex size-9 shrink-0 items-center justify-center rounded-xl text-white/50 transition hover:bg-white/10 hover:text-white md:hidden"
        aria-label="Cerrar menú"
        @click="cerrar"
      >
        <X :size="18" />
      </button>
    </div>

    <!-- CORREGIDO (26/10/2026): sin min-h-0 la lista no se encogía en pantallas bajas y empujaba el
         perfil y «Cerrar sesión» fuera de la pantalla; ahora la lista se desplaza y el pie queda fijo. -->
    <nav class="min-h-0 flex-1 space-y-1 overflow-y-auto overscroll-contain px-3 py-2 md:px-2 lg:px-3">
      <router-link
        v-for="item in enlaces"
        :key="item.to"
        :to="item.to"
        :title="item.label"
        class="group relative flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium transition md:flex-col md:gap-1.5 md:px-1 md:py-2.5 lg:flex-row lg:gap-3 lg:px-3 lg:py-2.5"
        :class="esActivo(item) ? 'bg-white/10 text-white' : 'text-white/55 hover:bg-white/5 hover:text-white'"
        active-class=""
        exact-active-class=""
        @click="cerrar"
      >
        <span
          v-if="esActivo(item)"
          class="absolute left-0 top-1/2 h-6 w-1 -translate-y-1/2 rounded-r-full bg-brand-500 md:h-8"
          aria-hidden="true"
        />
        <component
          :is="item.icon"
          :size="20"
          class="shrink-0 transition"
          :class="esActivo(item) ? 'text-brand-400' : 'text-white/50 group-hover:text-white'"
        />
        <span class="md:hidden lg:inline">{{ item.label }}</span>
        <span class="hidden text-[10px] font-semibold leading-tight md:inline lg:hidden">{{ item.corto }}</span>
      </router-link>
    </nav>

    <div class="shrink-0 space-y-1 border-t border-white/10 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] md:px-2 lg:px-3">
      <!-- Mejora (16/10/2026): instalar la app (PWA) en la tableta o el teléfono del personal. -->
      <div class="md:hidden lg:block"><BotonInstalarApp tono="oscuro" /></div>
      <!-- M11: en el teléfono la campana está en la barra de arriba. -->
      <div class="hidden md:block"><BotonNotificaciones variante="lateral" /></div>
      <router-link
        to="/perfil"
        class="flex items-center gap-3 rounded-xl p-2 transition hover:bg-white/5 md:justify-center lg:justify-start"
        title="Mi perfil"
        @click="cerrar"
      >
        <span
          class="flex size-9 shrink-0 items-center justify-center rounded-full bg-ink-700 text-xs font-semibold text-brand-400 ring-1 ring-white/10"
        >
          {{ iniciales }}
        </span>
        <span class="min-w-0 md:hidden lg:block">
          <span class="block truncate text-sm font-medium text-white">{{ usuario?.nombre ?? rolTexto }}</span>
          <span class="block truncate text-xs text-white/45">{{ usuario?.email ?? rolTexto }}</span>
        </span>
      </router-link>
      <button
        type="button"
        class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-white/55 transition hover:bg-red-500/10 hover:text-red-300 md:flex-col md:gap-1 md:px-1 lg:flex-row lg:gap-3 lg:px-3"
        @click="cerrarSesion"
      >
        <LogOut :size="18" />
        <span class="md:text-[10px] md:font-semibold lg:text-sm lg:font-medium">Cerrar sesión</span>
      </button>
    </div>
  </aside>

  <!-- Barra de pestañas inferior (solo Mesero, solo teléfono): las 5 secciones al alcance del
       pulgar. El área segura (env(safe-area-inset-bottom)) evita que la barra de gestos del
       iPhone/Android tape los botones. -->
  <nav
    v-if="variant === 'mesero' && !pantallaCompleta"
    class="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white/95 pb-[env(safe-area-inset-bottom)] shadow-nav backdrop-blur md:hidden"
    aria-label="Navegación principal"
  >
    <div
      class="mx-auto grid h-[4.25rem] max-w-md px-1"
      :style="{ gridTemplateColumns: `repeat(${enlaces.length}, minmax(0, 1fr))` }"
    >
      <router-link
        v-for="item in enlaces"
        :key="item.to"
        :to="item.to"
        class="flex flex-col items-center justify-center gap-1 text-[11px] font-semibold transition active:scale-95"
        :class="esActivo(item) ? 'text-ink' : 'text-gray-400'"
        active-class=""
        exact-active-class=""
      >
        <span
          class="flex h-8 w-11 items-center justify-center rounded-full transition"
          :class="esActivo(item) ? 'bg-brand-100 text-brand-700' : ''"
        >
          <component :is="item.icon" :size="21" />
        </span>
        {{ item.label }}
      </router-link>
    </div>
  </nav>

  <PanelNotificaciones />
</template>
