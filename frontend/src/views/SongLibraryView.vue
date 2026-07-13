<script setup lang="ts">
// Song Library — 2-pane layout: searchable list on the left, preview on the right.
// Search is debounced (250ms) so each keystroke doesn't fire an API call.
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import AppShell from '@/components/AppShell.vue'
import KeyBadge from '@/components/KeyBadge.vue'
import Icon from '@/components/Icon.vue'
import ChordProPreview from '@/components/ChordProPreview.vue'
import Toast from '@/components/Toast.vue'
import GoLiveDialog from '@/components/GoLiveDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { useSongsStore } from '@/stores/songs'
import { useSongbooksStore } from '@/stores/songbooks'
import { extractErrorMessage } from '@/api/client'
import type { Song } from '@/types'

const auth = useAuthStore()
const songs = useSongsStore()
const songbooks = useSongbooksStore()
const router = useRouter()
const { t } = useI18n()

const query = ref('')
const songbookFilter = ref<string>('')
const selectedId = ref<string | null>(null)
const errorToast = ref<string | null>(null)
const goLiveOpen = ref(false)

const selected = computed<Song | null>(
  () => songs.list.find((s) => s.id === selectedId.value) ?? null,
)

async function refresh() {
  try {
    await songs.fetchList({
      q: query.value || undefined,
      songbook: songbookFilter.value || undefined,
      per_page: 50,
    })
    if (!selectedId.value && songs.list.length) selectedId.value = songs.list[0].id
    // If the current selection is no longer in the list (e.g. after filter), reset.
    if (selectedId.value && !songs.list.some((s) => s.id === selectedId.value)) {
      selectedId.value = songs.list[0]?.id ?? null
    }
  } catch (err) {
    errorToast.value = extractErrorMessage(err, t('library.errors.load'))
  }
}

let debounceTimer: ReturnType<typeof setTimeout> | null = null
watch(query, () => {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(refresh, 250)
})
watch(songbookFilter, refresh)

onMounted(async () => {
  await Promise.all([refresh(), songbooks.fetchList()])
})

function songbookName(id: string): string {
  return songbooks.list.find((sb) => sb.id === id)?.name ?? '—'
}

/** Open the same Go Live dialog used by the playlist builder, scoped to the selected song. */
function onProjectSong(): void {
  if (!selected.value) return
  goLiveOpen.value = true
}

async function onGoLiveLaunched(sessionId: string): Promise<void> {
  goLiveOpen.value = false
  try {
    await router.push({ name: 'projection-control', params: { id: sessionId } })
  } catch (err) {
    errorToast.value = extractErrorMessage(err, t('library.errors.project'))
  }
}
</script>

<template>
  <AppShell>
    <div class="grid grid-cols-[380px_1fr] flex-1 min-h-0">
      <!-- LEFT: search + list -->
      <div class="border-r border-border flex flex-col min-h-0">
        <div class="px-4 pt-[14px] pb-[10px] border-b border-border">
          <div class="flex justify-between items-center mb-[10px]">
            <div class="font-display font-semibold text-[20px]">{{ t('library.title') }}</div>
            <router-link
              v-if="auth.canEditSongs"
              to="/songs/new"
              class="btn btn-primary"
              style="padding: 6px 10px; font-size: 12px"
            >
              <Icon name="plus" /> {{ t('library.newSong') }}
            </router-link>
          </div>
          <div class="relative">
            <span class="absolute left-[10px] top-[10px] text-text-faint"><Icon name="search" /></span>
            <input
              v-model="query"
              class="input"
              style="padding-left: 30px"
              :placeholder="t('library.searchPlaceholder')"
              data-testid="library-search"
            />
          </div>
          <select
            v-if="songbooks.list.length > 1"
            v-model="songbookFilter"
            class="input mt-2 text-[12px]"
            data-testid="library-songbook-filter"
          >
            <option value="">{{ t('library.allSongbooks') }}</option>
            <option v-for="sb in songbooks.list" :key="sb.id" :value="sb.id">
              {{ sb.name }}
            </option>
          </select>
        </div>
        <div class="overflow-auto flex-1" data-testid="library-list">
          <div v-if="songs.loading && songs.list.length === 0" class="p-5 text-[13px] text-text-faint">
            {{ t('common.loading') }}
          </div>
          <div v-else-if="songs.list.length === 0" class="p-5 text-[13px] text-text-faint">
            {{ t('library.noMatches') }}
          </div>
          <div
            v-for="s in songs.list"
            :key="s.id"
            class="px-4 py-3 border-b border-border cursor-pointer border-l-[3px]"
            :class="
              selectedId === s.id
                ? 'bg-accent-soft border-l-accent'
                : 'border-l-transparent hover:bg-bg-sunken'
            "
            data-testid="library-item"
            @click="selectedId = s.id"
          >
            <div class="flex gap-[10px]">
              <div class="flex-1 min-w-0">
                <div class="text-[14px] font-semibold truncate">{{ s.title }}</div>
                <div class="text-[11.5px] text-text-faint mt-[2px] truncate">
                  {{ s.author ?? t('musician.unknownAuthor') }}
                </div>
              </div>
              <KeyBadge :musical-key="s.original_key" />
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT: preview -->
      <div v-if="selected" class="flex flex-col min-h-0">
        <div class="px-6 py-4 border-b border-border flex items-center gap-3">
          <div class="flex-1">
            <div class="font-display font-semibold text-[22px]">{{ selected.title }}</div>
            <div class="text-[12px] text-text-faint mt-[2px]">
              {{ selected.author ?? t('musician.unknownAuthor') }}
              <span v-if="selected.time_signature"> · {{ selected.time_signature }}</span>
              <span v-if="selected.tempo"> · {{ selected.tempo }} {{ t('musician.bpm') }}</span>
              <span v-if="selected.ccli_number"> · {{ t('musician.ccli') }} {{ selected.ccli_number }}</span>
              <span> · {{ songbookName(selected.songbook_id) }}</span>
            </div>
          </div>
          <router-link
            :to="`/songs/${selected.id}/play`"
            class="btn btn-primary"
            data-testid="library-play"
          >
            {{ t('library.switchMusician') }}
          </router-link>
          <button
            type="button"
            class="btn btn-primary"
            data-testid="library-project"
            @click="onProjectSong"
          >
            <Icon name="cast" />
            {{ t('library.projectSong') }}
          </button>
          <router-link
            v-if="auth.canEditSongs"
            :to="`/songs/${selected.id}`"
            class="btn"
            data-testid="library-edit"
          >
            {{ t('common.edit') }}
          </router-link>
        </div>
        <div class="px-9 py-6 overflow-auto flex-1">
          <ChordProPreview :source="selected.lyrics" />
        </div>
      </div>
      <div v-else class="grid place-items-center text-text-faint text-[13px]">
        {{ t('library.selectToPreview') }}
      </div>
    </div>
    <Toast
      v-if="errorToast"
      :message="errorToast"
      kind="error"
      @close="errorToast = null"
    />

    <GoLiveDialog
      v-if="goLiveOpen && selected"
      :song="selected"
      :open="goLiveOpen"
      @close="goLiveOpen = false"
      @launched="onGoLiveLaunched"
    />
  </AppShell>
</template>
