// M12 — Impresoras (24/10/2026): espeja ImpresionController.php. La clave de este equipo va sola en
// la cabecera X-Equipo-Impresion (services/api.ts).
import api from './api'
import type { ComandaPendiente } from './comanda.service'

export type ResultadoPrueba = 'ok' | 'fallo' | 'cortado'

export interface EquipoImpresion {
  id: number
  nombre: string
  imprime_comandas: boolean
  imprime_comprobantes: boolean
  al_confirmar: boolean
  automatico: boolean
  ultima_senal_at: string | null
  ultima_prueba_at: string | null
  ultima_prueba_resultado: ResultadoPrueba | null
  en_linea: boolean
  vinculado: boolean
  codigo_vence_at?: string | null
  // Solo en el listado del Administrador.
  codigo?: string | null
  senal_por_usuario?: { id: number; nombre: string } | null
  prueba_por_usuario?: { id: number; nombre: string } | null
}

export interface FormatoImpresion {
  ticket_logo: boolean
  ticket_letra: 'normal' | 'grande'
  ticket_mensaje_pie: string
  comanda_letra_grande: boolean
  comanda_mostrar_hora: boolean
  comanda_mostrar_mesero: boolean
  copias_comprobante: number
  copias_comanda: number
}

export interface ImpresionHistorial {
  id: number
  accion: string
  fecha: string
  usuario: string | null
  pedido_id: number | null
  detalles: Record<string, unknown> | null
}

export interface EstadoImpresion {
  pendientes: ComandaPendiente[]
  historial: ImpresionHistorial[]
}

export const RESULTADOS_PRUEBA: Record<ResultadoPrueba, string> = {
  ok: 'Salió bien',
  fallo: 'No salió',
  cortado: 'Salió cortado',
}

export const impresionService = {
  // ---- desde este equipo ----
  vincular(codigo: string) {
    return api.post<{ equipo: EquipoImpresion; clave: string }>('/impresion/equipos/vincular', { codigo })
  },
  senal() {
    return api.post<EquipoImpresion>('/impresion/equipos/senal')
  },
  prueba() {
    return api.get<Blob>('/impresion/prueba', { responseType: 'blob' })
  },
  resultadoPrueba(resultado: ResultadoPrueba) {
    return api.post<EquipoImpresion>('/impresion/equipos/prueba', { resultado })
  },
  // ---- Administrador ----
  equipos() {
    return api.get<EquipoImpresion[]>('/impresion/equipos')
  },
  registrarAqui(nombre: string) {
    return api.post<{ equipo: EquipoImpresion; clave: string }>('/impresion/equipos', { nombre, aqui: true })
  },
  crearConCodigo(nombre: string) {
    return api.post<{ equipo: EquipoImpresion; codigo: string }>('/impresion/equipos', { nombre, aqui: false })
  },
  actualizar(id: number, datos: Partial<Pick<EquipoImpresion, 'nombre' | 'imprime_comandas' | 'imprime_comprobantes' | 'al_confirmar' | 'automatico'>>) {
    return api.put<EquipoImpresion>(`/impresion/equipos/${id}`, datos)
  },
  quitar(id: number) {
    return api.delete(`/impresion/equipos/${id}`)
  },
  nuevoCodigo(id: number) {
    return api.post<{ codigo: string; vence_at: string }>(`/impresion/equipos/${id}/codigo`)
  },
  formato() {
    return api.get<FormatoImpresion>('/impresion/formato')
  },
  guardarFormato(formato: FormatoImpresion) {
    return api.put<FormatoImpresion>('/impresion/formato', formato)
  },
  vistaPrevia(tipo: 'comprobante' | 'comanda', formato: FormatoImpresion) {
    return api.post<Blob>('/impresion/vista-previa', { tipo, ...formato }, { responseType: 'blob' })
  },
  estado() {
    return api.get<EstadoImpresion>('/impresion/estado')
  },
  descartarComanda(id: number, motivo: string) {
    return api.post(`/comandas/${id}/descartar`, { motivo })
  },
}
