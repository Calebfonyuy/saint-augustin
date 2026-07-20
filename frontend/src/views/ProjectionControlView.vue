<script setup lang="ts">
/*
 * Projection Controller — the worship leader's control surface.
 *
 * Layout:
 *   ┌────────────────┬──────────────────────────────────────────────────┐
 *   │ Service order  │ Current slide preview          ⌨ shortcuts panel │
 *   │ (jump-to-song) │ ─────────────────────────────                    │
 *   │                │ Next slide preview                               │
 *   │                │ Toolbar: Prev · Next · Blackout · Font · Display │
 *   └────────────────┴──────────────────────────────────────────────────┘
 *
 * The stage area uses the `.dark` design-token scope so slide previews
 * sit on a dark canvas that matches the projector screen aesthetic.
 * The header and service-order sidebar remain in the light theme.
 *
 * Keyboard shortcuts (documented in the panel and in SRS §8.2):
 *   Right / Space / PageDown  → next slide
 *   Left / PageUp             → previous slide
 *   B                         → toggle blackout
 *   F                         → toggle fullscreen
 *   +/=                       → font scale up
 *   - / _                     → font scale down
 *
 * Connection lifecycle:
 *   • If the projection store already holds the controlToken (we just
 *     came from "Go Live") we connect as controller immediately.
 *   • Otherwise, we first try to reclaim control using a token persisted
 *     in localStorage from an earlier visit (projection.tryReclaim) — if
 *     that succeeds a dismissible "reconnected" banner appears.
 *   • If neither applies, we connect as a read-only display. An
 *     owner/admin viewing a LIVE session this way sees a "Take control"
 *     button; using it rotates the control token and force-demotes the
 *     previous controller (which sees a "taken over" banner in real time
 *     via the control-transferred socket event).
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import AppShell from '@/components/AppShell.vue'
import Icon from '@/components/Icon.vue'
import SlideRenderer from '@/components/SlideRenderer.vue'
import Toast from '@/components/Toast.vue'
import { useProjectionStore } from '@/stores/projection'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const projection = useProjectionStore()
const auth = useAuthStore()
const { t } = useI18n()

const sessionId = computed(() => route.params.id as string)
const error = ref<string | null>(null)
const isFullscreen = ref(false)
const reclaimedNotice = ref(false)

const canTakeover = computed(
  () =>
    projection.role !== 'controller' &&
    projection.state?.status === 'LIVE' &&
    (auth.isAdmin || projection.state?.ownerId === auth.user?.id),
)

const displayUrl = computed(() => {
  if (!sessionId.value) return ''
  // We mint a fully-qualified URL so the worship leader can paste it
  // into a second browser / projector without thinking about origins.
  const path = router.resolve({ name: 'projection-display', params: { id: sessionId.value } }).href
  return `${window.location.origin}${path}`
})

async function copyDisplayUrl(): Promise<void> {
  try {
    await navigator.clipboard.writeText(displayUrl.value)
  } catch {
    error.value = t('projectionControl.errors.copy')
  }
}

// ── Fullscreen ──────────────────────────────────────────────────────

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

function onFullscreenChange(): void {
  isFullscreen.value = !!document.fullscreenElement
}

// ── Keyboard shortcuts ──────────────────────────────────────────────

function onKey(e: KeyboardEvent): void {
  if (projection.role !== 'controller') return
  // Don't hijack keys when the user is typing in an input.
  const target = e.target as HTMLElement | null
  if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) return

  switch (e.key) {
    case 'ArrowRight':
    case ' ':
    case 'PageDown':
      e.preventDefault()
      projection.next()
      break
    case 'ArrowLeft':
    case 'PageUp':
      e.preventDefault()
      projection.previous()
      break
    case 'b':
    case 'B':
      e.preventDefault()
      projection.setBlackout(!(projection.state?.blackout ?? false))
      break
    case 'f':
    case 'F':
      e.preventDefault()
      void toggleFullscreen()
      break
    case '+':
    case '=':
      e.preventDefault()
      projection.setFontScale(Math.min(3, (projection.state?.fontScale ?? 1) + 0.1))
      break
    case '-':
    case '_':
      e.preventDefault()
      projection.setFontScale(Math.max(0.5, (projection.state?.fontScale ?? 1) - 0.1))
      break
  }
}

// ── Lifecycle ───────────────────────────────────────────────────────

onMounted(async () => {
  document.addEventListener('fullscreenchange', onFullscreenChange)
  window.addEventListener('keydown', onKey)

  // Reuse the existing connection if we already joined as controller for
  // this session (e.g. created via Go Live and routed here).
  const alreadyConnected =
    projection.role === 'controller' &&
    projection.sessionId === sessionId.value &&
    projection.state?.id === sessionId.value
  if (!alreadyConnected) {
    const { reclaimed } = await projection.tryReclaim(sessionId.value)
    if (reclaimed) {
      reclaimedNotice.value = true
    } else {
      const result = await projection.connect({
        sessionId: sessionId.value,
        controlToken: projection.controlToken ?? undefined,
      })
      if (!result.ok) {
        error.value = result.error || t('projectionControl.errors.connect')
      }
    }
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('fullscreenchange', onFullscreenChange)
  window.removeEventListener('keydown', onKey)
  // Don't disconnect — the leader may navigate to a different tab and
  // come back. Disconnect happens on End Session.
})

async function endSession(): Promise<void> {
  if (!confirm(t('projectionControl.confirmEnd'))) return
  try {
    await projection.destroy()
    await router.push('/sessions')
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('projectionControl.errors.end')
  }
}

async function takeoverSession(): Promise<void> {
  if (!confirm(t('projectionControl.confirmTakeover'))) return
  try {
    await projection.takeover(sessionId.value)
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('projectionControl.errors.takeover')
  }
}
</script>

<template>
  <AppShell>
    <div class="flex flex-col h-full min-h-0">
      <!-- ── Header ──────────────────────────────────────────────── -->
      <div class="flex flex-wrap items-center gap-3 px-6 py-3 border-b border-border">
        <Icon name="cast" />
        <div class="flex-1 min-w-[280px]">
          <div class="text-[18px] font-semibold leading-tight" data-testid="ctrl-playlist-name">
            {{ projection.state?.playlistName ?? t('projectionControl.fallbackTitle') }}
          </div>
          <div class="text-[11px] text-text-faint mono uppercase tracking-[0.12em]">
            {{ projection.role === 'controller' ? t('projectionControl.controller') : t('projectionControl.viewOnly') }} ·
            {{ t(`projectionControl.status.${projection.status}`) }}
          </div>
        </div>
        <div class="flex items-center gap-2">
          <input
            class="input mono"
            style="width: 320px; font-size: 11px"
            readonly
            :value="displayUrl"
            data-testid="projection-display-url"
          />
          <button type="button" class="btn" data-testid="ctrl-copy-url" @click="copyDisplayUrl">
            {{ t('projectionControl.copyUrl') }}
          </button>
          <a
            :href="displayUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="btn btn-primary"
            data-testid="ctrl-open-display"
          >
            <Icon name="cast" /> {{ t('projectionControl.openDisplay') }}
          </a>
          <button
            v-if="canTakeover"
            type="button"
            class="btn btn-danger"
            data-testid="projection-takeover"
            @click="takeoverSession"
          >
            {{ t('projectionControl.takeControl') }}
          </button>
          <button
            v-if="projection.role === 'controller'"
            type="button"
            class="btn btn-danger"
            data-testid="projection-end"
            @click="endSession"
          >
            {{ t('projectionControl.endSession') }}
          </button>
        </div>
      </div>

      <!-- ── Banners (mutually exclusive, most-specific first) ─────── -->
      <div
        v-if="projection.takenOver"
        class="px-6 py-2 bg-bg-sunken text-[12px] text-danger border-b border-border flex items-center justify-between"
        data-testid="ctrl-takenover-banner"
      >
        <span>{{ t('projectionControl.takenOverNotice') }}</span>
        <button type="button" class="text-text-faint hover:text-text-muted" @click="projection.dismissTakenOver()">✕</button>
      </div>
      <div
        v-else-if="reclaimedNotice"
        class="px-6 py-2 bg-accent-soft text-[12px] text-accent border-b border-border flex items-center justify-between"
        data-testid="ctrl-reclaimed-banner"
      >
        <span>{{ t('projectionControl.reclaimedBanner') }}</span>
        <button type="button" class="text-text-faint hover:text-text-muted" @click="reclaimedNotice = false">✕</button>
      </div>
      <div
        v-else-if="projection.role !== 'controller'"
        class="px-6 py-2 bg-bg-sunken text-[12px] text-text-muted border-b border-border"
        data-testid="ctrl-viewonly-banner"
      >
        {{ t('projectionControl.viewOnlyBanner') }}
      </div>

      <!-- ── Body ─────────────────────────────────────────────────── -->
      <div class="flex-1 min-h-0 grid grid-cols-[280px_1fr] overflow-hidden">

        <!-- Service order / jump-to-song (light theme) -->
        <aside class="flex flex-col gap-1 p-3 overflow-y-auto border-r border-border">
          <div class="mono uppercase tracking-[0.14em] text-[10px] text-text-faint px-2 pt-1 pb-2">
            {{ t('projectionControl.serviceOrder') }}
          </div>
          <button
            v-for="g in projection.itemGroups"
            :key="g.itemIndex"
            type="button"
            class="text-left px-3 py-2 rounded text-[13px] flex items-center gap-2 transition-colors"
            :class="
              projection.currentSlide?.itemIndex === g.itemIndex
                ? 'bg-accent-soft text-accent font-semibold'
                : 'hover:bg-bg-sunken text-text-muted'
            "
            :disabled="projection.role !== 'controller'"
            data-testid="jump-item"
            @click="projection.jumpToItem(g.itemIndex)"
          >
            <span class="mono text-[10px] opacity-60 w-5 text-right">{{ g.itemIndex + 1 }}</span>
            <span class="truncate">{{ g.songTitle }}</span>
          </button>
        </aside>

        <!-- Stage area — dark token scope for cinema-style feel -->
        <section class="flex flex-col min-h-0 gap-3 p-4 overflow-hidden dark bg-bg-sunken">
          <!-- Current + next + shortcuts row -->
          <div class="flex flex-1 min-h-0 gap-3">
            <!-- Current slide -->
            <div class="flex-1 min-h-0 overflow-hidden card" data-testid="current-slide">
              <SlideRenderer
                :slide="projection.currentSlide"
                :blackout="projection.state?.blackout ?? false"
                :font-scale="projection.state?.fontScale ?? 1"
                variant="preview"
              />
            </div>

            <!-- Next slide + shortcuts -->
            <div class="w-[300px] flex flex-col gap-3">
              <div class="card overflow-hidden h-[170px]" data-testid="next-slide">
                <SlideRenderer :slide="projection.nextSlide" variant="thumb" />
              </div>
              <!-- Keyboard shortcuts reference card -->
              <div class="card p-3 text-[11px] text-text-muted leading-relaxed">
                <div class="mono uppercase tracking-[0.14em] text-text-faint mb-2">{{ t('projectionControl.shortcuts.title') }}</div>
                <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                  <span><kbd>→</kbd> <kbd>Space</kbd> <kbd>PgDn</kbd></span>
                  <span>{{ t('projectionControl.shortcuts.next') }}</span>
                  <span><kbd>←</kbd> <kbd>PgUp</kbd></span>
                  <span>{{ t('projectionControl.shortcuts.prev') }}</span>
                  <span><kbd>B</kbd></span>
                  <span>{{ t('projectionControl.shortcuts.blackout') }}</span>
                  <span><kbd>F</kbd></span>
                  <span>{{ t('projectionControl.shortcuts.fullscreen') }}</span>
                  <span><kbd>+</kbd> <kbd>-</kbd></span>
                  <span>{{ t('projectionControl.shortcuts.font') }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Toolbar -->
          <div class="flex flex-wrap items-center gap-3 p-3 card">
            <button
              type="button"
              class="btn"
              :disabled="projection.role !== 'controller'"
              data-testid="ctrl-prev"
              @click="projection.previous()"
            >
              <Icon name="arrow-left" /> {{ t('projectionControl.prev') }}
            </button>
            <button
              type="button"
              class="btn btn-primary"
              :disabled="projection.role !== 'controller'"
              data-testid="ctrl-next"
              @click="projection.next()"
            >
              {{ t('projectionControl.next') }} <Icon name="arrow-right" />
            </button>
            <div
              class="flex items-center gap-1 text-[12px] text-text-faint mono"
              data-testid="ctrl-slide-count"
            >
              {{ (projection.state?.currentIndex ?? 0) + 1 }} /
              {{ projection.state?.slides.length ?? 0 }}
            </div>

            <div class="flex-1" />

            <!-- Blackout toggle -->
            <button
              type="button"
              class="btn"
              :class="projection.state?.blackout ? 'btn-primary' : ''"
              :disabled="projection.role !== 'controller'"
              data-testid="ctrl-blackout"
              @click="projection.setBlackout(!(projection.state?.blackout ?? false))"
            >
              {{ projection.state?.blackout ? t('projectionControl.blackoutOn') : t('projectionControl.blackout') }}
            </button>

            <!-- Font scale -->
            <div class="flex items-center gap-1">
              <button
                type="button"
                class="btn"
                :disabled="projection.role !== 'controller'"
                data-testid="ctrl-font-down"
                @click="projection.setFontScale(Math.max(0.5, (projection.state?.fontScale ?? 1) - 0.1))"
              >
                A−
              </button>
              <span
                class="text-[11px] text-text-faint mono w-10 text-center"
                data-testid="ctrl-font-pct"
              >
                {{ ((projection.state?.fontScale ?? 1) * 100).toFixed(0) }}%
              </span>
              <button
                type="button"
                class="btn"
                :disabled="projection.role !== 'controller'"
                data-testid="ctrl-font-up"
                @click="projection.setFontScale(Math.min(3, (projection.state?.fontScale ?? 1) + 0.1))"
              >
                A+
              </button>
            </div>

            <!-- Fullscreen toggle -->
            <button
              type="button"
              class="btn"
              :title="isFullscreen ? t('projectionControl.exitFullscreenTip') : t('projectionControl.fullscreenTip')"
              data-testid="ctrl-fullscreen"
              @click="toggleFullscreen"
            >
              {{ isFullscreen ? t('projectionControl.exitFullscreen') : t('projectionControl.fullscreen') }}
            </button>
          </div>
        </section>
      </div>
    </div>

    <Toast v-if="error" :message="error" kind="error" @close="error = null" />
  </AppShell>
</template>

<style scoped>
kbd {
  display: inline-block;
  padding: 1px 5px;
  border: 1px solid var(--color-border, var(--border));
  border-bottom-width: 2px;
  border-radius: 3px;
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
  font-size: 10px;
  background: var(--bg-raised);
  color: var(--text-muted);
}
</style>
