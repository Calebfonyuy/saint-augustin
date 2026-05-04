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

/** Admin-only — list every invitation (pending, accepted, expired). */
export async function listInvitations(): Promise<Invitation[]> {
  const { data } = await apiClient.get<Invitation[]>('/auth/invitations')
  return data
}

/** Admin-only — re-email an invitation and rotate its token + expiry. */
export async function resendInvitation(
  id: string,
): Promise<{ message: string; invitation: Invitation }> {
  const { data } = await apiClient.post<{ message: string; invitation: Invitation }>(
    `/auth/invitations/${id}/resend`,
  )
  return data
}

/** Admin-only — delete an invitation, invalidating its token immediately. */
export async function deleteInvitation(id: string): Promise<void> {
  await apiClient.delete(`/auth/invitations/${id}`)
}
