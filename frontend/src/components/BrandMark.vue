<script setup lang="ts">
defineProps<{ size?: number }>()
</script>

<template>
  <span class="inline-flex items-center gap-[10px] text-text">
    <img
      src="/logo-96.png"
      :width="size ?? 20"
      :height="size ?? 20"
      alt=""
      class="rounded-full shrink-0"
    />
    <span class="font-display font-semibold text-[15px] tracking-[0.14em]">STAUG</span>
  </span>
</template>
