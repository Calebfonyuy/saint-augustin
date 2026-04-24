// SongEditorView — happy path for creating a new song.
// We stub the song and songbook stores and confirm that pressing "Create
// song" calls store.create with the form's values, then navigates to the
// new song's edit route.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { createTestingPinia } from '@pinia/testing'
import { setActivePinia } from 'pinia'
import SongEditorView from '@/views/SongEditorView.vue'
import { useSongsStore } from '@/stores/songs'
import { useSongbooksStore } from '@/stores/songbooks'
import type { Song, Songbook } from '@/types'

const songbook: Songbook = {
  id: 'sb-1',
  name: 'Default',
  description: null,
  is_default: true,
  created_by: null,
  songs_count: 0,
  created_at: '',
  updated_at: '',
}

const createdSong: Song = {
  id: 'new-song',
  title: 'Test Song',
  author: null,
  lyrics: '[G]Hello',
  original_key: 'G',
  tempo: 72,
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

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/library', component: { template: '<div>Library</div>' } },
      {
        path: '/songs/new',
        name: 'song-new',
        component: SongEditorView,
      },
      {
        path: '/songs/:id',
        name: 'song-edit',
        component: SongEditorView,
      },
    ],
  })
}

describe('SongEditorView (create)', () => {
  let router: ReturnType<typeof makeRouter>

  beforeEach(async () => {
    router = makeRouter()
    await router.push('/songs/new')
    await router.isReady()
  })

  it('creates a song and navigates to its edit route', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    setActivePinia(pinia)

    // Populate stubbed stores BEFORE mount so onMounted sees the spies.
    const songs = useSongsStore()
    const songbooks = useSongbooksStore()
    songbooks.list = [songbook]
    vi.spyOn(songbooks, 'fetchList').mockResolvedValue()
    vi.spyOn(songs, 'create').mockResolvedValue(createdSong)

    const w = mount(SongEditorView, {
      global: {
        plugins: [router, pinia],
        stubs: { AppShell: { template: '<div><slot /></div>' } },
      },
    })

    await flushPromises() // onMounted

    await w.find('#f-title').setValue('Test Song')
    await w.find('#f-lyrics').setValue('[G]Hello')
    // songbook is set by onMounted via defaultSongbook.

    await w.find('[data-testid="editor-save"]').trigger('click')
    await flushPromises()

    expect(songs.create).toHaveBeenCalled()
    const arg = vi.mocked(songs.create).mock.calls[0][0]
    expect(arg.title).toBe('Test Song')
    expect(arg.lyrics).toBe('[G]Hello')
    expect(arg.songbook_id).toBe('sb-1')

    // Router replaced to the new song's edit route.
    expect(router.currentRoute.value.path).toBe('/songs/new-song')
  })
})
