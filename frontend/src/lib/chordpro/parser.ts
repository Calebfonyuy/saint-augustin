/*
 * ChordPro chord-symbol extraction.
 *
 * The renderer in ChordProPreview.vue handles the column-aligned rendering
 * for the editor preview. The transposition engine only needs to *find* and
 * *replace* `[chord]` tokens inside ChordPro source — so this module exposes
 * a small surface tightly focused on that job.
 *
 * Chord-symbol grammar (deliberately permissive, matches what worship-music
 * ChordPro charts in the wild actually use):
 *
 *   chord  := root suffix? ( "/" bass )?
 *   root   := [A-G] accidental?
 *   bass   := [A-G] accidental?
 *   accidental := "#" | "b"
 *   suffix := any chars not containing "/" (m, maj7, sus4, add9, dim7, …)
 *
 * Anything that fails this shape is left untouched by the transposer, which
 * matches our "be tolerant" rule from ChordProPreview.
 */

export interface ParsedChord {
  /** Root note, e.g. "C", "F#", "Bb" */
  root: string
  /** Quality/extension suffix, e.g. "", "m", "maj7", "sus4", "add9" */
  suffix: string
  /** Optional bass note for slash chords, e.g. "E" in "C/E" */
  bass: string | null
}

/**
 * Match ChordPro `[chord]` tokens. The chord body itself can contain anything
 * except a closing bracket — we leave validation of the chord body to
 * `parseChord`.
 */
export const CHORD_TOKEN_RE = /\[([^\]]+)\]/g

const CHORD_SHAPE_RE = /^([A-G])([#b]?)([^/]*)(?:\/([A-G])([#b]?))?$/

/**
 * Suffix grammar: a chord suffix is built by concatenating recognized
 * chord-quality atoms. This rejects garbage like `[Chorus]` (which would
 * otherwise parse as root "C" + suffix "horus") while still accepting the
 * full real-world vocabulary of worship-chart chord qualities.
 *
 * Atoms (longest-first so `maj` matches before `m`, etc.):
 *   • Quality words: maj, min, sus, add, aug, dim, alt, no, m, M
 *   • Symbolic qualities: °, ø, Δ
 *   • Digits (extension numbers)
 *   • Accidentals & glue: # b ♭ ♯ + - ( )
 */
const SUFFIX_RE = /^(?:maj|min|sus|add|aug|dim|alt|no|m|M|°|ø|Δ|[0-9]+|[#b♭♯+\-()])*$/

/**
 * Parse a chord symbol like `C`, `Am7`, `F#sus4`, or `C/E` into its parts.
 * Returns `null` if the symbol doesn't match the chord grammar (e.g.
 * comments, lone lyric markers, or malformed input).
 */
export function parseChord(symbol: string): ParsedChord | null {
  const trimmed = symbol.trim()
  if (!trimmed) return null
  const m = CHORD_SHAPE_RE.exec(trimmed)
  if (!m) return null
  const [, rootLetter, rootAcc, suffix, bassLetter, bassAcc] = m
  if (!SUFFIX_RE.test(suffix ?? '')) return null
  return {
    root: rootLetter + rootAcc,
    suffix: suffix ?? '',
    bass: bassLetter ? bassLetter + (bassAcc ?? '') : null,
  }
}

/** Recompose a `ParsedChord` back into its string form. */
export function formatChord(c: ParsedChord): string {
  return c.root + c.suffix + (c.bass ? `/${c.bass}` : '')
}

/**
 * Pull every chord symbol out of a ChordPro source string. Useful for
 * inferring the source key when the user hasn't set one explicitly.
 *
 * Returns chord symbols in document order; duplicates are preserved so
 * callers can run their own frequency analysis if they need it.
 */
export function extractChords(source: string): string[] {
  const out: string[] = []
  for (const m of source.matchAll(CHORD_TOKEN_RE)) {
    const parsed = parseChord(m[1])
    if (parsed) out.push(m[1].trim())
  }
  return out
}
