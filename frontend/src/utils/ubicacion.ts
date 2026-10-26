// Mejora (26/10/2026): ubicación actual del Cliente para el domicilio.
//
// CORREGIDO (26/10/2026): la primera lectura del navegador suele ser aproximada (en un computador, o en
// un teléfono que aún no fija el GPS, sale de la red y puede caer en otro barrio), y con maximumAge se
// aceptaba una ubicación vieja. Ahora se escucha la ubicación hasta 12 s, sin datos guardados, y se
// queda la más precisa; termina antes si llega una de 30 m o menos. Igual el Cliente confirma el punto
// en el mapa y puede moverlo.
export interface Ubicacion {
  lat: number
  lng: number
  /** Radio de error en metros que da el navegador (0 si el Cliente movió el punto a mano). */
  precision: number
}

const ESPERA_MAXIMA_MS = 12000
const PRECISION_SUFICIENTE_M = 30

export type ErrorUbicacion = 'no_disponible' | 'sin_permiso' | 'tiempo' | 'fallo'

export function obtenerUbicacion(): Promise<Ubicacion> {
  return new Promise((resolver, rechazar) => {
    if (!('geolocation' in navigator) || !window.isSecureContext) {
      rechazar('no_disponible' satisfies ErrorUbicacion)
      return
    }
    let mejor: Ubicacion | null = null
    let terminado = false
    // POSITION_UNAVAILABLE puede ser pasajero mientras el GPS arranca: se sigue esperando hasta el límite.
    let sinSenal = false
    const terminar = (error?: ErrorUbicacion) => {
      if (terminado) return
      terminado = true
      navigator.geolocation.clearWatch(vigilancia)
      clearTimeout(limite)
      if (error) rechazar(error)
      else if (mejor) resolver(mejor)
      else rechazar((sinSenal ? 'fallo' : 'tiempo') satisfies ErrorUbicacion)
    }
    const vigilancia = navigator.geolocation.watchPosition(
      (pos) => {
        const lectura = {
          lat: Number(pos.coords.latitude.toFixed(7)),
          lng: Number(pos.coords.longitude.toFixed(7)),
          precision: Math.round(pos.coords.accuracy),
        }
        if (!mejor || lectura.precision < mejor.precision) mejor = lectura
        if (lectura.precision <= PRECISION_SUFICIENTE_M) terminar()
      },
      (err) => {
        if (err.code === err.PERMISSION_DENIED) terminar('sin_permiso')
        else if (err.code === err.POSITION_UNAVAILABLE) sinSenal = true
      },
      { enableHighAccuracy: true, maximumAge: 0, timeout: ESPERA_MAXIMA_MS },
    )
    const limite = setTimeout(() => terminar(), ESPERA_MAXIMA_MS)
  })
}

export const MENSAJE_ERROR_UBICACION: Record<ErrorUbicacion, string> = {
  no_disponible: 'Este navegador no permite compartir la ubicación aquí. Márcala en el mapa o escribe la dirección.',
  sin_permiso: 'No diste permiso para ver tu ubicación. Actívalo en el navegador (el candado junto a la dirección) o márcala en el mapa.',
  tiempo: 'Tu ubicación tardó demasiado. Enciende el GPS e intenta de nuevo, o márcala en el mapa.',
  fallo: 'No pudimos saber dónde estás. Enciende el GPS e intenta de nuevo, o márcala en el mapa.',
}
