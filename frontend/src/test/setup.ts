// Global test setup — stub browser APIs jsdom doesn't ship.
import { vi } from 'vitest'

// localStorage is provided by jsdom, but ensure it's clean between tests.
beforeEach(() => {
  localStorage.clear()
})

// Silence router warnings about hash/history mode in tests.
vi.stubGlobal('scrollTo', () => {})
