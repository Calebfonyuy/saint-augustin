<script setup lang="ts">
// Playlists index — searchable list with a "mine only" toggle, a quick-create
// form that lands the user in the builder for the new playlist, and inline
// row actions (Duplicate · Share · Go Live) so a leader doesn't have to
// open the builder for one-off tasks.
//
// Go Live needs the full Playlist (items + songs) — the list endpoint only
// returns summaries — so the action fetches lazily on click before opening
// the GoLiveDialog.
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppShell from '@/components/AppShell.vue'
import GoLiveDialog from '@/components/GoLiveDialog.vue'
import Icon from '@/components/Icon.vue'
import ShareDialog from '@/components/ShareDialog.vue'
import Toast from '@/components/Toast.vue'
import { useAuthStore } from '@/stores/auth'
import { usePlaylistsStore } from '@/stores/playlists'
import { extractErrorMessage } from '@/api/client'
import type { Playlist, PlaylistSummary } from '@/types'

const router = useRouter()
const auth = useAuthStore()
const playlists = usePlaylistsStore()

const query = ref('')
const tag = ref('')
const mineOnly = ref(false)
const error = ref<string | null>(null)
const success = ref<string | null>(null)

const creating = ref(false)
const newName = ref('')
const newDate = ref('')

/** Id of the row whose row-level action is currently in flight — used to
 *  disable that row's action buttons and surface a "…" affordance. */
const busyId = ref<string | null>(null)

/** Open share dialog (lazy — we mount it only when an id is set). */
const sharingId = ref<string | null>(null)

/** Playlist hydrated for the Go Live dialog. Holding the full object instead
 *  of an id avoids a second fetch — and lets the dialog access items/songs. */
const goLiveFor = ref<Playlist | null>(null)

async function refresh(): Promise<void> {
  try {
    await playlists.fetchList({
      q: query.value || undefined,
      tag: tag.value || undefined,
      mine: mineOnly.value || undefined,
      per_page: 30,
    })
  } catch (err) {
    error.value = extractErrorMessage(err, 'Could not load playlists.')
  }
}

let debounce: ReturnType<typeof setTimeout> | null = null
watch(query, () => {
  if (debounce) clearTimeout(debounce)
  debounce = setTimeout(refresh, 250)
})
watch([tag, mineOnly], refresh)

onMounted(refresh)

async function onCreate(): Promise<void> {
  const name = newName.value.trim()
  if (!name) return
  creating.value = true
  try {
    const p = await playlists.create({
      name,
      event_date: newDate.value || null,
      tags: [],
    })
    newName.value = ''
    newDate.value = ''
    await router.push(`/playlists/${p.id}`)
  } catch (err) {
    error.value = extractErrorMessage(err, 'Could not create playlist.')
  } finally {
    creating.value = false
  }
}

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString()
}

/** Sharing and Go-Live mutate the underlying playlist or its session, so
 *  they're gated to the owner-or-admin set (matches PlaylistBuilderView). */
function canEdit(p: PlaylistSummary): boolean {
  if (!auth.user) return false
  return auth.isAdmin || p.created_by === auth.user.id
}

// ── Row actions ────────────────────────────────────────────────────

async function onDuplicate(p: PlaylistSummary): Promise<void> {
  busyId.value = p.id
  try {
    const copy = await playlists.duplicate(p.id)
    success.value = `Duplicated "${p.name}".`
    // Surface the copy at the top of the list immediately (the store
    // already inserts a summary; just nudge focus by toast).
    void copy
  } catch (err) {
    error.value = extractErrorMessage(err, 'Could not duplicate playlist.')
  } finally {
    busyId.value = null
  }
}

function onShare(p: PlaylistSummary): void {
  sharingId.value = p.id
}

async function onGoLive(p: PlaylistSummary): Promise<void> {
  if (p.item_count === 0) {
    error.value = 'Add at least one song before going live.'
    return
  }
  busyId.value = p.id
  try {
    // The list-cached PlaylistSummary lacks items/songs — fetch the full
    // playlist before mounting GoLiveDialog.
    const full = await playlists.fetchOne(p.id)
    goLiveFor.value = full
  } catch (err) {
    error.value = extractErrorMessage(err, 'Could not load playlist.')
  } finally {
    busyId.value = null
  }
}

async function onGoLiveLaunched(sessionId: string): Promise<void> {
  goLiveFor.value = null
  await router.push({ name: 'projection-control', params: { id: sessionId } })
}

/** True for the row whose action we're currently driving. */
const isBusy = computed(() => (id: string) => busyId.value === id)
</script>

<template>
  <AppShell>
    <div class="px-6 py-4 border-b border-border flex items-center gap-3 flex-wrap">
      <div class="font-display font-semibold text-[20px]">Playlists</div>
      <div class="relative ml-2">
        <span class="absolute left-[10px] top-[10px] text-text-faint">
          <Icon name="search" />
        </span>
        <input
          v-model="query"
          class="input"
          style="padding-left: 30px; min-width: 280px"
          placeholder="Search by name or tag…"
          data-testid="playlists-search"
        />
      </div>
      <input
        v-model="tag"
        class="input"
        style="max-width: 160px"
        placeholder="Filter by tag"
        data-testid="playlists-tag-filter"
      />
      <label class="flex items-center gap-2 text-[12px] text-text-muted">
        <input
          v-model="mineOnly"
          type="checkbox"
          data-testid="playlists-mine"
        />
        Only mine
      </label>
    </div>

    <div class="px-6 py-4 border-b border-border flex items-end gap-2 flex-wrap">
      <div class="flex-1 min-w-[260px]">
        <label class="field-label" for="new-playlist-name">New playlist name</label>
        <input
          id="new-playlist-name"
          v-model="newName"
          class="input"
          placeholder="Sunday 9:30 — Advent II"
          data-testid="playlists-new-name"
          @keydown.enter="onCreate"
        />
      </div>
      <div>
        <label class="field-label" for="new-playlist-date">Event date</label>
        <input
          id="new-playlist-date"
          v-model="newDate"
          type="date"
          class="input"
          data-testid="playlists-new-date"
        />
      </div>
      <button
        type="button"
        class="btn btn-primary"
        :disabled="creating || !newName.trim()"
        data-testid="playlists-create"
        @click="onCreate"
      >
        <Icon name="plus" /> Create
      </button>
    </div>

    <div class="overflow-auto flex-1 px-6 py-4">
      <div
        v-if="playlists.loading && playlists.list.length === 0"
        class="text-text-faint text-[13px] p-4"
      >
        Loading…
      </div>
      <div
        v-else-if="playlists.list.length === 0"
        class="text-text-faint text-[13px] p-4"
        data-testid="playlists-empty"
      >
        No playlists yet.
      </div>
      <div v-else class="grid gap-2">
        <!--
          The row is a flex container with a clickable link area for the
          title/metadata and a separate, non-link area for action buttons.
          This keeps inline actions from triggering navigation.
        -->
        <div
          v-for="p in playlists.list"
          :key="p.id"
          class="card flex items-center gap-3 hover:bg-bg-sunken transition-colors"
          data-testid="playlist-row"
        >
          <router-link
            :to="`/playlists/${p.id}`"
            class="flex-1 min-w-0 flex items-center gap-3 px-4 py-3"
            data-testid="playlist-row-link"
          >
            <div class="flex-1 min-w-0">
              <div class="text-[14px] font-semibold truncate">{{ p.name }}</div>
              <div class="text-[11.5px] text-text-faint mt-[2px] truncate">
                {{ formatDate(p.event_date) }} ·
                {{ p.item_count }} song{{ p.item_count === 1 ? '' : 's' }}
                <span v-if="p.created_by === auth.user?.id"> · yours</span>
              </div>
            </div>
            <div class="flex flex-wrap gap-1 max-w-[220px]">
              <span v-for="t in p.tags" :key="t" class="chip">{{ t }}</span>
            </div>
          </router-link>

          <div class="flex items-center gap-1 pr-3">
            <button
              type="button"
              class="btn"
              :disabled="isBusy(p.id)"
              data-testid="playlist-row-duplicate"
              :title="`Duplicate ${p.name}`"
              @click="onDuplicate(p)"
            >
              Duplicate
            </button>
            <button
              v-if="canEdit(p)"
              type="button"
              class="btn"
              :disabled="isBusy(p.id)"
              data-testid="playlist-row-share"
              :title="`Share ${p.name}`"
              @click="onShare(p)"
            >
              Share
            </button>
            <button
              v-if="canEdit(p)"
              type="button"
              class="btn btn-primary"
              :disabled="isBusy(p.id) || p.item_count === 0"
              data-testid="playlist-row-go-live"
              :title="
                p.item_count === 0
                  ? 'Add songs before going live'
                  : `Project ${p.name}`
              "
              @click="onGoLive(p)"
            >
              <Icon name="cast" /> Go Live
            </button>
          </div>
        </div>
      </div>
    </div>

    <ShareDialog
      v-if="sharingId"
      :playlist-id="sharingId"
      :open="!!sharingId"
      @close="sharingId = null"
    />

    <GoLiveDialog
      v-if="goLiveFor"
      :playlist="goLiveFor"
      :open="!!goLiveFor"
      @close="goLiveFor = null"
      @launched="onGoLiveLaunched"
    />

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
