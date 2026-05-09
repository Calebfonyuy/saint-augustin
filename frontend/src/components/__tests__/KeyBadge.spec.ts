// Tiny smoke test — the badge renders the key text, and suppresses itself
// when the key is missing (so library rows don't show an empty pill).
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import KeyBadge from '@/components/KeyBadge.vue'

describe('KeyBadge', () => {
  it('renders the key', () => {
    const w = mount(KeyBadge, { props: { musicalKey: 'F#' } })
    expect(w.text()).toBe('F#')
    expect(w.find('.key-badge').exists()).toBe(true)
  })

  it('renders nothing when the key is null', () => {
    const w = mount(KeyBadge, { props: { musicalKey: null } })
    expect(w.find('.key-badge').exists()).toBe(false)
  })
})
