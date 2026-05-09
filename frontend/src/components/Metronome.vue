<script setup lang="ts">
/*
 * Web Audio metronome — visual pulse + audible click.
 *
 * Implementation notes:
 *   • One short-lived OscillatorNode per click (cheap; avoids the noise of
 *     gating a long-running oscillator). Beat 1 is louder + a higher pitch
 *     so the listener can feel the bar boundary.
 *   • The scheduler uses `setInterval` on the main thread. That's fine for
 *     a worship-rehearsal use case (60–180 BPM); we don't need the AudioWorklet
 *     scheduler precision used for click tracks in DAWs.
 *   • The AudioContext is created lazily on first Start because most browsers
 *     block AudioContext creation until a user gesture has occurred.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import Icon from './Icon.vue'

const props = defineProps<{
  /** Target BPM. May be null (e.g. song has no tempo) — we fall back to 90. */
  tempo: number | null | undefined
  /** "4/4", "3/4", "6/8" — the numerator drives the bar length and accent. */
  timeSignature: string | null | undefined
}>()

// User-controlled override BPM. Initialised from `tempo` and stays in sync
// only while the user hasn't manually edited it.
const overrideBpm = ref<number | null>(null)
const userTouched = ref(false)
watch(
  () => props.tempo,
  (next) => {
    if (!userTouched.value) overrideBpm.value = next ?? 90
  },
  { immediate: true },
)

const effectiveBpm = computed(() => clamp(overrideBpm.value ?? props.tempo ?? 90, 30, 240))

const beatsPerBar = computed(() => {
  const sig = props.timeSignature ?? '4/4'
  const n = Number(sig.split('/')[0])
  return Number.isFinite(n) && n > 0 ? n : 4
})

const isRunning = ref(false)
const currentBeat = ref(0) // 0-indexed; -1 means idle.

let audioCtx: AudioContext | null = null
let intervalId: ReturnType<typeof setInterval> | null = null

function clamp(v: number, lo: number, hi: number): number {
  return Math.max(lo, Math.min(hi, v))
}

function ensureAudioContext(): AudioContext {
  // SSR / older browsers: provide a no-op fallback so we don't crash.
  // (We never actually run in SSR here, but tests do mount the component.)
  const Ctor = (window.AudioContext || (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext)
  if (!Ctor) throw new Error('Web Audio is not supported in this browser.')
  if (!audioCtx) audioCtx = new Ctor()
  // Resume in case the browser auto-suspended after a previous stop.
  if (audioCtx.state === 'suspended') void audioCtx.resume()
  return audioCtx
}

function click(accent: boolean): void {
  let ctx: AudioContext
  try {
    ctx = ensureAudioContext()
  } catch {
    return // audible click unavailable; visual still pulses below.
  }
  const osc = ctx.createOscillator()
  const gain = ctx.createGain()
  osc.frequency.value = accent ? 1600 : 1100
  // Quick attack-decay envelope. The whole click is ~50ms.
  const t = ctx.currentTime
  gain.gain.setValueAtTime(0, t)
  gain.gain.linearRampToValueAtTime(accent ? 0.4 : 0.25, t + 0.002)
  gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.05)
  osc.connect(gain).connect(ctx.destination)
  osc.start(t)
  osc.stop(t + 0.06)
}

function tick(): void {
  // Advance the visual indicator first so the pulse animation lines up with
  // the click rather than lagging it by one frame.
  currentBeat.value = (currentBeat.value + 1) % beatsPerBar.value
  click(currentBeat.value === 0)
}

function start(): void {
  if (isRunning.value) return
  isRunning.value = true
  currentBeat.value = -1 // -1 so the first tick lands on beat 0.
  // Fire one immediately so the user hears the start, then schedule the rest.
  tick()
  intervalId = setInterval(tick, (60 / effectiveBpm.value) * 1000)
}

function stop(): void {
  if (intervalId) {
    clearInterval(intervalId)
    intervalId = null
  }
  isRunning.value = false
  currentBeat.value = -1
}

function toggle(): void {
  if (isRunning.value) { stop() } else { start() }
}

// If the user changes BPM mid-run, restart the interval at the new rate.
watch(effectiveBpm, () => {
  if (isRunning.value) {
    if (intervalId) clearInterval(intervalId)
    intervalId = setInterval(tick, (60 / effectiveBpm.value) * 1000)
  }
})

function bumpBpm(delta: number): void {
  userTouched.value = true
  overrideBpm.value = clamp((overrideBpm.value ?? effectiveBpm.value) + delta, 30, 240)
}

function onBpmInput(e: Event): void {
  const v = Number((e.target as HTMLInputElement).value)
  if (!Number.isFinite(v)) return
  userTouched.value = true
  overrideBpm.value = clamp(v, 30, 240)
}

onBeforeUnmount(() => {
  stop()
  // Don't close the audio context — Chrome treats that as user-perceptible
  // output severance and warns. Garbage collection handles cleanup.
})

defineExpose({ start, stop, toggle, isRunning })
</script>

<template>
  <div class="card p-4 flex flex-col gap-3" data-testid="metronome">
    <div class="flex items-center justify-between">
      <div class="mono uppercase tracking-[0.14em] text-[10.5px] text-text-faint">Metronome</div>
      <div class="text-[11px] text-text-faint">{{ timeSignature ?? '4/4' }}</div>
    </div>

    <!-- Beat dots — pulse the active beat; beat 1 is bigger/accented -->
    <div class="flex items-center gap-[6px]" data-testid="metronome-beats">
      <span
        v-for="i in beatsPerBar"
        :key="i"
        class="rounded-full transition-all duration-100"
        :class="[
          i === 1 ? 'w-3 h-3' : 'w-2.5 h-2.5',
          currentBeat === i - 1 ? 'bg-accent' : 'bg-border',
        ]"
      />
    </div>

    <div class="flex items-center gap-2">
      <button
        type="button"
        class="btn btn-secondary"
        style="padding: 6px 10px"
        data-testid="metronome-bump-down"
        @click="bumpBpm(-2)"
      >
        −
      </button>
      <input
        :value="effectiveBpm"
        type="number"
        min="30"
        max="240"
        class="input text-center"
        style="width: 72px; padding: 4px 6px"
        data-testid="metronome-bpm"
        @input="onBpmInput"
      />
      <span class="text-[11px] text-text-faint">BPM</span>
      <button
        type="button"
        class="btn btn-secondary"
        style="padding: 6px 10px"
        data-testid="metronome-bump-up"
        @click="bumpBpm(2)"
      >
        +
      </button>
      <div class="flex-1" />
      <button
        type="button"
        class="btn"
        :class="isRunning ? 'btn-danger' : 'btn-primary'"
        style="padding: 6px 12px"
        data-testid="metronome-toggle"
        @click="toggle"
      >
        <Icon :name="isRunning ? 'x' : 'check'" />
        {{ isRunning ? 'Stop' : 'Start' }}
      </button>
    </div>
  </div>
</template>
