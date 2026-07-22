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
      { path: '/songs/:id/play', component: { template: '<div />' } },
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

  it('Project song opens the Go Live dialog scoped to the selected song', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    const songs = useSongsStore()
    const songbooks = useSongbooksStore()
    vi.spyOn(songs, 'fetchList').mockImplementation(async () => {
      songs.list = [song('a', 'Amazing Grace')]
    })
    vi.spyOn(songbooks, 'fetchList').mockImplementation(async () => {
      songbooks.list = [sb]
    })
    const w = mount(SongLibraryView, {
      global: {
        plugins: [router, pinia],
        stubs: {
          AppShell: { template: '<div><slot /></div>' },
          GoLiveDialog: { template: '<div data-testid="go-live-stub" />', props: ['song', 'open'] },
        },
      },
    })
    await flushPromises()

    expect(w.find('[data-testid="go-live-stub"]').exists()).toBe(false)
    await w.find('[data-testid="library-project"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="go-live-stub"]').exists()).toBe(true)
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

  it('defaults to 25 songs per page and shows page navigation from meta', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    const songs = useSongsStore()
    const songbooks = useSongbooksStore()
    const fetchSpy = vi.spyOn(songs, 'fetchList').mockImplementation(async () => {
      songs.list = [song('a', 'Amazing Grace')]
      songs.meta = { current_page: 1, per_page: 25, total: 60, last_page: 3 }
    })
    vi.spyOn(songbooks, 'fetchList').mockResolvedValue()
    const w = mount(SongLibraryView, {
      global: {
        plugins: [router, pinia],
        stubs: { AppShell: { template: '<div><slot /></div>' } },
      },
    })
    await flushPromises()

    expect(fetchSpy.mock.calls[0][0]?.per_page).toBe(25)
    expect(w.find('[data-testid="library-page-indicator"]').text()).toContain('1')
    expect(w.find('[data-testid="library-page-indicator"]').text()).toContain('3')
    // On page 1: prev/first disabled, next/last enabled.
    expect(w.find('[data-testid="library-page-prev"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-testid="library-page-first"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-testid="library-page-next"]').attributes('disabled')).toBeUndefined()
    expect(w.find('[data-testid="library-page-last"]').attributes('disabled')).toBeUndefined()
  })

  it('moves to the next page and clamps at the last page', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    const songs = useSongsStore()
    const songbooks = useSongbooksStore()
    const fetchSpy = vi.spyOn(songs, 'fetchList').mockImplementation(async (query) => {
      const currentPage = query?.page ?? 1
      songs.list = [song('a', `Song page ${currentPage}`)]
      songs.meta = { current_page: currentPage, per_page: 25, total: 60, last_page: 3 }
    })
    vi.spyOn(songbooks, 'fetchList').mockResolvedValue()
    const w = mount(SongLibraryView, {
      global: {
        plugins: [router, pinia],
        stubs: { AppShell: { template: '<div><slot /></div>' } },
      },
    })
    await flushPromises()
    fetchSpy.mockClear()

    await w.find('[data-testid="library-page-next"]').trigger('click')
    await flushPromises()
    expect(fetchSpy.mock.calls[0][0]?.page).toBe(2)

    await w.find('[data-testid="library-page-last"]').trigger('click')
    await flushPromises()
    expect(fetchSpy.mock.calls[1][0]?.page).toBe(3)
    // Now on the last page: next/last disabled.
    expect(w.find('[data-testid="library-page-next"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-testid="library-page-last"]').attributes('disabled')).toBeDefined()

    await w.find('[data-testid="library-page-first"]').trigger('click')
    await flushPromises()
    expect(fetchSpy.mock.calls[2][0]?.page).toBe(1)
  })

  it('requests per_page=all and hides page navigation when "all" is selected', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    const songs = useSongsStore()
    const songbooks = useSongbooksStore()
    const fetchSpy = vi.spyOn(songs, 'fetchList').mockImplementation(async (query) => {
      if (query?.per_page === 'all') {
        songs.list = [song('a', 'A'), song('b', 'B'), song('c', 'C')]
        songs.meta = { current_page: 1, per_page: 3, total: 3, last_page: 1 }
      } else {
        songs.list = [song('a', 'A')]
        songs.meta = { current_page: 1, per_page: 25, total: 3, last_page: 1 }
      }
    })
    vi.spyOn(songbooks, 'fetchList').mockResolvedValue()
    const w = mount(SongLibraryView, {
      global: {
        plugins: [router, pinia],
        stubs: { AppShell: { template: '<div><slot /></div>' } },
      },
    })
    await flushPromises()
    fetchSpy.mockClear()

    await w.find('[data-testid="library-per-page"]').setValue('all')
    await flushPromises()

    expect(fetchSpy.mock.calls[0][0]?.per_page).toBe('all')
    expect(fetchSpy.mock.calls[0][0]?.page).toBeUndefined()
    expect(w.find('[data-testid="library-page-next"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-testid="library-page-prev"]').attributes('disabled')).toBeDefined()
  })
})
