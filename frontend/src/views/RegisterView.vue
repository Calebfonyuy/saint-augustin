<script setup lang="ts">
// Invitation-based registration.
// 1. Read `?token=...` from the URL, verify it against /api/auth/invitations/{token}
// 2. If valid, show a form (email + roles are read-only — from the invite)
// 3. On submit, POST /api/auth/register and redirect to /login
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import BrandMark from '@/components/BrandMark.vue'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'
import ThemeSwitcher from '@/components/ThemeSwitcher.vue'
import * as invitationsApi from '@/api/invitations'
import { extractErrorMessage } from '@/api/client'
import type { Invitation } from '@/types'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const token = ref<string>('')
const invitation = ref<Invitation | null>(null)
const loading = ref(true)
const verifyError = ref<string | null>(null)

const displayName = ref('')
const password = ref('')
const passwordConfirm = ref('')
const submitting = ref(false)
const submitError = ref<string | null>(null)
const success = ref(false)

onMounted(async () => {
  const tk = route.query.token
  if (typeof tk !== 'string' || !tk) {
    verifyError.value = t('auth.register.noToken')
    loading.value = false
    return
  }
  token.value = tk
  try {
    invitation.value = await invitationsApi.verifyInvitation(tk)
  } catch (err) {
    verifyError.value = extractErrorMessage(err, t('auth.register.verifyError'))
  } finally {
    loading.value = false
  }
})

async function onSubmit() {
  submitError.value = null
  if (password.value !== passwordConfirm.value) {
    submitError.value = t('auth.register.passwordMismatch')
    return
  }
  submitting.value = true
  try {
    await invitationsApi.register({
      token: token.value,
      display_name: displayName.value,
      password: password.value,
      password_confirmation: passwordConfirm.value,
    })
    success.value = true
    setTimeout(() => router.push({ name: 'login' }), 1500)
  } catch (err) {
    submitError.value = extractErrorMessage(err, t('auth.register.registrationFailed'))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div
    class="relative w-full h-full overflow-y-auto grid grid-cols-1 md:grid-cols-2 bg-bg text-text"
  >
    <div class="fixed top-3 right-3 z-20 flex items-center gap-2">
      <ThemeSwitcher direction="down" align="right" />
      <LanguageSwitcher direction="down" align="right" />
    </div>
    <aside class="p-6 md:p-12 bg-bg-sunken md:border-r border-border flex flex-col justify-between md:min-h-[240px]">
      <BrandMark :size="22" />
      <div class="mt-6 md:mt-auto">
        <div class="font-display font-medium text-[30px] leading-[1.1] md:text-[44px] lg:text-[48px]">
          {{ t('auth.register.panelHeadlinePre') }}<br /><em class="text-accent not-italic italic">SaintAugustin.</em>
        </div>
        <div class="text-[14px] md:text-[15px] text-text-muted mt-3 md:mt-4 max-w-[360px]">
          {{ t('auth.register.panelSubtext') }}
        </div>
      </div>
    </aside>
    <section class="p-6 md:p-14 flex flex-col justify-center">
      <div class="max-w-[360px] w-full mx-auto md:mx-0">
        <h1 class="font-display font-semibold text-[26px] md:text-[32px]">{{ t('auth.register.title') }}</h1>

        <div v-if="loading" class="mt-6 text-[13px] text-text-faint" data-testid="register-loading">
          {{ t('auth.register.verifying') }}
        </div>

        <div v-else-if="verifyError" class="mt-6">
          <p data-testid="register-verify-error" class="field-error mb-3">{{ verifyError }}</p>
          <router-link to="/login" class="btn">{{ t('auth.register.backToSignIn') }}</router-link>
        </div>

        <div v-else-if="success" class="mt-6" data-testid="register-success">
          <p class="text-success font-medium">{{ t('auth.register.accountCreated') }}</p>
          <p class="text-[13px] text-text-faint mt-2">{{ t('auth.register.redirecting') }}</p>
        </div>

        <form v-else class="mt-6 flex flex-col gap-3" novalidate @submit.prevent="onSubmit">
          <div>
            <label class="field-label">{{ t('auth.register.email') }}</label>
            <input class="input" :value="invitation?.email" readonly tabindex="-1" />
          </div>
          <div>
            <label class="field-label">{{ t('auth.register.assignedRoles') }}</label>
            <div class="flex gap-2 mt-1">
              <span v-for="r in invitation?.roles" :key="r" class="chip chip-accent">{{ r }}</span>
            </div>
          </div>
          <div>
            <label for="reg-name" class="field-label">{{ t('auth.register.displayName') }}</label>
            <input
              id="reg-name"
              v-model="displayName"
              class="input"
              required
              autocomplete="name"
              :disabled="submitting"
            />
          </div>
          <div>
            <label for="reg-password" class="field-label">{{ t('auth.register.password') }}</label>
            <input
              id="reg-password"
              v-model="password"
              class="input"
              type="password"
              minlength="8"
              required
              autocomplete="new-password"
              :disabled="submitting"
            />
            <p class="text-[11px] text-text-faint mt-[4px]">
              {{ t('auth.register.passwordHint') }}
            </p>
          </div>
          <div>
            <label for="reg-confirm" class="field-label">{{ t('auth.register.confirmPassword') }}</label>
            <input
              id="reg-confirm"
              v-model="passwordConfirm"
              class="input"
              type="password"
              required
              autocomplete="new-password"
              :disabled="submitting"
            />
          </div>
          <p v-if="submitError" data-testid="register-error" class="field-error">{{ submitError }}</p>
          <button type="submit" class="btn btn-primary justify-center" :disabled="submitting">
            {{ submitting ? t('auth.register.submitting') : t('auth.register.submit') }}
          </button>
        </form>
      </div>
    </section>
  </div>
</template>
