// Theme store — light / dark / system colour scheme.
//
// Three modes:
//   • light  — force the warm-parchment light palette (the historical default).
//   • dark   — force the `.dark` token overrides from tokens.css.
//   • system — follow the OS `prefers-color-scheme` and react to it live.
//
// The effective ("resolved") theme is reflected onto <html> as the `.dark`
// class, which is what tokens.css keys its dark overrides off of. A tiny
// inline script in index.html applies the same class before first paint so
// there's no light-mode flash on load; this store then takes over reactively.
import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'

export const THEME_MODES = ['light', 'dark', 'system'] as const
export type ThemeMode = (typeof THEME_MODES)[number]

const STORAGE_KEY = 'staug.theme'
const DARK_QUERY = '(prefers-color-scheme: dark)'

function isThemeMode(value: unknown): value is ThemeMode {
  return value === 'light' || value === 'dark' || value === 'system'
}

/**
 * Resolve the mode the app should boot with: an explicit stored choice, else
 * `system`. Safe to call outside a browser (tests) — access is guarded.
 */
export function detectThemeMode(): ThemeMode {
  try {
    const stored = globalThis.localStorage?.getItem(STORAGE_KEY)
    if (isThemeMode(stored)) return stored
  } catch {
    // localStorage unavailable (private mode / SSR-ish) — fall through.
  }
  return 'system'
}

function systemPrefersDark(): boolean {
  try {
    return globalThis.matchMedia?.(DARK_QUERY).matches ?? false
  } catch {
    return false
  }
}

export const useThemeStore = defineStore('theme', () => {
  const mode = ref<ThemeMode>(detectThemeMode())
  const systemDark = ref(systemPrefersDark())

  /** The colour scheme actually shown, after resolving `system`. */
  const resolved = computed<'light' | 'dark'>(() =>
    mode.value === 'system' ? (systemDark.value ? 'dark' : 'light') : mode.value,
  )

  /** Reflect the resolved theme onto <html> so tokens.css `.dark` applies. */
  function apply(): void {
    if (typeof document === 'undefined') return
    document.documentElement.classList.toggle('dark', resolved.value === 'dark')
  }

  function setMode(next: ThemeMode): void {
    mode.value = next
    try {
      globalThis.localStorage?.setItem(STORAGE_KEY, next)
    } catch {
      // Persisting is best-effort; the in-memory switch already applied.
    }
  }

  // Keep the DOM class in sync whenever the effective theme changes — whether
  // from a manual mode switch or an OS preference flip while in `system` mode.
  // Sync flush so the class flips in the same tick as the change (no flash,
  // no half-frame of the wrong palette).
  watch(resolved, apply, { immediate: true, flush: 'sync' })

  // Track OS preference changes so `system` mode reacts live (e.g. the user
  // flips their laptop to dark at sunset while the app is open).
  const mql = globalThis.matchMedia?.(DARK_QUERY)
  mql?.addEventListener?.('change', (event: MediaQueryListEvent) => {
    systemDark.value = event.matches
  })

  return { mode, resolved, setMode }
})
