// Playlists store tests. The store mirrors the songs-store shape but adds
// item-level mutators that the Playlist Builder relies on (insert at server
// position, optimistic reorder with rollback). These tests cover the cases
// that aren't already exercised by the API layer's own Pest suite.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/api/playlists', () => ({
  listPlaylists: vi.fn(),
  getPlaylist: vi.fn(),
  createPlaylist: vi.fn(),
  updatePlaylist: vi.fn(),
  deletePlaylist: vi.fn(),
  duplicatePlaylist: vi.fn(),
  addPlaylistItem: vi.fn(),
  updatePlaylistItem: vi.fn(),
  removePlaylistItem: vi.fn(),
  reorderPlaylistItems: vi.fn(),
  downloadPlaylistExport: vi.fn(),
}))

import * as api from '@/api/playlists'
import { usePlaylistsStore } from '@/stores/playlists'
import type { Playlist, PlaylistItem } from '@/types'

function makeItem(overrides: Partial<PlaylistItem> = {}): PlaylistItem {
  return {
    id: 'item-1',
    item_type: 'song',
    song_id: 'song-1',
    position: 0,
    target_key: null,
    notes: null,
    scripture: null,
    song: {
      id: 'song-1',
      title: 'Amazing Grace',
      author: 'John Newton',
      original_key: 'G',
      tempo: 72,
      time_signature: '3/4',
    },
    ...overrides,
  }
}

function makePlaylist(overrides: Partial<Playlist> = {}): Playlist {
  return {
    id: 'pl-1',
    name: 'Sunday',
    event_date: '2026-04-26',
    tags: [],
    created_by: 'user-1',
    item_count: 0,
    duplicated_from_id: null,
    items: [],
    created_at: '2026-04-25T00:00:00Z',
    updated_at: '2026-04-25T00:00:00Z',
    ...overrides,
  }
}

describe('playlists store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('fetches the list and stores meta', async () => {
    vi.mocked(api.listPlaylists).mockResolvedValue({
      data: [
        {
          id: 'pl-1',
          name: 'Sunday',
          event_date: null,
          tags: [],
          created_by: null,
          item_count: 0,
          created_at: '',
          updated_at: '',
        },
      ],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    })
    const store = usePlaylistsStore()
    await store.fetchList({ mine: true })
    expect(api.listPlaylists).toHaveBeenCalledWith({ mine: true })
    expect(store.list).toHaveLength(1)
    expect(store.meta?.total).toBe(1)
  })

  it('inserts a new item at the server-returned position and renumbers locally', async () => {
    const store = usePlaylistsStore()
    const a = makeItem({ id: 'a', position: 0 })
    const b = makeItem({ id: 'b', position: 1 })
    store.current = makePlaylist({ items: [a, b] })

    // Server inserts at position 1, shifting `b` to position 2 implicitly.
    vi.mocked(api.addPlaylistItem).mockResolvedValue({
      item: makeItem({ id: 'c', position: 1 }),
      created: true,
    })

    await store.addItem('pl-1', { song_id: 'song-c' })

    expect(store.current!.items.map((i) => i.id)).toEqual(['a', 'c', 'b'])
    expect(store.current!.items.map((i) => i.position)).toEqual([0, 1, 2])
  })

  it('does not mutate local state when the add is a no-op (song already present)', async () => {
    const store = usePlaylistsStore()
    const a = makeItem({ id: 'a', position: 0 })
    const b = makeItem({ id: 'b', position: 1 })
    store.current = makePlaylist({ items: [a, b] })

    // Server reports the song was already in the playlist (200 → created:false).
    vi.mocked(api.addPlaylistItem).mockResolvedValue({
      item: makeItem({ id: 'a', position: 0 }),
      created: false,
    })

    const result = await store.addItem('pl-1', { song_id: 'song-a' })

    expect(result.created).toBe(false)
    expect(store.current!.items.map((i) => i.id)).toEqual(['a', 'b'])
  })

  it('removes an item and renumbers the remainder', async () => {
    const store = usePlaylistsStore()
    store.current = makePlaylist({
      items: [
        makeItem({ id: 'a', position: 0 }),
        makeItem({ id: 'b', position: 1 }),
        makeItem({ id: 'c', position: 2 }),
      ],
    })
    vi.mocked(api.removePlaylistItem).mockResolvedValue()

    await store.removeItem('pl-1', 'b')

    expect(store.current!.items.map((i) => i.id)).toEqual(['a', 'c'])
    expect(store.current!.items.map((i) => i.position)).toEqual([0, 1])
  })

  it('reorder is optimistic: positions update before the API resolves', async () => {
    const store = usePlaylistsStore()
    store.current = makePlaylist({
      items: [
        makeItem({ id: 'a', position: 0 }),
        makeItem({ id: 'b', position: 1 }),
        makeItem({ id: 'c', position: 2 }),
      ],
    })

    let resolveServer: (() => void) | null = null
    vi.mocked(api.reorderPlaylistItems).mockReturnValue(
      new Promise<{ data: PlaylistItem[] }>((resolve) => {
        resolveServer = () => resolve({ data: [] })
      }),
    )

    const pending = store.reorderItems('pl-1', ['c', 'a', 'b'])

    // Local state should already reflect the new order.
    expect(store.current!.items.map((i) => i.id)).toEqual(['c', 'a', 'b'])
    expect(store.current!.items.map((i) => i.position)).toEqual([0, 1, 2])

    resolveServer!()
    await pending
  })

  it('rolls back via fetchOne when the server rejects a reorder', async () => {
    const store = usePlaylistsStore()
    store.current = makePlaylist({
      items: [
        makeItem({ id: 'a', position: 0 }),
        makeItem({ id: 'b', position: 1 }),
      ],
    })

    vi.mocked(api.reorderPlaylistItems).mockRejectedValue(new Error('nope'))
    vi.mocked(api.getPlaylist).mockResolvedValue(
      makePlaylist({
        items: [
          makeItem({ id: 'a', position: 0 }),
          makeItem({ id: 'b', position: 1 }),
        ],
      }),
    )

    await expect(store.reorderItems('pl-1', ['b', 'a'])).rejects.toThrow('nope')
    // After the error path, the canonical order is refetched.
    expect(api.getPlaylist).toHaveBeenCalledWith('pl-1')
    expect(store.current!.items.map((i) => i.id)).toEqual(['a', 'b'])
  })

  it('duplicate prepends a summary of the new playlist to the list', async () => {
    const store = usePlaylistsStore()
    vi.mocked(api.duplicatePlaylist).mockResolvedValue(
      makePlaylist({ id: 'pl-2', name: 'Sunday (copy)' }),
    )

    await store.duplicate('pl-1', 'Sunday (copy)')

    expect(store.list[0].id).toBe('pl-2')
    expect(store.list[0].name).toBe('Sunday (copy)')
  })
})
