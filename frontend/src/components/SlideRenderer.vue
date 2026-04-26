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
import { computed } from 'vue'
import type { ProjectionSlide } from '@/types'

const props = withDefaults(
  defineProps<{
    slide: ProjectionSlide | null
    blackout?: boolean
    fontScale?: number
    variant?: 'display' | 'preview' | 'thumb'
    background?: string
    color?: string
  }>(),
  {
    blackout: false,
    fontScale: 1,
    variant: 'preview',
    background: '#000000',
    color: '#ffffff',
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
      return 64
    case 'preview':
      return 32
    case 'thumb':
      return 18
  }
  return 24
})

const computedFontSize = computed(() => `${baseSize.value * props.fontScale}px`)

const lines = computed(() => (props.slide?.body ?? '').split('\n'))
</script>

<template>
  <div
    class="slide-renderer"
    :class="[`slide-${variant}`]"
    :style="{ backgroundColor: blackout ? '#000' : background, color }"
  >
    <template v-if="!blackout && slide">
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
    <template v-else-if="blackout">
      <!-- intentionally blank for the projector; preview shows a hint -->
      <div v-if="variant !== 'display'" class="slide-blackout-hint">— blackout —</div>
    </template>
    <template v-else>
      <div class="slide-empty">No slide</div>
    </template>
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
  align-items: center;
  text-align: center;
  transition: background-color 200ms ease, color 200ms ease;
  font-family: 'Georgia', 'Times New Roman', serif;
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
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
  font-size: 11px;
  opacity: 0.5;
  text-transform: uppercase;
  letter-spacing: 0.18em;
}
</style>
