// Auth store – skeleton for Phase 1
// Ref: https://pinia.vuejs.org/core-concepts/
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export interface User {
  id: string
  email: string
  displayName: string
  roles: string[]
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const token = ref<string | null>(null)

  const isAuthenticated = computed(() => !!token.value)
  const isAdmin = computed(() => user.value?.roles.includes('admin') ?? false)
  const isMusician = computed(() => user.value?.roles.includes('musician') ?? false)
  const isProjectionist = computed(() => user.value?.roles.includes('projectionist') ?? false)

  function setAuth(newUser: User, newToken: string) {
    user.value = newUser
    token.value = newToken
    localStorage.setItem('sa_token', newToken)
  }

  function logout() {
    user.value = null
    token.value = null
    localStorage.removeItem('sa_token')
  }

  // Restore token from localStorage on app load
  function init() {
    const stored = localStorage.getItem('sa_token')
    if (stored) {
      token.value = stored
      // TODO Phase 1: validate token against Auth Service and fetch user
    }
  }

  return { user, token, isAuthenticated, isAdmin, isMusician, isProjectionist, setAuth, logout, init }
})
