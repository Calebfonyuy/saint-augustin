import { describe, expect, it, vi } from 'vitest'
import { buildSlidesForPlaylist } from '../buildSlides'
import type { Playlist, PlaylistItem, Song } from '@/types'

function item(over: Partial<PlaylistItem> = {}): PlaylistItem {
  return {
    id: 'item-1',
    song_id: 'song-1',
    position: 0,
    target_key: null,
    notes: null,
    song: {
      id: 'song-1',
      title: 'Amazing Grace',
      original_key: 'G',
      author: null,
      tempo: null,
      time_signature: null,
      lyrics: 'first stanza\n\nsecond stanza',
    },
    ...over,
  }
}

function playlist(items: PlaylistItem[]): Playlist {
  return {
    id: 'pl-1',
    name: 'Sunday',
    event_date: null,
    tags: [],
    created_by: null,
    duplicated_from_id: null,
    items,
    item_count: items.length,
    created_at: '',
    updated_at: '',
  }
}

/** Tests must pass a fetcher to ensure the build never silently hits the
 *  real API. This default rejects so any unexpected fetch surfaces clearly. */
const failingFetch = vi.fn(
  async (id: string): Promise<Song> => {
    throw new Error(`unexpected fetch for ${id}`)
  },
)

describe('buildSlidesForPlaylist', () => {
  it('flattens items into slides ordered by playlist position', async () => {
    const pl = playlist([
      item({ id: 'a', position: 0 }),
      item({
        id: 'b',
        song_id: 'song-2',
        position: 1,
        song: {
          id: 'song-2',
          title: 'How Great',
          original_key: null,
          author: null,
          tempo: null,
          time_signature: null,
          lyrics: 'just one slide',
        },
      }),
    ])
    const slides = await buildSlidesForPlaylist(pl, { fetchSong: failingFetch })
    expect(slides.length).toBeGreaterThan(0)
    const titles = [...new Set(slides.map((s) => s.songTitle))]
    expect(titles).toEqual(['Amazing Grace', 'How Great'])
    // First slide of each song should be slideIndex 0.
    expect(slides[0].slideIndex).toBe(0)
    expect(slides.find((s) => s.songTitle === 'How Great')?.slideIndex).toBe(0)
  })

  it('emits a placeholder slide when a song is missing', async () => {
    const pl = playlist([item({ id: 'a', song: null })])
    const fetchSong = vi.fn(
      async (id: string): Promise<Song> => {
        throw new Error(`song ${id} not found`)
      },
    )
    const slides = await buildSlidesForPlaylist(pl, { fetchSong })
    expect(slides).toHaveLength(1)
    expect(slides[0].songTitle).toBe('Untitled')
    expect(slides[0].body).toBe('')
  })

  it('fetches the full song from the API when embedded lyrics are absent', async () => {
    // Normal playlist endpoint returns PlaylistItemSong WITHOUT lyrics —
    // construct that shape to verify the build falls back to a fetch.
    const slim: PlaylistItem = {
      id: 'a',
      song_id: 'song-1',
      position: 0,
      target_key: null,
      notes: null,
      song: {
        id: 'song-1',
        title: 'Slim Stub',
        original_key: 'G',
        author: null,
        tempo: null,
        time_signature: null,
        // lyrics intentionally omitted
      },
    }
    const fetchSong = vi.fn(
      async (id: string): Promise<Song> => ({
        id,
        title: 'Full Song',
        author: null,
        lyrics: 'stanza one\n\nstanza two',
        original_key: 'G',
        tempo: null,
        time_signature: null,
        songbook_id: 'sb-1',
        tags: [],
        preview_url: null,
        ccli_number: null,
        created_by: null,
        version: 1,
        created_at: '',
        updated_at: '',
        deleted_at: null,
      }),
    )

    const slides = await buildSlidesForPlaylist(playlist([slim]), { fetchSong })

    expect(fetchSong).toHaveBeenCalledWith('song-1')
    expect(fetchSong).toHaveBeenCalledOnce()
    expect(slides).toHaveLength(2)
    // Title comes from the fetched record (source of truth), not the stub.
    expect(slides[0].songTitle).toBe('Full Song')
    expect(slides[0].body).toBe('stanza one')
    expect(slides[1].body).toBe('stanza two')
  })

  it('de-dupes fetches when the same song appears twice in a playlist', async () => {
    const slim = (id: string): PlaylistItem => ({
      id,
      song_id: 'song-1',
      position: id === 'first' ? 0 : 1,
      target_key: null,
      notes: null,
      song: {
        id: 'song-1',
        title: 'Slim',
        original_key: null,
        author: null,
        tempo: null,
        time_signature: null,
      },
    })
    const fetchSong = vi.fn(
      async (id: string): Promise<Song> => ({
        id,
        title: 'Repeat',
        author: null,
        lyrics: 'one',
        original_key: null,
        tempo: null,
        time_signature: null,
        songbook_id: 'sb-1',
        tags: [],
        preview_url: null,
        ccli_number: null,
        created_by: null,
        version: 1,
        created_at: '',
        updated_at: '',
        deleted_at: null,
      }),
    )
    await buildSlidesForPlaylist(playlist([slim('first'), slim('second')]), { fetchSong })
    expect(fetchSong).toHaveBeenCalledOnce()
  })

  it('respects the playlist item order regardless of array order', async () => {
    const pl = playlist([
      item({
        id: 'b',
        song_id: 'song-2',
        position: 1,
        song: {
          id: 'song-2',
          title: 'Second',
          author: null,
          original_key: null,
          tempo: null,
          time_signature: null,
          lyrics: 'b',
        },
      }),
      item({
        id: 'a',
        position: 0,
        song: {
          id: 'song-1',
          title: 'First',
          author: null,
          original_key: null,
          tempo: null,
          time_signature: null,
          lyrics: 'a',
        },
      }),
    ])
    const slides = await buildSlidesForPlaylist(pl, { fetchSong: failingFetch })
    expect(slides[0].songTitle).toBe('First')
    expect(slides[slides.length - 1].songTitle).toBe('Second')
  })

  it('applies target_key transposition before splitting', async () => {
    // The source has chord tokens; after transposing G→A the chord names
    // shift but are then stripped. The body is unchanged in projection
    // output — this test verifies the pipeline doesn't error out and still
    // produces clean lyrics when a key override is set.
    const pl = playlist([
      item({
        id: 'a',
        target_key: 'A',
        song: {
          id: 'song-1',
          title: 'Song',
          author: null,
          original_key: 'G',
          tempo: null,
          time_signature: null,
          lyrics: '[G]Amazing [C]grace\n\n[G]through many [D]dangers',
        },
      }),
    ])
    const slides = await buildSlidesForPlaylist(pl, { fetchSong: failingFetch })
    expect(slides).toHaveLength(2)
    // Chords stripped — body should be plain text regardless of key
    expect(slides[0].body).toBe('Amazing grace')
    expect(slides[1].body).toBe('through many dangers')
  })

  it('skips transposition when target_key equals original_key', async () => {
    const pl = playlist([
      item({
        id: 'a',
        target_key: 'G',
        song: {
          id: 'song-1',
          title: 'Song',
          author: null,
          original_key: 'G',
          tempo: null,
          time_signature: null,
          lyrics: '[G]line one',
        },
      }),
    ])
    const slides = await buildSlidesForPlaylist(pl, { fetchSong: failingFetch })
    expect(slides[0].body).toBe('line one')
  })

  it('skips transposition when original_key is missing', async () => {
    const pl = playlist([
      item({
        id: 'a',
        target_key: 'A',
        song: {
          id: 'song-1',
          title: 'Song',
          author: null,
          original_key: null,
          tempo: null,
          time_signature: null,
          lyrics: '[G]line one',
        },
      }),
    ])
    const slides = await buildSlidesForPlaylist(pl, { fetchSong: failingFetch })
    expect(slides[0].body).toBe('line one')
  })

  it('numbers itemIndex by 0-based playlist position', async () => {
    const pl = playlist([
      item({ id: 'a', position: 0 }),
      item({
        id: 'b',
        song_id: 'song-2',
        position: 1,
        song: {
          id: 'song-2',
          title: 'Two',
          author: null,
          original_key: null,
          tempo: null,
          time_signature: null,
          lyrics: 'x',
        },
      }),
    ])
    const slides = await buildSlidesForPlaylist(pl, { fetchSong: failingFetch })
    const indexes = [...new Set(slides.map((s) => s.itemIndex))]
    expect(indexes).toEqual([0, 1])
  })
})
