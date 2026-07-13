// AddToPlaylistDialog tests — lists the caller's playlists, adds a song, and
// distinguishes a real add from a no-op (song already present). The list
// endpoint is mocked at the api module; the add goes through the real store
// action, whose network call (addPlaylistItem) is also mocked.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createTestingPinia } from '@pinia/testing'
import { setActivePinia } from 'pinia'
import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import { useAuthStore } from '@/stores/auth'
import * as playlistApi from '@/api/playlists'
import type { PlaylistSummary, Song, User } from '@/types'

vi.mock('@/api/playlists', () => ({
  listPlaylists: vi.fn(),
  addPlaylistItem: vi.fn(),
}))

function song(): Song {
  return {
    id: 'song-1',
    title: 'Amazing Grace',
    author: 'Author',
    lyrics: '',
    original_key: 'G',
    tempo: 80,
    time_signature: '4/4',
    songbook_id: 'sb-1',
    tags: [],
    preview_url: null,
    ccli_number: null,
    created_by: 'user-1',
    version: 1,
    created_at: '',
    updated_at: '',
    deleted_at: null,
  }
}

function summary(id: string, name: string): PlaylistSummary {
  return {
    id,
    name,
    event_date: null,
    tags: [],
    created_by: 'user-1',
    item_count: 0,
    created_at: '',
    updated_at: '',
  }
}

function signIn(over: Partial<User> = {}): void {
  const auth = useAuthStore()
  auth.user = { id: 'user-1', email: 'me@x.io', display_name: 'Me', roles: ['musician'], ...over }
  auth.token = 'tok'
}

function mountDialog() {
  const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
  setActivePinia(pinia)
  signIn()
  const w = mount(AddToPlaylistDialog, {
    props: { song: song(), open: true },
    global: { plugins: [pinia] },
  })
  return w
}

describe('AddToPlaylistDialog', () => {
  beforeEach(() => {
    vi.mocked(playlistApi.listPlaylists).mockReset()
    vi.mocked(playlistApi.addPlaylistItem).mockReset()
  })

  it('lists the caller-scoped playlists (mine for a non-admin)', async () => {
    vi.mocked(playlistApi.listPlaylists).mockResolvedValue({
      data: [summary('a', 'Sunday'), summary('b', 'Evening')],
      meta: { current_page: 1, per_page: 100, total: 2, last_page: 1 },
    })

    const w = mountDialog()
    await flushPromises()

    expect(playlistApi.listPlaylists).toHaveBeenCalledWith({ mine: true, per_page: 100 })
    expect(w.findAll('[data-testid="add-to-playlist-item"]')).toHaveLength(2)
  })

  it('adds the song and emits added with the playlist name', async () => {
    vi.mocked(playlistApi.listPlaylists).mockResolvedValue({
      data: [summary('a', 'Sunday')],
      meta: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
    })
    vi.mocked(playlistApi.addPlaylistItem).mockResolvedValue({
      item: { id: 'i1', song_id: 'song-1', position: 0, target_key: null, notes: null, song: null },
      created: true,
    })

    const w = mountDialog()
    await flushPromises()

    await w.find('[data-testid="add-to-playlist-item"]').trigger('click')
    await flushPromises()

    expect(playlistApi.addPlaylistItem).toHaveBeenCalledWith('a', { song_id: 'song-1' })
    expect(w.emitted('added')?.[0]?.[0]).toEqual({
      playlistName: 'Sunday',
      alreadyInPlaylist: false,
    })
  })

  it('reports a no-op when the song is already in the playlist', async () => {
    vi.mocked(playlistApi.listPlaylists).mockResolvedValue({
      data: [summary('a', 'Sunday')],
      meta: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
    })
    vi.mocked(playlistApi.addPlaylistItem).mockResolvedValue({
      item: { id: 'i1', song_id: 'song-1', position: 0, target_key: null, notes: null, song: null },
      created: false,
    })

    const w = mountDialog()
    await flushPromises()

    await w.find('[data-testid="add-to-playlist-item"]').trigger('click')
    await flushPromises()

    expect(w.emitted('added')?.[0]?.[0]).toEqual({
      playlistName: 'Sunday',
      alreadyInPlaylist: true,
    })
  })

  it('requests all playlists for an admin', async () => {
    vi.mocked(playlistApi.listPlaylists).mockResolvedValue({
      data: [],
      meta: { current_page: 1, per_page: 100, total: 0, last_page: 1 },
    })

    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    signIn({ roles: ['admin'] })
    mount(AddToPlaylistDialog, {
      props: { song: song(), open: true },
      global: { plugins: [pinia] },
    })
    await flushPromises()

    expect(playlistApi.listPlaylists).toHaveBeenCalledWith({ mine: false, per_page: 100 })
  })
})
