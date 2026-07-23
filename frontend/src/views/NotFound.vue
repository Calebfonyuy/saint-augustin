<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import BrandMark from '@/components/BrandMark.vue'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'
import ThemeSwitcher from '@/components/ThemeSwitcher.vue'

const { t } = useI18n()
</script>

<template>
  <div class="relative w-full h-full overflow-y-auto bg-bg text-text">
    <div class="fixed top-3 right-3 z-20 flex items-center gap-2">
      <ThemeSwitcher direction="down" align="right" />
      <LanguageSwitcher direction="down" align="right" />
    </div>
    <div class="min-h-full grid place-items-center p-4 sm:p-8">
      <div class="max-w-[420px] w-full card p-6 sm:p-8 text-center">
        <div class="mb-6 flex justify-center"><BrandMark /></div>
        <h1 class="font-display font-semibold text-[22px] sm:text-[26px]">{{ t('notFound.title') }}</h1>
        <p class="text-[13px] text-text-faint mt-[6px]">
          {{ t('notFound.subtitle') }}
        </p>
        <router-link to="/" class="btn btn-primary justify-center mt-5">
          {{ t('notFound.backHome') }}
        </router-link>
      </div>
    </div>
  </div>
</template>
