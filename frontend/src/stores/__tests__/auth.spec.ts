// Auth store integration tests.
// The api/auth module is mocked so we can assert what the store does with
// successful and failing responses without hitting the network.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/api/auth', () => ({
  login: vi.fn(),
  logout: vi.fn(),
  refresh: vi.fn(),
}))

import * as authApi from '@/api/auth'
import { useAuthStore } from '@/stores/auth'
import { getStoredToken } from '@/api/client'
import type { TokenResponse } from '@/types'

const tokenResponse: TokenResponse = {
  token_type: 'Bearer',
  access_token: 'fresh-token',
  user: {
    id: 'user-1',
    email: 'marie@example.com',
    display_name: 'Marie R.',
    roles: ['admin', 'musician'],
  },
}

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.resetAllMocks()
  })

  it('starts unauthenticated', () => {
    const auth = useAuthStore()
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.isAdmin).toBe(false)
  })

  it('stores the token and user on successful login', async () => {
    vi.mocked(authApi.login).mockResolvedValue(tokenResponse)
    const auth = useAuthStore()
    await auth.login('marie@example.com', 'secret')

    expect(auth.isAuthenticated).toBe(true)
    expect(auth.isAdmin).toBe(true)
    expect(auth.canEditSongs).toBe(true)
    expect(auth.user?.email).toBe('marie@example.com')
    expect(getStoredToken()).toBe('fresh-token')
  })

  it('propagates login errors without touching local state', async () => {
    vi.mocked(authApi.login).mockRejectedValue(new Error('401'))
    const auth = useAuthStore()
    await expect(auth.login('x@y.z', 'nope')).rejects.toThrow('401')
    expect(auth.isAuthenticated).toBe(false)
    expect(getStoredToken()).toBeNull()
  })

  it('clears state on logout even when the API call fails', async () => {
    vi.mocked(authApi.login).mockResolvedValue(tokenResponse)
    vi.mocked(authApi.logout).mockRejectedValue(new Error('network down'))
    const auth = useAuthStore()
    await auth.login('marie@example.com', 'secret')
    await auth.logout()
    expect(auth.isAuthenticated).toBe(false)
    expect(getStoredToken()).toBeNull()
  })

  it('restores the session via refresh on init() when a token is stored', async () => {
    localStorage.setItem('sa_token', 'stale-token')
    vi.mocked(authApi.refresh).mockResolvedValue(tokenResponse)
    const auth = useAuthStore()
    await auth.init()
    expect(authApi.refresh).toHaveBeenCalled()
    expect(auth.user?.id).toBe('user-1')
    expect(getStoredToken()).toBe('fresh-token')
  })

  it('discards a stored token when refresh fails', async () => {
    localStorage.setItem('sa_token', 'dead-token')
    vi.mocked(authApi.refresh).mockRejectedValue(new Error('401'))
    const auth = useAuthStore()
    await auth.init()
    expect(auth.isAuthenticated).toBe(false)
    expect(getStoredToken()).toBeNull()
  })

  it('init() is a no-op when nothing is stored', async () => {
    const auth = useAuthStore()
    await auth.init()
    expect(authApi.refresh).not.toHaveBeenCalled()
    expect(auth.isAuthenticated).toBe(false)
  })
})
