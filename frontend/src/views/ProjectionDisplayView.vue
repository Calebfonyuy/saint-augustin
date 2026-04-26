<script setup lang="ts">
/*
 * Projection Display — full-screen, distraction-free projector view.
 *
 * No AppShell, no chrome, no auth gate. The session id in the URL is the
 * sole authorisation token (it's an unguessable UUID minted by the
 * projection service). The page connects via socket.io as a passive
 * "display" client — even if someone replays this URL they cannot drive
 * the session because the controlToken is not in scope.
 *
 * Behaviour:
 *   • F or double-click anywhere → toggle browser fullscreen.
 *   • Tap or "Esc" exits fullscreen via native UA.
 *   • A small status overlay shows reconnecting / disconnected; it
 *     fades after a successful join so the projection stays clean.
 *   • Background and text colours come from URL query params so an
 *     operator can paint Sunday-specific theming without code changes:
 *       /projection/display/<id>?bg=%23000000&fg=%23ffffff
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import SlideRenderer from '@/components/SlideRenderer.vue'
import { useProjectionStore } from '@/stores/projection'

const route = useRoute()
const projection = useProjectionStore()

const sessionId = computed(() => route.params.id as string)
const isFullscreen = ref(false)
const showStatus = ref(true)

const bg = computed(() => (route.query.bg as string) || '#000000')
const fg = computed(() => (route.query.fg as string) || '#ffffff')

async function toggleFullscreen(): Promise<void> {
  try {
    if (!document.fullscreenElement) {
      await document.documentElement.requestFullscreen()
    } else {
      await document.exitFullscreen()
    }
  } catch {
    /* not supported; ignore */
  }
}

function onKey(e: KeyboardEvent): void {
  if (e.key === 'f' || e.key === 'F') {
    e.preventDefault()
    void toggleFullscreen()
  }
}

function onFullscreenChange(): void {
  isFullscreen.value = !!document.fullscreenElement
}

onMounted(async () => {
  document.addEventListener('fullscreenchange', onFullscreenChange)
  window.addEventListener('keydown', onKey)
  // Always connect as display: never pass a controlToken from this view,
  // even if one happens to be in the store (defensive — prevents an
  // operator who left the controller open in a hidden tab from acting
  // through this surface).
  const result = await projection.connect({ sessionId: sessionId.value })
  if (result.ok) {
    setTimeout(() => (showStatus.value = false), 1500)
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('fullscreenchange', onFullscreenChange)
  window.removeEventListener('keydown', onKey)
  projection.disconnect()
})
</script>

<template>
  <div
    class="display-root"
    :style="{ backgroundColor: bg, color: fg }"
    @dblclick="toggleFullscreen"
  >
    <SlideRenderer
      :slide="projection.currentSlide"
      :blackout="projection.state?.blackout ?? false"
      :font-scale="projection.state?.fontScale ?? 1"
      :background="bg"
      :color="fg"
      variant="display"
    />

    <transition name="fade">
      <div
        v-if="showStatus || projection.status !== 'connected'"
        class="status-overlay"
        :class="{ error: projection.status === 'error' }"
      >
        <span v-if="projection.status === 'connecting'">Connecting…</span>
        <span v-else-if="projection.status === 'connected'">Live</span>
        <span v-else-if="projection.status === 'error'">
          {{ projection.lastError || 'Disconnected' }}
        </span>
      </div>
    </transition>

    <button
      v-if="!isFullscreen"
      type="button"
      class="fs-button"
      aria-label="Enter fullscreen"
      @click="toggleFullscreen"
    >
      ⛶ Fullscreen
    </button>
  </div>
</template>

<style scoped>
.display-root {
  position: fixed;
  inset: 0;
  width: 100vw;
  height: 100vh;
  overflow: hidden;
  cursor: none;
}
.display-root:hover {
  cursor: default;
}
.status-overlay {
  position: absolute;
  top: 12px;
  right: 16px;
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
  font-size: 11px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  background: rgba(0, 0, 0, 0.55);
  color: #fff;
  padding: 4px 10px;
  border-radius: 999px;
  pointer-events: none;
}
.status-overlay.error {
  background: rgba(180, 30, 30, 0.7);
}
.fade-enter-active,
.fade-leave-active {
  transition: opacity 400ms ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
.fs-button {
  position: absolute;
  bottom: 16px;
  right: 18px;
  background: rgba(0, 0, 0, 0.4);
  color: #fff;
  border: 1px solid rgba(255, 255, 255, 0.2);
  padding: 6px 12px;
  border-radius: 6px;
  font-size: 12px;
  cursor: pointer;
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
}
.fs-button:hover {
  background: rgba(0, 0, 0, 0.65);
}
</style>
