<script setup lang="ts">
// Compact dropdown for switching the active locale.
//
// Lives outside any auth context so it can be used on the login/register/
// shared-playlist screens as well as inside AppShell. The button shows the
// current locale code (FR/EN) and a chevron; the menu lists each supported
// locale by its native name.
//
// Two layout knobs let callers fit the popup into different containers:
//   - `direction` controls which side of the trigger the menu opens toward
//     (use `up` when the trigger sits at the bottom of the viewport).
//   - `align` snaps the menu to the trigger's left or right edge.
import { ref } from 'vue'
import { onClickOutside } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { setLocale, SUPPORTED_LOCALES, type Locale } from '@/i18n'
import Icon from './Icon.vue'

const props = withDefaults(
  defineProps<{
    direction?: 'up' | 'down'
    align?: 'left' | 'right'
  }>(),
  { direction: 'down', align: 'right' },
)

const { locale } = useI18n()
const open = ref(false)
const root = ref<HTMLElement | null>(null)

// Native names: stay readable to a speaker of that language even when the
// app is currently displayed in the other.
const NATIVE_LABELS: Record<Locale, string> = {
  en: 'English',
  fr: 'Français',
}

function pick(target: Locale): void {
  setLocale(target)
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
      @click="open = !open"
    >
      {{ locale }}
      <span
        class="inline-block transition-transform"
        :class="open ? 'rotate-[-90deg]' : 'rotate-90'"
      >
        <Icon name="chev" :size="10" />
      </span>
    </button>
    <ul
      v-if="open"
      class="absolute z-50 min-w-[140px] py-1 rounded-md border border-border bg-bg-raised shadow-lg"
      :class="[
        props.direction === 'up' ? 'bottom-full mb-1' : 'top-full mt-1',
        props.align === 'right' ? 'right-0' : 'left-0',
      ]"
      role="menu"
    >
      <li v-for="l in SUPPORTED_LOCALES" :key="l">
        <button
          type="button"
          role="menuitemradio"
          :aria-checked="locale === l"
          class="w-full flex items-center justify-between px-3 py-[6px] text-[12px] text-left hover:bg-bg-sunken transition-colors"
          :class="locale === l ? 'text-accent font-semibold' : 'text-text'"
          @click="pick(l)"
        >
          <span>{{ NATIVE_LABELS[l] }}</span>
          <span v-if="locale === l" class="text-accent">
            <Icon name="check" :size="12" />
          </span>
        </button>
      </li>
    </ul>
  </div>
</template>
