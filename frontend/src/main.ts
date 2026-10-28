import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'
import { useNotificacionesStore } from './stores/notificaciones'
import { EVENTO_SESION_INVALIDA } from './services/api'
import { vEscritura } from './directives/escritura'
import { vFormato } from './directives/formato'
// Mejora (16/10/2026): escucha desde el arranque el aviso del navegador para instalar la app (PWA).
import './composables/useInstalarApp'

const app = createApp(App)

app.use(createPinia())
app.use(router)
// M10 (20/10/2026): v-escritura esconde a la cuenta de Soporte los botones que cambian algo.
app.directive('escritura', vEscritura)
// 22/10/2026: v-formato limpia lo que se escribe en un campo según su formato (utils/formatos.ts).
app.directive('formato', vFormato)

// CORREGIDO (29/09/2026): antes esto esperaba cargarUsuarioActual() antes de montar,
// pensado para "proteger" el primer guard de rutas — pero Vue Router dispara su
// navegación inicial (y el primer beforeEach) desde app.use(router) de arriba, NO
// desde app.mount(), así que ese gate nunca protegía nada. Ahora el propio guard
// (router/index.ts) espera auth.listo, que es donde debe estar esa espera — mount()
// puede llamarse de inmediato, <router-view> simplemente no resuelve nada hasta que
// el guard (ya esperando lo que haga falta) lo deje pasar.
const auth = useAuthStore()

// El interceptor 401 (services/api.ts) no puede importar este store directo — sería
// un ciclo de imports (api.ts -> stores/auth.ts -> services/auth.service.ts -> api.ts)
// que rompe la inicialización de módulos de ESM: se probó, y con el import directo
// cargarUsuarioActual() dejaba de poblar "usuario" sin ningún error visible. Por eso
// avisa con un evento del navegador en su lugar, y este listener (que sí puede
// importar el store sin ciclo, porque main.ts está fuera de esa cadena) limpia el
// estado reactivo — sin esto, auth.estaAutenticado seguía dando true tras un 401 y
// el guard de rutas dejaba pasar la siguiente navegación como si la sesión siguiera activa.
window.addEventListener(EVENTO_SESION_INVALIDA, () => {
  auth.token = null
  auth.usuario = null
})

app.mount('#app')

// M11 (28/10/2026, RF-M11-013): el service worker avisa cuando llega un aviso push (la campana se actualiza) y
// cuando se toca uno con la app abierta (se va a su pantalla sin recargar).
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.addEventListener('message', (evento: MessageEvent<{ tipo?: string; enlace?: string }>) => {
    if (evento.data?.tipo === 'abrir' && evento.data.enlace?.startsWith('/')) router.push(evento.data.enlace)
    if (evento.data?.tipo === 'aviso-push') void useNotificacionesStore().refrescar()
  })
}

// Mejora (16/10/2026): app instalable (PWA). El service worker (public/sw.js) solo se registra en la
// versión compilada: en desarrollo guardaría archivos de Vite que cambian a cada rato.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      // Sin service worker el sitio funciona igual; solo no se puede instalar ni hay página sin conexión.
    })
  })
}
