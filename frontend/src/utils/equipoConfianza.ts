// Seguridad (28/10/2026, RF-M01-036, RN-USR-27): las llaves de «equipo de confianza» que el servidor entrega al
// iniciar sesión bien. Se mandan en los próximos inicios de sesión: así, si alguien prueba contraseñas y bloquea
// una cuenta o la red del local, quien ya entraba desde este equipo puede seguir entrando. Una por cuenta que
// entró aquí (las 8 más recientes). Sin almacenamiento del navegador simplemente no hay llaves.
const CLAVE = 'fragua_real_equipos_confianza'
const MAXIMO = 8

export function llavesDeConfianza(): string[] {
  try {
    const lista = JSON.parse(localStorage.getItem(CLAVE) ?? '[]')
    return Array.isArray(lista) ? lista.filter((l): l is string => typeof l === 'string').slice(0, MAXIMO) : []
  } catch {
    return []
  }
}

export function guardarLlaveDeConfianza(llave: string | undefined) {
  if (!llave) return
  try {
    const lista = [llave, ...llavesDeConfianza().filter((l) => l !== llave)].slice(0, MAXIMO)
    localStorage.setItem(CLAVE, JSON.stringify(lista))
  } catch {
    // Sin almacenamiento: no pasa nada, solo no se recuerda el equipo.
  }
}
