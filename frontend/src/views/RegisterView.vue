<script setup lang="ts">
// Invitation-based registration.
// 1. Read `?token=...` from the URL, verify it against /api/auth/invitations/{token}
// 2. If valid, show a form (email + roles are read-only — from the invite)
// 3. On submit, POST /api/auth/register and redirect to /login
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BrandMark from '@/components/BrandMark.vue'
import * as invitationsApi from '@/api/invitations'
import { extractErrorMessage } from '@/api/client'
import type { Invitation } from '@/types'

const route = useRoute()
const router = useRouter()

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
  const t = route.query.token
  if (typeof t !== 'string' || !t) {
    verifyError.value = 'No invitation token was provided. Ask an admin for a new invite link.'
    loading.value = false
    return
  }
  token.value = t
  try {
    invitation.value = await invitationsApi.verifyInvitation(t)
  } catch (err) {
    verifyError.value = extractErrorMessage(err, 'This invitation link is invalid or has expired.')
  } finally {
    loading.value = false
  }
})

async function onSubmit() {
  submitError.value = null
  if (password.value !== passwordConfirm.value) {
    submitError.value = 'Passwords do not match.'
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
    submitError.value = extractErrorMessage(err, 'Registration failed.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="w-full h-full grid grid-cols-1 md:grid-cols-2 bg-bg text-text">
    <aside class="p-12 bg-bg-sunken md:border-r border-border flex flex-col justify-between min-h-[240px]">
      <BrandMark :size="22" />
      <div class="mt-auto">
        <div class="font-display font-medium text-[44px] leading-[1.1] md:text-[48px]">
          Welcome to<br /><em class="text-accent not-italic italic">SaintAugustin.</em>
        </div>
        <div class="text-[15px] text-text-muted mt-4 max-w-[360px]">
          One place for your choir's songs, chords, and Sunday sets.
        </div>
      </div>
    </aside>
    <section class="p-8 md:p-14 flex flex-col justify-center">
      <div class="max-w-[360px] w-full">
        <h1 class="font-display font-semibold text-[32px]">Accept your invite</h1>

        <div v-if="loading" class="mt-6 text-[13px] text-text-faint" data-testid="register-loading">
          Verifying invitation…
        </div>

        <div v-else-if="verifyError" class="mt-6">
          <p data-testid="register-verify-error" class="field-error mb-3">{{ verifyError }}</p>
          <router-link to="/login" class="btn">Back to sign in</router-link>
        </div>

        <div v-else-if="success" class="mt-6" data-testid="register-success">
          <p class="text-success font-medium">Account created.</p>
          <p class="text-[13px] text-text-faint mt-2">Redirecting you to sign in…</p>
        </div>

        <form v-else class="mt-6 flex flex-col gap-3" novalidate @submit.prevent="onSubmit">
          <div>
            <label class="field-label">Email</label>
            <input class="input" :value="invitation?.email" readonly tabindex="-1" />
          </div>
          <div>
            <label class="field-label">Assigned roles</label>
            <div class="flex gap-2 mt-1">
              <span v-for="r in invitation?.roles" :key="r" class="chip chip-accent">{{ r }}</span>
            </div>
          </div>
          <div>
            <label for="reg-name" class="field-label">Display name</label>
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
            <label for="reg-password" class="field-label">Password</label>
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
              Minimum 8 characters, mixed case with at least one number.
            </p>
          </div>
          <div>
            <label for="reg-confirm" class="field-label">Confirm password</label>
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
            {{ submitting ? 'Creating account…' : 'Create account' }}
          </button>
        </form>
      </div>
    </section>
  </div>
</template>
