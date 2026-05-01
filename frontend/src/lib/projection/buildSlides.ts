// Map a hydrated Playlist into the flat Slide[] expected by the
// Projection Service `POST /sessions` endpoint.
//
// Items whose underlying song is missing (deleted upstream) or has no
// lyrics still produce a placeholder title slide so the controller can
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
import type { Playlist } from '@/types'
import { transposeChordPro } from '@/lib/chordpro'
import { splitSongIntoSlides, type Slide } from './slides'
import { getSong } from '@/api/songs'

export async function buildSlidesForPlaylist(playlist: Playlist): Promise<Slide[]> {
  const out: Slide[] = []
  const items = [...playlist.items].sort((a, b) => a.position - b.position)
  for (let i = 0; i < items.length; i++) {
    const it = items[i]
    const song = await getSong(it.song_id).catch((_) => {
      // console.error(`Failed to fetch song ${it.song_id} for playlist item ${it.id}:`, err)
      return null
    })

    const title = song?.title ?? 'Untitled'
    let lyrics = song?.lyrics ?? 'Unknown song (lyrics unavailable)'

    // Apply per-item key override if one is set and differs from the
    // song's stored key. Both keys must be known for transposition to
    // be possible; if either is missing we use the lyrics as-is.
    if (it.target_key && song?.original_key && it.target_key !== song.original_key) {
      lyrics = transposeChordPro(lyrics, song.original_key, it.target_key)
    }

    const slides = splitSongIntoSlides({
      itemIndex: i,
      idPrefix: it.id,
      songTitle: title,
      lyrics,
    })
    out.push(...slides)
  }
  return out
}
