// vue-i18n setup.
//
// Two locales are shipped: French (default / fallback) and English. The
// active locale is picked at startup by:
//   1. Reading `localStorage.staug.locale` if the user set a manual override.
//   2. Walking `navigator.languages` for the first base tag we recognise.
//   3. Falling back to French.
//
// We persist the user's manual choice (when one exists) so a French speaker
// on an English browser doesn't get reset on every reload. The detection
// helpers are exported so views/tests can drive the picker explicitly.
import { createI18n } from 'vue-i18n'
import en from './locales/en.json'
import fr from './locales/fr.json'

export const SUPPORTED_LOCALES = ['fr', 'en'] as const
export type Locale = (typeof SUPPORTED_LOCALES)[number]
export const DEFAULT_LOCALE: Locale = 'fr'
const STORAGE_KEY = 'staug.locale'

function isLocale(value: unknown): value is Locale {
  return value === 'fr' || value === 'en'
}

/** Resolve an arbitrary BCP-47 tag (e.g. `en-GB`, `fr-CA`) to a supported base. */
function matchSupported(tag: string | null | undefined): Locale | null {
  if (!tag) return null
  const base = tag.toLowerCase().split('-')[0]
  return isLocale(base) ? base : null
}

/**
 * Pick the locale the app should boot with. Order:
 *   - explicit user choice in localStorage
 *   - first match against `navigator.languages` / `navigator.language`
 *   - DEFAULT_LOCALE
 *
 * Safe to call in non-browser environments (tests) — `navigator`/`localStorage`
 * access is guarded.
 */
export function detectLocale(): Locale {
  // Explicit user choice wins, even if their browser language disagrees.
  try {
    const stored = globalThis.localStorage?.getItem(STORAGE_KEY)
    if (isLocale(stored)) return stored
  } catch {
    // localStorage unavailable (private mode, SSR-ish) — fall through.
  }

  const nav = globalThis.navigator
  const candidates: string[] = []
  if (nav?.languages?.length) candidates.push(...nav.languages)
  if (nav?.language) candidates.push(nav.language)
  for (const c of candidates) {
    const matched = matchSupported(c)
    if (matched) return matched
  }
  return DEFAULT_LOCALE
}

const i18n = createI18n({
  legacy: false, // Composition API mode — `useI18n()` in <script setup>.
  locale: detectLocale(),
  fallbackLocale: DEFAULT_LOCALE,
  // Missing keys log once in dev; in production we silently fall back so
  // half-translated views render French rather than the literal key.
  missingWarn: import.meta.env.DEV,
  fallbackWarn: import.meta.env.DEV,
  messages: { en, fr },
})

/**
 * Switch the active locale at runtime and persist the choice.
 * Also reflects the language on `<html lang="…">` for accessibility / SEO.
 */
export function setLocale(locale: Locale): void {
  i18n.global.locale.value = locale
  try {
    globalThis.localStorage?.setItem(STORAGE_KEY, locale)
  } catch {
    // Persisting is best-effort; the in-memory switch already worked.
  }
  if (typeof document !== 'undefined') {
    document.documentElement.lang = locale
  }
}

// Keep `<html lang>` in sync with the boot-time locale.
if (typeof document !== 'undefined') {
  document.documentElement.lang = i18n.global.locale.value
}

export default i18n
