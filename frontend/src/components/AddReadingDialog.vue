<script setup lang="ts">
// Add a scripture reading to a playlist (FR-BI-3/4/7). Enter a reference
// either freeform ("Jean 3:16-4:2") or with the graphical picker, preview the
// resolved verses, then add it as a `scripture` playlist item (reusing the
// Stage-4/5 add-to-playlist path). Resolving also warms the server chapter
// cache — a head start on the projection pre-fetch.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Icon from './Icon.vue'
import ScripturePicker from './ScripturePicker.vue'
import { useBibleStore } from '@/stores/bible'
import { usePlaylistsStore } from '@/stores/playlists'
import { extractErrorMessage } from '@/api/client'
import type { ResolvedScripture, ScriptureQuery } from '@/types'

const props = defineProps<{ playlistId: string; open: boolean }>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'added', payload: { referenceLabel: string }): void
}>()

const { t } = useI18n()
const bible = useBibleStore()
const playlists = usePlaylistsStore()

type PickerRef = {
  book_code: string
  start_chapter: number
  start_verse: number
  end_chapter: number | null
  end_verse: number | null
}

const translationId = ref<string | null>(null)
const mode = ref<'freeform' | 'picker'>('freeform')
const freeform = ref('')
const pickerRef = ref<PickerRef | null>(null)
const preview = ref<ResolvedScripture | null>(null)
const busy = ref<'idle' | 'resolving' | 'adding'>('idle')
const error = ref<string | null>(null)

const enabled = computed(() => bible.enabled)
const configured = computed(() => enabled.value.length > 0)

onMounted(async () => {
  if (!bible.settings) {
    try {
      await bible.loadSettings()
    } catch {
      /* surfaced as "not configured" below */
    }
  }
  translationId.value = bible.defaultTranslationId ?? enabled.value[0]?.id ?? null
})

function currentQuery(): ScriptureQuery | null {
  if (mode.value === 'freeform') {
    const q = freeform.value.trim()
    return q ? { q, translation_id: translationId.value } : null
  }
  if (!pickerRef.value) return null
  return { ...pickerRef.value, translation_id: translationId.value }
}

async function onResolve(): Promise<void> {
  const query = currentQuery()
  if (!query) {
    error.value = t('addReading.errors.empty')
    return
  }
  busy.value = 'resolving'
  error.value = null
  preview.value = null
  try {
    preview.value = await bible.resolve(query)
  } catch (err) {
    error.value = extractErrorMessage(err, t('addReading.errors.resolve'))
  } finally {
    busy.value = 'idle'
  }
}

async function onAdd(): Promise<void> {
  if (!preview.value) return
  const ref = preview.value.reference
  busy.value = 'adding'
  error.value = null
  try {
    await playlists.addItem(props.playlistId, {
      item_type: 'scripture',
      translation_id: preview.value.translation_id,
      book_code: ref.book_code,
      start_chapter: ref.start_chapter,
      start_verse: ref.start_verse,
      end_chapter: ref.end_chapter,
      end_verse: ref.end_verse,
    })
    emit('added', { referenceLabel: preview.value.reference_label })
  } catch (err) {
    error.value = extractErrorMessage(err, t('addReading.errors.add'))
  } finally {
    busy.value = 'idle'
  }
}
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-40 grid place-items-center bg-black/30"
    role="dialog"
    aria-modal="true"
    aria-labelledby="add-reading-title"
    data-testid="add-reading-dialog"
    @click.self="emit('close')"
  >
    <div class="card w-[520px] max-w-[92vw] max-h-[88vh] flex flex-col">
      <div class="flex items-center justify-between px-5 py-4 border-b border-border">
        <div id="add-reading-title" class="font-display font-semibold text-[18px]">
          {{ t('addReading.title') }}
        </div>
        <button type="button" class="btn btn-ghost" :aria-label="t('common.close')" @click="emit('close')">
          <Icon name="x" />
        </button>
      </div>

      <div class="px-5 py-4 flex flex-col gap-4 overflow-auto">
        <div v-if="!configured" class="text-[13px] text-text-muted" data-testid="add-reading-unconfigured">
          {{ t('addReading.unconfigured') }}
        </div>

        <template v-else>
          <div>
            <label class="field-label" for="reading-translation">{{ t('addReading.translation') }}</label>
            <select id="reading-translation" v-model="translationId" class="input" data-testid="reading-translation">
              <option v-for="tr in enabled" :key="tr.id" :value="tr.id">{{ tr.name }}</option>
            </select>
          </div>

          <div class="flex gap-1">
            <button
              type="button"
              class="btn"
              :class="{ 'btn-primary': mode === 'freeform' }"
              data-testid="reading-mode-freeform"
              @click="mode = 'freeform'"
            >
              {{ t('addReading.modeFreeform') }}
            </button>
            <button
              type="button"
              class="btn"
              :class="{ 'btn-primary': mode === 'picker' }"
              data-testid="reading-mode-picker"
              @click="mode = 'picker'"
            >
              {{ t('addReading.modePicker') }}
            </button>
          </div>

          <input
            v-if="mode === 'freeform'"
            v-model="freeform"
            class="input"
            :placeholder="t('addReading.freeformPlaceholder')"
            data-testid="reading-freeform"
            @keydown.enter="onResolve"
          />
          <ScripturePicker v-else :translation-id="translationId" @change="pickerRef = $event" />

          <button
            type="button"
            class="btn"
            :disabled="busy !== 'idle'"
            data-testid="reading-resolve"
            @click="onResolve"
          >
            {{ busy === 'resolving' ? t('addReading.resolving') : t('addReading.preview') }}
          </button>

          <div v-if="preview" class="card p-3" data-testid="reading-preview">
            <div class="text-[13px] font-semibold">{{ preview.reference_label }}</div>
            <div class="text-[11px] text-text-faint mb-2">{{ preview.translation_label }}</div>
            <div class="text-[13px] max-h-[160px] overflow-auto">
              <span v-for="v in preview.verses" :key="`${v.chapter}:${v.number}`">
                <sup class="text-text-faint">{{ v.number }}</sup> {{ v.text }}
              </span>
            </div>
          </div>

          <p v-if="error" class="field-error" data-testid="reading-error">{{ error }}</p>
        </template>
      </div>

      <div class="flex items-center justify-end gap-2 px-5 py-4 border-t border-border">
        <button type="button" class="btn" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button
          type="button"
          class="btn btn-primary"
          :disabled="!preview || busy !== 'idle'"
          data-testid="reading-add"
          @click="onAdd"
        >
          <Icon name="plus" /> {{ t('addReading.add') }}
        </button>
      </div>
    </div>
  </div>
</template>
