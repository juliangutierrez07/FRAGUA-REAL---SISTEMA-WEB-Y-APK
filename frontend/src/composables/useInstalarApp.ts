// Mejora (16/10/2026): instalar la app (PWA) desde el sitio.
//  - Android y computador (Chrome, Edge): el navegador avisa con «beforeinstallprompt» que se puede
//    instalar; se guarda ese aviso y el botón «Instalar app» lo usa.
//  - iPhone y iPad (Safari): no hay ese aviso; se instala con Compartir > «Agregar a inicio», así que el
//    botón muestra esos pasos.
//  - Ya instalada (abierta desde el ícono): el botón no se muestra.
// El aviso se escucha apenas carga el módulo (main.ts lo importa), porque llega muy temprano.
import { computed, ref } from 'vue'

interface AvisoInstalacion extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

const aviso = ref<AvisoInstalacion | null>(null)
const instalada = ref(false)

function abiertaComoApp(): boolean {
  return (
    window.matchMedia?.('(display-mode: standalone)').matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true
  )
}

if (typeof window !== 'undefined') {
  instalada.value = abiertaComoApp()
  window.addEventListener('beforeinstallprompt', (evento) => {
    evento.preventDefault()
    aviso.value = evento as AvisoInstalacion
  })
  window.addEventListener('appinstalled', () => {
    aviso.value = null
    instalada.value = true
  })
}

const esIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)

// CORREGIDO (26/10/2026, RNF-TRANS-034): el botón solo aparecía cuando el navegador mandaba su aviso, y eso depende del
// navegador (Samsung Internet, Firefox o Chrome ya descartado no lo mandan). Ahora aparece siempre que la
// app no esté instalada: con aviso instala directo; sin aviso muestra los pasos de ese navegador.
// En computador sin aviso (p. ej. Firefox) no se muestra: ese navegador no instala apps.
const esMovil = esIOS || /Android|Mobile/i.test(navigator.userAgent)

export function useInstalarApp() {
  const puedeInstalar = computed(() => !instalada.value && !!aviso.value)
  const mostrarPasosIOS = computed(() => !instalada.value && !aviso.value && esIOS)
  const mostrarPasos = computed(() => !instalada.value && !aviso.value && esMovil)

  async function instalar(): Promise<boolean> {
    if (!aviso.value) return false
    await aviso.value.prompt()
    const { outcome } = await aviso.value.userChoice
    aviso.value = null
    if (outcome === 'accepted') instalada.value = true
    return outcome === 'accepted'
  }

  return { puedeInstalar, mostrarPasosIOS, mostrarPasos, esIOS, instalada, instalar }
}

/** True si la página se abrió desde el ícono de la app instalada. */
export function esAppInstalada(): boolean {
  return abiertaComoApp()
}
