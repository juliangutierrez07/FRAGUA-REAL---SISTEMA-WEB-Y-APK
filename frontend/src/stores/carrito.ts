import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import { catalogoService } from '@/services/catalogo.service'
import type { LineaPedidoPayload } from '@/services/pedido.service'
import type { Combo, Extra, Producto, Variante } from '@/types/models'
import { extrasDe } from '@/utils/extras'

// M02: carrito del Cliente antes de confirmar el pedido (Local vía QR,
// Domicilio o Recogida). Precio se congela al confirmar el pedido en el
// backend (ADR-002), no aquí — este store solo arma la selección.
//
// Cada línea es una selección concreta: producto + variante elegida (si
// tiene, RN-M04) + extras marcados. Dos líneas del mismo producto con
// distinta variante/extras NO se combinan — son pedidos distintos; con la
// misma selección se suman en una sola línea (ver agregar()).
//
// Revisión por rol (13/10/2026, RF-M04-020/031): una línea también puede ser un combo
// (combo con precio propio, sin variante ni extras; sí con nota para cocina). Antes el Menú y
// el QR de mesa no ofrecían combos: solo el Mesero podía venderlos.
export interface LineaCarrito {
  id: string
  producto: Producto | null
  combo?: Combo | null
  variante: Variante | null
  extras: Extra[]
  cantidad: number
  notas?: string
}

type SeleccionPrecio = Pick<LineaCarrito, 'producto' | 'variante' | 'extras'> & { combo?: Combo | null }

function precioUnitario(linea: SeleccionPrecio): number {
  if (linea.combo) return Number(linea.combo.precio)
  if (!linea.producto) return 0
  const base = linea.variante ? Number(linea.variante.precio) : Number(linea.producto.precio_base)
  const extras = linea.extras.reduce((acc, e) => acc + Number(e.precio), 0)
  return base + extras
}

function nombreLinea(linea: Pick<LineaCarrito, 'producto' | 'combo'>): string {
  return linea.combo?.nombre ?? linea.producto?.nombre ?? ''
}

// CORREGIDO (16/10/2026, producción): el carrito vivía solo en la memoria de la página y se perdía al
// recargar. Ahora se guarda en el navegador (localStorage) y vuelve al recargar, por 24 horas.
// `origen` dice de dónde es el carrito: "web" (Menú, domicilio o recogida) o "mesa:<qr>" (pedido
// por QR), para no mezclar un pedido de mesa con uno a domicilio.
const CLAVE_GUARDADO = 'fragua_real_carrito'
const VIGENCIA_MS = 24 * 60 * 60 * 1000

interface CarritoGuardado {
  v: 1
  guardado: number
  origen: string
  lineas: LineaCarrito[]
}

function leerGuardado(): CarritoGuardado | null {
  try {
    const datos = JSON.parse(localStorage.getItem(CLAVE_GUARDADO) ?? 'null') as CarritoGuardado | null
    if (!datos || datos.v !== 1 || !Array.isArray(datos.lineas) || Date.now() - datos.guardado > VIGENCIA_MS) return null
    return datos
  } catch {
    return null
  }
}

// Seguridad (28/10/2026, RN-PED-36): el servidor acepta hasta 20 unidades de cada producto en un pedido de la
// web o del QR (un pedido grande se hace por teléfono). El carrito no deja pasar de ahí.
export const MAX_UNIDADES_POR_LINEA = 20

export const useCarritoStore = defineStore('carrito', () => {
  const guardado = leerGuardado()
  const lineas = ref<LineaCarrito[]>(guardado?.lineas ?? [])
  const origen = ref(guardado?.origen ?? 'web')

  watch(
    [lineas, origen],
    () => {
      try {
        if (!lineas.value.length) {
          localStorage.removeItem(CLAVE_GUARDADO)
          return
        }
        const datos: CarritoGuardado = { v: 1, guardado: Date.now(), origen: origen.value, lineas: lineas.value }
        localStorage.setItem(CLAVE_GUARDADO, JSON.stringify(datos))
      } catch {
        // Sin almacenamiento (modo privado, lleno): el carrito sigue funcionando en memoria.
      }
    },
    { deep: true },
  )

  // El pedido por QR usa su propio carrito: si el guardado era de otra mesa o de la web, empieza vacío;
  // si es de esta misma mesa (se recargó la página), se conserva.
  function usarOrigen(nuevo: string) {
    if (origen.value !== nuevo) {
      lineas.value = []
      origen.value = nuevo
    }
  }

  // Al volver con un carrito guardado, los precios, las fotos o la disponibilidad pueden haber
  // cambiado: se actualiza con el catálogo de hoy y se quita lo que ya no se puede pedir.
  const avisosActualizacion = ref<string[]>([])
  let actualizado = false

  async function actualizarConCatalogo() {
    if (actualizado || !lineas.value.length) return
    actualizado = true
    try {
      const [{ data: productos }, { data: combos }] = await Promise.all([catalogoService.productos(), catalogoService.combos()])
      const avisos: string[] = []
      const nuevas: LineaCarrito[] = []
      for (const linea of lineas.value) {
        const nombre = nombreLinea(linea)
        if (linea.combo) {
          const combo = combos.find((c) => c.id === linea.combo?.id)
          if (!combo) {
            avisos.push(`Quitamos "${nombre}": ya no está disponible.`)
            continue
          }
          nuevas.push({ ...linea, combo })
          continue
        }
        const producto = productos.find((p) => p.id === linea.producto?.id && p.activo !== false && p.disponible !== false)
        const variante = linea.variante
          ? producto?.variantes?.find((v) => v.id === linea.variante?.id && v.activa && v.disponible !== false) ?? null
          : null
        if (!producto || (linea.variante && !variante)) {
          avisos.push(`Quitamos "${nombre}${linea.variante ? ` (${linea.variante.nombre})` : ''}": ya no está disponible.`)
          continue
        }
        const disponibles = extrasDe(producto)
        const extras = linea.extras
          .map((e) => disponibles.find((d) => d.id === e.id))
          .filter((e): e is Extra => !!e)
        if (extras.length < linea.extras.length) {
          const faltan = linea.extras.filter((e) => !extras.some((x) => x.id === e.id)).map((e) => e.nombre)
          avisos.push(`"${nombre}" sigue en tu carrito, pero sin ${faltan.join(', ')}: ya no se ofrece.`)
        }
        nuevas.push({ ...linea, producto, variante, extras })
      }
      lineas.value = nuevas
      avisosActualizacion.value = avisos
    } catch {
      // Sin conexión: queda lo guardado; el servidor revisa todo al confirmar el pedido.
      actualizado = false
    }
  }

  const totalProductos = computed(() => lineas.value.reduce((acc, l) => acc + l.cantidad, 0))

  const total = computed(() =>
    lineas.value.reduce((acc, l) => acc + precioUnitario(l) * l.cantidad, 0),
  )

  // CORREGIDO (16/10/2026, producción): agregar dos veces lo mismo (por ejemplo, "Jugo en Agua"
  // sin extras) creaba dos líneas iguales en el carrito. Ahora, si ya hay una línea con la misma
  // selección (producto o combo, presentación, extras y nota para cocina), se le suma la cantidad.
  // Con otra presentación, otros extras u otra nota siguen siendo líneas distintas.
  function claveSeleccion(linea: Omit<LineaCarrito, 'id'>): string {
    return JSON.stringify([
      linea.combo?.id ?? null,
      linea.producto?.id ?? null,
      linea.variante?.id ?? null,
      linea.extras.map((e) => e.id).sort((a, b) => a - b),
      (linea.notas ?? '').trim().toLowerCase(),
    ])
  }

  function agregar(linea: Omit<LineaCarrito, 'id'>) {
    const clave = claveSeleccion(linea)
    const existente = lineas.value.find((l) => claveSeleccion(l) === clave)
    if (existente) {
      existente.cantidad = Math.min(MAX_UNIDADES_POR_LINEA, existente.cantidad + linea.cantidad)
      return
    }
    lineas.value.push({ ...linea, cantidad: Math.min(MAX_UNIDADES_POR_LINEA, linea.cantidad), id: crypto.randomUUID() })
  }

  // Mejora (28/10/2026): el último producto agregado, para el aviso del botón flotante (teléfono) y del
  // encabezado (computador). Cada aviso trae un id nuevo para volver a mostrarse.
  const ultimoAgregado = ref<{ id: number; texto: string } | null>(null)
  function avisarAgregado(texto: string) {
    ultimoAgregado.value = { id: Date.now(), texto }
  }

  function actualizarCantidad(id: string, cantidad: number) {
    const linea = lineas.value.find((l) => l.id === id)
    if (linea) {
      linea.cantidad = Math.min(MAX_UNIDADES_POR_LINEA, Math.max(1, cantidad))
    }
  }

  function quitar(id: string) {
    lineas.value = lineas.value.filter((l) => l.id !== id)
  }

  function vaciar() {
    lineas.value = []
    avisosActualizacion.value = []
  }

  // CORREGIDO (30/09/2026): CheckoutView.vue (Domicilio/Recogida) y MesaQrView.vue (Local
  // vía QR) necesitan el carrito en la forma que espera StorePedidoRequest (producto_id/
  // variante_id/extras_ids/observaciones) — este método no existía, así que confirmar
  // CUALQUIER pedido fallaba con un TypeError antes de llegar siquiera al backend.
  function aLineasPedido(): LineaPedidoPayload[] {
    return lineas.value.map((l) =>
      l.combo
        ? { combo_id: l.combo.id, cantidad: l.cantidad, observaciones: l.notas || undefined }
        : {
            producto_id: l.producto?.id,
            variante_id: l.variante?.id ?? undefined,
            cantidad: l.cantidad,
            extras_ids: l.extras.length ? l.extras.map((e) => e.id) : undefined,
            observaciones: l.notas || undefined,
          },
    )
  }

  return {
    lineas,
    origen,
    usarOrigen,
    avisosActualizacion,
    actualizarConCatalogo,
    totalProductos,
    total,
    agregar,
    ultimoAgregado,
    avisarAgregado,
    actualizarCantidad,
    quitar,
    vaciar,
    precioUnitario,
    nombreLinea,
    aLineasPedido,
  }
})
