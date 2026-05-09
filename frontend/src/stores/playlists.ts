// Playlists Pinia store — list view + current playlist with hydrated items.
//
// The store mirrors the songs store's shape (list/meta/loading/current) plus
// item-level mutators that the Playlist Builder calls during drag-and-drop
// reorder. Reorder is optimistic: the local positions update immediately,
// and we only rollback if the server rejects the new ordering.
import { defineStore } from 'pinia'
import { ref } from 'vue'
import * as api from '@/api/playlists'
import type {
  AddItemInput,
} from '@/api/playlists'
import type {
  Paginated,
  Playlist,
  PlaylistInput,
  PlaylistItem,
  PlaylistListQuery,
  PlaylistSummary,
} from '@/types'

export const usePlaylistsStore = defineStore('playlists', () => {
  const list = ref<PlaylistSummary[]>([])
  const meta = ref<Paginated<PlaylistSummary>['meta'] | null>(null)
  const loading = ref(false)
  const current = ref<Playlist | null>(null)

  async function fetchList(query: PlaylistListQuery = {}): Promise<void> {
    loading.value = true
    try {
      const res = await api.listPlaylists(query)
      list.value = res.data
      meta.value = res.meta
    } finally {
      loading.value = false
    }
  }

  async function fetchOne(id: string): Promise<Playlist> {
    const p = await api.getPlaylist(id)
    current.value = p
    return p
  }

  async function create(input: PlaylistInput): Promise<Playlist> {
    const p = await api.createPlaylist(input)
    current.value = p
    return p
  }

  async function update(id: string, input: Partial<PlaylistInput>): Promise<Playlist> {
    const p = await api.updatePlaylist(id, input)
    if (current.value?.id === id) current.value = p
    list.value = list.value.map((s) => (s.id === id ? { ...s, ...summarize(p) } : s))
    return p
  }

  async function remove(id: string): Promise<void> {
    await api.deletePlaylist(id)
    list.value = list.value.filter((s) => s.id !== id)
    if (current.value?.id === id) current.value = null
  }

  async function duplicate(id: string, name?: string): Promise<Playlist> {
    const copy = await api.duplicatePlaylist(id, name)
    list.value = [summarize(copy), ...list.value]
    return copy
  }

  // ── Items ──────────────────────────────────────────────────────────

  async function addItem(playlistId: string, input: AddItemInput): Promise<PlaylistItem> {
    const item = await api.addPlaylistItem(playlistId, input)
    if (current.value?.id === playlistId) {
      const items = [...current.value.items]
      items.splice(item.position, 0, item)
      // Renumber locally to match the server-side shift.
      items.forEach((it, i) => (it.position = i))
      current.value = { ...current.value, items }
    }
    return item
  }

  async function updateItem(
    playlistId: string,
    itemId: string,
    input: { target_key?: string | null; notes?: string | null },
  ): Promise<PlaylistItem> {
    const item = await api.updatePlaylistItem(playlistId, itemId, input)
    if (current.value?.id === playlistId) {
      current.value = {
        ...current.value,
        items: current.value.items.map((i) => (i.id === itemId ? item : i)),
      }
    }
    return item
  }

  async function removeItem(playlistId: string, itemId: string): Promise<void> {
    await api.removePlaylistItem(playlistId, itemId)
    if (current.value?.id === playlistId) {
      const items = current.value.items
        .filter((i) => i.id !== itemId)
        .map((i, idx) => ({ ...i, position: idx }))
      current.value = { ...current.value, items }
    }
  }

  /**
   * Optimistic reorder. The caller passes the new ordered list of item IDs;
   * we patch positions locally first, then sync. If the server rejects, we
   * refetch to recover the canonical order.
   */
  async function reorderItems(playlistId: string, itemIds: string[]): Promise<void> {
    const previous = current.value?.items
    if (current.value?.id === playlistId && previous) {
      const byId = new Map(previous.map((i) => [i.id, i]))
      const newItems = itemIds
        .map((id, idx) => {
          const item = byId.get(id)
          return item ? { ...item, position: idx } : null
        })
        .filter((i): i is PlaylistItem => i !== null)
      current.value = { ...current.value, items: newItems }
    }

    try {
      await api.reorderPlaylistItems(playlistId, itemIds)
    } catch (err) {
      // Rollback by refetching the canonical list.
      if (current.value?.id === playlistId) {
        await fetchOne(playlistId)
      }
      throw err
    }
  }

  /** Strip a Playlist down to a PlaylistSummary for the list cache. */
  function summarize(p: Playlist): PlaylistSummary {
    return {
      id: p.id,
      name: p.name,
      event_date: p.event_date,
      tags: p.tags,
      created_by: p.created_by,
      item_count: p.items.length,
      created_at: p.created_at,
      updated_at: p.updated_at,
    }
  }

  return {
    list,
    meta,
    loading,
    current,
    fetchList,
    fetchOne,
    create,
    update,
    remove,
    duplicate,
    addItem,
    updateItem,
    removeItem,
    reorderItems,
  }
})
