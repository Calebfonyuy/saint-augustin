<script setup lang="ts">
// Public share view (`/s/:token`). Renders without the AppShell so
// unauthenticated visitors see only the playlist content.
//   - musician mode: lyrics with chord lines, target key, notes
//   - projection mode: lyrics only, large type, dark background
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import ChordProPreview from '@/components/ChordProPreview.vue'
import KeyBadge from '@/components/KeyBadge.vue'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'
import ThemeSwitcher from '@/components/ThemeSwitcher.vue'
import { resolveSharedPlaylist } from '@/api/shareLinks'
import type { SharedPlaylistResponse } from '@/types'

const route = useRoute()
const { t } = useI18n()
const token = computed(() => route.params.token as string)

const data = ref<SharedPlaylistResponse | null>(null)
const loading = ref(true)
const error = ref<string | null>(null)

const isProjection = computed(() => data.value?.mode === 'projection')

// Projection mode steps through items one at a time using arrow keys / clicks.
const slideIndex = ref(0)
const currentSlide = computed(() => data.value?.playlist.items[slideIndex.value] ?? null)

function next(): void {
  if (!data.value) return
  if (slideIndex.value < data.value.playlist.items.length - 1) slideIndex.value++
}
function prev(): void {
  if (slideIndex.value > 0) slideIndex.value--
}

function onKey(e: KeyboardEvent): void {
  if (!isProjection.value) return
  if (e.key === 'ArrowRight' || e.key === ' ' || e.key === 'PageDown') next()
  else if (e.key === 'ArrowLeft' || e.key === 'PageUp') prev()
}

onMounted(async () => {
  try {
    data.value = await resolveSharedPlaylist(token.value)
  } catch {
    // Server returns 404 for unknown/expired/revoked tokens; we don't
    // distinguish those cases on purpose.
    error.value = t('shared.linkInvalid')
  } finally {
    loading.value = false
  }
  window.addEventListener('keydown', onKey)
})

function stripChords(lyrics: string): string {
  // Remove [Cm] / [G/B] style chord tokens for projection mode.
  return lyrics.replace(/\[[^\]]+\]/g, '')
}
</script>

<template>
  <div
    class="relative w-full h-full overflow-y-auto"
    :class="isProjection ? 'bg-black text-white' : 'bg-bg text-text'"
  >
    <div class="fixed top-3 right-3 z-20 flex items-center gap-2">
      <ThemeSwitcher direction="down" align="right" />
      <LanguageSwitcher direction="down" align="right" />
    </div>
    <div v-if="loading" class="grid place-items-center min-h-full text-text-faint p-6">
      {{ t('shared.loading') }}
    </div>

    <div
      v-else-if="error"
      class="grid place-items-center min-h-full p-6 sm:p-8 text-center"
      data-testid="shared-error"
    >
      <div>
        <div class="font-display text-[22px] sm:text-[28px] mb-2">{{ t('shared.linkUnavailable') }}</div>
        <div class="text-text-faint">{{ error }}</div>
      </div>
    </div>

    <!-- Musician mode: scrollable list of all items with chords -->
    <div
      v-else-if="data && !isProjection"
      class="max-w-[820px] mx-auto px-4 py-6 sm:px-6 sm:py-10"
      data-testid="shared-musician"
    >
      <div class="mb-6 sm:mb-8">
        <div class="font-display font-semibold text-[22px] sm:text-[28px]">
          {{ data.playlist.name }}
        </div>
        <div class="text-[12px] text-text-faint mt-1 flex flex-wrap items-center gap-1">
          <span v-if="data.playlist.event_date">{{ data.playlist.event_date }}</span>
          <span v-for="tag in data.playlist.tags" :key="tag" class="chip">{{ tag }}</span>
        </div>
      </div>
      <div
        v-for="(item, idx) in data.playlist.items"
        :key="item.id"
        class="mb-8 sm:mb-10"
        data-testid="shared-item"
      >
        <div class="flex flex-wrap items-center gap-2 mb-2">
          <span class="mono text-[11px] text-text-faint">{{ idx + 1 }}.</span>
          <span class="font-display font-semibold text-[17px] sm:text-[20px]">
            {{ item.song?.title ?? t('shared.untitled') }}
          </span>
          <KeyBadge
            v-if="item.target_key"
            :musical-key="item.target_key"
          />
          <span v-else-if="item.song?.original_key" class="text-[11px] text-text-faint mono">
            {{ item.song.original_key }}
          </span>
        </div>
        <div class="text-[12px] text-text-faint mb-2">
          {{ item.song?.author ?? '' }}
          <span v-if="item.notes"> · {{ item.notes }}</span>
        </div>
        <div v-if="item.song?.lyrics" class="card p-3 sm:p-4 overflow-x-auto">
          <ChordProPreview :source="item.song.lyrics" />
        </div>
        <div v-else class="text-text-faint text-[13px] italic">
          {{ t('shared.lyricsUnavailable') }}
        </div>
      </div>
    </div>

    <!-- Projection mode: one item at a time, large lyrics, no chords -->
    <div
      v-else-if="data && isProjection"
      class="min-h-full flex flex-col"
      data-testid="shared-projection"
      tabindex="0"
      @click="next"
    >
      <div class="px-4 sm:px-6 py-3 flex items-center gap-3 border-b border-white/10">
        <div class="font-display text-[15px] sm:text-[18px] truncate">{{ data.playlist.name }}</div>
        <div class="ml-auto text-[11px] sm:text-[12px] opacity-60 mono shrink-0">
          {{ slideIndex + 1 }} / {{ data.playlist.items.length }}
        </div>
      </div>
      <div class="flex-1 grid place-items-center px-4 py-6 sm:px-8 sm:py-8">
        <div v-if="currentSlide" class="text-center max-w-[1100px] w-full">
          <div class="font-display text-[20px] sm:text-[24px] md:text-[28px] opacity-70 mb-4 sm:mb-6">
            {{ currentSlide.song?.title ?? t('shared.untitled') }}
          </div>
          <pre
            class="font-display whitespace-pre-wrap text-[22px] sm:text-[30px] md:text-[40px] leading-[1.35]"
            style="font-weight: 500"
          >{{ currentSlide.song?.lyrics ? stripChords(currentSlide.song.lyrics) : '—' }}</pre>
        </div>
      </div>
      <div class="px-4 sm:px-6 py-3 text-center text-[11px] opacity-50 border-t border-white/10">
        {{ t('shared.projectionHelp') }}
      </div>
    </div>
  </div>
</template>
