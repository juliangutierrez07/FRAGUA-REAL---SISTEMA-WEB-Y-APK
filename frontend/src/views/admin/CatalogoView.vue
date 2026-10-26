<script setup lang="ts">
// M04 — Catálogo. Parte 1: CRUD de categorías y productos. Parte 2 (agregada aquí):
// variantes y extras específicos (dentro del modal de cada producto), extras generales,
// combos y promociones. RN-M04-19 a 23: fechas especiales (Navidad, Amor y Amistad, etc.)
// NO tienen tratamiento propio — son una Promocion más con vigencia_inicio/vigencia_fin,
// igual que un combo con su propia vigencia. Ver CatalogoController y PromocionController.
// Eliminar (24/10/2026): un producto que nunca se vendió ni está en un combo se borra; si no, se
// archiva y se restaura desde «Eliminados» (con contraseña y motivo, EliminarModal).
import { computed, onMounted, ref, watch } from 'vue'
import { Boxes, Download, FileSpreadsheet, Image as ImageIcon, Layers, Pencil, Percent, Plus, RotateCcw, Tags, Trash2, Upload } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import CampoPesos from '@/components/common/CampoPesos.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import EliminarModal from '@/components/common/EliminarModal.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import { catalogoService, type VistaPreviaImportacion } from '@/services/catalogo.service'
import { guardarArchivo, mensajeDeBlob } from '@/utils/documentos'
import { promocionService } from '@/services/promocion.service'
import { eliminacionService, type ResultadoEliminacion } from '@/services/eliminacion.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import type { Categoria, Producto, Combo, Extra, Promocion } from '@/types/models'

type Seccion = 'categorias' | 'productos' | 'combos' | 'extras' | 'promociones'
const seccion = ref<Seccion>('categorias')

const categorias = ref<Categoria[]>([])
const productos = ref<Producto[]>([])
const combos = ref<Combo[]>([])
const extrasTodos = ref<Extra[]>([])
const promociones = ref<Promocion[]>([])
const cargando = ref(true)
const error = ref('')

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const [resCategorias, resProductos, resCombos, resExtras, resPromociones] = await Promise.all([
      catalogoService.categorias(),
      catalogoService.productos(),
      catalogoService.combosAdmin(),
      catalogoService.extras(),
      promocionService.index(),
    ])
    categorias.value = resCategorias.data
    productos.value = resProductos.data
    combos.value = resCombos.data
    extrasTodos.value = resExtras.data
    promociones.value = resPromociones.data
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar el catálogo.')
  } finally {
    cargando.value = false
  }
}

function formatoMoneda(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

// Fase 7, tarea 1 (RF-M04-009, RN-M04-04): un producto con presentaciones solo se ofrece si al
// menos una está activa y no agotada; aquí se avisa cuando no queda ninguna.
function sinPresentacionVendible(producto: Producto) {
  const variantes = producto.variantes ?? []
  return variantes.length > 0 && !variantes.some((v) => v.activa && v.disponible !== false)
}

// Fase 7, tarea 1 (RF-M04-014): referencia administrativa del combo frente a sus productos
// comprados por separado (al precio base de cada uno). El precio del combo lo pone el
// Administrador (RN-M04-06); esto solo lo muestra.
function comparacionCombo(precio: number, suma: number) {
  if (!suma || !precio) return null
  const diferencia = suma - Number(precio)
  if (diferencia > 0) {
    const pct = Math.round((diferencia / suma) * 100)
    return { texto: `Por separado ${formatoMoneda(suma)} · ahorro ${formatoMoneda(diferencia)} (${pct} %)`, alerta: false }
  }
  if (diferencia === 0) return { texto: `Por separado ${formatoMoneda(suma)} · sin ahorro para el cliente`, alerta: true }
  return { texto: `Por separado ${formatoMoneda(suma)} · el combo cuesta ${formatoMoneda(-diferencia)} más`, alerta: true }
}

function sumaComponentesCombo(combo: Combo) {
  return (combo.productos ?? []).reduce((acc, p) => acc + Number(p.precio_base) * (p.pivot?.cantidad ?? 1), 0)
}

function fechaParaInput(v: string | null): string {
  return v ? v.slice(0, 10) : ''
}

function horaParaInput(v: string | null): string {
  return v ? v.slice(0, 5) : ''
}

function horaParaApi(v: string): string | undefined {
  return v ? `${v}:00` : undefined
}

function vigenciaTexto(desde: string | null, hasta: string | null): string {
  if (!desde && !hasta) return 'Sin fecha límite'
  // OJO: 'new Date("2026-12-19")' (fecha sin hora) se interpreta como medianoche UTC — en una
  // zona horaria detrás de UTC (Colombia, UTC-5) toLocaleDateString mostraba el día anterior
  // (18 en vez de 19). Forzamos T00:00:00 para que el navegador la parseé en hora LOCAL.
  const f = (v: string) =>
    new Date(`${v.slice(0, 10)}T00:00:00`).toLocaleDateString('es-CO', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
    })
  if (desde && hasta) return `${f(desde)} – ${f(hasta)}`
  if (desde) return `Desde ${f(desde)}`
  return `Hasta ${f(hasta as string)}`
}

const DIAS_SEMANA = [
  { v: 1, t: 'Lun' },
  { v: 2, t: 'Mar' },
  { v: 3, t: 'Mié' },
  { v: 4, t: 'Jue' },
  { v: 5, t: 'Vie' },
  { v: 6, t: 'Sáb' },
  { v: 7, t: 'Dom' },
]

const categoriaPorId = computed(() => new Map(categorias.value.map((c) => [c.id, c])))
const extrasGenerales = computed(() => extrasTodos.value.filter((e) => e.alcance === 'general'))

// ---- Categorías ----

const modalCategoriaAbierto = ref(false)
const categoriaEditando = ref<Categoria | null>(null)
const formCategoria = ref({ nombre: '', activo: true, permite_quitar_ingredientes: true })
const erroresCategoria = ref<Record<string, string>>({})
const errorCategoria = ref('')
const guardandoCategoria = ref(false)

function abrirNuevaCategoria() {
  categoriaEditando.value = null
  formCategoria.value = { nombre: '', activo: true, permite_quitar_ingredientes: true }
  erroresCategoria.value = {}
  errorCategoria.value = ''
  modalCategoriaAbierto.value = true
}

function abrirEditarCategoria(categoria: Categoria) {
  categoriaEditando.value = categoria
  formCategoria.value = {
    nombre: categoria.nombre,
    activo: categoria.activo,
    permite_quitar_ingredientes: categoria.permite_quitar_ingredientes !== false,
  }
  erroresCategoria.value = {}
  errorCategoria.value = ''
  modalCategoriaAbierto.value = true
}

async function confirmarCategoria() {
  guardandoCategoria.value = true
  erroresCategoria.value = {}
  errorCategoria.value = ''
  try {
    if (categoriaEditando.value) {
      await catalogoService.actualizarCategoria(categoriaEditando.value.id, formCategoria.value)
    } else {
      await catalogoService.crearCategoria(formCategoria.value)
    }
    modalCategoriaAbierto.value = false
    await cargar()
  } catch (e) {
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) {
      erroresCategoria.value = campos
    } else {
      errorCategoria.value = mensajeDeError(e, 'No pudimos guardar la categoría.')
    }
  } finally {
    guardandoCategoria.value = false
  }
}

// ---- Productos ----

const filtroCategoria = ref<'todas' | number>('todas')
const busquedaProducto = ref('')

// ---- Eliminar y restaurar productos (24/10/2026) ----
const verEliminados = ref(false)
const productosEliminados = ref<Producto[]>([])
const cargandoEliminados = ref(false)
const productoAEliminar = ref<Producto | null>(null)
const avisoProductos = ref('')
const restaurandoId = ref<number | null>(null)

async function cargarEliminados() {
  cargandoEliminados.value = true
  try {
    const { data } = await catalogoService.productos({ eliminados: 1 })
    productosEliminados.value = data
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar los productos eliminados.')
  } finally {
    cargandoEliminados.value = false
  }
}

function verProductosEliminados(si: boolean) {
  verEliminados.value = si
  avisoProductos.value = ''
  if (si) cargarEliminados()
}

async function alEliminarProducto(resultado: ResultadoEliminacion) {
  productoAEliminar.value = null
  avisoProductos.value = resultado.mensaje
  await cargar() // los combos que lo traían también cambian
}

async function restaurarProducto(producto: Producto) {
  restaurandoId.value = producto.id
  error.value = ''
  try {
    await eliminacionService.restaurar<Producto>('productos', producto.id)
    avisoProductos.value = `«${producto.nombre}» volvió al catálogo, inactivo: actívalo al editarlo cuando lo vayas a vender.`
    await Promise.all([cargarEliminados(), cargar()])
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos restaurar el producto.')
  } finally {
    restaurandoId.value = null
  }
}

const productosFiltrados = computed(() => {
  return productos.value.filter((p) => {
    if (filtroCategoria.value !== 'todas' && p.categoria_id !== filtroCategoria.value) return false
    if (busquedaProducto.value.trim() && !p.nombre.toLowerCase().includes(busquedaProducto.value.trim().toLowerCase())) {
      return false
    }
    return true
  })
})

const TIPOS_VENTA: { v: 'bebida' | 'alimento' | 'otro'; t: string }[] = [
  { v: 'alimento', t: 'Alimento' },
  { v: 'bebida', t: 'Bebida' },
  { v: 'otro', t: 'Otro' },
]

const modalProductoAbierto = ref(false)
const productoEditando = ref<Producto | null>(null)
const formProducto = ref({
  categoria_id: 0,
  nombre: '',
  descripcion: '',
  precio_base: 0,
  tipo_venta: 'alimento' as 'bebida' | 'alimento' | 'otro',
  imagen_url: '',
  disponible: true,
  activo: true,
  // Fase 2, tarea 3 (RF-M04-011): extras generales que ofrece este producto.
  extras_generales: [] as number[],
})
// Aviso después de guardar (por ejemplo, combos desactivados al dar de baja un producto, RN-M04-12).
const avisoCatalogo = ref('')
const erroresProducto = ref<Record<string, string>>({})
const errorProducto = ref('')
const guardandoProducto = ref(false)
const procesandoDisponibilidad = ref<number | null>(null)

// ---- Variantes y extras específicos del producto en edición ----
interface VarianteEdicion {
  id?: number
  nombre: string
  // Mejora (16/10/2026): lo que trae la presentación y su foto propia.
  descripcion: string
  imagen_url: string | null
  precio: number
  activa: boolean
  guardando?: boolean
  subiendoFoto?: boolean
}
interface ExtraEdicion {
  id?: number
  nombre: string
  precio: number
  activo: boolean
  guardando?: boolean
}

const variantesEdicion = ref<VarianteEdicion[]>([])
const extrasEdicion = ref<ExtraEdicion[]>([])
const nuevaVariante = ref({ nombre: '', precio: 0 })
const nuevoExtraEspecifico = ref({ nombre: '', precio: 0 })
const errorVariantesExtras = ref('')

// ---- Fase 7, tarea 3 (RF-M04-004, HU-M04-002): foto del producto ----
const TIPOS_FOTO = ['image/jpeg', 'image/png', 'image/webp']
const fotoNueva = ref<File | null>(null)
const fotoVistaPrevia = ref('')
const quitarFotoActual = ref(false)
const errorFoto = ref('')
const fotoMostrada = computed(
  () => fotoVistaPrevia.value || (!quitarFotoActual.value ? (productoEditando.value?.imagen_url ?? '') : ''),
)

function limpiarFoto() {
  if (fotoVistaPrevia.value) URL.revokeObjectURL(fotoVistaPrevia.value)
  fotoNueva.value = null
  fotoVistaPrevia.value = ''
  quitarFotoActual.value = false
  errorFoto.value = ''
}

function elegirFoto(evento: Event) {
  const entrada = evento.target as HTMLInputElement
  const archivo = entrada.files?.[0] ?? null
  entrada.value = ''
  if (!archivo) return
  errorFoto.value = ''
  if (!TIPOS_FOTO.includes(archivo.type)) {
    errorFoto.value = 'La foto debe ser JPG, PNG o WebP.'
    return
  }
  if (archivo.size > 5 * 1024 * 1024) {
    errorFoto.value = 'La foto es muy pesada (máximo 5 MB).'
    return
  }
  if (fotoVistaPrevia.value) URL.revokeObjectURL(fotoVistaPrevia.value)
  fotoNueva.value = archivo
  fotoVistaPrevia.value = URL.createObjectURL(archivo)
  quitarFotoActual.value = false
}

function quitarFoto() {
  if (fotoVistaPrevia.value) URL.revokeObjectURL(fotoVistaPrevia.value)
  fotoNueva.value = null
  fotoVistaPrevia.value = ''
  quitarFotoActual.value = !!productoEditando.value?.imagen_url
}

function abrirNuevoProducto() {
  limpiarFoto()
  productoEditando.value = null
  formProducto.value = {
    categoria_id: categorias.value.find((c) => c.activo)?.id ?? 0,
    nombre: '',
    descripcion: '',
    precio_base: 0,
    tipo_venta: 'alimento',
    imagen_url: '',
    disponible: true,
    activo: true,
    extras_generales: [],
  }
  erroresProducto.value = {}
  errorProducto.value = ''
  variantesEdicion.value = []
  extrasEdicion.value = []
  modalProductoAbierto.value = true
}

function abrirEditarProducto(producto: Producto) {
  limpiarFoto()
  productoEditando.value = producto
  formProducto.value = {
    categoria_id: producto.categoria_id,
    nombre: producto.nombre,
    descripcion: producto.descripcion ?? '',
    precio_base: Number(producto.precio_base),
    tipo_venta: producto.tipo_venta,
    imagen_url: producto.imagen_url ?? '',
    disponible: producto.disponible,
    activo: producto.activo,
    extras_generales: (producto.extras_generales ?? []).map((e) => e.id),
  }
  erroresProducto.value = {}
  errorProducto.value = ''
  variantesEdicion.value = (producto.variantes ?? []).map((v) => ({
    id: v.id,
    nombre: v.nombre,
    descripcion: v.descripcion ?? '',
    imagen_url: v.imagen_url ?? null,
    precio: Number(v.precio),
    activa: v.activa,
  }))
  extrasEdicion.value = (producto.extras_especificos ?? []).map((e) => ({
    id: e.id,
    nombre: e.nombre,
    precio: Number(e.precio),
    activo: e.activo,
  }))
  nuevaVariante.value = { nombre: '', precio: 0 }
  nuevoExtraEspecifico.value = { nombre: '', precio: 0 }
  errorVariantesExtras.value = ''
  modalProductoAbierto.value = true
}

async function confirmarProducto() {
  guardandoProducto.value = true
  erroresProducto.value = {}
  errorProducto.value = ''
  try {
    const payload = {
      ...formProducto.value,
      descripcion: formProducto.value.descripcion || undefined,
      // Fase 7, tarea 3: la foto se sube aparte (subirFoto); la dirección ya no se escribe a mano.
      imagen_url: undefined,
      precio_base: Number(formProducto.value.precio_base),
    }
    avisoCatalogo.value = ''
    let productoId: number
    if (productoEditando.value) {
      const { data } = await catalogoService.actualizarProducto(productoEditando.value.id, payload)
      productoId = data.id
      if (data.combos_desactivados?.length) {
        avisoCatalogo.value = `Al dar de baja "${data.nombre}" se desactivaron: ${data.combos_desactivados.join(', ')}. Para volver a ofrecerlos, edítalos y reemplaza ese producto.`
      }
    } else {
      const { data } = await catalogoService.crearProducto(payload)
      productoId = data.id
    }
    // La foto, después de guardar el producto. Si falla, el producto ya quedó guardado.
    try {
      if (fotoNueva.value) await catalogoService.subirFoto(productoId, fotoNueva.value)
      else if (quitarFotoActual.value) await catalogoService.quitarFoto(productoId)
    } catch (e) {
      const campos = erroresDeValidacion(e)
      avisoCatalogo.value = `El producto se guardó, pero la foto no: ${campos.foto ?? mensajeDeError(e, 'inténtalo de nuevo.')}`
    }
    limpiarFoto()
    modalProductoAbierto.value = false
    await cargar()
  } catch (e) {
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) {
      erroresProducto.value = campos
    } else {
      errorProducto.value = mensajeDeError(e, 'No pudimos guardar el producto.')
    }
  } finally {
    guardandoProducto.value = false
  }
}

async function alternarDisponible(producto: Producto) {
  procesandoDisponibilidad.value = producto.id
  error.value = ''
  try {
    const { data } = await catalogoService.toggleDisponible(producto.id, !producto.disponible)
    const i = productos.value.findIndex((p) => p.id === producto.id)
    if (i !== -1) productos.value[i] = { ...productos.value[i], ...data }
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos actualizar la disponibilidad.')
  } finally {
    procesandoDisponibilidad.value = null
  }
}

// ---- Mejora (16/10/2026): importación masiva de productos ----
// 1) descargar la plantilla; 2) subir el archivo y ver qué haría cada fila (no guarda nada);
// 3) importar: todo o nada, y solo si no hay errores.
const modalImportarAbierto = ref(false)
const archivoImportacion = ref<File | null>(null)
const actualizarExistentes = ref(true)
const vistaImportacion = ref<VistaPreviaImportacion | null>(null)
const errorImportacion = ref('')
const analizando = ref(false)
const importando = ref(false)
const descargandoPlantilla = ref(false)
const ACCIONES_IMPORTACION = {
  crear: { texto: 'Nuevo', clase: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' },
  actualizar: { texto: 'Se actualiza', clase: 'bg-sky-50 text-sky-700 ring-sky-600/20' },
  sin_cambios: { texto: 'Sin cambios', clase: 'bg-gray-100 text-gray-500 ring-gray-500/20' },
  error: { texto: 'Error', clase: 'bg-red-50 text-red-700 ring-red-600/20' },
} as const

function abrirImportar() {
  archivoImportacion.value = null
  vistaImportacion.value = null
  errorImportacion.value = ''
  actualizarExistentes.value = true
  modalImportarAbierto.value = true
}

async function descargarPlantilla() {
  descargandoPlantilla.value = true
  errorImportacion.value = ''
  try {
    const { data } = await catalogoService.plantillaImportacion()
    guardarArchivo(data, 'plantilla-productos-fragua-real.xlsx')
  } catch (e) {
    errorImportacion.value = await mensajeDeBlob(e, 'No pudimos descargar la plantilla.')
  } finally {
    descargandoPlantilla.value = false
  }
}

async function analizarImportacion() {
  if (!archivoImportacion.value) return
  analizando.value = true
  errorImportacion.value = ''
  vistaImportacion.value = null
  try {
    const { data } = await catalogoService.vistaPreviaImportacion(archivoImportacion.value, actualizarExistentes.value)
    vistaImportacion.value = data
  } catch (e) {
    errorImportacion.value = erroresDeValidacion(e).archivo ?? mensajeDeError(e, 'No pudimos leer el archivo.')
  } finally {
    analizando.value = false
  }
}

function elegirArchivoImportacion(evento: Event) {
  const entrada = evento.target as HTMLInputElement
  archivoImportacion.value = entrada.files?.[0] ?? null
  entrada.value = ''
  analizarImportacion()
}

watch(actualizarExistentes, () => {
  if (archivoImportacion.value) analizarImportacion()
})

async function confirmarImportacion() {
  if (!archivoImportacion.value || !vistaImportacion.value || vistaImportacion.value.resumen.errores) return
  importando.value = true
  errorImportacion.value = ''
  try {
    const { data } = await catalogoService.importarProductos(archivoImportacion.value, actualizarExistentes.value)
    const partes = [
      data.resumen.crear ? `${data.resumen.crear} producto(s) nuevo(s)` : '',
      data.resumen.actualizar ? `${data.resumen.actualizar} actualizado(s)` : '',
      data.categorias_nuevas.length ? `categorías nuevas: ${data.categorias_nuevas.join(', ')}` : '',
    ].filter(Boolean)
    avisoCatalogo.value = `Importación lista: ${partes.join(' · ') || 'no había cambios'}.`
    modalImportarAbierto.value = false
    await cargar()
  } catch (e) {
    errorImportacion.value = mensajeDeError(e, 'No pudimos importar los productos.')
  } finally {
    importando.value = false
  }
}

// ---- Mejora (16/10/2026): atajo «Poner en oferta» ----
// Precio de oferta o porcentaje, hasta qué día y a qué presentación. Por dentro es una promoción
// automática de producto; un producto tiene una sola oferta a la vez (poner otra la reemplaza).
const productoOferta = ref<Producto | null>(null)
const modalOfertaAbierto = ref(false)
const formOferta = ref<{ modo: 'precio' | 'porcentaje'; precio_oferta: number | null; porcentaje: number | null; variante_id: number | null; hasta: string }>({
  modo: 'precio',
  precio_oferta: null,
  porcentaje: null,
  variante_id: null,
  hasta: '',
})
const erroresOferta = ref<Record<string, string>>({})
const errorOferta = ref('')
const guardandoOferta = ref(false)

const hoyNegocio = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota' }).format(new Date())

const presentacionesOferta = computed(() => (productoOferta.value?.variantes ?? []).filter((v) => v.activa))
// Precio normal de lo que se rebaja (la presentación elegida o el producto).
const precioNormalOferta = computed(() => {
  const p = productoOferta.value
  if (!p) return 0
  const v = presentacionesOferta.value.find((x) => x.id === formOferta.value.variante_id)
  return Number(v?.precio ?? p.precio_base)
})
const resumenOferta = computed(() => {
  const f = formOferta.value
  if (f.modo === 'precio' && f.precio_oferta && f.precio_oferta < precioNormalOferta.value) {
    const ahorro = precioNormalOferta.value - f.precio_oferta
    return `Antes ${formatoMoneda(precioNormalOferta.value)}, ahora ${formatoMoneda(f.precio_oferta)} (ahorro ${formatoMoneda(ahorro)}).`
  }
  if (f.modo === 'porcentaje' && f.porcentaje && f.porcentaje > 0 && f.porcentaje < 100) {
    return `${f.porcentaje} % menos${f.variante_id ? '' : ' en todas sus presentaciones'}.`
  }
  return ''
})

// Un precio de oferta es de una presentación (cada una tiene su precio): al pasar a "precio" sin
// presentación elegida, se toma la primera.
watch(
  () => formOferta.value.modo,
  (modo) => {
    if (modo === 'precio' && formOferta.value.variante_id === null && presentacionesOferta.value.length) {
      formOferta.value.variante_id = presentacionesOferta.value[0].id
    }
  },
)

function textoOferta(producto: Producto) {
  const o = producto.oferta
  if (!o) return ''
  const valor = o.tipo_valor === 'porcentaje' ? `−${Number(o.valor)} %` : `−${formatoMoneda(o.valor)}`
  const fecha = o.hasta ? ` hasta el ${o.hasta.split('-').reverse().join('/')}` : ''
  return `${valor}${o.variante ? ` en ${o.variante}` : ''}${fecha}`
}

function abrirOferta(producto: Producto) {
  productoOferta.value = producto
  const o = producto.oferta
  const presentaciones = (producto.variantes ?? []).filter((v) => v.activa)
  formOferta.value = {
    modo: o?.tipo_valor === 'porcentaje' ? 'porcentaje' : 'precio',
    precio_oferta: null,
    porcentaje: o?.tipo_valor === 'porcentaje' ? Number(o.valor) : null,
    // Con una sola presentación, esa; con varias, la de la oferta actual o la primera.
    variante_id: o?.variante_id ?? (presentaciones.length ? presentaciones[0].id : null),
    hasta: o?.hasta ?? '',
  }
  erroresOferta.value = {}
  errorOferta.value = ''
  modalOfertaAbierto.value = true
}

async function confirmarOferta() {
  if (!productoOferta.value) return
  guardandoOferta.value = true
  erroresOferta.value = {}
  errorOferta.value = ''
  const f = formOferta.value
  try {
    await catalogoService.ponerOferta(productoOferta.value.id, {
      modo: f.modo,
      precio_oferta: f.modo === 'precio' ? f.precio_oferta : null,
      porcentaje: f.modo === 'porcentaje' ? f.porcentaje : null,
      variante_id: f.variante_id,
      hasta: f.hasta || null,
    })
    avisoCatalogo.value = `"${productoOferta.value.nombre}" quedó en oferta. Ya se ve en el Menú, el QR y el pedido del Mesero.`
    modalOfertaAbierto.value = false
    await cargar()
  } catch (e) {
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) erroresOferta.value = campos
    else errorOferta.value = mensajeDeError(e, 'No pudimos poner la oferta.')
  } finally {
    guardandoOferta.value = false
  }
}

async function quitarOferta() {
  if (!productoOferta.value) return
  guardandoOferta.value = true
  errorOferta.value = ''
  try {
    await catalogoService.quitarOferta(productoOferta.value.id)
    avisoCatalogo.value = `Se quitó la oferta de "${productoOferta.value.nombre}".`
    modalOfertaAbierto.value = false
    await cargar()
  } catch (e) {
    errorOferta.value = mensajeDeError(e, 'No pudimos quitar la oferta.')
  } finally {
    guardandoOferta.value = false
  }
}

async function guardarVariante(v: VarianteEdicion) {
  if (!productoEditando.value || !v.id) return
  v.guardando = true
  errorVariantesExtras.value = ''
  try {
    await catalogoService.actualizarVariante(v.id, {
      producto_id: productoEditando.value.id,
      nombre: v.nombre,
      descripcion: v.descripcion.trim() || null,
      precio: Number(v.precio),
      activa: v.activa,
    })
  } catch (e) {
    errorVariantesExtras.value = mensajeDeError(e, 'No pudimos guardar la variante.')
  } finally {
    v.guardando = false
  }
}

// Mejora (16/10/2026): la foto de la presentación se sube al elegirla (la presentación ya existe).
async function elegirFotoVariante(v: VarianteEdicion, evento: Event) {
  const entrada = evento.target as HTMLInputElement
  const archivo = entrada.files?.[0] ?? null
  entrada.value = ''
  if (!archivo || !v.id) return
  errorVariantesExtras.value = ''
  if (!TIPOS_FOTO.includes(archivo.type)) {
    errorVariantesExtras.value = 'La foto debe ser JPG, PNG o WebP.'
    return
  }
  if (archivo.size > 5 * 1024 * 1024) {
    errorVariantesExtras.value = 'La foto es muy pesada (máximo 5 MB).'
    return
  }
  v.subiendoFoto = true
  try {
    const { data } = await catalogoService.subirFotoVariante(v.id, archivo)
    v.imagen_url = data.imagen_url ?? null
    actualizarVarianteEnLista(v)
  } catch (e) {
    errorVariantesExtras.value = erroresDeValidacion(e).foto ?? mensajeDeError(e, 'No pudimos subir la foto.')
  } finally {
    v.subiendoFoto = false
  }
}

async function quitarFotoVariante(v: VarianteEdicion) {
  if (!v.id) return
  v.subiendoFoto = true
  errorVariantesExtras.value = ''
  try {
    await catalogoService.quitarFotoVariante(v.id)
    v.imagen_url = null
    actualizarVarianteEnLista(v)
  } catch (e) {
    errorVariantesExtras.value = mensajeDeError(e, 'No pudimos quitar la foto.')
  } finally {
    v.subiendoFoto = false
  }
}

// Refleja la foto nueva en el producto de la lista sin recargar todo el catálogo.
function actualizarVarianteEnLista(v: VarianteEdicion) {
  const producto = productos.value.find((p) => p.id === productoEditando.value?.id)
  const variante = producto?.variantes?.find((x) => x.id === v.id)
  if (variante) variante.imagen_url = v.imagen_url
}

async function agregarVariante() {
  if (!productoEditando.value || !nuevaVariante.value.nombre.trim()) return
  const nueva: VarianteEdicion = {
    nombre: nuevaVariante.value.nombre,
    descripcion: '',
    imagen_url: null,
    precio: Number(nuevaVariante.value.precio),
    activa: true,
    guardando: true,
  }
  variantesEdicion.value.push(nueva)
  errorVariantesExtras.value = ''
  try {
    const { data } = await catalogoService.crearVariante({
      producto_id: productoEditando.value.id,
      nombre: nueva.nombre,
      precio: nueva.precio,
      activa: true,
    })
    nueva.id = data.id
    nuevaVariante.value = { nombre: '', precio: 0 }
  } catch (e) {
    variantesEdicion.value = variantesEdicion.value.filter((v) => v !== nueva)
    errorVariantesExtras.value = mensajeDeError(e, 'No pudimos crear la variante.')
  } finally {
    nueva.guardando = false
  }
}

async function guardarExtraEspecifico(ex: ExtraEdicion) {
  if (!productoEditando.value || !ex.id) return
  ex.guardando = true
  errorVariantesExtras.value = ''
  try {
    await catalogoService.actualizarExtra(ex.id, {
      nombre: ex.nombre,
      precio: Number(ex.precio),
      alcance: 'especifico',
      producto_id: productoEditando.value.id,
      activo: ex.activo,
    })
  } catch (e) {
    errorVariantesExtras.value = mensajeDeError(e, 'No pudimos guardar el extra.')
  } finally {
    ex.guardando = false
  }
}

async function agregarExtraEspecifico() {
  if (!productoEditando.value || !nuevoExtraEspecifico.value.nombre.trim()) return
  const nuevo: ExtraEdicion = {
    nombre: nuevoExtraEspecifico.value.nombre,
    precio: Number(nuevoExtraEspecifico.value.precio),
    activo: true,
    guardando: true,
  }
  extrasEdicion.value.push(nuevo)
  errorVariantesExtras.value = ''
  try {
    const { data } = await catalogoService.crearExtra({
      nombre: nuevo.nombre,
      precio: nuevo.precio,
      alcance: 'especifico',
      producto_id: productoEditando.value.id,
      activo: true,
    })
    nuevo.id = data.id
    nuevoExtraEspecifico.value = { nombre: '', precio: 0 }
  } catch (e) {
    extrasEdicion.value = extrasEdicion.value.filter((e) => e !== nuevo)
    errorVariantesExtras.value = mensajeDeError(e, 'No pudimos crear el extra.')
  } finally {
    nuevo.guardando = false
  }
}

// Al cerrar el modal de producto (guardado o cancelado) refrescamos el catálogo completo,
// así el conteo de variantes/extras que se editaron sin pasar por "Guardar producto" queda
// reflejado la próxima vez que se abra.
watch(modalProductoAbierto, (abierto) => {
  if (!abierto) cargar()
})

// ---- Combos (M04 parte 2) ----

const modalComboAbierto = ref(false)
const comboEditando = ref<Combo | null>(null)
const formCombo = ref({
  nombre: '',
  precio: 0,
  tipo_venta: 'alimento' as 'bebida' | 'alimento' | 'otro',
  vigencia_inicio: '',
  vigencia_fin: '',
  franja_inicio: '',
  franja_fin: '',
  dias_semana: [] as number[],
  activo: true,
})
const productosCombo = ref<Record<number, number>>({})
const sumaFormCombo = computed(() =>
  Object.entries(productosCombo.value).reduce((acc, [id, cantidad]) => {
    const producto = productos.value.find((p) => p.id === Number(id))
    return acc + (producto && cantidad > 0 ? Number(producto.precio_base) * cantidad : 0)
  }, 0),
)
const unidadesFormCombo = computed(() => Object.values(productosCombo.value).reduce((acc, c) => acc + (c > 0 ? c : 0), 0))
const erroresCombo = ref<Record<string, string>>({})
const errorCombo = ref('')
const guardandoCombo = ref(false)

function abrirNuevoCombo() {
  comboEditando.value = null
  formCombo.value = {
    nombre: '',
    precio: 0,
    tipo_venta: 'alimento',
    vigencia_inicio: '',
    vigencia_fin: '',
    franja_inicio: '',
    franja_fin: '',
    dias_semana: [],
    activo: true,
  }
  productosCombo.value = {}
  erroresCombo.value = {}
  errorCombo.value = ''
  modalComboAbierto.value = true
}

function abrirEditarCombo(combo: Combo) {
  comboEditando.value = combo
  formCombo.value = {
    nombre: combo.nombre,
    precio: Number(combo.precio),
    tipo_venta: combo.tipo_venta,
    vigencia_inicio: fechaParaInput(combo.vigencia_inicio),
    vigencia_fin: fechaParaInput(combo.vigencia_fin),
    franja_inicio: horaParaInput(combo.franja_inicio),
    franja_fin: horaParaInput(combo.franja_fin),
    dias_semana: combo.dias_semana ? [...combo.dias_semana] : [],
    activo: combo.activo,
  }
  productosCombo.value = {}
  for (const p of combo.productos ?? []) {
    productosCombo.value[p.id] = p.pivot.cantidad
  }
  erroresCombo.value = {}
  errorCombo.value = ''
  modalComboAbierto.value = true
}

function alternarProductoCombo(id: number) {
  productosCombo.value[id] = productosCombo.value[id] ? 0 : 1
}

function toggleDiaCombo(dia: number) {
  const i = formCombo.value.dias_semana.indexOf(dia)
  if (i === -1) formCombo.value.dias_semana.push(dia)
  else formCombo.value.dias_semana.splice(i, 1)
}

async function confirmarCombo() {
  guardandoCombo.value = true
  erroresCombo.value = {}
  errorCombo.value = ''
  if (unidadesFormCombo.value < 2) {
    // RF-M04-013: el servidor también lo valida.
    erroresCombo.value = { productos: 'Un combo lleva al menos dos productos (pueden ser 2 del mismo).' }
    guardandoCombo.value = false
    return
  }
  try {
    const productosPayload = Object.entries(productosCombo.value)
      .filter(([, cantidad]) => cantidad > 0)
      .map(([producto_id, cantidad]) => ({ producto_id: Number(producto_id), cantidad }))
    const payload = {
      nombre: formCombo.value.nombre,
      precio: Number(formCombo.value.precio),
      tipo_venta: formCombo.value.tipo_venta,
      vigencia_inicio: formCombo.value.vigencia_inicio || undefined,
      vigencia_fin: formCombo.value.vigencia_fin || undefined,
      franja_inicio: horaParaApi(formCombo.value.franja_inicio),
      franja_fin: horaParaApi(formCombo.value.franja_fin),
      dias_semana: formCombo.value.dias_semana.length ? formCombo.value.dias_semana : undefined,
      activo: formCombo.value.activo,
      productos: productosPayload,
    }
    if (comboEditando.value) {
      await catalogoService.actualizarCombo(comboEditando.value.id, payload)
    } else {
      await catalogoService.crearCombo(payload)
    }
    modalComboAbierto.value = false
    await cargar()
  } catch (e) {
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) {
      erroresCombo.value = campos
    } else {
      errorCombo.value = mensajeDeError(e, 'No pudimos guardar el combo.')
    }
  } finally {
    guardandoCombo.value = false
  }
}

// ---- Extras generales (M04 parte 2) ----

const modalExtraGeneralAbierto = ref(false)
const extraGeneralEditando = ref<Extra | null>(null)
const formExtraGeneral = ref({ nombre: '', precio: 0, activo: true })
const erroresExtraGeneral = ref<Record<string, string>>({})
const errorExtraGeneral = ref('')
const guardandoExtraGeneral = ref(false)

function abrirNuevoExtraGeneral() {
  extraGeneralEditando.value = null
  formExtraGeneral.value = { nombre: '', precio: 0, activo: true }
  erroresExtraGeneral.value = {}
  errorExtraGeneral.value = ''
  modalExtraGeneralAbierto.value = true
}

function abrirEditarExtraGeneral(extra: Extra) {
  extraGeneralEditando.value = extra
  formExtraGeneral.value = { nombre: extra.nombre, precio: Number(extra.precio), activo: extra.activo }
  erroresExtraGeneral.value = {}
  errorExtraGeneral.value = ''
  modalExtraGeneralAbierto.value = true
}

async function confirmarExtraGeneral() {
  guardandoExtraGeneral.value = true
  erroresExtraGeneral.value = {}
  errorExtraGeneral.value = ''
  try {
    const payload = {
      nombre: formExtraGeneral.value.nombre,
      precio: Number(formExtraGeneral.value.precio),
      alcance: 'general' as const,
      activo: formExtraGeneral.value.activo,
    }
    if (extraGeneralEditando.value) {
      await catalogoService.actualizarExtra(extraGeneralEditando.value.id, payload)
    } else {
      await catalogoService.crearExtra(payload)
    }
    modalExtraGeneralAbierto.value = false
    await cargar()
  } catch (e) {
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) {
      erroresExtraGeneral.value = campos
    } else {
      errorExtraGeneral.value = mensajeDeError(e, 'No pudimos guardar el extra.')
    }
  } finally {
    guardandoExtraGeneral.value = false
  }
}

// ---- Promociones (M04 parte 2) ----
// RN-M04-19 a 23: "Navidad", "Amor y Amistad", etc. se crean aquí como una promoción más,
// con vigencia_inicio/vigencia_fin marcando esas fechas — no hay una lista de feriados ni
// lógica especial por nombre de fecha.

// Fase 2, tarea 1: la automática se aplica sola a los pedidos dentro de su vigencia y se combina
// con las demás (RF-M04-035/038); la manual queda lista para que el Mesero la aplique a un pedido
// con un motivo (RF-M04-036), y en ese pedido reemplaza a las automáticas (RF-M04-037).
const TIPOS_PROMOCION = [
  { v: 'manual', t: 'Manual (la aplica el Mesero con motivo)' },
  { v: 'automatica', t: 'Automática (se aplica sola)' },
] as const
const ALCANCES_PROMOCION = [
  { v: 'total', t: 'Todo el pedido' },
  { v: 'categoria', t: 'Una categoría' },
  { v: 'producto', t: 'Productos específicos' },
] as const

const modalPromocionAbierto = ref(false)
const promocionEditando = ref<Promocion | null>(null)
const formPromocion = ref({
  nombre: '',
  tipo: 'manual' as 'automatica' | 'manual',
  alcance: 'total' as 'producto' | 'categoria' | 'total',
  tipo_valor: 'porcentaje' as 'porcentaje' | 'fijo',
  valor: 0,
  vigencia_inicio: '',
  vigencia_fin: '',
  franja_inicio: '',
  franja_fin: '',
  dias_semana: [] as number[],
  activo: true,
})
const productosPromocion = ref<number[]>([])
const categoriasPromocion = ref<number[]>([])
const erroresPromocion = ref<Record<string, string>>({})
const errorPromocion = ref('')
const guardandoPromocion = ref(false)

function abrirNuevaPromocion() {
  promocionEditando.value = null
  formPromocion.value = {
    nombre: '',
    tipo: 'manual',
    alcance: 'total',
    tipo_valor: 'porcentaje',
    valor: 0,
    vigencia_inicio: '',
    vigencia_fin: '',
    franja_inicio: '',
    franja_fin: '',
    dias_semana: [],
    activo: true,
  }
  productosPromocion.value = []
  categoriasPromocion.value = []
  erroresPromocion.value = {}
  errorPromocion.value = ''
  modalPromocionAbierto.value = true
}

function abrirEditarPromocion(promocion: Promocion) {
  promocionEditando.value = promocion
  formPromocion.value = {
    nombre: promocion.nombre,
    tipo: promocion.tipo,
    alcance: promocion.alcance,
    tipo_valor: promocion.tipo_valor,
    valor: Number(promocion.valor),
    vigencia_inicio: fechaParaInput(promocion.vigencia_inicio),
    vigencia_fin: fechaParaInput(promocion.vigencia_fin),
    franja_inicio: horaParaInput(promocion.franja_inicio),
    franja_fin: horaParaInput(promocion.franja_fin),
    dias_semana: promocion.dias_semana ? [...promocion.dias_semana] : [],
    activo: promocion.activo,
  }
  productosPromocion.value = (promocion.productos ?? []).map((p) => p.id)
  categoriasPromocion.value = (promocion.categorias ?? []).map((c) => c.id)
  erroresPromocion.value = {}
  errorPromocion.value = ''
  modalPromocionAbierto.value = true
}

function toggleDiaPromocion(dia: number) {
  const i = formPromocion.value.dias_semana.indexOf(dia)
  if (i === -1) formPromocion.value.dias_semana.push(dia)
  else formPromocion.value.dias_semana.splice(i, 1)
}

function toggleProductoPromocion(id: number) {
  const i = productosPromocion.value.indexOf(id)
  if (i === -1) productosPromocion.value.push(id)
  else productosPromocion.value.splice(i, 1)
}

function toggleCategoriaPromocion(id: number) {
  const i = categoriasPromocion.value.indexOf(id)
  if (i === -1) categoriasPromocion.value.push(id)
  else categoriasPromocion.value.splice(i, 1)
}

async function confirmarPromocion() {
  guardandoPromocion.value = true
  erroresPromocion.value = {}
  errorPromocion.value = ''
  try {
    const payload = {
      nombre: formPromocion.value.nombre,
      tipo: formPromocion.value.tipo,
      alcance: formPromocion.value.alcance,
      tipo_valor: formPromocion.value.tipo_valor,
      valor: Number(formPromocion.value.valor),
      vigencia_inicio: formPromocion.value.vigencia_inicio || undefined,
      vigencia_fin: formPromocion.value.vigencia_fin || undefined,
      franja_inicio: horaParaApi(formPromocion.value.franja_inicio),
      franja_fin: horaParaApi(formPromocion.value.franja_fin),
      dias_semana: formPromocion.value.dias_semana.length ? formPromocion.value.dias_semana : undefined,
      activo: formPromocion.value.activo,
      productos: formPromocion.value.alcance === 'producto' ? productosPromocion.value : undefined,
      categorias: formPromocion.value.alcance === 'categoria' ? categoriasPromocion.value : undefined,
    }
    if (promocionEditando.value) {
      await promocionService.actualizar(promocionEditando.value.id, payload)
    } else {
      await promocionService.crear(payload)
    }
    modalPromocionAbierto.value = false
    await cargar()
  } catch (e) {
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) {
      erroresPromocion.value = campos
    } else {
      errorPromocion.value = mensajeDeError(e, 'No pudimos guardar la promoción.')
    }
  } finally {
    guardandoPromocion.value = false
  }
}

onMounted(cargar)
</script>

<template>
  <div>
    <PageHeader eyebrow="MENÚ" titulo="Catálogo" descripcion="Categorías, productos, combos y promociones del menú.">
      <template #actions>
        <!-- Fase 1, tarea 5: agotar o habilitar productos y tamaños de un vistazo (RF-M04-016/017). -->
        <router-link
          :to="{ name: 'admin-disponibilidad' }"
          class="inline-flex min-h-11 items-center justify-center rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-ink transition hover:border-brand-400 hover:bg-brand-50"
        >
          Agotados y disponibilidad
        </router-link>
        <BaseButton v-escritura v-if="seccion === 'productos'" variant="secondary" @click="abrirImportar">
          <FileSpreadsheet :size="17" />
          Importar
        </BaseButton>
        <BaseButton v-escritura v-if="seccion === 'categorias'" variant="primary" @click="abrirNuevaCategoria">
          <Plus :size="17" />
          Nueva categoría
        </BaseButton>
        <BaseButton v-escritura v-else-if="seccion === 'productos'" variant="primary" @click="abrirNuevoProducto">
          <Plus :size="17" />
          Nuevo producto
        </BaseButton>
        <BaseButton v-escritura v-else-if="seccion === 'combos'" variant="primary" @click="abrirNuevoCombo">
          <Plus :size="17" />
          Nuevo combo
        </BaseButton>
        <BaseButton v-escritura v-else-if="seccion === 'extras'" variant="primary" @click="abrirNuevoExtraGeneral">
          <Plus :size="17" />
          Nuevo extra
        </BaseButton>
        <BaseButton v-escritura v-else variant="primary" @click="abrirNuevaPromocion">
          <Plus :size="17" />
          Nueva promoción
        </BaseButton>
      </template>
    </PageHeader>

    <div class="segmented mt-7 sm:inline-flex">
      <button
        type="button"
        class="segmented-item"
        :class="seccion === 'categorias' ? 'is-active' : ''"
        @click="seccion = 'categorias'"
      >
        <Tags :size="14" class="-mt-0.5 mr-1.5 inline-block" />
        Categorías
      </button>
      <button
        type="button"
        class="segmented-item"
        :class="seccion === 'productos' ? 'is-active' : ''"
        @click="seccion = 'productos'"
      >
        <Boxes :size="14" class="-mt-0.5 mr-1.5 inline-block" />
        Productos
      </button>
      <button
        type="button"
        class="segmented-item"
        :class="seccion === 'combos' ? 'is-active' : ''"
        @click="seccion = 'combos'"
      >
        <Layers :size="14" class="-mt-0.5 mr-1.5 inline-block" />
        Combos
      </button>
      <button
        type="button"
        class="segmented-item"
        :class="seccion === 'extras' ? 'is-active' : ''"
        @click="seccion = 'extras'"
      >
        Extras generales
      </button>
      <button
        type="button"
        class="segmented-item"
        :class="seccion === 'promociones' ? 'is-active' : ''"
        @click="seccion = 'promociones'"
      >
        <Percent :size="14" class="-mt-0.5 mr-1.5 inline-block" />
        Promociones
      </button>
    </div>

    <p v-if="error" class="mt-4 alert-error">{{ error }}</p>
    <p v-if="avisoCatalogo" class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800" role="status">{{ avisoCatalogo }}</p>

    <div v-if="cargando" class="loading-state">Cargando catálogo…</div>

    <!-- ---- Categorías ---- -->
    <template v-else-if="seccion === 'categorias'">
      <div v-if="!categorias.length" class="mt-10 empty-state">
        <Tags :size="32" class="mx-auto text-gray-300" />
        <p class="mt-3 text-sm text-gray-500">Todavía no hay categorías.</p>
      </div>
      <ul v-else class="mt-6 space-y-3">
        <li
          v-for="categoria in categorias"
          :key="categoria.id"
          class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-ink">
              {{ categoria.nombre }}
              <span
                class="badge"
                :class="categoria.activo ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'"
              >
                {{ categoria.activo ? 'Activa' : 'Inactiva' }}
              </span>
            </p>
            <p class="mt-1 text-xs text-gray-500">
              {{ categoria.productos?.length ?? 0 }} producto(s)
            </p>
          </div>
          <BaseButton v-escritura variant="ghost" size="sm" class="shrink-0" @click="abrirEditarCategoria(categoria)">
            <Pencil :size="14" />
            Editar
          </BaseButton>
        </li>
      </ul>
    </template>

    <!-- ---- Productos ---- -->
    <template v-else-if="seccion === 'productos'">
      <div class="mt-6 inline-flex rounded-xl border border-gray-200 bg-white p-1 text-sm">
        <button
          type="button"
          class="rounded-lg px-3.5 py-1.5 font-medium transition"
          :class="!verEliminados ? 'bg-ink text-white' : 'text-gray-600 hover:text-ink'"
          @click="verProductosEliminados(false)"
        >
          Productos
        </button>
        <button
          type="button"
          class="rounded-lg px-3.5 py-1.5 font-medium transition"
          :class="verEliminados ? 'bg-ink text-white' : 'text-gray-600 hover:text-ink'"
          @click="verProductosEliminados(true)"
        >
          Eliminados
        </button>
      </div>
      <p v-if="avisoProductos" class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ avisoProductos }}</p>

      <!-- Eliminados: solo se pueden restaurar. -->
      <template v-if="verEliminados">
        <div v-if="cargandoEliminados" class="loading-state">Cargando…</div>
        <div v-else-if="!productosEliminados.length" class="mt-10 empty-state">
          <Boxes :size="32" class="mx-auto text-gray-300" />
          <p class="mt-3 text-sm text-gray-500">No hay productos eliminados.</p>
        </div>
        <ul v-else class="mt-4 space-y-3">
          <li
            v-for="producto in productosEliminados"
            :key="producto.id"
            class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
          >
            <div class="min-w-0">
              <p class="text-[15px] font-semibold text-ink">{{ producto.nombre }}</p>
              <p class="mt-1 text-xs text-gray-500">
                {{ formatoMoneda(producto.precio_base) }} · eliminado el
                {{ producto.eliminado_at ? new Date(producto.eliminado_at).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) : '' }}
                <template v-if="producto.eliminado_motivo"> · «{{ producto.eliminado_motivo }}»</template>
              </p>
            </div>
            <BaseButton v-escritura variant="secondary" size="sm" :disabled="restaurandoId === producto.id" @click="restaurarProducto(producto)">
              <RotateCcw :size="14" />
              Restaurar
            </BaseButton>
          </li>
        </ul>
      </template>

      <template v-else>
      <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="segmented">
          <button
            type="button"
            class="segmented-item"
            :class="filtroCategoria === 'todas' ? 'is-active' : ''"
            @click="filtroCategoria = 'todas'"
          >
            Todas
          </button>
          <button
            v-for="categoria in categorias"
            :key="categoria.id"
            type="button"
            class="segmented-item"
            :class="filtroCategoria === categoria.id ? 'is-active' : ''"
            @click="filtroCategoria = categoria.id"
          >
            {{ categoria.nombre }}
          </button>
        </div>
        <input v-formato="'busqueda'"
          v-model="busquedaProducto"
          type="text"
          placeholder="Buscar producto…"
          class="field sm:w-64"
        />
      </div>

      <div v-if="!productosFiltrados.length" class="mt-10 empty-state">
        <Boxes :size="32" class="mx-auto text-gray-300" />
        <p class="mt-3 text-sm text-gray-500">No hay productos en este filtro.</p>
      </div>
      <ul v-else class="mt-4 space-y-3">
        <li
          v-for="producto in productosFiltrados"
          :key="producto.id"
          class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <div class="min-w-0">
            <img
              v-if="producto.imagen_url"
              :src="producto.imagen_url"
              alt=""
              loading="lazy"
              class="float-left mr-3 size-14 rounded-lg object-cover"
            />
            <p class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-ink">
              {{ producto.nombre }}
              <span class="badge bg-gray-100 text-gray-500 ring-gray-500/20">
                {{ categoriaPorId.get(producto.categoria_id)?.nombre ?? '—' }}
              </span>
              <span
                class="badge"
                :class="producto.activo ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'"
              >
                {{ producto.activo ? 'Activo' : 'Inactivo' }}
              </span>
              <span
                class="badge"
                :class="producto.disponible ? 'bg-brand-50 text-brand-700 ring-brand-600/20' : 'bg-yellow-50 text-yellow-800 ring-yellow-600/20'"
              >
                {{ producto.disponible ? 'Disponible' : 'Agotado' }}
              </span>
              <span
                v-if="producto.oferta"
                class="badge"
                :class="producto.oferta.vigente ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-gray-100 text-gray-600 ring-gray-500/20'"
                :title="producto.oferta.vigente ? 'Oferta activa' : 'Oferta programada o fuera de su horario'"
              >
                <Percent :size="11" class="inline-block" />
                {{ producto.oferta.vigente ? 'En oferta' : 'Oferta programada' }} {{ textoOferta(producto) }}
              </span>
            </p>
            <p class="mt-1 text-xs text-gray-500">
              {{ formatoMoneda(producto.precio_base) }} ·
              {{ TIPOS_VENTA.find((t) => t.v === producto.tipo_venta)?.t ?? producto.tipo_venta }}
              <template v-if="producto.variantes?.length"> · {{ producto.variantes.length }} variante(s)</template>
              <template v-if="producto.extras_especificos?.length"> · {{ producto.extras_especificos.length }} extra(s)</template>
            </p>
            <p v-if="producto.activo && sinPresentacionVendible(producto)" class="mt-0.5 text-xs font-medium text-amber-700">
              No se ofrece: ninguna presentación está activa y disponible.
            </p>
          </div>
          <div class="grid shrink-0 grid-cols-2 gap-2 sm:flex">
            <BaseButton v-escritura variant="ghost" size="sm" class="col-span-2 sm:col-span-1" @click="abrirOferta(producto)">
              <Percent :size="14" />
              {{ producto.oferta ? 'Cambiar oferta' : 'Poner en oferta' }}
            </BaseButton>
            <BaseButton v-escritura
              variant="ghost"
              size="sm"
              :disabled="procesandoDisponibilidad === producto.id"
              @click="alternarDisponible(producto)"
            >
              {{ producto.disponible ? 'Marcar agotado' : 'Marcar disponible' }}
            </BaseButton>
            <BaseButton v-escritura variant="ghost" size="sm" @click="abrirEditarProducto(producto)">
              <Pencil :size="14" />
              Editar
            </BaseButton>
            <BaseButton
              v-escritura
              variant="ghost"
              size="sm"
              class="!text-red-700 hover:!border-red-300 hover:!bg-red-50"
              @click="(productoAEliminar = producto), (avisoProductos = '')"
            >
              <Trash2 :size="14" />
              Eliminar
            </BaseButton>
          </div>
        </li>
      </ul>
      </template>
      <EliminarModal tipo="productos" articulo="el producto" :registro="productoAEliminar" @cerrar="productoAEliminar = null" @eliminado="alEliminarProducto" />
    </template>

    <!-- ---- Combos ---- -->
    <template v-else-if="seccion === 'combos'">
      <div v-if="!combos.length" class="mt-10 empty-state">
        <Layers :size="32" class="mx-auto text-gray-300" />
        <p class="mt-3 text-sm text-gray-500">Todavía no hay combos.</p>
      </div>
      <ul v-else class="mt-6 space-y-3">
        <li
          v-for="combo in combos"
          :key="combo.id"
          class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-ink">
              {{ combo.nombre }}
              <span
                class="badge"
                :class="combo.activo ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'"
              >
                {{ combo.activo ? 'Activo' : 'Inactivo' }}
              </span>
            </p>
            <p class="mt-1 text-xs text-gray-500">
              {{ formatoMoneda(combo.precio) }} · {{ combo.productos?.length ?? 0 }} producto(s) ·
              {{ vigenciaTexto(combo.vigencia_inicio, combo.vigencia_fin) }}
            </p>
            <p
              v-if="comparacionCombo(combo.precio, sumaComponentesCombo(combo))"
              class="mt-0.5 text-xs"
              :class="comparacionCombo(combo.precio, sumaComponentesCombo(combo))?.alerta ? 'text-amber-700' : 'text-emerald-700'"
            >
              {{ comparacionCombo(combo.precio, sumaComponentesCombo(combo))?.texto }}
            </p>
          </div>
          <BaseButton v-escritura variant="ghost" size="sm" class="shrink-0" @click="abrirEditarCombo(combo)">
            <Pencil :size="14" />
            Editar
          </BaseButton>
        </li>
      </ul>
    </template>

    <!-- ---- Extras generales ---- -->
    <template v-else-if="seccion === 'extras'">
      <div v-if="!extrasGenerales.length" class="mt-10 empty-state">
        <Layers :size="32" class="mx-auto text-gray-300" />
        <p class="mt-3 text-sm text-gray-500">Todavía no hay extras generales. Créalos aquí y elige en cada producto (al editarlo) cuáles ofrece. Un extra que no se marca en ningún producto no se ofrece.</p>
      </div>
      <ul v-else class="mt-6 space-y-3">
        <li
          v-for="extra in extrasGenerales"
          :key="extra.id"
          class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-ink">
              {{ extra.nombre }}
              <span
                class="badge"
                :class="extra.activo ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'"
              >
                {{ extra.activo ? 'Activo' : 'Inactivo' }}
              </span>
            </p>
            <p class="mt-1 text-xs text-gray-500">
              {{ formatoMoneda(extra.precio) }} ·
              {{ extra.productos?.length ? `se ofrece con ${extra.productos.map((p) => p.nombre).join(', ')}` : 'no se ofrece con ningún producto: márcalo en los productos que lo llevan' }}
            </p>
          </div>
          <BaseButton v-escritura variant="ghost" size="sm" class="shrink-0" @click="abrirEditarExtraGeneral(extra)">
            <Pencil :size="14" />
            Editar
          </BaseButton>
        </li>
      </ul>
    </template>

    <!-- ---- Promociones ---- -->
    <template v-else>
      <div v-if="!promociones.length" class="mt-10 empty-state">
        <Percent :size="32" class="mx-auto text-gray-300" />
        <p class="mt-3 text-sm text-gray-500">
          Todavía no hay promociones. Créalas para fechas como Navidad o Amor y Amistad con su rango de fechas.
        </p>
      </div>
      <ul v-else class="mt-6 space-y-3">
        <li
          v-for="promocion in promociones"
          :key="promocion.id"
          class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-ink">
              {{ promocion.nombre }}
              <span
                class="badge"
                :class="promocion.activo ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'"
              >
                {{ promocion.activo ? 'Activa' : 'Inactiva' }}
              </span>
              <span class="badge bg-gray-100 text-gray-500 ring-gray-500/20">
                {{ ALCANCES_PROMOCION.find((a) => a.v === promocion.alcance)?.t }}
              </span>
              <span v-if="promocion.es_oferta" class="badge bg-brand-50 text-brand-700 ring-brand-600/20">Oferta de producto</span>
            </p>
            <p class="mt-1 text-xs text-gray-500">
              {{
                promocion.tipo_valor === 'porcentaje'
                  ? `${Number(promocion.valor)}% de descuento`
                  : `${formatoMoneda(promocion.valor)} de descuento`
              }}
              <template v-if="promocion.variante"> · solo {{ promocion.variante.nombre }}</template>
              · {{ vigenciaTexto(promocion.vigencia_inicio, promocion.vigencia_fin) }}
            </p>
          </div>
          <BaseButton v-escritura variant="ghost" size="sm" class="shrink-0" @click="abrirEditarPromocion(promocion)">
            <Pencil :size="14" />
            Editar
          </BaseButton>
        </li>
      </ul>
    </template>

    <!-- Crear/editar categoría -->
    <BaseModal v-model="modalCategoriaAbierto" :title="categoriaEditando ? 'Editar categoría' : 'Nueva categoría'">
      <form class="space-y-4" @submit.prevent="confirmarCategoria">
        <label class="block">
          <span class="field-label">Nombre</span>
          <input v-formato="'nombre'" v-model="formCategoria.nombre" type="text" class="field" />
          <span v-if="erroresCategoria.nombre" class="mt-1 block text-xs text-red-600">{{ erroresCategoria.nombre }}</span>
        </label>

        <label v-if="categoriaEditando" class="flex items-center gap-2">
          <input v-model="formCategoria.activo" type="checkbox" class="size-[18px] accent-brand-600" />
          <span class="text-sm text-gray-700">Categoría activa</span>
        </label>

        <!-- Mejora (26/10/2026) -->
        <label class="flex items-start gap-2">
          <input v-model="formCategoria.permite_quitar_ingredientes" type="checkbox" class="mt-0.5 size-[18px] accent-brand-600" />
          <span class="text-sm text-gray-700">
            Se pueden quitar ingredientes
            <span class="block text-xs text-gray-500">El menú le pregunta al cliente «¿Quieres quitar algún ingrediente?». Apágalo en Bebidas.</span>
          </span>
        </label>

        <p v-if="errorCategoria" class="alert-error">{{ errorCategoria }}</p>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="modalCategoriaAbierto = false">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="guardandoCategoria">
            {{ guardandoCategoria ? 'Guardando…' : 'Guardar' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>

    <!-- Crear/editar producto -->
    <BaseModal v-model="modalProductoAbierto" :title="productoEditando ? 'Editar producto' : 'Nuevo producto'">
      <form class="space-y-4" @submit.prevent="confirmarProducto">
        <label class="block">
          <span class="field-label">Categoría</span>
          <select v-model.number="formProducto.categoria_id" class="field">
            <option :value="0" disabled>Selecciona una categoría</option>
            <option v-for="categoria in categorias" :key="categoria.id" :value="categoria.id">{{ categoria.nombre }}</option>
          </select>
          <span v-if="erroresProducto.categoria_id" class="mt-1 block text-xs text-red-600">{{ erroresProducto.categoria_id }}</span>
        </label>

        <label class="block">
          <span class="field-label">Nombre</span>
          <input v-formato="'nombre'" v-model="formProducto.nombre" type="text" class="field" />
          <span v-if="erroresProducto.nombre" class="mt-1 block text-xs text-red-600">{{ erroresProducto.nombre }}</span>
        </label>

        <label class="block">
          <span class="field-label">Descripción (opcional)</span>
          <textarea v-formato="'texto'" maxlength="1000" v-model="formProducto.descripcion" rows="2" class="field"></textarea>
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Precio base</span>
            <CampoPesos v-model="formProducto.precio_base" class="field" />
            <span v-if="erroresProducto.precio_base" class="mt-1 block text-xs text-red-600">{{ erroresProducto.precio_base }}</span>
          </label>
          <label class="block">
            <span class="field-label">Tipo de venta</span>
            <select v-model="formProducto.tipo_venta" class="field">
              <option v-for="tipo in TIPOS_VENTA" :key="tipo.v" :value="tipo.v">{{ tipo.t }}</option>
            </select>
            <span v-if="erroresProducto.tipo_venta" class="mt-1 block text-xs text-red-600">{{ erroresProducto.tipo_venta }}</span>
          </label>
        </div>

        <!-- Fase 7, tarea 3 (RF-M04-004, HU-M04-002): la foto se sube; el servidor la ajusta. -->
        <div>
          <p class="field-label">Foto (opcional)</p>
          <div class="flex items-center gap-4">
            <div class="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-gray-200 bg-gray-50 text-gray-300">
              <img v-if="fotoMostrada" :src="fotoMostrada" alt="Foto del producto" class="size-full object-cover" />
              <ImageIcon v-else :size="28" />
            </div>
            <div class="min-w-0 space-y-2">
              <div class="flex flex-wrap gap-2">
                <label class="inline-flex min-h-10 cursor-pointer items-center rounded-xl border border-gray-200 px-3 text-sm font-semibold text-ink transition hover:border-brand-400 hover:bg-brand-50">
                  {{ fotoMostrada ? 'Cambiar foto' : 'Elegir foto' }}
                  <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="elegirFoto" />
                </label>
                <button
                  v-if="fotoMostrada"
                  type="button"
                  class="inline-flex min-h-10 items-center rounded-xl px-3 text-sm font-semibold text-red-600 transition hover:bg-red-50"
                  @click="quitarFoto"
                >
                  Quitar foto
                </button>
              </div>
              <p class="text-xs text-gray-400">JPG, PNG o WebP, de al menos 200 × 200 px y hasta 5 MB. Se ajusta sola para que el menú cargue rápido. Se guarda al guardar el producto.</p>
              <p v-if="quitarFotoActual" class="text-xs text-amber-700">La foto se quitará al guardar.</p>
            </div>
          </div>
          <span v-if="errorFoto" class="mt-1 block text-xs text-red-600">{{ errorFoto }}</span>
        </div>

        <div class="flex flex-wrap gap-6">
          <label class="flex items-center gap-2">
            <input v-model="formProducto.disponible" type="checkbox" class="size-[18px] accent-brand-600" />
            <span class="text-sm text-gray-700">Disponible</span>
          </label>
          <label v-if="productoEditando" class="flex items-center gap-2">
            <input v-model="formProducto.activo" type="checkbox" class="size-[18px] accent-brand-600" />
            <span class="text-sm text-gray-700">Activo</span>
          </label>
        </div>

        <!-- Fase 2, tarea 3 (RF-M04-011): extras generales que se ofrecen con este producto. -->
        <fieldset v-if="extrasGenerales.some((e) => e.activo)" class="space-y-2 border-t border-gray-200 pt-4">
          <legend class="field-label">Extras generales que ofrece</legend>
          <div class="flex flex-wrap gap-x-5 gap-y-2">
            <label v-for="extra in extrasGenerales.filter((e) => e.activo)" :key="extra.id" class="flex items-center gap-2 text-sm text-gray-700">
              <input v-model="formProducto.extras_generales" type="checkbox" :value="extra.id" class="size-[18px] accent-brand-600" />
              {{ extra.nombre }} (+{{ formatoMoneda(extra.precio) }})
            </label>
          </div>
          <p class="text-xs text-gray-500">Solo los extras marcados aquí se ofrecen con este producto. Un extra general que no se marca en ningún producto no se ofrece.</p>
          <span v-if="erroresProducto.extras_generales" class="block text-xs text-red-600">{{ erroresProducto.extras_generales }}</span>
        </fieldset>

        <p v-if="errorProducto" class="alert-error">{{ errorProducto }}</p>

        <!-- Variantes (M04 parte 2) — solo tiene sentido con el producto ya creado -->
        <div v-if="productoEditando" class="space-y-3 border-t border-gray-200 pt-4">
          <p class="field-label">Variantes (tamaños/presentaciones)</p>
          <p class="text-xs text-gray-500">
            Con presentaciones, el cliente y el Mesero eligen una al pedir. Si ninguna queda activa y disponible, el producto deja
            de ofrecerse. Escribe qué trae cada una y, si quieres, súbele su foto: al elegirla, el cliente ve esa foto (sin foto
            propia, ve la del producto).
          </p>
          <div v-if="variantesEdicion.length" class="space-y-3">
            <div v-for="v in variantesEdicion" :key="v.id ?? v.nombre" class="space-y-2 rounded-xl border border-gray-200 p-3">
              <div class="flex flex-wrap items-center gap-2">
                <input v-formato="'nombre'" v-model="v.nombre" type="text" placeholder="Nombre" class="min-w-0 flex-1 field-sm" />
                <CampoPesos v-model="v.precio" placeholder="Precio" class="w-24 field-sm" />
                <label class="flex items-center gap-1 text-xs text-gray-600">
                  <input v-model="v.activa" type="checkbox" class="h-3.5 w-3.5 accent-brand-600" />
                  Activa
                </label>
              </div>
              <input v-formato="'texto'"
                v-model="v.descripcion"
                type="text"
                maxlength="160"
                placeholder="Qué trae (ej. doble carne, para 3 o 4 personas)"
                class="w-full field-sm"
              />
              <div class="flex flex-wrap items-center gap-2">
                <img v-if="v.imagen_url" :src="v.imagen_url" alt="" class="size-12 rounded-lg object-cover" />
                <span v-else class="flex size-12 items-center justify-center rounded-lg bg-gray-100 text-gray-400">
                  <ImageIcon :size="18" />
                </span>
                <template v-if="v.id">
                  <label
                    class="cursor-pointer rounded-lg px-2.5 py-1.5 text-xs font-medium text-brand-700 hover:bg-brand-50"
                    :class="v.subiendoFoto ? 'pointer-events-none opacity-50' : ''"
                  >
                    {{ v.subiendoFoto ? 'Subiendo…' : v.imagen_url ? 'Cambiar foto' : 'Subir foto' }}
                    <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="elegirFotoVariante(v, $event)" />
                  </label>
                  <button
                    v-if="v.imagen_url"
                    type="button"
                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
                    :disabled="v.subiendoFoto"
                    @click="quitarFotoVariante(v)"
                  >
                    Quitar foto
                  </button>
                </template>
                <BaseButton variant="ghost" type="button" class="ml-auto" :disabled="v.guardando" @click="guardarVariante(v)">
                  {{ v.guardando ? '…' : 'Guardar' }}
                </BaseButton>
              </div>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <input v-formato="'nombre'" v-model="nuevaVariante.nombre" type="text" placeholder="Nueva variante (ej. Familiar)" class="min-w-0 flex-1 field-sm" />
            <CampoPesos v-model="nuevaVariante.precio" placeholder="Precio" class="w-24 field-sm" />
            <BaseButton variant="ghost" type="button" @click="agregarVariante">
              <Plus :size="14" class="inline-block" />
            </BaseButton>
          </div>
        </div>

        <!-- Extras específicos de este producto (M04 parte 2) -->
        <div v-if="productoEditando" class="space-y-3 border-t border-gray-200 pt-4">
          <p class="field-label">Extras específicos de este producto</p>
          <div v-if="extrasEdicion.length" class="space-y-2">
            <div v-for="e in extrasEdicion" :key="e.id ?? e.nombre" class="flex flex-wrap items-center gap-2">
              <input v-formato="'nombre'" v-model="e.nombre" type="text" placeholder="Nombre" class="min-w-0 flex-1 field-sm" />
              <CampoPesos v-model="e.precio" placeholder="Precio" class="w-24 field-sm" />
              <label class="flex items-center gap-1 text-xs text-gray-600">
                <input v-model="e.activo" type="checkbox" class="h-3.5 w-3.5 accent-brand-600" />
                Activo
              </label>
              <BaseButton variant="ghost" type="button" :disabled="e.guardando" @click="guardarExtraEspecifico(e)">
                {{ e.guardando ? '…' : 'Guardar' }}
              </BaseButton>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <input v-formato="'nombre'" v-model="nuevoExtraEspecifico.nombre" type="text" placeholder="Nuevo extra (ej. Salsa BBQ)" class="min-w-0 flex-1 field-sm" />
            <CampoPesos v-model="nuevoExtraEspecifico.precio" placeholder="Precio" class="w-24 field-sm" />
            <BaseButton variant="ghost" type="button" @click="agregarExtraEspecifico">
              <Plus :size="14" class="inline-block" />
            </BaseButton>
          </div>
          <span v-if="errorVariantesExtras" class="block text-xs text-red-600">{{ errorVariantesExtras }}</span>
        </div>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="modalProductoAbierto = false">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="guardandoProducto">
            {{ guardandoProducto ? 'Guardando…' : 'Guardar' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>

    <!-- Mejora (16/10/2026): «Poner en oferta» -->
    <BaseModal v-model="modalOfertaAbierto" :title="productoOferta?.oferta ? 'Cambiar oferta' : 'Poner en oferta'">
      <form v-if="productoOferta" class="space-y-4" @submit.prevent="confirmarOferta">
        <p class="text-sm text-gray-600">
          <span class="font-semibold text-ink">{{ productoOferta.nombre }}</span>
          <template v-if="productoOferta.oferta">
            · oferta actual: {{ textoOferta(productoOferta) }}. Guardar la reemplaza.
          </template>
        </p>

        <div class="segmented flex">
          <button
            type="button"
            class="segmented-item flex-1"
            :class="formOferta.modo === 'precio' ? 'is-active' : ''"
            @click="formOferta.modo = 'precio'"
          >
            Precio de oferta
          </button>
          <button
            type="button"
            class="segmented-item flex-1"
            :class="formOferta.modo === 'porcentaje' ? 'is-active' : ''"
            @click="formOferta.modo = 'porcentaje'"
          >
            Porcentaje
          </button>
        </div>

        <label v-if="presentacionesOferta.length" class="block">
          <span class="field-label">Presentación</span>
          <select v-model="formOferta.variante_id" class="field">
            <option v-if="formOferta.modo === 'porcentaje'" :value="null">Todas las presentaciones</option>
            <option v-for="v in presentacionesOferta" :key="v.id" :value="v.id">
              {{ v.nombre }} ({{ formatoMoneda(v.precio) }})
            </option>
          </select>
          <span v-if="erroresOferta.variante_id" class="mt-1 block text-xs text-red-600">{{ erroresOferta.variante_id }}</span>
        </label>

        <label v-if="formOferta.modo === 'precio'" class="block">
          <span class="field-label">Precio de oferta (normal {{ formatoMoneda(precioNormalOferta) }})</span>
          <CampoPesos v-model="formOferta.precio_oferta" class="field" placeholder="Ej. 28000" />
          <span v-if="erroresOferta.precio_oferta" class="mt-1 block text-xs text-red-600">{{ erroresOferta.precio_oferta }}</span>
        </label>
        <label v-else class="block">
          <span class="field-label">Porcentaje de descuento</span>
          <input v-formato="'porcentaje'" maxlength="2" v-model.number="formOferta.porcentaje" type="text" min="1" max="99" class="field" placeholder="Ej. 15" />
          <span v-if="erroresOferta.porcentaje" class="mt-1 block text-xs text-red-600">{{ erroresOferta.porcentaje }}</span>
        </label>

        <label class="block">
          <span class="field-label">Hasta (opcional)</span>
          <input v-model="formOferta.hasta" type="date" :min="hoyNegocio()" class="field" />
          <span class="mt-1 block text-xs text-gray-500">Empieza hoy. Sin fecha, sigue hasta que la quites.</span>
          <span v-if="erroresOferta.hasta" class="mt-1 block text-xs text-red-600">{{ erroresOferta.hasta }}</span>
        </label>

        <p v-if="resumenOferta" class="rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ resumenOferta }}</p>
        <p class="text-xs text-gray-500">
          Se combina con las demás promociones vigentes y un descuento manual la reemplaza. Para horarios o días de la semana,
          usa Promociones.
        </p>
        <p v-if="errorOferta" class="alert-error">{{ errorOferta }}</p>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton
            v-if="productoOferta.oferta"
            variant="danger-soft"
            type="button"
            class="sm:mr-auto"
            :disabled="guardandoOferta"
            @click="quitarOferta"
          >
            Quitar oferta
          </BaseButton>
          <BaseButton variant="ghost" type="button" @click="modalOfertaAbierto = false">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="guardandoOferta">
            {{ guardandoOferta ? 'Guardando…' : 'Poner en oferta' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>

    <!-- Mejora (16/10/2026): importación masiva de productos -->
    <BaseModal v-model="modalImportarAbierto" title="Importar productos">
      <div class="space-y-5">
        <!-- 1. Plantilla -->
        <div class="flex items-start gap-3 rounded-xl bg-gray-50 p-4">
          <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-brand-400">1</span>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-ink">Descarga la plantilla y llénala</p>
            <p class="mt-0.5 text-xs text-gray-500">
              Categoría, nombre, descripción, precio, tipo de venta (Alimento, Bebida u Otro), presentaciones
              (Personal:18000 | Familiar:32000) y si está disponible. Hasta 500 productos por archivo.
            </p>
            <BaseButton variant="secondary" size="sm" class="mt-3" :disabled="descargandoPlantilla" @click="descargarPlantilla">
              <Download :size="14" />
              {{ descargandoPlantilla ? 'Descargando…' : 'Descargar plantilla (Excel)' }}
            </BaseButton>
          </div>
        </div>

        <!-- 2. Archivo -->
        <div class="flex items-start gap-3 rounded-xl bg-gray-50 p-4">
          <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-brand-400">2</span>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-ink">Sube el archivo</p>
            <label class="mt-3 flex cursor-pointer items-center gap-2 rounded-xl border border-dashed border-gray-300 bg-white px-4 py-3 text-sm text-gray-600 hover:border-brand-500">
              <Upload :size="16" class="shrink-0 text-brand-700" />
              <span class="min-w-0 truncate">{{ archivoImportacion?.name ?? 'Elegir archivo Excel (.xlsx) o CSV' }}</span>
              <input type="file" accept=".xlsx,.xls,.csv" class="sr-only" @change="elegirArchivoImportacion" />
            </label>
            <label class="mt-3 flex items-start gap-2 text-sm text-gray-700">
              <input v-model="actualizarExistentes" type="checkbox" class="mt-0.5 size-4 accent-brand-600" />
              <span>
                Actualizar los productos que ya existen (por nombre)
                <span class="block text-xs text-gray-500">Sirve para cambiar precios de todo el menú de una vez. Las celdas vacías no borran nada.</span>
              </span>
            </label>
          </div>
        </div>

        <p v-if="analizando" class="loading-state !py-4">Revisando el archivo…</p>
        <p v-if="errorImportacion" class="alert-error">{{ errorImportacion }}</p>

        <!-- 3. Vista previa -->
        <div v-if="vistaImportacion" class="space-y-3">
          <div class="grid grid-cols-2 gap-2 text-center text-xs sm:grid-cols-4">
            <div class="rounded-xl bg-emerald-50 p-2.5 text-emerald-800"><p class="text-lg font-semibold">{{ vistaImportacion.resumen.crear }}</p>nuevos</div>
            <div class="rounded-xl bg-sky-50 p-2.5 text-sky-800"><p class="text-lg font-semibold">{{ vistaImportacion.resumen.actualizar }}</p>se actualizan</div>
            <div class="rounded-xl bg-gray-100 p-2.5 text-gray-600"><p class="text-lg font-semibold">{{ vistaImportacion.resumen.sin_cambios }}</p>sin cambios</div>
            <div class="rounded-xl p-2.5" :class="vistaImportacion.resumen.errores ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-600'">
              <p class="text-lg font-semibold">{{ vistaImportacion.resumen.errores }}</p>con errores
            </div>
          </div>
          <p v-if="vistaImportacion.categorias_nuevas.length" class="text-xs text-gray-600">
            Se crearán las categorías: <strong>{{ vistaImportacion.categorias_nuevas.join(', ') }}</strong>.
          </p>

          <ul class="max-h-72 space-y-1.5 overflow-y-auto pr-1">
            <li
              v-for="f in vistaImportacion.filas"
              :key="f.fila"
              class="rounded-lg border px-3 py-2 text-xs"
              :class="f.accion === 'error' ? 'border-red-200 bg-red-50/50' : 'border-gray-200 bg-white'"
            >
              <p class="flex flex-wrap items-center gap-2">
                <span class="text-gray-400">Fila {{ f.fila }}</span>
                <span class="font-semibold text-ink">{{ f.nombre || 'Sin nombre' }}</span>
                <span class="badge" :class="ACCIONES_IMPORTACION[f.accion].clase">{{ ACCIONES_IMPORTACION[f.accion].texto }}</span>
              </p>
              <p v-if="f.accion === 'crear'" class="mt-0.5 text-gray-500">
                {{ f.categoria }} · {{ formatoMoneda(f.precio_base ?? 0) }}
                <template v-if="f.presentaciones.length"> · {{ f.presentaciones.map((p) => `${p.nombre} ${formatoMoneda(p.precio)}`).join(', ') }}</template>
              </p>
              <p v-else-if="f.accion === 'actualizar'" class="mt-0.5 text-gray-500">Cambia: {{ f.cambios.join(', ') }}</p>
              <ul v-if="f.errores.length" class="mt-0.5 list-disc pl-4 text-red-700">
                <li v-for="e in f.errores" :key="e">{{ e }}</li>
              </ul>
            </li>
          </ul>

          <p v-if="vistaImportacion.resumen.errores" class="text-xs text-red-700">
            Corrige las filas con errores en el archivo y súbelo de nuevo. No se guarda nada mientras haya errores.
          </p>
        </div>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="modalImportarAbierto = false">Cancelar</BaseButton>
          <BaseButton
            variant="primary"
            type="button"
            :disabled="!vistaImportacion || vistaImportacion.resumen.errores > 0 || importando || analizando || (vistaImportacion.resumen.crear + vistaImportacion.resumen.actualizar) === 0"
            @click="confirmarImportacion"
          >
            {{
              importando
                ? 'Importando…'
                : vistaImportacion
                  ? `Importar ${vistaImportacion.resumen.crear + vistaImportacion.resumen.actualizar} producto(s)`
                  : 'Importar'
            }}
          </BaseButton>
        </div>
      </div>
    </BaseModal>

    <!-- Crear/editar combo -->
    <BaseModal v-model="modalComboAbierto" :title="comboEditando ? 'Editar combo' : 'Nuevo combo'">
      <form class="space-y-4" @submit.prevent="confirmarCombo">
        <label class="block">
          <span class="field-label">Nombre</span>
          <input v-formato="'nombre'" v-model="formCombo.nombre" type="text" class="field" />
          <span v-if="erroresCombo.nombre" class="mt-1 block text-xs text-red-600">{{ erroresCombo.nombre }}</span>
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Precio del combo</span>
            <CampoPesos v-model="formCombo.precio" class="field" />
            <span v-if="erroresCombo.precio" class="mt-1 block text-xs text-red-600">{{ erroresCombo.precio }}</span>
          </label>
          <label class="block">
            <span class="field-label">Tipo de venta</span>
            <select v-model="formCombo.tipo_venta" class="field">
              <option v-for="tipo in TIPOS_VENTA" :key="tipo.v" :value="tipo.v">{{ tipo.t }}</option>
            </select>
            <span v-if="erroresCombo.tipo_venta" class="mt-1 block text-xs text-red-600">{{ erroresCombo.tipo_venta }}</span>
          </label>
        </div>

        <div>
          <p class="field-label">Productos incluidos</p>
          <div class="max-h-48 space-y-1.5 overflow-y-auto rounded-xl border border-gray-200 bg-gray-50/60 p-2">
            <label v-for="producto in productos" :key="producto.id" class="flex items-center gap-2 rounded px-1.5 py-1 hover:bg-gray-50">
              <input
                type="checkbox"
                :checked="!!productosCombo[producto.id]"
                class="size-[18px] accent-brand-600"
                @change="alternarProductoCombo(producto.id)"
              />
              <span class="flex-1 text-sm text-gray-700">
                {{ producto.nombre }}
                <!-- RN-M04-12: un componente dado de baja impide activar el combo. -->
                <span v-if="!producto.activo" class="ml-1 text-xs font-medium text-red-600">(dado de baja)</span>
              </span>
              <input v-formato="'entero'" maxlength="2"
                v-if="productosCombo[producto.id]"
                v-model.number="productosCombo[producto.id]"
                type="text"
                min="1"
                class="w-16 field-sm"
              />
            </label>
          </div>
          <span v-if="erroresCombo.productos" class="mt-1 block text-xs text-red-600">{{ erroresCombo.productos }}</span>
          <p
            v-else-if="comparacionCombo(Number(formCombo.precio), sumaFormCombo)"
            class="mt-1.5 text-xs"
            :class="comparacionCombo(Number(formCombo.precio), sumaFormCombo)?.alerta ? 'text-amber-700' : 'text-emerald-700'"
          >
            {{ comparacionCombo(Number(formCombo.precio), sumaFormCombo)?.texto }}
            <span class="text-gray-400">(a precio base; solo como referencia)</span>
          </p>
        </div>

        <div>
          <p class="field-label">Vigencia (opcional)</p>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1.5 block text-xs text-gray-500">Desde</span>
              <input v-model="formCombo.vigencia_inicio" type="date" class="field" />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-xs text-gray-500">Hasta</span>
              <input v-model="formCombo.vigencia_fin" type="date" class="field" />
              <span v-if="erroresCombo.vigencia_fin" class="mt-1 block text-xs text-red-600">{{ erroresCombo.vigencia_fin }}</span>
            </label>
          </div>
        </div>

        <div>
          <p class="field-label">Franja horaria (opcional)</p>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1.5 block text-xs text-gray-500">Desde</span>
              <input v-model="formCombo.franja_inicio" type="time" class="field" />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-xs text-gray-500">Hasta</span>
              <input v-model="formCombo.franja_fin" type="time" class="field" />
              <span v-if="erroresCombo.franja_fin" class="mt-1 block text-xs text-red-600">{{ erroresCombo.franja_fin }}</span>
            </label>
          </div>
        </div>

        <div>
          <p class="field-label">Días de la semana (opcional — vacío = todos)</p>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="dia in DIAS_SEMANA"
              :key="dia.v"
              type="button"
              class="rounded-lg border px-3 py-2 text-xs font-semibold transition"
              :class="formCombo.dias_semana.includes(dia.v) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-brand-300'"
              @click="toggleDiaCombo(dia.v)"
            >
              {{ dia.t }}
            </button>
          </div>
        </div>

        <label v-if="comboEditando" class="flex items-center gap-2">
          <input v-model="formCombo.activo" type="checkbox" class="size-[18px] accent-brand-600" />
          <span class="text-sm text-gray-700">Combo activo</span>
        </label>

        <p v-if="errorCombo" class="alert-error">{{ errorCombo }}</p>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="modalComboAbierto = false">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="guardandoCombo">
            {{ guardandoCombo ? 'Guardando…' : 'Guardar' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>

    <!-- Crear/editar extra general -->
    <BaseModal v-model="modalExtraGeneralAbierto" :title="extraGeneralEditando ? 'Editar extra' : 'Nuevo extra general'">
      <form class="space-y-4" @submit.prevent="confirmarExtraGeneral">
        <label class="block">
          <span class="field-label">Nombre</span>
          <input v-formato="'nombre'" v-model="formExtraGeneral.nombre" type="text" class="field" />
          <span v-if="erroresExtraGeneral.nombre" class="mt-1 block text-xs text-red-600">{{ erroresExtraGeneral.nombre }}</span>
        </label>
        <label class="block">
          <span class="field-label">Precio</span>
          <CampoPesos v-model="formExtraGeneral.precio" class="field" />
          <span v-if="erroresExtraGeneral.precio" class="mt-1 block text-xs text-red-600">{{ erroresExtraGeneral.precio }}</span>
        </label>
        <label v-if="extraGeneralEditando" class="flex items-center gap-2">
          <input v-model="formExtraGeneral.activo" type="checkbox" class="size-[18px] accent-brand-600" />
          <span class="text-sm text-gray-700">Extra activo</span>
        </label>
        <p v-if="errorExtraGeneral" class="alert-error">{{ errorExtraGeneral }}</p>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="modalExtraGeneralAbierto = false">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="guardandoExtraGeneral">
            {{ guardandoExtraGeneral ? 'Guardando…' : 'Guardar' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>

    <!-- Crear/editar promoción -->
    <BaseModal v-model="modalPromocionAbierto" :title="promocionEditando ? 'Editar promoción' : 'Nueva promoción'">
      <form class="space-y-4" @submit.prevent="confirmarPromocion">
        <label class="block">
          <span class="field-label">Nombre</span>
          <input v-formato="'nombre'" v-model="formPromocion.nombre" type="text" placeholder="Ej. Navidad, Amor y Amistad…" class="field" />
          <span v-if="erroresPromocion.nombre" class="mt-1 block text-xs text-red-600">{{ erroresPromocion.nombre }}</span>
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Tipo</span>
            <select v-model="formPromocion.tipo" class="field">
              <option v-for="t in TIPOS_PROMOCION" :key="t.v" :value="t.v">{{ t.t }}</option>
            </select>
          </label>
          <label class="block">
            <span class="field-label">Alcance</span>
            <select v-model="formPromocion.alcance" class="field">
              <option v-for="a in ALCANCES_PROMOCION" :key="a.v" :value="a.v">{{ a.t }}</option>
            </select>
          </label>
        </div>

        <div v-if="formPromocion.alcance === 'producto'">
          <p class="field-label">Productos con la promoción</p>
          <div class="max-h-40 space-y-1 overflow-y-auto rounded-xl border border-gray-200 bg-gray-50/60 p-2">
            <label v-for="producto in productos" :key="producto.id" class="flex items-center gap-2 rounded px-1.5 py-1 hover:bg-gray-50">
              <input
                type="checkbox"
                :checked="productosPromocion.includes(producto.id)"
                class="size-[18px] accent-brand-600"
                @change="toggleProductoPromocion(producto.id)"
              />
              <span class="text-sm text-gray-700">{{ producto.nombre }}</span>
            </label>
          </div>
          <span v-if="erroresPromocion.productos" class="mt-1 block text-xs text-red-600">{{ erroresPromocion.productos }}</span>
        </div>

        <div v-if="formPromocion.alcance === 'categoria'">
          <p class="field-label">Categorías con la promoción</p>
          <div class="max-h-40 space-y-1 overflow-y-auto rounded-xl border border-gray-200 bg-gray-50/60 p-2">
            <label v-for="categoria in categorias" :key="categoria.id" class="flex items-center gap-2 rounded px-1.5 py-1 hover:bg-gray-50">
              <input
                type="checkbox"
                :checked="categoriasPromocion.includes(categoria.id)"
                class="size-[18px] accent-brand-600"
                @change="toggleCategoriaPromocion(categoria.id)"
              />
              <span class="text-sm text-gray-700">{{ categoria.nombre }}</span>
            </label>
          </div>
          <span v-if="erroresPromocion.categorias" class="mt-1 block text-xs text-red-600">{{ erroresPromocion.categorias }}</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Tipo de descuento</span>
            <select v-model="formPromocion.tipo_valor" class="field">
              <option value="porcentaje">Porcentaje</option>
              <option value="fijo">Monto fijo</option>
            </select>
          </label>
          <label class="block">
            <span class="field-label">
              {{ formPromocion.tipo_valor === 'porcentaje' ? 'Porcentaje (%)' : 'Monto ($)' }}
            </span>
            <input
              v-if="formPromocion.tipo_valor === 'porcentaje'"
              v-model.number="formPromocion.valor"
              v-formato="'porcentaje'"
              type="text"
              class="field"
            />
            <CampoPesos v-else v-model="formPromocion.valor" class="field" />
            <span v-if="erroresPromocion.valor" class="mt-1 block text-xs text-red-600">{{ erroresPromocion.valor }}</span>
          </label>
        </div>

        <div>
          <p class="field-label">Vigencia (opcional — así se agenda Navidad, Amor y Amistad, etc.)</p>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1.5 block text-xs text-gray-500">Desde</span>
              <input v-model="formPromocion.vigencia_inicio" type="date" class="field" />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-xs text-gray-500">Hasta</span>
              <input v-model="formPromocion.vigencia_fin" type="date" class="field" />
              <span v-if="erroresPromocion.vigencia_fin" class="mt-1 block text-xs text-red-600">{{ erroresPromocion.vigencia_fin }}</span>
            </label>
          </div>
        </div>

        <div>
          <p class="field-label">Franja horaria (opcional)</p>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1.5 block text-xs text-gray-500">Desde</span>
              <input v-model="formPromocion.franja_inicio" type="time" class="field" />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-xs text-gray-500">Hasta</span>
              <input v-model="formPromocion.franja_fin" type="time" class="field" />
              <span v-if="erroresPromocion.franja_fin" class="mt-1 block text-xs text-red-600">{{ erroresPromocion.franja_fin }}</span>
            </label>
          </div>
        </div>

        <div>
          <p class="field-label">Días de la semana (opcional — vacío = todos)</p>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="dia in DIAS_SEMANA"
              :key="dia.v"
              type="button"
              class="rounded-lg border px-3 py-2 text-xs font-semibold transition"
              :class="formPromocion.dias_semana.includes(dia.v) ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-brand-300'"
              @click="toggleDiaPromocion(dia.v)"
            >
              {{ dia.t }}
            </button>
          </div>
        </div>

        <label v-if="promocionEditando" class="flex items-center gap-2">
          <input v-model="formPromocion.activo" type="checkbox" class="size-[18px] accent-brand-600" />
          <span class="text-sm text-gray-700">Promoción activa</span>
        </label>

        <p v-if="errorPromocion" class="alert-error">{{ errorPromocion }}</p>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="modalPromocionAbierto = false">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="guardandoPromocion">
            {{ guardandoPromocion ? 'Guardando…' : 'Guardar' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
