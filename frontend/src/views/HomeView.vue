<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const apiBase = import.meta.env.VITE_API_BASE_URL || '/api'
const authHealth = ref<{ status: string; timestamp: string } | null>(null)
const projectionHealth = ref<{ status: string; timestamp: string } | null>(null)
const errors = ref<string[]>([])

async function checkService(name: string, url: string) {
  try {
    const { data } = await axios.get(url, { timeout: 3000 })
    return data
  } catch {
    errors.value.push(`${name}: unreachable`)
    return null
  }
}

onMounted(async () => {
  authHealth.value = await checkService('Auth Service', `${apiBase}/auth/../health`)
  // Projection has its own port in dev, goes through gateway in production
  projectionHealth.value = await checkService(
    'Projection Service',
    `${apiBase.replace('/api', '')}/ws/projection/../../health`
  )
})
</script>

<template>
  <div class="space-y-8">
    <!-- Hero -->
    <div class="text-center py-12">
      <h2 class="text-4xl font-bold text-primary-800 mb-2">SaintAugustin</h2>
      <p class="text-lg text-gray-500 italic">"He who sings, prays twice"</p>
      <p class="mt-4 text-sm text-gray-400">Phase 0 – Development Environment</p>
    </div>

    <!-- Service Status -->
    <div class="max-w-md mx-auto bg-white rounded-lg shadow p-6 space-y-4">
      <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide">Service Health</h3>

      <div class="space-y-2 text-sm">
        <div class="flex justify-between items-center">
          <span>Auth Service (Laravel)</span>
          <span
            :class="authHealth?.status === 'ok' ? 'text-green-600' : 'text-red-500'"
            class="font-mono"
          >
            {{ authHealth?.status ?? '...' }}
          </span>
        </div>
        <div class="flex justify-between items-center">
          <span>Projection Service (NestJS)</span>
          <span
            :class="projectionHealth?.status === 'ok' ? 'text-green-600' : 'text-red-500'"
            class="font-mono"
          >
            {{ projectionHealth?.status ?? '...' }}
          </span>
        </div>
      </div>

      <div v-if="errors.length" class="mt-4 p-3 bg-red-50 rounded text-red-700 text-xs">
        <p class="font-semibold mb-1">Connection issues:</p>
        <ul class="list-disc list-inside">
          <li v-for="err in errors" :key="err">{{ err }}</li>
        </ul>
        <p class="mt-2 text-red-500">
          Make sure all services are running: <code>docker compose up -d</code>
        </p>
      </div>
    </div>

    <!-- Phase Roadmap -->
    <div class="max-w-md mx-auto text-sm text-gray-500 space-y-1">
      <p class="font-semibold text-gray-700">Upcoming phases:</p>
      <p>① Foundation – Auth + Song CRUD + Song Library UI</p>
      <p>② Musician Experience – Chords, transposition, metronome</p>
      <p>③ Playlists & Collaboration – Builder, share links</p>
      <p>④ Projection Engine – Live WebSocket presentation</p>
      <p>⑤ Import & Migration – VideoPsalm, OpenSong, ChordPro</p>
      <p>⑥ Polish & Deploy – i18n, Kubernetes, docs</p>
    </div>
  </div>
</template>
