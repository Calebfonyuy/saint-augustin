<script setup lang="ts">
// Phase-1 dashboard. Only the "Open library" quick action and the Recent Songs
// list are wired — the other cards route to pages that don't exist yet, so
// they're disabled. The greeting and tagline match the prototype.
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/AppShell.vue'
import KeyBadge from '@/components/KeyBadge.vue'
import Icon from '@/components/Icon.vue'
import { useAuthStore } from '@/stores/auth'
import { useSongsStore } from '@/stores/songs'

const auth = useAuthStore()
const songs = useSongsStore()
const { t } = useI18n()

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 12) return t('dashboard.greeting.morning')
  if (hour < 18) return t('dashboard.greeting.afternoon')
  return t('dashboard.greeting.evening')
})

onMounted(() => {
  if (songs.list.length === 0) {
    // Fire-and-forget; the list renders with a loading state.
    songs.fetchList({ per_page: 6 })
  }
})
</script>

<template>
  <AppShell>
    <div class="px-8 pt-7 pb-2">
      <div class="font-display font-semibold text-[32px]">
        {{ t('dashboard.welcome', { greeting, name: auth.user?.display_name?.split(' ')[0] ?? t('dashboard.friend') }) }}
      </div>
      <div class="font-display italic text-[14px] text-text-faint mt-[6px]">
        "Qui cantat, bis orat."
      </div>
    </div>

    <div class="px-8 pb-8 pt-4 overflow-auto flex-1">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <router-link to="/library" class="card p-4 text-left block hover:bg-bg-sunken transition-colors">
          <div class="text-accent mb-2"><Icon name="music" /></div>
          <div class="text-[14px] font-semibold">{{ t('dashboard.cards.openLibrary.title') }}</div>
          <div class="text-[12px] text-text-faint mt-[2px]">{{ t('dashboard.cards.openLibrary.subtitle') }}</div>
        </router-link>
        <router-link
          v-if="auth.canEditSongs"
          to="/songs/new"
          class="card p-4 text-left block hover:bg-bg-sunken transition-colors"
        >
          <div class="text-accent mb-2"><Icon name="plus" /></div>
          <div class="text-[14px] font-semibold">{{ t('dashboard.cards.newSong.title') }}</div>
          <div class="text-[12px] text-text-faint mt-[2px]">{{ t('dashboard.cards.newSong.subtitle') }}</div>
        </router-link>
        <router-link
          to="/playlists"
          class="card p-4 text-left block hover:bg-bg-sunken transition-colors"
        >
          <div class="text-accent mb-2"><Icon name="list" /></div>
          <div class="text-[14px] font-semibold">{{ t('dashboard.cards.playlists.title') }}</div>
          <div class="text-[12px] text-text-faint mt-[2px]">{{ t('dashboard.cards.playlists.subtitle') }}</div>
        </router-link>
        <router-link
          to="/sessions"
          class="card p-4 text-left block hover:bg-bg-sunken transition-colors"
        >
          <div class="text-accent mb-2"><Icon name="cast" /></div>
          <div class="text-[14px] font-semibold">{{ t('dashboard.cards.sessions.title') }}</div>
          <div class="text-[12px] text-text-faint mt-[2px]">{{ t('dashboard.cards.sessions.subtitle') }}</div>
        </router-link>
      </div>

      <div>
        <div class="mono uppercase tracking-[0.14em] text-[11px] text-text-faint mb-[10px]">
          {{ t('dashboard.recentSongs') }}
        </div>
        <div class="card overflow-hidden">
          <div v-if="songs.loading" class="p-5 text-[13px] text-text-faint">{{ t('common.loading') }}</div>
          <div v-else-if="songs.list.length === 0" class="p-5 text-[13px] text-text-faint">
            {{ t('dashboard.noSongs') }}
            <router-link v-if="auth.canEditSongs" to="/songs/new" class="text-accent ml-1">
              {{ t('dashboard.addFirst') }}
            </router-link>
          </div>
          <router-link
            v-for="(s, i) in songs.list.slice(0, 6)"
            :key="s.id"
            :to="`/songs/${s.id}`"
            class="block px-4 py-3 flex items-center gap-[10px] cursor-pointer hover:bg-bg-sunken"
            :class="i !== Math.min(songs.list.length, 6) - 1 ? 'border-b border-border' : ''"
          >
            <div class="flex-1 text-[13px] font-medium">{{ s.title }}</div>
            <KeyBadge :musical-key="s.original_key" />
          </router-link>
        </div>
      </div>
    </div>
  </AppShell>
</template>
