<script setup lang="ts">
// Root component. Views wrap themselves in the AppShell when appropriate, so
// the root is just the router outlet plus a full-viewport background.
// Auth hydration happens in main.ts before mount, so there's no init() here.
</script>

<template>
  <div class="min-h-screen w-screen bg-bg text-text">
    <router-view />
  </div>
</template>
