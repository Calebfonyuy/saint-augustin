<script setup lang="ts">
/*
 * Admin → Settings → Bible (FR-BI-1). Lists the translations HelloAO offers
 * in the configured languages (grouped by language, incl. French), lets the
 * admin enable a set and pick a default, and saves — which repopulates the
 * server-side book-structure cache. Gated by requiresAdmin in the router.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/AppShell.vue'
import AdminTabs from '@/components/admin/AdminTabs.vue'
import Toast from '@/components/Toast.vue'
import { availableTranslations } from '@/api/bible'
import { useBibleStore } from '@/stores/bible'
import { extractErrorMessage } from '@/api/client'
import type { BibleTranslation } from '@/types'

const { t } = useI18n()
const bible = useBibleStore()

const available = ref<BibleTranslation[]>([])
const enabledIds = ref<Set<string>>(new Set())
const defaultId = ref<string | null>(null)
const loading = ref(true)
const saving = ref(false)
const toast = ref<{ message: string; kind: 'success' | 'error' } | null>(null)

/** Translations grouped by (display) language for the checklist. */
const groups = computed<Array<[string, BibleTranslation[]]>>(() => {
  const map: Record<string, BibleTranslation[]> = {}
  for (const tr of available.value) {
    const key = tr.language_name ?? tr.language ?? 'Other'
    ;(map[key] ??= []).push(tr)
  }
  return Object.entries(map).sort((a, b) => a[0].localeCompare(b[0]))
})

const enabledList = computed(() => available.value.filter((tr) => enabledIds.value.has(tr.id)))

onMounted(async () => {
  try {
    const [avail] = await Promise.all([availableTranslations(), bible.loadSettings()])
    available.value = avail
    enabledIds.value = new Set((bible.settings?.enabled_translations ?? []).map((x) => x.id))
    defaultId.value = bible.defaultTranslationId
  } catch (err) {
    toast.value = { kind: 'error', message: extractErrorMessage(err, t('bibleSettings.errors.load')) }
  } finally {
    loading.value = false
  }
})

function toggle(id: string): void {
  const next = new Set(enabledIds.value)
  if (next.has(id)) {
    next.delete(id)
    if (defaultId.value === id) defaultId.value = null
  } else {
    next.add(id)
  }
  enabledIds.value = next
  if (!defaultId.value && next.size > 0) defaultId.value = [...next][0]
}

async function onSave(): Promise<void> {
  saving.value = true
  try {
    await bible.saveSettings({
      enabled_translations: enabledList.value,
      default_translation_id: defaultId.value,
    })
    enabledIds.value = new Set((bible.settings?.enabled_translations ?? []).map((x) => x.id))
    defaultId.value = bible.defaultTranslationId
    toast.value = { kind: 'success', message: t('bibleSettings.saved') }
  } catch (err) {
    toast.value = { kind: 'error', message: extractErrorMessage(err, t('bibleSettings.errors.save')) }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <AppShell>
    <AdminTabs active="settings" :subtitle="t('bibleSettings.subtitle')">
      <template #actions>
        <button
          type="button"
          class="btn btn-primary"
          :disabled="saving || loading"
          data-testid="bible-save"
          @click="onSave"
        >
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </template>
    </AdminTabs>

    <div class="px-6 py-5 overflow-auto flex-1">
      <h2 class="font-display text-[18px] font-semibold mb-1">{{ t('bibleSettings.title') }}</h2>
      <p class="text-[13px] text-text-muted mb-5 max-w-[640px]">{{ t('bibleSettings.intro') }}</p>

      <div v-if="loading" class="text-text-faint text-[13px]">{{ t('common.loading') }}</div>

      <div
        v-else-if="available.length === 0"
        class="text-text-faint text-[13px]"
        data-testid="bible-empty"
      >
        {{ t('bibleSettings.none') }}
      </div>

      <div v-else class="flex flex-col gap-6 max-w-[720px]">
        <section v-for="[language, translations] in groups" :key="language">
          <div class="mono uppercase tracking-[0.14em] text-[11px] text-text-faint mb-2">
            {{ language }}
          </div>
          <div class="flex flex-col gap-1">
            <label
              v-for="tr in translations"
              :key="tr.id"
              class="card px-4 py-3 flex items-center gap-3 cursor-pointer"
              :data-testid="`bible-translation-${tr.id}`"
            >
              <input
                type="checkbox"
                :checked="enabledIds.has(tr.id)"
                :data-testid="`bible-enable-${tr.id}`"
                @change="toggle(tr.id)"
              />
              <div class="flex-1 min-w-0">
                <div class="text-[14px] font-medium truncate">{{ tr.name }}</div>
                <div v-if="tr.english_name && tr.english_name !== tr.name" class="text-[11.5px] text-text-faint truncate">
                  {{ tr.english_name }}
                </div>
              </div>
              <label
                v-if="enabledIds.has(tr.id)"
                class="flex items-center gap-1 text-[12px] text-text-muted"
                @click.stop
              >
                <input
                  type="radio"
                  name="bible-default"
                  :value="tr.id"
                  :checked="defaultId === tr.id"
                  :data-testid="`bible-default-${tr.id}`"
                  @change="defaultId = tr.id"
                />
                {{ t('bibleSettings.default') }}
              </label>
            </label>
          </div>
        </section>
      </div>
    </div>

    <Toast v-if="toast" :message="toast.message" :kind="toast.kind" @close="toast = null" />
  </AppShell>
</template>
