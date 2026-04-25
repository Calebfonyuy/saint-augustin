// PlaylistsListView smoke tests — verify the list renders, the search box
// debounces, and creating a playlist routes to the new builder.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { createTestingPinia } from '@pinia/testing'
import { setActivePinia } from 'pinia'
import PlaylistsListView from '@/views/PlaylistsListView.vue'
import { usePlaylistsStore } from '@/stores/playlists'
import type { Playlist, PlaylistSummary } from '@/types'

function summary(id: string, name: string, overrides: Partial<PlaylistSummary> = {}): PlaylistSummary {
  return {
    id,
    name,
    event_date: '2026-04-26',
    tags: ['advent'],
    created_by: 'user-1',
    item_count: 3,
    created_at: '',
    updated_at: '',
    ...overrides,
  }
}

function fullPlaylist(id: string, name: string): Playlist {
  return {
    ...summary(id, name),
    duplicated_from_id: null,
    items: [],
  }
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/playlists', component: PlaylistsListView },
      { path: '/playlists/:id', component: { template: '<div data-testid="builder-stub" />' } },
    ],
  })
}

describe('PlaylistsListView', () => {
  let router: ReturnType<typeof makeRouter>

  beforeEach(async () => {
    router = makeRouter()
    await router.push('/playlists')
    await router.isReady()
    vi.useFakeTimers()
  })

  it('renders rows from the store', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    const playlists = usePlaylistsStore()
    vi.spyOn(playlists, 'fetchList').mockImplementation(async () => {
      playlists.list = [summary('a', 'Sunday 9:30'), summary('b', 'Sunday 11:00')]
    })

    const w = mount(PlaylistsListView, {
      global: {
        plugins: [router, pinia],
        stubs: { AppShell: { template: '<div><slot /></div>' } },
      },
    })
    await flushPromises()

    const rows = w.findAll('[data-testid="playlist-row"]')
    expect(rows).toHaveLength(2)
    expect(w.text()).toContain('Sunday 9:30')
    expect(w.text()).toContain('Sunday 11:00')
  })

  it('debounces the search and re-fetches with the query', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    const playlists = usePlaylistsStore()
    const fetchSpy = vi.spyOn(playlists, 'fetchList').mockResolvedValue()

    const w = mount(PlaylistsListView, {
      global: {
        plugins: [router, pinia],
        stubs: { AppShell: { template: '<div><slot /></div>' } },
      },
    })
    await flushPromises()
    fetchSpy.mockClear()

    await w.find('[data-testid="playlists-search"]').setValue('advent')
    vi.advanceTimersByTime(260)
    await flushPromises()

    expect(fetchSpy).toHaveBeenCalledTimes(1)
    expect(fetchSpy.mock.calls[0][0]?.q).toBe('advent')
  })

  it('routes to the new playlist after create', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    const playlists = usePlaylistsStore()
    vi.spyOn(playlists, 'fetchList').mockResolvedValue()
    const createSpy = vi
      .spyOn(playlists, 'create')
      .mockResolvedValue(fullPlaylist('new-id', 'New Playlist'))

    const w = mount(PlaylistsListView, {
      global: {
        plugins: [router, pinia],
        stubs: { AppShell: { template: '<div><slot /></div>' } },
      },
    })
    await flushPromises()

    await w.find('[data-testid="playlists-new-name"]').setValue('New Playlist')
    await w.find('[data-testid="playlists-create"]').trigger('click')
    await flushPromises()

    expect(createSpy).toHaveBeenCalledWith({
      name: 'New Playlist',
      event_date: null,
      tags: [],
    })
    expect(router.currentRoute.value.path).toBe('/playlists/new-id')
  })
})
