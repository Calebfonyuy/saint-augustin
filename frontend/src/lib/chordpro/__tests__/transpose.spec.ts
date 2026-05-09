import { describe, expect, it } from 'vitest'
import { keyPrefersFlats, transposeChord, transposeChordPro } from '../transpose'

describe('keyPrefersFlats', () => {
  it('returns true for flat major keys', () => {
    expect(keyPrefersFlats('F')).toBe(true)
    expect(keyPrefersFlats('Bb')).toBe(true)
    expect(keyPrefersFlats('Eb')).toBe(true)
    expect(keyPrefersFlats('Ab')).toBe(true)
    expect(keyPrefersFlats('Db')).toBe(true)
  })

  it('returns true for flat minor keys', () => {
    expect(keyPrefersFlats('Dm')).toBe(true)
    expect(keyPrefersFlats('Gm')).toBe(true)
    expect(keyPrefersFlats('Cm')).toBe(true)
  })

  it('returns false for sharp keys and C', () => {
    expect(keyPrefersFlats('C')).toBe(false)
    expect(keyPrefersFlats('G')).toBe(false)
    expect(keyPrefersFlats('D')).toBe(false)
    expect(keyPrefersFlats('A')).toBe(false)
    expect(keyPrefersFlats('E')).toBe(false)
    expect(keyPrefersFlats('B')).toBe(false)
    expect(keyPrefersFlats('F#')).toBe(false)
    expect(keyPrefersFlats('Am')).toBe(false)
    expect(keyPrefersFlats('Em')).toBe(false)
  })

  it('tolerates whitespace and case variations', () => {
    expect(keyPrefersFlats(' bb ')).toBe(true)
    expect(keyPrefersFlats('Bb major')).toBe(true)
    expect(keyPrefersFlats('d minor')).toBe(true)
  })
})

describe('transposeChord — same key', () => {
  it('returns the chord unchanged when from === to', () => {
    expect(transposeChord('C', 'C', 'C')).toBe('C')
    expect(transposeChord('Am7', 'G', 'G')).toBe('Am7')
    expect(transposeChord('F#m', 'D', 'D')).toBe('F#m')
  })
})

describe('transposeChord — whole-step transpositions', () => {
  it('C → D shifts every chord up two semitones', () => {
    expect(transposeChord('C', 'C', 'D')).toBe('D')
    expect(transposeChord('F', 'C', 'D')).toBe('G')
    expect(transposeChord('G', 'C', 'D')).toBe('A')
    expect(transposeChord('Am', 'C', 'D')).toBe('Bm')
  })

  it('G → A keeps quality suffixes intact', () => {
    expect(transposeChord('G', 'G', 'A')).toBe('A')
    expect(transposeChord('Em', 'G', 'A')).toBe('F#m')
    expect(transposeChord('Cmaj7', 'G', 'A')).toBe('Dmaj7')
    expect(transposeChord('D7', 'G', 'A')).toBe('E7')
  })
})

describe('transposeChord — half-step / boundary cases', () => {
  it('handles the B → C boundary cleanly (no B# or Cb)', () => {
    // B major up a semitone is C major.
    expect(transposeChord('B', 'B', 'C')).toBe('C')
    expect(transposeChord('E', 'B', 'C')).toBe('F')
    expect(transposeChord('F#m', 'B', 'C')).toBe('Gm')
  })

  it('handles the E → F boundary cleanly (no E# or Fb)', () => {
    expect(transposeChord('E', 'E', 'F')).toBe('F')
    expect(transposeChord('A', 'E', 'F')).toBe('Bb') // F major prefers flats
    expect(transposeChord('B', 'E', 'F')).toBe('C')
  })

  it('half-step down: C → B', () => {
    expect(transposeChord('C', 'C', 'B')).toBe('B')
    expect(transposeChord('F', 'C', 'B')).toBe('E')
    expect(transposeChord('Am', 'C', 'B')).toBe('G#m') // B major → sharps
  })
})

describe('transposeChord — sharp vs flat key spelling', () => {
  it('sharp target keys spell accidentals with sharps', () => {
    // C → D: D major has F#, so chord on chroma 6 is F# not Gb.
    expect(transposeChord('Eb', 'C', 'D')).toBe('F') // diatonic
    expect(transposeChord('F#', 'C', 'D')).toBe('G#')
    expect(transposeChord('Bb', 'C', 'D')).toBe('C')
  })

  it('flat target keys spell accidentals with flats', () => {
    // C → F: F major has Bb, so chord on chroma 10 is Bb not A#.
    expect(transposeChord('A', 'C', 'F')).toBe('D')
    expect(transposeChord('G', 'C', 'F')).toBe('C')
    // Chromatic chord: C# in C → F# would land on chroma 6, F prefers flats.
    expect(transposeChord('C#', 'C', 'F')).toBe('Gb')
  })

  it('respells when going from a sharp key to a flat key', () => {
    // G major (sharp) → Bb major (flat). G's F# becomes A.
    expect(transposeChord('G', 'G', 'Bb')).toBe('Bb')
    expect(transposeChord('F#', 'G', 'Bb')).toBe('A')
    // A non-diatonic G#m in G should land on Bm in Bb… no, that's diatonic
    // shifted up a minor third. Try a chromatic case: Eb in G → Gb in Bb.
    expect(transposeChord('Eb', 'G', 'Bb')).toBe('Gb')
  })

  it('respells when going from a flat key to a sharp key', () => {
    // Bb major (flat) → G major (sharp). Bb's Eb becomes C.
    expect(transposeChord('Bb', 'Bb', 'G')).toBe('G')
    expect(transposeChord('Eb', 'Bb', 'G')).toBe('C')
    // Chromatic Db in Bb → Bb in G… let's use a clearer chromatic.
    // Gb in Bb → Eb is on chroma 3 in G; G major prefers sharps so D#.
    expect(transposeChord('Gb', 'Bb', 'G')).toBe('D#')
  })
})

describe('transposeChord — slash chords', () => {
  it('transposes both root and bass', () => {
    expect(transposeChord('C/E', 'C', 'D')).toBe('D/F#')
    expect(transposeChord('G/B', 'C', 'D')).toBe('A/C#')
    expect(transposeChord('Am7/G', 'C', 'D')).toBe('Bm7/A')
  })

  it('respects target-key spelling for the bass note', () => {
    // C → F is +5 semitones. C/E → F/A.
    expect(transposeChord('C/E', 'C', 'F')).toBe('F/A')
    // C → Eb is +3. C/E → Eb/G.
    expect(transposeChord('C/E', 'C', 'Eb')).toBe('Eb/G')
    // C → Eb. C/G (chroma 7) → Eb/Bb.
    expect(transposeChord('C/G', 'C', 'Eb')).toBe('Eb/Bb')
  })
})

describe('transposeChord — extensions and qualities', () => {
  it('preserves sus chords', () => {
    expect(transposeChord('Csus4', 'C', 'D')).toBe('Dsus4')
    expect(transposeChord('Dsus2', 'C', 'D')).toBe('Esus2')
    expect(transposeChord('Gsus', 'C', 'D')).toBe('Asus')
  })

  it('preserves augmented and diminished', () => {
    expect(transposeChord('Caug', 'C', 'D')).toBe('Daug')
    expect(transposeChord('Cdim', 'C', 'D')).toBe('Ddim')
    expect(transposeChord('Bdim7', 'C', 'D')).toBe('C#dim7')
  })

  it('preserves 7th, 9th, 11th, 13th extensions', () => {
    expect(transposeChord('C7', 'C', 'D')).toBe('D7')
    expect(transposeChord('Cmaj7', 'C', 'D')).toBe('Dmaj7')
    expect(transposeChord('Cm7', 'C', 'D')).toBe('Dm7')
    expect(transposeChord('C9', 'C', 'D')).toBe('D9')
    expect(transposeChord('C11', 'C', 'D')).toBe('D11')
    expect(transposeChord('Cm13', 'C', 'D')).toBe('Dm13')
    expect(transposeChord('Cadd9', 'C', 'D')).toBe('Dadd9')
  })
})

describe('transposeChord — pass-through for non-chord input', () => {
  it('leaves unrecognized tokens alone', () => {
    expect(transposeChord('Chorus', 'C', 'D')).toBe('Chorus')
    expect(transposeChord('2x', 'C', 'D')).toBe('2x')
  })
})

describe('transposeChord — minor key transposition', () => {
  it('transposes from a minor key', () => {
    // Am → Bm is +2 semitones.
    expect(transposeChord('Am', 'Am', 'Bm')).toBe('Bm')
    expect(transposeChord('C', 'Am', 'Bm')).toBe('D')
    expect(transposeChord('F', 'Am', 'Bm')).toBe('G')
  })

  it('uses the target minor key signature for spelling', () => {
    // Dm prefers flats (1 flat: Bb).
    expect(transposeChord('A', 'Am', 'Dm')).toBe('D')
    expect(transposeChord('C', 'Am', 'Dm')).toBe('F')
    // Chromatic G# in Am → C# in Dm-land, but Dm prefers flats → Db.
    expect(transposeChord('G#', 'Am', 'Dm')).toBe('Db')
  })
})

describe('transposeChordPro', () => {
  const SOURCE = `{title: Amazing Grace}
[Chorus]
[C]Amazing [G]grace, how [Am]sweet the [F]sound
[C]That saved a [Am]wretch like [G]me
[C/E]I once was [F]lost, but [C/G]now am [G]found`

  it('transposes every chord token while leaving everything else alone', () => {
    const result = transposeChordPro(SOURCE, 'C', 'D')

    expect(result).toContain('{title: Amazing Grace}')
    expect(result).toContain('[Chorus]')
    expect(result).toContain('[D]Amazing [A]grace')
    expect(result).toContain('[Bm]sweet')
    expect(result).toContain('[G]sound')
    expect(result).toContain('[D/F#]I once was')
    expect(result).toContain('[D/A]now am')
  })

  it('returns the source verbatim for a same-key transposition', () => {
    expect(transposeChordPro(SOURCE, 'C', 'C')).toBe(SOURCE)
  })

  it('handles flat target keys (no double sharps)', () => {
    const out = transposeChordPro('[C]hi [E]there [F#]friend', 'C', 'F')
    expect(out).toBe('[F]hi [A]there [B]friend')
  })

  it('handles sharp target keys', () => {
    const out = transposeChordPro('[F]hi [Bb]there', 'F', 'A')
    expect(out).toBe('[A]hi [D]there')
  })

  it('preserves multiple newlines and indentation', () => {
    const src = '[C]line one\n\n  [G]line two\n[Am]line three'
    const out = transposeChordPro(src, 'C', 'D')
    expect(out).toBe('[D]line one\n\n  [A]line two\n[Bm]line three')
  })
})
