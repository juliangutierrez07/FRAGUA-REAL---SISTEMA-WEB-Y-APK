<script setup lang="ts">
// M12 — Impresoras (24/10/2026). El sistema imprime desde el navegador de los equipos del local
// (decisión D5): aquí se registran esos equipos, se prueba la impresora, se ajusta el formato del
// ticket de 80 mm, se ve lo que falta por imprimir y está la guía para dejar un equipo listo.
// Administrador y SuperAdmin (menú «Impresoras»); Soporte solo consulta.
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '@/components/common/PageHeader.vue'
import EquiposImpresion from '@/components/impresion/EquiposImpresion.vue'
import EstadoImpresion from '@/components/impresion/EstadoImpresion.vue'
import FormatoTicket from '@/components/impresion/FormatoTicket.vue'
import GuiaEquipoImpresion from '@/components/impresion/GuiaEquipoImpresion.vue'

type Pestana = 'equipos' | 'formato' | 'estado' | 'guia'
const PESTANAS: { v: Pestana; t: string }[] = [
  { v: 'equipos', t: 'Equipos' },
  { v: 'formato', t: 'Formato del ticket' },
  { v: 'estado', t: 'Estado' },
  { v: 'guia', t: 'Guía' },
]

const route = useRoute()
const router = useRouter()
const pestana = ref<Pestana>(PESTANAS.some((p) => p.v === route.query.ver) ? (route.query.ver as Pestana) : 'equipos')
watch(pestana, (v) => router.replace({ query: { ...route.query, ver: v } }))
</script>

<template>
  <div>
    <PageHeader
      eyebrow="OPERACIÓN"
      titulo="Impresoras"
      descripcion="Los equipos del local que imprimen comandas y comprobantes, el formato del ticket y lo que falta por imprimir."
    />

    <div class="segmented mt-6 max-w-full overflow-x-auto">
      <button v-for="p in PESTANAS" :key="p.v" type="button" class="segmented-item whitespace-nowrap" :class="pestana === p.v ? 'is-active' : ''" @click="pestana = p.v">
        {{ p.t }}
      </button>
    </div>

    <div class="mt-5">
      <EquiposImpresion v-if="pestana === 'equipos'" />
      <FormatoTicket v-else-if="pestana === 'formato'" />
      <EstadoImpresion v-else-if="pestana === 'estado'" />
      <GuiaEquipoImpresion v-else />
    </div>
  </div>
</template>
