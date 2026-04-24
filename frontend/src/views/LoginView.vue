<script setup lang="ts">
// Login — 2-column split layout from the prototype (brand panel on the left,
// form on the right). On success redirects to either the `?redirect=...`
// path (set by the router guard) or the dashboard.
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BrandMark from '@/components/BrandMark.vue'
import { useAuthStore } from '@/stores/auth'
import { extractErrorMessage } from '@/api/client'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const email = ref('')
const password = ref('')
const submitting = ref(false)
const error = ref<string | null>(null)

async function onSubmit() {
  error.value = null
  submitting.value = true
  try {
    await auth.login(email.value, password.value)
    const redirect = (route.query.redirect as string | undefined) ?? '/'
    await router.push(redirect)
  } catch (err) {
    error.value = extractErrorMessage(err, 'Invalid credentials.')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="w-full h-full grid grid-cols-1 md:grid-cols-2 bg-bg text-text">
    <aside
      class="p-12 bg-bg-sunken md:border-r border-border flex flex-col justify-between min-h-[240px]"
    >
      <BrandMark :size="22" />
      <div class="mt-auto">
        <div class="font-display font-medium text-[44px] leading-[1.1] md:text-[48px]">
          Qui cantat,<br />
          <em class="text-accent not-italic italic">bis orat.</em>
        </div>
        <div class="text-[15px] text-text-muted mt-4 max-w-[360px]">
          He who sings, prays twice. A quiet place to keep songs, build sets, and run your Sunday.
        </div>
      </div>
    </aside>
    <section class="p-8 md:p-14 flex flex-col justify-center">
      <form class="max-w-[340px] w-full" novalidate @submit.prevent="onSubmit">
        <h1 class="font-display font-semibold text-[32px]">Welcome back</h1>
        <p class="text-[13px] text-text-faint mt-[6px]">Sign in to continue to your workspace.</p>
        <div class="mt-7 flex flex-col gap-3">
          <div>
            <label for="login-email" class="field-label">Email</label>
            <input
              id="login-email"
              v-model="email"
              class="input"
              type="email"
              autocomplete="username"
              required
              :disabled="submitting"
            />
          </div>
          <div>
            <label for="login-password" class="field-label">Password</label>
            <input
              id="login-password"
              v-model="password"
              class="input"
              type="password"
              autocomplete="current-password"
              required
              :disabled="submitting"
            />
          </div>
          <p v-if="error" data-testid="login-error" class="field-error">{{ error }}</p>
          <button
            type="submit"
            class="btn btn-primary justify-center"
            style="padding: 11px 14px; font-size: 14px"
            :disabled="submitting"
          >
            {{ submitting ? 'Signing in…' : 'Sign in' }}
          </button>
          <div class="flex items-center gap-[10px]">
            <div class="flex-1 h-px bg-border" />
            <span class="text-[11px] text-text-faint">or</span>
            <div class="flex-1 h-px bg-border" />
          </div>
          <router-link
            to="/forgot-password"
            class="text-[12px] text-text-muted hover:text-accent text-center"
          >
            Forgot your password?
          </router-link>
        </div>
      </form>
    </section>
  </div>
</template>
