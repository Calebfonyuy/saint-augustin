<script setup lang="ts">
// Song Editor — handles both `/songs/new` (create) and `/songs/:id` (edit).
// Phase 1 scope: title, author, lyrics (ChordPro), key, tempo, time signature,
// songbook, tags, preview_url, CCLI number. No PDF sheet uploads yet
// (File Service lands in Phase 5).
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppShell from '@/components/AppShell.vue'
import ChordProPreview from '@/components/ChordProPreview.vue'
import Icon from '@/components/Icon.vue'
import Toast from '@/components/Toast.vue'
import { useSongsStore } from '@/stores/songs'
import { useSongbooksStore } from '@/stores/songbooks'
import { extractErrorMessage } from '@/api/client'
import type { SongInput } from '@/types'

const route = useRoute()
const router = useRouter()
const songs = useSongsStore()
const songbooks = useSongbooksStore()

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
    } catch (err) {
      error.value = extractErrorMessage(err, 'Failed to load song.')
    } finally {
      loading.value = false
    }
  }
})

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
      success.value = 'Song created.'
      await router.replace(`/songs/${song.id}`)
    } else if (songId.value) {
      await songs.update(songId.value, cleanPayload())
      success.value = 'Saved.'
    }
  } catch (err) {
    error.value = extractErrorMessage(err, 'Could not save song.')
  } finally {
    saving.value = false
  }
}

async function onDelete() {
  if (!songId.value) return
  if (!confirm('Delete this song? It can be restored by an admin within 30 days.')) return
  deleting.value = true
  try {
    await songs.remove(songId.value)
    await router.push('/library')
  } catch (err) {
    error.value = extractErrorMessage(err, 'Could not delete song.')
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <AppShell>
    <div class="px-6 py-[14px] border-b border-border flex items-center gap-3">
      <router-link to="/library" class="text-[12px] text-text-faint flex items-center gap-1">
        <Icon name="arrow-left" /> Library
      </router-link>
      <div class="flex-1" />
      <span v-if="saving" class="chip">Saving…</span>
      <span v-else-if="success" class="chip chip-accent">{{ success }}</span>
      <button
        v-if="!isNew"
        type="button"
        class="btn btn-danger"
        :disabled="deleting"
        data-testid="editor-delete"
        @click="onDelete"
      >
        <Icon name="trash" /> Delete
      </button>
      <button
        type="button"
        class="btn btn-primary"
        :disabled="saving || !form.title || !form.lyrics || !form.songbook_id"
        data-testid="editor-save"
        @click="onSave"
      >
        {{ isNew ? 'Create song' : 'Save' }}
      </button>
    </div>

    <div v-if="loading" class="p-8 text-text-faint">Loading…</div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-[1.4fr_1fr] gap-6 px-8 py-6 overflow-auto flex-1">
      <form class="flex flex-col gap-3" novalidate @submit.prevent="onSave">
        <div>
          <label class="field-label" for="f-title">Title</label>
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
            <label class="field-label" for="f-author">Author</label>
            <input id="f-author" v-model="form.author" class="input" />
          </div>
          <div>
            <label class="field-label" for="f-songbook">Songbook</label>
            <select id="f-songbook" v-model="form.songbook_id" class="input" required>
              <option value="" disabled>Select a songbook</option>
              <option v-for="sb in songbooks.list" :key="sb.id" :value="sb.id">
                {{ sb.name }}{{ sb.is_default ? ' (default)' : '' }}
              </option>
            </select>
          </div>
        </div>
        <div class="grid grid-cols-3 gap-[10px]">
          <div>
            <label class="field-label" for="f-key">Key</label>
            <input id="f-key" v-model="form.original_key" class="input" placeholder="G" />
          </div>
          <div>
            <label class="field-label" for="f-tempo">Tempo</label>
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
            <label class="field-label" for="f-time">Time</label>
            <select id="f-time" v-model="form.time_signature" class="input">
              <option v-for="t in TIME_SIGNATURES" :key="t" :value="t">{{ t }}</option>
            </select>
          </div>
        </div>
        <div>
          <label class="field-label" for="f-lyrics">Lyrics · ChordPro</label>
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
          <label class="field-label" for="f-preview">Preview URL</label>
          <input
            id="f-preview"
            v-model="form.preview_url"
            class="input"
            type="url"
            placeholder="https://youtube.com/watch?v=…"
          />
        </div>
        <div>
          <label class="field-label" for="f-ccli">CCLI number</label>
          <input id="f-ccli" v-model="form.ccli_number" class="input" />
        </div>
        <div>
          <label class="field-label" for="f-tags">Tags (comma-separated)</label>
          <input id="f-tags" v-model="tagsText" class="input" placeholder="hymn, grace, communion" />
          <div class="mt-2 flex flex-wrap gap-[6px]">
            <span v-for="t in form.tags" :key="t" class="chip chip-accent">{{ t }}</span>
          </div>
        </div>
        <div>
          <label class="field-label">Live preview</label>
          <div class="card p-4 max-h-[380px] overflow-auto">
            <ChordProPreview :source="form.lyrics" />
          </div>
        </div>
      </aside>
    </div>
    <Toast v-if="success" :message="success" kind="success" @close="success = null" />
  </AppShell>
</template>
