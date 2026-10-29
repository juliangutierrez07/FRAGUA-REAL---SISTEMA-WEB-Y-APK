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
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Bike, ChefHat, MapPin, MessageCircle, Plus, SlidersHorizontal } from 'lucide-vue-next'
import mascota from '@/assets/img/mascota-fragua-real.png'
import fotoLocal from '@/assets/img/hamburguesas-fragua-real.jpg'
import fotoTerraza from '@/assets/img/platos/duo-hamburguesas-terraza.jpg'
// Fotos flotantes de fondo (decorativas, RN: no se inventan platos — son las
// mismas fotos reales que ya usa el proyecto en Menú/Home).
import fotoBacon from '@/assets/img/platos/hamburguesa-bacon-cheddar.jpg'
import fotoSalchipapa from '@/assets/img/platos/salchipapa.jpg'
import fotoSandwich from '@/assets/img/platos/sandwich-platano.jpg'
import fotoDuo1 from '@/assets/img/platos/duo-hamburguesas-1.jpg'
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

const beneficios = [
  { icono: ChefHat, titulo: 'Hecho al momento', texto: 'Preparamos tu pedido al confirmarlo.' },
  { icono: SlidersHorizontal, titulo: 'Como tú lo prefieras', texto: 'Personaliza los ingredientes de tu pedido.' },
  { icono: Bike, titulo: 'Domicilio o recogida', texto: 'Elige cómo quieres recibirlo.' },
]
</script>

<template>
  <div class="bg-gradient-to-b from-[#fdf9f0] via-cream to-[#f7f1e2]">
    <!-- Hero -->
    <section class="relative overflow-hidden">
      <div
        class="pointer-events-none absolute -right-24 -top-24 size-[420px] rounded-full bg-brand-200/40 blur-3xl"
        aria-hidden="true"
      />
      <div
        class="pointer-events-none absolute -bottom-32 -left-20 size-[360px] rounded-full bg-brand-300/25 blur-3xl"
        aria-hidden="true"
      />

      <!-- Fotos flotantes: decoran las esquinas del hero, fuera del contenido central,
           con una animación suave de flotación + resplandor de color (ver <style scoped>
           al final del archivo). La rotación fija va "horneada" dentro de cada @keyframes
           propio (flotar-1/2/3/4) para que no se pierda durante la animación. -->
      <div class="foto-flotante-1 pointer-events-none absolute left-[1%] top-[4%] hidden w-28 lg:block" aria-hidden="true">
        <div class="absolute -inset-5 -z-10 rounded-full bg-brand-400/35 blur-2xl" aria-hidden="true" />
        <div class="overflow-hidden rounded-[1.25rem] ring-1 ring-white/70 shadow-[0_25px_45px_-12px_rgba(23,21,16,0.45)]">
          <img :src="fotoBacon" alt="" class="aspect-square w-full object-cover" />
        </div>
      </div>
      <div class="foto-flotante-2 pointer-events-none absolute right-[2%] top-[8%] hidden w-24 lg:block" aria-hidden="true">
        <div class="absolute -inset-5 -z-10 rounded-full bg-[#e8956b]/35 blur-2xl" aria-hidden="true" />
        <div class="overflow-hidden rounded-[1.25rem] ring-1 ring-white/70 shadow-[0_25px_45px_-12px_rgba(23,21,16,0.45)]">
          <img :src="fotoSalchipapa" alt="" class="aspect-square w-full object-cover" />
        </div>
      </div>
      <div class="foto-flotante-3 pointer-events-none absolute bottom-[8%] left-[3%] hidden w-24 xl:block" aria-hidden="true">
        <div class="absolute -inset-5 -z-10 rounded-full bg-brand-300/35 blur-2xl" aria-hidden="true" />
        <div class="overflow-hidden rounded-[1.25rem] ring-1 ring-white/70 shadow-[0_25px_45px_-12px_rgba(23,21,16,0.45)]">
          <img :src="fotoSandwich" alt="" class="aspect-square w-full object-cover" />
        </div>
      </div>

      <div class="relative mx-auto grid max-w-6xl gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:items-center lg:py-24">
        <div>
          <div class="mb-6 flex items-center gap-3">
            <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
            <span class="text-[11px] font-medium tracking-[2px] text-brand-700">
              SABOR HECHO AL MOMENTO
            </span>
          </div>
          <h1 class="font-display text-5xl font-medium leading-[1.05] tracking-tight text-ink sm:text-6xl lg:text-[4.5rem]">
            El antojo tiene corona.
          </h1>
          <p class="mt-6 max-w-md text-[17px] leading-relaxed text-[#696159]">
            Hamburguesas, perros y mucho más, preparados al momento.
          </p>
          <div class="mt-8 flex flex-wrap gap-3">
            <router-link
              to="/menu"
              class="rounded-xl bg-brand-500 px-6 py-3 text-sm font-medium text-ink transition hover:bg-brand-400"
            >
              Ver el menú
            </router-link>
            <router-link
              to="/reservar"
              class="rounded-xl border border-ink px-6 py-3 text-sm font-medium text-ink transition hover:bg-ink hover:text-white"
            >
              Reservar mesa
            </router-link>
          </div>
          <p class="mt-8 flex items-center gap-2 text-xs text-[#6a645c]">
            <Bike :size="15" class="text-brand-700" />
            Pide a domicilio o recoge en el local.
          </p>
        </div>

        <div class="relative">
          <div class="overflow-hidden rounded-3xl border border-[#e3ded4] bg-white shadow-[0_20px_55px_rgba(47,38,20,0.13)]">
            <div class="aspect-[4/3] overflow-hidden">
              <img
                :src="fotoLocal"
                alt="Hamburguesas de Fragua Real Burger servidas en el local"
                class="size-full object-cover"
              />
            </div>
            <!-- relative + z-10 + bg-white: esta franja necesita quedar SIEMPRE por encima de
                 la mascota (ver abajo) y con fondo opaco propio — si no, la mascota (que se
                 dibuja después en el DOM) tapa "NUESTRA CASA..." en cualquier ancho, de
                 escritorio a móvil, porque su alto casi siempre cubre esta franja completa. -->
            <div class="relative z-10 flex items-center justify-between gap-4 bg-white p-5">
              <div>
                <p class="text-[10px] font-medium tracking-[1.6px] text-brand-700">
                  NUESTRA CASA, TU PRÓXIMO ANTOJO
                </p>
                <p class="mt-1 font-display text-lg text-ink">Conoce Fragua Real</p>
              </div>
              <router-link
                to="/menu"
                class="flex size-10 shrink-0 items-center justify-center rounded-full border border-[#ddd7cd] text-ink transition hover:border-brand-500"
                aria-label="Ver el menú"
              >
                →
              </router-link>
            </div>
          </div>
          <!-- Mascota como acento: se superpone a la FOTO a propósito (se ve bien), pero
               nunca puede llegar a cruzar la franja de texto de abajo — la franja tiene
               una altura fija (texto de tamaño fijo, no responsivo) así que "bottom-24"
               deja un margen contra esa franja igual en cualquier ancho. Antes bajaba
               hasta salirse de la tarjeta (-bottom-16) y terminaba cruzando la franja por
               la mitad; el z-10 + bg-white de esa franja evitaba que tapara el texto, pero
               dejaba a la mascota con medio cuerpo "cortado" por un borde recto — se ve
               mejor completa, sin cruzar esa línea en absoluto. -->
          <img
            :src="mascota"
            alt="Mascota de Fragua Real Burger saludando con el pulgar arriba"
            class="pointer-events-none absolute bottom-24 -left-12 w-44 drop-shadow-[0_15px_25px_rgba(0,0,0,0.25)] sm:w-56 lg:w-64"
          />
        </div>
      </div>
    </section>

    <!-- Mejora del Inicio (21/10/2026): ofertas y combos que se pueden pedir hoy -->
    <OfertasDeHoy :productos="productos" :combos="combos" @agregado="alAgregar" />

    <!-- Accesos rápidos por categoría -->
    <section v-if="categoriasDestacadas.length" class="mx-auto max-w-6xl px-4 pb-4 sm:px-6">
      <div class="mb-6 flex items-center gap-3">
        <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
        <span class="text-[11px] font-medium tracking-[2px] text-brand-700">NUESTRA CARTA</span>
      </div>
      <h2 class="font-display text-3xl font-medium tracking-tight text-ink">¿Qué se te antoja hoy?</h2>
      <div class="mt-6 grid grid-cols-3 gap-3 sm:grid-cols-6">
        <router-link
          v-for="categoria in categoriasDestacadas"
          :key="categoria.id"
          :to="{ name: 'menu', query: { categoria: categoria.nombre } }"
          class="flex flex-col items-center gap-2 rounded-2xl border border-[#e3ded4] bg-white px-3 py-5 text-center transition hover:-translate-y-0.5 hover:border-brand-500 hover:shadow-[0_10px_20px_rgba(47,38,20,0.08)]"
        >
          <component :is="iconoPorNombre(categoria.nombre)" :size="22" class="text-brand-600" />
          <span class="text-xs font-medium text-ink">{{ categoria.nombre }}</span>
        </router-link>
      </div>
    </section>

    <!-- Productos destacados -->
    <section v-if="productosDestacados.length" class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
      <div class="mb-6 flex items-center justify-between gap-4">
        <h2 class="font-display text-3xl font-medium tracking-tight text-ink">Empieza por un buen antojo.</h2>
        <router-link to="/menu" class="shrink-0 text-sm font-medium text-brand-700 hover:text-brand-800">
          Ver menú completo →
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

    <!-- Beneficios -->
    <!-- El fondo de color va en una capa aparte con un degradado de opacidad
         (mask-image) que lo desvanece arriba y abajo, para que la franja se
         funda con el fondo crema de la página en vez de cortar en una línea
         recta — de ahí el padding extra, que le da espacio al desvanecido. -->
    <section class="relative overflow-hidden py-14">
      <div
        class="pointer-events-none absolute inset-0 -z-20 bg-gradient-to-r from-[#f2ead9] via-[#f6efe1] to-[#f2ead9] [mask-image:linear-gradient(to_bottom,transparent,black_28%,black_72%,transparent)] [-webkit-mask-image:linear-gradient(to_bottom,transparent,black_28%,black_72%,transparent)]"
        aria-hidden="true"
      />
      <div
        class="pointer-events-none absolute -left-16 top-1/2 size-56 -translate-y-1/2 rounded-full bg-brand-300/30 blur-3xl"
        aria-hidden="true"
      />
      <div
        class="pointer-events-none absolute -right-16 top-1/2 size-56 -translate-y-1/2 rounded-full bg-[#e8956b]/20 blur-3xl"
        aria-hidden="true"
      />
      <div class="relative mx-auto grid max-w-6xl gap-6 divide-y divide-[#e3d7bd] px-4 sm:px-6 md:grid-cols-3 md:divide-x md:divide-y-0">
        <div v-for="b in beneficios" :key="b.titulo" class="flex items-center gap-3 pt-6 first:pt-0 md:justify-center md:px-6 md:pt-0">
          <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-white text-brand-700" aria-hidden="true">
            <component :is="b.icono" :size="17" />
          </span>
          <span>
            <span class="block text-sm font-medium text-ink">{{ b.titulo }}</span>
            <span class="block text-xs text-[#8a8168]">{{ b.texto }}</span>
          </span>
        </div>
      </div>
    </section>

    <!-- Reservas -->
    <section class="relative overflow-hidden px-4 py-14 sm:px-6">
      <div
        class="pointer-events-none absolute -right-24 top-10 size-[340px] rounded-full bg-brand-200/35 blur-3xl"
        aria-hidden="true"
      />
      <div class="foto-flotante-4 pointer-events-none absolute bottom-[10%] left-[2%] hidden w-28 xl:block" aria-hidden="true">
        <div class="absolute -inset-5 -z-10 rounded-full bg-brand-400/35 blur-2xl" aria-hidden="true" />
        <div class="overflow-hidden rounded-[1.25rem] ring-1 ring-white/70 shadow-[0_25px_45px_-12px_rgba(23,21,16,0.45)]">
          <img :src="fotoDuo1" alt="" class="aspect-square w-full object-cover" />
        </div>
      </div>
      <div class="relative mx-auto grid max-w-6xl items-center gap-8 lg:grid-cols-2">
        <div class="overflow-hidden rounded-3xl">
          <img
            :src="fotoTerraza"
            alt="Clientes disfrutando hamburguesas de Fragua Real Burger en la terraza"
            class="aspect-[4/3] w-full object-cover"
          />
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
            Ven con tu familia o amigos y disfruta el sabor de Fragua Real.
          </p>
          <router-link
            to="/reservar"
            class="mt-6 inline-flex items-center justify-center rounded-xl bg-brand-500 px-6 py-3 text-sm font-medium text-ink transition hover:bg-brand-400"
          >
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
/* Animación de las fotos flotantes de fondo (hero y reservas) — un balanceo
   suave y continuo, sin brusquedad, para dar sensación de vida sin distraer.
   La rotación fija de cada foto va horneada dentro de su propio @keyframes:
   si se dejara como utilidad de Tailwind aparte (p. ej. -rotate-6), la
   animación de "transform: translateY(...)" la pisaría en cada fotograma y
   la foto "saltaría" a rotación 0 durante el movimiento. */
@keyframes flotar-1 {
  0%,
  100% {
    transform: translateY(0) rotate(-6deg);
  }
  50% {
    transform: translateY(-14px) rotate(-6deg);
  }
}

@keyframes flotar-2 {
  0%,
  100% {
    transform: translateY(0) rotate(6deg);
  }
  50% {
    transform: translateY(-14px) rotate(6deg);
  }
}

@keyframes flotar-3 {
  0%,
  100% {
    transform: translateY(0) rotate(-3deg);
  }
  50% {
    transform: translateY(-14px) rotate(-3deg);
  }
}

@keyframes flotar-4 {
  0%,
  100% {
    transform: translateY(0) rotate(6deg);
  }
  50% {
    transform: translateY(-14px) rotate(6deg);
  }
}

.foto-flotante-1 {
  animation: flotar-1 6s ease-in-out infinite;
}

.foto-flotante-2 {
  animation: flotar-2 7.5s ease-in-out infinite;
  animation-delay: 0.6s;
}

.foto-flotante-3 {
  animation: flotar-3 5s ease-in-out infinite;
  animation-delay: 1.1s;
}

.foto-flotante-4 {
  animation: flotar-4 7.5s ease-in-out infinite;
  animation-delay: 0.6s;
}
</style>
