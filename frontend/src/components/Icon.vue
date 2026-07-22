<script setup lang="ts">
// Minimal icon set used across STAUG (Phase 1 subset).
// Named stroke icons (1.5 width) matching the prototype's visual weight.
// Add new variants as they are needed rather than pulling in a full icon lib.
defineProps<{
  name:
    | 'search'
    | 'plus'
    | 'music'
    | 'list'
    | 'cast'
    | 'user'
    | 'cog'
    | 'chev'
    | 'dots'
    | 'upload'
    | 'eye'
    | 'x'
    | 'check'
    | 'trash'
    | 'logout'
    | 'arrow-left'
    | 'arrow-right'
    | 'chevrons-left'
    | 'chevrons-right'
    | 'menu'
  size?: number
}>()
</script>

<template>
  <svg
    :width="size ?? 14"
    :height="size ?? 14"
    viewBox="0 0 16 16"
    fill="none"
    stroke="currentColor"
    stroke-width="1.5"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <template v-if="name === 'search'">
      <circle cx="7" cy="7" r="5" />
      <path d="M11 11l3 3" />
    </template>
    <template v-else-if="name === 'plus'">
      <path d="M8 3v10M3 8h10" />
    </template>
    <template v-else-if="name === 'music'">
      <path d="M6 13V3l7-1v10" />
      <circle cx="4.5" cy="13" r="1.5" />
      <circle cx="11.5" cy="12" r="1.5" />
    </template>
    <template v-else-if="name === 'list'">
      <path d="M5 4h9M5 8h9M5 12h9M2 4h.01M2 8h.01M2 12h.01" />
    </template>
    <template v-else-if="name === 'cast'">
      <path d="M2 6V4a1 1 0 011-1h10a1 1 0 011 1v8a1 1 0 01-1 1H9" />
      <path d="M2 9a5 5 0 015 5M2 12a2 2 0 012 2M2 14h.01" />
    </template>
    <template v-else-if="name === 'user'">
      <circle cx="8" cy="5.5" r="2.5" />
      <path d="M3 14c0-2.5 2-4.5 5-4.5s5 2 5 4.5" />
    </template>
    <template v-else-if="name === 'cog'">
      <circle cx="8" cy="8" r="2.2" />
      <path d="M8 1.5v2M8 12.5v2M1.5 8h2M12.5 8h2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M3.4 12.6l1.4-1.4M11.2 4.8l1.4-1.4" />
    </template>
    <template v-else-if="name === 'chev'">
      <path d="M3 1l4 4-4 4" />
    </template>
    <template v-else-if="name === 'dots'">
      <circle cx="3" cy="8" r="1.2" fill="currentColor" stroke="none" />
      <circle cx="8" cy="8" r="1.2" fill="currentColor" stroke="none" />
      <circle cx="13" cy="8" r="1.2" fill="currentColor" stroke="none" />
    </template>
    <template v-else-if="name === 'upload'">
      <path d="M8 11V2M4.5 5.5L8 2l3.5 3.5" />
      <path d="M2 11v2a1 1 0 001 1h10a1 1 0 001-1v-2" />
    </template>
    <template v-else-if="name === 'eye'">
      <path d="M1 8s2.5-4.5 7-4.5S15 8 15 8s-2.5 4.5-7 4.5S1 8 1 8z" />
      <circle cx="8" cy="8" r="2" />
    </template>
    <template v-else-if="name === 'x'">
      <path d="M3 3l6 6M9 3l-6 6" />
    </template>
    <template v-else-if="name === 'check'">
      <path d="M2.5 8l3 3L13 3.5" />
    </template>
    <template v-else-if="name === 'trash'">
      <path d="M3 4h10M6 4V2.5a1 1 0 011-1h2a1 1 0 011 1V4M4.5 4l.5 9a1 1 0 001 1h4a1 1 0 001-1l.5-9" />
    </template>
    <template v-else-if="name === 'logout'">
      <path d="M10 4V2.5a1 1 0 00-1-1H3a1 1 0 00-1 1v11a1 1 0 001 1h6a1 1 0 001-1V12" />
      <path d="M7 8h8M12 5l3 3-3 3" />
    </template>
    <template v-else-if="name === 'arrow-left'">
      <path d="M13 8H3M6 5L3 8l3 3" />
    </template>
    <template v-else-if="name === 'arrow-right'">
      <path d="M3 8h10M10 5l3 3-3 3" />
    </template>
    <template v-else-if="name === 'chevrons-left'">
      <path d="M8 4L4 8l4 4" />
      <path d="M13 4L9 8l4 4" />
    </template>
    <template v-else-if="name === 'chevrons-right'">
      <path d="M3 4l4 4-4 4" />
      <path d="M8 4l4 4-4 4" />
    </template>
    <template v-else-if="name === 'menu'">
      <path d="M2 4h12M2 8h12M2 12h12" />
    </template>
  </svg>
</template>
