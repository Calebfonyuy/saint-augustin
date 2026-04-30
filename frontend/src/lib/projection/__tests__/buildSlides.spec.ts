import { describe, expect, it } from 'vitest'
import { buildSlidesForPlaylist } from '../buildSlides'
import type { Playlist, PlaylistItem } from '@/types'

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

describe('buildSlidesForPlaylist', () => {
  it('flattens items into slides ordered by playlist position', () => {
    const pl = playlist([
      item({ id: 'a', position: 0 }),
      item({
        id: 'b',
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
    const slides = buildSlidesForPlaylist(pl)
    expect(slides.length).toBeGreaterThan(0)
    const titles = [...new Set(slides.map((s) => s.songTitle))]
    expect(titles).toEqual(['Amazing Grace', 'How Great'])
    // First slide of each song should be slideIndex 0.
    expect(slides[0].slideIndex).toBe(0)
    expect(slides.find((s) => s.songTitle === 'How Great')?.slideIndex).toBe(0)
  })

  it('emits a placeholder slide when a song is missing', () => {
    const pl = playlist([item({ id: 'a', song: null })])
    const slides = buildSlidesForPlaylist(pl)
    expect(slides).toHaveLength(1)
    expect(slides[0].songTitle).toBe('Untitled')
    expect(slides[0].body).toBe('')
  })

  it('respects the playlist item order regardless of array order', () => {
    const pl = playlist([
      item({
        id: 'b',
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
    const slides = buildSlidesForPlaylist(pl)
    expect(slides[0].songTitle).toBe('First')
    expect(slides[slides.length - 1].songTitle).toBe('Second')
  })

  it('applies target_key transposition before splitting', () => {
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
    const slides = buildSlidesForPlaylist(pl)
    expect(slides).toHaveLength(2)
    // Chords stripped — body should be plain text regardless of key
    expect(slides[0].body).toBe('Amazing grace')
    expect(slides[1].body).toBe('through many dangers')
  })

  it('skips transposition when target_key equals original_key', () => {
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
    const slides = buildSlidesForPlaylist(pl)
    expect(slides[0].body).toBe('line one')
  })

  it('skips transposition when original_key is missing', () => {
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
    const slides = buildSlidesForPlaylist(pl)
    expect(slides[0].body).toBe('line one')
  })

  it('numbers itemIndex by 0-based playlist position', () => {
    const pl = playlist([
      item({ id: 'a', position: 0 }),
      item({
        id: 'b',
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
    const slides = buildSlidesForPlaylist(pl)
    const indexes = [...new Set(slides.map((s) => s.itemIndex))]
    expect(indexes).toEqual([0, 1])
  })
})
