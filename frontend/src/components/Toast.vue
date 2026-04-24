<script setup lang="ts">
// Tiny toast used to surface API errors and success messages.
// Stays deliberately simple — a single transient toast at a time, dismissed
// after a timeout or on click. Larger UX polish (queues, positions) can wait.
import { onMounted, onBeforeUnmount } from 'vue'

const props = defineProps<{
  message: string
  kind?: 'error' | 'success' | 'info'
  timeoutMs?: number
}>()

const emit = defineEmits<(e: 'close') => void>()

let timer: ReturnType<typeof setTimeout> | null = null

onMounted(() => {
  const ms = props.timeoutMs ?? 4000
  if (ms > 0) timer = setTimeout(() => emit('close'), ms)
})

onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
})
</script>

<template>
  <div
    class="toast"
    :class="{ 'toast-error': kind === 'error', 'toast-success': kind === 'success' }"
    role="status"
    :aria-live="kind === 'error' ? 'assertive' : 'polite'"
    @click="emit('close')"
  >
    {{ message }}
  </div>
</template>
