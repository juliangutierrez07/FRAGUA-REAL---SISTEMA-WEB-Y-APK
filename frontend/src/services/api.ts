import axios from 'axios'
import { claveEquipo } from '@/utils/equipoImpresion'

// Cliente HTTP central. Autenticación por TOKEN de Sanctum (no por cookie),
// igual que documenta README-BACKEND.md del backend: cada request autenticado
// lleva "Authorization: Bearer <token>".
const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? '/api',
  headers: {
    Accept: 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('fragua_real_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  // M12 (24/10/2026): si este navegador es un equipo de Impresoras, el servidor sabe desde dónde se imprime.
  const equipo = claveEquipo()
  if (equipo) {
    config.headers['X-Equipo-Impresion'] = equipo
  }
  return config
})

// Evento en vez de import directo del store: api.ts -> stores/auth.ts ->
// services/auth.service.ts -> api.ts sería un ciclo de imports de ESM que rompe la
// inicialización de módulos en Vite (se probó: con el import directo, useAuthStore()
// quedaba resuelto pero el store nunca llegaba a poblar "usuario", como si cargarUsuarioActual()
// nunca hubiera corrido — ver el listener de este evento en main.ts, que sí puede
// importar el store sin ciclo).
export const EVENTO_SESION_INVALIDA = 'auth:sesion-invalida'

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      // Token inválido/expirado o sesión revocada (RF-M01-010/023, AuthController::
      // revocarSesiones — el Administrador puede tumbar la sesión de otro usuario
      // mientras la tiene abierta en otra pestaña). CORREGIDO (29/09/2026): esto solo
      // limpiaba localStorage, no el estado reactivo del store — auth.estaAutenticado
      // (computed sobre el token del store, no sobre localStorage) seguía dando true,
      // así que el guard de rutas dejaba pasar la siguiente navegación como si la
      // sesión siguiera activa.
      localStorage.removeItem('fragua_real_token')
      window.dispatchEvent(new Event(EVENTO_SESION_INVALIDA))
    }
    return Promise.reject(error)
  },
)

export default api
