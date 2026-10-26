// «hace 3 min», «hace 2 h», «el 20 oct, 6:40 p. m.»: para señales y pruebas (M12, 24/10/2026).
export function haceCuanto(valor: string | null | undefined): string {
  if (!valor) return 'nunca'
  const fecha = new Date(valor)
  const segundos = Math.max(0, Math.round((Date.now() - fecha.getTime()) / 1000))
  if (segundos < 60) return 'hace un momento'
  const minutos = Math.round(segundos / 60)
  if (minutos < 60) return `hace ${minutos} min`
  const horas = Math.round(minutos / 60)
  if (horas < 24) return `hace ${horas} h`
  return `el ${fecha.toLocaleString('es-CO', { timeZone: 'America/Bogota', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}`
}
