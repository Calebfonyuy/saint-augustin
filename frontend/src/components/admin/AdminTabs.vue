<script setup lang="ts">
/*
 * Shared header bar for the admin section: title, tab strip, optional CTA.
 * Mirrors the prototype's admin layout (proto-screens.jsx — AdminScreen).
 *
 * The Settings tab is still a placeholder (no screen yet). Tabs without a
 * `to` are rendered disabled so the navigation chrome stays stable as
 * later phases land.
 */
import { useRouter } from 'vue-router'

defineProps<{
  /** Active tab id — controls underline and text color. */
  active: 'users' | 'songbooks' | 'import' | 'settings'
  /** Page title — usually "Admin". */
  title?: string
  /** Subtitle hint shown after the title. */
  subtitle?: string
}>()

const router = useRouter()

interface Tab {
  id: 'users' | 'songbooks' | 'import' | 'settings'
  label: string
  to?: string
}

const tabs: Tab[] = [
  { id: 'users', label: 'Users', to: '/admin/users' },
  { id: 'songbooks', label: 'Songbooks', to: '/admin/songbooks' },
  { id: 'import', label: 'Import / Export', to: '/admin/import' },
  { id: 'settings', label: 'Settings' },
]

function goTab(t: Tab): void {
  if (t.to) {
    void router.push(t.to)
  }
}
</script>

<template>
  <div
    class="px-6 py-[14px] border-b border-border flex items-center gap-3 flex-wrap"
    data-testid="admin-tabs"
  >
    <div class="font-display font-semibold text-[22px]">{{ title ?? 'Admin' }}</div>
    <div v-if="subtitle" class="text-[12px] text-text-faint ml-1">{{ subtitle }}</div>
    <div class="flex gap-1 ml-4">
      <button
        v-for="t in tabs"
        :key="t.id"
        type="button"
        class="px-[10px] py-[6px] text-[12.5px] rounded-none border-b-2 transition-colors"
        :class="[
          active === t.id
            ? 'border-accent text-text font-medium'
            : 'border-transparent text-text-muted hover:text-text',
          !t.to ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer',
        ]"
        :disabled="!t.to"
        :aria-current="active === t.id ? 'page' : undefined"
        :data-testid="`admin-tab-${t.id}`"
        @click="goTab(t)"
      >
        {{ t.label }}
      </button>
    </div>
    <div class="flex-1" />
    <slot name="actions" />
  </div>
</template>
