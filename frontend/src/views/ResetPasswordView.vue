<script setup lang="ts">
// Step 2 of the password-reset flow.
// Email + token are read from the URL (?email=&token=) — the reset email
// embeds them — and the user just picks the new password.
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import BrandMark from '@/components/BrandMark.vue'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'
import ThemeSwitcher from '@/components/ThemeSwitcher.vue'
import * as passwordApi from '@/api/password'
import { extractErrorMessage } from '@/api/client'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const email = ref('')
const token = ref('')
const password = ref('')
const passwordConfirm = ref('')
const submitting = ref(false)
const error = ref<string | null>(null)
const success = ref(false)

onMounted(() => {
  if (typeof route.query.email === 'string') email.value = route.query.email
  if (typeof route.query.token === 'string') token.value = route.query.token
})

async function onSubmit() {
  error.value = null
  if (password.value !== passwordConfirm.value) {
    error.value = t('auth.resetPassword.passwordMismatch')
    return
  }
  submitting.value = true
  try {
    await passwordApi.resetPassword({
      email: email.value,
      token: token.value,
      password: password.value,
      password_confirmation: passwordConfirm.value,
    })
    success.value = true
    setTimeout(() => router.push({ name: 'login' }), 1500)
  } catch (err) {
    error.value = extractErrorMessage(err, t('auth.resetPassword.error'))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="relative w-full h-full overflow-y-auto bg-bg text-text">
    <div class="fixed top-3 right-3 z-20 flex items-center gap-2">
      <ThemeSwitcher direction="down" align="right" />
      <LanguageSwitcher direction="down" align="right" />
    </div>
    <div class="min-h-full grid place-items-center p-4 sm:p-8">
      <div class="max-w-[420px] w-full card p-6 sm:p-8">
        <div class="mb-6"><BrandMark /></div>
        <h1 class="font-display font-semibold text-[22px] sm:text-[26px]">{{ t('auth.resetPassword.title') }}</h1>

        <div v-if="success" class="mt-5" data-testid="reset-success">
          <p class="text-success font-medium">{{ t('auth.resetPassword.success') }}</p>
          <p class="text-[13px] text-text-faint mt-2">{{ t('auth.resetPassword.redirecting') }}</p>
        </div>

        <form v-else class="mt-5 flex flex-col gap-3" novalidate @submit.prevent="onSubmit">
          <div>
            <label for="reset-email" class="field-label">{{ t('auth.resetPassword.email') }}</label>
            <input
              id="reset-email"
              v-model="email"
              class="input"
              type="email"
              required
              :disabled="submitting"
            />
          </div>
          <div>
            <label for="reset-password" class="field-label">{{ t('auth.resetPassword.newPassword') }}</label>
            <input
              id="reset-password"
              v-model="password"
              class="input"
              type="password"
              minlength="8"
              required
              autocomplete="new-password"
              :disabled="submitting"
            />
          </div>
          <div>
            <label for="reset-confirm" class="field-label">{{ t('auth.resetPassword.confirmPassword') }}</label>
            <input
              id="reset-confirm"
              v-model="passwordConfirm"
              class="input"
              type="password"
              required
              autocomplete="new-password"
              :disabled="submitting"
            />
          </div>
          <p v-if="error" data-testid="reset-error" class="field-error">{{ error }}</p>
          <button type="submit" class="btn btn-primary justify-center" :disabled="submitting">
            {{ submitting ? t('auth.resetPassword.submitting') : t('auth.resetPassword.submit') }}
          </button>
        </form>
      </div>
    </div>
  </div>
</template>
