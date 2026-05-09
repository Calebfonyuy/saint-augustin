// Admin → Users store.
//
// The Admin Users screen merges two backend resources into a single list:
//   • Registered users      (GET /api/users)
//   • Pending invitations   (GET /api/auth/invitations) — only those that
//                            have not been accepted and have not expired
//
// We expose them as a discriminated `AdminMember` so the table can render
// "real" rows and "INVITE PENDING" rows side-by-side without each call site
// repeating the merge logic.
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as usersApi from '@/api/users'
import * as invitationsApi from '@/api/invitations'
import type { Invitation, Role, UserDetail } from '@/types'

/** Shape rendered by the table — keeps `kind` so we can branch on actions. */
export type AdminMember =
  | {
      kind: 'user'
      id: string
      email: string
      display_name: string
      roles: Role[]
      created_at: string
      updated_at: string
      raw: UserDetail
    }
  | {
      kind: 'invitation'
      id: string
      email: string
      display_name: null
      roles: Role[]
      created_at: string
      updated_at: string
      expires_at: string
      raw: Invitation
    }

function toMember(u: UserDetail): AdminMember {
  return {
    kind: 'user',
    id: u.id,
    email: u.email,
    display_name: u.display_name,
    roles: u.roles,
    created_at: u.created_at,
    updated_at: u.updated_at,
    raw: u,
  }
}

function toInviteMember(i: Invitation): AdminMember {
  return {
    kind: 'invitation',
    id: i.id,
    email: i.email,
    display_name: null,
    roles: i.roles,
    // Invitations don't carry created_at on the public DTO, so we reuse
    // expires_at as the sortable timestamp; the table doesn't show it for
    // pending rows anyway.
    created_at: i.expires_at,
    updated_at: i.expires_at,
    expires_at: i.expires_at,
    raw: i,
  }
}

/** Pending = not accepted AND not expired. */
function isPending(inv: Invitation): boolean {
  if (inv.accepted) return false
  return new Date(inv.expires_at).getTime() > Date.now()
}

export const useUsersStore = defineStore('users', () => {
  const users = ref<UserDetail[]>([])
  const invitations = ref<Invitation[]>([])
  const loading = ref(false)
  const loaded = ref(false)

  /**
   * Unified list rendered by the admin table. Pending invitations render as
   * "INVITE PENDING" rows and surface alongside the real users so the admin
   * doesn't have to flip between two screens to reason about who has access.
   */
  const members = computed<AdminMember[]>(() => {
    const userRows = users.value.map(toMember)
    const inviteRows = invitations.value.filter(isPending).map(toInviteMember)
    return [...userRows, ...inviteRows].sort((a, b) =>
      (a.display_name ?? a.email).localeCompare(b.display_name ?? b.email),
    )
  })

  // ── Stats (driven directly by `members`) ────────────────────────────

  const stats = computed(() => {
    const total = users.value.length
    const pending = invitations.value.filter(isPending).length
    const admins = users.value.filter((u) => u.roles.includes('admin')).length
    const musicians = users.value.filter((u) => u.roles.includes('musician')).length
    return { total, pending, admins, musicians }
  })

  // ── Loaders ─────────────────────────────────────────────────────────

  async function fetchAll(): Promise<void> {
    loading.value = true
    try {
      const [u, inv] = await Promise.all([
        usersApi.listUsers(),
        invitationsApi.listInvitations(),
      ])
      users.value = u
      invitations.value = inv
      loaded.value = true
    } finally {
      loading.value = false
    }
  }

  // ── User actions ────────────────────────────────────────────────────

  async function updateUser(id: string, payload: usersApi.UpdateUserPayload): Promise<UserDetail> {
    const updated = await usersApi.updateUser(id, payload)
    users.value = users.value.map((u) => (u.id === id ? updated : u))
    return updated
  }

  async function removeUser(id: string): Promise<void> {
    await usersApi.deleteUser(id)
    users.value = users.value.filter((u) => u.id !== id)
  }

  // ── Invitation actions ──────────────────────────────────────────────

  async function inviteUser(email: string, roles: Role[]): Promise<Invitation> {
    const { invitation } = await invitationsApi.createInvitation({ email, roles })
    invitations.value = [invitation, ...invitations.value.filter((i) => i.email !== email)]
    return invitation
  }

  async function resendInvitation(id: string): Promise<Invitation> {
    const { invitation } = await invitationsApi.resendInvitation(id)
    invitations.value = invitations.value.map((i) => (i.id === id ? invitation : i))
    return invitation
  }

  async function cancelInvitation(id: string): Promise<void> {
    await invitationsApi.deleteInvitation(id)
    invitations.value = invitations.value.filter((i) => i.id !== id)
  }

  return {
    users,
    invitations,
    members,
    stats,
    loading,
    loaded,
    fetchAll,
    updateUser,
    removeUser,
    inviteUser,
    resendInvitation,
    cancelInvitation,
  }
})
