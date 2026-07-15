<script setup lang="ts">
// Pure presentational component for projection slides.
//
// Used by both the controller (for current/next preview tiles) and the
// display view. Letting one component own slide layout means a fix to
// typography or spacing is fixed in both places at once.
//
// `variant` adjusts size + padding without changing the layout. `display`
// is the full-screen projector style; `preview` is the controller's
// current pane; `thumb` is the smaller next-up preview.
//
// Transitions: the inner content cross-fades on every slide change
// (keyed by slide.id) with a 150ms fade — well within the <200ms NFR.
// The outer container transitions background-color separately so colour
// changes (blackout, theme switch) also animate smoothly.
//
// Background images: pass `backgroundImage` as a URL string. The image
// is rendered as a CSS background covering the full slide area. A solid
// `background` colour still acts as a fallback/overlay base.
//
// Text alignment: `textAlign` controls horizontal placement of the lyrics.
// Defaults to 'center' (appropriate for most congregation-facing projection).
// 'left' is useful for long readings or song sheets where left-aligned
// text is easier to follow.
//
// Font family: `fontFamily` accepts any CSS font-family string. Defaults
// to the project's --font-display token (Crimson Pro) which is designed
// for worship projection — high legibility at large sizes, warm serif feel.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { ProjectionSlide } from '@/types'

const props = withDefaults(
  defineProps<{
    slide: ProjectionSlide | null
    blackout?: boolean
    fontScale?: number
    variant?: 'display' | 'preview' | 'thumb'
    background?: string
    backgroundImage?: string
    color?: string
    textAlign?: 'left' | 'center' | 'right'
    fontFamily?: string
  }>(),
  {
    blackout: false,
    fontScale: 1,
    variant: 'preview',
    background: '#000000',
    backgroundImage: undefined,
    color: '#ffffff',
    textAlign: 'center',
    fontFamily: undefined,
  },
)

/**
 * Base font size in px before the user's fontScale is applied. Display
 * is sized for visibility from the back of a sanctuary; previews shrink
 * to fit the controller pane.
 */
const baseSize = computed(() => {
  switch (props.variant) {
    case 'display':
      return 56
    case 'preview':
      return 32
    case 'thumb':
      return 18
  }
  return 24
})

const computedFontSize = computed(() => `${baseSize.value * props.fontScale}px`)

const lines = computed(() => (props.slide?.body ?? '').split('\n'))

/**
 * Outer container style. When a background image is provided it is
 * rendered with `background-size: cover` so it fills the slide area at
 * any aspect ratio. The solid `background` colour acts as a fallback
 * (shown while the image loads and in any letterbox areas).
 */
const containerStyle = computed(() => {
  const style: Record<string, string> = {
    backgroundColor: props.blackout ? '#000' : props.background,
    color: props.color,
  }
  if (!props.blackout && props.backgroundImage) {
    style.backgroundImage = `url(${props.backgroundImage})`
    style.backgroundSize = 'cover'
    style.backgroundPosition = 'center'
    style.backgroundRepeat = 'no-repeat'
  }
  return style
})

/**
 * Resolved font family. Falls back through the design system's display
 * token (Crimson Pro) → generic serif. Callers can override per-display
 * via the fontFamily prop to use sans or mono faces.
 */
const resolvedFont = computed(
  () => props.fontFamily ?? "var(--font-display, 'Crimson Pro', Georgia, serif)",
)

/**
 * Inner content style — applies typography choices that should only
 * affect the slide text, not the outer container (which owns bg/color).
 */
const innerStyle = computed(() => ({
  fontFamily: resolvedFont.value,
  textAlign: props.textAlign,
}))

/**
 * Key used to drive the content cross-fade transition. Changes on every
 * new slide (including the null → slide transition so the empty state
 * also fades in correctly).
 */
const transitionKey = computed(() =>
  props.blackout ? '__blackout__' : (props.slide?.id ?? '__empty__'),
)

/** Scripture slides carry structured verses (FR-BI-8) and render differently. */
const isScripture = computed(
  () => props.slide?.kind === 'scripture' && (props.slide?.verses?.length ?? 0) > 0,
)

// ── Auto-fit for scripture (NFR-USE-1) ────────────────────────────────
// A reading slide can hold several verses; shrink the font until the text
// fits its container rather than overflowing. One-pass ratio estimate, then
// re-run on slide change and container resize.
const containerRef = ref<HTMLElement | null>(null)
const scriptureBodyRef = ref<HTMLElement | null>(null)
const fitScale = ref(1)

const scriptureFontSize = computed(
  () => `${baseSize.value * props.fontScale * fitScale.value}px`,
)

function refit(): void {
  if (!isScripture.value) {
    fitScale.value = 1
    return
  }
  fitScale.value = 1
  void nextTick(() => {
    const container = containerRef.value
    const body = scriptureBodyRef.value
    if (!container || !body) return
    // Leave headroom for the reference heading / footer / padding.
    const available = container.clientHeight * 0.82
    const needed = body.scrollHeight
    if (needed > available && needed > 0) {
      fitScale.value = Math.max(0.5, (available / needed) * 0.98)
    }
  })
}

let observer: ResizeObserver | null = null

onMounted(() => {
  if (typeof ResizeObserver !== 'undefined' && containerRef.value) {
    observer = new ResizeObserver(() => refit())
    observer.observe(containerRef.value)
  }
  refit()
})

onBeforeUnmount(() => {
  observer?.disconnect()
  observer = null
})

watch(() => [props.slide?.id, props.fontScale, props.blackout], refit)
</script>

<template>
  <div
    ref="containerRef"
    class="slide-renderer"
    :class="[`slide-${variant}`]"
    :style="containerStyle"
  >
    <Transition name="slide-fade">
      <div :key="transitionKey" class="slide-inner" :style="innerStyle">
        <template v-if="!blackout && slide">
          <!-- Scripture reading (FR-BI-8): reference heading on the first
               slide, verses with small superscript numbers, auto-fit. -->
          <template v-if="isScripture">
            <div v-if="slide.showReference && slide.reference" class="slide-reference">
              {{ slide.reference }}
            </div>
            <div ref="scriptureBodyRef" class="slide-scripture" :style="{ fontSize: scriptureFontSize }">
              <span v-for="v in slide.verses" :key="v.number" class="slide-verse">
                <sup class="slide-verse-num">{{ v.number }}</sup>{{ v.text }}
              </span>
            </div>
            <div v-if="variant !== 'thumb'" class="slide-footer">
              {{ slide.songTitle }}
            </div>
          </template>

          <template v-else>
            <div v-if="slide.section" class="slide-section">
              {{ slide.section }}
            </div>
            <div class="slide-body" :style="{ fontSize: computedFontSize }">
              <div v-for="(line, i) in lines" :key="i" class="slide-line">
                {{ line || '\u00a0' }}
              </div>
            </div>
            <div v-if="variant !== 'thumb'" class="slide-footer">
              {{ slide.songTitle }}
            </div>
          </template>
        </template>
        <template v-else-if="blackout">
          <!-- intentionally blank for the projector; preview shows a hint -->
          <div v-if="variant !== 'display'" class="slide-blackout-hint">— blackout —</div>
        </template>
        <template v-else>
          <div class="slide-empty">No slide</div>
        </template>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.slide-renderer {
  position: relative;
  width: 100%;
  height: 100%;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: stretch;
  transition: background-color 200ms ease, color 200ms ease;
  line-height: 1.35;
}
.slide-display {
  padding: 6vh 6vw;
}
.slide-preview {
  padding: 24px;
  border-radius: 8px;
}
.slide-thumb {
  padding: 12px;
  border-radius: 6px;
}

/* The inner wrapper fills the renderer and centres content; it is the
   element that cross-fades when the slide key changes. `position:
   absolute` during the transition prevents layout reflow as the leaving
   element is removed. */
.slide-inner {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: stretch;
  padding: inherit;
}

/* Cross-fade transition — 150ms total, comfortably under the 200ms NFR. */
.slide-fade-enter-active,
.slide-fade-leave-active {
  transition: opacity 150ms ease;
}
.slide-fade-enter-from,
.slide-fade-leave-to {
  opacity: 0;
}

.slide-section {
  position: absolute;
  top: 16px;
  left: 24px;
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
  text-transform: uppercase;
  letter-spacing: 0.18em;
  opacity: 0.55;
  font-size: 0.625em;
}
.slide-display .slide-section {
  top: 4vh;
  left: 4vw;
  font-size: 18px;
}
.slide-body {
  white-space: pre-wrap;
  max-width: 100%;
  word-break: break-word;
}
.slide-line {
  min-height: 1em;
}
.slide-footer {
  position: absolute;
  bottom: 12px;
  right: 18px;
  font-size: 11px;
  opacity: 0.45;
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
  letter-spacing: 0.05em;
}
.slide-display .slide-footer {
  bottom: 4vh;
  right: 4vw;
  font-size: 16px;
}
.slide-blackout-hint,
.slide-empty {
  text-align: center;
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
  font-size: 11px;
  opacity: 0.5;
  text-transform: uppercase;
  letter-spacing: 0.18em;
}

/* Scripture reading (FR-BI-8). */
.slide-reference {
  font-weight: 600;
  opacity: 0.85;
  text-align: center;
  margin-bottom: 0.4em;
  font-size: 20px;
}
.slide-display .slide-reference {
  font-size: 30px;
  margin-bottom: 3vh;
}
.slide-thumb .slide-reference {
  display: none;
}
.slide-scripture {
  white-space: normal;
  max-width: 100%;
  word-break: break-word;
  overflow: hidden;
}
.slide-verse {
  /* verses flow as continuous prose within the slide */
}
.slide-verse-num {
  font-size: 0.5em;
  line-height: 0;
  opacity: 0.5;
  margin-right: 0.15em;
  vertical-align: super;
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
}
</style>
