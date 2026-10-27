// Eliminar mesas, productos y usuarios (24/10/2026) y, desde el 27/10/2026 (RF-M04-057 a 060), categorías,
// combos, extras y promociones. Es definitivo: lo que nunca se usó se borra; lo que tiene historial sale de
// todo y su fila se guarda para lo que ya pasó (EliminacionService del backend). Administrador y SuperAdmin,
// con su contraseña; el motivo solo al eliminar una cuenta (`pide_motivo`).
import api from './api'

export type Eliminable = 'mesas' | 'productos' | 'usuarios' | 'categorias' | 'combos' | 'extras' | 'promociones'

export interface VistaEliminacion {
  /** true = se borra para siempre; false = se archiva. */
  se_borra: boolean
  detalle: string
  /** Por qué no se puede eliminar ahora (mesa ocupada, cuenta propia…), o null. */
  bloqueo: string | null
  /** true solo al eliminar una cuenta (27/10/2026). */
  pide_motivo: boolean
}

export interface ResultadoEliminacion {
  resultado: 'borrado' | 'archivado'
  mensaje: string
}

export const eliminacionService = {
  vista(tipo: Eliminable, id: number) {
    return api.get<VistaEliminacion>(`/${tipo}/${id}/eliminacion`)
  },
  eliminar(tipo: Eliminable, id: number, datos: { password: string; motivo?: string }) {
    return api.post<ResultadoEliminacion>(`/${tipo}/${id}/eliminar`, datos)
  },
}
