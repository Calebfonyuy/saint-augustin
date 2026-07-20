// GoLiveDialog tests — covers both sources it can launch from (a Playlist
// or a single Song) across all three modes. Store actions are stubbed via
// createTestingPinia so we assert on call args rather than real network/socket
// behavior (already covered by stores/__tests__/projection.spec.ts).
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createTestingPinia } from '@pinia/testing'
import { setActivePinia } from 'pinia'
import GoLiveDialog from '@/components/GoLiveDialog.vue'
import { useAuthStore } from '@/stores/auth'
import { useProjectionStore } from '@/stores/projection'
import type { Playlist, ProjectionSessionSummary, Song, User } from '@/types'

function signIn(over: Partial<User> = {}): User {
  const auth = useAuthStore()
  const user: User = {
    id: 'user-1',
    email: 'me@church.local',
    display_name: 'Me',
    roles: ['musician'],
    ...over,
  }
  auth.user = user
  auth.token = 'tok'
  return user
}

function song(over: Partial<Song> = {}): Song {
  return {
    id: 'song-1',
    title: 'Amazing Grace',
    author: 'Author',
    lyrics: '[G]Some lyrics',
    original_key: 'G',
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
    ...over,
  }
}

function playlist(over: Partial<Playlist> = {}): Playlist {
  return {
    id: 'pl-1',
    name: 'Sunday 9:30',
    event_date: null,
    tags: [],
    created_by: null,
    duplicated_from_id: null,
    item_count: 1,
    items: [],
    created_at: '',
    updated_at: '',
    ...over,
  }
}

function summary(over: Partial<ProjectionSessionSummary> = {}): ProjectionSessionSummary {
  return {
    id: 'sess-existing',
    name: 'Existing session',
    status: 'LIVE',
    kind: 'PERSISTENT',
    ownerId: 'user-1',
    ownerName: 'Me',
    scheduledStartAt: null,
    scheduledEndAt: null,
    startedAt: null,
    endedAt: null,
    playlistName: 'Existing session',
    slideCount: 3,
    createdAt: '',
    updatedAt: '',
    ...over,
  }
}

function mountDialog(props: { playlist?: Playlist; song?: Song }) {
  const pinia = createTestingPinia({ stubActions: true, createSpy: vi.fn })
  setActivePinia(pinia)
  signIn()
  const projection = useProjectionStore()
  vi.mocked(projection.fetchSessions).mockResolvedValue()

  const w = mount(GoLiveDialog, {
    props: { open: true, ...props },
    global: { plugins: [pinia] },
  })
  return { w, projection }
}

describe('GoLiveDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  // ── Temporary mode ──────────────────────────────────────────────────────

  it('temporary mode with a song calls createFromSong and emits launched', async () => {
    const s = song()
    const { w, projection } = mountDialog({ song: s })
    vi.mocked(projection.createFromSong).mockResolvedValue({
      sessionId: 'sess-new',
      controlToken: 'tok',
      state: {} as never,
    })

    await w.find('[data-testid="go-live-confirm"]').trigger('click')
    await flushPromises()

    expect(projection.createFromSong).toHaveBeenCalledWith(s)
    expect(projection.createFromPlaylist).not.toHaveBeenCalled()
    expect(w.emitted('launched')).toEqual([['sess-new']])
  })

  it('temporary mode with a playlist calls createFromPlaylist (regression)', async () => {
    const p = playlist()
    const { w, projection } = mountDialog({ playlist: p })
    vi.mocked(projection.createFromPlaylist).mockResolvedValue({
      sessionId: 'sess-new',
      controlToken: 'tok',
      state: {} as never,
    })

    await w.find('[data-testid="go-live-confirm"]').trigger('click')
    await flushPromises()

    expect(projection.createFromPlaylist).toHaveBeenCalledWith(p)
    expect(projection.createFromSong).not.toHaveBeenCalled()
    expect(w.emitted('launched')).toEqual([['sess-new']])
  })

  // ── Persistent mode ─────────────────────────────────────────────────────

  it('persistent mode with a song names the session after the song and loads it via loadSongInto', async () => {
    const s = song({ title: 'How Great Thou Art' })
    const { w, projection } = mountDialog({ song: s })
    vi.mocked(projection.createPersistent).mockResolvedValue({
      sessionId: 'sess-persist',
      controlToken: null,
      state: {} as never,
    })
    vi.mocked(projection.loadSongInto).mockResolvedValue({} as never)
    vi.mocked(projection.startPersistent).mockResolvedValue({
      sessionId: 'sess-persist',
      controlToken: 'tok',
      state: {} as never,
    })

    await w.find('[data-testid="go-live-mode-persistent"] input').setValue(true)
    await w.find('[data-testid="go-live-confirm"]').trigger('click')
    await flushPromises()

    expect(projection.createPersistent).toHaveBeenCalledWith(
      expect.objectContaining({ name: 'How Great Thou Art', playlistName: 'How Great Thou Art', playlistId: undefined }),
    )
    expect(projection.loadSongInto).toHaveBeenCalledWith('sess-persist', s)
    expect(projection.loadPlaylistInto).not.toHaveBeenCalled()
    // startNow defaults to true, so it should also start the session.
    expect(projection.startPersistent).toHaveBeenCalledWith('sess-persist')
    expect(w.emitted('launched')).toEqual([['sess-persist']])
  })

  it('persistent mode with a playlist includes playlistId (regression)', async () => {
    const p = playlist({ id: 'pl-42', name: 'Sunday Set' })
    const { w, projection } = mountDialog({ playlist: p })
    vi.mocked(projection.createPersistent).mockResolvedValue({
      sessionId: 'sess-persist',
      controlToken: null,
      state: {} as never,
    })
    vi.mocked(projection.loadPlaylistInto).mockResolvedValue({} as never)
    vi.mocked(projection.startPersistent).mockResolvedValue({
      sessionId: 'sess-persist',
      controlToken: 'tok',
      state: {} as never,
    })

    await w.find('[data-testid="go-live-mode-persistent"] input').setValue(true)
    await w.find('[data-testid="go-live-confirm"]').trigger('click')
    await flushPromises()

    expect(projection.createPersistent).toHaveBeenCalledWith(
      expect.objectContaining({ name: 'Sunday Set', playlistName: 'Sunday Set', playlistId: 'pl-42' }),
    )
    expect(projection.loadPlaylistInto).toHaveBeenCalledWith('sess-persist', p)
    expect(projection.loadSongInto).not.toHaveBeenCalled()
  })

  // ── Existing session mode ───────────────────────────────────────────────

  it('existing mode with a song pushes slides via loadSongInto and starts a NOT_STARTED session', async () => {
    const s = song()
    const { w, projection } = mountDialog({ song: s })
    projection.sessions = [summary({ id: 'sess-scheduled', status: 'NOT_STARTED' })]
    vi.mocked(projection.loadSongInto).mockResolvedValue({} as never)
    vi.mocked(projection.startPersistent).mockResolvedValue({
      sessionId: 'sess-scheduled',
      controlToken: 'tok',
      state: {} as never,
    })

    await w.find('[data-testid="go-live-mode-existing"] input').setValue(true)
    await flushPromises()
    await w.find('[data-testid="go-live-existing-select"]').setValue('sess-scheduled')
    await w.find('[data-testid="go-live-confirm"]').trigger('click')
    await flushPromises()

    expect(projection.loadSongInto).toHaveBeenCalledWith('sess-scheduled', s)
    expect(projection.startPersistent).toHaveBeenCalledWith('sess-scheduled')
    expect(w.emitted('launched')).toEqual([['sess-scheduled']])
  })

  it('existing mode does not auto-start an already-LIVE session (take-control happens on the control view)', async () => {
    const s = song()
    const { w, projection } = mountDialog({ song: s })
    projection.sessions = [summary({ id: 'sess-live', status: 'LIVE' })]
    vi.mocked(projection.loadSongInto).mockResolvedValue({} as never)

    await w.find('[data-testid="go-live-mode-existing"] input').setValue(true)
    await flushPromises()
    await w.find('[data-testid="go-live-existing-select"]').setValue('sess-live')
    await w.find('[data-testid="go-live-confirm"]').trigger('click')
    await flushPromises()

    expect(projection.loadSongInto).toHaveBeenCalledWith('sess-live', s)
    expect(projection.startPersistent).not.toHaveBeenCalled()
    expect(w.emitted('launched')).toEqual([['sess-live']])
  })

  it('existing mode shows an error when no session is picked', async () => {
    const { w, projection } = mountDialog({ song: song() })

    await w.find('[data-testid="go-live-mode-existing"] input').setValue(true)
    await w.find('[data-testid="go-live-confirm"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="go-live-error"]').exists()).toBe(true)
    expect(projection.loadSongInto).not.toHaveBeenCalled()
  })
})
