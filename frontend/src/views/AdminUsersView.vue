<script setup lang="ts">
/*
 * Admin → Users screen.
 *
 * Faithful port of the prototype's AdminScreen → UsersPane (proto-screens.jsx
 * in saint-augustin-design). Real users and pending invitations are rendered
 * as a single table; the kind-discriminator on each row lets us branch on
 * actions (resend/cancel for invites, edit/delete for users).
 *
 * The backend is split — users live at /api/users, invitations at
 * /api/auth/invitations — but both are loaded by the `users` store and
 * exposed as a unified `members` list to keep the table dumb.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/AppShell.vue'
import AdminTabs from '@/components/admin/AdminTabs.vue'
import UserEditorDrawer from '@/components/admin/UserEditorDrawer.vue'
import Icon from '@/components/Icon.vue'
import Toast from '@/components/Toast.vue'
import { useUsersStore, type AdminMember } from '@/stores/users'
import { extractErrorMessage } from '@/api/client'
import { requestPasswordReset } from '@/api/password'
import type { Role } from '@/types'

const usersStore = useUsersStore()
const { t } = useI18n()

// ── Filter state ──────────────────────────────────────────────────────────

const query = ref('')
const roleFilter = ref<'all' | Role>('all')

const ROLE_FILTERS = computed<{ id: 'all' | Role; label: string }[]>(() => [
  { id: 'all', label: t('adminUsers.filters.all') },
  { id: 'admin', label: t('roles.admin') },
  { id: 'musician', label: t('roles.musician') },
  { id: 'projectionist', label: t('roles.projectionist') },
])

const visible = computed<AdminMember[]>(() => {
  return usersStore.members.filter((m) => {
    if (roleFilter.value !== 'all' && !m.roles.includes(roleFilter.value)) return false
    if (query.value) {
      const q = query.value.toLowerCase()
      const haystack = `${m.display_name ?? ''} ${m.email}`.toLowerCase()
      if (!haystack.includes(q)) return false
    }
    return true
  })
})

// ── Bulk selection ────────────────────────────────────────────────────────

const selected = ref<Set<string>>(new Set())

const allSelected = computed(
  () => visible.value.length > 0 && visible.value.every((m) => selected.value.has(m.id)),
)

function toggleAll(): void {
  const next = new Set<string>()
  if (!allSelected.value) {
    for (const m of visible.value) next.add(m.id)
  }
  selected.value = next
}

function toggleOne(id: string): void {
  const next = new Set(selected.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  selected.value = next
}

// ── Drawer state ──────────────────────────────────────────────────────────

const drawerMember = ref<AdminMember | null>(null)
const drawerMode = ref<'invite' | 'edit'>('edit')
const saving = ref(false)

function openInvite(): void {
  drawerMember.value = null
  drawerMode.value = 'invite'
}

function openEdit(m: AdminMember): void {
  drawerMember.value = m
  drawerMode.value = 'edit'
}

function closeDrawer(): void {
  drawerMember.value = null
  drawerMode.value = 'edit'
}

// ── Toast ─────────────────────────────────────────────────────────────────

const toast = ref<{ message: string; kind: 'error' | 'success' } | null>(null)

function notify(kind: 'error' | 'success', message: string): void {
  toast.value = { message, kind }
}

// ── Helpers ───────────────────────────────────────────────────────────────

function initialsOf(m: AdminMember): string {
  const src = m.display_name || m.email
  return src
    .split(/[ @]/)
    .filter(Boolean)
    .map((n) => n[0])
    .slice(0, 2)
    .join('')
    .toUpperCase()
}

function lastActiveLabel(m: AdminMember): string {
  if (m.kind === 'invitation') return t('adminUsers.awaitingRegistration')
  return relativeFromIso(m.updated_at)
}

function relativeFromIso(iso: string): string {
  const ts = new Date(iso).getTime()
  if (!Number.isFinite(ts)) return '—'
  const diff = Date.now() - ts
  const min = Math.floor(diff / 60_000)
  if (min < 1) return t('adminUsers.relativeTime.justNow')
  if (min < 60) return t('adminUsers.relativeTime.minutes', { n: min })
  const hr = Math.floor(min / 60)
  if (hr < 24) return t('adminUsers.relativeTime.hours', { n: hr })
  const day = Math.floor(hr / 24)
  if (day < 7) return t('adminUsers.relativeTime.days', { n: day })
  return new Date(iso).toLocaleDateString()
}

function roleLabel(r: Role): string {
  return t(`roles.${r}`)
}

// ── Lifecycle ─────────────────────────────────────────────────────────────

onMounted(async () => {
  try {
    await usersStore.fetchAll()
  } catch (err) {
    notify('error', extractErrorMessage(err, t('adminUsers.errors.load')))
  }
})

// ── Action handlers (wired into the drawer) ───────────────────────────────

async function onSaveUser(payload: { id: string; display_name: string; roles: Role[] }) {
  saving.value = true
  try {
    await usersStore.updateUser(payload.id, {
      display_name: payload.display_name,
      roles: payload.roles,
    })
    notify('success', t('adminUsers.toast.updated'))
    closeDrawer()
  } catch (err) {
    notify('error', extractErrorMessage(err, t('adminUsers.errors.save')))
  } finally {
    saving.value = false
  }
}

async function onDeleteUser(id: string) {
  saving.value = true
  try {
    await usersStore.removeUser(id)
    notify('success', t('adminUsers.toast.removed'))
    closeDrawer()
  } catch (err) {
    notify('error', extractErrorMessage(err, t('adminUsers.errors.remove')))
  } finally {
    saving.value = false
  }
}

async function onInvite(payload: { email: string; roles: Role[] }) {
  saving.value = true
  try {
    await usersStore.inviteUser(payload.email, payload.roles)
    notify('success', t('adminUsers.toast.invitationSent', { email: payload.email }))
    closeDrawer()
  } catch (err) {
    notify('error', extractErrorMessage(err, t('adminUsers.errors.invite')))
  } finally {
    saving.value = false
  }
}

async function onResendInvite(id: string) {
  saving.value = true
  try {
    await usersStore.resendInvitation(id)
    notify('success', t('adminUsers.toast.invitationResent'))
    closeDrawer()
  } catch (err) {
    notify('error', extractErrorMessage(err, t('adminUsers.errors.resend')))
  } finally {
    saving.value = false
  }
}

async function onCancelInvite(id: string) {
  saving.value = true
  try {
    await usersStore.cancelInvitation(id)
    notify('success', t('adminUsers.toast.invitationCancelled'))
    closeDrawer()
  } catch (err) {
    notify('error', extractErrorMessage(err, t('adminUsers.errors.cancelInvite')))
  } finally {
    saving.value = false
  }
}

async function onResetPassword(member: AdminMember) {
  try {
    await requestPasswordReset(member.email)
    notify('success', t('adminUsers.toast.resetSent', { email: member.email }))
  } catch (err) {
    notify('error', extractErrorMessage(err, t('adminUsers.errors.resetLink')))
  }
}

// ── Bulk actions (toolbar buttons that appear when rows are selected) ─────

function selectedMembers(): AdminMember[] {
  return usersStore.members.filter((m) => selected.value.has(m.id))
}

async function bulkResendInvites() {
  const targets = selectedMembers().filter((m) => m.kind === 'invitation')
  if (targets.length === 0) {
    notify('error', t('adminUsers.bulk.pickInvites'))
    return
  }
  let ok = 0
  for (const m of targets) {
    try {
      await usersStore.resendInvitation(m.id)
      ok += 1
    } catch {
      // Continue — surface a single combined toast at the end.
    }
  }
  notify(
    ok === targets.length ? 'success' : 'error',
    t('adminUsers.bulk.resentCount', { ok, total: targets.length }),
  )
  selected.value = new Set()
}

async function bulkRemove() {
  const targets = selectedMembers()
  if (targets.length === 0) return
  if (!confirm(t('adminUsers.bulk.confirmRemove', { count: targets.length }))) return

  let ok = 0
  for (const m of targets) {
    try {
      if (m.kind === 'user') await usersStore.removeUser(m.id)
      else await usersStore.cancelInvitation(m.id)
      ok += 1
    } catch {
      // Continue.
    }
  }
  notify(
    ok === targets.length ? 'success' : 'error',
    t('adminUsers.bulk.removedCount', { ok, total: targets.length }),
  )
  selected.value = new Set()
}
</script>

<template>
  <AppShell>
    <AdminTabs active="users" :subtitle="t('adminUsers.subtitle')">
      <template #actions>
        <button
          type="button"
          class="btn btn-primary"
          data-testid="invite-user-btn"
          @click="openInvite"
        >
          <Icon name="plus" /> {{ t('adminUsers.inviteUser') }}
        </button>
      </template>
    </AdminTabs>

    <div class="px-6 py-5 overflow-auto flex-1">
      <!-- Stats row -->
      <div
        class="grid gap-3 mb-5"
        style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr))"
        data-testid="admin-users-stats"
      >
        <div
          v-for="s in [
            { label: t('adminUsers.stats.users'), value: usersStore.stats.total },
            { label: t('adminUsers.stats.pending'), value: usersStore.stats.pending },
            { label: t('adminUsers.stats.admins'), value: usersStore.stats.admins },
            { label: t('adminUsers.stats.musicians'), value: usersStore.stats.musicians },
          ]"
          :key="s.label"
          class="card p-[14px]"
        >
          <div class="mono uppercase tracking-[0.14em] text-[11px] text-text-faint">
            {{ s.label }}
          </div>
          <div class="font-display text-[28px] font-semibold mt-[6px]">{{ s.value }}</div>
        </div>
      </div>

      <!-- Filter bar -->
      <div class="flex gap-[10px] items-center mb-3 flex-wrap">
        <div class="relative flex-1 max-w-[360px]">
          <span class="absolute left-[10px] top-[10px] text-text-faint">
            <Icon name="search" />
          </span>
          <input
            v-model="query"
            class="input"
            :placeholder="t('adminUsers.searchPlaceholder')"
            style="padding-left: 30px"
            data-testid="admin-users-search"
          />
        </div>

        <div
          class="flex gap-1 p-[2px] bg-bg-sunken rounded-[8px] border border-border"
          data-testid="admin-users-role-filter"
        >
          <button
            v-for="r in ROLE_FILTERS"
            :key="r.id"
            type="button"
            class="px-[10px] py-[6px] text-[12px] rounded-[6px] transition-colors"
            :class="
              roleFilter === r.id
                ? 'bg-bg-raised text-text shadow-[0_1px_0_var(--border)]'
                : 'bg-transparent text-text-muted hover:text-text'
            "
            @click="roleFilter = r.id"
          >
            {{ r.label }}
          </button>
        </div>

        <div class="flex-1" />

        <div
          v-if="selected.size > 0"
          class="text-[12px] text-text-muted flex items-center gap-2"
          data-testid="admin-users-bulkbar"
        >
          <span>{{ t('adminUsers.bulk.selectedCount', { count: selected.size }) }}</span>
          <span class="text-text-faint">·</span>
          <button
            type="button"
            class="text-accent hover:underline"
            @click="bulkResendInvites"
          >
            {{ t('adminUsers.bulk.resend') }}
          </button>
          <span class="text-text-faint">·</span>
          <button
            type="button"
            class="hover:underline"
            style="color: var(--danger)"
            @click="bulkRemove"
          >
            {{ t('adminUsers.bulk.remove') }}
          </button>
        </div>
      </div>

      <!-- Table -->
      <div class="card" data-testid="admin-users-table">
        <!-- Header row -->
        <div
          class="grid items-center px-4 py-3 border-b border-border mono uppercase tracking-[0.14em] text-[10px] text-text-faint"
          style="grid-template-columns: 36px 2fr 2fr 1.6fr 1fr auto"
        >
          <input
            type="checkbox"
            :checked="allSelected"
            :aria-label="allSelected ? t('adminUsers.table.deselectAll') : t('adminUsers.table.selectAll')"
            @change="toggleAll"
          />
          <div>{{ t('adminUsers.table.name') }}</div>
          <div>{{ t('adminUsers.table.email') }}</div>
          <div>{{ t('adminUsers.table.roles') }}</div>
          <div>{{ t('adminUsers.table.lastActive') }}</div>
          <div></div>
        </div>

        <!-- Loading -->
        <div
          v-if="usersStore.loading && usersStore.members.length === 0"
          class="p-6 text-[13px] text-text-faint text-center"
        >
          {{ t('common.loading') }}
        </div>

        <!-- Rows -->
        <div
          v-for="(m, i) in visible"
          :key="m.id"
          class="grid items-center px-4 py-3 cursor-pointer hover:bg-bg-sunken"
          :class="i < visible.length - 1 ? 'border-b border-border' : ''"
          style="grid-template-columns: 36px 2fr 2fr 1.6fr 1fr auto; font-size: 13px"
          :data-testid="`admin-user-row-${m.id}`"
          @click="openEdit(m)"
        >
          <input
            type="checkbox"
            :checked="selected.has(m.id)"
            :aria-label="t('adminUsers.table.selectEmail', { email: m.email })"
            @click.stop
            @change="toggleOne(m.id)"
          />
          <div class="flex items-center gap-[10px] min-w-0">
            <div
              class="w-[28px] h-[28px] rounded-full bg-accent-soft text-accent grid place-items-center text-[11px] font-bold shrink-0"
            >
              {{ initialsOf(m) }}
            </div>
            <div class="min-w-0">
              <div class="font-semibold truncate">
                <span v-if="m.display_name">{{ m.display_name }}</span>
                <em v-else class="text-text-faint not-italic">{{ t('userDrawer.headerName.unnamed') }}</em>
              </div>
              <div
                v-if="m.kind === 'invitation'"
                class="mono text-[10.5px] text-text-faint mt-[2px] tracking-[0.16em]"
              >
                {{ t('adminUsers.invitePending') }}
              </div>
            </div>
          </div>
          <div class="text-text-muted truncate">{{ m.email }}</div>
          <div class="flex flex-wrap gap-1">
            <span
              v-for="r in m.roles"
              :key="r"
              class="chip"
              :class="r === 'admin' ? 'chip-accent' : ''"
              style="font-size: 10px"
            >
              {{ roleLabel(r) }}
            </span>
          </div>
          <div class="text-[12px] text-text-faint">{{ lastActiveLabel(m) }}</div>
          <button
            type="button"
            class="btn btn-ghost"
            style="padding: 4px"
            :aria-label="t('adminUsers.table.openEmail', { email: m.email })"
            @click.stop="openEdit(m)"
          >
            <Icon name="dots" />
          </button>
        </div>

        <!-- Empty state -->
        <div
          v-if="!usersStore.loading && visible.length === 0"
          class="p-10 text-center text-[13px] text-text-faint"
          data-testid="admin-users-empty"
        >
          {{ t('adminUsers.noMatch') }}
          <button
            type="button"
            class="text-accent ml-1 hover:underline"
            @click="openInvite"
          >
            {{ t('adminUsers.inviteSomeone') }}
          </button>
        </div>
      </div>
    </div>

    <UserEditorDrawer
      :member="drawerMember"
      :mode="drawerMode"
      :saving="saving"
      @close="closeDrawer"
      @save-user="onSaveUser"
      @delete-user="onDeleteUser"
      @invite="onInvite"
      @resend-invite="onResendInvite"
      @cancel-invite="onCancelInvite"
      @reset-password="onResetPassword"
    />

    <Toast
      v-if="toast"
      :message="toast.message"
      :kind="toast.kind"
      @close="toast = null"
    />
  </AppShell>
</template>
