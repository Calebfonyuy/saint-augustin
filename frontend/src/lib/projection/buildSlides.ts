// Map a hydrated Playlist into the flat Slide[] expected by the
// Projection Service `POST /sessions` endpoint.
//
// Items whose underlying song is missing (deleted upstream) or has no
// lyrics still produce a placeholder title slide so the controller can
// jump to them and the worship leader knows where they are in the
// service order.
import type { Playlist } from '@/types'
import { splitSongIntoSlides, type Slide } from './slides'

export function buildSlidesForPlaylist(playlist: Playlist): Slide[] {
  const out: Slide[] = []
  const items = [...playlist.items].sort((a, b) => a.position - b.position)
  for (let i = 0; i < items.length; i++) {
    const it = items[i]
    const song = it.song
    const title = song?.title ?? 'Untitled'
    const lyrics = song?.lyrics ?? ''
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
