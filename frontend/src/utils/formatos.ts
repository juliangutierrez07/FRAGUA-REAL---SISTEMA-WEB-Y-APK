// Formatos de los campos (22/10/2026): qué se puede escribir en cada campo y con qué largo, igual en
// todas las pantallas. Mientras se escribe o se pega, lo que no corresponde se descarta (letras en un
// teléfono, números en un nombre, espacios en un correo…) y el teclado del teléfono se adapta
// (numérico, correo). El servidor vuelve a validar con las mismas reglas (App\Support\Validacion\Formatos).
//
// Se usa con `<BaseInput formato="celular">`, con la directiva `v-formato="'celular'"` en un <input> o
// <textarea>, y para dinero con `<CampoPesos>` o `<BaseInput formato="pesos">` (separador de miles).

export type Formato =
  | 'celular'
  | 'persona'
  | 'nombre'
  | 'documento'
  | 'identificacion'
  | 'cuenta'
  | 'entero'
  | 'pesos'
  | 'porcentaje'
  | 'cantidad'
  | 'correo'
  | 'usuario'
  | 'referencia'
  | 'direccion'
  | 'llave'
  | 'ip'
  | 'codigo'
  | 'texto'
  | 'busqueda'

interface Regla {
  /** Deja solo lo permitido (no recorta el largo: eso lo hace `maxlength`). */
  limpiar: (valor: string) => string
  maxlength: number
  inputmode?: 'numeric' | 'tel' | 'email' | 'text' | 'search' | 'decimal'
  autocapitalize?: 'off' | 'words' | 'sentences' | 'characters'
}

const sinControl = (v: string) => v.replace(/[\u0000-\u0009\u000B-\u001F\u007F]/g, '')
const unEspacio = (v: string) => v.replace(/^\s+/, '').replace(/[ \t]{2,}/g, ' ')
const digitos = (v: string) => v.replace(/\D/g, '')

export const REGLAS: Record<Formato, Regla> = {
  // Celular o fijo de Colombia: 10 dígitos (3xx… o 60x…). Se guarda solo con números.
  celular: {
    limpiar: (v) => {
      let d = digitos(v)
      if (d.length > 10 && d.startsWith('57')) d = d.slice(2) // pegado con +57
      return d
    },
    maxlength: 10,
    inputmode: 'tel',
  },
  // Nombre de una persona o titular: letras (con tildes y ñ), espacios, punto, apóstrofo y guion.
  persona: {
    limpiar: (v) => unEspacio(v.replace(/[^\p{L}\p{M} .'-]/gu, '')),
    maxlength: 80,
    autocapitalize: 'words',
  },
  // Nombre de algo (negocio, producto, mesa, combo, proveedor…): letras, números y signos comunes.
  nombre: {
    limpiar: (v) => unEspacio(v.replace(/[^\p{L}\p{M}\p{N} .,&'()/+%$#°-]/gu, '')),
    maxlength: 120,
    autocapitalize: 'sentences',
  },
  // Cédula o NIT: hasta 10 números y, si es NIT, un guion con el dígito de verificación (900123456-7).
  documento: {
    limpiar: (v) => {
      const [base, ...resto] = v.replace(/[^\d-]/g, '').replace(/^-+/, '').split('-')
      const cuerpo = base.slice(0, 10)
      return resto.length ? `${cuerpo}-${digitos(resto.join('')).slice(0, 1)}` : cuerpo
    },
    maxlength: 12,
    inputmode: 'numeric',
  },
  // Documento de una persona (27/10/2026, RF-M01-035): cédula, cédula de extranjería o pasaporte; letras y
  // números en mayúscula, sin puntos ni espacios.
  identificacion: { limpiar: (v) => v.toUpperCase().replace(/[^A-Z0-9]/g, ''), maxlength: 15, autocapitalize: 'characters' },
  // Número de cuenta Bancolombia: 11 dígitos, sin guiones.
  cuenta: { limpiar: digitos, maxlength: 11, inputmode: 'numeric' },
  entero: { limpiar: digitos, maxlength: 6, inputmode: 'numeric' },
  // Pesos: solo números; la pantalla los muestra con punto de miles (20.000).
  pesos: { limpiar: digitos, maxlength: 11, inputmode: 'numeric' },
  // Cantidad con decimales (2,5 kilos): números y un punto decimal, hasta 2 decimales.
  cantidad: {
    limpiar: (v) => {
      const [entera, ...resto] = v.replace(/,/g, '.').replace(/[^\d.]/g, '').split('.')
      return resto.length ? `${entera || '0'}.${resto.join('').slice(0, 2)}` : entera
    },
    maxlength: 9,
    inputmode: 'decimal',
  },
  porcentaje: { limpiar: (v) => digitos(v).replace(/^0+(?=\d)/, ''), maxlength: 3, inputmode: 'numeric' },
  correo: {
    limpiar: (v) => v.toLowerCase().replace(/[^a-z0-9@._+-]/g, '').replace(/@(?=.*@)/g, ''),
    maxlength: 120,
    inputmode: 'email',
    autocapitalize: 'off',
  },
  // Nombre de usuario: empieza con letra; letras sin tilde, números, punto, guion y guion bajo.
  usuario: {
    limpiar: (v) => v.toLowerCase().replace(/[^a-z0-9._-]/g, '').replace(/^[^a-z]+/, ''),
    maxlength: 30,
    autocapitalize: 'off',
  },
  // Referencia o número de aprobación de una transferencia.
  referencia: { limpiar: (v) => v.toUpperCase().replace(/[^A-Z0-9-]/g, ''), maxlength: 40, autocapitalize: 'characters' },
  direccion: {
    limpiar: (v) => unEspacio(v.replace(/[^\p{L}\p{M}\p{N} #.,°/-]/gu, '')),
    maxlength: 160,
    autocapitalize: 'sentences',
  },
  // Llave Bre-B: @alias, celular, correo o documento; sin espacios.
  llave: { limpiar: (v) => v.replace(/[^A-Za-z0-9@._+-]/g, ''), maxlength: 80, autocapitalize: 'off' },
  ip: { limpiar: (v) => v.replace(/[^0-9a-fA-F.:]/g, ''), maxlength: 45, inputmode: 'decimal', autocapitalize: 'off' },
  // Código de recuperación de la contraseña.
  codigo: { limpiar: (v) => v.replace(/[^A-Za-z0-9]/g, ''), maxlength: 100, autocapitalize: 'off' },
  // Texto libre (observaciones, motivos, descripciones): sin caracteres de control.
  texto: { limpiar: (v) => sinControl(v).replace(/^\s+/, ''), maxlength: 500, autocapitalize: 'sentences' },
  busqueda: { limpiar: (v) => sinControl(v).replace(/^\s+/, ''), maxlength: 80, inputmode: 'search' },
}

/** 20000 → «20.000». Vacío si no hay número. */
export function agruparMiles(valor: string | number | null | undefined): string {
  const d = digitos(String(valor ?? '').split(/[.,]\d{1,2}$/)[0])
  return d ? Number(d).toLocaleString('es-CO', { maximumFractionDigits: 0 }) : ''
}

/**
 * Limpia el valor de un campo en su sitio y conserva el cursor donde estaba. Devuelve el valor
 * limpio. Para `pesos`, el campo muestra los miles y lo devuelto son solo los dígitos.
 */
export function aplicarFormato(campo: HTMLInputElement | HTMLTextAreaElement, formato: Formato, maximo?: number): string {
  const regla = REGLAS[formato]
  const largo = maximo && maximo > 0 ? maximo : regla.maxlength
  const original = campo.value
  const cursor = campo.selectionStart ?? original.length
  let limpio = regla.limpiar(original).slice(0, largo)
  let antesDelCursor = regla.limpiar(original.slice(0, cursor)).slice(0, largo).length

  if (formato === 'pesos') {
    limpio = limpio.replace(/^0+(?=\d)/, '')
    const mostrado = agruparMiles(limpio)
    // El cursor queda después del mismo número de dígitos que tenía antes.
    let vistos = 0
    let pos = 0
    const meta = digitos(original.slice(0, cursor)).replace(/^0+(?=\d)/, '').length
    while (pos < mostrado.length && vistos < meta) {
      if (/\d/.test(mostrado[pos])) vistos++
      pos++
    }
    if (campo.value !== mostrado) {
      campo.value = mostrado
      fijarCursor(campo, pos)
    }
    return limpio
  }

  if (campo.value !== limpio) {
    campo.value = limpio
    antesDelCursor = Math.min(antesDelCursor, limpio.length)
    fijarCursor(campo, antesDelCursor)
  }
  return limpio
}

function fijarCursor(campo: HTMLInputElement | HTMLTextAreaElement, pos: number) {
  if (document.activeElement !== campo) return
  try {
    campo.setSelectionRange(pos, pos)
  } catch {
    // type="email" o "number" no permiten mover el cursor.
  }
}

/**
 * Atributos del campo según su formato (teclado del teléfono, mayúsculas, corrector). El largo no va
 * como `maxlength`: el navegador cortaría lo pegado antes de limpiarlo («NIT: 900.123.456-7» quedaría
 * en «900123»); `aplicarFormato` limpia primero y recorta después.
 */
export function atributosDeFormato(formato: Formato): Record<string, string> {
  const regla = REGLAS[formato]
  const attrs: Record<string, string> = {}
  if (regla.inputmode) attrs.inputmode = regla.inputmode
  if (regla.autocapitalize) attrs.autocapitalize = regla.autocapitalize
  if (['correo', 'usuario', 'llave', 'ip', 'codigo', 'referencia', 'cuenta', 'documento', 'identificacion', 'celular'].includes(formato)) {
    attrs.spellcheck = 'false'
    attrs.autocorrect = 'off'
  }
  return attrs
}
