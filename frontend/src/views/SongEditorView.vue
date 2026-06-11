<script setup lang="ts">
// Song Editor — handles both `/songs/new` (create) and `/songs/:id` (edit).
// Phase 1 scope: title, author, lyrics (ChordPro), key, tempo, time signature,
// songbook, tags, preview_url, CCLI number.
// Phase 2 (FR5) adds the Sheets panel: PDF / image attachments via the File Service.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import AppShell from '@/components/AppShell.vue'
import ChordProPreview from '@/components/ChordProPreview.vue'
import Icon from '@/components/Icon.vue'
import Toast from '@/components/Toast.vue'
import { useAuthStore } from '@/stores/auth'
import { useSongsStore } from '@/stores/songs'
import { useSongbooksStore } from '@/stores/songbooks'
import { useSongSheetsStore } from '@/stores/songSheets'
import { extractErrorMessage } from '@/api/client'
import type { SongInput } from '@/types'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const songs = useSongsStore()
const songbooks = useSongbooksStore()
const sheets = useSongSheetsStore()
const { t } = useI18n()

const fileInput = ref<HTMLInputElement | null>(null)
const sheetError = ref<string | null>(null)

const isNew = computed(() => route.name === 'song-new')
const songId = computed(() => (isNew.value ? null : (route.params.id as string)))

const TIME_SIGNATURES = ['2/4', '3/4', '4/4', '5/4', '6/4', '3/8', '6/8', '9/8', '12/8']

const form = reactive<SongInput>({
  title: '',
  author: '',
  lyrics: '',
  original_key: 'G',
  tempo: 72,
  time_signature: '4/4',
  songbook_id: '',
  tags: [],
  preview_url: null,
  ccli_number: null,
})
const tagsText = ref('')
const saving = ref(false)
const deleting = ref(false)
const error = ref<string | null>(null)
const success = ref<string | null>(null)
const loading = ref(false)

watch(tagsText, (v) => {
  form.tags = v
    .split(',')
    .map((t) => t.trim())
    .filter((t) => t.length > 0)
})

onMounted(async () => {
  await songbooks.fetchList()
  if (!form.songbook_id && songbooks.defaultSongbook) {
    form.songbook_id = songbooks.defaultSongbook.id
  }
  if (!isNew.value && songId.value) {
    loading.value = true
    try {
      const song = await songs.fetchOne(songId.value)
      form.title = song.title
      form.author = song.author
      form.lyrics = song.lyrics
      form.original_key = song.original_key
      form.tempo = song.tempo
      form.time_signature = song.time_signature
      form.songbook_id = song.songbook_id
      form.tags = song.tags
      form.preview_url = song.preview_url
      form.ccli_number = song.ccli_number
      tagsText.value = song.tags.join(', ')
      // Sheets only exist for an already-saved song.
      sheets.fetchList(songId.value).catch(() => {
        // Non-fatal — the rest of the editor still works.
      })
    } catch (err) {
      error.value = extractErrorMessage(err, t('songEditor.errors.load'))
    } finally {
      loading.value = false
    }
  } else {
    sheets.reset()
  }
})

function formatBytes(n: number): string {
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`
  return `${(n / (1024 * 1024)).toFixed(1)} MB`
}

function pickSheet(): void {
  sheetError.value = null
  fileInput.value?.click()
}

async function onSheetSelected(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !songId.value) return

  try {
    await sheets.upload(songId.value, file)
    success.value = t('songEditor.toast.sheetUploaded')
  } catch (err) {
    sheetError.value = extractErrorMessage(err, t('songEditor.errors.uploadSheet'))
  } finally {
    // Reset so picking the same file twice still triggers `change`.
    input.value = ''
  }
}

async function onSheetDelete(id: string): Promise<void> {
  if (!confirm(t('songEditor.confirmDeleteSheet'))) return
  try {
    await sheets.remove(id)
  } catch (err) {
    sheetError.value = extractErrorMessage(err, t('songEditor.errors.deleteSheet'))
  }
}

function cleanPayload(): SongInput {
  return {
    ...form,
    author: form.author?.trim() || null,
    preview_url: form.preview_url?.trim() || null,
    ccli_number: form.ccli_number?.trim() || null,
  }
}

async function onSave() {
  error.value = null
  saving.value = true
  try {
    if (isNew.value) {
      const song = await songs.create(cleanPayload())
      success.value = t('songEditor.toast.songCreated')
      await router.replace(`/songs/${song.id}`)
    } else if (songId.value) {
      await songs.update(songId.value, cleanPayload())
      success.value = t('common.saved')
    }
  } catch (err) {
    error.value = extractErrorMessage(err, t('songEditor.errors.save'))
  } finally {
    saving.value = false
  }
}

async function onDelete() {
  if (!songId.value) return
  if (!confirm(t('songEditor.confirmDelete'))) return
  deleting.value = true
  try {
    await songs.remove(songId.value)
    await router.push('/library')
  } catch (err) {
    error.value = extractErrorMessage(err, t('songEditor.errors.delete'))
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <AppShell>
    <div class="px-6 py-[14px] border-b border-border flex items-center gap-3">
      <router-link to="/library" class="text-[12px] text-text-faint flex items-center gap-1">
        <Icon name="arrow-left" /> {{ t('musician.library') }}
      </router-link>
      <div class="flex-1" />
      <span v-if="saving" class="chip">{{ t('common.saving') }}</span>
      <span v-else-if="success" class="chip chip-accent">{{ success }}</span>
      <button
        v-if="!isNew"
        type="button"
        class="btn btn-danger"
        :disabled="deleting"
        data-testid="editor-delete"
        @click="onDelete"
      >
        <Icon name="trash" /> {{ t('common.delete') }}
      </button>
      <button
        type="button"
        class="btn btn-primary"
        :disabled="saving || !form.title || !form.lyrics || !form.songbook_id"
        data-testid="editor-save"
        @click="onSave"
      >
        {{ isNew ? t('songEditor.createSong') : t('common.save') }}
      </button>
    </div>

    <div v-if="loading" class="p-8 text-text-faint">{{ t('common.loading') }}</div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-6 px-8 py-6 overflow-auto flex-1">
      <form class="flex flex-col gap-3" novalidate @submit.prevent="onSave">
        <div>
          <label class="field-label" for="f-title">{{ t('songEditor.fields.title') }}</label>
          <input
            id="f-title"
            v-model="form.title"
            class="input font-display"
            style="font-size: 20px; font-weight: 600"
            required
          />
        </div>
        <div class="grid grid-cols-2 gap-[10px]">
          <div>
            <label class="field-label" for="f-author">{{ t('songEditor.fields.author') }}</label>
            <input id="f-author" v-model="form.author" class="input" />
          </div>
          <div>
            <label class="field-label" for="f-songbook">{{ t('songEditor.fields.songbook') }}</label>
            <select id="f-songbook" v-model="form.songbook_id" class="input" required>
              <option value="" disabled>{{ t('songEditor.selectSongbook') }}</option>
              <option v-for="sb in songbooks.list" :key="sb.id" :value="sb.id">
                {{ sb.name }}{{ sb.is_default ? ` (${t('songEditor.default')})` : '' }}
              </option>
            </select>
          </div>
        </div>
        <div class="grid grid-cols-3 gap-[10px]">
          <div>
            <label class="field-label" for="f-key">{{ t('songEditor.fields.key') }}</label>
            <input id="f-key" v-model="form.original_key" class="input" placeholder="G" />
          </div>
          <div>
            <label class="field-label" for="f-tempo">{{ t('songEditor.fields.tempo') }}</label>
            <input
              id="f-tempo"
              v-model.number="form.tempo"
              type="number"
              min="20"
              max="300"
              class="input"
            />
          </div>
          <div>
            <label class="field-label" for="f-time">{{ t('songEditor.fields.time') }}</label>
            <select id="f-time" v-model="form.time_signature" class="input">
              <option v-for="ts in TIME_SIGNATURES" :key="ts" :value="ts">{{ ts }}</option>
            </select>
          </div>
        </div>
        <div>
          <label class="field-label" for="f-lyrics">{{ t('songEditor.fields.lyrics') }}</label>
          <textarea
            id="f-lyrics"
            v-model="form.lyrics"
            class="input mono"
            style="min-height: 340px; font-size: 13px; line-height: 1.6"
            spellcheck="false"
            required
          ></textarea>
        </div>
        <p v-if="error" data-testid="editor-error" class="field-error">{{ error }}</p>
      </form>

      <aside class="flex flex-col gap-3">
        <div>
          <label class="field-label" for="f-preview">{{ t('songEditor.fields.previewUrl') }}</label>
          <input
            id="f-preview"
            v-model="form.preview_url"
            class="input"
            type="url"
            placeholder="https://youtube.com/watch?v=…"
          />
        </div>
        <div>
          <label class="field-label" for="f-ccli">{{ t('songEditor.fields.ccli') }}</label>
          <input id="f-ccli" v-model="form.ccli_number" class="input" />
        </div>
        <div>
          <label class="field-label" for="f-tags">{{ t('songEditor.fields.tags') }}</label>
          <input id="f-tags" v-model="tagsText" class="input" :placeholder="t('songEditor.tagsPlaceholder')" />
          <div class="mt-2 flex flex-wrap gap-[6px]">
            <span v-for="tag in form.tags" :key="tag" class="chip chip-accent">{{ tag }}</span>
          </div>
        </div>
        <div>
          <label class="field-label">{{ t('songEditor.livePreview') }}</label>
          <div class="card p-4 max-h-[380px] overflow-auto">
            <ChordProPreview :source="form.lyrics" />
          </div>
        </div>

        <!-- Phase 2 / FR5 — sheet attachments. Only available once the song
             has been saved (we need an id to attach to). -->
        <div v-if="!isNew" data-testid="sheets-panel">
          <label class="field-label">{{ t('songEditor.sheets.title') }}</label>
          <div class="card p-4 flex flex-col gap-3">
            <div v-if="auth.canEditSongs" class="flex items-center gap-2">
              <input
                ref="fileInput"
                type="file"
                accept="application/pdf,image/png,image/jpeg,image/webp"
                class="hidden"
                data-testid="sheet-file-input"
                @change="onSheetSelected"
              />
              <button
                type="button"
                class="btn btn-secondary"
                :disabled="sheets.uploading"
                data-testid="sheet-upload"
                @click="pickSheet"
              >
                <Icon name="upload" />
                {{ sheets.uploading ? t('songEditor.sheets.uploading') : t('songEditor.sheets.upload') }}
              </button>
              <span class="text-[12px] text-text-faint">{{ t('songEditor.sheets.hint') }}</span>
            </div>

            <p v-if="sheetError" data-testid="sheet-error" class="field-error">{{ sheetError }}</p>

            <div v-if="sheets.loading" class="text-text-faint text-[12px]">{{ t('musician.loadingSheets') }}</div>
            <div
              v-else-if="sheets.list.length === 0"
              class="text-text-faint text-[12px]"
              data-testid="sheets-empty"
            >
              {{ t('songEditor.sheets.empty') }}
            </div>
            <ul v-else class="flex flex-col gap-2" data-testid="sheets-list">
              <li
                v-for="sheet in sheets.list"
                :key="sheet.id"
                class="flex items-center gap-2 border border-border rounded p-2"
              >
                <Icon :name="sheet.file_type === 'pdf' ? 'list' : 'eye'" />
                <a
                  :href="sheet.url"
                  target="_blank"
                  rel="noopener"
                  class="flex-1 truncate text-[13px]"
                  :title="sheet.original_filename"
                >
                  {{ sheet.original_filename }}
                </a>
                <span class="text-[11px] text-text-faint">{{ formatBytes(sheet.size_bytes) }}</span>
                <button
                  v-if="auth.isAdmin"
                  type="button"
                  class="btn btn-icon btn-danger-ghost"
                  :title="t('songEditor.sheets.deleteTitle', { name: sheet.original_filename })"
                  data-testid="sheet-delete"
                  @click="onSheetDelete(sheet.id)"
                >
                  <Icon name="trash" />
                </button>
              </li>
            </ul>
          </div>
        </div>
      </aside>
    </div>
    <Toast v-if="success" :message="success" kind="success" @close="success = null" />
  </AppShell>
</template>
