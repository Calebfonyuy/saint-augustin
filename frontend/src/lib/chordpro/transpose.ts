/*
 * Chord transposition.
 *
 * We do chroma-based transposition (semitone-class arithmetic) rather than
 * letter-name arithmetic. The reason: in worship charts the user generally
 * cares about "this should sound right in the new key" rather than strict
 * music-theory letter spelling. Chroma math also sidesteps Tonal's tendency
 * to produce double-sharps and double-flats when intervals are mis-applied.
 *
 * Enharmonic spelling is then chosen by *target key signature*:
 *   • Sharp keys (G, D, A, E, B, F#, C# and their relative minors) → sharps.
 *   • Flat keys  (F, Bb, Eb, Ab, Db, Gb, Cb and their relative minors) → flats.
 *   • C / Am have no preference — we default to sharps, which matches what
 *     most ChordPro charts use for chromatic accidentals.
 */

import { Note } from '@tonaljs/tonal'
import { CHORD_TOKEN_RE, formatChord, parseChord } from './parser'

/**
 * Per-chroma spellings for each accidental preference. Index 0 == C.
 * No double accidentals — every chroma maps to exactly one sharp-spelled
 * and one flat-spelled name.
 */
const SHARP_NAMES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'] as const
const FLAT_NAMES  = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'] as const

/**
 * Keys whose signature contains flats. Minor keys are listed by their tonic
 * directly so callers can pass `Dm`, `Gm`, etc. without us doing relative-
 * major math at lookup time.
 */
const FLAT_KEYS = new Set([
  // Major
  'F', 'Bb', 'Eb', 'Ab', 'Db', 'Gb', 'Cb',
  // Minor (relative of the above majors)
  'Dm', 'Gm', 'Cm', 'Fm', 'Bbm', 'Ebm', 'Abm',
])

/**
 * Decide whether a key's accidentals should be spelled with flats. Anything
 * not explicitly listed as a flat key falls through to sharps — including C
 * and A minor, which have no inherent preference but conventionally use
 * sharp accidentals for chromatic chords.
 */
export function keyPrefersFlats(key: string): boolean {
  return FLAT_KEYS.has(normalizeKey(key))
}

/**
 * Tidy up a user-supplied key string: trim, capitalise the letter, lower-
 * case any trailing `m`. Accepts `c`, `c MAJOR`, `c maj`, `cm`, `c minor`.
 */
function normalizeKey(key: string): string {
  const trimmed = key.trim()
  // Strip "major"/"maj" — they don't change the canonical form.
  const stripped = trimmed.replace(/\s*(major|maj)\s*$/i, '')
  // "minor"/"min" → "m"
  const minorish = stripped.replace(/\s*(minor|min)\s*$/i, 'm')
  if (!minorish) return ''
  // Capitalise the letter, normalize accidentals (b is lowercase, # stays).
  const letter = minorish[0].toUpperCase()
  const rest = minorish.slice(1)
  return letter + rest
}

/**
 * Re-spell a single note (no octave) into the preferred accidental flavour
 * for the target key. Handles double accidentals and oddities by routing
 * through Tonal's chroma calculation, which is letter-spelling-agnostic.
 */
function respellNote(note: string, useFlats: boolean): string {
  const chroma = Note.chroma(note)
  if (chroma == null) return note
  return useFlats ? FLAT_NAMES[chroma] : SHARP_NAMES[chroma]
}

/**
 * Number of semitones to shift when going `from` → `to`. Always returns a
 * value in [0, 12) — direction is irrelevant for chroma-based transposition
 * since pitches wrap modulo 12.
 */
function semitonesBetween(from: string, to: string): number {
  const a = Note.chroma(stripQuality(from))
  const b = Note.chroma(stripQuality(to))
  if (a == null || b == null) return 0
  return ((b - a) % 12 + 12) % 12
}

/** Strip an `m`/`maj`/`minor` qualifier from a key so we get just the tonic. */
function stripQuality(key: string): string {
  return normalizeKey(key).replace(/m$/, '')
}

/**
 * Shift a note (no octave attached) by N semitones, returning the spelling
 * preferred for `useFlats`.
 */
function shiftNote(note: string, semitones: number, useFlats: boolean): string {
  const chroma = Note.chroma(note)
  if (chroma == null) return note
  const next = ((chroma + semitones) % 12 + 12) % 12
  return useFlats ? FLAT_NAMES[next] : SHARP_NAMES[next]
}

/**
 * Transpose a single chord symbol from one key to another.
 *
 * The chord's root and (if present) bass are shifted by the same number of
 * semitones as the key change. The suffix (`m`, `maj7`, `sus4`, `add9`, …)
 * is preserved verbatim — it doesn't depend on the key.
 *
 * Symbols that don't parse as chords are returned untouched. That's
 * deliberate: ChordPro tokens like `[Chorus]` or `[2x]` aren't chords and
 * shouldn't be mangled.
 */
export function transposeChord(symbol: string, fromKey: string, toKey: string): string {
  const parsed = parseChord(symbol)
  if (!parsed) return symbol

  const semis = semitonesBetween(fromKey, toKey)
  const useFlats = keyPrefersFlats(toKey)

  // No-op fast path keeps the source spelling intact when the user picks
  // the same key (e.g. if the UI auto-fires transpose on every key change).
  if (semis === 0) return formatChord(parsed)

  const newRoot = shiftNote(parsed.root, semis, useFlats)
  const newBass = parsed.bass ? shiftNote(parsed.bass, semis, useFlats) : null

  return formatChord({ root: newRoot, suffix: parsed.suffix, bass: newBass })
}

/**
 * Transpose every `[chord]` token in a ChordPro source string. Lyrics,
 * directives, and non-chord bracket tokens (like `[Chorus]`) pass through
 * unchanged.
 */
export function transposeChordPro(source: string, fromKey: string, toKey: string): string {
  const semis = semitonesBetween(fromKey, toKey)
  if (semis === 0) return source
  const useFlats = keyPrefersFlats(toKey)

  return source.replace(CHORD_TOKEN_RE, (full, body: string) => {
    const parsed = parseChord(body)
    if (!parsed) return full
    const root = shiftNote(parsed.root, semis, useFlats)
    const bass = parsed.bass ? shiftNote(parsed.bass, semis, useFlats) : null
    return `[${formatChord({ root, suffix: parsed.suffix, bass })}]`
  })
}

// Exposed for tests that want to bypass key inference.
export const __internals = { respellNote, shiftNote, semitonesBetween, normalizeKey }
