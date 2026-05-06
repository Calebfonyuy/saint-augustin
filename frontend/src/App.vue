<script setup lang="ts">
// Root component. Views wrap themselves in the AppShell when appropriate, so
// the root is just the router outlet plus the full-viewport surface.
// Height comes from the `html/body/#app` chain in main.css (100dvh) — using
// `h-full w-full` here lets that cascade through to AppShell without the
// `min-h-screen` underflow that was leaving a gap on short pages.
// Auth hydration happens in main.ts before mount, so there's no init() here.
</script>

<template>
  <div class="h-full w-full bg-bg text-text">
    <router-view />
  </div>
</template>
