<script setup lang="ts">
/*
 * Slide-in drawer for inviting a new user or editing an existing one.
 *
 * Two modes:
 *   • mode="invite"   — collect email + roles; calls POST /auth/invitations.
 *                       Display name is collected by the invitee at register
 *                       time, so we don't ask for it here.
 *   • mode="edit-user" — edit display_name + roles on a real user.
 *   • mode="edit-invite" — re-roll the invitation: cancel + recreate with
 *                          new roles. The backend doesn't allow editing an
 *                          invitation in place, so we cancel-and-resend
 *                          when the roles change.
 *
 * The drawer is rendered via `<Teleport to="body">` so the slide-in animation
 * doesn't get clipped by the admin view's overflow boundary.
 *
 * Ref: saint-augustin-design/components/proto-screens.jsx — UserEditorDrawer.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Icon from '@/components/Icon.vue'
import type { AdminMember } from '@/stores/users'
import type { Role } from '@/types'

const { t } = useI18n()

const props = defineProps<{
  /** Drawer is open when this is non-null. */
  member: AdminMember | null
  /** "invite" when creating; otherwise we infer edit mode from member.kind. */
  mode: 'invite' | 'edit'
  /** Disable Save while a request is in-flight. */
  saving?: boolean
}>()

const emit = defineEmits<{
  close: []
  saveUser: [payload: { id: string; display_name: string; roles: Role[] }]
  deleteUser: [id: string]
  invite: [payload: { email: string; roles: Role[] }]
  resendInvite: [id: string]
  cancelInvite: [id: string]
  resetPassword: [user: AdminMember]
}>()

interface RoleDef {
  id: Role
  label: string
  blurb: string
}

const ROLE_DEFS = computed<RoleDef[]>(() => [
  { id: 'admin', label: t('userDrawer.roles.admin.label'), blurb: t('userDrawer.roles.admin.blurb') },
  { id: 'musician', label: t('userDrawer.roles.musician.label'), blurb: t('userDrawer.roles.musician.blurb') },
  { id: 'projectionist', label: t('userDrawer.roles.projectionist.label'), blurb: t('userDrawer.roles.projectionist.blurb') },
])

// ── Local form state — kept in sync with the incoming member ──────────────

const form = reactive({
  email: '',
  display_name: '',
  roles: [] as Role[],
})

function syncForm(): void {
  if (!props.member) {
    form.email = ''
    form.display_name = ''
    form.roles = props.mode === 'invite' ? ['musician'] : []
    return
  }
  form.email = props.member.email
  form.display_name = props.member.display_name ?? ''
  form.roles = [...props.member.roles]
}

watch(() => props.member, syncForm, { immediate: true })
watch(() => props.mode, syncForm)

// ── Esc-to-close ──────────────────────────────────────────────────────────

function onKey(e: KeyboardEvent): void {
  if (e.key === 'Escape') emit('close')
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

// ── Derived state ─────────────────────────────────────────────────────────

const isInvite = computed(() => props.mode === 'invite')
const isPending = computed(() => props.member?.kind === 'invitation')

const initials = computed(() => {
  const src = form.display_name || form.email || '?'
  return src
    .split(/[ @]/)
    .filter(Boolean)
    .map((n) => n[0])
    .slice(0, 2)
    .join('')
    .toUpperCase()
})

const headerLabel = computed(() =>
  isInvite.value ? t('userDrawer.header.invite') : t('userDrawer.header.edit'),
)
const headerName = computed(() => {
  if (isInvite.value) return t('userDrawer.headerName.newMember')
  return (
    form.display_name ||
    (isPending.value ? t('userDrawer.headerName.pendingMember') : t('userDrawer.headerName.unnamed'))
  )
})
const subline = computed(() => {
  if (isInvite.value) return t('userDrawer.subline.invite')
  if (isPending.value) return t('userDrawer.subline.pending')
  return t('userDrawer.subline.active')
})

const emailLooksValid = computed(() => /.+@.+\..+/.test(form.email))
const canSave = computed(() => {
  if (isInvite.value) return emailLooksValid.value && form.roles.length > 0
  if (isPending.value) return form.roles.length > 0
  return form.display_name.trim().length > 0 && form.roles.length > 0
})

// ── Role toggling ─────────────────────────────────────────────────────────

function toggleRole(r: Role): void {
  if (form.roles.includes(r)) {
    form.roles = form.roles.filter((x) => x !== r)
  } else {
    form.roles = [...form.roles, r]
  }
}

// ── Action handlers ───────────────────────────────────────────────────────

function onSave(): void {
  if (!canSave.value) return
  if (isInvite.value) {
    emit('invite', { email: form.email.trim().toLowerCase(), roles: [...form.roles] })
    return
  }
  if (!props.member) return
  if (props.member.kind === 'user') {
    emit('saveUser', {
      id: props.member.id,
      display_name: form.display_name.trim(),
      roles: [...form.roles],
    })
  } else {
    // For pending invitations, "save changes to roles" is implemented as
    // cancel + re-issue. Surfaced as resend so the email gets re-sent too.
    emit('resendInvite', props.member.id)
  }
}

function onResend(): void {
  if (props.member?.kind === 'invitation') emit('resendInvite', props.member.id)
}

function onCancelInvite(): void {
  if (props.member?.kind === 'invitation') {
    if (confirm(t('userDrawer.confirmCancelInvite'))) {
      emit('cancelInvite', props.member.id)
    }
  }
}

function onDelete(): void {
  if (!props.member || props.member.kind !== 'user') return
  if (confirm(t('userDrawer.confirmDelete', { email: props.member.email }))) {
    emit('deleteUser', props.member.id)
  }
}

const open = computed(() => !!props.member || isInvite.value)

const showOnReset = ref(false)
function onReset(): void {
  if (props.member && props.member.kind === 'user') {
    emit('resetPassword', props.member)
    showOnReset.value = true
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-[100] flex justify-end"
      style="background: rgba(20, 15, 10, 0.28)"
      data-testid="user-drawer-backdrop"
      @click.self="emit('close')"
    >
      <aside
        class="w-[460px] max-w-full bg-bg border-l border-border flex flex-col h-full shadow-[-20px_0_40px_rgba(0,0,0,0.08)]"
        style="animation: drawerSlideIn 0.18s ease"
        data-testid="user-drawer"
        @click.stop
      >
        <!-- Header -->
        <div class="px-[22px] py-4 border-b border-border flex items-center gap-3">
          <div class="mono uppercase tracking-[0.16em] text-[10px] text-text-faint">
            {{ headerLabel }}
          </div>
          <div class="flex-1" />
          <button
            type="button"
            class="btn btn-ghost"
            style="padding: 6px"
            data-testid="user-drawer-close"
            @click="emit('close')"
          >
            <Icon name="x" />
          </button>
        </div>

        <!-- Body -->
        <div class="flex-1 overflow-auto px-[22px] pt-[22px] pb-4">
          <!-- Identity card -->
          <div class="flex items-center gap-[14px] mb-[22px]">
            <div
              class="w-[56px] h-[56px] rounded-full bg-accent-soft text-accent grid place-items-center font-display text-[22px] font-semibold shrink-0"
            >
              {{ initials || '·' }}
            </div>
            <div class="flex-1 min-w-0">
              <div class="font-display text-[22px] font-semibold truncate">
                {{ headerName }}
              </div>
              <div class="text-[12px] text-text-faint mt-[2px]">{{ subline }}</div>
            </div>
            <span
              v-if="isPending"
              class="chip chip-accent"
              style="font-size: 10px"
            >{{ t('userDrawer.pending') }}</span>
            <span
              v-else-if="!isInvite"
              class="chip"
              style="font-size: 10px; color: var(--success); border-color: transparent; background: color-mix(in oklch, var(--success) 15%, transparent)"
            >● {{ t('userDrawer.active') }}</span>
          </div>

          <!-- Identity form -->
          <div class="mt-[22px] mb-2">
            <div class="mono uppercase tracking-[0.16em] text-[10px] text-text-faint">
              {{ t('userDrawer.sections.identity') }}
            </div>
          </div>

          <div v-if="!isInvite && !isPending" class="mb-[10px]">
            <label class="field-label">{{ t('userDrawer.fullName') }}</label>
            <input
              v-model="form.display_name"
              class="input"
              :placeholder="t('userDrawer.fullNamePlaceholder')"
              data-testid="user-drawer-name"
            />
          </div>

          <div class="mb-[10px]">
            <label class="field-label">{{ t('userDrawer.email') }}</label>
            <input
              v-model="form.email"
              :disabled="!isInvite"
              type="email"
              class="input"
              :placeholder="t('userDrawer.emailPlaceholder')"
              data-testid="user-drawer-email"
            />
            <div v-if="!isInvite" class="text-[10.5px] text-text-faint mt-1">
              {{ t('userDrawer.emailHint') }}
            </div>
          </div>

          <!-- Roles -->
          <div class="mt-[22px] mb-2">
            <div class="mono uppercase tracking-[0.16em] text-[10px] text-text-faint">
              {{ t('userDrawer.sections.roles') }}
            </div>
            <div class="text-[11.5px] text-text-muted mt-1">
              {{ t('userDrawer.rolesHint') }}
            </div>
          </div>

          <div class="flex flex-col gap-[6px] mt-1">
            <label
              v-for="r in ROLE_DEFS"
              :key="r.id"
              class="flex items-start gap-3 px-3 py-[10px] rounded-[8px] cursor-pointer transition-colors"
              :class="
                form.roles.includes(r.id)
                  ? 'border border-accent bg-accent-soft'
                  : 'border border-border bg-bg-raised'
              "
              :data-testid="`user-drawer-role-${r.id}`"
            >
              <input
                type="checkbox"
                class="mt-[3px]"
                :checked="form.roles.includes(r.id)"
                @change="toggleRole(r.id)"
              />
              <div class="flex-1">
                <div
                  class="text-[13px] font-semibold"
                  :class="form.roles.includes(r.id) ? 'text-accent' : 'text-text'"
                >
                  {{ r.label }}
                </div>
                <div class="text-[11.5px] text-text-muted mt-[2px]">{{ r.blurb }}</div>
              </div>
            </label>
          </div>

          <!-- Actions for existing users -->
          <template v-if="!isInvite">
            <div class="mt-[22px] mb-2">
              <div class="mono uppercase tracking-[0.16em] text-[10px] text-text-faint">
                {{ t('userDrawer.sections.actions') }}
              </div>
            </div>
            <div class="flex flex-col gap-[6px]">
              <button
                v-if="!isPending"
                type="button"
                class="btn"
                style="justify-content: flex-start"
                data-testid="user-drawer-reset"
                @click="onReset"
              >
                {{ t('userDrawer.sendResetLink') }}
              </button>
              <div
                v-if="showOnReset"
                class="text-[11px] text-text-faint pl-1"
              >
                {{ t('userDrawer.resetLinkSent') }}
              </div>
              <button
                v-if="isPending"
                type="button"
                class="btn"
                style="justify-content: flex-start"
                data-testid="user-drawer-resend"
                @click="onResend"
              >
                {{ t('userDrawer.resendInvitation') }}
              </button>
            </div>

            <div class="mt-[22px] mb-2">
              <div class="mono uppercase tracking-[0.16em] text-[10px] text-text-faint">
                {{ t('userDrawer.sections.dangerZone') }}
              </div>
            </div>
            <button
              v-if="isPending"
              type="button"
              class="btn btn-danger"
              style="justify-content: flex-start"
              data-testid="user-drawer-cancel-invite"
              @click="onCancelInvite"
            >
              {{ t('userDrawer.cancelInvitation') }}
            </button>
            <button
              v-else
              type="button"
              class="btn btn-danger"
              style="justify-content: flex-start"
              data-testid="user-drawer-delete"
              @click="onDelete"
            >
              {{ t('userDrawer.removeFromWorkspace') }}
            </button>
          </template>
        </div>

        <!-- Footer -->
        <div class="px-[22px] py-3 border-t border-border bg-bg-sunken flex gap-2">
          <button
            type="button"
            class="btn"
            data-testid="user-drawer-cancel"
            @click="emit('close')"
          >
            {{ t('common.cancel') }}
          </button>
          <div class="flex-1" />
          <button
            type="button"
            class="btn btn-primary"
            :disabled="!canSave || saving"
            :class="{ 'opacity-40 cursor-not-allowed': !canSave }"
            data-testid="user-drawer-save"
            @click="onSave"
          >
            {{
              saving
                ? t('common.saving')
                : isInvite
                  ? t('userDrawer.sendInvitation')
                  : isPending
                    ? t('userDrawer.resendWithRoles')
                    : t('userDrawer.saveChanges')
            }}
          </button>
        </div>
      </aside>
    </div>
  </Teleport>
</template>

<style scoped>
@keyframes drawerSlideIn {
  from {
    transform: translateX(24px);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}
</style>
