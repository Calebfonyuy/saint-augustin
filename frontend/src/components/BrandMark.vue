<script setup lang="ts">
// Direction-A brand mark: serif S monogram inside a thin ring, paired with the
// wordmark "STAUG". Ported from saint-augustin-design/components/shared.jsx.
defineProps<{ size?: number }>()
</script>

<template>
  <span class="inline-flex items-center gap-[10px] text-text">
    <svg :width="size ?? 20" :height="size ?? 20" viewBox="0 0 24 24" aria-hidden="true">
      <circle cx="12" cy="12" r="10.5" fill="none" stroke="currentColor" stroke-width="1.2" />
      <text
        x="12"
        y="16.5"
        text-anchor="middle"
        font-family="Crimson Pro, serif"
        font-weight="600"
        font-size="13"
        fill="currentColor"
      >
        S
      </text>
    </svg>
    <span class="font-display font-semibold text-[15px] tracking-[0.14em]">STAUG</span>
  </span>
</template>
