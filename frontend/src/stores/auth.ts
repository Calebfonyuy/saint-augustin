// Auth store — source of truth for the current user + token.
// The raw Bearer token is kept in localStorage (see api/client.ts) so it
// survives page reloads; the store re-hydrates from it in init().
//
// Ref: https://pinia.vuejs.org/core-concepts/
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as authApi from '@/api/auth'
import { clearToken, getStoredToken, storeToken } from '@/api/client'
import type { Role, User } from '@/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  // Hydrate the token synchronously from localStorage on store creation so the
  // router's beforeEach guard sees `isAuthenticated` as true on a hard refresh
  // — otherwise the initial navigation runs before App.vue's onMounted has had
  // a chance to call init(), and the user gets bounced to /login on every reload.
  const token = ref<string | null>(getStoredToken())
  const initializing = ref(false)

  const isAuthenticated = computed(() => !!token.value)
  const hasRole = (r: Role) => computed(() => user.value?.roles.includes(r) ?? false)
  const isAdmin = hasRole('admin')
  const isMusician = hasRole('musician')
  const isProjectionist = hasRole('projectionist')
  /** Admin or Musician can create/edit songs (SRS 3.1.2). */
  const canEditSongs = computed(() => isAdmin.value || isMusician.value)

  async function login(email: string, password: string): Promise<void> {
    const res = await authApi.login(email, password)
    token.value = res.access_token
    user.value = res.user
    storeToken(res.access_token)
  }

  /**
   * Self-service profile update (display_name and/or password). The backend
   * keeps the current Sanctum token alive, so no token rotation is needed —
   * we only need to refresh the cached `user` object.
   */
  async function updateProfile(payload: authApi.UpdateProfilePayload): Promise<void> {
    const res = await authApi.updateProfile(payload)
    user.value = res.user
  }

  async function logout(): Promise<void> {
    try {
      if (token.value) await authApi.logout()
    } catch {
      // Even if the API call fails (expired token, network), clear local state.
    }
    clearLocal()
  }

  /** Local-only clear — used by the 401 interceptor so we don't recurse. */
  function clearLocal(): void {
    user.value = null
    token.value = null
    clearToken()
  }

  /**
   * Restore session from localStorage on app boot. Calls /auth/refresh to
   * validate the stored token and pick up any user/role changes. On failure
   * the token is discarded silently.
   */
  async function init(): Promise<void> {
    const stored = getStoredToken()
    if (!stored) return
    token.value = stored
    initializing.value = true
    try {
      const res = await authApi.refresh()
      token.value = res.access_token
      user.value = res.user
      storeToken(res.access_token)
    } catch {
      clearLocal()
    } finally {
      initializing.value = false
    }
  }

  return {
    user,
    token,
    initializing,
    isAuthenticated,
    isAdmin,
    isMusician,
    isProjectionist,
    canEditSongs,
    login,
    logout,
    clearLocal,
    init,
    updateProfile,
  }
})
