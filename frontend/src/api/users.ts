// Admin user-management endpoints.
// Ref: services/auth/routes/api.php (/api/users/*)
// All routes require the admin role; non-admins are bounced by router guards
// before they ever reach these calls.
import { apiClient } from './client'
import type { Role, UserDetail } from '@/types'

export async function listUsers(): Promise<UserDetail[]> {
  const { data } = await apiClient.get<UserDetail[]>('/users')
  return data
}

export interface UpdateUserPayload {
  display_name?: string
  roles?: Role[]
}

export async function updateUser(id: string, payload: UpdateUserPayload): Promise<UserDetail> {
  const { data } = await apiClient.put<UserDetail>(`/users/${id}`, payload)
  return data
}

export async function deleteUser(id: string): Promise<void> {
  await apiClient.delete(`/users/${id}`)
}
