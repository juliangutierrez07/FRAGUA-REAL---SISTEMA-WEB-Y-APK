// M01 — espeja UsuarioController del backend (routes/api.php: GET/POST /usuarios,
// PUT /usuarios/{id}, POST /usuarios/{id}/activar|desactivar|revocar-sesiones). Exclusivo Administrador.
import api from './api'
import type { ListaSesiones, Rol, Usuario } from '@/types/models'

export interface CrearUsuarioPayload {
  role: Extract<Rol, 'mesero' | 'administrador'>
  nombre: string
  email: string
  username?: string
  telefono?: string
  password: string
  password_confirmation: string
}

// Fase 3, tarea 2 (RF-M01-003/005): datos editables de una cuenta. La contraseña no va aquí.
export interface EditarUsuarioPayload {
  nombre: string
  email: string
  // Fase 3, tarea 3: vacío = sin nombre de usuario (entra solo con el correo).
  username: string
  telefono: string | null
  role: Rol
  // Fase 7, tarea 2 (RF-M03-32): solo cuenta para un Mesero; al cambiar a otro rol se quita.
  puede_registrar_gastos?: boolean
}

export interface UsuarioEditado {
  usuario: Usuario
  // true si cambió el rol: el backend cerró todas sus sesiones.
  sesiones_cerradas: boolean
}

export interface UsuariosPaginados {
  data: Usuario[]
  current_page: number
  last_page: number
  total: number
}

// M09 (20/10/2026): permisos de menú de una cuenta de Administrador o Mesero (solo SuperAdmin).
export interface MenusDeCuenta {
  usuario_id: number
  rol: Rol
  catalogo: { clave: string; nombre: string; fijo: boolean }[]
  menus: string[]
  // true = sin restricción: ve todos los menús de su rol.
  todos: boolean
}

export const usuarioService = {
  menus(id: number) {
    return api.get<MenusDeCuenta>(`/usuarios/${id}/menus`)
  },
  actualizarMenus(id: number, menus: string[]) {
    return api.put<MenusDeCuenta>(`/usuarios/${id}/menus`, { menus })
  },
  index(params?: { rol?: Rol; busqueda?: string; eliminados?: 1 }) {
    return api.get<UsuariosPaginados>('/usuarios', { params })
  },
  crear(payload: CrearUsuarioPayload) {
    return api.post<Usuario>('/usuarios', payload)
  },
  editar(id: number, payload: EditarUsuarioPayload) {
    return api.put<UsuarioEditado>(`/usuarios/${id}`, payload)
  },
  activar(id: number) {
    return api.post<Usuario>(`/usuarios/${id}/activar`)
  },
  desactivar(id: number) {
    return api.post<Usuario>(`/usuarios/${id}/desactivar`)
  },
  // Fase 3, tarea 4: sesiones abiertas de una cuenta y cerrar una en particular.
  sesiones(id: number) {
    return api.get<ListaSesiones>(`/usuarios/${id}/sesiones`)
  },
  revocarSesion(id: number, sesionId: number) {
    return api.delete<{ message: string }>(`/usuarios/${id}/sesiones/${sesionId}`)
  },
  revocarSesiones(id: number) {
    return api.post<{ message: string }>(`/usuarios/${id}/revocar-sesiones`)
  },
}
