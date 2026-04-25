import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import Metronome from '@/components/Metronome.vue'

// Minimal AudioContext stub — every call is a no-op so the metronome can run
// its scheduling logic in jsdom without exploding on Web Audio.
function stubAudio() {
  vi.stubGlobal(
    'AudioContext',
    vi.fn().mockImplementation(() => ({
      state: 'running',
      currentTime: 0,
      resume: vi.fn(),
      createOscillator: () => ({
        frequency: { value: 0 },
        connect: () => ({ connect: () => undefined }),
        start: vi.fn(),
        stop: vi.fn(),
      }),
      createGain: () => ({
        gain: {
          setValueAtTime: vi.fn(),
          linearRampToValueAtTime: vi.fn(),
          exponentialRampToValueAtTime: vi.fn(),
        },
        connect: () => ({ connect: () => undefined }),
      }),
      destination: {},
    })),
  )
}

describe('Metronome', () => {
  beforeEach(() => {
    stubAudio()
    vi.useFakeTimers()
  })

  it('renders the configured BPM and time signature', () => {
    const w = mount(Metronome, { props: { tempo: 90, timeSignature: '3/4' } })
    expect(w.find<HTMLInputElement>('[data-testid="metronome-bpm"]').element.value).toBe('90')
    // Three beat dots for 3/4.
    const beats = w.find('[data-testid="metronome-beats"]')
    expect(beats.findAll('span')).toHaveLength(3)
  })

  it('falls back to 4 beats and 90 BPM when the song has no metadata', () => {
    const w = mount(Metronome, { props: { tempo: null, timeSignature: null } })
    expect(w.find<HTMLInputElement>('[data-testid="metronome-bpm"]').element.value).toBe('90')
    expect(w.find('[data-testid="metronome-beats"]').findAll('span')).toHaveLength(4)
  })

  it('toggles between Start and Stop', async () => {
    const w = mount(Metronome, { props: { tempo: 60, timeSignature: '4/4' } })
    const btn = w.find('[data-testid="metronome-toggle"]')
    expect(btn.text()).toContain('Start')
    await btn.trigger('click')
    expect(btn.text()).toContain('Stop')
    // Advance one tick interval (60bpm = 1s).
    vi.advanceTimersByTime(1000)
    await flushPromises()
    await btn.trigger('click')
    expect(btn.text()).toContain('Start')
  })

  it('bumps BPM with the +/- controls', async () => {
    const w = mount(Metronome, { props: { tempo: 100, timeSignature: '4/4' } })
    await w.find('[data-testid="metronome-bump-up"]').trigger('click')
    expect(w.find<HTMLInputElement>('[data-testid="metronome-bpm"]').element.value).toBe('102')
    await w.find('[data-testid="metronome-bump-down"]').trigger('click')
    await w.find('[data-testid="metronome-bump-down"]').trigger('click')
    expect(w.find<HTMLInputElement>('[data-testid="metronome-bpm"]').element.value).toBe('98')
  })

  it('clamps BPM into the [30, 240] range', async () => {
    const w = mount(Metronome, { props: { tempo: 100, timeSignature: '4/4' } })
    const input = w.find<HTMLInputElement>('[data-testid="metronome-bpm"]')
    await input.setValue('500')
    expect(input.element.value).toBe('240')
    await input.setValue('5')
    expect(input.element.value).toBe('30')
  })
})
