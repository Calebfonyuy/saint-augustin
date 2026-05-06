<script setup lang="ts">
/*
 * Musician View — Phase 2's core deliverable (SRS 3.2 / FR2-FR6).
 *
 * Layout:
 *   ┌──────────────────────────────────────────────┬───────────────────┐
 *   │  Title · author · original key · key picker  │  Metronome        │
 *   │  ─────────────────────────────────────────── │  Sheets viewer    │
 *   │  Lyrics with chords (transposed live)        │  Preview          │
 *   └──────────────────────────────────────────────┴───────────────────┘
 *
 * Key behaviour:
 *   • Selecting a target key transposes ALL displayed chords in real time
 *     using the Phase-2 chordpro engine (`@/lib/chordpro`). The original
 *     key badge stays visible so the musician knows the source key.
 *   • Metronome reads tempo + time signature from the song; the BPM input
 *     overrides it locally without persisting.
 *   • Sheets are fetched on mount; the first one is shown by default.
 *   • Preview embed appears only when the song has a `preview_url`.
 *
 * Authorization: any authenticated user (musician/admin/projectionist).
 * Edits are gated to `auth.canEditSongs` — Read-only otherwise.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/AppShell.vue'
import ChordProPreview from '@/components/ChordProPreview.vue'
import Icon from '@/components/Icon.vue'
import KeyBadge from '@/components/KeyBadge.vue'
import Metronome from '@/components/Metronome.vue'
import PreviewPlayer from '@/components/PreviewPlayer.vue'
import SheetViewer from '@/components/SheetViewer.vue'
import Toast from '@/components/Toast.vue'
import { useAuthStore } from '@/stores/auth'
import { useSongsStore } from '@/stores/songs'
import { useSongSheetsStore } from '@/stores/songSheets'
import { transposeChordPro } from '@/lib/chordpro'
import { MAJOR_KEYS, MINOR_KEYS } from '@/lib/keys'
import { extractErrorMessage } from '@/api/client'
import type { Song } from '@/types'

const route = useRoute()
const auth = useAuthStore()
const songs = useSongsStore()
const sheets = useSongSheetsStore()
const { t } = useI18n()

const song = ref<Song | null>(null)
const loading = ref(false)
const errorToast = ref<string | null>(null)

/** Target key chosen by the user. Null = use original (no transposition). */
const targetKey = ref<string | null>(null)

const songId = computed(() => route.params.id as string)

// The "from" key we transpose from. Falls back to "C" only as a safe default
// when the song was saved without an original_key set.
const sourceKey = computed(() => song.value?.original_key ?? 'C')

const effectiveTargetKey = computed(() => targetKey.value ?? sourceKey.value)

/** Lyrics with chords transposed if a target key was chosen. */
const displayLyrics = computed(() => {
  if (!song.value) return ''
  if (!targetKey.value || targetKey.value === sourceKey.value) {
    return song.value.lyrics
  }
  return transposeChordPro(song.value.lyrics, sourceKey.value, targetKey.value)
})

/** Sheet to render in the sidebar. Defaults to the first one. */
const selectedSheetId = ref<string | null>(null)
const selectedSheet = computed(
  () => sheets.list.find((s) => s.id === selectedSheetId.value) ?? sheets.list[0] ?? null,
)
watch(
  () => sheets.list,
  (list) => {
    if (!selectedSheetId.value && list.length) selectedSheetId.value = list[0].id
    if (selectedSheetId.value && !list.some((s) => s.id === selectedSheetId.value)) {
      selectedSheetId.value = list[0]?.id ?? null
    }
  },
)

onMounted(async () => {
  loading.value = true
  try {
    song.value = await songs.fetchOne(songId.value)
    sheets.fetchList(songId.value).catch(() => {
      // Non-fatal — chord display still works without sheets.
    })
  } catch (err) {
    errorToast.value = extractErrorMessage(err, t('musician.failedLoad'))
  } finally {
    loading.value = false
  }
})

function resetKey(): void {
  targetKey.value = null
}
</script>

<template>
  <AppShell>
    <div class="px-4 md:px-6 py-[14px] border-b border-border flex items-center gap-3">
      <router-link to="/library" class="text-[12px] text-text-faint flex items-center gap-1">
        <Icon name="arrow-left" /> {{ t('musician.library') }}
      </router-link>
      <div class="flex-1" />
      <router-link
        v-if="song && auth.canEditSongs"
        :to="`/songs/${song.id}`"
        class="btn"
        data-testid="musician-edit"
      >
        {{ t('musician.edit') }}
      </router-link>
    </div>

    <div v-if="loading" class="p-8 text-text-faint">{{ t('musician.loading') }}</div>

    <div
      v-else-if="song"
      class="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-4 md:gap-6 px-4 py-4 md:px-8 md:py-6 overflow-auto flex-1"
    >
      <!-- LEFT: header + chord/lyrics body -->
      <section class="flex flex-col min-w-0">
        <header class="flex flex-wrap items-end gap-x-5 gap-y-3 pb-4 border-b border-border">
          <div class="min-w-0 flex-1">
            <h1
              class="font-display font-semibold text-[22px] md:text-[28px] leading-tight truncate"
              data-testid="musician-title"
            >
              {{ song.title }}
            </h1>
            <div class="text-[13px] text-text-faint mt-[2px] truncate">
              {{ song.author ?? t('musician.unknownAuthor') }}
              <span v-if="song.tempo"> · {{ song.tempo }} {{ t('musician.bpm') }}</span>
              <span v-if="song.time_signature"> · {{ song.time_signature }}</span>
              <span v-if="song.ccli_number"> · {{ t('musician.ccli') }} {{ song.ccli_number }}</span>
            </div>
          </div>

          <div class="flex items-end gap-3">
            <div class="flex flex-col">
              <span class="mono uppercase tracking-[0.14em] text-[10px] text-text-faint mb-1">
                {{ t('musician.original') }}
              </span>
              <KeyBadge :musical-key="song.original_key" />
            </div>
            <div class="flex flex-col">
              <label
                for="m-key"
                class="mono uppercase tracking-[0.14em] text-[10px] text-text-faint mb-1"
              >
                {{ t('musician.playIn') }}
              </label>
              <div class="flex items-center gap-2">
                <select
                  id="m-key"
                  :value="effectiveTargetKey"
                  class="input"
                  style="padding: 6px 8px; min-width: 100px"
                  data-testid="musician-key-select"
                  @change="targetKey = ($event.target as HTMLSelectElement).value"
                >
                  <optgroup :label="t('musician.keyMajor')">
                    <option v-for="k in MAJOR_KEYS" :key="k" :value="k">{{ k }}</option>
                  </optgroup>
                  <optgroup :label="t('musician.keyMinor')">
                    <option v-for="k in MINOR_KEYS" :key="k" :value="k">{{ k }}</option>
                  </optgroup>
                </select>
                <button
                  v-if="targetKey && targetKey !== sourceKey"
                  type="button"
                  class="text-[11px] text-text-faint hover:text-accent"
                  data-testid="musician-key-reset"
                  @click="resetKey"
                >
                  {{ t('musician.reset') }}
                </button>
              </div>
            </div>
          </div>
        </header>

        <div class="pt-6 overflow-auto" data-testid="musician-lyrics">
          <ChordProPreview :source="displayLyrics" />
        </div>
      </section>

      <!-- RIGHT: metronome / sheets / preview -->
      <aside class="flex flex-col gap-4 min-w-0">
        <Metronome :tempo="song.tempo" :time-signature="song.time_signature" />

        <div data-testid="musician-sheets">
          <div class="mono uppercase tracking-[0.14em] text-[10.5px] text-text-faint mb-2">
            {{ t('musician.songSheets') }}
          </div>

          <div v-if="sheets.loading" class="card p-4 text-[12px] text-text-faint">
            {{ t('musician.loadingSheets') }}
          </div>

          <template v-else-if="sheets.list.length">
            <div v-if="sheets.list.length > 1" class="flex flex-wrap gap-1 mb-2">
              <button
                v-for="s in sheets.list"
                :key="s.id"
                type="button"
                class="text-[11px] px-2 py-[3px] rounded border"
                :class="
                  s.id === selectedSheetId
                    ? 'bg-accent-soft border-accent text-accent'
                    : 'border-border text-text-faint hover:text-text'
                "
                data-testid="musician-sheet-tab"
                @click="selectedSheetId = s.id"
              >
                {{ s.original_filename }}
              </button>
            </div>
            <SheetViewer v-if="selectedSheet" :sheet="selectedSheet" />
          </template>

          <div
            v-else
            class="card p-4 text-[12px] text-text-faint"
            data-testid="musician-sheets-empty"
          >
            <p>{{ t('musician.noSheets') }}</p>
            <router-link
              v-if="auth.canEditSongs && song"
              :to="`/songs/${song.id}`"
              class="text-accent hover:underline mt-1 inline-block"
            >
              {{ t('musician.uploadSheet') }}
            </router-link>
          </div>
        </div>

        <div v-if="song.preview_url">
          <div class="mono uppercase tracking-[0.14em] text-[10.5px] text-text-faint mb-2">
            {{ t('musician.preview') }}
          </div>
          <PreviewPlayer :url="song.preview_url" />
        </div>
      </aside>
    </div>

    <div v-else class="p-8 text-text-faint">{{ t('musician.songNotFound') }}</div>

    <Toast
      v-if="errorToast"
      :message="errorToast"
      kind="error"
      @close="errorToast = null"
    />
  </AppShell>
</template>
