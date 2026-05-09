/*
 * Public surface for the ChordPro transposition engine.
 *
 * Import from `@/lib/chordpro` rather than reaching into the individual
 * modules — this lets us refactor internals without touching call sites.
 */

export {
  parseChord,
  formatChord,
  extractChords,
  CHORD_TOKEN_RE,
  type ParsedChord,
} from './parser'

export {
  transposeChord,
  transposeChordPro,
  keyPrefersFlats,
} from './transpose'
