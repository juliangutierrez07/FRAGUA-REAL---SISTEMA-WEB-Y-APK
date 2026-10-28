// Valores vigentes de domicilio y depósito de reserva.
//  · obtener()    → GET /configuracion  (público, solo lectura): lo usan Checkout y Reservar para
//                   mostrarle el valor al Cliente. El servidor sigue fijando el valor real al crear
//                   el Pedido/la Reserva.
//  · actualizar() → PUT /configuraciones (solo Administrador, RF-M07-030 y RF-M05-028): guarda
//                   ambos valores en la tabla `configuraciones`; aplican a los pedidos/reservas
//                   creados después del cambio.
import api from './api'
import type { MedioPagoEnLinea } from '@/types/models'

// Mejora (19/10/2026): datos del negocio que cambia el Administrador (Inicio, pie de página y PDF).
export interface DatosNegocio {
  nombre: string
  // M09 (19/10/2026): nombre y NIT los cambia solo el SuperAdmin.
  nit?: string
  direccion: string
  telefono: string
  whatsapp_url: string | null
  // M09 (20/10/2026): punto del local en el mapa (lo marca el SuperAdmin) y el enlace de «Cómo
  // llegar» (Google Maps en ese punto; sin punto, buscando la dirección).
  latitud?: number | null
  longitud?: number | null
  mapa_url?: string
  logo_url: string | null
}

export interface Configuracion {
  negocio?: DatosNegocio
  valor_domicilio: number
  deposito_reserva: number
  // Fase 4, tarea 2 (RF-M05-017/026, decisión D6): tiempos de las reservas, en minutos.
  reserva_tolerancia_minutos?: number
  reserva_recordatorio_minutos?: number
  reserva_ventana_reservada_minutos?: number
  // Corrección (19/10/2026): horario de reservas ("HH:MM", hora de Colombia) y anticipación mínima.
  reserva_hora_inicio?: string
  reserva_hora_fin?: string
  reserva_anticipacion_minutos?: number
  // 27/10/2026 (RF-M06-039): hora de cierre de la caja ("HH:MM", Colombia) y tolerancia en minutos;
  // pasado ese tiempo, el turno que nadie cerró se cierra solo.
  caja_hora_cierre?: string
  caja_tolerancia_minutos?: number
  // Seguridad (21/10/2026): requisitos de la contraseña (los hace cumplir el servidor).
  clave?: { minimo: number; mezcla: boolean }
  // Pago directo (22/10/2026): las cuentas a las que se transfiere (solo las que están completas).
  pagos_directos?: PagosDirectosPublico
}

// Pago directo (22/10/2026, RF-M06-030): sin Wompi, el Cliente transfiere a estas cuentas y sube la
// captura. «automatico» = Wompi activo (el pago se hace en la pasarela, sin captura).
export interface MedioPagoDirecto {
  medio: MedioPagoEnLinea
  nombre: string
  campos: { campo: string; etiqueta: string; valor: string; copiar: boolean }[]
}
export interface PagosDirectosPublico {
  activo: boolean
  automatico: boolean
  medios: MedioPagoDirecto[]
}

// Lo que guarda el SuperAdmin en Configuración (GET/PUT /negocio/pagos-directos).
export interface DatosPagosDirectos {
  nequi: { numero: string; titular: string }
  bancolombia: { tipo: string; numero: string; titular: string; documento: string }
  breb: { llave: string; titular: string }
}

// Rangos permitidos (los mismos que valida el backend, PoliticaReservas::RANGOS).
export const RANGOS_TIEMPOS_RESERVA = {
  reserva_tolerancia_minutos: [5, 120],
  reserva_recordatorio_minutos: [30, 2880],
  reserva_ventana_reservada_minutos: [15, 240],
} as const

// 27/10/2026 (RF-M06-039): la misma que valida el backend (PoliticaCaja::RANGO_TOLERANCIA).
export const RANGO_TOLERANCIA_CAJA = [0, 360] as const

// Fase 4, tarea 1: estado del programador de tareas (cron) y de la cola de trabajos en el
// servidor, con su último latido. GET /sistema/estado, solo Administrador.
export interface EstadoSistema {
  programador: { ultimo_latido: string | null; activo: boolean }
  cola: {
    conexion: string
    sincronica: boolean
    ultimo_latido: string | null
    activa: boolean
    pendientes: number | null
    fallidos: number | null
  }
  correo: { transporte: string; envia_de_verdad: boolean }
  minutos_activo: number
}

// Endurecimiento (13/10/2026): revisión antes de abrir al público, la misma de
// `php artisan fragua:revisar-produccion`. GET /sistema/revision-produccion, solo Administrador.
export interface ItemRevisionProduccion {
  clave: string
  nivel: 'ok' | 'aviso' | 'error'
  titulo: string
  detalle: string
}

export const configuracionService = {
  estadoSistema() {
    return api.get<EstadoSistema>('/sistema/estado')
  },
  revisionProduccion() {
    return api.get<{ items: ItemRevisionProduccion[]; listo: boolean }>('/sistema/revision-produccion')
  },
  obtener() {
    return api.get<Configuracion>('/configuracion')
  },
  actualizar(payload: Configuracion) {
    return api.put('/configuraciones', payload)
  },
  // Mejora (19/10/2026): solo Administrador.
  actualizarNegocio(payload: {
    direccion: string
    telefono: string
    nombre?: string
    nit?: string
    latitud?: number | null
    longitud?: number | null
  }) {
    return api.put<DatosNegocio>('/negocio', payload)
  },
  // M09 (20/10/2026): solo SuperAdmin. Lee el punto de un enlace de Google Maps (o de «lat, lng»).
  ubicacionDesdeEnlace(enlace: string) {
    return api.post<{ latitud: number; longitud: number }>('/negocio/ubicacion-desde-enlace', { enlace })
  },
  subirLogo(logo: File) {
    const datos = new FormData()
    datos.append('logo', logo)
    return api.post<DatosNegocio>('/negocio/logo', datos)
  },
  quitarLogo() {
    return api.delete<DatosNegocio>('/negocio/logo')
  },
  // Pago directo (22/10/2026): el Administrador las ve; solo el SuperAdmin las cambia.
  pagosDirectos() {
    return api.get<{ datos: DatosPagosDirectos; publico: PagosDirectosPublico }>('/negocio/pagos-directos')
  },
  actualizarPagosDirectos(datos: DatosPagosDirectos) {
    return api.put<{ datos: DatosPagosDirectos; publico: PagosDirectosPublico }>('/negocio/pagos-directos', datos)
  },
}
