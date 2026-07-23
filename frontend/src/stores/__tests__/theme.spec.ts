// Theme store tests — light / dark / system resolution, persistence, and the
// live reaction to OS `prefers-color-scheme` changes.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// jsdom doesn't implement matchMedia. Install a controllable fake so we can
// assert both the initial resolution and live `change` events.
type Listener = (e: MediaQueryListEvent) => void
let mediaMatches = false
let listeners: Listener[] = []

function emitSystemChange(matches: boolean): void {
  mediaMatches = matches
  for (const l of listeners) l({ matches } as MediaQueryListEvent)
}

beforeEach(() => {
  mediaMatches = false
  listeners = []
  document.documentElement.classList.remove('dark')
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: mediaMatches,
    media: query,
    addEventListener: (_: string, cb: Listener) => listeners.push(cb),
    removeEventListener: (_: string, cb: Listener) => {
      listeners = listeners.filter((l) => l !== cb)
    },
  }))
  setActivePinia(createPinia())
})

// Import after the matchMedia stub is registered so module-eval-time reads
// (detectThemeMode / systemPrefersDark) see the fake.
import { useThemeStore } from '@/stores/theme'

describe('theme store', () => {
  it('defaults to system mode and resolves to light when the OS is light', () => {
    const theme = useThemeStore()
    expect(theme.mode).toBe('system')
    expect(theme.resolved).toBe('light')
    expect(document.documentElement.classList.contains('dark')).toBe(false)
  })

  it('resolves system mode to dark when the OS prefers dark', () => {
    mediaMatches = true
    const theme = useThemeStore()
    expect(theme.resolved).toBe('dark')
    expect(document.documentElement.classList.contains('dark')).toBe(true)
  })

  it('forces dark regardless of the OS preference and persists the choice', () => {
    const theme = useThemeStore()
    theme.setMode('dark')
    expect(theme.resolved).toBe('dark')
    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(localStorage.getItem('staug.theme')).toBe('dark')
  })

  it('forces light even when the OS prefers dark', () => {
    mediaMatches = true
    const theme = useThemeStore()
    theme.setMode('light')
    expect(theme.resolved).toBe('light')
    expect(document.documentElement.classList.contains('dark')).toBe(false)
  })

  it('reacts to OS preference changes while in system mode', () => {
    const theme = useThemeStore()
    expect(theme.resolved).toBe('light')
    emitSystemChange(true)
    expect(theme.resolved).toBe('dark')
    expect(document.documentElement.classList.contains('dark')).toBe(true)
    emitSystemChange(false)
    expect(theme.resolved).toBe('light')
    expect(document.documentElement.classList.contains('dark')).toBe(false)
  })

  it('ignores OS changes once a mode is forced', () => {
    const theme = useThemeStore()
    theme.setMode('light')
    emitSystemChange(true)
    expect(theme.resolved).toBe('light')
    expect(document.documentElement.classList.contains('dark')).toBe(false)
  })

  it('boots from a persisted mode', () => {
    localStorage.setItem('staug.theme', 'dark')
    const theme = useThemeStore()
    expect(theme.mode).toBe('dark')
    expect(theme.resolved).toBe('dark')
  })
})
