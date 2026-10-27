<script setup lang="ts">
// Fase 3, tarea 1 — Auditoría y eventos de seguridad (RF-M01-017/024/025, HU-M01-015/020,
// RNF-TRANS-023, RN-USR-12/13). Exclusiva del Administrador y de solo lectura: muestra quién
// hizo cada acción crítica, cuándo, sobre qué registro, desde qué IP y con qué navegador, con
// filtros por día, usuario, grupo, acción, registro, IP y texto. Arriba, el resumen de
// seguridad de las últimas 24 horas (inicios de sesión fallidos, IPs con intentos repetidos).
// Ver AuditoriaController / AuditoriaConsultaService en el backend.
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChevronDown, KeyRound, LogIn, ShieldAlert, ShieldCheck, SlidersHorizontal } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import StatCard from '@/components/common/StatCard.vue'
import { auditoriaService, type FiltrosAuditoria } from '@/services/auditoria.service'
import { mensajeDeError } from '@/services/errors'
import { fechaNegocio, filasDetalle } from '@/utils/auditoria'
import type { OpcionesAuditoria, RegistroAuditoria, Rol } from '@/types/models'

const route = useRoute()
const router = useRouter()

const registros = ref<RegistroAuditoria[]>([])
const total = ref(0)
const pagina = ref(1)
const ultimaPagina = ref(1)
const cargando = ref(true)
const error = ref('')
const opciones = ref<OpcionesAuditoria | null>(null)
const expandido = ref<number | null>(null)
const verFiltros = ref(false)

type Filtros = Omit<FiltrosAuditoria, 'page'>

function haceDias(dias: number): string {
  const fecha = new Date()
  fecha.setDate(fecha.getDate() - dias)
  return fechaNegocio(fecha)
}

// Por defecto: los últimos 7 días (el historial completo puede ser muy largo).
function filtrosIniciales(): Filtros {
  return { desde: haceDias(6), hasta: fechaNegocio() }
}

const filtros = ref<Filtros>(filtrosIniciales())

// Los filtros llegan también por la URL (p. ej. "Actividad" desde Usuarios: ?user_id=3).
function leerFiltrosDeLaUrl() {
  const q = route.query
  const texto = (v: unknown) => (typeof v === 'string' && v !== '' ? v : undefined)
  const numero = (v: unknown) => (typeof v === 'string' && /^\d+$/.test(v) ? Number(v) : undefined)
  const desdeUrl: Filtros = {
    desde: texto(q.desde),
    hasta: texto(q.hasta),
    user_id: numero(q.user_id),
    categoria: texto(q.categoria),
    accion: texto(q.accion),
    entidad: texto(q.entidad),
    entidad_id: numero(q.entidad_id),
    ip: texto(q.ip),
    busqueda: texto(q.busqueda),
  }
  const hayAlgo = Object.values(desdeUrl).some((v) => v !== undefined)
  if (hayAlgo) {
    // Si la URL no trae fechas, se busca en todo el historial (no solo en los últimos 7 días).
    filtros.value = desdeUrl
    verFiltros.value = true
  }
}

function limpios(f: Filtros): Filtros {
  return Object.fromEntries(Object.entries(f).filter(([, v]) => v !== undefined && v !== null && v !== '')) as Filtros
}

const hayFiltrosExtra = computed(() =>
  Object.keys(limpios(filtros.value)).some((clave) => clave !== 'desde' && clave !== 'hasta'),
)

async function cargar() {
  cargando.value = true
  error.value = ''
  expandido.value = null
  try {
    const { data } = await auditoriaService.index({ ...limpios(filtros.value), page: pagina.value })
    registros.value = data.data
    total.value = data.total
    ultimaPagina.value = data.last_page
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar la auditoría.')
  } finally {
    cargando.value = false
  }
}

async function cargarOpciones() {
  try {
    const { data } = await auditoriaService.opciones()
    opciones.value = data
  } catch {
    // Los filtros siguen funcionando con texto libre aunque no carguen las opciones.
  }
}

function aplicarFiltros() {
  pagina.value = 1
  router.replace({ query: Object.fromEntries(Object.entries(limpios(filtros.value)).map(([k, v]) => [k, String(v)])) })
  cargar()
}

function limpiarFiltros() {
  filtros.value = filtrosIniciales()
  aplicarFiltros()
}

// Atajos: "Solo seguridad" y ver los intentos de una IP sospechosa.
function soloSeguridad() {
  filtros.value = { ...filtros.value, categoria: 'seguridad', accion: undefined }
  aplicarFiltros()
}

function verIntentosDeIp(ip: string) {
  filtros.value = { desde: haceDias(1), hasta: fechaNegocio(), accion: 'inicio_sesion_fallido', ip }
  verFiltros.value = true
  aplicarFiltros()
}

// Con un usuario elegido: además de lo que él hizo, ver lo que pasó sobre su cuenta
// (intentos fallidos con su correo, restablecimientos, desactivaciones…).
const usuarioFiltrado = computed(() => opciones.value?.usuarios.find((u) => u.id === filtros.value.user_id) ?? null)
function verEventosDeLaCuenta() {
  const id = filtros.value.user_id
  if (!id) return
  filtros.value = { desde: filtros.value.desde, hasta: filtros.value.hasta, entidad: 'User', entidad_id: id }
  aplicarFiltros()
}

// La lista de acciones se reduce al grupo elegido.
const accionesDelGrupo = computed(() => {
  const todas = opciones.value?.acciones ?? []
  return filtros.value.categoria ? todas.filter((a) => a.categoria === filtros.value.categoria) : todas
})

function alCambiarGrupo() {
  if (filtros.value.accion && !accionesDelGrupo.value.some((a) => a.valor === filtros.value.accion)) {
    filtros.value.accion = undefined
  }
}

const ROLES: Record<Rol, string> = { superadmin: 'SuperAdmin', administrador: 'Administrador', soporte: 'Soporte', mesero: 'Mesero', cliente: 'Cliente' }

// Color por grupo; los intentos fallidos se marcan en rojo.
function claseAccion(registro: RegistroAuditoria): string {
  if (registro.accion.endsWith('_fallido')) return 'bg-red-50 text-red-700 ring-red-600/20'
  const clases: Record<string, string> = {
    seguridad: 'bg-violet-50 text-violet-700 ring-violet-600/20',
    usuarios: 'bg-sky-50 text-sky-700 ring-sky-600/20',
    caja: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    compras: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  }
  return clases[registro.categoria] ?? 'bg-gray-100 text-gray-600 ring-gray-500/20'
}

function formatoFechaHora(valor: string) {
  return new Date(valor).toLocaleString('es-CO', {
    timeZone: 'America/Bogota',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    second: '2-digit',
  })
}

const contadorTexto = computed(() => (total.value === 1 ? '1 registro' : `${total.value.toLocaleString('es-CO')} registros`))
const seguridad = computed(() => opciones.value?.seguridad ?? null)

function alternar(id: number) {
  expandido.value = expandido.value === id ? null : id
}

onMounted(() => {
  leerFiltrosDeLaUrl()
  cargarOpciones()
  cargar()
})
</script>

<template>
  <div>
    <PageHeader
      eyebrow="ADMINISTRACIÓN"
      titulo="Auditoría"
      descripcion="Quién hizo cada acción crítica, cuándo y desde dónde. Es solo de consulta: el historial no se puede editar ni borrar."
    />

    <!-- Eventos de seguridad de las últimas 24 horas (RF-M01-017/024) -->
    <section v-if="seguridad" class="mt-6">
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard
          etiqueta="Inicios fallidos (24 h)"
          :valor="seguridad.inicios_fallidos_24h"
          detalle="Contraseña incorrecta, cuenta inexistente o desactivada"
          :icono="ShieldAlert"
          :destacada="seguridad.inicios_fallidos_24h > 0"
        />
        <StatCard etiqueta="Inicios de sesión (24 h)" :valor="seguridad.inicios_exitosos_24h" :icono="LogIn" />
        <StatCard etiqueta="Contraseñas restablecidas (24 h)" :valor="seguridad.restablecimientos_24h" :icono="KeyRound" />
      </div>
      <div v-if="seguridad.ips_con_intentos_repetidos.length" class="mt-3 alert-error">
        <p class="font-semibold">Intentos repetidos desde la misma IP en las últimas 24 horas:</p>
        <ul class="mt-1.5 space-y-1">
          <li v-for="fila in seguridad.ips_con_intentos_repetidos" :key="fila.ip" class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span>{{ fila.ip }} — {{ fila.intentos }} intentos fallidos</span>
            <button type="button" class="min-h-9 text-xs font-semibold underline" @click="verIntentosDeIp(fila.ip)">Ver intentos</button>
          </li>
        </ul>
      </div>
    </section>

    <!-- Filtros (RF-M01-025) -->
    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div class="flex flex-wrap items-center gap-2">
        <BaseButton variant="secondary" size="sm" @click="verFiltros = !verFiltros">
          <SlidersHorizontal :size="15" /> Filtros<template v-if="hayFiltrosExtra"> (activos)</template>
        </BaseButton>
        <BaseButton variant="ghost" size="sm" @click="soloSeguridad">
          <ShieldCheck :size="15" /> Solo seguridad y sesiones
        </BaseButton>
      </div>
      <p class="text-xs text-gray-500">
        {{ filtros.desde || filtros.hasta ? `Del ${filtros.desde ?? 'inicio'} al ${filtros.hasta ?? 'hoy'}` : 'Todo el historial' }}
      </p>
    </div>

    <form v-if="verFiltros" class="card mt-3 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="aplicarFiltros">
      <label class="block">
        <span class="field-label">Desde</span>
        <input v-model="filtros.desde" type="date" class="field" />
      </label>
      <label class="block">
        <span class="field-label">Hasta</span>
        <input v-model="filtros.hasta" type="date" class="field" />
      </label>
      <label class="block">
        <span class="field-label">Usuario que hizo la acción</span>
        <select v-model="filtros.user_id" class="field">
          <option :value="undefined">Todos</option>
          <option v-for="u in opciones?.usuarios ?? []" :key="u.id" :value="u.id">{{ u.nombre }} ({{ ROLES[u.role] }})</option>
        </select>
      </label>
      <label class="block">
        <span class="field-label">Grupo</span>
        <select v-model="filtros.categoria" class="field" @change="alCambiarGrupo">
          <option :value="undefined">Todos</option>
          <option v-for="c in opciones?.categorias ?? []" :key="c.valor" :value="c.valor">{{ c.texto }}</option>
        </select>
      </label>
      <label class="block">
        <span class="field-label">Acción</span>
        <select v-model="filtros.accion" class="field">
          <option :value="undefined">Todas</option>
          <option v-for="a in accionesDelGrupo" :key="a.valor" :value="a.valor">{{ a.texto }}</option>
        </select>
      </label>
      <label class="block">
        <span class="field-label">Sobre qué</span>
        <select v-model="filtros.entidad" class="field">
          <option :value="undefined">Cualquier registro</option>
          <option v-for="e in opciones?.entidades ?? []" :key="e.valor" :value="e.valor">{{ e.texto }}</option>
        </select>
      </label>
      <label class="block">
        <span class="field-label">Número del registro</span>
        <input v-formato="'entero'" maxlength="9" v-model.number="filtros.entidad_id" type="text" min="1" inputmode="numeric" class="field" placeholder="Ej. 125 (pedido 125)" />
      </label>
      <label class="block">
        <span class="field-label">IP</span>
        <input v-formato="'ip'" v-model.trim="filtros.ip" type="search" class="field" placeholder="Ej. 190.85.10.4" />
      </label>
      <label class="block">
        <span class="field-label">Texto en el detalle</span>
        <input v-formato="'busqueda'" v-model.trim="filtros.busqueda" type="search" maxlength="100" class="field" placeholder="Motivo, correo, valor…" />
      </label>
      <div class="flex gap-2 sm:col-span-2 sm:justify-end lg:col-span-3">
        <BaseButton variant="ghost" type="button" @click="limpiarFiltros">Limpiar</BaseButton>
        <BaseButton type="submit">Aplicar filtros</BaseButton>
      </div>
    </form>

    <p v-if="usuarioFiltrado && !filtros.entidad" class="mt-3 alert-info">
      Ves lo que hizo {{ usuarioFiltrado.nombre }}.
      <button type="button" class="font-semibold underline" @click="verEventosDeLaCuenta">
        Ver también lo que pasó sobre su cuenta
      </button>
      (intentos fallidos con su correo, restablecimientos, activación o desactivación).
    </p>

    <p v-if="error" class="mt-4 alert-error">{{ error }}</p>

    <div v-if="cargando" class="loading-state">Cargando auditoría…</div>

    <div v-else-if="!registros.length && !error" class="mt-10 empty-state">
      <ShieldCheck :size="32" class="mx-auto text-gray-300" />
      <p class="mt-3 text-sm text-gray-500">No hay registros con estos filtros.</p>
    </div>

    <template v-else-if="registros.length">
      <p class="mt-4 text-xs text-gray-500">{{ contadorTexto }} · del más reciente al más antiguo</p>
      <ul class="mt-2 space-y-2.5">
        <li v-for="registro in registros" :key="registro.id" class="card p-4">
          <button
            type="button"
            class="flex w-full items-start justify-between gap-3 text-left"
            :aria-expanded="expandido === registro.id"
            @click="alternar(registro.id)"
          >
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-ink">
                {{ registro.accion_texto }}
                <span class="badge" :class="claseAccion(registro)">{{ registro.categoria_texto }}</span>
              </p>
              <p class="mt-1 text-xs text-gray-500">
                {{ formatoFechaHora(registro.created_at) }}
                · <template v-if="registro.usuario">{{ registro.usuario.nombre }} ({{ ROLES[registro.usuario.role] }})</template>
                <template v-else>Sin sesión iniciada</template>
                · {{ registro.entidad_texto }}<template v-if="registro.entidad_id"> #{{ registro.entidad_id }}</template>
              </p>
              <p v-if="registro.ip || registro.navegador" class="mt-0.5 text-xs text-gray-400">
                <template v-if="registro.ip">IP {{ registro.ip }}</template>
                <template v-if="registro.ip && registro.navegador"> · </template>
                <template v-if="registro.navegador">{{ registro.navegador }}</template>
              </p>
            </div>
            <ChevronDown
              :size="18"
              class="mt-1 shrink-0 text-gray-400 transition"
              :class="{ 'rotate-180': expandido === registro.id }"
            />
          </button>

          <div v-if="expandido === registro.id" class="mt-3 border-t border-gray-100 pt-3 text-sm">
            <!-- Mejora (26/10/2026, RF-M01-034): quién lo hizo, también dentro del detalle. -->
            <dl class="mb-2 grid gap-x-4 gap-y-1.5 sm:grid-cols-[minmax(8rem,auto)_1fr]">
              <dt class="text-xs font-semibold text-gray-500">Hecho por</dt>
              <dd class="break-words font-medium text-ink">
                <template v-if="registro.usuario">
                  {{ registro.usuario.nombre }} · {{ ROLES[registro.usuario.role] }}
                  <span class="block text-xs font-normal text-gray-500">{{ registro.usuario.email }}</span>
                </template>
                <template v-else>Sin sesión iniciada (el cliente, un invitado o el sistema)</template>
              </dd>
              <dt class="text-xs font-semibold text-gray-500">Cuándo</dt>
              <dd class="text-gray-800">{{ formatoFechaHora(registro.created_at) }}</dd>
            </dl>
            <dl v-if="filasDetalle(registro.detalles).length" class="grid gap-x-4 gap-y-1.5 border-t border-gray-100 pt-2 sm:grid-cols-[minmax(8rem,auto)_1fr]">
              <template v-for="(fila, i) in filasDetalle(registro.detalles)" :key="i">
                <dt class="text-xs font-semibold text-gray-500">{{ fila.campo }}</dt>
                <dd class="break-words text-gray-800">{{ fila.valor }}</dd>
              </template>
            </dl>
            <p v-else class="text-xs text-gray-500">Esta acción no guarda detalles adicionales.</p>
            <p v-if="registro.user_agent" class="mt-1 break-all text-[11px] text-gray-400">Navegador completo: {{ registro.user_agent }}</p>
            <p class="mt-1 text-[11px] text-gray-400">Registro de auditoría #{{ registro.id }}</p>
          </div>
        </li>
      </ul>

      <div v-if="ultimaPagina > 1" class="mt-6 flex items-center justify-center gap-3">
        <BaseButton variant="ghost" :disabled="pagina <= 1 || cargando" @click="pagina--; cargar()">Anterior</BaseButton>
        <span class="text-xs text-gray-500">{{ pagina }} / {{ ultimaPagina }}</span>
        <BaseButton variant="ghost" :disabled="pagina >= ultimaPagina || cargando" @click="pagina++; cargar()">Siguiente</BaseButton>
      </div>
    </template>
  </div>
</template>
