// M12 — Impresoras (24/10/2026): la clave que identifica a este navegador como un equipo de impresión
// («Cocina», «Caja»). Se guarda en este equipo (no en la cuenta) y viaja en la cabecera
// X-Equipo-Impresion, para que el servidor sepa desde qué equipo se imprimió cada cosa.
const CLAVE = 'fragua_equipo_impresion'

export function claveEquipo(): string | null {
  try {
    return localStorage.getItem(CLAVE)
  } catch {
    return null
  }
}

export function guardarClaveEquipo(clave: string) {
  try {
    localStorage.setItem(CLAVE, clave)
  } catch {
    // Sin almacenamiento (navegación privada): el equipo no queda registrado.
  }
}

export function olvidarClaveEquipo() {
  try {
    localStorage.removeItem(CLAVE)
  } catch {
    // nada
  }
}
