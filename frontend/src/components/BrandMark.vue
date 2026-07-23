<script setup lang="ts">
defineProps<{ size?: number; path?: string }>()
</script>

<template>
  <span class="inline-flex items-center gap-[10px] text-text">
    <img
      :src="path ?? '/logo-128.png'"
      :width="size ?? 40"
      :height="size ?? 40"
      alt=""
      class="rounded-full shrink-0"
    />
    <span class="font-display font-semibold text-[15px] tracking-[0.14em]">STAUG</span>
  </span>
</template>
