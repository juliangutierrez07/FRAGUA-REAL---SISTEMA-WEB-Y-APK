// Fase 3, tarea 1: convierte los "detalles" de un registro de auditoría (JSON con motivo,
// valores antes/después, etc.) en filas legibles para el Administrador.

export interface FilaDetalle {
  campo: string
  valor: string
}

const NOMBRES: Record<string, string> = {
  motivo: 'Motivo',
  correo: 'Correo usado',
  rol: 'Rol',
  cambios: 'Cambios',
  antes: 'Antes',
  despues: 'Después',
  sesiones_revocadas: 'Sesiones cerradas',
  sesiones_cerradas: 'Sesiones cerradas',
  sesion_id: 'Sesión n.º',
  dispositivo: 'Dispositivo',
  ip: 'IP',
  via: 'Entró con',
  era_la_actual: 'Era la sesión desde la que se cerró',
  usuario: 'Nombre de usuario',
  username: 'Nombre de usuario',
  email: 'Correo',
  telefono: 'Teléfono',
  nombre: 'Nombre',
  otras_sesiones_cerradas: 'Otras sesiones cerradas',
  base_inicial: 'Base inicial',
  diferencia: 'Diferencia',
  justificacion: 'Justificación',
  formato: 'Formato',
  periodo: 'Periodo',
  reporte: 'Reporte',
  // Mejora (26/10/2026, RF-M05-045): depósito manual de una reserva.
  recibido: 'Entregó el cliente',
  cambio: 'Cambio entregado',
  con_foto: 'Con foto del comprobante',
}

// Motivos técnicos que guarda el backend → texto para el Administrador.
const VALORES: Record<string, string> = {
  contrasena_incorrecta: 'Contraseña incorrecta',
  cuenta_inexistente: 'El correo no corresponde a ninguna cuenta',
  cuenta_inactiva: 'La cuenta está desactivada',
  token_invalido: 'Enlace de recuperación no válido',
  token_vencido: 'Enlace de recuperación vencido',
  correo: 'el correo',
  usuario: 'el nombre de usuario',
}

export function nombreCampo(clave: string): string {
  if (NOMBRES[clave]) return NOMBRES[clave]
  const texto = clave.replace(/_/g, ' ')
  return texto.charAt(0).toUpperCase() + texto.slice(1)
}

export function textoValor(valor: unknown): string {
  if (valor === null || valor === undefined || valor === '') return '—'
  if (typeof valor === 'boolean') return valor ? 'Sí' : 'No'
  if (typeof valor === 'string') return VALORES[valor] ?? valor
  if (typeof valor === 'number') return String(valor)
  if (Array.isArray(valor)) return valor.map(textoValor).join(', ')
  if (typeof valor === 'object') {
    const objeto = valor as Record<string, unknown>
    if ('antes' in objeto || 'despues' in objeto) {
      return `${textoValor(objeto.antes)} → ${textoValor(objeto.despues)}`
    }
    return Object.entries(objeto)
      .map(([clave, v]) => `${nombreCampo(clave)}: ${textoValor(v)}`)
      .join(' · ')
  }
  return String(valor)
}

// Un nivel de profundidad: "cambios" se abre en una fila por campo cambiado.
export function filasDetalle(detalles: Record<string, unknown> | null): FilaDetalle[] {
  if (!detalles) return []
  const filas: FilaDetalle[] = []
  for (const [clave, valor] of Object.entries(detalles)) {
    if (clave === 'cambios' && valor && typeof valor === 'object' && !Array.isArray(valor)) {
      for (const [campo, cambio] of Object.entries(valor as Record<string, unknown>)) {
        filas.push({ campo: nombreCampo(campo), valor: textoValor(cambio) })
      }
      continue
    }
    filas.push({ campo: nombreCampo(clave), valor: textoValor(valor) })
  }
  return filas
}

// Fecha del negocio (Colombia) en formato YYYY-MM-DD, para los filtros por día.
export function fechaNegocio(fecha = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota', year: 'numeric', month: '2-digit', day: '2-digit' }).format(fecha)
}
