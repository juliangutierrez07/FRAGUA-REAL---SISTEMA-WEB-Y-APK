import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import type { Usuario } from '@/types/models'
import {
  authService,
  type ActualizarPerfilPayload,
  type CambiarPasswordPayload,
  type LoginPayload,
  type RegistroPayload,
} from '@/services/auth.service'
import { guardarLlaveDeConfianza, llavesDeConfianza } from '@/utils/equipoConfianza'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem('fragua_real_token'))
  const usuario = ref<Usuario | null>(null)

  // CORREGIDO (29/09/2026): el guard de router/index.ts espera este flag antes de
  // decidir nada — Vue Router dispara su navegación inicial (y con ella, el primer
  // beforeEach) desde app.use(router), NO desde app.mount(). Esto pasaba
  // INDEPENDIENTE de que main.ts esperara cargarUsuarioActual() antes de montar: el
  // guard ya se había evaluado con "usuario" todavía en null bastante antes de que
  // esa promesa resolviera (confirmado con logging: el guard corría, redirigía, y
  // SOLO DESPUÉS aparecía el "success" de cargarUsuarioActual). Con el guard viejo
  // (que se saltaba el chequeo de rol si auth.rol era null) esto quedaba
  // enmascarado — dejaba pasar por accidente. Al corregir ESE guard para que niegue
  // en vez de dejar pasar cuando no hay rol, esta carrera se volvió visible: hasta
  // un Mesero legítimo quedaba rebotado a "/" en su primera navegación tras una
  // recarga. "listo" resuelve esa carrera de raíz, esperándola explícitamente.
  const listo = ref(false)

  const estaAutenticado = computed(() => !!token.value)
  const rol = computed(() => usuario.value?.role ?? null)
  // M09 (19/10/2026): el SuperAdmin entra a todo lo del Administrador; lo exclusivo suyo
  // (cuentas de Administrador, nombre y NIT del negocio) se pregunta con esSuperAdmin.
  const esSuperAdmin = computed(() => rol.value === 'superadmin')
  // M10 (20/10/2026): Soporte entra al panel del Administrador solo para consultar; con esSoporte
  // se esconden los botones que cambian algo (el servidor de todos modos los rechaza).
  const esSoporte = computed(() => rol.value === 'soporte')
  const esAdministrador = computed(() => rol.value === 'administrador' || rol.value === 'superadmin' || rol.value === 'soporte')
  /** M09 (20/10/2026): ¿esta cuenta tiene ese menú habilitado? El SuperAdmin y Soporte, siempre. */
  function tieneMenu(clave: string): boolean {
    if (esSuperAdmin.value || esSoporte.value) return true
    const menus = usuario.value?.menus
    return !menus || menus.includes(clave)
  }
  /** El rol para decidir a qué zona entra: el SuperAdmin y Soporte cuentan como Administrador. */
  const rolDeZona = computed(() => (rol.value === 'superadmin' || rol.value === 'soporte' ? 'administrador' : rol.value))

  async function login(payload: LoginPayload) {
    const { data } = await authService.login({ ...payload, equipos_confianza: llavesDeConfianza() })
    guardarLlaveDeConfianza(data.equipo_confianza)
    token.value = data.token
    usuario.value = data.user
    localStorage.setItem('fragua_real_token', data.token)
  }

  // RF-M01-014: autorregistro de Cliente — ya deja la sesión iniciada, igual
  // que login(), porque el backend responde con token en el mismo POST.
  async function registrar(payload: RegistroPayload) {
    const { data } = await authService.registrar(payload)
    token.value = data.token
    usuario.value = data.user
    localStorage.setItem('fragua_real_token', data.token)
  }

  async function cargarUsuarioActual() {
    if (!token.value) {
      listo.value = true
      return
    }
    try {
      const { data } = await authService.me()
      usuario.value = data
    } catch {
      // Un 401 ya lo limpia el interceptor de services/api.ts (evento
      // EVENTO_SESION_INVALIDA); cualquier otro error (red caída, etc.) simplemente
      // deja "usuario" sin cargar — no hay nada más que este método pueda hacer.
    } finally {
      listo.value = true
    }
  }

  // Dispara la carga inicial UNA sola vez, en cuanto se crea este store (el primer
  // useAuthStore() de toda la app — hoy es el guard de router/index.ts) — así no
  // depende de que quien inicialice la app (main.ts) se acuerde de llamarlo.
  cargarUsuarioActual()

  // RF-M01: editar el propio perfil (nombre/teléfono) — refresca el usuario en memoria
  // con la respuesta del backend, sin necesidad de otro GET /auth/me.
  async function actualizarPerfil(payload: ActualizarPerfilPayload) {
    const { data } = await authService.actualizarPerfil(payload)
    usuario.value = data
  }

  // RF-M01: cambiar la propia contraseña. El backend revoca las demás sesiones y
  // devuelve un mensaje (no un token nuevo) — la sesión actual sigue siendo válida.
  async function cambiarPassword(payload: CambiarPasswordPayload) {
    await authService.cambiarPassword(payload)
  }

  async function logout() {
    try {
      await authService.logout()
    } finally {
      token.value = null
      usuario.value = null
      localStorage.removeItem('fragua_real_token')
    }
  }

  return {
    token,
    usuario,
    listo,
    estaAutenticado,
    rol,
    esSuperAdmin,
    esSoporte,
    esAdministrador,
    rolDeZona,
    tieneMenu,
    login,
    registrar,
    cargarUsuarioActual,
    actualizarPerfil,
    cambiarPassword,
    logout,
  }
})
