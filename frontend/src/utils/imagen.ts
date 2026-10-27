// Mejora (26/10/2026, RF-M06-037): la foto del comprobante que toma el Mesero con la cámara del teléfono pesa varios
// MB. Antes de subirla se reduce a 1600 px por el lado mayor y se guarda como JPEG: el texto del
// comprobante se sigue leyendo y el envío es rápido aunque la señal del local sea débil.
const LADO_MAXIMO = 1600
const CALIDAD = 0.82

export async function comprimirFoto(archivo: File): Promise<File> {
  if (!archivo.type.startsWith('image/')) return archivo
  try {
    const imagen = await createImageBitmap(archivo)
    const escala = Math.min(1, LADO_MAXIMO / Math.max(imagen.width, imagen.height))
    const lienzo = document.createElement('canvas')
    lienzo.width = Math.round(imagen.width * escala)
    lienzo.height = Math.round(imagen.height * escala)
    lienzo.getContext('2d')?.drawImage(imagen, 0, 0, lienzo.width, lienzo.height)
    imagen.close()
    const blob = await new Promise<Blob | null>((ok) => lienzo.toBlob(ok, 'image/jpeg', CALIDAD))
    if (!blob || blob.size >= archivo.size) return archivo
    return new File([blob], archivo.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' })
  } catch {
    // Un formato que el navegador no sabe abrir (p. ej. HEIC en Android) se sube tal cual.
    return archivo
  }
}
