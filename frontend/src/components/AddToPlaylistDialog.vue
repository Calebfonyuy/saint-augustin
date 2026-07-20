<script setup lang="ts">
// Add-to-playlist picker (Stage 4, FR-SL-1..4).
//
// Opened from the song library. Lists the playlists the caller can add to —
// their own, or every playlist for an admin (the /playlists endpoint already
// scopes by viewer via `mine`) — with an inline "create new playlist" path
// that reuses the shared CreatePlaylistModal. Selecting a playlist adds the
// song; re-adding a song already present is a server-side no-op surfaced here
// as an informational message.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Icon from './Icon.vue'
import CreatePlaylistModal from './CreatePlaylistModal.vue'
import { useAuthStore } from '@/stores/auth'
import { usePlaylistsStore } from '@/stores/playlists'
import { listPlaylists } from '@/api/playlists'
import { extractErrorMessage } from '@/api/client'
import type { Playlist, PlaylistSummary, Song } from '@/types'

const props = defineProps<{ song: Song; open: boolean }>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'added', payload: { playlistName: string; alreadyInPlaylist: boolean }): void
}>()

const { t } = useI18n()
const auth = useAuthStore()
const playlists = usePlaylistsStore()

const options = ref<PlaylistSummary[]>([])
const loading = ref(false)
const query = ref('')
const error = ref<string | null>(null)
/** Id of the playlist whose add is in flight (disables that row). */
const addingId = ref<string | null>(null)
const createOpen = ref(false)

const filtered = computed(() => {
  const term = query.value.trim().toLowerCase()
  if (!term) return options.value
  return options.value.filter((p) => p.name.toLowerCase().includes(term))
})

async function refresh(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    // Musicians see their own playlists; admins see all. The list endpoint
    // returns summaries, which is all the picker needs.
    const res = await listPlaylists({ mine: !auth.isAdmin, per_page: 100 })
    options.value = res.data
  } catch (err) {
    error.value = extractErrorMessage(err, t('addToPlaylist.errorLoad'))
  } finally {
    loading.value = false
  }
}

onMounted(refresh)

async function addToPlaylist(playlist: PlaylistSummary | Playlist): Promise<void> {
  addingId.value = playlist.id
  error.value = null
  try {
    const { created } = await playlists.addItem(playlist.id, { song_id: props.song.id })
    emit('added', { playlistName: playlist.name, alreadyInPlaylist: !created })
  } catch (err) {
    error.value = extractErrorMessage(err, t('addToPlaylist.errorAdd'))
  } finally {
    addingId.value = null
  }
}

async function onCreated(playlist: Playlist): Promise<void> {
  createOpen.value = false
  // A brand-new playlist is empty, so this add always inserts.
  await addToPlaylist(playlist)
}
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-30 grid place-items-center bg-black/30"
    role="dialog"
    aria-modal="true"
    aria-labelledby="add-to-playlist-title"
    data-testid="add-to-playlist-dialog"
    @click.self="emit('close')"
  >
    <div class="card w-[460px] max-w-[92vw] max-h-[80vh] flex flex-col">
      <div class="flex items-center justify-between px-5 py-4 border-b border-border">
        <div class="min-w-0">
          <div id="add-to-playlist-title" class="font-display font-semibold text-[18px]">
            {{ t('addToPlaylist.title') }}
          </div>
          <div class="text-[12px] text-text-faint truncate">{{ song.title }}</div>
        </div>
        <button
          type="button"
          class="btn btn-ghost"
          :aria-label="t('common.close')"
          @click="emit('close')"
        >
          <Icon name="x" />
        </button>
      </div>

      <div class="px-5 py-3 border-b border-border flex items-center gap-2">
        <div class="relative flex-1">
          <span class="absolute left-[10px] top-[10px] text-text-faint"><Icon name="search" /></span>
          <input
            v-model="query"
            class="input"
            style="padding-left: 30px"
            :placeholder="t('addToPlaylist.searchPlaceholder')"
            data-testid="add-to-playlist-search"
          />
        </div>
        <button
          type="button"
          class="btn btn-primary whitespace-nowrap"
          data-testid="add-to-playlist-create"
          @click="createOpen = true"
        >
          <Icon name="plus" /> {{ t('addToPlaylist.newPlaylist') }}
        </button>
      </div>

      <div class="flex-1 overflow-auto" data-testid="add-to-playlist-list">
        <div v-if="loading" class="p-5 text-[13px] text-text-faint">{{ t('common.loading') }}</div>
        <div
          v-else-if="filtered.length === 0"
          class="p-5 text-[13px] text-text-faint"
          data-testid="add-to-playlist-empty"
        >
          {{ t('addToPlaylist.empty') }}
        </div>
        <button
          v-for="p in filtered"
          :key="p.id"
          type="button"
          class="flex items-center w-full gap-2 px-5 py-3 text-left border-b border-border hover:bg-bg-sunken disabled:opacity-50"
          :disabled="addingId === p.id"
          data-testid="add-to-playlist-item"
          @click="addToPlaylist(p)"
        >
          <div class="flex-1 min-w-0">
            <div class="text-[13px] font-medium truncate">{{ p.name }}</div>
            <div class="text-[11px] text-text-faint truncate">
              {{ t('playlistsList.songsCount', { count: p.item_count }, p.item_count) }}
            </div>
          </div>
          <span class="text-accent"><Icon name="plus" /></span>
        </button>
      </div>

      <p v-if="error" class="px-5 py-3 field-error" data-testid="add-to-playlist-error">
        {{ error }}
      </p>
    </div>

    <CreatePlaylistModal
      v-if="createOpen"
      :open="createOpen"
      :initial-name="''"
      @close="createOpen = false"
      @created="onCreated"
    />
  </div>
</template>
