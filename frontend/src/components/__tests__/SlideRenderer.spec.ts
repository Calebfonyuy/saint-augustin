import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SlideRenderer from '@/components/SlideRenderer.vue'
import type { ProjectionSlide } from '@/types'

function slide(over: Partial<ProjectionSlide> = {}): ProjectionSlide {
  return {
    id: 'slide-1',
    itemIndex: 0,
    slideIndex: 0,
    songTitle: 'Amazing Grace',
    section: null,
    body: 'Amazing grace\nhow sweet the sound',
    ...over,
  }
}

describe('SlideRenderer', () => {
  // ── Slide body rendering ────────────────────────────────────────────────────

  it('renders each body line as a separate element', () => {
    const w = mount(SlideRenderer, { props: { slide: slide() } })
    const lines = w.findAll('.slide-line')
    expect(lines).toHaveLength(2)
    expect(lines[0].text()).toBe('Amazing grace')
    expect(lines[1].text()).toBe('how sweet the sound')
  })

  it('renders a non-breaking space for empty body lines', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide({ body: 'line one\n\nline two' }) },
    })
    const lines = w.findAll('.slide-line')
    // text() trims \u00a0; innerHTML returns the &nbsp; entity — use
    // textContent which decodes HTML entities to raw characters.
    expect(lines[1].element.textContent).toBe('\u00a0')
  })

  // ── Section label ───────────────────────────────────────────────────────────

  it('shows the section label when present', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide({ section: 'Verse 1' }) },
    })
    expect(w.find('.slide-section').text()).toBe('Verse 1')
  })

  it('hides the section element when section is null', () => {
    const w = mount(SlideRenderer, { props: { slide: slide({ section: null }) } })
    expect(w.find('.slide-section').exists()).toBe(false)
  })

  // ── Footer (song title) ─────────────────────────────────────────────────────

  it('shows the song title in the footer for display variant', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide(), variant: 'display' },
    })
    expect(w.find('.slide-footer').text()).toBe('Amazing Grace')
  })

  it('shows the song title in the footer for preview variant', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide(), variant: 'preview' },
    })
    expect(w.find('.slide-footer').text()).toBe('Amazing Grace')
  })

  it('hides the footer for the thumb variant', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide(), variant: 'thumb' },
    })
    expect(w.find('.slide-footer').exists()).toBe(false)
  })

  // ── Empty / null slide ──────────────────────────────────────────────────────

  it('shows "No slide" when slide prop is null', () => {
    const w = mount(SlideRenderer, { props: { slide: null } })
    expect(w.find('.slide-empty').exists()).toBe(true)
  })

  // ── Blackout ────────────────────────────────────────────────────────────────

  it('hides slide content during blackout', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide(), blackout: true },
    })
    expect(w.find('.slide-body').exists()).toBe(false)
  })

  it('shows the blackout hint in preview variant', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide(), blackout: true, variant: 'preview' },
    })
    expect(w.find('.slide-blackout-hint').exists()).toBe(true)
  })

  it('hides the blackout hint in display variant', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide(), blackout: true, variant: 'display' },
    })
    expect(w.find('.slide-blackout-hint').exists()).toBe(false)
  })

  // ── Font scale ──────────────────────────────────────────────────────────────

  it('applies the fontScale multiplier to the body font size', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide(), variant: 'preview', fontScale: 2 },
    })
    // preview base = 32px, scale = 2 → 64px
    expect(w.find('.slide-body').attributes('style')).toContain('64px')
  })

  it('uses a larger base size for display variant', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide(), variant: 'display', fontScale: 1 },
    })
    // display base = 56px (matches prototype projFontSize default)
    expect(w.find('.slide-body').attributes('style')).toContain('56px')
  })

  it('uses a smaller base size for thumb variant', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide(), variant: 'thumb', fontScale: 1 },
    })
    // thumb base = 18px
    expect(w.find('.slide-body').attributes('style')).toContain('18px')
  })

  // ── Background colour ───────────────────────────────────────────────────────

  it('applies the background colour to the container', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide(), background: '#1a1a2e' },
    })
    // JSDOM normalises hex to rgb — use the element.style property directly.
    expect((w.find('.slide-renderer').element as HTMLElement).style.backgroundColor).toBe('rgb(26, 26, 46)')
  })

  it('overrides background with black during blackout', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide(), background: '#1a1a2e', blackout: true },
    })
    const el = w.find('.slide-renderer').element as HTMLElement
    expect(el.style.backgroundColor).toBe('rgb(0, 0, 0)')
  })

  // ── Background image ────────────────────────────────────────────────────────

  it('renders a background image URL when provided', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide(), backgroundImage: 'https://example.com/bg.jpg' },
    })
    // JSDOM may or may not quote the URL inside url(); match both forms.
    expect((w.find('.slide-renderer').element as HTMLElement).style.backgroundImage).toMatch(
      /url\(["']?https:\/\/example\.com\/bg\.jpg["']?\)/,
    )
  })

  it('does not render a background image during blackout', () => {
    const w = mount(SlideRenderer, {
      props: {
        slide: slide(),
        backgroundImage: 'https://example.com/bg.jpg',
        blackout: true,
      },
    })
    expect((w.find('.slide-renderer').element as HTMLElement).style.backgroundImage).toBe('')
  })

  // ── Variant CSS class ───────────────────────────────────────────────────────

  it('applies the correct variant class to the root element', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide(), variant: 'display' },
    })
    expect(w.find('.slide-renderer').classes()).toContain('slide-display')
  })

  // ── Text alignment ──────────────────────────────────────────────────────────

  it('defaults to center text alignment', () => {
    const w = mount(SlideRenderer, { props: { slide: slide() } })
    expect((w.find('.slide-inner').element as HTMLElement).style.textAlign).toBe('center')
  })

  it('applies left text alignment', () => {
    const w = mount(SlideRenderer, { props: { slide: slide(), textAlign: 'left' } })
    expect((w.find('.slide-inner').element as HTMLElement).style.textAlign).toBe('left')
  })

  it('applies right text alignment', () => {
    const w = mount(SlideRenderer, { props: { slide: slide(), textAlign: 'right' } })
    expect((w.find('.slide-inner').element as HTMLElement).style.textAlign).toBe('right')
  })

  // ── Font family ─────────────────────────────────────────────────────────────

  it('applies a custom font family string', () => {
    const w = mount(SlideRenderer, {
      props: { slide: slide(), fontFamily: "'IBM Plex Sans', sans-serif" },
    })
    expect((w.find('.slide-inner').element as HTMLElement).style.fontFamily).toContain('IBM Plex Sans')
  })

  it('uses the design-system display font as the default', () => {
    const w = mount(SlideRenderer, { props: { slide: slide() } })
    // JSDOM can't resolve CSS variables so the value includes the literal
    // var(--font-display) string.
    expect((w.find('.slide-inner').element as HTMLElement).style.fontFamily).toContain('font-display')
  })
})
