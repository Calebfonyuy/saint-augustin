import { describe, expect, it } from 'vitest'
import { splitScriptureIntoSlides } from '../slides'

describe('splitScriptureIntoSlides', () => {
  it('renders verses with the reference label only on the first slide', () => {
    const slides = splitScriptureIntoSlides({
      itemIndex: 2,
      idPrefix: 'read-1',
      label: 'Jean 3:16 · Segond',
      title: 'Jean 3:16',
      verses: [
        { number: 16, text: 'Car Dieu a tant aimé le monde' },
        { number: 17, text: 'Dieu a envoyé son Fils' },
      ],
    })

    expect(slides).toHaveLength(1)
    expect(slides[0]).toMatchObject({
      itemIndex: 2,
      slideIndex: 0,
      kind: 'scripture',
      reference: 'Jean 3:16 · Segond',
      songTitle: 'Jean 3:16',
      showReference: true,
    })
    expect(slides[0].verses).toHaveLength(2)
  })

  it('splits verses across slides over the character budget; only the first shows the reference', () => {
    const long = 'x'.repeat(200)
    const slides = splitScriptureIntoSlides({
      itemIndex: 0,
      idPrefix: 'r',
      label: 'L',
      title: 'T',
      verses: [
        { number: 1, text: long },
        { number: 2, text: long },
      ],
    })

    expect(slides).toHaveLength(2)
    expect(slides[0].showReference).toBe(true)
    expect(slides[1].showReference).toBe(false)
    expect(slides[0].verses?.[0].number).toBe(1)
    expect(slides[1].verses?.[0].number).toBe(2)
  })
})
