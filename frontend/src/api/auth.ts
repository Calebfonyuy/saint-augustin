// Auth endpoints — login, logout, token refresh, self-service profile.
// Ref: services/auth/routes/api.php (/api/auth/{login,logout,refresh,me})
import { apiClient } from './client'
import type { TokenResponse, User } from '@/types'

export async function login(email: string, password: string): Promise<TokenResponse> {
  const { data } = await apiClient.post<TokenResponse>('/auth/login', { email, password })
  return data
}

export async function logout(): Promise<void> {
  await apiClient.post('/auth/logout')
}

export async function refresh(): Promise<TokenResponse> {
  const { data } = await apiClient.post<TokenResponse>('/auth/refresh')
  return data
}

/**
 * Payload for PATCH /auth/me. All fields are optional, but if `password` is
 * sent then `current_password` and `password_confirmation` are required by
 * the backend.
 */
export interface UpdateProfilePayload {
  display_name?: string
  current_password?: string
  password?: string
  password_confirmation?: string
}

export async function updateProfile(payload: UpdateProfilePayload): Promise<{ user: User }> {
  const { data } = await apiClient.patch<{ user: User }>('/auth/me', payload)
  return data
}
