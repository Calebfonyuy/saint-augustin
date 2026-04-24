// Invitation + registration endpoints.
// Ref: services/auth/routes/api.php (/api/auth/invitations, /api/auth/register)
import { apiClient } from './client'
import type { Invitation, Role } from '@/types'

export async function verifyInvitation(token: string): Promise<Invitation> {
  const { data } = await apiClient.get<Invitation>(`/auth/invitations/${encodeURIComponent(token)}`)
  return data
}

export interface RegisterPayload {
  token: string
  display_name: string
  password: string
  password_confirmation: string
}

export async function register(payload: RegisterPayload): Promise<void> {
  await apiClient.post('/auth/register', payload)
}

export interface CreateInvitationPayload {
  email: string
  roles?: Role[]
}

export async function createInvitation(
  payload: CreateInvitationPayload,
): Promise<{ message: string; invitation: Invitation }> {
  const { data } = await apiClient.post<{ message: string; invitation: Invitation }>(
    '/auth/invitations',
    payload,
  )
  return data
}
