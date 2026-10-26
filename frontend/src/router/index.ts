import { watch } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import type { Rol } from '@/types/models'

// Espera a que termine la primera carga de sesión (auth.listo — ver stores/auth.ts)
// antes de dejar seguir la navegación. Sin esto, el primer beforeEach (que Vue Router
// dispara desde app.use(router) en main.ts, antes de que exista tiempo para que
// cargarUsuarioActual() resuelva) leería auth.rol todavía en null.
function esperarSesionLista(auth: ReturnType<typeof useAuthStore>) {
  if (auth.listo) return Promise.resolve()
  return new Promise<void>((resolve) => {
    const detener = watch(
      () => auth.listo,
      (listo) => {
        if (listo) {
          detener()
          resolve()
        }
      },
    )
  })
}

const router = createRouter({
  history: createWebHistory(),
  // Permite enlaces con ancla (p. ej. el "Contacto" del header, que apunta a
  // #contacto en el Home) y deja el scroll donde estaba al usar atrás/adelante.
  scrollBehavior(to, _from, savedPosition) {
    if (savedPosition) return savedPosition
    if (to.hash) return { el: to.hash, behavior: 'smooth' }
    return { top: 0 }
  },
  routes: [
    // RF-M02-L-025 a 028: pedido por QR — página propia, sin ClienteLayout (sin nav,
    // login ni footer: el Cliente llegó escaneando el papel de la mesa, no navegando
    // el sitio). Antes de la ruta comodín de abajo, pero fuera de los demás grupos.
    {
      path: '/mesa/:qr_code',
      name: 'mesa-qr',
      component: () => import('@/views/cliente/MesaQrView.vue'),
    },

    // ---- Público / Cliente (M02, M04, M05, M07) ----
    {
      path: '/',
      component: () => import('@/layouts/ClienteLayout.vue'),
      children: [
        { path: '', name: 'home', component: () => import('@/views/HomeView.vue') },
        { path: 'menu', name: 'menu', component: () => import('@/views/cliente/MenuView.vue') },
        { path: 'carrito', name: 'carrito', component: () => import('@/views/cliente/CarritoView.vue') },
        { path: 'checkout', name: 'checkout', component: () => import('@/views/cliente/CheckoutView.vue') },
        { path: 'reservar', name: 'reservar', component: () => import('@/views/cliente/ReservaView.vue') },
        // Fase 1, tarea 6 (RF-M07-027, RF-M07-R-015): seguimiento del invitado con su enlace privado.
        {
          path: 'seguimiento/:token',
          name: 'seguimiento-pedido',
          component: () => import('@/views/cliente/SeguimientoView.vue'),
        },
        // Fase 4, tarea 4 (RF-M05-018): ver o cancelar una reserva con el enlace del correo, sin cuenta.
        {
          path: 'reserva/:token',
          name: 'reserva-enlace',
          component: () => import('@/views/cliente/ReservaEnlaceView.vue'),
        },
        // Fase 6, tarea 1 (RF-M06-006): a donde vuelve el Cliente desde la pasarela (Wompi agrega ?id=).
        {
          path: 'pago/retorno/:referencia',
          name: 'pago-retorno',
          component: () => import('@/views/cliente/PagoRetornoView.vue'),
        },
        {
          path: 'pedidos',
          name: 'cliente-pedidos',
          component: () => import('@/views/cliente/PedidosView.vue'),
          meta: { requiereAuth: true, roles: ['cliente'] as Rol[] },
        },
        {
          path: 'perfil',
          name: 'perfil',
          component: () => import('@/views/cliente/PerfilView.vue'),
          meta: { requiereAuth: true, roles: ['cliente', 'administrador', 'mesero'] as Rol[] },
        },
      ],
    },

    // ---- Auth (M01) ----
    {
      path: '/',
      component: () => import('@/layouts/AuthLayout.vue'),
      meta: { soloInvitado: true },
      children: [
        { path: 'login', name: 'login', component: () => import('@/views/auth/LoginView.vue') },
        { path: 'registro', name: 'registro', component: () => import('@/views/auth/RegisterView.vue') },
        {
          path: 'recuperar-password',
          name: 'recuperar-password',
          component: () => import('@/views/auth/RecuperarPasswordView.vue'),
        },
        {
          path: 'restablecer-password',
          name: 'restablecer-password',
          component: () => import('@/views/auth/RestablecerPasswordView.vue'),
        },
      ],
    },

    // ---- Administrador (M01, M03-M06, M08) ----
    {
      path: '/admin',
      component: () => import('@/layouts/AdminLayout.vue'),
      meta: { requiereAuth: true, roles: ['administrador'] as Rol[] },
      children: [
        { path: '', name: 'admin-dashboard', meta: { menu: 'dashboard' }, component: () => import('@/views/admin/DashboardView.vue') },
        { path: 'usuarios', name: 'admin-usuarios', meta: { menu: 'usuarios' }, component: () => import('@/views/admin/UsuariosView.vue') },
        { path: 'pedidos', name: 'admin-pedidos', meta: { menu: 'pedidos' }, component: () => import('@/views/admin/PedidosView.vue') },
        {
          path: 'compras-gastos',
          name: 'admin-compras-gastos',
          meta: { menu: 'compras_gastos' },
          component: () => import('@/views/admin/ComprasGastosView.vue'),
        },
        { path: 'catalogo', name: 'admin-catalogo', meta: { menu: 'catalogo' }, component: () => import('@/views/admin/CatalogoView.vue') },
        // Fase 1, tarea 5: la misma pantalla de disponibilidad del Mesero (RF-M04-016/017).
        {
          path: 'disponibilidad',
          name: 'admin-disponibilidad',
          meta: { menu: 'catalogo' },
          component: () => import('@/views/mesero/DisponibilidadView.vue'),
        },
        { path: 'mesas', name: 'admin-mesas', meta: { menu: 'mesas' }, component: () => import('@/views/admin/MesasView.vue') },
        { path: 'reservas', name: 'admin-reservas', meta: { menu: 'mesas' }, component: () => import('@/views/admin/ReservasView.vue') },
        { path: 'caja', name: 'admin-caja', meta: { menu: 'caja' }, component: () => import('@/views/admin/CajaView.vue') },
        { path: 'reportes', name: 'admin-reportes', meta: { menu: 'reportes' }, component: () => import('@/views/admin/ReportesView.vue') },
        // Fase 3, tarea 1 (RF-M01-025, HU-M01-020): auditoría y eventos de seguridad, solo lectura.
        { path: 'auditoria', name: 'admin-auditoria', meta: { menu: 'auditoria' }, component: () => import('@/views/admin/AuditoriaView.vue') },
        {
          path: 'configuracion',
          name: 'admin-configuracion',
          meta: { menu: 'configuracion' },
          component: () => import('@/views/admin/ConfiguracionView.vue'),
        },
        // M12 (24/10/2026): equipos que imprimen, formato del ticket, prueba y estado.
        { path: 'impresoras', name: 'admin-impresoras', meta: { menu: 'impresoras' }, component: () => import('@/views/admin/ImpresorasView.vue') },
      ],
    },

    // ---- Mesero (M02, M05, M06) ----
    {
      path: '/mesero',
      component: () => import('@/layouts/MeseroLayout.vue'),
      meta: { requiereAuth: true, roles: ['mesero'] as Rol[] },
      children: [
        { path: '', name: 'mesero-pedidos', component: () => import('@/views/mesero/PedidosView.vue') },
        {
          path: 'pedidos/nuevo',
          name: 'mesero-nuevo-pedido',
          component: () => import('@/views/mesero/NuevoPedidoView.vue'),
          // Pantalla completa: trae su propio encabezado y barra del carrito — el layout
          // esconde la barra superior/inferior de móvil y no le pone márgenes (MeseroLayout).
          meta: { pantallaCompleta: true },
        },
        { path: 'mesas', name: 'mesero-mesas', meta: { menu: 'mesas' }, component: () => import('@/views/mesero/MesasView.vue') },
        { path: 'caja', name: 'mesero-caja', meta: { menu: 'cobrar' }, component: () => import('@/views/mesero/CajaView.vue') },
        // Fase 1, tarea 4 (HU-M05-009/010): la misma pantalla de Reservas del Administrador,
        // que se adapta al rol (el Mesero no ve los depósitos retenidos, RF-M05-035).
        { path: 'reservas', name: 'mesero-reservas', meta: { menu: 'reservas' }, component: () => import('@/views/admin/ReservasView.vue') },
        // Fase 1, tarea 5 (HU-M04-009/010/013): agotado/disponible y búsqueda del menú.
        { path: 'menu', name: 'mesero-menu', meta: { menu: 'menu' }, component: () => import('@/views/mesero/DisponibilidadView.vue') },
        // Fase 7, tarea 2 (RNF-TRANS-012, RF-M03-32): solo con el permiso de compras y gastos.
        {
          path: 'gastos',
          name: 'mesero-gastos',
          component: () => import('@/views/mesero/GastosView.vue'),
          meta: { permisoGastos: true, menu: 'gastos' },
        },
      ],
    },

    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue') },
  ],
})

// Guard de autenticación y rol (RF-M01-009: restringir funcionalidades según
// rol y permisos). La autorización fina de cada acción sigue viviendo en el
// backend (Policies) — este guard solo evita navegar a una zona equivocada.
router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await esperarSesionLista(auth)

  // Mejora (16/10/2026): la app instalada abre en "/?origen=app" (manifest.webmanifest). El Mesero y el
  // Administrador entran directo a su panel; el Cliente, al inicio. La marca se quita de la dirección.
  if (to.query.origen === 'app') {
    const { origen: _origen, ...resto } = to.query
    if (to.path === '/' && auth.rol === 'mesero') return { name: 'mesero-pedidos', replace: true }
    if (to.path === '/' && auth.esAdministrador) return { name: 'admin-dashboard', replace: true }
    return { path: to.path, query: resto, hash: to.hash, replace: true }
  }

  if (to.meta.soloInvitado && auth.estaAutenticado) {
    return { name: 'home' }
  }

  if (to.meta.requiereAuth && !auth.estaAutenticado) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // CORREGIDO (29/09/2026): "auth.rol && ..." dejaba pasar SIN chequear el rol cuando
  // auth.rol todavía era null (ej. justo después de que el interceptor 401 de api.ts
  // limpia la sesión, o en cualquier estado donde el token diga "autenticado" pero el
  // usuario aún no cargó) — una ruta con roles restringidos requiere un rol conocido y
  // permitido, nunca "sin rol = dejar pasar".
  const rolesPermitidos = to.meta.roles as Rol[] | undefined
  // M09 (19/10/2026): el SuperAdmin entra a las zonas del Administrador (rolDeZona).
  if (rolesPermitidos && !(auth.rolDeZona && rolesPermitidos.includes(auth.rolDeZona))) {
    return { name: 'home' }
  }

  // M09 (20/10/2026): un menú que el SuperAdmin le quitó a esta cuenta no se abre ni escribiendo la
  // dirección (el servidor también rechaza sus acciones).
  const menuRuta = to.meta.menu as string | undefined
  if (menuRuta && !auth.esSuperAdmin && !auth.esSoporte && auth.usuario?.menus && !auth.usuario.menus.includes(menuRuta)) {
    return { name: auth.rolDeZona === 'mesero' ? 'mesero-pedidos' : 'admin-dashboard' }
  }

  // Fase 7, tarea 2: la pestaña Gastos del Mesero exige el permiso (el servidor también lo revisa).
  if (to.meta.permisoGastos && !auth.usuario?.puede_registrar_gastos) {
    return { name: 'mesero-pedidos' }
  }

  return true
})

// M09 (20/10/2026): si el SuperAdmin cambia los menús de una cuenta, el menú lateral se pone al día
// sin cerrar sesión: al navegar dentro del panel se vuelve a pedir la cuenta, como mucho cada minuto.
let ultimaRevisionMenus = Date.now()
router.afterEach((to) => {
  const auth = useAuthStore()
  if (!to.meta.menu || !auth.estaAutenticado || Date.now() - ultimaRevisionMenus < 60_000) return
  ultimaRevisionMenus = Date.now()
  auth.cargarUsuarioActual().catch(() => {})
})

export default router
