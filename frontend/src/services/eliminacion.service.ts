// Eliminar mesas, productos y usuarios (24/10/2026): lo que nunca se usó se borra; lo que tiene
// historial se archiva y se restaura desde «Eliminados» (EliminacionService del backend).
// Administrador y SuperAdmin, con su contraseña y un motivo.
import api from './api'

export type Eliminable = 'mesas' | 'productos' | 'usuarios'

export interface VistaEliminacion {
  /** true = se borra para siempre; false = se archiva. */
  se_borra: boolean
  detalle: string
  /** Por qué no se puede eliminar ahora (mesa ocupada, cuenta propia…), o null. */
  bloqueo: string | null
}

export interface ResultadoEliminacion {
  resultado: 'borrado' | 'archivado'
  mensaje: string
}

export const eliminacionService = {
  vista(tipo: Eliminable, id: number) {
    return api.get<VistaEliminacion>(`/${tipo}/${id}/eliminacion`)
  },
  eliminar(tipo: Eliminable, id: number, datos: { password: string; motivo: string }) {
    return api.post<ResultadoEliminacion>(`/${tipo}/${id}/eliminar`, datos)
  },
  restaurar<T>(tipo: Eliminable, id: number) {
    return api.post<T>(`/${tipo}/${id}/restaurar`)
  },
}
