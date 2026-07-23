<script setup lang="ts">
// Compact dropdown for switching the colour scheme: Light / Dark / System.
//
// Mirrors LanguageSwitcher so the two controls sit side by side and behave
// identically. The trigger shows an icon for the *selected mode* (a monitor
// glyph for System, sun/moon for the forced modes) so the user can see their
// choice at a glance without opening the menu.
//
// Layout knobs match LanguageSwitcher:
//   - `direction` — which side the menu opens toward (use `up` at the bottom
//     of the viewport, e.g. the AppShell footer).
//   - `align`     — snap the menu to the trigger's left or right edge.
import { ref } from 'vue'
import { onClickOutside } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { useThemeStore, THEME_MODES, type ThemeMode } from '@/stores/theme'
import Icon from './Icon.vue'

const props = withDefaults(
  defineProps<{
    direction?: 'up' | 'down'
    align?: 'left' | 'right'
  }>(),
  { direction: 'down', align: 'right' },
)

const theme = useThemeStore()
const { t } = useI18n()
const open = ref(false)
const root = ref<HTMLElement | null>(null)

const MODE_ICONS: Record<ThemeMode, 'sun' | 'moon' | 'monitor'> = {
  light: 'sun',
  dark: 'moon',
  system: 'monitor',
}

const MODE_LABELS: Record<ThemeMode, string> = {
  light: 'common.themeLight',
  dark: 'common.themeDark',
  system: 'common.themeSystem',
}

function pick(target: ThemeMode): void {
  theme.setMode(target)
  open.value = false
}

onClickOutside(root, () => {
  open.value = false
})
</script>

<template>
  <div ref="root" class="relative inline-block">
    <button
      type="button"
      class="flex items-center gap-1 px-2 py-1 rounded text-[11px] mono uppercase tracking-[0.08em] text-text-muted hover:text-text border border-border hover:bg-bg-sunken transition-colors"
      :aria-haspopup="true"
      :aria-expanded="open"
      :aria-label="t('common.theme')"
      :title="t('common.theme')"
      @click="open = !open"
    >
      <Icon :name="MODE_ICONS[theme.mode]" :size="13" />
      <span
        class="inline-block transition-transform"
        :class="open ? 'rotate-[-90deg]' : 'rotate-90'"
      >
        <Icon name="chev" :size="10" />
      </span>
    </button>
    <ul
      v-if="open"
      class="absolute z-50 min-w-[150px] py-1 rounded-md border border-border bg-bg-raised shadow-lg"
      :class="[
        props.direction === 'up' ? 'bottom-full mb-1' : 'top-full mt-1',
        props.align === 'right' ? 'right-0' : 'left-0',
      ]"
      role="menu"
    >
      <li v-for="m in THEME_MODES" :key="m">
        <button
          type="button"
          role="menuitemradio"
          :aria-checked="theme.mode === m"
          class="w-full flex items-center gap-2 px-3 py-[6px] text-[12px] text-left hover:bg-bg-sunken transition-colors"
          :class="theme.mode === m ? 'text-accent font-semibold' : 'text-text'"
          @click="pick(m)"
        >
          <Icon :name="MODE_ICONS[m]" :size="13" />
          <span class="flex-1">{{ t(MODE_LABELS[m]) }}</span>
          <span v-if="theme.mode === m" class="text-accent">
            <Icon name="check" :size="12" />
          </span>
        </button>
      </li>
    </ul>
  </div>
</template>
