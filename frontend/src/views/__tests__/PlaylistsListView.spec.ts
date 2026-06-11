// PlaylistsListView smoke tests — verify the list renders, the search box
// debounces, and creating a playlist routes to the new builder.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { createTestingPinia } from '@pinia/testing'
import { setActivePinia } from 'pinia'
import PlaylistsListView from '@/views/PlaylistsListView.vue'
import { useAuthStore } from '@/stores/auth'
import { usePlaylistsStore } from '@/stores/playlists'
import type { Playlist, PlaylistSummary, User } from '@/types'

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
      {
        path: '/projection/control/:id',
        name: 'projection-control',
        component: { template: '<div data-testid="control-stub" />' },
      },
    ],
  })
}

function signIn(): User {
  const auth = useAuthStore()
  const user: User = {
    id: 'user-1',
    email: 'me@church.local',
    display_name: 'Me',
    roles: ['musician'],
  }
  auth.user = user
  auth.token = 'tok'
  return user
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

  // ── Inline row actions ─────────────────────────────────────────────

  it('duplicates a playlist directly from the list', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    signIn()
    const playlists = usePlaylistsStore()
    vi.spyOn(playlists, 'fetchList').mockImplementation(async () => {
      playlists.list = [summary('a', 'Sunday 9:30')]
    })
    const duplicateSpy = vi
      .spyOn(playlists, 'duplicate')
      .mockResolvedValue(fullPlaylist('a-copy', 'Sunday 9:30 (copy)'))

    const w = mount(PlaylistsListView, {
      global: {
        plugins: [router, pinia],
        stubs: { AppShell: { template: '<div><slot /></div>' } },
      },
    })
    await flushPromises()

    await w.find('[data-testid="playlist-row-duplicate"]').trigger('click')
    await flushPromises()

    expect(duplicateSpy).toHaveBeenCalledWith('a')
    // No navigation — duplicate is in-place.
    expect(router.currentRoute.value.path).toBe('/playlists')
  })

  it('shows the share button only to the owner', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    signIn() // user-1
    const playlists = usePlaylistsStore()
    vi.spyOn(playlists, 'fetchList').mockImplementation(async () => {
      playlists.list = [
        summary('mine', 'Mine', { created_by: 'user-1' }),
        summary('theirs', 'Theirs', { created_by: 'user-2' }),
      ]
    })

    const w = mount(PlaylistsListView, {
      global: {
        plugins: [router, pinia],
        stubs: { AppShell: { template: '<div><slot /></div>' } },
      },
    })
    await flushPromises()

    // Owner row has Share; non-owner row does not. Find each row and check.
    const rows = w.findAll('[data-testid="playlist-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].find('[data-testid="playlist-row-share"]').exists()).toBe(true)
    expect(rows[1].find('[data-testid="playlist-row-share"]').exists()).toBe(false)
    // Duplicate is available on both rows regardless of ownership.
    expect(rows[0].find('[data-testid="playlist-row-duplicate"]').exists()).toBe(true)
    expect(rows[1].find('[data-testid="playlist-row-duplicate"]').exists()).toBe(true)
  })

  it('disables Go Live when a playlist has no songs', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    signIn()
    const playlists = usePlaylistsStore()
    vi.spyOn(playlists, 'fetchList').mockImplementation(async () => {
      playlists.list = [summary('empty', 'Empty', { item_count: 0 })]
    })

    const w = mount(PlaylistsListView, {
      global: {
        plugins: [router, pinia],
        stubs: { AppShell: { template: '<div><slot /></div>' } },
      },
    })
    await flushPromises()

    const btn = w.find('[data-testid="playlist-row-go-live"]')
    expect(btn.exists()).toBe(true)
    expect((btn.element as HTMLButtonElement).disabled).toBe(true)
  })

  it('Go Live fetches the full playlist and opens the dialog', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    signIn()
    const playlists = usePlaylistsStore()
    vi.spyOn(playlists, 'fetchList').mockImplementation(async () => {
      playlists.list = [summary('a', 'Sunday 9:30', { item_count: 2 })]
    })
    const fetchOneSpy = vi
      .spyOn(playlists, 'fetchOne')
      .mockResolvedValue(fullPlaylist('a', 'Sunday 9:30'))

    const w = mount(PlaylistsListView, {
      global: {
        plugins: [router, pinia],
        stubs: {
          AppShell: { template: '<div><slot /></div>' },
          // Stub the heavy dialogs out so we don't have to mock projection.
          GoLiveDialog: { template: '<div data-testid="go-live-stub" />' },
          ShareDialog: { template: '<div data-testid="share-dialog-stub" />' },
        },
      },
    })
    await flushPromises()

    await w.find('[data-testid="playlist-row-go-live"]').trigger('click')
    await flushPromises()

    expect(fetchOneSpy).toHaveBeenCalledWith('a')
    expect(w.find('[data-testid="go-live-stub"]').exists()).toBe(true)
  })

  it('opens the Share dialog without fetching the full playlist', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)
    signIn()
    const playlists = usePlaylistsStore()
    vi.spyOn(playlists, 'fetchList').mockImplementation(async () => {
      playlists.list = [summary('a', 'Sunday 9:30')]
    })
    const fetchOneSpy = vi.spyOn(playlists, 'fetchOne').mockResolvedValue(
      fullPlaylist('a', 'Sunday 9:30'),
    )

    const w = mount(PlaylistsListView, {
      global: {
        plugins: [router, pinia],
        stubs: {
          AppShell: { template: '<div><slot /></div>' },
          GoLiveDialog: { template: '<div data-testid="go-live-stub" />' },
          ShareDialog: { template: '<div data-testid="share-dialog-stub" />' },
        },
      },
    })
    await flushPromises()

    await w.find('[data-testid="playlist-row-share"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="share-dialog-stub"]').exists()).toBe(true)
    // Share only needs the id, so no full-playlist fetch.
    expect(fetchOneSpy).not.toHaveBeenCalled()
  })
})
