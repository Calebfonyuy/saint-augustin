<script setup lang="ts">
// Self-service account settings — every authenticated user can land here to
// rename themselves and rotate their own password without bothering an admin.
//
// The two cards submit independently:
//   • Profile card  → PATCH /auth/me { display_name }
//   • Password card → PATCH /auth/me { current_password, password, password_confirmation }
//
// Submitting them separately keeps the password flow forced (we always re-ask
// for `current_password`) and means a successful name change doesn't blank
// the password fields the user was halfway through.
//
// Email and roles are intentionally not editable — email is the primary
// identifier, and role changes belong to an admin via /admin/users.
import { computed, reactive, ref, watch } from 'vue'
import AppShell from '@/components/AppShell.vue'
import Toast from '@/components/Toast.vue'
import { useAuthStore } from '@/stores/auth'
import { extractErrorMessage } from '@/api/client'

const auth = useAuthStore()

// ── Profile (display name) ────────────────────────────────────────────
const profileForm = reactive({ display_name: auth.user?.display_name ?? '' })
const profileSubmitting = ref(false)
const profileError = ref<string | null>(null)

// Re-sync the form if the user object swaps under us (e.g. another tab
// triggered a refresh). Without this, `init()` running after the view mounts
// would leave the form empty.
watch(
  () => auth.user?.display_name,
  (name) => {
    if (name && !profileSubmitting.value) profileForm.display_name = name
  },
)

const profileDirty = computed(
  () => profileForm.display_name.trim() !== (auth.user?.display_name ?? '').trim(),
)

async function submitProfile() {
  profileError.value = null
  const trimmed = profileForm.display_name.trim()
  if (!trimmed) {
    profileError.value = 'Display name cannot be empty.'
    return
  }
  profileSubmitting.value = true
  try {
    await auth.updateProfile({ display_name: trimmed })
    toast.value = { message: 'Profile updated.', kind: 'success' }
  } catch (err) {
    profileError.value = extractErrorMessage(err, 'Could not update profile.')
  } finally {
    profileSubmitting.value = false
  }
}

// ── Password ──────────────────────────────────────────────────────────
const passwordForm = reactive({
  current_password: '',
  password: '',
  password_confirmation: '',
})
const passwordSubmitting = ref(false)
const passwordError = ref<string | null>(null)

function resetPasswordForm() {
  passwordForm.current_password = ''
  passwordForm.password = ''
  passwordForm.password_confirmation = ''
}

async function submitPassword() {
  passwordError.value = null
  if (passwordForm.password !== passwordForm.password_confirmation) {
    passwordError.value = 'Passwords do not match.'
    return
  }
  passwordSubmitting.value = true
  try {
    await auth.updateProfile({
      current_password: passwordForm.current_password,
      password: passwordForm.password,
      password_confirmation: passwordForm.password_confirmation,
    })
    resetPasswordForm()
    toast.value = {
      message: 'Password changed. Other devices have been signed out.',
      kind: 'success',
    }
  } catch (err) {
    passwordError.value = extractErrorMessage(err, 'Could not change password.')
  } finally {
    passwordSubmitting.value = false
  }
}

// ── Toast ─────────────────────────────────────────────────────────────
const toast = ref<{ message: string; kind: 'error' | 'success' } | null>(null)

const roleSummary = computed(() => {
  const roles = auth.user?.roles ?? []
  if (!roles.length) return 'No roles'
  return roles.map((r) => r[0].toUpperCase() + r.slice(1)).join(' · ')
})
</script>

<template>
  <AppShell>
    <div class="px-8 pt-7 pb-2">
      <div class="font-display font-semibold text-[28px]">Account settings</div>
      <div class="text-[13px] text-text-faint mt-[6px]">
        Update how your name appears across the workspace, or rotate your password.
      </div>
    </div>

    <div class="px-8 pb-8 pt-4 overflow-auto flex-1 flex flex-col gap-5 max-w-[680px]">
      <!-- Identity summary -->
      <section class="card p-5">
        <div class="text-[11px] mono uppercase tracking-[0.14em] text-text-faint">Signed in as</div>
        <div class="font-display font-semibold text-[18px] mt-[4px]">
          {{ auth.user?.email ?? '—' }}
        </div>
        <div class="text-[12px] text-text-faint mt-[2px]">{{ roleSummary }}</div>
        <div class="text-[12px] text-text-faint mt-[8px]">
          Email and roles can only be changed by an administrator.
        </div>
      </section>

      <!-- Display name -->
      <section class="card p-5">
        <h2 class="font-display font-semibold text-[16px]">Profile</h2>
        <p class="text-[12px] text-text-faint mt-[2px]">
          This is the name shown in the sidebar, on shared playlists, and in admin lists.
        </p>
        <form class="mt-4 flex flex-col gap-3" novalidate @submit.prevent="submitProfile">
          <div>
            <label for="account-display-name" class="field-label">Display name</label>
            <input
              id="account-display-name"
              v-model="profileForm.display_name"
              class="input"
              type="text"
              maxlength="255"
              autocomplete="name"
              required
              :disabled="profileSubmitting"
            />
          </div>
          <p v-if="profileError" data-testid="profile-error" class="field-error">
            {{ profileError }}
          </p>
          <div class="flex justify-end">
            <button
              type="submit"
              class="btn btn-primary"
              :disabled="profileSubmitting || !profileDirty"
            >
              {{ profileSubmitting ? 'Saving…' : 'Save changes' }}
            </button>
          </div>
        </form>
      </section>

      <!-- Password -->
      <section class="card p-5">
        <h2 class="font-display font-semibold text-[16px]">Change password</h2>
        <p class="text-[12px] text-text-faint mt-[2px]">
          Choose a strong password — at least 8 characters with mixed case and a number.
          Changing it will sign you out of every other device.
        </p>
        <form class="mt-4 flex flex-col gap-3" novalidate @submit.prevent="submitPassword">
          <div>
            <label for="account-current-password" class="field-label">Current password</label>
            <input
              id="account-current-password"
              v-model="passwordForm.current_password"
              class="input"
              type="password"
              autocomplete="current-password"
              required
              :disabled="passwordSubmitting"
            />
          </div>
          <div>
            <label for="account-new-password" class="field-label">New password</label>
            <input
              id="account-new-password"
              v-model="passwordForm.password"
              class="input"
              type="password"
              minlength="8"
              autocomplete="new-password"
              required
              :disabled="passwordSubmitting"
            />
          </div>
          <div>
            <label for="account-new-password-confirm" class="field-label">Confirm new password</label>
            <input
              id="account-new-password-confirm"
              v-model="passwordForm.password_confirmation"
              class="input"
              type="password"
              autocomplete="new-password"
              required
              :disabled="passwordSubmitting"
            />
          </div>
          <p v-if="passwordError" data-testid="password-error" class="field-error">
            {{ passwordError }}
          </p>
          <div class="flex justify-end">
            <button type="submit" class="btn btn-primary" :disabled="passwordSubmitting">
              {{ passwordSubmitting ? 'Updating…' : 'Update password' }}
            </button>
          </div>
        </form>
      </section>
    </div>

    <Toast v-if="toast" :message="toast.message" :kind="toast.kind" @close="toast = null" />
  </AppShell>
</template>
