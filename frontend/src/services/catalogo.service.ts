// M04 — Menú y Catálogo. Espeja app/Http/Controllers/Api/CatalogoController.php:
// GET /categorias y GET /productos son públicas y ya filtran activo+disponible según el
// rol autenticado (RN-M04-08) — el frontend no repite ese filtro, solo lo consume. Las
// mutaciones (crear/editar categoría o producto, alternar disponibilidad) son exclusivas
// Administrador (toggleDisponible también la usa Mesero), igual que en el backend.
import api from './api'
import type { Categoria, Producto, Combo, Variante, Extra, DatosOferta, Promocion } from '@/types/models'

export interface CrearCategoriaPayload {
  nombre: string
  activo?: boolean
  // Mejora (26/10/2026, RF-M04-056): el menú pregunta qué ingrediente quitar (las bebidas no).
  permite_quitar_ingredientes?: boolean
}

export interface CrearProductoPayload {
  categoria_id: number
  nombre: string
  descripcion?: string | null
  precio_base: number
  tipo_venta: 'bebida' | 'alimento' | 'otro'
  disponible?: boolean
  imagen_url?: string | null
  activo?: boolean
  // Fase 2, tarea 3 (RF-M04-011): ids de los extras generales que ofrece el producto.
  extras_generales?: number[]
}

export interface CrearVariantePayload {
  producto_id: number
  nombre: string
  // Mejora (16/10/2026): lo que trae la presentación ("para 3 o 4 personas").
  descripcion?: string | null
  precio: number
  activa?: boolean
}

export interface CrearExtraPayload {
  nombre: string
  precio: number
  alcance: 'general' | 'especifico'
  producto_id?: number | null
  activo?: boolean
}

export interface CrearComboPayload {
  nombre: string
  precio: number
  tipo_venta: 'bebida' | 'alimento' | 'otro'
  vigencia_inicio?: string | null
  vigencia_fin?: string | null
  franja_inicio?: string | null
  franja_fin?: string | null
  dias_semana?: number[] | null
  activo?: boolean
  productos: { producto_id: number; cantidad: number }[]
}

// Mejora (16/10/2026): importación masiva de productos (ImportacionProductosService).
export interface FilaImportacion {
  fila: number
  nombre: string
  categoria: string
  precio_base: number | null
  tipo_venta: 'alimento' | 'bebida' | 'otro' | null
  presentaciones: { nombre: string; precio: number }[]
  disponible: boolean | null
  descripcion: string
  accion: 'crear' | 'actualizar' | 'sin_cambios' | 'error'
  cambios: string[]
  errores: string[]
  producto_id: number | null
}

export interface VistaPreviaImportacion {
  filas: FilaImportacion[]
  resumen: { total: number; crear: number; actualizar: number; sin_cambios: number; errores: number }
  categorias_nuevas: string[]
}

function datosImportacion(archivo: File, actualizar: boolean) {
  const datos = new FormData()
  datos.append('archivo', archivo)
  datos.append('actualizar', actualizar ? '1' : '0')
  return datos
}

export const catalogoService = {
  categorias() {
    return api.get<Categoria[]>('/categorias')
  },
  // Fase 1, tarea 5 (RF-M04-025): q busca por nombre, categoria_id filtra. El público solo recibe
  // lo que se puede pedir; el personal recibe también lo agotado, para poder reactivarlo.
  productos(params?: { q?: string; categoria_id?: number }) {
    return api.get<Producto[]>('/productos', { params })
  },
  combos() {
    return api.get<Combo[]>('/combos')
  },
  crearCategoria(payload: CrearCategoriaPayload) {
    return api.post<Categoria>('/categorias', payload)
  },
  actualizarCategoria(id: number, payload: CrearCategoriaPayload) {
    return api.put<Categoria>(`/categorias/${id}`, payload)
  },
  crearProducto(payload: CrearProductoPayload) {
    return api.post<Producto>('/productos', payload)
  },
  actualizarProducto(id: number, payload: CrearProductoPayload) {
    return api.put<Producto>(`/productos/${id}`, payload)
  },
  // Fase 7, tarea 3 (RF-M04-004): foto del producto (JPG, PNG o WebP); el servidor la reduce.
  subirFoto(id: number, foto: File) {
    const datos = new FormData()
    datos.append('foto', foto)
    return api.post<Producto>(`/productos/${id}/foto`, datos)
  },
  quitarFoto(id: number) {
    return api.delete<Producto>(`/productos/${id}/foto`)
  },
  toggleDisponible(id: number, disponible: boolean) {
    return api.post<Producto>(`/productos/${id}/disponibilidad`, { disponible })
  },
  // RF-M04-016/017 (Fase 1): agotar o reactivar un tamaño/variante. Administrador y Mesero.
  toggleDisponibleVariante(id: number, disponible: boolean) {
    return api.post<Variante>(`/variantes/${id}/disponibilidad`, { disponible })
  },

  // ---- Variantes (M04 parte 2) — siempre en el contexto de su producto ----
  crearVariante(payload: CrearVariantePayload) {
    return api.post<Variante>('/variantes', payload)
  },
  actualizarVariante(id: number, payload: CrearVariantePayload) {
    return api.put<Variante>(`/variantes/${id}`, payload)
  },
  // Mejora (16/10/2026): foto propia de la presentación, con las mismas reglas que la del producto.
  subirFotoVariante(id: number, foto: File) {
    const datos = new FormData()
    datos.append('foto', foto)
    return api.post<Variante>(`/variantes/${id}/foto`, datos)
  },
  quitarFotoVariante(id: number) {
    return api.delete<Variante>(`/variantes/${id}/foto`)
  },

  // Mejora (16/10/2026): atajo «Poner en oferta» (crea o reemplaza la oferta del producto).
  ponerOferta(productoId: number, datos: DatosOferta) {
    return api.post<Promocion>(`/productos/${productoId}/oferta`, datos)
  },
  quitarOferta(productoId: number) {
    return api.delete<{ quitadas: number }>(`/productos/${productoId}/oferta`)
  },

  // Mejora (16/10/2026): importación masiva de productos desde Excel o CSV.
  plantillaImportacion() {
    return api.get<Blob>('/productos/importacion/plantilla', { responseType: 'blob' })
  },
  vistaPreviaImportacion(archivo: File, actualizar: boolean) {
    return api.post<VistaPreviaImportacion>('/productos/importacion/vista-previa', datosImportacion(archivo, actualizar))
  },
  importarProductos(archivo: File, actualizar: boolean) {
    return api.post<Pick<VistaPreviaImportacion, 'resumen' | 'categorias_nuevas'>>('/productos/importacion', datosImportacion(archivo, actualizar))
  },

  // ---- Extras (M04 parte 2) — "general" solo con los productos a los que se asocia, "especifico" con su producto ----
  extras() {
    return api.get<Extra[]>('/extras')
  },
  crearExtra(payload: CrearExtraPayload) {
    return api.post<Extra>('/extras', payload)
  },
  actualizarExtra(id: number, payload: CrearExtraPayload) {
    return api.put<Extra>(`/extras/${id}`, payload)
  },

  // ---- Combos: gestión completa (M04 parte 2) ----
  // combosAdmin trae TODOS los combos (incluidos inactivos/vencidos); combos() de arriba
  // es la lista pública, ya filtrada a lo disponible hoy.
  combosAdmin() {
    return api.get<Combo[]>('/combos-admin')
  },
  crearCombo(payload: CrearComboPayload) {
    return api.post<Combo>('/combos', payload)
  },
  actualizarCombo(id: number, payload: CrearComboPayload) {
    return api.put<Combo>(`/combos/${id}`, payload)
  },
}
