<script setup lang="ts">
// Step 2 of the password-reset flow.
// Email + token are read from the URL (?email=&token=) — the reset email
// embeds them — and the user just picks the new password.
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BrandMark from '@/components/BrandMark.vue'
import * as passwordApi from '@/api/password'
import { extractErrorMessage } from '@/api/client'

const route = useRoute()
const router = useRouter()

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
    error.value = 'Passwords do not match.'
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
    error.value = extractErrorMessage(err, 'Could not reset password.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="w-full h-full grid place-items-center bg-bg text-text">
    <div class="max-w-[420px] w-full card p-8">
      <div class="mb-6"><BrandMark /></div>
      <h1 class="font-display font-semibold text-[26px]">Choose a new password</h1>

      <div v-if="success" class="mt-5" data-testid="reset-success">
        <p class="text-success font-medium">Password updated.</p>
        <p class="text-[13px] text-text-faint mt-2">Redirecting you to sign in…</p>
      </div>

      <form v-else class="mt-5 flex flex-col gap-3" novalidate @submit.prevent="onSubmit">
        <div>
          <label for="reset-email" class="field-label">Email</label>
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
          <label for="reset-password" class="field-label">New password</label>
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
          <label for="reset-confirm" class="field-label">Confirm password</label>
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
          {{ submitting ? 'Updating…' : 'Update password' }}
        </button>
      </form>
    </div>
  </div>
</template>
