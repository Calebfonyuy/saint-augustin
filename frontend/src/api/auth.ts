// Auth endpoints — login, logout, token refresh.
// Ref: services/auth/routes/api.php (/api/auth/{login,logout,refresh})
import { apiClient } from './client'
import type { TokenResponse } from '@/types'

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
