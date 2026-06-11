<script setup lang="ts">
// Renders the given URL/text as a QR code into a <canvas>. Re-renders on
// every value change. Used in the session share dialog so worshippers can
// scan to join a session from their phone without typing the URL.
//
// Ref: https://github.com/soldair/node-qrcode
import { onMounted, ref, watch } from 'vue'
import QRCode from 'qrcode'

const props = withDefaults(
  defineProps<{
    value: string
    /** Pixel size of the rendered canvas (square). */
    size?: number
    /** Error-correction level; M is the library default and works well for URLs. */
    level?: 'L' | 'M' | 'Q' | 'H'
  }>(),
  { size: 220, level: 'M' },
)

const canvasRef = ref<HTMLCanvasElement | null>(null)
const error = ref<string | null>(null)

async function render(): Promise<void> {
  if (!canvasRef.value || !props.value) return
  error.value = null
  try {
    await QRCode.toCanvas(canvasRef.value, props.value, {
      width: props.size,
      margin: 1,
      errorCorrectionLevel: props.level,
    })
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to render QR code.'
  }
}

onMounted(render)
watch(() => [props.value, props.size, props.level], render)
</script>

<template>
  <div class="inline-flex flex-col items-center gap-1">
    <canvas ref="canvasRef" :width="size" :height="size" data-testid="qr-canvas" />
    <p v-if="error" class="text-[11px] text-text-faint">{{ error }}</p>
  </div>
</template>
