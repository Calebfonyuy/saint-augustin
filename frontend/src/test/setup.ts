// Global test setup — stub browser APIs jsdom doesn't ship.
import { vi } from 'vitest'
import { config } from '@vue/test-utils'
import i18n from '@/i18n'

// localStorage is provided by jsdom, but ensure it's clean between tests.
beforeEach(() => {
  localStorage.clear()
})

// Silence router warnings about hash/history mode in tests.
vi.stubGlobal('scrollTo', () => {})

// Install vue-i18n on every mounted component so views that call useI18n()
// don't have to re-wire it. Tests assert on user-visible strings, so we pin
// the locale to English (the locale the existing assertions were written in)
// regardless of whatever the test environment's navigator reports.
i18n.global.locale.value = 'en'
config.global.plugins = [...(config.global.plugins ?? []), i18n]
