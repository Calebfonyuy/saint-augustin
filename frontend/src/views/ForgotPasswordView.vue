<script setup lang="ts">
// Step 1 of the password-reset flow.
// The API response is intentionally generic (same message whether the email is
// registered or not) to prevent user enumeration. We surface that message.
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import BrandMark from '@/components/BrandMark.vue'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'
import ThemeSwitcher from '@/components/ThemeSwitcher.vue'
import * as passwordApi from '@/api/password'
import { extractErrorMessage } from '@/api/client'

const { t } = useI18n()
const email = ref('')
const submitting = ref(false)
const message = ref<string | null>(null)
const error = ref<string | null>(null)

async function onSubmit() {
  message.value = null
  error.value = null
  submitting.value = true
  try {
    const res = await passwordApi.requestPasswordReset(email.value)
    message.value = res.message
  } catch (err) {
    error.value = extractErrorMessage(err, t('auth.forgotPassword.error'))
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
        <h1 class="font-display font-semibold text-[22px] sm:text-[26px]">{{ t('auth.forgotPassword.title') }}</h1>
        <p class="text-[13px] text-text-faint mt-[6px]">
          {{ t('auth.forgotPassword.subtitle') }}
        </p>
        <form class="mt-5 flex flex-col gap-3" novalidate @submit.prevent="onSubmit">
          <div>
            <label for="forgot-email" class="field-label">{{ t('auth.forgotPassword.email') }}</label>
            <input
              id="forgot-email"
              v-model="email"
              class="input"
              type="email"
              required
              autocomplete="email"
              :disabled="submitting"
            />
          </div>
          <p v-if="error" data-testid="forgot-error" class="field-error">{{ error }}</p>
          <p v-if="message" data-testid="forgot-message" class="text-[13px] text-success">
            {{ message }}
          </p>
          <button type="submit" class="btn btn-primary justify-center" :disabled="submitting">
            {{ submitting ? t('auth.forgotPassword.submitting') : t('auth.forgotPassword.submit') }}
          </button>
          <router-link to="/login" class="text-[12px] text-text-muted text-center hover:text-accent">
            {{ t('auth.forgotPassword.backToSignIn') }}
          </router-link>
        </form>
      </div>
    </div>
  </div>
</template>
