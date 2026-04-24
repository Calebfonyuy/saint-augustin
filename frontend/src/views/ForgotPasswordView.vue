<script setup lang="ts">
// Step 1 of the password-reset flow.
// The API response is intentionally generic (same message whether the email is
// registered or not) to prevent user enumeration. We surface that message.
import { ref } from 'vue'
import BrandMark from '@/components/BrandMark.vue'
import * as passwordApi from '@/api/password'
import { extractErrorMessage } from '@/api/client'

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
    error.value = extractErrorMessage(err, 'Could not send reset link.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="w-full h-full grid place-items-center bg-bg text-text">
    <div class="max-w-[420px] w-full card p-8">
      <div class="mb-6"><BrandMark /></div>
      <h1 class="font-display font-semibold text-[26px]">Reset your password</h1>
      <p class="text-[13px] text-text-faint mt-[6px]">
        Enter the email address on your account and we'll send you a reset link.
      </p>
      <form class="mt-5 flex flex-col gap-3" novalidate @submit.prevent="onSubmit">
        <div>
          <label for="forgot-email" class="field-label">Email</label>
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
          {{ submitting ? 'Sending…' : 'Send reset link' }}
        </button>
        <router-link to="/login" class="text-[12px] text-text-muted text-center hover:text-accent">
          Back to sign in
        </router-link>
      </form>
    </div>
  </div>
</template>
