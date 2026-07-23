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
 * ── Behaviour ──────────────────────────────────────────────────────────
 *   • F or double-click anywhere → toggle browser fullscreen.
 *   • Tap or Esc exits fullscreen via the native UA.
 *   • A small status overlay shows "Connecting…" / "Live" / error; it
 *     fades 1.5 s after a successful join so the screen stays clean.
 *   • When no session state has been received yet (projection.state is
 *     null) a branded waiting screen is shown instead of "No slide".
 *   • On socket disconnect the status overlay reappears ("Connecting…")
 *     until the socket.io reconnection loop succeeds and the server
 *     pushes a fresh state snapshot.
 *
 * ── Query-param theming ─────────────────────────────────────────────────
 * All visual tweaks are set via URL query params so the operator can
 * configure a display without editing code:
 *
 *   bg    — CSS colour for the background (default #000000)
 *   fg    — CSS colour for the text       (default #ffffff)
 *   bgimg — URL-encoded background image URL (optional)
 *   align — Text alignment: left | center | right (default center)
 *   font  — Font preset: serif | sans | mono (default serif = Crimson Pro)
 *
 * Example:
 *   /projection/display/<id>?bg=%231a1a2e&fg=%23e8e0d0&align=left&font=serif
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import BrandMark from '@/components/BrandMark.vue'
import SlideRenderer from '@/components/SlideRenderer.vue'
import { useProjectionStore } from '@/stores/projection'

const route = useRoute()
const projection = useProjectionStore()
const { t } = useI18n()

const sessionId = computed(() => route.params.id as string)
const isFullscreen = ref(false)
const showStatus = ref(true)

/** True when we've joined a persistent session that hasn't been started yet.
 *  The display swaps the slide area for a friendly "session hasn't started"
 *  screen until the controller transitions it to LIVE. */
const isNotStarted = computed(
  () => projection.state?.status === 'NOT_STARTED',
)

/** Pre-formatted scheduled start time, in the viewer's local timezone, or
 *  null when the session is open-ended (no schedule). */
const scheduledStartLabel = computed(() => {
  const iso = projection.state?.scheduledStartAt
  if (!iso) return null
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  return d.toLocaleString(undefined, {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
})

// ── Query-param theming ─────────────────────────────────────────────────

const bg = computed(() => (route.query.bg as string) || '#000000')
const fg = computed(() => (route.query.fg as string) || '#ffffff')
// Background image URL (operator-provided via the controller's "copy display URL").
const bgImg = computed(() => (route.query.bgimg as string) || undefined)

// Text alignment — defaults to center for traditional worship projection.
const textAlign = computed((): 'left' | 'center' | 'right' => {
  const v = route.query.align as string
  if (v === 'left' || v === 'right') return v
  return 'center'
})

/**
 * Font family preset → CSS font-family string.
 *
 * Presets map to the three project design-system fonts:
 *   serif (default) → Crimson Pro — high legibility at large sizes
 *   sans            → IBM Plex Sans
 *   mono            → IBM Plex Mono — useful for spoken-word / readings
 *
 * When no param is given, undefined is passed to SlideRenderer which
 * falls back to var(--font-display), resolving to Crimson Pro.
 */
const fontFamilyPresets: Record<string, string> = {
  serif: "'Crimson Pro', Georgia, 'Times New Roman', serif",
  sans: "'IBM Plex Sans', -apple-system, system-ui, sans-serif",
  mono: "'IBM Plex Mono', ui-monospace, Menlo, monospace",
}
const fontFamily = computed(() => {
  const v = route.query.font as string
  return fontFamilyPresets[v] ?? undefined
})

// ── Fullscreen ──────────────────────────────────────────────────────────

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

// ── Lifecycle ───────────────────────────────────────────────────────────

onMounted(async () => {
  document.addEventListener('fullscreenchange', onFullscreenChange)
  window.addEventListener('keydown', onKey)
  // Always connect as display: never pass a controlToken from this view,
  // even if one happens to be in the store (defensive — prevents an
  // operator who left the controller open in a hidden tab from acting
  // through this surface).
  const result = await projection.connect({ sessionId: sessionId.value })
  if (result.ok) {
    // Brief "Live" confirmation, then clear the overlay so it doesn't
    // obstruct the lyrics.
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
    data-testid="display-root"
    @dblclick="toggleFullscreen"
  >
    <!-- ── Not-started screen ────────────────────────────────────────── -->
    <!-- Shown when the persistent session exists but hasn't been started -->
    <!-- yet. Surfaces the scheduled start time so worshippers know when  -->
    <!-- to come back.                                                    -->
    <template v-if="isNotStarted">
      <div class="waiting-screen" data-testid="display-not-started">
        <div class="waiting-inner">
          <BrandMark :size="44" class="waiting-brand" />
          <p class="waiting-headline" data-testid="display-not-started-name">
            {{ projection.state?.name ?? t('projectionDisplay.fallbackName') }}
          </p>
          <p class="waiting-label">{{ t('projectionDisplay.notStarted') }}</p>
          <p
            v-if="scheduledStartLabel"
            class="waiting-schedule"
            data-testid="display-not-started-schedule"
          >
            {{ t('projectionDisplay.startsAt', { time: scheduledStartLabel }) }}
          </p>
          <p v-else class="waiting-schedule" data-testid="display-not-started-schedule">
            {{ t('projectionDisplay.noScheduledStart') }}
          </p>
        </div>
      </div>
    </template>

    <!-- ── Main slide area ───────────────────────────────────────────── -->
    <template v-else-if="projection.state">
      <SlideRenderer
        :slide="projection.currentSlide"
        :blackout="projection.state.blackout"
        :font-scale="projection.state.fontScale"
        :background="bg"
        :background-image="bgImg"
        :color="fg"
        :text-align="textAlign"
        :font-family="fontFamily"
        variant="display"
        data-testid="display-slide-renderer"
      />
    </template>

    <!-- ── Waiting / idle screen ─────────────────────────────────────── -->
    <!-- Shown before the first state snapshot arrives (e.g. operator  -->
    <!-- opens the display URL before the controller has gone live).    -->
    <template v-else>
      <div class="waiting-screen" data-testid="display-waiting">
        <div class="waiting-inner">
          <BrandMark :size="44" class="waiting-brand" />
          <p class="waiting-label">{{ t('projectionDisplay.waiting') }}</p>
          <p class="waiting-session" data-testid="display-session-id">
            {{ sessionId }}
          </p>
        </div>
      </div>
    </template>

    <!-- ── Status overlay ────────────────────────────────────────────── -->
    <!-- Fades in during connecting / error states and 1.5 s after join. -->
    <Transition name="fade">
      <div
        v-if="showStatus || projection.status !== 'connected'"
        class="status-overlay"
        :class="{ 'status-error': projection.status === 'error' }"
        data-testid="display-status"
      >
        <span v-if="projection.status === 'connecting'">{{ t('projectionDisplay.connecting') }}</span>
        <span v-else-if="projection.status === 'connected'">{{ t('projectionDisplay.live') }}</span>
        <span v-else-if="projection.status === 'error'">
          {{ projection.lastError || t('projectionDisplay.disconnected') }}
        </span>
      </div>
    </Transition>

    <!-- ── Fullscreen prompt ──────────────────────────────────────────── -->
    <!-- Shown only when not already in fullscreen (disappears once the  -->
    <!-- operator clicks it or presses F).                               -->
    <button
      v-if="!isFullscreen"
      type="button"
      class="fs-button"
      :aria-label="t('projectionDisplay.enterFullscreen')"
      data-testid="display-fullscreen-btn"
      @click="toggleFullscreen"
    >
      ⛶ {{ t('projectionDisplay.fullscreen') }}
    </button>
  </div>
</template>

<style scoped>
/* Root — fills the entire viewport, no scroll, no chrome. */
.display-root {
  position: fixed;
  inset: 0;
  width: 100vw;
  height: 100vh;
  overflow: hidden;
  cursor: none;
  transition: background-color 300ms ease;
}
.display-root:hover {
  cursor: default;
}

/* ── Waiting screen ────────────────────────────────────────────────── */
.waiting-screen {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.waiting-inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 20px;
  opacity: 0.6;
  animation: waiting-pulse 3s ease-in-out infinite;
}
.waiting-brand {
  /* Recolors the "STAUG" wordmark text to match fg; the logo image itself
     is fixed-color and won't adapt to the slide's background/foreground. */
  color: inherit;
}
.waiting-label {
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
  font-size: 13px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  margin: 0;
}
.waiting-session {
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
  font-size: 10px;
  letter-spacing: 0.12em;
  opacity: 0.5;
  margin: 0;
}
.waiting-headline {
  font-family: 'Crimson Pro', Georgia, 'Times New Roman', serif;
  font-size: clamp(28px, 4vw, 48px);
  font-weight: 600;
  text-align: center;
  margin: 0;
  max-width: 80vw;
  line-height: 1.15;
}
.waiting-schedule {
  font-family: 'Crimson Pro', Georgia, 'Times New Roman', serif;
  font-size: clamp(16px, 2vw, 22px);
  font-weight: 400;
  text-align: center;
  margin: 0;
  opacity: 0.75;
}

@keyframes waiting-pulse {
  0%, 100% { opacity: 0.45; }
  50%       { opacity: 0.75; }
}

/* ── Status overlay ────────────────────────────────────────────────── */
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
.status-overlay.status-error {
  background: rgba(180, 30, 30, 0.7);
}

/* ── Fullscreen button ─────────────────────────────────────────────── */
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
  transition: background 120ms;
}
.fs-button:hover {
  background: rgba(0, 0, 0, 0.65);
}

/* ── Status overlay transitions ────────────────────────────────────── */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 400ms ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
