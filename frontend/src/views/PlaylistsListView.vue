<script setup lang="ts">
// Playlists index — searchable list with a "mine only" toggle and a quick-create
// form that lands the user in the builder for the new playlist.
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppShell from '@/components/AppShell.vue'
import Icon from '@/components/Icon.vue'
import Toast from '@/components/Toast.vue'
import { useAuthStore } from '@/stores/auth'
import { usePlaylistsStore } from '@/stores/playlists'
import { extractErrorMessage } from '@/api/client'

const router = useRouter()
const auth = useAuthStore()
const playlists = usePlaylistsStore()

const query = ref('')
const tag = ref('')
const mineOnly = ref(false)
const error = ref<string | null>(null)

const creating = ref(false)
const newName = ref('')
const newDate = ref('')

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
        <router-link
          v-for="p in playlists.list"
          :key="p.id"
          :to="`/playlists/${p.id}`"
          class="card px-4 py-3 flex items-center gap-3 hover:bg-bg-sunken"
          data-testid="playlist-row"
        >
          <div class="flex-1 min-w-0">
            <div class="text-[14px] font-semibold truncate">{{ p.name }}</div>
            <div class="text-[11.5px] text-text-faint mt-[2px] truncate">
              {{ formatDate(p.event_date) }} · {{ p.item_count }} song{{ p.item_count === 1 ? '' : 's' }}
              <span v-if="p.created_by === auth.user?.id"> · yours</span>
            </div>
          </div>
          <div class="flex flex-wrap gap-1 max-w-[260px]">
            <span v-for="t in p.tags" :key="t" class="chip">{{ t }}</span>
          </div>
        </router-link>
      </div>
    </div>

    <Toast
      v-if="error"
      :message="error"
      kind="error"
      @close="error = null"
    />
  </AppShell>
</template>
