<script setup lang="ts">
// Landing pública — alineada a la referencia visual que compartió el negocio
// (header + hero, accesos rápidos por categoría, productos destacados,
// franja de beneficios, sección de reservas y contacto). Categorías y
// productos destacados salen de GET /categorias y GET /productos
// (CatalogoController, M04) — nada de nombres o precios inventados; cuando
// un producto no tiene Producto.imagen_url todavía, se usa un ícono por tipo
// de plato (ver utils/iconoCategoria.ts), igual que en el Menú.
// Mejora del Inicio (21/10/2026): franja «Ofertas y combos de hoy» (OfertasDeHoy, GET /combos y las
// promociones vigentes de los productos), destacados elegidos entre los disponibles (primero los que
// tienen foto) y precios bien escritos (antes salía «Desde 5000.00»).
// Rediseño del Inicio (29/10/2026): portada oscura con fotos reales de la cocina (antes, en el celular, la primera
// pantalla era solo texto), categorías que se deslizan en el celular, «Pedir es así de fácil» en vez de la franja
// de beneficios, reservas con dos fotos y lo que incluye, y un cierre oscuro con foto para pedir o escribir por WhatsApp.
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowRight, Bike, CalendarDays, Check, Crown, Flame, MapPin, MessageCircle, Plus, QrCode, Radar, ShoppingBag, SlidersHorizontal, Store } from 'lucide-vue-next'
import mascota from '@/assets/img/mascota-fragua-real.png'
import fotoTerraza from '@/assets/img/platos/duo-hamburguesas-terraza.jpg'
// Fotos flotantes de fondo (decorativas, RN: no se inventan platos — son las
// mismas fotos reales que ya usa el proyecto en Menú/Home).
import fotoBacon from '@/assets/img/platos/hamburguesa-bacon-cheddar.jpg'
import fotoSalchipapa from '@/assets/img/platos/salchipapa.jpg'
import fotoSandwich from '@/assets/img/platos/sandwich-platano.jpg'
import CarritoFlotante from '@/components/common/CarritoFlotante.vue'
import MapaUbicacion from '@/components/common/MapaUbicacion.vue'
import OfertasDeHoy from '@/components/inicio/OfertasDeHoy.vue'
import { catalogoService } from '@/services/catalogo.service'
import { useCarritoStore } from '@/stores/carrito'
import { useNegocioStore } from '@/stores/negocio'
import { iconoPorNombre } from '@/utils/iconoCategoria'
import { precioConPromocion } from '@/utils/promociones'
import type { Categoria, Combo, Producto } from '@/types/models'

const router = useRouter()
const carrito = useCarritoStore()
const categorias = ref<Categoria[]>([])
const productos = ref<Producto[]>([])
const combos = ref<Combo[]>([])
// Lo que se agregó desde «Ofertas de hoy» (el aviso y el botón del pedido, en el teléfono).
const avisoAgregado = ref<{ id: number; texto: string } | null>(null)

onMounted(async () => {
  try {
    const [resCategorias, resProductos, resCombos] = await Promise.all([
      catalogoService.categorias(),
      catalogoService.productos(),
      // Si los combos no cargan, el resto del Inicio sigue igual.
      catalogoService.combos().catch(() => ({ data: [] as Combo[] })),
    ])
    categorias.value = resCategorias.data
    productos.value = resProductos.data
    combos.value = resCombos.data
  } catch {
    // El Home sigue siendo útil sin estas secciones (hero, reservas y
    // contacto no dependen del catálogo) — solo se ocultan en silencio.
  }
})

const categoriasDestacadas = computed(() => categorias.value.slice(0, 6))
// Destacados: disponibles, sin los que ya salen en «Ofertas de hoy», primero los que tienen foto y
// después los platos antes que las bebidas.
const productosDestacados = computed(() =>
  productos.value
    .filter((p) => p.disponible !== false && !(p.promociones_vigentes ?? []).length)
    .map((p, i) => ({ p, i, puntos: (p.imagen_url ? 2 : 0) + (p.tipo_venta === 'bebida' ? 0 : 1) }))
    .sort((a, b) => b.puntos - a.puntos || a.i - b.i)
    .slice(0, 4) // 28/10/2026: cuatro, para llenar dos filas de dos en el celular
    .map(({ p }) => p),
)

function formatoPrecio(valor: number | string) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function precioDestacado(producto: Producto): string {
  const variantes = (producto.variantes ?? []).filter((v) => v.disponible !== false)
  const precio = variantes.length
    ? Math.min(...variantes.map((v) => precioConPromocion(producto, Number(v.precio), v.id)))
    : precioConPromocion(producto, Number(producto.precio_base))
  return `${variantes.length ? 'Desde ' : ''}${formatoPrecio(precio)}`
}

function alAgregar(texto: string) {
  avisoAgregado.value = { id: Date.now(), texto }
}

// Datos de contacto reales del negocio (no se inventan): dirección y WhatsApp.
// Mejora (19/10/2026): dirección, teléfono y WhatsApp salen de Configuración (los cambia el Administrador).
const negocio = useNegocioStore()
negocio.cargar()
const direccion = computed(() => negocio.datos.direccion)
const telefono = computed(() => negocio.datos.telefono)
const whatsappUrl = computed(() => negocio.whatsappUrl)
const mapaUrl = computed(() => negocio.mapaUrl)
// M09 (20/10/2026): mapa pequeño con el punto que marcó el SuperAdmin (solo si hay punto).
const latitud = computed(() => negocio.datos.latitud ?? null)
const longitud = computed(() => negocio.datos.longitud ?? null)

// Rediseño del Inicio (29/10/2026): lo que se ve en la portada y en «Pedir es así de fácil». Solo lo que el sistema
// ya hace (domicilio, recogida, el QR de la mesa, personalizar y el seguimiento en vivo).
const canales = [
  { icono: Bike, texto: 'Domicilio' },
  { icono: Store, texto: 'Recoge en el local' },
  { icono: QrCode, texto: 'Pide desde tu mesa' },
]
const pasos = [
  { icono: SlidersHorizontal, titulo: 'Elige y personaliza', texto: 'Arma tu pedido en el menú y quita o agrega ingredientes a tu gusto.' },
  { icono: Bike, titulo: 'Escoge cómo recibirlo', texto: 'A domicilio, para recoger en el local o desde tu mesa con el código QR.' },
  { icono: Radar, titulo: 'Síguelo en vivo', texto: 'Mira cuándo lo confirmamos, lo preparamos y sale hacia ti.' },
]
const puntosReserva = [
  'Eliges el día, la hora y la mesa desde aquí.',
  'Te confirmamos por correo y te recordamos antes de llegar.',
  'Tu depósito se descuenta de la cuenta el día de tu visita.',
]
</script>

<template>
  <div class="bg-gradient-to-b from-[#fdf9f0] via-cream to-[#f7f1e2]">
    <!-- Rediseño del Inicio (29/10/2026): portada oscura con fotos reales de la cocina. Antes, en el celular la
         primera pantalla era solo texto (la comida aparecía al bajar) y en el computador una foto flotante tapaba
         la frase de arriba. -->
    <section class="relative isolate overflow-hidden bg-ink text-white">
      <div class="pointer-events-none absolute -right-32 -top-40 -z-10 size-[520px] rounded-full bg-brand-500/25 blur-3xl" aria-hidden="true" />
      <div class="pointer-events-none absolute -bottom-48 -left-32 -z-10 size-[460px] rounded-full bg-[#e8956b]/15 blur-3xl" aria-hidden="true" />
      <div
        class="pointer-events-none absolute inset-0 -z-10 opacity-[0.07] [background-image:radial-gradient(#d6a43a_1px,transparent_1px)] [background-size:22px_22px]"
        aria-hidden="true"
      />

      <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 pb-14 pt-10 sm:px-6 sm:pt-14 lg:grid-cols-[1.05fr_1fr] lg:gap-14 lg:pb-24 lg:pt-20">
        <div>
          <p class="inline-flex items-center gap-2 rounded-full border border-brand-500/40 bg-brand-500/10 px-3 py-1 text-[11px] font-medium tracking-[2px] text-brand-300">
            <Flame :size="13" aria-hidden="true" />
            HECHO AL MOMENTO
          </p>
          <h1 class="mt-5 font-display text-[2.7rem] font-medium leading-[1.02] tracking-tight sm:text-6xl lg:text-[4.6rem]">
            <!-- 29/10/2026: el eslogan oficial del negocio (antes «El antojo tiene corona.», provisional). -->
            Donde la vida <span class="italic text-brand-400">sabe mejor.</span>
          </h1>
          <p class="mt-5 max-w-md text-[16px] leading-relaxed text-white/70 sm:text-[17px]">
            Hamburguesas, perros, salchipapas y mucho más, preparados al momento y como tú los prefieras.
          </p>
          <div class="mt-7 grid grid-cols-2 gap-3 sm:flex">
            <router-link
              to="/menu"
              class="inline-flex min-h-12 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-500 px-3 py-3 text-[13px] font-semibold text-ink shadow-[0_12px_30px_rgba(214,164,58,0.35)] transition hover:-translate-y-0.5 hover:bg-brand-400 sm:px-7 sm:text-sm"
            >
              <ShoppingBag :size="17" />
              Pedir ahora
            </router-link>
            <router-link
              to="/reservar"
              class="inline-flex min-h-12 items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-white/25 px-3 py-3 text-[13px] font-medium text-white transition hover:border-white/60 hover:bg-white/5 sm:px-7 sm:text-sm"
            >
              <CalendarDays :size="17" />
              Reservar mesa
            </router-link>
          </div>
          <ul class="mt-7 flex flex-wrap gap-x-5 gap-y-2 text-xs text-white/60">
            <li v-for="canal in canales" :key="canal.texto" class="flex items-center gap-1.5">
              <component :is="canal.icono" :size="14" class="text-brand-400" aria-hidden="true" />
              {{ canal.texto }}
            </li>
          </ul>
        </div>

        <!-- Fotos reales: la principal, una segunda más pequeña y la mascota.
             29/10/2026: en el celular la foto va alineada con el texto (antes tenía sangría), es cuadrada para que la
             portada no quede tan larga, la salchipapa baja a la esquina izquierda y el letrero sube a la derecha,
             donde la mascota ya no lo tapa. -->
        <div class="relative mx-auto w-full max-w-[420px] pb-6 sm:max-w-[460px] sm:pl-6 lg:max-w-none lg:pl-10">
          <div class="relative overflow-hidden rounded-[2rem] shadow-[0_35px_70px_-20px_rgba(0,0,0,0.75)] ring-1 ring-white/10">
            <img
              :src="fotoBacon"
              alt="Hamburguesa doble con queso cheddar derretido y tocineta de Fragua Real"
              class="aspect-square w-full object-cover object-[50%_60%] sm:aspect-[4/4.2]"
              fetchpriority="high"
            />
            <p class="absolute right-3 top-3 inline-flex items-center gap-1.5 rounded-full bg-ink/75 py-1.5 pl-1.5 pr-3 text-xs font-medium text-white backdrop-blur-sm">
              <span class="flex size-6 items-center justify-center rounded-full bg-brand-500 text-ink" aria-hidden="true"><Crown :size="12" /></span>
              Recién salida de la cocina
            </p>
          </div>
          <div class="foto-flotante-1 absolute -left-2 bottom-12 w-[32%] overflow-hidden rounded-2xl border-4 border-ink shadow-[0_20px_40px_-12px_rgba(0,0,0,0.7)] sm:bottom-auto sm:left-0 sm:top-10 sm:w-[36%]" aria-hidden="true">
            <img :src="fotoSalchipapa" alt="" class="aspect-square w-full object-cover" />
          </div>
          <img
            :src="mascota"
            alt="Mascota de Fragua Real Burger con el pulgar arriba"
            class="mascota-flotante pointer-events-none absolute -bottom-2 -right-2 w-[36%] drop-shadow-[0_18px_28px_rgba(0,0,0,0.55)] sm:-right-6 sm:w-[40%] lg:-right-10"
          />
        </div>
      </div>
    </section>

    <!-- Mejora del Inicio (21/10/2026): ofertas y combos que se pueden pedir hoy -->
    <div class="pt-12 sm:pt-14">
      <OfertasDeHoy :productos="productos" :combos="combos" @agregado="alAgregar" />
    </div>

    <!-- Accesos rápidos por categoría. 29/10/2026: en el celular, una fila que se desliza (antes dos filas de
         tres que ocupaban media pantalla). -->
    <section v-if="categoriasDestacadas.length" class="mx-auto max-w-6xl pb-4 sm:px-6">
      <div class="px-4 sm:px-0">
        <div class="mb-3 flex items-center gap-3">
          <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
          <span class="text-[11px] font-medium tracking-[2px] text-brand-700">NUESTRA CARTA</span>
        </div>
        <h2 class="font-display text-3xl font-medium tracking-tight text-ink">¿Qué se te antoja hoy?</h2>
      </div>
      <div class="mt-6 flex snap-x snap-mandatory scroll-px-4 gap-3 overflow-x-auto px-4 pb-2 [scrollbar-width:none] sm:grid sm:grid-cols-6 sm:overflow-visible sm:px-0 [&::-webkit-scrollbar]:hidden">
        <router-link
          v-for="categoria in categoriasDestacadas"
          :key="categoria.id"
          :to="{ name: 'menu', query: { categoria: categoria.nombre } }"
          class="group flex w-[6.5rem] shrink-0 snap-start flex-col items-center gap-3 rounded-2xl border border-[#e3ded4] bg-white px-3 py-5 text-center transition hover:-translate-y-0.5 hover:border-brand-500 hover:shadow-[0_14px_28px_rgba(47,38,20,0.10)] sm:w-auto"
        >
          <span class="flex size-12 items-center justify-center rounded-full bg-[#fbf0d9] text-brand-700 transition group-hover:bg-brand-500 group-hover:text-ink">
            <component :is="iconoPorNombre(categoria.nombre)" :size="22" />
          </span>
          <span class="text-xs font-semibold text-ink">{{ categoria.nombre }}</span>
        </router-link>
      </div>
    </section>

    <!-- Productos destacados -->
    <section v-if="productosDestacados.length" class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
      <div class="mb-6 flex items-end justify-between gap-4">
        <h2 class="font-display text-2xl font-medium tracking-tight text-ink sm:text-3xl">Empieza por un buen antojo.</h2>
        <router-link to="/menu" class="shrink-0 pb-1 text-xs font-medium text-brand-700 hover:text-brand-800 sm:text-sm">
          Ver todo →
        </router-link>
      </div>
      <!-- Mejora (28/10/2026): en el celular, dos tarjetas por fila del mismo tamaño que las del menú (antes una
           sola, muy grande). Toda la tarjeta lleva al producto en el menú. -->
      <div class="grid grid-cols-2 gap-3 sm:gap-5 md:grid-cols-4">
        <router-link
          v-for="producto in productosDestacados"
          :key="producto.id"
          :to="{ name: 'menu', query: { categoria: producto.categoria?.nombre } }"
          class="group flex flex-col rounded-2xl border border-[#e3ded4] bg-white p-2.5 transition hover:border-brand-300 sm:p-4"
        >
          <div class="mb-2.5 flex aspect-[4/3] items-center justify-center overflow-hidden rounded-xl bg-[#fbf0d9] sm:mb-3">
            <img
              v-if="producto.imagen_url"
              :src="producto.imagen_url"
              :alt="producto.nombre"
              class="size-full object-cover transition duration-300 group-hover:scale-[1.03]"
              loading="lazy"
            />
            <component v-else :is="iconoPorNombre(producto.nombre)" :size="30" :stroke-width="1.5" class="text-brand-500" />
          </div>
          <p class="font-display text-[15px] leading-snug text-ink sm:text-lg">{{ producto.nombre }}</p>
          <p v-if="producto.descripcion" class="mt-1 line-clamp-2 text-[11px] text-[#6c655c] sm:text-xs">{{ producto.descripcion }}</p>
          <div class="mt-auto flex items-baseline justify-between gap-x-2 pt-2.5 sm:pt-3">
            <p class="text-sm font-semibold text-brand-700 sm:text-base">{{ precioDestacado(producto) }}</p>
            <span class="hidden border-b border-brand-700 pb-0.5 text-xs font-medium text-brand-700 sm:inline">Ver en el menú</span>
            <span class="flex size-7 shrink-0 items-center justify-center self-center rounded-full bg-brand-500 text-ink sm:hidden" aria-hidden="true">
              <Plus :size="15" />
            </span>
          </div>
        </router-link>
      </div>
    </section>

    <!-- Rediseño del Inicio (29/10/2026): «Pedir es así de fácil» en una franja oscura. Reemplaza la franja de
         beneficios, que casi no se veía (texto claro sobre crema). Todo lo que dice ya lo hace el sistema. -->
    <section class="relative isolate overflow-hidden bg-ink py-14 text-white sm:py-20">
      <div class="pointer-events-none absolute -left-24 top-0 -z-10 size-[380px] rounded-full bg-brand-500/15 blur-3xl" aria-hidden="true" />
      <div class="mx-auto max-w-6xl px-4 sm:px-6">
        <div class="flex items-center gap-3">
          <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
          <span class="text-[11px] font-medium tracking-[2px] text-brand-300">CÓMO PEDIR</span>
        </div>
        <h2 class="mt-3 font-display text-3xl font-medium tracking-tight sm:text-4xl">Pedir es así de fácil.</h2>
        <ol class="mt-8 grid gap-3 md:mt-10 md:grid-cols-3 md:gap-6">
          <li
            v-for="(paso, i) in pasos"
            :key="paso.titulo"
            class="relative flex gap-4 rounded-2xl border border-white/10 bg-white/[0.04] p-5 transition hover:border-brand-500/50 hover:bg-white/[0.06] md:block md:p-6"
          >
            <span class="absolute right-5 top-4 font-display text-4xl leading-none text-white/10 md:text-5xl" aria-hidden="true">{{ i + 1 }}</span>
            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-ink" aria-hidden="true">
              <component :is="paso.icono" :size="20" />
            </span>
            <div class="pr-6 md:pr-0">
              <p class="font-display text-lg md:mt-5 md:text-xl">{{ paso.titulo }}</p>
              <p class="mt-1 text-sm leading-relaxed text-white/65 md:mt-2">{{ paso.texto }}</p>
            </div>
          </li>
        </ol>
        <router-link
          to="/menu"
          class="mt-10 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-brand-500 px-7 py-3 text-sm font-semibold text-ink transition hover:bg-brand-400 sm:w-auto"
        >
          Empezar mi pedido
          <ArrowRight :size="16" />
        </router-link>
      </div>
    </section>

    <!-- Reservas. 29/10/2026: dos fotos reales en vez de una, y lo que incluye reservar. -->
    <section class="relative overflow-hidden px-4 py-16 sm:px-6 sm:py-20">
      <div class="pointer-events-none absolute -right-24 top-10 size-[340px] rounded-full bg-brand-200/35 blur-3xl" aria-hidden="true" />
      <div class="relative mx-auto grid max-w-6xl items-center gap-10 lg:grid-cols-2 lg:gap-14">
        <div class="relative pb-10 pr-10 sm:pb-14 sm:pr-14">
          <div class="overflow-hidden rounded-3xl shadow-[0_25px_55px_-20px_rgba(47,38,20,0.45)]">
            <img
              :src="fotoTerraza"
              alt="Hamburguesas de Fragua Real Burger servidas en la terraza"
              class="aspect-[4/3] w-full object-cover"
              loading="lazy"
            />
          </div>
          <div class="foto-flotante-4 absolute bottom-0 right-0 w-[42%] overflow-hidden rounded-2xl border-4 border-cream shadow-[0_20px_40px_-12px_rgba(23,21,16,0.45)]">
            <img :src="fotoSandwich" alt="Sándwich de plátano de Fragua Real" class="aspect-square w-full object-cover" loading="lazy" />
          </div>
        </div>
        <div>
          <div class="mb-4 flex items-center gap-3">
            <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
            <span class="text-[11px] font-medium tracking-[2px] text-brand-700">MOMENTOS QUE SE COMPARTEN</span>
          </div>
          <h2 class="font-display text-3xl font-medium leading-tight tracking-tight text-ink sm:text-4xl">
            Tu próxima salida tiene lugar.
          </h2>
          <p class="mt-3 max-w-md text-[15px] leading-relaxed text-[#696159]">
            Ven con tu familia o amigos y disfruta el sabor de Fragua Real. Separa tu mesa en un minuto.
          </p>
          <ul class="mt-6 space-y-3">
            <li v-for="punto in puntosReserva" :key="punto" class="flex items-start gap-3 text-sm text-ink">
              <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-500 text-ink" aria-hidden="true">
                <Check :size="12" :stroke-width="3" />
              </span>
              {{ punto }}
            </li>
          </ul>
          <router-link
            to="/reservar"
            class="mt-8 inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-ink px-7 py-3 text-sm font-medium text-white transition hover:bg-black"
          >
            <CalendarDays :size="17" />
            Reservar mesa
          </router-link>
        </div>
      </div>
    </section>

    <!-- Contacto -->
    <section id="contacto" class="mx-auto max-w-6xl scroll-mt-24 px-4 pb-16 sm:px-6">
      <div class="rounded-3xl border border-[#e3ded4] bg-white p-8 sm:p-10">
        <h2 class="text-center font-display text-2xl font-medium tracking-tight text-ink sm:text-left">
          Nos vemos en Fragua Real.
        </h2>
        <div class="mt-8 grid gap-8 divide-y divide-[#ece5d7] sm:grid-cols-2 sm:divide-x sm:divide-y-0">
          <div class="flex items-start gap-3 sm:pr-8">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-[#fbf0d9] text-brand-700" aria-hidden="true">
              <MapPin :size="18" />
            </span>
            <div>
              <p class="text-[10px] font-medium tracking-[1.6px] text-brand-700">ENCUÉNTRANOS</p>
              <p class="mt-1 text-sm text-ink">{{ direccion }}</p>
              <a
                :href="mapaUrl"
                target="_blank"
                rel="noopener"
                class="mt-3 inline-flex items-center justify-center rounded-xl border border-[#ddd7cd] px-4 py-2 text-sm font-medium text-ink transition hover:border-brand-500"
              >
                Cómo llegar
              </a>
            </div>
          </div>
          <div class="flex items-start gap-3 pt-8 sm:pl-8 sm:pt-0">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-[#fbf0d9] text-brand-700" aria-hidden="true">
              <MessageCircle :size="18" />
            </span>
            <div>
              <p class="text-[10px] font-medium tracking-[1.6px] text-brand-700">HABLEMOS</p>
              <p class="mt-1 text-sm text-ink">{{ telefono }}</p>
              <a
                :href="whatsappUrl"
                target="_blank"
                rel="noopener"
                class="mt-3 inline-flex items-center justify-center rounded-xl bg-brand-500 px-4 py-2 text-sm font-medium text-ink transition hover:bg-brand-400"
              >
                Escribir por WhatsApp
              </a>
            </div>
          </div>
        </div>
        <MapaUbicacion
          v-if="negocio.tienePunto"
          class="mt-8 h-56 sm:h-64"
          :latitud="latitud"
          :longitud="longitud"
          etiqueta="Mapa con la ubicación de Fragua Real"
        />
      </div>
    </section>

    <!-- Rediseño del Inicio (29/10/2026): cierre oscuro con la foto de la hamburguesa y la mascota, para pedir sin
         volver arriba (opción A que eligió el negocio; antes era una franja dorada con solo texto). -->
    <section class="px-4 pb-16 sm:px-6">
      <div class="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-ink text-white">
        <div class="pointer-events-none absolute -left-20 -top-24 size-80 rounded-full bg-brand-500/25 blur-3xl" aria-hidden="true" />
        <div class="relative grid items-center md:grid-cols-[1.1fr_1fr]">
          <div class="px-6 py-10 sm:px-12 sm:py-14">
            <p class="inline-flex items-center gap-2 rounded-full border border-brand-500/40 bg-brand-500/10 px-3 py-1 text-[11px] font-medium tracking-[2px] text-brand-300">
              <Flame :size="13" aria-hidden="true" />
              TU ANTOJO TE ESPERA
            </p>
            <h2 class="mt-4 font-display text-4xl font-medium leading-tight sm:text-5xl">
              ¿Ya se te <span class="italic text-brand-400">antojó?</span>
            </h2>
            <p class="mt-3 max-w-md text-[15px] leading-relaxed text-white/70">
              Lo preparamos al momento y te lo llevamos a la puerta, o lo recoges caliente en el local.
            </p>
            <div class="mt-7 grid grid-cols-2 gap-3 sm:flex">
              <router-link
                to="/menu"
                class="inline-flex min-h-12 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-500 px-5 text-sm font-semibold text-ink shadow-[0_12px_30px_rgba(214,164,58,0.3)] transition hover:-translate-y-0.5 hover:bg-brand-400"
              >
                <ShoppingBag :size="17" />
                Pedir ahora
              </router-link>
              <a
                v-if="whatsappUrl"
                :href="whatsappUrl"
                target="_blank"
                rel="noopener"
                class="inline-flex min-h-12 items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-white/25 px-5 text-sm font-medium text-white transition hover:border-white/60 hover:bg-white/5"
              >
                <MessageCircle :size="17" />
                WhatsApp
              </a>
            </div>
            <ul class="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-xs text-white/60">
              <li class="flex items-center gap-1.5"><Bike :size="14" class="text-brand-400" aria-hidden="true" />Domicilio</li>
              <li class="flex items-center gap-1.5"><Store :size="14" class="text-brand-400" aria-hidden="true" />Recoge en el local</li>
            </ul>
          </div>
          <div class="relative h-64 sm:h-80 md:h-full md:min-h-[360px]">
            <img
              :src="fotoBacon"
              alt="Hamburguesa doble con cheddar y tocineta de Fragua Real"
              class="absolute inset-0 size-full object-cover object-[50%_60%]"
              loading="lazy"
            />
            <div
              class="absolute inset-0 bg-gradient-to-b from-ink via-transparent to-transparent md:bg-gradient-to-r md:from-ink md:via-ink/20 md:to-transparent"
              aria-hidden="true"
            />
            <img
              :src="mascota"
              alt=""
              class="mascota-flotante pointer-events-none absolute -bottom-3 right-2 w-32 drop-shadow-[0_15px_25px_rgba(0,0,0,0.6)] sm:w-40"
              loading="lazy"
            />
          </div>
        </div>
      </div>
    </section>

    <!-- En el teléfono: el botón del pedido y el aviso de lo que se agregó desde «Ofertas de hoy». -->
    <CarritoFlotante
      v-if="carrito.totalProductos > 0"
      :cantidad="carrito.totalProductos"
      :aviso="avisoAgregado"
      solo-movil
      @abrir="router.push('/carrito')"
    />
  </div>
</template>

<style scoped>
/* Fotos y mascota que flotan suave (portada y reservas). La rotación fija de cada foto va dentro de su
   @keyframes: si fuera una utilidad aparte (-rotate-6), el transform de la animación la pisaría.
   29/10/2026: quien pide menos movimiento en su teléfono las ve quietas. */
@keyframes flotar-1 {
  0%,
  100% {
    transform: translateY(0) rotate(-6deg);
  }
  50% {
    transform: translateY(-12px) rotate(-6deg);
  }
}

@keyframes flotar-4 {
  0%,
  100% {
    transform: translateY(0) rotate(4deg);
  }
  50% {
    transform: translateY(-10px) rotate(4deg);
  }
}

@keyframes flotar-mascota {
  0%,
  100% {
    transform: translateY(0) rotate(0deg);
  }
  50% {
    transform: translateY(-10px) rotate(-2deg);
  }
}

.foto-flotante-1 {
  animation: flotar-1 6s ease-in-out infinite;
}

.foto-flotante-4 {
  animation: flotar-4 7.5s ease-in-out infinite;
  animation-delay: 0.6s;
}

.mascota-flotante {
  animation: flotar-mascota 5s ease-in-out infinite;
  animation-delay: 0.3s;
}

@media (prefers-reduced-motion: reduce) {
  .foto-flotante-1,
  .foto-flotante-4,
  .mascota-flotante {
    animation: none;
  }
}
</style>
