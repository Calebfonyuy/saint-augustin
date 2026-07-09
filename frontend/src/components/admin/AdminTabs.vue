<script setup lang="ts">
/*
 * Shared header bar for the admin section: title, tab strip, optional CTA.
 * Mirrors the prototype's admin layout (proto-screens.jsx — AdminScreen).
 *
 * The Settings tab is still a placeholder (no screen yet). Tabs without a
 * `to` are rendered disabled so the navigation chrome stays stable as
 * later phases land.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

defineProps<{
  /** Active tab id — controls underline and text color. */
  active: 'users' | 'songbooks' | 'import' | 'settings'
  /** Page title — usually "Admin". */
  title?: string
  /** Subtitle hint shown after the title. */
  subtitle?: string
}>()

const { t } = useI18n()

const router = useRouter()

interface Tab {
  id: 'users' | 'songbooks' | 'import' | 'settings'
  label: string
  to?: string
}

const tabs = computed<Tab[]>(() => [
  { id: 'users', label: t('admin.tabs.users'), to: '/admin/users' },
  { id: 'songbooks', label: t('admin.tabs.songbooks'), to: '/admin/songbooks' },
  { id: 'import', label: t('admin.tabs.import'), to: '/admin/import' },
  { id: 'settings', label: t('admin.tabs.settings') },
])

function goTab(tab: Tab): void {
  if (tab.to) {
    void router.push(tab.to)
  }
}
</script>

<template>
  <div
    class="px-6 py-[14px] border-b border-border flex items-center gap-3 flex-wrap"
    data-testid="admin-tabs"
  >
    <div class="font-display font-semibold text-[22px]">{{ title ?? t('admin.title') }}</div>
    <div v-if="subtitle" class="text-[12px] text-text-faint ml-1">{{ subtitle }}</div>
    <div class="flex gap-1 ml-4">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        type="button"
        class="px-[10px] py-[6px] text-[12.5px] rounded-none border-b-2 transition-colors"
        :class="[
          active === tab.id
            ? 'border-accent text-text font-medium'
            : 'border-transparent text-text-muted hover:text-text',
          !tab.to ? 'opacity-60 cursor-not-allowed' : 'cursor-pointer',
        ]"
        :disabled="!tab.to"
        :aria-current="active === tab.id ? 'page' : undefined"
        :data-testid="`admin-tab-${tab.id}`"
        @click="goTab(tab)"
      >
        {{ tab.label }}
      </button>
    </div>
    <div class="flex-1" />
    <slot name="actions" />
  </div>
</template>
