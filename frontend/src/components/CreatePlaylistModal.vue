<script setup lang="ts">
// Shared create-playlist modal (Stage 4, FR-PL-1).
//
// Used by both the Playlists screen and the add-to-playlist popup so tag
// entry lives in one place. Tags are entered as chips (type + Enter/comma to
// add) with a datalist of suggestions drawn from GET /tags across songs and
// playlists. On success it emits `created` with the new playlist and lets the
// caller decide what happens next (navigate, or drop a song into it).
import { computed, nextTick, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Icon from './Icon.vue'
import { usePlaylistsStore } from '@/stores/playlists'
import { useTagsStore } from '@/stores/tags'
import { extractErrorMessage } from '@/api/client'
import type { Playlist } from '@/types'

const props = withDefaults(
  defineProps<{
    open: boolean
    /** Prefill the name (e.g. from a song title the caller is adding). */
    initialName?: string
  }>(),
  { initialName: '' },
)

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'created', playlist: Playlist): void
}>()

const { t } = useI18n()
const playlists = usePlaylistsStore()
const tagsStore = useTagsStore()

const name = ref(props.initialName)
const eventDate = ref('')
const tags = ref<string[]>([])
const tagDraft = ref('')
const busy = ref(false)
const error = ref<string | null>(null)
const nameInput = ref<HTMLInputElement | null>(null)

/** Suggestions not already picked, for the datalist. */
const suggestions = computed(() =>
  tagsStore.tags.filter((tag) => !tags.value.includes(tag)),
)

onMounted(async () => {
  tagsStore.ensureLoaded().catch(() => {})
  await nextTick()
  nameInput.value?.focus()
})

function addTag(): void {
  const value = tagDraft.value.trim().replace(/,$/, '').trim()
  tagDraft.value = ''
  if (!value || tags.value.includes(value)) return
  tags.value.push(value)
}

function removeTag(tag: string): void {
  tags.value = tags.value.filter((existing) => existing !== tag)
}

/** Backspace on an empty draft removes the last chip. */
function onTagBackspace(): void {
  if (tagDraft.value === '' && tags.value.length > 0) {
    tags.value.pop()
  }
}

async function onSubmit(): Promise<void> {
  // Fold any half-typed tag into the list before saving.
  if (tagDraft.value.trim()) addTag()
  const trimmed = name.value.trim()
  if (!trimmed) {
    error.value = t('createPlaylist.nameRequired')
    return
  }
  busy.value = true
  error.value = null
  try {
    const playlist = await playlists.create({
      name: trimmed,
      event_date: eventDate.value || null,
      tags: tags.value,
    })
    // Newly introduced tags should show up in the next form's suggestions.
    if (tags.value.length) tagsStore.refresh().catch(() => {})
    emit('created', playlist)
  } catch (err) {
    error.value = extractErrorMessage(err, t('createPlaylist.error'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-40 grid place-items-center bg-black/30"
    role="dialog"
    aria-modal="true"
    aria-labelledby="create-playlist-title"
    data-testid="create-playlist-modal"
    @click.self="emit('close')"
  >
    <div class="card w-[460px] max-w-[92vw] flex flex-col">
      <div class="flex items-center justify-between px-5 py-4 border-b border-border">
        <div id="create-playlist-title" class="font-display font-semibold text-[18px]">
          {{ t('createPlaylist.title') }}
        </div>
        <button
          type="button"
          class="btn btn-ghost"
          :aria-label="t('common.close')"
          @click="emit('close')"
        >
          <Icon name="x" />
        </button>
      </div>

      <form class="px-5 py-4 flex flex-col gap-4" @submit.prevent="onSubmit">
        <div>
          <label class="field-label" for="create-playlist-name">
            {{ t('createPlaylist.nameLabel') }}
          </label>
          <input
            id="create-playlist-name"
            ref="nameInput"
            v-model="name"
            class="input"
            :placeholder="t('createPlaylist.namePlaceholder')"
            data-testid="create-playlist-name"
          />
        </div>

        <div>
          <label class="field-label" for="create-playlist-date">
            {{ t('createPlaylist.dateLabel') }}
          </label>
          <input
            id="create-playlist-date"
            v-model="eventDate"
            type="date"
            class="input"
            data-testid="create-playlist-date"
          />
        </div>

        <div>
          <label class="field-label" for="create-playlist-tag">
            {{ t('createPlaylist.tagsLabel') }}
          </label>
          <div class="flex flex-wrap items-center gap-1">
            <span
              v-for="tag in tags"
              :key="tag"
              class="chip flex items-center gap-1"
              data-testid="create-playlist-tag-chip"
            >
              {{ tag }}
              <button
                type="button"
                class="text-text-faint hover:text-text"
                :aria-label="t('createPlaylist.removeTag', { tag })"
                @click="removeTag(tag)"
              >
                <Icon name="x" />
              </button>
            </span>
            <input
              id="create-playlist-tag"
              v-model="tagDraft"
              class="input flex-1 min-w-[120px]"
              list="create-playlist-tag-suggestions"
              :placeholder="t('createPlaylist.tagsPlaceholder')"
              data-testid="create-playlist-tag-input"
              @keydown.enter.prevent="addTag"
              @keydown.,.prevent="addTag"
              @keydown.delete="onTagBackspace"
              @change="addTag"
            />
            <datalist id="create-playlist-tag-suggestions">
              <option v-for="tag in suggestions" :key="tag" :value="tag" />
            </datalist>
          </div>
          <p class="text-[11px] text-text-faint mt-1">{{ t('createPlaylist.tagsHint') }}</p>
        </div>

        <p v-if="error" class="field-error" data-testid="create-playlist-error">{{ error }}</p>

        <div class="flex items-center justify-end gap-2 pt-1">
          <button type="button" class="btn" @click="emit('close')">
            {{ t('common.cancel') }}
          </button>
          <button
            type="submit"
            class="btn btn-primary"
            :disabled="busy || !name.trim()"
            data-testid="create-playlist-submit"
          >
            <Icon name="plus" /> {{ t('common.create') }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
