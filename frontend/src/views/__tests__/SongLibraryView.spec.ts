// SongLibraryView integration test.
// Stubs the songs and songbooks stores and checks the list renders, a row
// selects, and typing in the search box triggers a refresh.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { createTestingPinia } from '@pinia/testing'
import { setActivePinia } from 'pinia'
import SongLibraryView from '@/views/SongLibraryView.vue'
import { useSongsStore } from '@/stores/songs'
import { useSongbooksStore } from '@/stores/songbooks'
import type { Song, Songbook } from '@/types'

function song(id: string, title: string, key = 'G'): Song {
  return {
    id,
    title,
    author: 'Author',
    lyrics: '[G]Some lyrics',
    original_key: key,
    tempo: 80,
    time_signature: '4/4',
    songbook_id: 'sb-1',
    tags: [],
    preview_url: null,
    ccli_number: null,
    created_by: null,
    version: 1,
    created_at: '',
    updated_at: '',
    deleted_at: null,
  }
}

const sb: Songbook = {
  id: 'sb-1',
  name: 'Hymnal',
  description: null,
  is_default: true,
  created_by: null,
  songs_count: 2,
  created_at: '',
  updated_at: '',
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/library', component: SongLibraryView },
      { path: '/songs/new', component: { template: '<div />' } },
      { path: '/songs/:id', component: { template: '<div />' } },
    ],
  })
}

describe('SongLibraryView', () => {
  let router: ReturnType<typeof makeRouter>

  beforeEach(async () => {
    router = makeRouter()
    await router.push('/library')
    await router.isReady()
    vi.useFakeTimers()
  })

  it('renders rows and selects the first song as active', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    const songs = useSongsStore()
    const songbooks = useSongbooksStore()
    vi.spyOn(songs, 'fetchList').mockImplementation(async () => {
      songs.list = [song('a', 'Amazing Grace'), song('b', 'Be Thou My Vision')]
    })
    vi.spyOn(songbooks, 'fetchList').mockImplementation(async () => {
      songbooks.list = [sb]
    })
    const w = mount(SongLibraryView, {
      global: {
        plugins: [router, pinia],
        stubs: { AppShell: { template: '<div><slot /></div>' } },
      },
    })

    await flushPromises()
    const items = w.findAll('[data-testid="library-item"]')
    expect(items).toHaveLength(2)
    // Active selection: first item gets the accent-soft class.
    expect(items[0].classes()).toContain('bg-accent-soft')
    // Preview shows the active song's title.
    expect(w.text()).toContain('Amazing Grace')
  })

  it('debounces the search and re-fetches', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    const songs = useSongsStore()
    const songbooks = useSongbooksStore()
    const fetchSpy = vi.spyOn(songs, 'fetchList').mockResolvedValue()
    vi.spyOn(songbooks, 'fetchList').mockResolvedValue()
    const w = mount(SongLibraryView, {
      global: {
        plugins: [router, pinia],
        stubs: { AppShell: { template: '<div><slot /></div>' } },
      },
    })

    await flushPromises()
    fetchSpy.mockClear()

    await w.find('[data-testid="library-search"]').setValue('grace')
    // Debounce is 250ms.
    vi.advanceTimersByTime(260)
    await flushPromises()

    expect(fetchSpy).toHaveBeenCalledTimes(1)
    const args = fetchSpy.mock.calls[0][0]
    expect(args?.q).toBe('grace')
  })
})
