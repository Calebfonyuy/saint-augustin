// Slide splitter — turns ChordPro lyrics into projection-ready slides.
//
// Both the controller (preview) and display (rendering) consume the same
// Slide[] shape, so this lives in `lib/` rather than next to either view.
// The wire shape mirrors the backend's SlideDto exactly (see
// services/projection/src/sessions/dto/slide.dto.ts) so the controller can
// POST the result straight to the projection service without translation.
//
// Splitting strategy (deliberately tolerant of real-world ChordPro):
//   • Section directives (start_of_verse / soc / sov / sob / start_of_…)
//     reset the section label and force a slide boundary.
//   • {comment: …} / {c: …} sets the section label for the slides that
//     follow, without forcing a boundary on its own line.
//   • Blank lines separate stanzas — each stanza becomes one slide.
//   • All `[chord]` tokens and any `{…}` directives are stripped from the
//     emitted body. The output is plain projector-ready text.
//
// What we do NOT do here: chord rendering, transpose, paginate by line
// count. Slide bodies are the natural stanza chunks; if a worship leader
// wants finer slicing they can rewrite the lyrics with explicit blank
// lines.
import { CHORD_TOKEN_RE } from '@/lib/chordpro'

/** Mirror of services/projection/src/sessions/dto/slide.dto.ts. */
export interface Slide {
  id: string
  itemIndex: number
  slideIndex: number
  songTitle: string
  section: string | null
  body: string
  /** Parent item kind (FR-PL-2). Omitted/'song' for song slides. */
  kind?: 'song' | 'scripture'
  /** Resolved reference label for scripture slides. */
  reference?: string | null
}

/** A directive of the form `{name: value}` or `{name}`. */
interface Directive {
  name: string
  value: string | null
}

const DIRECTIVE_RE = /^\{\s*([a-zA-Z_]+)\s*(?::\s*(.*?))?\s*\}\s*$/

/** Recognised "start of section" directives → display label. */
const SECTION_START: Record<string, string> = {
  start_of_verse: 'Verse',
  sov: 'Verse',
  start_of_chorus: 'Chorus',
  soc: 'Chorus',
  start_of_bridge: 'Bridge',
  sob: 'Bridge',
  start_of_tab: 'Tab',
  sot: 'Tab',
  start_of_grid: 'Grid',
  sog: 'Grid',
}

/** Recognised "end of section" directives — clear the section label. */
const SECTION_END = new Set([
  'end_of_verse',
  'eov',
  'end_of_chorus',
  'eoc',
  'end_of_bridge',
  'eob',
  'end_of_tab',
  'eot',
  'end_of_grid',
  'eog',
])

/** Comment directives carry section labels (e.g. "Verse 1", "Pre-Chorus"). */
const COMMENT_DIRECTIVES = new Set(['comment', 'c', 'comment_italic', 'ci', 'comment_box', 'cb'])

function parseDirective(line: string): Directive | null {
  const m = DIRECTIVE_RE.exec(line.trim())
  if (!m) return null
  return { name: m[1].toLowerCase(), value: m[2] ?? null }
}

/** Strip `[chord]` tokens and collapse the resulting double-spaces. */
function stripChords(line: string): string {
  return line.replace(CHORD_TOKEN_RE, '').replace(/\s+/g, ' ').trim()
}

/**
 * Split a single song's ChordPro source into slides.
 *
 * `itemIndex` is the playlist-item position; `idPrefix` is used to mint
 * stable per-slide ids (typically the playlist-item id). Slides for an
 * item with empty/whitespace-only lyrics produce a single placeholder
 * slide carrying the title — the projector still has something to show
 * when the controller jumps to that song.
 */
export function splitSongIntoSlides(args: {
  itemIndex: number
  idPrefix: string
  songTitle: string
  lyrics: string
}): Slide[] {
  const { itemIndex, idPrefix, songTitle, lyrics } = args
  const lines = (lyrics ?? '').split(/\r?\n/)

  // Walking state.
  let currentSection: string | null = null
  // Counts of un-numbered section starts so successive verses get
  // "Verse 1", "Verse 2", etc.
  const sectionCounts: Record<string, number> = {}
  let buffer: string[] = []
  let bufferSection: string | null = null
  const stanzas: { section: string | null; body: string }[] = []

  function flush(): void {
    if (buffer.length === 0) return
    const body = buffer.join('\n').trimEnd()
    if (body.length > 0) {
      stanzas.push({ section: bufferSection, body })
    }
    buffer = []
  }

  for (const raw of lines) {
    const line = raw ?? ''
    const trimmed = line.trim()

    // Blank line → stanza boundary.
    if (trimmed === '') {
      flush()
      // The next stanza inherits whatever section we're in.
      bufferSection = currentSection
      continue
    }

    const directive = parseDirective(trimmed)
    if (directive) {
      const { name, value } = directive
      // Section start: flush the prior stanza, set label, mint a number
      // when the directive didn't carry one.
      if (name in SECTION_START) {
        flush()
        const base = SECTION_START[name]
        let label: string
        if (value && value.trim()) {
          label = value.trim()
        } else {
          sectionCounts[base] = (sectionCounts[base] ?? 0) + 1
          label = `${base} ${sectionCounts[base]}`
        }
        currentSection = label
        bufferSection = label
        continue
      }
      // Section end: flush, clear label.
      if (SECTION_END.has(name)) {
        flush()
        currentSection = null
        bufferSection = null
        continue
      }
      // Comment-style label: doesn't force a boundary; just relabels the
      // forthcoming stanza. If a stanza is already mid-build we update its
      // label too — comments often appear *inside* the section.
      if (COMMENT_DIRECTIVES.has(name) && value && value.trim()) {
        currentSection = value.trim()
        bufferSection = currentSection
        continue
      }
      // Any other directive (title, key, capo, tempo, …) is metadata —
      // skip it, it doesn't appear on the slide.
      continue
    }

    const text = stripChords(line)
    if (text === '') {
      // The line was only chord tokens — treat as blank for slide
      // purposes (no point in projecting an empty line of accompaniment).
      continue
    }
    if (buffer.length === 0) {
      bufferSection = currentSection
    }
    buffer.push(text)
  }
  flush()

  if (stanzas.length === 0) {
    return [
      {
        id: `${idPrefix}-0`,
        itemIndex,
        slideIndex: 0,
        songTitle,
        section: null,
        body: '',
      },
    ]
  }

  return stanzas.map((s, i) => ({
    id: `${idPrefix}-${i}`,
    itemIndex,
    slideIndex: i,
    songTitle,
    section: s.section,
    body: s.body,
  }))
}
