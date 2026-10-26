<script setup lang="ts">
// M12 — Impresoras (24/10/2026, RF-M12-012): paso a paso para dejar un equipo del local listo para
// imprimir en la impresora térmica de 80 mm sin que aparezca el cuadro de impresión.
import { computed, ref } from 'vue'
import { Check, Copy } from 'lucide-vue-next'

const destino = ref<'mesero' | 'admin'>('mesero')
const url = computed(() => `${window.location.origin}/${destino.value === 'mesero' ? 'mesero' : 'admin/pedidos'}`)
const comando = computed(() => `"C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe" --kiosk-printing --app=${url.value}`)
const copiado = ref(false)

async function copiar() {
  try {
    await navigator.clipboard.writeText(comando.value)
  } catch {
    const campo = document.createElement('textarea')
    campo.value = comando.value
    document.body.appendChild(campo)
    campo.select()
    document.execCommand('copy')
    campo.remove()
  }
  copiado.value = true
  setTimeout(() => (copiado.value = false), 1800)
}
</script>

<template>
  <section class="card p-4 sm:p-6">
    <h2 class="font-display text-lg font-medium text-ink">Dejar un equipo listo para imprimir</h2>
    <p class="mt-1 text-sm text-gray-600">
      El sistema está en internet y la impresora en el local: imprime el navegador del equipo que está junto a ella. Con estos
      pasos, las comandas y los tickets salen solos, sin el cuadro de impresión. Hazlo una vez por equipo.
    </p>

    <ol class="mt-5 space-y-5">
      <li class="flex gap-3">
        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-white">1</span>
        <div class="text-sm text-gray-700">
          <p class="font-semibold text-ink">Instala la impresora y déjala como predeterminada</p>
          <p class="mt-0.5">
            Instala el controlador del fabricante (USB o red). En Windows: <strong>Configuración › Bluetooth y dispositivos › Impresoras</strong>,
            elige la térmica y toca <strong>Establecer como predeterminada</strong>. Apaga «Permitir que Windows administre la impresora predeterminada».
          </p>
        </div>
      </li>
      <li class="flex gap-3">
        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-white">2</span>
        <div class="text-sm text-gray-700">
          <p class="font-semibold text-ink">Configura el papel de 80 mm</p>
          <p class="mt-0.5">
            En las <strong>Preferencias de impresión</strong> de la impresora: papel de 80 mm (o «80 × 297 mm» / rollo), corte automático
            prendido y, si la impresora lo ofrece, «abrir cajón» solo si lo vas a usar.
          </p>
        </div>
      </li>
      <li class="flex gap-3">
        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-white">3</span>
        <div class="min-w-0 flex-1 text-sm text-gray-700">
          <p class="font-semibold text-ink">Crea el acceso directo de Chrome con impresión automática</p>
          <p class="mt-0.5">
            En el escritorio: clic derecho › <strong>Nuevo › Acceso directo</strong>, pega esto y ponle de nombre «Fragua Real».
            Abre siempre el sistema desde ahí.
          </p>
          <div class="mt-2 flex flex-wrap gap-2 text-xs">
            <label class="flex items-center gap-1.5"><input v-model="destino" type="radio" value="mesero" /> Abre en Pedidos del Mesero (cocina)</label>
            <label class="flex items-center gap-1.5"><input v-model="destino" type="radio" value="admin" /> Abre en Pedidos del Administrador</label>
          </div>
          <div class="mt-2 flex items-start gap-2 rounded-xl bg-gray-900 p-3">
            <code class="min-w-0 flex-1 break-all font-mono text-xs text-gray-100">{{ comando }}</code>
            <button type="button" class="inline-flex shrink-0 items-center gap-1 rounded-lg bg-white/10 px-2 py-1 text-xs text-white hover:bg-white/20" @click="copiar">
              <component :is="copiado ? Check : Copy" :size="13" /> {{ copiado ? 'Copiado' : 'Copiar' }}
            </button>
          </div>
          <p class="mt-1 text-xs text-gray-500">
            Cierra todas las ventanas de Chrome antes de abrir el acceso directo: la opción solo funciona si Chrome arranca con ella.
          </p>
        </div>
      </li>
      <li class="flex gap-3">
        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-white">4</span>
        <div class="text-sm text-gray-700">
          <p class="font-semibold text-ink">Permite las ventanas emergentes</p>
          <p class="mt-0.5">
            La primera vez que se abra una comanda, Chrome puede mostrar «Ventana emergente bloqueada» en la barra de direcciones:
            toca el aviso y elige <strong>Permitir siempre</strong> para este sitio.
          </p>
        </div>
      </li>
      <li class="flex gap-3">
        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-white">5</span>
        <div class="text-sm text-gray-700">
          <p class="font-semibold text-ink">Registra el equipo y haz la prueba</p>
          <p class="mt-0.5">
            En la pestaña <strong>Equipos</strong>: registra este equipo (o vincúlalo con un código si tiene la sesión de un Mesero),
            toca <strong>Imprimir prueba</strong> y responde cómo salió. La regla del ticket debe salir completa y las tildes y la ñ, bien.
          </p>
        </div>
      </li>
      <li class="flex gap-3">
        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-ink text-xs font-semibold text-white">6</span>
        <div class="text-sm text-gray-700">
          <p class="font-semibold text-ink">Si algo falla</p>
          <ul class="mt-1 list-disc space-y-0.5 pl-5">
            <li><strong>No sale nada:</strong> revisa que la impresora esté prendida, con papel y como predeterminada; y que el acceso directo tenga <code>--kiosk-printing</code>.</li>
            <li><strong>Sale cortado o muy pequeño:</strong> en el cuadro de impresión, márgenes en «Ninguno» y escala 100 %; papel de 80 mm.</li>
            <li><strong>Sale el cuadro de impresión:</strong> Chrome no arrancó con la opción; ciérralo del todo y ábrelo desde el acceso directo.</li>
            <li><strong>Las comandas no salen solas:</strong> en Equipos, prende «Abre solas las comandas nuevas» para ese equipo.</li>
          </ul>
        </div>
      </li>
    </ol>
  </section>
</template>
