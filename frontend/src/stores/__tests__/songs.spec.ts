// Songs store tests. Verifies that the store reflects API results into its
// reactive list and handles the create/update/remove cases optimistically.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/api/songs', () => ({
  listSongs: vi.fn(),
  getSong: vi.fn(),
  createSong: vi.fn(),
  updateSong: vi.fn(),
  deleteSong: vi.fn(),
  restoreSong: vi.fn(),
}))

import * as songsApi from '@/api/songs'
import { useSongsStore } from '@/stores/songs'
import type { Song } from '@/types'

function makeSong(overrides: Partial<Song> = {}): Song {
  return {
    id: 'song-1',
    title: 'Amazing Grace',
    author: 'John Newton',
    lyrics: '[G]Amazing grace',
    original_key: 'G',
    tempo: 72,
    time_signature: '3/4',
    songbook_id: 'sb-1',
    tags: ['hymn'],
    preview_url: null,
    ccli_number: '22025',
    created_by: null,
    version: 1,
    created_at: '2026-04-01T00:00:00Z',
    updated_at: '2026-04-01T00:00:00Z',
    deleted_at: null,
    ...overrides,
  }
}

describe('songs store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('fetches the list and stores meta', async () => {
    vi.mocked(songsApi.listSongs).mockResolvedValue({
      data: [makeSong()],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    })
    const store = useSongsStore()
    await store.fetchList({ q: 'grace' })
    expect(songsApi.listSongs).toHaveBeenCalledWith({ q: 'grace' })
    expect(store.list).toHaveLength(1)
    expect(store.meta?.total).toBe(1)
  })

  it('prepends the newly-created song to the list', async () => {
    const store = useSongsStore()
    store.list = [makeSong({ id: 'b', title: 'Be Thou My Vision' })]
    vi.mocked(songsApi.createSong).mockResolvedValue(makeSong({ id: 'a', title: 'New' }))
    await store.create({
      title: 'New',
      author: null,
      lyrics: 'x',
      original_key: 'G',
      tempo: 72,
      time_signature: '4/4',
      songbook_id: 'sb-1',
      tags: [],
      preview_url: null,
      ccli_number: null,
    })
    expect(store.list[0].id).toBe('a')
    expect(store.list).toHaveLength(2)
  })

  it('replaces the edited song in place and updates current when it matches', async () => {
    const store = useSongsStore()
    const original = makeSong({ id: 'x', title: 'Old' })
    store.list = [original]
    store.current = original
    vi.mocked(songsApi.updateSong).mockResolvedValue(makeSong({ id: 'x', title: 'New', version: 2 }))
    await store.update('x', { title: 'New' })
    expect(store.list[0].title).toBe('New')
    expect(store.current?.version).toBe(2)
  })

  it('removes the song from the list and clears current when deleted', async () => {
    const store = useSongsStore()
    const s = makeSong({ id: 'x' })
    store.list = [s]
    store.current = s
    vi.mocked(songsApi.deleteSong).mockResolvedValue()
    await store.remove('x')
    expect(store.list).toHaveLength(0)
    expect(store.current).toBeNull()
  })
})
