<script setup lang="ts">
// STAUG interchange panel (Stage 6) — shown on the Admin → Import screen.
// Two self-contained actions:
//   • Import a signed STAUG archive: pick a file → preview per-song actions
//     (create/skip/conflict) → commit (non-destructive merge).
//   • Queue a full-library export: the archive is built server-side and a
//     download link is emailed to the requesting admin.
// Toasts are delegated to the parent via `notify` so this stays presentational.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Icon from '@/components/Icon.vue'
import {
  importStaug,
  previewStaug,
  requestFullExport,
  type StaugImportPreview,
  type StaugImportResult,
} from '@/api/imports'
import { extractErrorMessage } from '@/api/client'

const emit = defineEmits<{
  (e: 'notify', payload: { kind: 'success' | 'error'; message: string }): void
}>()

const { t } = useI18n()

const file = ref<File | null>(null)
const preview = ref<StaugImportPreview | null>(null)
const result = ref<StaugImportResult | null>(null)
const busy = ref<'idle' | 'parsing' | 'importing' | 'exporting'>('idle')
const fileInput = ref<HTMLInputElement | null>(null)

const counts = computed(() => {
  const c = { create: 0, skip: 0, conflict: 0 }
  for (const s of preview.value?.songs ?? []) c[s.action]++
  return c
})

function reset(): void {
  file.value = null
  preview.value = null
  result.value = null
  if (fileInput.value) fileInput.value.value = ''
}

async function onFile(picked: File): Promise<void> {
  file.value = picked
  preview.value = null
  result.value = null
  busy.value = 'parsing'
  try {
    preview.value = await previewStaug(picked)
  } catch (err) {
    emit('notify', { kind: 'error', message: extractErrorMessage(err, t('staug.errors.preview')) })
    file.value = null
  } finally {
    busy.value = 'idle'
  }
}

function onPickChange(e: Event): void {
  const f = (e.target as HTMLInputElement).files?.[0]
  if (f) void onFile(f)
}

async function onImport(): Promise<void> {
  if (!file.value) return
  busy.value = 'importing'
  try {
    const r = await importStaug(file.value)
    result.value = r
    emit('notify', {
      kind: 'success',
      message: t('staug.toast.imported', {
        created: r.created,
        skipped: r.skipped,
        conflicted: r.conflicted,
      }),
    })
  } catch (err) {
    emit('notify', { kind: 'error', message: extractErrorMessage(err, t('staug.errors.import')) })
  } finally {
    busy.value = 'idle'
  }
}

async function onFullExport(): Promise<void> {
  busy.value = 'exporting'
  try {
    await requestFullExport()
    emit('notify', { kind: 'success', message: t('staug.toast.exportQueued') })
  } catch (err) {
    emit('notify', { kind: 'error', message: extractErrorMessage(err, t('staug.errors.export')) })
  } finally {
    busy.value = 'idle'
  }
}
</script>

<template>
  <section class="mt-8 pt-6 border-t border-border" data-testid="staug-panel">
    <h2 class="font-display text-[18px] font-semibold mb-1">{{ t('staug.title') }}</h2>
    <p class="text-[13px] text-text-muted mb-4 max-w-[640px]">{{ t('staug.intro') }}</p>

    <div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr))">
      <!-- Import -->
      <div class="card p-5">
        <div class="font-semibold text-[14px] mb-2">{{ t('staug.import.title') }}</div>
        <button
          type="button"
          class="btn"
          data-testid="staug-pick"
          :disabled="busy !== 'idle'"
          @click="fileInput?.click()"
        >
          <Icon name="upload" /> {{ busy === 'parsing' ? t('staug.import.parsing') : t('staug.import.choose') }}
        </button>
        <input
          ref="fileInput"
          type="file"
          accept=".zip,.staug"
          class="hidden"
          data-testid="staug-file"
          @change="onPickChange"
        />
        <div v-if="file" class="text-[12px] text-text-faint mt-2 truncate">{{ file.name }}</div>

        <div v-if="preview" class="mt-3" data-testid="staug-preview">
          <div class="flex gap-2 flex-wrap text-[12px]">
            <span class="chip">{{ t('staug.import.create', { n: counts.create }) }}</span>
            <span class="chip">{{ t('staug.import.skip', { n: counts.skip }) }}</span>
            <span class="chip">{{ t('staug.import.conflict', { n: counts.conflict }) }}</span>
          </div>
          <button
            type="button"
            class="btn btn-primary mt-3"
            data-testid="staug-import"
            :disabled="busy !== 'idle'"
            @click="onImport"
          >
            {{ busy === 'importing' ? t('staug.import.importing') : t('staug.import.commit') }}
          </button>
        </div>

        <div v-if="result" class="mt-3 text-[13px]" data-testid="staug-result">
          {{ t('staug.result', { created: result.created, skipped: result.skipped, conflicted: result.conflicted }) }}
          <button type="button" class="text-accent text-[12px] hover:underline ml-2" @click="reset">
            {{ t('staug.import.another') }}
          </button>
        </div>
      </div>

      <!-- Full library export -->
      <div class="card p-5">
        <div class="font-semibold text-[14px] mb-2">{{ t('staug.export.title') }}</div>
        <p class="text-[12px] text-text-muted mb-3">{{ t('staug.export.hint') }}</p>
        <button
          type="button"
          class="btn btn-primary"
          data-testid="staug-full-export"
          :disabled="busy !== 'idle'"
          @click="onFullExport"
        >
          <Icon name="upload" /> {{ busy === 'exporting' ? t('staug.export.queuing') : t('staug.export.action') }}
        </button>
      </div>
    </div>
  </section>
</template>
