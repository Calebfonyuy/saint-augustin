import { describe, expect, it } from 'vitest'
import { splitSongIntoSlides } from '../slides'

const base = { itemIndex: 0, idPrefix: 'item-1', songTitle: 'Amazing Grace' }

describe('splitSongIntoSlides', () => {
  it('returns a single placeholder slide for empty lyrics', () => {
    const out = splitSongIntoSlides({ ...base, lyrics: '' })
    expect(out).toHaveLength(1)
    expect(out[0].body).toBe('')
    expect(out[0].slideIndex).toBe(0)
    expect(out[0].id).toBe('item-1-0')
    expect(out[0].section).toBeNull()
  })

  it('treats blank lines as stanza boundaries', () => {
    const lyrics = ['line one', 'line two', '', 'line three', 'line four'].join('\n')
    const out = splitSongIntoSlides({ ...base, lyrics })
    expect(out).toHaveLength(2)
    expect(out[0].body).toBe('line one\nline two')
    expect(out[1].body).toBe('line three\nline four')
    expect(out[0].slideIndex).toBe(0)
    expect(out[1].slideIndex).toBe(1)
  })

  it('strips chord tokens from emitted text', () => {
    const lyrics = '[C]Amazing [F]grace, how [G]sweet the [C]sound'
    const out = splitSongIntoSlides({ ...base, lyrics })
    expect(out).toHaveLength(1)
    expect(out[0].body).toBe('Amazing grace, how sweet the sound')
  })

  it('numbers verses when section directives have no value', () => {
    const lyrics = [
      '{start_of_verse}',
      'first verse',
      '{end_of_verse}',
      '',
      '{sov}',
      'second verse',
      '{eov}',
    ].join('\n')
    const out = splitSongIntoSlides({ ...base, lyrics })
    expect(out.map((s) => s.section)).toEqual(['Verse 1', 'Verse 2'])
    expect(out.map((s) => s.body)).toEqual(['first verse', 'second verse'])
  })

  it('uses the directive value as the section label when provided', () => {
    const lyrics = ['{start_of_chorus: Refrain}', 'sing it', '{end_of_chorus}'].join('\n')
    const out = splitSongIntoSlides({ ...base, lyrics })
    expect(out[0].section).toBe('Refrain')
  })

  it('applies {comment: …} as the section label without forcing a boundary', () => {
    const lyrics = ['{c: Pre-Chorus}', 'lift it up', '', 'and again'].join('\n')
    const out = splitSongIntoSlides({ ...base, lyrics })
    expect(out).toHaveLength(2)
    expect(out[0].section).toBe('Pre-Chorus')
    expect(out[1].section).toBe('Pre-Chorus')
  })

  it('skips non-section directives like {title} and {key}', () => {
    const lyrics = ['{title: ignore me}', '{key: G}', 'real lyric'].join('\n')
    const out = splitSongIntoSlides({ ...base, lyrics })
    expect(out).toHaveLength(1)
    expect(out[0].body).toBe('real lyric')
    expect(out[0].section).toBeNull()
  })

  it('emits stable ids using idPrefix and slideIndex', () => {
    const lyrics = ['a', '', 'b', '', 'c'].join('\n')
    const out = splitSongIntoSlides({ ...base, idPrefix: 'pl-item-9', lyrics })
    expect(out.map((s) => s.id)).toEqual(['pl-item-9-0', 'pl-item-9-1', 'pl-item-9-2'])
  })

  it('drops lines that contain only chord tokens', () => {
    const lyrics = ['[C]    [G]    [Am]', 'real words here'].join('\n')
    const out = splitSongIntoSlides({ ...base, lyrics })
    expect(out).toHaveLength(1)
    expect(out[0].body).toBe('real words here')
  })

  it('flushes the stanza buffer at the start of a new section directive', () => {
    const lyrics = ['intro one', 'intro two', '{soc}', 'chorus line', '{eoc}'].join('\n')
    const out = splitSongIntoSlides({ ...base, lyrics })
    expect(out).toHaveLength(2)
    expect(out[0].section).toBeNull()
    expect(out[0].body).toBe('intro one\nintro two')
    expect(out[1].section).toBe('Chorus 1')
    expect(out[1].body).toBe('chorus line')
  })
})
