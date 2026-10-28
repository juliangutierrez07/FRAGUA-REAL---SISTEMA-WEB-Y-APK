import api from './api'
import type { ListaSesiones, Usuario } from '@/types/models'

// Espeja exactamente los Form Requests de AuthController (routes/api.php) —
// nombres de campo iguales a los del backend (email, no correo).
// Fase 3, tarea 3 (RF-M01-007): "login" acepta el correo o el nombre de usuario.
export interface LoginPayload {
  login: string
  password: string
}

export interface RegistroPayload {
  nombre: string
  email: string
  // Opcional (Fase 3, tarea 3): con él también se puede iniciar sesión.
  username?: string
  telefono?: string
  // 27/10/2026 (RF-M01-035): obligatorio y único.
  documento: string
  password: string
  password_confirmation: string
}

export interface AuthResponse {
  user: Usuario
  token: string
}

export interface ActualizarPerfilPayload {
  nombre: string
  telefono?: string
  // Fase 3, tarea 3: vacío = quitarlo; sin enviar = no se toca.
  username?: string
  // 27/10/2026 (RF-M01-035): solo el Cliente.
  documento?: string
}

export interface CambiarPasswordPayload {
  password_actual: string
  password: string
  password_confirmation: string
}

export const authService = {
  // Fase 3, tarea 4 (RF-M01-010/024): mis sesiones abiertas.
  sesiones() {
    return api.get<ListaSesiones>('/auth/sesiones')
  },
  cerrarSesion(id: number) {
    return api.delete<{ message: string; era_la_actual: boolean }>(`/auth/sesiones/${id}`)
  },
  cerrarOtrasSesiones() {
    return api.post<{ message: string; cerradas: number }>('/auth/sesiones/cerrar-otras')
  },
  login(payload: LoginPayload) {
    return api.post<AuthResponse>('/auth/login', payload)
  },
  // RF-M01-014: autorregistro, exclusivo para Cliente (Administrador/Mesero
  // los crea un Administrador por otro medio, ver README-BACKEND.md).
  registrar(payload: RegistroPayload) {
    return api.post<AuthResponse>('/auth/register', payload)
  },
  me() {
    return api.get<Usuario>('/auth/me')
  },
  logout() {
    return api.post('/auth/logout')
  },
  // RF-M01-010/023: cerrar sesión en todos los dispositivos.
  logoutTodos() {
    return api.post('/auth/logout-todos')
  },
  // RF-M01-012/013: recuperación de contraseña.
  solicitarRecuperacion(email: string) {
    return api.post<{ message: string }>('/auth/recuperar', { email })
  },
  restablecerContrasena(payload: {
    email: string
    token: string
    password: string
    password_confirmation: string
  }) {
    return api.post<{ message: string }>('/auth/restablecer', payload)
  },
  // RF-M01: editar el propio perfil / cambiar la propia contraseña.
  actualizarPerfil(payload: ActualizarPerfilPayload) {
    return api.put<Usuario>('/perfil', payload)
  },
  cambiarPassword(payload: CambiarPasswordPayload) {
    return api.post<{ message: string }>('/perfil/password', payload)
  },
}
