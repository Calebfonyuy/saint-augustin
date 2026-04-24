// Password reset endpoints (two-step email flow).
// Ref: services/auth/routes/api.php (/api/auth/password/{forgot,reset})
import { apiClient } from './client'

export async function requestPasswordReset(email: string): Promise<{ message: string }> {
  const { data } = await apiClient.post<{ message: string }>('/auth/password/forgot', { email })
  return data
}

export interface ResetPasswordPayload {
  email: string
  token: string
  password: string
  password_confirmation: string
}

export async function resetPassword(payload: ResetPasswordPayload): Promise<{ message: string }> {
  const { data } = await apiClient.post<{ message: string }>('/auth/password/reset', payload)
  return data
}
