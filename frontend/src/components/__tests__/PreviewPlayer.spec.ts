import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PreviewPlayer from '@/components/PreviewPlayer.vue'

describe('PreviewPlayer', () => {
  it('renders nothing for an empty url', () => {
    const w = mount(PreviewPlayer, { props: { url: '' } })
    expect(w.find('[data-testid="preview-player"]').exists()).toBe(false)
  })

  it('embeds a watch?v= YouTube URL', () => {
    const w = mount(PreviewPlayer, {
      props: { url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' },
    })
    const yt = w.find('[data-testid="preview-youtube"]')
    expect(yt.exists()).toBe(true)
    expect(yt.attributes('src')).toBe('https://www.youtube.com/embed/dQw4w9WgXcQ')
  })

  it('embeds a youtu.be short URL', () => {
    const w = mount(PreviewPlayer, {
      props: { url: 'https://youtu.be/abcDEF12345' },
    })
    expect(w.find('[data-testid="preview-youtube"]').attributes('src')).toBe(
      'https://www.youtube.com/embed/abcDEF12345',
    )
  })

  it('renders an audio element for an .mp3 url', () => {
    const w = mount(PreviewPlayer, {
      props: { url: 'https://example.com/song.mp3' },
    })
    expect(w.find('[data-testid="preview-audio"]').exists()).toBe(true)
    expect(w.find('[data-testid="preview-youtube"]').exists()).toBe(false)
  })

  it('falls back to a plain link for unknown URL types', () => {
    const w = mount(PreviewPlayer, {
      props: { url: 'https://example.com/some-page' },
    })
    expect(w.find('[data-testid="preview-link"]').exists()).toBe(true)
    expect(w.find('[data-testid="preview-link"]').attributes('href')).toBe(
      'https://example.com/some-page',
    )
  })
})
