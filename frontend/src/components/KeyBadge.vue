<script setup lang="ts">
// Terracotta pill showing a musical key (C, F#, Am, Eb, …).
// Phase 2 will reuse this for the per-song key override in the playlist
// builder; keeping it as its own component now simplifies that.
defineProps<{ musicalKey: string | null | undefined }>()
</script>

<template>
  <span v-if="musicalKey" class="key-badge">{{ musicalKey }}</span>
</template>
