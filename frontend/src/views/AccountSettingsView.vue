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
import axios from 'axios'
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/AppShell.vue'
import Toast from '@/components/Toast.vue'
import { useAuthStore } from '@/stores/auth'
import { extractErrorMessage } from '@/api/client'

const auth = useAuthStore()
const { t } = useI18n()

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
    profileError.value = t('account.errors.emptyName')
    return
  }
  profileSubmitting.value = true
  try {
    await auth.updateProfile({ display_name: trimmed })
    toast.value = { message: t('account.toast.profileUpdated'), kind: 'success' }
  } catch (err) {
    profileError.value = extractErrorMessage(err, t('account.errors.updateProfile'))
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
    passwordError.value = t('account.errors.passwordMismatch')
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
      message: t('account.toast.passwordChanged'),
      kind: 'success',
    }
  } catch (err) {
    passwordError.value = translatePasswordError(err)
  } finally {
    passwordSubmitting.value = false
  }
}

/**
 * Map Laravel's password-validation responses to translated messages.
 *
 * The auth service returns 422 with `errors.{field}: [string]` for each
 * failing field. Field names are reliable (current_password / password)
 * but the human messages are produced by Laravel in the server locale —
 * which is English. We pattern-match on the message *content* for the
 * password field (length / mixed-case / numbers) to surface a localised
 * equivalent, and fall through to a generic translated message for cases
 * we don't recognise.
 */
function translatePasswordError(err: unknown): string {
  if (axios.isAxiosError(err) && err.response?.status === 422) {
    const data = err.response.data as
      | { message?: string; errors?: Record<string, string[]> }
      | undefined
    const errors = data?.errors ?? {}
    if (errors.current_password?.length) {
      return t('account.errors.currentPasswordWrong')
    }
    const passwordMsg = errors.password?.[0]?.toLowerCase() ?? ''
    if (passwordMsg) {
      // Order matters: "uppercase / lowercase" is the most common combined
      // failure and must be checked before the more generic "character"
      // length rule (the case message also contains "letter", but the
      // length message contains the word "characters").
      if (
        passwordMsg.includes('confirm') ||
        passwordMsg.includes('confirmation')
      ) {
        return t('account.errors.passwordMismatch')
      }
      if (
        passwordMsg.includes('uppercase') ||
        passwordMsg.includes('lowercase') ||
        passwordMsg.includes('mixed case')
      ) {
        return t('account.errors.passwordCase')
      }
      if (
        passwordMsg.includes('number') ||
        passwordMsg.includes('digit')
      ) {
        return t('account.errors.passwordDigit')
      }
      if (
        passwordMsg.includes('characters') ||
        passwordMsg.includes('at least 8') ||
        passwordMsg.includes('minimum')
      ) {
        return t('account.errors.passwordTooShort')
      }
      return t('account.errors.passwordRules')
    }
  }
  return extractErrorMessage(err, t('account.errors.changePassword'))
}

// ── Toast ─────────────────────────────────────────────────────────────
const toast = ref<{ message: string; kind: 'error' | 'success' } | null>(null)

const roleSummary = computed(() => {
  const roles = auth.user?.roles ?? []
  if (!roles.length) return t('account.noRoles')
  return roles.map((r) => t(`roles.${r}`)).join(' · ')
})
</script>

<template>
  <AppShell>
    <div class="px-8 pt-7 pb-2">
      <div class="font-display font-semibold text-[28px]">{{ t('account.title') }}</div>
      <div class="text-[13px] text-text-faint mt-[6px]">
        {{ t('account.subtitle') }}
      </div>
    </div>

    <div class="px-8 pb-8 pt-4 overflow-auto flex-1 flex flex-col gap-5 max-w-[680px]">
      <!-- Identity summary -->
      <section class="card p-5">
        <div class="text-[11px] mono uppercase tracking-[0.14em] text-text-faint">{{ t('account.signedInAs') }}</div>
        <div class="font-display font-semibold text-[18px] mt-[4px]">
          {{ auth.user?.email ?? '—' }}
        </div>
        <div class="text-[12px] text-text-faint mt-[2px]">{{ roleSummary }}</div>
        <div class="text-[12px] text-text-faint mt-[8px]">
          {{ t('account.emailRolesHint') }}
        </div>
      </section>

      <!-- Display name -->
      <section class="card p-5">
        <h2 class="font-display font-semibold text-[16px]">{{ t('account.profile.title') }}</h2>
        <p class="text-[12px] text-text-faint mt-[2px]">
          {{ t('account.profile.subtitle') }}
        </p>
        <form class="mt-4 flex flex-col gap-3" novalidate @submit.prevent="submitProfile">
          <div>
            <label for="account-display-name" class="field-label">{{ t('account.profile.displayName') }}</label>
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
              {{ profileSubmitting ? t('common.saving') : t('account.profile.save') }}
            </button>
          </div>
        </form>
      </section>

      <!-- Password -->
      <section class="card p-5">
        <h2 class="font-display font-semibold text-[16px]">{{ t('account.password.title') }}</h2>
        <p class="text-[12px] text-text-faint mt-[2px]">
          {{ t('account.password.subtitle') }}
        </p>
        <form class="mt-4 flex flex-col gap-3" novalidate @submit.prevent="submitPassword">
          <div>
            <label for="account-current-password" class="field-label">{{ t('account.password.current') }}</label>
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
            <label for="account-new-password" class="field-label">{{ t('account.password.new') }}</label>
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
            <label for="account-new-password-confirm" class="field-label">{{ t('account.password.confirm') }}</label>
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
              {{ passwordSubmitting ? t('account.password.updating') : t('account.password.update') }}
            </button>
          </div>
        </form>
      </section>
    </div>

    <Toast v-if="toast" :message="toast.message" :kind="toast.kind" @close="toast = null" />
  </AppShell>
</template>
