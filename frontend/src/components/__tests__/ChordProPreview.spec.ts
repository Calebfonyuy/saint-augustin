// ChordPro parsing checks — covers directives, inline chords, and the
// column-alignment invariant (chord row and lyric row are the same width so
// CSS `white-space: pre` keeps chords over the right syllables).
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ChordProPreview from '@/components/ChordProPreview.vue'

describe('ChordProPreview', () => {
  it('shows a friendly empty state', () => {
    const w = mount(ChordProPreview, { props: { source: '' } })
    expect(w.text()).toContain('No lyrics yet')
  })

  it('renders section labels from {start_of_verse}', () => {
    const src = '{start_of_verse: Verse 1}\n[G]Amazing [C]grace\n{end_of_verse}'
    const w = mount(ChordProPreview, { props: { source: src } })
    expect(w.text()).toContain('Verse 1')
  })

  it('separates chord tokens from lyric text', () => {
    const w = mount(ChordProPreview, { props: { source: '[G]Amazing [C]grace' } })
    const chordLine = w.find('.chordline').text()
    const lyricLine = w.find('.lyricline').text()
    expect(chordLine).toContain('G')
    expect(chordLine).toContain('C')
    expect(lyricLine.trim()).toBe('Amazing grace')
  })

  it('pads chord and lyric rows to equal length', () => {
    const w = mount(ChordProPreview, { props: { source: '[G]Hello [C]world' } })
    const chordLine = w.find('.chordline').element.textContent ?? ''
    const lyricLine = w.find('.lyricline').element.textContent ?? ''
    expect(chordLine.length).toBe(lyricLine.length)
  })
})
