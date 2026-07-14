<script setup lang="ts">
// Playlist Builder — drag-and-drop editor for a single playlist.
// Two-pane layout: ordered item list (left) + searchable song picker (right).
// All mutations are gated to the playlist owner or an admin; non-owners fall
// through to a read-only view that still allows duplication.
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import draggable from 'vuedraggable'
import AppShell from '@/components/AppShell.vue'
import GoLiveDialog from '@/components/GoLiveDialog.vue'
import Icon from '@/components/Icon.vue'
import KeyBadge from '@/components/KeyBadge.vue'
import ShareDialog from '@/components/ShareDialog.vue'
import Toast from '@/components/Toast.vue'
import { useAuthStore } from '@/stores/auth'
import { usePlaylistsStore } from '@/stores/playlists'
import { useSongsStore } from '@/stores/songs'
import { downloadPlaylistExport } from '@/api/playlists'
import { extractErrorMessage } from '@/api/client'
import type { PlaylistItem } from '@/types'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const playlists = usePlaylistsStore()
const songs = useSongsStore()
const { t } = useI18n()

const id = computed(() => route.params.id as string)

const loading = ref(false)
const error = ref<string | null>(null)
const success = ref<string | null>(null)
const shareOpen = ref(false)
const goLiveOpen = ref(false)
const exportMenuOpen = ref(false)

// Editable header state — kept local until blur/save so an in-flight keystroke
// isn't pushed to the server every press.
const nameDraft = ref('')
const eventDateDraft = ref('')
const tagsDraft = ref('')

// Song picker (right pane).
const pickerQuery = ref('')

const playlist = computed(() => playlists.current)
const items = computed(() => playlist.value?.items ?? [])

const canEdit = computed(() => {
  if (!playlist.value || !auth.user) return false
  return auth.isAdmin || playlist.value.created_by === auth.user.id
})

// Local mirror that vuedraggable mutates directly. We keep it in sync with
// the store on every load, and push reorder updates back through the store
// when the user finishes a drag.
const draggableItems = ref<PlaylistItem[]>([])

watch(
  items,
  (next) => {
    draggableItems.value = [...next]
  },
  { immediate: true },
)

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const p = await playlists.fetchOne(id.value)
    nameDraft.value = p.name
    eventDateDraft.value = p.event_date ?? ''
    tagsDraft.value = (p.tags ?? []).join(', ')
  } catch (err) {
    error.value = extractErrorMessage(err, t('playlistBuilder.errors.load'))
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await load()
  // Stock the picker on first visit so musicians can drop songs in immediately.
  if (songs.list.length === 0) {
    songs.fetchList({ per_page: 50 }).catch(() => {})
  }
})

let pickerDebounce: ReturnType<typeof setTimeout> | null = null
watch(pickerQuery, (q) => {
  if (pickerDebounce) clearTimeout(pickerDebounce)
  pickerDebounce = setTimeout(() => {
    songs.fetchList({ q: q || undefined, per_page: 50 }).catch(() => {})
  }, 250)
})

// ── Header mutations ──────────────────────────────────────────────────

async function saveHeader(): Promise<void> {
  if (!playlist.value || !canEdit.value) return
  const name = nameDraft.value.trim()
  if (!name) {
    nameDraft.value = playlist.value.name
    return
  }
  const tags = tagsDraft.value
    .split(',')
    .map((t) => t.trim())
    .filter((t) => t.length > 0)
  try {
    await playlists.update(id.value, {
      name,
      event_date: eventDateDraft.value || null,
      tags,
    })
    success.value = t('common.saved')
  } catch (err) {
    error.value = extractErrorMessage(err, t('playlistBuilder.errors.save'))
  }
}

// ── Item mutations ────────────────────────────────────────────────────

async function onAddSong(songId: string): Promise<void> {
  try {
    const { created, item } = await playlists.addItem(id.value, { song_id: songId })
    if (!created) {
      success.value = t('playlistBuilder.alreadyAdded', { name: item.song?.title ?? '' })
    }
  } catch (err) {
    error.value = extractErrorMessage(err, t('playlistBuilder.errors.addSong'))
  }
}

async function onRemoveItem(itemId: string): Promise<void> {
  try {
    await playlists.removeItem(id.value, itemId)
  } catch (err) {
    error.value = extractErrorMessage(err, t('playlistBuilder.errors.removeItem'))
  }
}

async function onItemFieldBlur(item: PlaylistItem): Promise<void> {
  // Persist edits on blur — avoids spamming the API on every keystroke.
  // Scripture items have no target_key, so only notes are editable here.
  const payload =
    item.item_type === 'scripture'
      ? { notes: item.notes?.trim() || null }
      : { target_key: item.target_key?.trim() || null, notes: item.notes?.trim() || null }
  try {
    await playlists.updateItem(id.value, item.id, payload)
  } catch (err) {
    error.value = extractErrorMessage(err, t('playlistBuilder.errors.updateItem'))
  }
}

async function onReorderEnd(): Promise<void> {
  // Only persist if the order actually changed compared to the server snapshot.
  const oldOrder = items.value.map((i) => i.id).join(',')
  const newOrder = draggableItems.value.map((i) => i.id).join(',')
  if (oldOrder === newOrder) return
  try {
    await playlists.reorderItems(
      id.value,
      draggableItems.value.map((i) => i.id),
    )
  } catch (err) {
    error.value = extractErrorMessage(err, t('playlistBuilder.errors.reorder'))
  }
}

// ── Top-level actions ────────────────────────────────────────────────

async function onDuplicate(): Promise<void> {
  if (!playlist.value) return
  const name = prompt(
    t('playlistBuilder.duplicatePrompt'),
    t('playlistBuilder.duplicateDefault', { name: playlist.value.name }),
  )
  if (!name) return
  try {
    const copy = await playlists.duplicate(id.value, name)
    await router.push(`/playlists/${copy.id}`)
  } catch (err) {
    error.value = extractErrorMessage(err, t('playlistBuilder.errors.duplicate'))
  }
}

async function onDelete(): Promise<void> {
  if (!confirm(t('playlistBuilder.confirmDelete'))) return
  try {
    await playlists.remove(id.value)
    await router.push('/playlists')
  } catch (err) {
    error.value = extractErrorMessage(err, t('playlistBuilder.errors.delete'))
  }
}

/**
 * Open the Go-Live dialog. The dialog handles the three projection modes
 * (temporary / existing live / persistent) and routes the leader to the
 * controller view once it has a session id to attach to.
 */
function onGoLive(): void {
  if (!playlist.value) return
  if (items.value.length === 0) {
    error.value = t('playlistsList.errors.emptyForGoLive')
    return
  }
  goLiveOpen.value = true
}

async function onGoLiveLaunched(sessionId: string): Promise<void> {
  goLiveOpen.value = false
  await router.push({ name: 'projection-control', params: { id: sessionId } })
}

async function onExport(format: 'pdf' | 'txt' | 'staug'): Promise<void> {
  exportMenuOpen.value = false
  try {
    const { blob, filename } = await downloadPlaylistExport(id.value, format)
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  } catch (err) {
    error.value = extractErrorMessage(err, t('playlistBuilder.errors.export'))
  }
}
</script>

<template>
  <AppShell>
    <div v-if="loading" class="p-8 text-text-faint">{{ t('common.loading') }}</div>

    <template v-else-if="playlist">
      <!-- Header -->
      <div class="px-6 py-[14px] border-b border-border flex items-center gap-3 flex-wrap">
        <router-link to="/playlists" class="text-[12px] text-text-faint flex items-center gap-1">
          <Icon name="arrow-left" /> {{ t('nav.playlists') }}
        </router-link>
        <div class="flex-1 min-w-[280px]">
          <input
            v-model="nameDraft"
            class="input font-display"
            style="font-size: 22px; font-weight: 600; border: none; background: transparent; padding: 4px 0"
            :readonly="!canEdit"
            data-testid="playlist-name"
            @blur="saveHeader"
          />
          <div class="flex flex-wrap items-center gap-2 mt-1 text-[12px] text-text-faint">
            <span class="mono uppercase tracking-[0.12em] text-[10px]">{{ t('playlistBuilder.event') }}</span>
            <input
              v-model="eventDateDraft"
              type="date"
              class="input"
              style="width: 150px; padding: 3px 6px; font-size: 12px"
              :readonly="!canEdit"
              data-testid="playlist-event-date"
              @blur="saveHeader"
            />
            <span class="mono uppercase tracking-[0.12em] text-[10px] ml-2">{{ t('playlistBuilder.tags') }}</span>
            <input
              v-model="tagsDraft"
              class="input"
              style="width: 220px; padding: 3px 6px; font-size: 12px"
              :placeholder="t('playlistBuilder.tagsPlaceholder')"
              :readonly="!canEdit"
              data-testid="playlist-tags"
              @blur="saveHeader"
            />
          </div>
        </div>
        <button
          type="button"
          class="btn"
          data-testid="playlist-duplicate"
          @click="onDuplicate"
        >
          {{ t('playlistsList.row.duplicate') }}
        </button>
        <button
          v-if="canEdit"
          type="button"
          class="btn"
          data-testid="playlist-share"
          @click="shareOpen = true"
        >
          {{ t('playlistsList.row.share') }}
        </button>
        <button
          v-if="canEdit"
          type="button"
          class="btn btn-primary"
          data-testid="playlist-go-live"
          @click="onGoLive"
        >
          <Icon name="cast" /> {{ t('playlistsList.row.goLive') }}
        </button>
        <div class="relative">
          <button
            type="button"
            class="btn"
            data-testid="playlist-export"
            @click="exportMenuOpen = !exportMenuOpen"
          >
            {{ t('playlistBuilder.export') }} <Icon name="chev" />
          </button>
          <div
            v-if="exportMenuOpen"
            class="absolute right-0 top-[110%] card z-10"
            style="min-width: 140px"
            @click.self="exportMenuOpen = false"
          >
            <button
              type="button"
              class="block w-full text-left px-3 py-2 text-[13px] hover:bg-bg-sunken"
              data-testid="export-pdf"
              @click="onExport('pdf')"
            >
              {{ t('playlistBuilder.exportPdf') }}
            </button>
            <button
              type="button"
              class="block w-full text-left px-3 py-2 text-[13px] hover:bg-bg-sunken"
              data-testid="export-txt"
              @click="onExport('txt')"
            >
              {{ t('playlistBuilder.exportText') }}
            </button>
            <button
              type="button"
              class="block w-full text-left px-3 py-2 text-[13px] hover:bg-bg-sunken"
              data-testid="export-staug"
              @click="onExport('staug')"
            >
              {{ t('playlistBuilder.exportStaug') }}
            </button>
          </div>
        </div>
        <button
          v-if="canEdit"
          type="button"
          class="btn btn-danger"
          data-testid="playlist-delete"
          @click="onDelete"
        >
          <Icon name="trash" /> {{ t('common.delete') }}
        </button>
      </div>

      <!-- Body -->
      <div class="grid grid-cols-[1fr_360px] flex-1 min-h-0">
        <!-- LEFT: ordered item list -->
        <div class="px-6 py-5 overflow-auto">
          <div
            v-if="items.length === 0"
            class="text-text-faint text-[13px] py-12 text-center"
            data-testid="playlist-empty"
          >
            {{ t('playlistBuilder.emptyPart1') }}
            <Icon name="plus" /> {{ t('playlistBuilder.emptyPart2') }}
          </div>
          <draggable
            v-else
            v-model="draggableItems"
            :item-key="(it: PlaylistItem) => it.id"
            handle=".drag-handle"
            :disabled="!canEdit"
            tag="ol"
            class="flex flex-col gap-2"
            data-testid="playlist-items"
            @end="onReorderEnd"
          >
            <template #item="{ element, index }: { element: PlaylistItem; index: number }">
              <li
                class="flex items-start gap-3 px-3 py-3 card"
                :class="element.song?.deleted ? 'opacity-60' : ''"
                data-testid="playlist-item"
              >
                <span
                  v-if="canEdit"
                  class="pt-1 drag-handle cursor-grab text-text-faint"
                  :aria-label="t('playlistBuilder.dragHandle')"
                >
                  <Icon name="dots" />
                </span>
                <span
                  class="mono text-[11px] text-text-faint pt-1"
                  style="min-width: 22px; text-align: right"
                >
                  {{ index + 1 }}
                </span>
                <!-- Scripture reading item (FR-PL-2) -->
                <div v-if="element.item_type === 'scripture'" class="flex-1 min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[14px] font-semibold truncate">
                      {{ element.scripture?.reference ?? t('playlistBuilder.scripture') }}
                    </span>
                    <span class="chip" data-testid="item-scripture-badge">
                      {{ t('playlistBuilder.scripture') }}
                    </span>
                    <span v-if="element.scripture?.translation_id" class="text-[11px] text-text-faint mono">
                      {{ element.scripture.translation_id }}
                    </span>
                  </div>
                  <div class="text-[11.5px] text-text-faint mt-[2px] truncate">
                    {{ t('playlistBuilder.scriptureReading') }}
                  </div>
                  <input
                    v-if="canEdit"
                    v-model="element.notes"
                    class="input mt-2"
                    style="padding: 5px 8px; font-size: 12px"
                    :placeholder="t('playlistBuilder.notesPlaceholder')"
                    data-testid="item-notes"
                    @blur="onItemFieldBlur(element)"
                  />
                  <div
                    v-else-if="element.notes"
                    class="text-[12px] text-text-faint mt-1 italic"
                  >
                    {{ element.notes }}
                  </div>
                </div>

                <!-- Song item -->
                <div v-else class="flex-1 min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[14px] font-semibold truncate">
                      {{ element.song?.title ?? t('shared.untitled') }}
                    </span>
                    <span v-if="element.song?.deleted" class="chip chip-danger">
                      {{ t('playlistBuilder.songDeleted') }}
                    </span>
                    <KeyBadge
                      v-if="element.target_key"
                      :musical-key="element.target_key"
                    />
                    <span
                      v-else-if="element.song?.original_key"
                      class="text-[11px] text-text-faint mono"
                    >
                      {{ t('playlistBuilder.originalKey', { key: element.song.original_key }) }}
                    </span>
                  </div>
                  <div class="text-[11.5px] text-text-faint mt-[2px] truncate">
                    {{ element.song?.author ?? '—' }}
                  </div>
                  <div v-if="canEdit" class="grid grid-cols-[110px_1fr] gap-2 mt-2">
                    <input
                      v-model="element.target_key"
                      class="input mono"
                      style="padding: 5px 8px; font-size: 12px"
                      :placeholder="element.song?.original_key ?? t('playlistBuilder.keyPlaceholder')"
                      data-testid="item-key"
                      @blur="onItemFieldBlur(element)"
                    />
                    <input
                      v-model="element.notes"
                      class="input"
                      style="padding: 5px 8px; font-size: 12px"
                      :placeholder="t('playlistBuilder.notesPlaceholder')"
                      data-testid="item-notes"
                      @blur="onItemFieldBlur(element)"
                    />
                  </div>
                  <div
                    v-else-if="element.notes"
                    class="text-[12px] text-text-faint mt-1 italic"
                  >
                    {{ element.notes }}
                  </div>
                </div>
                <button
                  v-if="canEdit"
                  type="button"
                  class="btn btn-danger"
                  style="padding: 4px 6px; font-size: 11px"
                  :title="t('playlistBuilder.removeTitle', { name: element.song?.title ?? '' })"
                  data-testid="item-remove"
                  @click="onRemoveItem(element.id)"
                >
                  <Icon name="x" />
                </button>
              </li>
            </template>
          </draggable>
        </div>

        <!-- RIGHT: song picker -->
        <aside v-if="canEdit" class="flex flex-col min-h-0 border-l border-border">
          <div class="px-4 pt-4 pb-2 border-b border-border">
            <div class="mono uppercase tracking-[0.14em] text-[10px] text-text-faint mb-2">
              {{ t('playlistBuilder.addSongs') }}
            </div>
            <div class="relative">
              <span class="absolute left-[10px] top-[10px] text-text-faint">
                <Icon name="search" />
              </span>
              <input
                v-model="pickerQuery"
                class="input"
                style="padding-left: 30px"
                :placeholder="t('playlistBuilder.pickerPlaceholder')"
                data-testid="picker-search"
              />
            </div>
          </div>
          <div class="flex-1 overflow-auto" data-testid="picker-list">
            <div
              v-if="songs.loading && songs.list.length === 0"
              class="p-4 text-[12px] text-text-faint"
            >
              {{ t('common.loading') }}
            </div>
            <div
              v-else-if="songs.list.length === 0"
              class="p-4 text-[12px] text-text-faint"
            >
              {{ t('playlistBuilder.noSongsMatch') }}
            </div>
            <button
              v-for="s in songs.list"
              :key="s.id"
              type="button"
              class="flex items-center w-full gap-2 px-4 py-2 text-left border-b border-border hover:bg-bg-sunken"
              data-testid="picker-item"
              @click="onAddSong(s.id)"
            >
              <div class="flex-1 min-w-0">
                <div class="text-[13px] font-medium truncate">{{ s.title }}</div>
                <div class="text-[11px] text-text-faint truncate">
                  {{ s.author ?? t('musician.unknownAuthor') }}
                </div>
              </div>
              <KeyBadge :musical-key="s.original_key" />
              <span class="text-accent"><Icon name="plus" /></span>
            </button>
          </div>
        </aside>
        <aside v-else class="border-l border-border p-4 text-[12px] text-text-faint">
          {{ t('playlistBuilder.readOnlyNote') }}
        </aside>
      </div>

      <ShareDialog
        v-if="shareOpen"
        :playlist-id="id"
        :open="shareOpen"
        @close="shareOpen = false"
      />

      <GoLiveDialog
        v-if="goLiveOpen && playlist"
        :playlist="playlist"
        :open="goLiveOpen"
        @close="goLiveOpen = false"
        @launched="onGoLiveLaunched"
      />
    </template>

    <div v-else class="p-8 text-text-faint">{{ t('playlistBuilder.notFound') }}</div>

    <Toast
      v-if="error"
      :message="error"
      kind="error"
      @close="error = null"
    />
    <Toast
      v-if="success"
      :message="success"
      kind="success"
      @close="success = null"
    />
  </AppShell>
</template>
