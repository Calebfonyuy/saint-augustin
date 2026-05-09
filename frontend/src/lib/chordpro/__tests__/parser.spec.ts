import { describe, expect, it } from 'vitest'
import { extractChords, formatChord, parseChord } from '../parser'

describe('parseChord', () => {
  it('parses a bare major root', () => {
    expect(parseChord('C')).toEqual({ root: 'C', suffix: '', bass: null })
  })

  it('parses a sharp root', () => {
    expect(parseChord('F#')).toEqual({ root: 'F#', suffix: '', bass: null })
  })

  it('parses a flat root', () => {
    expect(parseChord('Bb')).toEqual({ root: 'Bb', suffix: '', bass: null })
  })

  it('parses minor chords', () => {
    expect(parseChord('Am')).toEqual({ root: 'A', suffix: 'm', bass: null })
  })

  it('parses extended qualities', () => {
    expect(parseChord('Cmaj7')).toEqual({ root: 'C', suffix: 'maj7', bass: null })
    expect(parseChord('Dm7')).toEqual({ root: 'D', suffix: 'm7', bass: null })
    expect(parseChord('G9')).toEqual({ root: 'G', suffix: '9', bass: null })
    expect(parseChord('Em11')).toEqual({ root: 'E', suffix: 'm11', bass: null })
    expect(parseChord('Csus4')).toEqual({ root: 'C', suffix: 'sus4', bass: null })
    expect(parseChord('Daug')).toEqual({ root: 'D', suffix: 'aug', bass: null })
    expect(parseChord('Bdim7')).toEqual({ root: 'B', suffix: 'dim7', bass: null })
    expect(parseChord('Cadd9')).toEqual({ root: 'C', suffix: 'add9', bass: null })
  })

  it('parses slash chords', () => {
    expect(parseChord('C/E')).toEqual({ root: 'C', suffix: '', bass: 'E' })
    expect(parseChord('G/B')).toEqual({ root: 'G', suffix: '', bass: 'B' })
    expect(parseChord('D/F#')).toEqual({ root: 'D', suffix: '', bass: 'F#' })
    expect(parseChord('Am7/G')).toEqual({ root: 'A', suffix: 'm7', bass: 'G' })
  })

  it('returns null for non-chord tokens', () => {
    expect(parseChord('Chorus')).toBeNull()
    expect(parseChord('2x')).toBeNull()
    expect(parseChord('Verse 1')).toBeNull()
    expect(parseChord('')).toBeNull()
    expect(parseChord('  ')).toBeNull()
  })

  it('returns null for malformed roots', () => {
    expect(parseChord('Hm')).toBeNull()       // H isn't a valid root letter
    expect(parseChord('#m')).toBeNull()       // missing letter
  })
})

describe('formatChord', () => {
  it('round-trips through parseChord', () => {
    const samples = ['C', 'Am', 'F#m7', 'Cmaj7', 'C/E', 'D/F#', 'Bbsus4', 'G7/B']
    for (const s of samples) {
      const p = parseChord(s)!
      expect(formatChord(p)).toBe(s)
    }
  })
})

describe('extractChords', () => {
  it('pulls all [chord] tokens from a ChordPro source', () => {
    const src = '[C]Amazing [G]grace, how [Am]sweet the [F]sound'
    expect(extractChords(src)).toEqual(['C', 'G', 'Am', 'F'])
  })

  it('preserves duplicates in document order', () => {
    const src = '[C]a [G]b [C]c'
    expect(extractChords(src)).toEqual(['C', 'G', 'C'])
  })

  it('skips non-chord brackets', () => {
    const src = '[Chorus]\n[C]hello [G]world'
    expect(extractChords(src)).toEqual(['C', 'G'])
  })

  it('handles slash chords', () => {
    const src = '[C/E]down [G/B]up'
    expect(extractChords(src)).toEqual(['C/E', 'G/B'])
  })
})
