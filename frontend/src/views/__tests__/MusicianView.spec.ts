// MusicianView integration tests.
//
// Coverage: song loads, key picker triggers transposition, sheets render,
// preview embeds for a YouTube URL.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createTestingPinia } from '@pinia/testing'
import { setActivePinia } from 'pinia'
import MusicianView from '@/views/MusicianView.vue'
import { useSongsStore } from '@/stores/songs'
import { useSongSheetsStore } from '@/stores/songSheets'
import type { Song, SongSheet } from '@/types'

const SONG: Song = {
  id: 'song-1',
  title: 'Amazing Grace',
  author: 'John Newton',
  lyrics: '[C]Amazing [G]grace, how [Am]sweet the [F]sound',
  original_key: 'C',
  tempo: 80,
  time_signature: '4/4',
  songbook_id: 'sb-1',
  tags: [],
  preview_url: 'https://www.youtube.com/watch?v=CDdvReNKKuk',
  ccli_number: null,
  created_by: null,
  version: 1,
  created_at: '',
  updated_at: '',
  deleted_at: null,
}

const SHEET: SongSheet = {
  id: 'sheet-1',
  song_id: 'song-1',
  original_filename: 'amazing-grace.pdf',
  file_type: 'pdf',
  mime_type: 'application/pdf',
  size_bytes: 8000,
  uploaded_by: null,
  url: 'https://example.test/presigned/amazing-grace.pdf',
  url_expires_at: null,
  created_at: '',
  updated_at: '',
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/library', component: { template: '<div />' } },
      { path: '/songs/:id', component: { template: '<div />' }, name: 'song-edit' },
      { path: '/songs/:id/play', component: MusicianView, name: 'song-play' },
    ],
  })
}

async function mountView() {
  const router = makeRouter()
  await router.push('/songs/song-1/play')
  await router.isReady()
  const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
  setActivePinia(pinia)
  const songs = useSongsStore()
  const sheets = useSongSheetsStore()
  vi.spyOn(songs, 'fetchOne').mockResolvedValue(SONG)
  vi.spyOn(sheets, 'fetchList').mockImplementation(async () => {
    sheets.list = [SHEET]
  })
  const w = mount(MusicianView, {
    global: {
      plugins: [router, pinia],
      stubs: { AppShell: { template: '<div><slot /></div>' } },
    },
  })
  await flushPromises()
  return w
}

describe('MusicianView', () => {
  beforeEach(() => {
    // Stub Web Audio so the metronome doesn't try to start a real context.
    vi.stubGlobal(
      'AudioContext',
      vi.fn().mockImplementation(() => ({
        state: 'running',
        currentTime: 0,
        resume: vi.fn(),
        createOscillator: () => ({
          frequency: { value: 0 },
          connect: () => ({ connect: () => undefined }),
          start: () => undefined,
          stop: () => undefined,
        }),
        createGain: () => ({
          gain: {
            setValueAtTime: () => undefined,
            linearRampToValueAtTime: () => undefined,
            exponentialRampToValueAtTime: () => undefined,
          },
          connect: () => ({ connect: () => undefined }),
        }),
        destination: {},
      })),
    )
  })

  it('renders the song title, original key badge, and chord/lyric body', async () => {
    const w = await mountView()
    expect(w.find('[data-testid="musician-title"]').text()).toBe('Amazing Grace')
    expect(w.find('[data-testid="musician-lyrics"]').text()).toContain('Amazing')
    // Original key badge — the KeyBadge renders the key as text.
    expect(w.text()).toContain('John Newton')
    // The lyric line contains the (untransposed) chord row, so 'C' should appear.
    expect(w.find('[data-testid="musician-lyrics"]').text()).toContain('C')
  })

  it('transposes chords when the key is changed', async () => {
    const w = await mountView()
    // Source key C, target D. Chords [C][G][Am][F] become [D][A][Bm][G].
    const select = w.find('[data-testid="musician-key-select"]')
    await select.setValue('D')
    await flushPromises()

    const body = w.find('[data-testid="musician-lyrics"]').text()
    // The transposed source has [D] [A] [Bm] [G] above the lyric line.
    expect(body).toContain('Bm')
    expect(body).toContain('A')
    // The original-key badge still says C (separate region).
    expect(w.html()).toMatch(/key-badge[^>]*>C</)
  })

  it('shows a Reset link only after a non-original key is picked', async () => {
    const w = await mountView()
    expect(w.find('[data-testid="musician-key-reset"]').exists()).toBe(false)
    await w.find('[data-testid="musician-key-select"]').setValue('D')
    await flushPromises()
    expect(w.find('[data-testid="musician-key-reset"]').exists()).toBe(true)

    await w.find('[data-testid="musician-key-reset"]').trigger('click')
    await flushPromises()
    // Lyric body should no longer contain the transposed Bm chord.
    expect(w.find('[data-testid="musician-lyrics"]').text()).not.toContain('Bm')
    expect(w.find('[data-testid="musician-key-reset"]').exists()).toBe(false)
  })

  it('renders the sheet viewer when sheets are present', async () => {
    const w = await mountView()
    expect(w.find('[data-testid="musician-sheets"]').exists()).toBe(true)
    const iframe = w.find('[data-testid="sheet-pdf"]')
    expect(iframe.exists()).toBe(true)
    expect(iframe.attributes('src')).toContain(SHEET.url)
  })

  it('embeds a YouTube preview when preview_url is a YouTube link', async () => {
    const w = await mountView()
    const yt = w.find('[data-testid="preview-youtube"]')
    expect(yt.exists()).toBe(true)
    expect(yt.attributes('src')).toBe('https://www.youtube.com/embed/CDdvReNKKuk')
  })
})
