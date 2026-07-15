// Map a hydrated Playlist into the flat Slide[] expected by the
// Projection Service `POST /sessions` endpoint.
//
// The playlist item's embedded `song` is the slim `PlaylistItemSong` shape
// (no lyrics) for the normal `GET /playlists/:id` response — only the
// public share-link endpoint returns lyrics inline. So whenever we don't
// already have lyrics we go fetch the full Song record from the songs API
// and use that. Fetches are de-duped per song_id so a playlist that lists
// the same song twice only round-trips once.
//
// Items whose underlying song is missing (deleted upstream, or the fetch
// failed) still produce a placeholder title slide so the controller can
// jump to them and the worship leader knows where they are in the
// service order.
//
// Per-song `target_key` overrides are honoured: when a playlist item
// carries a different key than the song's stored `original_key`, the
// ChordPro source is transposed before splitting. Chords are stripped
// from the final slide body (projection shows plain lyrics), so the
// transposition doesn't change the visible text — but it keeps the
// pipeline consistent with the Musician View and ensures the correct
// key metadata is available if the slide model is ever extended.
import { resolveScripture } from '@/api/bible'
import { getSong } from '@/api/songs'
import { transposeChordPro } from '@/lib/chordpro'
import type {
  Playlist,
  PlaylistItem,
  PlaylistItemScripture,
  ResolvedScripture,
  ScriptureQuery,
  Song,
} from '@/types'
import { splitScriptureIntoSlides, splitSongIntoSlides, type Slide } from './slides'

/** Fields the slide pipeline actually reads off a song. */
interface SongSource {
  title: string
  original_key: string | null
  lyrics: string
}

export interface BuildSlidesOptions {
  /** Override for the song fetcher — used by tests to stub the API. */
  fetchSong?: (id: string) => Promise<Song>
  /** Override for the scripture resolver — used by tests to stub the API. */
  fetchScripture?: (query: ScriptureQuery) => Promise<ResolvedScripture>
}

export async function buildSlidesForPlaylist(
  playlist: Playlist,
  opts: BuildSlidesOptions = {},
): Promise<Slide[]> {
  const fetchSong = opts.fetchSong ?? getSong
  const fetchScripture = opts.fetchScripture ?? resolveScripture
  const items = [...playlist.items].sort((a, b) => a.position - b.position)

  // Resolve full song data for every distinct song_id in the playlist.
  // Cache by song_id so we never fetch the same song twice in one build.
  const sources = await resolveSongSources(items, fetchSong)

  // Resolve verses for every distinct scripture reference up front — this is
  // the projection pre-fetch (FR-BI-6): the verses (and the server-side Redis
  // chapter cache) are in hand before the session goes live.
  const readings = await resolveScriptureSources(items, fetchScripture)

  const out: Slide[] = []
  for (let i = 0; i < items.length; i++) {
    const it = items[i]

    // Scripture readings: render the resolved verses across auto-fit slides
    // with the reference label on the first slide (FR-BI-8). If resolution
    // failed (offline with a cold cache), fall back to a single placeholder
    // slide so the service order still shows the reference.
    if (it.item_type === 'scripture' && it.scripture) {
      const resolved = readings.get(scriptureKey(it.scripture)) ?? null
      if (resolved) {
        out.push(
          ...splitScriptureIntoSlides({
            itemIndex: i,
            idPrefix: it.id,
            label: `${resolved.reference_label} · ${resolved.translation_label}`,
            title: resolved.reference_label,
            verses: resolved.verses,
          }),
        )
      } else {
        const reference = it.scripture.reference ?? 'Scripture'
        out.push({
          id: `${it.id}-0`,
          itemIndex: i,
          slideIndex: 0,
          songTitle: reference,
          section: null,
          body: reference,
          kind: 'scripture',
          reference,
          showReference: true,
        })
      }
      continue
    }

    const src = it.song_id ? (sources.get(it.song_id) ?? null) : null
    const title = src?.title ?? it.song?.title ?? 'Untitled'
    let lyrics = src?.lyrics ?? ''
    const originalKey = src?.original_key ?? it.song?.original_key ?? null

    // Apply per-item key override if one is set and differs from the
    // song's stored key. Both keys must be known for transposition to
    // be possible; if either is missing we use the lyrics as-is.
    if (it.target_key && originalKey && it.target_key !== originalKey) {
      lyrics = transposeChordPro(lyrics, originalKey, it.target_key)
    }

    const slides = splitSongIntoSlides({
      itemIndex: i,
      idPrefix: it.id,
      songTitle: title,
      lyrics,
    }).map((s) => ({ ...s, kind: 'song' as const }))
    out.push(...slides)
  }
  return out
}

/**
 * For each unique song_id in the playlist, resolve a SongSource:
 *   • If the embedded `song.lyrics` is already populated (share-link or
 *     ephemeral playlist), use it directly — no API hit.
 *   • Otherwise fetch the full song record. A failed fetch resolves to
 *     null so the calling loop falls back to a placeholder slide.
 */
async function resolveSongSources(
  items: PlaylistItem[],
  fetchSong: (id: string) => Promise<Song>,
): Promise<Map<string, SongSource | null>> {
  const sources = new Map<string, SongSource | null>()
  const toFetch: string[] = []

  for (const it of items) {
    // Scripture items have no song to resolve.
    if (it.item_type === 'scripture' || !it.song_id) continue
    if (sources.has(it.song_id)) continue

    const embedded = it.song
    if (embedded && typeof embedded.lyrics === 'string') {
      sources.set(it.song_id, {
        title: embedded.title,
        original_key: embedded.original_key,
        lyrics: embedded.lyrics,
      })
      continue
    }

    if (embedded?.deleted) {
      // Soft-deleted upstream — no point fetching.
      sources.set(it.song_id, null)
      continue
    }

    toFetch.push(it.song_id)
    // Reserve the slot so we don't queue the same id twice on this pass.
    sources.set(it.song_id, null)
  }

  if (toFetch.length === 0) return sources

  const results = await Promise.all(
    toFetch.map(async (id) => {
      try {
        return { id, song: await fetchSong(id) }
      } catch {
        return { id, song: null }
      }
    }),
  )
  for (const { id, song } of results) {
    if (!song) continue
    sources.set(id, {
      title: song.title,
      original_key: song.original_key,
      lyrics: song.lyrics,
    })
  }
  return sources
}

/** Stable key for a scripture reference, for de-duping resolution. */
function scriptureKey(s: PlaylistItemScripture): string {
  return [s.translation_id, s.book_code, s.start_chapter, s.start_verse, s.end_chapter, s.end_verse].join(':')
}

/**
 * Resolve verses for every distinct scripture reference in the playlist, once
 * each, in parallel. A failed resolution maps to null (the caller renders a
 * placeholder). This is the pre-fetch: it also warms the server chapter cache.
 */
async function resolveScriptureSources(
  items: PlaylistItem[],
  fetchScripture: (query: ScriptureQuery) => Promise<ResolvedScripture>,
): Promise<Map<string, ResolvedScripture | null>> {
  const readings = new Map<string, ResolvedScripture | null>()
  const toFetch: Array<{ key: string; query: ScriptureQuery }> = []

  for (const it of items) {
    if (it.item_type !== 'scripture' || !it.scripture) continue
    const s = it.scripture
    if (!s.book_code || s.start_chapter == null || s.start_verse == null) continue

    const key = scriptureKey(s)
    if (readings.has(key)) continue
    readings.set(key, null) // reserve

    toFetch.push({
      key,
      query: {
        translation_id: s.translation_id,
        book_code: s.book_code,
        start_chapter: s.start_chapter,
        start_verse: s.start_verse,
        end_chapter: s.end_chapter,
        end_verse: s.end_verse,
      },
    })
  }

  if (toFetch.length === 0) return readings

  const results = await Promise.all(
    toFetch.map(async ({ key, query }) => {
      try {
        return { key, resolved: await fetchScripture(query) }
      } catch {
        return { key, resolved: null }
      }
    }),
  )
  for (const { key, resolved } of results) {
    readings.set(key, resolved)
  }
  return readings
}
