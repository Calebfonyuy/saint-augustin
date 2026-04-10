<script setup lang="ts">
// Root component – navigation shell will be added in Phase 1
</script>

<template>
  <div class="min-h-screen">
    <header class="bg-primary-800 text-white px-6 py-4 shadow-md">
      <div class="max-w-7xl mx-auto flex items-center justify-between">
        <div>
          <h1 class="text-xl font-bold tracking-tight">SaintAugustin</h1>
          <p class="text-xs text-primary-200 italic">Qui cantat, bis orat</p>
        </div>
        <nav class="text-sm text-primary-200">
          <!-- Navigation links added in Phase 1 -->
        </nav>
      </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8">
      <router-view />
    </main>
  </div>
</template>
