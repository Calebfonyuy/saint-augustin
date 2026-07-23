<script setup lang="ts">
// Login — 2-column split layout from the prototype (brand panel on the left,
// form on the right). On success redirects to either the `?redirect=...`
// path (set by the router guard) or the dashboard.
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import BrandMark from '@/components/BrandMark.vue'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'
import { useAuthStore } from '@/stores/auth'
import { extractErrorMessage } from '@/api/client'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

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
    error.value = extractErrorMessage(err, t('auth.login.invalidCredentials'))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div
    class="relative grid w-full h-full grid-cols-1 overflow-y-auto md:grid-cols-2 bg-bg text-text"
  >
    <div class="fixed z-20 top-3 right-3">
      <LanguageSwitcher direction="down" align="right" />
    </div>
    <aside
      class="p-6 md:p-12 bg-bg-sunken md:border-r border-border flex flex-col justify-between md:min-h-[240px]"
    >
      <BrandMark :size="100" :path=" '/logo-512.png'" />
      <div class="mt-6 md:mt-auto">
        <div class="font-display font-medium text-[30px] leading-[1.1] md:text-[44px] lg:text-[48px]">
          Qui cantat,<br />
          <em class="italic not-italic text-accent">bis orat.</em>
        </div>
        <div class="text-[14px] md:text-[15px] text-text-muted mt-3 md:mt-4 max-w-[360px]">
          {{ t('auth.login.taglineSub') }}
        </div>
      </div>
    </aside>
    <section class="flex flex-col justify-center p-6 md:p-14">
      <form class="max-w-[340px] w-full mx-auto md:mx-0" novalidate @submit.prevent="onSubmit">
        <h1 class="font-display font-semibold text-[26px] md:text-[32px]">{{ t('auth.login.title') }}</h1>
        <p class="text-[13px] text-text-faint mt-[6px]">{{ t('auth.login.subtitle') }}</p>
        <div class="flex flex-col gap-3 mt-7">
          <div>
            <label for="login-email" class="field-label">{{ t('auth.login.email') }}</label>
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
            <label for="login-password" class="field-label">{{ t('auth.login.password') }}</label>
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
            class="justify-center btn btn-primary"
            style="padding: 11px 14px; font-size: 14px"
            :disabled="submitting"
          >
            {{ submitting ? t('auth.login.submitting') : t('auth.login.submit') }}
          </button>
          <div class="flex items-center gap-[10px]">
            <div class="flex-1 h-px bg-border" />
            <span class="text-[11px] text-text-faint">{{ t('common.or') }}</span>
            <div class="flex-1 h-px bg-border" />
          </div>
          <router-link
            to="/forgot-password"
            class="text-[12px] text-text-muted hover:text-accent text-center"
          >
            {{ t('auth.login.forgotPassword') }}
          </router-link>
        </div>
      </form>
    </section>
  </div>
</template>
