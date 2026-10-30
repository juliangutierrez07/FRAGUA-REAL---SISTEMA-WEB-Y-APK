<script setup lang="ts">
// Mejora (19/10/2026) — Configuración > Datos del negocio. El Administrador cambia la dirección, el
// teléfono (también el de WhatsApp) y el logo que salen en el Inicio, el pie de página, Reservar,
// los encabezados de las pantallas y los PDF (comprobante, cierre diario y reportes).
// Cada cambio se ve de inmediato en todo el sitio y queda en la Auditoría.
// M09 (20/10/2026): el SuperAdmin marca en el mapa el punto exacto del local (tocando o arrastrando el
// marcador, o pegando un enlace de Google Maps); «Cómo llegar» abre Google Maps ahí. El Administrador
// lo ve sin poder moverlo.
import TarjetaConfiguracion from '@/components/configuracion/TarjetaConfiguracion.vue'
import { computed, onMounted, ref } from 'vue'
import { ImagePlus, Link2, MapPinOff, Store, Trash2 } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseInput from '@/components/common/BaseInput.vue'
import LogoNegocio from '@/components/common/LogoNegocio.vue'
import MapaUbicacion from '@/components/common/MapaUbicacion.vue'
import { configuracionService } from '@/services/configuracion.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { useNegocioStore } from '@/stores/negocio'
import { useAuthStore } from '@/stores/auth'

const negocio = useNegocioStore()
// M09 (19/10/2026): el nombre y el NIT solo los cambia el SuperAdmin; el Administrador los ve.
const auth = useAuthStore()

const nombre = ref('')
const nit = ref('')
const direccion = ref('')
const telefono = ref('')
const latitud = ref<number | null>(null)
const longitud = ref<number | null>(null)
const guardados = ref({ nombre: '', nit: '', direccion: '', telefono: '', latitud: null as number | null, longitud: null as number | null })
const guardando = ref(false)
const errores = ref<Record<string, string>>({})
const error = ref('')
const exito = ref('')

const subiendo = ref(false)
const errorLogo = ref('')
const entradaLogo = ref<HTMLInputElement | null>(null)

const enlace = ref('')
const leyendoEnlace = ref(false)
const errorEnlace = ref('')

const hayCambios = computed(
  () =>
    direccion.value.trim() !== guardados.value.direccion ||
    telefono.value.trim() !== guardados.value.telefono ||
    (auth.esSuperAdmin &&
      (nombre.value.trim() !== guardados.value.nombre ||
        nit.value.trim() !== guardados.value.nit ||
        latitud.value !== guardados.value.latitud ||
        longitud.value !== guardados.value.longitud)),
)

function tomarDatos() {
  const d = negocio.datos
  nombre.value = d.nombre
  nit.value = d.nit ?? ''
  direccion.value = d.direccion
  telefono.value = d.telefono
  latitud.value = d.latitud ?? null
  longitud.value = d.longitud ?? null
  guardados.value = {
    nombre: d.nombre,
    nit: d.nit ?? '',
    direccion: d.direccion,
    telefono: d.telefono,
    latitud: latitud.value,
    longitud: longitud.value,
  }
}

onMounted(async () => {
  await negocio.cargar(true)
  tomarDatos()
})

async function guardar() {
  errores.value = {}
  error.value = ''
  exito.value = ''
  guardando.value = true
  try {
    const { data } = await configuracionService.actualizarNegocio({
      direccion: direccion.value.trim(),
      telefono: telefono.value.trim(),
      ...(auth.esSuperAdmin
        ? { nombre: nombre.value.trim(), nit: nit.value.trim(), latitud: latitud.value, longitud: longitud.value }
        : {}),
    })
    negocio.fijar(data)
    tomarDatos()
    exito.value = 'Datos del negocio guardados. Ya se ven en el sitio.'
  } catch (e) {
    errores.value = erroresDeValidacion(e)
    if (Object.keys(errores.value).length === 0) error.value = mensajeDeError(e, 'No pudimos guardar los datos del negocio.')
  } finally {
    guardando.value = false
  }
}

function elegirPunto(lat: number, lng: number) {
  latitud.value = lat
  longitud.value = lng
  exito.value = ''
}

function quitarPunto() {
  latitud.value = null
  longitud.value = null
  exito.value = ''
}

// Enlace de «Compartir» de Google Maps (o «1.6144, -75.6062»): el servidor saca el punto y el marcador
// se mueve ahí. Queda pendiente hasta «Guardar», igual que al tocar el mapa.
async function usarEnlace() {
  errorEnlace.value = ''
  if (!enlace.value.trim()) {
    errorEnlace.value = 'Pega el enlace de Google Maps.'
    return
  }
  leyendoEnlace.value = true
  try {
    const { data } = await configuracionService.ubicacionDesdeEnlace(enlace.value.trim())
    elegirPunto(data.latitud, data.longitud)
    enlace.value = ''
  } catch (e) {
    errorEnlace.value = erroresDeValidacion(e).enlace ?? mensajeDeError(e, 'No pudimos leer ese enlace.')
  } finally {
    leyendoEnlace.value = false
  }
}

async function elegirLogo(evento: Event) {
  const archivo = (evento.target as HTMLInputElement).files?.[0]
  if (!archivo) return
  errorLogo.value = ''
  exito.value = ''
  subiendo.value = true
  try {
    const { data } = await configuracionService.subirLogo(archivo)
    negocio.fijar(data)
    exito.value = 'Logo actualizado.'
  } catch (e) {
    errorLogo.value = erroresDeValidacion(e).logo ?? mensajeDeError(e, 'No pudimos subir el logo.')
  } finally {
    subiendo.value = false
    if (entradaLogo.value) entradaLogo.value.value = ''
  }
}

async function quitarLogo() {
  if (!window.confirm('¿Quitar el logo? Se volverá a mostrar el círculo «FR».')) return
  errorLogo.value = ''
  exito.value = ''
  subiendo.value = true
  try {
    const { data } = await configuracionService.quitarLogo()
    negocio.fijar(data)
    exito.value = 'Logo quitado.'
  } catch (e) {
    errorLogo.value = mensajeDeError(e, 'No pudimos quitar el logo.')
  } finally {
    subiendo.value = false
  }
}
</script>

<template>
  <form novalidate @submit.prevent="guardar">
    <TarjetaConfiguracion :icono="Store" tono="bg-emerald-50 text-emerald-700" titulo="Datos del negocio">
      <template #descripcion>
        Salen en el Inicio, el pie de página, Reservar, los encabezados de las pantallas y los PDF
        (comprobante, cierre diario y reportes).
      </template>

        <!-- Logo. 30/10/2026: en el teléfono, el botón va debajo (antes apretaba el texto en una columna angosta). -->
        <div class="grid grid-cols-[auto_1fr] items-center gap-3 rounded-xl border border-[#ece6db] bg-[#fbfaf7] p-3 sm:flex sm:gap-4">
          <LogoNegocio tamano="size-14 sm:size-16" texto="text-xl sm:text-2xl" />
          <div class="min-w-0 sm:flex-1">
            <p class="text-sm font-medium text-ink">Logo</p>
            <p class="text-xs text-gray-500">
              PNG, JPG o WebP de al menos 64 × 64 px; mejor cuadrado y con fondo transparente. Se ve en un
              círculo, así que deja margen alrededor.
            </p>
            <p v-if="errorLogo" class="mt-1 text-xs text-red-600">{{ errorLogo }}</p>
          </div>
          <div class="col-span-2 flex gap-2 [&>button:first-of-type]:flex-1 sm:shrink-0 sm:[&>button:first-of-type]:flex-none">
            <input ref="entradaLogo" type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="elegirLogo" />
            <BaseButton v-escritura type="button" variant="secondary" :disabled="subiendo" @click="entradaLogo?.click()">
              <ImagePlus :size="16" />
              {{ subiendo ? 'Subiendo…' : negocio.datos.logo_url ? 'Cambiar' : 'Subir logo' }}
            </BaseButton>
            <BaseButton v-escritura v-if="negocio.datos.logo_url" type="button" variant="ghost" :disabled="subiendo" aria-label="Quitar logo" @click="quitarLogo">
              <Trash2 :size="16" />
            </BaseButton>
          </div>
        </div>

        <div class="mt-4 space-y-4">
          <div v-if="auth.esSuperAdmin" class="grid gap-4 sm:grid-cols-2">
            <BaseInput formato="nombre" v-model="nombre" label="Nombre del negocio" type="text" placeholder="Fragua Real Burger" :error="errores.nombre" />
            <BaseInput formato="documento" v-model="nit" label="NIT" type="text" placeholder="1075320008-5" :error="errores.nit" />
          </div>
          <p v-else class="rounded-xl bg-[#fbfaf7] px-3 py-2 text-xs text-gray-500">
            {{ negocio.datos.nombre }}<template v-if="negocio.datos.nit"> · NIT {{ negocio.datos.nit }}</template> — el nombre y el NIT
            solo los cambia el SuperAdmin.
          </p>
          <BaseInput formato="direccion" v-model="direccion" label="Dirección" type="text" placeholder="Esquina frente al malecón turístico, etapa 1." :error="errores.direccion" />

          <!-- M09: punto exacto del local. -->
          <div>
            <div class="flex flex-wrap items-end justify-between gap-2">
              <div>
                <p class="text-sm font-medium text-ink">Ubicación en el mapa</p>
                <p class="text-xs text-gray-500">
                  <template v-if="auth.esSuperAdmin">
                    Toca el mapa o arrastra el marcador hasta la puerta del local. «Cómo llegar» abrirá Google Maps
                    justo ahí.
                  </template>
                  <template v-else>Solo el SuperAdmin cambia el punto del local.</template>
                </p>
              </div>
              <BaseButton v-escritura
                v-if="auth.esSuperAdmin && latitud !== null"
                type="button"
                variant="ghost"
                @click="quitarPunto"
              >
                <MapPinOff :size="16" />
                Quitar punto
              </BaseButton>
            </div>

            <MapaUbicacion
              class="mt-3 h-52 sm:h-72"
              :latitud="latitud"
              :longitud="longitud"
              :editable="auth.esSuperAdmin"
              @elegir="elegirPunto"
            />

            <p class="mt-2 text-xs" :class="latitud !== null ? 'text-gray-500' : 'text-amber-700'">
              <template v-if="latitud !== null">
                Punto: {{ latitud }}, {{ longitud }}
                <template v-if="auth.esSuperAdmin && (latitud !== guardados.latitud || longitud !== guardados.longitud)">
                  · sin guardar
                </template>
              </template>
              <template v-else>
                Sin punto: «Cómo llegar» busca la dirección escrita en Google Maps.
              </template>
            </p>

            <div v-if="auth.esSuperAdmin" class="mt-3 rounded-xl border border-[#ece6db] bg-[#fbfaf7] p-3">
              <p class="text-xs text-gray-600">
                ¿Ya lo tienes en Google Maps? Abre el lugar, toca «Compartir», copia el enlace y pégalo aquí.
              </p>
              <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                <div class="min-w-0 flex-1">
                  <BaseInput
                    v-model="enlace"
                    type="text"
                    placeholder="https://maps.app.goo.gl/…  o  1.6144, -75.6062"
                    :error="errorEnlace"
                    @keydown.enter.prevent="usarEnlace"
                  />
                </div>
                <BaseButton v-escritura type="button" variant="secondary" class="shrink-0 self-start" :disabled="leyendoEnlace" @click="usarEnlace">
                  <Link2 :size="16" />
                  {{ leyendoEnlace ? 'Buscando…' : 'Usar enlace' }}
                </BaseButton>
              </div>
            </div>
          </div>
          <div>
            <BaseInput formato="celular" v-model="telefono" label="Teléfono y WhatsApp" type="text" placeholder="322 725 9965" :error="errores.telefono" />
            <p v-if="!errores.telefono" class="mt-1 text-xs text-gray-500">
              Con este número se arma el botón de WhatsApp (sin indicativo se asume Colombia, +57).
            </p>
          </div>
        </div>

        <p v-if="error" class="alert-error mt-3">{{ error }}</p>
        <p v-if="exito" class="mt-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ exito }}</p>

        <div class="mt-4 flex justify-end">
          <BaseButton v-escritura type="submit" variant="primary" class="w-full sm:w-auto" :disabled="guardando || !hayCambios">
            {{ guardando ? 'Guardando…' : 'Guardar datos del negocio' }}
          </BaseButton>
        </div>
    </TarjetaConfiguracion>
  </form>
</template>
