<script setup lang="ts">
// M09 (20/10/2026): mapa del punto del negocio, con OpenStreetMap (gratis, sin clave).
//  · editable  → Configuración (SuperAdmin): tocar el mapa o arrastrar el marcador fija el punto.
//  · solo ver  → Inicio (sección Contacto) y Configuración del Administrador.
// Leaflet se carga aparte (import dinámico) para no engordar la primera carga del Inicio.
// Las teselas son imágenes (la CSP de nginx permite img-src https:); no se consulta ningún servicio externo.
// 26/10/2026: también en el Checkout, para que el Cliente confirme o mueva el punto de su domicilio
// (`centroInicial`: dónde abrir el mapa sin punto, p. ej. el local).
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { Map as MapaLeaflet, Marker } from 'leaflet'

const props = withDefaults(
  defineProps<{
    latitud: number | null
    longitud: number | null
    editable?: boolean
    etiqueta?: string
    centroInicial?: [number, number] | null
  }>(),
  { editable: false, etiqueta: 'Ubicación del negocio', centroInicial: null },
)

const emit = defineEmits<{ elegir: [latitud: number, longitud: number] }>()

// Florencia, Caquetá: donde se abre el mapa mientras no haya un punto guardado.
const CENTRO_POR_DEFECTO: [number, number] = [1.6144, -75.6062]
// En Configuración se acerca más (para atinarle a la puerta); en el Inicio se ve un poco del barrio.
const ZOOM_PUNTO = 17
const ZOOM_PUNTO_VER = 16
const ZOOM_CIUDAD = 14

const contenedor = ref<HTMLDivElement | null>(null)
const fallo = ref(false)
let L: typeof import('leaflet') | null = null
let mapa: MapaLeaflet | null = null
let marcador: Marker | null = null

function redondear(valor: number) {
  return Math.round(valor * 1e7) / 1e7
}

function icono() {
  return L!.divIcon({
    className: 'marcador-negocio',
    html: '<span class="marcador-negocio__pin"><span class="marcador-negocio__punto"></span></span>',
    iconSize: [34, 44],
    iconAnchor: [17, 42],
  })
}

function ponerMarcador(lat: number, lng: number) {
  if (!L || !mapa) return
  if (marcador) {
    marcador.setLatLng([lat, lng])
    return
  }
  marcador = L.marker([lat, lng], { icon: icono(), draggable: props.editable, keyboard: false, title: props.etiqueta }).addTo(mapa)
  if (props.editable) {
    marcador.on('dragend', () => {
      const p = marcador!.getLatLng()
      emit('elegir', redondear(p.lat), redondear(p.lng))
    })
  }
}

function quitarMarcador() {
  marcador?.remove()
  marcador = null
}

onMounted(async () => {
  try {
    const modulo = await import('leaflet')
    await import('leaflet/dist/leaflet.css')
    L = (modulo as unknown as { default?: typeof import('leaflet') }).default ?? modulo
  } catch {
    fallo.value = true
    return
  }
  if (!contenedor.value) return

  const hayPunto = props.latitud !== null && props.longitud !== null
  mapa = L.map(contenedor.value, {
    center: hayPunto ? [props.latitud!, props.longitud!] : (props.centroInicial ?? CENTRO_POR_DEFECTO),
    zoom: hayPunto ? (props.editable ? ZOOM_PUNTO : ZOOM_PUNTO_VER) : ZOOM_CIUDAD,
    // En el Inicio la rueda del mouse y el dedo siguen moviendo la página, no el mapa.
    scrollWheelZoom: props.editable,
    dragging: props.editable || !L.Browser.mobile,
    attributionControl: true,
  })
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
  }).addTo(mapa)
  mapa.attributionControl.setPrefix(false)

  if (hayPunto) ponerMarcador(props.latitud!, props.longitud!)
  if (props.editable) {
    mapa.on('click', (e) => {
      ponerMarcador(e.latlng.lat, e.latlng.lng)
      emit('elegir', redondear(e.latlng.lat), redondear(e.latlng.lng))
    })
  }
  // Si el contenedor cambia de tamaño (pestañas, giro del teléfono), Leaflet vuelve a medir.
  setTimeout(() => mapa?.invalidateSize(), 0)
})

// El punto cambia desde afuera (pegar un enlace, «Quitar punto», guardar): se mueve el marcador.
watch(
  () => [props.latitud, props.longitud] as const,
  ([lat, lng]) => {
    if (!mapa) return
    if (lat === null || lng === null) {
      quitarMarcador()
      return
    }
    ponerMarcador(lat, lng)
    if (!mapa.getBounds().pad(-0.2).contains([lat, lng])) mapa.setView([lat, lng], Math.max(mapa.getZoom(), ZOOM_PUNTO - 1))
  },
)

onBeforeUnmount(() => {
  mapa?.remove()
  mapa = null
  marcador = null
})
</script>

<template>
  <div class="mapa-ubicacion relative isolate overflow-hidden rounded-2xl border border-[#e3ded4] bg-[#f3efe7]">
    <div ref="contenedor" class="size-full" :aria-label="etiqueta" role="region" />
    <p v-if="fallo" class="absolute inset-0 flex items-center justify-center p-4 text-center text-xs text-gray-500">
      No pudimos cargar el mapa.
    </p>
  </div>
</template>

<style scoped>
.mapa-ubicacion :deep(.leaflet-container) {
  font-family: inherit;
  background: #f3efe7;
}

.mapa-ubicacion :deep(.marcador-negocio) {
  background: none;
  border: 0;
}

/* Pin con los colores de la marca: gota dorada con borde negro y punto blanco. */
.mapa-ubicacion :deep(.marcador-negocio__pin) {
  position: absolute;
  left: 3px;
  top: 0;
  width: 28px;
  height: 28px;
  border-radius: 50% 50% 50% 0;
  transform: rotate(-45deg);
  background: #d6a43a;
  border: 3px solid #111111;
  box-shadow: 0 6px 14px rgb(0 0 0 / 0.28);
  display: flex;
  align-items: center;
  justify-content: center;
}

.mapa-ubicacion :deep(.marcador-negocio__punto) {
  width: 8px;
  height: 8px;
  border-radius: 9999px;
  background: #ffffff;
}
</style>
