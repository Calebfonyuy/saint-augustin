// Axios instance for the Auth Service API.
// - Attaches `Authorization: Bearer <token>` when a token is stored.
// - On 401 responses, clears the token + user from the auth store and routes
//   the user back to /login. This keeps stale-token handling in one place
//   rather than scattered across every call site.
//
// Ref: https://axios-http.com/docs/interceptors
import axios, { type AxiosError, type AxiosInstance } from 'axios'

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api'
// export const API_BASE_URL = 'http://localhost:8000/api'

export const apiClient: AxiosInstance = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
  timeout: 15000,
})

const TOKEN_KEY = 'sa_token'

export function getStoredToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function storeToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY)
}

apiClient.interceptors.request.use((config) => {
  const token = getStoredToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

/**
 * Global 401 handler. Installed once from main.ts so it has access to the
 * router without creating an import cycle with the auth store.
 */
export function installUnauthorizedHandler(onUnauthorized: () => void): void {
  apiClient.interceptors.response.use(
    (r) => r,
    (error: AxiosError) => {
      // Only react on clearly-authenticated 401s — skip login attempts
      // themselves so invalid credentials stay in-view.
      const url = error.config?.url ?? ''
      const isLoginCall = url.endsWith('/auth/login')
      if (error.response?.status === 401 && !isLoginCall) {
        onUnauthorized()
      }
      return Promise.reject(error)
    },
  )
}

/** Extract a human-readable message from an Axios error (422/409/401/500). */
export function extractErrorMessage(err: unknown, fallback = 'Something went wrong.'): string {
  if (axios.isAxiosError(err)) {
    const data = err.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    if (data?.errors) {
      // Flatten first validation error for a compact inline message.
      const first = Object.values(data.errors)[0]?.[0]
      if (first) return first
    }
    if (data?.message) return data.message
    if (err.message) return err.message
  }
  if (err instanceof Error) return err.message
  return fallback
}
